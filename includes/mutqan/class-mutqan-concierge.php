<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «المستقبِل الموحّد» — one customer brain behind WhatsApp and the website chat (owner, 2026-11-20).
 *
 * The same customer asks the same thing on WhatsApp and on the site, and must get the same answer: the same price
 * from the same table, the same offers, the same order with the same technician and status, the same «where is he»,
 * and the same way of cancelling or moving it. So both doors call this class:
 *
 *   answer()  — what the system can say by itself, without a model: an order question (status, «الفني فين», cancel,
 *               move, a message for the technician), an order by its number, payment / warranty / offers. WhatsApp
 *               calls it before its model; the website chat calls POST /concierge before its model.
 *   context() — what the model is given on both doors for everything else: who the customer is, his orders, the
 *               official price of what he is asking about, the live offers, and the services that match his words.
 *
 * Who may see what: on WhatsApp the number is the customer's own (WhatsApp proved it). On the site a signed-in
 * customer is himself; a guest who typed a phone number only sees an order when he also gives that order's number,
 * and a guest never cancels anything.
 */
final class Mutqan_Concierge {

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'المساعد الذكي']; }
    private static function key($phone) { $d = preg_replace('/\D/', '', (string) $phone); return strlen($d) >= 9 ? substr($d, -9) : ''; }
    private static function num($o) { return (string) ($o['number'] ?? '') ?: substr((string) ($o['id'] ?? ''), -5); }

    /** The phone on a signed-in customer's account ('' for guests). */
    public static function phone_of($p) {
        if (($p['kind'] ?? '') !== 'user' || empty($p['id']) || !class_exists('Mutqan_Auth')) { return ''; }
        $u = Mutqan_Auth::user_row((string) $p['id']);
        return (string) ($u['phone'] ?? '');
    }

    /** His orders, newest first: by phone (last nine digits) or by account. */
    public static function orders_for($phone, $userId = '', $limit = 5) {
        $k = self::key($phone);
        if ($k === '' && $userId === '') { return []; }
        try { $rows = Mutqan_Store::select('orders', [], ['limit' => 400, 'order' => '-createdAt'], self::sys()); } catch (Throwable $e) { return []; }
        $out = [];
        foreach ($rows as $o) {
            $mine = ($k !== '' && self::key($o['clientPhone'] ?? '') === $k) || ($userId !== '' && (string) ($o['clientId'] ?? '') === $userId);
            if ($mine) { $out[] = $o; if (count($out) >= $limit) { break; } }
        }
        return $out;
    }

    /** «طلب 1024», «#1024», «رقم الطلب 1024», a bare 3–7 digit number next to «طلب». */
    public static function ref_in($text) {
        $n = class_exists('Mutqan_Quick') ? Mutqan_Quick::norm($text) : (string) $text;
        if (preg_match('/(طلب|الطلب|رقم|order|#)\s*(رقم)?\s*#?\s*(\d{3,7})/u', $n, $m)) { return $m[3]; }
        return '';
    }

    public static function order_line($o) {
        $st = class_exists('Mutqan_Ops') ? (Mutqan_Ops::STATUS[$o['status'] ?? ''] ?? ($o['status'] ?? '')) : ($o['status'] ?? '');
        $svc = class_exists('Mutqan_Rest') ? Mutqan_Rest::spec_label($o['specialtyId'] ?? '') : '';
        $svc = $svc ?: (string) ($o['service'] ?? '');
        return '#' . self::num($o) . ' — ' . $svc . ' — ' . $st
            . (!empty($o['technicianName']) ? ' — الفني: ' . $o['technicianName'] : '')
            . (!empty($o['preferredTime']) ? ' — الموعد: ' . mb_substr((string) $o['preferredTime'], 0, 40) : '')
            . ((float) ($o['agreedPrice'] ?? 0) > 0 ? ' — السعر المتفق: ' . (int) $o['agreedPrice'] . ' ر.س' : '');
    }

    /** The active offers, one short line each. */
    public static function offers($max = 5) {
        try { $rows = Mutqan_Store::select('offers', [], ['limit' => 30], self::sys()); } catch (Throwable $e) { return []; }
        $L = [];
        foreach ($rows as $o) {
            if ((isset($o['enabled']) && empty($o['enabled'])) || (isset($o['active']) && $o['active'] === false)) { continue; }
            $L[] = trim((string) ($o['title'] ?? ($o['name'] ?? 'عرض')) . (!empty($o['description']) ? ' — ' . mb_substr((string) $o['description'], 0, 90) : '') . (is_numeric($o['price'] ?? null) ? ' (' . (int) $o['price'] . ' ر.س)' : ''));
            if (count($L) >= $max) { break; }
        }
        return $L;
    }

    /** Services whose name or words appear in the text (for «عندكم …؟»). */
    public static function services_in($text) {
        if (!class_exists('Mutqan_Rest')) { return []; }
        $n = class_exists('Mutqan_Quick') ? Mutqan_Quick::norm($text) : (string) $text;
        $out = [];
        $words = class_exists('Mutqan_Hub') && defined('Mutqan_Hub::SPEC_WORDS') ? Mutqan_Hub::SPEC_WORDS : [];
        foreach (Mutqan_Rest::SPEC_LABELS as $id => $label) {
            $hit = mb_strpos($n, Mutqan_Quick::norm($label)) !== false;
            foreach ((array) ($words[$id] ?? []) as $w) { if (!$hit && mb_strpos($n, $w) !== false) { $hit = true; } }
            if ($hit) { $out[$id] = $label; }
        }
        return $out;
    }

    /* ================================================================== the model's facts — the same on both doors */

    public static function context($text, $phone = '', $userId = '') {
        $L = ['## بيانات العميل والخدمات (نفس البيانات في الواتساب والموقع — جاوب منها بس)'];
        if (class_exists('Mutqan_Customers') && self::key($phone) !== '') { $b = trim((string) Mutqan_Customers::brief($phone)); if ($b !== '') { $L[] = '- العميل: ' . mb_substr($b, 0, 300); } }
        $orders = self::orders_for($phone, $userId, 5);
        if ($orders) { $L[] = '- طلباته عندنا:'; foreach ($orders as $o) { $L[] = '  • ' . self::order_line($o); } }
        elseif (self::key($phone) !== '' || $userId !== '') { $L[] = '- مالوش طلبات قبل كده (عميل جديد).'; }
        if (class_exists('Mutqan_Hub')) {
            $q = Mutqan_Hub::quote((string) $text);
            if ($q) {
                if (!empty($q['total']) && !empty($q['fixed'])) { $L[] = '- السعر الرسمي لـ«' . $q['label'] . '»: ' . (int) $q['total'] . ' ر.س (الفصال حسب تعليمات العقل التجاري).'; }
                elseif (!empty($q['from'])) { $L[] = '- «' . $q['label'] . '»: يبدأ من ' . (int) $q['from'] . ' ر.س — ' . (string) ($q['inspect'] ?? 'السعر النهائي بعد المعاينة') . ''; }
            }
        }
        if (class_exists('Mutqan_Hub') && preg_match('/(قطع|قطعه|مكثف|كباستور|بطاريه|فريون|كونتاكتور|شريحه|بورده|كرت|موتور|ماتور|ريش|مروحه)/u', Mutqan_Quick::norm($text))) { $L[] = '- ' . str_replace("\n", "\n  ", Mutqan_Hub::parts_text($text)); }
        $sv = self::services_in($text);
        if ($sv) { $L[] = '- خدمات بنقدمها وليها علاقة بكلامه: ' . implode('، ', array_values($sv)) . '.'; }
        $of = self::offers(4);
        if ($of) { $L[] = '- العروض الشغالة دلوقتي: ' . implode(' | ', $of) . '.'; }
        return count($L) > 1 ? implode("\n", $L) . "\n" : '';
    }

    /** For Mutqan_AI::run: customer features only, with the phone the caller passed (WhatsApp) or the account's (site). */
    public static function inject($prompt, $opts, $p) {
        $feature = (string) ($opts['feature'] ?? '');
        if (!in_array($feature, ['chat', 'site_chat', 'wa', 'wa_hub', 'wa_reception', 'negotiate', 'sales'], true)) { return $prompt; }
        if (class_exists('Mutqan_Policy') && Mutqan_Policy::is_staff($p) && ($p['id'] ?? '') !== 'system') { return $prompt; }
        $phone = (string) ($opts['sales']['phone'] ?? '');
        $uid = ($p['kind'] ?? '') === 'user' && ($p['id'] ?? '') !== 'system' ? (string) $p['id'] : '';
        // On the site a typed phone is not proof: only the signed-in account's own phone opens his orders.
        if ($feature === 'chat' || $feature === 'site_chat') { $phone = self::phone_of($p); }
        $last = '';
        foreach (preg_split('/\r?\n/u', (string) $prompt) as $l) { if (preg_match('/^\s*(العميل|user|العميل \(آخر رسالة\))\s*:\s*(.+)$/u', $l, $m)) { $last = trim($m[2]); } }
        try { $ctx = self::context($last, $phone, $uid); } catch (Throwable $e) { $ctx = ''; }
        return $ctx === '' ? $prompt : $ctx . "\n" . $prompt;
    }

    /* ================================================================== what the system answers by itself */

    /**
     * $who: ['channel' => 'wa'|'site', 'phone' => …, 'userId' => …, 'verified' => bool (the phone is really his)].
     * Returns ['reply', 'kind', 'order', 'links'] or null (then the model answers).
     */
    public static function answer($text, $who) {
        $text = trim((string) $text);
        if ($text === '' || mb_strlen($text) > 600) { return null; }
        $phone = (string) ($who['phone'] ?? ''); $uid = (string) ($who['userId'] ?? ''); $verified = !empty($who['verified']);
        $site = ($who['channel'] ?? 'wa') === 'site';
        $link = function ($o) use ($site) { return $site ? [['label' => 'طلب #' . self::num($o), 'href' => '#/order/' . $o['id']]] : []; };

        // 1. An order by its number: «طلب 1024 وصل لفين؟»
        $ref = self::ref_in($text);
        if ($ref !== '') {
            $o = null;
            foreach (self::orders_for($phone, $uid, 20) as $x) { if (self::num($x) === $ref || substr((string) $x['id'], -strlen($ref)) === $ref) { $o = $x; break; } }
            if (!$o) { return ['reply' => 'مش لاقي طلب رقم ' . $ref . ' على ' . ($site && !$verified ? 'الرقم ده' : 'رقمك') . ' 🙏 اتأكد من الرقم، أو ابعتلي الرقم اللي طلبت بيه.', 'kind' => 'order_missing', 'order' => null, 'links' => []]; }
            if (class_exists('Mutqan_Deal') && Mutqan_Deal::intent($text) !== '' && $verified) {
                $sv = Mutqan_Deal::service(self::key($o['clientPhone'] ?? '') !== '' ? (string) $o['clientPhone'] : $phone, $text);
                if ($sv && ($sv['order'] ?? null) === $o['id']) { return ['reply' => $sv['reply'], 'kind' => 'service:' . $sv['intent'], 'order' => $o['id'], 'links' => $link($o)]; }
            }
            $f = class_exists('Mutqan_OrderBot') ? Mutqan_OrderBot::facts($o + ['id' => $o['id']]) : null;
            $reply = $f ? Mutqan_OrderBot::template('status', $f, []) : 'طلبك ' . self::order_line($o);
            return ['reply' => $reply, 'kind' => 'order_status', 'order' => $o['id'], 'links' => $link($o)];
        }

        // 2. A question about his live order (where / hurry / move / cancel / a message for the technician).
        if (class_exists('Mutqan_Deal') && Mutqan_Deal::intent($text) !== '') {
            if (!$verified) {
                if (in_array(Mutqan_Deal::intent($text), ['where', 'cancel', 'reschedule'], true)) {
                    return ['reply' => 'عشان أتابع طلبك ابعتلي رقم الطلب 🙏 (هتلاقيه في رسالة التأكيد)، أو سجّل دخول برقمك وهتلاقي طلباتك كلها.', 'kind' => 'need_ref', 'order' => null, 'links' => []];
                }
                return null;
            }
            // A signed-in customer whose live order carries his account (or another number of his): that order's phone.
            if ($uid !== '' && (self::key($phone) === '' || !Mutqan_Deal::live_order_for($phone))) {
                foreach (self::orders_for('', $uid, 5) as $x) { if (in_array((string) ($x['status'] ?? ''), Mutqan_Deal::LIVE, true) && !empty($x['clientPhone'])) { $phone = (string) $x['clientPhone']; break; } }
            }
            $sv = Mutqan_Deal::service($phone, $text);
            if ($sv) {
                $o = !empty($sv['order']) ? Mutqan_Store::get_raw('orders', $sv['order']) : null;
                return ['reply' => $sv['reply'], 'kind' => 'service:' . $sv['intent'], 'order' => $sv['order'] ?? null, 'links' => $o ? $link($o + ['id' => $sv['order']]) : []];
            }
        }

        // 3. Payment, warranty, offers — one line asked on its own.
        if (class_exists('Mutqan_Customers')) {
            $topic = Mutqan_Customers::topic($text);
            if ($topic !== '' && in_array($topic, ['pay', 'warranty', 'offers', 'specs'], true)) {
                return ['reply' => Mutqan_Customers::info($topic, 'customer', (string) ($who['brand'] ?? '')), 'kind' => 'topic:' . $topic, 'order' => null, 'links' => []];
            }
        }
        return null;
    }

    /** POST /concierge {text, phone?} — the website chat asks before its model. */
    public static function handle($in, $p) {
        if (class_exists('Mutqan_Policy') && !Mutqan_Policy::is_staff($p)) { Mutqan_Limiter::hit('concierge', 40, 600); }
        $text = mb_substr(trim((string) ($in['text'] ?? '')), 0, 600);
        $uid = ($p['kind'] ?? '') === 'user' ? (string) ($p['id'] ?? '') : '';
        $mine = self::phone_of($p);
        $typed = preg_replace('/\D/', '', (string) ($in['phone'] ?? ''));
        // Signed in: his own account and phone. Guest: the typed phone opens an order only together with its number.
        $who = $uid !== '' ? ['channel' => 'site', 'phone' => $mine, 'userId' => $uid, 'verified' => true]
            : ['channel' => 'site', 'phone' => $typed, 'userId' => '', 'verified' => false];
        if ($uid === '' && self::ref_in($text) === '') { $who['phone'] = ''; }
        $a = self::answer($text, $who);
        return $a ?: ['reply' => null];
    }
}
