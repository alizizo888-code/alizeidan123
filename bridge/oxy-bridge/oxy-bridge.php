<?php
/**
 * Plugin Name: Oxygen Bridge
 * Description: Signed REST endpoints for the Oxygen master control panel. HMAC-SHA256 over method, route, timestamp, nonce and body. No passwords, no cookies.
 * Version: 2.0.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OXY_BRIDGE_VERSION', '2.0.0');
define('OXY_BRIDGE_OPTION', 'oxy_bridge_secret');

// Generated per site on activation. The owner copies it once into the master (Settings > Oxygen Bridge).
register_activation_hook(__FILE__, 'oxy_bridge_activate');
function oxy_bridge_activate()
{
    if (!get_option(OXY_BRIDGE_OPTION)) {
        add_option(OXY_BRIDGE_OPTION, bin2hex(random_bytes(32)), '', 'no');
    }
}

add_action('rest_api_init', 'oxy_bridge_routes');
function oxy_bridge_routes()
{
    register_rest_route('oxy-bridge/v1', '/status', array(
        'methods'             => 'GET',
        'callback'            => 'oxy_bridge_status',
        'permission_callback' => 'oxy_bridge_authorize',
    ));
    register_rest_route('oxy-bridge/v1', '/purge', array(
        'methods'             => 'POST',
        'callback'            => 'oxy_bridge_purge',
        'permission_callback' => 'oxy_bridge_authorize',
    ));
}

/**
 * Accepts a request only if its signature matches the per-site secret, its timestamp is within 5 minutes,
 * and its nonce has not been used in the last 10 minutes (replay protection).
 */
function oxy_bridge_authorize(WP_REST_Request $request)
{
    $secret = (string) get_option(OXY_BRIDGE_OPTION, '');
    if (strlen($secret) < 64) {
        return new WP_Error('oxy_unpaired', 'bridge has no secret', array('status' => 403));
    }

    $ts = (string) $request->get_header('x-oxy-timestamp');
    $nonce = (string) $request->get_header('x-oxy-nonce');
    $sig = (string) $request->get_header('x-oxy-signature');
    if (!preg_match('/^\d{10}$/', $ts) || !preg_match('/^[a-f0-9]{32}$/', $nonce) || !preg_match('/^[a-f0-9]{64}$/', $sig)) {
        return new WP_Error('oxy_bad_headers', 'missing or malformed signature headers', array('status' => 401));
    }
    if (abs(time() - (int) $ts) > 300) {
        return new WP_Error('oxy_stale', 'timestamp outside the 5 minute window', array('status' => 401));
    }

    $canonical = strtoupper($request->get_method()) . "\n"
        . $request->get_route() . "\n"
        . $ts . "\n"
        . $nonce . "\n"
        . hash('sha256', (string) $request->get_body());
    $expected = hash_hmac('sha256', $canonical, $secret);
    if (!hash_equals($expected, $sig)) {
        return new WP_Error('oxy_bad_signature', 'signature mismatch', array('status' => 401));
    }

    $key = 'oxy_nonce_' . hash('sha256', $nonce);
    if (get_transient($key)) {
        return new WP_Error('oxy_replay', 'nonce already used', array('status' => 401));
    }
    set_transient($key, 1, 600);
    return true;
}

function oxy_bridge_status(WP_REST_Request $request)
{
    $counts = wp_count_posts('post');
    return array(
        'ok'              => true,
        'plugin'          => OXY_BRIDGE_VERSION,
        'wordpress'       => get_bloginfo('version'),
        'php'             => PHP_VERSION,
        'site_name'       => get_bloginfo('name'),
        'home'            => home_url('/'),
        'published_posts' => isset($counts->publish) ? (int) $counts->publish : 0,
        'time'            => time(),
    );
}

function oxy_bridge_purge(WP_REST_Request $request)
{
    $purged = array();
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
        $purged[] = 'object_cache';
    }
    if (function_exists('rocket_clean_domain')) {
        rocket_clean_domain();
        $purged[] = 'wp_rocket';
    }
    if (function_exists('w3tc_flush_all')) {
        w3tc_flush_all();
        $purged[] = 'w3_total_cache';
    }
    if (has_action('litespeed_purge_all')) {
        do_action('litespeed_purge_all');
        $purged[] = 'litespeed';
    }
    return array('ok' => true, 'purged' => $purged);
}

// Admin page: the owner sees the secret here, copies it once, and can rotate it (which invalidates the old one).
add_action('admin_menu', 'oxy_bridge_menu');
function oxy_bridge_menu()
{
    add_options_page('Oxygen Bridge', 'Oxygen Bridge', 'manage_options', 'oxy-bridge', 'oxy_bridge_admin_page');
}

function oxy_bridge_admin_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $secret = (string) get_option(OXY_BRIDGE_OPTION, '');
    echo '<div class="wrap"><h1>Oxygen Bridge v' . esc_html(OXY_BRIDGE_VERSION) . '</h1>';
    echo '<p>انسخ هذا المفتاح مرة واحدة والصقه في لوحة التحكم الماستر. لا تشاركه مع أحد.</p>';
    echo '<input type="text" readonly value="' . esc_attr($secret) . '" style="width:100%;max-width:640px;font-family:monospace" onclick="this.select()">';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px">';
    wp_nonce_field('oxy_bridge_rotate');
    echo '<input type="hidden" name="action" value="oxy_bridge_rotate">';
    submit_button('تدوير المفتاح (يبطل القديم)', 'secondary', 'submit', false);
    echo '</form></div>';
}

add_action('admin_post_oxy_bridge_rotate', 'oxy_bridge_rotate');
function oxy_bridge_rotate()
{
    if (!current_user_can('manage_options')) {
        wp_die('forbidden');
    }
    check_admin_referer('oxy_bridge_rotate');
    update_option(OXY_BRIDGE_OPTION, bin2hex(random_bytes(32)), false);
    wp_safe_redirect(admin_url('options-general.php?page=oxy-bridge&rotated=1'));
    exit;
}
