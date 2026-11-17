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
    public static function on() { $c = self::cfg(); return !empty($c['on']) && strlen((string) $c['secret']) >= 32 && (string) $c['url'] !== ''; }

    /* ------------------------------------------------------------------ the order, as the hub sees it */

    public static function view($o) {
        if (!is_array($o) || empty($o['id'])) { return null; }
        $id = (string) $o['id'];
        $f = class_exists('Mutqan_OrderBot') ? Mutqan_OrderBot::facts($o) : [];
        $tech = !empty($o['technicianId']) ? (Mutqan_Store::get_raw('tech_profiles', (string) $o['technicianId']) ?: []) : [];
        $svc = (string) ($o['serviceLabel'] ?? ($o['service'] ?? ''));
        if ($svc === '' && !empty($o['specialtyId']) && class_exists('Mutqan_Rest')) { $svc = (string) Mutqan_Rest::spec_label($o['specialtyId']); }
        if (!empty($o['serviceDetails'])) { $svc = trim($svc . ($svc !== '' ? ' — ' : '') . (string) $o['serviceDetails']); }
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
            'service' => mb_substr($svc, 0, 160), 'clientName' => (string) ($o['clientName'] ?? ''), 'clientPhone' => (string) ($o['clientPhone'] ?? ''),
            'address' => (string) ($o['address'] ?? ''), 'lat' => is_numeric($o['lat'] ?? null) ? (float) $o['lat'] : null, 'lng' => is_numeric($o['lng'] ?? null) ? (float) $o['lng'] : null,
            'appointment' => $appt, 'preferredTime' => (string) ($o['preferredTime'] ?? ''), 'note' => (string) ($o['note'] ?? ''),
            'price' => class_exists('Mutqan_Sales') ? (int) Mutqan_Sales::amount($o) : 0,
            'techId' => (string) ($o['technicianId'] ?? ''), 'techName' => (string) ($o['technicianName'] ?? ($tech['displayName'] ?? '')),
            'techPhone' => (string) ($tech['phone'] ?? ($o['technicianPhone'] ?? '')), 'techIsSite' => !empty($tech['isSite']),
            'customerConfirmed' => !empty($o['customerConfirmed']), 'cancelRequest' => $o['cancelRequest'] ?? null, 'winback' => $o['winback'] ?? null,
            'rating' => $o['rating'] ?? null, 'winbackPct' => class_exists('Mutqan_WA_Journey') ? Mutqan_WA_Journey::winback_offer_pct() : 10,
            'facts' => $f, 'statusText' => $f && class_exists('Mutqan_OrderBot') ? Mutqan_OrderBot::status_text($f) : '',
            'jobCard' => class_exists('Mutqan_WA_Desk') ? Mutqan_WA_Desk::job_card($o) : '',
            'url' => class_exists('Mutqan_OrderBot') ? Mutqan_OrderBot::order_url($id) : '', 'staffUrl' => home_url('/app/#/staff/order/' . rawurlencode($id)), 'waClaim' => !empty($o['claimCode']) && empty($o['customerConfirmed']),
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

    public static function order_created($doc) { return self::event('order.created', $doc); }
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
        $o = self::order_by(['number' => (string) ($in['number'] ?? '')]);
        $code = preg_replace('/\D/', '', (string) ($in['code'] ?? ''));
        $phone = preg_replace('/\D/', '', (string) ($in['phone'] ?? ''));
        if (!$o || $code === '' || strlen($phone) < 9 || !hash_equals((string) ($o['claimCode'] ?? ''), $code)) { return null; }
        $had = preg_replace('/\D/', '', (string) ($o['clientPhone'] ?? ''));
        if ($had !== '' && substr($had, -9) !== substr($phone, -9)) { return null; }
        $first = empty($o['customerConfirmed']);
        $o = Mutqan_Store::update('orders', $o['id'], ['clientPhone' => '+' . $phone, 'customerConfirmed' => true, 'confirmedAt' => gmdate('c'), 'confirmedVia' => 'whatsapp'], self::sys());
        if ($first && class_exists('Mutqan_WA')) { Mutqan_WA::add_step($o['id'], 'wa_confirmed', 'العميل أكد الطلب من واتساب', 'مركز الواتساب'); }
        return ['first' => $first] + self::view(Mutqan_Store::get_raw('orders', (string) $o['id']) + ['id' => (string) $o['id']]);
    }

    private static function need($in) { $o = self::order_by($in); if (!$o) { throw new Mutqan_Error('not_found', 'الطلب مش موجود.', 404); } return $o; }

    public static function op($op, $in) {
        switch ($op) {
            case 'order': { $o = self::order_by($in); return $o ? self::view($o) : null; }
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
            case 'info': {
                if (!class_exists('Mutqan_Customers')) { return null; }
                $t = Mutqan_Customers::topic((string) ($in['text'] ?? ''));
                return $t !== '' ? ['text' => Mutqan_Customers::info($t, 'customer')] : null;
            }
            case 'greet': return self::greet((string) ($in['phone'] ?? ''), (string) ($in['text'] ?? ''), (string) ($in['brand'] ?? ''));
            case 'converse': return self::converse($in);
            case 'quote': return self::quote((string) ($in['text'] ?? ''));
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
            case 'config': return ['testCustomer' => (string) get_option('mutqan_test_customer', ''), 'site' => home_url('/')];
        }
        throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400);
    }

    private static function num($o) { return (string) ($o['number'] ?? '') ?: substr((string) ($o['id'] ?? ''), -5); }

    /** Who is writing: a technician (with his open jobs), a member of the team, or a customer. */
    public static function who($phone) {
        $k = substr(preg_replace('/\D/', '', (string) $phone), -9);
        if (strlen($k) < 9) { return ['kind' => 'customer']; }
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
        Mutqan_Store::update('orders', (string) $order['id'], ['technicianId' => $tid, 'technicianName' => $tn, 'status' => 'assigned', 'assignedAt' => gmdate('c'), 'dispatchedBy' => 'whatsapp'], self::sys());
        return '✅ طلب ' . self::num($order) . ' اتوجّه لـ *' . $tn . '*' . (trim((string) ($tech['phone'] ?? '')) !== '' ? ' واتبعتله على الواتساب.' : ' (ما لوش رقم واتساب — بلّغه بنفسك).');
    }

    /** An order built from a WhatsApp chat. An open order on the same number gets the message as a note instead. */
    private static function create($in) {
        $phone = class_exists('Mutqan_WA') ? Mutqan_WA::intl((string) ($in['phone'] ?? '')) : preg_replace('/\D/', '', (string) ($in['phone'] ?? ''));
        if ($phone === '') { throw new Mutqan_Error('bad_input', 'رقم غير صالح.', 400); }
        $open = self::order_by(['phone' => $phone, 'open' => true]);
        $service = mb_substr(trim((string) ($in['service'] ?? '')), 0, 160);
        if ($open) {
            if (class_exists('Mutqan_WA')) { Mutqan_WA::add_step($open['id'], 'wa_more', 'رسالة إضافية من العميل على واتساب: ' . mb_substr($service, 0, 80), 'مركز الواتساب'); }
            return self::view($open);
        }
        $order = array_filter([
            'status' => 'new', 'source' => 'whatsapp', 'clientName' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 80), 'clientPhone' => $phone,
            'service' => $service, 'address' => mb_substr(trim((string) ($in['address'] ?? '')), 0, 160),
            'preferredTime' => mb_substr(trim((string) ($in['time'] ?? '')), 0, 120), 'brand' => (string) ($in['brand'] ?? '') ?: 'oxygen',
            'lat' => is_numeric($in['lat'] ?? null) ? (float) $in['lat'] : null, 'lng' => is_numeric($in['lng'] ?? null) ? (float) $in['lng'] : null,
            'waLine' => (string) ($in['line'] ?? ''), 'createdAt' => gmdate('c'),
            'agreedPrice' => is_numeric($in['price'] ?? null) && (int) $in['price'] > 0 ? (int) $in['price'] : null,
            'acQuote' => is_array($in['acQuote'] ?? null) ? $in['acQuote'] + ['total' => (int) ($in['price'] ?? 0)] : null, 'specialtyId' => (string) ($in['spec'] ?? '') ?: null,
            'statusHistory' => [['status' => 'new', 'at' => gmdate('c'), 'by' => 'whatsapp']],
        ], static function ($v) { return $v !== null && $v !== ''; });
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
        $site = class_exists('Mutqan_Customers') ? Mutqan_Customers::business($brand !== '' ? $brand : 'oxygen') : 'أكسجين';
        $src = class_exists('Mutqan_WA_Central') ? Mutqan_WA_Central::source($text) : [];
        $p = class_exists('Mutqan_Customers') ? Mutqan_Customers::profile($phone) : null;
        return ['brand' => $brand, 'site' => $site, 'fromUs' => (bool) $fromUs, 'source' => (string) ($src['title'] ?? ''),
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
            . "مواعيد العمل: من " . (int) ($in['hoursFrom'] ?? 8) . " لحد " . (int) ($in['hoursTo'] ?? 24) . ". " . ($after ? "دلوقتي برا مواعيد العمل: احجز له أقرب موعد الصبح، إلا لو الموضوع طارئ وخطير (تسريب مياه كبير، ماس أو ريحة حريق، غرفة تبريد فيها بضاعة هتبوظ) — ساعتها اطلب منه يتصل فورًا على {$emg}.\n" : "\n")
            . "## العميل\n" . (string) ($in['brief'] ?? 'عميل جديد') . (!empty($in['source']) ? "\nجه من صفحة: «" . mb_substr((string) $in['source'], 0, 90) . "»" : '') . "\n"
            . "## اللي اتجمع من الطلب\n" . ($lead ? wp_json_encode($lead, JSON_UNESCAPED_UNICODE) : 'لا شيء') . "\n"
            . "## المحادثة\n" . implode("\n", $hist) . "\nالعميل (آخر رسالة): " . mb_substr((string) ($in['text'] ?? ''), 0, 600) . "\n\n"
            . "## المطلوب\n"
            . "- افهم نوع الخدمة، وجاوب سؤاله بالظبط. اجمع بالتدريج (سؤال واحد في كل رسالة): الخدمة والتفاصيل (نوع وعدد الأجهزة)، الحي، الوقت المناسب، والاسم.\n"
            . "- الأسعار: من معلومات الشركة بس. لو فاصل في السعر نزّل بالتدريج لحد أقل سعر مسموح بس، وبعدها اعتذر بلطف واعرض قيمة الخدمة والضمان. ما تخترعش خصم ولا سعر.\n"
            . "- أول ما يبقى عندك الخدمة والحي والوقت: complete=true وقوله إن الطلب اتسجل وهيوصله التأكيد حالًا.\n"
            . "- شكوى، استرجاع فلوس، زعل شديد، أو قرار مش بتاعك: handoff=true.\n"
            . "- رد بالـJSON ده بس:\n"
            . '{"reply":"","lead":{"service":"","area":"","time":"","name":"","note":"","price":""},"complete":false,"handoff":false,"handoff_reason":""}';
        try { $r = Mutqan_AI::run($P, ['json' => true, 'kb' => true, 'temperature' => 0.4, 'tier' => 'low', 'feature' => 'wa_hub', 'budget' => 25, 'sales' => ['phone' => $phone]], self::sys()); }
        catch (Throwable $e) { error_log('[mutqan] hub converse ' . $e->getMessage()); return null; }
        $j = is_array($r['json'] ?? null) ? $r['json'] : null;
        $reply = trim((string) ($j['reply'] ?? ''));
        if ($reply === '') { return null; }
        $l = array_filter(array_map(static function ($v) { return trim(mb_substr((string) $v, 0, 160)); }, (array) ($j['lead'] ?? [])), 'strlen');
        return ['reply' => mb_substr($reply, 0, 1200), 'lead' => array_merge($lead, $l), 'complete' => !empty($j['complete']), 'handoff' => !empty($j['handoff']), 'reason' => mb_substr((string) ($j['handoff_reason'] ?? ''), 0, 140), 'provider' => (string) ($r['provider'] ?? '')];
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
            if ($wash) {
                $q = ['mode' => 'wash', 'type' => $type, 'qty' => $qty, 'full' => true];
                $total = (int) round(Mutqan_Policy::ac_total($q));
                $floor = max(0, (int) Mutqan_Policy::ac_floor($q));
                if ($total > 0) { return ['spec' => 'ac', 'fixed' => true, 'label' => 'غسيل ' . $qty . ' ' . ($qty > 1 ? 'مكيف ' : 'مكيف ') . $type, 'qty' => $qty, 'total' => $total, 'floor' => min($floor, $total), 'acQuote' => $q]; }
            }
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
    public static function status() { $c = self::cfg(); return ['on' => self::on(), 'url' => (string) $c['url'], 'paired' => strlen((string) $c['secret']) >= 32, 'queued' => count((array) get_option(self::QUEUE, []))]; }

    /** POST /hubadmin {op: status|pair|on|off} — the owner only. */
    public static function h_admin($in, $p) {
        if (($p['role'] ?? '') !== 'owner') { throw new Mutqan_Error('forbidden', 'للمالك فقط.', 403); }
        $op = (string) ($in['op'] ?? 'status');
        if ($op === 'pair') { return self::pair((string) ($in['url'] ?? ''), (string) ($in['pairToken'] ?? '')); }
        if ($op === 'on' || $op === 'off') { return self::set_on($op === 'on'); }
        return self::status();
    }
}
