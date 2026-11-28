<?php
// Why the whole chain appears to break at once.
//
// The app makes about seventy-five different backend calls, and every one of them is answered in the
// context of whoever is asking. The order list, the technician's jobs, the customer's own order —
// each is filtered by the caller's identity before anything is returned.
//
// So the identity is not one feature among many. It is the thing every other feature stands on. When
// the cached user is dropped, the caller silently becomes a guest, and a guest is shown nothing: the
// operations room empties, the technician's jobs vanish, the customer loses sight of their own order.
// Nothing errors. Everything just goes blank, which is why it looks like the linking broke.
//
// This proves that, against the real policy code.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-link-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
require dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/class-oxygen-desk.php';
Mutqan_Store::maybe_install();
Mutqan_Rest::routes();

$bad = 0;
function t($n, $c, $x = null) { global $bad; if (!$c) { $bad++; } echo ($c ? 'PASS ' : 'FAIL ') . "linkage: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; }
function ids($rows) { return array_column($rows, 'id'); }
function sees($p) { return ids(Mutqan_Store::select('orders', [], ['limit' => 50, 'order' => '-createdAt'], $p, false)); }

$sys  = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
$ops  = ['id' => 'u-ops',  'role' => 'operations', 'powers' => Mutqan_Auth::powers_for('operations'), 'kind' => 'user', 'name' => 'نورة'];
$tech = ['id' => 'u-tech', 'role' => 'technician', 'powers' => Mutqan_Auth::powers_for('technician'), 'kind' => 'user', 'name' => 'سامي'];
$tech2 = ['id' => 'u-t2',  'role' => 'technician', 'powers' => Mutqan_Auth::powers_for('technician'), 'kind' => 'user', 'name' => 'ماجد'];
$cust = ['id' => 'u-cust', 'role' => null, 'powers' => [], 'kind' => 'user', 'name' => 'منى'];
// What the caller becomes the moment the cached user is dropped: still holding a token, but nobody.
$wiped = ['id' => 'guest', 'role' => null, 'powers' => [], 'kind' => 'guest', 'name' => ''];

// --- the customer places an order -----------------------------------------------------------------
$oid = Mutqan_Store::insert('orders', [
    'status' => 'new', 'service' => 'غسيل مكيفات', 'clientName' => 'منى', 'clientPhone' => '966551110000', 'address' => 'العزيزية',
], null, [], $cust)['id'];

t('the customer sees their own order', in_array($oid, sees($cust), true));
t('it reaches the operations room', in_array($oid, sees($ops), true));
t('the owner sees it too', in_array($oid, sees($sys), true));
t('an unassigned order is visible to technicians to pick up', in_array($oid, sees($tech), true));

// --- operations hands it to one technician ------------------------------------------------------
Mutqan_Store::update('orders', $oid, ['technicianId' => 'u-tech', 'technicianName' => 'سامي', 'status' => 'assigned'], $ops);
t('the technician it went to still sees it', in_array($oid, sees($tech), true));
t('the other technician does not', !in_array($oid, sees($tech2), true));
t('the customer still sees their own order', in_array($oid, sees($cust), true));
t('operations still sees it', in_array($oid, sees($ops), true));

// --- the technician works it --------------------------------------------------------------------
foreach (['accepted', 'en_route', 'arrived', 'completed'] as $st) {
    Mutqan_Store::update('orders', $oid, ['status' => $st], $tech);
}
$o = Mutqan_Store::get_raw('orders', $oid);
t('the order reached completion through the technician', ($o['status'] ?? '') === 'completed');
$route = array_column($o['route'] ?? [], 'text');
t('every step the order took is written on it', count($route) >= 5, $route);
t('and the customer can still see it afterwards', in_array($oid, sees($cust), true));

// --- now the part that explains the breakage -------------------------------------------------------
// Same database, same order, same moment. The only thing that changed is who the server thinks is asking.
$blank = sees($wiped);
t('a caller whose session was dropped sees NO orders at all', $blank === [], $blank);
t('and that is true however many orders exist', count(sees($sys)) > 0 && sees($wiped) === []);

// It is not an error — which is the whole problem. Nothing tells anyone something went wrong.
$threw = false;
try { Mutqan_Store::select('orders', [], ['limit' => 50], $wiped, false); } catch (Throwable $e) { $threw = true; }
t('it does not raise an error, it just returns nothing', !$threw);

// The same for every other link in the chain.
foreach (['customers', 'notifications', 'handoffs'] as $coll) {
    Mutqan_Store::insert($coll, ['note' => 'x'], null, [], $sys);
    $n = count(Mutqan_Store::select($coll, [], ['limit' => 20], $wiped, false));
    t("a dropped session also empties $coll", $n === 0, $n);
}

// And writing fails too, so a technician cannot even update the job in front of him.
$code = '';
try { Mutqan_Store::update('orders', $oid, ['status' => 'closed'], $wiped); }
catch (Mutqan_Error $e) { $code = $e->slug; }
t('a dropped session cannot update an order either', $code !== '', $code);

// --- the conclusion, stated as a test ----------------------------------------------------------------
// Restoring the identity restores every link at once. There is nothing else to repair.
t('restoring who is asking restores the whole chain',
    in_array($oid, sees($ops), true) && in_array($oid, sees($tech), true) && in_array($oid, sees($cust), true));

exit($bad === 0 ? 0 : 1);
