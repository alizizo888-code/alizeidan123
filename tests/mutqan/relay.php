<?php
// Hotline voice through the site when the phones cannot reach each other: only the two ends of the call, in order, short-lived.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-relay-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "relay: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$cust = ['id' => 'anon-caller0001', 'role' => null, 'kind' => 'anon', 'powers' => []];
$other = ['id' => 'anon-other00001', 'role' => null, 'kind' => 'anon', 'powers' => []];
$ops = ['id' => 'u-ops', 'role' => 'operations', 'kind' => 'user', 'powers' => ['read', 'write', 'manage'], 'name' => 'x'];
$tech = ['id' => 'u-tech', 'role' => 'technician', 'kind' => 'user', 'powers' => []];
$code = function ($fn) { try { $fn(); return 200; } catch (Mutqan_Error $e) { return $e->status; } };
$call = Mutqan_Store::insert('calls', ['status' => 'active', 'name' => 'عميل'], null, ['visibleTo' => 'creator-and-admin', 'writableBy' => 'creator-and-admin'], $cust, false);
$R = function ($in, $p) use ($call) { return Mutqan_Relay::handle($in + ['id' => $call['id']], $p); };
$clip = base64_encode(random_bytes(3000));
for ($i = 0; $i < 12; $i++) { $R(['op' => 'put', 'seq' => $i, 'mime' => 'audio/webm;codecs=opus', 'b' => $clip], $cust); }
$R(['op' => 'put', 'seq' => 3, 'b' => $clip], $cust);   // a retried upload is harmless
$g = $R(['op' => 'get', 'after' => -1], $ops);
$t('operations hears the caller\'s clips in order, eight at a time', array_column($g['clips'], 'seq') === [0, 1, 2, 3, 4, 5, 6, 7], array_column($g['clips'], 'seq'));
$g2 = $R(['op' => 'get', 'after' => 7], $ops);
$t('…then the rest, with 10 and 11 after 9 (not sorted as text)', array_column($g2['clips'], 'seq') === [8, 9, 10, 11], array_column($g2['clips'], 'seq'));
$t('the clip arrives byte for byte', $g['clips'][0]['b'] === $clip && $g['clips'][0]['mime'] === 'audio/webm;codecs=opus');
$t('the caller does not get his own voice back', $R(['op' => 'get', 'after' => -1], $cust)['clips'] === []);
$R(['op' => 'put', 'seq' => 0, 'mime' => 'audio/mp4', 'b' => $clip], $ops);
$t('and hears operations', count($R(['op' => 'get', 'after' => -1], $cust)['clips']) === 1);
$t('another customer is refused', $code(function () use ($R, $other) { $R(['op' => 'get', 'after' => -1], $other); }) === 403);
$t('a technician is refused', $code(function () use ($R, $tech) { $R(['op' => 'get', 'after' => -1], $tech); }) === 403);
$t('a clip that is not audio data is refused', $code(function () use ($R, $cust) { $R(['op' => 'put', 'seq' => 99, 'b' => '<script>'], $cust); }) === 400);
// The owner (staff) places a test call to operations: each end must stay on its own side, or nobody hears anything.
$own = ['id' => 'u-owner', 'role' => 'owner', 'kind' => 'user', 'powers' => ['read', 'write', 'manage'], 'name' => 'o'];
$c2 = Mutqan_Store::insert('calls', ['status' => 'active', 'name' => 'x'], null, ['visibleTo' => 'creator-and-admin', 'writableBy' => 'creator-and-admin'], $own, false);
Mutqan_Relay::handle(['op' => 'put', 'id' => $c2['id'], 'side' => 'c', 'seq' => 0, 'b' => $clip], $own);
Mutqan_Relay::handle(['op' => 'put', 'id' => $c2['id'], 'side' => 's', 'seq' => 0, 'b' => $clip], $ops);
$t('a staff caller is the caller end when the app says so', count(Mutqan_Relay::handle(['op' => 'get', 'id' => $c2['id'], 'side' => 's', 'after' => -1], $ops)['clips']) === 1 && count(Mutqan_Relay::handle(['op' => 'get', 'id' => $c2['id'], 'side' => 'c', 'after' => -1], $own)['clips']) === 1);
$t('a customer cannot claim the staff end (still gets operations\' clip, not his own 12)', count($R(['op' => 'get', 'side' => 's', 'after' => -1], $cust)['clips']) === 1);
Mutqan_Store::update('calls', $call['id'], ['status' => 'ended'], $ops);
$t('once the call ends the relay closes', $code(function () use ($R, $cust, $clip) { $R(['op' => 'put', 'seq' => 50, 'b' => $clip], $cust); }) === 410);
echo $ok ? "ALL PASSED\n" : "SOME FAILED\n";
exit($ok ? 0 : 1);
