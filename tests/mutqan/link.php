<?php
// Hotline link code (paired devices, presence, public state), Telegram bridge (setup, pairing, team alerts, customer chat) and the desk's WordPress doors.
// Every code / token below is made up for the test — never a real one.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-link-' . getmypid());
require __DIR__ . '/wpshim.php';
require dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/class-oxygen-desk.php';
Mutqan_Store::maybe_install();
Mutqan_Rest::routes();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "link: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$owner = ['id' => 'u-owner', 'role' => 'owner', 'kind' => 'user', 'powers' => ['read', 'write', 'manage'], 'name' => 'Ali'];
$ops = ['id' => 'u-ops', 'role' => 'operations', 'kind' => 'user', 'powers' => ['read', 'write', 'manage'], 'name' => 'Sara'];
$tech = ['id' => 'u-tech', 'role' => 'technician', 'kind' => 'user', 'powers' => [], 'name' => 't'];
$cust = ['id' => 'u-cust', 'role' => null, 'kind' => 'user', 'powers' => [], 'name' => 'c'];
$guest = ['id' => 'guest', 'role' => null, 'kind' => 'guest', 'powers' => []];
$code = function ($fn) { try { $fn(); return 200; } catch (Mutqan_Error $e) { return $e->slug . ':' . $e->status; } };
$L = function ($in, $p) { return Mutqan_Link::handle($in, $p); };
$httplog = function () { $f = $GLOBALS['__dir'] . '/http.log'; return is_file($f) ? array_values(array_filter(array_map(function ($l) { return json_decode($l, true); }, explode("\n", trim((string) file_get_contents($f)))))) : []; };
$tglog = function () use ($httplog) { return array_values(array_filter($httplog(), function ($r) { return isset($r['tg']); })); };

// ---------------------------------------------------------------- hotline link code
$CODE = 'Tst-Link-246801357-Zz!';   // made up
$DEV1 = 'devowner0001'; $DEV2 = 'devops00002'; $DEV3 = 'devops00003';
$st = $L(['op' => 'state'], $guest);
$t('public state before any code: not linked, not online', $st['linked'] === false && $st['online'] === false, $st);
$t('a guest cannot read the status', $code(function () use ($L, $guest) { $L(['op' => 'status'], $guest); }) !== 200);
$t('a customer cannot use the link ops', $code(function () use ($L, $cust, $DEV1) { $L(['op' => 'status', 'deviceId' => $DEV1], $cust); }) === 'forbidden:403');
$t('a technician cannot use the link ops', $code(function () use ($L, $tech, $DEV1) { $L(['op' => 'status', 'deviceId' => $DEV1], $tech); }) === 'forbidden:403');
$t('operations cannot SET the code (owner only)', $code(function () use ($L, $ops, $CODE, $DEV2) { $L(['op' => 'set', 'code' => $CODE, 'deviceId' => $DEV2], $ops); }) === 'forbidden:403');
$t('operations cannot link while no code is set (409)', $code(function () use ($L, $ops, $CODE, $DEV2) { $L(['op' => 'link', 'code' => $CODE, 'deviceId' => $DEV2], $ops); }) === 'not_set:409');
$t('a too-short code is refused', $code(function () use ($L, $owner, $DEV1) { $L(['op' => 'set', 'code' => 'abc', 'deviceId' => $DEV1], $owner); }) === 'bad_request:400');
$r = $L(['op' => 'set', 'code' => $CODE, 'deviceId' => $DEV1, 'label' => 'جوال المالك'], $owner);
$t('owner sets the code: set + this device is linked', $r['set'] === true && $r['linkedHere'] === true && count($r['devices']) === 1, $r);
$t('the response never contains the code or a hash', strpos(json_encode($r), $CODE) === false && strpos(json_encode($r), '$2y$') === false, $r);
$stored = get_option('mutqan_link', []);
$t('the code is stored only as a password hash', strpos(serialize($stored), $CODE) === false && password_verify($CODE, $stored['h'] ?? ''), array_keys($stored));
$t('state: linked and online right after setting', ($s = $L(['op' => 'state'], $guest)) && $s['linked'] === true && $s['online'] === true, $s);
$t('state exposes nothing secret', array_keys($s) === ['linked', 'online', 'tg'], array_keys($s));
$t('operations: wrong code refused', $code(function () use ($L, $ops, $DEV2) { $L(['op' => 'link', 'code' => 'Wrong-Code-1', 'deviceId' => $DEV2], $ops); }) === 'forbidden:403');
$t('operations: right code links the device (digits of any script, trailing space tolerated)', ($r = $L(['op' => 'link', 'code' => ' ' . strtr($CODE, ['2' => '٢', '4' => '٤', '6' => '٦']) . ' ', 'deviceId' => $DEV2, 'label' => 'جوال سارة'], $ops)) && $r['linkedHere'] === true && count($r['devices']) === 2, $r);
$t('case matters (the code is case sensitive)', $code(function () use ($L, $ops, $CODE, $DEV3) { $L(['op' => 'link', 'code' => strtolower($CODE), 'deviceId' => $DEV3], $ops); }) === 'forbidden:403');
$t('bad device id refused', $code(function () use ($L, $ops, $CODE) { $L(['op' => 'link', 'code' => $CODE, 'deviceId' => 'x'], $ops); }) === 'bad_request:400');
// presence: online means "a paired device pinged in the last 2 minutes"
$rr = get_option('mutqan_link_recv', []); foreach ($rr as $k => $v) { $rr[$k]['seen'] = time() - 600; } update_option('mutqan_link_recv', $rr);
$t('nobody pinged for 10 min -> line offline', $L(['op' => 'state'], $guest)['online'] === false);
$p = $L(['op' => 'ping', 'deviceId' => $DEV2], $ops);
$t('a paired device pings -> online again', $p['linked'] === true && $L(['op' => 'state'], $guest)['online'] === true, $p);
$p = $L(['op' => 'ping', 'deviceId' => 'devunknown1'], $ops);
$t('an unpaired device is told it is not linked', $p['linked'] === false, $p);
$u = $L(['op' => 'unlink', 'deviceId' => $DEV2], $ops);
$t('a device can unlink itself', $u['linkedHere'] === false && count($u['devices']) === 1, $u);
$L(['op' => 'link', 'code' => $CODE, 'deviceId' => $DEV2], $ops);
$st = $L(['op' => 'status', 'deviceId' => $DEV2], $ops);
$t('non-owner sees short ids for other devices', in_array(substr($DEV1, 0, 6), array_column($st['devices'], 'id'), true) && !in_array($DEV1, array_column($st['devices'], 'id'), true), $st['devices']);
$u = $L(['op' => 'unlink', 'deviceId' => $DEV1, 'target' => $DEV2], $owner);
$t('the owner can unlink any device', count($u['devices']) === 1 && $u['devices'][0]['me'] === true, $u);
$L(['op' => 'link', 'code' => $CODE, 'deviceId' => $DEV2], $ops);
$NEW = 'Another-Made-Up-Code-77';
$r = $L(['op' => 'set', 'code' => $NEW, 'deviceId' => $DEV1], $owner);
$t('a NEW code unpairs everyone except the device that set it', count($r['devices']) === 1 && $r['devices'][0]['me'] === true, $r);
$t('…and the old code no longer works', $code(function () use ($L, $ops, $CODE, $DEV2) { $L(['op' => 'link', 'code' => $CODE, 'deviceId' => $DEV2], $ops); }) === 'forbidden:403');
// the guess limiter: 10 wrong guesses lock linking for 15 minutes — even for the right code
$c429 = 0; for ($i = 0; $i < 12; $i++) { if ($code(function () use ($L, $ops, $DEV2, $i) { $L(['op' => 'link', 'code' => 'guess-' . $i . '-xx', 'deviceId' => $DEV2], $ops); }) === 'rate_limited:429') { $c429++; } }
$t('wrong guesses end in a lock (429)', $c429 >= 1, $c429);
$t('…and the right code waits while locked', $code(function () use ($L, $ops, $NEW, $DEV2) { $L(['op' => 'link', 'code' => $NEW, 'deviceId' => $DEV2], $ops); }) === 'rate_limited:429');
$r = $L(['op' => 'clear'], $owner);
$t('owner clears the line: no code, no devices', $r['set'] === false && $r['devices'] === [], $r);
$t('operations cannot clear', $code(function () use ($L, $ops) { $L(['op' => 'clear'], $ops); }) === 'forbidden:403');

// ---------------------------------------------------------------- Telegram
$TOK = '123456789:' . 'AAH' . str_repeat('x7Q-_', 7);   // made up, valid shape
$t('Telegram not configured: nothing public', $L(['op' => 'state'], $guest)['tg'] === '');
$t('only the owner saves the bot token', $code(function () use ($L, $ops, $TOK) { $L(['op' => 'tg_save', 'token' => $TOK], $ops); }) === 'forbidden:403');
$t('a malformed token is refused before any network call', $code(function () use ($L, $owner) { $L(['op' => 'tg_save', 'token' => 'not-a-token'], $owner); }) === 'bad_request:400' && !$tglog());
$r = $L(['op' => 'tg_save', 'token' => $TOK], $owner);
$t('owner saves the token: bot is on, link shown', $r['on'] === true && $r['bot'] === 'mq_test_bot' && $r['link'] === 'https://t.me/mq_test_bot', $r);
$t('the token is never returned', strpos(json_encode($r), $TOK) === false && strpos(json_encode($r), '123456789:') === false);
$cfg = get_option('mutqan_tg', []);
$t('the token is not stored in clear text', strpos(serialize($cfg), $TOK) === false && strpos(serialize($cfg), 'AAH') === false, substr($cfg['t'] ?? '', 0, 6));
$calls = array_column($tglog(), 'tg');
$t('getMe then setWebhook were called', $calls === ['getMe', 'setWebhook'], $calls);
$hook = array_values(array_filter($tglog(), function ($r) { return $r['tg'] === 'setWebhook'; }))[0] ?? [];
$t('webhook points at this site with a secret header token', strpos($hook['body']['url'] ?? '', '/wp-json/mutqan/v1/tg-hook') !== false && strlen($hook['body']['secret_token'] ?? '') === 32 && ($hook['body']['allowed_updates'] ?? null) === ['message'], $hook);
$t('the Telegram bot name is public (so the customer button can offer it)', $L(['op' => 'state'], $guest)['tg'] === 'mq_test_bot');
$SECRET = $cfg['secret'];
$wh = function ($upd, $secret = null) use ($SECRET) { return Mutqan_Telegram::webhook(json_encode($upd, JSON_UNESCAPED_UNICODE), $secret ?? $SECRET); };
$msg = function ($chat, $text, $extra = []) { return ['message' => array_merge(['message_id' => 1, 'chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['id' => $chat, 'first_name' => 'Ahmed', 'username' => 'ahmed_x'], 'text' => $text], $extra)]; };
$t('a webhook call without the secret header is refused', $code(function () use ($wh, $msg) { $wh($msg(111111, 'hi'), 'wrong'); }) === 'forbidden:403');
$t('…and so is a call with no header at all', $code(function () use ($msg) { Mutqan_Telegram::webhook(json_encode($msg(111111, 'hi')), ''); }) === 'forbidden:403');
// route level: the registered REST route enforces the same header
$res = shim_dispatch('POST', '/wp-json/mutqan/v1/tg-hook', [], json_encode($msg(111111, 'hi')), []);
$t('REST /tg-hook without the secret -> 403', $res->status === 403, $res->status);
$res = shim_dispatch('POST', '/wp-json/mutqan/v1/tg-hook', ['x-telegram-bot-api-secret-token' => $SECRET], json_encode($msg(111111, 'hi')), []);
$t('REST /tg-hook with the secret -> 200', $res->status === 200, $res->status);

// pairing a team member: one tap, one use
$pr = $L(['op' => 'tg_pair'], $ops);
$t('a team member gets a one-time t.me link', preg_match('#^https://t\.me/mq_test_bot\?start=p_([a-f0-9]{12})$#', $pr['link'], $pm) === 1, $pr);
$t('a customer cannot create a pairing link', $code(function () use ($L, $cust) { $L(['op' => 'tg_pair'], $cust); }) === 'forbidden:403');
$before = count($tglog());
$wh($msg(900001, '/start p_' . $pm[1]));
$cfg = get_option('mutqan_tg', []);
$t('the chat is now a team chat (belongs to that user)', isset($cfg['chats']['900001']) && $cfg['chats']['900001']['uid'] === 'u-ops', array_keys($cfg['chats'] ?? []));
$tl = $tglog(); $last = end($tl);
$t('the bot confirmed in that chat', $last['tg'] === 'sendMessage' && $last['body']['chat_id'] === '900001' && strpos($last['body']['text'], 'تم الربط') !== false, $last);
$wh($msg(900002, '/start p_' . $pm[1]));
$cfg = get_option('mutqan_tg', []);
$t('the same link cannot be used twice', !isset($cfg['chats']['900002']), array_keys($cfg['chats'] ?? []));
$wh($msg(900002, '/start p_ffffffffffff'));
$t('an invented pairing code pairs nothing', !isset(get_option('mutqan_tg', [])['chats']['900002']));
$pr = $L(['op' => 'tg_pair'], $owner); preg_match('/p_([a-f0-9]{12})$/', $pr['link'], $pm2);
$wh($msg(900003, '/start p_' . $pm2[1]));
$t('two team chats are paired', count(get_option('mutqan_tg', [])['chats']) === 2);
$st = $L(['op' => 'tg_status'], $ops);
$t('status: on, 2 chats, mine', $st['on'] === true && $st['chats'] === 2 && $st['mine'] === true, $st);

// team alerts: an order alert reaches Telegram too (and goes to both team chats)
$n0 = count($tglog());
Mutqan_Rest::admin_alert('🆕 طلب جديد', "الخدمة: تكييف\nالاسم: خالد", '#/staff/order/x', 'new_order', 'high');
$new = array_slice($tglog(), $n0);
$to = array_map(function ($r) { return $r['body']['chat_id']; }, $new);
sort($to);
$t('an alert is sent to every team chat', $to === ['900001', '900003'], $to);
$t('…with the title and the body', strpos($new[0]['body']['text'], 'طلب جديد') !== false && strpos($new[0]['body']['text'], 'خالد') !== false, $new[0]['body']['text'] ?? null);

// customer chat: /start with the hotline call code, then free text; the team answers by REPLYING
$n0 = count($tglog());
$wh($msg(555001, '/start c_AB12CD'));
$new = array_slice($tglog(), $n0);
$greet = array_values(array_filter($new, function ($r) { return $r['body']['chat_id'] === '555001'; }));
$fwd = array_values(array_filter($new, function ($r) { return $r['body']['chat_id'] === '900001'; }));
$t('the customer is greeted', count($greet) === 1 && strpos($greet[0]['body']['text'], 'أهلًا') !== false, $greet);
$t('the team gets the chat start with the call code', count($fwd) === 1 && strpos($fwd[0]['body']['text'], 'AB12CD') !== false && preg_match('/#c555001$/', $fwd[0]['body']['text']), $fwd);
$n0 = count($tglog());
$wh($msg(555001, "السلام عليكم\nأحتاج صيانة مكيف سبليت اليوم <b>x</b>"));
$new = array_slice($tglog(), $n0);
$fwd = array_values(array_filter($new, function ($r) { return $r['body']['chat_id'] === '900001'; }));
$t('the customer message is forwarded to the team with the sender and the reply tag', count($fwd) === 1 && strpos($fwd[0]['body']['text'], 'Ahmed') !== false && strpos($fwd[0]['body']['text'], '@ahmed_x') !== false && strpos($fwd[0]['body']['text'], 'مكيف') !== false && !strpos($fwd[0]['body']['text'], '<b>'), $fwd);
$notif = Mutqan_Store::select('notifications', ['event' => 'telegram_msg'], ['limit' => 5], ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'], false);
$t('the in-app alert (sound) was raised for the customer message', count($notif) >= 1, count($notif));
$t('…and that alert did NOT bounce a second copy to Telegram', count(array_filter($new, function ($r) { return $r['body']['chat_id'] === '900001'; })) === 1);
// team reply -> customer
$n0 = count($tglog());
$tag = $fwd[0]['body']['text'];
$wh($msg(900001, 'أهلاً أحمد، نرسل الفني خلال ساعة', ['reply_to_message' => ['message_id' => 77, 'text' => $tag]]));
$new = array_slice($tglog(), $n0);
$toCust = array_values(array_filter($new, function ($r) { return $r['body']['chat_id'] === '555001'; }));
$t('the reply to the tagged message reaches the customer', count($toCust) === 1 && strpos($toCust[0]['body']['text'], 'نرسل الفني') !== false, $new);
$ack = array_values(array_filter($new, function ($r) { return $r['body']['chat_id'] === '900001'; }));
$t('the team member gets a delivery tick', count($ack) === 1 && strpos($ack[0]['body']['text'], 'وصل الرد') !== false, $ack);
$n0 = count($tglog());
$wh($msg(900001, 'كلام بلا رد'));
$new = array_slice($tglog(), $n0);
$t('a team message that is not a reply goes to nobody but explains how to reply', count($new) === 1 && $new[0]['body']['chat_id'] === '900001' && !array_filter($new, function ($r) { return $r['body']['chat_id'] === '555001'; }), $new);
$n0 = count($tglog());
$wh($msg(900001, 'محاولة تحويل', ['reply_to_message' => ['message_id' => 5, 'text' => 'رسالة عادية بلا وسم']]));
$t('a reply to an untagged message reaches no customer', !array_filter(array_slice($tglog(), $n0), function ($r) { return $r['body']['chat_id'] === '555001'; }));
// a customer cannot talk as the team: a non-team chat replying to a tagged text just becomes a customer message
$n0 = count($tglog());
$wh($msg(555002, 'سأتظاهر بأني موظف', ['reply_to_message' => ['message_id' => 9, 'text' => "x\n#c555001"]]));
$t('a customer chat replying to a tagged message does NOT reach another customer', !array_filter(array_slice($tglog(), $n0), function ($r) { return $r['body']['chat_id'] === '555001'; }));
// location and media from a customer
$n0 = count($tglog());
$wh($msg(555001, '', ['text' => null, 'location' => ['latitude' => 21.5, 'longitude' => 39.2]]));
$f = array_values(array_filter(array_slice($tglog(), $n0), function ($r) { return $r['body']['chat_id'] === '900001'; }));
$t('a shared location arrives as a map link', count($f) === 1 && strpos($f[0]['body']['text'], 'maps.google.com/?q=21.5,39.2') !== false, $f);
// private chats only
$n0 = count($tglog());
$wh(['message' => ['message_id' => 1, 'chat' => ['id' => -100123, 'type' => 'supergroup'], 'from' => ['id' => 1, 'first_name' => 'G'], 'text' => 'hello group']]);
$t('group chats are ignored', count($tglog()) === $n0);
// customer flooding is limited per chat
$sent = 0; for ($i = 0; $i < 140; $i++) { $b = count($tglog()); $wh($msg(777001, 'spam ' . $i)); if (count($tglog()) > $b) { $sent++; } }
$t('a flooding customer is cut off (limit per hour)', $sent > 0 && $sent <= 121, $sent);
// /stop
$wh($msg(900003, '/stop'));
$t('/stop removes the team chat', !isset(get_option('mutqan_tg', [])['chats']['900003']) && isset(get_option('mutqan_tg', [])['chats']['900001']));
$u = $L(['op' => 'tg_unpair'], $ops);
$t('the app can unpair my chats', $u['mine'] === false && $u['chats'] === 0, $u);
$r = $L(['op' => 'tg_off'], $owner);
$t('owner switches the bot off: webhook deleted, config gone', $r['on'] === false && !get_option('mutqan_tg', []) && in_array('deleteWebhook', array_column($tglog(), 'tg'), true), $r);
$t('after that the webhook is refused', $code(function () use ($msg, $SECRET) { Mutqan_Telegram::webhook(json_encode($msg(1, 'x')), $SECRET); }) === 'forbidden:403');
$t('and the public state hides the bot', $L(['op' => 'state'], $guest)['tg'] === '');

// ---------------------------------------------------------------- the desk's doors (device diagnostics + WordPress)
$h = function ($op, $body, $p, $headers = []) { Mutqan_Auth::reset_memo(); return call_user_func(['Mutqan_Rest', 'h_desk'], new class($op, $body) { public $b; function __construct($o, $b) { $this->b = array_merge(['op' => $o], $b); } function get_json_params() { return $this->b; } }, $p); };
$r = $h('diag', ['app' => 'ops', 'build' => 'b123', 'step' => 'gate', 'info' => "no network\n\x01x"], $guest);
$log = get_option('oxygen_desk_log', []);
$t('a device report is logged (no control characters)', $r['ok'] === true && end($log)['k'] === 'diag' && end($log)['step'] === 'gate' && strpos(end($log)['info'], "\n") === false, end($log));
$n = count($log); $h('diag', ['app' => 'ops', 'build' => 'b123', 'step' => 'gate', 'info' => "no network\n\x01x"], $guest);
$log = get_option('oxygen_desk_log', []);
$t('the same report again is one line with a counter', count($log) === $n && end($log)['n'] === 2, end($log));
for ($i = 0; $i < 100; $i++) { Oxygen_Desk::log('diag', ['step' => 'flood-' . $i]); }
$t('the log is capped at 80 lines', count(get_option('oxygen_desk_log', [])) === 80);
$t('overview / pages / sso need the owner', $code(function () use ($h, $ops) { $h('wp_overview', [], $ops); }) === 'forbidden:403' && $code(function () use ($h, $guest) { $h('wp_sso', ['to' => 'index.php'], $guest); }) !== 200);
$t('safe_admin_path accepts real wp-admin screens', Oxygen_Desk::safe_admin_path('/wp-admin/edit.php?post_type=page') === 'edit.php?post_type=page' && Oxygen_Desk::safe_admin_path('post.php?post=12&action=edit') === 'post.php?post=12&action=edit' && Oxygen_Desk::safe_admin_path('admin.php?page=oxygen-desk') === 'admin.php?page=oxygen-desk');
$bad = ['//evil.com/x.php', 'https://evil.com/wp-admin/index.php', '../wp-config.php', 'edit.php?x=<script>', 'index.php#x', "index.php\r\nSet-Cookie: a=b", 'wp-login.php?redirect_to=http://evil.com', 'file.txt', ''];
$okbad = true; foreach ($bad as $b) { if ($b === '') { continue; } if ($code(function () use ($b) { Oxygen_Desk::safe_admin_path($b); }) === 200) { $okbad = false; echo "  accepted: $b\n"; } }
$t('safe_admin_path refuses other hosts, traversal, markup, header injection', $okbad);
$t('an empty path means the dashboard', Oxygen_Desk::safe_admin_path('') === 'index.php');
// ---- one-time sign-in link: a single-use token, only for a manage_options user, only to a wp-admin screen
if (!function_exists('get_users')) { function get_users($a = []) { return [7]; } }
if (!function_exists('get_user_by')) { function get_user_by($f, $v) { return in_array((int) $v, [7, 8], true) ? (object) ['ID' => (int) $v, 'user_login' => 'admin' . $v] : false; } }
if (!function_exists('user_can')) { function user_can($u, $c) { return is_object($u) && (int) $u->ID === 7; } }
$url = Oxygen_Desk::make_sso_url('/wp-admin/edit.php?post_type=page', 'https://desk.oxygen11.com/desk-ops.html');
$t('make_sso_url: a home-page address carrying a 48-hex one-time token', preg_match('#\?oxygen_sso=([a-f0-9]{48})$#', $url, $mt) === 1, $url);
$t('the token is stored only as a hash (the transient key never holds it)', !isset($GLOBALS['wpdb']) || $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT COUNT(*) FROM shim_kv WHERE k LIKE %s', '%' . $mt[1] . '%')) == 0);
$r = Oxygen_Desk::sso_redeem($mt[1]);
$t('redeem: the administrator, the screen and the way back', is_array($r) && $r['user']->ID === 7 && $r['to'] === 'edit.php?post_type=page' && $r['back'] === 'https://desk.oxygen11.com/desk-ops.html', $r);
$t('redeem is single use', Oxygen_Desk::sso_redeem($mt[1]) === null);
$t('a malformed / unknown token is refused', Oxygen_Desk::sso_redeem('zz') === null && Oxygen_Desk::sso_redeem(str_repeat('a', 48)) === null && Oxygen_Desk::sso_redeem(['x']) === null);
$u2 = Oxygen_Desk::make_sso_url('index.php', 'https://evil.example/steal'); preg_match('#oxygen_sso=([a-f0-9]{48})#', $u2, $m2);
$t('a back address on another host is dropped', Oxygen_Desk::sso_redeem($m2[1])['back'] === '');
update_option('oxygen_desk_wp_user', 8);   // user 8 is not an administrator
$u3 = Oxygen_Desk::make_sso_url('index.php'); preg_match('#oxygen_sso=([a-f0-9]{48})#', $u3, $m3);
$t('sso_user_id falls back to the first administrator when the saved one cannot manage the site', Oxygen_Desk::sso_user_id() === 7, Oxygen_Desk::sso_user_id());
update_option('oxygen_desk_wp_user', 7);
update_option('oxygen_desk_sso', '0');
$t('the switch in wp-admin turns the direct entry off (no link can be made)', $code(function () { Oxygen_Desk::make_sso_url('index.php'); }) === 'forbidden:403');
$u4 = null; update_option('oxygen_desk_sso', '1'); $u4 = Oxygen_Desk::make_sso_url('index.php'); preg_match('#oxygen_sso=([a-f0-9]{48})#', $u4, $m4); update_option('oxygen_desk_sso', '0');
$t('…and a link made earlier stops working too', Oxygen_Desk::sso_redeem($m4[1]) === null);
update_option('oxygen_desk_sso', '1');
$t('a path that is not a wp-admin screen cannot be requested', $code(function () { Oxygen_Desk::make_sso_url('https://evil.example/x.php'); }) === 'bad_request:400');
exit($ok ? 0 : 1);
