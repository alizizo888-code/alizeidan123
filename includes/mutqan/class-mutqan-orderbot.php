<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «مساعد الطلب» — the assistant that answers inside an order's conversation.
 *
 * The order page had a «مراسلة خدمة العملاء» box, and a message written there waited for whoever in the
 * operations room happened to look. «الفني فين؟», «هيتأخر؟», «ممكن يجيب معاه خرطوم؟», «عايز أأجل» — each of
 * them sat unanswered, though the answer was already in the system or one question to the technician away.
 *
 * So after every customer message this:
 *
 *   - reads the order the way the system knows it: the step it is at, the technician, the appointment, the
 *     technician's own last estimate, how far away his phone last reported him, the warranty, the payment;
 *   - does what the message needs: asks the technician «فاضل لك قد إيه؟» (in the app, by push and on
 *     WhatsApp), passes a request on to him, raises an urgent or postpone request to the operations room;
 *   - answers the customer from those facts — through the AI gateway when it is up, from a fixed template
 *     when it is not — and never invents a time, a price or a promise the facts do not hold.
 *
 * When the technician answers the estimate, the answer goes straight back to the customer's conversation.
 * And every time the order moves (accepted, on the way, arrived, done) the customer gets the same facts on
 * WhatsApp, with the link back to the order page.
 */
final class Mutqan_OrderBot {

    const BOT = ['id' => 'system:orderbot', 'role' => 'owner', 'kind' => 'user', 'powers' => ['read', 'write', 'manage'], 'name' => 'المساعد الذكي'];
    const LIVE = ['new', 'forced', 'inhouse', 'assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress'];
    const MOVING = ['accepted', 'en_route', 'nearby'];
    const STEPS = ['استلام الطلب', 'التوزيع', 'الفني قبل الطلب', 'الفني في الطريق', 'الفني وصل', 'اكتمل'];
    const ETA_FRESH = 1800;          // a technician's estimate older than 30 minutes is no longer an answer
    const ASK_EVERY = 600;           // the technician is asked at most once every 10 minutes per order

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user']; }

    public static function step_of($s) {
        if (in_array($s, ['new', 'forced', 'inhouse'], true)) { return 0; }
        if ($s === 'assigned') { return 1; }
        if ($s === 'accepted') { return 2; }
        if (in_array($s, ['en_route', 'nearby'], true)) { return 3; }
        if (in_array($s, ['arrived', 'in_progress'], true)) { return 4; }
        if (in_array($s, ['completed', 'closed'], true)) { return 5; }
        return 0;
    }

    public static function order_url($id) { return home_url('/#/order/' . rawurlencode((string) $id)); }

    private static function km($lat1, $lng1, $lat2, $lng2) {
        $r = 6371; $dl = deg2rad($lat2 - $lat1); $dn = deg2rad($lng2 - $lng1);
        $a = sin($dl / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dn / 2) ** 2;
        return 2 * $r * asin(min(1, sqrt($a)));
    }

    private static function order_point($o) {
        foreach ([[$o['lat'] ?? null, $o['lng'] ?? null], [$o['coords']['lat'] ?? null, $o['coords']['lng'] ?? null]] as $pt) {
            if (is_numeric($pt[0]) && is_numeric($pt[1])) { return [(float) $pt[0], (float) $pt[1]]; }
        }
        return null;
    }

    /** Warranty from the completion time and the owner's policy (same rules as warranty.js). */
    public static function warranty($o) {
        if (!in_array((string) ($o['status'] ?? ''), ['completed', 'closed'], true)) { return null; }
        $done = strtotime((string) ($o['completedAt'] ?? '')) ?: 0;
        if (!$done) { foreach (array_reverse((array) ($o['statusHistory'] ?? [])) as $h) { if (($h['status'] ?? '') === 'completed') { $done = strtotime((string) ($h['at'] ?? '')) ?: 0; break; } } }
        if (!$done) { return null; }
        $pol = Mutqan_Store::get_raw('warranty_policy', 'main') ?: (Mutqan_Store::get_raw('warranty_policy', 'default') ?: []);
        $days = is_numeric($o['warrantyDays'] ?? null) ? (int) $o['warrantyDays'] : (is_numeric($pol['days'][$o['specialtyId'] ?? ''] ?? null) ? (int) $pol['days'][$o['specialtyId']] : (int) ($pol['defaultDays'] ?? 30));
        $left = (int) ceil(($done + $days * 86400 - time()) / 86400);
        return ['days' => $days, 'left' => max(0, $left), 'active' => $left > 0, 'until' => gmdate('Y-m-d', $done + $days * 86400)];
    }

    /** Everything the system knows about this order that the customer may be told. */
    public static function facts($o) {
        $st = (string) ($o['status'] ?? 'new');
        $i = self::step_of($st);
        $f = [
            'id' => (string) ($o['id'] ?? ''), 'short' => '#' . substr((string) ($o['id'] ?? ''), -5),
            'service' => class_exists('Mutqan_Rest') ? Mutqan_Rest::spec_label($o['specialtyId'] ?? '') : (string) ($o['specialtyId'] ?? ''),
            'status' => $st, 'cancelled' => $st === 'cancelled', 'step' => $i, 'stepLabel' => $st === 'cancelled' ? 'ملغي' : self::STEPS[$i],
            'tech' => (string) ($o['technicianName'] ?? ''), 'hasTech' => !empty($o['technicianId']),
            'appointment' => null, 'eta' => null, 'distanceKm' => null, 'posAgoMin' => null,
            'price' => class_exists('Mutqan_Sales') ? (int) Mutqan_Sales::amount($o) : 0,
            'paid' => ($o['paymentStatus'] ?? '') === 'paid', 'warranty' => self::warranty($o), 'url' => self::order_url($o['id'] ?? ''),
        ];
        $t = strtotime((string) ($o['appointmentAt'] ?? '')) ?: 0;
        if ($t && class_exists('Mutqan_Sales')) { $f['appointment'] = Mutqan_Sales::slot_label($t); }
        elseif (!empty($o['preferredTime'])) { $f['appointment'] = (string) $o['preferredTime']; }
        $e = (array) ($o['techEta'] ?? []);
        // An estimate only means something while he is still on his way.
        if (!empty($e['minutes']) && in_array($st, ['assigned', 'accepted', 'en_route', 'nearby'], true) && ($at = strtotime((string) ($e['at'] ?? ''))) && time() - $at < self::ETA_FRESH) {
            $f['eta'] = ['minutes' => max(0, (int) $e['minutes'] - (int) floor((time() - $at) / 60)), 'saidAgo' => (int) floor((time() - $at) / 60)];
        }
        if ($f['hasTech'] && in_array($st, self::MOVING, true) && class_exists('Mutqan_Track')) {
            $pos = Mutqan_Track::where((string) $o['technicianId']); $pt = self::order_point($o);
            if ($pos && $pt) { $f['distanceKm'] = round(self::km($pos['lat'], $pos['lng'], $pt[0], $pt[1]), 1); $f['posAgoMin'] = (int) floor($pos['ago'] / 60); }
        }
        return $f;
    }

    /** The order told in a few lines (WhatsApp, the fallback answer, the AI's context). */
    public static function status_text($f, $withLink = true) {
        $L = ['📋 طلب ' . $f['short'] . ($f['service'] !== '' ? ' — ' . $f['service'] : ''), '📍 المرحلة: ' . $f['stepLabel'] . ($f['cancelled'] ? '' : ' (' . ($f['step'] + 1) . ' من 6)')];
        if (!$f['cancelled']) {
            $bar = ''; foreach (self::STEPS as $k => $s) { $bar .= ($k <= $f['step'] ? '●' : '○'); }
            $L[] = $bar . ' ' . implode(' ← ', array_slice(self::STEPS, 0, $f['step'] + 1));
        }
        if ($f['tech'] !== '') { $L[] = '🧑‍🔧 الفني: ' . $f['tech']; }
        if ($f['appointment']) { $L[] = '📅 الموعد: ' . $f['appointment']; }
        if ($f['eta']) { $L[] = '🚗 الفني قال إنه يوصل خلال ~' . $f['eta']['minutes'] . ' دقيقة'; }
        elseif ($f['distanceKm'] !== null) { $L[] = '🚗 الفني على بعد حوالي ' . $f['distanceKm'] . ' كم (آخر تحديث قبل ' . $f['posAgoMin'] . ' د)'; }
        if ($f['warranty']) { $L[] = $f['warranty']['active'] ? '🛡️ الضمان ساري — باقي ' . $f['warranty']['left'] . ' يوم (حتى ' . $f['warranty']['until'] . ')' : '🛡️ الضمان انتهى في ' . $f['warranty']['until']; }
        if ($f['price'] > 0) { $L[] = '💰 المبلغ: ' . $f['price'] . ' ر.س' . ($f['step'] === 5 ? ($f['paid'] ? ' — مدفوع ✅' : ' — لم يُسدد بعد') : ''); }
        if ($withLink && $f['id'] !== '') { $L[] = '🔗 تابع طلبك وتواصل مع الفني: ' . $f['url']; }
        return implode("\n", $L);
    }

    /* ------------------------------------------------------------------ what the customer wants */

    const INTENTS = [
        'human' => ['موظف', 'انسان', 'بشري', 'مسؤول', 'كلمني حد', 'اكلم حد', 'خدمه العملاء'],
        'cancel' => ['الغاء', 'الغي', 'كنسل', 'مش عايز الطلب', 'ما ابغى الطلب'],
        'postpone' => ['اجل', 'تاجيل', 'اغير الموعد', 'غير الموعد', 'موعد ثاني', 'ميعاد تاني', 'يوم ثاني', 'يوم تاني', 'مش فاضي', 'مو فاضي', 'ما ني فاضي', 'بعدين تعال'],
        'warranty' => ['ضمان', 'الضمان'],
        'pay' => ['ادفع', 'دفع', 'فاتوره', 'كم المبلغ', 'كم الحساب', 'تحويل'],
        'urgent' => ['استعجل', 'مستعجل', 'بسرعه', 'اتاخر', 'تاخر', 'متاخر', 'طولتو', 'طولتوا', 'لسه ما جا', 'لسا ما جا', 'ليش ما وصل', 'ما وصل', 'مجاش', 'منتظر من'],
        'where' => ['فين', 'وين', 'فاضل', 'باقي له', 'باقي كم', 'كم باقي', 'متى يوصل', 'امتى يوصل', 'امتي', 'يوصل', 'مكانه', 'قرب', 'قريب'],
        'request' => ['هات', 'جيب', 'يجيب', 'يجيبه', 'معاه', 'معه', 'خليه', 'قول للفني', 'قل للفني', 'بلغ الفني', 'يحضر', 'يعمل', 'يسوي', 'اعمل', 'يركب', 'يشيل', 'يتصل بي', 'يكلمني'],
        'status' => ['حاله', 'اخبار', 'وصل لحد فين', 'وش صار', 'ايه الاخبار', 'الطلب', 'الاجراءات', 'خطوات'],
    ];

    public static function intent($text) {
        $n = Mutqan_Playbook::norm($text);
        foreach (self::INTENTS as $k => $words) {
            foreach ($words as $w) { if (mb_strpos($n, Mutqan_Playbook::norm($w)) !== false) { return $k; } }
        }
        return 'general';
    }

    /* ------------------------------------------------------------------ talking to the technician and the room */

    private static function insert_msg($orderId, $channel, $kind, $text, $extra = []) {
        return Mutqan_Store::insert('order_msgs', array_merge(['orderId' => (string) $orderId, 'channel' => $channel, 'kind' => $kind, 'text' => mb_substr((string) $text, 0, 600)], $extra), null, ['visibleTo' => 'group'], self::BOT);
    }

    private static function push($user, $title, $body, $url, $prio = 'high') {
        if (!$user) { return; }
        try { Mutqan_Store::insert('_push', ['target' => ['user' => (string) $user], 'title' => mb_substr($title, 0, 120), 'body' => mb_substr($body, 0, 300), 'url' => $url, 'sound' => 'bell', 'priority' => $prio, 'event' => 'order_bot', 'from' => 'system'], null, ['expiresAt' => gmdate('c', time() + 3 * 86400)], self::sys(), false); }
        catch (Throwable $e) { error_log('[mutqan] orderbot push: ' . $e->getMessage()); }
    }

    private static function tech_phone($o) {
        if (!empty($o['technicianPhone'])) { return (string) $o['technicianPhone']; }
        $t = !empty($o['technicianId']) ? Mutqan_Store::get_raw('tech_profiles', (string) $o['technicianId']) : null;
        return (string) ($t['phone'] ?? '');
    }

    /** One message to the technician: in the order's chat, a push to his phone, and WhatsApp when the gateway is up. */
    public static function tell_tech($o, $text, $kind = 'system') {
        if (empty($o['technicianId'])) { return false; }
        self::insert_msg($o['id'], 'tech', $kind, $text);
        self::push($o['technicianId'], '🤖 ' . mb_substr($text, 0, 60), $text, '#/staff/order/' . $o['id']);
        $ph = self::tech_phone($o);
        if ($ph !== '' && class_exists('Mutqan_WA')) {
            try { Mutqan_WA::auto_send($ph, "🤖 من المساعد الذكي — طلب #" . substr((string) $o['id'], -5) . "\n" . $text . "\nرد من التطبيق: " . home_url('/#/staff/order/' . rawurlencode((string) $o['id'])), 'order'); }
            catch (Throwable $e) { error_log('[mutqan] orderbot wa tech: ' . $e->getMessage()); }
        }
        return true;
    }

    /** «فاضل لك قد إيه؟» — at most once every ten minutes per order. */
    public static function ask_eta($o) {
        $key = 'mq_ob_ask_' . md5((string) $o['id']);
        if (get_transient($key)) { return 'already'; }
        if (empty($o['technicianId']) || !in_array((string) ($o['status'] ?? ''), ['assigned', 'accepted', 'en_route', 'nearby'], true)) { return false; }
        set_transient($key, time(), self::ASK_EVERY);
        self::tell_tech($o, 'العميل بيسأل: فاضل لك قد إيه وتوصل؟ اختار المدة من الأزرار تحت عشان توصله على طول.', 'eta_ask');
        return 'asked';
    }

    private static function alert($o, $title, $body, $event = 'order_bot') {
        if (class_exists('Mutqan_Rest')) { Mutqan_Rest::admin_alert($title . ' #' . substr((string) $o['id'], -5), $body, '#/staff/order/' . $o['id'], $event, 'high', $o['id']); }
    }

    /** Does what the message asks. Returns the notes the reply is written from. */
    public static function act($intent, $o, $text, $quiet = false) {
        $did = [];
        // $quiet: Mutqan_Order_AI is answering this conversation and raises the room itself (hurry, postpone,
        // cancel, a person) — here only what it does not do: asking the technician and passing requests to him.
        if ($quiet) {
            if (in_array($intent, ['urgent', 'where'], true) && !self::facts($o)['eta']) { $a = self::ask_eta($o); if ($a) { $did[] = 'asked_eta'; } }
            if ($intent === 'request' && !empty($o['technicianId'])) { self::tell_tech($o, '📌 طلب من العميل: «' . mb_substr($text, 0, 300) . '»'); $did[] = 'relayed'; }
            if ($intent === 'postpone' && !empty($o['technicianId'])) { self::tell_tech($o, 'العميل بيطلب تأجيل: «' . mb_substr($text, 0, 160) . '» — استنى تأكيد العمليات قبل ما تتحرك.'); $did[] = 'told_tech'; }
            return $did;
        }
        $once = function ($what) use ($o) { $k = 'mq_ob_' . $what . '_' . md5((string) $o['id']); if (get_transient($k)) { return false; } set_transient($k, 1, self::ASK_EVERY); return true; };
        switch ($intent) {
            case 'urgent':
                if ($once('urgent')) { self::alert($o, '🔔 العميل يستعجل', ($o['clientName'] ?? 'العميل') . ': ' . mb_substr($text, 0, 160), 'order_nudge'); $did[] = 'بلّغت غرفة العمليات إنك مستعجل'; }
                $a = self::ask_eta($o); if ($a) { $did[] = $a === 'asked' ? 'سألت الفني دلوقتي فاضل له قد إيه' : 'الفني اتسأل من شوية ومستنيين رده'; }
                break;
            case 'where':
                $f = self::facts($o);
                if (!$f['eta']) { $a = self::ask_eta($o); if ($a) { $did[] = $a === 'asked' ? 'سألت الفني دلوقتي فاضل له قد إيه' : 'الفني اتسأل من شوية ومستنيين رده'; } }
                break;
            case 'postpone':
                if ($once('postpone')) {
                    self::insert_msg($o['id'], 'ops', 'postpone_req', mb_substr($text, 0, 200));
                    self::alert($o, '⏳ العميل يطلب تأجيل', mb_substr($text, 0, 200), 'order_postpone_req');
                    if (!empty($o['technicianId'])) { self::tell_tech($o, 'العميل بيطلب تأجيل: «' . mb_substr($text, 0, 160) . '» — استنى تأكيد العمليات قبل ما تتحرك.'); }
                    $did[] = 'سجلت طلب التأجيل وبلّغت العمليات' . (!empty($o['technicianId']) ? ' والفني' : '') . '، والموعد الجديد بيتأكد لك من العمليات';
                }
                break;
            case 'cancel':
                if ($once('cancel')) { self::alert($o, '❌ العميل يطلب إلغاء', mb_substr($text, 0, 200)); $did[] = 'بلّغت العمليات بطلب الإلغاء، والإلغاء بيتأكد منهم (ما اتلغاش لسه)'; }
                break;
            case 'request':
                if (!empty($o['technicianId'])) { self::tell_tech($o, '📌 طلب من العميل: «' . mb_substr($text, 0, 300) . '»'); $did[] = 'وصلت طلبك للفني ' . ($o['technicianName'] ?? ''); }
                else { self::alert($o, '📌 طلب من العميل', mb_substr($text, 0, 300)); $did[] = 'سجلت طلبك وهيوصل للفني أول ما يتسند'; }
                break;
            case 'human':
                if ($once('human')) { self::alert($o, '🙋 العميل يطلب موظف', mb_substr($text, 0, 200)); $did[] = 'بلّغت موظف خدمة العملاء وهيرد عليك هنا'; }
                break;
        }
        return $did;
    }

    /* ------------------------------------------------------------------ the answer */

    public static function template($intent, $f, $did) {
        $L = [];
        if ($f['cancelled']) { $L[] = 'طلبك ' . $f['short'] . ' ملغي حاليًا. لو حابب نرجّعه أو نفتح طلب جديد قول لي.'; }
        elseif ($intent === 'where' || $intent === 'urgent') {
            if ($f['eta']) { $L[] = 'الفني ' . $f['tech'] . ' قال إنه يوصل خلال حوالي ' . $f['eta']['minutes'] . ' دقيقة 🚗'; }
            elseif ($f['distanceKm'] !== null) { $L[] = 'الفني ' . $f['tech'] . ' على بعد حوالي ' . $f['distanceKm'] . ' كم منك (آخر تحديث قبل ' . $f['posAgoMin'] . ' د).'; }
            elseif (!$f['hasTech']) { $L[] = 'طلبك لسه في التوزيع على أقرب فني.'; }
            else { $L[] = 'طلبك مع الفني ' . $f['tech'] . ' — المرحلة: ' . $f['stepLabel'] . '.'; }
        } elseif ($intent === 'warranty') {
            $L[] = $f['warranty'] ? ($f['warranty']['active'] ? 'ضمان طلبك ساري، باقي ' . $f['warranty']['left'] . ' يوم (حتى ' . $f['warranty']['until'] . ').' : 'ضمان الطلب انتهى في ' . $f['warranty']['until'] . '.') : 'الضمان بيبدأ من لحظة إنهاء الخدمة، وبيظهر هنا أول ما الطلب يكتمل.';
        } elseif ($intent === 'pay') {
            $L[] = $f['price'] > 0 ? 'مبلغ الطلب ' . $f['price'] . ' ر.س' . ($f['paid'] ? ' ومدفوع ✅' : '، والدفع بعد إنهاء الخدمة بفاتورة.') : 'السعر النهائي بيتحدد مع الفني بعد المعاينة، والدفع بعد الخدمة بفاتورة.';
        } else {
            $L[] = 'طلبك ' . $f['short'] . ' (' . $f['service'] . ') — المرحلة: ' . $f['stepLabel'] . ($f['tech'] !== '' ? ' مع الفني ' . $f['tech'] : '') . ($f['appointment'] ? '، الموعد ' . $f['appointment'] : '') . '.';
        }
        foreach ($did as $d) { $L[] = '✅ ' . $d . '.'; }
        if (!$did && in_array($intent, ['general', 'status'], true)) { $L[] = 'تقدر تسألني عن مكان الفني، أو تطلب تأجيل، أو تبعت أي طلب للفني وأنا أوصله.'; }
        return implode("\n", $L);
    }

    private static function ai_reply($f, $intent, $did, $history, $text) {
        if (!class_exists('Mutqan_AI') || !Mutqan_AI::any_enabled()) { return ''; }
        $convo = '';
        foreach (array_slice($history, -10) as $m) { $convo .= ($m['from'] === 'client' ? 'العميل' : ($m['from'] === 'tech' ? 'الفني' : 'خدمة العملاء')) . ': ' . mb_substr((string) ($m['text'] ?? ''), 0, 300) . "\n"; }
        $prompt = "أنت «المساعد الذكي» لخدمة عملاء شركة صيانة في مكة وجدة، بترد جوه محادثة طلب واحد.\n"
            . "## بيانات الطلب من النظام (المصدر الوحيد — لا تخترع وقت وصول أو سعر أو حالة غير المكتوب هنا)\n" . self::status_text($f, false) . "\n"
            . '## اللي عملته فعلًا دلوقتي بخصوص رسالة العميل' . "\n" . ($did ? '- ' . implode("\n- ", $did) : '- لا شيء (سؤال معلومات فقط)') . "\n"
            . "## المحادثة\n" . $convo . 'العميل: ' . $text . "\n\n"
            . "اكتب ردك للعميل في سطرين إلى ثلاثة بلهجة ودودة مختصرة (سعودية بيضاء أو بلهجته). جاوب سؤاله بالظبط من البيانات، واذكر اللي عملته لو في. لو المعلومة مش موجودة قل إنك سألت الفني أو العمليات وهتبلغه. لا تعد بموعد وصول غير اللي قاله الفني. لا JSON.";
        try { $r = Mutqan_AI::run($prompt, ['tier' => 'low', 'temperature' => 0.3, 'feature' => 'order_chat'], ['role' => null, 'kind' => 'guest', 'id' => 'orderbot']); return trim((string) ($r['text'] ?? '')); }
        catch (Throwable $e) { return ''; }
    }

    /** POST /orderbot {op:'reply', orderId} — answers the customer's latest unanswered message. */
    public static function handle($in, $p) {
        $op = (string) ($in['op'] ?? 'reply');
        $oid = (string) ($in['orderId'] ?? '');
        $o = $oid !== '' ? Mutqan_Store::get_raw('orders', $oid) : null;
        if (!$o) { throw new Mutqan_Error('bad_order', 'الطلب غير موجود.', 404); }
        $o['id'] = $oid;
        $owner = Mutqan_Store::owner_of('orders', $oid);
        $isClient = ($p['kind'] ?? 'guest') !== 'guest' && (($owner !== '' && $owner === ($p['id'] ?? '#')) || (($o['clientId'] ?? null) === ($p['id'] ?? '#')));
        $isStaff = class_exists('Mutqan_Policy') && Mutqan_Policy::is_staff($p);
        if (!$isClient && !$isStaff) { throw new Mutqan_Error('forbidden', 'غير مسموح لك بهذا الطلب.', 403); }
        if ($op === 'facts') { $f = self::facts($o); return ['facts' => $f, 'text' => self::status_text($f)]; }
        if ($op !== 'reply') { throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400); }
        if (!$isStaff) { Mutqan_Limiter::hit('orderbot', 30, 600); }
        $msgs = Mutqan_Store::select('order_msgs', ['orderId' => $oid, 'channel' => 'ops'], ['order' => 'at', 'limit' => 200], self::sys());
        $last = null; $after = false;
        foreach ($msgs as $m) {
            if (($m['from'] ?? '') === 'client' && ($m['kind'] ?? 'text') === 'text') { $last = $m; $after = false; }
            elseif ($last && in_array($m['from'] ?? '', ['ai', 'ops'], true) && ($m['kind'] ?? 'text') === 'text') { $after = true; }
        }
        if (!$last || $after) { return ['ok' => true, 'skipped' => true]; }
        $lock = 'mq_ob_lock_' . md5((string) $last['id']);
        if (get_transient($lock)) { return ['ok' => true, 'skipped' => true]; }
        set_transient($lock, 1, 120);
        $text = (string) ($last['text'] ?? '');
        $intent = self::intent($text);
        // The conversational assistant (Mutqan_Order_AI, with its memory of the customer) answers when the AI is up;
        // this one then only does the technician's side, so the customer never gets two answers.
        if (class_exists('Mutqan_Order_AI') && Mutqan_Order_AI::enabled()) {
            return ['ok' => true, 'deferred' => true, 'intent' => $intent, 'did' => self::act($intent, $o, $text, true)];
        }
        $did = self::act($intent, $o, $text);
        $o = Mutqan_Store::get_raw('orders', $oid) ?: $o; $o['id'] = $oid;
        $f = self::facts($o);
        $reply = self::ai_reply($f, $intent, $did, $msgs, $text);
        if ($reply === '') { $reply = self::template($intent, $f, $did); }
        $doc = self::insert_msg($oid, 'ops', 'text', $reply, ['intent' => $intent]);
        return ['ok' => true, 'intent' => $intent, 'did' => $did, 'reply' => $reply, 'id' => $doc['id'] ?? null];
    }

    /* ------------------------------------------------------------------ the technician answers; the order moves */

    /** A technician's estimate: kept on the order, and passed to the customer at once. */
    public static function on_tech_eta($m) {
        try {
            $min = (int) ($m['minutes'] ?? 0);
            if ($min <= 0) { return; }
            $oid = (string) $m['orderId'];
            Mutqan_Store::update('orders', $oid, ['techEta' => ['minutes' => $min, 'at' => gmdate('c')]], self::sys());
            if (get_transient('mq_ob_ask_' . md5($oid))) {
                delete_transient('mq_ob_ask_' . md5($oid));
                self::insert_msg($oid, 'ops', 'text', '🚗 الفني رد: هيوصل خلال حوالي ' . $min . ' دقيقة.');
            }
        } catch (Throwable $e) { error_log('[mutqan] orderbot eta: ' . $e->getMessage()); }
    }

    const WA_STATUS = [
        'accepted' => '✅ الفني {tech} قبل طلبك وهيتواصل معاك قبل ما يتحرك.',
        'en_route' => '🚗 الفني {tech} في الطريق ليك دلوقتي.',
        'nearby' => '📍 الفني {tech} قرّب من موقعك.',
        'arrived' => '🏠 الفني {tech} وصل لموقعك.',
        'completed' => '🎉 تم إنجاز طلبك، شكرًا لثقتك.',
        'cancelled' => 'تم إلغاء طلبك. لو محتاج أي خدمة إحنا موجودين.',
    ];

    /** Every move of the order reaches the customer on WhatsApp, with the facts and the link back. */
    public static function on_status($doc, $old) {
        try {
            $st = (string) ($doc['status'] ?? '');
            if ($st === (string) ($old['status'] ?? '') || !isset(self::WA_STATUS[$st])) { return false; }
            $cfg = get_option('mutqan_orderbot', []);
            if (is_array($cfg) && isset($cfg['waStatus']) && !$cfg['waStatus']) { return false; }
            $phone = (string) ($doc['clientPhone'] ?? '');
            if ($phone === '' || !class_exists('Mutqan_WA')) { return false; }
            $key = 'mq_ob_wa_' . md5($doc['id'] . '|' . $st);
            if (get_transient($key)) { return false; }
            set_transient($key, 1, DAY_IN_SECONDS);
            $head = strtr(self::WA_STATUS[$st], ['{tech}' => (string) ($doc['technicianName'] ?? '')]);
            $head = preg_replace('/\s{2,}/u', ' ', $head);
            return (bool) Mutqan_WA::auto_send($phone, $head . "\n\n" . self::status_text(self::facts($doc)), 'order');
        } catch (Throwable $e) { error_log('[mutqan] orderbot status: ' . $e->getMessage()); return false; }
    }
}
