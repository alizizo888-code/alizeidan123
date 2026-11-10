<?php
/**
 * Request signing, identical on both sides. Must stay byte for byte in step with src/Hmac.php on the desk:
 * canonical = METHOD \n ROUTE \n TIMESTAMP \n NONCE \n sha256(BODY)
 * ROUTE is the REST route without the /wp-json prefix, e.g. /mutqan/v1/status.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MUTQAN_SKEW', 300);
define('MUTQAN_REPLAY_WINDOW', 600);

function mutqan_canonical($method, $route, $ts, $nonce, $body)
{
    return strtoupper($method) . "\n" . $route . "\n" . $ts . "\n" . $nonce . "\n" . hash('sha256', (string) $body);
}

function mutqan_sign($secret, $method, $route, $ts, $nonce, $body)
{
    return hash_hmac('sha256', mutqan_canonical($method, $route, $ts, $nonce, $body), $secret);
}

/**
 * True only when the signature matches, the clock is within the window and the shape of every header is right.
 * Replay is handled by the caller, which owns the nonce store.
 */
function mutqan_signature_ok($secret, $method, $route, $ts, $nonce, $body, $sig, $now)
{
    if (strlen((string) $secret) < 64) {
        return false;
    }
    if (!preg_match('/^\d{10}$/', (string) $ts)
        || !preg_match('/^[a-f0-9]{32}$/', (string) $nonce)
        || !preg_match('/^[a-f0-9]{64}$/', (string) $sig)) {
        return false;
    }
    if (abs((int) $now - (int) $ts) > MUTQAN_SKEW) {
        return false;
    }
    return hash_equals(mutqan_sign($secret, $method, $route, $ts, $nonce, $body), (string) $sig);
}

/** The headers a signed request carries, ready to hand to wp_remote_request. */
function mutqan_headers($secret, $method, $route, $body, $host)
{
    $ts = (string) time();
    $nonce = bin2hex(random_bytes(16));
    return array(
        'Accept'            => 'application/json',
        'Content-Type'      => 'application/json',
        'X-Oxy-Host'        => $host,
        'X-Oxy-Timestamp'   => $ts,
        'X-Oxy-Nonce'       => $nonce,
        'X-Oxy-Signature'   => mutqan_sign($secret, $method, $route, $ts, $nonce, $body),
    );
}
