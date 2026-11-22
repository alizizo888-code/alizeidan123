<?php
if (!defined('ABSPATH')) exit;

/**
 * v1.2 — مركز المدير والتوجيه + ربط البوت الذكي (wa-hub على السيرفر) + قوالب Meta + إشعارات التطبيق (APK).
 */
class MWC_Route {

    /* ======================= ربط البوت الذكي ======================= */
    static function hub() { $h = get_option('mwc_hub'); return is_array($h) ? array_merge(['url' => '', 'key' => '', 'on' => 1], $h) : ['url' => '', 'key' => '', 'on' => 0]; }

    /** كل رسالة (من العميل، أو رد موظف) بتروح لدماغ البوت على السيرفر — هو اللي يقرر يرد ولا يستنى الموظف */
    static function to_hub($payload) {
        $h = self::hub();
        if (empty($h['url']) || empty($h['key']) || empty($h['on'])) return false;
        $body = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
        wp_remote_post(rtrim($h['url'], '/') . '/center/in', ['blocking' => false, 'timeout' => 3, 'body' => $body,
            'headers' => ['Content-Type' => 'application/json', 'X-Mwc-Key' => $h['key']]]);
        return true;
    }

    static function boot() {
        // عميل كتب — والبوت مسموح له
        add_action('mwc_bot_incoming', function ($m, $chat, $acc) {
            self::to_hub(['account' => (int) $acc['id'], 'chat_id' => (int) $chat['id'], 'phone' => $chat['wa_id'], 'name' => $chat['name'] ?: $chat['push_name'],
                'text' => in_array($m['type'], ['text', 'template'], true) ? $m['body'] : ($m['type'] === 'location' ? self::loc_url($m['body']) : ($m['body'] ?? '')),
                'kind' => $m['type'] === 'template' ? 'text' : $m['type'], 'source' => $chat['source'] ?? '', 'brand' => $acc['config']['brand'] ?? '', 'fromMe' => false]);
        }, 10, 3);
        // موظف رد بنفسه (من التطبيق أو من الموبايل): البوت يعرف إن فيه بني آدم شغال على المحادثة
        add_action('mwc_sent', function ($row, $chat, $acc) {
            if (!$row || in_array($row['sender'], ['المساعد الذكي'], true) || strpos((string) $row['sender'], 'حملة') === 0) return;
            self::to_hub(['account' => (int) $acc['id'], 'chat_id' => (int) $chat['id'], 'phone' => $chat['wa_id'], 'text' => (string) $row['body'], 'kind' => $row['type'], 'fromMe' => true]);
        }, 10, 3);
        add_action('mwc_incoming', function ($m, $chat, $acc) { self::auto_assign($chat, $acc); }, 5, 3);
        add_action('mwc_from_phone', function ($m, $chat, $acc) {
            self::to_hub(['account' => (int) $acc['id'], 'chat_id' => (int) $chat['id'], 'phone' => $chat['wa_id'], 'text' => (string) ($m['body'] ?? ''), 'kind' => $m['type'], 'fromMe' => true]);
        }, 10, 3);
    }

    static function loc_url($json) { $l = json_decode((string) $json, true) ?: []; return isset($l['lat']) ? 'https://maps.google.com/?q=' . $l['lat'] . ',' . $l['lng'] : ''; }

    /** البوت بيرد من نفس رقم العميل:  POST /ext/send  {account, phone, text, image?} */
    static function ext_send(WP_REST_Request $q) {
        $h = self::hub();
        if (empty($h['key']) || !hash_equals((string) $h['key'], (string) ($q->get_header('x_mwc_key') ?: $q->get_param('k')))) return new WP_Error('forbidden', 'bad key', ['status' => 403]);
        $b = $q->get_json_params() ?: [];
        $acc = MWC_Store::account((int) ($b['account'] ?? 0));
        if (!$acc) return new WP_Error('acc', 'unknown account', ['status' => 404]);
        $phone = MWC_Leads::norm($b['phone'] ?? '') ?: MWC_Store::norm_phone($b['phone'] ?? '');
        $cid = MWC_Store::contact_upsert($phone, []);
        $chat_id = MWC_Store::chat_get_or_create($acc['id'], $cid);
        $chat = MWC_Store::chat($chat_id);
        // اتقفل للعميل ده بعد ما البوت قرر يرد؟ ما نبعتش
        if (!MWC_Rest::bot_allowed($chat, $acc) && empty($b['force'])) return ['ok' => false, 'skipped' => 'bot_off'];
        $GLOBALS['mwc_user_obj'] = MWC_Users::owner() + ['name' => 'المساعد الذكي'];
        $text = (string) ($b['text'] ?? '');
        $img = (string) ($b['image'] ?? '');
        $p = $img && preg_match('#^https://#', $img) ? self::remote_media($img, $text) : ['type' => 'text', 'body' => $text];
        $m = MWC_Rest::do_send($chat_id, $p, 'المساعد الذكي');
        return is_wp_error($m) ? $m : ['ok' => $m['status'] !== 'failed', 'id' => $m['id'], 'error' => $m['error']];
    }

    static function remote_media($url, $caption) {
        $r = wp_remote_get($url, ['timeout' => 20]);
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) return ['type' => 'text', 'body' => $caption];
        $mime = wp_remote_retrieve_header($r, 'content-type') ?: 'image/jpeg';
        return ['type' => 'image', 'body' => $caption, 'media_url' => MWC_Store::save_media_bytes(wp_remote_retrieve_body($r), $mime), 'mime' => $mime];
    }

    /* ======================= التوجيه ======================= */
    static function routing() {
        $r = get_option('mwc_routing');
        return array_merge(['technicians' => [], 'auto' => [], 'manager_account' => 0, 'room_phone' => '966536554731', 'notify_manager' => 0, 'rr' => []], is_array($r) ? $r : []);
    }

    static function save_routing($in) {
        $r = self::routing();
        if (isset($in['technicians'])) $r['technicians'] = array_values(array_filter(array_map(function ($t) {
            return ['name' => sanitize_text_field($t['name'] ?? ''), 'phone' => MWC_Leads::norm($t['phone'] ?? ''), 'branch' => sanitize_text_field($t['branch'] ?? ''), 'skill' => sanitize_text_field($t['skill'] ?? '')];
        }, (array) $in['technicians']), function ($t) { return $t['name'] !== '' && $t['phone'] !== ''; }));
        if (isset($in['auto'])) { $a = []; foreach ((array) $in['auto'] as $acc => $names) $a[(int) $acc] = array_values(array_filter(array_map('sanitize_text_field', (array) $names))); $r['auto'] = $a; }
        foreach (['manager_account', 'notify_manager'] as $k) if (isset($in[$k])) $r[$k] = (int) $in[$k];
        if (isset($in['room_phone'])) $r['room_phone'] = MWC_Leads::norm($in['room_phone']);
        update_option('mwc_routing', $r, false);
        MWC_Store::log('routing', 'تعديل إعدادات التوجيه والفنيين');
        return $r;
    }

    /** محادثة جديدة مالهاش مسؤول: تتوزع بالدور على موظفين الرقم ده */
    static function auto_assign($chat, $acc) {
        if (!$chat || $chat['assigned'] !== '') return;
        $r = self::routing();
        $names = $r['auto'][(int) $acc['id']] ?? [];
        if (!$names) return;
        $i = ((int) ($r['rr'][(int) $acc['id']] ?? 0)) % count($names);
        MWC_Store::update_chat($chat['id'], ['assigned' => $names[$i]]);
        $r['rr'][(int) $acc['id']] = $i + 1;
        update_option('mwc_routing', $r, false);
    }

    static function manager_account() {
        $r = self::routing();
        if ($r['manager_account'] && ($a = MWC_Store::account($r['manager_account'])) && $a['active']) return $a;
        foreach (MWC_Store::accounts(true) as $a) if ($a['role'] === 'manager' && $a['active'] && $a['status'] === 'open') return $a;
        foreach (MWC_Store::accounts(true) as $a) if ($a['active'] && $a['status'] === 'open' && $a['role'] !== 'outreach') return $a;
        return null;
    }

    /** كارت العميل: اسم، رقم، موقع، مصدر، آخر كلام */
    static function card($chat, $note = '') {
        global $wpdb;
        $loc = $wpdb->get_var($wpdb->prepare("SELECT body FROM " . MWC_Store::t('messages') . " WHERE chat_id=%d AND type='location' ORDER BY id DESC LIMIT 1", $chat['id']));
        $last = $wpdb->get_col($wpdb->prepare("SELECT body FROM " . MWC_Store::t('messages') . " WHERE chat_id=%d AND direction='in' AND type='text' ORDER BY id DESC LIMIT 3", $chat['id']));
        $acc = MWC_Store::account($chat['account_id']);
        $name = $chat['name'] ?: ($chat['push_name'] ?: 'عميل');
        $local = preg_match('/^966([15]\d{8})$/', $chat['wa_id'], $mm) ? '0' . $mm[1] : '+' . $chat['wa_id'];
        $t = "👤 *{$name}*\n📞 {$local}\nwa.me/{$chat['wa_id']}";
        if ($loc) $t .= "\n📍 " . self::loc_url($loc);
        if (!empty($chat['source'])) $t .= "\n🧭 المصدر: " . $chat['source'];
        $t .= "\n📱 كلّمنا على: " . ($acc['label'] ?? '');
        if ($last) $t .= "\n💬 «" . mb_substr(implode(' / ', array_reverse($last)), 0, 300) . '»';
        if ($note !== '') $t .= "\n📝 " . $note;
        return $t;
    }

    /** تحويل: لموظف / لفني / لغرفة العمليات / لرقم تاني */
    static function transfer(WP_REST_Request $q) {
        $b = $q->get_json_params() ?: [];
        $chat = MWC_Rest::chat_guard((int) ($b['chat_id'] ?? 0), 'chat_send');
        if (is_wp_error($chat)) return $chat;
        $to = sanitize_key($b['to'] ?? '');
        $note = sanitize_textarea_field($b['note'] ?? '');
        $who = MWC_Users::name();
        switch ($to) {
            case 'staff':
                $name = sanitize_text_field($b['staff'] ?? '');
                MWC_Store::update_chat($chat['id'], ['assigned' => $name]);
                if ($note !== '') MWC_Store::contact_upsert($chat['wa_id'], ['notes' => trim(($chat['notes'] ?? '') . "\n[" . $who . ' → ' . $name . '] ' . $note)]);
                MWC_Store::log('routing', 'تحويل ' . ($chat['name'] ?: $chat['wa_id']) . ' للموظف ' . $name);
                return ['ok' => true, 'msg' => 'اتحوّل لـ ' . $name];
            case 'tech':
            case 'room':
                $r = self::routing();
                $mgr = self::manager_account();
                if (!$mgr) return new WP_Error('mgr', 'مفيش رقم مدير متصل يبعت منه — اربط رقم المدير أو أي رقم', ['status' => 400]);
                if ($to === 'tech') {
                    $t = $r['technicians'][(int) ($b['tech'] ?? -1)] ?? null;
                    if (!$t) return new WP_Error('tech', 'اختار الفني', ['status' => 400]);
                    $phone = $t['phone']; $label = 'الفني ' . $t['name'];
                    $head = "🛠️ *مهمة جديدة* من " . ($mgr['config']['brand'] ?? 'الإدارة') . "\n";
                } else {
                    $phone = $r['room_phone']; $label = 'غرفة العمليات';
                    $head = "📥 *عميل محوّل للعمليات* بواسطة {$who}\n";
                }
                if (!$phone) return new WP_Error('ph', 'رقم الوجهة مش متظبط', ['status' => 400]);
                $cid = MWC_Store::contact_upsert($phone, ['name' => $label, 'bot' => 'off', '_keep_source' => 1, 'source' => 'فريق العمل']);
                $tchat = MWC_Store::chat_get_or_create($mgr['id'], $cid);
                $m = MWC_Rest::do_send($tchat, ['type' => 'text', 'body' => $head . self::card($chat, $note)], $who);
                if (is_wp_error($m)) return $m;
                if ($m['status'] === 'failed') return new WP_Error('send', 'ما اتبعتش: ' . $m['error'], ['status' => 502]);
                $labels = array_filter(explode(',', $chat['labels']));
                $labels[] = $to === 'tech' ? 'مع فني' : 'العمليات';
                MWC_Store::update_chat($chat['id'], ['labels' => implode(',', array_unique($labels)), 'assigned' => $to === 'tech' ? $label : $chat['assigned']]);
                MWC_Store::log('routing', 'تحويل ' . ($chat['name'] ?: $chat['wa_id']) . ' إلى ' . $label . ' من رقم ' . $mgr['label']);
                return ['ok' => true, 'msg' => 'اتبعت لـ ' . $label . ' من رقم ' . $mgr['label']];
            case 'account':
                $acc = MWC_Store::account((int) ($b['account'] ?? 0));
                if (!$acc || !MWC_Users::can_account($acc['id'])) return new WP_Error('acc', 'اختار الرقم', ['status' => 400]);
                $nchat = MWC_Store::chat_get_or_create($acc['id'], $chat['contact_id']);
                if (!empty($b['intro'])) MWC_Rest::do_send($nchat, ['type' => 'text', 'body' => sanitize_textarea_field($b['intro'])], $who);
                MWC_Store::update_chat($nchat, ['archived' => 0]);
                MWC_Store::log('routing', 'نقل ' . ($chat['name'] ?: $chat['wa_id']) . ' لرقم ' . $acc['label']);
                return ['ok' => true, 'chat' => MWC_Rest::chat_out(MWC_Store::chat($nchat)), 'msg' => 'اتنقل لرقم ' . $acc['label']];
        }
        return new WP_Error('to', 'وجهة غير معروفة', ['status' => 400]);
    }

    /** لوحة المدير: حالة كل رقم + المحادثات اللي مستنية رد + ضغط الموظفين */
    static function dashboard() {
        global $wpdb;
        $al = MWC_Users::allowed_accounts();
        $C = MWC_Store::t('chats'); $M = MWC_Store::t('messages'); $K = MWC_Store::t('contacts');
        $day = strtotime(MWC_Store::riyadh('Y-m-d') . ' 00:00:00 Asia/Riyadh');
        $accs = [];
        foreach (MWC_Rest::my_accounts() as $a) {
            $id = (int) $a['id'];
            $accs[] = ['id' => $id, 'label' => $a['label'], 'phone' => $a['phone'], 'role' => $a['role'], 'branch' => $a['branch'], 'color' => $a['color'], 'status' => $a['status'], 'provider' => $a['provider'],
                'unread' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $C WHERE account_id=%d AND unread>0 AND archived=0", $id)),
                'waiting' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $C c WHERE c.account_id=%d AND c.archived=0 AND c.last_in_ts>0 AND c.last_in_ts >= c.last_ts", $id)),
                'in_today' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $M m JOIN $C c ON c.id=m.chat_id WHERE c.account_id=%d AND m.direction='in' AND m.ts>=%d", $id, $day)),
                'out_today' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $M m JOIN $C c ON c.id=m.chat_id WHERE c.account_id=%d AND m.direction='out' AND m.ts>=%d", $id, $day)),
                'new_today' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $K k JOIN $C c ON c.contact_id=k.id WHERE c.account_id=%d AND k.created>=%d", $id, $day))];
        }
        $in = $al === null ? '' : ($al ? ' AND c.account_id IN (' . implode(',', array_map('intval', $al)) . ')' : ' AND 0=1');
        $waiting = $wpdb->get_results("SELECT c.id, c.account_id, c.last_in_ts, c.preview, c.assigned, c.unread, k.name, k.push_name, k.wa_id, k.source FROM $C c JOIN $K k ON k.id=c.contact_id
            WHERE c.archived=0 AND c.last_in_ts>0 AND c.last_in_ts >= c.last_ts $in ORDER BY c.last_in_ts ASC LIMIT 60", ARRAY_A);
        $staff = $wpdb->get_results("SELECT assigned AS name, COUNT(*) AS chats, SUM(CASE WHEN last_in_ts>=last_ts AND last_in_ts>0 THEN 1 ELSE 0 END) AS waiting FROM $C c WHERE c.archived=0 AND c.assigned<>'' $in GROUP BY assigned ORDER BY chats DESC", ARRAY_A);
        $bot = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $M m JOIN $C c ON c.id=m.chat_id WHERE m.sender='المساعد الذكي' AND m.ts>=%d $in", $day));
        return ['accounts' => $accs, 'waiting' => $waiting, 'staff' => $staff, 'bot_today' => $bot, 'now' => time(), 'hub' => ['on' => (bool) self::hub()['on'] && self::hub()['url'] !== '']];
    }

    /* ======================= قوالب Meta ======================= */
    static function meta_info() {
        return ['callback' => rest_url(MWC_NS . '/webhook/meta'), 'verify_token' => get_option('mwc_meta_verify')];
    }
    static function templates($acc) {
        if (($acc['provider'] ?? '') !== 'meta') return [];
        $c = $acc['config'];
        if (empty($c['waba_id']) || empty($c['token'])) return new WP_Error('waba', 'اكتب WhatsApp Business Account ID في إعدادات الرقم', ['status' => 400]);
        $r = wp_remote_get('https://graph.facebook.com/' . ($c['api_version'] ?? 'v21.0') . '/' . rawurlencode($c['waba_id']) . '/message_templates?fields=name,language,status,category,components&limit=100',
            ['timeout' => 20, 'headers' => ['Authorization' => 'Bearer ' . $c['token']]]);
        if (is_wp_error($r)) return $r;
        $b = json_decode(wp_remote_retrieve_body($r), true) ?: [];
        if (!empty($b['error'])) return new WP_Error('meta', $b['error']['message'] ?? 'Meta error', ['status' => 502]);
        return array_map(function ($t) {
            $body = ''; foreach ((array) ($t['components'] ?? []) as $cp) if (($cp['type'] ?? '') === 'BODY') $body = $cp['text'] ?? '';
            return ['name' => $t['name'], 'lang' => $t['language'], 'status' => $t['status'], 'category' => $t['category'] ?? '', 'body' => $body, 'params' => preg_match_all('/\{\{\d+\}\}/', $body)];
        }, (array) ($b['data'] ?? []));
    }

    /* ======================= تطبيق الأندرويد (APK) ======================= */
    /** الخدمة اللي في التطبيق بتسأل هنا كل 15 ثانية والشاشة مقفولة: {op:'inbox', since} → رسايل جديدة */
    static function native_push(WP_REST_Request $q) {
        global $wpdb;
        $b = $q->get_json_params() ?: [];
        $since = (int) ($b['since'] ?? 0);
        $now = time();
        if (!$since) return ['data' => ['items' => [], 'now' => (string) $now]];
        if (!MWC_Users::can('chat_view')) return ['data' => ['items' => [], 'now' => (string) $now]];
        $al = MWC_Users::allowed_accounts();
        $in = $al === null ? '' : ($al ? ' AND c.account_id IN (' . implode(',', array_map('intval', $al)) . ')' : ' AND 0=1');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT m.id, m.type, m.body, m.file_name, m.chat_id, c.account_id, k.name, k.push_name, k.wa_id FROM " . MWC_Store::t('messages') . " m
            JOIN " . MWC_Store::t('chats') . " c ON c.id=m.chat_id JOIN " . MWC_Store::t('contacts') . " k ON k.id=c.contact_id
            WHERE m.direction='in' AND m.ts>=%d $in ORDER BY m.id ASC LIMIT 30", $since - 1), ARRAY_A);
        $accs = []; foreach (MWC_Store::accounts() as $a) $accs[$a['id']] = $a['label'];
        $items = array_map(function ($r) use ($accs) {
            return ['id' => 'mwc' . $r['id'], 'kind' => 'msg_in', 'event' => 'wa_msg',
                'title' => ($r['name'] ?: ($r['push_name'] ?: '+' . $r['wa_id'])) . ' • ' . ($accs[$r['account_id']] ?? ''),
                'body' => mb_substr(MWC_Rest::preview_of($r), 0, 160), 'url' => home_url('/wa-center/?chat=' . $r['chat_id'])];
        }, $rows);
        return ['data' => ['items' => $items, 'now' => (string) $now]];
    }
}
MWC_Route::boot();
