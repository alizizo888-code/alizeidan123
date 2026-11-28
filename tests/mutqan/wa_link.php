<?php
// «ربط بلمسة»: one field (the number) → the right device opens it → the QR reaches the screen on the next 3-second
// check-in; tapping a number that is not connected asks for a fresh QR; WhatsApp commands from the team.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-walink-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "wa_link: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$W = 'Mutqan_WA'; $F = 'Mutqan_WA_Fleet';
$owner = ['kind' => 'user', 'role' => 'owner', 'id' => 'o1', 'name' => 'علي', 'powers' => ['read', 'write', 'manage']];
$pull = function ($secret, $in) use ($W) { $raw = json_encode($in); return $W::pull_signed($raw, hash_hmac('sha256', $raw, $secret)); };
$hook = function ($secret, $ev) use ($W) { $raw = json_encode($ev, JSON_UNESCAPED_UNICODE); return $W::webhook($raw, hash_hmac('sha256', $raw, $secret)); };

$err = ''; try { $W::handle(['op' => 'link', 'phone' => '0536554731'], $owner); } catch (Mutqan_Error $e) { $err = $e->getMessage(); }
$t('no device yet: says exactly what to do', strpos($err, 'أجهزة الواتساب') !== false, $err);

$A = $W::handle(['op' => 'fleet_register', 'name' => 'موبايل الشغل'], $owner);
$pull($A['secret'], ['device' => $A['id'], 'sessions' => []]);           // the device checks in: it is online
$r = $W::handle(['op' => 'link', 'phone' => '0536554731', 'role' => 'customers'], $owner);
$t('one field is enough: device picked by itself', ($r['device'] ?? '') === $A['id'] && !empty($r['id']) && !empty($r['deviceOnline']), $r);
$p = $pull($A['secret'], ['device' => $A['id'], 'sessions' => []]);
$cmd = $p['cmds'][0] ?? [];
$t('the device collects «open this number» on its next check-in', ($cmd['op'] ?? '') === 'add' && ($cmd['id'] ?? '') === $r['id'] && ($cmd['mode'] ?? '') === 'qr', $p);
$hook($A['secret'], ['type' => 'status', 'accountId' => $r['id'], 'status' => 'qr', 'qr' => 'data:image/png;base64,AAAA', 'label' => '+966536554731']);
$ss = $W::handle(['op' => 'sessions'], $owner)['sessions'];
$row = array_values(array_filter($ss, function ($x) use ($r) { return $x['id'] === $r['id']; }))[0] ?? [];
$t('the QR reaches the screen', ($row['qr'] ?? '') === 'data:image/png;base64,AAAA' && $row['status'] === 'qr', $row);

$pull($A['secret'], ['device' => $A['id'], 'sessions' => [], 'done' => [$cmd['cid']]]);
$rr = $W::handle(['op' => 'restart', 'id' => $r['id']], $owner);
$p = $pull($A['secret'], ['device' => $A['id'], 'sessions' => []]);
$t('tapping the number asks its device for a fresh QR (no more «restart it from the phone»)', ($rr['ok'] ?? false) && ($p['cmds'][0]['op'] ?? '') === 'add' && ($p['cmds'][0]['id'] ?? '') === $r['id'], $p);
$c = $W::handle(['op' => 'relink', 'id' => $r['id'], 'mode' => 'code'], $owner);
$p = $pull($A['secret'], ['device' => $A['id'], 'sessions' => []]);
$last = end($p['cmds']);
$t('pairing code instead of QR reuses the saved number', $c['mode'] === 'code' && ($last['mode'] ?? '') === 'code' && ($last['phone'] ?? '') === '966536554731', $p);

// WhatsApp commands from the team run on the internal engine
$GLOBALS['wpdb']->insert(Mutqan_Store::table('users'), ['id' => 'op9', 'handle' => '551231234', 'display_name' => 'منى', 'phone' => '0551231234', 'role' => 'operations', 'status' => 'active', 'password_hash' => 'x', 'created_at' => Mutqan_Store::now()]);
Mutqan_Store::insert('orders', ['status' => 'new', 'number' => '4321', 'clientName' => 'سالم'], null, [], $owner);
$who = Mutqan_WA_Desk::identify('966551231234');
$m = new ReflectionMethod('Mutqan_WA_Desk', 'management'); $m->setAccessible(true);
$a = $m->invoke(null, $who, 'فلوس النهارده');
$t('a WhatsApp command from operations runs on the internal engine', strpos($a, 'الإيراد') !== false, $a);
$a = $m->invoke(null, $who, 'طلب 4321');
$t('order tracking by number over WhatsApp', strpos($a, '4321') !== false && strpos($a, 'سالم') !== false, $a);
$a = $m->invoke(null, $who, 'كلام مش أمر خالص');
$t('anything else still gets the command list', strpos($a, 'أوامر غرفة العمليات') !== false, $a);
exit($ok ? 0 : 1);
