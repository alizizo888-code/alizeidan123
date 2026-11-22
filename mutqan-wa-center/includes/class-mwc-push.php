<?php
if (!defined('ABSPATH')) exit;

/**
 * إشعارات Web Push (VAPID + aes128gcm) — بتوصل للموبايل حتى والتطبيق مقفول.
 * من غير أي خدمة مدفوعة: الموقع بيبعت للـpush service بتاع جوجل/أبل/موزيلا مباشرة.
 */
class MWC_Push {
    static function b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    static function b64u_dec($s) { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

    static function keys() {
        $k = get_option('mwc_vapid');
        if (is_array($k) && !empty($k['priv'])) return $k;
        $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($res, $pem);
        $det = openssl_pkey_get_details($res);
        $pub = "\x04" . str_pad($det['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($det['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $k = ['priv' => $pem, 'pub' => self::b64u($pub)];
        update_option('mwc_vapid', $k, false);
        return $k;
    }
    static function public_key() { return self::keys()['pub']; }

    static function pem_from_raw_pub($raw) {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    static function hkdf($salt, $ikm, $info, $len) {
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        return substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $len);
    }

    /** RFC 8291 */
    static function encrypt($payload, $p256dh_b64, $auth_b64) {
        $ua_pub = self::b64u_dec($p256dh_b64); $auth = self::b64u_dec($auth_b64);
        if (strlen($ua_pub) !== 65 || strlen($auth) < 16) return null;
        $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $det = openssl_pkey_get_details($eph);
        $as_pub = "\x04" . str_pad($det['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($det['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $peer = openssl_pkey_get_public(self::pem_from_raw_pub($ua_pub));
        if (!$peer) return null;
        $shared = openssl_pkey_derive($peer, $eph, 32);
        if ($shared === false) return null;
        $ikm = self::hkdf($auth, $shared, "WebPush: info\0" . $ua_pub . $as_pub, 32);
        $salt = random_bytes(16);
        $cek = self::hkdf($salt, $ikm, "Content-Encoding: aes128gcm\0", 16);
        $nonce = self::hkdf($salt, $ikm, "Content-Encoding: nonce\0", 12);
        $tag = '';
        $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        return $salt . pack('N', 4096) . chr(65) . $as_pub . $ct . $tag;
    }

    static function der_to_raw($der) {
        $o = 2; if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7f;
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$o + 1]); $v = substr($der, $o + 2, $len); $o += 2 + $len;
            $v = ltrim($v, "\0"); $out .= str_pad($v, 32, "\0", STR_PAD_LEFT);
        }
        return $out;
    }

    static function vapid($endpoint) {
        $k = self::keys();
        $p = wp_parse_url($endpoint);
        $aud = $p['scheme'] . '://' . $p['host'];
        $h = self::b64u(wp_json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $c = self::b64u(wp_json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => 'mailto:admin@' . (wp_parse_url(home_url(), PHP_URL_HOST) ?: 'oxygen11.com')]));
        openssl_sign("$h.$c", $sig, openssl_pkey_get_private($k['priv']), OPENSSL_ALGO_SHA256);
        return 'vapid t=' . "$h.$c." . self::b64u(self::der_to_raw($sig)) . ', k=' . $k['pub'];
    }

    static function subscribe($user_id, $sub) {
        global $wpdb; $t = MWC_Store::t('push');
        $ep = esc_url_raw($sub['endpoint'] ?? '');
        $p = $sub['keys']['p256dh'] ?? ''; $a = $sub['keys']['auth'] ?? '';
        if (!$ep || !$p || !$a || !preg_match('#^https://#', $ep)) return false;
        $wpdb->delete($t, ['endpoint' => $ep]);
        $wpdb->insert($t, ['user_id' => $user_id, 'endpoint' => $ep, 'p256dh' => sanitize_text_field($p), 'auth' => sanitize_text_field($a), 'created' => time()]);
        return true;
    }

    /** إرسال إشعار لكل اللي مسموح لهم يشوفوا الرقم ده */
    static function notify_account($account_id, $data) {
        global $wpdb;
        $subs = $wpdb->get_results("SELECT * FROM " . MWC_Store::t('push') . " ORDER BY id DESC LIMIT 60", ARRAY_A);
        $sent = 0;
        foreach ($subs as $s) {
            $u = MWC_Users::get($s['user_id']);
            if (!$u || (isset($u['active']) && !$u['active'])) { $wpdb->delete(MWC_Store::t('push'), ['id' => $s['id']]); continue; }
            if ($u['role'] !== 'owner' && $u['accounts'] !== 'all' && !in_array((int) $account_id, array_map('intval', (array) $u['accounts']), true)) continue;
            if ($u['role'] !== 'owner' && !in_array('chat_view', (array) $u['perms'], true)) continue;
            $sent += self::send($s, $data) ? 1 : 0;
        }
        return $sent;
    }

    static function send($s, $data) {
        global $wpdb;
        $body = self::encrypt(wp_json_encode($data, JSON_UNESCAPED_UNICODE), $s['p256dh'], $s['auth']);
        if ($body === null) return false;
        $r = wp_remote_post($s['endpoint'], ['timeout' => 6, 'body' => $body, 'headers' => [
            'Authorization' => self::vapid($s['endpoint']), 'Content-Encoding' => 'aes128gcm', 'Content-Type' => 'application/octet-stream',
            'TTL' => '86400', 'Urgency' => 'high', 'Topic' => substr(preg_replace('/[^A-Za-z0-9_-]/', '', $data['tag'] ?? 'mwc'), 0, 32)]]);
        $code = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
        if ($code === 404 || $code === 410) $wpdb->delete(MWC_Store::t('push'), ['id' => $s['id']]);
        return $code >= 200 && $code < 300;
    }
}
