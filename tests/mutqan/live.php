<?php
// The whole operation in one small answer.
//
// A live view was being assembled by pulling whole collections every few seconds from every open
// screen, against the one server all twenty-two faces share. These checks are about the two things
// that make polling affordable: the answer is small, and an unchanged answer is almost nothing.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'live: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$L = 'Mutqan_Live';
$sys = ['kind' => 'user', 'role' => 'owner', 'id' => 'system'];

Mutqan_Store::insert('tech_profiles', ['displayName' => 'وليد', 'homeLat' => 21.3, 'homeLng' => 39.2], 'u-walid', [], $sys);
Mutqan_Store::insert('shops', ['name' => 'قطع غيار النزهة', 'lat' => 21.44, 'lng' => 39.83], null, [], $sys);
$open = Mutqan_Store::insert('orders', ['number' => '5001', 'status' => 'assigned', 'technicianId' => 'u-walid',
    'clientName' => 'أبو محمد', 'serviceLabel' => 'صيانة تكييف', 'lat' => 21.42, 'lng' => 39.82,
    'note' => 'ملاحظة طويلة جدا لا يحتاجها أي دبوس على الخريطة'], null, [], $sys);
Mutqan_Store::insert('orders', ['number' => '5002', 'status' => 'new', 'clientName' => 'بدون موقع', 'address' => 'حي ما'], null, [], $sys);
Mutqan_Store::insert('orders', ['number' => '5003', 'status' => 'completed', 'clientName' => 'خلص'], null, [], $sys);

$s = $L::snapshot();
$t('what is in motion is in the answer', count($s['orders']) === 2, array_column($s['orders'], 'n'));
$t('what is finished is not', !in_array('5003', array_column($s['orders'], 'n'), true));
$row = null; foreach ($s['orders'] as $o) { if ($o['n'] === '5001') { $row = $o; } }
$t('an order on the map carries its point', isset($row['lat']) && abs($row['lat'] - 21.42) < 0.0001, $row);
$t('and only what a pin or a row shows', !isset($row['note']) && !isset($row['address']) && !isset($row['route']), array_keys($row));
$t('an order with no point still appears, so it can be worked on',
   in_array('5002', array_column($s['orders'], 'n'), true));
$t('and is counted as needing one', ($s['count']['unplaced'] ?? 0) === 1, $s['count']);
$t('the counts separate waiting from running', ($s['count']['new'] ?? 0) === 1 && ($s['count']['open'] ?? 0) === 1, $s['count']);

$tech = $s['techs'][0] ?? null;
$t('a technician carries how much is on them', ($tech['busy'] ?? null) === 1, $tech);
$t('and their base when they are not reporting a position', ($tech['home'] ?? false) === true && abs($tech['lat'] - 21.3) < 0.0001, $tech);
Mutqan_Track::ping('u-walid', 21.50, 39.90);
$tech2 = $L::snapshot()['techs'][0];
$t('a live position wins over the base', empty($tech2['home']) && abs($tech2['lat'] - 21.50) < 0.0001, $tech2);
$t('and says how old it is', isset($tech2['ago']), $tech2);
$t('shops with a point are there', count($s['shops']) === 1 && $s['shops'][0]['name'] === 'قطع غيار النزهة', $s['shops']);

/* ---------------------------------------------------------------- asking again is nearly free */
$v = $L::snapshot()['v'];
$same = $L::snapshot($v);
$t('nothing changed: the answer is one word', ($same['fresh'] ?? false) === true && !isset($same['orders']), $same);
Mutqan_Store::update('orders', $open['id'], ['status' => 'arrived'], $sys);
$after = $L::snapshot($v);
$t('something changed: the rows come back', ($after['fresh'] ?? true) === false && isset($after['orders']));
$t('and the version moved with it', $after['v'] !== $v);
$t('a version from nowhere is simply not fresh', ($L::snapshot('ffffffffffffffff')['fresh'] ?? true) === false);

/* ---------------------------------------------------------------- it is small */
$full = strlen(json_encode($L::snapshot(), JSON_UNESCAPED_UNICODE));
$raw = strlen(json_encode(Mutqan_Store::select('orders', [], ['limit' => 300, 'order' => 'desc'], $sys), JSON_UNESCAPED_UNICODE));
$t('the answer is smaller than the rows it replaces', $full < $raw, ['snapshot' => $full, 'raw orders' => $raw]);

exit($ok ? 0 : 1);
