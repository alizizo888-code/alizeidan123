<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Signing in with a mobile number and a one-time code.
 *
 * Customers and technicians both have a phone and most have no patience for passwords, so the code
 * is the whole sign-in. That makes this the softest way into the system, and the rules below exist
 * because of it:
 *
 *  - A code never grants a staff role. Someone who signs in this way becomes a customer, and only
 *    the owner or operations can make them anything more. Otherwise anyone with a phone could read
 *    every order by registering as a technician.
 *  - A code is stored hashed, like a password, so a database copy is not a pile of working codes.
 *  - Guesses are counted per number as well as per caller: six digits is ~1 in a million, which is
 *    a lot for a person and very little for a script.
 *  - Asking for a code for a number that is not registered is answered exactly like one that is,
 *    so this cannot be used to find out who has an account.
 */
final class Mutqan_OTP {

    /** How long a code is good for. Long enough for a slow SMS, short enough to be worth little if seen. */
    const TTL = 300;

    /** Wrong guesses allowed for one number before its code is thrown away. */
    const MAX_ATTEMPTS = 5;

    /** Codes one caller may ask for in an hour, and one number may receive in an hour. */
    const MAX_SENDS_PER_CALLER = 6;
    const MAX_SENDS_PER_PHONE = 4;

    /** A fresh code may not be asked for more often than this, so a button cannot be used to spam a number. */
    const RESEND_SECONDS = 45;

    private static function key($phone) { return 'mq_otp_' . substr(hash('sha256', $phone . wp_salt('auth')), 0, 24); }

    /** Digits only, Saudi local forms folded to international, matching the rest of the system. */
    public static function normalize($phone) {
        $d = preg_replace('/\D/', '', (string) $phone);
        if (strpos($d, '00') === 0) { $d = substr($d, 2); }
        if (preg_match('/^05\d{8}$/', $d)) { return '966' . substr($d, 1); }
        if (preg_match('/^5\d{8}$/', $d)) { return '966' . $d; }
        return $d;
    }

    public static function valid_phone($phone) {
        return (bool) preg_match('/^\d{9,15}$/', (string) $phone);
    }

    /**
     * Sends a code to a mobile number.
     *
     * The answer is the same whether or not the number already has an account, and never contains
     * the code itself.
     */
    public static function request($in) {
        $phone = self::normalize($in['phone'] ?? '');
        if (!self::valid_phone($phone)) { throw new Mutqan_Error('bad_phone', 'اكتب رقم جوال صحيح.', 422); }

        Mutqan_Limiter::hit('otp_send', self::MAX_SENDS_PER_CALLER, 3600);
        Mutqan_Limiter::hit('otp_phone', self::MAX_SENDS_PER_PHONE, 3600, $phone);

        $cur = get_transient(self::key($phone));
        if (is_array($cur) && (time() - (int) ($cur['at'] ?? 0)) < self::RESEND_SECONDS) {
            $wait = self::RESEND_SECONDS - (time() - (int) $cur['at']);
            throw new Mutqan_Error('too_soon', 'انتظر ' . $wait . ' ثانية قبل طلب رمز جديد.', 429);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        set_transient(self::key($phone), [
            'hash' => password_hash($code, PASSWORD_DEFAULT),
            'at' => time(),
            'tries' => 0,
        ], self::TTL);

        $sent = self::deliver($phone, $code);
        // Whether the message left is about our gateway, not about them; the code is valid either way.
        return ['ok' => true, 'sent' => $sent, 'expiresIn' => self::TTL, 'resendIn' => self::RESEND_SECONDS];
    }

    /** Sends the code over WhatsApp, which is the channel this business already runs on. */
    private static function deliver($phone, $code) {
        $text = 'رمز الدخول: ' . $code . "\nصالح لمدة 5 دقائق. لا تشاركه مع أحد.";
        try { return (bool) Mutqan_WA::auto_send($phone, $text, 'otp'); }
        catch (Throwable $e) { error_log('[mutqan] otp send: ' . $e->getMessage()); return false; }
    }

    /**
     * Checks a code and signs the person in, creating a customer account the first time.
     *
     * A number that already belongs to someone — customer or staff — signs that person in. It never
     * creates a second account for the same number, and never changes anybody's role.
     */
    public static function verify($in) {
        $phone = self::normalize($in['phone'] ?? '');
        $code = preg_replace('/\D/', '', (string) ($in['code'] ?? ''));
        if (!self::valid_phone($phone)) { throw new Mutqan_Error('bad_phone', 'اكتب رقم جوال صحيح.', 422); }

        Mutqan_Limiter::hit('otp_try', 12, 3600);

        $rec = get_transient(self::key($phone));
        if (!is_array($rec) || empty($rec['hash'])) {
            throw new Mutqan_Error('otp_expired', 'الرمز منتهي أو غير موجود. اطلب رمزًا جديدًا.', 401);
        }
        if ((int) $rec['tries'] >= self::MAX_ATTEMPTS) {
            delete_transient(self::key($phone));
            throw new Mutqan_Error('otp_locked', 'محاولات كثيرة. اطلب رمزًا جديدًا.', 429);
        }
        if (!password_verify($code, $rec['hash'])) {
            $rec['tries'] = (int) $rec['tries'] + 1;
            $left = max(0, self::TTL - (time() - (int) $rec['at']));
            if ($left > 0) { set_transient(self::key($phone), $rec, $left); }
            throw new Mutqan_Error('otp_wrong', 'الرمز غير صحيح.', 401);
        }

        // Used once, and only once.
        delete_transient(self::key($phone));

        $u = Mutqan_Auth::find_by_phone_any($phone);
        if ($u) { return Mutqan_Auth::session_for($u); }

        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') { $name = 'عميل ' . substr($phone, -4); }
        return Mutqan_Auth::create_customer_by_phone($phone, mb_substr($name, 0, 80));
    }

    /**
     * A technician asking to join. This creates no account and grants nothing: it records the request
     * for the owner or operations to act on, which is the only way a staff role is ever given.
     */
    public static function request_staff($in, $p) {
        $phone = self::normalize($in['phone'] ?? '');
        if (!self::valid_phone($phone)) { throw new Mutqan_Error('bad_phone', 'اكتب رقم جوال صحيح.', 422); }
        Mutqan_Limiter::hit('otp_staff_req', 3, 3600);

        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 80);
        if ($name === '') { throw new Mutqan_Error('bad_name', 'اكتب اسمك.', 422); }

        $id = 'jr' . substr(hash('sha256', $phone), 0, 22);
        Mutqan_Store::upsert('join_requests', [
            'phone' => $phone,
            'name' => $name,
            'craft' => mb_substr(trim((string) ($in['craft'] ?? '')), 0, 60),
            'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 300),
            'status' => 'pending',
            'userId' => ($p['kind'] ?? '') === 'user' ? (string) $p['id'] : null,
            'at' => gmdate('c'),
        ], $id, [], Mutqan_WA::sys());

        Mutqan_WA::notify_admins('طلب انضمام فني', $name . ' — ' . $phone, 'join-' . $id, 0);
        return ['ok' => true, 'pending' => true];
    }
}
