<?php
// Where the work is, and where it has been.
//
// A live map and any travel estimate need two things that did not exist: a trail entry that
// records where something happened, and a technician's current position. The second is a person's
// whereabouts, so most of what is checked here is the narrowness of it — kept only during a job,
// never accumulated, and gone when the work ends.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'track: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$K = 'Mutqan_Track';
$sys = ['kind' => 'user', 'role' => 'owner', 'id' => 'system'];

Mutqan_Store::insert('tech_profiles', ['displayName' => 'وليد', 'phone' => '0551234567'], 'u-walid', [], $sys);
$order = Mutqan_Store::insert('orders', ['number' => '4001', 'status' => 'new', 'address' => 'حي النزهة'], null, [], $sys);

/* ---------------------------------------------------------------- a position is for a working shift */
$r = $K::ping('u-walid', 21.42, 39.82);
$t('a technician with no job open does not have their position kept', ($r['reason'] ?? '') === 'no_open_job', $r);
$t('and nothing was written', $K::where('u-walid') === null);

Mutqan_Store::update('orders', $order['id'], ['technicianId' => 'u-walid', 'status' => 'assigned'], $sys);
$r = $K::ping('u-walid', 21.42, 39.82);
$t('once the job is theirs, the position is kept', ($r['ok'] ?? false) === true, $r);
$w = $K::where('u-walid');
$t('and can be read back', $w && abs($w['lat'] - 21.42) < 0.0001 && abs($w['lng'] - 39.82) < 0.0001, $w);

// A short real move (~150 m): an 11 km leap in no time is now dropped as a GPS jump (see Mutqan_Track::plausible).
$K::ping('u-walid', 21.421, 39.821);
$w2 = $K::where('u-walid');
$t('a newer position replaces the old one — there is no trail of a person',
   abs($w2["lat"] - 21.421) < 0.0001, $w2);
$doc = Mutqan_Store::get_raw('tech_profiles', 'u-walid');
$t('and nothing accumulates on the profile', !isset($doc['positions']) && !isset($doc['history']), array_keys($doc));

$t('rubbish coordinates are refused', ($K::ping('u-walid', 999, 0)['reason'] ?? '') === 'bad_position');
$t('and so is the middle of the ocean at 0,0', ($K::ping('u-walid', 0, 0)['reason'] ?? '') === 'bad_position');
$t('a technician nobody knows is refused', ($K::ping('u-nobody', 21.4, 39.8)['reason'] ?? '') === 'no_open_job');

/* ---------------------------------------------------------------- a position goes stale by itself */
Mutqan_Store::update('tech_profiles', 'u-walid', ['atAt' => time() - (Mutqan_Track::STALE + 60)], $sys);
$t('a phone left behind stops standing for where anyone is', $K::where('u-walid') === null);
$K::ping('u-walid', 21.42, 39.82);
$t('reporting again makes it current', $K::where('u-walid') !== null);

/* ---------------------------------------------------------------- the trail writes itself */
$before = count(Mutqan_Store::get_raw('orders', $order['id'])['route'] ?? []);
Mutqan_Store::update('orders', $order['id'], ['status' => 'arrived'], $sys);
$route = Mutqan_Store::get_raw('orders', $order['id'])['route'] ?? [];
$last = end($route);
$t('a status change records itself without anyone remembering to', count($route) > $before, count($route));
$t('as the status in words', mb_strpos((string) ($last['text'] ?? ''), 'وصل') !== false || ($last['kind'] ?? '') === 'status', $last);
$t('with where the technician was when it happened',
   isset($last['lat']) && abs($last['lat'] - 21.42) < 0.0001, $last);

$n = count(Mutqan_Store::get_raw('orders', $order['id'])['route'] ?? []);
Mutqan_Store::update('orders', $order['id'], ['note' => 'مش تغيير حالة'], $sys);
$t('a change that is not a status change adds nothing to the trail',
   count(Mutqan_Store::get_raw('orders', $order['id'])['route'] ?? []) === $n);

/* ---------------------------------------------------------------- the shift ends */
Mutqan_Store::update('orders', $order['id'], ['status' => 'completed'], $sys);
$t('finishing the last job drops the position — nothing is held past the work',
   $K::where('u-walid') === null, Mutqan_Store::get_raw('tech_profiles', 'u-walid'));
$done = Mutqan_Store::get_raw('orders', $order['id'])['route'] ?? [];
$t('but the job keeps its own record of where it was done',
   count(array_filter($done, function ($s) { return isset($s['lat']); })) >= 1, $done);

/* ---------------------------------------------------------------- two jobs, one still open */
$a = Mutqan_Store::insert('orders', ['number' => '4002', 'status' => 'assigned', 'technicianId' => 'u-walid'], null, [], $sys);
$b = Mutqan_Store::insert('orders', ['number' => '4003', 'status' => 'assigned', 'technicianId' => 'u-walid'], null, [], $sys);
$K::ping('u-walid', 21.44, 39.84);
Mutqan_Store::update('orders', $a['id'], ['status' => 'completed'], $sys);
$t('finishing one of two jobs keeps the position, because the shift is not over', $K::where('u-walid') !== null);
Mutqan_Store::update('orders', $b['id'], ['status' => 'completed'], $sys);
$t('finishing the second drops it', $K::where('u-walid') === null);

exit($ok ? 0 : 1);
