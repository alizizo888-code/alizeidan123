<?php
if (!defined('ABSPATH')) { exit; }

/**
 * The WhatsApp control desk: one number, three different people on the other end.
 *
 * Until now every inbound WhatsApp message met the reception bot, which only knows how to talk to a
 * customer. A technician writing "وصلت" got a price list, and the operations supervisor asking for a
 * report got nothing at all. Who is writing decides what happens:
 *
 *   a technician   -> job updates. "وصلت" moves the order, by rule, with no AI and no cost.
 *   the team       -> reports and dispatch orders in plain words.
 *   anyone else    -> the reception bot, exactly as before.
 *
 * Only a sentence none of the rules recognise reaches the model, so the common traffic is free and
 * instant and keeps working when the model does not.
 */
final class Mutqan_WA_Desk {

    /** The words a technician actually types, and where each one puts the order. */
    const STEPS = [
        'accepted'    => ['قبلت', 'موافق', 'تمام ماشي', 'استلمت الطلب', 'استلمت'],
        'en_route'    => ['في الطريق', 'فى الطريق', 'طالع', 'متوجه', 'خرجت', 'راكب'],
        'nearby'      => ['قربت', 'قريب', 'وصلت الحي', 'هوصل'],
        'arrived'     => ['وصلت', 'انا عند العميل', 'عند العميل', 'نزلت'],
        'in_progress' => ['بدأت العمل', 'بديت', 'بدأت', 'شغال', 'تحت الشغل'],
        'completed'   => ['تم إنجاز المهمة', 'تم الانجاز', 'خلصت', 'انتهيت', 'تم التنفيذ', 'المهمة تمت'],
    ];

    /** What the technician hears back. Short: they are on a ladder, not reading their phone. */
    const SAID = [
        'accepted' => '✅ تم استلام الطلب',
        'en_route' => '🚗 سجّلنا إنك في الطريق',
        'nearby' => '📍 قرّبت — تمام',
        'arrived' => '✅ وصولك مسجّل',
        'in_progress' => '🔧 الشغل بدأ',
        'completed' => '✅ تم إنجاز المهمة — شكرًا',
    ];

    const OPEN = ['assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress'];

    private static function sys() { return Mutqan_WA::sys(); }
    private static function key($phone) { return class_exists('Mutqan_Auth') ? Mutqan_Auth::phone_key($phone) : substr(preg_replace('/\D/', '', (string) $phone), -9); }

    /* ---------------------------------------------------------------- who is writing */

    /**
     * The number decides. A technician is matched against tech_profiles, the team against their own
     * accounts; anything else is a customer, which is the safe default — a stranger must never be
     * able to move somebody's order by typing "وصلت".
     */
    public static function identify($phone) {
        $k = self::key($phone);
        if (strlen($k) < 8) { return ['kind' => 'customer']; }
        foreach (Mutqan_Store::select('tech_profiles', [], ['limit' => 400], self::sys()) as $t) {
            if (!empty($t['phone']) && self::key($t['phone']) === $k) {
                return ['kind' => 'tech', 'id' => (string) ($t['id'] ?? ''), 'name' => self::tech_name($t), 'doc' => $t];
            }
        }
        global $wpdb;
        $rows = (array) $wpdb->get_results('SELECT id, display_name, phone, role FROM ' . Mutqan_Store::table('users')
            . " WHERE phone<>'' AND role IS NOT NULL AND role<>'' AND status='active'", ARRAY_A);
        foreach ($rows as $r) {
            if (self::key($r['phone']) === $k) {
                return ['kind' => 'staff', 'id' => (string) $r['id'], 'name' => (string) ($r['display_name'] ?: 'الإدارة'), 'role' => (string) $r['role']];
            }
        }
        return ['kind' => 'customer'];
    }

    /* ---------------------------------------------------------------- inbound */

    /** True when this message was ours to answer, so the reception bot is left out of it. */
    public static function inbound($acct, $phone, $text, $tid, $th) {
        $who = self::identify($phone);
        if ($who['kind'] === 'customer') { return false; }
        $text = trim((string) $text);
        try {
            $reply = $who['kind'] === 'tech' ? self::technician($who, $text) : self::management($who, $text);
        } catch (Throwable $e) {
            error_log('[mutqan] wa-desk ' . $e->getMessage());
            $reply = '⚠️ حصل خطأ عند تنفيذ الطلب. جرّب تاني أو افتح التطبيق.';
        }
        if ($reply === '') { return true; }
        try { Mutqan_WA::send($acct, $phone, $reply, 'desk'); } catch (Throwable $e) { error_log('[mutqan] wa-desk send ' . $e->getMessage()); }
        return true;
    }

    /**
     * One spelling for comparing Arabic: no vowel marks, and the letters people type either way
     * (أ إ آ / ة / ى) folded together, so «بدأت» and «بدات» are the same word.
     */
    private static function fold($s) {
        $s = preg_replace('/[\x{0617}-\x{061A}\x{064B}-\x{0652}\x{0640}]/u', '', (string) $s);
        $s = str_replace(['أ', 'إ', 'آ', 'ة', 'ى'], ['ا', 'ا', 'ا', 'ه', 'ي'], $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** Which step a sentence means, or '' when it is not one of them. */
    public static function step_of($text) {
        $t = self::fold(mb_strtolower(trim((string) $text)));
        if ($t === '') { return ''; }
        $best = ''; $len = 0;
        foreach (self::STEPS as $status => $words) {
            foreach ($words as $w) {
                $n = self::fold($w);
                // The whole message, or the sentence it opens with — never a word buried in a story.
                if ($t === $n || strpos($t, $n . ' ') === 0) { if (mb_strlen($n) > $len) { $best = $status; $len = mb_strlen($n); } }
            }
        }
        return $best;
    }

    /* ---------------------------------------------------------------- the technician */

    private static function technician($who, $text) {
        $jobs = self::jobs_of($who['id']);
        $step = self::step_of($text);
        if ($step === '') { return self::tech_help($who, $jobs, $text); }
        if (!$jobs) { return 'مفيش طلب مفتوح عندك دلوقتي. استنى الإسناد من غرفة العمليات.'; }
        // Several open jobs and a bare "وصلت" is ambiguous, and guessing moves the wrong one.
        if (count($jobs) > 1 && !preg_match('/\d{3,}/', $text)) {
            $list = array_map(function ($o) { return '• ' . self::num($o) . ' — ' . self::what($o); }, array_slice($jobs, 0, 6));
            return "عندك " . count($jobs) . " طلبات مفتوحة. ابعت رقم الطلب مع الكلمة، مثال: *وصلت " . self::num($jobs[0]) . "*\n" . implode("\n", $list);
        }
        $order = $jobs[0];
        if (preg_match('/(\d{3,})/u', $text, $m)) {
            foreach ($jobs as $o) { if (strpos(self::num($o), $m[1]) !== false) { $order = $o; break; } }
        }
        self::set_status($order, $step, $who['name']);
        $said = self::SAID[$step] . ' — طلب ' . self::num($order);
        if ($step === 'completed') { $said .= "\nلو فيه قطع غيار أو ملاحظة، ابعتها دلوقتي وهتتسجل على الطلب."; }
        return $said;
    }

    private static function tech_help($who, $jobs, $text) {
        if ($text !== '' && $jobs) {   // free text on an open job is a note, not chatter
            self::note($jobs[0], $who['name'] . ': ' . mb_substr($text, 0, 300));
            return '📝 تم تسجيل ملاحظتك على طلب ' . self::num($jobs[0]) . '.';
        }
        $lines = ['*أهلًا ' . $who['name'] . '*'];
        if ($jobs) {
            $lines[] = 'طلباتك المفتوحة:';
            foreach (array_slice($jobs, 0, 6) as $o) { $lines[] = '📋 ' . self::num($o) . ' — ' . self::what($o) . ' — _' . self::status_label($o) . '_'; }
        } else { $lines[] = 'مفيش طلبات مفتوحة عندك دلوقتي.'; }
        $lines[] = '';
        $lines[] = 'اكتب: *قبلت* · *في الطريق* · *قربت* · *وصلت* · *بدأت العمل* · *تم إنجاز المهمة*';
        return implode("\n", $lines);
    }

    /* ---------------------------------------------------------------- the operations room */

    private static function management($who, $text) {
        $t = trim($text);
        if ($t === '' || preg_match('/^(مساعدة|الاوامر|الأوامر|help)$/u', $t)) { return self::ops_help(); }
        if (preg_match('/(تقرير|الحالة|الوضع|ملخص)/u', $t)) { return self::report(); }
        if (preg_match('/(حالة\s*الفنيين|الفنيين|الفنيه|الفنية)/u', $t)) { return self::techs_report(); }
        if (preg_match('/(الطلبات|المعلقة|المعلقه|المفتوحة|المفتوحه)/u', $t)) { return self::orders_report(); }
        if (preg_match('/(?:وجه|وجّه|اسند|أسند|حوّل|حول)\s*(?:الطلب\s*)?#?(\d{3,})\s*(?:الى|إلى|ل|لـ)?\s*(.+)$/u', $t, $m)) {
            return self::assign_by_name(trim($m[1]), trim($m[2]), $who);
        }
        if (preg_match('/(?:اسحب|ارجع|فك)\s*(?:الطلب\s*)?#?(\d{3,})/u', $t, $m)) { return self::unassign(trim($m[1]), $who); }
        // Everything the app's internal engine knows (cache, money, alerts, where the technicians are…) works from WhatsApp too.
        if (class_exists('Mutqan_Quick')) {
            $p = ['id' => (string) ($who['id'] ?? ''), 'role' => (string) ($who['role'] ?? ''), 'kind' => 'user', 'powers' => ['read', 'write', 'manage'], 'name' => (string) ($who['name'] ?? '')];
            $q = Mutqan_Quick::try_run($t, $p, 'agent');
            if ($q) { return (string) $q['text'] . (!empty($q['pending']) ? "\n(الأمر ده محتاج تأكيد من التطبيق: المساعد ← أوامر)" : ''); }
        }
        return self::ops_help();
    }

    private static function ops_help() {
        return "*أوامر غرفة العمليات*\n"
            . "📋 *تقرير* — ملخص اليوم\n"
            . "👷 *الفنيين* — مين متاح ومين في الموقع\n"
            . "🗂 *الطلبات* — المفتوح والمعلّق\n"
            . "➡️ *وجّه 1234 لوليد* — إسناد طلب لفني\n"
            . "↩️ *اسحب 1234* — سحب الطلب من الفني\n"
            . "⚡ *التنبيهات* · *فين الفنيين* · *فلوس النهارده* · *طلب 1234* · *نضف الكاش* — بتتنفذ فورًا";
    }

    public static function report() {
        $all = Mutqan_Store::select('orders', [], ['limit' => 400, 'order' => 'desc'], self::sys());
        $today = substr(gmdate('c'), 0, 10);
        $n = ['new' => 0, 'open' => 0, 'done' => 0, 'today' => 0];
        foreach ($all as $o) {
            $s = (string) ($o['status'] ?? '');
            if (substr((string) ($o['createdAt'] ?? ''), 0, 10) === $today) { $n['today']++; }
            if ($s === 'new' || $s === 'forced') { $n['new']++; }
            elseif (in_array($s, self::OPEN, true)) { $n['open']++; }
            elseif ($s === 'completed') { $n['done']++; }
        }
        return "*📋 تقرير العمليات*\n"
            . '🆕 جديدة بانتظار الإسناد: *' . $n['new'] . "*\n"
            . '🔧 شغالة مع فنيين: *' . $n['open'] . "*\n"
            . '✅ منجزة: *' . $n['done'] . "*\n"
            . '📅 طلبات النهاردة: *' . $n['today'] . '*';
    }

    public static function techs_report() {
        $techs = Mutqan_Store::select('tech_profiles', [], ['limit' => 200], self::sys());
        if (!$techs) { return 'مفيش فنيين مسجّلين لسه.'; }
        $lines = ['*👷 حالة الفنيين*'];
        foreach ($techs as $t) {
            $jobs = self::jobs_of((string) ($t['id'] ?? ''));
            $busy = count($jobs);
            $off = isset($t['available']) && !$t['available'];
            $dot = $off ? '⚫' : ($busy ? '🔴' : '🟢');
            $state = $off ? 'غير متاح' : ($busy ? 'في الموقع (' . $busy . ')' : 'متاح');
            $lines[] = $dot . ' ' . self::tech_name($t) . ': ' . $state;
        }
        return implode("\n", $lines);
    }

    public static function orders_report() {
        $rows = Mutqan_Store::select('orders', ['status' => ['in' => array_merge(['new', 'forced'], self::OPEN)]], ['limit' => 40, 'order' => 'desc'], self::sys());
        if (!$rows) { return 'مفيش طلبات مفتوحة. 👍'; }
        $lines = ['*🗂 الطلبات المفتوحة (' . count($rows) . ')*'];
        foreach (array_slice($rows, 0, 15) as $o) {
            $lines[] = '• ' . self::num($o) . ' — ' . self::what($o) . ' — _' . self::status_label($o) . '_' . (empty($o['technicianId']) ? ' ⚠️ بدون فني' : '');
        }
        return implode("\n", $lines);
    }

    private static function assign_by_name($num, $name, $who) {
        $order = self::find_order($num);
        if (!$order) { return '❌ مالقيتش طلب رقمه ' . $num . '.'; }
        $tech = null;
        foreach (Mutqan_Store::select('tech_profiles', [], ['limit' => 300], self::sys()) as $t) {
            if ($name !== '' && mb_strpos(self::tech_name($t), $name) !== false) { $tech = $t; break; }
        }
        if (!$tech) { return '❌ مالقيتش فني اسمه «' . $name . '». اكتب *الفنيين* تشوف الأسماء.'; }
        // The store sends the job card itself the moment the order changes hands, so dispatching it
        // here too would reach the technician twice.
        Mutqan_Store::update('orders', (string) $order['id'], ['technicianId' => (string) $tech['id'], 'status' => 'assigned', 'assignedAt' => gmdate('c')], self::sys());
        $has = trim((string) ($tech['phone'] ?? '')) !== '';
        return '✅ طلب ' . self::num($order) . ' اتوجّه لـ *' . self::tech_name($tech) . '*' . ($has ? ' واتبعتله على الواتساب.' : ' (متبعتش واتساب — الرقم مش مسجّل).');
    }

    private static function unassign($num, $who) {
        $order = self::find_order($num);
        if (!$order) { return '❌ مالقيتش طلب رقمه ' . $num . '.'; }
        Mutqan_Store::update('orders', (string) $order['id'], ['technicianId' => null, 'status' => 'new'], self::sys());
        return '↩️ طلب ' . self::num($order) . ' اتسحب ورجع لغرفة العمليات.';
    }

    /* ---------------------------------------------------------------- outbound: the job card */

    /** The message a technician gets when a job becomes theirs. Everything they need to start moving. */
    public static function job_card($order) {
        $l = ['*🔧 طلب جديد ليك*', '📋 رقم الطلب: *' . self::num($order) . '*'];
        if (!empty($order['clientName'])) { $l[] = '👤 العميل: ' . $order['clientName']; }
        if (!empty($order['clientPhone'])) { $l[] = '📱 الجوال: ' . $order['clientPhone']; }
        $l[] = '🔧 المطلوب: ' . self::what($order);
        if (!empty($order['address'])) { $l[] = '🏠 العنوان: ' . $order['address']; }
        if (isset($order['lat'], $order['lng']) && is_numeric($order['lat']) && is_numeric($order['lng'])) {
            $l[] = '📍 الموقع: https://maps.google.com/?q=' . $order['lat'] . ',' . $order['lng'];
        }
        if (!empty($order['appointmentAt'])) { $l[] = '🕐 الموعد: ' . $order['appointmentAt']; }
        if (!empty($order['note'])) { $l[] = '📝 ' . mb_substr((string) $order['note'], 0, 200); }
        $l[] = '';
        $l[] = 'رد بكلمة: *قبلت* لما تستلم، وبعدين *في الطريق* · *وصلت* · *بدأت العمل* · *تم إنجاز المهمة*';
        return implode("\n", $l);
    }

    /** Sends that card. False when the technician has no number on file — never an exception. */
    public static function dispatch($order, $tech) {
        $phone = (string) ($tech['phone'] ?? '');
        if ($phone === '') { return false; }
        try { return (bool) Mutqan_WA::auto_send($phone, self::job_card($order), 'dispatch'); }
        catch (Throwable $e) { error_log('[mutqan] wa-desk dispatch ' . $e->getMessage()); return false; }
    }

    /** Called when an order is assigned anywhere in the system. */
    public static function on_assigned($order, $techId) {
        if (!$techId) { return false; }
        $t = Mutqan_Store::get_raw('tech_profiles', (string) $techId);
        return $t ? self::dispatch($order, $t) : false;
    }

    /* ---------------------------------------------------------------- the model, for anything else */

    /**
     * Applies and removes a trailing {"action": ...} block.
     *
     * The model is told to end with one when a status should move. It is stripped before the message
     * is sent: the person on the other end should never see the machinery.
     */
    public static function apply_action($text) {
        if (!preg_match('/\{[^{}]*"action"\s*:\s*"update_status".*\}\s*$/su', $text, $m)) { return [$text, null]; }
        $json = json_decode($m[0], true);
        $clean = trim(str_replace($m[0], '', $text));
        if (!is_array($json) || empty($json['order_id']) || empty($json['new_status'])) { return [$clean, null]; }
        $status = (string) $json['new_status'];
        if (!in_array($status, array_keys(self::STEPS), true)) { return [$clean, null]; }
        $order = self::find_order((string) $json['order_id']);
        if (!$order) { return [$clean, null]; }
        self::set_status($order, $status, 'الوكيل');
        return [$clean, ['id' => $order['id'], 'status' => $status]];
    }

    /* ---------------------------------------------------------------- small shared pieces */

    private static function jobs_of($techId) {
        if ($techId === '') { return []; }
        return array_values(Mutqan_Store::select('orders', ['technicianId' => $techId, 'status' => ['in' => self::OPEN]], ['limit' => 20, 'order' => 'desc'], self::sys()));
    }
    private static function find_order($num) {
        $num = preg_replace('/\D/', '', (string) $num);
        if ($num === '') { return null; }
        foreach (Mutqan_Store::select('orders', [], ['limit' => 300, 'order' => 'desc'], self::sys()) as $o) {
            if (strpos(self::num($o), $num) !== false || (string) ($o['id'] ?? '') === $num) { return $o; }
        }
        return null;
    }
    private static function set_status($order, $status, $by) {
        Mutqan_Store::update('orders', (string) $order['id'], ['status' => $status], self::sys());
        if (class_exists('Mutqan_WA')) { Mutqan_WA::add_step((string) $order['id'], $status, self::SAID[$status] ?? $status, $by); }
    }
    private static function note($order, $text) {
        if (class_exists('Mutqan_WA')) { Mutqan_WA::add_step((string) $order['id'], 'note', $text, 'الفني'); }
    }
    private static function tech_name($t) { return (string) ($t['displayName'] ?? $t['name'] ?? 'فني'); }
    private static function num($o) { return (string) ($o['number'] ?? $o['ref'] ?? $o['id'] ?? '—'); }
    private static function what($o) {
        $s = (string) ($o['serviceLabel'] ?? $o['service'] ?? $o['specialtyLabel'] ?? '');
        return $s !== '' ? $s : 'صيانة';
    }
    const LABELS = ['new' => 'جديد', 'forced' => 'إجباري', 'assigned' => 'مُسند', 'accepted' => 'قَبِل الفني',
        'en_route' => 'في الطريق', 'nearby' => 'قرّب', 'arrived' => 'وصل', 'in_progress' => 'جاري العمل',
        'completed' => 'تم الإنجاز', 'cancelled' => 'ملغي'];
    private static function status_label($o) {
        $s = (string) ($o['status'] ?? '');
        return self::LABELS[$s] ?? $s;
    }
}
