<?php
/**
 * The contract between the two sides, checked end to end in one process.
 *
 * The unit runs prove each side on its own. What breaks a two-part system is the join: the desk signs
 * one string and the plugin verifies another, or the plugin answers a shape the desk does not read.
 * So this builds the exact request the desk's Sites::call would put on the wire, hands it to the
 * plugin's real authorize and handler, and checks the reply against what the desk's Config expects.
 *
 * Nothing is mocked between the two: the desk's Hmac signs, the plugin's own code verifies.
 */

require __DIR__ . '/wp-stub.php';

$plugin = dirname(__DIR__);
$desk = dirname(dirname($plugin));

require $plugin . '/inc/sign.php';
require $plugin . '/inc/settings.php';
require $plugin . '/inc/outbox.php';
require $plugin . '/inc/config.php';
require $plugin . '/inc/rest.php';
require $desk . '/src/Hmac.php';

define('MUTQAN_VERSION', '3.0.0');
define('MUTQAN_SECRET_OPTION', 'oxy_bridge_secret');
define('MUTQAN_DESK_OPTION', 'mutqan_desk_url');
define('MUTQAN_QUEUE_OPTION', 'mutqan_outbox');

$passed = 0;
$failed = 0;
function check($label, $ok)
{
    global $passed, $failed;
    if ($ok) { $passed++; return; }
    $failed++;
    echo "  FAIL: $label\n";
}

$secret = str_repeat('9f', 32);
update_option(MUTQAN_SECRET_OPTION, $secret);

/**
 * Exactly what src/Sites.php puts on the wire: the same canonical string, the same headers, the same
 * body encoding. If Sites::call changes, this is the test that should be changed with it.
 */
function desk_calls($secret, $method, $route, ?array $body = null)
{
    $payload = $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $ts = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $request = new WP_REST_Request($method, $route, $payload, array(
        'X-Oxy-Timestamp' => $ts,
        'X-Oxy-Nonce'     => $nonce,
        'X-Oxy-Signature' => Hmac::sign($secret, $method, $route, $ts, $nonce, $payload),
    ));
    // WordPress fills the path parts from the route pattern; do the same here.
    if (preg_match('#^/[a-z-]+/v1/config/([a-z_]+)$#', $route, $m)) {
        $request->set_param('section', $m[1]);
    } elseif (preg_match('#^/[a-z-]+/v1/config$#', $route)) {
        $request->set_param('section', '');
    }

    $allowed = mutqan_authorize($request);
    if (is_wp_error($allowed)) {
        return array('ok' => false, 'code' => $allowed->get_status(), 'error' => $allowed->get_error_code(), 'data' => null);
    }
    $handlers = array(
        'config_get'  => 'mutqan_rest_config_read',
        'config_post' => 'mutqan_rest_config_write',
        'settings_get'  => 'mutqan_rest_settings_read',
        'settings_post' => 'mutqan_rest_settings_write',
        'status_get'  => 'mutqan_rest_status',
    );
    $which = (strpos($route, '/config') !== false ? 'config' : (strpos($route, '/settings') !== false ? 'settings' : 'status'))
        . '_' . strtolower($method);
    $reply = $handlers[$which]($request);
    if (is_wp_error($reply)) {
        return array('ok' => false, 'code' => $reply->get_status(), 'error' => $reply->get_error_code(), 'data' => null);
    }
    // Sites::call treats a reply as good only when it carries ok:true, so check that here too.
    return array('ok' => !empty($reply['ok']), 'code' => 200, 'error' => null, 'data' => $reply);
}

echo "The desk reaches the face over both namespaces\n";
foreach (array('/mutqan/v1/status', '/oxy-bridge/v1/status') as $route) {
    $r = desk_calls($secret, 'GET', $route);
    check("a signed GET $route is accepted", $r['ok'] === true);
}
$r = desk_calls(str_repeat('11', 32), 'GET', '/mutqan/v1/status');
check('a request signed with the wrong key is refused', $r['ok'] === false && $r['error'] === 'mutqan_bad_signature');

echo "A settings section travels whole\n";
$write = desk_calls($secret, 'POST', '/mutqan/v1/config/pricing', array('config' => array(
    'wash_split'   => 90,
    'wash_window'  => 120,
    'discount_pct' => 10,
    'free_above'   => 500,
    'note'         => 'خصم الشتاء — حتى نهاية فبراير',
    'tiers'        => array('new' => 0, 'repeat' => 5, 'gold' => 12),
)));
check('the face accepts the section', $write['ok'] === true);
check('and says it changed', $write['data']['changed'] === true);

$read = desk_calls($secret, 'GET', '/mutqan/v1/config/pricing');
check('reading it back gives the same document', $read['data']['config']['tiers'] === array('new' => 0, 'repeat' => 5, 'gold' => 12));
check('Arabic survives the round trip unchanged', $read['data']['config']['note'] === 'خصم الشتاء — حتى نهاية فبراير');
check('numbers come back as numbers, not text', $read['data']['config']['wash_split'] === 90);

$again = desk_calls($secret, 'POST', '/mutqan/v1/config/pricing', array('config' => $read['data']['config']));
check('writing the same document reports no change', $again['data']['changed'] === false);

echo "Every section the owner sees can hold a document\n";
$stored = 0;
foreach (array_keys(mutqan_config_sections()) as $section) {
    $w = desk_calls($secret, 'POST', '/mutqan/v1/config/' . $section, array('config' => array('enabled' => true, 'label' => 'قسم ' . $section)));
    if ($w['ok'] && $w['data']['changed']) { $stored++; }
}
check('all of them round trip', $stored === count(mutqan_config_sections()));

$all = desk_calls($secret, 'GET', '/mutqan/v1/config');
check('one read brings every section back', count($all['data']['config']) === count(mutqan_config_sections()));
check('with the labels the owner knows', $all['data']['sections']['warranties'] === 'الضمانات');

echo "A bad document is refused by the face, not half written\n";
$before = mutqan_config_get('warranties');
$bad = desk_calls($secret, 'POST', '/mutqan/v1/config/warranties', array('config' => array('x' => '<script>steal()</script>')));
check('a script is refused', $bad['ok'] === false && $bad['error'] === 'mutqan_bad_config');
check('and the section is untouched', mutqan_config_get('warranties') === $before);

$missing = desk_calls($secret, 'POST', '/mutqan/v1/config/not_a_section', array('config' => array()));
check('an unknown section is a 404', $missing['ok'] === false && $missing['code'] === 404);

echo "A signature is bound to its own section\n";
// The section is part of the path, so a signature for one section must not work on another.
$payload = json_encode(array('config' => array('taken' => true)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$ts = (string) time();
$nonce = bin2hex(random_bytes(16));
$moved = new WP_REST_Request('POST', '/mutqan/v1/config/accounts', $payload, array(
    'X-Oxy-Timestamp' => $ts,
    'X-Oxy-Nonce'     => $nonce,
    // signed for pricing, replayed at accounts
    'X-Oxy-Signature' => Hmac::sign($secret, 'POST', '/mutqan/v1/config/pricing', $ts, $nonce, $payload),
));
$moved->set_param('section', 'accounts');
check('a signature cannot be moved to another section', is_wp_error(mutqan_authorize($moved)));

echo "The bulk settings reply is the shape the desk reads\n";
$bulk = desk_calls($secret, 'POST', '/oxy-bridge/v1/settings', array('values' => array('blogname' => 'أكسجين', 'whatsapp' => '+966555000111')));
foreach (array('changed', 'before', 'after') as $key) {
    check("the reply carries $key, which Bulk::apply reads", array_key_exists($key, $bulk['data']));
}
check('changed names what really changed', $bulk['data']['changed'] === array('blogname', 'whatsapp'));

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
