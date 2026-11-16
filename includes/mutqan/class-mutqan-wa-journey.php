<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «حلقة الوصل» — one order, followed on WhatsApp from the moment it lands to the customer's rating.
 *
 * The apps hold the order; WhatsApp is where the customer actually is. Everything in between used to need a
 * person: calling to confirm, asking for the location, telling the room it was confirmed, answering «الفني فين؟».
 * This joins the two systems so that, for every order from any door (a website, the app, WhatsApp itself):
 *
 *   1. confirm  — the customer gets the order on WhatsApp from the line of the site he came through, with what is
 *                 missing (the time, the location) asked for; his answers are written into the order, and «تأكيد»
 *                 confirms it. The room (the master number) hears «العميل أكد».
 *   2. live     — distribution (auto or manual) and the technician's job card are the existing machinery. While the
 *                 job runs, whatever the customer writes is answered from the order's facts: «فين الفني» asks the
 *                 technician for his estimate and passes the answer back, a request is relayed to him, a postpone
 *                 or cancel goes to the room. Nothing invented.
 *   3. rate     — when the job is done the owner hears «تمت المهمة», the customer is asked for 1–5 stars, and the
 *                 stars (and a low score at once) reach the owner.
 *
 * Every step leaves a line on the order's route. A conversation a person took over (the bot paused) is left alone.
 */
final class Mutqan_WA_Journey {

    const OPT = 'mutqan_wa_journey';
    const MAP = 'mutqan_wa_journey_map';     // phone key -> {o: orderId, s: stage, at}
    const KEEP = 4 * 86400;                  // a journey nobody answered is forgotten after four days
    const YES = ['تاكيد', 'تأكيد', 'اكد', 'أكد', 'مؤكد', 'موكد', 'اكدت', 'أكدت', 'تمام', 'موافق', 'اوكي', 'اوك', 'ok', 'okay', 'نعم', 'ايوه', 'أيوه', 'ايوا', 'اه', 'صح', 'كله صح', 'مظبوط', 'yes', '1', '١', '👍', '✅'];
    const TIME_WORDS = ['اليوم', 'النهارده', 'النهاردة', 'الليلة', 'بكرة', 'بكره', 'باكر', 'بعد بكرة', 'الصبح', 'الصباح', 'الظهر', 'العصر', 'المغرب', 'العشاء', 'العشا', 'بالليل', 'الساعة', 'الساعه', 'دلوقتي', 'الحين', 'حالا', 'حالًا', 'الان', 'الآن', 'السبت', 'الاحد', 'الأحد', 'الاثنين', 'الإثنين', 'الثلاثاء', 'الاربعاء', 'الأربعاء', 'الخميس', 'الجمعة', 'صباحا', 'مساء', 'مساءً', 'ص', 'م'];
    const SKIP_SOURCES = ['test', 'import'];

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'حلقة الوصل']; }
    public static function enabled() { $c = get_option(self::OPT, []); return !(is_array($c) && isset($c['enabled']) && !$c['enabled']); }
    public static function set_enabled($on) { update_option(self::OPT, ['enabled' => $on ? 1 : 0], false); return self::enabled(); }
    private static function key($phone) { $d = preg_replace('/\D/', '', (string) $phone); return strlen($d) >= 8 ? substr($d, -9) : ''; }
    private static function num($o) { return (string) ($o['number'] ?? '') ?: substr((string) ($o['id'] ?? ''), -5); }
    private static function norm($s) {
        $s = mb_strtolower(trim((string) $s));
        $s = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $s);
        return strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);
    }

    /* ------------------------------------------------------------------ the map: which order a number is talking about */

    private static function map() { $m = get_option(self::MAP, []); return is_array($m) ? $m : []; }
    private static function put($phone, $orderId, $stage) {
        $k = self::key($phone); if ($k === '') { return; }
        $m = self::map();
        foreach ($m as $kk => $v) { if (time() - (int) ($v['at'] ?? 0) > self::KEEP) { unset($m[$kk]); } }
        $m[$k] = ['o' => (string) $orderId, 's' => (string) $stage, 'at' => time()];
        if (count($m) > 500) { $m = array_slice($m, -500, null, true); }
        update_option(self::MAP, $m, false);
    }
    private static function drop($phone) { $k = self::key($phone); $m = self::map(); if (isset($m[$k])) { unset($m[$k]); update_option(self::MAP, $m, false); } }
    public static function current($phone) {
        $k = self::key($phone); $m = self::map();
        if ($k === '' || empty($m[$k])) { return null; }
        if (time() - (int) ($m[$k]['at'] ?? 0) > self::KEEP) { return null; }
        return $m[$k];
    }

    /** What the order still needs before a technician can be sent. */
    public static function missing($o) {
        $miss = [];
        if (empty($o['appointmentAt']) && trim((string) ($o['preferredTime'] ?? '')) === '') { $miss[] = 'time'; }
        if (!is_numeric($o['lat'] ?? null) && trim((string) ($o['address'] ?? '')) === '') { $miss[] = 'place'; }
        return $miss;
    }
    private static function missing_text($miss) {
        $L = [];
        if (in_array('time', $miss, true)) { $L[] = '📅 الموعد المناسب ليك (مثال: بكرة العصر، أو اليوم الساعة 6)'; }
        if (in_array('place', $miss, true)) { $L[] = '📍 موقعك: ابعت اللوكيشن من واتساب (📎 ← الموقع) أو اكتب الحي والعنوان'; }
        return $L;
    }

    private static function summary($o) {
        $L = [];
        $svc = (string) ($o['serviceLabel'] ?? ($o['service'] ?? ''));
        if ($svc === '' && class_exists('Mutqan_Rest') && !empty($o['specialtyId'])) { $svc = (string) Mutqan_Rest::spec_label($o['specialtyId']); }
        if ($svc !== '') { $L[] = '🛠 الخدمة: ' . mb_substr($svc, 0, 120); }
        if (!empty($o['address'])) { $L[] = '🏠 العنوان: ' . mb_substr((string) $o['address'], 0, 140); }
        if (is_numeric($o['lat'] ?? null)) { $L[] = '📍 اللوكيشن: https://maps.google.com/?q=' . $o['lat'] . ',' . $o['lng']; }
        if (!empty($o['appointmentAt'])) { $L[] = '📅 الموعد: ' . (class_exists('Mutqan_Sales') ? Mutqan_Sales::slot_label(strtotime((string) $o['appointmentAt'])) : $o['appointmentAt']); }
        elseif (!empty($o['preferredTime'])) { $L[] = '📅 الموعد: ' . mb_substr((string) $o['preferredTime'], 0, 80); }
        $price = $o['agreedPrice'] ?? ($o['plan']['agreedPrice'] ?? null);
        if (is_numeric($price)) { $L[] = '💰 السعر المتفق: ' . $price . ' ر.س'; }
        return $L;
    }

    private static function site_name($o) {
        $card = (array) ($o['card'] ?? []);
        if (!empty($card['site']) && $card['site'] !== 'غير محدد') { return (string) $card['site']; }
        return class_exists('Mutqan_WA_Central') ? (string) (Mutqan_WA_Central::site((string) ($o['brand'] ?? ''))['name'] ?? '') : '';
    }

    /** Sends to the customer from the line his order belongs to (the one he wrote to, or his site's customers' line). */
    /** The company the customer dealt with, at the foot of every message, so he always knows who is talking. */
    public static function sign($o, $text) {
        $site = self::site_name($o);
        if ($site === '' || mb_strpos($text, '— *' . $site . '*') !== false) { return $text; }
        return rtrim($text) . "\n\n— *" . $site . '* 🌷';
    }

    private static function tell_customer($o, $text) {
        $text = self::sign($o, $text);
        $phone = (string) ($o['clientPhone'] ?? '');
        if ($phone === '' || !class_exists('Mutqan_WA')) { return false; }
        $acct = (string) ($o['waAccount'] ?? '');
        if ($acct !== '') {
            try { Mutqan_WA::send($acct, Mutqan_WA::intl($phone), $text, 'journey', 'system'); return true; }
            catch (Throwable $e) { error_log('[mutqan] journey send ' . $e->getMessage()); }
        }
        return (bool) Mutqan_WA::auto_send($phone, $text, 'order');
    }
    private static function tell_room($text, $key, $o = null) {
        if (!class_exists('Mutqan_WA_Central')) { return 0; }
        $k = (string) $key;
        $kind = 'ops';
        foreach (['jr-ok-' => 'confirm', 'jr-as-' => 'ops', 'jr-go-' => 'tech', 'jr-arr-' => 'tech', 'jr-done-' => 'done', 'jr-rate-' => 'rating', 'jr-cmt-' => 'rating', 'jr-x-' => 'cancel', 'jr-cancel-' => 'cancel', 'jr-wb' => 'winback'] as $p => $kd) { if (strpos($k, $p) === 0) { $kind = $kd; break; } }
        if (strpos($text, 'راجع وابعت بنفسك') !== false || strpos($text, 'عميل مش راضي') !== false) { $kind = 'review'; }
        return Mutqan_WA_Central::to_master($text, $key, $kind, $o);
    }
    private static function step($o, $kind, $text) {
        if (class_exists('Mutqan_WA') && !empty($o['id'])) { try { Mutqan_WA::add_step((string) $o['id'], $kind, $text, 'حلقة الوصل'); } catch (Throwable $e) { error_log('[mutqan] journey step ' . $e->getMessage()); } }
    }

    /* ------------------------------------------------------------------ 1. a new order: confirm it on WhatsApp */

    public static function on_created($doc) {
        try {
            if (!self::enabled() || !is_array($doc) || empty($doc['id']) || empty($doc['clientPhone'])) { return false; }
            if (in_array((string) ($doc['source'] ?? ''), self::SKIP_SOURCES, true) || !empty($doc['customerConfirmed'])) { return false; }
            $site = self::site_name($doc);
            $name = trim((string) ($doc['clientName'] ?? ''));
            $L = ['أهلًا' . ($name !== '' ? ' ' . $name : '') . ' 👋', 'معاك خدمة عملاء' . ($site !== '' ? ' *' . $site . '*' : '') . '، وصلنا طلبك رقم *' . self::num($doc) . '*:'];
            $L = array_merge($L, self::summary($doc));
            $miss = self::missing($doc);
            if ($miss) { $L[] = ''; $L[] = 'عشان نبعتلك الفني محتاجين منك:'; $L = array_merge($L, self::missing_text($miss)); }
            $L[] = '';
            $L[] = $miss ? 'ابعتهم هنا، وبعدها اكتب *تأكيد* ✅' : 'لو البيانات صح اكتب *تأكيد* ✅ — أو اكتب أي تعديل.';
            $sent = self::tell_customer($doc, implode("\n", $L));
            self::put($doc['clientPhone'], $doc['id'], 'confirm');
            self::step($doc, 'wa_confirm_ask', 'اتبعت للعميل تأكيد الطلب على واتساب' . ($miss ? ' (ناقص: ' . implode('، ', array_map(function ($m) { return $m === 'time' ? 'الموعد' : 'الموقع'; }, $miss)) . ')' : ''));
            return $sent;
        } catch (Throwable $e) { error_log('[mutqan] journey created ' . $e->getMessage()); return false; }
    }

    /* ------------------------------------------------------------------ the customer writes */

    /** True when the message belonged to an order's journey and was answered here (the reception bot then stays out). */
    public static function inbound($acct, $phone, $text, $tid) {
        if (!self::enabled()) { return false; }
        $j = self::current($phone);
        if (!$j) { return false; }
        $o = Mutqan_Store::get_raw('orders', $j['o']);
        if (!$o) { self::drop($phone); return false; }
        $o['id'] = $j['o'];
        $text = trim((string) $text);
        if ($text === '') { return false; }
        // He wrote on a line: answers go back on that line from now on.
        if (($o['waAccount'] ?? '') === '' && $acct !== '') { Mutqan_Store::update('orders', $o['id'], ['waAccount' => $acct, 'waThread' => $tid], self::sys()); $o['waAccount'] = $acct; }
        try {
            if ($j['s'] === 'confirm') { $reply = self::on_confirm_stage($o, $text, $phone); }
            elseif ($j['s'] === 'rate') { $reply = self::on_rate_stage($o, $text, $phone); }
            elseif ($j['s'] === 'winback' || $j['s'] === 'winback_time') { $reply = self::on_winback_stage($o, $text, $phone, $j['s']); }
            else { $reply = self::on_live_stage($o, $text); }
        } catch (Throwable $e) { error_log('[mutqan] journey inbound ' . $e->getMessage()); return false; }
        if ($reply === null) { return false; }
        if ($reply !== '') {
            try { Mutqan_WA::send($acct ?: (string) $o['waAccount'], Mutqan_WA::intl($phone), self::sign($o, $reply), 'journey', 'system'); }
            catch (Throwable $e) { error_log('[mutqan] journey reply ' . $e->getMessage()); }
        }
        return true;
    }

    public static function is_yes($text) {
        $n = self::norm(preg_replace('/[!.،,؟?]+/u', ' ', $text));
        $n = trim(preg_replace('/\s+/u', ' ', $n));
        if ($n === '') { return false; }
        foreach (self::YES as $w) { $w = self::norm($w); if ($n === $w || mb_strpos(' ' . $n . ' ', ' ' . $w . ' ') !== false && mb_strlen($n) <= 30) { return true; } }
        return false;
    }
    public static function coords($text) {
        if (preg_match('/(-?\d{1,2}\.\d{3,})\s*,\s*(-?\d{1,3}\.\d{3,})/', (string) $text, $m)) {
            $lat = (float) $m[1]; $lng = (float) $m[2];
            if (abs($lat) <= 90 && abs($lng) <= 180) { return [$lat, $lng]; }
        }
        return null;
    }
    public static function looks_like_time($text) {
        $n = ' ' . self::norm($text) . ' ';
        if (preg_match('/\d{1,2}\s*(:\d{2})?\s*(ص|م|am|pm|صباحا|مساء)?/u', $n) && preg_match('/(ساعه|الساعه|ص |م |am|pm|:|بعد|قبل|يوم|بكره|اليوم)/u', $n)) { return true; }
        foreach (self::TIME_WORDS as $w) { $w = self::norm($w); if (mb_strlen($w) <= 1) { continue; } if (mb_strpos($n, ' ' . $w . ' ') !== false || mb_strpos($n, $w) !== false && mb_strlen($w) > 3) { return true; } }
        return false;
    }

    const LABEL_ST = ['accepted' => 'قبل الطلب', 'en_route' => 'في الطريق', 'nearby' => 'قرّب', 'arrived' => 'وصل', 'in_progress' => 'بيشتغل'];

    private static function on_confirm_stage($o, $text, $phone) {
        $patch = []; $got = [];
        if (class_exists('Mutqan_OrderBot') && Mutqan_OrderBot::intent($text) === 'cancel') {
            Mutqan_OrderBot::act('cancel', $o, $text);
            self::tell_room('❌ العميل طلب إلغاء طلب #' . self::num($o) . ' قبل التأكيد: «' . mb_substr($text, 0, 120) . '»', 'jr-cancel-' . $o['id'], $o);
            return 'وصلني طلب الإلغاء وبلّغت العمليات، وهيتأكد لك حالًا.';
        }
        $c = self::coords($text);
        if ($c) { $patch['lat'] = $c[0]; $patch['lng'] = $c[1]; $got[] = 'اللوكيشن'; }
        $miss = self::missing(array_merge($o, $patch));
        $yes = self::is_yes($text);
        if (!$c && !$yes) {
            if (in_array('time', $miss, true) && self::looks_like_time($text)) { $patch['preferredTime'] = mb_substr($text, 0, 120); $got[] = 'الموعد'; }
            elseif (in_array('place', $miss, true) && mb_strlen($text) >= 4) { $patch['address'] = mb_substr($text, 0, 160); $got[] = 'العنوان'; }
            elseif (mb_strlen($text) >= 2) { $patch['note'] = trim((string) ($o['note'] ?? '') . "\n" . mb_substr($text, 0, 200)); $got[] = 'ملاحظتك'; }
        }
        if ($patch) { Mutqan_Store::update('orders', $o['id'], $patch, self::sys()); $o = array_merge($o, $patch); self::step($o, 'wa_info', 'العميل بعت على واتساب: ' . implode('، ', $got)); }
        $miss = self::missing($o);
        if ($yes && !$miss) { return self::confirm($o, $phone); }
        $L = [];
        if ($got) { $L[] = 'تمام، سجلت ' . implode(' و', $got) . ' ✅'; }
        if ($miss) { $L[] = 'لسه ناقصنا:'; $L = array_merge($L, self::missing_text($miss)); }
        else { $L[] = 'كده البيانات كاملة:'; $L = array_merge($L, self::summary($o)); $L[] = ''; $L[] = 'اكتب *تأكيد* ✅ ونبدأ نجهز الفني.'; }
        return implode("\n", $L);
    }

    private static function confirm($o, $phone) {
        Mutqan_Store::update('orders', $o['id'], ['customerConfirmed' => true, 'confirmedAt' => gmdate('c'), 'confirmedVia' => 'whatsapp'], self::sys());
        self::put($phone, $o['id'], 'live');
        self::step($o, 'wa_confirmed', 'العميل أكد الطلب على واتساب');
        $room = ['✅ *العميل أكد طلب #' . self::num($o) . '* على واتساب'];
        $room = array_merge($room, ['👤 ' . ((string) ($o['clientName'] ?? '') ?: '—') . ' — ' . (string) $o['clientPhone']], self::summary($o));
        $room[] = '';
        $room[] = empty($o['technicianId']) ? 'وزّعه: *اعتمد ' . self::num($o) . '* أو *اسند طلب ' . self::num($o) . ' لمحمود*' : '👷 مع الفني: ' . (string) ($o['technicianName'] ?? '');
        self::tell_room(implode("\n", $room), 'jr-ok-' . $o['id'], $o);
        return "تم تأكيد طلبك ✅\nهنبلغك هنا أول ما الفني يستلم ويتحرك، وتقدر تسألني في أي وقت «الفني فين؟» أو تطلب أي حاجة توصل له.";
    }

    private static function on_live_stage($o, $text) {
        $st = (string) ($o['status'] ?? '');
        if (in_array($st, ['completed', 'cancelled'], true)) { return null; }
        if (!class_exists('Mutqan_OrderBot')) { return null; }
        $c = self::coords($text);
        if ($c && !is_numeric($o['lat'] ?? null)) {
            Mutqan_Store::update('orders', $o['id'], ['lat' => $c[0], 'lng' => $c[1]], self::sys());
            if (!empty($o['technicianId'])) { Mutqan_OrderBot::tell_tech($o, '📍 العميل بعت اللوكيشن: https://maps.google.com/?q=' . $c[0] . ',' . $c[1]); }
            return 'وصل اللوكيشن ✅' . (!empty($o['technicianId']) ? ' وبعته للفني.' : '');
        }
        $intent = Mutqan_OrderBot::intent($text);
        if ($intent === 'cancel') {
            // On the way or not, the customer said «cancel»: the room decides, the technician holds, and nothing is cancelled behind anyone's back.
            Mutqan_Store::update('orders', $o['id'], ['cancelRequest' => ['at' => gmdate('c'), 'text' => mb_substr($text, 0, 200), 'status' => $st]], self::sys());
            $moving = in_array($st, ['accepted', 'en_route', 'nearby', 'arrived', 'in_progress'], true);
            if (!empty($o['technicianId'])) { Mutqan_OrderBot::tell_tech($o, '⏸ العميل طلب إلغاء طلب #' . self::num($o) . ($moving ? ' — استنى مكانك' : '') . ' لحد ما العمليات تأكد. هنبلغك حالًا.'); }
            self::tell_room('❌ *العميل عايز يلغي طلب #' . self::num($o) . '*' . ($moving ? ' — والفني ' . (string) ($o['technicianName'] ?? '') . ' ' . (self::LABEL_ST[$st] ?? $st) : '') . "\n💬 «" . mb_substr($text, 0, 160) . "»\n👤 " . ((string) ($o['clientName'] ?? '') ?: '—') . ' — ' . (string) ($o['clientPhone'] ?? '')
                . "\n\nتأكّد الإلغاء: *الغي " . self::num($o) . "* (هسأله عن السبب وأحاول أرجّعه) · أو كلّمه: *رد " . self::num($o) . " نص الرسالة*", 'jr-cancel-' . $o['id'] . '-' . md5($text), $o);
            self::step($o, 'wa_cancel_request', 'العميل طلب الإلغاء على واتساب: ' . mb_substr($text, 0, 80));
            return "وصلني طلب الإلغاء ✅ وبلّغت العمليات" . (!empty($o['technicianId']) ? ' والفني' : '') . " حالًا.\nلو في حاجة نقدر نعملها عشانك (موعد تاني، السعر، فني تاني) قولّي وأنا أوصّلها.";
        }
        // A plain message to the technician («هات معاك سلم») is still a request for him.
        if ($intent === 'general' && !empty($o['technicianId']) && mb_strlen($text) > 8 && !self::is_yes($text)) { $intent = 'request'; }
        $did = Mutqan_OrderBot::act($intent, $o, $text);
        $oid = (string) ($o['id'] ?? '');
        $o = (Mutqan_Store::get_raw('orders', $oid) ?: $o) + ['id' => $oid];
        $f = Mutqan_OrderBot::facts($o);
        self::step($o, 'wa_customer', 'العميل على واتساب: ' . mb_substr($text, 0, 80));
        return Mutqan_OrderBot::template($intent, $f, $did);
    }

    private static function on_rate_stage($o, $text, $phone) {
        $r = (array) ($o['rating'] ?? []);
        $stars = null;
        $stars_in = preg_match_all('/⭐|★/u', $text);
        if ($stars_in >= 1 && $stars_in <= 5) { $stars = $stars_in; }
        elseif (preg_match('/(?<![\d])([1-5١-٥])(?![\d])/u', $text, $m)) { $stars = (int) strtr($m[1], ['١' => 1, '٢' => 2, '٣' => 3, '٤' => 4, '٥' => 5]); }
        if ($stars === null && empty($r['stars'])) {
            $n = self::norm($text);
            if (preg_match('/(ممتاز|رائع|جميل|شكرا|حلو|مبدع|تسلم)/u', $n)) { $stars = 5; }
            elseif (preg_match('/(سيء|سيئ|وحش|زفت|مش كويس|متاخر|غالي|ما عجبني)/u', $n)) { $stars = 2; }
        }
        if ($stars !== null && empty($r['stars'])) {
            $r = ['stars' => $stars, 'comment' => preg_match('/[\p{L}]{3,}/u', $text) ? mb_substr($text, 0, 300) : '', 'at' => gmdate('c'), 'via' => 'whatsapp'];
            Mutqan_Store::update('orders', $o['id'], ['rating' => $r, 'customerRating' => $stars], self::sys());
            self::step($o, 'wa_rating', 'تقييم العميل: ' . $stars . '/5');
            $line = str_repeat('⭐', $stars) . ' *تقييم العميل لطلب #' . self::num($o) . ': ' . $stars . '/5*' . "\n👤 " . ((string) ($o['clientName'] ?? '') ?: (string) $o['clientPhone']) . (!empty($o['technicianName']) ? "\n👷 الفني: " . $o['technicianName'] : '') . ($r['comment'] !== '' ? "\n💬 «" . $r['comment'] . '»' : '');
            if ($stars <= 3) { $line = "⚠️ *عميل مش راضي*\n" . $line . "\n\nكلّمه: *رد " . self::num($o) . ' نص الرسالة*'; if (class_exists('Mutqan_Rest')) { Mutqan_Rest::admin_alert('⚠️ تقييم منخفض #' . self::num($o), $stars . '/5 ' . $r['comment'], '#/staff/order/' . $o['id'], 'order_rating', 'high', $o['id']); } }
            self::tell_room($line, 'jr-rate-' . $o['id'], $o);
            if ($r['comment'] === '') { return $stars >= 4 ? "شكرًا لتقييمك 🙏 يسعدنا إنك راضي.\nلو حابب تكتب لنا كلمة عن الفني أو الخدمة اكتبها هنا." : "شكرًا لصراحتك 🙏 آسفين إن التجربة ما كانتش زي ما تستاهل.\nقولنا إيه اللي حصل وهيكلمك المسؤول بنفسه."; }
            self::drop($phone);
            return $stars >= 4 ? 'شكرًا لتقييمك وكلامك 🙏 في خدمتك دايمًا.' : 'شكرًا لصراحتك 🙏 وصلت ملاحظتك للمسؤول وهيتواصل معاك.';
        }
        if (!empty($r['stars'])) {
            $r['comment'] = trim(($r['comment'] ?? '') . ' ' . mb_substr($text, 0, 300));
            Mutqan_Store::update('orders', $o['id'], ['rating' => $r], self::sys());
            self::tell_room('💬 تعليق العميل على طلب #' . self::num($o) . ' (' . $r['stars'] . '/5): «' . mb_substr($text, 0, 300) . '»', 'jr-cmt-' . $o['id'] . '-' . md5($text), $o);
            self::drop($phone);
            return 'وصل كلامك للإدارة 🙏 شكرًا ليك.';
        }
        return 'قيّم الخدمة برقم من 1 لـ 5 ⭐ (5 = ممتازة).';
    }

    /* ------------------------------------------------------------------ 4. the customer cancelled: ask why, try to keep him */

    const WB_REASONS = [
        'price' => ['1', '١', 'سعر', 'السعر', 'غالي', 'غاليه', 'مكلف', 'فلوس', 'الفلوس'],
        'time' => ['2', '٢', 'موعد', 'الموعد', 'الوقت', 'مش فاضي', 'مسافر', 'مشغول', 'وقت تاني', 'بعدين', 'تأجيل', 'تاجيل', 'اجل', 'أجل'],
        'late' => ['3', '٣', 'اتاخر', 'اتأخر', 'تاخير', 'تأخير', 'متاخر', 'محدش رد', 'ما حد رد', 'ماحد رد', 'محدش جه', 'ما جا', 'مجاش', 'بطيء'],
        'other_co' => ['4', '٤', 'حد تاني', 'شركه تانيه', 'شركة تانية', 'غيركم', 'لقيت', 'اتصلح', 'اشتغل', 'مش محتاج', 'ما احتاج', 'خلاص', 'استغنيت'],
    ];
    const WB_LABEL = ['price' => 'السعر', 'time' => 'الموعد مش مناسب', 'late' => 'التأخير / محدش رد', 'other_co' => 'لقى بديل / مش محتاج', 'other' => 'سبب تاني'];
    const BACK = ['رجعه', 'رجّعه', 'رجعو', 'ارجعه', 'رجع الطلب', 'ارجع الطلب', 'كمل', 'كمّل', 'كملوا', 'موافق', 'تمام', 'ماشي', 'اوكي', 'ok', 'نعم', 'ايوه', 'ابغاه', 'ابغى', 'عايزه', 'خلاص رجعه'];

    public static function winback_offer_pct() {
        $P = class_exists('Mutqan_Sales') ? (array) (Mutqan_Sales::settings()['pricing'] ?? []) : [];
        $max = (float) ($P['maxDiscountPct'] ?? 10);
        $want = max((float) ($P['offPeakPct'] ?? 5), 10);
        return (int) max(0, min($max, $want));
    }

    public static function winback_start($doc) {
        $name = trim((string) ($doc['clientName'] ?? ''));
        $L = ['أهلًا' . ($name !== '' ? ' ' . $name : '') . ' 🌷', 'لاحظنا إنك لغيت طلبك رقم *' . self::num($doc) . '*' . (($s = self::summary($doc)) ? ' (' . preg_replace('/^🛠 الخدمة: /u', '', $s[0]) . ')' : '') . '.', 'يهمنا نعرف السبب عشان نخدمك أحسن — اختار رقم:',
            '1️⃣ السعر', '2️⃣ الموعد مش مناسب', '3️⃣ اتأخرنا عليك / محدش رد', '4️⃣ لقيت بديل أو مش محتاج', '5️⃣ سبب تاني (اكتبه)', '', 'ولو غيرت رأيك اكتب *رجّعه* ✅ ونكمّل بنفس البيانات من غير ما تعيد أي حاجة.'];
        self::tell_customer($doc, implode("\n", $L));
        self::put($doc['clientPhone'], $doc['id'], 'winback');
        self::step($doc, 'wa_winback', 'اتسأل العميل عن سبب الإلغاء وعرضنا نرجّع الطلب');
    }

    private static function wb_reason($text) {
        $n = ' ' . self::norm($text) . ' ';
        foreach (self::WB_REASONS as $k => $words) {
            foreach ($words as $w) {
                $w = self::norm($w);
                if (preg_match('/^\d$|^[١-٩]$/u', $w)) { if (preg_match('/(^|\s)' . $w . '(\s|$)/u', trim($n))) { return $k; } continue; }
                if (mb_strpos($n, $w) !== false) { return $k; }
            }
        }
        if (preg_match('/(^|\s)(5|٥)(\s|$)/u', trim($n))) { return 'other'; }
        return '';
    }
    private static function wants_back($text) {
        $n = trim(self::norm(preg_replace('/[!.،,؟?]+/u', ' ', $text)));
        foreach (self::BACK as $w) { $w = self::norm($w); if ($n === $w || (mb_strlen($n) <= 25 && mb_strpos($n, $w) !== false)) { return true; } }
        return false;
    }

    private static function reopen($o, $phone, $extra, $why) {
        $wb = (array) ($o['winback'] ?? []);
        $patch = array_merge(['status' => 'new', 'technicianId' => null, 'technicianName' => null, 'customerConfirmed' => true, 'confirmedAt' => gmdate('c'), 'confirmedVia' => 'whatsapp',
            'winback' => array_merge($wb, ['won' => true, 'at' => gmdate('c')])], $extra);
        Mutqan_Store::update('orders', $o['id'], $patch, self::sys());
        $o = array_merge($o, $patch);
        self::put($phone, $o['id'], 'live');
        self::step($o, 'wa_winback_won', 'العميل رجّع الطلب على واتساب' . ($why !== '' ? ' — ' . $why : ''));
        $room = ['♻️ *العميل رجّع طلب #' . self::num($o) . '* بعد ما لغاه', '👤 ' . ((string) ($o['clientName'] ?? '') ?: '—') . ' — ' . (string) $o['clientPhone']];
        if (!empty($wb['reason'])) { $room[] = '❓ سبب الإلغاء كان: ' . (self::WB_LABEL[$wb['reason']] ?? $wb['reason']) . (!empty($wb['text']) ? ' — «' . $wb['text'] . '»' : ''); }
        if ($why !== '') { $room[] = '🎯 ' . $why; }
        $room = array_merge($room, self::summary($o));
        $room[] = ''; $room[] = 'وزّعه: *اعتمد ' . self::num($o) . '* أو *اسند طلب ' . self::num($o) . ' لمحمود*';
        self::tell_room(implode("\n", $room), 'jr-wb-' . $o['id'] . '-' . time(), $o);
        if (class_exists('Mutqan_Dispatch') && Mutqan_Dispatch::is_auto()) { try { Mutqan_Dispatch::dispatch(Mutqan_Store::get_raw('orders', $o['id']) + ['id' => $o['id']]); } catch (Throwable $e) { error_log('[mutqan] winback dispatch ' . $e->getMessage()); } }
        return "رجّعنا طلبك ✅ بنفس البيانات" . ($why !== '' ? ' (' . $why . ')' : '') . ".\nهنبلغك هنا أول ما الفني يستلم ويتحرك. شكرًا لثقتك 🌷";
    }

    private static function on_winback_stage($o, $text, $phone, $stage) {
        $wb = (array) ($o['winback'] ?? []);
        if ($stage === 'winback_time') {
            if (self::looks_like_time($text) || mb_strlen($text) >= 3 && !self::wants_back($text)) { return self::reopen($o, $phone, ['preferredTime' => mb_substr($text, 0, 120), 'appointmentAt' => null], 'على موعد جديد: ' . mb_substr($text, 0, 60)); }
            if (self::wants_back($text)) { return 'اكتب الوقت اللي يناسبك (مثال: بكرة العصر) وأرجّع الطلب عليه.'; }
        }
        if (self::wants_back($text) && empty($wb['reason'])) { return self::reopen($o, $phone, [], ''); }
        if (self::wants_back($text) && !empty($wb['offerPct'])) { return self::reopen($o, $phone, ['winbackDiscountPct' => (int) $wb['offerPct'], 'note' => trim((string) ($o['note'] ?? '') . "\nخصم استرجاع " . (int) $wb['offerPct'] . '% (واتساب)')], 'بخصم ' . (int) $wb['offerPct'] . '%'); }
        if (self::wants_back($text) && ($wb['reason'] ?? '') === 'late') { return self::reopen($o, $phone, ['priority' => 'high'], 'بأولوية وأقرب فني'); }
        if (self::wants_back($text)) { return self::reopen($o, $phone, [], ''); }
        $reason = self::wb_reason($text);
        if ($reason === '' && !empty($wb['reason'])) {
            // More words after the reason: kept for the owner.
            $wb['text'] = trim(($wb['text'] ?? '') . ' ' . mb_substr($text, 0, 200));
            Mutqan_Store::update('orders', $o['id'], ['winback' => $wb], self::sys());
            self::tell_room('💬 العميل (طلب #' . self::num($o) . ' الملغي): «' . mb_substr($text, 0, 200) . '»', 'jr-wbt-' . $o['id'] . '-' . md5($text), $o);
            return 'وصل كلامك للإدارة 🙏 ولو حابب نرجّع الطلب في أي وقت اكتب *رجّعه*.';
        }
        if ($reason === '') { $reason = 'other'; }
        $wb = array_merge($wb, ['reason' => $reason, 'text' => preg_match('/[\p{L}]{3,}/u', $text) ? mb_substr($text, 0, 200) : '', 'askedAt' => $wb['askedAt'] ?? gmdate('c')]);
        $reply = '';
        switch ($reason) {
            case 'price':
                $pct = self::winback_offer_pct();
                $wb['offerPct'] = $pct;
                $reply = $pct > 0 ? "مقدّرين ده 🙏 عشانك هنعملك *خصم " . $pct . "%* على نفس الطلب، والسعر النهائي يتأكد لك قبل التنفيذ ومفيش أي حاجة من غير موافقتك.\nاكتب *رجّعه* ✅ ونكمّل فورًا." : "مقدّرين ده 🙏 السعر بيتأكد لك قبل التنفيذ ومفيش أي حاجة من غير موافقتك، والفني بيعاين الأول.\nاكتب *رجّعه* ✅ لو حابب نكمّل.";
                break;
            case 'time':
                $reply = "ولا يهمك 🌷 قولّي الوقت اللي يناسبك (مثال: بكرة العصر، أو الخميس الساعة 6) وأرجّع الطلب عليه على طول من غير أي إجراءات.";
                self::put($phone, $o['id'], 'winback_time');
                break;
            case 'late':
                $reply = "حقك علينا 🙏 آسفين على التأخير.\nلو رجّعنا الطلب هيكون *بأولوية* مع أقرب فني متاح، وهتتابع مكانه من هنا لحظة بلحظة.\nاكتب *رجّعه* ✅";
                break;
            case 'other_co':
                $reply = "تمام، يسعدنا إن مشكلتك اتحلت 🌷\nلو احتجت أي خدمة بعد كده ابعتلنا هنا كلمة واحدة ونرتب لك كل حاجة بسرعة.";
                break;
            default:
                $reply = "شكرًا إنك قلتلنا 🙏 وصلت ملاحظتك للمسؤول.\nولو حابب نرجّع الطلب بنفس البيانات اكتب *رجّعه* ✅";
        }
        Mutqan_Store::update('orders', $o['id'], ['winback' => $wb, 'cancelReason' => self::WB_LABEL[$reason] . ($wb['text'] !== '' ? ' — ' . $wb['text'] : '')], self::sys());
        self::step($o, 'wa_cancel_reason', 'سبب الإلغاء: ' . self::WB_LABEL[$reason]);
        self::tell_room('❓ *سبب إلغاء طلب #' . self::num($o) . '*: ' . self::WB_LABEL[$reason] . ($wb['text'] !== '' ? "\n💬 «" . $wb['text'] . '»' : '') . ($reason === 'price' && !empty($wb['offerPct']) ? "\n🎯 عرضت عليه خصم " . $wb['offerPct'] . '%' : ($reason === 'time' ? "\n🎯 بسأله عن موعد جديد" : ($reason === 'late' ? "\n🎯 عرضت عليه أولوية وأقرب فني" : ''))) . "\n\nتكلّمه بنفسك: *رد " . self::num($o) . ' نص الرسالة*', 'jr-wbr-' . $o['id'] . '-' . $reason, $o);
        if ($reason === 'other_co') { self::drop($phone); }
        return $reply;
    }

    /** The owner brings a cancelled order back: «رجع 7001». */
    public static function owner_reopen($o) {
        $o['id'] = (string) $o['id'];
        Mutqan_Store::update('orders', $o['id'], ['status' => 'new', 'technicianId' => null, 'technicianName' => null], self::sys());
        if (!empty($o['clientPhone'])) { self::put($o['clientPhone'], $o['id'], 'live'); self::tell_customer($o, 'رجّعنا طلبك رقم *' . self::num($o) . '* ✅ بنفس البيانات، وهنبلغك أول ما الفني يتحرك.'); }
        self::step($o, 'reopen', 'المالك رجّع الطلب من واتساب');
        return '♻️ الطلب #' . self::num($o) . ' رجع «جديد»' . (!empty($o['clientPhone']) ? ' واتبلغ العميل' : '') . '. وزّعه: *اعتمد ' . self::num($o) . '*';
    }

    /** Today's cancellations and why, for the owner («الملغي»). */
    public static function cancelled_today() {
        $since = time() - 86400;
        $rows = Mutqan_Store::select('orders', ['status' => 'cancelled'], ['order' => 'updatedAt', 'limit' => 30], self::sys());
        $L = [];
        foreach ($rows as $o) {
            $at = strtotime((string) ($o['updatedAt'] ?? '')) ?: 0; if ($at && $at < $since) { continue; }
            $L[] = '• #' . self::num($o) . ' ' . ((string) ($o['clientName'] ?? '') ?: (string) ($o['clientPhone'] ?? '—')) . ' — ' . ((string) ($o['cancelReason'] ?? '') ?: 'لسه ما قالش السبب');
        }
        return $L ? "❌ *الملغي آخر 24 ساعة (" . count($L) . ")*\n" . implode("\n", $L) . "\n\nترجّع واحد: *رجع 7001*" : '✅ مفيش طلبات ملغية آخر 24 ساعة.';
    }

    /* ------------------------------------------------------------------ the order moves: the owner hears, the customer is asked */

    public static function on_status($doc, $old, $p = null) {
        try {
            if (!self::enabled() || !is_array($doc) || empty($doc['id'])) { return false; }
            $st = (string) ($doc['status'] ?? ''); $was = (string) ($old['status'] ?? '');
            if ($st === $was) { return false; }
            $n = self::num($doc); $tech = (string) ($doc['technicianName'] ?? '');
            $who = ((string) ($doc['clientName'] ?? '') ?: (string) ($doc['clientPhone'] ?? ''));
            $phone = (string) ($doc['clientPhone'] ?? '');
            switch ($st) {
                case 'accepted':
                case 'en_route':
                    if ($phone !== '') { $j = self::current($phone); if (!$j || $j['o'] === $doc['id']) { self::put($phone, $doc['id'], $j && $j['s'] === 'confirm' ? 'confirm' : 'live'); } }
                    if ($st === 'en_route') { self::tell_room('🚗 الفني ' . $tech . ' اتحرك لطلب #' . $n . ' — ' . $who, 'jr-go-' . $doc['id'], $doc); }
                    return true;
                case 'arrived':
                    self::tell_room('🏠 *الفني ' . $tech . ' وصل* لطلب #' . $n . ' — ' . $who . (!empty($doc['address']) ? "\n📍 " . mb_substr((string) $doc['address'], 0, 100) : ''), 'jr-arr-' . $doc['id'], $doc);
                    return true;
                case 'completed':
                    $price = class_exists('Mutqan_Sales') ? (int) Mutqan_Sales::amount($doc) : 0;
                    self::tell_room('✅ *تمت المهمة* — طلب #' . $n . "\n👤 " . $who . ($tech !== '' ? "\n👷 الفني: " . $tech : '') . ($price > 0 ? "\n💰 " . $price . ' ر.س' : '') . "\n⭐ تقييم العميل هيوصلك هنا أول ما يرد.", 'jr-done-' . $doc['id'], $doc);
                    if ($phone !== '') {
                        self::put($phone, $doc['id'], 'rate');
                        self::tell_customer($doc, "🙏 شكرًا لثقتك" . (self::site_name($doc) !== '' ? ' في *' . self::site_name($doc) . '*' : '') . ".\nقيّم الخدمة والفني" . ($tech !== '' ? ' ' . $tech : '') . " برقم من *1* لـ *5* ⭐ (5 = ممتازة)، ورأيك بيوصل للمدير مباشرة.");
                    }
                    return true;
                case 'cancelled':
                    $byCustomer = is_array($p) && class_exists('Mutqan_Policy') && !Mutqan_Policy::is_staff($p);
                    // The customer asked first (on WhatsApp or in the app) and the room confirmed: it is still his cancellation — ask him why.
                    if (!$byCustomer && !empty($doc['cancelRequest']['at']) && time() - (strtotime((string) $doc['cancelRequest']['at']) ?: 0) < 2 * 86400) { $byCustomer = true; }
                    if (!empty($doc['technicianId']) && in_array($was, ['assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress'], true) && class_exists('Mutqan_OrderBot')) {
                        Mutqan_OrderBot::tell_tech($doc, '❌ طلب #' . $n . ' اتلغى' . (in_array($was, ['en_route', 'nearby'], true) ? ' — ارجع، مش محتاج تكمّل الطريق' : '') . '. شكرًا ليك 🙏');
                    }
                    self::tell_room('❌ اتلغى طلب #' . $n . ' — ' . $who . ($byCustomer ? "\n🔁 العميل لغاه بنفسه — بسأله عن السبب وبحاول أرجّعه، وهبلغك بالنتيجة." : ''), 'jr-x-' . $doc['id'], $doc);
                    if ($phone !== '' && $byCustomer) { self::winback_start($doc); }
                    elseif ($phone !== '') { $j = self::current($phone); if ($j && $j['o'] === $doc['id']) { self::drop($phone); } }
                    return true;
            }
        } catch (Throwable $e) { error_log('[mutqan] journey status ' . $e->getMessage()); }
        return false;
    }

    /** A technician was given the job (auto or by hand): the room hears who and the customer's journey goes live. */
    public static function on_assigned($doc) {
        try {
            if (!self::enabled() || empty($doc['id']) || empty($doc['technicianId'])) { return false; }
            $t = Mutqan_Store::get_raw('tech_profiles', (string) $doc['technicianId']) ?: [];
            $noPhone = trim((string) ($t['phone'] ?? ($doc['technicianPhone'] ?? ''))) === '';
            $head = '📤 طلب #' . self::num($doc) . ' اتسند للفني ' . (string) ($doc['technicianName'] ?? ($t['displayName'] ?? ''));
            if ($noPhone || !empty($t['isSite'])) {
                // Nobody's phone will ring for this one: the room gets the whole job card to review and pass on by hand.
                $card = class_exists('Mutqan_WA_Desk') ? Mutqan_WA_Desk::job_card($doc) : '';
                $card = preg_replace('/\n*رد بكلمة:.*$/su', '', (string) $card);
                $why = !empty($t['isSite']) ? '«فني الموقع» حساب عام' : 'الفني ده ما لوش رقم واتساب في ملفه';
                self::tell_room("⚠️ *راجع وابعت بنفسك* — " . $why . "\n" . $head . "\n\n" . $card . (empty($doc['clientPhone']) ? "\n\n📵 الطلب من غير رقم عميل — كلّمه من بيانات الطلب في التطبيق." : '') . "\n\nوزّعه على فني: *اسند طلب " . self::num($doc) . " لمحمود*", 'jr-as-' . $doc['id'] . '-' . $doc['technicianId'], $doc);
                return true;
            }
            self::tell_room($head . (!empty($doc['customerConfirmed']) ? '' : "\n⏳ العميل لسه ما أكدش على واتساب"), 'jr-as-' . $doc['id'] . '-' . $doc['technicianId'], $doc);
            return true;
        } catch (Throwable $e) { error_log('[mutqan] journey assigned ' . $e->getMessage()); return false; }
    }

    /** The technician's estimate goes back to the customer on WhatsApp too (the app chat already has it). */
    public static function on_eta($orderId, $minutes) {
        if (class_exists('Mutqan_Hub') && Mutqan_Hub::on()) { $o = Mutqan_Store::get_raw('orders', (string) $orderId); return $o ? Mutqan_Hub::event('order.eta', $o + ['id' => (string) $orderId], ['minutes' => (int) $minutes]) : false; }
        if (!self::enabled()) { return false; }
        $o = Mutqan_Store::get_raw('orders', (string) $orderId);
        if (!$o || empty($o['clientPhone'])) { return false; }
        $j = self::current((string) $o['clientPhone']);
        if (!$j || $j['o'] !== (string) $orderId) { return false; }
        $o['id'] = (string) $orderId;
        return self::tell_customer($o, '🚗 الفني ' . (string) ($o['technicianName'] ?? '') . ' رد: هيوصل خلال حوالي ' . (int) $minutes . ' دقيقة.');
    }
}
