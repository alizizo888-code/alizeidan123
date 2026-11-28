<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «سجل هوية الفني» (owner, 2026-11-24).
 *
 * Every technician has one record: his photo, full name, iqama number and expiry, nationality, phone, address, the
 * iqama pictures and any other paper. The technician fills it ONCE himself (the first time he signs in: his photo and
 * his data); after that it is locked — every change (photo, phone, anything) goes through operations / the owner.
 * The iqama and paper pictures are kept in a private folder (never a public link): they are read back only through
 * this endpoint, by operations / the owner, or by the technician himself.
 */
final class Mutqan_TechId {

    const OPT = 'mutqan_techid';
    const FIELDS = ['fullName', 'iqamaNo', 'iqamaExpiry', 'nationality', 'phone', 'address', 'birthDate', 'emergencyPhone', 'notes'];
    const FILES = ['photo', 'iqamaFront', 'iqamaBack', 'license', 'other'];
    const MAX = 6291456;

    private static function all() { $a = get_option(self::OPT, []); return is_array($a) ? $a : []; }
    private static function put($id, $rec) { $a = self::all(); $a[$id] = $rec; update_option(self::OPT, $a, false); }
    private static function staff($p) { return in_array((string) ($p['role'] ?? ''), ['owner', 'operations', 'supervisor'], true); }

    private static function dir() {
        $up = wp_upload_dir();
        $d = trailingslashit($up['basedir']) . 'mutqan-private/techid';
        if (!is_dir($d)) { wp_mkdir_p($d); }
        $root = dirname($d);
        if (!file_exists($root . '/.htaccess')) { @file_put_contents($root . '/.htaccess', "Require all denied\nDeny from all\n"); }
        if (!file_exists($root . '/index.html')) { @file_put_contents($root . '/index.html', ''); }
        return $d;
    }

    private static function data_url($name) {
        $name = basename((string) $name);
        if ($name === '') { return ''; }
        $p = self::dir() . '/' . $name;
        if (!is_file($p)) { return ''; }
        $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][strtolower(pathinfo($p, PATHINFO_EXTENSION))] ?? '';
        return $mime ? 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($p)) : '';
    }

    /** What the screen shows: the data, the pictures as data URLs, and whether he may still fill it himself. */
    private static function view($id, $rec) {
        $files = [];
        foreach (self::FILES as $f) { $files[$f] = self::data_url($rec['files'][$f] ?? ''); }
        return ['techId' => $id, 'data' => array_intersect_key((array) ($rec['data'] ?? []), array_flip(self::FIELDS)), 'files' => $files,
            'locked' => !empty($rec['locked']), 'consentAt' => (string) ($rec['consentAt'] ?? ''), 'log' => array_slice((array) ($rec['log'] ?? []), 0, 30), 'updatedAt' => (string) ($rec['updatedAt'] ?? '')];
    }

    /** POST /techid — {op:get|save|file, techId?, data?, kind?, image?} */
    public static function handle($in, $p) {
        $me = (string) ($p['id'] ?? '');
        $staff = self::staff($p);
        $id = $staff && !empty($in['techId']) ? (string) $in['techId'] : $me;
        if ($id === '' || (!$staff && ($p['role'] ?? '') !== 'technician')) { throw new Mutqan_Error('forbidden', 'مش مسموح.', 403); }
        $rec = self::all()[$id] ?? ['data' => [], 'files' => [], 'locked' => false, 'log' => []];
        $op = (string) ($in['op'] ?? 'get');
        if ($op === 'get') { return self::view($id, $rec); }
        // the technician himself: only while the record is not locked (his first time); then operations only
        if (!$staff && !empty($rec['locked'])) { throw new Mutqan_Error('locked', 'التعديل بقى عن طريق العمليات — كلّمهم يعدّلوا لك.', 403); }
        $who = (string) ($p['name'] ?? '') ?: ($staff ? 'الإدارة' : 'الفني');
        if ($op === 'save') {
            $d = (array) ($in['data'] ?? []);
            $changed = [];
            foreach (self::FIELDS as $f) {
                if (!array_key_exists($f, $d)) { continue; }
                $v = mb_substr(sanitize_text_field((string) $d[$f]), 0, 200);
                if ((string) ($rec['data'][$f] ?? '') !== $v) { $rec['data'][$f] = $v; $changed[] = $f; }
            }
            if (!$staff && !empty($in['done'])) {
                // locking needs his consent to the sharing clause (owner, 2026-11-24: a problem in a job done in the
                // company's name → his identity goes to the customer / the authorities so it is settled between them)
                if (empty($in['consent'])) { throw new Mutqan_Error('consent', 'لازم توافق على بند المشاركة قبل التأكيد.', 400); }
                $rec['locked'] = true; $rec['consentAt'] = gmdate('c');
            }
            if ($staff && isset($in['locked'])) { $rec['locked'] = (bool) $in['locked']; }
            if ($changed || !empty($in['done'])) { array_unshift($rec['log'], ['at' => gmdate('c'), 'by' => $who, 'what' => $changed ? implode('، ', $changed) : 'تأكيد البيانات']); }
        } elseif ($op === 'share') {
            // operations / owner only, with a reason; every share is written in the record
            if (!$staff) { throw new Mutqan_Error('forbidden', 'للإدارة بس.', 403); }
            $why = trim(mb_substr(sanitize_text_field((string) ($in['reason'] ?? '')), 0, 300));
            if (mb_strlen($why) < 5) { throw new Mutqan_Error('bad_input', 'اكتب سبب المشاركة (رقم الطلب والمشكلة).', 400); }
            array_unshift($rec['log'], ['at' => gmdate('c'), 'by' => $who, 'what' => 'مشاركة بيانات الهوية — ' . $why . ((string) ($in['to'] ?? '') !== '' ? ' — إلى: ' . mb_substr(sanitize_text_field((string) $in['to']), 0, 80) : '')]);
        } elseif ($op === 'file') {
            $kind = (string) ($in['kind'] ?? '');
            if (!in_array($kind, self::FILES, true)) { throw new Mutqan_Error('bad_input', 'نوع الملف مش معروف.', 400); }
            $img = (string) ($in['image'] ?? '');
            if (!preg_match('#^data:image/(png|jpeg|webp);base64,#', $img, $m)) { throw new Mutqan_Error('bad_input', 'ارفع صورة (JPG / PNG).', 400); }
            $bytes = base64_decode(substr($img, strpos($img, ',') + 1), true);
            if ($bytes === false || strlen($bytes) > self::MAX) { throw new Mutqan_Error('bad_input', 'الصورة كبيرة — أقصى 6 ميجا.', 400); }
            $name = preg_replace('/[^a-z0-9_-]/i', '', $id) . '-' . $kind . '-' . wp_generate_password(14, false) . '.' . ($m[1] === 'jpeg' ? 'jpg' : $m[1]);
            file_put_contents(self::dir() . '/' . $name, $bytes);
            $old = basename((string) ($rec['files'][$kind] ?? ''));
            if ($old !== '' && is_file(self::dir() . '/' . $old)) { @unlink(self::dir() . '/' . $old); }
            $rec['files'][$kind] = $name;
            array_unshift($rec['log'], ['at' => gmdate('c'), 'by' => $who, 'what' => 'صورة: ' . $kind]);
            // his photo shows on his profile (the room, the customer's card) — a small copy, the original stays private
            if ($kind === 'photo' && class_exists('Mutqan_Store')) {
                try {
                    $rows = Mutqan_Store::select('tech_profiles', ['userId' => $id], ['limit' => 1], ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']]);
                    if ($rows) { Mutqan_Store::update('tech_profiles', (string) $rows[0]['id'], ['photoAt' => gmdate('c')], ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']]); }
                } catch (Throwable $e) { /* the record is what matters */ }
            }
        } else {
            throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400);
        }
        $rec['log'] = array_slice((array) $rec['log'], 0, 100);
        $rec['updatedAt'] = gmdate('c');
        self::put($id, $rec);
        if (!$staff) {
            try { Mutqan_Store::insert('_push', ['target' => ['role' => 'operations'], 'title' => '🪪 فني حدّث سجل هويته', 'body' => $who, 'url' => '#/staff/team', 'sound' => 'chime', 'priority' => 'normal', 'event' => 'tech_id', 'from' => 'system'], null, ['expiresAt' => gmdate('c', time() + 3 * 86400)], ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']]); } catch (Throwable $e) {}
        }
        return self::view($id, $rec);
    }
}
