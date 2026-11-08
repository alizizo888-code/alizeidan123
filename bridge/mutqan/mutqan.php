<?php
/**
 * Plugin Name: Mutqan — متقن
 * Description: الوصلة الموقّعة بين الموقع ولوحة التحكم (الدسك): الإعدادات، استقبال الطلبات، وحالة الموقع. بدون تسجيل دخول وبدون صلاحيات ووردبريس.
 * Version: 3.0.0
 * Author: OXYGEN
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * One plugin, one job: carry data between this face and the desk over a signed channel.
 *
 * Why it is built this way: every earlier attempt failed because it rode on a WordPress login
 * (cookies, nonces, user capabilities), which produces 401/403 for a machine caller. Here every
 * request is signed with a per-site secret and verified without ever asking WordPress who the
 * user is. Business logic (orders, technicians, balances) lives in the desk, not here.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MUTQAN_VERSION', '3.0.0');

/** The per-site secret. Shared with the desk once, by hand, from the admin page. */
define('MUTQAN_SECRET_OPTION', 'oxy_bridge_secret');

/** Where the desk lives. Set here on the site only: a remote caller must never be able to redirect orders. */
define('MUTQAN_DESK_OPTION', 'mutqan_desk_url');

/** Orders that could not reach the desk yet, kept until they do. */
define('MUTQAN_QUEUE_OPTION', 'mutqan_outbox');

define('MUTQAN_DIR', plugin_dir_path(__FILE__));

require_once MUTQAN_DIR . 'inc/sign.php';
require_once MUTQAN_DIR . 'inc/settings.php';
require_once MUTQAN_DIR . 'inc/config.php';
require_once MUTQAN_DIR . 'inc/rest.php';
require_once MUTQAN_DIR . 'inc/outbox.php';
require_once MUTQAN_DIR . 'inc/form.php';
require_once MUTQAN_DIR . 'inc/admin.php';

register_activation_hook(__FILE__, 'mutqan_activate');
/** Keeps an existing secret so pairing survives an upgrade or a re-activation. */
function mutqan_activate()
{
    if (strlen((string) get_option(MUTQAN_SECRET_OPTION, '')) < 64) {
        update_option(MUTQAN_SECRET_OPTION, bin2hex(random_bytes(32)), false);
    }
    if (!wp_next_scheduled(MUTQAN_RETRY_HOOK)) {
        wp_schedule_event(time() + 300, 'hourly', MUTQAN_RETRY_HOOK);
    }
}

register_deactivation_hook(__FILE__, 'mutqan_deactivate');
function mutqan_deactivate()
{
    $next = wp_next_scheduled(MUTQAN_RETRY_HOOK);
    if ($next) {
        wp_unschedule_event($next, MUTQAN_RETRY_HOOK);
    }
}

add_action('rest_api_init', 'mutqan_register_routes');
add_action('admin_menu', 'mutqan_admin_menu');
add_action('admin_post_mutqan_save', 'mutqan_admin_save');
add_action('admin_post_mutqan_rotate', 'mutqan_admin_rotate');
add_action(MUTQAN_RETRY_HOOK, 'mutqan_outbox_flush');
