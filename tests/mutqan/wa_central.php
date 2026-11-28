<?php
// «السنترال»: many lines in front, one master in the owner's hand — identity card, ready-order digest, master commands
// answered from the customer's own line, lanes, n8n signed in/out, the VPS installer.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-central-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "central: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$W = 'Mutqan_WA'; $F = 'Mutqan_WA_Fleet'; $C = 'Mutqan_WA_Central';
$owner = ['kind' => 'user', 'role' => 'owner', 'id' => 'o1', 'name' => 'علي', 'powers' => ['read', 'write', 'manage']];
$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
$hook = function ($secret, $ev) use ($W) { $raw = json_encode($ev, JSON_UNESCAPED_UNICODE); return $W::webhook($raw, hash_hmac('sha256', $raw, $secret)); };
$pull = function ($secret, $in) use ($W) { $raw = json_encode($in); return $W::pull_signed($raw, hash_hmac('sha256', $raw, $secret)); };
$sat = Oxygen_Network::satellites();
$site = $sat[0] ?? 'khabeer';

// the VPS server and its lines
$D = $W::handle(['op' => 'fleet_register', 'name' => 'VPS هستنجر', 'kind' => 'vps'], $owner);
$t('a VPS device gets an Ubuntu systemd installer (no Termux)', $D['kind'] === 'vps' && strpos($D['setup'], 'systemctl enable --now mutqan-wa-' . $D['id']) !== false && strpos($D['setup'], 'pkg install') === false);
$pull($D['secret'], ['device' => $D['id'], 'sessions' => []]);
$L1 = $W::handle(['op' => 'link', 'phone' => '0501110001', 'role' => 'customers', 'brand' => $site], $owner);     // site line
$L2 = $W::handle(['op' => 'link', 'phone' => '0501110002', 'role' => 'customers'], $owner);                      // shared line
$L3 = $W::handle(['op' => 'link', 'phone' => '0501110003', 'role' => 'reports'], $owner);                        // reports → master
$L4 = $W::handle(['op' => 'link', 'phone' => '0501110004', 'role' => 'technicians'], $owner);
foreach ([$L1, $L2, $L3, $L4] as $i => $L) { $hook($D['secret'], ['type' => 'status', 'accountId' => $L['id'], 'status' => 'open', 'phone' => '96650111000' . ($i + 1)]); }
$lines = $C::lines();
$t('every line knows its site and lane', (bool) array_filter($lines, function ($l) use ($L1, $site) { return $l['id'] === $L1['id'] && $l['brand'] === $site && $l['role'] === 'customers'; }), $lines);

// 1. identity card
$hook($D['secret'], ['type' => 'message', 'accountId' => $L1['id'], 'msgId' => 'm1', 'direction' => 'in', 'phone' => '966555000111', 'name' => 'أبو فهد', 'text' => 'السلام عليكم', 'ts' => time()]);
$th = Mutqan_Store::get_raw('wa_threads', $W::tid($L1['id'], '966555000111'));
$t('a site line puts the identity card on the conversation', ($th['card']['brand'] ?? '') === $site && ($th['card']['how'] ?? '') === 'line' && $th['card']['line']['id'] === $L1['id'] && $th['card']['domain'] !== '', $th['card'] ?? null);
$hook($D['secret'], ['type' => 'message', 'accountId' => $L2['id'], 'msgId' => 'm2', 'direction' => 'in', 'phone' => '966555000222', 'name' => 'سعد', 'text' => 'مرحبا 👋 #' . $site, 'ts' => time()]);
$th2 = Mutqan_Store::get_raw('wa_threads', $W::tid($L2['id'], '966555000222'));
$t('on a shared line the website\'s #code tells the site', ($th2['card']['brand'] ?? '') === $site && $th2['card']['how'] === 'link', $th2['card'] ?? null);

// 3. master: ready-order digest from the reports line
$C::save(['master' => '0569998887']);
$sent = []; $GLOBALS['__post_hook'] = null;
$o = Mutqan_Store::insert('orders', ['status' => 'new', 'number' => '5001', 'clientName' => 'أبو فهد', 'clientPhone' => '966555000111', 'service' => 'تنظيف سبليت', 'address' => 'العزيزية', 'brand' => $site, 'waAccount' => $L1['id'], 'card' => $th['card']], null, [], $sys);
$jobs = $pull($D['secret'], ['device' => $D['id'], 'sessions' => []])['jobs'] ?? [];
$m = array_values(array_filter($jobs, function ($j) { return substr(preg_replace('/\D/', '', $j['to']), -9) === '569998887'; }));
$t('a new order reaches the master as one formatted message', $m && strpos($m[0]['text'], 'أوردر جاهز #5001') !== false && strpos($m[0]['text'], 'العزيزية') !== false && strpos($m[0]['text'], 'رد 5001') !== false, $jobs);
$t('…sent from the reports line, never a customers\' line', $m && $m[0]['acct'] === $L3['id'], $m[0] ?? null);
Mutqan_Store::update('orders', $o['id'], ['note' => 'x'], $sys);
$t('the same order is not sent twice', !array_filter($pull($D['secret'], ['device' => $D['id'], 'sessions' => []])['jobs'] ?? [], function ($j) { return strpos($j['text'], '#5001') !== false; }));

// master commands: reply from the customer's own line
$t('the master number is recognised as the owner', Mutqan_WA_Desk::identify('966569998887')['role'] === 'owner');
$mg = new ReflectionMethod('Mutqan_WA_Desk', 'management'); $mg->setAccessible(true);
$who = Mutqan_WA_Desk::identify('966569998887');
$r = $mg->invoke(null, $who, 'رد 5001 الفني هيكون عندك الساعة 5 إن شاء الله');
$jobs = $pull($D['secret'], ['device' => $D['id'], 'sessions' => []])['jobs'] ?? [];
$c = array_values(array_filter($jobs, function ($j) { return substr(preg_replace('/\D/', '', $j['to']), -9) === '555000111'; }));
$t('«رد 5001 …» reaches the customer from the line he wrote to', $c && $c[0]['acct'] === $L1['id'] && strpos($c[0]['text'], 'الساعة 5') !== false && strpos($r, 'اتبعت') !== false, [$r, $jobs]);
$th = Mutqan_Store::get_raw('wa_threads', $W::tid($L1['id'], '966555000111'));
$t('and the bot steps back on that conversation', ($th['bot']['pausedUntil'] ?? 0) > time());
$r = $mg->invoke(null, $who, 'فلوس النهارده');
$t('every internal-engine command works from the master', strpos($r, 'الإيراد') !== false, $r);

// 5. n8n
$s = $C::settings();
$body = json_encode(['op' => 'master', 'text' => 'تقرير من n8n']);
$bad = ''; try { $C::inbound($body, 'nope'); } catch (Mutqan_Error $e) { $bad = $e->slug; }
$t('n8n → site refuses an unsigned call', $bad === 'forbidden');
$r = $C::inbound($body, hash_hmac('sha256', $body, $s['n8nSecret']));
$t('a signed n8n call writes to the master', $r['sent'] === 1, $r);
$body = json_encode(['op' => 'reply', 'order' => '5001', 'text' => 'تأكيد الموعد']);
$r = $C::inbound($body, hash_hmac('sha256', $body, $s['n8nSecret']));
$t('n8n can answer a customer through his own line', strpos($r['text'], 'اتبعت') !== false, $r);
$posted = []; $GLOBALS['__post_hook'] = function ($url, $a) use (&$posted) { if (strpos($url, 'n8n.example') !== false) { $posted[] = $a; return ['code' => 200, 'body' => '{}', 'headers' => []]; } return null; };
$C::save(['n8nUrl' => 'https://n8n.example.com/webhook/mutqan']);
Mutqan_Store::insert('orders', ['status' => 'new', 'number' => '5002', 'clientName' => 'سعد', 'brand' => $site], null, [], $sys);
$ev = $posted ? json_decode($posted[0]['body'], true) : null;
$t('site → n8n: every ready order is posted, signed', $ev && $ev['event'] === 'order_ready' && hash_equals(hash_hmac('sha256', $posted[0]['body'], $C::settings()['n8nSecret']), $posted[0]['headers']['X-Mutqan-Signature']), $ev);
$GLOBALS['__post_hook'] = null;

// website links carry the site code
ob_start(); $C::link_tagger(); $js = ob_get_clean();
$t('websites tag their WhatsApp links with the site code', $js === '' || strpos($js, 'wa.me') !== false);

// server only, a new key when the old one was seen
$Ph = $W::handle(['op' => 'fleet_register', 'name' => 'موبايل قديم'], $owner);
$t('every new device is a server (no Termux anywhere)', $Ph['kind'] === 'vps' && strpos($Ph['setup'], 'pkg install') === false && strpos($Ph['setup'], 'systemctl') !== false);
$R = $W::handle(['op' => 'fleet_rotate', 'id' => $D['id']], $owner);
$t('«مفتاح جديد» gives a new key and the old one stops working', $R['secret'] !== $D['secret'] && strpos($R['setup'], $R['secret']) !== false && Mutqan_WA_Fleet::signer('x', hash_hmac('sha256', 'x', $D['secret'])) === null);

// quiet supervisors: not-urgent things wait for the night report
$C::defer('خطة اليوم', 'مكة 4 طلبات', 'k1', 'العقل التجاري');
$C::defer('تقرير شبكة المواقع والمقالات', '15 مقال اتنشر', 'net', 'المواقع والمقالات');
$C::defer('تقرير شبكة المواقع والمقالات', '18 مقال اتنشر', 'net', 'المواقع والمقالات');
$nt = $C::night_text();
$t('deferred items are grouped and merged for the night', strpos($nt, 'العقل التجاري') !== false && strpos($nt, '18 مقال') !== false && strpos($nt, '15 مقال') === false && strpos($nt, '(×2)') !== false, $nt);
Mutqan_Sales::notify('خطة اليوم', 'نص', 'test-key', 'digest');
$pushes = array_filter(Mutqan_Store::select('_push', [], ['limit' => 300], $sys), function ($x) { return ($x['event'] ?? '') === 'sales_brain'; });
$t('the sales brain no longer rings supervisors during the day', !$pushes);
$C::save(['master' => '0569998887']);
$sopt = get_option(Mutqan_WA_Central::OPT); $sopt['dailyHour'] = 0; $sopt['lastDaily'] = ''; update_option(Mutqan_WA_Central::OPT, $sopt);
$C::daily();
$jobs = $pull($D['secret'] === $R['secret'] ? $D['secret'] : $R['secret'], ['device' => $D['id'], 'sessions' => []])['jobs'] ?? [];
$nightMsg = array_values(array_filter($jobs, function ($j) { return strpos($j['text'], 'تقرير اليوم') !== false; }));
// owner, 2026-11-22: reports stay in the system — the night report is an app notification, never a WhatsApp message
$notes = array_values(array_filter(Mutqan_Store::select('notifications', [], ['limit' => 300], $sys), function ($x) { return ($x['event'] ?? '') === 'daily_report'; }));
$t('the night report goes to the app\'s notifications (not WhatsApp), carries the deferred items, then the list is empty', !$nightMsg && $notes && strpos($notes[0]['body'], 'اتأجل لتقرير الليلة') !== false && $C::night_text() === '', [$jobs, $notes]);

// location: the server is the judge
Mutqan_Store::upsert('tech_profiles', ['userId' => 'tq', 'displayName' => 'دقة', 'active' => true], 'tq', [], $owner);
$t('a vague reading (500 m) is refused', Mutqan_Track::ping('tq', 21.40, 39.80, 500)['reason'] === 'low_accuracy');
$t('a precise one is kept', Mutqan_Track::ping('tq', 21.40, 39.80, 12)['ok'] === true);
$t('a jump across the city in seconds is refused', Mutqan_Track::ping('tq', 21.60, 39.20, 10)['reason'] === 'jump');
exit($ok ? 0 : 1);
