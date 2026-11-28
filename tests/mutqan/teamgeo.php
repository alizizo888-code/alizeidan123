<?php
// «بث موقعي»: team members share a live point; the owner sees all; owner/operations hide technicians (all or one),
// decide what technicians see, and only the owner decides whether operations see him.
require __DIR__ . '/wpshim.php';
$ok = 0; $bad = 0;
$t = function ($n, $c, $x = null) use (&$ok, &$bad) { if ($c) { $ok++; echo "PASS teamgeo: $n\n"; } else { $bad++; echo "FAIL teamgeo: $n  -> " . json_encode($x, JSON_UNESCAPED_UNICODE) . "\n"; } };
$P = function ($id, $role, $name) { return ['kind' => 'user', 'id' => $id, 'role' => $role, 'name' => $name]; };
$own = $P('o1', 'owner', 'علي'); $ops = $P('p1', 'operations', 'مشرف'); $t1 = $P('t1', 'technician', 'فني 1'); $t2 = $P('t2', 'technician', 'فني 2');
$cli = ['kind' => 'user', 'id' => 'c1', 'role' => 'client'];
foreach ([[$own, 21.42], [$ops, 21.43], [$t1, 21.44], [$t2, 21.45]] as [$who, $lat]) { Mutqan_TeamGeo::handle(['op' => 'ping', 'lat' => $lat, 'lng' => 39.82], $who); }
$ids = function ($p) { $r = Mutqan_TeamGeo::handle(['op' => 'list'], $p); $x = array_column($r['people'], 'id'); sort($x); return implode(',', $x); };
$t('the owner sees everyone sharing', $ids($own) === 'o1,p1,t1,t2', $ids($own));
$t('operations see everyone', $ids($ops) === 'o1,p1,t1,t2', $ids($ops));
$t('a technician sees the management and himself only (default)', $ids($t1) === 'o1,p1,t1', $ids($t1));
$denied = false; try { Mutqan_TeamGeo::handle(['op' => 'ping', 'lat' => 21, 'lng' => 39], $cli); } catch (Mutqan_Error $e) { $denied = $e->status === 403; }
$t('a customer cannot share or see', $denied);
Mutqan_TeamGeo::handle(['op' => 'cfg_set', 'showTechs' => false], $ops);
$t('operations hide all technicians', $ids($ops) === 'o1,p1', $ids($ops));
$t('the owner still sees them', $ids($own) === 'o1,p1,t1,t2');
$t('a hidden technician still sees his own dot', strpos($ids($t1), 't1') !== false);
Mutqan_TeamGeo::handle(['op' => 'cfg_set', 'showTechs' => true, 'hide' => ['t2' => true]], $ops);
$t('one technician hidden on his own', $ids($ops) === 'o1,p1,t1', $ids($ops));
Mutqan_TeamGeo::handle(['op' => 'cfg_set', 'hide' => ['o1' => true], 'opsSeeOwner' => false], $ops);
$t('operations cannot hide or reveal the owner', $ids($ops) === 'o1,p1,t1', $ids($ops));
Mutqan_TeamGeo::handle(['op' => 'cfg_set', 'opsSeeOwner' => false], $own);
$t('the owner can hide himself from operations', $ids($ops) === 'p1,t1', $ids($ops));
Mutqan_TeamGeo::handle(['op' => 'cfg_set', 'techsSee' => 'all'], $own);
$t('technicians may be allowed to see each other', $ids($t1) === 'p1,t1', $ids($t1));
$nc = false; try { Mutqan_TeamGeo::handle(['op' => 'cfg_set', 'showTechs' => false], $t1); } catch (Mutqan_Error $e) { $nc = $e->status === 403; }
$t('a technician cannot change visibility', $nc);
Mutqan_TeamGeo::handle(['op' => 'stop'], $ops);
$t('stop erases the point at once', strpos($ids($own), 'p1') === false, $ids($own));
echo $bad ? "FAILED $bad\n" : "ALL PASSED\n"; exit($bad ? 1 : 0);
