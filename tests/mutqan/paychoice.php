<?php
// «طرق الدفع» link of one order (owner, 2026-11-23 — idea 25).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'paychoice: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$sys = ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']];
$sh = ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'];
$P = 'Mutqan_PayChoice';
$o = Mutqan_Store::insert('orders', ['status' => 'completed', 'clientPhone' => '+966551234567', 'clientName' => 'سامي', 'finalPrice' => 250, 'number' => 7001, 'technicianId' => 'tech1', 'createdAt' => gmdate('c')], null, $sh, $sys);
$msg = $P::message($o);
$raw = Mutqan_Store::get_raw('orders', $o['id']);
$t('the message carries the amount and the order\'s own link', strpos($msg, '250 ر.س') !== false && strpos($msg, '#/pay?o=' . $o['id'] . '&k=' . $raw['payToken']) !== false, $msg);
$t('… and tells him how to get it back', strpos($msg, 'اكتب *طرق الدفع*') !== false);
$t('the key is made once', $P::token($raw + ['id' => $o['id']]) === $raw['payToken']);
$v = $P::handle(['op' => 'view', 'o' => $o['id'], 'k' => $raw['payToken']]);
$t('the page sees the amount and the methods', $v['amount'] == 250 && isset($v['methods']['cash']) && $v['paid'] === false, $v);
$err = ''; try { $P::handle(['op' => 'view', 'o' => $o['id'], 'k' => 'wrong']); } catch (Mutqan_Error $e) { $err = $e->getMessage(); }
$t('a wrong key sees nothing', $err !== '', $err);
$v = $P::handle(['op' => 'choose', 'o' => $o['id'], 'k' => $raw['payToken'], 'method' => 'transfer']);
$after = Mutqan_Store::get_raw('orders', $o['id']);
$t('his choice is written on the order', ($after['paymentChoice']['method'] ?? '') === 'transfer' && ($v['choice']['label'] ?? '') === 'تحويل بنكي', $after['paymentChoice'] ?? null);
$push = Mutqan_Store::select('_push', ['event' => 'order_pay_choice'], ['limit' => 10], $sys);
$t('the technician and operations hear it', count($push) >= 1, count($push));
$sysx = ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']];
$cm = Mutqan_Store::insert('payment_methods', ['type' => 'card_on_site', 'name' => 'شبكة', 'enabled' => false], null, ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'], $sysx);
$v = $P::handle(['op' => 'view', 'o' => $o['id'], 'k' => $raw['payToken']]);
$t('card machine switched off: still listed, marked unavailable', isset($v['methods']['card']) && strpos((string) ($v['off']->card ?? ''), 'غير متاحة حاليًا') !== false, $v);
$err = ''; try { $P::handle(['op' => 'choose', 'o' => $o['id'], 'k' => $raw['payToken'], 'method' => 'card']); } catch (Mutqan_Error $e) { $err = $e->getMessage(); }
$t('…picking it says the machine is with other technicians, never «not offered»', strpos($err, 'مع فنيين تانيين') !== false, $err);
Mutqan_Store::update('payment_methods', $cm['id'], ['enabled' => true], $sysx);
$v = $P::handle(['op' => 'view', 'o' => $o['id'], 'k' => $raw['payToken']]);
$t('…switched on: it can be picked', empty((array) $v['off']), $v);
$err = ''; try { $P::handle(['op' => 'choose', 'o' => $o['id'], 'k' => $raw['payToken'], 'method' => 'bitcoin']); } catch (Mutqan_Error $e) { $err = $e->getMessage(); }
$t('only the listed methods', $err !== '');
$f = $P::for_phone('0551234567');
$t('«طرق الدفع» again finds his unpaid finished order', ($f['id'] ?? '') === $o['id'], $f);
Mutqan_Store::update('orders', $o['id'], ['paymentStatus' => 'paid'], $sys);
$t('… and nothing once it is paid', $P::for_phone('0551234567') === null);
$h = Mutqan_Hub::op('paylink', ['phone' => '966551234567']);
$t('the hub op answers null when there is nothing to pay', $h === null, $h);
echo $ok ? "OK paychoice\n" : "FAILED paychoice\n";
exit($ok ? 0 : 1);
