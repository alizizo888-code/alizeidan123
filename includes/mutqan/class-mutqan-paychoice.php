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
            . "\n\nالرسالة دي بتتمسح لوحدها بعد يومين — لو احتجتها تاني اكتب *طرق الدفع*.";
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
            ];
        };
        if ($op === 'view') { return $view($o); }
        if ($op === 'choose') {
            $m = (string) ($in['method'] ?? '');
            if (!isset(self::METHODS[$m])) { throw new Mutqan_Error('bad_method', 'اختار طريقة من القائمة.', 400); }
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
