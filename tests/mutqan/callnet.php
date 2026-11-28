<?php
// Calls between the customer, the technician and operations: who may ring whom, operations' leave for the customer,
// and that each call is seen, answered and heard only by its two ends.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-callnet-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "callnet: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$code = function ($fn) { try { $fn(); return 200; } catch (Mutqan_Error $e) { return $e->status; } };
$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
$ops = ['id' => 'u-ops', 'role' => 'operations', 'kind' => 'user', 'powers' => ['read', 'write', 'manage'], 'name' => 'سارة'];
$tech = ['id' => 'u-tech1', 'role' => 'technician', 'kind' => 'user', 'powers' => [], 'name' => 'خالد'];
$tech2 = ['id' => 'u-tech2', 'role' => 'technician', 'kind' => 'user', 'powers' => [], 'name' => 'فهد'];
$cust = ['id' => 'anon-cust00042', 'role' => null, 'kind' => 'anon', 'powers' => []];
$stranger = ['id' => 'anon-other0001', 'role' => null, 'kind' => 'anon', 'powers' => []];
$C = function ($in, $p) { return Mutqan_Callnet::handle($in, $p); };
$pushTo = function ($u) use ($sys) { return array_values(array_filter(Mutqan_Store::select('_push', [], ['limit' => 300], $sys), function ($r) use ($u) { return ($r['target']['user'] ?? null) === $u; })); };
global $wpdb;
$wpdb->insert(Mutqan_Store::table('users'), ['id' => 'u-tech1', 'handle' => 'khaled', 'display_name' => 'خالد', 'role' => 'technician', 'status' => 'active', 'password_hash' => 'x', 'created_at' => Mutqan_Store::now()]);

$o = Mutqan_Store::insert('orders', ['specialtyId' => 'ac', 'clientName' => 'أبو محمد', 'clientPhone' => '0550000000', 'status' => 'new'], null, ['visibleTo' => 'creator-and-admin'], $cust, false);
Mutqan_Store::update('orders', $o['id'], ['technicianId' => 'u-tech1', 'technicianName' => 'خالد', 'status' => 'accepted'], $ops);
$oid = $o['id'];

// --- default rule: the customer asks, operations decides
$r = $C(['op' => 'can', 'orderId' => $oid], $cust);
$t('by default the customer must ask before calling the technician', $r['tech'] === false && $r['techState'] === 'ask' && $r['mode'] === 'manual', $r);
$t('…and cannot place the call without leave', $code(function () use ($C, $oid, $cust) { $C(['op' => 'start', 'to' => 'tech', 'orderId' => $oid], $cust); }) === 403);
$t('a customer cannot give himself leave by writing the order', ($code(function () use ($oid, $cust) { Mutqan_Store::update('orders', $oid, ['techCall' => ['state' => 'approved', 'techId' => 'u-tech1']], $cust); }) || true) && empty(Mutqan_Store::get_raw('orders', $oid)['techCall']));
$r = $C(['op' => 'request', 'orderId' => $oid], $cust);
$t('the request waits for operations', $r['techState'] === 'pending' && $C(['op' => 'can', 'orderId' => $oid], $cust)['techState'] === 'pending', $r);
$alerts = Mutqan_Store::select('notifications', ['event' => 'call_request'], ['limit' => 5], $sys);
$t('…and operations is alerted with the order', count($alerts) === 1 && $alerts[0]['orderId'] === $oid, $alerts);
$t('a technician cannot approve', $code(function () use ($C, $oid, $tech) { $C(['op' => 'decide', 'orderId' => $oid, 'allow' => true], $tech); }) === 403);
$C(['op' => 'decide', 'orderId' => $oid, 'allow' => true], $ops);
$t('operations approves: the customer may call, and is told', $C(['op' => 'can', 'orderId' => $oid], $cust)['tech'] === true && count($pushTo('anon-cust00042')) >= 1);
$call = $C(['op' => 'start', 'to' => 'tech', 'orderId' => $oid, 'name' => 'أبو محمد'], $cust);
$doc = Mutqan_Store::get_raw('calls', $call['id']);
$t('the call rings the technician, saying it is the customer', $doc['toUser'] === 'u-tech1' && $doc['from']['role'] === 'client' && $doc['from']['name'] === 'أبو محمد' && $doc['status'] === 'ringing', $doc);
$t('…and wakes the technician\'s phone', count($pushTo('u-tech1')) >= 1 && strpos($pushTo('u-tech1')[0]['title'], 'العميل') !== false, $pushTo('u-tech1'));
$seen = function ($p) use ($call) { return count(Mutqan_Store::select('calls', ['id' => $call['id']], ['limit' => 1], $p)); };
$t('the technician sees the call, another technician does not', $seen($tech) === 1 && $seen($tech2) === 0);
$t('another customer does not see it', $seen($stranger) === 0);
Mutqan_Store::update('calls', $call['id'], ['sSig' => ['sdp' => 'x'], 'status' => 'active', 'toUser' => 'u-tech2', 'name' => 'hack'], $tech);
$doc = Mutqan_Store::get_raw('calls', $call['id']);
$t('the technician answers; he cannot re-route the call or rename the caller', $doc['status'] === 'active' && $doc['sSig']['sdp'] === 'x' && $doc['toUser'] === 'u-tech1' && $doc['name'] === 'أبو محمد', $doc);
$t('another technician cannot touch it', $code(function () use ($call, $tech2) { Mutqan_Store::update('calls', $call['id'], ['status' => 'ended'], $tech2); }) === 403);
$clip = base64_encode(random_bytes(500));
Mutqan_Relay::handle(['op' => 'put', 'id' => $call['id'], 'side' => 'c', 'seq' => 0, 'b' => $clip], $cust);
Mutqan_Relay::handle(['op' => 'put', 'id' => $call['id'], 'side' => 's', 'seq' => 0, 'b' => $clip], $tech);
$t('voice through the site: the customer and the technician hear each other', count(Mutqan_Relay::handle(['op' => 'get', 'id' => $call['id'], 'side' => 's', 'after' => -1], $tech)['clips']) === 1 && count(Mutqan_Relay::handle(['op' => 'get', 'id' => $call['id'], 'side' => 'c', 'after' => -1], $cust)['clips']) === 1);
$t('operations cannot listen in on a call between the customer and the technician', $code(function () use ($call, $ops) { Mutqan_Relay::handle(['op' => 'get', 'id' => $call['id'], 'side' => 's', 'after' => -1], $ops); }) === 403);

// --- a new technician on the job: the old leave does not carry over
Mutqan_Store::update('orders', $oid, ['technicianId' => 'u-tech2', 'technicianName' => 'فهد'], $ops);
$t('a new technician on the job: the customer has to ask again', $C(['op' => 'can', 'orderId' => $oid], $cust)['techState'] === 'ask');
$C(['op' => 'request', 'orderId' => $oid], $cust);
$C(['op' => 'decide', 'orderId' => $oid, 'allow' => false], $ops);
$t('operations refuses: still no call', $C(['op' => 'can', 'orderId' => $oid], $cust)['techState'] === 'refused');

// --- the operations switch
$t('a technician cannot change the rule', $code(function () use ($C, $tech) { $C(['op' => 'setPolicy', 'clientToTech' => 'auto'], $tech); }) === 403);
$C(['op' => 'setPolicy', 'clientToTech' => 'auto'], $ops);
$t('«auto»: the customer may call the technician of his open order', $C(['op' => 'can', 'orderId' => $oid], $cust)['tech'] === true);
$t('…but a stranger may not use that order', $code(function () use ($C, $oid, $stranger) { $C(['op' => 'start', 'to' => 'tech', 'orderId' => $oid], $stranger); }) === 403);
$C(['op' => 'setPolicy', 'clientToTech' => 'off'], $ops);
$t('«off»: never', $C(['op' => 'can', 'orderId' => $oid], $cust)['techState'] === 'off');
Mutqan_Store::update('orders', $oid, ['status' => 'completed'], $ops);
$C(['op' => 'setPolicy', 'clientToTech' => 'auto'], $ops);
$t('a finished job closes the line even on «auto»', $C(['op' => 'can', 'orderId' => $oid], $cust)['tech'] === false);
Mutqan_Store::update('orders', $oid, ['status' => 'in_progress'], $ops);

// --- the technician and operations
$t('the technician on the job may call the customer', $C(['op' => 'can', 'orderId' => $oid], $tech2)['client'] === true);
$t('…a technician who is not on it may not', $C(['op' => 'can', 'orderId' => $oid], $tech)['client'] === false && $code(function () use ($C, $oid, $tech) { $C(['op' => 'start', 'to' => 'client', 'orderId' => $oid], $tech); }) === 403);
$tc = $C(['op' => 'start', 'to' => 'client', 'orderId' => $oid, 'name' => 'فهد'], $tech2);
$d = Mutqan_Store::get_raw('calls', $tc['id']);
$t('the customer\'s phone rings with the technician\'s name', $d['toUser'] === 'anon-cust00042' && $d['from'] === ['role' => 'tech', 'name' => 'فهد', 'id' => 'u-tech2'], $d);
$t('…and the customer can see and answer it', count(Mutqan_Store::select('calls', ['id' => $tc['id']], ['limit' => 1], $cust)) === 1 && $code(function () use ($tc, $cust) { Mutqan_Store::update('calls', $tc['id'], ['status' => 'active'], $cust); }) === 200);
$oc = $C(['op' => 'start', 'to' => 'client', 'orderId' => $oid], $ops);
$t('operations may call the customer of an order', Mutqan_Store::get_raw('calls', $oc['id'])['from']['role'] === 'ops');
$dt = $C(['op' => 'start', 'to' => 'tech', 'toUser' => 'u-tech1'], $ops);
$t('operations may ring a technician directly', Mutqan_Store::get_raw('calls', $dt['id'])['toUser'] === 'u-tech1');
$t('…but not a customer through that door', $code(function () use ($C, $ops) { $C(['op' => 'start', 'to' => 'tech', 'toUser' => 'anon-cust00042'], $ops); }) === 403);
$h = Mutqan_Store::insert('calls', ['status' => 'ringing', 'name' => 'فهد', 'toUser' => 'u-ops', 'to' => 'tech'], null, ['visibleTo' => 'creator-and-admin'], $tech2, false);
$t('a technician\'s hotline call goes to operations, marked as a technician (he cannot aim it at someone)', $h['to'] === 'ops' && empty($h['toUser']) && $h['from']['role'] === 'tech', $h);

echo $ok ? "ALL PASSED\n" : "SOME FAILED\n";
exit($ok ? 0 : 1);
