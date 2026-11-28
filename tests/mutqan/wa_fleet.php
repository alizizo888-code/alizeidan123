<?php
// «أجهزة الواتساب»: each desk device carries its own numbers, each number has a job, and every automatic
// message leaves from the right number through the device that carries it.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'wa_fleet: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$F = 'Mutqan_WA_Fleet'; $W = 'Mutqan_WA';
$owner = ['kind' => 'user', 'role' => 'owner', 'id' => 'o1', 'name' => 'علي', 'powers' => ['read', 'write', 'manage']];
$ops = ['kind' => 'user', 'role' => 'operations', 'id' => 'op1', 'powers' => ['read', 'write']];
$client = ['kind' => 'user', 'role' => 'client', 'id' => 'c1', 'powers' => []];
$sys = ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']];
$hook = function ($secret, $ev) use ($W) { $raw = json_encode($ev, JSON_UNESCAPED_UNICODE); return $W::webhook($raw, hash_hmac('sha256', $raw, $secret)); };
$pull = function ($secret, $in) use ($W) { $raw = json_encode($in); return $W::pull_signed($raw, hash_hmac('sha256', $raw, $secret)); };

/* ---------------------------------------------------------------- who may */
try { $W::handle(['op' => 'fleet_register', 'name' => 'x'], $client); $t('a customer cannot register a device', false); }
catch (Mutqan_Error $e) { $t('a customer cannot register a device', $e->status === 403); }

/* ---------------------------------------------------------------- registering a device */
$A = $W::handle(['op' => 'fleet_register', 'name' => 'تابلت المكتب'], $owner);
$B = $W::handle(['op' => 'fleet_register', 'name' => 'موبايل العمليات'], $ops);
$t('a device gets its own id and key', !empty($A['id']) && strlen($A['secret']) >= 40 && $A['secret'] !== $B['secret']);
$t('and a one-paste setup that points at this site with its own key', strpos($A['setup'], 'WEBHOOK_SECRET=' . $A['secret']) !== false && strpos($A['setup'], 'DEVICE_ID=' . $A['id']) !== false && strpos($A['setup'], '/wa-hook') !== false, $A['setup']);
$t('the setup starts the gateway', strpos($A['setup'], 'node src/index.js') !== false);
$list = $W::handle(['op' => 'fleet'], $ops);
$t('the fleet lists both devices', count($list['devices']) === 2);
$t('a key is never shown in the list', strpos(json_encode($list), $A['secret']) === false && strpos(json_encode($list), $B['secret']) === false);
$one = $W::handle(['op' => 'fleet_device', 'id' => $A['id']], $ops);
$t('the setup is given on request, to install the device', strpos($one['setup'], $A['secret']) !== false);
try { $W::handle(['op' => 'fleet_device', 'id' => $A['id']], ['kind' => 'user', 'role' => 'supervisor', 'id' => 's1', 'powers' => ['read']]); $t('read-only staff cannot take a device key', false); } catch (Mutqan_Error $e) { $t('read-only staff cannot take a device key', $e->status === 403); }

/* ---------------------------------------------------------------- a number is ordered onto a device */
$n1 = $W::handle(['op' => 'fleet_add', 'device' => $A['id'], 'role' => 'customers', 'label' => 'خط العملاء'], $owner);
$t('a new number belongs to its device', strpos($n1['id'], $A['id'] . '-') === 0);
$r = $pull($A['secret'], ['sent' => [], 'limit' => 5, 'sessions' => []]);
$t('the device collects «open this number» on its next check-in', count($r['cmds']) === 1 && $r['cmds'][0]['op'] === 'add' && $r['cmds'][0]['id'] === $n1['id'], $r['cmds']);
$rb = $pull($B['secret'], ['sent' => []]);
$t('and only that device', $rb['cmds'] === []);
$hook($A['secret'], ['type' => 'status', 'accountId' => $n1['id'], 'status' => 'qr', 'label' => 'خط العملاء', 'qr' => 'data:image/png;base64,AAA']);
$num = array_values(array_filter($F::numbers(), function ($n) use ($n1) { return $n['id'] === $n1['id']; }))[0];
$t('its QR comes back to the screen to be scanned', $num['qr'] === 'data:image/png;base64,AAA' && $num['device'] === $A['id'] && $num['role'] === 'customers', $num);
$r = $pull($A['secret'], ['done' => [$r['cmds'][0]['cid']]]);
$t('an order carried out is dropped', $r['cmds'] === []);
$hook($A['secret'], ['type' => 'status', 'accountId' => $n1['id'], 'status' => 'open', 'phone' => '966500000001']);

$n2 = $W::handle(['op' => 'fleet_add', 'device' => $B['id'], 'role' => 'technicians', 'label' => 'خط الفنيين'], $owner);
$hook($B['secret'], ['type' => 'status', 'accountId' => $n2['id'], 'status' => 'open', 'phone' => '966500000002']);
$pull($B['secret'], []);
$n3 = $W::handle(['op' => 'fleet_add', 'device' => $B['id'], 'role' => 'reports'], $owner);
$hook($B['secret'], ['type' => 'status', 'accountId' => $n3['id'], 'status' => 'open', 'phone' => '966500000003']);

/* ---------------------------------------------------------------- the right number for each message */
$t('an order copy leaves from the customers line', $F::account_for('order') === $n1['id']);
$t('a job card leaves from the technicians line', $F::account_for('dispatch') === $n2['id']);
$t('a report leaves from the reports line', $F::account_for('report') === $n3['id']);
$t('a complaint with no complaints line falls back to customers, never to technicians', $F::account_for('complaint') === $n1['id']);
$W::auto_send('0555555555', 'كرت شغل', 'dispatch');
$W::auto_send('0566666666', 'تم استلام طلبك', 'order');
$ja = $pull($A['secret'], ['limit' => 10]); $jb = $pull($B['secret'], ['limit' => 10]);
$t('device A collects only the customer message', count($ja['jobs']) === 1 && $ja['jobs'][0]['text'] === 'تم استلام طلبك', $ja['jobs']);
$t('device B collects only the job card', count($jb['jobs']) === 1 && $jb['jobs'][0]['text'] === 'كرت شغل', $jb['jobs']);
try { $W::handle(['op' => 'fleet_role', 'id' => $n3['id'], 'role' => 'hacker'], $owner); $t('an unknown role is refused', false); } catch (Mutqan_Error $e) { $t('an unknown role is refused', $e->status === 400); }

/* ---------------------------------------------------------------- a device that goes quiet */
$d = get_option($F::DEVICES); $d[$A['id']]['lastSeen'] = time() - 3600; update_option($F::DEVICES, $d);
$st = array_values(array_filter($F::numbers(), function ($n) use ($n1) { return $n['id'] === $n1['id']; }))[0]['status'];
$t('a number on a silent device is offline', $st === 'offline', $st);
$t('and nothing is routed to it', $F::account_for('order') !== $n1['id']);
$issues = $F::tick();
$t('the hourly round reports the silent device', (bool) array_filter($issues, function ($i) { return strpos($i, 'تابلت المكتب') !== false; }), $issues);
$push = Mutqan_Store::select('_push', [], ['limit' => 100], $sys);
$t('and tells the team', (bool) array_filter($push, function ($p) { return ($p['title'] ?? '') === 'جهاز واتساب متوقف'; }));
$pull($A['secret'], []);
$t('it is back the moment it checks in', $F::account_for('order') === $n1['id']);

/* ---------------------------------------------------------------- incoming: tagged, complaints raised */
$hook($A['secret'], ['type' => 'message', 'accountId' => $n1['id'], 'msgId' => 'm1', 'direction' => 'in', 'phone' => '966577777777', 'name' => 'سعد', 'text' => 'عندي شكوى الفني ما اشتغل كويس', 'kind' => 'text', 'ts' => time()]);
$th = Mutqan_Store::get_raw('wa_threads', $W::tid($n1['id'], '966577777777'));
$t('a complaint is tagged on its conversation', in_array('complaint', (array) ($th['tags'] ?? []), true), $th);
$notes = Mutqan_Store::select('notifications', [], ['limit' => 100], $sys);
$t('and raises the operations room', (bool) array_filter($notes, function ($n) { return ($n['event'] ?? '') === 'wa_complaint'; }));

/* ---------------------------------------------------------------- archive */
Mutqan_Store::update('wa_threads', $W::tid($n1['id'], '966577777777'), ['lastAt' => gmdate('c', time() - 40 * 86400), 'unread' => 0], $sys);
$t('idle conversations are archived', $F::archive(21) >= 1 && !empty(Mutqan_Store::get_raw('wa_threads', $W::tid($n1['id'], '966577777777'))['archived']));

/* ---------------------------------------------------------------- the old single gateway keeps working */
$c = $W::cfg();
$r = $pull($c['secret'], []);
$t('the original gateway key still checks in', isset($r['jobs']));

/* ---------------------------------------------------------------- revoke */
$W::handle(['op' => 'fleet_revoke', 'id' => $B['id']], $owner);
try { $pull($B['secret'], []); $t('a revoked device is refused', false); } catch (Mutqan_Error $e) { $t('a revoked device is refused', $e->status === 403); }
$W::handle(['op' => 'fleet_remove', 'id' => $n1['id']], $owner);
$t('a removed number is gone and its device is told to log it out', !array_filter($F::numbers(), function ($n) use ($n1) { return $n['id'] === $n1['id']; }) && $pull($A['secret'], [])['cmds'][0]['op'] === 'remove');

/* ---------------------------------------------------------------- settings */
try { $W::handle(['op' => 'fleet_save', 'settings' => ['archiveDays' => 5]], $ops); $t('only the owner changes fleet settings', false); } catch (Mutqan_Error $e) { $t('only the owner changes fleet settings', $e->status === 403); }
$s = $W::handle(['op' => 'fleet_save', 'settings' => ['archiveDays' => 1, 'reportTo' => '0501112222, 0503334444', 'repo' => 'javascript:alert(1)']], $owner);
$t('settings are clamped and phones normalised', $s['archiveDays'] === 3 && $s['reportTo'] === ['966501112222', '966503334444'] && strpos($s['repo'], 'https://') === 0, $s);

echo $ok ? "wa_fleet: all passed\n" : "wa_fleet: FAILURES\n";
exit($ok ? 0 : 1);
