<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Real background notifications (Web Push), from this site alone: no Firebase, no paid service, no app store.
 *
 * Until now a phone heard about a new order, a message or a hotline call only while the app was open and
 * polling. Every alert the server raises (every row written to `_push`) is now also sent through the
 * browser's own push service (Google / Apple / Mozilla run these for free) to each device that allowed
 * notifications, so it rings with the app closed and the screen off, like WhatsApp.
 *
 *   - VAPID keys are made on this server the first time they are needed and kept in an option.
 *   - The payload is encrypted the standard way (RFC 8291, aes128gcm) with PHP's own OpenSSL: no library.
 *   - Subscriptions are kept per device in a server-only collection, with who the device belongs to
 *     (user / anonymous customer, role, admin), so targets resolve the same way the in-app inbox does.
 *   - Sending happens after the request has been answered, so nobody waits for it; a subscription the
 *     push service says is gone (404 / 410) is removed.
 */
final class Mutqan_WebPush {
    const OPT = 'mutqan_vapid';
    const SUBS = '_push_subs';
    const MAX_PER_ALERT = 60;
    const ADMIN_ROLES = ['owner', 'operations', 'supervisor'];

    private static $queue = [];
    private static $hooked = false;

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user']; }

    public static function b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    public static function unb64u($s) { $s = strtr((string) $s, '-_', '+/'); return (string) base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

    public static function supported() {
        return function_exists('openssl_pkey_new') && function_exists('openssl_pkey_derive') && in_array('aes-128-gcm', openssl_get_cipher_methods(), true);
    }

    /** ['public' => 65-byte uncompressed point (b64url), 'pem' => private key PEM], made once. */
    public static function keys() {
        $k = get_option(self::OPT, []);
        if (is_array($k) && !empty($k['public']) && !empty($k['pem'])) { return $k; }
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$key) { throw new Mutqan_Error('push_unavailable', 'الخادم لا يدعم الإشعارات الخلفية.', 503); }
        openssl_pkey_export($key, $pem);
        $d = openssl_pkey_get_details($key);
        $pub = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $k = ['public' => self::b64u($pub), 'pem' => $pem, 'at' => time()];
        update_option(self::OPT, $k, false);
        return $k;
    }

    /* ------------------------------------------------------------------ REST: key / subscribe / unsubscribe */

    public static function handle($in, $p) {
        $op = (string) ($in['op'] ?? '');
        if (!self::supported()) { throw new Mutqan_Error('push_unavailable', 'الخادم لا يدعم الإشعارات الخلفية.', 503); }
        if ($op === 'key') { return ['key' => self::keys()['public']]; }
        $kind = (string) ($p['kind'] ?? 'guest');
        if ($kind === 'guest') { throw new Mutqan_Error('unauthorized', 'افتح التطبيق من جديد.', 401); }
        Mutqan_Limiter::hit('webpush_sub', 60, 3600);
        $sub = is_array($in['sub'] ?? null) ? $in['sub'] : [];
        $endpoint = (string) ($sub['endpoint'] ?? '');
        if ($op === 'unsubscribe') {
            if ($endpoint !== '') { self::drop($endpoint); }
            return ['ok' => true];
        }
        if ($op !== 'subscribe') { throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400); }
        $host = strtolower((string) wp_parse_url($endpoint, PHP_URL_HOST));
        if (strpos($endpoint, 'https://') !== 0 || $host === '' || strlen($endpoint) > 1000) { throw new Mutqan_Error('bad_request', 'اشتراك غير صالح.', 400); }
        $p256 = self::unb64u($sub['keys']['p256dh'] ?? '');
        $auth = self::unb64u($sub['keys']['auth'] ?? '');
        if (strlen($p256) !== 65 || $p256[0] !== "\x04" || strlen($auth) < 16) { throw new Mutqan_Error('bad_request', 'مفاتيح الاشتراك غير صالحة.', 400); }
        $role = (string) ($p['role'] ?? '');
        $doc = [
            'endpoint' => $endpoint, 'p256dh' => self::b64u($p256), 'auth' => self::b64u($auth),
            'userId' => (string) ($p['id'] ?? ''), 'role' => $role, 'admin' => in_array($role, self::ADMIN_ROLES, true),
            'ua' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160), 'at' => gmdate('c'),
        ];
        self::put(self::id($endpoint), $doc);
        return ['ok' => true];
    }

    private static function id($endpoint) { return 'ps:' . substr(hash('sha256', $endpoint), 0, 40); }

    private static function put($id, $doc) {
        $sys = self::sys();
        try { Mutqan_Store::delete(self::SUBS, $id, $sys, true); } catch (Throwable $e) { /* new */ }
        Mutqan_Store::insert(self::SUBS, $doc, $id, [], $sys, true);
    }

    private static function drop($endpoint) {
        try { Mutqan_Store::delete(self::SUBS, self::id($endpoint), self::sys(), true); } catch (Throwable $e) { /* gone */ }
    }

    /* ------------------------------------------------------------------ fan-out */

    /** Called for every alert written to `_push`; sent once the request is answered. */
    public static function queue($doc) {
        if (!is_array($doc) || !self::supported()) { return; }
        self::$queue[] = $doc;
        if (self::$hooked) { return; }
        self::$hooked = true;
        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
            elseif (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); }
            @ignore_user_abort(true);
            @set_time_limit(120);
            self::flush();
        });
    }

    public static function flush() {
        $q = self::$queue; self::$queue = [];
        foreach ($q as $doc) { try { self::deliver($doc); } catch (Throwable $e) { error_log('[mutqan] webpush ' . $e->getMessage()); } }
    }

    /** The devices an alert is for: the same rule as the in-app inbox. */
    public static function targets($t) {
        $t = is_array($t) ? $t : [];
        $f = [];
        if (!empty($t['user'])) { $f = ['userId' => (string) $t['user']]; }
        elseif (!empty($t['role'])) { $rows = Mutqan_Store::select(self::SUBS, ['role' => (string) $t['role']], ['limit' => 300], self::sys()); return $rows; }
        elseif (!empty($t['admins'])) { $f = ['admin' => true]; }
        else { return []; }
        return Mutqan_Store::select(self::SUBS, $f, ['limit' => 300], self::sys());
    }

    public static function deliver($doc) {
        $subs = self::targets($doc['target'] ?? []);
        if (!$subs) { return 0; }
        $hi = ($doc['priority'] ?? '') === 'high';
        $payload = wp_json_encode([
            'id' => (string) ($doc['id'] ?? ''), 'title' => (string) ($doc['title'] ?? ''), 'body' => mb_substr((string) ($doc['body'] ?? ''), 0, 600),
            'url' => (string) ($doc['url'] ?? ''), 'priority' => $hi ? 'high' : 'normal', 'event' => (string) ($doc['event'] ?? ''), 'sound' => $doc['sound'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $n = 0;
        foreach (array_slice($subs, 0, self::MAX_PER_ALERT) as $s) {
            // A call that rings an hour late is worse than none: it lives a minute; everything else a day.
            $code = self::send($s, $payload, $hi ? 'high' : 'normal', strpos((string) ($doc['event'] ?? ''), 'call') !== false ? 60 : 86400);
            if ($code === 404 || $code === 410) { self::drop((string) $s['endpoint']); }
            if ($code >= 200 && $code < 300) { $n++; }
        }
        return $n;
    }

    /** One encrypted push to one device. Returns the push service's HTTP status (0 on a network failure). */
    public static function send($s, $payload, $urgency = 'normal', $ttl = 86400) {
        $endpoint = (string) ($s['endpoint'] ?? '');
        $body = self::encrypt($payload, self::unb64u($s['p256dh'] ?? ''), self::unb64u($s['auth'] ?? ''));
        if ($body === '') { return 0; }
        $k = self::keys();
        $u = wp_parse_url($endpoint);
        $aud = ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? '');
        $jwt = self::jwt($aud, $k['pem']);
        if ($jwt === '') { return 0; }
        $r = wp_remote_post($endpoint, [
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/octet-stream', 'Content-Encoding' => 'aes128gcm',
                'TTL' => (string) (int) $ttl, 'Urgency' => $urgency,
                'Authorization' => 'vapid t=' . $jwt . ', k=' . $k['public'],
            ],
            'body' => $body,
        ]);
        if (is_wp_error($r)) { return 0; }
        return (int) wp_remote_retrieve_response_code($r);
    }

    /* ------------------------------------------------------------------ crypto (RFC 8291 / 8188 / 8292) */

    private static function hkdf($salt, $ikm, $info, $len) {
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        return substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $len);
    }

    /** Raw 65-byte P-256 point → a PEM public key OpenSSL accepts. */
    public static function point_pem($point) {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    public static function encrypt($payload, $uaPub, $authSecret) {
        if (strlen($uaPub) !== 65 || strlen($authSecret) < 16) { return ''; }
        $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$eph) { return ''; }
        $d = openssl_pkey_get_details($eph);
        $asPub = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $peer = openssl_pkey_get_public(self::point_pem($uaPub));
        if (!$peer) { return ''; }
        $shared = openssl_pkey_derive($peer, $eph, 32);
        if ($shared === false || strlen($shared) !== 32) { return ''; }
        $ikm = self::hkdf($authSecret, $shared, "WebPush: info\0" . $uaPub . $asPub, 32);
        $salt = random_bytes(16);
        $cek = self::hkdf($salt, $ikm, "Content-Encoding: aes128gcm\0", 16);
        $nonce = self::hkdf($salt, $ikm, "Content-Encoding: nonce\0", 12);
        $tag = '';
        $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ct === false) { return ''; }
        return $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag;
    }

    /** ES256 JWT for VAPID: OpenSSL signs in DER, the JWT wants the raw 64-byte r||s. */
    public static function jwt($aud, $pem) {
        $h = self::b64u('{"typ":"JWT","alg":"ES256"}');
        $c = self::b64u(wp_json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => home_url('/')], JSON_UNESCAPED_SLASHES));
        $sig = '';
        if (!openssl_sign($h . '.' . $c, $sig, $pem, OPENSSL_ALGO_SHA256)) { return ''; }
        $raw = self::der_to_raw($sig);
        return $raw === '' ? '' : $h . '.' . $c . '.' . self::b64u($raw);
    }

    public static function der_to_raw($der) {
        $o = 2;
        if (ord($der[1] ?? "\0") & 0x80) { $o += ord($der[1]) & 0x7f; }
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            if (($der[$o] ?? '') !== "\x02") { return ''; }
            $len = ord($der[$o + 1]);
            $v = substr($der, $o + 2, $len);
            $o += 2 + $len;
            $v = ltrim($v, "\0");
            $parts[] = str_pad($v, 32, "\0", STR_PAD_LEFT);
        }
        return strlen($parts[0]) === 32 && strlen($parts[1]) === 32 ? $parts[0] . $parts[1] : '';
    }
}
