<?php
// The owner's own simulation: ONE WhatsApp line does everything, ONE test phone plays the customer and gives the room's
// commands. AI takes the order → number 1001 → customer confirms → the room hears → «اسند طلب 1001 لمصطفى» → the job card
// reaches Mustafa (the one WhatsApp can reach) → his «قبلت / في الطريق / وصلت / تم» move the order → customer and room told →
// rating to the room.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-sim-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "sim: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$W = 'Mutqan_WA';
$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
$owner = ['kind' => 'user', 'role' => 'owner', 'id' => 'o1', 'name' => 'علي', 'powers' => ['read', 'write', 'manage']];
$s = Mutqan_AI::settings(); $s['providers']['groq'] = array_merge($s['providers']['groq'], ['enabled' => 1, 'key' => 'gsk_test']); update_option('mutqan_ai_settings', $s);
$next = null;
$GLOBALS['__post_hook'] = function ($url, $a) use (&$next) { return ['code' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => json_encode($next, JSON_UNESCAPED_UNICODE)]]]]), 'headers' => []]; };

$D = $W::handle(['op' => 'fleet_register', 'name' => 'VPS', 'kind' => 'vps'], $owner);
$jobs = function () use ($W, $D) { $raw = json_encode(['device' => $D['id'], 'sessions' => []]); return $W::pull_signed($raw, hash_hmac('sha256', $raw, $D['secret']))['jobs'] ?? []; };
$jobs();
$L = $W::handle(['op' => 'link', 'phone' => '0501234567', 'role' => 'customers'], $owner);       // the one line
$hook = function ($ev) use ($W, $D) { $raw = json_encode($ev, JSON_UNESCAPED_UNICODE); return $W::webhook($raw, hash_hmac('sha256', $raw, $D['secret'])); };
$hook(['type' => 'status', 'accountId' => $L['id'], 'status' => 'open', 'phone' => '966501234567']);
$T = '966500700800';                                                                                // the owner's test phone
Mutqan_WA_Central::save(['master' => $T]);
update_option('mutqan_test_customer', $T);
Mutqan_Store::insert('tech_profiles', ['userId' => 'u-mus', 'displayName' => 'مصطفى', 'phone' => '', 'available' => true], 'u-mus', [], $sys);
Mutqan_Store::insert('tech_profiles', ['userId' => 'u-tolba', 'displayName' => 'الفنى مصطفى طلبة', 'phone' => '0531485458', 'available' => true], 'u-tolba', [], $sys);
$i = 0;
$say = function ($from, $text) use ($hook, $L, &$i) { $i++; $hook(['type' => 'message', 'accountId' => $L['id'], 'msgId' => 's' . $i, 'direction' => 'in', 'phone' => $from, 'name' => 'علي', 'text' => $text, 'ts' => time()]); };
$to = function ($js, $tail) { return array_values(array_filter($js, function ($j) use ($tail) { return substr(preg_replace('/\D/', '', $j['to']), -9) === $tail; })); };
$has = function ($js, $needle) { foreach ($js as $j) { if (mb_strpos($j['text'], $needle) !== false) { return true; } } return false; };

// 1. the customer (the test phone) talks to the AI receptionist
$next = ['reply' => 'أهلًا بيك 🌷 المكيف فيه إيه بالظبط؟ وفين الحي؟', 'lead' => ['service' => 'صيانة مكيف سبليت'], 'complete' => false, 'handoff' => false];
$say($T, 'السلام عليكم عندي تكييف اسبليت بينقط مية');
$js = $jobs(); $c = $to($js, '500700800');
$t('the test phone is answered as a customer by the AI (not as the owner)', $c && $has($c, 'المكيف فيه إيه'), $js);
$t('…from the one line', $c && $c[0]['acct'] === $L['id']);
$next = ['reply' => 'تمام، سجلت طلبك ✅', 'lead' => ['area' => 'العزيزية', 'time' => 'بكرة العصر', 'name' => 'علي'], 'complete' => true, 'handoff' => false];
$say($T, 'العزيزية بكرة العصر');
$js = $jobs();
$t('a neighbourhood name only: no order, no «سجلت طلبك», the pin itself is asked for', !Mutqan_Store::select('orders', ['source' => 'whatsapp'], ['limit' => 1], $sys) && $has($to($js, '500700800'), 'اللوكيشن نفسه') && !$has($to($js, '500700800'), 'سجلت طلبك'), $js);
$say($T, 'https://maps.google.com/?q=21.4310,39.8410');
$o = Mutqan_Store::select('orders', ['source' => 'whatsapp'], ['limit' => 1], $sys)[0] ?? null;
$t('the order is created with a short number', $o && $o['number'] === '1001', $o);
$js = $jobs();
$t('the room (the same phone, as master) gets «أوردر جاهز #1001»', $has($to($js, '500700800'), 'أوردر جاهز #1001'), $js);
$t('the customer is asked to confirm order 1001', $has($to($js, '500700800'), 'طلبك رقم *1001*'), $js);

// 2. the customer confirms
$say($T, 'تأكيد');
$js = $jobs();
$t('«تأكيد» confirms, and the room hears «العميل أكد طلب #1001»', $has($to($js, '500700800'), 'العميل أكد طلب #1001') && $has($to($js, '500700800'), 'تم تأكيد طلبك'), $js);

// 3. the room assigns it to Mustafa from the same phone
$say($T, 'اسند طلب 1001 لمصطفى');
$js = $jobs();
$o = Mutqan_Store::get_raw('orders', $o['id']) + ['id' => $o['id']];
$t('«اسند طلب 1001 لمصطفى» goes to the Mustafa WhatsApp can reach', ($o['technicianId'] ?? '') === 'u-tolba' && ($o['technicianName'] ?? '') === 'الفنى مصطفى طلبة', $o);
$card = $to($js, '531485458');
$t('the job card reaches Mustafa on WhatsApp from the same line', $card && $has($card, 'طلب جديد ليك') && $has($card, '1001') && $card[0]['acct'] === $L['id'], $js);
$t('the room is told', $has($to($js, '500700800'), 'اتوجّه'), $js);

// 4. Mustafa works it from WhatsApp; the customer and the room follow
$tech = function ($text) use ($hook, $L, &$i) { $i++; $hook(['type' => 'message', 'accountId' => $L['id'], 'msgId' => 'm' . $i, 'direction' => 'in', 'phone' => '966531485458', 'text' => $text, 'ts' => time()]); };
$tech('قبلت'); $tech('في الطريق');
$js = $jobs();
$o = Mutqan_Store::get_raw('orders', $o['id']) + ['id' => $o['id']];
$t('«قبلت» then «في الطريق» move the order', ($o['status'] ?? '') === 'en_route', $o['status'] ?? null);
$t('the customer hears the technician is on the way', $has($to($js, '500700800'), 'في الطريق'), $js);
$tech('وصلت');
$js = $jobs();
$t('the room hears he arrived', $has($to($js, '500700800'), 'وصل'), $js);
$tech('تم إنجاز المهمة');
$js = $jobs();
$o = Mutqan_Store::get_raw('orders', $o['id']) + ['id' => $o['id']];
$t('«تم إنجاز المهمة» completes it', ($o['status'] ?? '') === 'completed', $o['status'] ?? null);
$t('the room hears «تمت المهمة» and the customer is asked to rate', $has($to($js, '500700800'), 'تمت المهمة') && $has($to($js, '500700800'), 'قيّم الخدمة'), $js);

// 5. the rating
$say($T, '5 ممتاز');
$js = $jobs();
$o = Mutqan_Store::get_raw('orders', $o['id']);
$t('the rating is saved and reaches the room', ($o['rating']['stars'] ?? 0) === 5 && $has($to($js, '500700800'), '5/5'), $js);

echo $ok ? "sim: all passed\n" : "sim: FAILED\n";
exit($ok ? 0 : 1);
