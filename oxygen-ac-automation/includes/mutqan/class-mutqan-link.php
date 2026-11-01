<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Hotline link — a secret «link code» that pairs the site's hotline button with the devices that answer it
 * (the way a TeamViewer partner code or a Telegram login code pairs two ends).
 *
 *  - The OWNER chooses the code in the app (Settings) and types it himself. It is stored ONLY as a salted hash in this site's database:
 *    never in code, never returned by any call, never logged.
 *  - Any staff member (owner / operations / supervisor) pairs a device by typing the same code; that device then "pings" while its app is open.
 *  - The public hotline button asks «is a paired device online?» before it rings. Nobody online → the caller is not left ringing into the void:
 *    the call is recorded with its code as a call-back request and the team is alerted (app + Telegram).
 *  - Changing the code unpairs every device (the owner's own device is paired again at once).
 */
final class Mutqan_Link {
    const OPT = 'mutqan_link';          // ['h' => password_hash, 'at' => time, 'by' => user id]
    const RECV = 'mutqan_link_recv';    // deviceId => ['label', 'uid', 'role', 'at', 'seen']
    const ONLINE_SECS = 120;            // a device counts as online for 2 minutes after its last ping
    const ROLES = ['owner', 'operations', 'supervisor'];

    private static function cfg() { $o = get_option(self::OPT, []); return is_array($o) ? $o : []; }
    private static function recv() { $o = get_option(self::RECV, []); return is_array($o) ? $o : []; }
    private static function put_recv($r) {
        if (count($r) > 20) { uasort($r, function ($a, $b) { return (int) ($b['seen'] ?? 0) <=> (int) ($a['seen'] ?? 0); }); $r = array_slice($r, 0, 20, true); }
        update_option(self::RECV, $r, false);
    }
    public static function is_set() { $c = self::cfg(); return !empty($c['h']); }

    /** What is compared: trimmed, digits of any script folded to 0-9 (an Arabic keyboard types ٧٨٩), no invisible direction marks. Letters keep their case. */
    public static function norm($s) {
        $s = Mutqan_Auth::fold_digits(trim((string) $s));
        $r = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}\x{00A0}]+/u', '', $s);
        return is_string($r) ? $r : '';
    }
    public static function verify($code) {
        $c = self::cfg();
        if (empty($c['h'])) { return false; }
        $n = self::norm($code);
        if ($n === '' || mb_strlen($n) > 64) { return false; }
        return password_verify($n, (string) $c['h']);
    }
    public static function online() {
        $from = time() - self::ONLINE_SECS;
        foreach (self::recv() as $d) { if ((int) ($d['seen'] ?? 0) >= $from) { return true; } }
        return false;
    }
    /** For the public hotline button: no code, no secrets — only whether the line is paired / answered and which Telegram bot (if any) customers may use. */
    public static function public_state() {
        $set = self::is_set();
        return ['linked' => $set, 'online' => $set && self::online(), 'tg' => class_exists('Mutqan_Telegram') ? Mutqan_Telegram::bot_name() : ''];
    }

    private static function dev($in) { return substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($in['deviceId'] ?? '')), 0, 40); }
    private static function label($in, $p) {
        $l = trim(mb_substr((string) preg_replace('/[\x00-\x1F]/u', ' ', (string) ($in['label'] ?? '')), 0, 40));
        return $l !== '' ? $l : (trim((string) ($p['name'] ?? '')) !== '' ? (string) $p['name'] : 'جهاز');
    }
    private static function register($p, $in) {
        $id = self::dev($in);
        if (strlen($id) < 8) { throw new Mutqan_Error('bad_request', 'معرّف الجهاز غير صالح — أعد فتح التطبيق.', 400); }
        $r = self::recv(); $now = time();
        $r[$id] = ['label' => self::label($in, $p), 'uid' => (string) ($p['id'] ?? ''), 'role' => (string) ($p['role'] ?? ''), 'at' => (int) ($r[$id]['at'] ?? $now), 'seen' => $now];
        self::put_recv($r);
        return $id;
    }

    /** One entry point for /link. `state` is public; everything else needs a staff session. */
    public static function handle($in, $p) {
        $op = (string) ($in['op'] ?? '');
        if ($op === 'state') { Mutqan_Limiter::hit('link_state', 240, 3600); return self::public_state(); }
        Mutqan_Auth::require_user($p);
        if (!in_array($p['role'] ?? '', self::ROLES, true)) { throw new Mutqan_Error('forbidden', 'للإدارة والعمليات فقط.', 403); }
        switch ($op) {
            case 'status': return self::status($p, $in);
            case 'set': Mutqan_Auth::require_owner($p); return self::set_code($p, $in);
            case 'link': return self::link($p, $in);
            case 'ping': return self::ping($p, $in);
            case 'unlink': return self::unlink($p, $in);
            case 'clear': Mutqan_Auth::require_owner($p); delete_option(self::OPT); delete_option(self::RECV); return self::status($p, $in);
            case 'tg_status': return Mutqan_Telegram::status($p);
            case 'tg_save': Mutqan_Auth::require_owner($p); return Mutqan_Telegram::save($in);
            case 'tg_off': Mutqan_Auth::require_owner($p); return Mutqan_Telegram::off();
            case 'tg_pair': return Mutqan_Telegram::pair($p);
            case 'tg_unpair': return Mutqan_Telegram::unpair($p);
            case 'tg_test': Mutqan_Auth::require_owner($p); return Mutqan_Telegram::test();
        }
        throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400);
    }

    public static function status($p, $in = []) {
        $me = self::dev($in); $now = time(); $owner = ($p['role'] ?? '') === 'owner'; $r = self::recv(); $list = [];
        foreach ($r as $id => $d) {
            $seen = (int) ($d['seen'] ?? 0);
            $list[] = ['id' => ($owner || $id === $me) ? (string) $id : substr((string) $id, 0, 6), 'label' => (string) ($d['label'] ?? ''), 'role' => (string) ($d['role'] ?? ''), 'seenAt' => gmdate('c', $seen), 'online' => $seen >= $now - self::ONLINE_SECS, 'me' => $id === $me];
        }
        $c = self::cfg();
        return ['set' => self::is_set(), 'at' => !empty($c['at']) ? gmdate('c', (int) $c['at']) : null, 'linkedHere' => $me !== '' && isset($r[$me]), 'online' => self::online(), 'devices' => $list, 'tg' => class_exists('Mutqan_Telegram') ? Mutqan_Telegram::status($p) : null];
    }

    private static function set_code($p, $in) {
        Mutqan_Limiter::hit('link_set', 20, 3600);
        $code = self::norm($in['code'] ?? '');
        $n = mb_strlen($code);
        if ($n < 6 || $n > 64) { throw new Mutqan_Error('bad_request', 'رمز الربط من ٦ إلى ٦٤ خانة.', 400); }
        update_option(self::OPT, ['h' => password_hash($code, PASSWORD_DEFAULT), 'at' => time(), 'by' => (string) ($p['id'] ?? '')], false);
        update_option(self::RECV, [], false);   // a new code unpairs everything…
        self::register($p, $in);                // …and the device that set it is paired at once
        return self::status($p, $in);
    }

    private static function link($p, $in) {
        if (!self::is_set()) { throw new Mutqan_Error('not_set', 'لم يُضبط رمز الربط بعد — يضبطه المالك أولًا من الإعدادات.', 409); }
        if (!Mutqan_Limiter::peek('link_fail', 10)) { throw new Mutqan_Error('rate_limited', 'محاولات خاطئة كثيرة — انتظر ١٥ دقيقة ثم أعد المحاولة.', 429); }
        if (!self::verify($in['code'] ?? '')) {
            try { Mutqan_Limiter::hit('link_fail', 10, 900); } catch (Mutqan_Error $e) { /* counted */ }
            throw new Mutqan_Error('forbidden', 'رمز الربط غير صحيح.', 403);
        }
        self::register($p, $in);
        return self::status($p, $in);
    }

    private static function ping($p, $in) {
        Mutqan_Limiter::hit('link_ping', 400, 3600);
        $id = self::dev($in); $r = self::recv();
        if ($id === '' || !isset($r[$id])) { return ['linked' => false, 'online' => false]; }
        $r[$id]['seen'] = time(); $r[$id]['uid'] = (string) ($p['id'] ?? ''); $r[$id]['role'] = (string) ($p['role'] ?? '');
        update_option(self::RECV, $r, false);
        return ['linked' => true, 'online' => true];
    }

    private static function unlink($p, $in) {
        $r = self::recv();
        $target = (($p['role'] ?? '') === 'owner' && !empty($in['target'])) ? substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $in['target']), 0, 40) : self::dev($in);
        if ($target !== '') { unset($r[$target]); update_option(self::RECV, $r, false); }
        return self::status($p, $in);
    }
}
