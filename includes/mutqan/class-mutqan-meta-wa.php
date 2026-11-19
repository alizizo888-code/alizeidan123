<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «واتساب الرسمي» — one business number on Meta's WhatsApp Cloud API (Coexistence: the same number stays live in the
 * WhatsApp Business app on the owner's phone).
 *
 * No VPS, no phone gateway, no polling: Meta calls the site the moment a customer writes (webhook), and the site answers
 * with one HTTPS call. Everything above the wire — the reception AI, «حلقة الوصل», the room's commands, the order cards,
 * the technicians' job cards — is the website's own WhatsApp brain (Mutqan_WA and friends), unchanged.
 *
 * While this is on, the VPS hub (Mutqan_Hub) and the phone gateways are retired: every WhatsApp message in and out goes
 * through here, from account id «meta».
 *
 * Secrets (access token, app secret, verify token) live in the option only, typed in by the owner on the settings page.
 */
final class Mutqan_Meta_WA {
    const OPT = 'mutqan_meta_wa';
    const ACCT = 'meta';
    const GRAPH = 'https://graph.facebook.com/v21.0/';
    const LOG = 'mutqan_meta_wa_log';

    public static function boot() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('admin_menu', function () {
            add_options_page('واتساب الرسمي (Meta)', 'واتساب الرسمي', 'manage_options', 'mutqan-meta-wa', [__CLASS__, 'page']);
        });
    }

    public static function cfg() {
        $c = get_option(self::OPT, []);
        return (is_array($c) ? $c : []) + ['on' => 0, 'phone_id' => '', 'waba_id' => '', 'token' => '', 'app_secret' => '', 'verify' => '', 'template' => '', 'lang' => 'ar', 'number' => ''];
    }
    /** On once the owner switched it on and the number id + token are in. */
    public static function on() { $c = self::cfg(); return !empty($c['on']) && $c['phone_id'] !== '' && $c['token'] !== ''; }
    public static function hook_url() { return rest_url('mutqan/v1/meta-wa'); }

    private static function log($line) {
        $l = get_option(self::LOG, []); $l = is_array($l) ? $l : [];
        array_unshift($l, gmdate('m-d H:i') . ' ' . mb_substr((string) $line, 0, 300));
        update_option(self::LOG, array_slice($l, 0, 60), false);
    }

    /* ------------------------------------------------------------------ webhook */

    public static function routes() {
        register_rest_route('mutqan/v1', '/meta-wa', [
            ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function ($req) {
                // Meta's one-time check when the webhook is saved: echo the challenge if the verify token matches.
                $c = self::cfg();
                $ok = (string) $req->get_param('hub_mode') === 'subscribe' && $c['verify'] !== '' && hash_equals($c['verify'], (string) $req->get_param('hub_verify_token'));
                if (!$ok) { return new WP_REST_Response('forbidden', 403); }
                header('Content-Type: text/plain; charset=utf-8');
                echo (string) $req->get_param('hub_challenge');
                exit;
            }],
            ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function ($req) {
                $raw = (string) $req->get_body();
                $c = self::cfg();
                $sig = (string) $req->get_header('x-hub-signature-256');
                if ($c['app_secret'] === '' || !hash_equals('sha256=' . hash_hmac('sha256', $raw, $c['app_secret']), $sig)) {
                    self::log('توقيع مرفوض'); return new WP_REST_Response(['ok' => false], 403);
                }
                // Meta wants a quick 200 (it retries otherwise): answer first, then do the work in the same request.
                $events = self::parse(json_decode($raw, true));
                if ($events) {
                    $job = function () use ($events) { foreach ($events as $e) { try { Mutqan_WA::process($e); } catch (Throwable $ex) { self::log('معالجة: ' . $ex->getMessage()); } } };
                    if (function_exists('fastcgi_finish_request')) { add_action('shutdown', function () use ($job) { @fastcgi_finish_request(); $job(); }, 40); }
                    else { $job(); }
                }
                $res = new WP_REST_Response(['ok' => true], 200);
                $res->header('Cache-Control', 'no-store');
                return $res;
            }],
        ]);
    }

    /** Meta's payload → the gateway event shape Mutqan_WA::process() already understands. */
    public static function parse($in) {
        $out = [];
        foreach ((array) ($in['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $ch) {
                $field = (string) ($ch['field'] ?? ''); $v = (array) ($ch['value'] ?? []);
                $names = [];
                foreach ((array) ($v['contacts'] ?? []) as $ct) { $names[(string) ($ct['wa_id'] ?? '')] = (string) ($ct['profile']['name'] ?? ''); }
                if ($field === 'messages') {
                    foreach ((array) ($v['messages'] ?? []) as $m) {
                        $from = preg_replace('/\D/', '', (string) ($m['from'] ?? ''));
                        $out[] = ['type' => 'message', 'accountId' => self::ACCT, 'phone' => $from, 'direction' => 'in', 'msgId' => (string) ($m['id'] ?? ''),
                            'ts' => (int) ($m['timestamp'] ?? time()), 'name' => $names[$from] ?? ''] + self::content($m);
                    }
                    foreach ((array) ($v['statuses'] ?? []) as $st) {
                        if (($st['status'] ?? '') === 'failed') { self::log('فشل توصيل لـ ' . ($st['recipient_id'] ?? '') . ': ' . ($st['errors'][0]['title'] ?? '')); }
                    }
                } elseif ($field === 'smb_message_echoes') {
                    // Coexistence: a reply typed on the phone's WhatsApp Business app — a human is handling this chat.
                    foreach ((array) ($v['message_echoes'] ?? []) as $m) {
                        $to = preg_replace('/\D/', '', (string) ($m['to'] ?? ''));
                        $out[] = ['type' => 'message', 'accountId' => self::ACCT, 'phone' => $to, 'direction' => 'out', 'msgId' => (string) ($m['id'] ?? ''),
                            'ts' => (int) ($m['timestamp'] ?? time())] + self::content($m);
                    }
                }
            }
        }
        return array_values(array_filter($out, function ($e) { return $e['phone'] !== '' && $e['msgId'] !== ''; }));
    }

    private static function content($m) {
        $t = (string) ($m['type'] ?? 'text');
        switch ($t) {
            case 'text': return ['kind' => 'text', 'text' => (string) ($m['text']['body'] ?? '')];
            case 'button': return ['kind' => 'text', 'text' => (string) ($m['button']['text'] ?? '')];
            case 'interactive':
                $i = (array) ($m['interactive'] ?? []);
                return ['kind' => 'text', 'text' => (string) ($i['button_reply']['title'] ?? ($i['list_reply']['title'] ?? ''))];
            case 'location':
                $l = (array) ($m['location'] ?? []);
                return ['kind' => 'location', 'text' => 'https://maps.google.com/?q=' . ($l['latitude'] ?? '') . ',' . ($l['longitude'] ?? '')];
            case 'image': case 'video': case 'document':
                return ['kind' => $t, 'text' => (string) ($m[$t]['caption'] ?? ($m[$t]['filename'] ?? ''))];
            case 'audio': return ['kind' => !empty($m['audio']['voice']) ? 'voice' : 'audio', 'text' => ''];
            case 'contacts': return ['kind' => 'contact', 'text' => (string) ($m['contacts'][0]['name']['formatted_name'] ?? '')];
            default: return ['kind' => 'other', 'text' => ''];
        }
    }

    /* ------------------------------------------------------------------ sending */

    /**
     * One text to one number, right now. Returns Meta's message id; throws on failure.
     * Outside the 24-hour window (the person has not written in a day) WhatsApp refuses free text: then the approved
     * template (one body variable) carries the same text, if the owner set one.
     */
    public static function send($to, $text) {
        $c = self::cfg();
        $to = Mutqan_WA::intl($to) ?: preg_replace('/\D/', '', (string) $to);
        if ($to === '') { throw new Mutqan_Error('bad_input', 'رقم غير صالح.', 400); }
        $r = self::call(['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => mb_substr((string) $text, 0, 4096), 'preview_url' => true]]);
        if ($r['ok']) { return $r['id']; }
        if (in_array($r['code'], [131047, 131026], true) && $c['template'] !== '') {
            // Template variables cannot hold line breaks, tabs or long runs of spaces.
            $flat = trim(preg_replace('/ {4,}/', '   ', preg_replace('/[\r\n\t]+/u', ' · ', (string) $text)));
            $r2 = self::call(['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => [
                'name' => $c['template'], 'language' => ['code' => $c['lang'] ?: 'ar'],
                'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => mb_substr($flat, 0, 1000)]]]],
            ]]);
            if ($r2['ok']) { return $r2['id']; }
            $r = $r2;
        }
        self::log('إرسال لـ ' . $to . ' فشل: ' . $r['error']);
        throw new Mutqan_Error('wa_failed', 'واتساب: ' . $r['error'], 502);
    }

    /** Any Graph call (POST) with the stored token: used by «تفعيل الرقم». */
    private static function graph_post($path, $body) {
        $c = self::cfg();
        $res = wp_remote_post(self::GRAPH . ltrim($path, '/'), ['timeout' => 20, 'headers' => ['Authorization' => 'Bearer ' . $c['token'], 'Content-Type' => 'application/json'], 'body' => wp_json_encode($body)]);
        if (is_wp_error($res)) { return 'خطأ اتصال: ' . $res->get_error_message(); }
        $j = json_decode(wp_remote_retrieve_body($res), true);
        if (!empty($j['success'])) { return ''; }
        return (string) ($j['error']['error_user_msg'] ?? ($j['error']['message'] ?? ('HTTP ' . wp_remote_retrieve_response_code($res))));
    }

    /**
     * One-time activation of the number on Cloud API: register it (with a 6-digit two-step PIN the owner chooses) and
     * subscribe this app to the WhatsApp account so Meta starts calling the webhook. Returns a line for the owner.
     */
    public static function activate($pin) {
        $c = self::cfg();
        if ($c['phone_id'] === '' || $c['waba_id'] === '' || $c['token'] === '') { return 'لازم Phone number ID وWhatsApp Business Account ID والـ Access token يتحفظوا الأول.'; }
        if (!preg_match('/^\d{6}$/', (string) $pin)) { return 'الـ PIN لازم 6 أرقام.'; }
        $out = [];
        $e1 = self::graph_post(rawurlencode($c['phone_id']) . '/register', ['messaging_product' => 'whatsapp', 'pin' => (string) $pin]);
        $out[] = $e1 === '' ? 'الرقم اتسجّل على Cloud API ✅' : 'تسجيل الرقم: ' . $e1;
        $e2 = self::graph_post(rawurlencode($c['waba_id']) . '/subscribed_apps', []);
        $out[] = $e2 === '' ? 'التطبيق اشترك في رسايل الحساب ✅' : 'الاشتراك: ' . $e2;
        self::log(implode(' | ', $out));
        return implode(' — ', $out);
    }

    private static function call($body) {
        $c = self::cfg();
        $res = wp_remote_post(self::GRAPH . rawurlencode($c['phone_id']) . '/messages', [
            'timeout' => 15, 'headers' => ['Authorization' => 'Bearer ' . $c['token'], 'Content-Type' => 'application/json'], 'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($res)) { return ['ok' => false, 'code' => 0, 'error' => $res->get_error_message(), 'id' => '']; }
        $j = json_decode(wp_remote_retrieve_body($res), true);
        $id = (string) ($j['messages'][0]['id'] ?? '');
        if ($id !== '') { return ['ok' => true, 'code' => 0, 'error' => '', 'id' => $id]; }
        return ['ok' => false, 'code' => (int) ($j['error']['code'] ?? wp_remote_retrieve_response_code($res)), 'error' => (string) ($j['error']['message'] ?? ('HTTP ' . wp_remote_retrieve_response_code($res))), 'id' => ''];
    }

    /* ------------------------------------------------------------------ settings page (owner only) */

    public static function page() {
        if (!current_user_can('manage_options')) { return; }
        $c = self::cfg(); $msg = '';
        if (!empty($_POST['mq_meta_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mq_meta_nonce'])), 'mq_meta')) {
            foreach (['phone_id', 'waba_id', 'template', 'lang', 'number'] as $k) { $c[$k] = sanitize_text_field(wp_unslash($_POST[$k] ?? $c[$k])); }
            foreach (['token', 'app_secret', 'verify'] as $k) { $v = trim((string) wp_unslash($_POST[$k] ?? '')); if ($v !== '') { $c[$k] = sanitize_text_field($v); } }   // blank = keep
            $c['on'] = !empty($_POST['on']) ? 1 : 0;
            update_option(self::OPT, $c, false); $msg = 'اتحفظ.';
            if (!empty($_POST['register_pin'])) { $msg .= ' ' . self::activate(preg_replace('/\D/', '', (string) wp_unslash($_POST['register_pin']))); }
            if (!empty($_POST['test_to'])) {
                try { self::send(sanitize_text_field(wp_unslash($_POST['test_to'])), 'رسالة تجربة من الموقع ✅'); $msg .= ' ورسالة التجربة اتبعتت.'; }
                catch (Throwable $e) { $msg .= ' التجربة فشلت: ' . $e->getMessage(); }
            }
        }
        $f = function ($k, $label, $secret = false) use ($c) {
            $val = $secret ? '' : esc_attr($c[$k]);
            $ph = $secret ? ($c[$k] !== '' ? 'محفوظ — سيبه فاضي عشان يفضل زي ما هو' : '') : '';
            echo '<tr><th><label for="' . esc_attr($k) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" dir="ltr" type="' . ($secret ? 'password' : 'text') . '" id="' . esc_attr($k) . '" name="' . esc_attr($k) . '" value="' . $val . '" placeholder="' . esc_attr($ph) . '" autocomplete="off"></td></tr>';
        };
        echo '<div class="wrap" dir="rtl"><h1>واتساب الرسمي (Meta Cloud API)</h1>';
        if ($msg) { echo '<div class="notice notice-info"><p>' . esc_html($msg) . '</p></div>'; }
        echo '<p>رابط الـ Webhook اللي يتحط في Meta: <code dir="ltr">' . esc_html(self::hook_url()) . '</code> — والحقول: <code>messages</code> و<code>smb_message_echoes</code>.</p>';
        echo '<form method="post">'; wp_nonce_field('mq_meta', 'mq_meta_nonce');
        echo '<table class="form-table">';
        echo '<tr><th>تشغيل</th><td><label><input type="checkbox" name="on" value="1" ' . checked(!empty($c['on']), true, false) . '> كل رسايل الواتساب تمشي من هنا (بيوقف بوابة السيرفر والموبايل)</label></td></tr>';
        $f('number', 'الرقم (للعرض)'); $f('phone_id', 'Phone number ID'); $f('waba_id', 'WhatsApp Business Account ID');
        $f('token', 'Access token (دائم)', true); $f('app_secret', 'App secret', true); $f('verify', 'Verify token (أي كلمة تختارها)', true);
        $f('template', 'اسم القالب لرسايل بعد 24 ساعة (اختياري)'); $f('lang', 'لغة القالب');
        echo '<tr><th>تفعيل الرقم (مرة واحدة)</th><td><input class="regular-text" dir="ltr" type="password" name="register_pin" placeholder="PIN من 6 أرقام تختاره (التحقق بخطوتين)" autocomplete="off"><p class="description">بيسجّل الرقم على Cloud API ويشغّل وصول الرسايل للموقع. احفظ الـ PIN عندك.</p></td></tr>';
        echo '<tr><th>رقم تجربة</th><td><input class="regular-text" dir="ltr" name="test_to" placeholder="05xxxxxxxx — لازم يكون باعتلك في آخر 24 ساعة"></td></tr>';
        echo '</table>'; submit_button('حفظ'); echo '</form>';
        $log = get_option(self::LOG, []);
        if ($log) { echo '<h2>آخر الأحداث</h2><pre dir="rtl" style="background:#fff;padding:10px;max-height:300px;overflow:auto">' . esc_html(implode("\n", (array) $log)) . '</pre>'; }
        echo '</div>';
    }
}
