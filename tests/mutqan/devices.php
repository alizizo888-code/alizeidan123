<?php
// One-device activation links: owner only, first device wins, same device may re-activate, revoke signs out.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . (getenv('MQ_DATA') ?: sys_get_temp_dir() . '/mutqan-dev-' . getmypid()));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "devices: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$err = function ($fn) { try { $fn(); return 0; } catch (Mutqan_Error $e) { return $e->status; } };
$owner = ['id' => 'u-own', 'role' => 'owner', 'kind' => 'user', 'powers' => ['read', 'write', 'manage']];
$ops = ['id' => 'u-ops', 'role' => 'operations', 'kind' => 'user', 'powers' => ['read', 'write', 'manage']];
$A = str_repeat('a', 24); $B = str_repeat('b', 24);
$ua = 'Mozilla/5.0 (Linux; Android 14; SM-A546E Build/UP1A; wv) AppleWebKit/537.36';

$t('operations cannot make links', $err(function () use ($ops) { Mutqan_Devices::handle(['op' => 'create', 'app' => 'tech', 'name' => 'x'], $ops); }) === 403);
$c = Mutqan_Devices::handle(['op' => 'create', 'app' => 'tech', 'name' => 'محمود', 'phone' => '0551234567'], $owner);
$t('link + code + whatsapp', strlen($c['code']) === 6 && strpos($c['url'], 'desk-tech.html#/activate/') !== false && strpos($c['whatsapp'], 'wa.me/966551234567') !== false, $c);
$t('technician profile created', (bool) Mutqan_Store::peek_doc('tech_profiles', $c['link']['id'] ? Mutqan_Store::get_raw('_device_links', $c['link']['id'])['userId'] : ''));
$token = substr($c['url'], strpos($c['url'], '/activate/') + 10);
$pk = Mutqan_Devices::handle(['op' => 'peek', 'token' => $token], []);
$t('peek shows name and apk', $pk['name'] === 'محمود' && $pk['status'] === 'pending' && strpos($pk['apk'], 'oxygen-desktech.apk') !== false, $pk);
$s1 = Mutqan_Devices::handle(['op' => 'activate', 'code' => $c['code'], 'deviceId' => $A, 'ua' => $ua], []);
$t('code activates and signs in as technician', !empty($s1['token']) && $s1['user']['role'] === 'technician', $s1);
$t('second device refused', $err(function () use ($token, $B) { Mutqan_Devices::handle(['op' => 'activate', 'token' => $token, 'deviceId' => $B], []); }) === 409);
$s2 = Mutqan_Devices::handle(['op' => 'activate', 'token' => $token, 'deviceId' => $A], []);
$t('same device can activate again', !empty($s2['token']));
$l = Mutqan_Devices::handle(['op' => 'list'], $owner)['links'];
$t('owner sees active device', $l[0]['status'] === 'active' && strpos($l[0]['device'], 'أندرويد 14') !== false && $l[0]['code'] === '', $l);
$t('db cannot read the links', Mutqan_Store::select('_device_links', [], ['limit' => 5], $ops) === []);
Mutqan_Devices::handle(['op' => 'revoke', 'id' => $l[0]['id']], $owner);
global $wpdb; $n = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Mutqan_Store::table('sessions') . ' WHERE user_id=%s', $s1['user']['id']));
$t('revoke signs the device out', $n === 0, $n);
$t('revoked link refused', $err(function () use ($token, $A) { Mutqan_Devices::handle(['op' => 'activate', 'token' => $token, 'deviceId' => $A], []); }) === 410);
$t('bad code', $err(function () use ($A) { Mutqan_Devices::handle(['op' => 'activate', 'code' => '000000', 'deviceId' => $A], []); }) === 404);

// existing member, wrong app, replacing links
$mem = Mutqan_Devices::handle(['op' => 'members', 'role' => 'technician'], $owner)['members'];
$t('members list', count($mem) === 1 && $mem[0]['name'] === 'محمود', $mem);
$t('technician cannot get the operations app', $err(function () use ($owner, $mem) { Mutqan_Devices::handle(['op' => 'create', 'app' => 'ops', 'userId' => $mem[0]['id']], $owner); }) === 422);
$c2 = Mutqan_Devices::handle(['op' => 'create', 'app' => 'tech', 'userId' => $mem[0]['id']], $owner);
$c3 = Mutqan_Devices::handle(['op' => 'create', 'app' => 'tech', 'userId' => $mem[0]['id']], $owner);
$t('a newer link replaces the waiting one', $err(function () use ($c2, $B) { Mutqan_Devices::handle(['op' => 'activate', 'code' => $c2['code'], 'deviceId' => $B], []); }) === 404);
$t('new phone activates the newest link', !empty(Mutqan_Devices::handle(['op' => 'activate', 'code' => $c3['code'], 'deviceId' => $B], [])['token']));
$o = Mutqan_Devices::handle(['op' => 'create', 'app' => 'ops', 'name' => 'سعيد', 'role' => 'supervisor'], $owner);
$t('supervisor link uses the operations app', strpos($o['url'], 'desk-ops.html') !== false && Mutqan_Devices::handle(['op' => 'activate', 'code' => $o['code'], 'deviceId' => $A], [])['user']['role'] === 'supervisor');
exit($ok ? 0 : 1);
