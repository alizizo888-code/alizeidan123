<?php
// Order conversation: who may write / read which channel, what the server forces, and a technician handing HIS order to a colleague.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-omsg-' . getmypid());
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "order_msgs: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$ops = ['id' => 'u-ops', 'role' => 'operations', 'kind' => 'user', 'powers' => ['read', 'write', 'manage'], 'name' => 'x'];
$tech = ['id' => 'u-tech', 'role' => 'technician', 'kind' => 'user', 'powers' => [], 'name' => 'فني1'];
$tech2 = ['id' => 'u-tech2', 'role' => 'technician', 'kind' => 'user', 'powers' => [], 'name' => 'فني2'];
$cust = ['id' => 'anon-cust000001', 'role' => null, 'kind' => 'anon', 'powers' => []];
$other = ['id' => 'anon-other00001', 'role' => null, 'kind' => 'anon', 'powers' => []];
$code = function ($fn) { try { $fn(); return 200; } catch (Mutqan_Error $e) { return $e->status; } };
$o = ['visibleTo' => 'creator-and-admin', 'writableBy' => 'admins'];
$ord = Mutqan_Store::insert('orders', ['specialtyId' => 'ac', 'clientName' => 'س', 'clientPhone' => '0501234567', 'address' => 'ج'], null, $o, $cust, false);
$oid = $ord['id'];
Mutqan_Store::update('orders', $oid, ['technicianId' => 'u-tech', 'technicianName' => 'فني1', 'status' => 'assigned'], $ops);
$msg = function ($p, $d) use ($oid, $o) { return Mutqan_Store::insert('order_msgs', array_merge(['orderId' => $oid], $d), null, $o, $p, false); };

$m1 = $msg($cust, ['channel' => 'tech', 'kind' => 'text', 'text' => 'وين وصلت؟', 'from' => 'ops', 'clientId' => 'x']);
$t('customer writes; server forces from=client + ids from the order', $m1['from'] === 'client' && $m1['techId'] === 'u-tech' && $m1['clientId'] === 'anon-cust000001', $m1);
$m2 = $msg($cust, ['channel' => 'ops', 'kind' => 'text', 'text' => 'أريد موظف']);
$m3 = $msg($cust, ['channel' => 'tech', 'kind' => 'eta', 'text' => 'x']);
$t('customer cannot forge a technician-only kind', $m3['kind'] === 'text', $m3);
$m4 = $msg($cust, ['channel' => 'ops', 'kind' => 'nudge', 'text' => '']);
$t('customer nudge needs no text', $m4['kind'] === 'nudge');
$t('empty customer text refused', $code(function () use ($msg, $cust) { $msg($cust, ['channel' => 'ops', 'kind' => 'text', 'text' => '  ']); }) === 400);

$tq = Mutqan_Store::select('order_msgs', ['orderId' => $oid], ['limit' => 50], $tech, false);
$t('technician sees only the technician channel', count($tq) === 2 && !array_filter($tq, function ($r) { return $r['channel'] !== 'tech'; }), $tq);
$cq = Mutqan_Store::select('order_msgs', ['orderId' => $oid], ['limit' => 50], $cust, false);
$t('customer sees all four', count($cq) === 4, count($cq));
$oq = Mutqan_Store::select('order_msgs', ['orderId' => $oid], ['limit' => 50], $ops, false);
$t('operations sees everything', count($oq) === 4);
$xq = Mutqan_Store::select('order_msgs', ['orderId' => $oid], ['limit' => 50], $other, false);
$t('a stranger sees nothing', count($xq) === 0, $xq);
$tq2 = Mutqan_Store::select('order_msgs', ['orderId' => $oid], ['limit' => 50], $tech2, false);
$t('another technician sees nothing', count($tq2) === 0);
$t('a stranger cannot write into the order', $code(function () use ($msg, $other) { $msg($other, ['channel' => 'tech', 'text' => 'hi']); }) === 403);
$t('another technician cannot write into the order', $code(function () use ($msg, $tech2) { $msg($tech2, ['channel' => 'tech', 'text' => 'hi']); }) === 403);

$a = $msg($tech, ['channel' => 'ops', 'kind' => 'eta', 'text' => '']);
$t('technician eta is accepted and pinned to the technician channel', $a['from'] === 'tech' && $a['kind'] === 'eta' && $a['channel'] === 'tech', $a);
$b = $msg($ops, ['channel' => 'ops', 'kind' => 'text', 'text' => 'نتابع طلبك']);
$t('operations writes in the customer-service channel', $b['from'] === 'ops' && $b['channel'] === 'ops');
$t('unknown order refused', $code(function () use ($o, $ops) { Mutqan_Store::insert('order_msgs', ['orderId' => 'nope', 'text' => 'x'], null, $o, $ops, false); }) === 404);

// technician hands his own order to a colleague; a stranger technician cannot grab it
$u = Mutqan_Store::update('orders', $oid, ['technicianId' => 'u-tech2', 'technicianName' => 'فني2', 'status' => 'assigned'], $tech);
$t('technician reassigns HIS order to a colleague', $u['technicianId'] === 'u-tech2', $u);
$t('...but cannot take it back once it is the colleague\'s', $code(function () use ($oid, $tech) { Mutqan_Store::update('orders', $oid, ['technicianId' => 'u-tech', 'status' => 'accepted'], $tech); }) === 403 && Mutqan_Store::get_raw('orders', $oid)['technicianId'] === 'u-tech2');
echo $ok ? "ALL PASSED\n" : "SOME FAILED\n";
exit($ok ? 0 : 1);
