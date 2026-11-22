<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «التنبيه الصوتي» (owner, 2026-11-22): every alert is also SAID — one man's voice, loud and clear: «عندك طلب جديد»,
 * «العميل رفض الطلب», «الفني قبل الطلب», «الفني اتأخر على الطلب», «تم الدفع», «تم التحويل», «تم الإسناد الآلي»…
 *
 * One fixed sentence per kind of alert (KEYS), spoken once by the free Gemini voice (the same male voice as the
 * assistant, read as an announcement), made louder, and kept as a file — every phone and every app plays the same
 * clip, with no cost after the first time. GET /mutqan/v1/voice?k=new_order returns it (audio/wav); every inbox item
 * carries its key (`voice`), so the page and the Android apps know what to say, even with the screen off.
 */
final class Mutqan_Voice {
    const VOICE = 'Charon';
    const STYLE = 'You are the alert announcer of a maintenance company in Saudi Arabia. Read the Arabic sentence below EXACTLY as written, word for word, in a loud, clear, firm adult male voice, Saudi accent, like an important announcement: steady pace, strong and attention-getting, no singing. Sentence:';
    const KEYS = [
        'new_order'      => 'انتباه، عندك طلب جديد',
        'new_task'       => 'انتباه، عندك مهمة جديدة',
        'auto_assigned'  => 'تم الإسناد الآلي للطلب',
        'assigned'       => 'تم إسناد الطلب للفني',
        'unassigned'     => 'تنبيه، في طلب لسه من غير فني',
        'accepted'       => 'الفني قبل الطلب',
        'tech_rejected'  => 'تنبيه، الفني رفض الطلب',
        'cust_rejected'  => 'تنبيه، العميل رفض الطلب',
        'cust_cancelled' => 'تنبيه، العميل ألغى الطلب',
        'late'           => 'تنبيه، الفني اتأخر على الطلب',
        'en_route'       => 'الفني في الطريق للعميل',
        'nearby'         => 'الفني قرّب من العميل',
        'arrived'        => 'الفني وصل عند العميل',
        'started'        => 'الفني بدأ الشغل',
        'completed'      => 'تم تنفيذ الطلب',
        'failed'         => 'تنبيه، الطلب ما اتنفذش',
        'paid'           => 'تم الدفع',
        'transfer'       => 'تم التحويل',
        'message'        => 'وصلتك رسالة جديدة',
        'handoff'        => 'عميل محتاج موظف، رد عليه',
        'complaint'      => 'تنبيه، في شكوى من عميل',
        'warranty'       => 'عندك طلب ضمان جديد',
        'call'           => 'مكالمة واردة',
        'postpone'       => 'العميل عايز يأجل الموعد',
        'rating'         => 'العميل قيّم الخدمة',
        'reminder'       => 'تذكير، موعد الخدمة قرّب',
        'contact'        => 'الفني محتاج يتواصل مع العميل',
        'tech_join'      => 'في طلب انضمام فني جديد',
        'tech_money'     => 'في طلب مالي من فني',
        'alert'          => 'عندك تنبيه جديد',
    ];
    const DIR = 'mutqan-voice';

    /** The sentence for an alert: by its event first, then by what its title says. Never null: «عندك تنبيه جديد». */
    public static function key($event, $title = '', $body = '') {
        $e = strtolower((string) $event); $t = (string) $title . ' ' . (string) $body;
        if ($e !== '' && (strpos($e, 'call') !== false)) { return 'call'; }
        if (preg_match('/الإسناد الآلي|التوزيع الآلي|اتسند تلقائي|إسناد تلقائي|أسند تلقائي/u', $t)) { return 'auto_assigned'; }
        if (preg_match('/(العميل|الزبون).{0,12}(رفض)/u', $t) || $e === 'customer_rejected') { return 'cust_rejected'; }
        if (preg_match('/(العميل|الزبون).{0,12}(لغى|ألغى|الغى|إلغاء|الغاء)/u', $t) || in_array($e, ['cancelled', 'order_cancelled', 'customer_cancel'], true)) { return 'cust_cancelled'; }
        if (preg_match('/اتأخر|متأخر|تأخر/u', $t) || in_array($e, ['order_nudge', 'late', 'order_late'], true)) { return 'late'; }
        $map = [
            'new_order' => 'new_order', 'order_new' => 'new_order', 'order_created' => 'new_order', 'order_bot' => 'new_order', 'intake' => 'new_order',
            'order_assigned' => 'assigned', 'assigned' => 'assigned', 'order_unassigned' => 'unassigned',
            'accepted' => 'accepted', 'rejected' => 'tech_rejected', 'en_route' => 'en_route', 'nearby' => 'nearby', 'distance' => 'nearby',
            'arrived' => 'arrived', 'started' => 'started', 'progress' => 'started', 'completed' => 'completed', 'failed' => 'failed', 'order_failed' => 'failed',
            'paid' => 'paid', 'payment' => 'paid', 'transfer' => 'transfer',
            'order_msg' => 'message', 'wa_chat' => 'message', 'wa_message' => 'message', 'message' => 'message', 'msg' => 'message', 'chat' => 'message', 'phone_wa' => 'message', 'support_msg' => 'message',
            'wa_handoff' => 'handoff', 'order_handoff' => 'handoff', 'wa_complaint' => 'complaint', 'complaint' => 'complaint',
            'warranty_claim' => 'warranty', 'order_postpone_req' => 'postpone', 'order_rating' => 'rating', 'appointment_reminder' => 'reminder',
            'contact' => 'contact', 'tech_application' => 'tech_join', 'payout_request' => 'tech_money', 'tech_topup' => 'tech_money', 'tech_objection' => 'tech_money',
        ];
        if (isset($map[$e])) {
            $k = $map[$e];
            if ($k === 'assigned' && preg_match('/مهمة جديدة|طلب جديد ليك|مهمة ليك/u', $t)) { return 'new_task'; }
            return $k;
        }
        if (preg_match('/طلب جديد/u', $t)) { return 'new_order'; }
        if (preg_match('/تم الدفع|دفع العميل|اتدفع/u', $t)) { return 'paid'; }
        if (preg_match('/تحويل/u', $t)) { return 'transfer'; }
        if (preg_match('/رسالة/u', $t)) { return 'message'; }
        return 'alert';
    }

    /* ------------------------------------------------------------------ the clips */

    private static function path($key) {
        $u = wp_upload_dir();
        return [rtrim((string) $u['basedir'], '/') . '/' . self::DIR, rtrim((string) $u['basedir'], '/') . '/' . self::DIR . '/' . $key . '-' . substr(md5(self::KEYS[$key] . self::STYLE), 0, 8) . '.wav'];
    }

    /** The clip's bytes (made once, then from the file), or '' when the voice service is not reachable. */
    public static function clip($key) {
        if (!isset(self::KEYS[$key])) { return ''; }
        list($dir, $file) = self::path($key);
        if (is_readable($file)) { return (string) file_get_contents($file); }
        if (get_transient('mq_voice_wait_' . $key)) { return ''; }   // just failed: not every request asks again
        $r = class_exists('Mutqan_AI') ? Mutqan_AI::tts(self::KEYS[$key], self::VOICE, self::STYLE) : ['ok' => false];
        if (empty($r['ok']) || empty($r['wav'])) { set_transient('mq_voice_wait_' . $key, 1, 600); return ''; }
        $wav = self::louder((string) base64_decode((string) $r['wav']));
        if ((is_dir($dir) || wp_mkdir_p($dir))) { @file_put_contents($file, $wav); }
        return $wav;
    }

    /**
     * Loud: the 16-bit samples are raised so the loudest one sits just under full scale, then given a little more
     * (soft-clipped), so the clip is as loud as a phone plays — an alarm, not a whisper.
     */
    public static function louder($wav) {
        if (strlen($wav) < 48 || substr($wav, 0, 4) !== 'RIFF') { return $wav; }
        $p = strpos($wav, 'data'); if ($p === false) { return $wav; }
        $head = substr($wav, 0, $p + 8); $pcm = substr($wav, $p + 8);
        $n = intdiv(strlen($pcm), 2); if ($n < 1) { return $wav; }
        $s = unpack('s*', substr($pcm, 0, $n * 2));
        $peak = 1; foreach ($s as $v) { $a = abs($v); if ($a > $peak) { $peak = $a; } }
        // normalised to the loudest sample, then compressed (tanh): quiet syllables come up, nothing clips
        $k = 2.2; $den = tanh($k); $out = '';
        foreach ($s as $v) { $out .= pack('s', (int) round(tanh($k * $v / $peak) / $den * 0.97 * 32767)); }
        return $head . $out;
    }

    /** Makes the clips nobody has yet (hourly, a few at a time), so the first alert of each kind is already spoken. */
    public static function warm($max = 6) {
        $done = 0;
        foreach (array_keys(self::KEYS) as $k) {
            list(, $file) = self::path($k);
            if (is_readable($file)) { continue; }
            if (self::clip($k) !== '') { $done++; }
            if (--$max <= 0) { break; }
        }
        return $done;
    }

    public static function boot() {
        add_action('mutqan_voice_warm', function () { self::warm(); });
        add_action('init', function () { if (!wp_next_scheduled('mutqan_voice_warm')) { wp_schedule_event(time() + 120, 'hourly', 'mutqan_voice_warm'); } }, 9);
        add_action('rest_api_init', function () {
            register_rest_route('mutqan/v1', '/voice', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function ($req) {
                $k = preg_replace('/[^a-z_]/', '', (string) $req->get_param('k'));
                if ($k === 'list') { return rest_ensure_response(['keys' => self::KEYS]); }
                $wav = self::clip($k);
                if ($wav === '') { return new WP_REST_Response(['error' => ['code' => 'not_ready', 'text' => self::KEYS[$k] ?? '']], 404); }
                header('Content-Type: audio/wav');
                header('Content-Length: ' . strlen($wav));
                header('Cache-Control: public, max-age=604800');
                header('Access-Control-Allow-Origin: *');
                echo $wav;
                exit;
            }]);
        });
    }
}
