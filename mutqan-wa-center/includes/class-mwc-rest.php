<?php
if (!defined('ABSPATH')) exit;

class MWC_Rest {

    static function routes() {
        $auth = [__CLASS__, 'auth'];
        $open = '__return_true';
        $r = function ($path, $methods, $cb, $perm) {
            register_rest_route(MWC_NS, $path, ['methods' => $methods, 'callback' => $cb, 'permission_callback' => $perm]);
        };
        $r('/login', 'POST', [__CLASS__, 'login'], $open);
        $r('/me', 'GET', [__CLASS__, 'me'], $auth);
        $r('/bootstrap', 'GET', [__CLASS__, 'bootstrap'], $auth);
        $r('/poll', 'GET', [__CLASS__, 'poll'], $auth);
        $r('/chats', 'GET', [__CLASS__, 'chats'], $auth);
        $r('/chats/(?P<id>\d+)', 'POST', [__CLASS__, 'chat_update'], $auth);
        $r('/chats/(?P<id>\d+)/messages', 'GET', [__CLASS__, 'messages'], $auth);
        $r('/chats/(?P<id>\d+)/send', 'POST', [__CLASS__, 'send'], $auth);
        $r('/chats/(?P<id>\d+)/read', 'POST', [__CLASS__, 'read'], $auth);
        $r('/chats/(?P<id>\d+)/profile', 'GET', [__CLASS__, 'profile'], $auth);
        $r('/messages/(?P<id>\d+)/retry', 'POST', [__CLASS__, 'retry'], $auth);
        $r('/contacts/(?P<id>\d+)', 'POST', [__CLASS__, 'contact_update'], $auth);
        $r('/start', 'POST', [__CLASS__, 'start'], $auth);
        $r('/upload', 'POST', [__CLASS__, 'upload'], $auth);
        $r('/accounts', 'GET', [__CLASS__, 'accounts'], $auth);
        $r('/accounts', 'POST', [__CLASS__, 'account_save'], $auth);
        $r('/accounts/(?P<id>\d+)', 'DELETE', [__CLASS__, 'account_delete'], $auth);
        $r('/accounts/(?P<id>\d+)/connect', 'POST', [__CLASS__, 'account_connect'], $auth);
        $r('/accounts/(?P<id>\d+)/logout', 'POST', [__CLASS__, 'account_logout'], $auth);
        $r('/config', 'GET', [__CLASS__, 'config_get'], $auth);
        $r('/config', 'POST', [__CLASS__, 'config_save'], $auth);
        $r('/actions/run', 'POST', [__CLASS__, 'action_run'], $auth);
        // Webhooks (مفتوحة لكن محمية بسر/توقيع)
        $r('/webhook/meta', 'GET', [__CLASS__, 'meta_verify'], $open);
        $r('/webhook/meta', 'POST', [__CLASS__, 'meta_hook'], $open);
        $r('/webhook/evolution', 'POST', [__CLASS__, 'evo_hook'], $open);
    }

    /* ---------------- auth ---------------- */
    static function auth(WP_REST_Request $req) {
        if (current_user_can('manage_options')) return true;
        $tok = $req->get_header('x_mwc_token') ?: $req->get_param('token');
        $who = MWC_Store::check_token($tok);
        if ($who === null) return new WP_Error('mwc_auth', 'سجّل دخول بالرقم السري', ['status' => 401]);
        $GLOBALS['mwc_user'] = $who;
        return true;
    }
    static function who() { return $GLOBALS['mwc_user'] ?? (wp_get_current_user()->display_name ?: 'المالك'); }

    static function login(WP_REST_Request $req) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
        $k = 'mwc_fail_' . md5($ip);
        $fails = (int) get_transient($k);
        if ($fails >= 8) return new WP_Error('mwc_locked', 'محاولات كثيرة — جرّب بعد ربع ساعة', ['status' => 429]);
        $hash = get_option('mwc_pin_hash');
        if (!$hash) return new WP_Error('mwc_nopin', 'لسه ما اتحددش رقم سري — حدّده من لوحة ووردبريس ← مركز الواتساب', ['status' => 400]);
        $pin = (string) $req->get_param('pin');
        if (!wp_check_password($pin, $hash)) {
            set_transient($k, $fails + 1, 15 * MINUTE_IN_SECONDS);
            return new WP_Error('mwc_bad', 'الرقم السري غلط', ['status' => 401]);
        }
        delete_transient($k);
        $name = sanitize_text_field($req->get_param('name') ?: 'الإدارة');
        return ['token' => MWC_Store::new_token($name), 'name' => $name];
    }

    static function me() { return ['name' => self::who()]; }

    /* ---------------- shaping ---------------- */
    static function chat_out($c) {
        return [
            'id' => (int) $c['id'], 'account_id' => (int) $c['account_id'], 'contact_id' => (int) $c['contact_id'],
            'wa_id' => $c['wa_id'], 'name' => $c['name'] ?: '', 'push_name' => $c['push_name'] ?: '',
            'avatar' => $c['avatar'] ?: '', 'preview' => $c['preview'], 'last_ts' => (int) $c['last_ts'], 'last_in_ts' => (int) $c['last_in_ts'],
            'unread' => (int) $c['unread'], 'pinned' => (int) $c['pinned'], 'archived' => (int) $c['archived'],
            'labels' => $c['labels'] ? explode(',', $c['labels']) : [], 'assigned' => $c['assigned'], 'status' => $c['status'],
            'tags' => $c['tags'] ?? '', 'source' => $c['source'] ?? '',
        ];
    }
    static function msg_out($m) {
        return [
            'id' => (int) $m['id'], 'chat_id' => (int) $m['chat_id'], 'dir' => $m['direction'], 'type' => $m['type'],
            'body' => $m['body'] ?? '', 'media_url' => $m['media_url'], 'mime' => $m['mime'], 'file_name' => $m['file_name'],
            'duration' => (int) $m['duration'], 'status' => $m['status'], 'error' => $m['error'], 'sender' => $m['sender'],
            'ts' => (int) $m['ts'], 'ext_id' => $m['ext_id'], 'reply_to' => $m['reply_to'],
        ];
    }

    /* ---------------- reading ---------------- */
    static function bootstrap() {
        return ['me' => self::who(), 'now' => time(), 'accounts' => MWC_Store::accounts(), 'config' => MWC_Store::config(),
                'chats' => array_map([__CLASS__, 'chat_out'], MWC_Store::chats(['limit' => 300]))];
    }

    static function poll(WP_REST_Request $req) {
        $since = max(0, (int) $req->get_param('since') - 2);
        $acc = (int) $req->get_param('account');
        return [
            'now' => time(),
            'chats' => array_map([__CLASS__, 'chat_out'], MWC_Store::chats(['since' => $since, 'account' => $acc, 'limit' => 200])),
            'messages' => array_map([__CLASS__, 'msg_out'], MWC_Store::messages_since($since, $acc)),
            'accounts' => array_map(function ($a) { return ['id' => $a['id'], 'status' => $a['status']]; }, MWC_Store::accounts()),
        ];
    }

    static function chats(WP_REST_Request $req) {
        return array_map([__CLASS__, 'chat_out'], MWC_Store::chats([
            'account' => (int) $req->get_param('account'), 'q' => sanitize_text_field((string) $req->get_param('q')),
            'archived' => (int) $req->get_param('archived'), 'limit' => 300]));
    }

    static function messages(WP_REST_Request $req) {
        return array_map([__CLASS__, 'msg_out'], MWC_Store::messages((int) $req['id'], (int) $req->get_param('before')));
    }

    static function profile(WP_REST_Request $req) {
        $chat = MWC_Store::chat((int) $req['id']);
        if (!$chat) return new WP_Error('nf', 'غير موجود', ['status' => 404]);
        $acc = MWC_Store::account($chat['account_id']);
        $fresh = (int) $req->get_param('refresh');
        if ($acc && ($fresh || !$chat['avatar'])) {
            try { $p = MWC_Provider::make($acc)->profile($chat['wa_id']); } catch (\Throwable $e) { $p = []; }
            if ($p) { MWC_Store::contact_upsert($chat['wa_id'], $p); $chat = MWC_Store::chat($chat['id']); }
        }
        global $wpdb;
        $others = $wpdb->get_results($wpdb->prepare("SELECT id, account_id, last_ts FROM " . MWC_Store::t('chats') . " WHERE contact_id=%d AND id<>%d", $chat['contact_id'], $chat['id']), ARRAY_A);
        return [
            'chat' => self::chat_out($chat),
            'contact' => ['id' => (int) $chat['contact_id'], 'wa_id' => $chat['wa_id'], 'name' => $chat['name'], 'push_name' => $chat['push_name'],
                'avatar' => $chat['avatar'], 'about' => $chat['about'], 'notes' => $chat['notes'], 'tags' => $chat['tags'], 'source' => $chat['source']],
            'media' => MWC_Store::media($chat['id']),
            'other_chats' => $others,
            'window_open' => (time() - (int) $chat['last_in_ts']) < DAY_IN_SECONDS,
            'provider' => $acc['provider'] ?? '',
        ];
    }

    /* ---------------- writing ---------------- */
    static function chat_update(WP_REST_Request $req) {
        $f = [];
        foreach (['pinned', 'archived', 'unread'] as $k) if ($req->has_param($k)) $f[$k] = (int) $req->get_param($k);
        foreach (['assigned', 'status'] as $k) if ($req->has_param($k)) $f[$k] = sanitize_text_field($req->get_param($k));
        if ($req->has_param('labels')) $f['labels'] = implode(',', array_map('sanitize_text_field', (array) $req->get_param('labels')));
        if ($f) MWC_Store::update_chat((int) $req['id'], $f);
        return self::chat_out(MWC_Store::chat((int) $req['id']));
    }

    static function contact_update(WP_REST_Request $req) {
        global $wpdb;
        $f = [];
        foreach (['name', 'tags', 'source'] as $k) if ($req->has_param($k)) $f[$k] = sanitize_text_field($req->get_param($k));
        if ($req->has_param('notes')) $f['notes'] = sanitize_textarea_field($req->get_param('notes'));
        if ($f) { $f['updated'] = time(); $wpdb->update(MWC_Store::t('contacts'), $f, ['id' => (int) $req['id']]); }
        $wpdb->query($wpdb->prepare("UPDATE " . MWC_Store::t('chats') . " SET changed=%d WHERE contact_id=%d", time(), (int) $req['id']));
        return MWC_Store::contact((int) $req['id']);
    }

    static function read(WP_REST_Request $req) {
        global $wpdb;
        $chat = MWC_Store::chat((int) $req['id']);
        if (!$chat) return new WP_Error('nf', 'غير موجود', ['status' => 404]);
        if ((int) $chat['unread'] > 0) {
            $ids = $wpdb->get_col($wpdb->prepare("SELECT ext_id FROM " . MWC_Store::t('messages') . " WHERE chat_id=%d AND direction='in' AND ext_id<>'' ORDER BY id DESC LIMIT %d", $chat['id'], max(1, (int) $chat['unread'])));
            $acc = MWC_Store::account($chat['account_id']);
            if ($acc && !empty($acc['config']['send_read'])) { try { MWC_Provider::make($acc)->mark_read($chat['wa_id'], array_reverse($ids)); } catch (\Throwable $e) {} }
            MWC_Store::update_chat($chat['id'], ['unread' => 0]);
        }
        return ['ok' => true];
    }

    /** إرسال من أي رقم — نفس الدالة بيستخدمها أي نظام تاني (الـdesk، البوت) عن طريق mwc_send() */
    static function do_send($chat_id, $d, $sender = '') {
        $chat = MWC_Store::chat($chat_id);
        if (!$chat) return new WP_Error('nf', 'المحادثة غير موجودة', ['status' => 404]);
        $acc = MWC_Store::account($chat['account_id']);
        if (!$acc) return new WP_Error('nf', 'الرقم غير موجود', ['status' => 404]);
        $types = ['text', 'image', 'video', 'audio', 'voice', 'document', 'sticker', 'location', 'template'];
        $type = in_array($d['type'] ?? 'text', $types, true) ? $d['type'] : 'text';
        $body = (string) ($d['body'] ?? '');
        if ($type === 'template') $body = $body ?: ('قالب: ' . ($d['template']['name'] ?? ''));
        if ($type === 'text' && trim($body) === '') return new WP_Error('empty', 'الرسالة فاضية', ['status' => 400]);
        $m = [
            'chat_id' => $chat['id'], 'direction' => 'out', 'type' => $type, 'body' => $body,
            'media_url' => esc_url_raw($d['media_url'] ?? ''), 'mime' => sanitize_text_field($d['mime'] ?? ''),
            'file_name' => sanitize_file_name($d['file_name'] ?? ''), 'duration' => (int) ($d['duration'] ?? 0),
            'reply_to' => sanitize_text_field($d['reply_to'] ?? ''), 'status' => 'pending', 'sender' => $sender ?: self::who(), 'ts' => time(),
        ];
        $id = MWC_Store::add_message($m);
        try { $res = MWC_Provider::make($acc)->send($chat['wa_id'], $m + ['template' => $d['template'] ?? []]); }
        catch (\Throwable $e) { $res = ['ok' => false, 'error' => $e->getMessage()]; }
        MWC_Store::update_message($id, $res['ok']
            ? ['status' => 'sent', 'ext_id' => $res['ext_id'], 'error' => '']
            : ['status' => 'failed', 'error' => mb_substr($res['error'] ?? 'فشل', 0, 250)]);
        MWC_Store::touch_chat($chat['id'], self::preview_of($m), time(), false);
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . MWC_Store::t('messages') . " WHERE id=%d", $id), ARRAY_A);
        do_action('mwc_sent', $row, $chat, $acc);
        return self::msg_out($row);
    }

    static function send(WP_REST_Request $req) { return self::do_send((int) $req['id'], $req->get_json_params() ?: $req->get_params()); }

    static function retry(WP_REST_Request $req) {
        global $wpdb;
        $m = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . MWC_Store::t('messages') . " WHERE id=%d AND direction='out'", (int) $req['id']), ARRAY_A);
        if (!$m) return new WP_Error('nf', 'غير موجودة', ['status' => 404]);
        $wpdb->delete(MWC_Store::t('messages'), ['id' => $m['id']]);
        return self::do_send($m['chat_id'], $m);
    }

    static function start(WP_REST_Request $req) {
        $acc = MWC_Store::account((int) $req->get_param('account_id'));
        $phone = MWC_Store::norm_phone($req->get_param('phone'));
        if (!$acc || strlen($phone) < 8) return new WP_Error('bad', 'اختار الرقم واكتب رقم العميل صح', ['status' => 400]);
        $cid = MWC_Store::contact_upsert($phone, ['name' => sanitize_text_field($req->get_param('name') ?: '')]);
        $chat_id = MWC_Store::chat_get_or_create($acc['id'], $cid);
        MWC_Store::update_chat($chat_id, ['archived' => 0]);
        return self::chat_out(MWC_Store::chat($chat_id));
    }

    static function upload(WP_REST_Request $req) {
        $files = $req->get_file_params();
        $f = $files['file'] ?? null;
        if (!$f || !empty($f['error']) || !is_uploaded_file($f['tmp_name'])) return new WP_Error('up', 'مفيش ملف', ['status' => 400]);
        if ($f['size'] > 100 * 1024 * 1024) return new WP_Error('big', 'الملف أكبر من 100 ميجا', ['status' => 400]);
        $mime = $f['type'] ?: 'application/octet-stream';
        if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); $det = finfo_file($fi, $f['tmp_name']); if ($det && $det !== 'application/octet-stream') $mime = (strpos($f['type'], $det) === 0) ? $f['type'] : $det; }
        $url = MWC_Store::save_media_bytes(file_get_contents($f['tmp_name']), $mime, $f['name']);
        return ['url' => $url, 'mime' => $mime, 'name' => sanitize_file_name($f['name']), 'size' => (int) $f['size']];
    }

    /* ---------------- accounts & config ---------------- */
    static function accounts() { return MWC_Store::accounts(); }

    static function account_save(WP_REST_Request $req) {
        $id = MWC_Store::save_account($req->get_json_params() ?: []);
        foreach (MWC_Store::accounts() as $a) if ($a['id'] === $id) return $a;
        return ['id' => $id];
    }

    static function account_delete(WP_REST_Request $req) {
        global $wpdb;
        $wpdb->update(MWC_Store::t('accounts'), ['active' => 0], ['id' => (int) $req['id']]); // تعطيل فقط — المحادثات ما تتمسحش
        return ['ok' => true];
    }

    static function account_connect(WP_REST_Request $req) {
        $acc = MWC_Store::account((int) $req['id']);
        if (!$acc) return new WP_Error('nf', 'غير موجود', ['status' => 404]);
        try { $r = MWC_Provider::make($acc)->connect(); } catch (\Throwable $e) { $r = ['ok' => false, 'error' => $e->getMessage()]; }
        if (!empty($r['state'])) MWC_Store::set_account_status($acc['id'], $r['state']);
        return $r;
    }

    static function account_logout(WP_REST_Request $req) {
        $acc = MWC_Store::account((int) $req['id']);
        if ($acc && $acc['provider'] === 'evolution') { (new MWC_Evolution($acc))->logout(); MWC_Store::set_account_status($acc['id'], 'close'); }
        return ['ok' => true];
    }

    static function config_get() { return MWC_Store::config(); }

    static function config_save(WP_REST_Request $req) {
        $in = $req->get_json_params() ?: [];
        $cur = MWC_Store::config();
        $clean = function ($v) use (&$clean) {
            if (is_array($v)) return array_map($clean, $v);
            return is_string($v) ? wp_kses_post($v) : $v;
        };
        foreach (['quick_replies', 'labels', 'actions', 'vars', 'staff', 'sound'] as $k) if (isset($in[$k])) $cur[$k] = $clean($in[$k]);
        update_option('mwc_config', $cur, false);
        return $cur;
    }

    static function action_run(WP_REST_Request $req) {
        $cfg = MWC_Store::config();
        $i = (int) $req->get_param('index');
        $a = $cfg['actions'][$i] ?? null;
        $chat = MWC_Store::chat((int) $req->get_param('chat_id'));
        if (!$a || !$chat) return new WP_Error('bad', 'الزر أو المحادثة غير موجودة', ['status' => 400]);
        $data = ['action' => $a['label'], 'chat' => self::chat_out($chat), 'account' => MWC_Store::account($chat['account_id'])['label'] ?? '',
                 'phone' => $chat['wa_id'], 'name' => $chat['name'] ?: $chat['push_name'], 'by' => self::who()];
        do_action('mwc_action', $a, $data);
        if (!empty($a['payload']) && preg_match('#^https?://#', $a['payload'])) {
            $r = wp_remote_post($a['payload'], ['timeout' => 15, 'headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode($data)]);
            return ['ok' => !is_wp_error($r), 'code' => is_wp_error($r) ? 0 : wp_remote_retrieve_response_code($r)];
        }
        return ['ok' => true];
    }

    /* ---------------- incoming helpers ---------------- */
    static function preview_of($m) {
        $icons = ['image' => '📷 صورة', 'video' => '🎥 فيديو', 'audio' => '🎵 ملف صوتي', 'voice' => '🎤 رسالة صوتية', 'document' => '📄 ', 'sticker' => '🏷️ ملصق', 'location' => '📍 موقع', 'contact' => '👤 جهة اتصال'];
        $t = $m['type'];
        if ($t === 'text' || $t === 'template') return $m['body'];
        if ($t === 'document') return '📄 ' . ($m['file_name'] ?: 'ملف');
        return ($icons[$t] ?? '') . (!empty($m['body']) && $t !== 'location' ? ' ' . $m['body'] : '');
    }

    static function ingest($acc, $wa_id, $push_name, $m, $from_me = false) {
        $cid = MWC_Store::contact_upsert($wa_id, ['push_name' => $push_name]);
        if (!$cid) return 0;
        $chat_id = MWC_Store::chat_get_or_create($acc['id'], $cid);
        $m['chat_id'] = $chat_id;
        global $wpdb;
        if (!empty($m['ext_id'])) { // Meta/السيرفر ساعات بيبعتوا نفس الرسالة مرتين
            $dup = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . MWC_Store::t('messages') . " WHERE ext_id=%s AND chat_id=%d", $m['ext_id'], $chat_id));
            if ($dup) return (int) $dup;
        }
        $m['direction'] = $from_me ? 'out' : 'in';
        $m['status'] = $from_me ? 'sent' : 'received';
        if ($from_me) {
            // رسالة اتبعتت من التطبيق لسه ما اترقّمتش؟ نربطها بدل ما تتكرر
            global $wpdb;
            $pend = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . MWC_Store::t('messages') . " WHERE chat_id=%d AND direction='out' AND ext_id='' AND type=%s AND ts>%d ORDER BY id DESC LIMIT 1",
                $chat_id, $m['type'], time() - 90));
            if ($pend) { MWC_Store::update_message($pend, ['ext_id' => $m['ext_id']]); return (int) $pend; }
            $m['sender'] = 'من الموبايل';
        }
        $id = MWC_Store::add_message($m);
        MWC_Store::touch_chat($chat_id, self::preview_of($m), $m['ts'], !$from_me);
        if (!$from_me) do_action('mwc_incoming', $m + ['id' => $id], MWC_Store::chat($chat_id), $acc);
        return $id;
    }

    /* ---------------- Meta webhook ---------------- */
    static function meta_verify(WP_REST_Request $req) {
        if ($req->get_param('hub_mode') === 'subscribe' && hash_equals((string) get_option('mwc_meta_verify'), (string) $req->get_param('hub_verify_token'))) {
            header('Content-Type: text/plain'); echo $req->get_param('hub_challenge'); exit;
        }
        return new WP_Error('forbidden', 'bad token', ['status' => 403]);
    }

    static function meta_hook(WP_REST_Request $req) {
        $raw = $req->get_body();
        $p = json_decode($raw, true) ?: [];
        foreach (($p['entry'] ?? []) as $entry) foreach (($entry['changes'] ?? []) as $ch) {
            $v = $ch['value'] ?? [];
            $pnid = $v['metadata']['phone_number_id'] ?? '';
            $acc = MWC_Store::account_by('phone_number_id', $pnid);
            if (!$acc) continue;
            $secret = $acc['config']['app_secret'] ?? '';
            if ($secret) {
                $sig = $req->get_header('x_hub_signature_256');
                if (!$sig || !hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $sig)) return new WP_Error('sig', 'bad signature', ['status' => 401]);
            }
            MWC_Store::set_account_status($acc['id'], 'open');
            $names = [];
            foreach (($v['contacts'] ?? []) as $c) $names[$c['wa_id']] = $c['profile']['name'] ?? '';
            $prov = new MWC_Meta($acc);
            foreach (($v['messages'] ?? []) as $msg) {
                $t = $msg['type'] ?? 'text';
                $m = ['type' => $t, 'body' => '', 'media_url' => '', 'mime' => '', 'file_name' => '', 'ext_id' => $msg['id'] ?? '',
                      'reply_to' => $msg['context']['id'] ?? '', 'ts' => (int) ($msg['timestamp'] ?? time())];
                switch ($t) {
                    case 'text': $m['body'] = $msg['text']['body'] ?? ''; break;
                    case 'button': $m['type'] = 'text'; $m['body'] = $msg['button']['text'] ?? ''; break;
                    case 'interactive': $m['type'] = 'text'; $m['body'] = $msg['interactive']['button_reply']['title'] ?? ($msg['interactive']['list_reply']['title'] ?? ''); break;
                    case 'location': $l = $msg['location']; $m['body'] = wp_json_encode(['lat' => $l['latitude'], 'lng' => $l['longitude'], 'name' => $l['name'] ?? '', 'address' => $l['address'] ?? ''], JSON_UNESCAPED_UNICODE); break;
                    case 'contacts': $m['type'] = 'contact'; $c0 = $msg['contacts'][0] ?? [];
                        $m['body'] = wp_json_encode(['name' => $c0['name']['formatted_name'] ?? '', 'phones' => array_column($c0['phones'] ?? [], 'phone')], JSON_UNESCAPED_UNICODE); break;
                    case 'image': case 'video': case 'audio': case 'document': case 'sticker':
                        $o = $msg[$t];
                        if ($t === 'audio' && !empty($o['voice'])) $m['type'] = 'voice';
                        $m['body'] = $o['caption'] ?? ''; $m['file_name'] = $o['filename'] ?? ''; $m['mime'] = $o['mime_type'] ?? '';
                        $dl = $prov->download($o['id']);
                        if ($dl) { $m['media_url'] = $dl['url']; $m['mime'] = $dl['mime']; }
                        break;
                    case 'reaction': continue 2;
                    default: $m['type'] = 'text'; $m['body'] = '[' . $t . ']';
                }
                self::ingest($acc, $msg['from'], $names[$msg['from']] ?? '', $m);
            }
            foreach (($v['statuses'] ?? []) as $s) {
                MWC_Store::status_by_ext($s['id'], $s['status'], $s['errors'][0]['title'] ?? ($s['errors'][0]['message'] ?? ''));
            }
        }
        return ['ok' => true];
    }

    /* ---------------- Evolution webhook ---------------- */
    static function evo_hook(WP_REST_Request $req) {
        if (!hash_equals((string) get_option('mwc_webhook_secret'), (string) $req->get_param('s'))) return new WP_Error('forbidden', 'bad secret', ['status' => 403]);
        $p = $req->get_json_params() ?: [];
        $event = strtolower(str_replace('_', '.', $p['event'] ?? ''));
        $inst = $p['instance'] ?? '';
        $acc = MWC_Store::account_by('instance', $inst);
        if (!$acc && preg_match('/^mwc(\d+)$/', $inst, $mm)) $acc = MWC_Store::account((int) $mm[1]);
        if (!$acc) return ['ok' => false, 'reason' => 'unknown instance'];
        $data = $p['data'] ?? [];

        if ($event === 'connection.update') { MWC_Store::set_account_status($acc['id'], $data['state'] ?? 'unknown'); return ['ok' => true]; }
        if ($event === 'qrcode.updated') { MWC_Store::set_account_status($acc['id'], 'qr'); return ['ok' => true]; }

        if ($event === 'messages.update') {
            $list = isset($data[0]) ? $data : [$data];
            $map = ['SERVER_ACK' => 'sent', 'DELIVERY_ACK' => 'delivered', 'READ' => 'read', 'PLAYED' => 'read', 'ERROR' => 'failed'];
            foreach ($list as $u) {
                $id = $u['keyId'] ?? ($u['key']['id'] ?? ($u['messageId'] ?? ''));
                $st = $map[strtoupper($u['status'] ?? ($u['update']['status'] ?? ''))] ?? '';
                if ($id && $st) MWC_Store::status_by_ext($id, $st);
            }
            return ['ok' => true];
        }

        if ($event === 'messages.upsert' || $event === 'send.message') {
            $list = isset($data[0]) ? $data : (isset($data['messages']) ? $data['messages'] : [$data]);
            foreach ($list as $d) self::evo_message($acc, $d, $p);
            return ['ok' => true];
        }

        if ($event === 'contacts.upsert' || $event === 'contacts.update') {
            foreach ((isset($data[0]) ? $data : [$data]) as $c) {
                $jid = $c['remoteJid'] ?? ($c['id'] ?? '');
                if (!$jid || strpos($jid, '@g.us') !== false) continue;
                $f = ['push_name' => $c['pushName'] ?? '', '_auto' => 1];
                if (!empty($c['profilePicUrl'])) {
                    $img = wp_remote_get($c['profilePicUrl'], ['timeout' => 20]);
                    if (!is_wp_error($img) && wp_remote_retrieve_response_code($img) === 200) $f['avatar'] = MWC_Store::save_media_bytes(wp_remote_retrieve_body($img), 'image/jpeg');
                }
                global $wpdb; // نحدّث جهات موجودة بس — ما نضيفش كل جهات الموبايل
                if ($wpdb->get_var($wpdb->prepare("SELECT id FROM " . MWC_Store::t('contacts') . " WHERE wa_id=%s", MWC_Store::norm_phone($jid)))) MWC_Store::contact_upsert($jid, $f);
            }
        }
        return ['ok' => true];
    }

    static function evo_message($acc, $d, $p) {
        $key = $d['key'] ?? [];
        $jid = $key['remoteJid'] ?? '';
        if (!$jid || strpos($jid, '@g.us') !== false || strpos($jid, 'status@') === 0 || strpos($jid, '@newsletter') !== false || strpos($jid, '@broadcast') !== false) return;
        if (strpos($jid, '@lid') !== false) { $alt = $key['remoteJidAlt'] ?? ($key['senderPn'] ?? ($d['senderPn'] ?? '')); if ($alt) $jid = $alt; }
        $from_me = !empty($key['fromMe']);
        $msg = $d['message'] ?? [];
        if (isset($msg['ephemeralMessage']['message'])) $msg = $msg['ephemeralMessage']['message'];
        if (isset($msg['viewOnceMessageV2']['message'])) $msg = $msg['viewOnceMessageV2']['message'];
        if (isset($msg['protocolMessage']) || isset($msg['reactionMessage']) || isset($msg['senderKeyDistributionMessage']) && count($msg) === 1) return;
        $m = ['type' => 'text', 'body' => '', 'media_url' => '', 'mime' => '', 'file_name' => '', 'duration' => 0,
              'ext_id' => $key['id'] ?? '', 'ts' => (int) ($d['messageTimestamp'] ?? time()),
              'reply_to' => ''];
        $media = null;
        if (isset($msg['conversation'])) $m['body'] = $msg['conversation'];
        elseif (isset($msg['extendedTextMessage'])) { $m['body'] = $msg['extendedTextMessage']['text'] ?? ''; $m['reply_to'] = $msg['extendedTextMessage']['contextInfo']['stanzaId'] ?? ''; }
        elseif (isset($msg['imageMessage'])) { $m['type'] = 'image'; $media = $msg['imageMessage']; }
        elseif (isset($msg['videoMessage'])) { $m['type'] = 'video'; $media = $msg['videoMessage']; }
        elseif (isset($msg['audioMessage'])) { $m['type'] = !empty($msg['audioMessage']['ptt']) ? 'voice' : 'audio'; $media = $msg['audioMessage']; $m['duration'] = (int) ($media['seconds'] ?? 0); }
        elseif (isset($msg['documentMessage'])) { $m['type'] = 'document'; $media = $msg['documentMessage']; }
        elseif (isset($msg['documentWithCaptionMessage']['message']['documentMessage'])) { $m['type'] = 'document'; $media = $msg['documentWithCaptionMessage']['message']['documentMessage']; }
        elseif (isset($msg['stickerMessage'])) { $m['type'] = 'sticker'; $media = $msg['stickerMessage']; }
        elseif (isset($msg['locationMessage'])) { $l = $msg['locationMessage']; $m['type'] = 'location';
            $m['body'] = wp_json_encode(['lat' => $l['degreesLatitude'] ?? 0, 'lng' => $l['degreesLongitude'] ?? 0, 'name' => $l['name'] ?? '', 'address' => $l['address'] ?? ''], JSON_UNESCAPED_UNICODE); }
        elseif (isset($msg['contactMessage'])) { $m['type'] = 'contact'; $vc = $msg['contactMessage']['vcard'] ?? '';
            preg_match_all('/waid=(\d+)|TEL[^:]*:([+\d ]+)/', $vc, $mm);
            $m['body'] = wp_json_encode(['name' => $msg['contactMessage']['displayName'] ?? '', 'phones' => array_values(array_filter(array_map('trim', array_merge($mm[1], $mm[2]))))], JSON_UNESCAPED_UNICODE); }
        elseif (isset($msg['buttonsResponseMessage'])) $m['body'] = $msg['buttonsResponseMessage']['selectedDisplayText'] ?? '';
        elseif (isset($msg['listResponseMessage'])) $m['body'] = $msg['listResponseMessage']['title'] ?? '';
        else { $k = array_keys($msg); $k = array_values(array_diff($k, ['messageContextInfo'])); if (!$k) return; $m['body'] = '[' . $k[0] . ']'; }

        if ($media) {
            $m['body'] = $media['caption'] ?? '';
            $m['mime'] = $media['mimetype'] ?? '';
            $m['file_name'] = $media['fileName'] ?? ($media['title'] ?? '');
            $m['reply_to'] = $media['contextInfo']['stanzaId'] ?? '';
            $b64 = $d['message']['base64'] ?? ($d['base64'] ?? ($msg['base64'] ?? ''));
            if (!$b64) { $g = (new MWC_Evolution($acc))->get_media_base64($m['ext_id']); if ($g) { $b64 = $g['b64']; if (!$m['mime']) $m['mime'] = $g['mime']; } }
            if ($b64) $m['media_url'] = MWC_Store::save_media_bytes(base64_decode(preg_replace('#^data:[^,]+,#', '', $b64)), $m['mime'] ?: 'application/octet-stream', $m['file_name']);
        }
        // لو الرسالة متسجلة قبل كده (رسالة طالعة اتبعتت من التطبيق) ما نكررهاش
        global $wpdb;
        if ($m['ext_id'] && $wpdb->get_var($wpdb->prepare("SELECT id FROM " . MWC_Store::t('messages') . " WHERE ext_id=%s", $m['ext_id']))) return;
        self::ingest($acc, $jid, $from_me ? '' : ($d['pushName'] ?? ''), $m, $from_me);
    }
}

/** دالة عامة لأي نظام تاني (الـdesk، بوت الذكاء، إشعارات الطلبات): mwc_send(رقم_الحساب, رقم_العميل, النص) */
function mwc_send($account_id, $phone, $text, $extra = []) {
    $cid = MWC_Store::contact_upsert($phone, []);
    $chat_id = MWC_Store::chat_get_or_create((int) $account_id, $cid);
    return MWC_Rest::do_send($chat_id, array_merge(['type' => 'text', 'body' => $text], $extra), $extra['sender'] ?? 'النظام');
}
