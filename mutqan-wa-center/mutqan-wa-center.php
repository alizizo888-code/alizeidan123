<?php
/**
 * Plugin Name: مركز الواتساب — Mutqan WA Center
 * Description: صندوق محادثات واتساب موحّد لكل أرقام أكسجين/متقن (Meta الرسمي + أرقام QR على السيرفر عبر Evolution API) — صوت، ريكورد، صور، فيديو، ملفات، بروفايل العميل، ردود جاهزة وأزرار خدمات قابلة للتعديل.
 * Version: 1.0.0
 * Author: Oxygen / Mutqan
 * Text Domain: mutqan-wa-center
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

define('MWC_VERSION', '1.0.0');
define('MWC_DIR', plugin_dir_path(__FILE__));
define('MWC_URL', plugin_dir_url(__FILE__));
define('MWC_NS', 'mwc/v1');

require_once MWC_DIR . 'includes/class-mwc-store.php';
require_once MWC_DIR . 'includes/class-mwc-providers.php';
require_once MWC_DIR . 'includes/class-mwc-rest.php';
require_once MWC_DIR . 'includes/class-mwc-admin.php';

register_activation_hook(__FILE__, ['MWC_Store', 'install']);
register_activation_hook(__FILE__, function () {
    add_rewrite_rule('^wa-center/?$', 'index.php?mwc_app=1', 'top');
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, function () { flush_rewrite_rules(); });

add_action('plugins_loaded', function () {
    if (get_option('mwc_db_version') !== MWC_VERSION) MWC_Store::install();
});

add_action('rest_api_init', ['MWC_Rest', 'routes']);
add_action('admin_menu', ['MWC_Admin', 'menu']);
add_action('admin_init', ['MWC_Admin', 'register']);

/* التطبيق نفسه على  /wa-center/  (صفحة كاملة بدون قالب الموقع) */
add_action('init', function () {
    add_rewrite_rule('^wa-center/?$', 'index.php?mwc_app=1', 'top');
});
add_filter('query_vars', function ($v) { $v[] = 'mwc_app'; return $v; });
add_action('template_redirect', function () {
    if (!get_query_var('mwc_app') && !isset($_GET['mwc_app'])) return;
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    $html = file_get_contents(MWC_DIR . 'app/index.html');
    $boot = '<script>window.MWC_API=' . wp_json_encode(esc_url_raw(rest_url(MWC_NS))) . ';</script>';
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
        'icons' => [['src' => MWC_URL . 'app/icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml']],
    ]);
    exit;
}, 1);

/* تنظيف دوري: حذف التوكنات المنتهية */
add_action('mwc_cleanup', function () {
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}mwc_tokens WHERE expires < %d", time()));
});
if (!wp_next_scheduled('mwc_cleanup')) wp_schedule_event(time() + 3600, 'daily', 'mwc_cleanup');
