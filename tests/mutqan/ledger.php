<?php
// «سجل العملاء الدائم» (owner, 2026-11-21): once a customer, always on the map — even after his order is deleted;
// a new order knows «worked here before» / «next to an old customer»; the trip of a technician to his job.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $n = 0;
$t = function ($name, $c, $x = null) use (&$ok, &$n) { $n++; echo ($c ? 'PASS ' : 'FAIL ') . 'ledger: ' . $name . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'علي'];
$sh = ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'];
$Lg = 'Mutqan_Ledger';

$o1 = Mutqan_Store::insert('orders', ['number' => '2001', 'status' => 'completed', 'service' => 'غسيل 2 سبليت', 'clientName' => 'أبو خالد', 'clientPhone' => '966551110001', 'technicianName' => 'محمود', 'lat' => 21.4000, 'lng' => 39.8000, 'address' => 'العزيزية', 'source' => 'whatsapp'], null, $sh, $sys);
$r = $Lg::card('551110001');
$t('every order lands in the customer\'s permanent record', $r && $r['name'] === 'أبو خالد' && count($r['orders']) === 1 && count($r['places']) === 1, $r);

// the same customer again: «worked here before»
$o2 = Mutqan_Store::insert('orders', ['number' => '2002', 'status' => 'new', 'service' => 'صيانة مكيف', 'clientName' => 'أبو خالد', 'clientPhone' => '0551110001', 'lat' => 21.40001, 'lng' => 39.80001], null, $sh, $sys);
$g2 = Mutqan_Store::get_raw('orders', $o2['id']);
$t('a repeat customer\'s new order carries «عميل سابق» with what was done and by whom', !empty($g2['history']['repeat']) && mb_strpos($g2['history']['text'], 'غسيل 2 سبليت') !== false && mb_strpos($g2['history']['text'], 'محمود') !== false, $g2['history'] ?? null);
$r = $Lg::card('551110001');
$t('…the same spot stays one place, two orders', count($r['places']) === 1 && count($r['orders']) === 2, $r);

// a neighbour 60 m away
$o3 = Mutqan_Store::insert('orders', ['number' => '2003', 'status' => 'new', 'service' => 'تركيب', 'clientName' => 'جار', 'clientPhone' => '966552220002', 'lat' => 21.4005, 'lng' => 39.8000], null, $sh, $sys);
$g3 = Mutqan_Store::get_raw('orders', $o3['id']);
$t('a new customer next to an old one: «جنب عميل قديم» with the distance', empty($g3['history']['repeat']) && !empty($g3['history']['nearby']) && mb_strpos($g3['history']['text'], 'أبو خالد') !== false && $g3['history']['nearby']['m'] < 100, $g3['history'] ?? null);

// deleting the order keeps the customer
Mutqan_Store::delete('orders', $o1['id'], $sys);
$r = $Lg::card('551110001');
$del = array_values(array_filter($r['orders'], function ($x) use ($o1) { return $x['id'] === $o1['id']; }));
$t('deleting an order keeps the customer and marks that order «deleted»', $r && $del && !empty($del[0]['deleted']) && count($r['places']) === 1, $r);
$rows = $Lg::map_rows();
$t('the map layer still shows him', (bool) array_filter($rows, function ($x) { return $x['key'] === '551110001'; }), $rows);

// a new place for the same customer
Mutqan_Store::insert('orders', ['number' => '2004', 'status' => 'new', 'service' => 'غسيل', 'clientName' => 'أبو خالد', 'clientPhone' => '966551110001', 'lat' => 21.5, 'lng' => 39.9], null, $sh, $sys);
$t('a far address is a second place for the same customer', count($Lg::card('551110001')['places']) === 2);

// backfill: orders that existed before the ledger
$GLOBALS['wpdb']->query('DELETE FROM ' . Mutqan_Store::table('docs') . " WHERE collection = 'client_ledger'");
$t('backfill rebuilds the record from the existing orders', $Lg::backfill() >= 3 && $Lg::card('552220002'));

// the background fill, in steps
$GLOBALS['wpdb']->query('DELETE FROM ' . Mutqan_Store::table('docs') . " WHERE collection = 'client_ledger'");
delete_option('mutqan_ledger_filled'); Mutqan_Ledger::fill_step();
$t('the background step fills the ledger and marks it done', get_option('mutqan_ledger_filled') && $Lg::card('551110001'));

// pins by category
$pin = $Lg::pin_save(['lat' => 21.41, 'lng' => 39.81, 'kind' => 'shop', 'name' => 'محل قطع غيار الحج', 'phone' => '0550000000'], $sys);
$pins = $Lg::pins();
$t('a pin dropped on the map is kept with its category and emoji', count($pins) === 1 && $pins[0]['kind'] === 'shop' && $pins[0]['emoji'] === '🏪');
$bad = null; try { $Lg::pin_save(['kind' => 'shop'], $sys); } catch (Mutqan_Error $e) { $bad = $e; }
$t('a pin needs a place on the map', $bad !== null);

// the trip: start, covered, left
$trips = [];
$now = time();
$trail = [[21.40, 39.80, $now - 600], [21.41, 39.80, $now - 300], [21.42, 39.80, $now - 10]];
$job = ['id' => 'J1', 'status' => 'en_route', 'lat' => 21.45, 'lng' => 39.80];
$row = ['live' => true, 'lat' => 21.42, 'lng' => 39.80];
$tr = Mutqan_Route::trip('T1', $job, $row, $trail, Mutqan_Route::km(21.42, 39.80, 21.45, 39.80), 30, $trips);
$t('the trip starts where the technician was when first seen heading there', isset($trips['J1']) && abs($tr['startLat'] - 21.42) < 1e-6, $tr);
$trips['J1']['at'] = $now - 700; $trips['J1']['lat'] = 21.40;
$tr = Mutqan_Route::trip('T1', $job, $row, $trail, Mutqan_Route::km(21.42, 39.80, 21.45, 39.80), 30, $trips);
$t('…covered = road driven since the start, left = to the customer, total = both', abs($tr['done'] - 2.2) < 0.15 && abs($tr['left'] - 3.3) < 0.15 && abs($tr['total'] - 5.6) < 0.2 && $tr['pct'] > 30 && $tr['pct'] < 50 && $tr['min'] >= 11 && $tr['etaMin'] >= 6, $tr);
$tr2 = Mutqan_Route::trip('T2', $job, $row, $trail, 3.3, 30, $trips);
$t('…another technician on the same job starts a new trip', $trips['J1']['tech'] === 'T2' && $tr2['done'] < 0.1);
$tr3 = Mutqan_Route::trip('T2', ['status' => 'arrived'] + $job, $row, $trail, 0.05, 30, $trips);
$t('…arrived: nothing left, 100%', $tr3['arrived'] && $tr3['left'] == 0 && $tr3['etaMin'] === 0);

echo ($ok ? "ALL PASS" : "SOME FAILED") . " ($n)\n";
exit($ok ? 0 : 1);
