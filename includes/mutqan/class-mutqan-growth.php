<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «جلب العملاء» (owner, 2026-11-23 — ideas 16–21). Each part has its own switch in the desk (الإدارة → جلب العملاء):
 *
 *  16 Google review — after a 5-star rating on WhatsApp the customer gets the owner's Google Maps review link.
 *     Off until the owner turns it on AND pastes the link.
 *  17 Seasonal reminder — N months after a finished job (4 by default) a customer who hasn't ordered since gets one
 *     friendly WhatsApp reminder; at most 20 a day so the number is never flagged.
 *  18 Win-back of a cancelled order — already done by the WhatsApp journey (Mutqan_WA_Journey::winback_start).
 *  19 Yearly maintenance contracts (companies, restaurants, buildings) — scheduled visits become orders by themselves.
 *  20 Referral — with the review message the customer gets a WhatsApp link to send a friend; when the friend orders,
 *     both are noted for the discount and the room is told.
 *  21 Returning customer greeting — already done by the WhatsApp hub (welcomeBack: name, last service, warranty).
 */
final class Mutqan_Growth {

    const OPT = 'mutqan_growth';
    const REF_OPT = 'mutqan_ref_pending';
    const DEFAULTS = [
        'reviewOn' => false, 'reviewUrl' => '',
        'seasonalOn' => false, 'seasonalMonths' => 4, 'seasonalOffer' => '', 'seasonalPerDay' => 20,
        'referralOn' => false, 'referralPct' => 10,
        'contractsOn' => true,
    ];

    private static function sys() { return ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']]; }

    public static function cfg() {
        $c = get_option(self::OPT, []);
        return array_merge(self::DEFAULTS, is_array($c) ? $c : []);
    }

    public static function save($in) {
        $c = self::cfg();
        foreach (['reviewOn', 'seasonalOn', 'referralOn', 'contractsOn'] as $k) { if (array_key_exists($k, $in)) { $c[$k] = (bool) $in[$k]; } }
        if (array_key_exists('reviewUrl', $in)) {
            $u = trim((string) $in['reviewUrl']);
            if ($u !== '' && !preg_match('#^https://#i', $u)) { throw new Mutqan_Error('bad_url', 'الرابط لازم يبدأ بـ https://', 400); }
            $c['reviewUrl'] = mb_substr($u, 0, 500);
        }
        if (array_key_exists('seasonalMonths', $in)) { $c['seasonalMonths'] = max(1, min(24, (int) $in['seasonalMonths'])); }
        if (array_key_exists('seasonalPerDay', $in)) { $c['seasonalPerDay'] = max(1, min(60, (int) $in['seasonalPerDay'])); }
        if (array_key_exists('seasonalOffer', $in)) { $c['seasonalOffer'] = mb_substr(trim((string) $in['seasonalOffer']), 0, 300); }
        if (array_key_exists('referralPct', $in)) { $c['referralPct'] = max(1, min(50, (int) $in['referralPct'])); }
        update_option(self::OPT, $c, false);
        return $c;
    }

    /** POST /growth — {op: get|set|contracts_run}. Staff read; the owner and operations (write power) change it. */
    public static function handle($in, $p) {
        if (!Mutqan_Policy::is_staff($p)) { throw new Mutqan_Error('forbidden', 'غير مسموح.', 403); }
        $op = (string) ($in['op'] ?? 'get');
        if ($op === 'get') { return self::cfg() + ['status' => self::status()]; }
        if (!Mutqan_Policy::has_power($p, 'write')) { throw new Mutqan_Error('forbidden', 'التعديل للمالك والعمليات.', 403); }
        if ($op === 'set') { return self::save((array) ($in['cfg'] ?? [])) + ['status' => self::status()]; }
        if ($op === 'contracts_run') { return ['made' => self::contracts_tick(true)]; }
        throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400);
    }

    /** What already runs on its own (18, 21) and the counters, for the desk page. */
    public static function status() {
        return [
            'winback' => class_exists('Mutqan_WA_Journey') && Mutqan_WA_Journey::enabled(),
            'returning' => class_exists('Mutqan_Hub') && Mutqan_Hub::on(),
            'seasonalSentToday' => (int) (get_option('mutqan_growth_day', [])['n'] ?? 0),
            'refPending' => count((array) get_option(self::REF_OPT, [])),
        ];
    }

    /* ------------------------------------------------------------------ 16 + 20: after a good rating */

    /** The customer's referral code: short, stable, not his number. */
    public static function ref_code($phone) {
        $d = substr(preg_replace('/\D/', '', (string) $phone), -9);
        return $d === '' ? '' : 'R' . strtoupper(substr(hash_hmac('sha256', $d, wp_salt('auth')), 0, 6));
    }

    private static function sales_number() {
        if (class_exists('Mutqan_Lines')) { foreach (Mutqan_Lines::pool('sales') as $r) { return preg_replace('/\D/', '', (string) $r['phone']); } }
        return '966549423050';
    }

    /** Extra lines for the thank-you after a rating (the hub calls this). '' when nothing is on. */
    public static function after_rating($o, $stars) {
        $c = self::cfg();
        $L = [];
        if ((int) $stars >= 5 && $c['reviewOn'] && $c['reviewUrl'] !== '') {
            $L[] = "⭐ لو تحب تساعدنا، قيّمنا على خرائط جوجل — دقيقة واحدة بتفرق معانا كتير:\n" . $c['reviewUrl'];
        }
        if ((int) $stars >= 4 && $c['referralOn'] && !empty($o['clientPhone'])) {
            $code = self::ref_code($o['clientPhone']);
            $text = rawurlencode('السلام عليكم، جاي بترشيح من صاحبي — كود الترشيح ' . $code);
            $L[] = "🎁 ابعت اللينك ده لحد تعرفه محتاج صيانة مكيفات — لو طلب، انت وهو تاخدوا خصم " . (int) $c['referralPct'] . "% على الطلب الجاي:\nhttps://wa.me/" . self::sales_number() . '?text=' . $text;
        }
        return implode("\n\n", $L);
    }

    /** A message on WhatsApp carried a referral code: remember who brought this number (30 days). */
    public static function note_referral($phone, $text) {
        if (!preg_match('/كود الترشيح\s*(R[0-9A-F]{6})/u', (string) $text, $m)) { return null; }
        $to = substr(preg_replace('/\D/', '', (string) $phone), -9);
        if ($to === '') { return null; }
        $map = (array) get_option(self::REF_OPT, []);
        $map[$to] = ['code' => $m[1], 'at' => time()];
        foreach ($map as $k => $v) { if (time() - (int) ($v['at'] ?? 0) > 30 * 86400) { unset($map[$k]); } }
        update_option(self::REF_OPT, array_slice($map, -500, null, true), false);
        return $m[1];
    }

    /** Who owns a code: the phone of a past customer whose code it is (searched in recent orders). */
    public static function referrer($code, $orders) {
        foreach ($orders as $o) { if (!empty($o['clientPhone']) && self::ref_code($o['clientPhone']) === $code) { return $o; } }
        return null;
    }

    /** Attach a pending referral to the new order of that number (run from the sweep). */
    public static function attach_referrals($orders) {
        $map = (array) get_option(self::REF_OPT, []);
        if (!$map) { return 0; }
        $n = 0; $c = self::cfg();
        foreach ($orders as $o) {
            if (!empty($o['referral']) || empty($o['clientPhone'])) { continue; }
            $k = substr(preg_replace('/\D/', '', (string) $o['clientPhone']), -9);
            if (!isset($map[$k])) { continue; }
            if (strtotime((string) ($o['createdAt'] ?? '')) < (int) $map[$k]['at'] - 3600) { continue; }   // an order made before he came through the link
            $code = (string) $map[$k]['code'];
            $by = self::referrer($code, $orders);
            if ($by && substr(preg_replace('/\D/', '', (string) $by['clientPhone']), -9) === $k) { unset($map[$k]); continue; }   // his own code
            Mutqan_Store::update('orders', $o['id'], ['referral' => ['code' => $code, 'byPhone' => (string) ($by['clientPhone'] ?? ''), 'byName' => (string) ($by['clientName'] ?? ''), 'pct' => (int) $c['referralPct'], 'at' => gmdate('c')]], self::sys());
            unset($map[$k]); $n++;
            if (class_exists('Mutqan_Rest')) {
                Mutqan_Rest::admin_alert('🎁 عميل جديد بترشيح #' . substr((string) $o['id'], -5), ((string) ($o['clientName'] ?? '') ?: (string) $o['clientPhone']) . ' جه بترشيح من ' . ((string) ($by['clientName'] ?? '') ?: (string) ($by['clientPhone'] ?? $code)) . ' — خصم ' . (int) $c['referralPct'] . '% للاتنين', '#/staff/order/' . $o['id'], 'order_referral', 'normal', $o['id']);
            }
        }
        update_option(self::REF_OPT, $map, false);
        return $n;
    }

    /* ------------------------------------------------------------------ 17: seasonal reminder */

    private static function ts($iso) { $t = strtotime((string) $iso); return $t ?: 0; }

    /** Finished orders whose customer is due a reminder now (pure, for the tests). */
    public static function seasonal_due($orders, $now = null, $months = 4) {
        $now = $now ?? time();
        $from = $now - ($months * 30 + 10) * 86400; $to = $now - $months * 30 * 86400;
        $latest = [];
        foreach ($orders as $o) {
            $k = substr(preg_replace('/\D/', '', (string) ($o['clientPhone'] ?? '')), -9);
            if ($k !== '') { $latest[$k] = max($latest[$k] ?? 0, self::ts($o['createdAt'] ?? '')); }
        }
        $out = [];
        foreach ($orders as $o) {
            if (!in_array($o['status'] ?? '', ['completed', 'closed'], true) || !empty($o['growth']['seasonalAt'])) { continue; }
            $k = substr(preg_replace('/\D/', '', (string) ($o['clientPhone'] ?? '')), -9);
            if ($k === '') { continue; }
            $done = self::ts($o['completedAt'] ?? '') ?: self::ts($o['updatedAt'] ?? '');
            if ($done < $from || $done > $to) { continue; }
            if (($latest[$k] ?? 0) > self::ts($o['createdAt'] ?? '')) { continue; }   // he ordered again since
            $out[$k] = $o;   // one per customer
        }
        return array_values($out);
    }

    public static function seasonal_text($o, $c) {
        $name = trim((string) ($o['clientName'] ?? ''));
        return '👋 أهلًا' . ($name !== '' && $name !== 'عميل' ? ' ' . $name : '') . "\n"
            . 'عدّى حوالي ' . (int) $c['seasonalMonths'] . " شهور على آخر خدمة لمكيفاتك معانا 🌬️\n"
            . "الغسيل الدوري بيخلّي المكيف أبرد وأوفر في الكهربا ويطوّل عمره."
            . ($c['seasonalOffer'] !== '' ? "\n🎁 " . $c['seasonalOffer'] : '')
            . "\n\nتحب نحجزلك؟ رد بكلمة *احجز* ونرتبلك أقرب موعد.";
    }

    public static function seasonal_tick($now = null) {
        $c = self::cfg();
        if (!$c['seasonalOn'] || !class_exists('Mutqan_WA')) { return 0; }
        $day = gmdate('Y-m-d', ($now ?? time()) + 3 * 3600);
        $st = (array) get_option('mutqan_growth_day', []);
        $sent = ($st['d'] ?? '') === $day ? (int) ($st['n'] ?? 0) : 0;
        if ($sent >= (int) $c['seasonalPerDay']) { return 0; }
        $all = Mutqan_Store::select('orders', [], ['limit' => 1000, 'order' => '-createdAt'], self::sys());
        $n = 0;
        foreach (self::seasonal_due($all, $now, (int) $c['seasonalMonths']) as $o) {
            if ($sent + $n >= (int) $c['seasonalPerDay']) { break; }
            $ok = Mutqan_WA::auto_send((string) $o['clientPhone'], self::seasonal_text($o, $c), 'reminder');
            Mutqan_Store::update('orders', $o['id'], ['growth' => array_merge((array) ($o['growth'] ?? []), ['seasonalAt' => gmdate('c'), 'seasonalSent' => (bool) $ok])], self::sys());
            if ($ok) { $n++; }
        }
        update_option('mutqan_growth_day', ['d' => $day, 'n' => $sent + $n], false);
        return $n;
    }

    /* ------------------------------------------------------------------ 19: yearly maintenance contracts */

    /** The visit dates of a contract: start + k × (12 / visits) months. */
    public static function next_visit($contract, $after = null) {
        $start = self::ts($contract['startDate'] ?? '');
        $visits = max(1, min(24, (int) ($contract['visitsPerYear'] ?? 4)));
        if (!$start) { return 0; }
        $step = (int) round(12 / $visits);
        $after = $after ?? 0;
        for ($k = 0; $k < $visits * 5; $k++) {
            $t = strtotime('+' . ($k * $step) . ' months', $start);
            if ($t > $after) { return $t; }
        }
        return 0;
    }

    /** Due visits (within the next day) become orders, once each. Returns how many it made. */
    public static function contracts_tick($force = false) {
        $c = self::cfg();
        if (!$c['contractsOn'] && !$force) { return 0; }
        $sys = self::sys();
        $rows = Mutqan_Store::select('contracts', [], ['limit' => 300], $sys);
        $made = 0;
        foreach ($rows as $k) {
            if (($k['active'] ?? true) === false) { continue; }
            $end = self::ts($k['endDate'] ?? '');
            $next = self::ts($k['nextVisitAt'] ?? '') ?: self::next_visit($k, 0);
            if (!$next || ($end && $next > $end)) { continue; }
            if ($next - time() > 86400) { if (empty($k['nextVisitAt'])) { Mutqan_Store::update('contracts', $k['id'], ['nextVisitAt' => gmdate('c', $next)], $sys); } continue; }
            $done = (int) ($k['visitsMade'] ?? 0) + 1;
            $order = Mutqan_Store::insert('orders', [
                'status' => 'new', 'source' => 'contract', 'contractId' => $k['id'],
                'clientName' => (string) ($k['company'] ?? ($k['contactName'] ?? 'عقد صيانة')), 'clientPhone' => (string) ($k['phone'] ?? ''),
                'address' => (string) ($k['address'] ?? ''), 'lat' => $k['lat'] ?? null, 'lng' => $k['lng'] ?? null,
                'specialtyId' => (string) ($k['specialtyId'] ?? 'ac'), 'units' => (int) ($k['units'] ?? 0),
                'note' => 'زيارة عقد صيانة دورية (' . $done . ' من ' . (int) ($k['visitsPerYear'] ?? 4) . ' في السنة)' . (!empty($k['notes']) ? ' — ' . $k['notes'] : ''),
                'appointmentAt' => gmdate('c', $next), 'preferredTime' => wp_date('Y-m-d', $next), 'customerConfirmed' => true, 'confirmedVia' => 'contract',
                'price' => 0, 'createdAt' => gmdate('c'),
            ], null, ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'], $sys);
            $after = self::next_visit($k, $next);
            Mutqan_Store::update('contracts', $k['id'], ['nextVisitAt' => $after ? gmdate('c', $after) : null, 'visitsMade' => $done, 'lastOrderId' => (string) ($order['id'] ?? ''), 'lastVisitAt' => gmdate('c', $next)], $sys);
            if (class_exists('Mutqan_Rest')) { Mutqan_Rest::admin_alert('📑 زيارة عقد صيانة اتسجلت طلب', ((string) ($k['company'] ?? '')) . ' — ' . wp_date('Y-m-d', $next), '#/staff/order/' . ($order['id'] ?? ''), 'contract_visit', 'normal', $order['id'] ?? null); }
            $made++;
        }
        return $made;
    }

    /** Once an hour at most (from the 5-minute reminder hook): contracts, then the seasonal reminders. */
    public static function tick() {
        if (get_transient('mutqan_growth_lock')) { return; }
        set_transient('mutqan_growth_lock', 1, 3600);
        try { self::contracts_tick(); } catch (Throwable $e) { error_log('[mutqan] contracts ' . $e->getMessage()); }
        try { self::seasonal_tick(); } catch (Throwable $e) { error_log('[mutqan] seasonal ' . $e->getMessage()); }
    }

    public static function boot() { add_action('mutqan_remind', [__CLASS__, 'tick'], 25); }
}
