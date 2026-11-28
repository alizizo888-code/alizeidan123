<?php
// All sites in one operations room, and linking a new site from the owner's app.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . (getenv('MQ_DATA') ?: sys_get_temp_dir() . '/mutqan-netlink-' . getmypid()));
$_SERVER['HTTP_X_SITE_HOST'] = 'oxygen11.com';          // run as the hub
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "netlink: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$err = function ($fn) { try { $fn(); return 0; } catch (Mutqan_Error $e) { return $e->status; } };
$owner = ['id' => 'u-own', 'role' => 'owner', 'kind' => 'user', 'powers' => ['read', 'write', 'manage']];
$ops = ['id' => 'u-ops', 'role' => 'operations', 'kind' => 'user', 'powers' => ['read', 'write', 'manage']];
$tech = ['id' => 'u-t', 'role' => 'technician', 'kind' => 'user', 'powers' => ['read']];

$t('this is the hub', Oxygen_Network::is_hub());
$t('every face files to the hub, Mutqan included', Oxygen_Network::api_base('mutqan') === 'https://oxygen11.com/wp-json/mutqan/v1' && Oxygen_Network::api_base('jawda') !== '' && Oxygen_Network::api_base('oxygen') === '');
Mutqan_Store::insert('orders', ['status' => 'new', 'brand' => 'mutqan'], null, [], $owner);
Mutqan_Store::insert('orders', ['status' => 'completed', 'brand' => 'aqsa'], null, [], $owner);
Mutqan_Store::insert('orders', ['status' => 'new', 'brand' => 'aqsa'], null, [], $owner);
$s = Mutqan_Network::handle(['op' => 'list'], $ops)['sites'];
$by = array_column($s, null, 'id');
$t('22 sites listed with their work', count($s) === 22 && $by['aqsa']['month'] === 2 && $by['aqsa']['open'] === 1 && $by['mutqan']['open'] === 1 && $by['jawda']['month'] === 0, $by['aqsa'] ?? $s);
$t('technician cannot list the network', $err(function () use ($tech) { Mutqan_Network::handle(['op' => 'list'], $tech); }) === 403);
$t('operations cannot link a site', $err(function () use ($ops) { Mutqan_Network::handle(['op' => 'add', 'name' => 'x', 'host' => 'x.com'], $ops); }) === 403);
$t('bad domain refused', $err(function () use ($owner) { Mutqan_Network::handle(['op' => 'add', 'name' => 'موقع', 'host' => 'not a domain'], $owner); }) === 422);
$t('an existing domain refused', $err(function () use ($owner) { Mutqan_Network::handle(['op' => 'add', 'name' => 'موقع', 'host' => 'https://oxygen11.com/'], $owner); }) === 422);
$a = Mutqan_Network::handle(['op' => 'add', 'name' => 'برودة الخليج', 'host' => 'https://www.Baroda-Gulf.com/ar', 'color' => '#ff0000'], $owner);
$id = $a['site']['id'];
$t('new site linked', $a['site']['host'] === 'baroda-gulf.com' && $id === 'barodagulf' && count($a['steps']) >= 3, $a);
$t('it is part of the network now', Oxygen_Network::valid($id) && Oxygen_Network::host_of($id) === 'baroda-gulf.com' && Oxygen_Network::id_for_host('www.baroda-gulf.com') === $id && Oxygen_Network::api_base($id) === 'https://oxygen11.com/wp-json/mutqan/v1');
$t('its orders keep their tag', Oxygen_Network::clean_brand($id) === $id);
$t('the hub accepts calls from its domain', Mutqan_REST::linked_origin('https://baroda-gulf.com') && Mutqan_REST::linked_origin('https://www.baroda-gulf.com') && !Mutqan_REST::linked_origin('http://baroda-gulf.com') && !Mutqan_REST::linked_origin('https://evil.com'));
$t('23 sites listed', count(Mutqan_Network::handle(['op' => 'list'], $ops)['sites']) === 23);
$t('brands for every team screen', count(Mutqan_Network::handle(['op' => 'brands'], $tech)['sites']) === 23);
$t('core sites cannot be unlinked', $err(function () use ($owner) { Mutqan_Network::handle(['op' => 'remove', 'id' => 'aqsa'], $owner); }) === 422);
Mutqan_Network::handle(['op' => 'remove', 'id' => $id], $owner);
$t('unlinked', !Oxygen_Network::valid($id) && !Mutqan_REST::linked_origin('https://baroda-gulf.com'));
exit($ok ? 0 : 1);
