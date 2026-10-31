<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Owner link: a private REST surface (oxy-owner/v1) for the owner's two standalone consoles
 * (orders inbox + site control). The consoles are local files on the owner's devices — they are NOT hosted anywhere,
 * so they cannot be indexed. No typed password: each device holds a long random device key generated here.
 * Only the SHA-256 hash of a key is stored. Every change goes through WordPress itself, so it shows on the site at once.
 */
class Oxygen_Owner_Link {
    const NS = 'oxy-owner/v1';
    const KEYS = 'oxygen_owner_keys'; // [ ['h' => sha256, 'name' => label, 'at' => mysql time, 'last' => mysql time] ]

    public static function boot() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('init', [__CLASS__, 'preflight'], 1);
        add_action('admin_menu', function () { add_submenu_page('oxygen-seo', 'أجهزة المالك السرية', 'أجهزة المالك السرية', 'manage_options', 'oxygen-owner-link', [__CLASS__, 'page']); }, 23);
        add_action('admin_post_oxygen_owner_key', [__CLASS__, 'key_action']);
    }

    /* ---------- CORS (the consoles run from local files, origin "null") ---------- */
    private static function is_ours() {
        $u = (string) ($_SERVER['REQUEST_URI'] ?? '');
        return strpos($u, '/wp-json/' . self::NS) !== false || strpos($u, 'rest_route=/' . self::NS) !== false || strpos(rawurldecode($u), 'rest_route=/' . self::NS) !== false;
    }
    private static function cors() {
        if (headers_sent()) { return; }
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: X-Owner-Key, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Max-Age: 600');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
    }
    public static function preflight() {
        if (!self::is_ours()) { return; }
        self::cors();
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { status_header(204); exit; }
    }

    /* ---------- device keys ---------- */
    private static function keys() { $k = get_option(self::KEYS, []); return is_array($k) ? $k : []; }
    private static function ip() { return preg_replace('/[^0-9a-f:.]/i', '', (string) ($_SERVER['REMOTE_ADDR'] ?? '')); }
    public static function auth($req) {
        self::cors();
        $ip = self::ip(); $tk = 'oxo_fail_' . md5($ip);
        $fails = (int) get_transient($tk);
        if ($fails >= 8) { return new WP_Error('locked', 'محاولات كثيرة — حاول لاحقًا.', ['status' => 429]); }
        $given = trim((string) $req->get_header('x-owner-key'));
        if ($given !== '' && strlen($given) >= 32) {
            $h = hash('sha256', $given);
            $keys = self::keys();
            foreach ($keys as $i => $k) {
                if (hash_equals((string) $k['h'], $h)) {
                    if (empty($k['last']) || strtotime($k['last']) < time() - 600) { $keys[$i]['last'] = current_time('mysql'); update_option(self::KEYS, $keys, false); }
                    return true;
                }
            }
        }
        set_transient($tk, $fails + 1, 15 * MINUTE_IN_SECONDS);
        return new WP_Error('forbidden', 'مفتاح الجهاز غير صحيح.', ['status' => 403]);
    }

    /* ---------- routes ---------- */
    public static function routes() {
        $a = [__CLASS__, 'auth'];
        $r = function ($path, $methods, $cb) use ($a) { register_rest_route(self::NS, $path, ['methods' => $methods, 'callback' => $cb, 'permission_callback' => $a]); };
        $r('/ping', 'GET', function () { return ['ok' => true, 'site' => get_bloginfo('name'), 'url' => home_url('/')]; });
        $r('/orders', 'GET', [__CLASS__, 'orders']);
        $r('/orders/status', 'POST', [__CLASS__, 'order_status']);
        $r('/settings', 'GET', [__CLASS__, 'settings_get']);
        $r('/settings', 'POST', [__CLASS__, 'settings_set']);
        $r('/posts', 'GET', [__CLASS__, 'posts']);
        $r('/post', 'GET', [__CLASS__, 'post_get']);
        $r('/post', 'POST', [__CLASS__, 'post_set']);
        $r('/action', 'POST', [__CLASS__, 'action']);
    }

    /* ---------- orders ---------- */
    public static function orders($req) {
        global $wpdb;
        if (!class_exists('Mutqan_Store')) { return new WP_Error('no_store', 'نظام الطلبات غير متاح.', ['status' => 500]); }
        $t = Mutqan_Store::table('docs');
        $limit = max(1, min(200, (int) $req->get_param('limit') ?: 60));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE coll=%s AND (expires_at IS NULL OR expires_at > %s) ORDER BY updated_at DESC LIMIT $limit", 'orders', Mutqan_Store::now()), ARRAY_A) ?: [];
        $out = array_map(['Mutqan_Store', 'row_to_doc'], $rows);
        return ['orders' => $out, 'at' => gmdate('c')];
    }
    public static function order_status($req) {
        global $wpdb;
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $req->get_param('id'));
        $status = preg_replace('/[^a-z_]/', '', (string) $req->get_param('status'));
        $allowed = ['new', 'forced', 'assigned', 'accepted', 'arrived', 'in_progress', 'done', 'completed', 'cancelled'];
        if ($id === '' || !in_array($status, $allowed, true)) { return new WP_Error('bad', 'بيانات غير صحيحة.', ['status' => 400]); }
        $t = Mutqan_Store::table('docs');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE coll=%s AND doc_id=%s", 'orders', $id), ARRAY_A);
        if (!$row) { return new WP_Error('nf', 'الطلب غير موجود.', ['status' => 404]); }
        $d = json_decode($row['data'], true) ?: [];
        $d['status'] = $status;
        $h = is_array($d['statusHistory'] ?? null) ? $d['statusHistory'] : [];
        $h[] = ['status' => $status, 'at' => gmdate('c'), 'by' => 'owner'];
        $d['statusHistory'] = $h;
        $wpdb->update($t, ['data' => wp_json_encode($d, JSON_UNESCAPED_UNICODE), 'updated_at' => Mutqan_Store::now()], ['pk' => $row['pk']]);
        try { $m = new ReflectionMethod('Mutqan_Store', 'bump'); $m->setAccessible(true); $m->invoke(null, 'orders'); } catch (Throwable $e) { /* optional */ }
        return ['ok' => true, 'id' => $id, 'status' => $status];
    }

    /* ---------- settings ---------- */
    private static function bc_fields() { return ['company_name', 'phone', 'whatsapp', 'email', 'city', 'services', 'offers', 'banner_text', 'banner_image', 'banner_video', 'gallery_urls', 'payment_methods', 'price_rules']; }
    private static function bc_flags() { return ['comments_enabled', 'registration_enabled', 'ai_enabled']; }
    public static function settings_get() {
        $s = class_exists('Oxygen_Business_Control') ? Oxygen_Business_Control::settings() : [];
        $o = []; foreach (self::bc_fields() as $k) { $o[$k] = (string) ($s[$k] ?? ''); }
        foreach (self::bc_flags() as $k) { $o[$k] = !empty($s[$k]) ? 1 : 0; }
        $soc = class_exists('Oxygen_Social') ? Oxygen_Social::settings() : [];
        foreach (['tiktok', 'facebook', 'tiktok_videos', 'facebook_videos'] as $k) { $o['social_' . $k] = (string) ($soc[$k] ?? ''); }
        return $o;
    }
    public static function settings_set($req) {
        $in = $req->get_json_params(); $in = is_array($in) ? $in : [];
        if (class_exists('Oxygen_Business_Control')) {
            $s = Oxygen_Business_Control::settings();
            $multi = ['services', 'offers', 'banner_text', 'gallery_urls', 'payment_methods', 'price_rules'];
            foreach (self::bc_fields() as $k) {
                if (!array_key_exists($k, $in)) { continue; }
                $v = (string) $in[$k];
                $s[$k] = in_array($k, ['banner_image', 'banner_video'], true) ? esc_url_raw($v) : ($k === 'email' ? sanitize_email($v) : (in_array($k, $multi, true) ? sanitize_textarea_field($v) : sanitize_text_field($v)));
            }
            foreach (self::bc_flags() as $k) { if (array_key_exists($k, $in)) { $s[$k] = !empty($in[$k]) ? 1 : 0; } }
            $s['last_saved'] = current_time('mysql');
            update_option(Oxygen_Business_Control::OPT, $s, false);
            delete_transient('oxygen_business_public_config'); wp_cache_delete('oxygen_business_public_config', 'oxygen');
        }
        if (class_exists('Oxygen_Social')) {
            $soc = Oxygen_Social::settings(); $chg = false;
            foreach (['tiktok', 'facebook'] as $k) { if (array_key_exists('social_' . $k, $in)) { $soc[$k] = esc_url_raw(trim((string) $in['social_' . $k])); $chg = true; } }
            foreach (['tiktok_videos', 'facebook_videos'] as $k) { if (array_key_exists('social_' . $k, $in)) { $soc[$k] = implode("\n", array_filter(array_map('esc_url_raw', array_map('trim', preg_split('/\R+/', (string) $in['social_' . $k]))))); $chg = true; } }
            if ($chg) { update_option(Oxygen_Social::OPT, $soc, false); }
        }
        self::purge();
        return ['ok' => true, 'settings' => self::settings_get()];
    }

    /* ---------- articles ---------- */
    public static function posts($req) {
        $q = sanitize_text_field((string) $req->get_param('q'));
        $pg = max(1, (int) $req->get_param('page'));
        $p = get_posts(['post_type' => 'post', 'post_status' => ['publish', 'draft', 'pending', 'private'], 'numberposts' => 30, 'paged' => $pg, 's' => $q, 'orderby' => 'date', 'order' => 'DESC']);
        return ['posts' => array_map(function ($x) { return ['id' => $x->ID, 'title' => html_entity_decode(get_the_title($x), ENT_QUOTES, 'UTF-8'), 'status' => $x->post_status, 'date' => $x->post_date, 'link' => get_permalink($x)]; }, $p)];
    }
    public static function post_get($req) {
        $x = get_post((int) $req->get_param('id'));
        if (!$x || $x->post_type !== 'post') { return new WP_Error('nf', 'المقال غير موجود.', ['status' => 404]); }
        return ['id' => $x->ID, 'title' => $x->post_title, 'content' => $x->post_content, 'excerpt' => $x->post_excerpt, 'status' => $x->post_status, 'link' => get_permalink($x)];
    }
    public static function post_set($req) {
        $in = $req->get_json_params(); $in = is_array($in) ? $in : [];
        $x = get_post((int) ($in['id'] ?? 0));
        if (!$x || $x->post_type !== 'post') { return new WP_Error('nf', 'المقال غير موجود.', ['status' => 404]); }
        $u = ['ID' => $x->ID];
        if (isset($in['title'])) { $u['post_title'] = sanitize_text_field((string) $in['title']); }
        if (isset($in['content'])) { $u['post_content'] = wp_kses_post((string) $in['content']); }
        if (isset($in['excerpt'])) { $u['post_excerpt'] = sanitize_textarea_field((string) $in['excerpt']); }
        if (isset($in['status']) && in_array($in['status'], ['publish', 'draft', 'pending', 'private'], true)) { $u['post_status'] = $in['status']; }
        $r = wp_update_post($u, true);
        if (is_wp_error($r)) { return $r; }
        // keep the owner's manual edit: stop the auto-refresh from overwriting it
        update_post_meta($x->ID, '_oxy_seo_updated', current_time('mysql'));
        self::purge();
        return ['ok' => true, 'link' => get_permalink($x->ID)];
    }

    /* ---------- actions ---------- */
    public static function action($req) {
        $n = (string) $req->get_param('name');
        if ($n === 'clear_cache') { self::purge(); return ['ok' => true, 'msg' => 'تم تفريغ الكاش.']; }
        if ($n === 'run_engine' && class_exists('Oxygen_SEO_Engine')) { try { Oxygen_SEO_Engine::tick(); } catch (Throwable $e) { return new WP_Error('err', 'تعذر التشغيل الآن.', ['status' => 500]); } self::purge(); return ['ok' => true, 'msg' => 'تم تشغيل دورة النشر.']; }
        return new WP_Error('bad', 'إجراء غير معروف.', ['status' => 400]);
    }
    private static function purge() {
        if (function_exists('wp_cache_flush')) { wp_cache_flush(); }
        do_action('litespeed_purge_all');
    }

    /* ---------- admin: create / revoke device keys (the key is shown once) ---------- */
    public static function page() {
        if (!current_user_can('manage_options')) { return; }
        $keys = self::keys();
        $flash = get_transient('oxo_flash_' . get_current_user_id());
        echo '<div class="wrap" dir="rtl"><h1>أجهزة المالك السرية</h1><p>مفتاح الجهاز يربط واجهتَي المالك (الطلبات + التحكم) بالموقع بدون كلمة مرور. الواجهتان ملفان على جوالك فقط ولا تُنشران في أي مكان. يظهر المفتاح مرة واحدة عند إنشائه.</p>';
        if ($flash) { delete_transient('oxo_flash_' . get_current_user_id()); echo '<div class="notice notice-warning" style="padding:14px"><p><b>مفتاح الجهاز الجديد (انسخه الآن، لن يظهر مرة أخرى):</b></p><p><code style="font-size:16px;user-select:all;direction:ltr;display:inline-block">' . esc_html($flash) . '</code></p></div>'; }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="oxygen_owner_key">' . wp_nonce_field('oxygen_owner_key', '_wpnonce', true, false)
            . '<p><input type="text" name="label" placeholder="اسم الجهاز (مثال: جوالي)" class="regular-text"> <button class="button button-primary" name="do" value="new">إنشاء مفتاح جهاز</button></p></form>';
        if ($keys) {
            echo '<table class="widefat" style="max-width:640px"><thead><tr><th>الجهاز</th><th>أُنشئ</th><th>آخر استخدام</th><th></th></tr></thead><tbody>';
            foreach ($keys as $i => $k) {
                echo '<tr><td>' . esc_html($k['name'] ?: 'جهاز') . '</td><td>' . esc_html($k['at']) . '</td><td>' . esc_html($k['last'] ?: '—') . '</td><td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0"><input type="hidden" name="action" value="oxygen_owner_key"><input type="hidden" name="idx" value="' . (int) $i . '">' . wp_nonce_field('oxygen_owner_key', '_wpnonce', true, false) . '<button class="button" name="do" value="revoke" onclick="return confirm(\'إلغاء هذا الجهاز؟\')">إلغاء</button></form></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }
    public static function key_action() {
        if (!current_user_can('manage_options')) { wp_die('غير مصرح.'); }
        check_admin_referer('oxygen_owner_key');
        $keys = self::keys();
        if (($_POST['do'] ?? '') === 'new') {
            $key = wp_generate_password(48, false, false);
            $keys[] = ['h' => hash('sha256', $key), 'name' => sanitize_text_field(wp_unslash($_POST['label'] ?? '')), 'at' => current_time('mysql'), 'last' => ''];
            update_option(self::KEYS, array_values($keys), false);
            set_transient('oxo_flash_' . get_current_user_id(), $key, 300);
        } elseif (($_POST['do'] ?? '') === 'revoke') {
            unset($keys[(int) ($_POST['idx'] ?? -1)]);
            update_option(self::KEYS, array_values($keys), false);
        }
        wp_safe_redirect(admin_url('admin.php?page=oxygen-owner-link')); exit;
    }
}
