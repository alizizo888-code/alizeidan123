<?php
/**
 * The other direction: an order the customer just confirmed goes to the desk at once.
 *
 * An order must never be lost because the desk was briefly unreachable, so a send that fails is kept
 * in a queue and tried again on the hourly job. Each order carries its own reference, and the desk
 * answers a repeat of the same reference with the order it already made, so a retry cannot duplicate it.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MUTQAN_RETRY_HOOK', 'mutqan_outbox_retry');

/** Orders kept after this many failed attempts are dropped from the queue and only logged. */
define('MUTQAN_MAX_ATTEMPTS', 24);

/** The queue never grows without bound: the oldest beyond this are dropped. */
define('MUTQAN_QUEUE_MAX', 200);

function mutqan_desk_url()
{
    $url = trim((string) get_option(MUTQAN_DESK_OPTION, ''));
    return $url === '' ? '' : untrailingslashit($url);
}

function mutqan_site_host()
{
    $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
    return strtolower((string) $host);
}

/**
 * Sends one order to the desk. Returns array('ok' => bool, 'code' => int, 'error' => string|null, 'data' => array|null).
 * It never throws: the caller decides whether to queue.
 */
function mutqan_send_order(array $order)
{
    $desk = mutqan_desk_url();
    if ($desk === '') {
        return array('ok' => false, 'code' => 0, 'error' => 'desk_url_missing', 'data' => null);
    }
    $secret = (string) get_option(MUTQAN_SECRET_OPTION, '');
    if (strlen($secret) < 64) {
        return array('ok' => false, 'code' => 0, 'error' => 'unpaired', 'data' => null);
    }

    $body = wp_json_encode($order, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $route = '/intake/order';
    $response = wp_remote_post($desk . $route, array(
        'timeout'     => 15,
        'redirection' => 0,
        'sslverify'   => true,
        'headers'     => mutqan_headers($secret, 'POST', $route, $body, mutqan_site_host()),
        'body'        => $body,
    ));

    if (is_wp_error($response)) {
        return array('ok' => false, 'code' => 0, 'error' => 'network:' . $response->get_error_message(), 'data' => null);
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $json = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($json)) {
        // A firewall or a maintenance page answers with HTML. Telling that apart from a real refusal matters.
        return array('ok' => false, 'code' => $code, 'error' => 'non_json:' . $code, 'data' => null);
    }
    $good = $code === 201 && !empty($json['ok']);
    return array(
        'ok'    => $good,
        'code'  => $code,
        'error' => $good ? null : 'http_' . $code . ':' . (string) (isset($json['error']) ? $json['error'] : 'unknown'),
        'data'  => $json,
    );
}

/**
 * The entry point the order form uses. Sends straight away and queues on failure, so the customer is
 * never told the order failed because of a problem between two of our own servers.
 * @return array('ok' => bool, 'queued' => bool, 'code' => string|null, 'error' => string|null)
 */
function mutqan_push_order(array $order)
{
    $result = mutqan_send_order($order);
    if ($result['ok']) {
        return array(
            'ok'     => true,
            'queued' => false,
            'code'   => isset($result['data']['code']) ? (string) $result['data']['code'] : null,
            'error'  => null,
        );
    }
    // A refusal the desk means (a bad field, intake paused) will not pass on a retry either, except
    // for a paused intake, which the owner reopens. Both are worth keeping, so both are queued.
    mutqan_outbox_add($order, $result['error']);
    return array('ok' => false, 'queued' => true, 'code' => null, 'error' => $result['error']);
}

function mutqan_outbox_read()
{
    $queue = get_option(MUTQAN_QUEUE_OPTION, array());
    return is_array($queue) ? $queue : array();
}

function mutqan_outbox_write(array $queue)
{
    if (count($queue) > MUTQAN_QUEUE_MAX) {
        $queue = array_slice($queue, -MUTQAN_QUEUE_MAX);
    }
    update_option(MUTQAN_QUEUE_OPTION, array_values($queue), false);
}

function mutqan_outbox_add(array $order, $error)
{
    $queue = mutqan_outbox_read();
    $ref = isset($order['site_ref']) ? (string) $order['site_ref'] : '';
    foreach ($queue as $item) {
        if (isset($item['order']['site_ref']) && (string) $item['order']['site_ref'] === $ref) {
            return; // Already waiting: one entry per reference.
        }
    }
    $queue[] = array(
        'order'      => $order,
        'attempts'   => 1,
        'first'      => time(),
        'last'       => time(),
        'last_error' => (string) $error,
    );
    mutqan_outbox_write($queue);
}

/**
 * Tries every queued order once. An order the desk accepts leaves the queue; one that is refused stays
 * with its attempt count raised, until it has been tried too many times.
 * @return array('sent' => int, 'kept' => int, 'dropped' => int)
 */
function mutqan_outbox_flush()
{
    $queue = mutqan_outbox_read();
    if ($queue === array()) {
        return array('sent' => 0, 'kept' => 0, 'dropped' => 0);
    }
    $kept = array();
    $sent = 0;
    $dropped = 0;
    foreach ($queue as $item) {
        $order = isset($item['order']) && is_array($item['order']) ? $item['order'] : null;
        if ($order === null) {
            $dropped++;
            continue;
        }
        $result = mutqan_send_order($order);
        if ($result['ok']) {
            $sent++;
            continue;
        }
        $item['attempts'] = (int) $item['attempts'] + 1;
        $item['last'] = time();
        $item['last_error'] = (string) $result['error'];
        if ($item['attempts'] >= MUTQAN_MAX_ATTEMPTS) {
            $dropped++;
            continue;
        }
        $kept[] = $item;
    }
    mutqan_outbox_write($kept);
    return array('sent' => $sent, 'kept' => count($kept), 'dropped' => $dropped);
}
