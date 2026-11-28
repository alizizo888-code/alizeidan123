<?php
// «جسر Chatwoot»: our WhatsApp lines become Chatwoot API inboxes; every message mirrors in; an agent's reply leaves from the
// customer's own line and the AI steps back; no echo loop; off until set up; the webhook needs its secret.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-cw-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "chatwoot: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$W = 'Mutqan_WA'; $CW = 'Mutqan_Chatwoot';
$owner = ['kind' => 'user', 'role' => 'owner', 'id' => 'o1', 'name' => 'علي', 'powers' => ['read', 'write', 'manage']];
Mutqan_WA_Journey::set_enabled(false);
update_option(Mutqan_WA_AI::OPT, ['enabled' => 0]);   // the receptionist is tested elsewhere; here only the bridge

// a pretend Chatwoot
$calls = []; $nextId = 100;
$GLOBALS['__post_hook'] = function ($url, $a) use (&$calls, &$nextId) {
    if (strpos($url, 'chat.example.com') === false) { return null; }
    $b = json_decode($a['body'] ?? '', true); $path = parse_url($url, PHP_URL_PATH);
    $calls[] = ['path' => $path, 'body' => $b, 'token' => $a['headers']['api_access_token'] ?? ''];
    $id = $nextId++;
    if (preg_match('#/inbox_members$#', $path)) { $GLOBALS['__cw_members'][] = $b; return ['code' => 200, 'body' => '{}', 'headers' => []]; }
    if (preg_match('#/inboxes$#', $path)) { return ['code' => 200, 'body' => json_encode(['id' => 7]), 'headers' => []]; }
    if (preg_match('#/contacts$#', $path)) { return ['code' => 200, 'body' => json_encode(['payload' => ['contact' => ['id' => 55, 'contact_inboxes' => [['inbox' => ['id' => 7], 'source_id' => 'src-1']]]]]), 'headers' => []]; }
    if (preg_match('#/conversations$#', $path)) { static $conv = 899; $conv++; return ['code' => 200, 'body' => json_encode(['id' => $conv]), 'headers' => []]; }
    if (preg_match('#/messages$#', $path)) { $GLOBALS['__cw_last_msg'] = $id; return ['code' => 200, 'body' => json_encode(['id' => $id]), 'headers' => []]; }
    return ['code' => 404, 'body' => '{}', 'headers' => []];
};
$GLOBALS['__get_hook'] = function ($url, $a) { if (strpos($url, 'chat.example.com') === false) { return null; } if (strpos($url, '/agents') !== false) { return ['code' => 200, 'body' => json_encode([['id' => 1], ['id' => 4]]), 'headers' => []]; } return ($a['headers']['api_access_token'] ?? '') === 'tok-good' ? ['code' => 200, 'body' => json_encode(['payload' => [['id' => 7]]]), 'headers' => []] : ['code' => 401, 'body' => '{}', 'headers' => []]; };

$D = $W::handle(['op' => 'fleet_register', 'name' => 'VPS', 'kind' => 'vps'], $owner);
$pull = function () use ($W, $D) { $raw = json_encode(['device' => $D['id'], 'sessions' => []]); return $W::pull_signed($raw, hash_hmac('sha256', $raw, $D['secret']))['jobs'] ?? []; };
$pull();
$L1 = $W::handle(['op' => 'link', 'phone' => '0501110001', 'role' => 'customers'], $owner);
$hook = function ($ev) use ($W, $D) { $raw = json_encode($ev, JSON_UNESCAPED_UNICODE); return $W::webhook($raw, hash_hmac('sha256', $raw, $D['secret'])); };
$hook(['type' => 'status', 'accountId' => $L1['id'], 'status' => 'open', 'phone' => '966501110001']);

// off: nothing goes to Chatwoot
$hook(['type' => 'message', 'accountId' => $L1['id'], 'msgId' => 'm0', 'direction' => 'in', 'phone' => '966555111000', 'text' => 'قبل الربط', 'ts' => time()]);
$t('off by default: nothing is sent to Chatwoot', !$calls && !$CW::ready());

// settings: owner only, token never shown, bad token caught by the test
$v = $W::handle(['op' => 'chatwoot_set', 'url' => 'https://chat.example.com/', 'account' => 3, 'token' => 'tok-bad', 'enabled' => 1], $owner);
$t('settings saved; the token is never shown back', $v['url'] === 'https://chat.example.com' && $v['hasToken'] && !isset($v['token']) && strpos($v['webhook'], '/chatwoot?k=') !== false, $v);
$err = ''; try { $W::handle(['op' => 'chatwoot_test'], $owner); } catch (Mutqan_Error $e) { $err = $e->getMessage(); }
$t('a wrong token is reported in plain words', strpos($err, 'المفتاح') !== false, $err);
$W::handle(['op' => 'chatwoot_set', 'token' => 'tok-good'], $owner);
$t('the right token passes the test', ($W::handle(['op' => 'chatwoot_test'], $owner)['ok'] ?? false) === true);
$denied = false; try { $W::handle(['op' => 'chatwoot_set', 'enabled' => 0], ['kind' => 'user', 'role' => 'technician', 'id' => 'tc', 'powers' => ['read']]); } catch (Mutqan_Error $e) { $denied = true; }
$t('a technician cannot change it', $denied);

// a customer writes: inbox + contact + conversation + card note + the message
$calls = [];
$hook(['type' => 'message', 'accountId' => $L1['id'], 'msgId' => 'm1', 'direction' => 'in', 'phone' => '966555222333', 'name' => 'فهد', 'text' => 'السلام عليكم المكيف خربان', 'ts' => time()]);
$paths = array_column($calls, 'path');
$t('the line becomes an API inbox with our webhook', ($calls[0]['path'] ?? '') === '/api/v1/accounts/3/inboxes' && ($calls[0]['body']['channel']['type'] ?? '') === 'api' && strpos($calls[0]['body']['channel']['webhook_url'] ?? '', '/chatwoot?k=') !== false && $calls[0]['token'] === 'tok-good', $calls[0] ?? null);
$t('the team is added to the new inbox (so it shows in their app)', ($GLOBALS['__cw_members'][0]['inbox_id'] ?? 0) === 7 && ($GLOBALS['__cw_members'][0]['user_ids'] ?? []) === [1, 4], $GLOBALS['__cw_members'] ?? null);
$calls = array_values(array_filter($calls, function ($c) { return !preg_match('#/inbox_members$#', $c['path']); }));
$t('the customer becomes a contact with his number', ($calls[1]['body']['phone_number'] ?? '') === '+966555222333' && ($calls[1]['body']['name'] ?? '') === 'فهد', $calls[1] ?? null);
$msgs = array_values(array_filter($calls, function ($c) { return preg_match('#/messages$#', $c['path']); }));
$t('the conversation gets a private identity card first', $msgs && !empty($msgs[0]['body']['private']) && strpos($msgs[0]['body']['content'], 'كارت المحادثة') !== false, $msgs);
$t('then his message, as incoming', count($msgs) === 2 && $msgs[1]['body']['message_type'] === 'incoming' && strpos($msgs[1]['body']['content'], 'المكيف خربان') !== false, $msgs);

// our own reply (a staff send from the app) mirrors as outgoing, signed, into the same conversation
$calls = [];
$W::send($L1['id'], '966555222333', 'أهلًا فهد، الفني جاي', 'staff', 'سارة');
foreach ($pull() as $j) { }
$raw = json_encode(['device' => $D['id'], 'sessions' => [], 'sent' => array_map(function ($j) { return ['id' => $j['id'], 'ok' => true, 'msgId' => 'x' . $j['id']]; }, Mutqan_Store::select('wa_outbox', ['state' => 'taken'], ['limit' => 10], $owner))]);
$W::pull_signed($raw, hash_hmac('sha256', $raw, $D['secret']));
$out = array_values(array_filter($calls, function ($c) { return preg_match('#/conversations/900/messages$#', $c['path']); }));
$t('a reply sent from here shows in Chatwoot as outgoing, with who sent it', $out && $out[0]['body']['message_type'] === 'outgoing' && strpos($out[0]['body']['content'], 'سارة') !== false, $calls);
$tidF = $W::tid($L1['id'], '966555222333');
$t('the conversation is not made twice', !array_filter($calls, function ($c) use ($tidF) { return preg_match('#/conversations$#', $c['path']) && ($c['body']['additional_attributes']['mutqan_thread'] ?? '') === $tidF; }));

// an agent answers in Chatwoot → it leaves from the customer's own line, the AI steps back, no loop
$key = Mutqan_Chatwoot::settings()['secret'];
$bad = false; try { $CW::webhook('{}', 'wrong'); } catch (Mutqan_Error $e) { $bad = $e->status === 403; }
$t('the webhook refuses a wrong key', $bad);
$calls = [];
$r = $CW::webhook(json_encode(['event' => 'message_created', 'id' => 5001, 'message_type' => 'outgoing', 'private' => false, 'content' => 'تمام يا فهد، هنكون عندك 5 العصر', 'conversation' => ['id' => 900], 'sender' => ['name' => 'محمد']], JSON_UNESCAPED_UNICODE), $key);
$jobs = $pull();
$j = array_values(array_filter($jobs, function ($x) { return substr($x['to'], -9) === '555222333'; }));
$t('an agent\'s reply goes out on WhatsApp from the line the customer wrote to', ($r['sent'] ?? false) && $j && $j[0]['acct'] === $L1['id'] && strpos($j[0]['text'], '5 العصر') !== false, [$r, $jobs]);
$th = Mutqan_Store::get_raw('wa_threads', $W::tid($L1['id'], '966555222333'));
$t('…and the AI receptionist steps back from that conversation', ($th['bot']['pausedUntil'] ?? 0) > time() + 3600, $th['bot'] ?? null);
$raw = json_encode(['device' => $D['id'], 'sessions' => [], 'sent' => [['id' => $j[0]['id'], 'ok' => true, 'msgId' => 'cw1']]]);
$W::pull_signed($raw, hash_hmac('sha256', $raw, $D['secret']));
$t('what came from Chatwoot is not mirrored back (no loop)', !array_filter($calls, function ($c) { return preg_match('#/messages$#', $c['path']); }), $calls);
$t('our own posts echoed by Chatwoot are ignored', ($CW::webhook(json_encode(['event' => 'message_created', 'id' => $GLOBALS['__cw_last_msg'], 'message_type' => 'outgoing', 'content' => 'x', 'conversation' => ['id' => 900]]), $key)['ignored'] ?? '') === 'echo');
$t('private notes and incoming events are ignored', ($CW::webhook(json_encode(['event' => 'message_created', 'id' => 6001, 'message_type' => 'outgoing', 'private' => true, 'content' => 'ملاحظة', 'conversation' => ['id' => 900]]), $key)['ignored'] ?? '') === 'type' && ($CW::webhook(json_encode(['event' => 'message_created', 'id' => 6002, 'message_type' => 'incoming', 'content' => 'x', 'conversation' => ['id' => 900]]), $key)['ignored'] ?? '') === 'type');

// Chatwoot down: WhatsApp carries on
$GLOBALS['__post_hook'] = function ($url, $a) { return strpos($url, 'chat.example.com') !== false ? new WP_Error('down', 'timeout') : null; };
$hook(['type' => 'message', 'accountId' => $L1['id'], 'msgId' => 'm9', 'direction' => 'in', 'phone' => '966555222333', 'text' => 'لسه مستني', 'ts' => time()]);
$t('with Chatwoot down the WhatsApp message is still stored', (bool) array_filter(Mutqan_Store::select('wa_msgs', ['threadId' => $W::tid($L1['id'], '966555222333')], ['limit' => 20], $owner), function ($m) { return ($m['text'] ?? '') === 'لسه مستني'; }));

// …and it is not lost either: a refused mirror waits in the queue and goes in when Chatwoot comes back
$q = get_option($CW::Q, []);
$t('a mirror Chatwoot refused waits in the queue instead of being dropped', count($q) === 1 && ($q[0]['m']['text'] ?? '') === 'لسه مستني' && (int) $q[0]['tries'] === 1, $q);
$GLOBALS['__cw_retried'] = [];
$up = function ($url, $a) {
    if (strpos($url, 'chat.example.com') === false) { return null; }
    $path = parse_url($url, PHP_URL_PATH);
    if (preg_match('#/inboxes$#', $path)) { return ['code' => 200, 'body' => json_encode(['id' => 7]), 'headers' => []]; }
    if (preg_match('#/contacts$#', $path)) { return ['code' => 200, 'body' => json_encode(['payload' => ['contact' => ['id' => 55, 'contact_inboxes' => [['inbox' => ['id' => 7], 'source_id' => 'src-1']]]]]), 'headers' => []]; }
    if (preg_match('#/conversations$#', $path)) { return ['code' => 200, 'body' => json_encode(['id' => 951]), 'headers' => []]; }
    if (preg_match('#/messages$#', $path)) { $GLOBALS['__cw_retried'][] = json_decode($a['body'] ?? '', true); return ['code' => 200, 'body' => json_encode(['id' => 7001]), 'headers' => []]; }
    return ['code' => 200, 'body' => '{}', 'headers' => []];
};
$GLOBALS['__post_hook'] = $up;
$r = $CW::drain();
$sentTexts = array_map(function ($b) { return (string) ($b['content'] ?? ''); }, $GLOBALS['__cw_retried']);
$t('when Chatwoot is back the retry sends it and the queue empties', ($r['sent'] ?? 0) === 1 && ($r['left'] ?? -1) === 0 && in_array('لسه مستني', $sentTexts, true) && !get_option($CW::Q, []), [$r, $sentTexts]);

// a message Chatwoot keeps refusing is given up on after 3 tries, so the queue can never pile up forever
$GLOBALS['__post_hook'] = function ($url, $a) { return strpos($url, 'chat.example.com') !== false ? new WP_Error('down', 'timeout') : null; };
$hook(['type' => 'message', 'accountId' => $L1['id'], 'msgId' => 'm10', 'direction' => 'in', 'phone' => '966555222333', 'text' => 'مرفوضة دايمًا', 'ts' => time()]);
$t('the second refusal is queued too', count(get_option($CW::Q, [])) === 1);
$tries = [];
for ($k = 0; $k < 3; $k++) { $CW::drain(); $tries[] = count(get_option($CW::Q, [])); }
$t('…and it is dropped after 3 tries, not kept for ever', $tries === [1, 1, 0], $tries);
$GLOBALS['__post_hook'] = $up;

// القاطع: بعد 5 فشل ورا بعض يبطّل يحاول خالص — من غير ما يوقف أي حاجة وChatwoot شغال
delete_option('mutqan_chatwoot_down'); update_option('mutqan_chatwoot_queue', []);
$GLOBALS['__post_hook'] = function ($url, $a) { return strpos($url, 'chat.example.com') !== false ? new WP_Error('down', 'timeout') : null; };
$grew = [];
for ($k = 0; $k < 8; $k++) {
    $hook(['type' => 'message', 'accountId' => $L1['id'], 'msgId' => 'brk' . $k, 'direction' => 'in', 'phone' => '966555222333', 'text' => 'ب' . $k, 'ts' => time()]);
    $grew[] = count(get_option('mutqan_chatwoot_queue', []));
}
$t('القاطع بيوقف المحاولات بعد 5 فشل ورا بعض', $grew[4] === 5 && $grew[7] === 5, $grew);
// ولما Chatwoot يرجع، أول نجاح يفتح القاطع تاني
delete_option('mutqan_chatwoot_down');
$GLOBALS['__post_hook'] = $up;
$hook(['type' => 'message', 'accountId' => $L1['id'], 'msgId' => 'brk9', 'direction' => 'in', 'phone' => '966555222333', 'text' => 'رجع', 'ts' => time()]);
$t('ولما يرجع بيبعت عادي ومفيش حاجة بتتحبس', !get_option('mutqan_chatwoot_down'), get_option('mutqan_chatwoot_down'));
update_option('mutqan_chatwoot_queue', []);

// the one-time link from the Chatwoot server: saves the token, tests it, switches the bridge on
$GLOBALS['__get_hook'] = function ($url, $a) { return strpos($url, 'chat.example.com') !== false ? (($a['headers']['api_access_token'] ?? '') === 'tok-linked-123' ? ['code' => 200, 'body' => '{"payload":[]}', 'headers' => []] : ['code' => 401, 'body' => '{}', 'headers' => []]) : null; };
$W::handle(['op' => 'chatwoot_set', 'enabled' => 0], $owner);
$r = $CW::webhook(json_encode(['event' => 'mutqan_link', 'token' => 'tok-linked-123', 'account' => 1]), $key);
$v = $CW::view();
$t('the server-side link saves the key, tests it and switches the bridge on', ($r['enabled'] ?? false) && $v['enabled'] === 1 && $v['account'] === 1 && $v['hasToken'], [$r, $v]);
$bad = false; try { $CW::webhook(json_encode(['event' => 'mutqan_link', 'token' => 'x']), 'nope'); } catch (Mutqan_Error $e) { $bad = true; }
$t('…only with the bridge secret', $bad);

echo $ok ? "chatwoot: all passed\n" : "chatwoot: FAILED\n";
exit($ok ? 0 : 1);
