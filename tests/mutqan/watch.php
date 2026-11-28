<?php
// The follow-up watch over open orders + coverage areas, working hours, standby technician, auto-transfer
// (owner, 2026-11-23 — ideas 1–3 and 6–9).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'watch: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$sys = ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']];
$sh = ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'];
$W = 'Mutqan_Watch'; $D = 'Mutqan_Dispatch';
$ago = function ($min) { return gmdate('c', time() - $min * 60); };
$cfg = $W::cfg([]);

/* ------------------------------------------------------------------ the rules (pure) */
$f = $W::flags_of(['status' => 'new', 'createdAt' => $ago(12)], null, $cfg);
$t('1 · not confirmed after 10 minutes is flagged', isset($f['unconfirmed']), $f);
$f = $W::flags_of(['status' => 'new', 'createdAt' => $ago(4)], null, $cfg);
$t('… but not after 4 minutes', !isset($f['unconfirmed']), $f);
$f = $W::flags_of(['status' => 'new', 'createdAt' => $ago(30), 'customerConfirmed' => true], null, $cfg);
$t('… nor once he confirmed', !isset($f['unconfirmed']), $f);
$f = $W::flags_of(['status' => 'completed', 'createdAt' => $ago(30)], null, $cfg);
$t('a finished order is never flagged', $f === [], $f);
$f = $W::flags_of(['status' => 'assigned', 'technicianId' => 'a', 'technicianName' => 'أحمد', 'assignedAt' => $ago(7), 'createdAt' => $ago(8), 'customerConfirmed' => true], null, $cfg);
$t('3 · assigned 7 minutes ago and not accepted is flagged', isset($f['not_accepted']) && strpos($f['not_accepted'], 'أحمد') !== false, $f);
$f = $W::flags_of(['status' => 'assigned', 'technicianId' => 'site', 'dispatch' => 'site', 'assignedAt' => $ago(30), 'createdAt' => $ago(30), 'customerConfirmed' => true], null, $cfg);
$t('… the site technician is not chased', !isset($f['not_accepted']), $f);
$f = $W::flags_of(['status' => 'en_route', 'technicianId' => 'a', 'statusHistory' => [['status' => 'en_route', 'at' => $ago(70)]], 'createdAt' => $ago(90), 'customerConfirmed' => true], null, $cfg);
$t('3 · an hour on the road without a move is «متعطل»', isset($f['stalled']), $f);
$f = $W::flags_of(['status' => 'in_progress', 'technicianId' => 'a', 'statusHistory' => [['status' => 'in_progress', 'at' => $ago(70)]], 'createdAt' => $ago(90), 'customerConfirmed' => true], null, $cfg);
$t('… but an hour of work on site is normal', !isset($f['stalled']), $f);
$f = $W::flags_of(['status' => 'accepted', 'technicianId' => 'a', 'appointmentAt' => $ago(40), 'statusHistory' => [['status' => 'accepted', 'at' => $ago(10)]], 'createdAt' => $ago(120), 'customerConfirmed' => true], null, $cfg);
$t('3 · appointment 40 minutes past with nobody there is «متأخر»', isset($f['late']), $f);
$f = $W::flags_of(['status' => 'arrived', 'technicianId' => 'a', 'appointmentAt' => $ago(40), 'statusHistory' => [['status' => 'arrived', 'at' => $ago(10)]], 'createdAt' => $ago(120), 'customerConfirmed' => true], null, $cfg);
$t('… not once he arrived', !isset($f['late']), $f);
$c2 = $W::cfg(['unconfirmedMinutes' => 20, 'acceptMinutes' => 8, 'autoTransfer' => false, 'mode' => 'auto']);
$t('the minutes come from the owner\'s settings', $c2['unconfirmed'] === 20 && $c2['accept'] === 8 && $c2['transfer'] === false && $c2['auto'] === true, $c2);

/* ------------------------------------------------------------------ working hours (Makkah time) */
$at = function ($hhmm, $day = 'Mon') { $d = new DateTime("next $day $hhmm", new DateTimeZone('Asia/Riyadh')); return $d->getTimestamp(); };
$sh8 = ['shift' => ['from' => '08:00', 'to' => '22:00', 'off' => [5]]];
$t('7 · on shift at 10:00', $D::on_shift($sh8, $at('10:00')));
$t('7 · off shift at 23:00', !$D::on_shift($sh8, $at('23:00')));
$t('7 · off on his day off (Friday)', !$D::on_shift($sh8, $at('12:00', 'Fri')));
$night = ['shift' => ['from' => '18:00', 'to' => '02:00']];
$t('7 · a night shift covers 01:00', $D::on_shift($night, $at('01:00')) && !$D::on_shift($night, $at('10:00')));
$t('7 · no shift written = any time', $D::on_shift([], $at('04:00')));

/* ------------------------------------------------------------------ coverage areas */
$z = ['zones' => ['مكة', 'العزيزية']];
$t('6 · «مكة المكرمة — حي العوالي» is inside «مكة»', $D::in_zone($z, ['address' => 'مكة المكرمة — حي العوالي']));
$t('6 · «جدة، حي الصفا» is outside', !$D::in_zone($z, ['address' => 'جدة، حي الصفا']));
$t('6 · «حي عزيزيه» matches «العزيزية» (spelling folded)', $D::in_zone($z, ['address' => 'حي عزيزيه']));
$t('6 · an order with no address yet matches', $D::in_zone($z, ['address' => '']));
$t('6 · a technician with no zones takes everything', $D::in_zone([], ['address' => 'جدة']));

/* ------------------------------------------------------------------ dispatch with zones, hours, the standby */
$now = gmdate('c');
$prof = function ($id, $extra) use ($sys, $sh) { Mutqan_Store::upsert('tech_profiles', array_merge(['userId' => $id, 'displayName' => $id, 'active' => true, 'lastSeenAt' => gmdate('c'), 'specialties' => ['ac']], $extra), $id, $sh, $sys); };
$order = function ($extra = []) use ($sys, $sh) { return Mutqan_Store::insert('orders', array_merge(['status' => 'new', 'specialtyId' => 'ac', 'lat' => 21.5433, 'lng' => 39.1728, 'clientName' => 'عميل', 'createdAt' => gmdate('c')], $extra), null, $sh, $sys); };
Mutqan_Store::upsert('assignment_settings', ['mode' => 'auto', 'strategy' => 'smart', 'backupTechId' => 'standby', 'siteFallback' => false], 'default', $sh, $sys);
$prof('jeddah', ['lat' => 21.544, 'lng' => 39.173, 'zones' => ['جدة']]);
$prof('makkah', ['lat' => 21.60, 'lng' => 39.25, 'zones' => ['مكة']]);
$prof('standby', ['lat' => 21.70, 'lng' => 39.40, 'shift' => null]);
$o = $order(['address' => 'مكة المكرمة، العزيزية']);
$t('6 · a Makkah order goes to the Makkah technician even though the Jeddah one is closer', ($o['technicianId'] ?? '') === 'makkah', $o['technicianId'] ?? null);
$prof('makkah', ['lat' => 21.60, 'lng' => 39.25, 'zones' => ['مكة'], 'shift' => ['from' => '00:00', 'to' => '00:01']]);
$o = $order(['address' => 'مكة، الششة']);
$t('9 · Makkah technician off shift, Jeddah outside his area → the standby technician', ($o['technicianId'] ?? '') === 'standby', $o['technicianId'] ?? null);
$t('9 · … recorded as «backup»', (end($o['transferLog'])['by'] ?? '') === 'backup', $o['transferLog'] ?? null);

/* ------------------------------------------------------------------ the sweep: alert once, auto-transfer */
$prof('makkah', ['lat' => 21.60, 'lng' => 39.25, 'zones' => ['مكة'], 'shift' => null]);
$prof('makkah2', ['lat' => 21.61, 'lng' => 39.26, 'zones' => ['مكة']]);
$slow = $order(['address' => 'مكة', 'customerConfirmed' => true]);
$first = (string) $slow['technicianId'];
Mutqan_Store::update('orders', $slow['id'], ['assignedAt' => $ago(6), 'statusHistory' => [['status' => 'assigned', 'at' => $ago(6)]]], $sys);
$r = $W::sweep();
$after = Mutqan_Store::get_raw('orders', $slow['id']);
$t('8 · after 5 minutes without «قبول» the order moves to another technician by itself', $r['moved'] >= 1 && ($after['technicianId'] ?? '') !== '' && ($after['technicianId'] ?? '') !== $first, [$first, $after['technicianId'] ?? null, $r]);
$t('8 · and never back to the one who didn\'t take it', in_array($first, (array) ($after['skipTechIds'] ?? []), true), $after['skipTechIds'] ?? null);
$t('8 · the transfer is written in the order\'s history', count(array_filter((array) $after['transferLog'], function ($x) { return ($x['by'] ?? '') === 'transfer'; })) === 1);
$pushes = Mutqan_Store::select('_push', ['event' => 'order_transferred'], ['limit' => 10], $sys);
$t('8 · the technician who lost it is told', count($pushes) >= 1 && ($pushes[0]['target']['user'] ?? '') === $first, $pushes);

Mutqan_Store::upsert('assignment_settings', ['mode' => 'manual'], 'default', $sh, $sys);
$cold = $order(['address' => 'جدة']);
$later = time() + 15 * 60;   // a quarter of an hour on
$W::sweep($later);
$n1 = count(Mutqan_Store::select('_push', ['event' => 'order_watch_unconfirmed'], ['limit' => 50], $sys));
$W::sweep($later + 300);
$n2 = count(Mutqan_Store::select('_push', ['event' => 'order_watch_unconfirmed'], ['limit' => 50], $sys));
$t('1 · an unconfirmed order rings operations and the supervisor', $n1 >= 2, $n1);
$t('1 · … once, not on every pass', $n1 === $n2, [$n1, $n2]);
$t('the mark is kept on the order', (Mutqan_Store::get_raw('orders', $cold['id'])['watch']['unconfirmed'] ?? '') === '1');

/* ------------------------------------------------------------------ 14 · asked to rate again two hours later */
$t('14 · finished 3 hours ago, not rated → due', $W::rate_due(['status' => 'completed', 'clientPhone' => '0550000000', 'completedAt' => $ago(180)]));
$t('14 · finished an hour ago → not yet', !$W::rate_due(['status' => 'completed', 'clientPhone' => '0550000000', 'completedAt' => $ago(60)]));
$t('14 · already rated → never', !$W::rate_due(['status' => 'completed', 'clientPhone' => '0550000000', 'completedAt' => $ago(180), 'rating' => ['stars' => 5]]));
$t('14 · no phone → never', !$W::rate_due(['status' => 'completed', 'completedAt' => $ago(180)]));
$done = $order(['address' => 'جدة', 'clientPhone' => '0551112223']);
Mutqan_Store::update('orders', $done['id'], ['status' => 'completed', 'completedAt' => $ago(150), 'customerConfirmed' => true], $sys);
$W::sweep(); $W::sweep();
$t('14 · the sweep marks it once', in_array(Mutqan_Store::get_raw('orders', $done['id'])['watch']['rate'] ?? '', ['sent', 'skipped'], true), Mutqan_Store::get_raw('orders', $done['id'])['watch'] ?? null);

/* ------------------------------------------------------------------ the customer never sees the team's marks and notes */
$doc = Mutqan_Store::for_reader('orders', ['id' => 'x', 'internalNotes' => [['text' => 'العميل صعب']], 'watch' => ['late' => '1'], 'skipTechIds' => ['a'], 'clientName' => 'ع'], ['id' => 'anon-1', 'kind' => 'anon']);
$t('5 · internal notes, watch marks and skipped technicians are stripped for the customer', !isset($doc['internalNotes']) && !isset($doc['watch']) && !isset($doc['skipTechIds']) && ($doc['clientName'] ?? '') === 'ع', $doc);
$doc = Mutqan_Store::for_reader('orders', ['id' => 'x', 'internalNotes' => [['text' => 'n']]], ['id' => 'u-ops', 'role' => 'operations', 'kind' => 'user', 'powers' => ['read', 'write']]);
$t('5 · … and kept for operations', isset($doc['internalNotes']));
$row = ['owner_id' => 'anon-9', 'data' => '{}'];
$patch = Mutqan_Policy::sanitize_update('orders', [], ['internalNotes' => [['text' => 'x']], 'watch' => [], 'note' => 'ok'], ['id' => 'anon-9', 'kind' => 'anon'], $row);
$t('5 · a customer cannot write the team\'s notes or marks', !isset($patch['internalNotes']) && !isset($patch['watch']) && ($patch['note'] ?? '') === 'ok', $patch);

echo $ok ? "OK watch\n" : "FAILED watch\n";
exit($ok ? 0 : 1);
