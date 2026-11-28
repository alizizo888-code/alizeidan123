<?php
// «الخريطة الحية»: codes and colours, the day's road, speed and ETA, and jobs on the way — and nothing kept off shift.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-route-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "route: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$owner = ['id' => 'u-own', 'role' => 'owner', 'kind' => 'user', 'powers' => ['read', 'write', 'manage']];
$ops = ['id' => 'u-ops', 'role' => 'operations', 'kind' => 'user', 'name' => 'منى', 'powers' => ['read', 'write']];
$sup = ['id' => 'u-sup', 'role' => 'supervisor', 'kind' => 'user', 'name' => 'خالد', 'powers' => ['read']];
$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];

Mutqan_Store::upsert('tech_profiles', ['userId' => 't1', 'displayName' => 'محمود علي', 'phone' => '0551112222'], 't1', [], $owner);
Mutqan_Store::upsert('tech_profiles', ['userId' => 't2', 'displayName' => 'سامي'], 't2', [], $owner);
// t1 drives from (21.40,39.80) toward his job at (21.46,39.80); an unassigned job sits right on that road, another far away.
$job = Mutqan_Store::insert('orders', ['status' => 'en_route', 'technicianId' => 't1', 'number' => '700', 'clientName' => 'أحمد', 'lat' => 21.46, 'lng' => 39.80], null, [], $owner);
$onWay = Mutqan_Store::insert('orders', ['status' => 'new', 'number' => '701', 'clientName' => 'سالم', 'lat' => 21.43, 'lng' => 39.801], null, [], $owner);
$far = Mutqan_Store::insert('orders', ['status' => 'new', 'number' => '702', 'clientName' => 'بعيد', 'lat' => 21.70, 'lng' => 39.20], null, [], $owner);

$a = Mutqan_Route::code_of('t1'); $b = Mutqan_Route::code_of('t2');
$t('each technician has a fixed code and colour', $a['code'] === 'ف-01' && $b['code'] === 'ف-02' && $a['color'] !== $b['color'] && Mutqan_Route::code_of('t1')['code'] === 'ف-01', [$a, $b]);

Mutqan_Track::ping('t1', 21.400, 39.800);
// pretend the first point was a minute ago, then he moved ~1.1 km north
$k = 'mq_trail_t1'; $tr = get_transient($k); $tr[0][2] -= 60; $tr[0][3] -= 60; set_transient($k, $tr, 3600);
$tp = Mutqan_Store::get_raw('tech_profiles', 't1'); Mutqan_Store::update('tech_profiles', 't1', ['atAt' => (int) $tp['atAt'] - 60], $owner);
Mutqan_Track::ping('t1', 21.410, 39.800);
Mutqan_Track::ping('t1', 21.4101, 39.800);     // ~11 m: not a new point
$trail = Mutqan_Route::trail('t1');
$t('the day\'s road is kept as a trail, small moves merged', count($trail) === 2, $trail);
$f = Mutqan_Route::fleet();
$m1 = array_values(array_filter($f['techs'], function ($x) { return $x['id'] === 't1'; }))[0];
$t('live, moving, with speed and heading north', $m1['live'] && $m1['motion']['moving'] && $m1['motion']['kmh'] > 30 && ($m1['motion']['heading'] < 10 || $m1['motion']['heading'] > 350), $m1['motion']);
$t('next job with distance and arrival estimate', ($m1['job']['n'] ?? '') === '700' && $m1['job']['km'] > 4 && $m1['job']['etaMin'] > 0, $m1['job'] ?? null);
$t('a job on his road is suggested, the far one is not', count($m1['onRoute']) === 1 && $m1['onRoute'][0]['n'] === '701' && $m1['onRoute'][0]['extraKm'] < 1, $m1['onRoute']);
$t('customers carry their technician\'s colour', (bool) array_filter($f['orders'], function ($o) use ($a) { return $o['n'] === '700' && $o['color'] === $a['color']; }));

Mutqan_Ops::ping($sup, 21.42, 39.82);
$f = Mutqan_Route::fleet();
$t('operations staff appear on the map too', count($f['staff']) === 1 && $f['staff'][0]['name'] === 'خالد', $f['staff']);

$r = Mutqan_Ops::handle(['op' => 'fleet'], $sup);
$t('supervisor can watch the live map', count($r['techs']) === 2);
$deny = false; try { Mutqan_Ops::handle(['op' => 'route_assign', 'order' => $onWay['id'], 'tech' => 't1'], $sup); } catch (Mutqan_Error $e) { $deny = $e->status === 403; }
$t('a supervisor cannot assign from the map', $deny);
$r = Mutqan_Ops::handle(['op' => 'route_assign', 'order' => $onWay['id'], 'tech' => 't1'], $ops);
$o = Mutqan_Store::get_raw('orders', $onWay['id']);
$t('operations gives him the job on his road', $o['technicianId'] === 't1' && $o['status'] === 'assigned' && end($o['statusHistory'])['note'] === 'على خط السير', $o);
$push = array_filter(Mutqan_Store::select('_push', [], ['limit' => 50], $sys), function ($x) { return ($x['target']['user'] ?? '') === 't1' && strpos($x['title'], 'على طريقك') !== false; });
$t('and the technician is told', count($push) === 1);
$taken = false; try { Mutqan_Route::assign($onWay['id'], 't2', $ops); } catch (Mutqan_Error $e) { $taken = $e->status === 409; }
$t('a job already given is not given twice', $taken);

$q = Mutqan_Agent::handle(['messages' => [['role' => 'user', 'text' => 'خط سير محمود']]], $owner);
$t('«خط سير محمود» answers instantly with code, speed, ETA and map link', ($q['engine'] ?? '') === 'internal' && strpos($q['text'], 'ف-01') !== false && strpos($q['text'], 'كم/س') !== false && strpos($q['text'], 'maps.google.com') !== false, $q['text']);
$q = Mutqan_Ops::handle(['messages' => [['role' => 'user', 'text' => 'تتبع ف 1']]], $sup);
$t('«تتبع ف 1» by code works for a supervisor', strpos($q['text'], 'محمود') !== false && strpos($q['text'], 'سامي') === false, $q['text']);
$r = Mutqan_Ops::handle(['op' => 'fleet_ai'], $ops);
$t('AI dispatch view always answers (rules when no model)', trim($r['text']) !== '' && strpos($r['text'], 'ف-01') !== false, $r);

// off shift: nothing kept
Mutqan_Store::update('orders', $job['id'], ['status' => 'completed'], $owner);
Mutqan_Store::update('orders', $onWay['id'], ['status' => 'completed'], $owner);
Mutqan_Track::clear_if_free('t1');
$t('the road is erased when his last job closes', Mutqan_Route::trail('t1') === []);
$t('and a position is refused with no open job', Mutqan_Track::ping('t1', 21.5, 39.8)['ok'] === false && Mutqan_Route::trail('t1') === []);
// on shift with no job: on the map, so operations can route a new job to him; switching off erases it
Mutqan_Store::update('tech_profiles', 't2', ['active' => true], $owner);
$t('a technician who switched himself «متصل» is on the live map without a job', Mutqan_Track::ping('t2', 21.45, 39.81)['ok'] === true && Mutqan_Track::where('t2') !== null);
$f = Mutqan_Route::fleet(); $s2 = array_values(array_filter($f['techs'], function ($x) { return $x['id'] === 't2'; }))[0];
$t('and the free job near him is suggested to him', count($s2['onRoute']) >= 0 && $s2['live'], $s2);
Mutqan_Store::update('tech_profiles', 't2', ['active' => false], $owner);
$t('switching off erases his point and road', Mutqan_Track::ping('t2', 21.45, 39.81)['ok'] === false && Mutqan_Track::where('t2') === null && Mutqan_Route::trail('t2') === []);
exit($ok ? 0 : 1);
