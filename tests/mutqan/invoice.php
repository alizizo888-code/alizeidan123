<?php
// Invoices (owner, 2026-11-22): plain and tax, sequential numbers, the template's amounts (120 → 104.35 + 15.65),
// the ZATCA QR, the stamp choice, never deleted (voided by the owner), the company as it was the day of issue.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $n = 0;
$t = function ($name, $c, $x = null) use (&$ok, &$n) { $n++; echo ($c ? 'PASS ' : 'FAIL ') . 'invoice: ' . $name . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$I = 'Mutqan_Invoice';
$owner = ['id' => 'u1', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'علي'];
$ops = ['id' => 'u2', 'role' => 'operations', 'powers' => ['read', 'write'], 'kind' => 'user', 'name' => 'العمليات'];
$sup = ['id' => 'u3', 'role' => 'supervisor', 'powers' => ['read'], 'kind' => 'user', 'name' => 'مشرف'];
$deny = function ($fn) { try { $fn(); return false; } catch (Mutqan_Error $e) { return true; } };

$c = $I::cfg();
$t('defaults from the VAT certificate', $c['vatNo'] === '310798423700003' && $c['crNo'] === '4031244695' && mb_strpos($c['nameAr'], 'فيصل حسين') !== false && $c['vatRate'] == 15);

// the template's numbers: 120 inclusive → total 104.35, VAT 15.65, net 120
$k = $I::calc([['name' => 'غسيل تكيف اسبليت', 'qty' => 1, 'price' => 120]], true, 15, true);
$t('tax, inclusive: 120 → 104.35 + 15.65 (as the template)', $k['totals']['afterDiscount'] == 104.35 && $k['totals']['vat'] == 15.65 && $k['totals']['net'] == 120.0, $k['totals']);
$k = $I::calc([['name' => 'x', 'qty' => 2, 'price' => 100, 'discount' => 20]], true, 15, false);
$t('tax, exclusive with a discount: 200 − 20 = 180 + 27 = 207', $k['totals']['afterDiscount'] == 180.0 && $k['totals']['vat'] == 27.0 && $k['totals']['net'] == 207.0, $k['totals']);
$k = $I::calc([['name' => 'a', 'qty' => 2, 'price' => 80], ['name' => 'b', 'qty' => 1, 'price' => 50]], false, 15, true);
$t('plain invoice: no VAT, the lines add up', $k['totals']['vat'] == 0.0 && $k['totals']['net'] == 210.0 && count($k['rows']) === 2);

// ZATCA TLV
$qr = $I::zatca_qr('Bobs Records', '310122393500003', '2022-04-25T15:30:00Z', 1000, 150);
$raw = base64_decode($qr);
$t('ZATCA QR: TLV tags 1..5 in order, base64', ord($raw[0]) === 1 && substr($raw, 2, ord($raw[1])) === 'Bobs Records' && strpos($raw, '1000.00') !== false && strpos($raw, '150.00') !== false);
$ar = base64_decode($I::zatca_qr('مؤسسة', '310798423700003', 'x', 1, 0));
$t('…Arabic seller name: the length is in bytes', ord($ar[1]) === strlen('مؤسسة'));

// issuing
$inv = $I::save(['type' => 'tax', 'client' => ['name' => 'مطعم إشراق', 'vatNo' => '310132544200003', 'city' => 'المدينة المنورة'], 'items' => [['code' => '101', 'name' => 'غسيل تكيف اسبليت', 'qty' => 1, 'price' => 120]], 'tech' => ['name' => 'محمود'], 'stamped' => true], $owner);
$t('a tax invoice: number 1, net 120, VAT 15.65, QR, stamped', $inv['number'] === 1 && $inv['totals']['net'] == 120.0 && $inv['totals']['vat'] == 15.65 && !empty($inv['qr']) && $inv['stamped'] === true, $inv);
$t('…keeps the company as it is today (VAT no, CR)', $inv['company']['vatNo'] === '310798423700003' && $inv['company']['crNo'] === '4031244695');
$inv2 = $I::save(['type' => 'tax', 'client' => ['name' => 'شركة أجواد الكرم'], 'items' => [['name' => 'صيانة', 'qty' => 1, 'price' => 500]], 'stamped' => false], $ops);
$t('numbers follow (2), operations can issue, the stamp can be left off', $inv2['number'] === 2 && $inv2['stamped'] === false);
$n1 = $I::save(['type' => 'normal', 'client' => ['name' => 'أبو سعد'], 'items' => [['name' => 'اصلاح غرفة تجميد', 'qty' => 1, 'price' => 500], ['name' => 'شحن فريون', 'qty' => 1, 'price' => 200]], 'tech' => ['name' => 'علي'], 'pay' => 'cash', 'stamped' => true], $owner);
$t('a plain invoice has its own numbers (1001), no VAT, no QR, the technician', $n1['number'] === 1001 && $n1['totals']['net'] == 700.0 && $n1['totals']['vat'] == 0 && empty($n1['qr']) && $n1['tech']['name'] === 'علي', $n1);
$t('a supervisor cannot issue', $deny(function () use ($I, $sup) { $I::save(['type' => 'normal', 'client' => ['name' => 'x'], 'items' => [['name' => 'y', 'price' => 10]]], $sup); }));
$t('no client name → refused', $deny(function () use ($I, $owner) { $I::save(['type' => 'normal', 'client' => ['name' => ''], 'items' => [['name' => 'y', 'price' => 10]]], $owner); }));
$t('no items or a zero total → refused', $deny(function () use ($I, $owner) { $I::save(['type' => 'normal', 'client' => ['name' => 'x'], 'items' => []], $owner); }) && $deny(function () use ($I, $owner) { $I::save(['type' => 'normal', 'client' => ['name' => 'x'], 'items' => [['name' => 'y', 'price' => 0]]], $owner); }));
$t('a wrong customer VAT number → refused', $deny(function () use ($I, $owner) { $I::save(['type' => 'tax', 'client' => ['name' => 'x', 'vatNo' => '12345'], 'items' => [['name' => 'y', 'price' => 10]]], $owner); }));

// settings: owner only, a change never rewrites an issued invoice
$t('settings are the owner\'s', $deny(function () use ($I, $ops) { $I::cfg_set(['nameAr' => 'x'], $ops); }));
$t('a wrong VAT number in the settings → refused', $deny(function () use ($I, $owner) { $I::cfg_set(['vatNo' => '123'], $owner); }));
$I::cfg_set(['nameAr' => 'اسم جديد', 'items' => [['code' => '9', 'name' => 'فحص', 'unit' => 'خدمة', 'price' => 50]], 'next' => ['normal' => 5000], 'template' => ['normal' => 'modern']], $owner);
$c = $I::cfg();
$t('settings saved: name, catalogue with codes, next number, default template', $c['nameAr'] === 'اسم جديد' && $c['items'][0]['code'] === '9' && $c['next']['normal'] === 5000 && $c['template']['normal'] === 'modern');
$t('…the issued invoice keeps the old name', $I::get($inv['id'])['company']['nameAr'] !== 'اسم جديد');
$n2 = $I::save(['type' => 'normal', 'client' => ['name' => 'س'], 'items' => [['name' => 'ي', 'price' => 10]]], $owner);
$t('the next number from the settings is used (5000) and the default template', $n2['number'] === 5000 && $n2['template'] === 'modern', $n2);
$t('duplicate item codes are refused', $deny(function () use ($I, $owner) { $I::cfg_set(['items' => [['code' => '1', 'name' => 'a'], ['code' => '1', 'name' => 'b']]], $owner); }));
$I::cfg_set(['next' => ['normal' => 10]], $owner);
$n3 = $I::save(['type' => 'normal', 'client' => ['name' => 'س'], 'items' => [['name' => 'ي', 'price' => 10]]], $owner);
$t('a lower next number never reuses one already issued', $n3['number'] === 5001, $n3['number']);

// register, void, never deleted
$l = $I::lst(['type' => 'tax']);
$t('the register lists by type', count($l) === 2 && $l[0]['type'] === 'tax');
$t('…and finds by client name', count($I::lst(['q' => 'أجواد'])) === 1);
$t('only the owner voids', $deny(function () use ($I, $ops, $inv2) { $I::void($inv2['id'], 'غلط', $ops); }));
$v = $I::void($inv2['id'], 'غلط في الاسم', $owner);
$t('voided: kept with its number and the reason', $v['status'] === 'void' && $v['number'] === 2 && $v['voidReason'] === 'غلط في الاسم');
$t('nobody deletes an invoice through the store', $deny(function () use ($inv, $ops) { Mutqan_Store::delete('invoices', $inv['id'], $ops); }));

// from an order: the order remembers its invoice
$o = Mutqan_Store::insert('orders', ['status' => 'completed', 'clientName' => 'ع', 'service' => 'غسيل 1 سبليت'], null, ['groupId' => 'main'], ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user']);
$f = $I::save(['type' => 'normal', 'orderId' => $o['id'], 'client' => ['name' => 'ع'], 'items' => [['name' => 'غسيل', 'price' => 100]]], $owner);
$t('an invoice from an order is written on the order', (Mutqan_Store::get_raw('orders', $o['id'])['invoice']['number'] ?? 0) === $f['number']);
$t('the ops door answers', ($I::op('invoice_cfg', [], $owner)['templates']['thermal'] ?? '') !== '' && count($I::op('invoice_list', [], $owner)['items']) >= 4);

echo ($ok ? 'ALL PASS' : 'SOME FAILED') . " ($n)\n";
exit($ok ? 0 : 1);
