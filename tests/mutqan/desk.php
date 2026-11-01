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
// 5. The PIN is tolerant of the keyboard: Arabic-Indic / Persian digits, spaces and direction marks mean the same PIN (a hash saved from either spelling still opens)
// (made-up test digits — never a real PIN)
t('norm_pin folds Arabic-Indic, Persian digits and spaces', Oxygen_Desk::norm_pin(" ٢٤٦ ٨۰١\u{200F}٣٥٧ ") === '246801357', Oxygen_Desk::norm_pin(" ٢٤٦ ٨۰١\u{200F}٣٥٧ "));
update_option('oxygen_desk_pin', password_hash('246801357', PASSWORD_DEFAULT));
t('Western hash: Arabic-Indic typing opens', Oxygen_Desk::valid_secret('٢٤٦٨٠١٣٥٧'));
t('Western hash: Western typing opens', Oxygen_Desk::valid_secret('246801357'));
t('Western hash: a wrong PIN stays refused', !Oxygen_Desk::valid_secret('246801358') && !Oxygen_Desk::valid_secret('') && !Oxygen_Desk::valid_secret(str_repeat('9', 80)));
update_option('oxygen_desk_pin', password_hash('٢٤٦٨٠١٣٥٧', PASSWORD_DEFAULT));   // an OLD hash made from the raw Arabic-Indic text
t('Old Arabic-Indic hash: Western typing opens', Oxygen_Desk::valid_secret('246801357'));
t('Old Arabic-Indic hash: Arabic-Indic typing opens', Oxygen_Desk::valid_secret('٢٤٦٨٠١٣٥٧'));
update_option('oxygen_desk_pin', password_hash($PIN, PASSWORD_DEFAULT));

// 6. Rate limit counts only WRONG PINs: many correct logins never lock the owner out, 12 wrong guesses do (then even the right PIN waits)
$okc = 0; for ($i = 0; $i < 30; $i++) { $r = call('auth', ['op' => 'desk', 'token' => $PIN]); if ($r['status'] === 200) { $okc++; } }
t('30 correct logins in a row all succeed (renewals are free)', $okc === 30, $okc);
$codes = []; for ($i = 0; $i < 11; $i++) { $codes[] = call('auth', ['op' => 'desk', 'token' => 'bad-' . $i])['status']; }   // +1 wrong guess made earlier in this file = 12
t('wrong guesses answer 403 until the limit', count(array_unique($codes)) === 1 && $codes[0] === 403, $codes);
$r = call('auth', ['op' => 'desk', 'token' => 'bad-x']);
t('after 12 wrong guesses the site waits (429)', $r['status'] === 429 && ($r['error']['code'] ?? '') === 'rate_limited', $r);
$r = call('auth', ['op' => 'desk', 'token' => $PIN]);
t('…and even the right PIN waits while locked', $r['status'] === 429, $r);
exit($bad ? 1 : 0);
