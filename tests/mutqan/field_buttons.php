<?php
// The technician's buttons on his order page (owner, 2026-11-24: «اطلب إصدار الفاتورة» did nothing — the order was read
// without its id, so nothing was written and operations heard nothing). Each button: what it writes, who hears it.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'field: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$sys = ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']];
$sh = ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'];
$tech = ['kind' => 'user', 'role' => 'technician', 'id' => 'u-tech1', 'displayName' => 'مصطفى', 'powers' => ['read', 'write']];
$alerts = function ($ev) use ($sys) { return Mutqan_Store::select('_push', ['event' => $ev], ['limit' => 20], $sys); };

// invoice request
$o = Mutqan_Store::insert('orders', ['status' => 'completed', 'technicianId' => 'u-tech1', 'technicianName' => 'مصطفى', 'clientPhone' => '0551231231', 'number' => '1777'], null, $sh, $sys);
$r = Mutqan_Fieldops::handle('invoice', ['orderId' => $o['id'], 'note' => 'ضريبية باسم الشركة'], $tech);
$raw = Mutqan_Store::get_raw('orders', $o['id']);
$t('invoice request: written on the order', ($r['ok'] ?? false) && ($raw['invoiceRequest']['byName'] ?? '') === 'مصطفى' && ($raw['invoiceRequest']['note'] ?? '') === 'ضريبية باسم الشركة', $raw['invoiceRequest'] ?? null);
$a = $alerts('invoice_request');
$t('…operations hear it, with the order number', count($a) >= 1 && strpos($a[0]['title'] . $a[0]['body'], '1777') !== false, $a);
$err = ''; try { Mutqan_Fieldops::handle('invoice', ['orderId' => $o['id']], ['role' => 'technician', 'id' => 'u-other', 'kind' => 'user']); } catch (Mutqan_Error $e) { $err = $e->getMessage(); }
$t('…another technician cannot ask for it', $err !== '');

// decline
$d = Mutqan_Store::insert('orders', ['status' => 'assigned', 'technicianId' => 'u-tech1', 'technicianName' => 'مصطفى', 'clientPhone' => '0551231232', 'number' => '1778'], null, $sh, $sys);
$r = Mutqan_Fieldops::handle('decline', ['orderId' => $d['id'], 'reason' => 'far'], $tech);
$raw = Mutqan_Store::get_raw('orders', $d['id']);
$t('decline: the order goes back to operations without him', empty($raw['technicianId']) && in_array((string) ($raw['status'] ?? ''), ['new', 'pending'], true), $raw);
$t('…operations hear it', count($alerts('tech_decline')) >= 1);

// claim from the radar
$c = Mutqan_Store::insert('orders', ['status' => 'new', 'clientPhone' => '0551231233', 'number' => '1779'], null, $sh, $sys);
try { $r = Mutqan_Fieldops::handle('claim', ['orderId' => $c['id']], $tech); } catch (Throwable $e) { $r = ['error' => $e->getMessage()]; }
$raw = Mutqan_Store::get_raw('orders', $c['id']);
$t('claim: the order is his', ($raw['technicianId'] ?? '') === 'u-tech1', [$r, $raw['technicianId'] ?? null]);

echo $ok ? "OK field buttons\n" : "FAILED field buttons\n";
exit($ok ? 0 : 1);
