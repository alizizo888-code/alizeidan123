<?php
declare(strict_types=1);

/**
 * Owner login: one 6-digit PIN (hashed, never in code), lockout after repeated failures,
 * server-side sessions with an HttpOnly/Secure/SameSite=Strict cookie, and a CSRF token on writes.
 */
final class Auth
{
    public const MAX_FAILS = 5;
    public const LOCK_SECONDS = 900;
    public const SESSION_SECONDS = 2592000; // 30 days
    public const COOKIE = 'oxy_s';

    public static function pinIsSet(): bool
    {
        return Settings::get('pin_hash') !== null;
    }

    public static function setPin(string $pin): void
    {
        if (!preg_match('/^\d{6}$/', $pin)) {
            throw new OxyHttpError(400, 'pin_format');
        }
        Settings::set('pin_hash', password_hash($pin, PASSWORD_DEFAULT));
        Settings::set('pin_fails', '0');
        Settings::set('pin_locked_until', '0');
        Audit::log('pin_set');
    }

    public static function checkPin(string $pin): void
    {
        $now = time();
        if ((int) (Settings::get('pin_locked_until') ?? '0') > $now) {
            throw new OxyHttpError(423, 'locked');
        }
        $hash = Settings::get('pin_hash');
        if ($hash === null) {
            throw new OxyHttpError(409, 'no_pin');
        }
        if (password_verify($pin, $hash)) {
            Settings::set('pin_fails', '0');
            return;
        }
        $fails = (int) (Settings::get('pin_fails') ?? '0') + 1;
        if ($fails >= self::MAX_FAILS) {
            Settings::set('pin_fails', '0');
            Settings::set('pin_locked_until', (string) ($now + self::LOCK_SECONDS));
            Audit::log('login_locked');
            throw new OxyHttpError(423, 'locked');
        }
        Settings::set('pin_fails', (string) $fails);
        Audit::log('login_failed');
        throw new OxyHttpError(401, 'bad_pin');
    }

    /** @return array{csrf:string, expires:int} */
    public static function login(string $pin): array
    {
        self::checkPin($pin);
        $token = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $now = time();
        $exp = $now + self::SESSION_SECONDS;
        $pdo = oxy_pdo();
        $pdo->prepare('DELETE FROM sessions WHERE expires < ?')->execute([$now]);
        $pdo->prepare('INSERT INTO sessions (token_hash, csrf, created, expires) VALUES (?, ?, ?, ?)')
            ->execute([hash('sha256', $token), $csrf, $now, $exp]);
        Audit::log('login_ok');
        self::setCookie($token, $exp);
        return ['csrf' => $csrf, 'expires' => $exp];
    }

    /** Returns the CSRF token of the current session, or throws 401. */
    public static function current(): string
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new OxyHttpError(401, 'no_session');
        }
        $stmt = oxy_pdo()->prepare('SELECT csrf, expires FROM sessions WHERE token_hash = ?');
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();
        if (!$row || (int) $row['expires'] < time()) {
            throw new OxyHttpError(401, 'no_session');
        }
        return (string) $row['csrf'];
    }

    public static function requireCsrf(): void
    {
        $csrf = self::current();
        if (!hash_equals($csrf, $_SERVER['HTTP_X_CSRF'] ?? '')) {
            throw new OxyHttpError(403, 'csrf');
        }
    }

    public static function logout(): void
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (preg_match('/^[a-f0-9]{64}$/', $token)) {
            oxy_pdo()->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([hash('sha256', $token)]);
        }
        self::setCookie('', 1);
    }

    private static function setCookie(string $value, int $expires): void
    {
        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
}
