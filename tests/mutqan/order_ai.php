<?php
// The assistant in the order's customer-service chat: answers as «خدمة العملاء», knows the order and
// the customer's history, remembers the case, stays quiet when a person is in the conversation, and
// hands over to the operations room when it should.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-oai-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "order_ai: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$s = Mutqan_AI::settings(); $s['providers']['groq'] = array_merge($s['providers']['groq'], ['enabled' => 1, 'key' => 'gsk_test']); update_option('mutqan_ai_settings', $s);

$ops = ['id' => 'u-ops', 'role' => 'operations', 'kind' => 'user', 'powers' => ['read', 'write', 'manage'], 'name' => 'سارة'];
$cust = ['id' => 'u-cust1', 'role' => 'client', 'kind' => 'user', 'powers' => []];
$o = ['visibleTo' => 'creator-and-admin', 'writableBy' => 'admins'];
$old = Mutqan_Store::insert('orders', ['specialtyId' => 'ac', 'clientName' => 'أبو فهد', 'clientPhone' => '0551234567', 'address' => 'جدة', 'description' => 'المكيف السبليت يسرب ماء'], null, $o, $cust, false);
Mutqan_Store::update('orders', $old['id'], ['status' => 'completed', 'price' => 180], $ops);
$ord = Mutqan_Store::insert('orders', ['specialtyId' => 'ac', 'clientName' => 'أبو فهد', 'clientPhone' => '0551234567', 'address' => 'جدة', 'description' => 'المكيف ما يبرد'], null, $o, $cust, false);
$oid = $ord['id'];
Mutqan_Store::update('orders', $oid, ['technicianId' => 'u-tech', 'technicianName' => 'خالد', 'status' => 'en_route'], $ops);
$say = function ($p, $text, $chan = 'ops') use ($oid, $o) { return Mutqan_Store::insert('order_msgs', ['orderId' => $oid, 'channel' => $chan, 'kind' => 'text', 'text' => $text], null, $o, $p, false); };
$aiMsgs = function () use ($oid, $ops) { return array_values(array_filter(Mutqan_Store::select('order_msgs', ['orderId' => $oid, 'channel' => 'ops'], ['limit' => 100, 'order' => 'at'], $ops), function ($m) { return ($m['fromName'] ?? '') === Mutqan_Order_AI::NAME; })); };

$prompts = []; $next = ['reply' => 'وعليكم السلام، الفني خالد في الطريق إليك الآن.', 'handoff' => false, 'memory' => ['عنده مكيف سبليت يسرب ماء سابقًا'], 'order_summary' => 'العميل يسأل عن وصول الفني لعطل عدم التبريد.'];
$GLOBALS['__post_hook'] = function ($url, $a) use (&$prompts, &$next) {
    $b = json_decode($a['body'], true); $prompts[] = $b['messages'][0]['content'] ?? '';
    return ['code' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => json_encode($next, JSON_UNESCAPED_UNICODE)]]]]), 'headers' => []];
};

$t('on by default once a provider is set up', Mutqan_Order_AI::enabled());
$m = $say($cust, 'السلام عليكم');
Mutqan_Order_AI::reply($m, $m['id'], 0);
$ai = $aiMsgs();
$t('answers the customer in the customer-service channel', count($ai) === 1 && $ai[0]['from'] === 'ops' && strpos($ai[0]['text'], 'خالد') !== false, $ai);
$seen = Mutqan_Store::select('order_msgs', ['orderId' => $oid], ['limit' => 50], $cust, false);
$t('the customer can read the answer', count(array_filter($seen, function ($x) { return ($x['fromName'] ?? '') === Mutqan_Order_AI::NAME; })) === 1);
$p0 = $prompts[0] ?? '';
$t('the model sees the order: service, status, technician, description', strpos($p0, 'صيانة وتكييف') !== false && strpos($p0, 'الفني في الطريق') !== false && strpos($p0, 'خالد') !== false && strpos($p0, 'ما يبرد') !== false, mb_substr($p0, 0, 400));
$t('and the customer\'s earlier order with its price', strpos($p0, 'يسرب ماء') !== false && strpos($p0, '180') !== false);
$t('the phone number never reaches the model', strpos($p0, '0551234567') === false && strpos($p0, '551234567') === false);

$mem = Mutqan_Store::get_raw('_ai_memory', 'c:p551234567');
$t('remembers the customer by phone across orders', is_array($mem) && in_array('عنده مكيف سبليت يسرب ماء سابقًا', $mem['facts'] ?? [], true), $mem);
$t('keeps a running summary of the order', strpos((string) (Mutqan_Store::get_raw('_ai_memory', 'o:' . $oid)['summary'] ?? ''), 'عدم التبريد') !== false);

$next = ['reply' => 'تمام', 'memory' => ['يفضل الزيارة بعد العصر'], 'order_summary' => 'ملخص 2'];
$m2 = $say($cust, 'متى يوصل؟');
Mutqan_Order_AI::reply($m2, $m2['id'], 0);
$p1 = end($prompts);
$t('the next turn carries the memory and the summary back in', strpos($p1, 'عنده مكيف سبليت يسرب ماء سابقًا') !== false && strpos($p1, 'عدم التبريد') !== false);
$t('and the whole conversation so far, its own replies included', strpos($p1, 'السلام عليكم') !== false && strpos($p1, 'أنت (خدمة العملاء)') !== false);
$t('memory grows without repeating itself', count(Mutqan_Store::get_raw('_ai_memory', 'c:p551234567')['facts']) === 2);

$n = count($prompts);
$m3 = $say($cust, 'رسالة أولى'); $m4 = $say($cust, 'رسالة ثانية');
Mutqan_Order_AI::reply($m3, $m3['id'], 0);
$t('a burst of messages gets one answer: an older message is skipped', count($prompts) === $n);
$tm = $say($cust, 'للفني', 'tech');
Mutqan_Order_AI::on_message($tm);
$t('the technician channel is not its business', count($prompts) === $n);

$say($ops, 'أنا سارة من العمليات، معك.');
$m5 = $say($cust, 'شكرا');
Mutqan_Order_AI::reply($m5, $m5['id'], 0);
$t('once a person writes in the order, it stays quiet', count($prompts) === $n);

update_option(Mutqan_Order_AI::OPT, ['enabled' => 1, 'quiet_min' => 1]);
$GLOBALS['__now_shift'] = 0;
// age the human message beyond the quiet window
global $wpdb;
$rows = Mutqan_Store::select('order_msgs', ['orderId' => $oid, 'channel' => 'ops'], ['limit' => 100], $ops);
foreach ($rows as $r) { if (($r['fromName'] ?? '') === 'سارة') { Mutqan_Store::update('order_msgs', $r['id'], ['at' => gmdate('c', time() - 600)], $ops); } }
$next = ['reply' => 'نعتذر، زميلنا سيتابع معك الآن.', 'handoff' => true, 'handoff_reason' => 'العميل غاضب من التأخير', 'action' => 'nudge', 'memory' => [], 'order_summary' => 'شكوى تأخير'];
$before = count(Mutqan_Store::select('notifications', [], ['limit' => 500], $ops));
$m6 = $say($cust, 'تأخرتوا كثير وهذا غير مقبول');
Mutqan_Order_AI::reply($m6, $m6['id'], 0);
$notes = Mutqan_Store::select('notifications', [], ['limit' => 500, 'order' => '-createdAt'], $ops);
$titles = implode(' | ', array_map(function ($x) { return (string) ($x['title'] ?? ''); }, array_slice($notes, 0, 6)));
$t('after the quiet window it answers again', count($prompts) === $n + 1);
$t('a complaint is handed to the operations room with the reason', strpos($titles, 'يحتاج موظف') !== false, $titles);
$t('and a request to hurry reaches them as a nudge', strpos($titles, 'يستعجل') !== false, $titles);

$code = function ($fn) { try { $fn(); return 200; } catch (Mutqan_Error $e) { return $e->status; } };
$r = null; $c = $code(function () use (&$r, $cust) { $r = Mutqan_Store::select('_ai_memory', [], ['limit' => 10], $cust, false); });
$t('customers cannot read the assistant\'s memory', $c !== 200 || $r === [], [$c, $r]);

update_option(Mutqan_Order_AI::OPT, ['enabled' => 0, 'quiet_min' => 15]);
$t('the owner can switch it off', !Mutqan_Order_AI::enabled());
echo $ok ? "ALL PASSED\n" : "SOME FAILED\n";
exit($ok ? 0 : 1);
