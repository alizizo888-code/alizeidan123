<?php
// «وضع الصيانة» (owner, 2026-11-24): the owner switches it; the customer app reads it; nobody else can.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'maint: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$own = ['role' => 'owner', 'id' => 'u-own', 'name' => 'علي'];
$r = Mutqan_Maintenance::handle(['op' => 'get'], $own);
$t('off by default, with a bypass link', $r['on'] === false && strpos($r['bypass'], 'mq_open=') !== false, $r);
$r = Mutqan_Maintenance::handle(['op' => 'set', 'on' => true, 'title' => 'صيانة', 'msg' => 'راجعين'], $own);
$t('the owner switches it on, with who', $r['on'] === true && $r['by'] === 'علي' && get_option('mutqan_maintenance')['on'] === true, $r);
$err = ''; try { Mutqan_Maintenance::handle(['op' => 'set', 'on' => false], ['role' => 'operations']); } catch (Mutqan_Error $e) { $err = $e->slug; }
$t('operations cannot', $err === 'forbidden');
$r = Mutqan_Maintenance::handle(['op' => 'set', 'on' => false], $own);
$t('…and off again', $r['on'] === false);
echo $ok ? "OK maint\n" : "FAILED maint\n";
exit($ok ? 0 : 1);
