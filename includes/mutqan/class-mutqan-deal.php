<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «محرك الصفقة» — the owner's own selling rules, written down so the bot plays them the same way every time.
 *
 * What the owner said (2026-11-20), and what this class does with it:
 *
 *   1. How far the price can bend depends on the day. A quiet day with no orders: four split units for 200 and six
 *      for 250–300 are fine, a filled slot beats an empty one. Two orders already in: hold the price, six is 350–360.
 *      → demand() counts today's real orders; bulk_floor() moves the lowest price for 4+ units between the owner's
 *        minimum and maximum per unit according to it.
 *
 *   2. The owner or operations steer it: «ارفع الليميت», «قلل الليميت», «ثبت», «الليميت من 45 لـ 60».
 *      → band() / set_band(): a minimum and a maximum per unit and a mode (auto follows the day, loose sits on the
 *        minimum, tight on the maximum, fixed = official prices only, no haggling).
 *
 *   3. Appointments: 7 in the morning to 10 at night, orders are taken and dispatched the same day, and the customer may
 *      choose today or tomorrow. From 11 at night, new orders go to tomorrow — early morning or any hour he picks.
 *      Between 10 and 11, today only if he insists (operations confirm), otherwise tomorrow morning.
 *      → booking().
 *
 *   4. A customer with an order asks «الفني فين؟», hurries, wants to cancel or move it: the bot acts on the order
 *      (asks the technician, tells operations, cancels when no technician is on his way yet) and says what it did.
 *      → service().
 *
 * Nothing here ever goes under what the owner allowed, and no internal figure is written to a customer.
 */
final class Mutqan_Deal {

    const OPT = 'mutqan_price_band';
    const TZ = 'Asia/Riyadh';
    const DEAD = ['cancelled', 'canceled', 'rejected', 'closed_cancelled'];

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'المساعد الذكي']; }

    /* ================================================================== the band */

    public static function defaults() {
        // minUnit 42 → six units for 250, maxUnit 60 → six for 360; never under the three-unit price for 4+ units.
        return ['mode' => 'auto', 'minUnit' => 42, 'maxUnit' => 60, 'fromQty' => 4, 'busyAt' => 2, 'until' => '', 'note' => '', 'by' => '', 'at' => ''];
    }

    public static function band() {
        $b = get_option(self::OPT, []);
        $b = array_merge(self::defaults(), is_array($b) ? $b : []);
        if ($b['until'] !== '' && strtotime((string) $b['until']) && strtotime((string) $b['until']) < time()) {
            $b = array_merge(self::defaults(), ['minUnit' => $b['minUnit'], 'maxUnit' => $b['maxUnit']]);   // a timed order ends by itself; the numbers stay
        }
        $b['minUnit'] = (int) max(20, min(200, (float) $b['minUnit']));
        $b['maxUnit'] = (int) max($b['minUnit'], min(200, (float) $b['maxUnit']));
        $b['fromQty'] = (int) max(2, min(20, (int) $b['fromQty']));
        $b['busyAt'] = (int) max(1, min(20, (int) $b['busyAt']));
        if (!in_array($b['mode'], ['auto', 'loose', 'tight', 'fixed'], true)) { $b['mode'] = 'auto'; }
        return $b;
    }

    /** $patch: mode, minUnit, maxUnit, step (+/- per unit on both ends), hours, note. Returns the new band. */
    public static function set_band($patch, $p = null) {
        $b = self::band();
        if (isset($patch['step']) && is_numeric($patch['step'])) {
            $st = (int) $patch['step'];
            $b['minUnit'] += $st; $b['maxUnit'] += $st;
        }
        foreach (['minUnit', 'maxUnit'] as $k) { if (isset($patch[$k]) && is_numeric($patch[$k])) { $b[$k] = (int) $patch[$k]; } }
        if ($b['minUnit'] > $b['maxUnit']) { $t = $b['minUnit']; $b['minUnit'] = $b['maxUnit']; $b['maxUnit'] = $t; }
        if (isset($patch['mode']) && in_array($patch['mode'], ['auto', 'loose', 'tight', 'fixed'], true)) { $b['mode'] = $patch['mode']; }
        $h = isset($patch['hours']) && is_numeric($patch['hours']) ? (float) $patch['hours'] : 0;
        $b['until'] = $h > 0 ? gmdate('c', time() + (int) round(min(720, $h) * 3600)) : '';
        $b['note'] = mb_substr(trim((string) ($patch['note'] ?? '')), 0, 200);
        $b['by'] = (string) ($p['name'] ?? ($p['role'] ?? ''));
        $b['at'] = gmdate('c');
        foreach (['fromQty', 'busyAt'] as $k) { if (isset($patch[$k]) && is_numeric($patch[$k])) { $b[$k] = (int) $patch[$k]; } }
        update_option(self::OPT, $b, false);
        return self::band();
    }

    /* ================================================================== the day */

    public static function local($ts = null) { $d = new DateTime('@' . (int) ($ts ?? time())); $d->setTimezone(new DateTimeZone(self::TZ)); return $d; }

    /** Today's real orders (Makkah date, not cancelled) and what that makes the day. */
    public static function demand($now = null, $orders = null) {
        $now = $now ?? time();
        $ymd = self::local($now)->format('Y-m-d');
        if ($orders === null) {
            try { $orders = Mutqan_Store::select('orders', [], ['limit' => 300, 'order' => '-createdAt'], self::sys()); } catch (Throwable $e) { $orders = []; }
        }
        $n = 0; $done = 0;
        foreach ($orders as $o) {
            if (in_array((string) ($o['status'] ?? ''), self::DEAD, true)) { continue; }
            $t = strtotime((string) ($o['createdAt'] ?? '')) ?: 0;
            if (!$t || self::local($t)->format('Y-m-d') !== $ymd) { continue; }
            $n++;
            if (($o['status'] ?? '') === 'completed') { $done++; }
        }
        $busyAt = self::band()['busyAt'];
        $level = $n >= $busyAt ? 'busy' : ($n === 0 ? 'quiet' : 'normal');
        return ['today' => $n, 'completed' => $done, 'level' => $level, 'label' => ['quiet' => 'اليوم هادي', 'normal' => 'اليوم ماشي', 'busy' => 'اليوم شغال'][$level]];
    }

    /** The lowest price per unit for a 4+ unit deal right now (null = official prices only). */
    public static function unit_floor($demand = null) {
        $b = self::band();
        if ($b['mode'] === 'fixed') { return null; }
        if ($b['mode'] === 'loose') { return $b['minUnit']; }
        if ($b['mode'] === 'tight') { return $b['maxUnit']; }
        $d = $demand ?? self::demand();
        if ($d['level'] === 'quiet') { return $b['minUnit']; }
        if ($d['level'] === 'busy') { return $b['maxUnit']; }
        return (int) round(($b['minUnit'] + $b['maxUnit']) / 2);
    }

    private static function r5($v) { return (int) (round($v / 5) * 5); }

    /**
     * The lowest total for $qty AC wash units whose official total is $list. Under the band size, the engine's own
     * floor stands ($engine). 4+ units: qty × unit floor, never under the three-unit price, never over the list.
     * Fixed mode: the list itself.
     */
    public static function bulk_floor($qty, $list, $engine = null, $demand = null) {
        $qty = max(1, (int) $qty); $list = (int) round((float) $list);
        $b = self::band();
        if ($b['mode'] === 'fixed') { return $list; }
        $eng = $engine !== null ? (int) $engine : $list;
        if ($qty < $b['fromQty'] || $list <= 0) { return min($list, $eng); }
        $u = self::unit_floor($demand);
        $three = class_exists('Mutqan_Policy') ? (int) round(Mutqan_Policy::ac_table()['split3']) : 200;
        $f = max($three, self::r5($qty * $u));
        return min($list, $eng, $f);
    }

    /** How the bot should play a bulk deal today, for the prompt (internal). */
    public static function deal_hint($qty, $list, $engine = null) {
        $b = self::band(); $d = self::demand();
        if ($b['mode'] === 'fixed') { return 'أوامر الإدارة: الأسعار الرسمية فقط اليوم، بدون فصال. اعتذر بلطف وركّز على القيمة والضمان.'; }
        $f = self::bulk_floor($qty, $list, $engine, $d);
        if ($qty < $b['fromQty']) { return ''; }
        $how = $d['level'] === 'quiet' || $b['mode'] === 'loose'
            ? 'اليوم هادي: الصفقة الكبيرة أهم من السعر — انزل بالتدريج لحد ما تقفل، واقبل عرضه لو فوق ' . $f . ' ر.س.'
            : ($d['level'] === 'busy' || $b['mode'] === 'tight'
                ? 'اليوم شغال (فيه طلبات): امسك في السعر، تنازل صغير واحد بس، وما تنزلش تحت ' . $f . ' ر.س.'
                : 'اليوم ماشي: فاصل بهدوء على خطوتين، وما تنزلش تحت ' . $f . ' ر.س.');
        return 'صفقة ' . $qty . ' مكيفات (الرسمي ' . $list . '): ' . $how;
    }

    public static function status_text() {
        $b = self::band(); $d = self::demand();
        $u = self::unit_floor($d);
        $modes = ['auto' => 'تلقائي حسب شغل اليوم', 'loose' => 'مرن (على المينيمم)', 'tight' => 'ماسك (على الماكسيمم)', 'fixed' => 'الأسعار الرسمية بس — مفيش فصال'];
        $ex = function ($q) use ($d) { $list = class_exists('Mutqan_Policy') ? (int) round(Mutqan_Policy::ac_total(['mode' => 'wash', 'type' => 'سبليت', 'qty' => $q, 'full' => true])) : 0; return $q . ' مكيف: الرسمي ' . $list . ' ← أقل حاجة ' . self::bulk_floor($q, $list, null, $d); };
        return 'ليميت الأسعار: ' . $modes[$b['mode']] . "\n"
            . 'المينيمم ' . $b['minUnit'] . ' ر.س للمكيف · الماكسيمم ' . $b['maxUnit'] . ' ر.س للمكيف (من ' . $b['fromQty'] . ' مكيفات وطالع)' . "\n"
            . $d['label'] . ': ' . $d['today'] . ' طلب النهارده' . ($u !== null ? ' — الحد الحالي ' . $u . ' للمكيف' : '') . "\n"
            . $ex(4) . "\n" . $ex(6) . "\n" . $ex(10)
            . ($b['until'] !== '' ? "\nلحد: " . self::local(strtotime($b['until']))->format('H:i d/m') : '')
            . ($b['note'] !== '' ? "\nملاحظة: " . $b['note'] : '');
    }

    /* ================================================================== appointments */

    /**
     * today: 07:00–21:59, take and dispatch the same day; offer today or tomorrow.
     * late:  22:00–22:59, tomorrow morning by default; tonight only if he insists (operations confirm).
     * tomorrow: 23:00–06:59, book for tomorrow (early morning or any hour he picks).
     */
    public static function booking($now = null) {
        $d = self::local($now ?? time());
        $h = (int) $d->format('G');
        $mode = ($h >= 7 && $h < 22) ? 'today' : ($h === 22 ? 'late' : 'tomorrow');
        // after midnight «tomorrow» is today's date for the customer: the morning of the same calendar day.
        $morningIsToday = $h < 7;
        $day = $morningIsToday ? 'النهارده الصبح' : 'بكره الصبح';
        $intake = class_exists('Mutqan_Command') ? Mutqan_Command::intake()['mode'] : 'open';
        if ($intake === 'tomorrow' && $mode === 'today') { $mode = 'tomorrow'; $day = 'بكره الصبح'; }
        $texts = [
            'today' => "- الوقت الآن داخل ساعات الاستقبال (7 الصبح لـ 10 بالليل): استقبل الطلب ووزّعه في نفس اليوم عادي.\n"
                . "- لو العميل ما حددش وقت: اعرض عليه بسلاسة «تحب نخليها اليوم ولا بكره؟» والافتراضي اليوم — أقرب وقت. سجّل اللي يختاره.\n"
                . "- لو قال وقت بمواقيت الصلاة أو بالساعة أو بيوم معين: سجّله زي ما قاله.",
            'late' => "- الساعة دلوقتي بعد 10 بالليل: الافتراضي موعد بكره الصبح بدري، أو أي وقت بكره يناسبه.\n"
                . "- لو العميل مُصر إنها الليلة أو حالة طارئة: سجّلها «الليلة لو فيه فني متاح» وقوله إن العمليات هتأكد له خلال دقايق.",
            'tomorrow' => "- الساعة دلوقتي بعد 11 بالليل: الطلبات الجديدة بتتحجز {$day} بدري، أو أي وقت يناسبه خلال اليوم.\n"
                . "- قوله بلطف إن الفنيين بيبدأوا من 7 الصبح، واسأله: تحب أول الصبح ولا وقت معين؟ وسجّل اختياره.\n"
                . "- حالة طارئة وخطيرة (تسريب مياه كبير، ماس أو ريحة حريق، غرفة تبريد فيها بضاعة هتبوظ): خليه يتصل فورًا بالرقم المباشر.",
        ];
        $lead = ['today' => 'اليوم — أقرب وقت ممكن', 'late' => 'بكره الصبح بدري', 'tomorrow' => $day . ' بدري'][$mode];
        return ['mode' => $mode, 'hour' => $h, 'rule' => $texts[$mode], 'defaultTime' => $lead, 'offerTomorrow' => $mode === 'today'];
    }

    /* ================================================================== a customer with an order */

    const LIVE = ['new', 'forced', 'inhouse', 'assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress'];
    const MOVING = ['accepted', 'en_route', 'nearby', 'arrived', 'in_progress'];

    public static function live_order_for($phone) {
        $k = substr(preg_replace('/\D/', '', (string) $phone), -9);
        if (strlen($k) !== 9) { return null; }
        try { $rows = Mutqan_Store::select('orders', [], ['limit' => 300, 'order' => '-createdAt'], self::sys()); } catch (Throwable $e) { return null; }
        foreach ($rows as $o) {
            if (substr(preg_replace('/\D/', '', (string) ($o['clientPhone'] ?? '')), -9) !== $k) { continue; }
            if (in_array((string) ($o['status'] ?? ''), self::LIVE, true)) { return $o; }
        }
        return null;
    }

    private static function num($o) { return (string) ($o['number'] ?? '') ?: substr((string) $o['id'], -5); }

    private static function room($o, $title, $body) {
        if (class_exists('Mutqan_Rest')) { try { Mutqan_Rest::admin_alert($title . ' #' . self::num($o), $body, '#/staff/order/' . $o['id'], 'order_bot', 'high', $o['id']); } catch (Throwable $e) {} }
    }

    /**
     * The customer wants to cancel. Before a technician has accepted the job, it is cancelled at once (operations and
     * the technician are told). Once a technician accepted or is on his way, the request goes to operations, the
     * technician holds, and the customer is told it is being confirmed.
     */
    public static function cancel($o, $text) {
        $st = (string) ($o['status'] ?? '');
        if (in_array($st, self::DEAD, true)) { return ['done' => 'already', 'reply' => 'طلبك #' . self::num($o) . ' ملغي بالفعل. لو حابب نفتح طلب جديد قول لي.']; }
        if ($st === 'completed') { return ['done' => 'completed', 'reply' => 'طلبك #' . self::num($o) . ' خلص بالفعل. لو في ملاحظة على الشغل قول لي وأنا أوصلها للعمليات.']; }
        $why = mb_substr(trim((string) $text), 0, 200);
        if (!in_array($st, self::MOVING, true)) {
            Mutqan_Store::update('orders', $o['id'], ['status' => 'cancelled', 'cancelReason' => 'العميل: ' . $why, 'cancelledBy' => 'customer_bot', 'cancelledAt' => gmdate('c')], self::sys());
            if (!empty($o['technicianId']) && class_exists('Mutqan_OrderBot')) { Mutqan_OrderBot::tell_tech($o, '❌ العميل لغى طلب #' . self::num($o) . ' — ما تتحركش عليه.'); }
            self::room($o, '❌ العميل لغى الطلب', 'اتلغى من المساعد قبل ما فني يقبله: «' . $why . '»');
            return ['done' => 'cancelled', 'reply' => 'تم إلغاء طلبك #' . self::num($o) . " ✅ وبلّغت العمليات" . (!empty($o['technicianId']) ? ' والفني' : '') . ".\nلو حابب نحجزه في وقت تاني أو في حاجة نقدر نعدلها (الموعد أو السعر) قول لي وأنا أرتبها لك."];
        }
        Mutqan_Store::update('orders', $o['id'], ['cancelRequest' => ['at' => gmdate('c'), 'text' => $why, 'status' => $st]], self::sys());
        if (!empty($o['technicianId']) && class_exists('Mutqan_OrderBot')) { Mutqan_OrderBot::tell_tech($o, '⏸ العميل طلب إلغاء طلب #' . self::num($o) . ' — استنى مكانك لحد ما العمليات تأكد.'); }
        self::room($o, '❌ العميل عايز يلغي والفني متحرك', '«' . $why . '» — الفني ' . (string) ($o['technicianName'] ?? '') . '. أكّد الإلغاء أو كلّم العميل.');
        return ['done' => 'requested', 'reply' => "وصلني طلب الإلغاء ✅ الفني " . ((string) ($o['technicianName'] ?? '') ?: '') . " كان متحرك لك، فبلّغته يستنى وبلّغت العمليات، وهيأكدوا لك خلال دقايق.\nلو المشكلة في الموعد أقدر أأجله لك بدل الإلغاء — تحب؟"];
    }

    /** The customer wants another time: the order is marked, operations and the technician are told. */
    public static function reschedule($o, $text) {
        $when = mb_substr(trim((string) $text), 0, 160);
        Mutqan_Store::update('orders', $o['id'], ['rescheduleRequest' => ['at' => gmdate('c'), 'text' => $when]], self::sys());
        if (!empty($o['technicianId']) && class_exists('Mutqan_OrderBot')) { Mutqan_OrderBot::tell_tech($o, '⏳ العميل عايز يغيّر الموعد: «' . $when . '» — استنى تأكيد العمليات.'); }
        self::room($o, '⏳ العميل عايز يغيّر الموعد', '«' . $when . '»');
        return 'تمام ✅ سجلت إنك عايز تغيّر الموعد وبلّغت العمليات' . (!empty($o['technicianId']) ? ' والفني' : '') . '، وهيأكدوا لك الموعد الجديد هنا.';
    }

    const INTENTS = [
        'cancel' => ['الغي', 'الغاء', 'ألغي', 'كنسل', 'كانسل', 'cancel', 'مش عايز الطلب', 'ما ابغى الطلب', 'ما ابي الطلب', 'لا تجون', 'متجوش', 'لغيت', 'بلاش الطلب', 'خلاص مش محتاج', 'ماعاد ابغى'],
        'reschedule' => ['اجل', 'أجل', 'تاجيل', 'تأجيل', 'غير الموعد', 'غيّر الموعد', 'موعد تاني', 'وقت تاني', 'خليها بكره', 'خليه بكره', 'بدل الموعد', 'ممكن بعدين', 'نأخرها', 'نأجلها'],
        'where' => ['الفني فين', 'فين الفني', 'وين الفني', 'الفني وين', 'متى يوصل', 'امتي يوصل', 'امتى يجي', 'متى يجي', 'وصل لفين', 'فين وصل', 'قرب يوصل', 'هيجي امتى', 'تاخرتو', 'اتاخر', 'تأخر', 'تاخر', 'لسه ما جاش', 'ما جاء', 'ما وصل', 'طولتو', 'طولتوا', 'فين الطلب', 'وين الطلب', 'طلبي فين', 'وين طلبي', 'وصلتو فين'],
        'urgent' => ['مستعجل', 'بسرعه', 'ضروري', 'الحين الحين', 'دلوقتي حالا', 'عاجل'],
        'tech_msg' => ['قول للفني', 'قل للفني', 'بلغ الفني', 'خلي الفني', 'الفني يجيب', 'يجيب معاه', 'يجيب معه', 'سلم', 'يتصل بي', 'يكلمني'],
    ];

    public static function intent($text) {
        $n = class_exists('Mutqan_Playbook') ? Mutqan_Playbook::norm($text) : mb_strtolower((string) $text);
        foreach (self::INTENTS as $k => $words) {
            foreach ($words as $w) {
                $w = class_exists('Mutqan_Playbook') ? Mutqan_Playbook::norm($w) : $w;
                if ($w !== '' && mb_strpos($n, $w) !== false) { return $k; }
            }
        }
        return '';
    }

    /**
     * A message from a customer who has a live order, about that order: do it and say what was done.
     * Returns ['reply' => …, 'intent' => …, 'order' => id] or null when the message is not about his order.
     */
    public static function service($phone, $text) {
        $intent = self::intent($text);
        if ($intent === '') { return null; }
        $o = self::live_order_for($phone);
        if (!$o) {
            if (in_array($intent, ['where', 'cancel'], true)) { return ['reply' => 'مش لاقي طلب مفتوح على رقمك ده 🙏 ابعتلي رقم الطلب أو الرقم اللي طلبت بيه وأنا أتابعه لك حالًا.', 'intent' => $intent, 'order' => null]; }
            return null;
        }
        if ($intent === 'cancel') { $r = self::cancel($o, $text); return ['reply' => $r['reply'], 'intent' => 'cancel', 'order' => $o['id'], 'done' => $r['done']]; }
        if ($intent === 'reschedule') { return ['reply' => self::reschedule($o, $text), 'intent' => 'reschedule', 'order' => $o['id']]; }
        if (!class_exists('Mutqan_OrderBot')) { return null; }
        $obIntent = $intent === 'tech_msg' ? 'request' : $intent;
        $did = Mutqan_OrderBot::act($obIntent, $o, $text);
        $o2 = (Mutqan_Store::get_raw('orders', $o['id']) ?: $o) + ['id' => $o['id']];
        return ['reply' => Mutqan_OrderBot::template($obIntent, Mutqan_OrderBot::facts($o2), $did), 'intent' => $intent, 'order' => $o['id'], 'did' => $did];
    }

    /* ================================================================== the price, read from the conversation */

    /** What the customer is asking to have priced, from his lines: ['list','floor','qty','label'] or null. */
    public static function priced($lines) {
        if (!class_exists('Mutqan_Hub')) { return null; }
        $q = null;
        foreach (array_reverse((array) $lines) as $l) {
            $x = Mutqan_Hub::quote((string) $l);
            if ($x && !empty($x['total'])) { $q = $x; break; }
        }
        if (!$q && $lines) { $x = Mutqan_Hub::quote(implode(' ', (array) $lines)); if ($x && !empty($x['total'])) { $q = $x; } }
        if (!$q) { return null; }
        $list = (int) $q['total'];
        $floor = (int) ($q['floor'] ?? $list);
        $bulk = false;
        if (($q['spec'] ?? '') === 'ac' && !empty($q['fixed']) && ($q['acQuote']['type'] ?? 'سبليت') === 'سبليت' && (int) $q['qty'] >= self::band()['fromQty']) {
            $floor = self::bulk_floor((int) $q['qty'], $list, $floor); $bulk = true;
        } elseif (self::band()['mode'] === 'fixed') { $floor = $list; }
        return ['list' => $list, 'floor' => min($floor, $list), 'qty' => (int) $q['qty'], 'label' => (string) $q['label'], 'bulk' => $bulk, 'spec' => (string) $q['spec']];
    }
}
