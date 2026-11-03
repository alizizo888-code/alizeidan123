<?php
declare(strict_types=1);

// Private application code. Never served by the web root (see .htaccess).
define('OXY_ROOT', dirname(__DIR__));

/** Error that maps directly to an HTTP status and a stable machine-readable code. */
final class OxyHttpError extends RuntimeException
{
    public int $status;
    public string $errCode;

    public function __construct(int $status, string $errCode)
    {
        parent::__construct($errCode);
        $this->status = $status;
        $this->errCode = $errCode;
    }
}

/**
 * Secrets live OUTSIDE the repository and the web root, in one PHP file that returns an array.
 * Default: <account>/oxy-master-config.php (two levels above this app). Override with OXY_CONFIG.
 */
function oxy_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $path = getenv('OXY_CONFIG') ?: dirname(OXY_ROOT, 2) . '/oxy-master-config.php';
    if (!is_file($path)) {
        throw new RuntimeException('config_missing');
    }
    $cfg = require $path;
    if (
        !is_array($cfg)
        || empty($cfg['db']['dsn'])
        || !preg_match('/^[a-f0-9]{64}$/', (string) ($cfg['master_key'] ?? ''))
        || strlen((string) ($cfg['setup_token'] ?? '')) < 32
        || strlen((string) ($cfg['cron_token'] ?? '')) < 32
    ) {
        throw new RuntimeException('config_invalid');
    }
    return $cfg;
}

function oxy_pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $db = oxy_config()['db'];
        $pdo = new PDO($db['dsn'], $db['user'] ?? null, $db['pass'] ?? null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function oxy_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function oxy_body(): array
{
    $raw = (string) file_get_contents('php://input');
    if ($raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new OxyHttpError(400, 'bad_json');
    }
    return $data;
}

function oxy_id(): string
{
    return bin2hex(random_bytes(16));
}

require_once OXY_ROOT . '/src/Hmac.php';
require_once OXY_ROOT . '/src/Vault.php';
require_once OXY_ROOT . '/src/Store.php';
require_once OXY_ROOT . '/src/Auth.php';
require_once OXY_ROOT . '/src/Sites.php';
require_once OXY_ROOT . '/src/Jobs.php';
