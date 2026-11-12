<?php
if (!defined('ABSPATH')) { exit; }

/**
 * The AI answers the customer-service conversation inside an order (channel «ops»).
 *
 * A customer who writes «السلام عليكم» on his order page used to wait until somebody in the
 * operations room happened to look. Now the assistant answers within seconds, as «خدمة العملاء»,
 * knowing everything a good employee would have open in front of them:
 *
 *   - the order itself: service, status, technician, price, appointment, description, payment;
 *   - the whole conversation on this order, and the last lines of the technician's channel;
 *   - the customer's earlier orders (what broke, what was done, what it cost);
 *   - a long-term memory per customer, and a running summary per order, that the assistant keeps
 *     itself after every reply — so it remembers the case across days and long sessions.
 *
 * What it never does, and why:
 *   - it never quotes a price that is not on the order (a quote is a promise the business keeps);
 *   - it never talks over a person: once someone from the operations room writes in the order,
 *     it stays quiet for QUIET_MIN minutes;
 *   - when it cannot settle something (complaint, refund, angry customer, «I want a person», a
 *     question it has no facts for) it tells the customer a colleague is taking over and alerts
 *     the operations room with the reason. A request to hurry or postpone is passed on the same way.
 *
 * It runs after the customer's request has been answered (the reply is not on his critical path),
 * and waits a moment so three quick messages get one considered answer instead of three.
 */
final class Mutqan_Order_AI {
    const OPT = 'mutqan_order_ai';          // ['enabled' => 1, 'quiet_min' => 15]
    const MEM = '_ai_memory';               // c:<customer> and o:<order> memory rows (server-only)
    const NAME = 'المساعد الذكي';            // fromName of the assistant's messages (shown as «خدمة العملاء» to the customer)
    const SETTLE = 4;                       // seconds to wait for the customer to finish typing
    const MAX_FACTS = 40;

    const STATUS = ['new' => 'جديد — بانتظار التوزيع على فني', 'inhouse' => 'جارٍ التنفيذ', 'forced' => 'قيد الإسناد لفني', 'assigned' => 'مسند لفني',
        'accepted' => 'الفني قبل الطلب', 'en_route' => 'الفني في الطريق', 'nearby' => 'الفني قريب من الموقع', 'arrived' => 'الفني وصل',
        'in_progress' => 'العمل جارٍ الآن', 'completed' => 'اكتملت الخدمة', 'closed' => 'مغلق', 'cancelled' => 'ملغي'];

    public static function settings() {
        $s = get_option(self::OPT, []);
        $s = is_array($s) ? $s : [];
        return ['enabled' => isset($s['enabled']) ? (int) $s['enabled'] : 1, 'quiet_min' => max(1, min(240, (int) ($s['quiet_min'] ?? 15)))];
    }

    public static function enabled() {
        return self::settings()['enabled'] === 1 && class_exists('Mutqan_AI') && Mutqan_AI::any_enabled();
    }

    private static function sys() {
        return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => self::NAME];
    }

    /** Called for every new order message. Only a customer's text in the customer-service channel is answered. */
    public static function on_message($m) {
        if (($m['from'] ?? '') !== 'client' || ($m['channel'] ?? '') !== 'ops' || ($m['kind'] ?? '') !== 'text') { return; }
        if (!self::enabled()) { return; }
        $mid = (string) ($m['id'] ?? '');
        register_shutdown_function(function () use ($m, $mid) {
            // Hand the customer his answer first; the assistant works after the connection is closed.
            if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
            elseif (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); }
            @ignore_user_abort(true);
            @set_time_limit(90);
            try { self::reply($m, $mid); } catch (Throwable $e) { error_log('[mutqan] order-ai ' . $e->getMessage()); }
        });
    }

    private static function msgs($oid, $channel, $limit) {
        $rows = Mutqan_Store::select('order_msgs', ['orderId' => $oid, 'channel' => $channel], ['order' => '-at', 'limit' => $limit], self::sys());
        return array_reverse($rows);
    }

    public static function reply($m, $mid, $settle = self::SETTLE) {
        $oid = (string) ($m['orderId'] ?? '');
        if ($oid === '') { return; }
        if ($settle > 0) { sleep((int) $settle); }
        // One answer per burst: only the newest customer message gets one, and only one worker at a time.
        $ops = self::msgs($oid, 'ops', 40);
        $lastClient = null;
        foreach ($ops as $x) { if (($x['from'] ?? '') === 'client') { $lastClient = $x; } }
        if (!$lastClient || ($mid !== '' && (string) ($lastClient['id'] ?? '') !== $mid)) { return; }
        $lock = 'mq_oai_' . md5($oid);
        if (get_transient($lock)) { return; }
        set_transient($lock, 1, 60);
        try { self::answer($oid, $ops, $lastClient); }
        finally { delete_transient($lock); }
    }

    /** True when a person from the operations room wrote in this order recently: the assistant then stays out of it. */
    private static function human_active($ops) {
        $quiet = self::settings()['quiet_min'] * 60;
        foreach (array_reverse($ops) as $x) {
            if (($x['from'] ?? '') === 'ops' && ($x['fromName'] ?? '') !== self::NAME && ($x['kind'] ?? 'text') === 'text') {
                $t = strtotime((string) ($x['at'] ?? '')) ?: 0;
                return $t > 0 && time() - $t < $quiet;
            }
        }
        return false;
    }

    private static function answer($oid, $ops, $last) {
        if (self::human_active($ops)) { return; }
        $ord = Mutqan_Store::get_raw('orders', $oid);
        if (!$ord) { return; }
        $client = (string) ($last['clientId'] ?? ($ord['clientId'] ?? ''));
        $ckey = self::customer_key($ord, $client);
        $cmem = $ckey !== '' ? (Mutqan_Store::get_raw(self::MEM, 'c:' . $ckey) ?: []) : [];
        $omem = Mutqan_Store::get_raw(self::MEM, 'o:' . $oid) ?: [];

        $prompt = self::prompt($oid, $ord, $ops, self::msgs($oid, 'tech', 8), self::history($ord, $client, $oid), $cmem, $omem);
        // The customer's own principal: the knowledge base answers for a customer, and his limits apply.
        $cp = ['id' => $client !== '' ? $client : 'guest', 'kind' => 'user', 'role' => 'client', 'powers' => []];
        try { $r = Mutqan_AI::run($prompt, ['json' => true, 'tier' => 'mid', 'temperature' => 0.3, 'feature' => 'order_chat'], $cp); }
        catch (Throwable $e) { error_log('[mutqan] order-ai run ' . $e->getMessage()); return; }
        $j = is_array($r['json'] ?? null) ? $r['json'] : [];
        $text = trim((string) ($j['reply'] ?? ''));
        if ($text === '') { return; }
        // A person may have stepped in while the model was thinking.
        if (self::human_active(self::msgs($oid, 'ops', 10))) { return; }

        $doc = Mutqan_Store::insert('order_msgs', ['orderId' => $oid, 'channel' => 'ops', 'kind' => 'text', 'text' => mb_substr($text, 0, 600)], null, ['groupId' => 'main', 'visibleTo' => 'group'], self::sys());
        if (class_exists('Mutqan_Rest')) { Mutqan_Rest::after_order_msg($doc); }

        self::act($oid, $ord, $j);
        self::remember($ckey, $oid, $ord, $cmem, $omem, $j);
    }

    /** Hand-off and the customer's requests reach the operations room the same way a button press would. */
    private static function act($oid, $ord, $j) {
        if (!class_exists('Mutqan_Rest')) { return; }
        $short = '#' . substr($oid, -5);
        $url = '#/staff/order/' . $oid;
        $who = (string) ($ord['clientName'] ?? 'العميل');
        $why = mb_substr(trim((string) ($j['handoff_reason'] ?? '')), 0, 200);
        if (!empty($j['handoff'])) {
            Mutqan_Rest::admin_alert('🙋 عميل يحتاج موظف — ' . $short, $who . ': ' . ($why !== '' ? $why : 'المساعد الذكي حوّل المحادثة'), $url, 'order_handoff', 'high', $oid);
        }
        $action = (string) ($j['action'] ?? '');
        if ($action === 'nudge') {
            Mutqan_Rest::admin_alert('🔔 العميل يستعجل طلبه ' . $short, $who . ' يطلب سرعة المتابعة (عبر المساعد الذكي)', $url, 'order_nudge', 'high', $oid);
        } elseif ($action === 'postpone') {
            $when = mb_substr(trim((string) ($j['action_detail'] ?? '')), 0, 120);
            Mutqan_Rest::admin_alert('⏳ العميل يطلب تأجيل الطلب ' . $short, ($when !== '' ? 'الموعد المقترح: ' . $when : 'بدون موعد محدد') . ' (عبر المساعد الذكي)', $url, 'order_postpone_req', 'high', $oid);
        } elseif ($action === 'cancel') {
            Mutqan_Rest::admin_alert('❌ العميل يطلب إلغاء الطلب ' . $short, $who . ($why !== '' ? ' — ' . $why : '') . ' (عبر المساعد الذكي)', $url, 'order_handoff', 'high', $oid);
        }
    }

    /* ------------------------------------------------------------------ memory */

    private static function customer_key($ord, $client) {
        $phone = preg_replace('/\D/', '', Mutqan_Auth::fold_digits((string) ($ord['clientPhone'] ?? '')));
        if (strlen($phone) >= 9) { return 'p' . substr($phone, -9); }      // one customer across devices and accounts
        return $client !== '' ? substr(preg_replace('/[^A-Za-z0-9_.:@-]/', '', $client), 0, 60) : '';
    }

    private static function put($id, $data) {
        $sys = self::sys();
        try { Mutqan_Store::delete(self::MEM, $id, $sys, true); } catch (Throwable $e) { /* not there yet */ }
        Mutqan_Store::insert(self::MEM, $data, $id, [], $sys, true);
    }

    private static function remember($ckey, $oid, $ord, $cmem, $omem, $j) {
        try {
            $sum = trim((string) ($j['order_summary'] ?? ''));
            if ($sum !== '') {
                self::put('o:' . $oid, ['summary' => mb_substr($sum, 0, 900), 'at' => gmdate('c'), 'turns' => (int) ($omem['turns'] ?? 0) + 1]);
            }
            if ($ckey === '') { return; }
            $facts = array_values(array_filter(array_map('strval', (array) ($cmem['facts'] ?? []))));
            foreach ((array) ($j['memory'] ?? []) as $f) {
                $f = trim(mb_substr((string) $f, 0, 180));
                if ($f === '' || in_array($f, $facts, true)) { continue; }
                $facts[] = $f;
            }
            $facts = array_slice($facts, -self::MAX_FACTS);
            $orders = array_values(array_unique(array_merge((array) ($cmem['orders'] ?? []), [$oid])));
            self::put('c:' . $ckey, ['facts' => $facts, 'name' => (string) ($ord['clientName'] ?? ($cmem['name'] ?? '')), 'orders' => array_slice($orders, -30), 'at' => gmdate('c')]);
        } catch (Throwable $e) { error_log('[mutqan] order-ai memory ' . $e->getMessage()); }
    }

    /* ------------------------------------------------------------------ context */

    private static function spec($id) {
        $l = defined('Mutqan_Rest::SPEC_LABELS') ? Mutqan_Rest::SPEC_LABELS : [];
        return (string) ($l[$id] ?? $id);
    }

    private static function when($iso) {
        $t = strtotime((string) $iso);
        if (!$t) { return ''; }
        return wp_date('j/n H:i', $t, new DateTimeZone('Asia/Riyadh'));
    }

    private static function order_facts($ord) {
        $f = [];
        $add = function ($k, $v) use (&$f) { if ($v !== null && $v !== '' && $v !== []) { $f[] = $k . ': ' . (is_scalar($v) ? (string) $v : wp_json_encode($v, JSON_UNESCAPED_UNICODE)); } };
        $add('الخدمة', self::spec((string) ($ord['specialtyId'] ?? '')));
        $add('الحالة', self::STATUS[$ord['status'] ?? ''] ?? (string) ($ord['status'] ?? ''));
        $add('تاريخ الطلب', self::when($ord['createdAt'] ?? ''));
        $add('الموعد المطلوب', self::when($ord['appointmentAt'] ?? ''));
        $add('وصف العميل للمشكلة', mb_substr((string) ($ord['description'] ?? ''), 0, 500));
        $add('الماركة/الجهاز', (string) ($ord['brand'] ?? '') !== '' && !preg_match('/^[a-z0-9_-]+$/', (string) $ord['brand']) ? $ord['brand'] : '');
        $add('الفني', (string) ($ord['technicianName'] ?? ''));
        if (isset($ord['technicianDistanceM']) && is_numeric($ord['technicianDistanceM']) && in_array($ord['status'] ?? '', ['en_route', 'nearby'], true)) { $add('مسافة الفني', round($ord['technicianDistanceM'] / 1000, 1) . ' كم'); }
        $add('السعر المعتمد على الطلب', isset($ord['price']) && is_numeric($ord['price']) && $ord['price'] > 0 ? $ord['price'] . ' ريال' : '');
        $add('عرض السعر', isset($ord['quotedTotal']) && is_numeric($ord['quotedTotal']) && $ord['quotedTotal'] > 0 ? $ord['quotedTotal'] . ' ريال' : '');
        $add('خصم الترحيب', !empty($ord['welcomeDiscountPct']) ? $ord['welcomeDiscountPct'] . '% بحد أقصى ' . ($ord['welcomeDiscountMax'] ?? '') . ' ريال' : '');
        $add('حالة الدفع', (string) ($ord['paymentStatus'] ?? ''));
        $add('طريقة الدفع', (string) ($ord['paymentMethod'] ?? ''));
        $add('طلب ضمان', !empty($ord['isWarranty']) ? 'نعم' : '');
        if (!empty($ord['spareParts']) && is_array($ord['spareParts'])) {
            $parts = array_map(function ($x) { return is_array($x) ? trim(($x['name'] ?? '') . ' ' . (isset($x['price']) ? $x['price'] . ' ريال' : '')) : (string) $x; }, array_slice($ord['spareParts'], 0, 8));
            $add('قطع الغيار', implode('، ', array_filter($parts)));
        }
        if (!empty($ord['report']) && is_array($ord['report'])) { $add('تقرير الفني', mb_substr(trim((string) ($ord['report']['summary'] ?? ($ord['report']['notes'] ?? ''))), 0, 400)); }
        $add('التقييم', !empty($ord['rating']) ? $ord['rating'] . '/5' : '');
        if (!empty($ord['statusHistory']) && is_array($ord['statusHistory'])) {
            $h = array_map(function ($x) { return (self::STATUS[$x['status'] ?? ''] ?? ($x['status'] ?? '')) . ' (' . self::when($x['at'] ?? '') . ')'; }, array_slice($ord['statusHistory'], -6));
            $add('سجل الحالات', implode(' ← ', $h));
        }
        return implode("\n", $f);
    }

    private static function history($ord, $client, $oid) {
        $rows = [];
        try {
            if ($client !== '') { $rows = Mutqan_Store::select('orders', ['clientId' => $client], ['order' => '-createdAt', 'limit' => 12], self::sys()); }
        } catch (Throwable $e) { $rows = []; }
        $out = [];
        foreach ($rows as $o) {
            if ((string) ($o['id'] ?? '') === $oid) { continue; }
            $price = isset($o['price']) && is_numeric($o['price']) && $o['price'] > 0 ? ' — ' . $o['price'] . ' ريال' : '';
            $out[] = '- #' . substr((string) $o['id'], -5) . ' ' . self::when($o['createdAt'] ?? '') . ': ' . self::spec((string) ($o['specialtyId'] ?? '')) . ' — ' . (self::STATUS[$o['status'] ?? ''] ?? '') . $price
                . ((string) ($o['description'] ?? '') !== '' ? ' — «' . mb_substr((string) $o['description'], 0, 140) . '»' : '');
            if (count($out) >= 8) { break; }
        }
        return implode("\n", $out);
    }

    private static function convo($rows, $tech = false) {
        $out = [];
        foreach ($rows as $x) {
            $t = trim((string) ($x['text'] ?? ''));
            $k = (string) ($x['kind'] ?? 'text');
            if ($t === '' && $k === 'text') { continue; }
            $who = ($x['from'] ?? '') === 'client' ? 'العميل' : (($x['from'] ?? '') === 'tech' ? 'الفني' : ((($x['fromName'] ?? '') === self::NAME) ? 'أنت (خدمة العملاء)' : 'موظف خدمة العملاء'));
            $label = ['nudge' => '[استعجال]', 'postpone_req' => '[طلب تأجيل]', 'eta' => '[الفني سيصل خلال 10 دقائق]', 'arrived' => '[وصل الفني]', 'postponed' => '[تم التأجيل]'][$k] ?? '';
            $out[] = '[' . self::when($x['at'] ?? '') . '] ' . $who . ': ' . trim($label . ' ' . mb_substr($t, 0, 600));
        }
        return implode("\n", $out);
    }

    private static function prompt($oid, $ord, $ops, $tech, $history, $cmem, $omem) {
        $brand = class_exists('Oxygen_SEO_Engine') ? Oxygen_SEO_Engine::brand() : 'أكسجين';
        $now = wp_date('l j/n H:i', time(), new DateTimeZone('Asia/Riyadh'));
        $facts = array_map(function ($f) { return '- ' . $f; }, (array) ($cmem['facts'] ?? []));
        $p = "أنت موظف خدمة العملاء في «{$brand}» للصيانة والمقاولات العامة (مكة المكرمة وجدة)، ترد داخل محادثة طلب العميل في التطبيق.\n"
            . "الوقت الآن بتوقيت السعودية: {$now}\n\n"
            . "## قواعد لا تُكسر\n"
            . "1. اكتب بالعربية بلهجة سعودية مهذبة وقصيرة (سطر إلى أربعة أسطر)، ورد على آخر ما كتبه العميل مباشرة. رد التحية بمثلها.\n"
            . "2. اعتمد فقط على بيانات الطلب والذاكرة والمحادثة أدناه. لا تخترع موعدًا أو اسم فني أو حالة.\n"
            . "3. لا تذكر أي سعر أو مبلغ غير مكتوب في بيانات الطلب. إن سأل عن السعر ولا يوجد سعر معتمد: قل إن الفني يحدد السعر بعد الفحص ويعتمده العميل قبل التنفيذ.\n"
            . "4. لا تكشف معلومات داخلية أو بيانات عملاء آخرين أو أرقامًا شخصية للفنيين.\n"
            . "5. حوّل لموظف (handoff=true) عند: شكوى أو غضب، طلب استرجاع/تعويض/خصم، خلاف على الفاتورة أو الضمان، طلب صريح لموظف، أو سؤال ليس عندك بيانات له. وقل للعميل إن زميلًا من فريق العمليات سيتابع معه الآن.\n"
            . "6. إن طلب الاستعجال: action=\"nudge\". إن طلب التأجيل: action=\"postpone\" وضع الموعد المقترح في action_detail. إن طلب الإلغاء: action=\"cancel\" و handoff=true. وأكد له أنك أبلغت فريق العمليات.\n"
            . "7. إن ذكر العميل عطلًا أو تفصيلة مفيدة (نوع الجهاز، المكان، العطل المتكرر، تفضيل الموعد…) فاحفظها في memory كجمل قصيرة. لا تحفظ أرقام هواتف أو بيانات دفع.\n"
            . "8. اكتب order_summary: ملخص محدّث لحالة هذا الطلب والمحادثة حتى الآن (العطل، ما تم، ما ينتظره العميل) في 2-4 جمل.\n\n"
            . "## بيانات الطلب #" . substr($oid, -5) . "\n" . self::order_facts($ord) . "\n\n"
            . "## ملخص سابق لهذا الطلب\n" . ((string) ($omem['summary'] ?? '') !== '' ? $omem['summary'] : 'لا يوجد') . "\n\n"
            . "## ذاكرة العميل" . ((string) ($cmem['name'] ?? '') !== '' ? ' (' . $cmem['name'] . ')' : '') . "\n" . ($facts ? implode("\n", $facts) : 'لا يوجد') . "\n\n"
            . "## طلباته السابقة\n" . ($history !== '' ? $history : 'لا يوجد') . "\n\n"
            . ($tech ? "## آخر رسائل قناة الفني (للاطلاع فقط)\n" . self::convo($tech, true) . "\n\n" : '')
            . "## المحادثة مع خدمة العملاء\n" . self::convo($ops) . "\n\n"
            . "أعد JSON فقط بهذا الشكل:\n"
            . '{"reply":"نص الرد للعميل","handoff":false,"handoff_reason":"","action":"","action_detail":"","memory":["معلومة قصيرة"],"order_summary":"..."}';
        return $p;
    }
}
