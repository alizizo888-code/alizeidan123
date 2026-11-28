<?php
// Who is told when an order changes: the technician the server picked, the open pool, and the customer who
// ordered without an account.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-tell-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "dispatch_tell: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
$ops = ['id' => 'u-ops', 'role' => 'operations', 'kind' => 'user', 'powers' => ['read', 'write', 'manage'], 'name' => 'سارة'];
$tech = ['id' => 'u-tech1', 'role' => 'technician', 'kind' => 'user', 'powers' => [], 'name' => 'خالد'];
$anon = ['id' => 'anon-cust00042', 'role' => null, 'kind' => 'anon', 'powers' => []];
$pushes = function () use ($sys) { return Mutqan_Store::select('_push', [], ['limit' => 200, 'order' => 'createdAt'], $sys); };
$to = function ($rows, $k, $v) { return array_values(array_filter($rows, function ($r) use ($k, $v) { return ($r['target'][$k] ?? null) === $v; })); };

Mutqan_Store::upsert('assignment_settings', ['mode' => 'auto', 'strategy' => 'smart', 'siteFallback' => false], 'default', ['groupId' => 'main'], $sys);
Mutqan_Store::insert('tech_profiles', ['userId' => 'u-tech1', 'displayName' => 'خالد', 'active' => true, 'dailyCapacity' => 5, 'specialties' => ['ac'], 'lastSeenAt' => gmdate('c')], 'u-tech1', ['groupId' => 'main', 'visibleTo' => 'group'], $sys);
$o = Mutqan_Store::insert('orders', ['specialtyId' => 'ac', 'clientName' => 'ضيف', 'clientPhone' => '0550000000', 'address' => 'العزيزية', 'status' => 'new'], null, ['visibleTo' => 'creator-and-admin'], $anon, false);
$t('automatic distribution gave the order to the technician', ($o['technicianId'] ?? '') === 'u-tech1', $o['technicianId'] ?? null);
$tp = $to($pushes(), 'user', 'u-tech1');
$t('…and the technician is told at once, with the service and the area', count($tp) === 1 && strpos($tp[0]['title'], 'مسند لك') !== false && strpos($tp[0]['body'], 'العزيزية') !== false, $tp);
$cp = $to($pushes(), 'user', 'anon-cust00042');
$t('the customer without an account hears his order was assigned', count($cp) === 1 && strpos($cp[0]['title'], 'تم إسناد') !== false, $cp);

Mutqan_Store::update('orders', $o['id'], ['status' => 'en_route'], $tech);
$cp = $to($pushes(), 'user', 'anon-cust00042');
$t('…and that the technician is on the way, as an urgent alert', count($cp) === 2 && strpos($cp[1]['title'], 'في الطريق') !== false && $cp[1]['priority'] === 'high', $cp);
Mutqan_Store::update('orders', $o['id'], ['technicianName' => 'خالد'], $ops);
$t('a change that is not a step tells nobody', count($pushes()) === 3, count($pushes()));

// a manual assignment: the app's own message covers the technician, so the server does not send a second one
$o2 = Mutqan_Store::insert('orders', ['specialtyId' => 'plumbing', 'status' => 'new'], null, ['visibleTo' => 'creator-and-admin'], $ops, false);
$n = count($to($pushes(), 'user', 'u-tech2'));
Mutqan_Store::update('orders', $o2['id'], ['technicianId' => 'u-tech2', 'technicianName' => 'سعيد', 'status' => 'assigned'], $ops);
$t('a hand assignment is not doubled by the server', count($to($pushes(), 'user', 'u-tech2')) === $n);

// nobody fits: the open pool rings every technician
Mutqan_Store::update('tech_profiles', 'u-tech1', ['active' => false], $sys);
$o3 = Mutqan_Store::insert('orders', ['specialtyId' => 'ac', 'status' => 'new'], null, ['visibleTo' => 'creator-and-admin'], $anon, false);
$pool = $to($pushes(), 'role', 'technician');
$t('an order nobody fits goes to the pool and every technician hears it', ($o3['status'] ?? '') === 'forced' && count($pool) === 1, [$o3['status'] ?? null, $pool]);
echo $ok ? "ALL PASSED\n" : "SOME FAILED\n";
exit($ok ? 0 : 1);
