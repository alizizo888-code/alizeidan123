<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Telegram bridge (free, no accounts on our side):
 *  1. TEAM ALERTS — every order / customer action / hotline call that reaches the app also reaches the team's Telegram, so it rings on the phone even when the app is closed.
 *  2. CUSTOMER CHAT — a customer taps «ادردش على تيليجرام» (t.me/<bot>?start=c_<call code>) and writes; the team sees the message in Telegram and simply REPLIES to it
 *     to answer. The customer never registers anywhere: Telegram already knows him; the call code ties the chat to the hotline caller.
 *  3. PAIRING — a team member taps «اربط تيليجرام» in the app; the app shows a one-time link (t.me/<bot>?start=p_<code>, valid 10 min). One tap on Start pairs his chat.
 *
 * The bot token (from @BotFather) is typed by the owner inside the app, kept encrypted in this site's database only, and never returned to any client.
 * Webhook calls are accepted only with Telegram's secret header. Group chats are ignored (private chats only).
 */
final class Mutqan_Telegram {
    const OPT = 'mutqan_tg';   // ['t' => encrypted token, 'bot' => username, 'secret' => hex, 'on' => bool, 'chats' => [chatId => ['label','uid','role','at']]]

    private static function cfg() { $o = get_option(self::OPT, []); return is_array($o) ? $o : []; }
    private static function put($c) { update_option(self::OPT, $c, false); }

    /* ---- token at rest ---- */
    private static function key() { return hash('sha256', 'mqtg|' . wp_salt('auth') . '|' . (defined('AUTH_KEY') ? AUTH_KEY : ''), true); }
    private static function enc($s) {
        if (!function_exists('openssl_encrypt')) { return 'p:' . $s; }
        $iv = random_bytes(16);
        $c = openssl_encrypt($s, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);
        return $c === false ? 'p:' . $s : 'e:' . base64_encode($iv . $c);
    }
    private static function dec($s) {
        $s = (string) $s;
        if (strpos($s, 'p:') === 0) { return substr($s, 2); }
        if (strpos($s, 'e:') === 0 && function_exists('openssl_decrypt')) {
            $raw = base64_decode(substr($s, 2), true);
            if ($raw === false || strlen($raw) < 17) { return ''; }
            $v = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
            return $v === false ? '' : $v;
        }
        return '';
    }
    private static function token() { $c = self::cfg(); return !empty($c['t']) ? self::dec($c['t']) : ''; }

    public static function ready() { $c = self::cfg(); return !empty($c['on']) && self::token() !== ''; }
    public static function bot_name() { $c = self::cfg(); return self::ready() ? (string) ($c['bot'] ?? '') : ''; }

    /** Calls the Bot API. The token only ever appears in this URL — it is never logged, returned or put in an error message. */
    private static function call($tok, $method, $params = [], $blocking = true) {
        if ($tok === '') { return null; }
        $res = wp_remote_post('https://api.telegram.org/bot' . $tok . '/' . $method, ['timeout' => $blocking ? 10 : 1, 'blocking' => $blocking, 'headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode($params)]);
        if (!$blocking) { return ['ok' => true]; }
        if (is_wp_error($res)) { return null; }
        $j = json_decode((string) wp_remote_retrieve_body($res), true);
        return is_array($j) ? $j : null;
    }
    private static function say($chat, $text, $blocking = false, $extra = []) {
        return self::call(self::token(), 'sendMessage', array_merge(['chat_id' => (string) $chat, 'text' => mb_substr((string) $text, 0, 3800), 'disable_web_page_preview' => true], $extra), $blocking);
    }

    /* ---- owner setup ---- */
    public static function status($p) {
        $c = self::cfg(); $on = self::ready(); $mine = 0;
        foreach ((array) ($c['chats'] ?? []) as $ch) { if (($ch['uid'] ?? '') === ($p['id'] ?? '#')) { $mine++; } }
        $bot = $on ? (string) ($c['bot'] ?? '') : '';
        return ['on' => $on, 'bot' => $bot, 'link' => $bot !== '' ? 'https://t.me/' . $bot : '', 'chats' => count((array) ($c['chats'] ?? [])), 'mine' => $mine > 0];
    }

    public static function save($in) {
        Mutqan_Limiter::hit('tg_save', 10, 3600);
        $tok = trim((string) ($in['token'] ?? ''));
        if (!preg_match('/^\d{6,12}:[A-Za-z0-9_\-]{30,60}$/', $tok)) { throw new Mutqan_Error('bad_request', 'رمز البوت غير صحيح — انسخه كما أعطاك @BotFather (شكله 123456:ABC…).', 400); }
        $me = self::call($tok, 'getMe');
        if (empty($me['ok']) || empty($me['result']['username'])) { throw new Mutqan_Error('bad_request', 'تعذّر التحقق من البوت — تأكد من الرمز ومن أن الموقع يصل إلى تيليجرام.', 400); }
        $secret = bin2hex(random_bytes(16));
        $hook = self::call($tok, 'setWebhook', ['url' => home_url('/wp-json/' . Mutqan_Rest::NS . '/tg-hook'), 'secret_token' => $secret, 'allowed_updates' => ['message'], 'drop_pending_updates' => true]);
        if (empty($hook['ok'])) { throw new Mutqan_Error('server_error', 'تعذّر ربط البوت بالموقع (تيليجرام رفض عنوان الاستقبال). تأكد أن الموقع يعمل بـ HTTPS.', 502); }
        $old = self::cfg();
        $same = !empty($old['bot']) && $old['bot'] === (string) $me['result']['username'];
        self::put(['t' => self::enc($tok), 'bot' => (string) $me['result']['username'], 'secret' => $secret, 'on' => true, 'chats' => $same ? (array) ($old['chats'] ?? []) : []]);
        return self::status(['id' => '#']);
    }

    public static function off() {
        $tok = self::token();
        if ($tok !== '') { self::call($tok, 'deleteWebhook', ['drop_pending_updates' => true]); }
        delete_option(self::OPT);
        return self::status(['id' => '#']);
    }

    public static function test() {
        $c = self::cfg(); $n = 0;
        foreach (array_keys((array) ($c['chats'] ?? [])) as $cid) { self::say($cid, "✅ اختبار تنبيهات تيليجرام\nإذا وصلتك هذه الرسالة فكل شيء يعمل: ستصلك هنا الطلبات والمكالمات الجديدة.", true); $n++; }
        return ['sent' => $n];
    }

    /** A team member's one-tap pairing link (valid 10 minutes, one use). */
    public static function pair($p) {
        if (!self::ready()) { throw new Mutqan_Error('not_set', 'بوت تيليجرام غير مفعّل — يفعّله المالك أولًا.', 409); }
        Mutqan_Limiter::hit('tg_pair', 20, 3600);
        $code = bin2hex(random_bytes(6));
        set_transient('mqtg_p_' . $code, ['uid' => (string) ($p['id'] ?? ''), 'label' => (string) ($p['name'] ?? ''), 'role' => (string) ($p['role'] ?? '')], 600);
        $bot = (string) (self::cfg()['bot'] ?? '');
        return ['link' => 'https://t.me/' . $bot . '?start=p_' . $code, 'expiresIn' => 600];
    }
    public static function unpair($p) {
        $c = self::cfg();
        foreach ((array) ($c['chats'] ?? []) as $cid => $ch) {
            if (($ch['uid'] ?? '') === ($p['id'] ?? '#')) { unset($c['chats'][$cid]); self::say($cid, 'تم إيقاف التنبيهات على هذه المحادثة.', false); }
        }
        self::put($c);
        return self::status($p);
    }

    /* ---- 1. team alerts (fire and forget: a slow Telegram never slows an order) ---- */
    public static function notify($title, $body) {
        if (!self::ready()) { return; }
        $c = self::cfg();
        $text = '📣 ' . $title . ($body !== '' ? "\n" . $body : '');
        foreach (array_keys((array) ($c['chats'] ?? [])) as $cid) { self::say($cid, $text, false); }
    }

    /* ---- 2. webhook ---- */
    public static function webhook($raw, $secret) {
        $c = self::cfg();
        if (empty($c['secret']) || !is_string($secret) || !hash_equals((string) $c['secret'], $secret)) { throw new Mutqan_Error('forbidden', 'غير مصرح.', 403); }
        $u = json_decode((string) $raw, true);
        $m = is_array($u) ? ($u['message'] ?? null) : null;
        if (!is_array($m) || empty($m['chat']['id']) || ($m['chat']['type'] ?? '') !== 'private') { return ['ok' => true]; }
        $chat = (string) (int) $m['chat']['id'];
        $text = isset($m['text']) ? trim((string) $m['text']) : '';
        $staff = isset($c['chats'][$chat]);

        if (preg_match('/^\/start(?:@\w+)?\s+p_([a-f0-9]{12})$/', $text, $mm)) { return self::do_pair($c, $chat, $mm[1], $m); }
        if (preg_match('/^\/stop(?:@\w+)?$/', $text)) {
            if ($staff) { unset($c['chats'][$chat]); self::put($c); self::say($chat, 'تم إيقاف تنبيهات الفريق على هذه المحادثة.', true); }
            else { self::say($chat, 'تم إنهاء المحادثة. اكتب لنا متى شئت.', true); }
            return ['ok' => true];
        }
        if ($staff) { return self::staff_message($c, $chat, $m, $text); }
        return self::customer_message($c, $chat, $m, $text);
    }

    private static function do_pair($c, $chat, $code, $m) {
        $d = get_transient('mqtg_p_' . $code);
        if (!is_array($d)) { self::say($chat, 'رابط الربط انتهت صلاحيته — أنشئ رابطًا جديدًا من التطبيق.', true); return ['ok' => true]; }
        delete_transient('mqtg_p_' . $code);   // one use
        $c['chats'][$chat] = ['label' => $d['label'] ?: trim(((string) ($m['from']['first_name'] ?? '')) . ' ' . ((string) ($m['from']['last_name'] ?? ''))), 'uid' => $d['uid'], 'role' => $d['role'], 'at' => time()];
        self::put($c);
        self::say($chat, "✅ تم الربط.\nستصلك هنا الطلبات الجديدة والمكالمات ورسائل العملاء فورًا.\nللرد على عميل: اضغط «رد» على رسالته واكتب ردك.\nلإيقاف التنبيهات: /stop", true);
        return ['ok' => true];
    }

    /** A team member replied to a forwarded customer message → deliver the reply to that customer. */
    private static function staff_message($c, $chat, $m, $text) {
        $src = (string) ($m['reply_to_message']['text'] ?? '');
        if (!preg_match('/#c(\d{5,15})\s*$/', $src, $mm)) {
            self::say($chat, "للرد على عميل اضغط «رد» على رسالته هنا.\nلإيقاف التنبيهات: /stop", true);
            return ['ok' => true];
        }
        $cust = $mm[1];
        if ($text === '') { self::say($chat, 'أرسل الرد نصًا.', true); return ['ok' => true]; }
        $r = self::say($cust, $text, true);
        self::say($chat, !empty($r['ok']) ? '✓ وصل الرد للعميل' : '⚠️ تعذّر إيصال الرد (ربما أوقف العميل المحادثة)', true);
        return ['ok' => true];
    }

    /** A customer wrote to the bot → greet on first contact, forward to every paired team chat, and raise the in-app alert. */
    private static function customer_message($c, $chat, $m, $text) {
        try { Mutqan_Limiter::hit('tg_in', 120, 3600, $chat); } catch (Mutqan_Error $e) { return ['ok' => true]; }
        $name = trim(((string) ($m['from']['first_name'] ?? '')) . ' ' . ((string) ($m['from']['last_name'] ?? '')));
        $user = !empty($m['from']['username']) ? '@' . preg_replace('/[^A-Za-z0-9_]/', '', (string) $m['from']['username']) : '';
        $code = '';
        if (preg_match('/^\/start(?:@\w+)?(?:\s+(\S{1,40}))?$/', $text, $mm)) {
            $code = preg_match('/^c_([A-Za-z0-9_\-]{3,20})$/', $mm[1] ?? '', $cm) ? $cm[1] : '';
            self::say($chat, "أهلًا بك 👋\nاكتب رسالتك هنا وسيردّ عليك أحد موظفينا في أقرب وقت.", true);
            if ($code === '') { return ['ok' => true]; }
            $text = 'بدأ المحادثة من زر الخط الساخن';
        } elseif (isset($m['location']['latitude'], $m['location']['longitude'])) {
            $text = 'موقع العميل: https://maps.google.com/?q=' . (float) $m['location']['latitude'] . ',' . (float) $m['location']['longitude'];
        } elseif (isset($m['contact']['phone_number'])) {
            $text = 'جوال العميل: ' . preg_replace('/[^\d+]/', '', (string) $m['contact']['phone_number']);
        } elseif ($text === '') {
            $text = '[مرفق: ' . (isset($m['photo']) ? 'صورة' : (isset($m['voice']) ? 'رسالة صوتية' : (isset($m['document']) ? 'ملف' : 'غير نصي'))) . '] — اطلب منه كتابتها نصًا';
        }
        $text = mb_substr(trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($text))), 0, 1000);
        $who = ($name !== '' ? $name : 'عميل') . ($user !== '' ? ' ' . $user : '');
        $msg = "💬 رسالة من عميل (تيليجرام)\nالاسم: " . $who . ($code !== '' ? "\nكود المكالمة: " . $code : '') . "\n\n" . $text . "\n\n↩️ اضغط «رد» على هذه الرسالة للرد على العميل\n#c" . $chat;
        $sent = 0;
        foreach (array_keys((array) ($c['chats'] ?? [])) as $cid) { self::say($cid, $msg, false); $sent++; }
        if (class_exists('Mutqan_Rest')) { Mutqan_Rest::admin_alert('💬 رسالة من عميل على تيليجرام', "الاسم: " . $who . ($code !== '' ? "\nكود المكالمة: " . $code : '') . "\n" . $text . "\n(الرد من تيليجرام: اضغط رد على الرسالة)", '#/staff/operations', 'telegram_msg', 'high'); }
        if ($sent === 0) { self::say($chat, 'استلمنا رسالتك، وسنعاود التواصل معك قريبًا.', false); }
        return ['ok' => true];
    }
}
