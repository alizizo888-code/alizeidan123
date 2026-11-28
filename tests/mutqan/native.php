<?php
// The installed apps: a download link that works, WhatsApp notifications from team phones, and «الفني قريب»
// decided by the server from background GPS.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-native-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "native: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$owner = ['id' => 'u-own', 'role' => 'owner', 'kind' => 'user', 'powers' => ['read', 'write', 'manage']];
$ops = ['id' => 'u-ops', 'role' => 'operations', 'kind' => 'user', 'name' => 'منى', 'powers' => ['read', 'write']];
$cust = ['id' => 'c1', 'role' => 'client', 'kind' => 'user', 'powers' => []];

// downloads
$cat = Mutqan_Devices::catalog();
$t('every app link goes through the stable /apk address', count($cat) >= 4 && !array_filter($cat, function ($a) { return strpos($a['apk'], '/apk?f=oxygen-') === false; }), array_column($cat, 'apk'));
$t('no link points at the desk host (that was the 404)', strpos(json_encode($cat), 'desk.oxygen11.com/oxygen-') === false);
$t('/apk sends to the public release', strpos(Mutqan_Native::apk_target('oxygen-desktech.apk'), 'github.com/alizizo888-code/alizeidan123/releases/download/apps-latest/oxygen-desktech.apk') !== false);
$t('/apk refuses anything else', Mutqan_Native::apk_target('../../wp-config.php') === '' && Mutqan_Native::apk_target('x.apk') === '');

// WhatsApp notifications from a team phone
$r = Mutqan_Native::handle(['op' => 'notifs', 'items' => [
    ['app' => 'com.whatsapp', 'title' => 'أبو خالد', 'text' => 'ابغى صيانة مكيف سبليت بكرة', 'at' => time()],
    ['app' => 'com.whatsapp.w4b', 'title' => 'عميل', 'text' => 'عاجل في تسريب مويه من المكيف', 'at' => time()],
    ['app' => 'com.instagram.android', 'title' => 'x', 'text' => 'ليس واتساب'],
    ['app' => 'com.whatsapp', 'title' => 'واتساب', 'text' => '3 رسائل جديدة'],
]], $ops);
$t('WhatsApp only, summaries skipped', $r['saved'] === 2, $r);
$t('orders and emergencies raise the operations room', $r['alerts'] === 2, $r);
$r2 = Mutqan_Native::handle(['op' => 'notifs', 'items' => [['app' => 'com.whatsapp', 'title' => 'أبو خالد', 'text' => 'ابغى صيانة مكيف سبليت بكرة']]], $ops);
$t('the same notification twice is kept once', $r2['saved'] === 0, $r2);
$deny = false; try { Mutqan_Native::handle(['op' => 'notifs', 'items' => []], $cust); } catch (Mutqan_Error $e) { $deny = $e->status === 403; }
$t('a customer app cannot send notifications', $deny);
$c = Mutqan_Native::handle(['op' => 'config'], ['id' => 't1', 'role' => 'technician', 'kind' => 'user', 'powers' => ['read']]);
$t('technician app gets background GPS every 30 s', ($c['gps']['every'] ?? 0) === 30 && $c['gps']['path'] === '/track', $c);

// the server decides «الفني قريب»
Mutqan_Store::upsert('tech_profiles', ['userId' => 't1', 'displayName' => 'وليد'], 't1', [], $owner);
$o = Mutqan_Store::insert('orders', ['status' => 'en_route', 'technicianId' => 't1', 'clientId' => 'c1', 'number' => '555', 'lat' => 21.4225, 'lng' => 39.8262], null, [], $owner);
$far = Mutqan_Track::ping('t1', 21.4400, 39.8262);                  // ~2 km away
$o1 = Mutqan_Store::get_raw('orders', $o['id']);
$t('on the way: the order carries where the technician is and how far', $o1['status'] === 'en_route' && $o1['technicianDistanceM'] > 1500 && isset($o1['technicianLocation']['lat']), $o1);
// three minutes later (a real drive, not a GPS jump — see Mutqan_Track::plausible)
$tp = Mutqan_Store::get_raw('tech_profiles', 't1'); Mutqan_Store::update('tech_profiles', 't1', ['atAt' => (int) $tp['atAt'] - 180], $owner);
$near = Mutqan_Track::ping('t1', 21.4235, 39.8262);                 // ~110 m
$o2 = Mutqan_Store::get_raw('orders', $o['id']);
$t('within 300 m it turns «الفني قريب» with a time on the step', $o2['status'] === 'nearby' && end($o2['statusHistory'])['status'] === 'nearby' && in_array($o['id'], $near['nearby'], true), $o2);
$pushes = Mutqan_Store::select('_push', [], ['limit' => 50], ['kind' => 'user', 'role' => 'owner', 'id' => 'system']);
$t('and the customer is told', (bool) array_filter($pushes, function ($x) { return strpos($x['title'] ?? '', 'الفني قريب') !== false && ($x['target']['user'] ?? '') === 'c1'; }), $pushes);
Mutqan_Track::ping('t1', 21.4230, 39.8262);
$n = count(array_filter(Mutqan_Store::select('_push', [], ['limit' => 50], ['kind' => 'user', 'role' => 'owner', 'id' => 'system']), function ($x) { return strpos($x['title'] ?? '', 'الفني قريب') !== false; }));
$t('only once', $n === 1, $n);

// The alert service on the phone polls the same inbox with the server's own «now» (ISO) as since.
$mk = function ($b) { $q = new WP_REST_Request(); $q->body = json_encode($b); return $q; };
$first = Mutqan_Rest::h_push($mk(['op' => 'inbox']), $ops);
sleep(1);
Mutqan_Store::insert('_push', ['target' => ['user' => 'u-ops'], 'title' => '📞 مكالمة من العميل', 'body' => 'x', 'url' => '#/staff', 'priority' => 'high', 'event' => 'incoming_call'], null, [], ['kind' => 'user', 'role' => 'owner', 'id' => 'system']);
$next = Mutqan_Rest::h_push($mk(['op' => 'inbox', 'since' => $first['now']]), $ops);
$calls = array_values(array_filter($next['items'], function ($m) { return $m['event'] === 'incoming_call'; }));
$t('a call reaches the phone service with its event and priority (ISO since)', count($calls) === 1 && $calls[0]['priority'] === 'high' && !empty($next['now']), $next);
$again = Mutqan_Rest::h_push($mk(['op' => 'inbox', 'since' => $next['now']]), $ops);
// Same-second items may come again (the server uses «not older than»); the phone service skips ids it has seen.
$t('a repeat carries the same id, so the phone skips it', !array_filter($again['items'], function ($m) use ($calls) { return $m['event'] === 'incoming_call' && $m['id'] !== $calls[0]['id']; }), $again);
$src = file_get_contents(__DIR__ . '/../../native/shared/java/MqPushService.java');
$t('the phone service de-duplicates by id and stays quiet while the app is on screen', strpos($src, 'seen.contains("|" + id + "|")') !== false && strpos($src, '!appVisible') !== false);
$t('calls ring full-screen with the ringtone and wake the screen', strpos($src, 'setFullScreenIntent') !== false && strpos($src, 'TYPE_RINGTONE') !== false && strpos($src, 'ACQUIRE_CAUSES_WAKEUP') !== false);
exit($ok ? 0 : 1);
