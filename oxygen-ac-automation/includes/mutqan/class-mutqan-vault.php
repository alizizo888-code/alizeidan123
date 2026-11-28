<?php
/**
 * A private drawer for customers' papers (owner, 2026-11-22): commercial registrations, VAT certificates, national
 * addresses, contracts. Never a public link: the folder refuses direct reads, and a file is opened only through a signed
 * link — a short one for the staff screen, a longer one (days) when he shares it with someone.
 */
if (!defined('ABSPATH')) { exit; }

final class Mutqan_Vault {
    const COLL = 'vault_files';
    const TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic'];
    const KINDS = ['cr' => 'السجل التجاري', 'vat' => 'الشهادة الضريبية', 'address' => 'العنوان الوطني', 'contract' => 'عقد', 'quote' => 'عرض سعر', 'invoice' => 'فاتورة', 'other' => 'ملف آخر'];

    public static function dir() {
        $up = wp_upload_dir();
        $d = trailingslashit($up['basedir']) . 'mutqan-vault';
        if (!is_dir($d)) { wp_mkdir_p($d); }
        if (!file_exists($d . '/.htaccess')) {
            @file_put_contents($d . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            @file_put_contents($d . '/index.html', '');
        }
        return $d;
    }

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'النظام']; }
    private static function key() { return hash('sha256', (defined('AUTH_KEY') ? AUTH_KEY : 'mq') . '|vault|' . (defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : '')); }

    /** Keep a file (base64 or a data URL). @return array the file's record (no path, no link). */
    public static function put($b64, $mime, $name, $kind, $p, $owner = '') {
        if (!isset(self::TYPES[$mime])) { throw new Mutqan_Error('bad_type', 'صور أو PDF بس.', 415); }
        $raw = base64_decode(preg_replace('/^data:[^,]+,/', '', (string) $b64), true);
        if ($raw === false || $raw === '') { throw new Mutqan_Error('bad_input', 'الملف فاضي.', 400); }
        if (strlen($raw) > 6 * 1024 * 1024) { throw new Mutqan_Error('too_large', 'أقصى حجم 6 ميجا.', 413); }
        if (function_exists('finfo_open')) {
            $real = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $raw);
            if ($real && !isset(self::TYPES[$real]) && !($mime === 'image/heic' && strpos((string) $real, 'heic') !== false)) { throw new Mutqan_Error('bad_type', 'نوع الملف مش صورة أو PDF.', 415); }
        }
        $file = bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];
        if (@file_put_contents(self::dir() . '/' . $file, $raw) === false) { throw new Mutqan_Error('server_error', 'ما اتحفظش الملف.', 500); }
        $doc = ['file' => $file, 'mime' => $mime, 'size' => strlen($raw), 'name' => mb_substr(trim((string) $name) ?: 'ملف', 0, 120), 'kind' => isset(self::KINDS[$kind]) ? $kind : 'other',
            'owner' => (string) $owner, 'at' => gmdate('c'), 'by' => (string) ($p['name'] ?? '')];
        $r = Mutqan_Store::insert(self::COLL, $doc, null, ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'], self::sys());
        return self::pub(['id' => (string) $r['id']] + $doc);
    }

    /** The record as the screens see it, with a fresh link (12 hours). */
    public static function pub($f, $ttl = 43200) {
        return ['id' => (string) $f['id'], 'name' => $f['name'] ?? '', 'kind' => $f['kind'] ?? 'other', 'kindLabel' => self::KINDS[$f['kind'] ?? 'other'] ?? 'ملف', 'mime' => $f['mime'] ?? '',
            'size' => (int) ($f['size'] ?? 0), 'at' => $f['at'] ?? '', 'by' => $f['by'] ?? '', 'url' => self::link((string) $f['id'], $ttl)];
    }

    public static function link($id, $ttl = 43200) {
        $exp = time() + max(60, (int) $ttl);
        $sig = substr(hash_hmac('sha256', $id . '|' . $exp, self::key()), 0, 32);
        return rest_url('mutqan/v1/vault/' . rawurlencode($id)) . '?e=' . $exp . '&s=' . $sig;
    }

    public static function get($id) { $r = Mutqan_Store::get_raw(self::COLL, (string) $id); return $r ? ['id' => (string) $id] + $r : null; }

    public static function attach($id, $owner) {
        $f = self::get($id);
        if ($f && ($f['owner'] ?? '') === '') { Mutqan_Store::update(self::COLL, (string) $id, ['owner' => (string) $owner], self::sys()); }
    }

    public static function remove($id, $p) {
        if (!Mutqan_Policy::has_power($p, 'write')) { throw new Mutqan_Error('forbidden', 'للمالك والعمليات.', 403); }
        $f = self::get($id); if (!$f) { return ['ok' => true]; }
        @unlink(self::dir() . '/' . basename((string) $f['file']));
        Mutqan_Store::delete(self::COLL, (string) $id, self::sys());
        return ['ok' => true];
    }

    /** GET /vault/<id>?e=&s= — the signed link opens the file inline (PDF or image) for whoever holds it until it expires. */
    public static function serve($id, $e, $s) {
        $id = (string) $id; $e = (int) $e;
        if ($e < time() || !hash_equals(substr(hash_hmac('sha256', $id . '|' . $e, self::key()), 0, 32), (string) $s)) { status_header(403); echo 'الرابط انتهى — اطلب رابط جديد.'; exit; }
        $f = self::get($id);
        $path = $f ? self::dir() . '/' . basename((string) $f['file']) : '';
        if (!$f || !is_file($path)) { status_header(404); echo 'الملف مش موجود.'; exit; }
        nocache_headers();
        header('Content-Type: ' . $f['mime']);
        header('Content-Length: ' . filesize($path));
        header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode(($f['name'] ?? 'file') . (preg_match('/\.\w{2,5}$/', (string) $f['name']) ? '' : '.' . (self::TYPES[$f['mime']] ?? 'bin'))));
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        readfile($path); exit;
    }

    public static function boot() {
        add_action('rest_api_init', function () {
            register_rest_route('mutqan/v1', '/vault/(?P<id>[A-Za-z0-9\-]+)', ['methods' => 'GET', 'permission_callback' => '__return_true',
                'callback' => function ($req) { self::serve($req['id'], $req->get_param('e'), $req->get_param('s')); }]);
        });
    }
}
