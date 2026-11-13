<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «أجهزة الواتساب» — every desk device can carry WhatsApp numbers, and every number has a job.
 *
 * There was one gateway, one secret, and whichever number happened to be connected sent everything: the
 * customer's order copy, the technician's job card, the login code. A second phone could not join, a number
 * could not be told it was the complaints line, and when the one phone went quiet nothing said so.
 *
 * Now:
 *
 *   - Any device with the desk app is registered as a management device by the owner or operations. It gets
 *     its own key and a one-paste setup that runs the WhatsApp Web gateway on it (Termux on Android, or any
 *     machine with Node). A device's key can be revoked on its own without touching the others.
 *   - Numbers are added from the screen, onto a chosen device: the device collects the order on its next
 *     check-in, opens the number, and the QR comes back to the screen to be scanned by the WhatsApp being
 *     linked (WhatsApp ← الأجهزة المرتبطة ← ربط جهاز). A pairing code works when no second screen is near.
 *   - Each number has a role — customers, technicians, complaints, marketing, reports, articles, site
 *     technician — and every automatic message leaves from the number whose role it is, through the device
 *     that carries that number. Nothing goes out from a number that is not connected.
 *   - Every incoming conversation is tagged (order, complaint, technician, marketing…), a complaint raises the
 *     operations room whichever number it reached, idle conversations are archived, and a device or number
 *     that goes quiet is reported — hourly, by the server, with nobody watching.
 */
final class Mutqan_WA_Fleet {

    const DEVICES = 'mutqan_wa_devices';
    const ROLES_OPT = 'mutqan_wa_roles';
    const CMDS = 'mutqan_wa_cmds';
    const OPT = 'mutqan_wa_fleet';
    const CRON = 'mutqan_wa_fleet_tick';
    const ONLINE = 180;            // a device that checked in within three minutes is online
    const OFFLINE_ALERT = 900;     // quiet for fifteen minutes is worth telling someone

    const ROLES = [
        'customers'   => ['label' => 'العملاء والطلبات', 'icon' => '🛎️', 'by' => ['order', 'otp', 'status', 'customer']],
        'technicians' => ['label' => 'الفنيين', 'icon' => '🧑‍🔧', 'by' => ['dispatch', 'tech']],
        'complaints'  => ['label' => 'الشكاوى والضمان', 'icon' => '⚠️', 'by' => ['complaint', 'warranty']],
        'marketing'   => ['label' => 'الدعاية والعروض', 'icon' => '📣', 'by' => ['marketing', 'promo']],
        'reports'     => ['label' => 'التقارير للإدارة', 'icon' => '📊', 'by' => ['report', 'digest']],
        'articles'    => ['label' => 'المقالات والمحتوى', 'icon' => '📝', 'by' => ['article', 'content']],
        'site_tech'   => ['label' => 'فني الموقع', 'icon' => '🛠️', 'by' => ['site_tech', 'site']],
        'general'     => ['label' => 'عام', 'icon' => '💬', 'by' => []],
    ];

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user']; }

    public static function boot() {
        add_action(self::CRON, [__CLASS__, 'tick']);
        add_action('init', function () { if (function_exists('wp_next_scheduled') && !wp_next_scheduled(self::CRON)) { wp_schedule_event(time() + 600, 'hourly', self::CRON); } }, 8);
    }

    /* ------------------------------------------------------------------ settings */

    public static function settings() {
        $s = get_option(self::OPT, []); $s = is_array($s) ? $s : [];
        return array_merge(['archiveDays' => 21, 'reportTo' => [], 'aiTagging' => 0, 'complaintAlert' => 1, 'healthAlerts' => 1,
            'repo' => 'https://github.com/alizizo888-code/alizeidan123.git', 'branch' => 'wa-gateway', 'subdir' => '', 'dailyCap' => 200], $s);
    }

    public static function save_settings($in) {
        $s = self::settings();
        if (isset($in['archiveDays'])) { $s['archiveDays'] = max(3, min(365, (int) $in['archiveDays'])); }
        foreach (['aiTagging', 'complaintAlert', 'healthAlerts'] as $k) { if (isset($in[$k])) { $s[$k] = $in[$k] ? 1 : 0; } }
        if (isset($in['dailyCap'])) { $s['dailyCap'] = max(20, min(1000, (int) $in['dailyCap'])); }
        if (isset($in['reportTo'])) {
            $list = is_array($in['reportTo']) ? $in['reportTo'] : preg_split('/[\s,،]+/u', (string) $in['reportTo']);
            $s['reportTo'] = array_values(array_unique(array_filter(array_map(function ($p) { return Mutqan_WA::intl($p); }, (array) $list)))); $s['reportTo'] = array_slice($s['reportTo'], 0, 10);
        }
        if (isset($in['repo']) && preg_match('#^https://[\w.-]+/[\w./-]+$#', (string) $in['repo'])) { $s['repo'] = (string) $in['repo']; }
        if (isset($in['branch']) && preg_match('/^[\w.\/-]{1,80}$/', (string) $in['branch'])) { $s['branch'] = (string) $in['branch']; }
        if (isset($in['subdir'])) { $s['subdir'] = preg_match('/^[\w.\/-]{0,80}$/', (string) $in['subdir']) ? trim((string) $in['subdir'], '/') : $s['subdir']; }
        update_option(self::OPT, $s, false);
        return $s;
    }

    /* ------------------------------------------------------------------ devices */

    public static function devices() { $d = get_option(self::DEVICES, []); return is_array($d) ? $d : []; }
    private static function put_devices($d) { update_option(self::DEVICES, $d, false); }

    private static function slug($s) { return substr(strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $s)), 0, 12); }

    /** Registers this device as a management device: its own key, and the one paste that starts its gateway. */
    public static function register_device($in, $p) {
        $name = mb_substr(trim(wp_strip_all_tags((string) ($in['name'] ?? ''))), 0, 40) ?: 'جهاز إدارة';
        $d = self::devices();
        if (count($d) >= 20) { throw new Mutqan_Error('too_many', 'وصلت للحد الأقصى للأجهزة (20). ألغِ جهازًا قديمًا أولًا.', 400); }
        $id = 'd' . substr(bin2hex(random_bytes(4)), 0, 6);
        $d[$id] = ['name' => $name, 'secret' => bin2hex(random_bytes(24)), 'token' => bin2hex(random_bytes(16)), 'enabled' => 1,
            'createdAt' => gmdate('c'), 'by' => (string) ($p['name'] ?? ($p['id'] ?? '')), 'lastSeen' => 0, 'agent' => mb_substr((string) ($in['agent'] ?? ''), 0, 120)];
        self::put_devices($d);
        return self::device_view($id, true);
    }

    public static function revoke_device($id) {
        $d = self::devices();
        if (!isset($d[$id])) { throw new Mutqan_Error('not_found', 'الجهاز غير موجود.', 404); }
        unset($d[$id]); self::put_devices($d);
        $c = get_option(self::CMDS, []); if (is_array($c)) { unset($c[$id]); update_option(self::CMDS, $c, false); }
        return ['ok' => true];
    }

    /** The paste that turns a phone (Termux) or a computer into this device's WhatsApp gateway. */
    public static function setup($id) {
        $d = self::devices()[$id] ?? null;
        if (!$d) { return ''; }
        $s = self::settings();
        $dir = $s['subdir'] !== '' ? 'mq-wa/' . $s['subdir'] : 'mq-wa';
        $env = "GATEWAY_TOKEN={$d['token']}\nWEBHOOK_URL=" . Mutqan_WA::hook_url() . "\nWEBHOOK_SECRET={$d['secret']}\nDEVICE_ID={$id}\nDATA_DIR=./data\nSEND_DAILY_CAP={$s['dailyCap']}\n";
        return "pkg update -y && pkg install -y nodejs-lts git\n"
            . "git clone --depth 1 -b {$s['branch']} {$s['repo']} mq-wa || (cd mq-wa && git pull)\n"
            . "cd {$dir} && npm ci --omit=dev\n"
            . "cat > .env <<'EOF'\n{$env}EOF\n"
            . "termux-wake-lock; set -a && . ./.env && set +a && node src/index.js";
    }

    public static function device_view($id, $withSecret = false) {
        $d = self::devices()[$id] ?? null;
        if (!$d) { return null; }
        $nums = array_values(array_filter(self::numbers(), function ($n) use ($id) { return $n['device'] === $id; }));
        $v = ['id' => $id, 'name' => $d['name'], 'enabled' => !empty($d['enabled']), 'createdAt' => $d['createdAt'], 'by' => $d['by'] ?? '',
            'lastSeen' => (int) $d['lastSeen'], 'online' => (int) $d['lastSeen'] > 0 && time() - (int) $d['lastSeen'] < self::ONLINE,
            'seenAgo' => (int) $d['lastSeen'] ? time() - (int) $d['lastSeen'] : null, 'numbers' => count($nums),
            'pending' => count((array) ((get_option(self::CMDS, [])[$id] ?? [])))];
        // The setup carries the device's key, so it leaves only on an explicit request by someone allowed to install.
        if ($withSecret) { $v['secret'] = $d['secret']; $v['setup'] = self::setup($id); }
        return $v;
    }

    /** Which device signed this body: a device id, 'legacy' for the original single gateway, or null. */
    public static function signer($raw, $sig) {
        if (!is_string($sig) || $sig === '') { return null; }
        $c = Mutqan_WA::cfg();
        if (hash_equals(hash_hmac('sha256', $raw, $c['secret']), $sig)) { return 'legacy'; }
        foreach (self::devices() as $id => $d) {
            if (!empty($d['enabled']) && !empty($d['secret']) && hash_equals(hash_hmac('sha256', $raw, $d['secret']), $sig)) { return (string) $id; }
        }
        return null;
    }

    public static function touch($dev, $report = []) {
        if ($dev === 'legacy' || $dev === null) { return; }
        $d = self::devices(); if (!isset($d[$dev])) { return; }
        $d[$dev]['lastSeen'] = time();
        if (is_array($report) && $report) { $d[$dev]['sessions'] = array_slice(array_map(function ($s) { return ['id' => (string) ($s['id'] ?? ''), 'status' => (string) ($s['status'] ?? ''), 'phone' => (string) ($s['phone'] ?? '')]; }, $report), 0, 20); }
        self::put_devices($d);
    }

    /* ------------------------------------------------------------------ numbers and their jobs */

    public static function roles() { $r = get_option(self::ROLES_OPT, []); return is_array($r) ? $r : []; }

    /** Every number we know of, with its device, its role and how it is doing. */
    public static function numbers() {
        $seen = get_option(Mutqan_WA::SEEN, []); $seen = is_array($seen) ? $seen : [];
        $roles = self::roles(); $out = []; $devs = self::devices();
        foreach ($seen as $acct => $r) {
            $age = time() - (int) ($r['at'] ?? 0);
            $st = (string) ($r['status'] ?? '');
            $fresh = $age < Mutqan_WA::QR_FRESH;
            $out[] = ['id' => (string) $acct, 'label' => (string) ($r['label'] ?? $acct), 'phone' => (string) ($r['phone'] ?? ''), 'name' => (string) ($r['name'] ?? ''),
                'device' => (string) ($roles[$acct]['device'] ?? ($r['dev'] ?? '')), 'role' => (string) ($roles[$acct]['role'] ?? 'general'),
                'status' => $st === 'open' ? 'open' : ($fresh ? $st : ($st === 'logged_out' ? 'logged_out' : 'stopped')),
                'qr' => $fresh ? ($r['qr'] ?? null) : null, 'pairingCode' => $fresh ? ($r['pairingCode'] ?? null) : null, 'seenAgo' => $age];
            // A number is only as reachable as the device carrying it: nothing is routed to a phone that went quiet.
            $dv = $out[count($out) - 1]['device'];
            if ($dv !== '' && isset($devs[$dv]) && $out[count($out) - 1]['status'] === 'open' && (empty($devs[$dv]['enabled']) || time() - (int) $devs[$dv]['lastSeen'] > self::OFFLINE_ALERT)) { $out[count($out) - 1]['status'] = 'offline'; }
        }
        // A number ordered onto a device that has not reported yet.
        foreach ($roles as $acct => $x) {
            if (!isset($seen[$acct]) && !empty($x['device'])) { $out[] = ['id' => (string) $acct, 'label' => (string) ($x['label'] ?? $acct), 'phone' => '', 'name' => '', 'device' => (string) $x['device'], 'role' => (string) ($x['role'] ?? 'general'), 'status' => 'pending', 'qr' => null, 'pairingCode' => null, 'seenAgo' => null]; }
        }
        return $out;
    }

    public static function set_role($acct, $role) {
        if (!isset(self::ROLES[$role])) { throw new Mutqan_Error('bad_role', 'دور غير معروف.', 400); }
        $r = self::roles(); $r[$acct] = array_merge((array) ($r[$acct] ?? []), ['role' => $role]);
        update_option(self::ROLES_OPT, $r, false);
        return ['ok' => true];
    }

    private static function queue_cmd($dev, $cmd) {
        $c = get_option(self::CMDS, []); $c = is_array($c) ? $c : [];
        $cmd['cid'] = 'c' . substr(bin2hex(random_bytes(5)), 0, 8); $cmd['at'] = time();
        $c[$dev] = array_slice(array_merge((array) ($c[$dev] ?? []), [$cmd]), -20);
        update_option(self::CMDS, $c, false);
        return $cmd['cid'];
    }

    /** A new number on a device: the device opens it on its next check-in and the QR comes back here. */
    public static function add_number($in) {
        $dev = (string) ($in['device'] ?? '');
        if (!isset(self::devices()[$dev])) { throw new Mutqan_Error('bad_device', 'اختار جهاز مسجّل.', 400); }
        $role = isset(self::ROLES[$in['role'] ?? '']) ? $in['role'] : 'general';
        $mode = ($in['mode'] ?? '') === 'code' ? 'code' : 'qr';
        $phone = Mutqan_WA::intl($in['phone'] ?? '');
        if ($mode === 'code' && $phone === '') { throw new Mutqan_Error('bad_phone', 'اكتب رقم الواتساب اللي هيترَبط (05xxxxxxxx).', 400); }
        $label = mb_substr(trim(wp_strip_all_tags((string) ($in['label'] ?? ''))), 0, 40) ?: self::ROLES[$role]['label'];
        $acct = $dev . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
        $r = self::roles(); $r[$acct] = ['role' => $role, 'device' => $dev, 'label' => $label, 'createdAt' => gmdate('c')];
        update_option(self::ROLES_OPT, $r, false);
        self::queue_cmd($dev, ['op' => 'add', 'id' => $acct, 'label' => $label, 'mode' => $mode, 'phone' => $phone]);
        return ['ok' => true, 'id' => $acct];
    }

    public static function remove_number($acct) {
        $r = self::roles(); $dev = (string) ($r[$acct]['device'] ?? '');
        $seen = get_option(Mutqan_WA::SEEN, []); if ($dev === '' && is_array($seen)) { $dev = (string) ($seen[$acct]['dev'] ?? ''); }
        if ($dev !== '' && isset(self::devices()[$dev])) { self::queue_cmd($dev, ['op' => 'remove', 'id' => $acct]); }
        unset($r[$acct]); update_option(self::ROLES_OPT, $r, false);
        if (is_array($seen)) { unset($seen[$acct]); update_option(Mutqan_WA::SEEN, $seen, false); }
        return ['ok' => true];
    }

    public static function restart_number($acct) {
        $dev = (string) (self::roles()[$acct]['device'] ?? ((get_option(Mutqan_WA::SEEN, [])[$acct]['dev'] ?? '')));
        if ($dev === '' || !isset(self::devices()[$dev])) { throw new Mutqan_Error('no_device', 'الرقم ده مش على جهاز مسجّل — أعد تشغيله من الجهاز نفسه.', 409); }
        self::queue_cmd($dev, ['op' => 'restart', 'id' => $acct]);
        return ['ok' => true];
    }

    /** What a device is asked to do on this check-in; acknowledged ones are dropped. */
    public static function cmds_for($dev, $done = []) {
        if ($dev === null || $dev === 'legacy') { return []; }
        $c = get_option(self::CMDS, []); $c = is_array($c) ? $c : [];
        $mine = (array) ($c[$dev] ?? []);
        $done = array_map('strval', (array) $done);
        $left = array_values(array_filter($mine, function ($x) use ($done) { return !in_array((string) ($x['cid'] ?? ''), $done, true) && time() - (int) ($x['at'] ?? 0) < 7 * 86400; }));
        if (count($left) !== count($mine)) { $c[$dev] = $left; update_option(self::CMDS, $c, false); }
        return array_map(function ($x) { return array_intersect_key($x, array_flip(['cid', 'op', 'id', 'label', 'mode', 'phone'])); }, $left);
    }

    /** The device that carries a number ('' when unknown). */
    public static function device_of($acct) {
        $r = self::roles(); if (!empty($r[$acct]['device'])) { return (string) $r[$acct]['device']; }
        $seen = get_option(Mutqan_WA::SEEN, []); return is_array($seen) ? (string) ($seen[$acct]['dev'] ?? '') : '';
    }

    /** Which connected number an automatic message of this kind leaves from. '' = none of the fleet (old path decides). */
    public static function account_for($by) {
        $want = 'general';
        foreach (self::ROLES as $role => $x) { if (in_array((string) $by, $x['by'], true)) { $want = $role; break; } }
        $open = array_values(array_filter(self::numbers(), function ($n) { return $n['status'] === 'open'; }));
        if (!$open) { return ''; }
        foreach ([$want, 'customers', 'general'] as $role) {
            $hit = array_values(array_filter($open, function ($n) use ($role) { return $n['role'] === $role; }));
            if ($hit) { return $hit[abs(crc32((string) $by . date('YmdH'))) % count($hit)]['id']; }
        }
        // Never send a customer message from the technicians' or the reports' line; any other open number will do.
        $any = array_values(array_filter($open, function ($n) { return !in_array($n['role'], ['technicians', 'reports', 'marketing'], true); }));
        return $any ? $any[0]['id'] : '';
    }

    /** Whether a queued job may be handed to this device. */
    public static function job_for_device($job, $dev) {
        $acct = (string) ($job['acct'] ?? '');
        $owner = $acct === '' ? '' : self::device_of($acct);
        if ($dev === 'legacy' || $dev === null) { return $owner === '' || !isset(self::devices()[$owner]); }
        if ($owner !== '') { return $owner === $dev; }
        // An unaddressed job goes to a device that has a connected number.
        foreach (self::numbers() as $n) { if ($n['device'] === $dev && $n['status'] === 'open') { return true; } }
        return false;
    }

    /* ------------------------------------------------------------------ incoming: tag, route, alert */

    const TAGS = [
        'complaint' => ['شكوى', 'اشتكي', 'زعلان', 'سيء', 'سيئ', 'مش راضي', 'مو راضي', 'نصب', 'حرامي', 'استرجاع', 'فلوسي', 'خربتو', 'باظ', 'خرب', 'ما اشتغل', 'مشتغلش', 'ضمان', 'رجعو', 'متاخر', 'اتاخر'],
        'order' => ['ابغى', 'ابي', 'عايز', 'محتاج', 'غسيل', 'صيانه', 'تركيب', 'مكيف', 'سعر', 'بكم', 'كم'],
        'tech' => ['وصلت', 'خلصت', 'تم انجاز', 'فني', 'المهمه', 'الطلب رقم'],
        'marketing' => ['عرض', 'اعلان', 'تسويق', 'خصم'],
    ];

    public static function tag($text) {
        $n = class_exists('Mutqan_Playbook') ? Mutqan_Playbook::norm($text) : mb_strtolower((string) $text);
        foreach (self::TAGS as $tag => $words) { foreach ($words as $w) { $w = class_exists('Mutqan_Playbook') ? Mutqan_Playbook::norm($w) : $w; if ($w !== '' && mb_strpos($n, $w) !== false) { return $tag; } } }
        return 'general';
    }

    /** Called for every new incoming message, after it is stored. */
    public static function on_incoming($acct, $phone, $text, $tid) {
        try {
            $s = self::settings();
            $role = (string) (self::roles()[$acct]['role'] ?? 'general');
            $tag = self::tag($text);
            if ($tag === 'general' && !empty($s['aiTagging']) && class_exists('Mutqan_AI') && Mutqan_AI::any_enabled() && mb_strlen($text) > 12) {
                try { $r = Mutqan_AI::run("صنّف رسالة عميل شركة صيانة في كلمة واحدة من: complaint, order, tech, marketing, general.\nالرسالة: " . mb_substr($text, 0, 400) . "\nأعد JSON: {\"tag\":\"...\"}", ['json' => true, 'temperature' => 0, 'feature' => 'wa_tag', 'kb' => false], self::sys()); $t = (string) ($r['json']['tag'] ?? ''); if (isset(self::TAGS[$t])) { $tag = $t; } } catch (Throwable $e) {}
            }
            $th = Mutqan_Store::get_raw('wa_threads', $tid) ?: [];
            $tags = array_values(array_unique(array_merge((array) ($th['tags'] ?? []), $tag === 'general' ? [] : [$tag])));
            Mutqan_Store::update('wa_threads', $tid, ['tags' => array_slice($tags, -5), 'role' => $role, 'device' => self::device_of($acct), 'archived' => false], self::sys());
            if (($tag === 'complaint' || $role === 'complaints') && !empty($s['complaintAlert'])) {
                $who = ($th['name'] ?? '') ?: $phone;
                if (class_exists('Mutqan_Rest')) { Mutqan_Rest::admin_alert('⚠️ شكوى واتساب — ' . $who, mb_substr($text, 0, 240) . "\n(وصلت على رقم: " . (self::numbers_label($acct)) . ')', '#/staff/wa-inbox', 'wa_complaint', 'high'); }
            }
            return $tag;
        } catch (Throwable $e) { error_log('[mutqan] fleet incoming: ' . $e->getMessage()); return 'general'; }
    }

    private static function numbers_label($acct) { foreach (self::numbers() as $n) { if ($n['id'] === $acct) { return $n['label'] . ($n['phone'] ? ' ' . $n['phone'] : ''); } } return $acct; }

    /* ------------------------------------------------------------------ the hourly round: health, archive, reports */

    public static function tick() {
        $issues = [];
        try {
            $s = self::settings();
            foreach (self::devices() as $id => $d) {
                $ago = (int) $d['lastSeen'] ? time() - (int) $d['lastSeen'] : null;
                if (!empty($d['enabled']) && $ago !== null && $ago > self::OFFLINE_ALERT) {
                    $issues[] = 'الجهاز «' . $d['name'] . '» ما اتصلش من ' . (int) floor($ago / 60) . ' دقيقة — افتحه وتأكد إن الوسيط شغال.';
                    if (!empty($s['healthAlerts'])) { Mutqan_WA::notify_admins('جهاز واتساب متوقف', end($issues), 'fleet-dev-' . $id, 7200); }
                }
            }
            foreach (self::numbers() as $n) {
                if (in_array($n['status'], ['logged_out', 'stopped'], true) && $n['device'] !== '') {
                    $issues[] = 'الرقم «' . $n['label'] . '» (' . (self::ROLES[$n['role']]['label'] ?? $n['role']) . ') غير متصل.';
                    if (!empty($s['healthAlerts'])) { Mutqan_WA::notify_admins('رقم واتساب غير متصل', end($issues), 'fleet-num-' . $n['id'], 7200); }
                }
            }
            $missing = array_diff(['customers', 'technicians'], array_map(function ($n) { return $n['role']; }, array_filter(self::numbers(), function ($n) { return $n['status'] === 'open'; })));
            foreach ($missing as $r) { $issues[] = 'مفيش رقم متصل لدور «' . self::ROLES[$r]['label'] . '» — رسايله هتطلع من رقم تاني.'; }
            $issues[] = 'اتأرشفت ' . self::archive((int) $s['archiveDays']) . ' محادثة خاملة.';
        } catch (Throwable $e) { error_log('[mutqan] fleet tick: ' . $e->getMessage()); }
        update_option('mutqan_wa_fleet_health', ['at' => gmdate('c'), 'issues' => $issues], false);
        return $issues;
    }

    /** Conversations nobody has written in for $days are archived (kept, just out of the inbox). */
    public static function archive($days) {
        $cut = time() - max(1, $days) * 86400; $n = 0;
        foreach (Mutqan_Store::select('wa_threads', [], ['limit' => 300, 'order' => 'updatedAt'], self::sys()) as $th) {
            if (!empty($th['archived'])) { continue; }
            $t = strtotime((string) ($th['lastAt'] ?? ($th['updatedAt'] ?? ''))) ?: 0;
            if ($t && $t < $cut && (int) ($th['unread'] ?? 0) === 0) { Mutqan_Store::update('wa_threads', (string) $th['id'], ['archived' => true, 'status' => 'archived'], self::sys()); $n++; }
        }
        return $n;
    }

    /** A report to the people the owner listed, from the reports number. */
    public static function send_report($text) {
        $to = (array) self::settings()['reportTo']; $sent = 0;
        foreach ($to as $ph) { if (Mutqan_WA::auto_send($ph, $text, 'report')) { $sent++; } }
        return $sent;
    }

    /* ------------------------------------------------------------------ POST /wa {op: fleet_*} */

    public static function handle($op, $in, $p) {
        $owner = ($p['role'] ?? '') === 'owner';
        $write = Mutqan_Policy::has_power($p, 'write');
        switch ($op) {
            case 'fleet':
                $h = get_option('mutqan_wa_fleet_health', []);
                return ['devices' => array_values(array_filter(array_map(function ($id) { return self::device_view($id); }, array_keys(self::devices())))),
                    'numbers' => self::numbers(), 'roles' => array_map(function ($r) { return ['label' => $r['label'], 'icon' => $r['icon']]; }, self::ROLES),
                    'settings' => self::settings(), 'health' => is_array($h) ? $h : [], 'legacy' => Mutqan_WA::config_get()['onPhone'] ? 'phone' : 'url'];
        }
        if (!$write) { throw new Mutqan_Error('forbidden', 'غير مسموح.', 403); }
        switch ($op) {
            case 'fleet_register': return self::register_device($in, $p);
            case 'fleet_device': $v = self::device_view((string) ($in['id'] ?? ''), true); if (!$v) { throw new Mutqan_Error('not_found', 'الجهاز غير موجود.', 404); } return $v;
            case 'fleet_revoke': return self::revoke_device((string) ($in['id'] ?? ''));
            case 'fleet_add': return self::add_number($in);
            case 'fleet_remove': return self::remove_number((string) ($in['id'] ?? ''));
            case 'fleet_restart': return self::restart_number((string) ($in['id'] ?? ''));
            case 'fleet_role': return self::set_role((string) ($in['id'] ?? ''), (string) ($in['role'] ?? ''));
            case 'fleet_check': return ['issues' => self::tick()];
            case 'fleet_save':
                if (!$owner) { throw new Mutqan_Error('forbidden', 'للمالك فقط.', 403); }
                return self::save_settings((array) ($in['settings'] ?? []));
        }
        throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400);
    }
}
