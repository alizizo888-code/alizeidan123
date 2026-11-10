<?php
declare(strict_types=1);

/**
 * Request signing shared with the WordPress bridge plugin (bridge/oxy-bridge).
 * Canonical string = METHOD \n ROUTE \n TIMESTAMP \n NONCE \n sha256(BODY)
 * ROUTE is the REST route without the /wp-json prefix, e.g. /oxy-bridge/v1/status.
 */
final class Hmac
{
    public const SKEW_SECONDS = 300;

    public static function canonical(string $method, string $route, string $ts, string $nonce, string $body): string
    {
        return strtoupper($method) . "\n" . $route . "\n" . $ts . "\n" . $nonce . "\n" . hash('sha256', $body);
    }

    public static function sign(string $secret, string $method, string $route, string $ts, string $nonce, string $body): string
    {
        return hash_hmac('sha256', self::canonical($method, $route, $ts, $nonce, $body), $secret);
    }

    public static function verify(
        string $secret,
        string $method,
        string $route,
        string $ts,
        string $nonce,
        string $body,
        string $sig,
        int $now
    ): bool {
        if (!preg_match('/^\d{10}$/', $ts) || !preg_match('/^[a-f0-9]{32}$/', $nonce) || !preg_match('/^[a-f0-9]{64}$/', $sig)) {
            return false;
        }
        if (abs($now - (int) $ts) > self::SKEW_SECONDS) {
            return false;
        }
        return hash_equals(self::sign($secret, $method, $route, $ts, $nonce, $body), $sig);
    }
}
