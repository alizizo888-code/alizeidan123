<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «مدرّب أكسجين» — the bridge between the owner's Claude artifact and the business.
 *
 * The artifact is a page on claude.ai that talks to Claude on the owner's own Claude subscription (no Anthropic key on
 * this server). It reaches this site through the owner's WordPress connector, so every call arrives as a logged-in
 * WordPress administrator — that is the only door this endpoint opens: `current_user_can('manage_options')`.
 *
 * What it is responsible for, in one place:
 *   - the knowledge every channel answers from (the FAQ in the AI settings: site chat, WhatsApp bot, app assistant),
 *     and the instant WhatsApp replies (keywords → reply) that need no model at all;
 *   - what the bot learned by itself and waits for approval, and the questions it could not answer;
 *   - standing instructions (the sales brain's notes) that hold for every conversation;
 *   - running the owner's commands (the internal engine and the control assistant's closed tool list), with the same
 *     «needs confirm» rule: a change that matters comes back as pending and runs only when the owner confirms;
 *   - requests for Claude Code to step in (teach / execute / fix), kept as tickets with their outcome.
 * Everything it changes is written to the same audit log as the control assistant.
 */
final class Mutqan_Coach {
    const NS = 'mutqan/v1';
    const TICKETS = '_coach_tickets';

    public static function boot() { add_action('rest_api_init', [__CLASS__, 'routes']); }

    public static function routes() {
        register_rest_route(self::NS, '/coach', [
            ['methods' => 'GET', 'permission_callback' => [__CLASS__, 'allowed'], 'callback' => function ($req) { return self::respond(['op' => 'state'] + (array) $req->get_query_params()); }],
            ['methods' => 'POST', 'permission_callback' => [__CLASS__, 'allowed'], 'callback' => function ($req) { $b = json_decode((string) $req->get_body(), true); return self::respond(is_array($b) ? $b : []); }],
        ]);
    }

    public static function allowed() { return function_exists('current_user_can') && current_user_can('manage_options'); }

    private static function respond($in) {
        try { return new WP_REST_Response(['ok' => true, 'data' => self::handle($in)], 200); }
        catch (Mutqan_Error $e) { return new WP_REST_Response(['ok' => false, 'error' => ['code' => $e->slug, 'message' => $e->getMessage()]], 200); }
        catch (Throwable $e) { error_log('[mutqan] coach ' . $e->getMessage()); return new WP_REST_Response(['ok' => false, 'error' => ['code' => 'server_error', 'message' => mb_substr($e->getMessage(), 0, 200)]], 200); }
    }

    /** The owner, as the rest of the system knows him, acting through the artifact. */
    public static function owner() { return ['id' => 'coach', 'role' => 'owner', 'kind' => 'user', 'powers' => ['read', 'write', 'manage'], 'name' => 'مدرّب أكسجين']; }
    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user']; }

    public static function handle($in) {
        $op = (string) ($in['op'] ?? 'state');
        $p = self::owner();
        switch ($op) {
            case 'state': return self::state();
            case 'faq_add': return self::faq_save(null, $in);
            case 'faq_update': return self::faq_save((int) ($in['i'] ?? -1), $in);
            case 'faq_delete': return self::faq_delete((int) ($in['i'] ?? -1));
            case 'reply_add': return self::reply_save(null, $in);
            case 'reply_delete': return self::reply_delete((int) ($in['i'] ?? -1));
            case 'learned_approve': return self::learned((string) ($in['id'] ?? ''), 'approved', $in);
            case 'learned_reject': return self::learned((string) ($in['id'] ?? ''), 'rejected', $in);
            case 'miss_answer': return self::miss_answer((string) ($in['id'] ?? ''), $in);
            case 'miss_dismiss': return self::miss_dismiss((string) ($in['id'] ?? ''));
            case 'note_add':
                $t = trim((string) ($in['text'] ?? ''));
                if ($t === '') { throw new Mutqan_Error('empty', 'اكتب التعليمة.', 400); }
                $st = Mutqan_Sales::save(['note' => $t]); self::audit('note_add', $t);
                return ['notes' => self::notes($st)];
            case 'note_delete':
                $st = Mutqan_Sales::save(['removeNote' => (int) ($in['i'] ?? -1)]); self::audit('note_delete', (string) ($in['i'] ?? ''));
                return ['notes' => self::notes($st)];
            case 'run': return self::run((string) ($in['text'] ?? ''), $p);
            case 'tool': return self::tool((string) ($in['name'] ?? ''), (array) ($in['input'] ?? []), $p);
            case 'confirm':
                $r = Mutqan_Agent::handle(['confirm' => (string) ($in['id'] ?? '')], $p);
                return $r;
            case 'cancel': Mutqan_Agent::handle(['cancel' => (string) ($in['id'] ?? '')], $p); return ['ok' => true];
            case 'ticket_add': return self::ticket_add($in);
            case 'ticket_update': return self::ticket_update((string) ($in['id'] ?? ''), $in);
            case 'test_bot': return self::test_bot((string) ($in['text'] ?? ''));
            case 'vps_enroll': return self::vps_enroll();
            case 'brief': return self::brief();
            case 'apply': return self::apply($in);
        }
        throw new Mutqan_Error('bad_op', 'عملية غير معروفة: ' . $op, 400);
    }


    /* ------------------------------------------------------------------ «عقل Claude» (owner, 2026-11-20) */

    /**
     * Everything Claude on the owner's subscription needs to study the business, in ONE call (the WordPress connector
     * has a daily call allowance, so the page reads once and writes once): last nights' practice, what the bot could not
     * answer, answers waiting for approval, the house rules with the price sheet, the standing instructions, the FAQ
     * titles, a short picture of operations and recent customer messages (masked).
     */
    public static function brief() {
        $tr = class_exists('Mutqan_Trainer') ? Mutqan_Trainer::status() : null;
        $runs = [];
        foreach ((array) ($tr['runs'] ?? []) as $r) {
            if (($r['kind'] ?? '') === 'drill') { continue; }
            $runs[] = ['scenario' => $r['scenario'] ?? '', 'pass' => !empty($r['pass']), 'score' => (int) ($r['score'] ?? 0), 'issues' => array_slice((array) ($r['issues'] ?? []), 0, 4), 'transcript' => array_slice((array) ($r['transcript'] ?? []), 0, 10), 'day' => $r['day'] ?? ''];
            if (count($runs) >= 14) { break; }
        }
        $st = self::state();
        $ai = self::ai_doc();
        global $wpdb;
        $t = Mutqan_Store::table('docs');
        $recent = [];
        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT data FROM $t WHERE coll IN ('ai_messages','wa_msgs') AND created_at > %s ORDER BY created_at DESC LIMIT 120", gmdate('Y-m-d H:i:s', time() - 14 * 86400))) as $raw) {
            $d = json_decode((string) $raw, true);
            if (!is_array($d)) { continue; }
            $txt = trim((string) ($d['text'] ?? $d['content'] ?? $d['body'] ?? ''));
            if ($txt === '' || mb_strlen($txt) > 400) { continue; }
            $who = (string) ($d['role'] ?? $d['from'] ?? $d['direction'] ?? '');
            $recent[] = (in_array($who, ['user', 'customer', 'in', 'incoming'], true) ? 'عميل: ' : 'نحن: ') . Mutqan_AI::mask($txt);
            if (count($recent) >= 40) { break; }
        }
        return [
            'at' => gmdate('c'),
            'rules' => class_exists('Mutqan_AI') ? Mutqan_AI::house_rules() : '',
            'notes' => array_column($st['notes'], 'text'),
            'faq' => array_map(function ($f) { return mb_substr((string) ($f['q'] ?? ''), 0, 140); }, array_slice((array) ($ai['faq'] ?? []), 0, 120)),
            'misses' => array_map(function ($m) { return ['q' => $m['q'], 'count' => $m['count']]; }, array_slice($st['misses'], 0, 20)),
            'pending' => array_map(function ($l) { return ['id' => $l['id'], 'q' => $l['q'], 'a' => $l['a']]; }, array_slice($st['learned'], 0, 20)),
            'trainer' => $tr ? ['day' => $tr['day'], 'results' => $tr['results'], 'stats' => $tr['stats'], 'end' => $tr['end'], 'lessons' => array_slice((array) $tr['lessons'], 0, 20)] : null,
            'runs' => $runs,
            'recent' => array_reverse($recent),
            'overview' => $st['overview'],
            'extraScenarios' => array_values((array) get_option('mutqan_trainer_extra', [])),
        ];
    }

    /**
     * What the owner approved from Claude's study, written in ONE call: answers into the FAQ every channel reads,
     * standing instructions, approve/reject of waiting answers, and new practice scenarios for the nightly trainer.
     */
    public static function apply($in) {
        $done = ['faq' => 0, 'notes' => 0, 'approved' => 0, 'rejected' => 0, 'scenarios' => 0];
        foreach (array_slice((array) ($in['faq'] ?? []), 0, 30) as $f) {
            if (trim((string) ($f['q'] ?? '')) === '' || trim((string) ($f['a'] ?? '')) === '') { continue; }
            self::faq_save(null, ['q' => $f['q'], 'a' => $f['a'], 'cat' => 'Claude', 'keywords' => (string) ($f['keywords'] ?? '')]); $done['faq']++;
        }
        foreach (array_slice((array) ($in['notes'] ?? []), 0, 10) as $n) {
            $n = trim((string) $n);
            if ($n === '') { continue; }
            Mutqan_Sales::save(['note' => mb_substr($n, 0, 400)]); self::audit('note_add', $n); $done['notes']++;
        }
        foreach (array_slice((array) ($in['decide'] ?? []), 0, 40) as $d) {
            $id = (string) ($d['id'] ?? ''); $ok = !empty($d['approve']);
            if ($id === '') { continue; }
            $r = Mutqan_Store::get_raw('wa_bot', $id);
            if (!$r) { continue; }
            if (($r['feature'] ?? '') === 'trainer' && class_exists('Mutqan_Trainer')) { Mutqan_Trainer::handle(['op' => 'lesson', 'id' => $id, 'decision' => $ok ? 'approved' : 'rejected', 'a' => (string) ($d['a'] ?? '')], self::owner()); }
            else { self::learned($id, $ok ? 'approved' : 'rejected', ['a' => (string) ($d['a'] ?? ''), 'to_faq' => $ok]); }
            $done[$ok ? 'approved' : 'rejected']++;
        }
        $extra = array_values((array) get_option('mutqan_trainer_extra', []));
        foreach (array_slice((array) ($in['scenarios'] ?? []), 0, 10) as $sc) {
            $turns = array_values(array_filter(array_map(function ($x) { return mb_substr(trim((string) $x), 0, 300); }, (array) ($sc['turns'] ?? [])), 'strlen'));
            $expect = mb_substr(trim((string) ($sc['expect'] ?? '')), 0, 400);
            if (!$turns || $expect === '') { continue; }
            $extra[] = ['id' => 'cl-' . substr(md5(implode('|', $turns)), 0, 8), 'kind' => in_array($sc['kind'] ?? '', ['chat', 'lead'], true) ? $sc['kind'] : 'chat', 'turns' => array_slice($turns, 0, 5), 'expect' => $expect, 'after' => !empty($sc['after'])];
            $done['scenarios']++;
        }
        if ($done['scenarios']) {
            $seen = []; $extra = array_values(array_filter($extra, function ($x) use (&$seen) { if (isset($seen[$x['id']])) { return false; } $seen[$x['id']] = 1; return true; }));
            update_option('mutqan_trainer_extra', array_slice($extra, -40), false);
        }
        self::audit('claude_apply', wp_json_encode($done));
        return $done;
    }

    /* ------------------------------------------------------------------ what the artifact shows */

    public static function state() {
        $ai = self::ai_doc();
        $faq = [];
        foreach ((array) ($ai['faq'] ?? []) as $i => $f) {
            $faq[] = ['i' => $i, 'q' => (string) ($f['q'] ?? ''), 'a' => (string) ($f['a'] ?? ''), 'cat' => (string) ($f['cat'] ?? ''),
                'audience' => array_values((array) ($f['audience'] ?? [])), 'internal' => !empty($f['internal'])];
        }
        $cfg = Mutqan_Store::get_raw('wa_bot', 'cfg') ?: [];
        $replies = [];
        foreach ((array) ($cfg['faq'] ?? []) as $i => $f) { $replies[] = ['i' => $i, 'keywords' => (string) ($f['keywords'] ?? ''), 'reply' => (string) ($f['reply'] ?? '')]; }
        $learned = []; $misses = [];
        foreach (Mutqan_Store::select('wa_bot', [], ['limit' => 600, 'order' => '-updatedAt'], self::sys()) as $r) {
            $k = (string) ($r['kind'] ?? '');
            if (($k === 'ai' || $k === 'learn') && ($r['status'] ?? 'pending') === 'pending') {
                $learned[] = ['id' => (string) $r['id'], 'from' => $k === 'ai' ? 'الذكاء' : 'ردك من الموبايل', 'q' => (string) ($r['q'] ?? ''), 'a' => (string) ($r['a'] ?? ''),
                    'count' => (int) ($r['count'] ?? 1), 'figure' => !empty($r['figure']), 'at' => (string) ($r['at'] ?? '')];
            } elseif ($k === 'miss') {
                $misses[] = ['id' => (string) $r['id'], 'q' => (string) ($r['q'] ?? ''), 'count' => (int) ($r['count'] ?? 1), 'at' => (string) ($r['at'] ?? '')];
            }
        }
        usort($learned, function ($a, $b) { return $b['count'] <=> $a['count']; });
        usort($misses, function ($a, $b) { return $b['count'] <=> $a['count']; });
        $tickets = Mutqan_Store::select(self::TICKETS, [], ['limit' => 30, 'order' => '-createdAt'], self::sys());
        $live = class_exists('Mutqan_AI') ? Mutqan_AI::live_providers() : [];
        return [
            'at' => gmdate('c'), 'faq' => $faq, 'replies' => $replies,
            'learned' => array_slice($learned, 0, 40), 'misses' => array_slice($misses, 0, 40),
            'notes' => self::notes(Mutqan_Sales::settings()),
            'tickets' => array_map(function ($t) { return array_intersect_key($t, array_flip(['id', 'kind', 'title', 'detail', 'status', 'result', 'createdAt', 'updatedAt', 'session'])); }, $tickets),
            'overview' => class_exists('Mutqan_Quick') ? Mutqan_Quick::say('ops_overview', Mutqan_Ops::run_tool('ops_overview', [])) : '',
            'ai' => ['live' => $live, 'any' => class_exists('Mutqan_AI') && Mutqan_AI::any_enabled()],
            'tools' => array_map(function ($t) { return ['name' => $t['name'], 'description' => $t['description'], 'confirm' => $t['name'] === 'wp_update' || Mutqan_Agent::needs_confirm($t['name'], [])]; }, Mutqan_Agent::tools()),
            'commands' => Mutqan_Quick::help(),
        ];
    }

    private static function notes($st) {
        $out = [];
        foreach ((array) ($st['notes'] ?? []) as $i => $n) { $out[] = ['i' => $i, 'text' => (string) ($n['text'] ?? ''), 'at' => (string) ($n['at'] ?? '')]; }
        return $out;
    }

    /* ------------------------------------------------------------------ knowledge (every channel) */

    private static function ai_doc() {
        $row = Mutqan_Store::get_raw('ai_settings', 'default');
        if (is_array($row)) { return $row; }
        return Mutqan_Store::upsert('ai_settings', ['faq' => []], 'default', [], self::sys());
    }

    private static function clean($s, $n) { return mb_substr(trim(wp_strip_all_tags((string) $s)), 0, $n); }

    public static function faq_save($i, $in) {
        $ai = self::ai_doc(); $faq = array_values((array) ($ai['faq'] ?? []));
        $row = ['q' => self::clean($in['q'] ?? '', 300), 'a' => self::clean($in['a'] ?? '', 1500), 'cat' => self::clean($in['cat'] ?? '', 40),
            'audience' => array_values(array_intersect((array) ($in['audience'] ?? []), Mutqan_KB::AUDIENCES)), 'internal' => !empty($in['internal'])];
        if ($row['q'] === '' || $row['a'] === '') { throw new Mutqan_Error('empty', 'السؤال والإجابة مطلوبين.', 400); }
        if ($i === null) {
            foreach ($faq as $k => $f) { if (Mutqan_Quick::norm($f['q'] ?? '') === Mutqan_Quick::norm($row['q'])) { $i = $k; break; } }   // same question: update it, no duplicate
        }
        if ($i === null) { $faq[] = $row; $i = count($faq) - 1; }
        elseif (isset($faq[$i])) { $faq[$i] = array_merge((array) $faq[$i], $row); }
        else { throw new Mutqan_Error('not_found', 'السؤال مش موجود.', 404); }
        Mutqan_Store::update('ai_settings', 'default', ['faq' => $faq], self::sys());
        Mutqan_KB::flush();
        // Keywords given: the WhatsApp bot also answers it instantly, without a model.
        if (trim((string) ($in['keywords'] ?? '')) !== '') { self::reply_save(null, ['keywords' => $in['keywords'], 'reply' => $row['a']]); }
        self::audit('faq_save', $row['q']);
        return ['i' => $i, 'count' => count($faq)];
    }

    public static function faq_delete($i) {
        $ai = self::ai_doc(); $faq = array_values((array) ($ai['faq'] ?? []));
        if (!isset($faq[$i])) { throw new Mutqan_Error('not_found', 'السؤال مش موجود.', 404); }
        $q = (string) ($faq[$i]['q'] ?? ''); array_splice($faq, $i, 1);
        Mutqan_Store::update('ai_settings', 'default', ['faq' => $faq], self::sys());
        Mutqan_KB::flush(); self::audit('faq_delete', $q);
        return ['count' => count($faq)];
    }

    private static function wa_cfg() {
        $c = Mutqan_Store::get_raw('wa_bot', 'cfg');
        return is_array($c) ? $c : Mutqan_Store::upsert('wa_bot', ['faq' => []], 'cfg', [], self::sys());
    }

    public static function reply_save($i, $in) {
        $c = self::wa_cfg(); $list = array_values((array) ($c['faq'] ?? []));
        $row = ['keywords' => self::clean($in['keywords'] ?? '', 300), 'reply' => self::clean($in['reply'] ?? '', 1500)];
        if ($row['keywords'] === '' || $row['reply'] === '') { throw new Mutqan_Error('empty', 'الكلمات والرد مطلوبين.', 400); }
        if ($i === null) { $list[] = $row; } elseif (isset($list[$i])) { $list[$i] = $row; } else { throw new Mutqan_Error('not_found', 'الرد مش موجود.', 404); }
        Mutqan_Store::update('wa_bot', 'cfg', ['faq' => $list], self::sys());
        self::audit('reply_save', $row['keywords']);
        return ['count' => count($list)];
    }

    public static function reply_delete($i) {
        $c = self::wa_cfg(); $list = array_values((array) ($c['faq'] ?? []));
        if (!isset($list[$i])) { throw new Mutqan_Error('not_found', 'الرد مش موجود.', 404); }
        array_splice($list, $i, 1);
        Mutqan_Store::update('wa_bot', 'cfg', ['faq' => $list], self::sys());
        self::audit('reply_delete', (string) $i);
        return ['count' => count($list)];
    }

    /** Approve (optionally with a corrected answer, optionally into the FAQ) or reject what the bot learned. */
    public static function learned($id, $status, $in) {
        $r = Mutqan_Store::get_raw('wa_bot', $id);
        if (!$r || !in_array($r['kind'] ?? '', ['ai', 'learn'], true)) { throw new Mutqan_Error('not_found', 'العنصر مش موجود.', 404); }
        $patch = ['status' => $status, 'reviewedAt' => gmdate('c')];
        if ($status === 'approved' && trim((string) ($in['a'] ?? '')) !== '') { $patch['a'] = self::clean($in['a'], 1500); $patch['figure'] = Mutqan_Brain::has_figure($patch['a']); }
        Mutqan_Store::update('wa_bot', $id, $patch, self::sys());
        if ($status === 'approved' && !empty($in['to_faq'])) { self::faq_save(null, ['q' => $r['q'] ?? '', 'a' => $patch['a'] ?? ($r['a'] ?? ''), 'cat' => 'متعلّم', 'keywords' => $in['keywords'] ?? '']); }
        self::audit('learned_' . $status, (string) ($r['q'] ?? ''));
        return ['ok' => true, 'id' => $id, 'status' => $status];
    }

    public static function miss_answer($id, $in) {
        $r = Mutqan_Store::get_raw('wa_bot', $id);
        if (!$r || ($r['kind'] ?? '') !== 'miss') { throw new Mutqan_Error('not_found', 'السؤال مش موجود.', 404); }
        $out = self::faq_save(null, ['q' => $in['q'] ?? ($r['q'] ?? ''), 'a' => $in['a'] ?? '', 'cat' => $in['cat'] ?? 'من الأسئلة اللي ما اتجاوبتش', 'keywords' => $in['keywords'] ?? '']);
        Mutqan_Store::delete('wa_bot', $id, self::sys());
        return $out + ['answered' => $id];
    }

    public static function miss_dismiss($id) {
        $r = Mutqan_Store::get_raw('wa_bot', $id);
        if (!$r || ($r['kind'] ?? '') !== 'miss') { throw new Mutqan_Error('not_found', 'السؤال مش موجود.', 404); }
        Mutqan_Store::delete('wa_bot', $id, self::sys());
        return ['ok' => true];
    }

    /** What the WhatsApp bot would answer a customer right now, without sending anything. */
    public static function test_bot($text) {
        $text = self::clean($text, 500);
        if ($text === '') { throw new Mutqan_Error('empty', 'اكتب رسالة العميل.', 400); }
        $cfg = (Mutqan_Store::get_raw('wa_bot', 'cfg') ?: []) + Mutqan_WA_Bot::defaults();
        $out = Mutqan_WA_Bot::decide($cfg, ['bot' => ['greeted' => true]], $text, time(), 'text');
        $kb = Mutqan_KB::pick($text, 'customer');
        return ['rules' => (array) ($out['replies'] ?? []), 'kb' => array_map(function ($r) { return ['title' => $r['title'], 'body' => mb_substr($r['body'], 0, 300)]; }, array_slice((array) $kb, 0, 4)),
            'learned' => Mutqan_Brain::recall($text), 'unanswered' => empty($out['replies'])];
    }

    /* ------------------------------------------------------------------ execution */

    /** A command in plain words: the internal engine first; anything else is reported back so Claude picks a tool. */
    public static function run($text, $p) {
        $text = self::clean($text, 500);
        if ($text === '') { throw new Mutqan_Error('empty', 'اكتب الأمر.', 400); }
        $q = Mutqan_Quick::try_run($text, $p, 'agent');
        if ($q) { self::audit('run', $text); return $q; }
        return ['text' => '', 'unknown' => true, 'hint' => 'الأمر مش من الأوامر الجاهزة — استخدم أداة بالاسم (tools) أو اطلب تدخل Claude Code.'];
    }

    /** One tool from the control assistant's closed list. Changes that matter wait for «confirm». */
    public static function tool($name, $input, $p) {
        if (!in_array($name, array_column(Mutqan_Agent::tools(), 'name'), true)) { throw new Mutqan_Error('bad_tool', 'أداة غير معروفة: ' . $name, 400); }
        if (Mutqan_Agent::needs_confirm($name, $input)) {
            $pend = Mutqan_Agent::park_public($name, $input, $p);
            return ['pending' => $pend, 'text' => 'محتاج تأكيد: ' . $pend['summary']];
        }
        $out = Mutqan_Agent::run_tool($name, $input, $p);
        Mutqan_Agent::audit_public($name, $input, true, $p);
        $text = Mutqan_Quick::say($name, is_array($out) ? $out : []);
        return ['result' => mb_substr(wp_json_encode($out, JSON_UNESCAPED_UNICODE), 0, 12000), 'text' => $text];
    }

    /**
     * The VPS gateway, enrolled by the operator over the API (Hostinger Docker Manager) — never a pasted command.
     * One device named «سيرفر VPS (تلقائي)»: created once, given a fresh key on every enrollment (so a key seen anywhere
     * stops working), and returned as the environment for wa-gateway/docker-compose.yml.
     */
    public static function vps_enroll() {
        $name = 'سيرفر VPS (تلقائي)';
        $id = '';
        foreach (Mutqan_WA_Fleet::devices() as $did => $d) { if (($d['name'] ?? '') === $name) { $id = (string) $did; break; } }
        $v = $id !== '' ? Mutqan_WA_Fleet::rotate_device($id) : Mutqan_WA_Fleet::register_device(['name' => $name, 'kind' => 'vps'], self::owner());
        $d = Mutqan_WA_Fleet::devices()[$v['id']];
        $env = "GATEWAY_TOKEN={$d['token']}\nWEBHOOK_URL=" . Mutqan_WA::hook_url() . "\nWEBHOOK_SECRET={$d['secret']}\nDEVICE_ID={$v['id']}\nSEND_DAILY_CAP=" . (int) Mutqan_WA_Fleet::settings()['dailyCap'] . "\n";
        self::audit('vps_enroll', $v['id']);
        return ['device' => $v['id'], 'name' => $name, 'environment' => $env,
            'others' => array_values(array_map(function ($x) { return $x; }, array_diff(array_keys(Mutqan_WA_Fleet::devices()), [$v['id']])))];
    }

    /* ------------------------------------------------------------------ Claude Code stepping in */

    public static function ticket_add($in) {
        $kind = in_array($in['kind'] ?? '', ['teach', 'exec', 'fix'], true) ? $in['kind'] : 'fix';
        $title = self::clean($in['title'] ?? '', 120);
        if ($title === '') { throw new Mutqan_Error('empty', 'اكتب عنوان الطلب.', 400); }
        $t = Mutqan_Store::insert(self::TICKETS, ['kind' => $kind, 'title' => $title, 'detail' => self::clean($in['detail'] ?? '', 4000), 'status' => 'open',
            'session' => self::clean($in['session'] ?? '', 200)], null, [], self::sys());
        $labels = ['teach' => 'تعليم', 'exec' => 'تنفيذ', 'fix' => 'حل مشكلة'];
        if (class_exists('Mutqan_Rest')) { Mutqan_Rest::admin_alert('🧑‍💻 طلب تدخل Claude Code (' . $labels[$kind] . ')', $title, '#/staff/admin', 'coach_ticket', 'normal'); }
        return ['id' => (string) $t['id']];
    }

    public static function ticket_update($id, $in) {
        $t = Mutqan_Store::get_raw(self::TICKETS, $id);
        if (!$t) { throw new Mutqan_Error('not_found', 'الطلب مش موجود.', 404); }
        $patch = [];
        if (in_array($in['status'] ?? '', ['open', 'working', 'done', 'cancelled'], true)) { $patch['status'] = $in['status']; }
        foreach (['result' => 4000, 'session' => 200] as $k => $n) { if (isset($in[$k])) { $patch[$k] = self::clean($in[$k], $n); } }
        Mutqan_Store::update(self::TICKETS, $id, $patch, self::sys());
        return ['ok' => true];
    }

    private static function audit($what, $detail) {
        try {
            Mutqan_Store::insert('_ai_audit', ['at' => gmdate('c'), 'action' => 'coach:' . $what, 'by' => 'coach', 'ok' => true, 'confirmed' => true,
                'detail' => mb_substr((string) $detail, 0, 600)], null, [], self::sys());
        } catch (Throwable $e) { /* never block */ }
    }
}
