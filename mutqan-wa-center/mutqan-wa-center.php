<?php
/**
 * Plugin Name: مركز الواتساب — Mutqan WA Center
 * Description: صندوق محادثات واتساب موحّد لكل أرقام أكسجين/متقن (Meta الرسمي + أرقام QR على السيرفر عبر Evolution API) — صوت، ريكورد، صور، فيديو، ملفات، بروفايل العميل، ردود جاهزة وأزرار خدمات قابلة للتعديل.
 * Version: 1.4.0
 * Author: Oxygen / Mutqan
 * Text Domain: mutqan-wa-center
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

define('MWC_VERSION', '1.4.0');
define('MWC_DIR', plugin_dir_path(__FILE__));
define('MWC_URL', plugin_dir_url(__FILE__));
define('MWC_NS', 'mwc/v1');

require_once MWC_DIR . 'includes/class-mwc-store.php';
require_once MWC_DIR . 'includes/class-mwc-providers.php';
require_once MWC_DIR . 'includes/class-mwc-rest.php';
require_once MWC_DIR . 'includes/class-mwc-admin.php';
require_once MWC_DIR . 'includes/class-mwc-users.php';
require_once MWC_DIR . 'includes/class-mwc-leads.php';
require_once MWC_DIR . 'includes/class-mwc-campaigns.php';
require_once MWC_DIR . 'includes/class-mwc-maps.php';
require_once MWC_DIR . 'includes/class-mwc-push.php';
require_once MWC_DIR . 'includes/class-mwc-rest2.php';
require_once MWC_DIR . 'includes/class-mwc-route.php';

register_activation_hook(__FILE__, ['MWC_Store', 'install']);
register_activation_hook(__FILE__, function () {
    add_rewrite_rule('^wa-center/?$', 'index.php?mwc_app=1', 'top');
    add_rewrite_rule('^wa-center/sw\\.js$', 'index.php?mwc_sw=1', 'top');
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, function () { flush_rewrite_rules(); });

add_action('plugins_loaded', function () {
    if (get_option('mwc_db_version') !== MWC_VERSION) { MWC_Store::install(); flush_rewrite_rules(false); }
});

add_action('rest_api_init', ['MWC_Rest', 'routes']);

/* أول تشغيل: أرقام الشركة بتتضاف لوحدها (جاهزة لمسح الـQR) لما السيرفر الافتراضي يتظبط */
add_action('plugins_loaded', function () {
    if (get_option('mwc_seed_done') || !is_array(get_option('mwc_evo_default'))) return;
    global $wpdb;
    if ((int) $wpdb->get_var("SELECT COUNT(*) FROM " . MWC_Store::t('accounts')) > 0) { update_option('mwc_seed_done', 1, false); return; }
    $seed = [
        ['المدير', '966549247636', 'meta', 'manager', '#00a884', 'أكسجين للصيانة'],
        ['أكسجين 0536', '966536554731', 'evolution', 'sales', '#53bdeb', 'أكسجين للصيانة'],
        ['متقن 3050', '966549423050', 'evolution', 'sales', '#faa774', 'متقن للصيانة'],
        ['مبيعات 5320', '966549665320', 'evolution', 'sales', '#a695e7', 'أكسجين للصيانة'],
        ['مبيعات 5646', '966549715646', 'evolution', 'sales', '#6ec9cb', 'أكسجين للصيانة'],
        ['مبيعات 8890', '966549648890', 'evolution', 'sales', '#7bc862', 'متقن للصيانة'],
        ['تسويق 9205', '966546619205', 'evolution', 'outreach', '#ee7aae', 'أكسجين للصيانة'],
        ['تسويق 2660', '966547512660', 'evolution', 'outreach', '#e5ca77', 'متقن للصيانة'],
    ];
    foreach ($seed as $i => $a) {
        $cfg = $a[2] === 'meta' ? ['brand' => $a[5], 'api_version' => 'v21.0'] : ['brand' => $a[5], 'instance' => 'n' . substr($a[1], -9)];
        MWC_Store::save_account(['label' => $a[0], 'phone' => $a[1], 'provider' => $a[2], 'role' => $a[3], 'color' => $a[4], 'sort' => $i, 'config' => $cfg]);
    }
    update_option('mwc_seed_done', 1, false);
}, 20);
add_action('rest_api_init', ['MWC_Rest2', 'routes']);
add_action('admin_menu', ['MWC_Admin', 'menu']);
add_action('admin_init', ['MWC_Admin', 'register']);

/**
 * توكن الدخول الموحّد لمدير الموقع. بيتعمل مرة واحدة ويتخزن على المستخدم نفسه، وبيتجدد لما يقرب ينتهي —
 * عشان كل فتحة للصفحة ما تعملش توكن جديد وتكبّر جدول التوكنات على الفاضي.
 */
function mwc_sso_token() {
    $uid = get_current_user_id();
    $tok = (string) get_user_meta($uid, 'mwc_sso_token', true);
    // check_token بترجع null لو التوكن اتمسح أو خلصت مدته — ساعتها بس بنعمل واحد جديد
    if ($tok !== '' && MWC_Store::check_token($tok)) { return $tok; }
    $u = wp_get_current_user();
    $tok = MWC_Store::new_token($u->display_name ?: 'المالك', 'owner');
    update_user_meta($uid, 'mwc_sso_token', $tok);
    return $tok;
}

/* التطبيق نفسه على  /wa-center/  (صفحة كاملة بدون قالب الموقع) */
add_action('init', function () {
    add_rewrite_rule('^wa-center/?$', 'index.php?mwc_app=1', 'top');
});
add_filter('query_vars', function ($v) { $v[] = 'mwc_app'; $v[] = 'mwc_sw'; return $v; });
add_action('init', function () { add_rewrite_rule('^wa-center/sw\\.js$', 'index.php?mwc_sw=1', 'top'); });

/* Service Worker — إشعارات حتى والتطبيق مقفول */
add_action('template_redirect', function () {
    if (!get_query_var('mwc_sw') && !isset($_GET['mwc_sw'])) return;
    header('Content-Type: application/javascript; charset=utf-8');
    header('Service-Worker-Allowed: ' . wp_parse_url(home_url('/wa-center/'), PHP_URL_PATH));
    header('Cache-Control: no-cache');
    readfile(MWC_DIR . 'app/sw.js');
    exit;
}, 0);
add_action('template_redirect', function () {
    if (!get_query_var('mwc_app') && !isset($_GET['mwc_app'])) return;
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    $html = file_get_contents(MWC_DIR . 'app/index.html');
    // MWC_VER: التطبيق المتثبّت على الموبايل بيفضل مفتوح أيام من غير ما يتقفل، فبيفضل شغّال بكود قديم بعد أي تحديث.
    // بيقارن الرقم ده باللي جايله من /poll ويحدّث نفسه لوحده (1.3) — عشان كده لازم يتحقن في الصفحة مش يتقرا من الـAPI بس.
    $boot = '<script>window.MWC_API=' . wp_json_encode(esc_url_raw(rest_url(MWC_NS)))
        . ';window.MWC_VER=' . wp_json_encode(MWC_VERSION);
    // دخول موحّد: اللي داخل على الموقع كمدير أصلًا، يدخل المركز على طول من غير رقم سري تاني (1.4).
    // ده مش تسهيل زيادة: مدير الموقع يقدر يغيّر الرقم السري نفسه من اللوحة، فالرقم ما كانش بيضيف أمان عليه —
    // كان بيضيف خطوة بس، ولما ما يتحددش خالص (الحالة اللي كانت قايمة) بيقفل المركز على الكل.
    if (function_exists('is_user_logged_in') && is_user_logged_in() && current_user_can('manage_options')) {
        $boot .= ';window.MWC_SSO=' . wp_json_encode(mwc_sso_token());
    }
    $boot .= ';</script>';
    echo str_replace('<!--MWC_BOOT-->', $boot, $html);
    exit;
});

/* manifest لتثبيت التطبيق على الموبايل/الآيباد كأنه تطبيق مستقل */
add_action('template_redirect', function () {
    if (!isset($_GET['mwc_manifest'])) return;
    header('Content-Type: application/manifest+json');
    echo wp_json_encode([
        'name' => 'مركز الواتساب', 'short_name' => 'الواتساب',
        'start_url' => home_url('/wa-center/'), 'display' => 'standalone',
        'background_color' => '#111b21', 'theme_color' => '#00a884', 'dir' => 'rtl', 'lang' => 'ar',
        'scope' => wp_parse_url(home_url('/wa-center/'), PHP_URL_PATH), 'id' => 'mwc-wa-center',
        'icons' => [['src' => MWC_URL . 'app/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                    ['src' => MWC_URL . 'app/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
                    ['src' => MWC_URL . 'app/icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml']],
    ]);
    exit;
}, 1);

/* محرّك الحملات وسحب الخرائط: كل دقيقة (WP-Cron احتياطي + نبض من السيرفر على /tick) */
add_filter('cron_schedules', function ($s) { $s['mwc_minute'] = ['interval' => 60, 'display' => 'Every minute']; return $s; });
add_action('mwc_tick', function () { MWC_Campaigns::tick(); MWC_Maps::tick(); });
add_action('init', function () { if (!wp_next_scheduled('mwc_tick')) wp_schedule_event(time() + 60, 'mwc_minute', 'mwc_tick'); });

/* تنظيف دوري: حذف التوكنات المنتهية */
add_action('mwc_cleanup', function () {
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}mwc_tokens WHERE expires < %d", time()));
});
if (!wp_next_scheduled('mwc_cleanup')) wp_schedule_event(time() + 3600, 'daily', 'mwc_cleanup');
