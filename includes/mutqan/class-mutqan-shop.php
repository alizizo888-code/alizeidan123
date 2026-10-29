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
}
