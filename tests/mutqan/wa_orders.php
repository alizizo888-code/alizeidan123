<?php
// A WhatsApp conversation has to end as an order the operations room can see, and the order has to
// carry its own history so anyone who picks it up knows what happened to it.
//
// Before this, a customer who answered the bot's questions produced a row in wa_leads — a table no
// operations screen reads — so the order existed only in the conversation and somebody had to notice
// it and retype it.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-waord-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
require dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/class-oxygen-desk.php';
Mutqan_Store::maybe_install();
Mutqan_Rest::routes();

$bad = 0;
function t($n, $c, $x = null) { global $bad; if (!$c) { $bad++; } echo ($c ? 'PASS ' : 'FAIL ') . "wa-orders: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; }

$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
$ops = ['id' => 'u-ops', 'role' => 'operations', 'powers' => Mutqan_Auth::powers_for('operations'), 'kind' => 'user', 'name' => 'نورة'];
$tech = ['id' => 'u-tech', 'role' => 'technician', 'powers' => Mutqan_Auth::powers_for('technician'), 'kind' => 'user', 'name' => 'سامي'];
$tech2 = ['id' => 'u-tech2', 'role' => 'technician', 'powers' => Mutqan_Auth::powers_for('technician'), 'kind' => 'user', 'name' => 'ماجد'];

// --- a customer finishes the bot's questions -------------------------------------------------
$acct = 'main';
$phone = '966551234567';
$tid = Mutqan_WA::tid($acct, $phone);
Mutqan_Store::upsert('wa_threads', ['name' => 'أبو خالد', 'bot' => ['neg' => ['qty' => 3, 'type' => 'سبليت', 'total' => 300, 'offer' => 270]]], $tid, [], $sys);

$lead = ['name' => 'أبو خالد', 'service' => 'غسيل مكيفات', 'area' => 'العزيزية', 'note' => 'تسجيل عبر البوت — السعر المتفق 270 ر.س'];
$oid = Mutqan_WA::lead_to_order($acct, $phone, $lead, $tid);
t('a finished conversation creates an order', is_string($oid) && $oid !== '', $oid);

$o = Mutqan_Store::get_raw('orders', $oid);
t('it is a new order, not a lead', ($o['status'] ?? '') === 'new');
t('it is marked as coming from WhatsApp', ($o['source'] ?? '') === 'whatsapp');
t('it carries the customer name', ($o['clientName'] ?? '') === 'أبو خالد');
t('it carries the phone in the international form the rest of the system uses', ($o['clientPhone'] ?? '') === '966551234567', $o['clientPhone'] ?? null);
t('it carries the service', ($o['service'] ?? '') === 'غسيل مكيفات');
t('it carries the area as the address', ($o['address'] ?? '') === 'العزيزية');
t('it links back to the conversation', ($o['waThread'] ?? '') === $tid);

// --- the plan ---------------------------------------------------------------------------------
t('the plan records what was agreed', ($o['plan']['qty'] ?? null) === 3 && ($o['plan']['unitType'] ?? '') === 'سبليت');
t('the plan records the agreed price, not just the quote', ($o['plan']['agreedPrice'] ?? null) === 270 && ($o['plan']['quotedPrice'] ?? null) === 300);
t('the agreed price is on the order itself too', ($o['agreedPrice'] ?? null) === 270);
t('the plan leaves out what was never said', !array_key_exists('nothing', $o['plan'] ?? []));

// --- the route --------------------------------------------------------------------------------
t('the route starts with the order arriving', ($o['route'][0]['kind'] ?? '') === 'wa_in');
t('the first step names where it came from', mb_strpos($o['route'][0]['text'] ?? '', 'واتساب') !== false);
t('the first step mentions the area', mb_strpos($o['route'][0]['text'] ?? '', 'العزيزية') !== false);

// --- operations sees it -------------------------------------------------------------------------
$seen = Mutqan_Store::select('orders', [], ['limit' => 50, 'order' => '-createdAt'], $ops, false);
$ids = array_column($seen, 'id');
t('the operations room can see it', in_array($oid, $ids, true), $ids);

// --- operations hands it to a technician ---------------------------------------------------------
Mutqan_Store::update('orders', $oid, ['technicianId' => 'u-tech', 'technicianName' => 'سامي', 'status' => 'assigned'], $ops);
$o = Mutqan_Store::get_raw('orders', $oid);
t('the assignment sticks', ($o['technicianId'] ?? '') === 'u-tech');
$kinds = array_column($o['route'], 'kind');
t('the route records the handover', in_array('assign', $kinds, true), $kinds);
$as = array_values(array_filter($o['route'], function ($r) { return ($r['kind'] ?? '') === 'assign'; }));
t('and who did it', ($as[0]['by'] ?? '') === 'نورة', $as[0] ?? null);
t('the route records the new state', in_array('status', $kinds, true));
t('the handover names the technician', mb_strpos(implode(' ', array_column($o['route'], 'text')), 'سامي') !== false);

// --- the technician sees his own order, and only his -----------------------------------------
$mine = Mutqan_Store::select('orders', [], ['limit' => 50], $tech, false);
t('the technician sees the order assigned to him', in_array($oid, array_column($mine, 'id'), true));

$other = Mutqan_Store::insert('orders', ['status' => 'assigned', 'technicianId' => 'u-tech2', 'clientName' => 'عميل آخر'], null, [], $sys)['id'];
$mine2 = Mutqan_Store::select('orders', [], ['limit' => 50], $tech, false);
t('he does not see another technician assigned order', !in_array($other, array_column($mine2, 'id'), true));

// --- the technician works it, and every step is written down ------------------------------------
Mutqan_Store::update('orders', $oid, ['status' => 'accepted'], $tech);
Mutqan_Store::update('orders', $oid, ['status' => 'completed'], $tech);
$o = Mutqan_Store::get_raw('orders', $oid);
$texts = implode(' | ', array_column($o['route'], 'text'));
t('accepting is on the route', mb_strpos($texts, 'قبل الطلب') !== false, $texts);
t('completing is on the route', mb_strpos($texts, 'إنجاز') !== false, $texts);
t('the route is in order, oldest first', $o['route'][0]['kind'] === 'wa_in' && end($o['route'])['kind'] === 'status');
t('the technician is named on his own steps', ($o['route'][count($o['route']) - 1]['by'] ?? '') === 'سامي');

// --- a second message does not become a second order ---------------------------------------------
Mutqan_Store::upsert('wa_threads', ['name' => 'أبو خالد', 'orderId' => $oid], $tid, [], $sys);
$before = count(Mutqan_Store::select('orders', [], ['limit' => 200], $sys, false));
$again = Mutqan_WA::lead_to_order($acct, $phone, ['name' => 'أبو خالد', 'service' => 'و كمان المطبخ', 'area' => 'العزيزية'], $tid);
$after = count(Mutqan_Store::select('orders', [], ['limit' => 200], $sys, false));
// the first order is completed, so a genuinely new request SHOULD open a new order
t('a new request after the job is done opens a new order', is_string($again) && $after === $before + 1, [$before, $after]);

// but while an order is still open, more detail joins it
$tid2 = Mutqan_WA::tid($acct, '966559999999');
Mutqan_Store::upsert('wa_threads', ['name' => 'سلمى'], $tid2, [], $sys);
$live = Mutqan_WA::lead_to_order($acct, '966559999999', ['name' => 'سلمى', 'service' => 'سباكة', 'area' => 'الشوقية'], $tid2);
$n1 = count(Mutqan_Store::select('orders', [], ['limit' => 200], $sys, false));
$dup = Mutqan_WA::lead_to_order($acct, '966559999999', ['name' => 'سلمى', 'service' => 'و كمان الحمام', 'area' => 'الشوقية'], $tid2);
$n2 = count(Mutqan_Store::select('orders', [], ['limit' => 200], $sys, false));
t('a follow-up while the order is open does not duplicate it', $dup === null && $n2 === $n1, [$n1, $n2]);
$lo = Mutqan_Store::get_raw('orders', $live);
t('the follow-up is added to the open order instead', mb_strpos(implode(' ', array_column($lo['route'], 'text')), 'الحمام') !== false, $lo['route']);

// --- nothing is created out of nothing ------------------------------------------------------------
$empty = Mutqan_WA::lead_to_order($acct, '966550000000', ['name' => 'x', 'service' => '', 'area' => 'مكة'], Mutqan_WA::tid($acct, '966550000000'));
t('a conversation with no service does not create an order', $empty === null);

// --- the route cannot grow without limit -----------------------------------------------------------
for ($i = 0; $i < 70; $i++) { Mutqan_WA::add_step($live, 'note', 'خطوة ' . $i); }
$lo = Mutqan_Store::get_raw('orders', $live);
t('the route is capped', count($lo['route']) === 60, count($lo['route']));
t('and keeps the newest steps', mb_strpos(end($lo['route'])['text'], '69') !== false);

exit($bad === 0 ? 0 : 1);
