<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «مركز الواتساب» on the VPS — the website's half.
 *
 * WhatsApp used to live inside the website: a gateway asked the site for work every three seconds, every incoming
 * message ran the reception bot, the order's journey and the room's commands inside a PHP request, and the app kept a
 * live inbox open on every screen. On shared hosting that made the site slow and WhatsApp late — hours late at times.
 *
 * Now the site only *reports* and *answers*:
 *   - reports: «order #1002 was created / assigned / moved», and «send this text to this phone», as one small signed
 *     POST at the end of the request (after the visitor already has his page). A report that cannot be delivered waits
 *     in a short queue the hub collects.
 *   - answers: the hub asks short, signed data questions — the order, who a phone belongs to, mark it «arrived».
 *
 * Every WhatsApp word is decided and sent by the hub. When the hub is not paired, nothing here changes anything.
 */
final class Mutqan_Hub {

    const OPT = 'mutqan_hub';
    const QUEUE = 'mutqan_hub_queue';
    const QUEUE_MAX = 300;
    const OPEN = ['new', 'forced', 'inhouse', 'assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress'];
    const LABELS = ['new' => 'جديد', 'forced' => 'إجباري', 'inhouse' => 'تنفيذ داخلي', 'assigned' => 'مُسند', 'accepted' => 'قَبِل الفني', 'en_route' => 'في الطريق', 'nearby' => 'قرّب', 'arrived' => 'وصل', 'in_progress' => 'جاري العمل', 'completed' => 'تم الإنجاز', 'closed' => 'مغلق', 'cancelled' => 'ملغي'];
    const PATCHABLE = ['status', 'customerConfirmed', 'confirmedAt', 'confirmedVia', 'lat', 'lng', 'address', 'preferredTime', 'appointmentAt', 'note', 'cancelRequest', 'rating', 'customerRating', 'winback', 'cancelReason', 'winbackDiscountPct', 'priority', 'technicianId', 'technicianName', 'cancelledAt', 'cancelledBy', 'cancelNote'];

    private static $buf = [];
    private static $hooked = false;

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'مركز الواتساب']; }
    public static function cfg() { $c = get_option(self::OPT, []); return is_array($c) ? $c + ['url' => '', 'secret' => '', 'on' => 0] : ['url' => '', 'secret' => '', 'on' => 0]; }
    /** True once the hub is paired: from then on WhatsApp is the hub's alone. */
    public static function on() { if (class_exists('Mutqan_Meta_WA') && Mutqan_Meta_WA::on()) { return false; }   // the official number took WhatsApp over
        $c = self::cfg(); return !empty($c['on']) && strlen((string) $c['secret']) >= 32 && (string) $c['url'] !== ''; }

    /* ------------------------------------------------------------------ the order, as the hub sees it */

    public static function view($o) {
        if (!is_array($o) || empty($o['id'])) { return null; }
        $id = (string) $o['id'];
        $f = class_exists('Mutqan_OrderBot') ? Mutqan_OrderBot::facts($o) : [];
        $tech = !empty($o['technicianId']) ? (Mutqan_Store::get_raw('tech_profiles', (string) $o['technicianId']) ?: []) : [];
        $svc = Mutqan_Service::label($o);
        $site = '';
        $card = (array) ($o['card'] ?? []);
        if (!empty($card['site']) && $card['site'] !== 'غير محدد') { $site = (string) $card['site']; }
        elseif (class_exists('Mutqan_WA_Central')) { $site = (string) (Mutqan_WA_Central::site((string) ($o['brand'] ?? ''))['name'] ?? ''); }
        $appt = '';
        if (!empty($o['appointmentAt']) && class_exists('Mutqan_Sales')) { $t = strtotime((string) $o['appointmentAt']); if ($t) { $appt = Mutqan_Sales::slot_label($t); } }
        $st = (string) ($o['status'] ?? 'new');
        return [
            'id' => $id, 'number' => (string) ($o['number'] ?? '') ?: substr($id, -5), 'status' => $st, 'statusLabel' => self::LABELS[$st] ?? $st,
            'source' => (string) ($o['source'] ?? ''), 'brand' => (string) ($o['brand'] ?? ''), 'site' => $site,
            'origin' => is_array($o['origin'] ?? null) ? $o['origin'] : null, 'originLine' => class_exists('Mutqan_Source') ? Mutqan_Source::line((array) ($o['origin'] ?? [])) : '', 'historyText' => (string) ($o['history']['text'] ?? ''),
            'service' => mb_substr($svc, 0, 160), 'details' => Mutqan_Service::lines($o, true), 'opsDetails' => Mutqan_Service::lines($o, false), 'clientName' => (string) ($o['clientName'] ?? ''), 'clientPhone' => (string) ($o['clientPhone'] ?? ''),
            'address' => (string) ($o['address'] ?? ''), 'lat' => is_numeric($o['lat'] ?? null) ? (float) $o['lat'] : null, 'lng' => is_numeric($o['lng'] ?? null) ? (float) $o['lng'] : null,
            'appointment' => $appt, 'preferredTime' => (string) ($o['preferredTime'] ?? ''), 'note' => (string) ($o['note'] ?? ''),
            'price' => class_exists('Mutqan_Sales') ? (int) Mutqan_Sales::amount($o) : 0,
            'techId' => (string) ($o['technicianId'] ?? ''), 'techName' => (string) ($o['technicianName'] ?? ($tech['displayName'] ?? '')),
            'techPhone' => (string) ($tech['phone'] ?? ($o['technicianPhone'] ?? '')), 'techIsSite' => !empty($tech['isSite']),
            'customerConfirmed' => !empty($o['customerConfirmed']), 'cancelRequest' => $o['cancelRequest'] ?? null, 'winback' => $o['winback'] ?? null,
            'rating' => $o['rating'] ?? null, 'winbackPct' => class_exists('Mutqan_WA_Journey') ? Mutqan_WA_Journey::winback_offer_pct() : 10,
            'facts' => $f, 'statusText' => $f && class_exists('Mutqan_OrderBot') ? Mutqan_OrderBot::status_text($f) : '',
            'jobCard' => class_exists('Mutqan_WA_Desk') ? Mutqan_WA_Desk::job_card($o) : '',
            'url' => class_exists('Mutqan_OrderBot') ? Mutqan_OrderBot::order_url($id) : '', 'seal' => (string) ($o['sealUrl'] ?? ''), 'staffUrl' => home_url('/app/#/staff/order/' . rawurlencode($id)), 'waClaim' => !empty($o['claimCode']) && empty($o['customerConfirmed']),
        ];
    }

    /* ------------------------------------------------------------------ reports to the hub */

    public static function event($type, $doc = null, $extra = []) {
        if (!self::on()) { return false; }
        $ev = array_merge(['type' => (string) $type, 'at' => gmdate('c')], $extra);
        if ($doc !== null) { $ev['order'] = self::view($doc); if (!$ev['order']) { return false; } }
        self::$buf[] = $ev;
        if (!self::$hooked) { self::$hooked = true; add_action('shutdown', [__CLASS__, 'flush'], 99); }
        return true;
    }
    public static function send($phone, $text, $purpose = 'site', $dedup = '') {
        $to = class_exists('Mutqan_WA') ? Mutqan_WA::intl($phone) : preg_replace('/\D/', '', (string) $phone);
        if ($to === '' || trim((string) $text) === '') { return false; }
        return self::event('send', null, array_filter(['phone' => $to, 'text' => mb_substr((string) $text, 0, 4000), 'purpose' => (string) $purpose, 'dedup' => (string) $dedup]));
    }

    // The full card goes with it: the room reads the same order message the customer gets (site, service, time, map, seal).
    public static function order_created($doc) { $card = ''; try { $card = class_exists('Mutqan_Rest') ? Mutqan_Rest::order_card($doc) : ''; } catch (Throwable $e) { $card = ''; } return self::event('order.created', $doc, $card !== '' ? ['card' => $card] : []); }
    public static function order_assigned($doc) { return self::event('order.assigned', $doc); }
    public static function order_status($doc, $old, $p = null) {
        $st = (string) ($doc['status'] ?? ''); $was = (string) ($old['status'] ?? '');
        if ($st === $was) { return false; }
        $by = is_array($p) && class_exists('Mutqan_Policy') && !Mutqan_Policy::is_staff($p);
        if (!$by && $st === 'cancelled' && !empty($doc['cancelRequest']['at']) && time() - (strtotime((string) $doc['cancelRequest']['at']) ?: 0) < 2 * 86400) { $by = true; }
        return self::event('order.status', $doc, ['was' => $was, 'byCustomer' => $by]);
    }

    /** At the end of the request, after the visitor has his answer: one POST with everything this request reported. */
    public static function flush() {
        if (!self::$buf) { return; }
        $events = self::$buf; self::$buf = [];
        if (function_exists('fastcgi_finish_request') && !defined('MUTQAN_TESTING')) { @fastcgi_finish_request(); }
        if (!self::post($events)) { self::enqueue($events); }
    }
    public static function post($events) {
        $c = self::cfg();
        $raw = wp_json_encode(['ts' => time(), 'events' => array_values($events)]);
        $r = wp_remote_post(rtrim((string) $c['url'], '/') . '/wp/events', ['timeout' => 4, 'headers' => ['content-type' => 'application/json', 'x-mq-hub' => hash_hmac('sha256', $raw, (string) $c['secret'])], 'body' => $raw]);
        return !is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) === 200;
    }
    private static function enqueue($events) {
        $q = get_option(self::QUEUE, []); $q = is_array($q) ? $q : [];
        foreach ($events as $e) { $q[] = $e; }
        if (count($q) > self::QUEUE_MAX) { $q = array_slice($q, -self::QUEUE_MAX); }
        update_option(self::QUEUE, $q, false);
    }
    public static function take_queue() { $q = get_option(self::QUEUE, []); delete_option(self::QUEUE); return is_array($q) ? array_values($q) : []; }

    /* ------------------------------------------------------------------ the hub asks */

    /** POST /wp-json/mutqan/v1/hub — signed with the shared secret, fresh (five minutes), one short operation. */
    public static function api($raw, $sig) {
        $c = self::cfg();
        if (strlen((string) $c['secret']) < 32 || !is_string($sig) || !hash_equals(hash_hmac('sha256', $raw, (string) $c['secret']), $sig)) { throw new Mutqan_Error('forbidden', 'توقيع غير صحيح.', 403); }
        $in = json_decode($raw, true);
        if (!is_array($in) || abs(time() - (int) ($in['ts'] ?? 0)) > 300) { throw new Mutqan_Error('stale', 'طلب قديم.', 403); }
        return ['result' => self::op((string) ($in['op'] ?? ''), $in)];
    }

    private static function order_by($in) {
        if (!empty($in['id'])) { $o = Mutqan_Store::get_raw('orders', (string) $in['id']); return $o ? $o + ['id' => (string) $in['id']] : null; }
        $rows = Mutqan_Store::select('orders', [], ['limit' => 400, 'order' => 'desc'], self::sys());
        if (!empty($in['number'])) {
            $n = preg_replace('/\D/', '', (string) $in['number']);
            foreach ($rows as $o) { if ((string) ($o['number'] ?? '') === $n) { return $o; } }
            foreach ($rows as $o) { if ($n !== '' && strpos((string) ($o['number'] ?? substr((string) $o['id'], -5)), $n) !== false) { return $o; } }
            return null;
        }
        if (!empty($in['phone'])) {
            $k = substr(preg_replace('/\D/', '', (string) $in['phone']), -9);
            foreach ($rows as $o) {
                if (strlen($k) === 9 && substr(preg_replace('/\D/', '', (string) ($o['clientPhone'] ?? '')), -9) === $k && (empty($in['open']) || in_array((string) ($o['status'] ?? ''), self::OPEN, true))) { return $o; }
            }
        }
        return null;
    }

    /**
     * «أكدت طلبي رقم #1234 … رمز الطلب: 4821» — the customer pressed confirm on the site and WhatsApp sent this from his
     * own number: that number becomes the order's, and the message itself is his confirmation. The four-digit code
     * written on the order at creation keeps anyone else from taking an order by its number.
     */
    private static function claim($in) {
        $phone = preg_replace('/\D/', '', (string) ($in['phone'] ?? ''));
        if (!empty($in['seal'])) {
            // The site's own order message («🔔 طلب جديد … ✅ للتحقق من صحة الطلب: …/v/CODE-chk»): its seal names the order.
            $o = null;
            if (class_exists('Mutqan_Shop') && strlen($phone) >= 9) {
                list($st, $f) = Mutqan_Shop::seal_check((string) $in['seal']);
                $ref = $st === 'verified' ? (string) ($f['ref'] ?? '') : '';
                if ($ref !== '') { foreach (Mutqan_Store::select('orders', [], ['limit' => 300, 'order' => 'desc'], self::sys()) as $r) { if (substr((string) $r['id'], -5) === $ref) { $o = $r; break; } } }
            }
            if (!$o) { return null; }
        } elseif (!empty($in['ref']) && empty($in['code'])) {
            // The site's order message without its verification link (sealing failed offline): «رقم الطلب: #5bfb9».
            // Accepted only for a fresh order (≤ 6 hours) that no number has claimed yet.
            $ref = strtolower(preg_replace('/[^0-9a-z]/i', '', (string) $in['ref']));
            $o = null;
            if (strlen($ref) === 5 && strlen($phone) >= 9) {
                foreach (Mutqan_Store::select('orders', [], ['limit' => 200, 'order' => 'desc'], self::sys()) as $r) {
                    if (substr((string) $r['id'], -5) !== $ref) { continue; }
                    $t = strtotime((string) ($r['createdAt'] ?? '')) ?: 0;
                    if ($t > time() - 6 * 3600 && preg_replace('/\D/', '', (string) ($r['clientPhone'] ?? '')) === '') { $o = $r; }
                    break;
                }
            }
            if (!$o) { return null; }
        } else {
            $o = self::order_by(['number' => (string) ($in['number'] ?? '')]);
            $code = preg_replace('/\D/', '', (string) ($in['code'] ?? ''));
            if (!$o || $code === '' || strlen($phone) < 9 || !hash_equals((string) ($o['claimCode'] ?? ''), $code)) { return null; }
        }
        $had = preg_replace('/\D/', '', (string) ($o['clientPhone'] ?? ''));
        if ($had !== '' && substr($had, -9) !== substr($phone, -9)) { return null; }
        $first = empty($o['customerConfirmed']);
        $o = Mutqan_Store::update('orders', $o['id'], ['clientPhone' => '+' . $phone, 'customerConfirmed' => true, 'confirmedAt' => gmdate('c'), 'confirmedVia' => 'whatsapp'], self::sys());
        if ($first && class_exists('Mutqan_WA')) { Mutqan_WA::add_step($o['id'], 'wa_confirmed', 'العميل أكد الطلب من واتساب', 'مركز الواتساب'); }
        $raw = Mutqan_Store::get_raw('orders', (string) $o['id']) + ['id' => (string) $o['id']];
        $card = $first && class_exists('Mutqan_Rest') ? Mutqan_Rest::order_card($raw) : '';
        return ['first' => $first, 'card' => $card] + self::view($raw);
    }

    private static function need($in) { $o = self::order_by($in); if (!$o) { throw new Mutqan_Error('not_found', 'الطلب مش موجود.', 404); } return $o; }

    public static function op($op, $in) {
        switch ($op) {
            case 'order': { $o = self::order_by($in); return $o ? self::view($o) : null; }
            // «جلب العملاء» (Mutqan_Growth): what follows a good rating (Google review, referral link), and a referral code seen in a message.
            case 'after_rating': { if (!class_exists('Mutqan_Growth')) { return ['text' => '']; } $o = self::need($in); return ['text' => Mutqan_Growth::after_rating($o, (int) ($in['stars'] ?? 0))]; }
            case 'referral': { return ['code' => class_exists('Mutqan_Growth') ? Mutqan_Growth::note_referral((string) ($in['phone'] ?? ''), (string) ($in['text'] ?? '')) : null]; }
            // «طرق الدفع» asked again on WhatsApp: the message with the link for his latest unpaid order, or null (the usual answer then).
            // the payment menu before the rating (owner, 2026-11-23): what to ask, and his answer
            // the detailed rating of the technician (owner, 2026-11-23): only to a pleased customer — 4–5 stars, no complaint,
            // no low rating, and who did not chase the technician twice or more
            case 'traits_ask': {
                $o = Mutqan_Store::get_raw('orders', (string) ($in['id'] ?? ''));
                $hard = array_intersect((array) ($o['techOnClient']['tags'] ?? []), ['صعب التعامل', 'بيفاصل في السعر']);   // the technician's own word on him
                if (!$o || (int) ($in['stars'] ?? 0) < 4 || !empty($o['complaint']) || (int) ($o['nudges'] ?? 0) >= 2 || !empty($o['rating']['traits']) || $hard || !empty($o['warrantyClaim']) || !empty($o['escalation'])) { return null; }
                $tn = trim(preg_replace('/^الفن[يى]\s+/u', '', (string) ($o['technicianName'] ?? '')));
                return ['text' => "🌟 سؤال أخير يفرّق معانا" . ($tn !== '' ? ' ويفرّح ' . $tn : '') . ":\nإيه أكتر حاجة عجبتك في الفني" . ($tn !== '' ? ' ' . $tn : '') . "؟ اكتب الأرقام اللي تنطبق (مثال: *1 4 6*)\n"
                    . "1️⃣ مظهره وشكله 👔\n2️⃣ نظافته وريحته الحلوة 🌸\n3️⃣ هادي ومش رغّاي 🤫\n4️⃣ أسلوبه وطريقة كلامه 💬\n5️⃣ سريع ⚡\n6️⃣ متقن في شغله 🛠️\n7️⃣ أمين 🤝\n8️⃣ مؤدب ولبق 🌷\n\nتقييمك بيوصل للفني نفسه، وبيفرّق في جودة الخدمة 💚", 'traits' => self::TRAITS];
            }
            case 'traits_set': {
                $o = Mutqan_Store::get_raw('orders', (string) ($in['id'] ?? ''));
                if (!$o) { return null; }
                $got = [];
                foreach ((array) ($in['picks'] ?? []) as $k) { $k = (int) $k; if (isset(self::TRAITS[$k])) { $got[$k] = self::TRAITS[$k]; } }
                if (!$got) { return null; }
                Mutqan_Store::update('orders', (string) $in['id'], ['rating' => array_merge((array) ($o['rating'] ?? []), ['traits' => array_values($got)])], self::sys());
                return ['labels' => array_values($got), 'tech' => (string) ($o['technicianName'] ?? '')];
            }
            case 'pay_menu': {
                if (!class_exists('Mutqan_PayChoice')) { return null; }
                $o = Mutqan_Store::get_raw('orders', (string) ($in['id'] ?? ''));
                if (!$o || Mutqan_PayChoice::is_paid($o) || get_option('mutqan_paylink_off')) { return null; }
                return ['text' => Mutqan_PayChoice::menu($o + ['id' => (string) $in['id']])];
            }
            case 'pay_pick': {
                if (!class_exists('Mutqan_PayChoice')) { return null; }
                $o = Mutqan_Store::get_raw('orders', (string) ($in['id'] ?? ''));
                return $o ? Mutqan_PayChoice::pick($o + ['id' => (string) $in['id']], (string) ($in['method'] ?? '')) : null;
            }
            case 'pay_bank': {
                if (!class_exists('Mutqan_PayChoice')) { return null; }
                $o = Mutqan_Store::get_raw('orders', (string) ($in['id'] ?? ''));
                if (!$o) { return null; }
                $r = Mutqan_PayChoice::bank_details($o + ['id' => (string) $in['id']], (int) ($in['idx'] ?? 0));
                if ($r) { try { Mutqan_Store::update('orders', (string) $in['id'], ['paymentChoice' => array_merge((array) ($o['paymentChoice'] ?? []), ['account' => $r['bank']])], self::sys()); } catch (Throwable $e) {} }
                return $r;
            }
            // the transfer receipt he sent on WhatsApp: on the order, and operations told
            case 'pay_proof': {
                $o = Mutqan_Store::get_raw('orders', (string) ($in['id'] ?? ''));
                if (!$o) { return null; }
                $n = self::num($o + ['id' => (string) $in['id']]);
                Mutqan_Store::update('orders', (string) $in['id'], ['paymentProof' => ['at' => gmdate('c'), 'via' => 'whatsapp', 'kind' => (string) ($in['kind'] ?? 'image'), 'url' => preg_match('#^https://#', (string) ($in['url'] ?? '')) ? (string) $in['url'] : '']], self::sys());
                if (class_exists('Mutqan_Rest')) { Mutqan_Rest::admin_alert('🧾 العميل بعت إيصال التحويل #' . $n, ((string) ($o['clientName'] ?? '') ?: (string) ($o['clientPhone'] ?? '')) . ' — راجعه في محادثته وأكّد الدفع', '#/staff/order/' . $in['id'], 'order_pay_proof', 'high', (string) $in['id']); }
                return ['ok' => true];
            }
            case 'paylink': {
                if (!class_exists('Mutqan_PayChoice')) { return null; }
                $o = Mutqan_PayChoice::for_phone((string) ($in['phone'] ?? ''));
                return $o ? ['text' => Mutqan_PayChoice::message($o), 'orderId' => (string) $o['id']] : null;
            }
            case 'patch': {
                $o = self::need($in);
                $patch = array_intersect_key((array) ($in['patch'] ?? []), array_flip(self::PATCHABLE));
                if (!empty($in['step']['text']) && class_exists('Mutqan_WA')) { Mutqan_WA::add_step($o['id'], (string) ($in['step']['kind'] ?? 'wa'), (string) $in['step']['text'], (string) ($in['step']['by'] ?? 'مركز الواتساب')); }
                if ($patch) { $o = Mutqan_Store::update('orders', $o['id'], $patch, self::sys()); }
                return self::view(Mutqan_Store::get_raw('orders', (string) $o['id']) + ['id' => (string) $o['id']]);
            }
            case 'step': { $o = self::need($in); if (class_exists('Mutqan_WA')) { Mutqan_WA::add_step($o['id'], (string) ($in['kind'] ?? 'wa'), (string) ($in['text'] ?? ''), (string) ($in['by'] ?? 'مركز الواتساب')); } return ['ok' => true]; }
            case 'claim': return self::claim($in);
            case 'who': return self::who((string) ($in['phone'] ?? ''));
            case 'report': {
                $w = (string) ($in['what'] ?? 'report');
                if ($w === 'techs') { return ['text' => Mutqan_WA_Desk::techs_report()]; }
                if ($w === 'orders') { return ['text' => Mutqan_WA_Desk::orders_report()]; }
                if ($w === 'cancelled') { return ['text' => Mutqan_WA_Journey::cancelled_today()]; }
                return ['text' => Mutqan_WA_Desk::report()];
            }
            case 'approve': {
                $o = self::need($in);
                if (!class_exists('Mutqan_Dispatch')) { return ['text' => '⚠️ التوزيع مش متاح.']; }
                $d = Mutqan_Dispatch::dispatch($o);
                $n = (string) ($d['technicianName'] ?? '');
                return ['text' => $n !== '' ? '✅ طلب #' . self::num($o) . ' اتسند للفني ' . $n . '.' : '⏳ طلب #' . self::num($o) . ' مستني فني متاح — وزّعه بالاسم: *وجّه ' . self::num($o) . ' لمحمود*'];
            }
            case 'assign': return ['text' => self::assign(self::need($in), trim((string) ($in['name'] ?? '')))];
            case 'reopen': {
                $o = self::need($in);
                $patch = array_merge(array_intersect_key((array) ($in['patch'] ?? []), array_flip(self::PATCHABLE)), ['status' => 'new', 'technicianId' => null, 'technicianName' => null]);
                foreach (['winback'] as $k) { if (isset($in['patch'][$k])) { $patch[$k] = $in['patch'][$k]; } }
                Mutqan_Store::update('orders', $o['id'], $patch, self::sys());
                if (class_exists('Mutqan_WA')) { Mutqan_WA::add_step($o['id'], 'reopen', 'الطلب رجع' . (!empty($in['why']) ? ' — ' . mb_substr((string) $in['why'], 0, 80) : ''), 'مركز الواتساب'); }
                if (class_exists('Mutqan_Dispatch') && Mutqan_Dispatch::is_auto()) { try { Mutqan_Dispatch::dispatch(Mutqan_Store::get_raw('orders', (string) $o['id']) + ['id' => (string) $o['id']]); } catch (Throwable $e) { error_log('[mutqan] hub reopen dispatch ' . $e->getMessage()); } }
                return self::view(Mutqan_Store::get_raw('orders', (string) $o['id']) + ['id' => (string) $o['id']]);
            }
            case 'create': return self::create($in);
            case 'eta_ask': { $o = self::need($in); return ['result' => class_exists('Mutqan_OrderBot') ? Mutqan_OrderBot::ask_eta($o) : false]; }
            case 'tell_tech': { $o = self::need($in); return ['ok' => class_exists('Mutqan_OrderBot') ? Mutqan_OrderBot::tell_tech($o, mb_substr((string) ($in['text'] ?? ''), 0, 600)) : false]; }
            case 'alert': { $o = self::need($in); if (class_exists('Mutqan_Rest')) { Mutqan_Rest::admin_alert(mb_substr((string) ($in['title'] ?? 'تنبيه'), 0, 80) . ' #' . self::num($o), mb_substr((string) ($in['body'] ?? ''), 0, 300), '#/staff/order/' . $o['id'], 'order_bot', 'high', $o['id']); } return ['ok' => true]; }
            // A WhatsApp chat that needs a person (no order yet): it rings on the phones (the apps' notification service),
            // and a tap opens «مركز الواتساب» — the new Evolution-based Center (owner, 2026-11-22), not the old raw
            // wa-hub panel at vps.oxygen11.com: that's a bare unstyled admin tool, not what the owner/staff should land on.
            case 'chat_alert': {
                if (class_exists('Mutqan_Rest')) { Mutqan_Rest::admin_alert(mb_substr((string) ($in['title'] ?? '💬 عميل على الواتساب'), 0, 80), mb_substr((string) ($in['body'] ?? ''), 0, 400), 'https://oxygen11.com/wa-center/', 'wa_chat', 'high'); }
                return ['ok' => true];
            }
            case 'info': {
                if (!class_exists('Mutqan_Customers')) { return null; }
                $t = Mutqan_Customers::topic((string) ($in['text'] ?? ''));
                return $t !== '' ? ['text' => Mutqan_Customers::info($t, 'customer')] : null;
            }
            case 'greet': return self::greet((string) ($in['phone'] ?? ''), (string) ($in['text'] ?? ''), (string) ($in['brand'] ?? ''));
            case 'converse': return self::converse($in);
            case 'quote': return self::quote((string) ($in['text'] ?? ''));
            // The WhatsApp menu's price: counted by type (split / window / other), priced by the owner's table — never by a model.
            case 'ac_price': return self::ac_price($in);
            case 'ac_table': return ['text' => self::ac_table_text()];
            // the big units' rough range + inspection fee, when the owner set them (Mutqan_Policy::big_price_text)
            case 'big_price': return ['text' => Mutqan_Policy::big_price_text((string) ($in['type'] ?? ''), (int) ($in['count'] ?? 1))];
            case 'parts': return self::parts_answer((string) ($in['text'] ?? '')) + ['strategy' => self::part_strategy()];
            case 'events': return ['events' => self::take_queue()];
            case 'ping': return ['ok' => true, 'site' => home_url('/')];
            case 'ai': return self::ai_status();
            // Chatwoot (the team's phone app on chat.oxygen11.com): the hub now carries the chats there itself.
            case 'chatwoot': {
                if (!class_exists('Mutqan_Chatwoot')) { return null; }
                $c = Mutqan_Chatwoot::settings();
                return $c['token'] !== '' && (int) $c['account'] > 0 ? ['url' => $c['url'] !== '' ? $c['url'] : 'https://chat.oxygen11.com', 'account' => (int) $c['account'], 'token' => (string) $c['token']] : null;
            }
            case 'ai_keys': return self::save_ai_keys(['providers' => (array) ($in['providers'] ?? [])]);
            // «رقم التجربة» set on the site (one phone that is both a customer and the room) is the hub's too.
            // The owner's hours (2026-11-20) — the VPS hub follows the site, not its own panel.
            case 'config': return ['testCustomer' => (string) get_option('mutqan_test_customer', ''), 'site' => home_url('/'), 'hoursFrom' => 7, 'hoursTo' => 23]
                + (class_exists('Mutqan_Lines') ? Mutqan_Lines::hub_config() : [])
                + (class_exists('Mutqan_Kit') ? ['washVideos' => array_map(function ($v) { return ['label' => (string) $v['label'], 'url' => (string) $v['url']]; }, (array) Mutqan_Kit::get()['washVideos'])] : []);
            // One procedure for both lines (Mutqan_Deal::cancel): before a technician accepted, cancelled at once.
            case 'cancel': { $o = self::need($in); if (!class_exists('Mutqan_Deal')) { return ['reply' => null]; } $r = Mutqan_Deal::cancel($o, (string) ($in['text'] ?? '')); return $r + ['num' => self::num($o)]; }
            // The same answers as the site chat (Mutqan_Concierge): an order by number, payment, warranty, offers, his live order.
            // «حملات الاستقطاب»: the hub asks for its outreach lines' next messages, and reports a «إيقاف» or a reply.
            case 'outreach_batch': return class_exists('Mutqan_Outreach') ? Mutqan_Outreach::batch((array) ($in['lines'] ?? [])) : ['items' => []];
            case 'outreach_optout': return class_exists('Mutqan_Outreach') ? Mutqan_Outreach::optout((string) ($in['phone'] ?? '')) : ['ok' => false];
            case 'outreach_reply': return class_exists('Mutqan_Outreach') ? Mutqan_Outreach::replied((string) ($in['phone'] ?? '')) : ['ok' => false];
            // «#سؤال» from a technician on WhatsApp (owner, 2026-11-23): the AI answers him like a senior colleague — prices
            // from the house sheet, technical know-how, his open job. Never a customer's private data beyond his own job.
            // «عايز أكلمكم» (owner, 2026-11-23): the hotline page of the site HE came through — a free call over the internet
            case 'hotline': {
                $brand = preg_replace('/[^a-z]/', '', strtolower((string) ($in['brand'] ?? '')));
                $ph = substr(preg_replace('/\D/', '', (string) ($in['phone'] ?? '')), -9);
                if (strlen($ph) === 9) {
                    try { foreach (Mutqan_Store::select('orders', [], ['limit' => 200, 'order' => '-createdAt'], self::sys()) as $o) { if (substr(preg_replace('/\D/', '', (string) ($o['clientPhone'] ?? '')), -9) === $ph && !empty($o['brand'])) { $brand = preg_replace('/[^a-z]/', '', strtolower((string) $o['brand'])); break; } } } catch (Throwable $e) { /* the line's brand then */ }
                }
                $host = $brand === '' || $brand === 'oxygen' ? 'oxygen11.com' : ($brand === 'mutqan' ? 'mutqan.oxygen11.com' : $brand . '-maintenance-general-contracting.oxygen11.com');
                return ['url' => 'https://' . $host . '/app/#/call', 'brand' => $brand];
            }
            // a voice note on WhatsApp → its words, so the bot answers it like a written message (owner, 2026-11-23).
            // Only a file of this site's own uploads (the WhatsApp center keeps the media there).
            case 'transcribe': {
                $url = (string) ($in['url'] ?? '');
                $up = wp_upload_dir();
                $base = trailingslashit((string) $up['baseurl']);
                if ($url === '' || strpos($url, $base) !== 0 || strpos($url, '..') !== false) { return ['text' => '']; }
                $path = trailingslashit((string) $up['basedir']) . substr($url, strlen($base));
                if (!is_file($path) || filesize($path) > 15 * 1024 * 1024) { return ['text' => '']; }
                return ['text' => class_exists('Mutqan_AI') ? Mutqan_AI::transcribe((string) file_get_contents($path), (string) ($in['mime'] ?? '')) : ''];
            }
            case 'tech_ask': {
                $q = trim((string) ($in['text'] ?? ''));
                if ($q === '') { return ['reply' => 'اكتب سؤالك بعد # 🙏']; }
                $job = trim((string) ($in['job'] ?? ''));
                $kit = class_exists('Mutqan_Kit') ? Mutqan_Kit::prompt() : '';
                $sheet = class_exists('Mutqan_AI') ? Mutqan_AI::price_sheet() : '';
                $prompt = "انت مساعد فني خبير في شركة صيانة مكيفات وتبريد في السعودية، بترد على فني من الفريق على واتساب.\n"
                    . "رد بالعامية المصرية في سطور قصيرة عملية (خطوات أو أرقام)، من غير مقدمات. لو سؤال سعر رد من جدول الأسعار بس. لو مش متأكد قول كده واقترح يكلم العمليات بكتابة * في أول الرسالة.\n\n"
                    . ($sheet !== '' ? "## جدول الأسعار\n" . $sheet . "\n\n" : '') . ($kit !== '' ? $kit . "\n\n" : '')
                    . ($job !== '' ? "## طلبه المفتوح\n" . mb_substr($job, 0, 400) . "\n\n" : '')
                    . "## سؤال الفني " . mb_substr((string) ($in['name'] ?? ''), 0, 40) . "\n" . mb_substr($q, 0, 800);
                try { $r = Mutqan_AI::run($prompt, ['tier' => 'low', 'temperature' => 0.3, 'budget' => 10], self::sys()); $txt = trim((string) ($r['text'] ?? '')); }
                catch (Throwable $e) { $txt = ''; }
                return ['reply' => $txt !== '' ? mb_substr($txt, 0, 1500) : 'المساعد الذكي مش متاح دلوقتي 🙏 ابعت سؤالك للعمليات بكتابة * في أول الرسالة.'];
            }
            case 'answer': { if (!class_exists('Mutqan_Concierge')) { return ['reply' => null]; } $a = Mutqan_Concierge::answer((string) ($in['text'] ?? ''), ['channel' => 'wa', 'phone' => (string) ($in['phone'] ?? ''), 'verified' => true, 'brand' => (string) ($in['brand'] ?? '')]); return $a ?: ['reply' => null]; }
        }
        throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400);
    }

    const TRAITS = [1 => 'مظهره وشكله', 2 => 'نظافته وريحته', 3 => 'هادي', 4 => 'أسلوبه وكلامه', 5 => 'سريع', 6 => 'متقن', 7 => 'أمين', 8 => 'مؤدب ولبق'];

    private static function num($o) { return (string) ($o['number'] ?? '') ?: substr((string) ($o['id'] ?? ''), -5); }

    /** Who is writing: a technician (with his open jobs), a member of the team, or a customer. */
    public static function who($phone) {
        $k = substr(preg_replace('/\D/', '', (string) $phone), -9);
        if (strlen($k) < 9) { return ['kind' => 'customer']; }
        // One of the company's own numbers (Mutqan_Lines): the manager is the room; a sales or outreach line is ours — never
        // a customer. A reserve number (a technician's own phone) falls through to the technician / staff lookup below.
        $ln = class_exists('Mutqan_Lines') ? Mutqan_Lines::ours($phone) : null;
        if ($ln && $ln['role'] === 'manager' && $ln['status'] === 'active') { return ['kind' => 'staff', 'name' => 'المدير', 'role' => 'owner', 'line' => 'manager']; }
        if ($ln && $ln['role'] !== 'reserve') { return ['kind' => 'line', 'name' => $ln['label'] ?: 'رقم الشركة', 'role' => $ln['role']]; }
        foreach (Mutqan_Store::select('tech_profiles', [], ['limit' => 400], self::sys()) as $t) {
            if (!empty($t['phone']) && substr(preg_replace('/\D/', '', (string) $t['phone']), -9) === $k) {
                $tid = (string) ($t['userId'] ?? ($t['id'] ?? ''));
                $jobs = $tid === '' ? [] : Mutqan_Store::select('orders', ['technicianId' => $tid, 'status' => ['in' => ['assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress']]], ['limit' => 20, 'order' => 'desc'], self::sys());
                return ['kind' => 'tech', 'id' => $tid, 'name' => (string) ($t['displayName'] ?? ($t['name'] ?? 'فني')), 'jobs' => array_values(array_filter(array_map([__CLASS__, 'view'], $jobs)))];
            }
        }
        global $wpdb;
        $rows = (array) $wpdb->get_results('SELECT id, display_name, phone, role FROM ' . Mutqan_Store::table('users') . " WHERE phone<>'' AND role IS NOT NULL AND role<>'' AND status='active'", ARRAY_A);
        foreach ($rows as $r) {
            if (substr(preg_replace('/\D/', '', (string) $r['phone']), -9) === $k && in_array((string) $r['role'], ['owner', 'operations', 'supervisor'], true)) {
                return ['kind' => 'staff', 'id' => (string) $r['id'], 'name' => (string) ($r['display_name'] ?: 'الإدارة'), 'role' => (string) $r['role']];
            }
        }
        return ['kind' => 'customer'];
    }

    private static function assign($order, $name) {
        if ($name === '') { return '❌ اكتب اسم الفني: *وجّه ' . self::num($order) . ' لمحمود*'; }
        $tech = null; $best = -1;
        foreach (Mutqan_Store::select('tech_profiles', [], ['limit' => 300], self::sys()) as $t) {
            $tn = (string) ($t['displayName'] ?? ($t['name'] ?? ''));
            if (mb_strpos($tn, $name) === false) { continue; }
            $score = (trim((string) ($t['phone'] ?? '')) !== '' ? 10 : 0) + ($tn === $name ? 5 : 0) + (empty($t['isSite']) ? 1 : 0);
            if ($score > $best) { $best = $score; $tech = $t; }
        }
        if (!$tech) { return '❌ مالقيتش فني اسمه «' . $name . '». اكتب *الفنيين* تشوف الأسماء.'; }
        $tid = (string) ($tech['userId'] ?? ($tech['id'] ?? ''));
        $tn = (string) ($tech['displayName'] ?? ($tech['name'] ?? 'فني'));
        // written down like any other hand-over: the order's history, its path and its log say who took it and from whom
        // (live test 2026-11-23: «وجّه 1018 لمصطفى» moved the order but its page still said «فني الموقع»)
        $at = gmdate('c'); $was = (string) ($order['technicianName'] ?? '');
        Mutqan_Store::update('orders', (string) $order['id'], ['technicianId' => $tid, 'technicianName' => $tn, 'status' => 'assigned', 'assignedAt' => $at, 'dispatchedBy' => 'whatsapp',
            'dispatch' => !empty($tech['isSite']) ? 'site' : 'tech', 'handler' => 'technician',
            'statusHistory' => array_merge((array) ($order['statusHistory'] ?? []), [['status' => 'assigned', 'at' => $at, 'by' => 'room', 'note' => 'من غرفة العمليات على واتساب' . ($was !== '' && $was !== $tn ? ' (كان مع ' . $was . ')' : '')]]),
            'transferLog' => array_merge((array) ($order['transferLog'] ?? []), [['from' => 'operations', 'to' => 'technician', 'at' => $at, 'by' => 'room', 'note' => $tn]]),
            'route' => array_merge((array) ($order['route'] ?? []), [['at' => $at, 'kind' => 'assign', 'text' => 'اتوجّه لـ ' . $tn . ' من غرفة العمليات', 'by' => 'غرفة العمليات']]),
        ], self::sys());
        return '✅ طلب ' . self::num($order) . ' اتوجّه لـ *' . $tn . '*' . (trim((string) ($tech['phone'] ?? '')) !== '' ? ' واتبعتله على الواتساب.' : ' (ما لوش رقم واتساب — بلّغه بنفسك).');
    }

    /** An order built from a WhatsApp chat. An open order on the same number gets the message as a note instead. */
    private static function create($in) {
        $phone = class_exists('Mutqan_WA') ? Mutqan_WA::intl((string) ($in['phone'] ?? '')) : preg_replace('/\D/', '', (string) ($in['phone'] ?? ''));
        if ($phone === '') { throw new Mutqan_Error('bad_input', 'رقم غير صالح.', 400); }
        $open = empty($in['confirmed']) ? self::order_by(['phone' => $phone, 'open' => true]) : null;   // the menu's «تأكيد» is a new order
        $service = mb_substr(trim((string) ($in['service'] ?? '')), 0, 160);
        if ($open) {
            if (class_exists('Mutqan_WA')) { Mutqan_WA::add_step($open['id'], 'wa_more', 'رسالة إضافية من العميل على واتساب: ' . mb_substr($service, 0, 80), 'مركز الواتساب'); }
            return self::view($open);
        }
        // The location itself, never a name (owner, 2026-11-21): «الشرايع عند البيك» does not take a technician to the door.
        if (!is_numeric($in['lat'] ?? null) || !is_numeric($in['lng'] ?? null)) { throw new Mutqan_Error('need_location', 'لازم اللوكيشن نفسه من واتساب قبل تسجيل الطلب.', 422); }
        $order = array_filter([
            'status' => 'new', 'source' => 'whatsapp', 'clientName' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 80), 'clientPhone' => $phone,
            'service' => $service, 'address' => mb_substr(trim((string) ($in['address'] ?? '')), 0, 160),
            'preferredTime' => mb_substr(trim((string) ($in['time'] ?? '')), 0, 120), 'brand' => (string) ($in['brand'] ?? '') ?: 'oxygen',
            'lat' => is_numeric($in['lat'] ?? null) ? (float) $in['lat'] : null, 'lng' => is_numeric($in['lng'] ?? null) ? (float) $in['lng'] : null,
            'waLine' => (string) ($in['line'] ?? ''), 'createdAt' => gmdate('c'),
            'origin' => class_exists('Mutqan_Source') ? Mutqan_Source::clean_origin([
                'site' => (string) ($in['brand'] ?? ''), 'page' => 'whatsapp', 'channel' => 'whatsapp', 'code' => (string) ($in['code'] ?? ''),
                'title' => (string) ($in['source'] ?? ''), 'url' => (string) ($in['sourceUrl'] ?? ''), 'at' => gmdate('c'),
            ], array_filter(['line' => (string) ($in['line'] ?? ''), 'platform' => (string) ($in['platform'] ?? '')])) : null,
            'agreedPrice' => is_numeric($in['price'] ?? null) && (int) $in['price'] > 0 ? (int) $in['price'] : null,
            'acQuote' => is_array($in['acQuote'] ?? null) ? $in['acQuote'] + ['total' => (int) ($in['price'] ?? 0)] : null, 'specialtyId' => (string) ($in['spec'] ?? '') ?: null,
            'statusHistory' => [['status' => 'new', 'at' => gmdate('c'), 'by' => 'whatsapp']],
            // The WhatsApp menu showed him the whole order and he pressed «تأكيد»: confirmed already.
            'customerConfirmed' => !empty($in['confirmed']) ? true : null, 'confirmedAt' => !empty($in['confirmed']) ? gmdate('c') : null, 'confirmedVia' => !empty($in['confirmed']) ? 'whatsapp' : null,
            'qty' => is_numeric($in['units'] ?? null) ? (int) $in['units'] : null,
            // what the menu chose, exactly: the mode and the A/C type (the label is read from these, not guessed)
            'svcMode' => isset(Mutqan_Service::MODES[$in['mode'] ?? '']) ? (string) $in['mode'] : null,
            'acType' => isset(Mutqan_Service::TYPES[$in['acType'] ?? '']) ? (string) $in['acType'] : null,
            'description' => mb_substr(trim((string) ($in['desc'] ?? '')), 0, 300) ?: null,
        ], static function ($v) { return $v !== null && $v !== ''; });
        // The hub writes as the system, which the store's price guard trusts — so the floor is checked here: a price
        // under what the owner allows never reaches the order (it becomes a note for operations; the order is kept).
        if (isset($order['agreedPrice']) && !empty($order['specialtyId'])) {
            $fl = ($order['specialtyId'] === 'ac' && !empty($order['acQuote'])) ? Mutqan_Policy::ac_floor($order['acQuote']) : Mutqan_Policy::floor_for($order['specialtyId'], $order['qty'] ?? 1, false);
            if ((float) $order['agreedPrice'] < $fl - 1) {
                $order['note'] = trim((string) ($order['note'] ?? '') . "\n⚠️ سعر من واتساب (" . (int) $order['agreedPrice'] . ' ر.س) أقل من المسموح (' . (int) ($fl + 1) . ') — راجعه مع العميل.');
                unset($order['agreedPrice']);
                if (isset($order['acQuote']['total'])) { unset($order['acQuote']['total']); }
            }
        }
        $doc = Mutqan_Store::insert('orders', $order, null, [], self::sys());
        if (class_exists('Mutqan_WA') && !empty($doc['id'])) { Mutqan_WA::add_step((string) $doc['id'], 'wa_in', 'وصل الطلب من واتساب', 'مركز الواتساب'); }
        return self::view(Mutqan_Store::get_raw('orders', (string) $doc['id']) + ['id' => (string) $doc['id']]);
    }

    /* ------------------------------------------------------------------ the first message and the conversation */

    /**
     * Who this customer is and where he came from: the site (by the «#code» our sites put in their WhatsApp links, else
     * the line's own site), the article he was reading, and his history with us (new, returning, VIP).
     */
    public static function greet($phone, $text, $lineBrand = '') {
        $brand = '';
        if (preg_match_all('/#([a-z][a-z0-9]{1,23})\b/i', $text, $m)) {
            foreach ($m[1] as $code) { $code = strtolower($code); if (class_exists('Oxygen_Network') && Oxygen_Network::valid($code)) { $brand = $code; break; } }
        }
        $fromUs = $brand !== '' || preg_match('/(من موقع|من موقعكم|شفت اعلان|شفت إعلان|بخصوص:|من جوجل|من قوقل|من التيك|من تيك توك|سناب|انستا|حولني|حوّلني|كلمني|من طرف)/u', $text);
        if ($brand === '') { $brand = $lineBrand; }
        $known = $brand !== '';
        $site = class_exists('Mutqan_Customers') ? Mutqan_Customers::business($brand !== '' ? $brand : 'oxygen') : 'أكسجين';
        $src = class_exists('Mutqan_WA_Central') ? Mutqan_WA_Central::source($text) : [];
        // The code itself («#oxygen/a26») stays as the customer sent it; here it becomes the article (title, link, WordPress/Blogger).
        $art = null; $code = '';
        if (class_exists('Mutqan_Source')) {
            $cs = Mutqan_Source::codes($text);
            if ($cs) { $known = true; $code = Mutqan_Source::code_of($cs[0]['brand'], $cs[0]['kind'], $cs[0]['id']); $art = Mutqan_Source::lookup($cs[0]); if ($brand === '') { $brand = $cs[0]['brand']; $site = class_exists('Mutqan_Customers') ? Mutqan_Customers::business($brand) : $site; } }
        }
        $title = (string) ($src['title'] ?? '') ?: (string) ($art['title'] ?? '');
        $p = class_exists('Mutqan_Customers') ? Mutqan_Customers::profile($phone) : null;
        // the site he came through is known (a code, an article, the line's own brand) — else the welcome stays general
        return ['brand' => $brand, 'site' => $site, 'known' => $known || $title !== '', 'fromUs' => (bool) $fromUs, 'source' => $title,
            'code' => $code, 'sourceUrl' => (string) ($art['url'] ?? ''), 'platform' => (string) ($art['platform'] ?? ''), 'domain' => (string) ($art['domain'] ?? ''),
            'kind' => (string) ($p['kind'] ?? 'new'), 'name' => (string) ($p['name'] ?? ''), 'orders' => (int) ($p['orders'] ?? 0),
            'lastService' => mb_substr((string) ($p['lastService'] ?? ''), 0, 60), 'lastDays' => !empty($p['lastAt']) ? max(1, (int) floor((time() - (int) $p['lastAt']) / 86400)) : 0,
            'warrantyLeft' => !empty($p['warranty']['active']) ? (int) $p['warranty']['left'] : 0, 'brief' => class_exists('Mutqan_Customers') ? Mutqan_Customers::brief($phone) : ''];
    }

    /**
     * One turn of the conversation, answered by the site's own AI (the owner's free providers, his facts and FAQ, the
     * sales brain with prices, floor and free slots). No provider configured → null, and the hub uses its own rules.
     */
    public static function converse($in) {
        if (!class_exists('Mutqan_AI') || !Mutqan_AI::any_enabled()) { return null; }
        $phone = (string) ($in['phone'] ?? '');
        $site = (string) ($in['site'] ?? 'أكسجين');
        $hist = array_slice(array_map(static function ($h) { return mb_substr((string) $h, 0, 400); }, (array) ($in['history'] ?? [])), -14);
        $lead = (array) ($in['lead'] ?? []);
        $tone = class_exists('Mutqan_WA_AI') ? (string) Mutqan_WA_AI::settings()['tone'] : 'سعودية بيضاء ودودة ومختصرة';
        $specs = class_exists('Mutqan_Rest') ? implode('، ', array_values(Mutqan_Rest::SPEC_LABELS)) : 'التكييف، غرف التبريد والتجميد، السباكة، الكهرباء، النجارة';
        $after = !empty($in['afterHours']);
        $emg = (string) ($in['emergencyPhone'] ?? '');
        $P = "أنت موظف خدمة عملاء محترف في «{$site}» للصيانة والمقاولات العامة (مكة المكرمة وجدة)، بترد على واتساب باسم الشركة.\n"
            . "اكتب بلهجة {$tone}: رسالة قصيرة أو اتنين، بأدب واحترام، زي موظف حقيقي، من غير قوائم أرقام ومن غير ما تقول إنك بوت أو ذكاء اصطناعي.\n"
            . "## الشركة\nالخدمات: {$specs}. صيانة وإصلاح جميع أنواع المكيفات، وغرف التبريد والتجميد، وكمان سباكة ونجارة وكهرباء وغيرها.\n"
            . (class_exists('Mutqan_Deal')
                // The owner's appointment rule for this hour (same as the site): 7 am–10 pm today, after 11 pm tomorrow morning.
                ? "## المواعيد (حسب الساعة دلوقتي)\n" . Mutqan_Deal::booking()['rule'] . ($emg !== '' ? "\n- رقم الطوارئ المباشر: {$emg}." : '') . "\n"
                : "مواعيد العمل: من " . (int) ($in['hoursFrom'] ?? 8) . " لحد " . (int) ($in['hoursTo'] ?? 24) . ". " . ($after ? "دلوقتي برا مواعيد العمل: احجز له أقرب موعد الصبح، إلا لو الموضوع طارئ وخطير — ساعتها اطلب منه يتصل فورًا على {$emg}.\n" : "\n"))
            . "## العميل\n" . (string) ($in['brief'] ?? 'عميل جديد') . (!empty($in['source']) ? "\nجه من صفحة: «" . mb_substr((string) $in['source'], 0, 90) . "»" : '') . "\n"
            . "## طلباته عندنا (من نفس الجدول اللي العمليات شغالة عليه)\n" . self::orders_brief($phone) . "\n"
            . "## اللي اتجمع من الطلب\n" . ($lead ? wp_json_encode($lead, JSON_UNESCAPED_UNICODE) : 'لا شيء') . "\n"
            . "## المحادثة\n" . implode("\n", $hist) . "\nالعميل (آخر رسالة): " . mb_substr((string) ($in['text'] ?? ''), 0, 600) . "\n\n"
            . "## المطلوب\n"
            . "- افهم نوع الخدمة، وجاوب سؤاله بالظبط. اجمع بالتدريج (سؤال واحد في كل رسالة): الخدمة والتفاصيل (نوع وعدد الأجهزة)، وبعدين الحي أو يبعت اللوكيشن. الاسم مش شرط.\n"
            . "- الأسعار: من جدول الأسعار الرسمي في آخر التعليمات بس. لو فاصل نزّل بالتدريج لحد أقل سعر مسموح بس، وبعدها اعتذر بلطف واعرض قيمة الخدمة والضمان.\n"
            . "- الوقت: " . (class_exists('Mutqan_Deal') ? 'لو ما حددش اكتب في lead.time «' . Mutqan_Deal::booking()['defaultTime'] . '»' . (Mutqan_Deal::booking()['offerTomorrow'] ? '، وممكن تسأله بسلاسة اليوم ولا بكره' : '، وقوله إن أقرب موعد الصبح بدري واسأله يناسبه أول الصبح ولا وقت معين') : 'لو ما حددش اكتب «اليوم — أقرب وقت ممكن»') . ".\n"
            . "- أول ما يبقى عندك الخدمة والحي: complete=true وقوله إن الطلب اتسجل وهيوصله التأكيد حالًا.\n"
            . "- شكوى، استرجاع فلوس، زعل شديد، أو قرار مش بتاعك: handoff=true.\n"
            . "- رد بالـJSON ده بس:\n"
            . '{"reply":"","lead":{"service":"","area":"","time":"","name":"","note":"","price":""},"complete":false,"handoff":false,"handoff_reason":""}';
        try { $r = Mutqan_AI::run($P, ['json' => true, 'kb' => true, 'temperature' => 0.4, 'tier' => 'low', 'feature' => 'wa_hub', 'budget' => 25, 'sales' => ['phone' => $phone]], self::sys()); }
        catch (Throwable $e) { error_log('[mutqan] hub converse ' . $e->getMessage()); return null; }
        $j = is_array($r['json'] ?? null) ? $r['json'] : null;
        $reply = trim((string) ($j['reply'] ?? ''));
        if ($reply === '') { return null; }
        $l = array_filter(array_map(static function ($v) { return trim(mb_substr((string) $v, 0, 160)); }, (array) ($j['lead'] ?? [])), 'strlen');
        // The model never sets a price (owner, 2026-11-21: «خليها 100 المكيفين» was accepted). Any figure it agreed to or
        // wrote under the owner's floor for what is being priced is replaced by the floor, and no order is completed on it.
        $g = self::price_guard_reply($reply, array_merge($lead, $l), (string) ($in['text'] ?? ''));
        if ($g) { $reply = $g['reply']; $l['price'] = (string) $g['price']; $j['complete'] = false; }
        return ['reply' => mb_substr($reply, 0, 1200), 'lead' => array_merge($lead, $l), 'complete' => !empty($j['complete']), 'handoff' => !empty($j['handoff']), 'reason' => mb_substr((string) ($j['handoff_reason'] ?? ''), 0, 140), 'provider' => (string) ($r['provider'] ?? '')];
    }

    /** His orders, in one line each, for «طلبي فين / الفني فين». */
    private static function orders_brief($phone) {
        $k = substr(preg_replace('/\D/', '', (string) $phone), -9);
        if (strlen($k) !== 9 || !class_exists('Mutqan_Store')) { return 'مفيش.'; }
        $out = [];
        foreach (Mutqan_Store::select('orders', [], ['limit' => 300, 'order' => 'desc'], self::sys()) as $o) {
            if (substr(preg_replace('/\D/', '', (string) ($o['clientPhone'] ?? '')), -9) !== $k) { continue; }
            $v = self::view($o);
            $out[] = '- #' . self::num($o) . ' — ' . ($v['service'] ?? '') . ' — الحالة: ' . ($v['statusLabel'] ?? '') . (!empty($v['techName']) ? ' — الفني: ' . $v['techName'] : '') . (!empty($o['preferredTime']) ? ' — الموعد: ' . $o['preferredTime'] : '');
            if (count($out) >= 5) { break; }
        }
        return $out ? implode("\n", $out) : 'مفيش طلبات قبل كده.';
    }

    /* ------------------------------------------------------------------ the price, from the owner's own price lists */

    const SPEC_WORDS = [
        'fridge' => ['غرفه تبريد', 'غرف تبريد', 'غرفه تجميد', 'غرف تجميد', 'ثلاجه عرض', 'فريزر', 'تجميد'],
        'ac' => ['مكيف', 'مكيفات', 'تكييف', 'سبليت', 'شباك', 'دولابي', 'مخفي', 'كاسيت', 'مركزي', 'فريون'],
        'plumbing' => ['سباك', 'سباكه', 'تسريب', 'تسرب', 'حنفيه', 'مواسير', 'صرف', 'بلاعه', 'سيفون', 'سخان'],
        'electric' => ['كهربا', 'كهرباء', 'كهربائي', 'فيش', 'لمبات', 'ماس', 'قاطع', 'طبلون'],
        'general' => ['نجار', 'نجاره', 'باب', 'دولاب', 'مطبخ'],
        'paint' => ['دهان', 'دهانات', 'بويه'], 'cleaning' => ['تنظيف', 'تعقيم'], 'pest' => ['حشرات', 'رش', 'صراصير', 'بق'],
        'tanks' => ['خزان', 'خزانات'], 'cameras' => ['كاميرا', 'كاميرات'], 'satellite' => ['دش', 'ساتلايت', 'رسيفر'],
        'appliances' => ['غساله', 'ثلاجه', 'فرن', 'نشافه'], 'moving' => ['نقل عفش', 'نقل اثاث', 'نقل الاثاث'],
    ];

    /** How many units: «2 مكيف», «مكيفين», «تلات مكيفات». */
    /** Units per AC type in normalised text: «2 سبليت و شباك» → split 2, window 1. */
    public static function ac_counts($n) {
        $n = preg_replace('/(اسبليت|سبلت|اسبلت|سبيلت)/u', 'سبليت', (string) $n);
        $words = ['واحد' => 1, 'واحده' => 1, 'وحده' => 1, 'احد' => 1, 'اتنين' => 2, 'اثنين' => 2, 'ثنين' => 2, 'تلاته' => 3, 'ثلاثه' => 3, 'تلات' => 3, 'ثلاث' => 3, 'اربعه' => 4, 'اربع' => 4, 'خمسه' => 5, 'خمس' => 5, 'سته' => 6, 'ست' => 6];
        $tok = preg_split('/\s+/u', trim($n));
        $out = ['split' => 0, 'window' => 0, 'other' => 0];
        foreach ($tok as $i => $w) {
            $k = preg_match('/سبليت/u', $w) ? 'split' : (preg_match('/شباك/u', $w) ? 'window' : (preg_match('/(كاسيت|مخفي|دولابي|كونسيلد|مركزي|داكت)/u', $w) ? 'other' : ''));
            if ($k === '') { continue; }
            $q = 0;
            for ($j = $i - 1; $j >= max(0, $i - 3); $j--) {
                $t = preg_replace('/^و(?=\d)/u', '', $tok[$j]);
                if (preg_match('/^\d{1,2}$/', $t)) { $q = (int) $t; break; }
                if (isset($words[$t])) { $q = $words[$t]; break; }
                if (isset($words[preg_replace('/^و/u', '', $t)])) { $q = $words[preg_replace('/^و/u', '', $t)]; break; }
                if (preg_match('/^(مكيفين|جهازين)$/u', $t)) { $q = 2; break; }
                if (!preg_match('/^(و|مكيف|مكيفات|جهاز|وحده|نوع|غسيل)$/u', $t)) { break; }
            }
            $out[$k] += $q ?: 1;
        }
        return $out;
    }

    private static function qty_of($n) {
        if (preg_match('/(?<!\d)(\d{1,2})(?!\d)\s*(مكيف|جهاز|وحده|سبليت|شباك|غرف|غرفه|حنفي|لمب|كاميرا)/u', $n, $m)) { return max(1, min(50, (int) $m[1])); }
        if (preg_match('/(مكيفين|جهازين|غرفتين|اتنين|اثنين|ثنين)/u', $n)) { return 2; }
        $words = ['تلات' => 3, 'ثلاث' => 3, 'تلاته' => 3, 'اربع' => 4, 'اربعه' => 4, 'خمس' => 5, 'خمسه' => 5, 'ست' => 6, 'سته' => 6];
        foreach ($words as $w => $q) { if (preg_match('/(^|\s)' . $w . '(\s|$)/u', $n)) { return $q; } }
        return 1;
    }

    /**
     * «بكام غسيل 2 سبليت؟» — the list price and the lowest the owner allows, from his own price lists (the AC
     * calculator, each specialty's base / minimum / visit fee). Repairs are priced after inspection: the starting
     * price and the inspection rule are given instead.
     */
    public static function quote($text) {
        $n = class_exists('Mutqan_Playbook') ? Mutqan_Playbook::norm($text) : mb_strtolower($text);
        $spec = '';
        foreach (self::SPEC_WORDS as $k => $words) { foreach ($words as $w) { if (mb_strpos($n, $w) !== false) { $spec = $k; break 2; } } }
        if ($spec === '') { return null; }
        $qty = self::qty_of($n);
        $label = class_exists('Mutqan_Rest') ? (string) (Mutqan_Rest::SPEC_LABELS[$spec] ?? $spec) : $spec;
        if ($spec === 'ac') {
            $type = preg_match('/شباك/u', $n) ? 'شباك' : (preg_match('/(دولابي|مخفي|كاسيت|مركزي|داكت)/u', $n) ? 'دولابي' : 'سبليت');
            $wash = (bool) preg_match('/(غسيل|غسل|اغسل|تنظيف|نظافه)/u', $n);
            if ($wash || preg_match('/(سبليت|اسبليت|شباك)/u', $n)) {
                // Count each type on its own: «مكيف سبليت و مكيف شباك» is one of each, not one unit.
                $cnt = self::ac_counts($n);
                if ($wash && (($cnt['split'] > 0) + ($cnt['window'] > 0) + ($cnt['other'] > 0)) >= 2) {
                    $ap = self::ac_price($cnt);
                    if (!empty($ap['total'])) { return ['spec' => 'ac', 'fixed' => true, 'label' => $ap['label'], 'qty' => $ap['units'], 'total' => $ap['total'], 'floor' => $ap['floor'], 'acQuote' => $ap['acQuote'], 'lines' => $ap['lines']]; }
                }
                if (!$wash) { goto repair; }
                $one = $cnt['split'] ?: ($cnt['window'] ?: $cnt['other']);
                if ($one > 0) { $qty = $one; }
                $q = ['mode' => 'wash', 'type' => $type, 'qty' => $qty, 'full' => true];
                $total = (int) round(Mutqan_Policy::ac_total($q));
                $floor = max(0, (int) Mutqan_Policy::ac_floor($q));
                if ($total > 0) { return ['spec' => 'ac', 'fixed' => true, 'label' => 'غسيل ' . $qty . ' ' . ($qty > 1 ? 'مكيف ' : 'مكيف ') . $type, 'qty' => $qty, 'total' => $total, 'floor' => min($floor, $total), 'acQuote' => $q]; }
            }
            repair:
            $row = Mutqan_Store::get_raw('pricing', 'ac') ?: [];
            $from = (int) (is_numeric($row['base'] ?? null) ? $row['base'] : (Mutqan_Policy::price_bases()['ac'] ?? 100));
            return ['spec' => 'ac', 'fixed' => false, 'label' => 'صيانة وإصلاح ' . ($qty > 1 ? $qty . ' مكيف' : 'المكيف'), 'qty' => $qty, 'from' => $from, 'inspect' => 'المعاينة مجانية لحد جهازين، وأكتر من جهازين من غير شغل رسوم الزيارة 50 ر.س.'];
        }
        $row = Mutqan_Store::get_raw('pricing', $spec) ?: [];
        $base = (float) (is_numeric($row['base'] ?? null) ? $row['base'] : (Mutqan_Policy::price_bases()[$spec] ?? 100));
        $visit = (float) (is_numeric($row['visitFee'] ?? null) ? $row['visitFee'] : 0);
        $extra = (float) (is_numeric($row['extraUnitPct'] ?? null) ? $row['extraUnitPct'] : 80);
        $total = (int) round($base * (1 + ($qty - 1) * $extra / 100) + $visit);
        $floor = (int) Mutqan_Policy::floor_for($spec, $qty, false);
        return ['spec' => $spec, 'fixed' => false, 'label' => $label, 'qty' => $qty, 'from' => $total, 'total' => $total, 'floor' => min($floor, $total), 'inspect' => 'والسعر النهائي بيتأكد بعد ما الفني يعاين، ومن غير أي حاجة من غير موافقتك.'];
    }

    /** The floor for what the conversation is pricing, and the reply corrected when the model went under it. */
    public static function price_guard_reply($reply, $lead, $text) {
        $q = self::quote(trim((string) ($lead['service'] ?? '') . ' ' . (string) ($lead['note'] ?? '')));
        if (!$q || empty($q['total'])) { return null; }
        $floor = (int) ($q['floor'] ?? $q['total']);
        $nums = function ($s) { preg_match_all('/(\d{2,5})\s*(ريال|ر\.?\s?س|sar)/u', strtr((string) $s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']), $m); return array_map('intval', $m[1]); };
        $low = array_filter(array_merge($nums($reply), $nums((string) ($lead['price'] ?? '') . ' ريال')), function ($v) use ($floor) { return $v > 0 && $v < $floor; });
        if (!$low) { return null; }
        return ['price' => $floor, 'reply' => 'آسفين 🙏 أقل سعر نقدر عليه لـ' . $q['label'] . ' هو *' . $floor . ' ر.س* (السعر الرسمي ' . (int) $q['total'] . ')، وشامل الغسيل الكامل والضمان. نكمّل الحجز؟'];
    }

    /** {split, window, other, inner?} → the official total, the lowest the owner allows today, and the lines. */
    public static function ac_price($in) {
        $types = ['split' => 'سبليت', 'window' => 'شباك', 'other' => 'دولابي'];
        $items = []; $lines = []; $total = 0; $floor = 0; $units = 0;
        // standing or cassette: priced from the same row, named as what it is
        $otherName = Mutqan_Service::TYPES[$in['otherType'] ?? ''] ?? 'كاسيت/مخفي/دولابي';
        foreach ($types as $k => $t) {
            $q = max(0, min(50, (int) ($in[$k] ?? 0)));
            if ($q < 1) { continue; }
            $one = ['mode' => 'wash', 'type' => $t, 'qty' => $q, 'full' => empty($in['inner'])];
            $sum = (int) round(Mutqan_Policy::ac_total($one));
            $fl = min($sum, max(0, (int) Mutqan_Policy::ac_floor($one) + 1));
            $items[] = ['type' => $t, 'qty' => $q, 'full' => empty($in['inner'])];
            $lines[] = $q . ' ' . ($k === 'other' ? $otherName : $t) . ' = ' . $sum . ' ر.س';
            $total += $sum; $floor += $fl; $units += $q;
        }
        if (!$items) { return ['total' => 0]; }
        if (class_exists('Mutqan_Deal') && Mutqan_Deal::band()['mode'] === 'fixed') { $floor = $total; }
        $label = 'غسيل ' . implode(' + ', array_map(function ($it) use ($otherName) { return $it['qty'] . ' ' . ($it['type'] === 'دولابي' ? $otherName : $it['type']); }, $items)) . (empty($in['inner']) ? '' : ' (داخلي فقط)');
        $acQuote = count($items) === 1 ? ['mode' => 'wash', 'type' => $items[0]['type'], 'qty' => $items[0]['qty'], 'full' => $items[0]['full']] : ['mode' => 'wash', 'items' => $items];
        return ['total' => $total, 'floor' => min($floor, $total), 'units' => $units, 'lines' => $lines, 'label' => $label, 'acQuote' => $acQuote, 'spec' => 'ac'];
    }

    /**
     * Spare parts (owner, 2026-11-21). The customer hears one thing: the part's price, or the part with its fitting —
     * never the ranges by brand / BTU / market, never the fitting rate. «from/to» = the part, «fit» = our fitting
     * (internal: 50–150 per part), the customer sees part + fit. Big parts (compressor…) have no fixed price: the photo
     * and «الفني يحدده بعد الكشف». Photos come from the WordPress media library: an image whose title carries the
     * part's name (e.g. «المكثف»). The owner can replace the table in the option «mutqan_parts».
     */
    const PARTS = [
        ['id' => 'capacitor', 'label' => 'تغيير المكثف (الكباستور)', 'words' => ['مكثف', 'كباستور', 'كاباستور', 'كابستور', 'بطاريه', 'المبينه', 'مبينه'], 'from' => 100, 'to' => 100, 'fit' => 50],
        ['id' => 'thermostat', 'label' => 'تغيير الترموستات', 'words' => ['ترموستات', 'ثرموستات', 'ثيرموستات', 'حساس الحراره', 'حساس'], 'from' => 50, 'to' => 50, 'fit' => 50],
        ['id' => 'freon', 'label' => 'شحن الفريون', 'words' => ['فريون', 'شحن غاز', 'تعبيه غاز', 'غاز المكيف'], 'from' => 200, 'to' => 270, 'fit' => 0, 'low' => 150],
        ['id' => 'contactor', 'label' => 'تغيير الكونتاكتور', 'words' => ['كونتاكتور', 'كنتاكتور', 'كونتكتر', 'كنتكتر', 'كونتاكتر', 'ريليه', 'قطع الكهرباء', 'قطع كهرباء'], 'from' => 250, 'to' => 300, 'fit' => 0],
        ['id' => 'power_board', 'label' => 'تغيير شريحة الكهرباء', 'words' => ['شريحه الكهرباء', 'شريحه كهرباء', 'بورده كهرباء', 'كرت كهرباء'], 'from' => 300, 'to' => 450, 'fit' => 0],
        ['id' => 'digital_board', 'label' => 'تغيير الشريحة الإلكترونية (الديجيتال)', 'words' => ['شريحه الكترونيه', 'شريحه ديجيتال', 'بورده', 'الكارت', 'كرت', 'شريحه'], 'from' => 300, 'to' => 500, 'fit' => 0],
        ['id' => 'fan_motor', 'label' => 'تغيير موتور المروحة', 'words' => ['موتور المروحه', 'ماتور المروحه', 'موتور', 'ماتور', 'محرك المروحه', 'فانه', 'الفان', 'دينمو', 'دنمو', 'دينامو', 'الدينمو', 'الدنمو'], 'from' => 200, 'to' => 550, 'fit' => 0],
        ['id' => 'fan_blade', 'label' => 'تغيير ريش المروحة', 'words' => ['ريش', 'ريشه', 'المروحه مكسوره'], 'from' => 150, 'to' => 250, 'fit' => 0],
        ['id' => 'compressor', 'label' => 'تغيير الكمبروسر', 'words' => ['كمبروسر', 'كمبريسور', 'كومبروسر', 'كمبرسور', 'ضاغط', 'الضاغط', 'الكباس', 'الماتور الكبير', 'الموتور الكبير', 'ماتور كبير', 'موتور كبير', 'الماتور البراني الكبير'], 'noPrice' => true],
    ];

    /** «إحنا بنبيع حل العطل مش قطعة غيار» — the owner's line for whoever haggles over a part. */
    const PART_STRATEGY = "إحنا مش محل قطع غيار 🙏 إحنا شركة صيانة — بنجيب القطعة ونركّبها ونضمنها، يعني بنبيعلك *حل العطل* مش القطعة.\nيعني الفني بيكشف، ويركّب، ويجرب، ويضمن الشغل: لو القطعة خربت بعد التركيب، أو حصل تهريب بعد شحن الفريون، إحنا اللي بنرجع ونصلّح على ضماننا.\nالسعر اللي قلناه هو سعر الحل كامل بضمانه.";

    public static function parts() {
        if (class_exists('Mutqan_Kit')) { $p = (array) Mutqan_Kit::get()['parts']; if ($p) { return $p; } }
        $o = get_option('mutqan_parts', []);
        return is_array($o) && $o ? $o : self::PARTS;
    }
    public static function part_strategy() { return class_exists('Mutqan_Kit') ? (string) Mutqan_Kit::get()['partStrategy'] : self::PART_STRATEGY; }

    /** The customer's price: the part with its fitting, as one figure or one range. '' when it has no fixed price. */
    private static function part_price($p) {
        if (!empty($p['noPrice'])) { return ''; }
        $f = (int) ($p['fit'] ?? 0); $a = (int) $p['from'] + $f; $b = (int) $p['to'] + $f;
        return $a === $b ? $a . ' ر.س' : 'من ' . $a . ' لـ ' . $b . ' ر.س';
    }

    /** The photo of the part from the media library (title contains its name), or ''. */
    /**
     * The photo of that repair from the media library, titled by the owner with the part's own name («المكثف — …»,
     * «الفريون R410»…). Several photos per part: the one whose title matches what the customer said (410, 22, شباك,
     * DC, خارجي…) wins; otherwise the first one uploaded for that part.
     */
    public static function part_image($p, $text = '') {
        $all = self::part_images($p);
        if (!$all) { return ''; }
        $n = class_exists('Mutqan_Playbook') ? Mutqan_Playbook::norm($text) : mb_strtolower((string) $text);
        $want = [];
        foreach (['410' => '/410/u', 'R22' => '/(^|\D)22(\D|$)/u', 'R32' => '/(^|\D)32(\D|$)/u', '134' => '/134/u', 'DC' => '/(انفرتر|انفيرتر|dc|دي سي|ديسي)/u', 'شباك' => '/شباك/u',
            'سبليت' => '/(سبليت|سبلت|اسبليت)/u', 'خارجي' => '/(خارجي|برا|بره|الكمبروسر|الكومبروسر)/u', 'داخلي' => '/(داخلي|جوه|جوا)/u', 'مروحة' => '/مروح/u', '3 فاز' => '/(3 فاز|تلات فاز|ثلاث فاز|380)/u', '220' => '/(220|فاز واحد|سنجل|سينجل|منزلي)/u'] as $tag => $rx) {
            if ($n !== '' && preg_match($rx, $n)) { $want[] = $tag; }
        }
        $best = $all[0]; $score = 0;
        foreach ($all as $im) { $sc = 0; foreach ($want as $w) { if (mb_stripos($im['title'], $w) !== false) { $sc++; } } if ($sc > $score) { $score = $sc; $best = $im; } }
        // he named a kind we have no photo of (a 220 V contactor, R22 gas…): no photo rather than the other kind's
        foreach ([['410', 'R22', 'R32', '134'], ['220', '3 فاز']] as $grp) {
            $asked = array_intersect($want, $grp); if (!$asked) { continue; }
            $has = false; foreach ($asked as $w) { if (mb_stripos($best['title'], $w) !== false) { $has = true; } }
            if (!$has) { foreach ($grp as $g) { if (mb_stripos($best['title'], $g) !== false) { return ''; } } }
        }
        return $best['url'];
    }

    /** Every photo of that part, oldest first: [['url', 'title']]. */
    public static function part_images($p) {
        if (!function_exists('get_posts')) { return []; }
        $key = 'mq_part_imgs_' . $p['id'];
        $c = get_transient($key);
        if (is_array($c)) { return $c; }
        $core = trim(preg_replace(['/^(تغيير|شحن)\s+/u', '/\s*\(.*\)$/u'], '', (string) $p['label']));
        $out = [];
        foreach (array_unique(array_merge([$core, preg_replace('/^ال/u', '', $core)], (array) $p['words'])) as $w) {
            $q = get_posts(['post_type' => 'attachment', 'post_mime_type' => 'image', 'post_status' => 'inherit', 's' => (string) $w, 'numberposts' => 20, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => false]);
            foreach ($q as $x) {
                if (mb_strpos(get_the_title($x->ID), preg_replace('/^ال/u', '', $core)) === false && $w !== $core) { continue; }
                $url = (string) wp_get_attachment_url($x->ID); $bn = basename(parse_url($url, PHP_URL_PATH));
                $out[$x->ID] = ['url' => substr($url, 0, -strlen($bn)) . rawurlencode(rawurldecode($bn)), 'title' => (string) get_the_title($x->ID)];   // Arabic file names, encoded for WhatsApp
            }
            if ($out) { break; }
        }
        ksort($out); $out = array_values($out);
        set_transient($key, $out, $out ? DAY_IN_SECONDS : HOUR_IN_SECONDS);
        return $out;
    }

    /** The parts he named; [] when he named none. */
    public static function parts_in($text) {
        $n = class_exists('Mutqan_Playbook') ? Mutqan_Playbook::norm($text) : (string) $text;
        $hit = [];
        foreach (self::parts() as $p) { foreach ((array) $p['words'] as $w) { if ($n !== '' && mb_strpos($n, $w) !== false) { $hit[] = $p; break; } } }
        // «الماتور الكبير» / «الضاغط» is the compressor (owner, 2026-11-21): the fan motor is not meant unless named as the fan's
        $ids = array_column($hit, 'id');
        if (in_array('compressor', $ids, true) && in_array('fan_motor', $ids, true) && !preg_match('/(مروح|فانه|الفان|دينمو|دنمو|دينامو)/u', $n)) {
            $hit = array_values(array_filter($hit, function ($p) { return $p['id'] !== 'fan_motor'; }));
        }
        return $hit;
    }

    /**
     * The customer's answer: for the part(s) he asked about only that price (with fitting) and its photo; the short
     * table when he asked for parts prices in general. ['text', 'image'].
     */
    public static function parts_answer($text = '') {
        $hit = self::parts_in($text);
        if ($hit) {
            $L = []; $img = '';
            foreach (array_slice($hit, 0, 3) as $p) {
                $pr = self::part_price($p);
                $L[] = $pr !== '' ? '🔧 *' . $p['label'] . '*: *' . $pr . '*' : '🔧 *' . $p['label'] . '*: بنأكدلك السعر بعد الكشف، والفني بيقولك قبل أي شغل.';
                if ($img === '') { $img = self::part_image($p, $text); }
            }
            $L[] = 'بالضمان ✅ — إحنا بنجيب القطعة ونركّبها ونجرب الجهاز قدامك.';
            return ['text' => implode("\n", $L), 'image' => $img, 'parts' => array_column($hit, 'id')];
        }
        $L = ['🔧 *أسعار الإصلاح*'];
        foreach (self::parts() as $p) { $pr = self::part_price($p); $L[] = '• ' . $p['label'] . ': ' . ($pr !== '' ? $pr : 'بعد الكشف'); }
        $L[] = 'الفني بيأكد العطل بعد الكشف وقبل أي شغل ✅';
        return ['text' => implode("\n", $L), 'image' => ''];
    }

    /** Kept for callers that want the text only. */
    public static function parts_text($text = '') { return self::parts_answer($text)['text']; }

    /** For the model: customer prices, the owner's strategy, and what never to tell (internal). */
    public static function parts_for_prompt() {
        $L = ['## أسعار الإصلاح للعميل (سعر حل العطل — قوله كده بالظبط، من غير «سعر القطعة» ولا «شامل التركيب»)'];
        foreach (self::parts() as $p) { $pr = self::part_price($p); $L[] = '- ' . $p['label'] . ': ' . ($pr !== '' ? $pr : 'بعد الكشف (ما تقولش رقم)'); }
        $L[] = '- (داخلي — ممنوع تقوله للعميل) السعر بيتحرك 20–60% حسب الماركة والقدرة وتوفر القطعة، والتركيب عندنا 50–150 للقطعة، والفريون ممكن ينزل لـ 150 في الركود. ما تفصّلش للعميل سعر القطعة وسعر التركيب لوحدهم، ولا الماركات، ولا النسب.';
        $L[] = '- إحنا مش بنبيع قطع غيار لوحدها: لو العميل عايز القطعة بس يشتريها، قوله بلطف إننا شركة صيانة مش محل قطع غيار — بنجيب القطعة ونركّبها ونضمنها.';
        $L[] = '- لو العميل فاصل في سعر قطعة أو قال القطعة دي غالية أو بيجيبها أرخص: فهّمه معلومة واحدة بلطف: إحنا مش محل قطع غيار، إحنا شركة صيانة بنبيع حل العطل بضمانه — لو القطعة خربت بعد التركيب أو حصل تهريب بعد شحن الفريون إحنا اللي بنرجع ونصلح.';
        return implode("\n", $L);
    }

    /** The AC wash table in words, from the owner's own numbers. */
    public static function ac_table_text() {
        $c = Mutqan_Policy::ac_table();
        return "💰 *أسعار غسيل المكيفات* (غسيل كامل داخلي وخارجي)\n"
            . '❄️ سبليت: 1 = ' . (int) $c['split1'] . ' · 2 = ' . (int) $c['split2'] . ' · 3 = ' . (int) $c['split3'] . " ر.س، والزيادة بسعر مخفّض لكل جهاز\n"
            . '🪟 شباك: 1 = ' . (int) $c['win1'] . ' · 2 = ' . (int) $c['win2'] . ' · 3 = ' . (int) $c['win3'] . ' ر.س، وكل جهاز زيادة ' . (int) $c['winExtra'] . " ر.س\n"
            . '🏢 كاسيت / مخفي / دولابي: يبدأ من ' . (int) $c['other1'] . " ر.س للجهاز\n"
            . "🔧 الصيانة والأعطال: الكشف مجاني لحد جهازين، والسعر بعد الكشف وقبل أي شغل.\n"
            . "🛡 ضمان على الشغل، والدفع بعد الخدمة.";
    }

    /* ------------------------------------------------------------------ the AI keys */

    /** Which AI providers are live (names only, never keys), and whether the paid fallback (Claude) is set. */
    public static function ai_status() {
        if (!class_exists('Mutqan_AI')) { return ['live' => [], 'claude' => false]; }
        $s = Mutqan_AI::settings();
        $live = method_exists('Mutqan_AI', 'live_providers') ? array_values((array) Mutqan_AI::live_providers($s)) : [];
        return ['live' => array_map('strval', $live), 'order' => array_values((array) $s['order']), 'claude' => Mutqan_AI::anthropic_key() !== ''];
    }

    /**
     * POST /ai-keys — the free providers' keys kept as GitHub secrets, handed to the site by a workflow signed in as
     * the owner (WordPress application password). A key the owner already typed here is never overwritten.
     */
    public static function save_ai_keys($in) {
        if (!class_exists('Mutqan_AI')) { throw new Mutqan_Error('unavailable', 'AI غير متاح.', 400); }
        $cur = (array) get_option(Mutqan_AI::OPT, []);
        $known = array_keys((array) Mutqan_AI::defaults()['providers']);
        $set = [];
        foreach ((array) ($in['providers'] ?? []) as $id => $c) {
            $id = (string) $id; $key = trim((string) ($c['key'] ?? ''));
            if (!in_array($id, $known, true) || strlen($key) < 12) { continue; }
            $old = (array) ($cur['providers'][$id] ?? []);
            if (!empty($old['key']) && empty($in['overwrite'])) { continue; }
            $cur['providers'][$id] = array_merge($old, ['enabled' => 1, 'key' => $key], !empty($c['model']) ? ['model' => (string) $c['model']] : []);
            $set[] = $id;
        }
        if ($set) { update_option(Mutqan_AI::OPT, $cur, false); }
        return ['set' => $set] + self::ai_status();
    }

    /* ------------------------------------------------------------------ pairing (the owner, once) */

    /**
     * The owner pairs the site with the hub: a fresh secret is made here, handed to the hub together with the token
     * only the server's owner has, and kept here. Nobody types or copies the secret, and it never appears anywhere else.
     */
    public static function pair($url, $token) {
        $url = rtrim(esc_url_raw((string) $url), '/');
        if (!preg_match('#^https://[a-z0-9.-]+$#i', $url) || strlen((string) $token) < 16) { throw new Mutqan_Error('bad_input', 'رابط المركز أو رمز الربط غير صحيح.', 400); }
        $secret = bin2hex(random_bytes(32));
        $r = wp_remote_post($url . '/pair', ['timeout' => 15, 'headers' => ['content-type' => 'application/json'], 'body' => wp_json_encode(['pairToken' => (string) $token, 'url' => rtrim(home_url('/'), '/'), 'secret' => $secret])]);
        if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) { throw new Mutqan_Error('pair_failed', 'المركز رفض الربط: ' . (is_wp_error($r) ? $r->get_error_message() : wp_remote_retrieve_body($r)), 400); }
        update_option(self::OPT, ['url' => $url, 'secret' => $secret, 'on' => 1, 'at' => gmdate('c')], false);
        return self::status();
    }
    public static function set_on($on) { $c = self::cfg(); $c['on'] = $on ? 1 : 0; update_option(self::OPT, $c, false); return self::status(); }
    /** The recent customer conversations on the VPS line (owner review). $phone: one customer, or '' for the latest. */
    public static function chats($phone = '', $limit = 3, $msgs = 60) {
        $c = self::cfg();
        if ((string) $c['url'] === '' || strlen((string) $c['secret']) < 32) { throw new Mutqan_Error('hub_off', 'مركز الواتساب مش مربوط.', 400); }
        $raw = wp_json_encode(['ts' => time(), 'phone' => (string) $phone, 'limit' => (int) $limit, 'msgs' => (int) $msgs]);
        $r = wp_remote_post(rtrim((string) $c['url'], '/') . '/wp/chats', ['timeout' => 15, 'headers' => ['content-type' => 'application/json', 'x-mq-hub' => hash_hmac('sha256', $raw, (string) $c['secret'])], 'body' => $raw]);
        if (is_wp_error($r)) { throw new Mutqan_Error('hub_down', 'مركز الواتساب مش بيرد: ' . $r->get_error_message(), 502); }
        $j = json_decode((string) wp_remote_retrieve_body($r), true);
        if ((int) wp_remote_retrieve_response_code($r) !== 200 || !is_array($j)) { throw new Mutqan_Error('hub_down', 'مركز الواتساب رد بخطأ ' . wp_remote_retrieve_response_code($r), 502); }
        return (array) ($j['chats'] ?? []);
    }

    /** How the hub sees one number (owner's diagnosis): chat record, who it is, the test phone, the log lines about it. */
    public static function diag($phone) {
        $c = self::cfg();
        if ((string) $c['url'] === '' || strlen((string) $c['secret']) < 32) { throw new Mutqan_Error('hub_off', 'مركز الواتساب مش مربوط.', 400); }
        $raw = wp_json_encode(['ts' => time(), 'phone' => (string) $phone]);
        $r = wp_remote_post(rtrim((string) $c['url'], '/') . '/wp/diag', ['timeout' => 15, 'headers' => ['content-type' => 'application/json', 'x-mq-hub' => hash_hmac('sha256', $raw, (string) $c['secret'])], 'body' => $raw]);
        if (is_wp_error($r)) { throw new Mutqan_Error('hub_down', 'مركز الواتساب مش بيرد: ' . $r->get_error_message(), 502); }
        $j = json_decode((string) wp_remote_retrieve_body($r), true);
        if ((int) wp_remote_retrieve_response_code($r) !== 200 || !is_array($j)) { throw new Mutqan_Error('hub_down', 'مركز الواتساب رد بخطأ ' . wp_remote_retrieve_response_code($r), 502); }
        return $j;
    }

    public static function status() { $c = self::cfg(); return ['on' => self::on(), 'url' => (string) $c['url'], 'paired' => strlen((string) $c['secret']) >= 32, 'queued' => count((array) get_option(self::QUEUE, []))]; }

    /** POST /hubadmin {op: status|pair|on|off} — the owner only. */
    public static function h_admin($in, $p) {
        if (($p['role'] ?? '') !== 'owner') { throw new Mutqan_Error('forbidden', 'للمالك فقط.', 403); }
        $op = (string) ($in['op'] ?? 'status');
        if ($op === 'pair') { return self::pair((string) ($in['url'] ?? ''), (string) ($in['pairToken'] ?? '')); }
        if ($op === 'on' || $op === 'off') { return self::set_on($op === 'on'); }
        if ($op === 'chats') { return ['chats' => self::chats((string) ($in['phone'] ?? ''), (int) ($in['limit'] ?? 3), (int) ($in['msgs'] ?? 40))]; }
        if ($op === 'diag') { return self::diag((string) ($in['phone'] ?? '')); }
        return self::status();
    }
}
