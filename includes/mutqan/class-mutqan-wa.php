<?php
if (!defined('ABSPATH')) { exit; }

/**
 * WhatsApp hub: talks to the self-hosted wa-gateway (QR / pairing-code sessions), keeps threads + messages
 * in the docs store (collections wa_threads / wa_msgs, readable only by owner + operations), and runs a free
 * rule-based reception bot (no AI credits, no Cloud API).
 */
final class Mutqan_WA {
    const OPT = 'mutqan_wa_gw';

    public static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user']; }

    /* ---------------------------------------------------------------- config */

    public static function cfg() {
        $c = get_option(self::OPT, []);
        $c = is_array($c) ? $c : [];
        if (empty($c['secret'])) { $c['secret'] = bin2hex(random_bytes(24)); update_option(self::OPT, $c, false); }
        return $c + ['url' => '', 'token' => ''];
    }
    public static function hook_url() { return rest_url(Mutqan_Rest::NS . '/wa-hook'); }
    public static function pull_url() { return rest_url(Mutqan_Rest::NS . '/wa-pull'); }

    const SEEN = 'mutqan_wa_seen';      // what each number last told us about itself
    const QR_FRESH = 180;               // a WhatsApp QR is only good for about a minute

    /**
     * The numbers, and how each one is doing.
     *
     * A gateway with an address is asked directly, as before. A gateway on a phone cannot be asked
     * anything, so the screen shows what the phone last reported — including the QR image itself,
     * which is the whole point: the code has to appear somewhere other than the handset displaying
     * it, or there is no second screen to scan it with.
     */
    public static function sessions() {
        if (self::cfg()['url'] !== '') { return self::gw('GET', '/sessions'); }
        $seen = get_option(self::SEEN, []);
        $out = [];
        foreach ((is_array($seen) ? $seen : []) as $id => $r) {
            $age = time() - (int) ($r['at'] ?? 0);
            $stale = $age > self::QR_FRESH;
            $out[] = [
                'id' => (string) $id, 'label' => (string) ($r['label'] ?? $id), 'mode' => 'qr',
                'phone' => (string) ($r['phone'] ?? ''), 'name' => (string) ($r['name'] ?? ''),
                'status' => $stale && ($r['status'] ?? '') !== 'open' ? 'stopped' : (string) ($r['status'] ?? ''),
                // An expired code is worse than none: it is scanned, it fails, and nobody knows why.
                'qr' => $stale ? null : ($r['qr'] ?? null),
                'pairingCode' => $stale ? null : ($r['pairingCode'] ?? null),
                'sentToday' => 0, 'dailyCap' => 0, 'via' => 'phone', 'seenAgo' => $age,
            ];
        }
        return ['sessions' => $out, 'via' => 'phone'];
    }

    private static function remember_session($acct, $e, $dev = null) {
        $seen = get_option(self::SEEN, []);
        $seen = is_array($seen) ? $seen : [];
        $row = isset($seen[$acct]) && is_array($seen[$acct]) ? $seen[$acct] : [];
        $row['status'] = (string) ($e['status'] ?? '');
        $row['at'] = time();
        foreach (['label', 'phone', 'name'] as $k) { if (!empty($e[$k])) { $row[$k] = (string) $e[$k]; } }
        if ($dev !== null && $dev !== 'legacy') { $row['dev'] = (string) $dev; }
        // Only a picture, and only a small one: this goes in an option read on every screen.
        $qr = (string) ($e['qr'] ?? '');
        $row['qr'] = (strpos($qr, 'data:image/') === 0 && strlen($qr) < 60000) ? $qr : null;
        $row['pairingCode'] = !empty($e['pairingCode']) ? mb_substr((string) $e['pairingCode'], 0, 16) : null;
        if (($e['status'] ?? '') === 'open') { $row['qr'] = null; $row['pairingCode'] = null; }
        $seen[$acct] = $row;
        if (count($seen) > 40) { $seen = array_slice($seen, -40, null, true); }
        update_option(self::SEEN, $seen, false);
    }

    private static function forget_session($acct) {
        $seen = get_option(self::SEEN, []);
        if (is_array($seen) && isset($seen[$acct])) { unset($seen[$acct]); update_option(self::SEEN, $seen, false); }
    }

    public static function config_get() {
        $c = self::cfg();
        return ['url' => $c['url'], 'hasToken' => $c['token'] !== '', 'secret' => $c['secret'],
            'hookUrl' => self::hook_url(), 'pullUrl' => self::pull_url(), 'onPhone' => $c['url'] === ''];
    }
    public static function config_set($in) {
        $c = self::cfg();
        $url = trim((string) ($in['url'] ?? $c['url']));
        if ($url !== '' && !preg_match('#^https?://[^\s/]+(:\d+)?(/.*)?$#i', $url)) { throw new Mutqan_Error('bad_url', 'رابط الوسيط غير صالح.', 400); }
        $c['url'] = rtrim($url, '/');
        if (!empty($in['token'])) { $c['token'] = trim((string) $in['token']); }
        if (!empty($in['rotateSecret'])) { $c['secret'] = bin2hex(random_bytes(24)); }
        update_option(self::OPT, $c, false);
        return self::config_get();
    }

    private static function gw($method, $path, $body = null, $timeout = 25) {
        $c = self::cfg();
        if ($c['url'] === '' || $c['token'] === '') { throw new Mutqan_Error('gw_not_set', 'اربط الوسيط أولًا (رابط + مفتاح).', 400); }
        $args = ['method' => $method, 'timeout' => $timeout, 'headers' => ['Authorization' => 'Bearer ' . $c['token'], 'Content-Type' => 'application/json']];
        if ($body !== null) { $args['body'] = wp_json_encode($body); }
        $res = wp_remote_request($c['url'] . $path, $args);
        if (is_wp_error($res)) { throw new Mutqan_Error('gw_down', 'تعذر الوصول للوسيط: ' . $res->get_error_message(), 502); }
        $code = (int) wp_remote_retrieve_response_code($res);
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if ($code >= 400) { throw new Mutqan_Error('gw_error', is_array($data) && !empty($data['error']) ? (string) $data['error'] : 'خطأ من الوسيط', $code === 401 ? 400 : ($code >= 500 ? 502 : $code)); }
        return is_array($data) ? $data : [];
    }

    /* ---------------------------------------------------------------- admin ops (POST /wa) */

    public static function handle($in, $p) {
        if (!Mutqan_Policy::has_power($p, 'read')) { throw new Mutqan_Error('forbidden', 'للمالك ومشرف العمليات فقط.', 403); }
        $op = (string) ($in['op'] ?? '');
        $write = Mutqan_Policy::has_power($p, 'write');
        switch ($op) {
            case 'config_get': return self::config_get();
            case 'config_set': if (($p['role'] ?? '') !== 'owner') { throw new Mutqan_Error('forbidden', 'للمالك فقط.', 403); } return self::config_set($in);
            case 'sessions': return self::sessions();
        }
        // «أجهزة الواتساب»: devices, numbers and their roles (Mutqan_WA_Fleet).
        if (strpos($op, 'fleet') === 0 && class_exists('Mutqan_WA_Fleet')) { if ($op !== 'fleet') { Mutqan_Limiter::hit('wa_ops', 300, 3600); } return Mutqan_WA_Fleet::handle($op, $in, $p); }
        if (!$write) { throw new Mutqan_Error('forbidden', 'غير مسموح.', 403); }
        Mutqan_Limiter::hit('wa_ops', 300, 3600);
        switch ($op) {
            case 'connect':
                if (self::cfg()['url'] === '') { throw new Mutqan_Error('phone_gateway', 'الوسيط شغال على الموبايل: شغّله هناك وهيظهر الـQR هنا خلال ثوانٍ لتمسحه.', 409); }
                return self::gw('POST', '/sessions', ['id' => (string) ($in['id'] ?? ''), 'label' => (string) ($in['label'] ?? ''), 'mode' => ($in['mode'] ?? '') === 'code' ? 'code' : 'qr', 'phone' => (string) ($in['phone'] ?? '')]);
            case 'restart':
                if (self::cfg()['url'] === '') { throw new Mutqan_Error('phone_gateway', 'الوسيط على الموبايل — أعد تشغيله من هناك.', 409); }
                return self::gw('POST', '/sessions/' . rawurlencode(self::id($in['id'] ?? '')) . '/restart', []);
            case 'logout':
                if (self::cfg()['url'] === '') { self::forget_session(self::id($in['id'] ?? '')); return ['ok' => true]; }
                return self::gw('DELETE', '/sessions/' . rawurlencode(self::id($in['id'] ?? '')));
            case 'send': return self::staff_send($in, $p);
        }
        throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400);
    }

    private static function id($s) { $s = strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string) $s)); if ($s === '') { throw new Mutqan_Error('bad_id', 'معرّف غير صالح.', 400); } return substr($s, 0, 32); }
    public static function tid($acct, $phone) { return 't' . substr(md5($acct . '|' . $phone), 0, 24); }
    public static function mid($acct, $msgId) { return 'm' . substr(md5($acct . '|' . $msgId), 0, 24); }

    private static function staff_send($in, $p) {
        $acct = self::id($in['accountId'] ?? '');
        $phone = preg_replace('/\D/', '', (string) ($in['to'] ?? ''));
        $text = mb_substr(trim((string) ($in['text'] ?? '')), 0, 4000);
        if (strlen($phone) < 8 || $text === '') { throw new Mutqan_Error('bad_input', 'بيانات ناقصة.', 400); }
        $r = self::send($acct, $phone, $text, 'staff', $p['name'] ?? '');
        // A human answered: pause the bot on this thread for 12h.
        $tid = self::tid($acct, $phone);
        $th = Mutqan_Store::get_raw('wa_threads', $tid) ?: [];
        $bot = $th['bot'] ?? [];
        $bot['pausedUntil'] = time() + 12 * 3600;
        self::put_thread($tid, ['bot' => $bot, 'unread' => 0]);
        return $r;
    }


    /** Id of a connected (status «open») business number, or '' when the gateway is not set up / down. Cached for a minute so an order never waits on it twice. */
    public static function open_account() {
        $c = self::cfg();
        // A phone gateway cannot be asked which numbers it has; it tells us when it connects.
        if ($c['url'] === '') { return (string) get_option('mutqan_wa_last_acct', ''); }
        if ($c['token'] === '') { return ''; }
        $hit = get_transient('mq_wa_open');
        if ($hit !== false) { return (string) $hit; }
        $id = '';
        try {
            $r = self::gw('GET', '/sessions', null, 6);
            foreach ((array) ($r['sessions'] ?? []) as $s) { if (($s['status'] ?? '') === 'open' && !empty($s['id'])) { $id = (string) $s['id']; break; } }
        } catch (Throwable $e) { $id = ''; }
        set_transient('mq_wa_open', $id, $id === '' ? 30 : 60);
        return $id;
    }
    /** 05xxxxxxxx / 5xxxxxxxx / +9665… → 9665xxxxxxxx (Saudi default). */
    public static function intl($phone) {
        $d = preg_replace('/\D/', '', (string) $phone);
        if (strpos($d, '00') === 0) { $d = substr($d, 2); }
        if (preg_match('/^05\d{8}$/', $d)) { return '966' . substr($d, 1); }
        if (preg_match('/^5\d{8}$/', $d)) { return '966' . $d; }
        return strlen($d) >= 10 ? $d : '';
    }
    /** The order message, sent by the business number itself — no tap needed from anyone. */
    /** Returns 'sent', 'queued' (a phone gateway will send it when it next connects) or false. The customer is never asked to send anything himself. */
    public static function auto_send($phone, $text, $by = 'order') {
        $to = self::intl($phone);
        if ($to === '') { return false; }
        // A message leaves from the number whose job it is (orders from the customers' line, job cards from the
        // technicians' line, reports from the reports line), through the device that carries that number.
        $fleet = class_exists('Mutqan_WA_Fleet') ? Mutqan_WA_Fleet::account_for($by) : '';
        if ($fleet !== '' && Mutqan_WA_Fleet::device_of($fleet) !== '') {
            try { self::queue($fleet, $to, $text, 'auto', $by); return 'queued'; } catch (Throwable $e) { error_log('[mutqan] wa fleet send: ' . $e->getMessage()); }
        }
        $acct = $fleet !== '' ? $fleet : self::open_account();
        if ($acct === '') {
            // Phone gateway that has not connected yet: queue it — the phone sends it as soon as it pulls (stale ones expire, see pull()).
            // Only the order copy waits; a login code or a job card must fail now so its caller falls back.
            if ($by === 'order' && self::cfg()['url'] === '') { try { self::queue('', $to, $text, 'auto', $by); return 'queued'; } catch (Throwable $e) { return false; } }
            return false;
        }
        try { $r = self::send($acct, $to, $text, 'auto', $by); return !empty($r['queued']) ? 'queued' : 'sent'; }
        catch (Throwable $e) { error_log('[mutqan] wa auto_send: ' . $e->getMessage()); delete_transient('mq_wa_open'); return false; }
    }

    /** Send through the gateway and record the outgoing message. */
    /**
     * Sends a message, by whichever route the gateway can be reached.
     *
     * A gateway on a phone has no address of its own: nothing on the internet can call into it. So
     * when no URL is configured the message is queued and the phone collects it on its next pull.
     * With a URL (a machine that can be dialled) it is pushed as before. Same call either way —
     * nothing above this line needs to know where the gateway lives.
     */
    public static function send($acct, $phone, $text, $via, $by = '') {
        $c = self::cfg();
        if ($c['url'] === '') { return self::queue($acct, $phone, $text, $via, $by); }
        $r = self::gw('POST', '/sessions/' . rawurlencode($acct) . '/send', ['to' => $phone, 'text' => $text]);
        $msgId = (string) ($r['msgId'] ?? wp_generate_uuid4());
        self::store_message($acct, $phone, ['msgId' => $msgId, 'dir' => 'out', 'text' => $text, 'kind' => 'text', 'ts' => time(), 'via' => $via, 'by' => $by]);
        return ['ok' => true];
    }

    /** Puts one message in the queue the phone collects from. */
    public static function queue($acct, $phone, $text, $via = 'auto', $by = '') {
        $doc = Mutqan_Store::insert('wa_outbox', [
            'acct' => (string) $acct, 'to' => self::intl($phone) ?: preg_replace('/\D/', '', (string) $phone),
            'text' => mb_substr((string) $text, 0, 4000), 'via' => (string) $via, 'by' => (string) $by,
            'state' => 'pending', 'tries' => 0, 'at' => gmdate('c'),
        ], null, [], self::sys());
        return ['ok' => true, 'queued' => true, 'id' => (string) ($doc['id'] ?? '')];
    }

    /**
     * The phone asks: anything to send? — and reports what it managed to send last time.
     *
     * The queue is what makes a phone gateway possible at all, so the rules here are the ones that
     * keep it honest: a job handed out is marked taken with the time, so a phone that dies mid-send
     * does not strand it forever; a job tried too many times is dropped rather than retried into a
     * number that keeps failing.
     */
    const TAKEN_STUCK = 300;        // a job handed out and never acked is offered again after this
    const MAX_TRIES = 4;
    const AUTO_MAX_AGE = 6 * 3600;  // an automatic message («تم استلام طلبك…») older than this is dropped, not sent late

    /** Same signature as the webhook, checked the same way: the body, with the shared secret. */
    public static function pull_signed($raw, $sig) {
        $dev = class_exists('Mutqan_WA_Fleet') ? Mutqan_WA_Fleet::signer($raw, $sig) : null;
        if ($dev === null) {
            $c = self::cfg();
            if (!is_string($sig) || !hash_equals(hash_hmac('sha256', $raw, $c['secret']), $sig)) { throw new Mutqan_Error('forbidden', 'توقيع غير صحيح.', 403); }
            $dev = 'legacy';
        }
        $in = json_decode($raw, true);
        return self::pull(is_array($in) ? $in : [], $dev);
    }

    public static function pull($in, $dev = 'legacy') {
        $now = time();
        $fleet = class_exists('Mutqan_WA_Fleet');
        if ($fleet) { Mutqan_WA_Fleet::touch($dev, (array) ($in['sessions'] ?? [])); }
        foreach ((array) ($in['sent'] ?? []) as $one) {
            $id = (string) (is_array($one) ? ($one['id'] ?? '') : $one);
            if ($id === '') { continue; }
            $job = Mutqan_Store::get_raw('wa_outbox', $id);
            if (!$job) { continue; }
            $okFlag = !is_array($one) || ($one['ok'] ?? true);
            if ($okFlag) {
                self::store_message((string) $job['acct'], (string) $job['to'], ['msgId' => (string) ((is_array($one) ? ($one['msgId'] ?? '') : '') ?: wp_generate_uuid4()),
                    'dir' => 'out', 'text' => (string) $job['text'], 'kind' => 'text', 'ts' => $now, 'via' => (string) ($job['via'] ?? 'auto'), 'by' => (string) ($job['by'] ?? '')]);
                Mutqan_Store::delete('wa_outbox', $id, self::sys());
            } else {
                $tries = (int) ($job['tries'] ?? 0) + 1;
                if ($tries >= self::MAX_TRIES) { Mutqan_Store::delete('wa_outbox', $id, self::sys()); }
                else { Mutqan_Store::update('wa_outbox', $id, ['state' => 'pending', 'tries' => $tries], self::sys()); }
            }
        }
        $limit = max(1, min(20, (int) ($in['limit'] ?? 10)));
        $jobs = [];
        foreach (Mutqan_Store::select('wa_outbox', [], ['limit' => 100, 'order' => 'asc'], self::sys()) as $job) {
            if (count($jobs) >= $limit) { break; }
            // A device only collects what leaves from its own numbers.
            if ($fleet && !Mutqan_WA_Fleet::job_for_device($job, $dev)) { continue; }
            if (($job['via'] ?? '') === 'auto' && ($t = strtotime((string) ($job['at'] ?? ''))) && $now - $t > self::AUTO_MAX_AGE) { Mutqan_Store::delete('wa_outbox', (string) $job['id'], self::sys()); continue; }
            $state = (string) ($job['state'] ?? 'pending');
            $taken = (int) ($job['takenAt'] ?? 0);
            if ($state === 'taken' && $now - $taken < self::TAKEN_STUCK) { continue; }
            Mutqan_Store::update('wa_outbox', (string) $job['id'], ['state' => 'taken', 'takenAt' => $now], self::sys());
            $jobs[] = ['id' => (string) $job['id'], 'acct' => (string) ($job['acct'] ?? ''), 'to' => (string) ($job['to'] ?? ''), 'text' => (string) ($job['text'] ?? '')];
        }
        return ['jobs' => $jobs, 'at' => $now, 'cmds' => $fleet ? Mutqan_WA_Fleet::cmds_for($dev, (array) ($in['done'] ?? [])) : []];
    }

    /* ---------------------------------------------------------------- storage */

    private static function put_thread($tid, $patch, $seed = []) {
        $sys = self::sys();
        $cur = Mutqan_Store::get_raw('wa_threads', $tid);
        if ($cur === null) { return Mutqan_Store::insert('wa_threads', $patch + $seed, $tid, [], $sys); }
        return Mutqan_Store::update('wa_threads', $tid, $patch, $sys);
    }

    /** @return bool true when the message is new */
    public static function store_message($acct, $phone, $m) {
        $sys = self::sys();
        $id = self::mid($acct, $m['msgId']);
        if (Mutqan_Store::get_raw('wa_msgs', $id) !== null) { return false; }
        $tid = self::tid($acct, $phone);
        $at = gmdate('c', (int) $m['ts']);
        Mutqan_Store::insert('wa_msgs', [
            'threadId' => $tid, 'accountId' => $acct, 'phone' => $phone, 'dir' => $m['dir'], 'text' => mb_substr((string) $m['text'], 0, 4000),
            'kind' => $m['kind'] ?? 'text', 'at' => $at, 'via' => $m['via'] ?? 'customer', 'by' => $m['by'] ?? '',
        ], $id, ['expiresAt' => gmdate('c', time() + 180 * 86400)], $sys);
        $th = Mutqan_Store::get_raw('wa_threads', $tid) ?: [];
        $patch = ['lastText' => mb_substr((string) $m['text'], 0, 120) ?: ('[' . ($m['kind'] ?? 'text') . ']'), 'lastAt' => $at, 'lastDir' => $m['dir']];
        if ($m['dir'] === 'in') { $patch['unread'] = (int) ($th['unread'] ?? 0) + 1; $patch['status'] = 'open'; if (!empty($m['name'])) { $patch['name'] = mb_substr($m['name'], 0, 60); } }
        self::put_thread($tid, $patch, ['accountId' => $acct, 'phone' => $phone, 'name' => mb_substr((string) ($m['name'] ?? ''), 0, 60), 'unread' => 0, 'status' => 'open']);
        return true;
    }

    /* ---------------------------------------------------------------- webhook from the gateway */

    public static function webhook($raw, $sig) {
        $c = self::cfg();
        $dev = class_exists('Mutqan_WA_Fleet') ? Mutqan_WA_Fleet::signer($raw, $sig) : null;
        if ($dev === null && (!is_string($sig) || !hash_equals(hash_hmac('sha256', $raw, $c['secret']), $sig))) { throw new Mutqan_Error('forbidden', 'توقيع غير صحيح.', 403); }
        if (class_exists('Mutqan_WA_Fleet')) { Mutqan_WA_Fleet::touch($dev); }
        $e = json_decode($raw, true);
        if (!is_array($e)) { throw new Mutqan_Error('bad_input', 'حمولة غير صالحة.', 400); }
        $acct = self::id($e['accountId'] ?? '');
        if (($e['type'] ?? '') === 'status') {
            set_transient('mq_wa_st_' . $acct, ['status' => (string) ($e['status'] ?? ''), 'at' => time()], DAY_IN_SECONDS);
            if (($e['status'] ?? '') === 'open') { update_option('mutqan_wa_last_acct', $acct, false); }
            self::remember_session($acct, $e, $dev);
            if (in_array($e['status'] ?? '', ['logged_out', 'closed'], true)) { self::notify_admins('تنبيه واتساب', 'الرقم «' . $acct . '» انقطع اتصاله — افتح صندوق الواتساب لإعادة الربط.', 'wa-down-' . $acct); }
            return ['ok' => true];
        }
        if (($e['type'] ?? '') !== 'message') { return ['ok' => true]; }
        $phone = preg_replace('/\D/', '', (string) ($e['phone'] ?? ''));
        if (strlen($phone) < 8 || strlen($phone) > 16) { return ['ok' => true]; }
        $dir = ($e['direction'] ?? '') === 'out' ? 'out' : 'in';
        $isNew = self::store_message($acct, $phone, ['msgId' => (string) ($e['msgId'] ?? wp_generate_uuid4()), 'dir' => $dir, 'text' => (string) ($e['text'] ?? ''), 'kind' => (string) ($e['kind'] ?? 'text'), 'ts' => (int) ($e['ts'] ?? time()), 'name' => (string) ($e['name'] ?? ''), 'via' => $dir === 'out' ? 'phone' : 'customer']);
        if (!$isNew) { return ['ok' => true]; }
        $tid = self::tid($acct, $phone);
        if ($dir === 'in' && class_exists('Mutqan_WA_Fleet')) { Mutqan_WA_Fleet::on_incoming($acct, $phone, (string) ($e['text'] ?? ''), $tid); }
        $th = Mutqan_Store::get_raw('wa_threads', $tid) ?: [];
        if ($dir === 'out') {   // replied from the phone itself -> human is handling it
            $bot = $th['bot'] ?? []; $bot['pausedUntil'] = time() + 12 * 3600;
            self::put_thread($tid, ['bot' => $bot]);
            self::learn_from_owner((string) ($th['lastIn'] ?? ''), (string) ($e['text'] ?? ''));
            return ['ok' => true];
        }
        // A technician or someone from the team is not a customer, and the reception bot has nothing
        // useful to say to either of them. The number decides who this is.
        if (class_exists('Mutqan_WA_Desk') && Mutqan_WA_Desk::inbound($acct, $phone, (string) ($e['text'] ?? ''), $tid, $th)) { return ['ok' => true]; }
        $label = ($th['name'] ?? '') ?: $phone;
        self::notify_admins('رسالة واتساب جديدة', $label . ': ' . mb_substr((string) ($e['text'] ?? ''), 0, 80), 'wa-' . $tid, 120);
        $cfg = Mutqan_Store::get_raw('wa_bot', 'cfg') ?: [];
        $acfg = Mutqan_Store::get_raw('wa_bot', 'acct:' . $acct) ?: [];
        if ((isset($cfg['enabled']) && !$cfg['enabled']) || (isset($acfg['botEnabled']) && !$acfg['botEnabled'])) { return ['ok' => true]; }
        self::put_thread($tid, ['lastIn' => mb_substr((string) ($e['text'] ?? ''), 0, 300)]);
        $out = Mutqan_WA_Bot::decide($cfg + Mutqan_WA_Bot::defaults(), $th, (string) ($e['text'] ?? ''), (int) ($e['ts'] ?? time()), (string) ($e['kind'] ?? 'text'));
        // The rules had nothing to say. If the model cannot be reached either, the bot falls back
        // on what the model has already answered before — which is the whole point of it learning.
        if (empty($out['replies']) && class_exists('Mutqan_Brain')) {
            $said = Mutqan_Brain::fallback((string) ($e['text'] ?? ''));
            if ($said !== '') { $out['replies'] = [$said]; }
        }
        if ($out['patch']) { self::put_thread($tid, $out['patch']); }
        if (!empty($out['miss'])) { self::learn_miss($out['miss']); }
        foreach ($out['replies'] as $txt) {
            try { self::send($acct, $phone, $txt, 'bot'); } catch (Throwable $ex) { error_log('[mutqan] wa bot send: ' . $ex->getMessage()); break; }
        }
        if ($out['lead']) {
            Mutqan_Store::insert('wa_leads', $out['lead'] + ['numberId' => $acct, 'phone' => $phone, 'at' => gmdate('c'), 'source' => 'bot'], null, [], self::sys());
            // A customer who finished giving their details has placed an order. Until now that only
            // landed in wa_leads, which the operations room does not read, so the order was invisible
            // and somebody had to retype it by hand. Register it as a real order instead.
            try { self::lead_to_order($acct, $phone, $out['lead'], $tid); }
            catch (Throwable $ex) { error_log('[mutqan] wa lead_to_order: ' . $ex->getMessage()); }
        }
        if ($out['notify']) { self::notify_admins('واتساب — يحتاج متابعة', $out['notify'] . ' (' . $label . ')', 'wa-n-' . $tid . '-' . substr(md5($out['notify']), 0, 6), 60); }
        return ['ok' => true];
    }


    /* ---------------------------------------------------------------- a WhatsApp conversation becomes an order */

    /** How long a WhatsApp thread's open order stays the same order, so follow-up messages do not pile up duplicates. */
    const SAME_ORDER_HOURS = 12;

    /** The statuses that mean an order is still being worked on. A new message joins one of these rather than starting another. */
    const LIVE_STATUSES = ['new', 'forced', 'assigned', 'accepted'];

    /**
     * Turns a finished WhatsApp conversation into a real order the operations room can see.
     *
     * The customer never sees any of this: as far as they are concerned they answered three questions
     * on WhatsApp. What changes is that the answer now exists where the work is actually done.
     *
     * It will not create a second order while the customer already has one open on the same number:
     * people send "و كمان المطبخ" a minute later, and that is more information about one job, not a
     * second job. It is added to the open order's route instead.
     *
     * @return string|null the order id, or null when an open order was updated instead.
     */
    public static function lead_to_order($acct, $phone, $lead, $tid) {
        $service = trim((string) ($lead['service'] ?? ''));
        if ($service === '') { return null; }

        $intl = self::intl($phone);
        $open = self::open_order_for($tid);
        if ($open !== null) {
            self::add_step($open['id'], 'wa_more', 'رسالة إضافية من العميل على واتساب: ' . mb_substr($service, 0, 60));
            return null;
        }

        $th = Mutqan_Store::get_raw('wa_threads', $tid) ?: [];
        $bot = (array) ($th['bot'] ?? []);
        $neg = (array) ($bot['neg'] ?? []);
        $area = trim((string) ($lead['area'] ?? ''));
        $name = trim((string) ($lead['name'] ?? '')) ?: (string) ($th['name'] ?? '');

        $order = [
            'status' => 'new',
            'source' => 'whatsapp',
            'clientName' => mb_substr($name, 0, 80),
            'clientPhone' => $intl,
            'service' => mb_substr($service, 0, 120),
            'address' => mb_substr($area, 0, 160),
            'note' => mb_substr((string) ($lead['note'] ?? ''), 0, 300),
            'waThread' => $tid,
            'waAccount' => $acct,
            'createdAt' => gmdate('c'),
            // The plan: what was agreed on WhatsApp, so operations is not guessing at a price later.
            'plan' => array_filter([
                'service' => mb_substr($service, 0, 120),
                'area' => $area !== '' ? $area : null,
                'qty' => isset($neg['qty']) ? (int) $neg['qty'] : null,
                'unitType' => isset($neg['type']) ? (string) $neg['type'] : null,
                'agreedPrice' => isset($neg['offer']) ? (int) $neg['offer'] : null,
                'quotedPrice' => isset($neg['total']) ? (int) $neg['total'] : null,
            ], static function ($v) { return $v !== null && $v !== ''; }),
            // The route: every step this order takes, in order, so anyone picking it up can see its history.
            'route' => [self::step('wa_in', 'وصل الطلب من واتساب' . ($area !== '' ? ' — ' . $area : ''))],
        ];
        if (isset($neg['offer'])) { $order['agreedPrice'] = (int) $neg['offer']; }

        $doc = Mutqan_Store::insert('orders', $order, null, [], self::sys());
        $id = (string) ($doc['id'] ?? '');
        if ($id === '') { return null; }
        self::put_thread($tid, ['orderId' => $id]);
        self::notify_admins('طلب جديد من واتساب', trim(($name !== '' ? $name : $intl) . ' — ' . $service . ($area !== '' ? ' — ' . $area : '')), 'wa-order-' . $id, 0);
        return $id;
    }

    /** The order this thread already has open, or null. */
    private static function open_order_for($tid) {
        $th = Mutqan_Store::get_raw('wa_threads', $tid) ?: [];
        $id = (string) ($th['orderId'] ?? '');
        if ($id === '') { return null; }
        $o = Mutqan_Store::get_raw('orders', $id);
        if (!$o || !in_array((string) ($o['status'] ?? ''), self::LIVE_STATUSES, true)) { return null; }
        $at = strtotime((string) ($o['createdAt'] ?? '')) ?: 0;
        if ($at && time() - $at > self::SAME_ORDER_HOURS * 3600) { return null; }
        return ['id' => $id, 'order' => $o];
    }

    /** One entry on an order's route. */
    public static function step($kind, $text, $by = 'النظام') {
        return ['at' => gmdate('c'), 'kind' => (string) $kind, 'text' => mb_substr((string) $text, 0, 200), 'by' => mb_substr((string) $by, 0, 60)];
    }

    /** Appends a step to an order's route, keeping the list bounded so one chatty order cannot grow without limit. */
    public static function add_step($orderId, $kind, $text, $by = 'النظام') {
        $o = Mutqan_Store::get_raw('orders', $orderId);
        if (!$o) { return; }
        $route = isset($o['route']) && is_array($o['route']) ? $o['route'] : [];
        $route[] = self::step($kind, $text, $by);
        if (count($route) > 60) { $route = array_slice($route, -60); }
        Mutqan_Store::update('orders', $orderId, ['route' => $route], self::sys());
    }

    /** Free learning: owner's own replies become candidate scenarios (pending owner approval); unanswered questions are counted. */
    public static function learn_from_owner($q, $a) {
        $q = trim($q); $a = trim($a);
        if (mb_strlen($q) < 3 || mb_strlen($a) < 6) { return; }
        try {
            $id = 'learn:' . substr(md5(Mutqan_WA_Bot::norm($q)), 0, 16);
            $cur = Mutqan_Store::get_raw('wa_bot', $id);
            if ($cur && ($cur['status'] ?? '') === 'rejected') { return; }
            $row = ['kind' => 'learn', 'q' => mb_substr($q, 0, 300), 'a' => mb_substr($a, 0, 800), 'count' => (int) ($cur['count'] ?? 0) + 1, 'status' => $cur['status'] ?? 'pending', 'at' => gmdate('c')];
            Mutqan_Store::upsert('wa_bot', $row, $id, [], self::sys());
            // writing-style profile (length, emoji, openers) — no AI, just counters
            $st = Mutqan_Store::get_raw('wa_bot', 'style') ?: ['kind' => 'style', 'n' => 0, 'len' => 0, 'emoji' => 0, 'open' => [], 'close' => []];
            $st['n']++; $st['len'] += mb_strlen($a);
            $st['emoji'] += preg_match_all('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $a);
            $w = preg_split('/\s+/u', $a); $o = (string) ($w[0] ?? ''); $cl = (string) end($w);
            if ($o !== '') { $st['open'][$o] = ($st['open'][$o] ?? 0) + 1; arsort($st['open']); $st['open'] = array_slice($st['open'], 0, 12, true); }
            if ($cl !== '') { $st['close'][$cl] = ($st['close'][$cl] ?? 0) + 1; arsort($st['close']); $st['close'] = array_slice($st['close'], 0, 12, true); }
            Mutqan_Store::upsert('wa_bot', $st, 'style', [], self::sys());
        } catch (Throwable $e) { error_log('[mutqan] wa learn: ' . $e->getMessage()); }
    }
    public static function learn_miss($q) {
        try {
            $id = 'miss:' . substr(md5(Mutqan_WA_Bot::norm($q)), 0, 16);
            $cur = Mutqan_Store::get_raw('wa_bot', $id);
            Mutqan_Store::upsert('wa_bot', ['kind' => 'miss', 'q' => $q, 'count' => (int) ($cur['count'] ?? 0) + 1, 'at' => gmdate('c')], $id, [], self::sys());
        } catch (Throwable $e) { error_log('[mutqan] wa miss: ' . $e->getMessage()); }
    }

    public static function notify_admins($title, $body, $key, $every = 600) {
        $hi = (strpos((string) $key, 'wa-n-') === 0) || $title === 'تنبيه واتساب' || $title === 'واتساب — يحتاج متابعة';
        if (get_transient('mq_wan_' . md5($key))) { return; }
        set_transient('mq_wan_' . md5($key), 1, $every);
        try {
            Mutqan_Store::insert('_push', ['target' => ['admins' => true], 'title' => $title, 'body' => mb_substr($body, 0, 300), 'url' => '#/staff/wa-inbox', 'sound' => 'bell', 'priority' => ($hi ? 'high' : 'normal'), 'event' => ($hi ? 'wa_handoff' : 'wa_message'), 'from' => 'system'], null, ['expiresAt' => gmdate('c', time() + 7 * 86400)], self::sys());
        } catch (Throwable $e) { error_log('[mutqan] wa notify: ' . $e->getMessage()); }
    }
}

/** Free rule-based reception bot. Pure decide() so it can be unit-tested. */
final class Mutqan_WA_Bot {
    public static function defaults() {
        return [
            'enabled' => true,
            'negStep' => 10,
            'extraDistricts' => [],
            'brand' => 'أكسجين',
            'welcome' => "أهلًا بك في {brand} 👋\nأنا المساعد الآلي، كيف أقدر أخدمك؟",
            'menu' => "اكتب رقم الخيار:\n1️⃣ الخدمات والأسعار\n2️⃣ فيديوهات (شرح وغسيل المكيفات)\n3️⃣ مواعيد العمل\n4️⃣ طلب خدمة وتسجيل بياناتي\n5️⃣ التحدث مع موظف",
            'services' => "خدماتنا: صيانة وتكييف، سباكة، كهرباء، نظافة وتعقيم، أجهزة منزلية، دهانات، غرف تبريد، خزانات، أقفال ذكية، كاميرات، ساتلايت، طاقة شمسية.\nللأسعار والعروض اطلب الخدمة (اكتب 4) وسنرسل لك التفاصيل.",
            'videos' => [],        // [{title,url,keywords}] — owner adds TikTok/YouTube links
            'faq' => [],           // [{keywords,reply}]
            'hours' => ['start' => '08:00', 'end' => '22:00', 'days' => [0, 1, 2, 3, 4, 5, 6], 'tz' => 'Asia/Riyadh'],
            'forceAway' => false,  // owner toggle: nobody is available now
            'awayText' => 'لا يتوفر موظف حاليًا، لكن نسجّل بياناتك ويتواصل معك فريقنا أول ما نبدأ الدوام.',
            'handoffText' => 'تم تحويل محادثتك لأحد الموظفين، وسيرد عليك قريبًا 🙏',
            'doneText' => 'تم تسجيل بياناتك ✅ سيتواصل معك فريقنا قريبًا.',
            'maxPerHour' => 8,
        ];
    }

    public static function norm($s) {
        $s = mb_strtolower(trim((string) $s));
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0640}]/u', '', $s);
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5']);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }
    private static function has($n, $words) { foreach ($words as $w) { if (mb_strpos($n, self::norm($w)) !== false) { return true; } } return false; }

    public static function open_now($hours, $ts) {
        try {
            $d = new DateTime('@' . $ts); $d->setTimezone(new DateTimeZone($hours['tz'] ?? 'Asia/Riyadh'));
        } catch (Throwable $e) { return true; }
        if (!in_array((int) $d->format('w'), array_map('intval', $hours['days'] ?? [0, 1, 2, 3, 4, 5, 6]), true)) { return false; }
        $cur = $d->format('H:i'); $s = $hours['start'] ?? '08:00'; $e2 = $hours['end'] ?? '22:00';
        return $s <= $e2 ? ($cur >= $s && $cur < $e2) : ($cur >= $s || $cur < $e2);
    }


    // ── sales brain: Makkah coverage, quantity discovery, stepped bargaining, return-after-absence ──
    private static $dc = [];
    private static function data($f) {
        if (!isset(self::$dc[$f])) { $j = @file_get_contents(__DIR__ . '/data/' . $f . '.json'); self::$dc[$f] = $j ? (json_decode($j, true) ?: []) : []; }
        return self::$dc[$f];
    }
    /** Rapport / trust / objection topics. Returns reply text or null. Patterns come from data/extras.json (generated from the app bot). */
    private static function extra($n, $hasService) {
        $d = self::data('extras');
        if (empty($d['patterns'])) { return null; }
        $words = count(array_filter(explode(' ', $n)));
        $svc = $hasService || (bool) preg_match('/(?:مكيف|سباك|كهرب|غسيل|تركيب|تسريب|فريون|مجاري|سخان|قفل|ثلاج)/u', $n);
        $order = [['callback', 9, true], ['refund', 99, true], ['visit_fee', 99, true], ['package_vs_wash', 99, true], ['method', 8, true], ['own_tech', 99, true], ['tech_nationality', 99, true], ['papers', 99, true], ['trust_fear', 99, true], ['show_works', 8, true], ['join_how', 99, true], ['think_later', 99, true], ['contact_info', 8, true], ['annual_pkg', 99, true], ['part_change', 99, true], ['project', 99, true], ['business', 99, true], ['compare', 99, true], ['why_us', 99, true], ['triage', 99, true], ['howru', 5, false], ['tired', 5, false], ['praise', 5, false], ['budget', 99, true]];
        foreach ($order as $o) {
            [$k, $max, $any] = $o;
            if ($words > $max || (!$any && $svc) || empty($d['patterns'][$k]) || empty($d['replies'][$k])) { continue; }
            if (@preg_match('/' . str_replace('/', '\\/', $d['patterns'][$k]) . '/u', $n)) {
                $b = $d['replies'][$k];
                return str_replace('{n}', '', $b[crc32($k . $n) % count($b)]);
            }
        }
        return null;
    }
    public static function say($key, $seed, $v = []) {
        $b = self::data('sales')[$key]['sa'] ?? [];
        if (!$b) { return ''; }
        $t = $b[crc32($key . $seed) % count($b)];
        $v += ['n' => '', 'p' => '', 'per' => '', 'd' => '', 'c' => '', 'k' => ''];
        $m = []; foreach ($v as $k => $x) { $m['{' . $k . '}'] = (string) $x; }
        return strtr($t, $m);
    }
    private static function strip_al($s) { return preg_replace('/^ال/u', '', $s); }
    private static function word_in($n, $w) { $w = self::norm($w); return $w !== '' && (bool) preg_match('/(?:^|\s|ب|في|حي|ل)' . preg_quote($w, '/') . '(?=\s|$)/u', $n); }
    /** @return array{inside:bool,district:?string,city:?string}|null */
    public static function area($text, $extra = []) {
        $n = self::norm($text); $d = self::data('makkah');
        $toMakkah = (bool) preg_match('/(?:الي|الى|ل|لـ)\s*مكه/u', $n);
        // Same rule as checkMakkahArea() in the app: a city we serve besides Makkah is inside.
        foreach (($d['served'] ?? []) as $c) { if (self::word_in($n, self::norm($c)) || self::word_in($n, self::strip_al(self::norm($c)))) { return ['inside' => true, 'district' => null, 'city' => $c]; } }
        if (!$toMakkah) {
            foreach (($d['outside'] ?? []) as $c) { if (self::word_in($n, $c) || self::word_in($n, self::strip_al($c))) { return ['inside' => false, 'district' => null, 'city' => $c]; } }
        }
        $best = null;
        foreach (array_merge($d['districts'] ?? [], (array) $extra) as $x) {
            if (self::word_in($n, $x) || self::word_in($n, self::strip_al(self::norm($x)))) { if ($best === null || mb_strlen($x) > mb_strlen($best)) { $best = $x; } }
        }
        if ($best !== null) { return ['inside' => true, 'district' => $best, 'city' => null]; }
        if (preg_match('/مكه|المكرمه/u', $n)) { return ['inside' => true, 'district' => null, 'city' => null]; }
        return null;
    }
    private static function qty_of($n) {
        if (preg_match('/(\d{1,2})/u', $n, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 50) { return (int) $m[1]; }
        $w = ['واحد' => 1, 'وحده' => 1, 'مكيفين' => 2, 'اتنين' => 2, 'اثنين' => 2, 'ثنتين' => 2, 'ثلاث' => 3, 'تلات' => 3, 'ثلاثه' => 3, 'اربع' => 4, 'اربعه' => 4, 'خمس' => 5, 'خمسه' => 5, 'ست' => 6, 'سته' => 6, 'سبع' => 7, 'سبعه' => 7, 'ثمان' => 8, 'ثمانيه' => 8, 'تسع' => 9, 'عشر' => 10, 'عشره' => 10];
        foreach ($w as $k => $v) { if (preg_match('/(?:^|\s)' . $k . '(?=\s|$)/u', $n)) { return $v; } }
        return null;
    }
    /** Per-unit stepped offers (e.g. 100 -> 90 -> 80), never below the floor. */
    public static function ladder($total, $floor, $units, $step = 10) {
        $units = max(1, (int) $units); $L = $total / $units; $F = $floor / $units;
        if ($total <= $floor) { return []; }
        $st = max($step, (int) ceil(($L - $F) / 6));
        $o = [];
        for ($u = floor($L / 5) * 5 - ((fmod($L, 5) == 0) ? $st : 0); $u > $F; $u -= $st) { $o[] = (int) round($u * $units); }
        $o[] = (int) $floor;
        return $o;
    }
    private static function resume_text($bot) {
        $neg = (array) ($bot['neg'] ?? []);
        if ($neg) { return 'نكمل من حيث توقفنا: غسيل ' . ($neg['qty'] ?? '') . ' ' . ($neg['type'] ?? '') . ' — السعر الحالي ' . ($neg['offer'] ?? $neg['total'] ?? '') . ' ر.س. تثبّت الحجز؟'; }
        return 'نكمل طلبك من حيث توقفنا. تفضل أكمل بياناتك.';
    }


    private static function finish($out, $bot, $r, $hs, $hc, $ts) {
        $bot['hourStart'] = $hs; $bot['hourCount'] = $hc + count($r); $bot['lastAt'] = $ts; if (empty($bot['welcomedAt'])) { $bot['welcomedAt'] = $ts; }
        $out['replies'] = $r; $out['patch'] = ['bot' => $bot];
        return $out;
    }

    private static function hours_text($c, $ts) {
        $h = $c['hours'];
        $names = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        $days = count($h['days']) >= 7 ? 'كل يوم' : implode('، ', array_map(function ($i) use ($names) { return $names[(int) $i] ?? ''; }, $h['days']));
        return "🕒 مواعيد العمل: {$days} من {$h['start']} إلى {$h['end']}\n" . (self::open_now($h, $ts) ? 'نحن متواجدون الآن ✅' : 'نحن خارج أوقات الدوام حاليًا.');
    }

    private static function videos_text($c, $n) {
        $vs = array_values(array_filter((array) $c['videos'], function ($v) { return !empty($v['url']); }));
        if (!$vs) { return 'سنوافيك بفيديوهات الشرح قريبًا. اكتب 4 لطلب خدمة أو 5 للتحدث مع موظف.'; }
        $hit = array_values(array_filter($vs, function ($v) use ($n) { foreach (preg_split('/[,،\s]+/u', (string) ($v['keywords'] ?? '')) as $k) { if ($k !== '' && mb_strpos($n, self::norm($k)) !== false) { return true; } } return false; }));
        $list = $hit ?: $vs;
        $lines = ['🎬 فيديوهات مفيدة:'];
        foreach (array_slice($list, 0, 6) as $v) { $lines[] = '• ' . ($v['title'] ?? 'فيديو') . "\n" . $v['url']; }
        return implode("\n", $lines);
    }

    /** @return array{replies:string[],patch:array,notify:?string,lead:?array} */
    public static function decide($c, $th, $text, $ts, $kind = 'text') {
        $out = ['replies' => [], 'patch' => [], 'notify' => null, 'lead' => null];
        $bot = (array) ($th['bot'] ?? []);
        if (($bot['pausedUntil'] ?? 0) > $ts) { return $out; }
        // rate limit per thread: max N bot messages per hour
        $hs = (int) ($bot['hourStart'] ?? 0); $hc = (int) ($bot['hourCount'] ?? 0);
        if ($ts - $hs > 3600) { $hs = $ts; $hc = 0; }
        if ($hc >= (int) $c['maxPerHour']) { return $out; }
        $n = self::norm($text);
        $brand = $c['brand'];
        $welcome = str_replace('{brand}', $brand, $c['welcome']);
        $step = $bot['step'] ?? null;
        $r = [];

        if ($kind !== 'text' && $n === '' && !$step) { $n = 'x'; }

        // ── long absence: offer to resume or restart ──
        $lastAt = (int) ($bot['lastAt'] ?? 0);
        $hasState = !empty($bot['neg']) || !empty($bot['step']) || !empty($bot['pendingWash']);
        if (($bot['await'] ?? '') === 'resume') {
            if (self::has($n, ['1', 'استكمال', 'اكمل', 'نكمل', 'كمل'])) { $bot['await'] = null; $r[] = self::resume_text($bot); }
            elseif (self::has($n, ['2', 'جديده', 'جديد', 'ابدا', 'من الاول'])) { $bot = ['welcomedAt' => $ts, 'hourStart' => $hs, 'hourCount' => $hc, 'lastAt' => $ts]; $r[] = self::say('ask_need', $n); }
            else { $r[] = "اكتب 1 لاستكمال طلبك السابق أو 2 لبدء محادثة جديدة."; }
            return self::finish($out, $bot, $r, $hs, $hc, $ts);
        }
        if ($lastAt && $ts - $lastAt > 6 * 3600 && $hasState && ($bot['await'] ?? '') !== 'resume') {
            $bot['await'] = 'resume';
            $r[] = "أهلاً بعودتك 👋 غبت فترة. تحب:\n1️⃣ استكمال طلبك السابق\n2️⃣ محادثة جديدة";
            return self::finish($out, $bot, $r, $hs, $hc, $ts);
        }
        $extraD = (array) ($c['extraDistricts'] ?? []);
        $ar = self::area($text, $extraD);
        $neg = (array) ($bot['neg'] ?? []);
        $isWash = !preg_match('/(?:^|\s)(?:كيف|طريقه|طريقة|شرح|فيديو)/u', $n) && (bool) preg_match('/(?:غسيل|غسل|تنظيف|اغسل)/u', $n) && (bool) preg_match('/(?:مكيف|سبليت|شباك|تكييف)/u', $n);
        $yes = self::has($n, ['نعم', 'ايوه', 'ايوا', 'اي نعم', 'حولني', 'حول', 'اوكي', 'تمام', 'موافق', 'ابشر']);
        // conversation layer (rapport, trust, objections, budget) — same wording as the app bot, Saudi voice
        if (!$step && !$neg) {
            $ex = self::extra($n, !empty($bot['pendingWash']) || $isWash);
            if ($ex !== null) { $r[] = $ex; return self::finish($out, $bot, $r, $hs, $hc, $ts); }
        }
        // outside Makkah -> partner branches through technical support
        if ($ar && $ar['inside'] === false && !$step) {
            $r[] = self::say('out_cov', $n, ['c' => $ar['city']]);
            $bot['await'] = 'handoff';
            $out['notify'] = 'طلب من خارج مكة وجدة (' . $ar['city'] . ') — يحتاج تنسيق مع فرع شريك';
            return self::finish($out, $bot, $r, $hs, $hc, $ts);
        }
        if (($bot['await'] ?? '') === 'handoff' && $yes && !$step) {
            $bot['await'] = null; $bot['pausedUntil'] = $ts + 12 * 3600; $r[] = $c['handoffText']; $out['notify'] = 'عميل وافق على التحويل للدعم الفني';
            return self::finish($out, $bot, $r, $hs, $hc, $ts);
        }
        // discovery: wash request -> ask the count -> calculator
        if (!$step && ($isWash || !empty($bot['pendingWash']))) {
            $qty = self::qty_of($n);
            $type = preg_match('/شباك/u', $n) ? 'شباك' : (preg_match('/(?:دكت|داكت|مركزي|كونسيلد|باكيج|باكيدج|دولابي)/u', $n) ? 'داكت' : 'سبليت');
            if ($qty === null) { $bot['pendingWash'] = true; $r[] = self::say('ask_qty', $n); return self::finish($out, $bot, $r, $hs, $hc, $ts); }
            $q = ['mode' => 'wash', 'type' => $type, 'qty' => $qty, 'full' => true];
            $total = (int) round(Mutqan_Policy::ac_total($q));
            if ($total > 0) {
                $floor = (int) Mutqan_Policy::ac_floor($q) + 1;
                $bot['pendingWash'] = false; $bot['neg'] = ['qty' => $qty, 'type' => $type, 'total' => $total, 'floor' => min($floor, $total), 'i' => 0, 'offer' => $total];
                $r[] = "غسيل {$qty} {$type}: {$total} ر.س شامل ضمان 15 يوم مجاناً ✅\n" . self::say('quote_close', $n);
                return self::finish($out, $bot, $r, $hs, $hc, $ts);
            }
        }
        // stepped bargaining
        if (!$step && $neg && !empty($neg['total'])) {
            $per = $neg['qty'] > 1 ? function ($p) use ($neg) { return ' (' . (int) round($p / $neg['qty']) . ' ر.س للوحدة)'; } : function ($p) { return ''; };
            $money = null;
            if (preg_match('/(\d{2,5})/u', $n, $m) && (int) $m[1] >= 30 && !preg_match('/^(?:\d{1,2})$/', $n)) { $money = (int) $m[1]; }
            $pushy = self::has($n, ['غالي', 'غاليه', 'خصم', 'تخفيض', 'انقص', 'اقل', 'فصال', 'اخر سعر', 'مبالغ', 'كثير', 'ارخص', 'نزل', 'نزلي', 'وفر']);
            if ($money !== null && $money >= $neg['floor'] && $money < ($neg['offer'] ?? $neg['total']) && self::has($n, ['ادفع', 'ابغى', 'ابي', 'بكم', 'عشرين', 'يصير', 'اديك', 'اعطيك', 'سعر']) || ($money !== null && $money >= $neg['floor'] && $money <= $neg['total'] && $pushy)) {
                $neg['offer'] = $money; $bot['neg'] = $neg;
                $r[] = "أبشر، اتفقنا على {$money} ر.س" . $per($money) . " ✅\n" . self::say('quote_close', $n);
                return self::finish($out, $bot, $r, $hs, $hc, $ts);
            }
            if ($money !== null && $money < $neg['floor'] && ($pushy || self::has($n, ['ادفع', 'ابغى', 'ابي', 'اديك']))) {
                $r[] = self::say('neg_counter_sorry', $n, ['k' => $money, 'p' => $neg['floor'], 'per' => $per($neg['floor'])]);
                $bot['await'] = 'handoff'; $out['notify'] = 'عميل يفاوض على سعر أقل من الحد الأدنى (' . $money . ' ر.س)';
                return self::finish($out, $bot, $r, $hs, $hc, $ts);
            }
            if ($pushy) {
                $lad = self::ladder($neg['total'], $neg['floor'], $neg['qty'], (int) ($c['negStep'] ?? 10));
                $i = (int) ($neg['i'] ?? 0);
                if ($i < count($lad)) {
                    $off = $lad[$i]; $neg['i'] = $i + 1; $neg['offer'] = $off; $bot['neg'] = $neg;
                    $r[] = self::say($off <= $neg['floor'] ? 'neg_floor_s' : 'neg_step', $n . $i, ['p' => $off, 'per' => $per($off)]);
                } else {
                    $r[] = self::say('neg_sorry', $n . $i, ['p' => $neg['floor'], 'per' => $per($neg['floor'])]);
                    $bot['await'] = 'handoff'; $out['notify'] = 'عميل وصل لأدنى سعر ويحتاج عرض الدعم الفني (' . $neg['floor'] . ' ر.س)';
                }
                return self::finish($out, $bot, $r, $hs, $hc, $ts);
            }
            if ($yes && ($bot['await'] ?? '') !== 'handoff') {   // accepted the price -> book
                $bot['step'] = 'name'; $bot['data'] = ['price' => $neg['offer'] ?? $neg['total'], 'service' => 'غسيل ' . $neg['qty'] . ' ' . $neg['type']];
                $r[] = 'ممتاز! السعر المتفق ' . ($neg['offer'] ?? $neg['total']) . ' ر.س. ما اسمك الكريم؟';
                return self::finish($out, $bot, $r, $hs, $hc, $ts);
            }
        }
        if ($step && in_array($step, ['name', 'service', 'area'], true)) {
            if (self::has($n, ['الغاء', 'رجوع', 'cancel'])) { $bot['step'] = null; $bot['data'] = []; $r[] = "تم الإلغاء.\n" . $c['menu']; }
            else {
                $d = (array) ($bot['data'] ?? []);
                $val = mb_substr(trim((string) $text), 0, 80);
                if ($step === 'name') { $d['name'] = $val; $bot['step'] = 'service'; $r[] = 'ما الخدمة المطلوبة؟ (مثال: غسيل مكيف، سباكة، كهرباء…)'; }
                elseif ($step === 'service') { $d['service'] = $val; $bot['step'] = 'area'; $r[] = 'ما الحي / المدينة؟'; }
                else { $d['area'] = $val; $bot['step'] = null; $ax = self::area($val, (array) ($c['extraDistricts'] ?? [])); $r[] = ($ax && $ax['inside'] === false ? self::say('out_cov', $val, ['c' => $ax['city']]) . "\n" : ($ax && $ax['district'] ? self::say('in_cov', $val, ['d' => $ax['district']]) . "\n" : '')) . $c['doneText']; $out['lead'] = ['name' => $d['name'] ?? '', 'service' => $d['service'] ?? '', 'area' => $d['area'], 'note' => 'تسجيل عبر البوت' . (!empty($d['price']) ? ' — السعر المتفق ' . $d['price'] . ' ر.س' : '') . (($ax && $ax['inside'] === false) ? ' — خارج مكة (فرع شريك)' : '')]; $out['notify'] = 'عميل سجّل بياناته: ' . ($d['service'] ?? '') . ' — ' . $d['area']; $d = []; }
                $bot['data'] = $d;
            }
        } else {
            $first = empty($bot['welcomedAt']) || $ts - (int) $bot['welcomedAt'] > 86400;
            $intent = null;
            $map = ['1' => 'services', '2' => 'videos', '3' => 'hours', '4' => 'capture', '5' => 'human'];
            if (isset($map[$n])) { $intent = $map[$n]; }
            elseif (self::has($n, ['موظف', 'شخص', 'بشري', 'مسؤول', 'مدير', 'اكلم', 'اتكلم', 'تحدث مع', 'خدمه العملاء'])) { $intent = 'human'; }
            elseif (self::has($n, ['فيديو', 'تيك توك', 'تكتك', 'tiktok', 'يوتيوب', 'شرح', 'طريقه غسيل', 'كيف اغسل'])) { $intent = 'videos'; }
            elseif (self::has($n, ['موعد', 'مواعيد', 'دوام', 'ساعات', 'متي تفتح', 'مفتوح', 'تعملون'])) { $intent = 'hours'; }
            elseif (self::has($n, ['اطلب', 'طلب', 'حجز', 'احجز', 'سجل', 'بياناتي', 'ابغي', 'ابي', 'اريد', 'محتاج'])) { $intent = 'capture'; }
            elseif (self::has($n, ['سعر', 'اسعار', 'تكلفه', 'بكم', 'كم السعر', 'خدمات', 'خدماتكم', 'عروض'])) { $intent = 'services'; }
            if ($intent === null) {
                foreach ((array) $c['faq'] as $f) {
                    foreach (preg_split('/[,،]+/u', (string) ($f['keywords'] ?? '')) as $k) {
                        $k = trim($k);
                        if ($k !== '' && mb_strpos($n, self::norm($k)) !== false) { $intent = 'faq'; $r[] = (string) $f['reply']; break 2; }
                    }
                }
            }
            if ($first) { $r[] = $welcome . "\n\n" . self::say('ask_need', $n) . "\n\nأو اختر من القائمة:\n" . $c['menu']; }
            switch ($intent) {
                case 'services': $r[] = $c['services']; break;
                case 'videos': $r[] = self::videos_text($c, $n); break;
                case 'hours': $r[] = self::hours_text($c, $ts); break;
                case 'capture': $bot['step'] = 'name'; $bot['data'] = []; $r[] = 'أبشر! أحتاج بعض البيانات. ما اسمك الكريم؟ (اكتب «إلغاء» للرجوع)'; break;
                case 'human':
                    if (empty($c['forceAway']) && self::open_now($c['hours'], $ts)) {
                        $r[] = $c['handoffText']; $bot['pausedUntil'] = $ts + 12 * 3600; $out['notify'] = 'عميل يطلب التحدث مع موظف';
                    } else {
                        $r[] = $c['awayText'] . "\nما اسمك الكريم؟"; $bot['step'] = 'name'; $bot['data'] = [];
                    }
                    break;
                case 'faq': break;
                default:
                    if (!$first) { $r[] = "ما فهمت طلبك تمامًا 🙏\n" . $c['menu']; $out['miss'] = mb_substr((string) $text, 0, 200); }
            }
            if ($first) { $bot['welcomedAt'] = $ts; }
        }
        $hc += count($r);
        $bot['hourStart'] = $hs; $bot['hourCount'] = $hc;
        $bot['lastAt'] = $ts;
        $out['replies'] = $r; $out['patch'] = ['bot' => $bot];
        return $out;
    }
}
