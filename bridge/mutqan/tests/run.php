<?php
/**
 * The plugin's own test run. It proves, without WordPress and without touching a live face:
 *  - the signature the plugin produces is the one the desk verifies (the desk's real Hmac class is used)
 *  - every refusal the desk's caller could hit says which check failed
 *  - a setting outside the allowed list, or out of range, is refused before anything is written
 *  - an order reaches the desk, and a failed send is queued and retried rather than lost
 *  - the public form route refuses what it should and never exposes a setting
 */

require __DIR__ . '/wp-stub.php';

$plugin = dirname(__DIR__);
require $plugin . '/inc/sign.php';
require $plugin . '/inc/settings.php';
require $plugin . '/inc/outbox.php';
require $plugin . '/inc/config.php';
require $plugin . '/inc/rest.php';
require $plugin . '/inc/form.php';
require $plugin . '/inc/admin.php';

// The desk's own signing code, so both sides are checked against each other and not against a copy.
require dirname(dirname($plugin)) . '/src/Hmac.php';

define('MUTQAN_VERSION', '3.0.0');
define('MUTQAN_SECRET_OPTION', 'oxy_bridge_secret');
define('MUTQAN_DESK_OPTION', 'mutqan_desk_url');
define('MUTQAN_QUEUE_OPTION', 'mutqan_outbox');

$passed = 0;
$failed = 0;
function check($label, $ok)
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        return;
    }
    $failed++;
    echo "  FAIL: $label\n";
}

/** A signed request the desk would send, built the way the desk builds it. */
function signed_request($secret, $method, $route, $body = '', $skewSeconds = 0, $nonce = null)
{
    $ts = (string) (time() + $skewSeconds);
    $nonce = $nonce === null ? bin2hex(random_bytes(16)) : $nonce;
    return new WP_REST_Request($method, $route, $body, array(
        'X-Oxy-Timestamp' => $ts,
        'X-Oxy-Nonce'     => $nonce,
        'X-Oxy-Signature' => Hmac::sign($secret, $method, $route, $ts, $nonce, $body),
    ));
}

$secret = str_repeat('a1', 32);
update_option(MUTQAN_SECRET_OPTION, $secret);

echo "Signing agrees with the desk\n";
$ts = '1700000000';
$nonce = str_repeat('b', 32);
check(
    'the plugin and the desk produce the same signature',
    mutqan_sign($secret, 'POST', '/mutqan/v1/settings', $ts, $nonce, '{"a":1}')
        === Hmac::sign($secret, 'POST', '/mutqan/v1/settings', $ts, $nonce, '{"a":1}')
);
check('the canonical strings match too', mutqan_canonical('GET', '/x', $ts, $nonce, '') === Hmac::canonical('GET', '/x', $ts, $nonce, ''));
check('a wrong body changes the signature', mutqan_sign($secret, 'POST', '/x', $ts, $nonce, 'a') !== mutqan_sign($secret, 'POST', '/x', $ts, $nonce, 'b'));
check('a wrong route changes the signature', mutqan_sign($secret, 'POST', '/x', $ts, $nonce, '') !== mutqan_sign($secret, 'POST', '/y', $ts, $nonce, ''));
check('a wrong secret is rejected', !mutqan_signature_ok($secret, 'GET', '/x', $ts, $nonce, '', mutqan_sign(str_repeat('c', 64), 'GET', '/x', $ts, $nonce, ''), (int) $ts));

echo "Authorization says which check failed\n";
$ok = mutqan_authorize(signed_request($secret, 'GET', '/mutqan/v1/status'));
check('a correctly signed request is accepted', $ok === true);

$bad = mutqan_authorize(new WP_REST_Request('GET', '/mutqan/v1/status', '', array()));
check('a request with no headers is 401, not 403', is_wp_error($bad) && $bad->get_status() === 401 && $bad->get_error_code() === 'mutqan_bad_headers');

$stale = mutqan_authorize(signed_request($secret, 'GET', '/mutqan/v1/status', '', 900));
check('an old timestamp is refused as stale', is_wp_error($stale) && $stale->get_error_code() === 'mutqan_stale');

$wrongSig = signed_request($secret, 'GET', '/mutqan/v1/status');
$wrongSig->set_header('X-Oxy-Signature', str_repeat('f', 64));
check('a wrong signature is refused as a mismatch', ($r = mutqan_authorize($wrongSig)) && is_wp_error($r) && $r->get_error_code() === 'mutqan_bad_signature');

$replayNonce = str_repeat('d', 32);
mutqan_authorize(signed_request($secret, 'GET', '/mutqan/v1/status', '', 0, $replayNonce));
$replay = mutqan_authorize(signed_request($secret, 'GET', '/mutqan/v1/status', '', 0, $replayNonce));
check('the same nonce twice is refused as a replay', is_wp_error($replay) && $replay->get_error_code() === 'mutqan_replay');

update_option(MUTQAN_SECRET_OPTION, '');
$unpaired = mutqan_authorize(signed_request($secret, 'GET', '/mutqan/v1/status'));
check('a site with no key says so plainly', is_wp_error($unpaired) && $unpaired->get_error_code() === 'mutqan_unpaired');
update_option(MUTQAN_SECRET_OPTION, $secret);

echo "Settings are checked before anything is written\n";
check('an unknown key is refused', is_wp_error(mutqan_validate(array('siteurl' => 'https://evil.example'))));
check('and nothing was written', get_option('siteurl', null) === null);
check('a number out of range is refused', is_wp_error(mutqan_validate(array('posts_per_page' => 500))));
check('a numeric string is accepted as a number', mutqan_validate(array('posts_per_page' => '12')) === array('posts_per_page' => 12));
check('markup in text is refused', is_wp_error(mutqan_validate(array('company_name' => 'أكسجين <script>'))));
check('a bad phone number is refused', is_wp_error(mutqan_validate(array('phone' => '12ab'))));
check('a good phone number is kept as written', mutqan_validate(array('whatsapp' => '+966555000111')) === array('whatsapp' => '+966555000111'));
check('an empty phone is allowed', mutqan_validate(array('phone' => '')) === array('phone' => ''));
check('a bad email is refused', is_wp_error(mutqan_validate(array('email' => 'not-an-email'))));
check('a non-boolean for a switch is refused', is_wp_error(mutqan_validate(array('chat_enabled' => 'yes'))));
check('too many services are refused', is_wp_error(mutqan_validate(array('services' => array_fill(0, 61, 'خدمة')))));
check('duplicate services collapse to one', mutqan_validate(array('services' => array('صيانة', 'صيانة'))) === array('services' => array('صيانة')));
check('an empty service item is refused', is_wp_error(mutqan_validate(array('services' => array('صيانة', '')))));

$write = mutqan_rest_settings_write(new WP_REST_Request('POST', '/mutqan/v1/settings', json_encode(array('values' => array(
    'company_name' => 'أكسجين للتكييف',
    'whatsapp'     => '+966555000111',
    'services'     => array('صيانة مكيف', 'تنظيف مكيف'),
    'chat_enabled' => true,
    'posts_per_page' => 9,
)), JSON_UNESCAPED_UNICODE)));
check('a good write reports what it saved', is_array($write) && $write['ok'] === true && in_array('company_name', $write['saved'], true));
check('the values read back as written', mutqan_get('company_name') === 'أكسجين للتكييف' && mutqan_get('whatsapp') === '+966555000111');
check('a switch reads back as a boolean', mutqan_get('chat_enabled') === true);
check('a list reads back as a list', mutqan_get('services') === array('صيانة مكيف', 'تنظيف مكيف'));
check('a core setting is stored under its own name', get_option('posts_per_page') === 9);
check('our own settings are namespaced', get_option('mutqan_company_name') === 'أكسجين للتكييف');

$empty = mutqan_rest_settings_write(new WP_REST_Request('POST', '/mutqan/v1/settings', '{"values":{}}'));
check('a write with no values is refused', is_wp_error($empty) && $empty->get_status() === 400);

$again = mutqan_rest_settings_write(new WP_REST_Request('POST', '/mutqan/v1/settings', json_encode(array('values' => array(
    'company_name' => 'أكسجين للتكييف',
    'city'         => 'جدة',
)), JSON_UNESCAPED_UNICODE)));
check('the reply tells the desk what really changed', $again['changed'] === array('city'));
check('and carries the value before the write', $again['before']['city'] === '' && $again['after']['city'] === 'جدة');

$read = mutqan_rest_settings_read(new WP_REST_Request('GET', '/mutqan/v1/settings'));
check('the read returns every allowed key', is_array($read) && count($read['values']) === count(mutqan_fields()));
check('the read never carries the key itself', !in_array($secret, array_map('strval', array_filter($read['values'], 'is_string')), true));

echo "Orders reach the desk, and a failure is kept\n";
update_option(MUTQAN_DESK_OPTION, 'https://desk.oxygen11.com/api');
$GLOBALS['wp_http_log'] = array();
$order = array('site_ref' => 'ref-1', 'name' => 'منى', 'phone' => '0555222333', 'service' => 'صيانة مكيف', 'source' => 'form');
$sent = mutqan_push_order($order);
check('an accepted order is not queued', $sent['ok'] === true && $sent['queued'] === false);
check('it went to the intake route', $GLOBALS['wp_http_log'][0]['url'] === 'https://desk.oxygen11.com/api/intake/order');
check('it carried this face as the host', $GLOBALS['wp_http_log'][0]['args']['headers']['X-Oxy-Host'] === 'oxygen11.com');

// The desk's own verifier must accept what the plugin signed. This is the whole contract in one check.
$outbound = $GLOBALS['wp_http_log'][0]['args'];
check('the desk accepts the signature the plugin sent', Hmac::verify(
    $secret,
    'POST',
    '/intake/order',
    $outbound['headers']['X-Oxy-Timestamp'],
    $outbound['headers']['X-Oxy-Nonce'],
    $outbound['body'],
    $outbound['headers']['X-Oxy-Signature'],
    time()
));

$GLOBALS['wp_http_next'] = new WP_Error('http_request_failed', 'cURL error 28');
$queued = mutqan_push_order(array('site_ref' => 'ref-2', 'service' => 'تنظيف مكيف'));
check('an order that could not be sent is queued', $queued['ok'] === false && $queued['queued'] === true);
check('the queue holds exactly one', count(mutqan_outbox_read()) === 1);
check('the reason is kept with it', strpos(mutqan_outbox_read()[0]['last_error'], 'network:') === 0);

mutqan_push_order(array('site_ref' => 'ref-2', 'service' => 'تنظيف مكيف'));
check('the same order is not queued twice', count(mutqan_outbox_read()) === 1);

$GLOBALS['wp_http_next'] = array('code' => 201, 'body' => '{"ok":true,"code":"c","order_id":"o"}');
$flush = mutqan_outbox_flush();
check('the retry sends what was waiting', $flush['sent'] === 1 && $flush['kept'] === 0);
check('and the queue is empty after it', mutqan_outbox_read() === array());

$GLOBALS['wp_http_next'] = array('code' => 503, 'body' => '{"ok":false,"error":"intake_paused"}');
mutqan_push_order(array('site_ref' => 'ref-3', 'service' => 'خدمة'));
check('a paused intake is kept to try again', count(mutqan_outbox_read()) === 1);
check('the desk reason is recorded', strpos(mutqan_outbox_read()[0]['last_error'], 'http_503:intake_paused') === 0);

$GLOBALS['wp_http_next'] = array('code' => 200, 'body' => '<html>firewall</html>');
mutqan_outbox_flush();
check('an HTML page is told apart from a real refusal', strpos(mutqan_outbox_read()[0]['last_error'], 'non_json:') === 0);

$queue = mutqan_outbox_read();
$queue[0]['attempts'] = MUTQAN_MAX_ATTEMPTS - 1;
mutqan_outbox_write($queue);
$GLOBALS['wp_http_next'] = new WP_Error('http_request_failed', 'down');
$last = mutqan_outbox_flush();
check('an order tried too many times is dropped, not kept forever', $last['dropped'] === 1 && mutqan_outbox_read() === array());

mutqan_outbox_write(array_fill(0, 260, array('order' => array('site_ref' => 'x'), 'attempts' => 1, 'first' => 0, 'last' => 0, 'last_error' => '')));
check('the queue is capped', count(mutqan_outbox_read()) === MUTQAN_QUEUE_MAX);
mutqan_outbox_write(array());

update_option(MUTQAN_DESK_OPTION, '');
$noDesk = mutqan_send_order(array('site_ref' => 'r'));
check('no desk address is a clear reason, not a crash', $noDesk['ok'] === false && $noDesk['error'] === 'desk_url_missing');
update_option(MUTQAN_DESK_OPTION, 'https://desk.oxygen11.com/api');

echo "The public form route\n";
$GLOBALS['wp_http_next'] = array('code' => 201, 'body' => '{"ok":true,"code":"c","order_id":"o"}');
update_option(mutqan_option_name('intake_enabled'), '1');
update_option(mutqan_option_name('services'), array('صيانة مكيف', 'تنظيف مكيف'));

$good = mutqan_public_order(new WP_REST_Request('POST', '/mutqan/v1/order', json_encode(array(
    'name' => 'منى الحربي', 'phone' => '0555222333', 'service' => 'صيانة مكيف', 'qty' => 2,
), JSON_UNESCAPED_UNICODE)));
check('a complete order is accepted with 201', $good->get_status() === 201 && $good->get_data()['ok'] === true);

$noPhone = mutqan_public_order(new WP_REST_Request('POST', '/mutqan/v1/order', '{"name":"x","phone":"12","service":"صيانة مكيف"}'));
check('a bad phone number is refused', $noPhone->get_status() === 400);

$noName = mutqan_public_order(new WP_REST_Request('POST', '/mutqan/v1/order', '{"phone":"0555222333","service":"صيانة مكيف"}'));
check('a missing name is refused', $noName->get_status() === 400);

$madeUp = mutqan_public_order(new WP_REST_Request('POST', '/mutqan/v1/order', json_encode(array(
    'name' => 'x', 'phone' => '0555222333', 'service' => 'خدمة مش موجودة',
), JSON_UNESCAPED_UNICODE)));
check('a service the face does not offer is refused', $madeUp->get_status() === 400);

$bot = mutqan_public_order(new WP_REST_Request('POST', '/mutqan/v1/order', '{"name":"x","phone":"0555222333","service":"صيانة مكيف","website":"http://spam"}'));
check('a filled honeypot is answered as accepted and sent nowhere', $bot->get_status() === 201);
$before = count($GLOBALS['wp_http_log']);
mutqan_public_order(new WP_REST_Request('POST', '/mutqan/v1/order', '{"name":"x","phone":"0555222333","service":"صيانة مكيف","website":"spam"}'));
check('the bot order really was not sent', count($GLOBALS['wp_http_log']) === $before);

update_option(mutqan_option_name('intake_enabled'), '0');
$closed = mutqan_public_order(new WP_REST_Request('POST', '/mutqan/v1/order', '{"name":"x","phone":"0555222333","service":"صيانة مكيف"}'));
check('a closed intake refuses with 503', $closed->get_status() === 503);
check('and the form says so instead of rendering', strpos(mutqan_order_form(), 'متوقف') !== false);
update_option(mutqan_option_name('intake_enabled'), '1');

$GLOBALS['wp_transients'] = array();
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.9';
check('behind a proxy the visitor is counted, not the proxy', mutqan_client_ip() === '203.0.113.9');
$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
check('a forged header that is not an address is ignored', mutqan_client_ip() === '10.0.0.1');
unset($_SERVER['HTTP_CF_CONNECTING_IP']);

$GLOBALS['wp_transients'] = array();
$allowed = 0;
for ($i = 0; $i < MUTQAN_RATE_LIMIT + 3; $i++) {
    $r = mutqan_public_order(new WP_REST_Request('POST', '/mutqan/v1/order', json_encode(array(
        'name' => 'x' . $i, 'phone' => '055522233' . ($i % 10), 'service' => 'صيانة مكيف',
    ), JSON_UNESCAPED_UNICODE)));
    if ($r->get_status() === 201) { $allowed++; }
}
check('the rate limit stops a flood', $allowed === MUTQAN_RATE_LIMIT);

$GLOBALS['wp_transients'] = array();
$form = mutqan_order_form();
check('the form offers the services the desk set', strpos($form, 'تنظيف مكيف') !== false);
check('the script finds the form by its own id, not by position', preg_match('/id="(mutqan-form-\d+)"/', $form, $m) && strpos($form, "getElementById('" . $m[1] . "')") !== false);
check('two forms on one page do not collide', mutqan_order_form() !== $form);
check('the form carries a honeypot', strpos($form, 'mutqan-trap') !== false);
check('the form never carries the key', strpos($form, $secret) === false);

echo "Configuration sections\n";
check('every section the site menu shows has a key', count(mutqan_config_sections()) >= 24);
check('an unknown section reads as nothing', mutqan_config_get('does_not_exist') === null);
check('a section never written reads as an empty document', mutqan_config_get('pricing') === array());

$wrote_req = new WP_REST_Request('POST', '/mutqan/v1/config/pricing', json_encode(array(
    'config' => array('wash_split' => 90, 'discount_pct' => 10, 'note' => 'خصم الشتاء'),
), JSON_UNESCAPED_UNICODE));
$wrote_req->set_param('section', 'pricing');
$wrote = mutqan_rest_config_write($wrote_req);
check('a section is written and reports that it changed', is_array($wrote) && $wrote['ok'] === true && $wrote['changed'] === true);
check('it reads back exactly', mutqan_config_get('pricing') === array('wash_split' => 90, 'discount_pct' => 10, 'note' => 'خصم الشتاء'));

$same = new WP_REST_Request('POST', '/mutqan/v1/config/pricing', json_encode(array(
    'config' => array('wash_split' => 90, 'discount_pct' => 10, 'note' => 'خصم الشتاء'),
), JSON_UNESCAPED_UNICODE));
$same->set_param('section', 'pricing');
check('writing the same document reports no change', mutqan_rest_config_write($same)['changed'] === false);

$unknown = new WP_REST_Request('POST', '/mutqan/v1/config/not_a_section', '{"config":{}}');
$unknown->set_param('section', 'not_a_section');
check('an unknown section is refused with 404', is_wp_error(($u = mutqan_rest_config_write($unknown))) && $u->get_status() === 404);

check('a document that is not a document is refused', is_wp_error(mutqan_config_check('text')));
check('a script in a value is refused', is_wp_error(mutqan_config_check(array('banner' => '<script>alert(1)</script>'))));
check('an iframe in a value is refused', is_wp_error(mutqan_config_check(array('x' => '<iframe src=x>'))));
check('plain text with an angle bracket is still allowed', !is_wp_error(mutqan_config_check(array('note' => 'السعر < 100'))));
// Naming a few tags is not enough: these carry no script tag and still run in a browser.
foreach (array('<img src=x onerror=alert(1)>', '<svg onload=alert(1)>', '<body onload=x>', '<a href="javascript:void(0)">x</a>', '<IMG SRC=x ONERROR=y>') as $attack) {
    check('a tag that runs code is refused: ' . substr($attack, 0, 16), is_wp_error(mutqan_config_check(array('v' => $attack))));
}
foreach (array('السعر < 100 ريال', '5 < 7 و 9 > 3', 'a < b', 'خصم 10% < الحد') as $fine) {
    check('ordinary text with a bracket still passes: ' . mb_substr($fine, 0, 12), !is_wp_error(mutqan_config_check(array('v' => $fine))));
}
// The key cap only works if the counter starts: a flat document with too many keys must be refused.
check('a document with too many keys is refused', is_wp_error(mutqan_config_check(array_fill_keys(array_map(static fn ($i) => 'k' . $i, range(1, 401)), 1))));
check('a document just under the cap passes', !is_wp_error(mutqan_config_check(array_fill_keys(array_map(static fn ($i) => 'k' . $i, range(1, 300)), 1))));
check('a key with odd characters is refused', is_wp_error(mutqan_config_check(array('bad key!' => 1))));
check('a document nested too deeply is refused', is_wp_error(mutqan_config_check(array('a' => array('b' => array('c' => array('d' => array('e' => array('f' => array('g' => 1))))))))));
check('a document that is too large is refused', is_wp_error(mutqan_config_check(array('big' => str_repeat('a', 70000)))));
check('a value of a type that cannot be stored is refused', is_wp_error(mutqan_config_check(array('x' => new stdClass()))));
check('numbers, booleans and null are all fine', !is_wp_error(mutqan_config_check(array('a' => 1, 'b' => 1.5, 'c' => true, 'd' => null))));

$readAll = new WP_REST_Request('GET', '/mutqan/v1/config');
$readAll->set_param('section', '');
$all = mutqan_rest_config_read($readAll);
check('reading with no section returns every section', count($all['config']) === count(mutqan_config_sections()));
check('and the Arabic labels come with it', $all['sections']['pricing'] === 'التسعير والخصومات');

echo "Conflicts with another plugin are caught, not left silent\n";
check('a clean site reports no conflict', mutqan_conflicts() === array());
// The old bridge registers the same namespace; whichever loads last wins, so the owner must be told.
// Declared inside a block on purpose: a plain top-level declaration is hoisted and would exist
// before the clean-site check above ever ran.
if (true) { function oxy_bridge_routes() {} }
check('the old bridge plugin is spotted', count(mutqan_conflicts()) === 1);
if (true) { class Oxygen_Orders_Chat { public static function push_to_desk($o) {} } }
$both = mutqan_conflicts();
check('the duplicate order sender is spotted too', count($both) === 2);
check('each conflict says what to do about it', strpos($both[0], 'عطّلها') !== false && strpos($both[1], 'وقّف') !== false);

echo "Routes and status\n";
$GLOBALS['wp_routes'] = array();
mutqan_register_routes();
mutqan_register_public_route();
$paths = array_keys($GLOBALS['wp_routes']);
foreach (array('mutqan/v1/status', 'mutqan/v1/settings', 'mutqan/v1/posts', 'mutqan/v1/purge', 'mutqan/v1/order', 'mutqan/v1/config(?:/(?P<section>[a-z_]{1,40}))?') as $want) {
    check("the route $want is registered", in_array($want, $paths, true));
}
check('the old namespace still answers, so the desk keeps working', in_array('oxy-bridge/v1/settings', $paths, true));
check('the public order route is the only unsigned one', count(array_filter($GLOBALS['wp_routes'], static function ($specs, $path) {
    foreach ($specs as $spec) {
        $entries = isset($spec['methods']) ? array($spec) : $spec;
        foreach ($entries as $entry) {
            if (($entry['permission_callback'] ?? null) === '__return_true' && $path !== 'mutqan/v1/order') {
                return true;
            }
        }
    }
    return false;
}, ARRAY_FILTER_USE_BOTH)) === 0);

$status = mutqan_rest_status(new WP_REST_Request('GET', '/mutqan/v1/status'));
check('status reports the version and the link', $status['plugin'] === MUTQAN_VERSION && $status['desk_linked'] === true);
check('status never carries the key', !in_array($secret, array_map('strval', array_filter($status, 'is_string')), true));

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
