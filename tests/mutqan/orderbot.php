<?php
// «مساعد الطلب»: the customer writes in his order's conversation, and something answers — from the order's
// own facts, asking the technician when the facts run out, and passing requests on to him and to the room.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'orderbot: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$B = 'Mutqan_OrderBot';
$sys = ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']];
$client = ['kind' => 'user', 'role' => 'client', 'id' => 'c1', 'powers' => []];
$other = ['kind' => 'user', 'role' => 'client', 'id' => 'c2', 'powers' => []];
$tech = ['kind' => 'user', 'role' => 'technician', 'id' => 't1', 'name' => 'أحمد', 'powers' => ['read']];
Mutqan_Store::upsert('tech_profiles', ['userId' => 't1', 'displayName' => 'أحمد', 'phone' => '0555555555', 'lat' => 21.42, 'lng' => 39.83, 'atAt' => time() - 120], 't1', [], $sys);

$o = Mutqan_Store::insert('orders', ['specialtyId' => 'ac', 'clientName' => 'خالد', 'clientPhone' => '0500000009', 'address' => 'العزيزية مكة', 'lat' => 21.39, 'lng' => 39.86, 'status' => 'new'], null, [], $client);
$oid = $o['id'];
$say = function ($text, $who = null) use ($oid, $client) { return Mutqan_Store::insert('order_msgs', ['orderId' => $oid, 'channel' => 'ops', 'kind' => 'text', 'text' => $text], null, ['visibleTo' => 'group'], $who ?: $client); };
$msgs = function ($chan) use ($oid, $sys) { return Mutqan_Store::select('order_msgs', ['orderId' => $oid, 'channel' => $chan], ['order' => 'at', 'limit' => 200], $sys); };
$lastAi = function () use ($msgs) { $a = array_values(array_filter($msgs('ops'), function ($m) { return ($m['from'] ?? '') === 'ai'; })); return $a ? end($a) : null; };

/* ---------------------------------------------------------------- who may ask */
try { $B::handle(['orderId' => $oid], $other); $t('another customer cannot drive this order\'s assistant', false); }
catch (Mutqan_Error $e) { $t('another customer cannot drive this order\'s assistant', $e->status === 403); }

/* ---------------------------------------------------------------- reading what he wants */
$t('«الفني فين؟» is where', $B::intent('الفني فين؟') === 'where');
$t('«فاضل له قد ايه» is where', $B::intent('فاضل له قد ايه') === 'where');
$t('«اتأخر كدا ليه» is urgent', $B::intent('اتأخر كدا ليه') === 'urgent');
// live test 2026-11-23: a complaint about the technician is a complaint (to the owner), never a «request» for him
$t('«الفني اتعامل معايا بقلة ذوق وعلّى صوته» is a complaint', $B::intent('الفني اتعامل معايا بقلة ذوق وعلّى صوته عليا، أنا عايز أشتكي') === 'complaint');
$t('«الفني اتعارك معايا» is a complaint', $B::intent('الفني اتعارك معايا') === 'complaint');
$t('«عايز رقم الفني أكلمه» is the phone', $B::intent('عايز رقم الفني أكلمه') === 'phone');
$t('«هات معاك سلم» is still not a complaint', $B::intent('هات معاك سلم') !== 'complaint');
$t('«ممكن نأجل لبكرة» is postpone', $B::intent('ممكن تأجيل لبكرة') === 'postpone');
$t('«خليه يجيب معاه خرطوم» is a request for the technician', $B::intent('خليه يجيب معاه خرطوم') === 'request');
$t('«الضمان باقي كام» is warranty', $B::intent('الضمان باقي كام') === 'warranty');
$t('«الضمان لحد امتى» is warranty, not where', $B::intent('الضمان لحد امتى؟') === 'warranty');

/* ---------------------------------------------------------------- a status question, before any technician */
$say('السلام عليكم ايه الاخبار؟');
$r = $B::handle(['orderId' => $oid], $client);
$ai = $lastAi();
$t('the customer gets an answer in the same conversation', $ai && ($ai['text'] ?? '') !== '' && ($ai['from'] ?? '') === 'ai', $r);
$t('the answer is the order\'s real step', strpos($ai['text'], 'استلام الطلب') !== false, $ai['text']);
$r2 = $B::handle(['orderId' => $oid], $client);
$t('a message is answered once, not on every refresh', !empty($r2['skipped']));
$t('the assistant cannot be made to forge a customer line', ($ai['from'] ?? '') !== 'client');

/* ---------------------------------------------------------------- the technician is on the way: ask him */
Mutqan_Store::update('orders', $oid, ['technicianId' => 't1', 'technicianName' => 'أحمد', 'status' => 'en_route'], $sys);
$say('الفني فين؟ فاضل له قد ايه');
$B::handle(['orderId' => $oid], $client);
$ask = array_values(array_filter($msgs('tech'), function ($m) { return ($m['kind'] ?? '') === 'eta_ask'; }));
$t('with no estimate yet, the technician is asked «فاضل لك قد إيه؟»', count($ask) === 1, $msgs('tech'));
$push = array_filter(Mutqan_Store::select('_push', [], ['limit' => 100], $sys), function ($x) { return ($x['target']['user'] ?? '') === 't1' && ($x['event'] ?? '') === 'order_bot'; });
$t('and he is pushed on his phone', (bool) $push);
$wa = Mutqan_Store::select('wa_outbox', [], ['limit' => 50], $sys);
$t('and on WhatsApp (queued for the gateway)', (bool) array_filter($wa, function ($x) { return strpos((string) ($x['text'] ?? ''), 'فاضل لك') !== false; }), $wa);
$ai = $lastAi();
$t('the customer is told the distance from the technician\'s last position', strpos($ai['text'], 'كم') !== false, $ai['text']);
$say('طيب فين؟');
$B::handle(['orderId' => $oid], $client);
$t('asked again within ten minutes, the technician is not asked twice', count(array_filter($msgs('tech'), function ($m) { return ($m['kind'] ?? '') === 'eta_ask'; })) === 1);

/* ---------------------------------------------------------------- he answers: it goes straight back */
$m = Mutqan_Store::insert('order_msgs', ['orderId' => $oid, 'channel' => 'tech', 'kind' => 'eta', 'text' => '', 'minutes' => 20], null, ['visibleTo' => 'group'], $tech);
$t('the technician\'s estimate is kept in minutes', (int) ($m['minutes'] ?? 0) === 20, $m);
$rm = new ReflectionMethod('Mutqan_Rest', 'after_order_msg'); $rm->setAccessible(true); $rm->invoke(null, $m);
$ord = Mutqan_Store::get_raw('orders', $oid);
$t('the order remembers it', (int) ($ord['techEta']['minutes'] ?? 0) === 20, $ord['techEta'] ?? null);
$t('and the customer\'s conversation hears it at once', strpos((string) ($lastAi()['text'] ?? ''), '20 دقيقة') !== false, $lastAi());
$say('هيوصل امتى؟');
$B::handle(['orderId' => $oid], $client);
$t('the next question is answered from his estimate, never an invented one', strpos($lastAi()['text'], '20') !== false || strpos($lastAi()['text'], '19') !== false, $lastAi()['text']);

/* ---------------------------------------------------------------- requests, urgency, postpone */
$say('خليه يجيب معاه سلم كبير');
$B::handle(['orderId' => $oid], $client);
$t('a request is passed to the technician word for word', (bool) array_filter($msgs('tech'), function ($m) { return strpos((string) ($m['text'] ?? ''), 'سلم كبير') !== false; }));
$say('عايز أأجل الموعد لبكرة الصبح');
$B::handle(['orderId' => $oid], $client);
$t('a postpone becomes a postpone request for the room', (bool) array_filter($msgs('ops'), function ($m) { return ($m['kind'] ?? '') === 'postpone_req'; }));
$notes = Mutqan_Store::select('notifications', [], ['limit' => 100], $sys);
$t('the operations room is alerted', (bool) array_filter($notes, function ($n) { return ($n['event'] ?? '') === 'order_postpone_req'; }));
$t('and the customer is told it waits for confirmation — not that it moved', strpos($lastAi()['text'], 'العمليات') !== false, $lastAi()['text']);

/* ---------------------------------------------------------------- every move reaches WhatsApp */
$before = count(Mutqan_Store::select('wa_outbox', [], ['limit' => 200], $sys));
Mutqan_Store::update('orders', $oid, ['status' => 'arrived'], $sys);
$after = Mutqan_Store::select('wa_outbox', [], ['limit' => 200, 'order' => '-createdAt'], $sys);
$hit = array_values(array_filter($after, function ($x) { return strpos((string) ($x['text'] ?? ''), 'وصل لموقعك') !== false; }));
$t('the customer gets «arrived» on WhatsApp', count($after) > $before && $hit, $after[0] ?? null);
$t('with the steps, the technician and the link back to the order', $hit && strpos($hit[0]['text'], '●') !== false && strpos($hit[0]['text'], 'أحمد') !== false && strpos($hit[0]['text'], '/#/order/' . $oid) !== false, $hit[0]['text'] ?? '');
Mutqan_Store::update('orders', $oid, ['status' => 'arrived', 'staffNote' => 'x'], $sys);
$t('the same move is not sent twice', count(Mutqan_Store::select('wa_outbox', [], ['limit' => 200], $sys)) === count($after));

/* ---------------------------------------------------------------- done: warranty and payment */
Mutqan_Store::update('orders', $oid, ['status' => 'completed', 'completedAt' => gmdate('c', time() - 5 * 86400), 'price' => 250], $sys);
$f = $B::facts(Mutqan_Store::get_raw('orders', $oid) + ['id' => $oid]);
$t('warranty runs from the completion time', $f['warranty'] && $f['warranty']['active'] && $f['warranty']['left'] === 25, $f['warranty']);
$say('الضمان لحد امتى؟');
$B::handle(['orderId' => $oid], $client);
$t('the warranty question is answered with the days left', strpos($lastAi()['text'], '25') !== false, $lastAi()['text']);
$st = $B::status_text($f);
$t('once he has arrived or finished, an old estimate is no longer quoted', $f['eta'] === null);
$t('the WhatsApp summary carries the warranty', strpos($st, 'الضمان ساري') !== false, $st);

/* ---------------------------------------------------------------- with the conversational assistant on: no second answer */
$o2 = Mutqan_Store::insert('orders', ['specialtyId' => 'ac', 'clientName' => 'خالد', 'clientPhone' => '0500000009', 'status' => 'en_route', 'technicianId' => 't1', 'technicianName' => 'أحمد'], null, [], $client);
Mutqan_Store::update('orders', $o2['id'], ['technicianId' => 't1', 'technicianName' => 'أحمد', 'status' => 'en_route'], $sys);
update_option('mutqan_order_ai', ['enabled' => 1]);
update_option(Mutqan_AI::OPT, ['providers' => ['groq' => ['enabled' => 1, 'key' => 'k', 'model' => 'm']]]);
if (class_exists('Mutqan_Order_AI') && Mutqan_Order_AI::enabled()) {
    Mutqan_Store::insert('order_msgs', ['orderId' => $o2['id'], 'channel' => 'ops', 'kind' => 'text', 'text' => 'الفني فين؟'], null, ['visibleTo' => 'group'], $client);
    $r = $B::handle(['orderId' => $o2['id']], $client);
    $ais = array_filter(Mutqan_Store::select('order_msgs', ['orderId' => $o2['id'], 'channel' => 'ops'], ['limit' => 50], $sys), function ($m) { return ($m['from'] ?? '') === 'ai'; });
    $t('with the conversational assistant on, this one does not answer too', !empty($r['deferred']) && !$ais, $r);
    $asked = array_filter(Mutqan_Store::select('order_msgs', ['orderId' => $o2['id'], 'channel' => 'tech'], ['limit' => 50], $sys), function ($m) { return ($m['kind'] ?? '') === 'eta_ask'; });
    $t('but it still asks the technician how long', count($asked) === 1);
} else { $t('the conversational assistant is present in this build', false); }

echo $ok ? "orderbot: all passed\n" : "orderbot: FAILURES\n";
exit($ok ? 0 : 1);
