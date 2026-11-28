<?php
// «حلقة الوصل»: one website order followed on WhatsApp — confirm (missing time/location asked and filled), the room told,
// the technician's job card, the customer's «الفني فين» answered through the technician, arrival, done, rating to the owner.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-journey-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "journey: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$W = 'Mutqan_WA'; $J = 'Mutqan_WA_Journey';
$owner = ['kind' => 'user', 'role' => 'owner', 'id' => 'o1', 'name' => 'علي', 'powers' => ['read', 'write', 'manage']];
$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
$hook = function ($secret, $ev) use ($W) { $raw = json_encode($ev, JSON_UNESCAPED_UNICODE); return $W::webhook($raw, hash_hmac('sha256', $raw, $secret)); };
$pull = function ($secret, $in) use ($W) { $raw = json_encode($in); return $W::pull_signed($raw, hash_hmac('sha256', $raw, $secret)); };

$D = $W::handle(['op' => 'fleet_register', 'name' => 'VPS', 'kind' => 'vps'], $owner);
$pull($D['secret'], ['device' => $D['id'], 'sessions' => []]);
$L1 = $W::handle(['op' => 'link', 'phone' => '0501110001', 'role' => 'customers'], $owner);
$L3 = $W::handle(['op' => 'link', 'phone' => '0501110003', 'role' => 'reports'], $owner);
$L4 = $W::handle(['op' => 'link', 'phone' => '0501110004', 'role' => 'technicians'], $owner);
foreach ([$L1, $L3, $L4] as $i => $L) { $hook($D['secret'], ['type' => 'status', 'accountId' => $L['id'], 'status' => 'open', 'phone' => '9665011100' . $i]); }
Mutqan_WA_Central::save(['master' => '0569998887']);
$jobs = function () use ($pull, $D) { return $pull($D['secret'], ['device' => $D['id'], 'sessions' => []])['jobs'] ?? []; };
$to = function ($js, $tail) { return array_values(array_filter($js, function ($j) use ($tail) { return substr(preg_replace('/\D/', '', $j['to']), -9) === $tail; })); };
$cust = '555000777'; $CP = '966' . $cust;
$say = function ($text, $id) use ($hook, $D, $L1, $CP) { $hook($D['secret'], ['type' => 'message', 'accountId' => $L1['id'], 'msgId' => $id, 'direction' => 'in', 'phone' => $CP, 'name' => 'أبو خالد', 'text' => $text, 'ts' => time()]); };

// a technician with a number
Mutqan_Store::insert('tech_profiles', ['userId' => 'u-t1', 'displayName' => 'محمود', 'phone' => '0577770001', 'available' => true], 'u-t1', [], $sys);

// 1. a website order, no time and no location
$o = Mutqan_Store::insert('orders', ['status' => 'new', 'number' => '7001', 'source' => 'web', 'clientName' => 'أبو خالد', 'clientPhone' => $CP, 'service' => 'صيانة مكيف سبليت', 'brand' => 'oxygen'], null, [], $sys);
$js = $jobs();
$c = $to($js, $cust);
$t('a new website order is sent to the customer on WhatsApp', $c && strpos($c[0]['text'], 'طلبك رقم *7001*') !== false, $js);
$t('…asking for what is missing (time and location)', $c && strpos($c[0]['text'], 'الموعد المناسب') !== false && strpos($c[0]['text'], 'اللوكيشن') !== false, $c[0]['text'] ?? null);
$t('…from a customers\' line', $c && $c[0]['acct'] === $L1['id'], $c[0] ?? null);
$t('the journey is waiting for his confirmation', ($J::current($CP)['s'] ?? '') === 'confirm');

$say('تأكيد', 'j1');
$c = $to($jobs(), $cust);
$t('«تأكيد» with data missing asks again for what is missing', $c && strpos($c[0]['text'], 'لسه ناقصنا') !== false, $c);
$say('بكرة العصر الساعة 5', 'j2');
$oo = Mutqan_Store::get_raw('orders', $o['id']);
$t('his time is written into the order', ($oo['preferredTime'] ?? '') === 'بكرة العصر الساعة 5', $oo);
$say('https://maps.google.com/?q=21.4225,39.8262', 'j3');
$oo = Mutqan_Store::get_raw('orders', $o['id']);
$t('his location pin is written into the order', abs((float) ($oo['lat'] ?? 0) - 21.4225) < 0.0001, $oo);
$c = $to($jobs(), $cust);
$t('…and with everything there he is asked to confirm', $c && strpos(end($c)['text'], 'اكتب *تأكيد*') !== false, $c);
$say('تمام', 'j4');
$oo = Mutqan_Store::get_raw('orders', $o['id']);
$t('«تمام» confirms the order', !empty($oo['customerConfirmed']) && ($oo['confirmedVia'] ?? '') === 'whatsapp', $oo);
$js = $jobs();
$m = $to($js, '569998887');
$t('the room (master) hears «العميل أكد» with the details', $m && strpos($m[count($m) - 1]['text'], 'العميل أكد طلب #7001') !== false && strpos($m[count($m) - 1]['text'], 'اعتمد 7001') !== false, $m);
$t('…from the reports line', $m && end($m)['acct'] === $L3['id'], $m);
$c = $to($js, $cust);
$t('the customer is told it is confirmed', $c && strpos($c[0]['text'], 'تم تأكيد طلبك') !== false, $c);
$t('the journey is live', ($J::current($CP)['s'] ?? '') === 'live');

// 2. distribution → technician's job card, room told
Mutqan_Store::update('orders', $o['id'], ['technicianId' => 'u-t1', 'technicianName' => 'محمود', 'status' => 'assigned'], $sys);
$js = $jobs();
$tc = $to($js, '577770001');
$t('the technician gets the job card on WhatsApp', $tc && strpos($tc[0]['text'], 'طلب جديد ليك') !== false && strpos($tc[0]['text'], '21.4225') !== false, $js);
$m = $to($js, '569998887');
$t('the room hears who it went to', $m && strpos(end($m)['text'], 'اتسند للفني محمود') !== false, $m);

// 3. «الفني فين؟» — the technician is asked, his answer goes back to the customer
Mutqan_Store::update('orders', $o['id'], ['status' => 'en_route'], $sys);
$jobs();
$say('الفني فين؟', 'j5');
$js = $jobs();
$c = $to($js, $cust);
$t('«الفني فين» is answered on WhatsApp from the order', $c && strpos(end($c)['text'], 'محمود') !== false, $c);
$t('…and the technician was asked for his estimate', (bool) $to($js, '577770001'), $js);
Mutqan_OrderBot::on_tech_eta(['orderId' => $o['id'], 'minutes' => 15]);
$c = $to($jobs(), $cust);
$t('his estimate reaches the customer on WhatsApp', $c && strpos(end($c)['text'], '15 دقيقة') !== false, $c);
$say('لو سمحت هات معاك سلم', 'j6');
$js = $jobs();
$t('a request is relayed to the technician', (bool) array_filter($to($js, '577770001'), function ($j) { return strpos($j['text'], 'هات معاك سلم') !== false; }), $js);

// 4. arrival and completion → owner; rating asked
Mutqan_Store::update('orders', $o['id'], ['status' => 'arrived'], $sys);
$m = $to($jobs(), '569998887');
$t('the owner hears the technician arrived', $m && strpos(end($m)['text'], 'الفني محمود وصل') !== false, $m);
Mutqan_Store::update('orders', $o['id'], ['status' => 'completed', 'agreedPrice' => 250], $sys);
$js = $jobs();
$m = $to($js, '569998887');
$t('the owner hears the job is done', (bool) array_filter($m, function ($j) { return strpos($j['text'], 'تمت المهمة') !== false; }), $m);
$t('the customer is asked to rate 1–5', (bool) array_filter($to($js, $cust), function ($j) { return strpos($j['text'], 'قيّم الخدمة') !== false; }), $js);
$say('5 ممتاز والفني محترم', 'j7');
$oo = Mutqan_Store::get_raw('orders', $o['id']);
$t('his stars are saved on the order', ($oo['rating']['stars'] ?? 0) === 5 && ($oo['customerRating'] ?? 0) === 5, $oo['rating'] ?? null);
$m = $to($jobs(), '569998887');
$t('…and reach the owner', $m && strpos(end($m)['text'], '5/5') !== false && strpos(end($m)['text'], 'محترم') !== false, $m);
$t('the journey ends', $J::current($CP) === null);

// 5. a low score raises it
$o2 = Mutqan_Store::insert('orders', ['status' => 'new', 'number' => '7002', 'source' => 'app', 'clientName' => 'سعد', 'clientPhone' => '966555000888', 'service' => 'سباكة', 'address' => 'الشوقية', 'preferredTime' => 'الآن'], null, [], $sys);
$c = $to($jobs(), '555000888');
$t('with nothing missing he is asked only to confirm', $c && strpos($c[0]['text'], 'لو البيانات صح') !== false, $c);
Mutqan_Store::update('orders', $o2['id'], ['status' => 'completed'], $sys); $jobs();
$hook($D['secret'], ['type' => 'message', 'accountId' => $L1['id'], 'msgId' => 'k1', 'direction' => 'in', 'phone' => '966555000888', 'text' => '2', 'ts' => time()]);
$m = $to($jobs(), '569998887');
$t('a low rating reaches the owner as «عميل مش راضي»', $m && strpos(end($m)['text'], 'عميل مش راضي') !== false, $m);

// 6. a site technician (no phone): the job card goes to the room to pass on by hand
Mutqan_Store::insert('tech_profiles', ['userId' => 'u-site', 'displayName' => 'فني الموقع', 'isSite' => true], 'u-site', [], $sys);
$o4 = Mutqan_Store::insert('orders', ['status' => 'new', 'number' => '7004', 'source' => 'web', 'clientName' => 'ماجد', 'clientPhone' => '966555000444', 'service' => 'غسيل', 'address' => 'النسيم', 'preferredTime' => 'بكرة'], null, [], $sys);
$jobs();
Mutqan_Store::update('orders', $o4['id'], ['technicianId' => 'u-site', 'technicianName' => 'فني الموقع', 'status' => 'assigned'], $sys);
$m = $to($jobs(), '569998887');
$t('a site technician\'s job is sent to the room to review and pass on', $m && strpos(end($m)['text'], 'راجع وابعت بنفسك') !== false && strpos(end($m)['text'], 'النسيم') !== false, $m);

// 7. the customer cancels from the site: asked why, offered a way back
$client = ['kind' => 'user', 'role' => 'client', 'id' => 'c-55', 'name' => 'ماجد', 'powers' => []];
$o5 = Mutqan_Store::insert('orders', ['status' => 'new', 'number' => '7005', 'source' => 'web', 'clientName' => 'ماجد', 'clientPhone' => '966555000555', 'clientId' => 'c-55', 'service' => 'غسيل 3 مكيفات', 'address' => 'العوالي', 'preferredTime' => 'اليوم', 'agreedPrice' => 300], null, [], $sys);
$jobs();
$J::set_enabled(false); Mutqan_Store::update('orders', $o5['id'], ['status' => 'cancelled'], $sys); $J::set_enabled(true);
Mutqan_WA_Journey::on_status(Mutqan_Store::get_raw('orders', $o5['id']) + ['id' => $o5['id']], ['status' => 'new'], $client);
$js = $jobs(); $c = $to($js, '555000555');
$t('a customer who cancelled is asked why on WhatsApp', $c && strpos(end($c)['text'], 'لغيت طلبك رقم *7005*') !== false && strpos(end($c)['text'], 'رجّعه') !== false, $c);
$m = $to($js, '569998887');
$t('…and the room knows the win-back started', $m && strpos(end($m)['text'], 'بحاول أرجّعه') !== false, $m);
$hook($D['secret'], ['type' => 'message', 'accountId' => $L1['id'], 'msgId' => 'w1', 'direction' => 'in', 'phone' => '966555000555', 'text' => '1', 'ts' => time()]);
$js = $jobs(); $c = $to($js, '555000555');
$t('«السعر» gets a capped discount offer', $c && preg_match('/خصم (\d+)%/u', end($c)['text'], $mm) && (int) $mm[1] <= 15, $c);
$m = $to($js, '569998887');
$t('the reason reaches the room', $m && strpos(end($m)['text'], 'سبب إلغاء طلب #7005') !== false && strpos(end($m)['text'], 'السعر') !== false, $m);
$hook($D['secret'], ['type' => 'message', 'accountId' => $L1['id'], 'msgId' => 'w2', 'direction' => 'in', 'phone' => '966555000555', 'text' => 'رجعه', 'ts' => time()]);
$oo = Mutqan_Store::get_raw('orders', $o5['id']);
$t('«رجّعه» brings the order back with the discount noted', ($oo['status'] ?? '') === 'new' && ($oo['winbackDiscountPct'] ?? 0) > 0 && !empty($oo['winback']['won']), $oo);
$m = $to($jobs(), '569998887');
$t('…and the room hears it came back', $m && strpos(end($m)['text'], 'العميل رجّع طلب #7005') !== false, $m);

// time as the reason → one message with the new time brings it back
$o6 = Mutqan_Store::insert('orders', ['status' => 'new', 'number' => '7006', 'source' => 'web', 'clientPhone' => '966555000666', 'service' => 'سباكة', 'address' => 'الزاهر', 'preferredTime' => 'اليوم'], null, [], $sys);
$jobs();
$J::set_enabled(false); Mutqan_Store::update('orders', $o6['id'], ['status' => 'cancelled'], $sys); $J::set_enabled(true);
Mutqan_WA_Journey::on_status(Mutqan_Store::get_raw('orders', $o6['id']) + ['id' => $o6['id']], ['status' => 'new'], $client);
$jobs();
$hook($D['secret'], ['type' => 'message', 'accountId' => $L1['id'], 'msgId' => 'w3', 'direction' => 'in', 'phone' => '966555000666', 'text' => 'الموعد مش مناسب', 'ts' => time()]);
$hook($D['secret'], ['type' => 'message', 'accountId' => $L1['id'], 'msgId' => 'w4', 'direction' => 'in', 'phone' => '966555000666', 'text' => 'الخميس الساعة 6', 'ts' => time()]);
$oo = Mutqan_Store::get_raw('orders', $o6['id']);
$t('«الموعد» → his new time reopens the order on it', ($oo['status'] ?? '') === 'new' && ($oo['preferredTime'] ?? '') === 'الخميس الساعة 6', $oo);

// staff cancelling does not message the customer
$o7 = Mutqan_Store::insert('orders', ['status' => 'new', 'number' => '7007', 'source' => 'web', 'clientPhone' => '966555000777', 'service' => 'x', 'address' => 'y', 'preferredTime' => 'z'], null, [], $sys);
$jobs();
Mutqan_Store::update('orders', $o7['id'], ['status' => 'cancelled'], $owner);
$t('a cancellation by the team does not start a win-back', !array_filter($to($jobs(), '555000777'), function ($j) { return strpos($j['text'], 'لغيت') !== false; }));

// the owner's commands
$mg = new ReflectionMethod('Mutqan_WA_Desk', 'management'); $mg->setAccessible(true);
$who = Mutqan_WA_Desk::identify('966569998887');
$r = $mg->invoke(null, $who, 'الملغي');
$t('«الملغي» lists today\'s cancellations with reasons', strpos($r, '#7007') !== false, $r);
$r = $mg->invoke(null, $who, 'رجع 7007');
$t('«رجع 7007» brings it back', (Mutqan_Store::get_raw('orders', $o7['id'])['status'] ?? '') === 'new' && strpos($r, 'رجع') !== false, $r);

// the owner writing in «message yourself» on a linked line is the owner, not a reply to a customer
Mutqan_WA_Central::save(['master' => '0501110001']);
$hook($D['secret'], ['type' => 'message', 'accountId' => $L1['id'], 'msgId' => 's1', 'direction' => 'in', 'self' => true, 'phone' => '966501110001', 'text' => 'الطلبات', 'ts' => time()]);
$c = $to($jobs(), '501110001');
$t('a command in the line\'s own chat is answered as the owner', (bool) $c, $c);
Mutqan_WA_Central::save(['master' => '0569998887']);

// the frame: kind, site, time, number on every message to the team; the company's name under every customer message
$o8 = Mutqan_Store::insert('orders', ['status' => 'new', 'number' => '7008', 'source' => 'web', 'brand' => 'oxygen', 'clientPhone' => '966555000123', 'service' => 'غسيل', 'address' => 'العزيزية', 'preferredTime' => 'بكرة'], null, [], $sys);
$js = $jobs();
$m = $to($js, '569998887');
$t('a team message is framed: kind, order number, time', $m && preg_match('/━+\n\*🆕 طلب جديد\* · #7008 · 🕐 \d\d\/\d\d \d\d:\d\d/u', $m[0]['text']), $m[0]['text'] ?? null);
$c = $to($js, '555000123');
$t('a customer message is signed with the company', $c && preg_match('/— \*[^*]+\* 🌷$/u', $c[0]['text']), $c[0]['text'] ?? null);
$src = Mutqan_WA_Central::source('مرحبا خبير 👋 بخصوص: غسيل المكيفات في مكة #khabeer/a321');
$t('the article a customer came from is read off his first message', ($src['type'] ?? '') === 'article' && $src['id'] === 321 && $src['title'] === 'غسيل المكيفات في مكة', $src);

// two of our own lines never answer each other (a loop gets both banned)
$before = count($jobs());
$hook($D['secret'], ['type' => 'message', 'accountId' => $L1['id'], 'msgId' => 'own1', 'direction' => 'in', 'phone' => '96650111002', 'text' => 'مرحبا', 'ts' => time()]);
$t('a message from another of our linked lines gets no automatic answer', !$to($jobs(), '650111002'));

// helpers
$t('confirm words', $J::is_yes('تمام') && $J::is_yes('أكد') && $J::is_yes('ok') && !$J::is_yes('عايز أغير الموعد'));
$t('time words', $J::looks_like_time('بكره الصبح') && $J::looks_like_time('الساعة 6 م') && !$J::looks_like_time('حي العزيزية شارع الحج'));
$t('coordinates from a maps link', $J::coords('https://maps.google.com/?q=21.5,39.2') === null && $J::coords('https://maps.google.com/?q=21.5433,39.1728') === [21.5433, 39.1728]);
$J::set_enabled(false);
$o3 = Mutqan_Store::insert('orders', ['status' => 'new', 'clientPhone' => '966555000999', 'service' => 'x'], null, [], $sys);
$t('switched off, nothing is sent', !$to($jobs(), '555000999'));
$J::set_enabled(true);

echo $ok ? "journey: all passed\n" : "journey: FAILED\n";
exit($ok ? 0 : 1);
