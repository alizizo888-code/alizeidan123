<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Used-AC shop: price negotiation. The public catalogue lives in collection `used_ac` (readable by everyone);
 * the owner's private floor price lives in `used_ac_private` (staff only) and NEVER leaves the server.
 * A customer's offer is answered by a short ladder (counter-offers, then the floor) and every offer is stored in
 * `used_offers` and pushed to the admins, so the team can close the deal by phone / WhatsApp.
 */
final class Mutqan_Shop {
    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user']; }
    private static function r5($n) { return (int) (round($n / 5) * 5); }

    public static function handle($in, $p) {
        $op = (string) ($in['op'] ?? '');
        if ($op === 'offer') { return self::offer($in); }
        if ($op === 'seal') { return self::seal($in); }
        if ($op === 'load') { return self::load(); }
        throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400);
    }

    /** Ladder: offer >= floor -> accepted; otherwise counter 1 (60% towards the list price), counter 2 (25%), then the floor. */
    public static function ladder_reply($price, $floor, $offer, $attempt) {
        if ($offer >= $price) { return ['accepted', $price]; }
        if ($offer >= $floor) { return ['accepted', $offer]; }
        $span = max(0, $price - $floor);
        if ($attempt <= 1) { return ['counter', max($floor, self::r5($floor + $span * 0.6))]; }
        if ($attempt === 2) { return ['counter', max($floor, self::r5($floor + $span * 0.25))]; }
        return ['final', $floor];
    }

    private static function offer($in) {
        Mutqan_Limiter::hit('shop_offer', 30, 3600);
        $uid = (string) ($in['unitId'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.:@-]{1,64}$/', $uid)) { throw new Mutqan_Error('bad_id', 'جهاز غير صالح.', 400); }
        $offer = isset($in['price']) && is_numeric($in['price']) ? (int) round((float) $in['price']) : 0;
        if ($offer < 50 || $offer > 200000) { throw new Mutqan_Error('bad_price', 'اكتب سعراً صحيحاً.', 400); }
        $name = trim(mb_substr(wp_strip_all_tags((string) ($in['name'] ?? '')), 0, 60));
        $phone = preg_replace('/\D/', '', (string) ($in['phone'] ?? ''));
        if (!preg_match('/^(?:966|0)?5\d{8}$/', $phone)) { throw new Mutqan_Error('bad_phone', 'اكتب رقم جوال سعودي صحيح (05xxxxxxxx).', 400); }
        $phone = '966' . substr($phone, -9);

        $unit = Mutqan_Store::get_raw('used_ac', $uid);
        if (!$unit) { throw new Mutqan_Error('not_found', 'الجهاز غير موجود.', 404); }
        if (($unit['status'] ?? 'available') !== 'available') { throw new Mutqan_Error('unavailable', 'الجهاز غير متاح حالياً (محجوز أو مباع).', 409); }
        $price = (int) ($unit['price'] ?? 0);
        if ($price <= 0) { throw new Mutqan_Error('no_price', 'السعر يحدده فريقنا — تواصل معنا مباشرة.', 409); }
        $priv = Mutqan_Store::get_raw('used_ac_private', $uid) ?: [];
        $floor = isset($priv['floor']) && is_numeric($priv['floor']) && (int) $priv['floor'] > 0 ? min((int) $priv['floor'], $price) : self::r5($price * 0.9);

        $sid = 'o' . substr(md5($uid . '|' . $phone), 0, 24);
        $prev = Mutqan_Store::get_raw('used_offers', $sid) ?: [];
        $attempt = (int) ($prev['attempts'] ?? 0) + 1;
        list($status, $val) = self::ladder_reply($price, $floor, $offer, $attempt);
        $history = (array) ($prev['history'] ?? []);
        $history[] = ['offer' => $offer, 'reply' => $status, 'price' => $val, 'at' => gmdate('c')];
        $doc = [
            'unitId' => $uid, 'unitTitle' => (string) ($unit['title'] ?? ''), 'name' => $name, 'phone' => $phone,
            'listPrice' => $price, 'lastOffer' => $offer, 'lastReply' => $status, 'lastPrice' => $val,
            'attempts' => $attempt, 'history' => array_slice($history, -12), 'state' => $status === 'accepted' ? 'accepted' : 'open',
            'updatedAt' => gmdate('c'),
        ];
        Mutqan_Store::upsert('used_offers', $doc, $sid, ['groupId' => 'admins', 'writableBy' => 'admins'], self::sys());
        $title = ($status === 'accepted' ? '✅ عرض مقبول على جهاز مستعمل' : '🛒 عرض سعر على جهاز مستعمل');
        Mutqan_REST::admin_alert($title, trim(($name ?: 'عميل') . ' — ' . ($unit['title'] ?? '') . ' — عرض ' . $offer . ' ر.س (السعر ' . $price . ')'), '/staff/used-ac', 'used_offer', $status === 'accepted' ? 'high' : 'normal');

        if ($status === 'accepted') {
            $msg = 'اتفقنا مبدئياً على ' . $val . ' ر.س ✅ فريقنا يتواصل معك على جوالك لتأكيد الحجز والمعاينة. ولا تقلق، ما يتأكد البيع إلا بعد موافقتك.';
        } elseif ($status === 'counter') {
            $msg = 'تقدير لعرضك 🙏 أقدر أوصل لك لـ ' . $val . ' ر.س. إذا تناسبك اضغط «أوافق»، وإلا اكتب لي سعرك الجديد.';
        } else {
            $msg = 'هذا أقل سعر نقدر ننزل له: ' . $val . ' ر.س ولا نقدر بعده. تحب أثبّت لك الجهاز؟ وإذا حبيت تتفاهم مع فريقنا مباشرة اضغط «تواصل واتساب».';
        }
        return ['status' => $status, 'price' => $val, 'attempt' => $attempt, 'message' => $msg];
    }

    /* ---------------------------------------------------------------- order seal (verification link) */

    private static function seal_fields($in) {
        $c = function ($k, $n) use ($in) { return trim(mb_substr(wp_strip_all_tags((string) ($in[$k] ?? '')), 0, $n)); };
        $price = isset($in['price']) && is_numeric($in['price']) ? (int) round((float) $in['price']) : 0;
        return ['service' => $c('service', 120), 'price' => max(0, min(1000000, $price)), 'time' => $c('time', 80), 'address' => $c('address', 200), 'ref' => $c('ref', 24)];
    }
    private static function seal_chk($code, $f) {
        return substr(hash_hmac('sha256', $code . '|' . wp_json_encode($f, JSON_UNESCAPED_UNICODE), wp_salt('auth')), 0, 6);
    }
    /** Store the order's key facts and return the short verification link that goes into the WhatsApp message. */
    private static function seal($in) {
        Mutqan_Limiter::hit('order_seal', 40, 3600);
        $f = self::seal_fields($in);
        if ($f['service'] === '') { throw new Mutqan_Error('bad_order', 'بيانات الطلب ناقصة.', 400); }
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = ''; for ($i = 0; $i < 8; $i++) { $code .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
        $f['at'] = Mutqan_Store::iso(Mutqan_Store::now());
        Mutqan_Store::upsert('order_seals', $f, $code, ['groupId' => 'admins', 'writableBy' => 'admins'], self::sys());
        $chk = self::seal_chk($code, $f);
        return ['code' => $code, 'url' => home_url('/v/' . $code . '-' . $chk)];
    }
    /** @return array [status(verified|invalid|missing), fields|null] */
    public static function seal_check($token) {
        if (!preg_match('/^([A-Z0-9]{8})(?:-([a-f0-9]{6}))?$/', (string) $token, $m)) { return ['missing', null]; }
        $f = Mutqan_Store::get_raw('order_seals', $m[1]);
        if (!$f) { return ['missing', null]; }
        $stored = $f; unset($stored['at']);
        $ok = isset($m[2]) && hash_equals(self::seal_chk($m[1], $f), $m[2]);
        if (!$ok) { $ok2 = isset($m[2]) && hash_equals(self::seal_chk($m[1], $stored), $m[2]); $ok = $ok2; }
        return [$ok ? 'verified' : 'invalid', $f];
    }
    public static function seal_page($token) {
        Mutqan_Limiter::hit('seal_view', 120, 3600);
        list($st, $f) = self::seal_check($token);
        $map = ['verified' => ['✅ طلب موثّق', '#0a7d3b', 'بيانات الطلب مطابقة لما سُجّل في النظام.'], 'invalid' => ['⚠️ طلب غير مطابق (غلط)', '#b3261e', 'الرابط لا يطابق بيانات الطلب المسجّلة. لا تعتمد على الرسالة.'], 'missing' => ['❌ طلب غير موجود', '#6b6b6b', 'لا يوجد طلب بهذا الكود.']];
        $m = $map[$st];
        $rows = '';
        if ($f) {
            $L = ['service' => 'الخدمة', 'price' => 'السعر', 'time' => 'الموعد', 'address' => 'الموقع', 'ref' => 'رقم الطلب'];
            foreach ($L as $k => $lab) {
                if (!isset($f[$k]) || $f[$k] === '' || ($k === 'price' && !$f[$k])) { continue; }
                $v = $k === 'price' ? $f[$k] . ' ر.س' : $f[$k];
                $rows .= '<tr><td>' . esc_html($lab) . '</td><td><b>' . esc_html($v) . '</b></td></tr>';
            }
        }
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>توثيق الطلب</title>'
            . '<body style="font-family:Tahoma,sans-serif;background:#eef4fb;margin:0;padding:24px"><div style="max-width:440px;margin:auto;background:#fff;border-radius:16px;padding:24px;box-shadow:0 4px 18px #0002;border-top:8px solid ' . $m[1] . '">'
            . '<h1 style="margin:0 0 8px;color:' . $m[1] . ';font-size:24px">' . $m[0] . '</h1><p style="color:#444">' . $m[2] . '</p>'
            . ($rows ? '<table style="width:100%;border-collapse:collapse;margin-top:12px;font-size:15px">' . str_replace('<td>', '<td style="padding:8px;border-bottom:1px solid #eee">', $rows) . '</table>' : '')
            . '</div></body></html>';
        exit;
    }

    /** Appointment load for the scheduler: only the booked times (no names, phones or addresses). */
    private static function load() {
        Mutqan_Limiter::hit('sched_load', 240, 3600);
        $rows = Mutqan_Store::select('orders', ['appointmentAt' => ['gte' => gmdate('Y-m-d')]], ['limit' => 500], self::sys());
        $times = [];
        foreach ($rows as $o) {
            if (in_array($o['status'] ?? '', ['cancelled', 'completed', 'done', 'closed'], true)) { continue; }
            if (!empty($o['appointmentAt']) && strtotime((string) $o['appointmentAt'])) { $times[] = gmdate('c', strtotime((string) $o['appointmentAt'])); }
        }
        return ['times' => $times, 'now' => Mutqan_Store::iso(Mutqan_Store::now())];
    }
}
