<?php
// One button for the owner: a strong hotline link code is generated, saved (as a hash) and this device paired;
// with automatic joining the operations team's devices answer the line without typing anything.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-lgen-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "link_generate: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$owner = ['id' => 'u-owner', 'role' => 'owner', 'kind' => 'user', 'powers' => ['read', 'write', 'manage'], 'name' => 'Ali'];
$ops = ['id' => 'u-ops', 'role' => 'operations', 'kind' => 'user', 'powers' => ['read', 'write', 'manage'], 'name' => 'Sara'];
$tech = ['id' => 'u-tech', 'role' => 'technician', 'kind' => 'user', 'powers' => [], 'name' => 't'];
$guest = ['id' => 'guest', 'role' => null, 'kind' => 'guest', 'powers' => []];
$code = function ($fn) { try { $fn(); return 200; } catch (Mutqan_Error $e) { return $e->slug . ':' . $e->status; } };
$L = function ($in, $p) { return Mutqan_Link::handle($in, $p); };

$t('only the owner may generate', $code(function () use ($L, $ops) { $L(['op' => 'generate', 'deviceId' => 'devops00002'], $ops); }) === 'forbidden:403');
$g = $L(['op' => 'generate', 'deviceId' => 'devowner0001'], $owner);
$t('a code comes back once, in readable groups', is_string($g['code'] ?? null) && preg_match('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $g['code']), $g);
$t('the line is set, this device is paired and automatic joining is on', $g['set'] === true && $g['linkedHere'] === true && $g['auto'] === true, $g);
$t('the code is stored only as a hash', strpos(json_encode(get_option(Mutqan_Link::OPT)), str_replace('-', '', $g['code'])) === false);
$t('status never shows the code again', !isset($L(['op' => 'status', 'deviceId' => 'devowner0001'], $owner)['code']));
$t('typed with the dash it matches', Mutqan_Link::verify($g['code']));
$t('typed without it, or in lower case with spaces, it matches', Mutqan_Link::verify(str_replace('-', '', $g['code'])) && Mutqan_Link::verify(strtolower(substr($g['code'], 0, 5)) . ' ' . strtolower(substr($g['code'], 6))));
$t('a wrong code does not', !Mutqan_Link::verify('ABCDE-FGHJK'));
$g2 = $L(['op' => 'generate', 'deviceId' => 'devowner0001'], $owner);
$t('each press makes a new code', $g2['code'] !== $g['code'] && !Mutqan_Link::verify($g['code']));

$p = $L(['op' => 'ping', 'deviceId' => 'devops00002', 'label' => 'جوال سارة'], $ops);
$t('an operations device joins by itself on its first ping', !empty($p['linked']) && !empty($p['joined']), $p);
$st = $L(['op' => 'state'], $guest);
$t('and the public button now sees the line answered', $st['linked'] === true && $st['online'] === true, $st);
$t('a technician cannot reach the line at all', $code(function () use ($L, $tech) { $L(['op' => 'ping', 'deviceId' => 'devtech00009'], $tech); }) === 'forbidden:403');

$a = $L(['op' => 'auto', 'on' => false, 'deviceId' => 'devowner0001'], $owner);
$t('the owner can turn automatic joining off', $a['auto'] === false);
$p3 = $L(['op' => 'ping', 'deviceId' => 'devops00003'], $ops);
$t('then a new device must type the code', empty($p3['linked']), $p3);
$t('…and typing it works', $L(['op' => 'link', 'code' => $g2['code'], 'deviceId' => 'devops00003'], $ops)['linkedHere'] === true);
$t('only the owner toggles it', $code(function () use ($L, $ops) { $L(['op' => 'auto', 'on' => true, 'deviceId' => 'devops00002'], $ops); }) === 'forbidden:403');
echo $ok ? "ALL PASSED\n" : "SOME FAILED\n";
exit($ok ? 0 : 1);
