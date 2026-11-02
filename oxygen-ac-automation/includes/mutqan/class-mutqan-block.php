<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Block list — stops a customer who abuses the site: by phone number, IP address or device id.
 * Stored hashed (phones/IPs/devices as salted hashes, with a short label for the owner). Staff are never blocked.
 */
final class Mutqan_Block {
    const OPT = 'mutqan_blocklist';   // ['phone' => [hash => ['label','at']], 'ip' => [...], 'device' => [...]]

    private static function all() { $o = get_option(self::OPT, []); return is_array($o) ? $o : []; }
    private static function h($kind, $v) { return hash_hmac('sha256', $kind . '|' . $v, wp_salt('auth')); }
    public static function norm_phone($p) { return substr(preg_replace('/\D/', '', Mutqan_Auth::fold_digits((string) $p)), -9); }
    public static function client_ip() { return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : ''; }
    private static function dev() { return isset($_SERVER['HTTP_X_MUTQAN_ANON']) ? substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $_SERVER['HTTP_X_MUTQAN_ANON']), 0, 60) : ''; }

    public static function add($kind, $value, $label = '') {
        if (!in_array($kind, ['phone', 'ip', 'device'], true)) { throw new Exception('نوع الحظر غير معروف.'); }
        $v = $kind === 'phone' ? self::norm_phone($value) : trim((string) $value);
        if (strlen($v) < ($kind === 'phone' ? 8 : 4)) { throw new Exception('القيمة غير صالحة.'); }
        $a = self::all(); $a[$kind][self::h($kind, $v)] = ['label' => mb_substr($label !== '' ? $label : ($kind === 'phone' ? '…' . substr($v, -4) : substr($v, 0, 4) . '…'), 0, 60), 'at' => gmdate('c')];
        update_option(self::OPT, $a, false);
        return ['blocked' => true, 'kind' => $kind];
    }
    public static function remove($kind, $hash) { $a = self::all(); unset($a[$kind][$hash]); update_option(self::OPT, $a, false); return ['removed' => true]; }
    public static function list_all() {
        $out = [];
        foreach (self::all() as $k => $rows) { foreach ((array) $rows as $h => $r) { $out[] = ['kind' => $k, 'hash' => $h, 'label' => $r['label'] ?? '', 'at' => $r['at'] ?? '']; } }
        return $out;
    }
    private static function has($kind, $v) { $a = self::all(); return $v !== '' && isset($a[$kind][self::h($kind, $v)]); }

    /** Request-level check (IP / device). Staff pass through. @throws Mutqan_Error */
    public static function check_request($p) {
        if (!self::all() || (($p['kind'] ?? 'guest') === 'user' && Mutqan_Policy::is_staff($p))) { return; }
        if (self::has('ip', self::client_ip()) || self::has('device', self::dev())) { throw new Mutqan_Error('blocked', 'تم تقييد الوصول من هذا الجهاز.', 403); }
    }
    /** Row-level check for customer-written orders/calls (phone numbers). */
    public static function check_row($data, $p) {
        if (!self::all() || Mutqan_Policy::is_staff($p)) { return; }
        foreach (['phone', 'clientPhone', 'contact'] as $f) { if (!empty($data[$f]) && self::has('phone', self::norm_phone($data[$f]))) { throw new Mutqan_Error('blocked', 'تم تقييد الوصول لهذا الرقم.', 403); } }
    }
}
