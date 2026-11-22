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

    /*
     * Owner, 2026-11-22 (2): the alert says WHAT it is about, so nobody has to pick up the phone to know — «الفني علي قبل
     * طلب رقم ألف وأربعة، غسيل مكيفين في العزيزية», «رسالة جديدة من العميل محمد: …», «تقرير نشر المقالات: ستين مقال».
     * And the voice itself tells how much it matters: three tiers, three male voices and three ways of reading.
     */
    const TIERS = [
        'urgent' => ['voice' => 'Orus', 'open' => 'انتباه! ', 'style' => 'You are the emergency alert announcer of a maintenance company in Saudi Arabia. Read the Arabic text below EXACTLY as written, word for word, in a LOUD, urgent, commanding adult male voice, Saudi accent, fast and sharp like an alarm announcement that demands immediate action. Text:'],
        'normal' => ['voice' => 'Charon', 'open' => '', 'style' => 'You are the operations announcer of a maintenance company in Saudi Arabia. Read the Arabic text below EXACTLY as written, word for word, in a loud, clear, confident adult male voice, Saudi accent, steady pace, like a clear status update. Text:'],
        'info'   => ['voice' => 'Iapetus', 'open' => 'للعلم، ', 'style' => 'You are a calm assistant of a maintenance company in Saudi Arabia. Read the Arabic text below EXACTLY as written, word for word, in a calm, relaxed, clear adult male voice, Saudi accent, unhurried, like a friendly report. Text:'],
    ];
    const URGENT = ['new_order', 'new_task', 'unassigned', 'tech_rejected', 'cust_rejected', 'cust_cancelled', 'late', 'failed', 'handoff', 'complaint', 'call', 'warranty'];

    /** urgent | normal | info — from the alert's kind, its priority and whether it is a report. */
    public static function tier($m) {
        $e = strtolower((string) ($m['event'] ?? '')); $t = (string) ($m['title'] ?? '');
        if (preg_match('/report|digest|summary|night|weekly|daily|trainer|sales_brain|article|plan|statement/', $e) || preg_match('/تقرير|ملخص|خطة اليوم|كشف حساب|مقالات/u', $t)) { return 'info'; }
        $k = self::key($e, $t, (string) ($m['body'] ?? ''));
        if (in_array($k, self::URGENT, true) || (($m['priority'] ?? '') === 'high' && $k === 'alert')) { return 'urgent'; }
        return 'normal';
    }

    /** Text fit to be read aloud: no emoji, no links, «#1004» as «رقم 1004», separators as pauses, short. */
    public static function clean($s) {
        $s = (string) $s;
        $s = preg_replace('#https?://\S+#u', '', $s);
        $s = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}]/u', '', $s);
        $s = preg_replace('/#\s*(\d+)/u', 'رقم $1', $s);
        $s = str_replace(['·', '—', '–', '|', '•', '*', '_', '`', '>'], ['، ', '، ', '، ', '، ', '، ', '', ' ', '', ''], $s);
        $s = preg_replace('/ر\.س/u', 'ريال', $s);
        // read clearly whoever and whatever the data holds (owner): a phone number digit by digit, a code like «AB12CD34» letter by letter
        $s = preg_replace_callback('/(?<![\d])\+?\d{9,15}(?![\d])/u', function ($x) { return implode(' ', preg_split('//u', ltrim($x[0], '+'), -1, PREG_SPLIT_NO_EMPTY)); }, $s);
        $s = preg_replace_callback('/\b(?=[A-Za-z0-9-]*[A-Za-z])(?=[A-Za-z0-9-]*\d)[A-Za-z0-9-]{4,16}\b/u', function ($x) { return implode(' ', preg_split('//u', strtoupper(str_replace('-', '', $x[0])), -1, PREG_SPLIT_NO_EMPTY)); }, $s);
        $s = preg_replace('/\s*\n+\s*/u', '، ', $s);
        $s = preg_replace('/\s*،\s*/u', '، ', $s);
        $s = preg_replace('/(،\s*){2,}/u', '، ', $s);
        return (string) preg_replace('/^[\s،.:]+|[\s،.:]+$/u', '', (string) preg_replace('/\s{2,}/u', ' ', $s));   // not trim(): it cuts Arabic letters byte by byte
    }

    /**
     * The whole sentence for one alert: the urgency word, what happened (its title — or the kind's own sentence when the
     * title says nothing), then the details (its body), at most ~35 words.
     */
    public static function sentence($m) {
        $tier = self::tier($m);
        $key = self::key((string) ($m['event'] ?? ''), (string) ($m['title'] ?? ''), (string) ($m['body'] ?? ''));
        $title = self::clean($m['title'] ?? ''); $body = self::clean($m['body'] ?? '');
        if (mb_strlen($title) < 6 || preg_match('/^(تنبيه|إشعار|اشعار|تنبيه جديد)$/u', $title)) { $title = preg_replace('/^(انتباه|تنبيه)،\s*/u', '', self::KEYS[$key] ?? self::KEYS['alert']); }
        $t = self::TIERS[$tier]['open'] . $title . ($body !== '' && mb_strpos($title, $body) === false ? '. ' . $body : '');
        $w = preg_split('/\s+/u', $t) ?: [];
        if (count($w) > 35) { $t = implode(' ', array_slice($w, 0, 35)); }
        return $t;
    }

    /** The spoken clip of one alert (made once per sentence and tier, then from the file); '' when the voice is down. */
    public static function speak($m) {
        $tier = self::tier($m); $text = self::sentence($m);
        $u = wp_upload_dir(); $dir = rtrim((string) $u['basedir'], '/') . '/' . self::DIR . '/say';
        $file = $dir . '/' . md5($tier . '|' . $text) . '.wav';
        if (is_readable($file)) { return (string) file_get_contents($file); }
        if (get_transient('mq_voice_down')) { return ''; }
        $T = self::TIERS[$tier];
        $r = class_exists('Mutqan_AI') ? Mutqan_AI::tts($text, $T['voice'], $T['style']) : ['ok' => false];
        if (empty($r['ok']) || empty($r['wav'])) { set_transient('mq_voice_down', 1, 120); return ''; }
        $wav = self::louder((string) base64_decode((string) $r['wav']), $tier === 'urgent' ? 2.8 : ($tier === 'info' ? 1.6 : 2.2));
        if (is_dir($dir) || wp_mkdir_p($dir)) { @file_put_contents($file, $wav); }
        return $wav;
    }

    /** Spoken clips older than a week go (they were for alerts that are long read). */
    public static function sweep() {
        $u = wp_upload_dir(); $dir = rtrim((string) $u['basedir'], '/') . '/' . self::DIR . '/say';
        foreach ((array) glob($dir . '/*.wav') as $f) { if (@filemtime($f) < time() - 7 * 86400) { @unlink($f); } }
    }

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
    public static function louder($wav, $k = 2.2) {
        if (strlen($wav) < 48 || substr($wav, 0, 4) !== 'RIFF') { return $wav; }
        $p = strpos($wav, 'data'); if ($p === false) { return $wav; }
        $head = substr($wav, 0, $p + 8); $pcm = substr($wav, $p + 8);
        $n = intdiv(strlen($pcm), 2); if ($n < 1) { return $wav; }
        $s = unpack('s*', substr($pcm, 0, $n * 2));
        $peak = 1; foreach ($s as $v) { $a = abs($v); if ($a > $peak) { $peak = $a; } }
        // normalised to the loudest sample, then compressed (tanh): quiet syllables come up, nothing clips
        $den = tanh($k); $out = '';
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
        add_action('mutqan_voice_warm', function () { self::warm(); self::sweep(); });
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
