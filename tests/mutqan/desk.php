<?php
// Desk apps: PIN -> owner session -> the session can write settings and read the ops data. Run in-process (no HTTP).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-desk-' . getmypid());
require __DIR__ . '/wpshim.php';
require dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/class-oxygen-desk.php';
Mutqan_Store::maybe_install();
Mutqan_Rest::routes();
$bad = 0;
function t($n, $c, $x = null) { global $bad; if (!$c) { $bad++; } echo ($c ? 'PASS ' : 'FAIL ') . "desk: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; }
function call($path, $body, $token = '') {
    Mutqan_Auth::reset_memo();
    $h = ['x-mutqan-anon' => 'anon-test-0001']; if ($token) { $h['x-mutqan-token'] = $token; }
    $r = shim_dispatch('POST', '/wp-json/mutqan/v1/' . $path, $h, json_encode($body), []);
    return ['status' => $r->status, 'data' => $r->data['data'] ?? null, 'error' => $r->data['error'] ?? null];
}
$PIN = 'test-pin-' . bin2hex(random_bytes(4));
// 1. no PIN configured -> nobody gets in
$r = call('auth', ['op' => 'desk', 'token' => $PIN]);
t('refused while no PIN is set', $r['status'] === 403 && !isset($r['data']['token']), $r);
update_option('oxygen_desk_pin', password_hash($PIN, PASSWORD_DEFAULT));
$r = call('auth', ['op' => 'desk', 'token' => 'wrong']);
t('wrong PIN refused', $r['status'] === 403, $r);
// 2. fresh site (no owner row yet): the PIN creates/activates the owner session
$r = call('auth', ['op' => 'desk', 'token' => $PIN]);
t('PIN opens an owner session', $r['status'] === 200 && ($r['data']['user']['role'] ?? '') === 'owner' && preg_match('/^[a-f0-9]{64}$/', $r['data']['token'] ?? ''), $r);
$tok = $r['data']['token'] ?? '';
$me = call('auth', ['op' => 'me'], $tok);
t('me: valid owner', ($me['data']['user']['role'] ?? '') === 'owner', $me);
$w = call('db', ['op' => 'upsertShared', 'coll' => 'pricing', 'id' => 'contact_numbers', 'data' => ['serviceId' => 'contact_numbers', 'whatsapp' => '966500000000', 'phone' => '966500000000'], 'opts' => ['writableBy' => 'admins']], $tok);
t('owner session saves settings (pricing/contact_numbers)', $w['status'] === 200, $w);
$w = call('db', ['op' => 'upsertShared', 'coll' => 'pricing', 'id' => 'hotline', 'data' => ['serviceId' => 'hotline', 'enabled' => true], 'opts' => ['writableBy' => 'admins']], $tok);
t('owner session saves hotline settings', $w['status'] === 200, $w);
$g = call('team', ['op' => 'canManage'], $tok);
t('canManage', ($g['data']['value'] ?? null) === true, $g);
// 3. an existing owner account (ali123): the desk uses THAT row, still owner
$GLOBALS['wpdb']->query('DELETE FROM wp_mutqan_users'); $GLOBALS['wpdb']->query('DELETE FROM wp_mutqan_sessions');
Mutqan_Auth::set_owner('ali123', 'Ali', 'Some-Long-Pass-1');
$r = call('auth', ['op' => 'desk', 'token' => $PIN]);
t('existing owner row reused', ($r['data']['user']['handle'] ?? '') === 'ali123' && ($r['data']['user']['role'] ?? '') === 'owner', $r);
$tok2 = $r['data']['token'] ?? '';
$w = call('db', ['op' => 'upsertShared', 'coll' => 'pricing', 'id' => 'tax_info', 'data' => ['serviceId' => 'tax_info', 'seller' => 'X'], 'opts' => ['writableBy' => 'admins']], $tok2);
t('saves with the existing owner', $w['status'] === 200, $w);
// 4. owner credentials rewritten in wp-admin (set_owner) drops sessions: the old desk token must now be refused, a new PIN login works
Mutqan_Auth::set_owner('ali', 'Ali', 'Another-Long-Pass-2');
$m = call('auth', ['op' => 'me'], $tok2);
t('old token no longer valid after owner update', is_array($m['data'] ?? null) && array_key_exists('user', $m['data']) && $m['data']['user'] === null, $m);
$r = call('auth', ['op' => 'desk', 'token' => $PIN]);
t('PIN still works after owner update', ($r['data']['user']['role'] ?? '') === 'owner' && ($r['data']['user']['handle'] ?? '') === 'ali', $r);
exit($bad ? 1 : 0);
