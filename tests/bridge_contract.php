<?php
declare(strict_types=1);

// Contract test: a request signed by the master must pass the bridge plugin's checks, and
// every tampered or replayed request must fail. Runs the real bridge file with minimal WordPress stubs.
putenv('OXY_CONFIG=' . __DIR__ . '/config.test.php');
require dirname(__DIR__) . '/src/bootstrap.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}
$GLOBALS['wp_options'] = [];
$GLOBALS['wp_transients'] = [];
function get_option($k, $d = false) { return $GLOBALS['wp_options'][$k] ?? $d; }
function add_option($k, $v, $dep = '', $autoload = 'yes') { $GLOBALS['wp_options'][$k] = $v; return true; }
function update_option($k, $v, $autoload = null) { $GLOBALS['wp_options'][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['wp_transients'][$k] ?? false; }
function set_transient($k, $v, $ttl = 0) { $GLOBALS['wp_transients'][$k] = $v; return true; }
function register_activation_hook($f, $cb) {}
function add_action($hook, $cb, $prio = 10, $args = 1) {}
function add_options_page(...$a) {}
function register_rest_route($ns, $route, $args) {}
function has_action($h) { return false; }

final class WP_Error
{
    public string $code;
    public function __construct(string $code, string $message = '', $data = null) { $this->code = $code; }
}

final class WP_REST_Request
{
    private string $method; private string $route; private array $headers; private string $body;
    public function __construct(string $method, string $route, array $headers, string $body)
    {
        $this->method = $method; $this->route = $route; $this->headers = $headers; $this->body = $body;
    }
    public function get_method(): string { return $this->method; }
    public function get_route(): string { return $this->route; }
    public function get_body(): string { return $this->body; }
    public function get_header(string $name): ?string { return $this->headers[strtolower($name)] ?? null; }
}

require dirname(__DIR__) . '/bridge/oxy-bridge/oxy-bridge.php';

$passed = 0;
$failed = 0;
function check(string $name, bool $cond): void
{
    global $passed, $failed;
    $cond ? $passed++ : $failed++;
    echo ($cond ? '  ok   ' : '  FAIL ') . $name . "\n";
}

$secret = bin2hex(random_bytes(32));
$GLOBALS['wp_options']['oxy_bridge_secret'] = $secret;

function signed(string $secret, string $method, string $route, string $body, ?string $ts = null, ?string $nonce = null): WP_REST_Request
{
    $ts = $ts ?? (string) time();
    $nonce = $nonce ?? bin2hex(random_bytes(16));
    $sig = Hmac::sign($secret, $method, $route, $ts, $nonce, $body);
    return new WP_REST_Request($method, $route, [
        'x-oxy-timestamp' => $ts,
        'x-oxy-nonce' => $nonce,
        'x-oxy-signature' => $sig,
    ], $body);
}

echo "Master signature accepted by the bridge\n";
$req = signed($secret, 'POST', '/oxy-bridge/v1/purge', '{"scope":"all"}');
check('valid POST purge is authorised', oxy_bridge_authorize($req) === true);
$req = signed($secret, 'GET', '/oxy-bridge/v1/status', '');
check('valid GET status is authorised (empty body hash matches)', oxy_bridge_authorize($req) === true);

echo "Rejections\n";
$good = signed($secret, 'POST', '/oxy-bridge/v1/purge', '{"scope":"all"}');
$tampered = new WP_REST_Request('POST', '/oxy-bridge/v1/purge', [
    'x-oxy-timestamp' => $good->get_header('x-oxy-timestamp'),
    'x-oxy-nonce' => $good->get_header('x-oxy-nonce'),
    'x-oxy-signature' => $good->get_header('x-oxy-signature'),
], '{"scope":"none"}');
check('tampered body is refused', oxy_bridge_authorize($tampered) instanceof WP_Error);

$replay = signed($secret, 'GET', '/oxy-bridge/v1/status', '', null, str_repeat('b', 32));
oxy_bridge_authorize($replay);
check('reused nonce is refused (replay)', (oxy_bridge_authorize($replay) instanceof WP_Error) && oxy_bridge_authorize($replay)->code === 'oxy_replay');

$stale = signed($secret, 'GET', '/oxy-bridge/v1/status', '', (string) (time() - 600));
check('timestamp outside 5 minutes is refused', oxy_bridge_authorize($stale) instanceof WP_Error);

$wrongSecret = signed(bin2hex(random_bytes(32)), 'GET', '/oxy-bridge/v1/status', '');
check('signature from another site secret is refused', oxy_bridge_authorize($wrongSecret) instanceof WP_Error);

$GLOBALS['wp_options']['oxy_bridge_secret'] = '';
check('unpaired face (no secret) refuses everything', oxy_bridge_authorize(signed($secret, 'GET', '/oxy-bridge/v1/status', '')) instanceof WP_Error);

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
