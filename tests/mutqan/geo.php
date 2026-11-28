<?php
// Every order on the map, whether anyone is looking at a map or not.
//
// The field map, nearest-technician assignment and any travel estimate all read lat/lng and skip
// whatever has none — so an order whose customer typed an address instead of sharing a pin is
// invisible to all three, and most orders are like that. These checks are about the two things
// that make filling them in safely possible: never making a customer wait on it, and never
// hammering a free service we are a guest of.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('MQ_TESTING', 1);
require __DIR__ . '/wpshim.php';
$GLOBALS['__asked'] = [];
$GLOBALS['__answer'] = null;
Mutqan_Store::maybe_install();
$GLOBALS['__get_hook'] = function ($url) {
    if (strpos($url, 'nominatim') === false) { return null; }
    $GLOBALS['__asked'][] = $url;
    $r = $GLOBALS['__answer'];
    if ($r === 'down') { return new WP_Error('http', 'boom'); }
    if ($r === null) { return ['code' => 200, 'body' => '[]', 'headers' => []]; }
    return ['code' => 200, 'body' => json_encode($r), 'headers' => []];
};
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'geo: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$G = 'Mutqan_Geo';
$sys = ['kind' => 'user', 'role' => 'owner', 'id' => 'system'];
$found = [['lat' => '21.389082', 'lon' => '39.857910', 'display_name' => 'حي النزهة']];

/* ---------------------------------------------------------------- what we ask about */
$t('the address, the district and the city together',
   $G::query(['address' => 'شارع الأمير ماجد', 'district' => 'النزهة', 'city' => 'جدة']) === 'شارع الأمير ماجد، النزهة، جدة',
   $G::query(['address' => 'شارع الأمير ماجد', 'district' => 'النزهة', 'city' => 'جدة']));
$t('a flat number tells a search engine nothing and is dropped',
   strpos($G::query(['address' => 'شارع الأمير ماجد شقة 12', 'city' => 'جدة']), 'شقة') === false,
   $G::query(['address' => 'شارع الأمير ماجد شقة 12', 'city' => 'جدة']));
$t('the same word twice is asked once', $G::query(['address' => 'جدة', 'city' => 'جدة']) === 'جدة');
$t('nothing to go on means no question', $G::query(['clientName' => 'أبو محمد']) === '');

/* ---------------------------------------------------------------- the lookup */
$GLOBALS['__asked'] = []; $GLOBALS['__answer'] = $found;
$hit = $G::forward('حي النزهة، جدة');
$t('an address becomes a point', $hit && abs($hit['lat'] - 21.389082) < 0.0001 && abs($hit['lng'] - 39.85791) < 0.0001, $hit);
$t('and the service is told who is asking and where to look',
   strpos($GLOBALS['__asked'][0], 'countrycodes=sa') !== false && strpos($GLOBALS['__asked'][0], 'limit=1') !== false, $GLOBALS['__asked'][0] ?? null);
$GLOBALS['__asked'] = [];
$again = $G::forward('حي النزهة، جدة');
$t('asked again, it is answered from memory — the same streets come back all day',
   $again['lat'] === $hit['lat'] && count($GLOBALS['__asked']) === 0, $GLOBALS['__asked']);

$GLOBALS['__asked'] = []; $GLOBALS['__answer'] = null;
$t('a place that does not exist answers nothing', $G::forward('مكان مش موجود خالص ١٢٣') === null);
$GLOBALS['__asked'] = [];
$G::forward('مكان مش موجود خالص ١٢٣');
$t('and is not asked about twice', count($GLOBALS['__asked']) === 0, $GLOBALS['__asked']);

$GLOBALS['__asked'] = []; $GLOBALS['__answer'] = 'down';
$t('a service that is down answers nothing', $G::forward('حي الصفا، جدة') === null);
$GLOBALS['__asked'] = []; $GLOBALS['__answer'] = $found;
$t('but being down is not an answer: it is asked again next time', $G::forward('حي الصفا، جدة') !== null && count($GLOBALS['__asked']) === 1);
$t('something too short to mean anything is never asked', $G::forward('جد') === null);

/* ---------------------------------------------------------------- the background pass */
$GLOBALS['__answer'] = $found;
$withPin = Mutqan_Store::insert('orders', ['number' => '3001', 'address' => 'حي الروضة', 'city' => 'جدة', 'lat' => 21.5, 'lng' => 39.1, 'status' => 'new'], null, [], $sys);
$noPin   = Mutqan_Store::insert('orders', ['number' => '3002', 'address' => 'شارع فلسطين', 'city' => 'جدة', 'status' => 'new'], null, [], $sys);
$noWhere = Mutqan_Store::insert('orders', ['number' => '3003', 'clientName' => 'بدون عنوان', 'status' => 'new'], null, [], $sys);
$pend = array_column($G::pending(50), 'number');
$t('an order already on the map is left alone', !in_array('3001', $pend, true), $pend);
$t('an order with an address and no point is waiting', in_array('3002', $pend, true), $pend);
$t('an order with nowhere to look up is not waiting forever', !in_array('3003', $pend, true), $pend);

$done = $G::fill_batch();
$after = Mutqan_Store::get_raw('orders', $noPin['id']);
$t('the pass puts it on the map', $done === 1 && isset($after['lat']) && abs($after['lat'] - 21.389082) < 0.0001, $after);
$t('and records that the point came from the address, not from the customer', ($after['geoFrom'] ?? '') === 'address');
$t('nothing is left waiting', count($G::pending(50)) === 0);

/* ---------------------------------------------------------------- an address that never resolves */
$GLOBALS['__answer'] = null;
$bad = Mutqan_Store::insert('orders', ['number' => '3004', 'address' => 'عنوان غلط تماما', 'city' => 'جدة', 'status' => 'new'], null, [], $sys);
for ($i = 0; $i < 5; $i++) { $G::fill_batch(); }
$row = Mutqan_Store::get_raw('orders', $bad['id']);
$t('an address that cannot be found is tried a few times, not forever',
   (int) ($row['geoTries'] ?? 0) === Mutqan_Geo::MAX_TRIES, $row['geoTries'] ?? null);
$t('and then stops taking the budget that new orders need', count($G::pending(50)) === 0);

/* ---------------------------------------------------------------- never in a customer's way */
$src = file_get_contents(dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/mutqan/class-mutqan-geo.php');
$t('the lookup is only ever reached from the scheduled pass',
   substr_count($src, 'self::forward(') === 1 && strpos($src, 'fill_batch') !== false);
$t('and that pass is on a schedule, not on a request', strpos($src, "wp_schedule_event") !== false);

exit($ok ? 0 : 1);
