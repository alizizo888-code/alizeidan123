<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Transfer receipts (owner, 2026-11-24).
 *
 * The customer sends the picture of his transfer on WhatsApp → the hub passes its link → here it is copied to our own
 * private folder (never served directly: a name nobody can guess, behind «deny from all»), and operations are told with
 * a direct «راجع وأكّد الدفع» link. On the order page the picture is shown stamped — order number, client, service,
 * date and appointment drawn on it (in the browser: it shapes Arabic, PHP's GD does not) — and the stamped copy is saved
 * back next to the original, so what is kept is the receipt with its order written on it.
 */
final class Mutqan_Proof {

    const MAX = 8388608;   // 8 MB

    private static function sys() { return ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']]; }

    private static function dir() {
        $up = wp_upload_dir();
        $d = trailingslashit($up['basedir']) . 'mutqan-private/proofs';
        if (!is_dir($d)) { wp_mkdir_p($d); }
        $root = dirname($d);
        if (!file_exists($root . '/.htaccess')) { @file_put_contents($root . '/.htaccess', "Require all denied\nDeny from all\n"); }
        if (!file_exists($root . '/index.html')) { @file_put_contents($root . '/index.html', ''); }
        return $d;
    }

    private static function ext($mime) {
        $m = ['image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
        return $m[strtolower(trim(explode(';', (string) $mime)[0]))] ?? '';
    }

    /** Copy the receipt at $url into our folder. Returns the file name, or '' when it could not be fetched. */
    public static function keep($orderId, $url) {
        $url = (string) $url;
        $bytes = ''; $mime = '';
        // the center is on our own site: read the file straight from disk when it is ours
        $up = wp_upload_dir();
        if (strpos($url, (string) $up['baseurl']) === 0) {
            $p = realpath($up['basedir'] . substr($url, strlen($up['baseurl'])));
            if ($p && strpos($p, realpath($up['basedir'])) === 0 && is_file($p) && filesize($p) <= self::MAX) {
                $bytes = (string) file_get_contents($p);
                $mime = function_exists('mime_content_type') ? (string) mime_content_type($p) : '';
            }
        }
        if ($bytes === '') {
            if (!preg_match('#^https://#', $url)) { return ''; }
            $r = wp_remote_get($url, ['timeout' => 20, 'limit_response_size' => self::MAX]);
            if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) { return ''; }
            $bytes = (string) wp_remote_retrieve_body($r);
            $mime = (string) wp_remote_retrieve_header($r, 'content-type');
        }
        $ext = self::ext($mime);
        if ($bytes === '' || $ext === '') { return ''; }
        $name = preg_replace('/[^a-z0-9_-]/i', '', (string) $orderId) . '-' . wp_generate_password(16, false) . '.' . $ext;
        file_put_contents(self::dir() . '/' . $name, $bytes);
        return $name;
    }

    private static function path($name) {
        $name = basename((string) $name);
        return $name !== '' ? self::dir() . '/' . $name : '';
    }

    private static function data_url($name) {
        $p = self::path($name);
        if (!$p || !is_file($p)) { return ''; }
        $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'][strtolower(pathinfo($p, PATHINFO_EXTENSION))] ?? '';
        return $mime ? 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($p)) : '';
    }

    /** What is written on the receipt. */
    public static function facts($o) {
        $tz = new DateTimeZone('Asia/Riyadh');
        $fmt = function ($iso) use ($tz) { $t = $iso ? strtotime((string) $iso) : 0; if (!$t) { return ''; } $d = new DateTime('@' . $t); $d->setTimezone($tz); return $d->format('Y/m/d g:i') . ((int) $d->format('G') < 12 ? ' ص' : ' م'); };
        return [
            'number' => Mutqan_Store::num($o),
            'client' => (string) ($o['clientName'] ?? ''),
            'phone' => (string) ($o['clientPhone'] ?? ''),
            'service' => trim((string) ($o['serviceLabel'] ?? ($o['service'] ?? '')) . ((string) ($o['acType'] ?? '') !== '' ? ' — ' . $o['acType'] : '')),
            'date' => $fmt($o['createdAt'] ?? ''),
            'appointment' => (string) ($o['preferredTime'] ?? '') ?: $fmt($o['appointmentAt'] ?? ''),
            'amount' => (string) ($o['price'] ?? ($o['quotedTotal'] ?? '')),
            'received' => $fmt($o['paymentProof']['at'] ?? ''),
            'tech' => (string) ($o['technicianName'] ?? ''),
        ];
    }

    /** The hub: a receipt came for order $id. Saves it and tells operations. */
    public static function arrived($id, $kind, $url) {
        $o = Mutqan_Store::get_raw('orders', (string) $id);
        if (!$o) { return null; }
        $o['id'] = (string) $id;
        $prev = (array) ($o['paymentProof'] ?? []);
        $file = self::keep($id, $url);
        $proof = ['at' => gmdate('c'), 'via' => 'whatsapp', 'kind' => (string) $kind, 'file' => $file, 'stamped' => '', 'count' => (int) ($prev['count'] ?? 0) + 1];
        Mutqan_Store::update('orders', (string) $id, ['paymentProof' => $proof], self::sys());
        $n = Mutqan_Store::num($o);
        $body = ((string) ($o['clientName'] ?? '') ?: (string) ($o['clientPhone'] ?? '')) . ((string) ($o['price'] ?? '') !== '' ? ' — ' . $o['price'] . ' ر.س' : '') . "\nافتح الطلب: الإيصال قدامك مختوم ببيانات الطلب، شيّك إن التحويل وصل واضغط «تأكيد الدفع».";
        foreach (['operations', 'supervisor'] as $role) {
            try { Mutqan_Store::insert('_push', ['target' => ['role' => $role], 'title' => '🧾 إيصال تحويل وصل — راجع وأكّد الدفع #' . $n, 'body' => $body, 'url' => '#/staff/order/' . $id . '?proof=1', 'sound' => 'bell', 'priority' => 'high', 'event' => 'order_pay_proof', 'from' => 'system', 'orderId' => (string) $id], null, ['expiresAt' => gmdate('c', time() + 7 * 86400)], self::sys()); } catch (Throwable $e) { /* never block */ }
        }
        if (class_exists('Mutqan_WA')) { try { Mutqan_WA::add_step((string) $id, 'pay', '🧾 العميل بعت إيصال التحويل' . ($file === '' ? ' (الصورة في محادثته)' : ''), 'واتساب'); } catch (Throwable $e) {} }
        $f = self::facts($o + ['paymentProof' => $proof]);
        return ['ok' => true, 'saved' => $file !== '', 'caption' => "🧾 *إيصال تحويل* — طلب #{$f['number']}\n👤 {$f['client']} — {$f['phone']}\n🛠️ {$f['service']}" . ($f['amount'] !== '' ? "\n💰 {$f['amount']} ر.س" : '') . ($f['appointment'] !== '' ? "\n📅 {$f['appointment']}" : '') . "\n\nشيّك إن التحويل وصل وأكّد الدفع من صفحة الطلب."];
    }

    /** POST /proof — {op:get|stamped, id}: operations, supervisor, owner. */
    public static function handle($in, $p) {
        if (!in_array((string) ($p['role'] ?? ''), ['owner', 'operations', 'supervisor'], true)) { throw new Mutqan_Error('forbidden', 'للإدارة بس.', 403); }
        $id = (string) ($in['id'] ?? '');
        $o = Mutqan_Store::get_raw('orders', $id);
        if (!$o) { throw new Mutqan_Error('not_found', 'الطلب مش موجود.', 404); }
        $o['id'] = $id;
        $pf = (array) ($o['paymentProof'] ?? []);
        $op = (string) ($in['op'] ?? 'get');
        if ($op === 'stamped') {
            $png = (string) ($in['png'] ?? '');
            if (!preg_match('#^data:image/(png|jpeg);base64,#', $png, $m)) { throw new Mutqan_Error('bad_input', 'الصورة مش سليمة.', 400); }
            $bytes = base64_decode(substr($png, strpos($png, ',') + 1), true);
            if ($bytes === false || strlen($bytes) > self::MAX) { throw new Mutqan_Error('bad_input', 'الصورة كبيرة.', 400); }
            $name = preg_replace('/[^a-z0-9_-]/i', '', $id) . '-stamped-' . wp_generate_password(12, false) . '.' . ($m[1] === 'png' ? 'png' : 'jpg');
            file_put_contents(self::dir() . '/' . $name, $bytes);
            if (!empty($pf['stamped']) && ($old = self::path($pf['stamped'])) && is_file($old)) { @unlink($old); }
            Mutqan_Store::update('orders', $id, ['paymentProof' => array_merge($pf, ['stamped' => $name, 'stampedBy' => (string) ($p['name'] ?? '')])], self::sys());
            return ['ok' => true];
        }
        return ['proof' => $pf, 'image' => self::data_url($pf['file'] ?? ''), 'stamped' => self::data_url($pf['stamped'] ?? ''), 'facts' => self::facts($o)];
    }
}
