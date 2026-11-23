<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «طرق الدفع» link for one order (owner, 2026-11-23 — idea 25).
 *
 * When the job is done and not paid, the customer gets on WhatsApp a link to the payment-methods page (#/pay?o=…&k=…).
 * He picks how he pays (cash to the technician, card on the technician's machine, a bank transfer, the online link);
 * operations see his choice on the order. The hub deletes that message for everyone just before WhatsApp's limit
 * (wa-hub/src/revoke.js); when he writes «طرق الدفع» again it is sent again (Mutqan_Hub op «paylink»).
 * The key in the link is the order's own random `payToken`, so nobody can choose for someone else's order.
 */
final class Mutqan_PayChoice {

    const DONE = ['arrived', 'in_progress', 'completed', 'closed'];
    const METHODS = ['cash' => 'كاش للفني', 'card' => 'شبكة/مدى مع الفني', 'transfer' => 'تحويل بنكي', 'online' => 'دفع إلكتروني بالرابط', 'later' => 'هتواصل معاكم'];

    private static function sys() { return ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']]; }

    public static function amount($o) {
        foreach (['finalPrice', 'price', 'agreedPrice', 'quotedTotal'] as $k) { $v = (float) ($o[$k] ?? 0); if ($v > 0) { return $v; } }
        return 0.0;
    }

    public static function is_paid($o) { return ($o['paymentStatus'] ?? '') === 'paid'; }

    /** The order's link key: made once, kept. */
    public static function token($o) {
        $t = (string) ($o['payToken'] ?? '');
        if ($t !== '' || empty($o['id'])) { return $t; }
        $t = substr(bin2hex(random_bytes(8)), 0, 12);
        Mutqan_Store::update('orders', $o['id'], ['payToken' => $t], self::sys());
        return $t;
    }

    public static function link($o) {
        $base = function_exists('home_url') ? home_url('/app/') : '/app/';
        return $base . '#/pay?o=' . rawurlencode((string) $o['id']) . '&k=' . rawurlencode(self::token($o));
    }

    public static function message($o) {
        $n = (string) ($o['number'] ?? substr((string) $o['id'], -5));
        $amt = self::amount($o);
        return "💳 *طرق الدفع — طلب #" . $n . "*\n"
            . ($amt > 0 ? 'المبلغ: *' . rtrim(rtrim(number_format($amt, 2, '.', ''), '0'), '.') . " ر.س*\n" : '')
            . "اختار طريقة الدفع اللي تناسبك من هنا 👇\n" . self::link($o)
            . "\n\nلو احتجت طرق الدفع تاني اكتب *طرق الدفع* 🌷";
    }

    /* ---------------------------------------------------------------- the WhatsApp menu before the rating (owner, 2026-11-23)
     * When the job is done the customer is asked first how he pays: 1 cash to the technician · 2 bank transfer (the
     * company's accounts: الراجحي، الأهلي، بنك الرياض، STC، برق… whatever the owner saved in «طرق الدفع») · 3 the secure
     * payment gateway (the order's page: card, and the online accounts like PayPal). Then the thanks and the rating. */

    public static function menu($o) {
        $amt = self::amount($o);
        $tech = (string) ($o['technicianName'] ?? '');
        return '✅ ' . ($tech !== '' ? 'الفني ' . $tech . ' خلّص الشغل' : 'الشغل خلص') . ($amt > 0 ? ' — المبلغ *' . self::money($amt) . ' ر.س*' : '') . ".\n"
            . "💳 حابب تدفع إزاي؟\n1️⃣ كاش للفني\n2️⃣ تحويل بنكي\n3️⃣ بوابة الدفع الآمنة (فيزا / مدى / حسابات أونلاين)";
    }

    private static function money($v) { return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'); }

    /** The company's transfer accounts: [{name, iban, number, wallet, holder, qr}] from «طرق الدفع», else the quote's banks. */
    public static function accounts() {
        $L = [];
        try {
            foreach (Mutqan_Store::select('payment_methods', [], ['limit' => 100], self::sys()) as $m) {
                if (!in_array((string) ($m['type'] ?? ''), ['bank_transfer', 'wallet_app'], true)) { continue; }
                $any = true;   // the owner manages his accounts here: switched-off ones never come back from the quote's list
                if (!empty($m['deleted']) || (isset($m['enabled']) && !$m['enabled'])) { continue; }
                $a = ['name' => (string) ($m['bankName'] ?? '') ?: (string) ($m['name'] ?? 'حساب'), 'iban' => preg_replace('/\s+/', '', (string) ($m['iban'] ?? '')), 'number' => (string) ($m['accountNumber'] ?? ''), 'wallet' => (string) ($m['walletPhone'] ?? ''), 'holder' => (string) ($m['accountHolder'] ?? ''), 'qr' => preg_match('#^https://#', (string) ($m['qrUrl'] ?? '')) ? (string) $m['qrUrl'] : '', 'sort' => (int) ($m['sort'] ?? 99)];
                if ($a['iban'] === '' && $a['number'] === '' && $a['wallet'] === '') { continue; }
                $L[] = $a;
            }
            usort($L, function ($x, $y) { return $x['sort'] <=> $y['sort']; });
        } catch (Throwable $e) { /* fall back below */ }
        if (!$L && empty($any) && class_exists('Mutqan_Quote')) {
            try { $c = Mutqan_Quote::company(); foreach ((array) ($c['banks'] ?? []) as $b) { if (!empty($b['iban'])) { $L[] = ['name' => (string) $b['bank'], 'iban' => preg_replace('/\s+/', '', (string) $b['iban']), 'number' => '', 'wallet' => '', 'holder' => (string) ($c['nameAr'] ?? ''), 'qr' => '', 'sort' => 99]; } } } catch (Throwable $e) { /* none */ }
        }
        return array_values($L);
    }

    /** «2» → which bank: the list by number. */
    public static function banks_menu($o) {
        $L = self::accounts();
        if (!$L) { return ''; }
        $amt = self::amount($o);
        $out = '🏦 اختار البنك اللي هتحوّل عليه' . ($amt > 0 ? ' — المبلغ *' . self::money($amt) . ' ر.س*' : '') . ":\n";
        foreach ($L as $i => $a) { $out .= ($i + 1) . '️⃣ ' . $a['name'] . "\n"; }
        return rtrim($out);
    }

    /** One bank, the way he copies it: the details, the IBAN alone (one tap to copy), the QR image when saved. */
    public static function bank_details($o, $idx) {
        $L = self::accounts();
        $a = $L[(int) $idx - 1] ?? null;
        if (!$a) { return null; }
        $amt = self::amount($o);
        $d = '🏦 *' . $a['name'] . '*' . ($amt > 0 ? ' — حوّل *' . self::money($amt) . ' ر.س*' : '') . "\n"
            . ($a['holder'] !== '' ? 'اسم الحساب: ' . $a['holder'] . "\n" : '')
            . ($a['number'] !== '' ? 'رقم الحساب: ' . $a['number'] . "\n" : '')
            . ($a['wallet'] !== '' ? 'رقم المحفظة: ' . $a['wallet'] . "\n" : '')
            . ($a['iban'] !== '' ? "الآيبان في الرسالة اللي جاية — اضغط عليها مطوّل وانسخها 👇" : '');
        $msgs = [['text' => trim($d), 'image' => $a['qr']]];
        if ($a['iban'] !== '') { $msgs[] = ['text' => $a['iban'], 'image' => '']; }
        $msgs[] = ['text' => "بعد التحويل ابعت هنا *صورة الإيصال* أو ملف التحويل 🌷", 'image' => ''];
        return ['messages' => $msgs, 'bank' => $a['name']];
    }

    /** The company's transfer accounts, as saved in «طرق الدفع» (payment_methods), else the quote's banks. */
    public static function accounts_text() {
        $L = [];
        try {
            foreach (Mutqan_Store::select('payment_methods', [], ['limit' => 100], self::sys()) as $m) {
                if (!empty($m['deleted']) || (isset($m['enabled']) && !$m['enabled'])) { continue; }
                if (!in_array((string) ($m['type'] ?? ''), ['bank_transfer', 'wallet_app'], true)) { continue; }
                $parts = array_filter([
                    !empty($m['iban']) ? 'آيبان: ' . preg_replace('/\s+/', '', (string) $m['iban']) : '',
                    !empty($m['accountNumber']) ? 'رقم الحساب: ' . $m['accountNumber'] : '',
                    !empty($m['walletPhone']) ? 'جوال المحفظة: ' . $m['walletPhone'] : '',
                    !empty($m['accountHolder']) ? 'باسم: ' . $m['accountHolder'] : '',
                ]);
                if (!$parts) { continue; }
                $L[] = '🏦 *' . ((string) ($m['bankName'] ?? '') ?: (string) ($m['name'] ?? 'حساب')) . "*\n" . implode("\n", $parts);
            }
        } catch (Throwable $e) { /* fall back below */ }
        if (!$L && class_exists('Mutqan_Quote')) {
            try { $c = Mutqan_Quote::company(); foreach ((array) ($c['banks'] ?? []) as $b) { if (!empty($b['iban'])) { $L[] = '🏦 *' . $b['bank'] . "*\nآيبان: " . $b['iban'] . (!empty($c['nameAr']) ? "\nباسم: " . $c['nameAr'] : ''); } } } catch (Throwable $e) { /* none */ }
        }
        return implode("\n\n", $L);
    }

    /** His answer to the menu: recorded on the order, the room and the technician told, and what he needs now. */
    public static function pick($o, $method) {
        $map = ['cash' => 'cash', 'transfer' => 'transfer', 'online' => 'online'];
        if (!isset($map[$method]) || empty($o['id'])) { return null; }
        $choice = ['method' => $method, 'label' => self::METHODS[$method], 'account' => '', 'at' => gmdate('c'), 'via' => 'whatsapp'];
        Mutqan_Store::update('orders', (string) $o['id'], ['paymentChoice' => $choice], self::sys());
        $n = (string) ($o['number'] ?? substr((string) $o['id'], -5));
        if (class_exists('Mutqan_Rest')) { Mutqan_Rest::admin_alert('💳 العميل اختار طريقة الدفع #' . $n, $choice['label'] . ' · ' . ((string) ($o['clientName'] ?? '') ?: (string) ($o['clientPhone'] ?? '')), '#/staff/order/' . $o['id'], 'order_pay_choice', 'normal', $o['id']); }
        if (!empty($o['technicianId'])) { Mutqan_Store::insert('_push', ['target' => ['user' => (string) $o['technicianId']], 'title' => '💳 العميل هيدفع: ' . $choice['label'] . ' — #' . $n, 'body' => 'طلب #' . $n, 'url' => '#/staff/order/' . $o['id'], 'sound' => 'chime', 'priority' => 'normal', 'event' => 'order_pay_choice', 'from' => 'system'], null, ['expiresAt' => gmdate('c', time() + 2 * 86400)], self::sys()); }
        $amt = self::amount($o); $tech = (string) ($o['technicianName'] ?? 'الفني');
        if ($method === 'cash') { return ['text' => 'تمام ✅ ادفع' . ($amt > 0 ? ' *' . self::money($amt) . ' ر.س*' : '') . ' كاش للفني ' . $tech . '.', 'purpose' => 'reply']; }
        if ($method === 'transfer') {
            $menu = self::banks_menu($o);
            if ($menu !== '') { return ['text' => $menu, 'purpose' => 'reply', 'banks' => count(self::accounts())]; }
            $acc = self::accounts_text();
            return ['text' => ($acc !== '' ? "تمام ✅ دي حساباتنا للتحويل" . ($amt > 0 ? ' — المبلغ *' . self::money($amt) . ' ر.س*' : '') . ":\n\n" . $acc : 'تمام ✅ موظف هيبعتلك حسابات التحويل حالًا.') . "\n\nبعد التحويل ابعت صورة الإيصال هنا 🌷 أو ملف التحويل.", 'purpose' => 'paylink'];
        }
        Mutqan_Store::update('orders', (string) $o['id'], ['paylinkSentAt' => gmdate('c')], self::sys());
        return ['text' => "🔒 *بوابة الدفع الآمنة* — طلب #" . $n . ($amt > 0 ? ' — *' . self::money($amt) . ' ر.س*' : '') . "\nادفع بالفيزا / مدى أو بحساباتك الأونلاين من هنا 👇\n" . self::link($o + ['payToken' => self::token($o)]), 'purpose' => 'paylink'];
    }

    /** Sends it now (the hub marks it to be deleted for everyone later). True when handed over. */
    public static function send($o) {
        $phone = (string) ($o['clientPhone'] ?? '');
        if ($phone === '' || empty($o['id']) || !class_exists('Mutqan_WA')) { return false; }
        $ok = Mutqan_WA::auto_send($phone, self::message($o), 'paylink');
        if ($ok) {
            Mutqan_Store::update('orders', $o['id'], ['paylinkSentAt' => gmdate('c')], self::sys());
            if (method_exists('Mutqan_WA', 'add_step')) { try { Mutqan_WA::add_step((string) $o['id'], 'wa_paylink', 'اتبعت للعميل رابط طرق الدفع', 'مركز الواتساب'); } catch (Throwable $e) {} }
        }
        return (bool) $ok;
    }

    /** At the end of the job (WA journey «completed»): unpaid, with a phone → the link. */
    public static function on_completed($doc) {
        try {
            if (!is_array($doc) || self::is_paid($doc) || (string) ($doc['clientPhone'] ?? '') === '') { return false; }
            if (get_option('mutqan_paylink_off')) { return false; }
            return self::send(Mutqan_Store::get_raw('orders', (string) $doc['id']) + ['id' => (string) $doc['id']]);
        } catch (Throwable $e) { error_log('[mutqan] paylink ' . $e->getMessage()); return false; }
    }

    /** The customer asked for it again on WhatsApp: his latest finished-or-in-progress unpaid order. */
    public static function for_phone($phone) {
        $d = preg_replace('/\D/', '', (string) $phone);
        if (strlen($d) < 9) { return null; }
        $tail = substr($d, -9);
        $rows = Mutqan_Store::select('orders', [], ['limit' => 300, 'order' => '-createdAt'], self::sys());
        foreach ($rows as $o) {
            if (substr(preg_replace('/\D/', '', (string) ($o['clientPhone'] ?? '')), -9) !== $tail) { continue; }
            if (in_array($o['status'] ?? '', self::DONE, true) && !self::is_paid($o)) { return $o; }
        }
        return null;
    }

    private static function order_for($in) {
        $id = (string) ($in['o'] ?? '');
        $k = (string) ($in['k'] ?? '');
        if ($id === '' || $k === '') { throw new Mutqan_Error('not_found', 'الرابط ناقص.', 404); }
        $o = Mutqan_Store::get_raw('orders', $id);
        if (!$o || (string) ($o['payToken'] ?? '') === '' || !hash_equals((string) $o['payToken'], $k)) { throw new Mutqan_Error('not_found', 'الرابط ده مش صالح أو انتهى — اكتب «طرق الدفع» على واتساب ونبعتلك رابط جديد.', 404); }
        return $o + ['id' => $id];
    }

    /** POST /paychoice — {op: view|choose, o, k, method}. Open to anyone holding the link. */
    const CARD_OFF = 'ماكينة الشبكة غير متاحة حاليًا — مع فنيين تانيين. تقدر تدفع كاش للفني أو تحويل بنكي أو بالرابط 🌷';

    /** Ways that stay on the page but cannot be picked now (owner, 2026-11-24): the card machine switched off in «طرق الدفع»
     *  is not hidden — the customer sees it and is told the machine is out with other technicians. */
    public static function off_methods() {
        $card = null;
        try {
            foreach (Mutqan_Store::select('payment_methods', [], ['limit' => 100], self::sys()) as $m) {
                if (!empty($m['deleted']) || (string) ($m['type'] ?? '') !== 'card_on_site') { continue; }
                $card = ($card === true) ? true : (!isset($m['enabled']) || (bool) $m['enabled']);
            }
        } catch (Throwable $e) { /* unknown: leave it on */ }
        return $card === false ? ['card' => self::CARD_OFF] : [];
    }

    public static function handle($in) {
        $op = (string) ($in['op'] ?? 'view');
        if (class_exists('Mutqan_Limiter')) { Mutqan_Limiter::hit('paychoice', 60, 3600); }
        $o = self::order_for($in);
        $view = function ($o) {
            return [
                'number' => (string) ($o['number'] ?? substr((string) $o['id'], -5)),
                'amount' => self::amount($o),
                'paid' => self::is_paid($o),
                'choice' => $o['paymentChoice'] ?? null,
                'payLink' => (string) ($o['payLink'] ?? ''),
                'service' => (string) ($o['specialtyName'] ?? ($o['serviceName'] ?? '')),
                'methods' => self::METHODS,
                'off' => (object) self::off_methods(),
            ];
        };
        if ($op === 'view') { return $view($o); }
        if ($op === 'choose') {
            $m = (string) ($in['method'] ?? '');
            if (!isset(self::METHODS[$m])) { throw new Mutqan_Error('bad_method', 'اختار طريقة من القائمة.', 400); }
            $off = self::off_methods();
            if (isset($off[$m])) { throw new Mutqan_Error('method_off', $off[$m], 409); }
            $choice = ['method' => $m, 'label' => self::METHODS[$m], 'account' => mb_substr((string) ($in['account'] ?? ''), 0, 80), 'at' => gmdate('c')];
            Mutqan_Store::update('orders', $o['id'], ['paymentChoice' => $choice], self::sys());
            $n = (string) ($o['number'] ?? substr((string) $o['id'], -5));
            if (class_exists('Mutqan_Rest')) { Mutqan_Rest::admin_alert('💳 العميل اختار طريقة الدفع #' . $n, $choice['label'] . ($choice['account'] !== '' ? ' — ' . $choice['account'] : '') . ' · ' . ((string) ($o['clientName'] ?? '') ?: (string) ($o['clientPhone'] ?? '')), '#/staff/order/' . $o['id'], 'order_pay_choice', 'normal', $o['id']); }
            if (!empty($o['technicianId'])) { Mutqan_Store::insert('_push', ['target' => ['user' => (string) $o['technicianId']], 'title' => '💳 العميل هيدفع: ' . $choice['label'] . ' — #' . $n, 'body' => 'طلب #' . $n, 'url' => '#/staff/order/' . $o['id'], 'sound' => 'chime', 'priority' => 'normal', 'event' => 'order_pay_choice', 'from' => 'system'], null, ['expiresAt' => gmdate('c', time() + 2 * 86400)], self::sys()); }
            return $view(array_merge($o, ['paymentChoice' => $choice]));
        }
        throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400);
    }
}
