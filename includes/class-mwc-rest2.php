<?php
if (!defined('ABSPATH')) exit;

/** مسارات v1.1: المستخدمين، قاعدة العملاء، الحملات، الخرائط، السجلات، الإشعارات، النبض */
class MWC_Rest2 {
    static function routes() {
        $auth = ['MWC_Rest', 'auth'];
        $r = function ($path, $methods, $cb, $perm) { register_rest_route(MWC_NS, $path, ['methods' => $methods, 'callback' => [__CLASS__, $cb], 'permission_callback' => $perm]); };
        $r('/users', 'GET', 'users', $auth);
        $r('/users', 'POST', 'user_save', $auth);
        $r('/users/(?P<id>[a-z0-9_-]+)', 'DELETE', 'user_delete', $auth);
        $r('/leads', 'GET', 'leads', $auth);
        $r('/leads', 'POST', 'lead_save', $auth);
        $r('/leads/facets', 'GET', 'facets', $auth);
        $r('/leads/import', 'POST', 'import', $auth);
        $r('/leads/bulk', 'POST', 'bulk', $auth);
        $r('/leads/export', 'POST', 'export', $auth);
        $r('/campaigns', 'GET', 'campaigns', $auth);
        $r('/campaigns', 'POST', 'campaign_save', $auth);
        $r('/campaigns/preview', 'POST', 'campaign_preview', $auth);
        $r('/campaigns/(?P<id>\d+)', 'GET', 'campaign_get', $auth);
        $r('/campaigns/(?P<id>\d+)/status', 'POST', 'campaign_status', $auth);
        $r('/maps/jobs', 'GET', 'maps_jobs', $auth);
        $r('/maps/jobs', 'POST', 'maps_create', $auth);
        $r('/maps/jobs/(?P<id>\d+)/check', 'POST', 'maps_check', $auth);
        $r('/maps/plan', 'GET', 'maps_plan', $auth);
        $r('/maps/plan', 'POST', 'maps_plan_save', $auth);
        $r('/maps/run', 'POST', 'maps_run_now', $auth);
        $r('/maps/config', 'GET', 'maps_cfg', $auth);
        $r('/maps/config', 'POST', 'maps_cfg_save', $auth);
        $r('/logs', 'GET', 'logs', $auth);
        $r('/push/subscribe', 'POST', 'push_sub', $auth);
        $r('/push/test', 'POST', 'push_test', $auth);
        $r('/push', 'POST', 'native_push', $auth);
        $r('/dashboard', 'GET', 'dashboard', $auth);
        $r('/routing', 'GET', 'routing', $auth);
        $r('/routing', 'POST', 'routing_save', $auth);
        $r('/transfer', 'POST', 'transfer', $auth);
        $r('/hub', 'POST', 'hub_save', $auth);
        $r('/meta/info', 'GET', 'meta_info', $auth);
        $r('/accounts/(?P<id>\d+)/templates', 'GET', 'templates', $auth);
        $r('/ext/send', 'POST', 'ext_send', '__return_true');
        $r('/ext/revoke', 'POST', 'ext_revoke', '__return_true');
        // مفتوحة بمفتاح سري: النبض من السيرفر، واستيراد من أي سكرابر/n8n، وسؤال البوت
        $r('/tick', 'GET', 'tick', '__return_true');
        $r('/ext/leads', 'POST', 'ext_import', '__return_true');
        $r('/ext/bot', 'GET', 'ext_bot', '__return_true');
    }

    static function deny($p) { return MWC_Rest::deny($p); }

    /* ---------- users ---------- */
    static function users() { if ($e = self::deny('users')) return $e; return MWC_Users::public_list(); }
    static function user_save(WP_REST_Request $q) { if ($e = self::deny('users')) return $e; return MWC_Users::save($q->get_json_params() ?: []); }
    static function user_delete(WP_REST_Request $q) { if ($e = self::deny('users')) return $e; MWC_Users::delete($q['id']); return ['ok' => true]; }

    /* ---------- leads ---------- */
    static function filter_of(WP_REST_Request $q) {
        $f = [];
        foreach (['status', 'city', 'country', 'kind', 'category', 'tag', 'q', 'source'] as $k) { $v = $q->get_param($k); if ($v !== null && $v !== '') $f[$k] = is_array($v) ? array_map('sanitize_text_field', $v) : sanitize_text_field($v); }
        return $f;
    }
    static function leads(WP_REST_Request $q) {
        if ($e = self::deny('leads')) return $e;
        $per = min(500, max(10, (int) ($q->get_param('per') ?: 100)));
        $page = max(1, (int) $q->get_param('page'));
        return MWC_Leads::query(self::filter_of($q), $per, ($page - 1) * $per);
    }
    static function facets() { if ($e = self::deny('leads')) return $e; return MWC_Leads::facets(); }
    static function lead_save(WP_REST_Request $q) { if ($e = self::deny('leads')) return $e; return MWC_Leads::save($q->get_json_params() ?: []); }
    static function import(WP_REST_Request $q) {
        if ($e = self::deny('leads')) return $e;
        $b = $q->get_json_params() ?: [];
        $rows = array_slice((array) ($b['rows'] ?? []), 0, 20000);
        return MWC_Leads::import($rows, ['source' => sanitize_text_field($b['source'] ?? 'ملف Excel'), 'city' => sanitize_text_field($b['city'] ?? ''),
            'category' => sanitize_text_field($b['category'] ?? ''), 'tags' => sanitize_text_field($b['tags'] ?? '')]);
    }
    static function bulk(WP_REST_Request $q) {
        $b = $q->get_json_params() ?: [];
        $act = sanitize_key($b['action'] ?? '');
        if ($e = self::deny($act === 'delete' ? 'leads_delete' : 'leads')) return $e;
        $f = (array) ($b['filter'] ?? []);
        if (!empty($b['ids'])) $f = ['ids' => array_map('intval', (array) $b['ids'])];
        if (!$f) return new WP_Error('f', 'حدد العملاء الأول', ['status' => 400]);
        return ['count' => MWC_Leads::bulk($f, $act, $b['value'] ?? '')];
    }
    static function export(WP_REST_Request $q) {
        if ($e = self::deny('leads_export')) return $e;
        $b = $q->get_json_params() ?: [];
        $f = (array) ($b['filter'] ?? []);
        if (!empty($b['ids'])) $f = ['ids' => array_map('intval', (array) $b['ids'])];
        $res = MWC_Leads::query($f, 50000, 0);
        MWC_Store::log('export', 'تصدير ' . count($res['rows']) . ' عميل إلى Excel');
        foreach ($res['rows'] as &$r) { $r['kind_label'] = MWC_Leads::kind_label($r['kind']); $r['status_label'] = MWC_Leads::STATUSES[$r['status']] ?? $r['status']; }
        return $res;
    }

    /* ---------- campaigns ---------- */
    static function campaigns() { if ($e = self::deny('campaigns')) return $e; return MWC_Campaigns::all(); }
    static function campaign_get(WP_REST_Request $q) {
        if ($e = self::deny('campaigns')) return $e;
        $c = MWC_Campaigns::get((int) $q['id']);
        if (!$c) return new WP_Error('nf', 'مش موجودة', ['status' => 404]);
        $c['items'] = MWC_Campaigns::items($c['id'], sanitize_key((string) $q->get_param('status')));
        return $c;
    }
    static function campaign_save(WP_REST_Request $q) { if ($e = self::deny('campaigns')) return $e; return MWC_Campaigns::save($q->get_json_params() ?: []); }
    static function campaign_preview(WP_REST_Request $q) { if ($e = self::deny('campaigns')) return $e; return MWC_Campaigns::preview((array) (($q->get_json_params() ?: [])['data'] ?? [])); }
    static function campaign_status(WP_REST_Request $q) {
        if ($e = self::deny('campaigns')) return $e;
        $r = MWC_Campaigns::set_status((int) $q['id'], sanitize_key($q->get_param('status')));
        if (!is_wp_error($r) && $r['status'] === 'running') MWC_Campaigns::tick();
        return is_wp_error($r) ? $r : MWC_Campaigns::get((int) $q['id']);
    }

    /* ---------- maps ---------- */
    static function maps_jobs() { if ($e = self::deny('maps')) return $e; return ['jobs' => MWC_Maps::jobs(), 'status' => MWC_Maps::status()]; }
    static function maps_create(WP_REST_Request $q) { if ($e = self::deny('maps')) return $e; return MWC_Maps::create($q->get_json_params() ?: []); }
    static function maps_check(WP_REST_Request $q) {
        if ($e = self::deny('maps')) return $e;
        global $wpdb;
        $j = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . MWC_Store::t('jobs') . " WHERE id=%d", (int) $q['id']), ARRAY_A);
        if ($j && $j['status'] === 'working') MWC_Maps::check($j);
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . MWC_Store::t('jobs') . " WHERE id=%d", (int) $q['id']), ARRAY_A);
    }
    static function maps_plan() { if ($e = self::deny('maps')) return $e; return MWC_Maps::plan_out(); }
    static function maps_plan_save(WP_REST_Request $q) { if ($e = self::deny('maps')) return $e; return MWC_Maps::save_plan($q->get_json_params() ?: []); }
    /** «شغّل دلوقتي»: بيصفّر تاريخ آخر تشغيل عشان النبضة الجاية تبعت شغلانات النهاردة من غير استنى الميعاد */
    static function maps_run_now() {
        if ($e = self::deny('maps')) return $e;
        $p = MWC_Maps::plan(); $p['last_run'] = ''; $p['on'] = 1; update_option('mwc_maps_plan', $p, false);
        MWC_Maps::run_daily();
        return MWC_Maps::plan_out();
    }
    static function maps_cfg() { if ($e = self::deny('maps')) return $e; return MWC_Maps::public_cfg(); }
    static function maps_cfg_save(WP_REST_Request $q) { if ($e = self::deny('settings')) return $e; return MWC_Maps::save_cfg($q->get_json_params() ?: []); }

    /* ---------- logs ---------- */
    static function logs(WP_REST_Request $q) {
        if ($e = self::deny('logs')) return $e;
        global $wpdb; $t = MWC_Store::t('logs');
        $type = sanitize_key((string) $q->get_param('type'));
        $sql = "SELECT * FROM $t" . ($type ? $wpdb->prepare(" WHERE type=%s", $type) : '') . " ORDER BY id DESC LIMIT 500";
        return $wpdb->get_results($sql, ARRAY_A);
    }

    /* ---------- push ---------- */
    static function push_sub(WP_REST_Request $q) {
        $ok = MWC_Push::subscribe(MWC_Users::current()['id'], $q->get_json_params() ?: []);
        return $ok ? ['ok' => true] : new WP_Error('sub', 'اشتراك غير صالح', ['status' => 400]);
    }
    static function push_test() {
        global $wpdb;
        $subs = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . MWC_Store::t('push') . " WHERE user_id=%s", MWC_Users::current()['id']), ARRAY_A);
        $n = 0;
        foreach ($subs as $s) $n += MWC_Push::send($s, ['title' => 'مركز الواتساب ✅', 'body' => 'الإشعارات شغالة على الجهاز ده', 'tag' => 'test', 'url' => home_url('/wa-center/')]) ? 1 : 0;
        return ['devices' => count($subs), 'delivered' => $n];
    }

    /* ---------- v1.2: المدير والتوجيه والبوت وMeta والتطبيق ---------- */
    static function native_push(WP_REST_Request $q) { return MWC_Route::native_push($q); }
    static function dashboard() { if ($e = self::deny('chat_view')) return $e; return MWC_Route::dashboard(); }
    static function routing() {
        if ($e = self::deny('chat_view')) return $e;
        $r = MWC_Route::routing(); unset($r['rr']);
        $h = MWC_Route::hub(); $r['hub'] = ['on' => (int) $h['on'], 'linked' => $h['url'] !== '' && $h['key'] !== ''];
        $r['staff'] = array_values(array_unique(array_merge(['المالك'], array_column(MWC_Users::all(), 'name'), (array) (MWC_Store::config()['staff'] ?? []))));
        return $r;
    }
    static function routing_save(WP_REST_Request $q) { if ($e = self::deny('settings')) return $e; MWC_Route::save_routing($q->get_json_params() ?: []); return self::routing(); }
    static function transfer(WP_REST_Request $q) { return MWC_Route::transfer($q); }
    static function hub_save(WP_REST_Request $q) {
        if ($e = self::deny('settings')) return $e;
        $h = get_option('mwc_hub'); $h = is_array($h) ? $h : [];
        $h['on'] = $q->get_param('on') ? 1 : 0;
        update_option('mwc_hub', $h, false);
        MWC_Store::log('bot', 'البوت الذكي على أرقام المركز: ' . ($h['on'] ? 'اتشغّل' : 'اتوقف'));
        return ['on' => $h['on']];
    }
    static function meta_info() { if ($e = self::deny('accounts')) return $e; return MWC_Route::meta_info(); }
    static function templates(WP_REST_Request $q) {
        if ($e = MWC_Rest::deny('chat_send', (int) $q['id'])) return $e;
        $acc = MWC_Store::account((int) $q['id']);
        return $acc ? MWC_Route::templates($acc) : new WP_Error('nf', 'غير موجود', ['status' => 404]);
    }
    static function ext_send(WP_REST_Request $q) { return MWC_Route::ext_send($q); }
    static function ext_revoke(WP_REST_Request $q) { return MWC_Route::ext_revoke($q); }

    /* ---------- open endpoints (secret key) ---------- */
    static function key_ok(WP_REST_Request $q, $opt) { return hash_equals((string) get_option($opt), (string) ($q->get_param('k') ?: $q->get_header('x_mwc_key'))); }

    static function tick(WP_REST_Request $q) {
        if (!self::key_ok($q, 'mwc_tick_key')) return new WP_Error('forbidden', 'bad key', ['status' => 403]);
        // never from a cache (2026-11-24: the host's cache answered the ticker with the same «01:19» for hours, so the
        // campaigns and the maps schedule never ran)
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        if (!headers_sent()) { nocache_headers(); header('X-LiteSpeed-Cache-Control: no-cache'); header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); }
        update_option('mwc_last_tick', time(), false);
        $GLOBALS['mwc_user_obj'] = MWC_Users::owner() + ['name' => 'النظام'];
        $c = MWC_Campaigns::tick();
        MWC_Maps::tick();
        return ['ok' => true, 'campaigns' => $c, 'time' => MWC_Store::riyadh('H:i')];
    }

    /** أي سكرابر أو n8n يقدر يبعت أرقام: POST /ext/leads?k=...  {rows:[{phone,name,city,category}], source, city, tags} */
    static function ext_import(WP_REST_Request $q) {
        if (!self::key_ok($q, 'mwc_webhook_secret')) return new WP_Error('forbidden', 'bad key', ['status' => 403]);
        $GLOBALS['mwc_user_obj'] = MWC_Users::owner() + ['name' => 'استيراد خارجي'];
        $b = $q->get_json_params() ?: [];
        return MWC_Leads::import(array_slice((array) ($b['rows'] ?? []), 0, 20000), ['source' => sanitize_text_field($b['source'] ?? 'استيراد خارجي'),
            'city' => sanitize_text_field($b['city'] ?? ''), 'category' => sanitize_text_field($b['category'] ?? ''), 'tags' => sanitize_text_field($b['tags'] ?? '')]);
    }

    /** البوت (على السيرفر) يسأل: أرد على العميل ده ولا لأ؟  GET /ext/bot?k=...&phone=9665..&account=ID */
    static function ext_bot(WP_REST_Request $q) {
        if (!self::key_ok($q, 'mwc_webhook_secret')) return new WP_Error('forbidden', 'bad key', ['status' => 403]);
        global $wpdb;
        $phone = MWC_Leads::norm($q->get_param('phone'));
        $c = $wpdb->get_row($wpdb->prepare("SELECT bot, source, name FROM " . MWC_Store::t('contacts') . " WHERE wa_id=%s", $phone), ARRAY_A);
        $acc = $q->get_param('account') ? MWC_Store::account((int) $q->get_param('account')) : null;
        $allowed = MWC_Rest::bot_allowed(['bot' => $c['bot'] ?? 'default'], $acc ?: ['config' => []]);
        return ['phone' => $phone, 'bot' => $c['bot'] ?? 'default', 'reply' => $allowed, 'source' => $c['source'] ?? '', 'name' => $c['name'] ?? ''];
    }
}
