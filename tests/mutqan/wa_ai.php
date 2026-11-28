<?php
// «موظف الاستقبال الذكي»: the AI answers WhatsApp customers (no numbered menu), gathers the order step by step and
// registers it when complete, hands over to a person on a complaint, and never leaves a customer unanswered when
// the model is down.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-waai-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "wa_ai: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$s = Mutqan_AI::settings(); $s['providers']['groq'] = array_merge($s['providers']['groq'], ['enabled' => 1, 'key' => 'gsk_test']); update_option('mutqan_ai_settings', $s);
Mutqan_WA_Journey::set_enabled(false);
$W = 'Mutqan_WA';
$owner = ['kind' => 'user', 'role' => 'owner', 'id' => 'o1', 'name' => 'علي', 'powers' => ['read', 'write', 'manage']];
$D = $W::handle(['op' => 'fleet_register', 'name' => 'VPS', 'kind' => 'vps'], $owner);
$pull = function () use ($W, $D) { $raw = json_encode(['device' => $D['id'], 'sessions' => []]); return $W::pull_signed($raw, hash_hmac('sha256', $raw, $D['secret']))['jobs'] ?? []; };
$pull();
$L1 = $W::handle(['op' => 'link', 'phone' => '0501110001', 'role' => 'customers'], $owner);
$hook = function ($ev) use ($W, $D) { $raw = json_encode($ev, JSON_UNESCAPED_UNICODE); return $W::webhook($raw, hash_hmac('sha256', $raw, $D['secret'])); };
$hook(['type' => 'status', 'accountId' => $L1['id'], 'status' => 'open', 'phone' => '966501110001']);
$to = function ($js, $tail) { return array_values(array_filter($js, function ($j) use ($tail) { return substr(preg_replace('/\D/', '', $j['to']), -9) === $tail; })); };

$prompts = []; $next = null; $down = false;
$GLOBALS['__post_hook'] = function ($url, $a) use (&$prompts, &$next, &$down) {
    if ($down) { return ['code' => 503, 'body' => '{"error":"down"}', 'headers' => []]; }
    $b = json_decode($a['body'], true); $prompts[] = $b['messages'][0]['content'] ?? '';
    return ['code' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => json_encode($next, JSON_UNESCAPED_UNICODE)]]]]), 'headers' => []];
};
$i = 0;
$say = function ($text) use ($hook, $L1, &$i) { $i++; $hook(['type' => 'message', 'accountId' => $L1['id'], 'msgId' => 'a' . $i, 'direction' => 'in', 'phone' => '966555222333', 'name' => 'فهد', 'text' => $text, 'ts' => time()]); };

$t('on by default', Mutqan_WA_AI::enabled());
$next = ['reply' => 'وعليكم السلام يا فهد 🌷 تحت أمرك، المكيف فيه إيه بالظبط؟', 'lead' => ['service' => 'صيانة مكيف'], 'complete' => false, 'handoff' => false];
$say('السلام عليكم المكيف خربان');
$c = $to($pull(), '555222333');
$t('the customer gets a human-like AI answer, not a numbered menu', $c && strpos($c[0]['text'], 'تحت أمرك') !== false && strpos($c[0]['text'], '1️⃣') === false, $c);
$p = end($prompts);
$t('the model sees the conversation and the order rules', strpos($p, 'المكيف خربان') !== false && strpos($p, '"complete"') !== false, mb_substr($p, 0, 300));
$t('no order yet with only the service', !Mutqan_Store::select('orders', ['source' => 'whatsapp'], ['limit' => 5], $owner));

$next = ['reply' => 'تمام، سجلت طلبك ✅ وهيوصلك التأكيد حالًا.', 'lead' => ['area' => 'العزيزية', 'time' => 'بكرة العصر', 'name' => 'فهد'], 'complete' => true, 'handoff' => false];
$say('في العزيزية بكرة العصر');
$p = end($prompts);
$t('what was gathered before is kept for the next turn', strpos($p, 'صيانة مكيف') !== false, mb_substr($p, 0, 600));
$os = Mutqan_Store::select('orders', ['source' => 'whatsapp'], ['limit' => 5], $owner);
$c = $to($pull(), '555222333');
$t('…a neighbourhood name is not enough: no order, the pin itself is asked for', !$os && $c && mb_strpos(end($c)['text'], 'اللوكيشن نفسه') !== false, [$os, $c]);
$say('https://maps.google.com/?q=21.4225,39.8262');
$os = Mutqan_Store::select('orders', ['source' => 'whatsapp'], ['limit' => 5], $owner);
$t('…the pin arrives: the order is registered with it', count($os) === 1 && abs((float) ($os[0]['lat'] ?? 0) - 21.4225) < 1e-4, $os);
$t('a complete order becomes a real order', count($os) === 1 && $os[0]['service'] === 'صيانة مكيف' && strpos($os[0]['address'], 'العزيزية') !== false && ($os[0]['preferredTime'] ?? '') === 'بكرة العصر', $os);
$pull();

$next = ['reply' => 'حقك علينا، المسؤول هيكلمك حالًا.', 'lead' => [], 'complete' => false, 'handoff' => true, 'handoff_reason' => 'شكوى من التأخير'];
$say('انتو نصابين محدش جه');
$th = Mutqan_Store::get_raw('wa_threads', $W::tid($L1['id'], '966555222333'));
$t('a complaint hands the conversation to a person (bot pauses)', ($th['bot']['pausedUntil'] ?? 0) > time(), $th['bot'] ?? null);
$pull();

// the model is down: a holding line, never silence
$down = true;
$hook(['type' => 'message', 'accountId' => $L1['id'], 'msgId' => 'z1', 'direction' => 'in', 'phone' => '966555999000', 'text' => 'بكم غسيل المكيف؟', 'ts' => time()]);
$c = $to($pull(), '555999000');
$t('with the AI down the customer still gets a reply', $c && mb_strlen($c[0]['text']) > 5, $c);

// the real person: a picture he sends is kept and shown; his profile picture is copied to the site
$down = false; $next = ['reply' => 'وصلت الصورة 👍', 'lead' => [], 'complete' => false, 'handoff' => false];
$png = base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$hook(['type' => 'message', 'accountId' => $L1['id'], 'msgId' => 'img1', 'direction' => 'in', 'phone' => '966555777888', 'text' => 'المكيف كده', 'kind' => 'image', 'ts' => time(), 'media' => ['mime' => 'image/png', 'thumb' => $png, 'data' => $png]]);
$ms = Mutqan_Store::select('wa_msgs', ['threadId' => $W::tid($L1['id'], '966555777888')], ['limit' => 5], $owner);
$im = array_values(array_filter($ms, function ($m) { return ($m['kind'] ?? '') === 'image'; }));
$path = $im ? str_replace('http://127.0.0.1:8099/uploads', $GLOBALS['__dir'] . '/uploads', $im[0]['mediaUrl'] ?? '') : '';
$t('a picture the customer sends is saved and linked on the message', $im && !empty($im[0]['mediaUrl']) && is_file($path) && strpos($im[0]['thumb'] ?? '', 'data:image/jpeg;base64,') === 0, $im);
$t('a file type that is not allowed is not written', Mutqan_WA::save_media(['mime' => 'application/x-php', 'data' => base64_encode('<?php echo 1;')], 'document', 'x') === null);

// a few seconds' drop is not news; a number logged out or down five minutes is
$alerts = function () use ($owner) { return array_values(array_filter(Mutqan_Store::select('_push', [], ['limit' => 200], ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read','write','manage']]), function ($x) { return ($x['title'] ?? '') === 'تنبيه واتساب'; })); };
$n0 = count($alerts());
$hook(['type' => 'status', 'accountId' => $L1['id'], 'status' => 'closed']);
$hook(['type' => 'status', 'accountId' => $L1['id'], 'status' => 'open', 'phone' => '966501110001']);
Mutqan_WA::down_check();
$t('a reconnect within seconds sends no alert', count($alerts()) === $n0, $alerts());
$hook(['type' => 'status', 'accountId' => $L1['id'], 'status' => 'closed']);
set_transient('mq_wa_down_' . $L1['id'], time() - 400, DAY_IN_SECONDS);
Mutqan_WA::down_check(); Mutqan_WA::down_check();
$t('down five minutes: one alert, by the number\'s name', count($alerts()) === $n0 + 1 && ($al = $alerts()) && strpos(end($al)['body'] ?? '', $L1['id']) === false, $alerts());

// a guest order from the website gets its customer's number from the «رقم الطلب» WhatsApp message
Mutqan_WA_Journey::set_enabled(true);
$g = Mutqan_Store::insert('orders', ['status' => 'new', 'clientName' => '', 'clientPhone' => '', 'clientType' => 'guest', 'specialtyId' => 'ac', 'address' => 'العزيزية', 'preferredTime' => 'بكرة'], null, [], ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user']);
$pull();
$ref = substr($g['id'], -5);
$hook(['type' => 'message', 'accountId' => $L1['id'], 'msgId' => 'claim1', 'direction' => 'in', 'phone' => '966555444333', 'name' => 'أبو ريان', 'text' => "🔔 *طلب جديد — أكسجين*\nرقم الطلب: #$ref\n🛠️ الخدمة: غسيل", 'ts' => time()]);
$go = Mutqan_Store::get_raw('orders', $g['id']);
$t('the WhatsApp «رقم الطلب» message gives the guest order its number and name', substr($go['clientPhone'] ?? '', -9) === '555444333' && ($go['clientName'] ?? '') === 'أبو ريان', $go);
$c = $to($pull(), '555444333');
$t('…and «حلقة الوصل» confirms it with him at once', $c && strpos($c[0]['text'], 'وصلنا طلبك رقم') !== false, $c);
Mutqan_WA_Journey::set_enabled(false);

echo $ok ? "wa_ai: all passed\n" : "wa_ai: FAILED\n";
exit($ok ? 0 : 1);
