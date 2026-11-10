<?php
declare(strict_types=1);

/** AES-256-GCM sealing for per-site bridge secrets stored in MySQL. Key comes from config, never from the repo. */
final class Vault
{
    public static function seal(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            throw new RuntimeException('seal_failed');
        }
        return base64_encode($iv . $tag . $ct);
    }

    public static function open(string $blob): string
    {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('vault_format');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new RuntimeException('vault_auth');
        }
        return $plain;
    }

    private static function key(): string
    {
        return hex2bin((string) oxy_config()['master_key']);
    }
}
