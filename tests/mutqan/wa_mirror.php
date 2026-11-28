<?php
// «محادثة واحدة»: a website order is confirmed on WhatsApp; what is said on WhatsApp shows in the order's conversation
// on the site (and the site assistant reads it); what operations / the technician write on the site reaches WhatsApp;
// «فين الفني؟» on WhatsApp answers with where he is on the map and the live link.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-mirror-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "mirror: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$W = 'Mutqan_WA';
$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
$owner = ['kind' => 'user', 'role' => 'owner', 'id' => 'o1', 'name' => 'علي', 'powers' => ['read', 'write', 'manage']];
$GLOBALS['__post_hook'] = function () { return ['code' => 503, 'body' => '{}', 'headers' => []]; };
Mutqan_WA_AI::settings();
$D = $W::handle(['op' => 'fleet_register', 'name' => 'VPS', 'kind' => 'vps'], $owner);
// the gateway collects jobs and, on the next pull, reports what it sent (as the VPS gateway does)
$sent = [];
$jobs = function () use ($W, $D, &$sent) { $raw = json_encode(['device' => $D['id'], 'sessions' => [], 'sent' => $sent]); $js = $W::pull_signed($raw, hash_hmac('sha256', $raw, $D['secret']))['jobs'] ?? []; $sent = array_map(function ($j) { return $j['id']; }, $js); return $js; };
$jobs();
$L = $W::handle(['op' => 'link', 'phone' => '0501234567', 'role' => 'customers'], $owner);
$hook = function ($ev) use ($W, $D) { $raw = json_encode($ev, JSON_UNESCAPED_UNICODE); return $W::webhook($raw, hash_hmac('sha256', $raw, $D['secret'])); };
$hook(['type' => 'status', 'accountId' => $L['id'], 'status' => 'open', 'phone' => '966501234567']);
$C = '966500700900';
$i = 0;
$say = function ($text) use ($hook, $L, $C, &$i) { $i++; $hook(['type' => 'message', 'accountId' => $L['id'], 'msgId' => 'c' . $i, 'direction' => 'in', 'phone' => $C, 'name' => 'أبو خالد', 'text' => $text, 'ts' => time()]); };
$to = function ($js, $tail) { return array_values(array_filter($js, function ($j) use ($tail) { return substr(preg_replace('/\D/', '', $j['to']), -9) === $tail; })); };
$has = function ($js, $needle) { foreach ($js as $j) { if (mb_strpos($j['text'], $needle) !== false) { return true; } } return false; };
$chat = function ($oid) use ($sys) { return Mutqan_Store::select('order_msgs', ['orderId' => $oid, 'channel' => 'ops'], ['order' => 'at', 'limit' => 50], $sys); };
$chatHas = function ($rows, $from, $needle) { foreach ($rows as $r) { if (($r['from'] ?? '') === $from && mb_strpos((string) ($r['text'] ?? ''), $needle) !== false) { return true; } } return false; };

// 1. the customer orders on the website (name + phone are required now)
$cust = ['kind' => 'user', 'role' => 'client', 'id' => 'u-cli', 'name' => 'أبو خالد', 'powers' => []];
$o = Mutqan_Store::insert('orders', ['status' => 'new', 'clientName' => 'أبو خالد', 'clientPhone' => '0500700900', 'clientId' => 'u-cli', 'specialtyId' => 'ac', 'address' => 'العوالي، مكة', 'lat' => 21.35, 'lng' => 39.88, 'preferredTime' => 'اليوم المغرب'], null, [], $cust);
$js = $jobs(); $c = $to($js, '500700900');
$t('the website order is confirmed with the customer on WhatsApp', $c && $has($c, 'وصلنا طلبك رقم'), $js);
$t('…from the customers\' line', $c && $c[0]['acct'] === $L['id'], $c);
$jobs();   // the gateway reports it sent — the confirmation is written into the order's conversation on the site
$rows = $chat($o['id']);
$t('the WhatsApp confirmation shows in the order chat on the site', $chatHas($rows, 'ai', 'وصلنا طلبك') || $chatHas($rows, 'ops', 'وصلنا طلبك'), $rows);

// 2. he answers on WhatsApp: it is in the order's conversation, visible to him on the site
$say('تأكيد');
$rows = Mutqan_Store::select('order_msgs', ['orderId' => $o['id'], 'channel' => 'ops'], ['limit' => 50], $cust);
$t('his WhatsApp «تأكيد» shows in the order chat — and he can read it on the site', $chatHas($rows, 'client', 'تأكيد'), $rows);
$js = $jobs();
$t('…and the confirmation answer reaches him', $has($to($js, '500700900'), 'تم تأكيد طلبك'), $js);

// 3. operations writes in the order chat on the site → it reaches his WhatsApp, once
$staff = ['kind' => 'user', 'role' => 'operations', 'id' => 'u-ops', 'name' => 'سارة', 'powers' => ['read', 'write']];
$m = Mutqan_Store::insert('order_msgs', ['orderId' => $o['id'], 'channel' => 'ops', 'kind' => 'text', 'text' => 'أهلا أبو خالد، الفني هيكون عندك بعد المغرب'], null, ['groupId' => 'main', 'visibleTo' => 'group'], $staff);
Mutqan_Rest::after_order_msg($m);
$js = $jobs(); $c = $to($js, '500700900');
$t('what operations writes on the site reaches his WhatsApp', $has($c, 'بعد المغرب'), $js);
$n0 = count($chat($o['id']));
$jobs();
$t('…and is not written into the chat a second time when WhatsApp reports it sent', count($chat($o['id'])) === $n0, $chat($o['id']));

// 4. assigned to a technician on his way; «فين الفني؟» on WhatsApp → where he is on the map + the live link
Mutqan_Store::insert('tech_profiles', ['userId' => 'u-tech', 'displayName' => 'مصطفى', 'phone' => '0531485458', 'available' => true, 'lat' => 21.36, 'lng' => 39.86, 'atAt' => time() - 60], 'u-tech', [], $sys);
Mutqan_Store::update('orders', $o['id'], ['technicianId' => 'u-tech', 'technicianName' => 'مصطفى', 'status' => 'en_route'], $sys);
$jobs();
$say('فين الفني؟');
$js = $jobs(); $c = $to($js, '500700900');
$t('«فين الفني؟» answers with his place on the map', $has($c, 'maps.google.com/?q=21.36'), $js);
$t('…and the live link to follow him and talk to him', $has($c, '#/order/' . $o['id']), $js);

// 5. the technician writes to the customer in the order (site) → WhatsApp, with his name
$tp = ['kind' => 'user', 'role' => 'technician', 'id' => 'u-tech', 'name' => 'مصطفى', 'powers' => []];
$m = Mutqan_Store::insert('order_msgs', ['orderId' => $o['id'], 'channel' => 'tech', 'kind' => 'text', 'text' => 'انا قريب، جهز المفتاح'], null, [], $tp);
Mutqan_Rest::after_order_msg($m);
$c = $to($jobs(), '500700900');
$t('the technician\'s message on the site reaches the customer\'s WhatsApp with his name', $has($c, 'مصطفى: انا قريب'), $c);

// 6. the site assistant reads the WhatsApp conversation
$rows = $chat($o['id']);
$t('the order conversation holds the WhatsApp side too (what the site assistant reads)', $chatHas($rows, 'client', 'فين الفني'), $rows);

echo $ok ? "mirror: all passed\n" : "mirror: FAILED\n";
exit($ok ? 0 : 1);
