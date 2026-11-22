<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «جسر Chatwoot» — the team's inbox on Chatwoot (its phone apps, assignment, canned replies), while the brain stays here.
 *
 * Chatwoot cannot hold our QR-linked WhatsApp numbers itself (it speaks the paid official API), so each of our lines
 * becomes an «API channel» inbox there and this bridge carries the messages both ways:
 *
 *   our line → Chatwoot : every WhatsApp message stored here (the customer's, the AI receptionist's, «حلقة الوصل»'s, a
 *                         staff reply from the app) is posted into that line's inbox, in the customer's conversation —
 *                         incoming as incoming, ours as outgoing — so the team sees the whole story. A new conversation
 *                         gets a private note with its identity card (site, line, the customer's file).
 *   Chatwoot → our line : an agent's reply (Chatwoot calls our webhook) is sent from the same line the customer wrote to,
 *                         and the AI steps back from that conversation for 12 hours, exactly as when a person replies here.
 *
 * Loops are impossible by construction: what came from Chatwoot is stored with via «chatwoot» and never mirrored back,
 * and what we posted there is remembered by its Chatwoot id so its webhook echo is ignored.
 *
 * Off until the owner gives the Chatwoot address, the account id and an access token. Nothing waits on Chatwoot: a
 * mirror that fails is logged and the WhatsApp side carries on.
 */
final class Mutqan_Chatwoot {

    const OPT = 'mutqan_chatwoot';
    const MAP = 'mutqan_chatwoot_map';      // acct => inbox_id ; "c:<tid>" => conversation id ; "k:<conv>" => tid

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'Chatwoot']; }

    public static function settings() {
        $s = get_option(self::OPT, []); $s = is_array($s) ? $s : [];
        $s += ['enabled' => 0, 'url' => '', 'account' => 0, 'token' => '', 'secret' => '', 'notes' => 1];
        if ($s['secret'] === '') { $s['secret'] = bin2hex(random_bytes(16)); update_option(self::OPT, $s, false); }
        return $s;
    }
    public static function ready() { $s = self::settings(); return !empty($s['enabled']) && $s['url'] !== '' && (int) $s['account'] > 0 && $s['token'] !== ''; }
    public static function hook_url() { return add_query_arg('k', self::settings()['secret'], rest_url(Mutqan_Rest::NS . '/chatwoot')); }

    /** What the settings screen shows: never the token itself. */
    public static function view() {
        $s = self::settings();
        $m = self::map(); $inboxes = [];
        foreach ($m as $k => $v) { if (strpos((string) $k, 'c:') !== 0 && strpos((string) $k, 'k:') !== 0) { $inboxes[(string) $k] = (int) $v; } }
        return ['enabled' => (int) $s['enabled'], 'url' => $s['url'], 'account' => (int) $s['account'], 'hasToken' => $s['token'] !== '', 'notes' => (int) $s['notes'], 'webhook' => self::hook_url(), 'inboxes' => $inboxes];
    }

    public static function save($in) {
        $s = self::settings();
        if (isset($in['url'])) {
            $u = rtrim(trim((string) $in['url']), '/');
            if ($u !== '' && !preg_match('#^https://[a-z0-9.-]+(:\d+)?(/.*)?$#i', $u)) { throw new Mutqan_Error('bad_url', 'رابط Chatwoot لازم يبدأ بـ https://', 400); }
            $s['url'] = $u;
        }
        if (isset($in['account'])) { $s['account'] = max(0, (int) $in['account']); }
        if (!empty($in['token'])) { $s['token'] = trim((string) $in['token']); }
        if (isset($in['enabled'])) { $s['enabled'] = !empty($in['enabled']) ? 1 : 0; }
        if (isset($in['notes'])) { $s['notes'] = !empty($in['notes']) ? 1 : 0; }
        if (!empty($in['rotateSecret'])) { $s['secret'] = bin2hex(random_bytes(16)); }
        if (!empty($in['resetMap'])) { delete_option(self::MAP); }
        update_option(self::OPT, $s, false);
        return self::view();
    }

    /* ------------------------------------------------------------------ the Chatwoot API */

    public static function api($method, $path, $body = null) {
        $s = self::settings();
        $url = $s['url'] . '/api/v1/accounts/' . (int) $s['account'] . $path;
        $args = ['method' => $method, 'timeout' => 10, 'headers' => ['api_access_token' => $s['token'], 'Content-Type' => 'application/json', 'Accept' => 'application/json']];
        if ($body !== null) { $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE); }
        $r = $method === 'GET' ? wp_remote_get($url, $args) : wp_remote_post($url, $args);
        if (is_wp_error($r)) { throw new Mutqan_Error('chatwoot_down', 'تعذر الوصول لـ Chatwoot: ' . $r->get_error_message(), 502); }
        $code = (int) wp_remote_retrieve_response_code($r);
        $data = json_decode((string) wp_remote_retrieve_body($r), true);
        if ($code === 401) { throw new Mutqan_Error('chatwoot_auth', 'Chatwoot رفض المفتاح — انسخ «Access Token» من إعدادات حسابك هناك.', 400); }
        if ($code >= 400) { throw new Mutqan_Error('chatwoot_error', 'Chatwoot رد بخطأ ' . $code . (is_array($data) && !empty($data['message']) ? ': ' . $data['message'] : ''), 502); }
        return is_array($data) ? $data : [];
    }

    /** «اختبار الاتصال»: reads the account's inboxes. */
    public static function test() {
        $r = self::api('GET', '/inboxes');
        $list = (array) ($r['payload'] ?? $r);
        return ['ok' => true, 'inboxes' => count($list)];
    }

    private static function map() { $m = get_option(self::MAP, []); return is_array($m) ? $m : []; }
    private static function map_set($k, $v) { $m = self::map(); $m[(string) $k] = $v; if (count($m) > 5000) { $m = array_slice($m, -5000, null, true); } update_option(self::MAP, $m, false); }

    private static function line_label($acct) {
        if (class_exists('Mutqan_WA')) { return Mutqan_WA::acct_label($acct); }
        return (string) $acct;
    }

    /** One API-channel inbox per WhatsApp line, made the first time that line has something to show. */
    public static function inbox_for($acct) {
        $m = self::map();
        if (!empty($m[$acct])) { return (int) $m[$acct]; }
        $r = self::api('POST', '/inboxes', ['name' => mb_substr('واتساب · ' . self::line_label($acct), 0, 60), 'channel' => ['type' => 'api', 'webhook_url' => self::hook_url()]]);
        $id = (int) ($r['id'] ?? 0);
        if ($id <= 0) { throw new Mutqan_Error('chatwoot_error', 'Chatwoot ما رجّعش رقم الصندوق.', 502); }
        self::map_set($acct, $id);
        self::add_members($id);
        return $id;
    }

    /** Everyone on the Chatwoot team becomes a member of the inbox, so its conversations show in their app and get assigned. */
    public static function add_members($inbox) {
        try {
            $agents = self::api('GET', '/agents');
            $ids = array_values(array_filter(array_map(function ($a) { return (int) ($a['id'] ?? 0); }, (array) ($agents['payload'] ?? $agents))));
            if (!$ids) { return 0; }
            self::api('POST', '/inbox_members', ['inbox_id' => (int) $inbox, 'user_ids' => $ids]);
            return count($ids);
        } catch (Throwable $e) { error_log('[mutqan] chatwoot members ' . $e->getMessage()); return 0; }
    }

    /** «مزامنة»: test the key, make sure every open WhatsApp line has its inbox, and the team is in every inbox. */
    public static function sync() {
        $out = ['test' => self::test(), 'inboxes' => []];
        $lines = class_exists('Mutqan_WA_Fleet') ? Mutqan_WA_Fleet::numbers() : [];
        foreach ($lines as $n) {
            if (($n['status'] ?? '') !== 'open') { continue; }
            $inbox = self::inbox_for((string) $n['id']);
            $out['inboxes'][] = ['line' => (string) $n['label'], 'inbox' => $inbox, 'members' => self::add_members($inbox)];
        }
        return $out;
    }

    /** The customer's conversation in that inbox: contact + conversation made once, remembered on the thread. */
    public static function conversation_for($acct, $phone, $tid) {
        $m = self::map();
        if (!empty($m['c:' . $tid])) { return (int) $m['c:' . $tid]; }
        $inbox = self::inbox_for($acct);
        $th = Mutqan_Store::get_raw('wa_threads', $tid) ?: [];
        $intl = class_exists('Mutqan_WA') ? Mutqan_WA::intl($phone) : preg_replace('/\D/', '', (string) $phone);
        $contact = ['inbox_id' => $inbox, 'name' => (string) (($th['name'] ?? '') ?: ('+' . $intl)), 'phone_number' => '+' . $intl, 'identifier' => 'wa:' . $intl];
        if (!empty($th['avatar']) && preg_match('#^https://#', (string) $th['avatar'])) { $contact['avatar_url'] = (string) $th['avatar']; }
        $c = self::api('POST', '/contacts', $contact);
        $cp = (array) ($c['payload']['contact'] ?? ($c['payload'] ?? $c));
        $cid = (int) ($cp['id'] ?? 0);
        $source = '';
        foreach ((array) ($cp['contact_inboxes'] ?? ($c['payload']['contact_inbox'] ?? [])) as $ci) { if ((int) ($ci['inbox']['id'] ?? ($ci['inbox_id'] ?? 0)) === $inbox) { $source = (string) ($ci['source_id'] ?? ''); } }
        if ($source === '' && !empty($c['payload']['contact_inbox']['source_id'])) { $source = (string) $c['payload']['contact_inbox']['source_id']; }
        $card = (array) ($th['card'] ?? []);
        $conv = self::api('POST', '/conversations', array_filter(['source_id' => $source ?: null, 'inbox_id' => $inbox, 'contact_id' => $cid ?: null,
            'additional_attributes' => ['site' => (string) ($card['site'] ?? ''), 'line' => self::line_label($acct), 'mutqan_thread' => (string) $tid]], function ($v) { return $v !== null; }));
        $id = (int) ($conv['id'] ?? 0);
        if ($id <= 0) { throw new Mutqan_Error('chatwoot_error', 'Chatwoot ما رجّعش رقم المحادثة.', 502); }
        self::map_set('c:' . $tid, $id); self::map_set('k:' . $id, (string) $tid);
        // The identity card, for the agent only: which site, which line, who the customer is.
        if (!empty(self::settings()['notes'])) {
            $L = ['🪪 كارت المحادثة'];
            if (!empty($card['site']) && $card['site'] !== 'غير محدد') { $L[] = '🌐 الموقع: ' . $card['site'] . (!empty($card['domain']) ? ' (' . $card['domain'] . ')' : ''); }
            if (!empty($card['source']['title'])) { $L[] = '📰 جه من: ' . $card['source']['title']; }
            $L[] = '📞 الخط: ' . self::line_label($acct);
            if (class_exists('Mutqan_Customers')) { $L[] = '🧾 ' . Mutqan_Customers::brief($phone); }
            if (!empty($th['orderId'])) { $L[] = '📋 طلب مفتوح: #' . substr((string) $th['orderId'], -5); }
            try { self::post_message($id, implode("\n", $L), 'outgoing', true); } catch (Throwable $e) { error_log('[mutqan] chatwoot card ' . $e->getMessage()); }
        }
        return $id;
    }

    private static function post_message($conv, $text, $type, $private = false) {
        $r = self::api('POST', '/conversations/' . (int) $conv . '/messages', ['content' => mb_substr((string) $text, 0, 9000), 'message_type' => $type, 'private' => (bool) $private]);
        if (!empty($r['id'])) { set_transient('mq_cw_mine_' . (int) $r['id'], 1, DAY_IN_SECONDS); }
        return $r;
    }

    /** Called for every WhatsApp message stored here. Never throws, never blocks the WhatsApp side. */
    public static function mirror($acct, $phone, $tid, $m) {
        if (!self::ready() || ($m['via'] ?? '') === 'chatwoot') { return false; }
        if (class_exists('Mutqan_WA') && Mutqan_WA::is_own_number($phone)) { return false; }   // our lines talking to each other are not customers
        $job = function () use ($acct, $phone, $tid, $m) {
            try {
                $conv = self::conversation_for($acct, $phone, $tid);
                $text = trim((string) ($m['text'] ?? ''));
                if (!empty($m['mediaUrl'])) { $text = trim($text . "\n" . (string) $m['mediaUrl']); }
                if ($text === '') { $text = '[' . (string) ($m['kind'] ?? 'رسالة') . ']'; }
                $in = ($m['dir'] ?? '') === 'in';
                if (!$in) {
                    $who = ['bot' => '🤖 موظف الاستقبال', 'journey' => '🔗 حلقة الوصل', 'auto' => '⚙️ النظام', 'order' => '⚙️ النظام', 'phone' => '📱 من الموبايل', 'staff' => '👤 ' . (string) ($m['by'] ?? 'الفريق')][$m['via'] ?? ''] ?? '';
                    if ($who !== '') { $text = $who . "\n" . $text; }
                }
                self::post_message($conv, $text, $in ? 'incoming' : 'outgoing');
                return true;
            } catch (Throwable $e) { error_log('[mutqan] chatwoot mirror ' . $e->getMessage()); return false; }
        };
        if (function_exists('fastcgi_finish_request') && function_exists('add_action')) { add_action('shutdown', function () use ($job) { @fastcgi_finish_request(); $job(); }, 60); return true; }
        return $job();
    }

    /* ------------------------------------------------------------------ Chatwoot → our line */

    /** The webhook Chatwoot calls (each inbox's webhook_url, or an account webhook pointed at the same address). */
    public static function webhook($raw, $key) {
        $s = self::settings();
        if (!is_string($key) || $key === '' || !hash_equals($s['secret'], $key)) { throw new Mutqan_Error('forbidden', 'مفتاح غير صحيح.', 403); }
        $e = json_decode((string) $raw, true);
        if (!is_array($e)) { throw new Mutqan_Error('bad_input', 'حمولة غير صالحة.', 400); }
        // One-time link from the Chatwoot server itself (a task on the VPS reads the owner's access token and sends it
        // here over HTTPS with this bridge's secret): saved, tested, switched on — the token never passes through a chat.
        if (($e['event'] ?? '') === 'mutqan_sync') {
            try { return ['ok' => true] + self::sync(); } catch (Throwable $ex) { return ['ok' => false, 'error' => $ex->getMessage()]; }
        }
        if (($e['event'] ?? '') === 'mutqan_link') {
            $tok = trim((string) ($e['token'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_-]{10,200}$/', $tok)) { throw new Mutqan_Error('bad_input', 'مفتاح غير صالح.', 400); }
            $s['token'] = $tok;
            if ((int) ($e['account'] ?? 0) > 0) { $s['account'] = (int) $e['account']; }
            update_option(self::OPT, $s, false);
            try { self::test(); } catch (Throwable $ex) { return ['ok' => false, 'linked' => true, 'test' => $ex->getMessage()]; }
            $s['enabled'] = 1; update_option(self::OPT, $s, false);
            try { $sync = self::sync(); } catch (Throwable $ex) { $sync = ['error' => $ex->getMessage()]; }
            return ['ok' => true, 'linked' => true, 'enabled' => true, 'sync' => $sync];
        }
        if (empty($s['enabled'])) { return ['ok' => true, 'off' => true]; }
        if (($e['event'] ?? '') !== 'message_created') { return ['ok' => true, 'ignored' => 'event']; }
        $type = $e['message_type'] ?? '';
        if (!in_array($type, ['outgoing', 1, '1'], true) || !empty($e['private'])) { return ['ok' => true, 'ignored' => 'type']; }
        $mid = (int) ($e['id'] ?? 0);
        if ($mid && get_transient('mq_cw_mine_' . $mid)) { return ['ok' => true, 'ignored' => 'echo']; }
        $conv = (int) ($e['conversation']['id'] ?? ($e['conversation_id'] ?? 0));
        $tid = (string) (self::map()['k:' . $conv] ?? ($e['conversation']['additional_attributes']['mutqan_thread'] ?? ''));
        $th = $tid !== '' ? (Mutqan_Store::get_raw('wa_threads', $tid) ?: null) : null;
        if (!$th) { return ['ok' => true, 'ignored' => 'unknown_conversation']; }
        $text = trim((string) ($e['content'] ?? ''));
        foreach ((array) ($e['attachments'] ?? []) as $a) { if (!empty($a['data_url'])) { $text = trim($text . "\n" . $a['data_url']); } }
        if ($text === '') { return ['ok' => true, 'ignored' => 'empty']; }
        $agent = mb_substr((string) ($e['sender']['name'] ?? 'الفريق'), 0, 60);
        // A person answered: the AI receptionist steps back from this conversation, as when someone replies here.
        $bot = (array) ($th['bot'] ?? []); $bot['pausedUntil'] = max((int) ($bot['pausedUntil'] ?? 0), time() + Mutqan_WA::human_idle()); $bot['humanAt'] = time();
        Mutqan_WA::put_thread($tid, ['bot' => $bot, 'status' => 'open', 'unread' => 0]);
        Mutqan_WA::send((string) $th['accountId'], (string) $th['phone'], $text, 'chatwoot', $agent);
        return ['ok' => true, 'sent' => true];
    }
}
