<?php
// «جدول المهام» (owner, 2026-11-23): an order for a later day is filed, listed the evening before, dispatched on its day at 7.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'schedule: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$sys = ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']];
$sh = ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'];
$S = 'Mutqan_Schedule';
// Monday 2026-11-23 15:00 Makkah
$mon = strtotime('2026-11-23 12:00:00 UTC');
$t('«بكره العصر» on Monday → Tuesday', $S::day_of(['preferredTime' => 'بكره العصر'], $mon) === '2026-11-24');
$t('«الخميس» → Thursday', $S::day_of(['preferredTime' => 'الخميس بعد المغرب'], $mon) === '2026-11-26');
$t('«بعد 3 أيام» → Thursday', $S::day_of(['preferredTime' => 'بعد 3 أيام'], $mon) === '2026-11-26');
$t('«اليوم» is today, not later', !$S::is_later(['preferredTime' => 'اليوم العصر'], $mon));
$t('«بكره الصبح» said at 2 am is this morning', $S::day_of(['preferredTime' => 'بكره الصبح بدري'], strtotime('2026-11-23 23:00:00 UTC')) === '2026-11-24');
$o = Mutqan_Store::insert('orders', ['status' => 'new', 'clientPhone' => '0551110001', 'clientName' => 'مؤجل', 'preferredTime' => 'بعد بكره العصر'], null, $sh, $sys);
$raw = Mutqan_Store::get_raw('orders', $o['id']);
$t('a new order for a later day is filed, not given to a technician', !empty($raw['scheduled']['day']) && empty($raw['scheduled']['released']) && empty($raw['technicianId']), $raw['scheduled'] ?? null);
$t('…and the watch does not call it late', Mutqan_Watch::flags_of($raw + ['createdAt' => gmdate('c', time() - 3600)]) === []);
$day = $raw['scheduled']['day'];
$eve = strtotime($day . ' 00:00:00 UTC') - 86400 + 18 * 3600 + 600;   // 21:10 Makkah the evening before
$r = $S::tick($eve);
$t('the evening before: tomorrow\'s list once', $r['evening'] >= 1 && $r['released'] === 0, $r);
$r = $S::tick($eve + 300);
$t('…not twice', $r['evening'] === 0, $r);
$r = $S::tick(strtotime($day . ' 03:30:00 UTC'));   // 6:30 Makkah: not yet
$t('before 7 am on the day: still held', $r['released'] === 0, $r);
$r = $S::tick(strtotime($day . ' 04:05:00 UTC'));   // 7:05 Makkah
$raw = Mutqan_Store::get_raw('orders', $o['id']);
$t('7 am on the day: released for dispatch', $r['released'] >= 1 && !empty($raw['scheduled']['released']), $raw['scheduled'] ?? null);
$L = $S::handle(['op' => 'list'], ['role' => 'operations', 'id' => 'u1']);
$t('the page lists it with the log', count($L['log']) >= 3, $L);
$err = ''; try { $S::handle([], ['role' => 'technician']); } catch (Mutqan_Error $e) { $err = $e->getMessage(); }
$t('a technician does not open it', $err !== '');
/* ---------------------------------------------------------------- «سكور الفني» (owner, 2026-11-23) */
$D = 'Mutqan_Dispatch';
$good = ['userId' => 'g1']; $weak = ['userId' => 'w1'];
$mk = function ($tid, $stars, $traits = [], $by = 'technician', $extra = []) { return array_merge(['technicianId' => $tid, 'status' => 'completed', 'createdAt' => gmdate('c', time() - 7200), 'rating' => ['stars' => $stars, 'traits' => $traits], 'statusHistory' => [['status' => 'completed', 'by' => $by]]], $extra); };
$orders = [];
for ($i = 0; $i < 6; $i++) { $orders[] = $mk('g1', 5, ['مظهره وشكله', 'متقن', 'مؤدب ولبق', 'سريع']); }
for ($i = 0; $i < 6; $i++) { $orders[] = $mk('w1', 3, [], $i < 3 ? 'operations' : 'technician', $i === 0 ? ['warrantyClaim' => true] : []); }
$orders[] = ['technicianId' => 'w1', 'status' => 'in_progress', 'createdAt' => gmdate('c', time() - 3 * 86400)];
$qg = $D::quality($good, $orders); $qw = $D::quality($weak, $orders);
$t('score: praised 5-star technician well above one with returns, ops-closed and hanging jobs', $qg['score'] >= 80 && $qw['score'] <= 45 && $qw['opsClosed'] === 3 && $qw['stuck'] === 1 && $qw['returns'] === 1, [$qg, $qw]);
$six = array_map(function ($i) { return ['userId' => 'x' . $i, 'active' => true, 'lastSeenAt' => gmdate('c')]; }, range(1, 6));
$t('merit mode only with more than 5 active technicians', $D::merit_mode($six) === ($D::presence($six[0]) !== 'offline') && !$D::merit_mode(array_slice($six, 0, 2)));

echo $ok ? "OK schedule\n" : "FAILED schedule\n";
exit($ok ? 0 : 1);
