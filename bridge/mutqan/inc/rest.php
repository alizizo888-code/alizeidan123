<?php
/**
 * The signed routes the desk calls. No cookie, no nonce, no user: the signature is the whole authority,
 * which is why these never return "you do not have permission".
 *
 * Both namespaces are registered: mutqan/v1 is the name going forward, oxy-bridge/v1 keeps the desk
 * working while it still calls the old paths. The signature covers whichever route was actually called.
 */

if (!defined('ABSPATH')) {
    exit;
}

function mutqan_register_routes()
{
    $routes = array(
        '/status'   => array('GET',  'mutqan_rest_status'),
        '/posts'    => array('GET',  'mutqan_rest_posts'),
        '/purge'    => array('POST', 'mutqan_rest_purge'),
    );
    foreach (array('mutqan/v1', 'oxy-bridge/v1') as $ns) {
        foreach ($routes as $path => $spec) {
            register_rest_route($ns, $path, array(
                'methods'             => $spec[0],
                'callback'            => $spec[1],
                'permission_callback' => 'mutqan_authorize',
            ));
        }
        // One section of the face's configuration. The section is part of the path, so it is part of
        // the signed canonical string too: a signature for one section cannot be replayed on another.
        register_rest_route($ns, '/config(?:/(?P<section>[a-z_]{1,40}))?', array(
            array(
                'methods'             => 'GET',
                'callback'            => 'mutqan_rest_config_read',
                'permission_callback' => 'mutqan_authorize',
            ),
            array(
                'methods'             => 'POST',
                'callback'            => 'mutqan_rest_config_write',
                'permission_callback' => 'mutqan_authorize',
            ),
        ));
        register_rest_route($ns, '/settings', array(
            array(
                'methods'             => 'GET',
                'callback'            => 'mutqan_rest_settings_read',
                'permission_callback' => 'mutqan_authorize',
            ),
            array(
                'methods'             => 'POST',
                'callback'            => 'mutqan_rest_settings_write',
                'permission_callback' => 'mutqan_authorize',
            ),
        ));
    }
}

/**
 * Accepts a request only when its signature matches this site's secret, its timestamp is inside the
 * window, and its nonce has not been seen recently. Every refusal says which check failed, because a
 * vague 403 is what made the earlier attempts impossible to debug.
 */
function mutqan_authorize(WP_REST_Request $request)
{
    $secret = (string) get_option(MUTQAN_SECRET_OPTION, '');
    if (strlen($secret) < 64) {
        return new WP_Error('mutqan_unpaired', 'this site has no key yet', array('status' => 403));
    }

    $ts    = (string) $request->get_header('x-oxy-timestamp');
    $nonce = (string) $request->get_header('x-oxy-nonce');
    $sig   = (string) $request->get_header('x-oxy-signature');

    if (!preg_match('/^\d{10}$/', $ts) || !preg_match('/^[a-f0-9]{32}$/', $nonce) || !preg_match('/^[a-f0-9]{64}$/', $sig)) {
        return new WP_Error('mutqan_bad_headers', 'signature headers missing or malformed', array('status' => 401));
    }
    if (abs(time() - (int) $ts) > MUTQAN_SKEW) {
        return new WP_Error('mutqan_stale', 'timestamp outside the 5 minute window', array('status' => 401));
    }
    if (!mutqan_signature_ok($secret, $request->get_method(), $request->get_route(), $ts, $nonce, $request->get_body(), $sig, time())) {
        return new WP_Error('mutqan_bad_signature', 'signature mismatch', array('status' => 401));
    }

    $key = 'mutqan_nonce_' . hash('sha256', $nonce);
    if (get_transient($key)) {
        return new WP_Error('mutqan_replay', 'this nonce was already used', array('status' => 401));
    }
    set_transient($key, 1, MUTQAN_REPLAY_WINDOW);
    return true;
}

function mutqan_rest_status(WP_REST_Request $request)
{
    mutqan_fresh();
    $counts = wp_count_posts('post');
    $queue = mutqan_outbox_read();
    return array(
        'ok'              => true,
        'plugin'          => MUTQAN_VERSION,
        'wordpress'       => get_bloginfo('version'),
        'php'             => PHP_VERSION,
        'site_name'       => get_bloginfo('name'),
        'home'            => home_url('/'),
        'published_posts' => isset($counts->publish) ? (int) $counts->publish : 0,
        'desk_linked'     => mutqan_desk_url() !== '',
        'queued_orders'   => count($queue),
        'config_sections' => count(mutqan_config_sections()),
        'intake_enabled'  => mutqan_get('intake_enabled'),
        'time'            => time(),
    );
}

/** The newest posts of any status, so the desk can show this face's articles. Read only. */
function mutqan_rest_posts(WP_REST_Request $request)
{
    mutqan_fresh();
    $items = array();
    $posts = get_posts(array(
        'post_type'   => 'post',
        'post_status' => array('publish', 'future', 'draft', 'pending', 'private'),
        'numberposts' => 100,
        'orderby'     => 'date',
        'order'       => 'DESC',
    ));
    foreach ($posts as $post) {
        $items[] = array(
            'id'     => (int) $post->ID,
            'title'  => (string) $post->post_title,
            'status' => (string) $post->post_status,
            'date'   => (string) $post->post_date,
            'url'    => (string) get_permalink($post->ID),
        );
    }
    return array('ok' => true, 'count' => count($items), 'posts' => $items);
}

function mutqan_rest_settings_read(WP_REST_Request $request)
{
    mutqan_fresh();
    return array('ok' => true, 'values' => mutqan_all());
}

function mutqan_rest_settings_write(WP_REST_Request $request)
{
    $body = json_decode((string) $request->get_body(), true);
    $input = (is_array($body) && isset($body['values']) && is_array($body['values'])) ? $body['values'] : array();
    if ($input === array()) {
        return new WP_Error('mutqan_bad_request', 'no values were sent', array('status' => 400));
    }
    $clean = mutqan_validate($input);
    if (is_wp_error($clean)) {
        return $clean;
    }
    // Read each value before and after writing it, so the desk can report what actually changed
    // rather than what it asked for. A face behind a cache can accept a write and still serve the old value.
    $before = array();
    $after = array();
    $changed = array();
    foreach ($clean as $key => $value) {
        $before[$key] = mutqan_get($key);
        mutqan_save(array($key => $value));
        mutqan_fresh();
        $after[$key] = mutqan_get($key);
        if ($before[$key] !== $after[$key]) {
            $changed[] = $key;
        }
    }
    return array(
        'ok'      => true,
        'saved'   => array_keys($clean),
        'changed' => $changed,
        'before'  => $before,
        'after'   => $after,
        'values'  => mutqan_all(),
    );
}

function mutqan_rest_purge(WP_REST_Request $request)
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
