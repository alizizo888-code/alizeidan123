<?php
// «مركز الواتساب»: once the hub on the VPS is paired, the site only reports and answers — it never talks WhatsApp itself.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'hub: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
$H = 'Mutqan_Hub';

// The hub, faked: it accepts pairing and collects every report.
$GLOBALS['hub_up'] = true; $GLOBALS['hub_pair'] = null; $GLOBALS['hub_events'] = [];
$GLOBALS['__post_hook'] = function ($url, $a) {
    if (strpos($url, 'https://wa.hub.test') !== 0) { return null; }
    if (!$GLOBALS['hub_up']) { return new WP_Error('down', 'connection refused'); }
    $b = json_decode($a['body'], true);
    if (substr($url, -5) === '/pair') { if (($b['pairToken'] ?? '') !== 'pair-token-1234567890') { return ['code' => 401, 'body' => '{"error":"bad token"}', 'headers' => []]; } $GLOBALS['hub_pair'] = $b; return ['code' => 200, 'body' => '{"ok":true}', 'headers' => []]; }
    if (substr($url, -10) === '/wp/events') {
        $sig = $a['headers']['x-mq-hub'] ?? '';
        if (!hash_equals(hash_hmac('sha256', $a['body'], $GLOBALS['hub_pair']['secret'] ?? 'x'), $sig)) { return ['code' => 401, 'body' => '{}', 'headers' => []]; }
        foreach ($b['events'] as $e) { $GLOBALS['hub_events'][] = $e; }
        return ['code' => 200, 'body' => '{"ok":true}', 'headers' => []];
    }
    return null;
};
$flush = function () { Mutqan_Hub::flush(); $e = $GLOBALS['hub_events']; $GLOBALS['hub_events'] = []; return $e; };

/* ---------------------------------------------------------------- pairing */
$t('not paired: off', !$H::on());
$err = null; try { $H::h_admin(['op' => 'pair', 'url' => 'https://wa.hub.test', 'pairToken' => 'pair-token-1234567890'], ['role' => 'operations']); } catch (Mutqan_Error $e) { $err = $e->slug; }
$t('only the owner pairs', $err === 'forbidden', $err);
$err = null; try { $H::h_admin(['op' => 'pair', 'url' => 'https://wa.hub.test', 'pairToken' => 'wrong-token-000000000'], ['role' => 'owner']); } catch (Mutqan_Error $e) { $err = $e->slug; }
$t('a wrong token is refused by the hub', $err === 'pair_failed' && !$H::on(), $err);
$st = $H::h_admin(['op' => 'pair', 'url' => 'https://wa.hub.test', 'pairToken' => 'pair-token-1234567890'], ['role' => 'owner']);
$t('paired: on, the secret went to the hub and never comes back out', $st['on'] && $st['paired'] && strlen($GLOBALS['hub_pair']['secret'] ?? '') === 64 && !isset($st['secret']), $st);
$secret = $GLOBALS['hub_pair']['secret'];

/* ---------------------------------------------------------------- the site reports, and stays quiet on WhatsApp */
Mutqan_Store::insert('tech_profiles', ['displayName' => 'الفني علي', 'phone' => '0549648890', 'userId' => 'u-ali', 'available' => true], 'u-ali', [], $sys);
$o = Mutqan_Store::insert('orders', ['status' => 'new', 'clientName' => 'علي تجربة', 'clientPhone' => '0536554731', 'specialtyId' => 'ac', 'preferredTime' => 'أقرب وقت ممكن', 'brand' => 'raad'], null, [], $sys);
$ev = $flush();
$c = array_values(array_filter($ev, function ($e) { return $e['type'] === 'order.created'; }));
$t('a new order is reported once, with what the hub needs', count($c) === 1 && $c[0]['order']['clientPhone'] === '0536554731' && $c[0]['order']['preferredTime'] === 'أقرب وقت ممكن' && $c[0]['order']['number'] !== '', $ev);
$t('…and the site sent nothing on WhatsApp itself', !Mutqan_Store::select('wa_outbox', [], ['limit' => 5], $sys));
Mutqan_Store::update('orders', $o['id'], ['technicianId' => 'u-ali', 'technicianName' => 'الفني علي', 'status' => 'assigned'], $sys);
$ev = $flush();
$types = array_column($ev, 'type');
$as = array_values(array_filter($ev, function ($e) { return $e['type'] === 'order.assigned'; }));
$t('assigned: reported with the technician\'s phone and his job card', in_array('order.status', $types, true) && $as && $as[0]['order']['techPhone'] === '0549648890' && strpos($as[0]['order']['jobCard'], 'طلب جديد ليك') !== false, $ev);
Mutqan_Store::update('orders', $o['id'], ['cancelRequest' => ['at' => gmdate('c'), 'text' => 'الغي']], $sys);
Mutqan_Store::update('orders', $o['id'], ['status' => 'cancelled'], $sys);
$ev = $flush();
$x = array_values(array_filter($ev, function ($e) { return $e['type'] === 'order.status'; }));
$t('the room cancelling after the customer asked counts as the customer\'s cancellation', $x && $x[0]['was'] === 'assigned' && $x[0]['byCustomer'] === true, $ev);
$r = Mutqan_WA::auto_send('0500000001', 'رمز الدخول 1234', 'otp');
$ev = $flush();
$t('any WhatsApp text the site wants out is handed to the hub', $r === 'sent' && $ev && $ev[0]['type'] === 'send' && $ev[0]['phone'] === '966500000001' && $ev[0]['purpose'] === 'otp', $ev);
$t('the old gateway gets nothing to do', !empty(Mutqan_WA::pull_signed('{}', 'x')['retired']));

/* ---------------------------------------------------------------- the hub is down: nothing is lost */
$GLOBALS['hub_up'] = false;
Mutqan_WA::auto_send('0500000002', 'رسالة', 'order');
$flush();
$t('a report that cannot be delivered waits in the queue', count((array) get_option(Mutqan_Hub::QUEUE, [])) === 1);
$GLOBALS['hub_up'] = true;

/* ---------------------------------------------------------------- the hub asks */
$call = function ($in, $sig = null) use ($secret) { $raw = json_encode($in + ['ts' => time()], JSON_UNESCAPED_UNICODE); try { return Mutqan_Hub::api($raw, $sig ?? hash_hmac('sha256', $raw, $secret))['result']; } catch (Mutqan_Error $e) { return 'ERR:' . $e->slug; } };
$t('a wrong signature is refused', $call(['op' => 'ping'], 'bad') === 'ERR:forbidden');
$raw = json_encode(['op' => 'ping', 'ts' => time() - 3600]);
$err = null; try { Mutqan_Hub::api($raw, hash_hmac('sha256', $raw, $secret)); } catch (Mutqan_Error $e) { $err = $e->slug; }
$t('an old (replayed) question is refused', $err === 'stale', $err);
$q = $call(['op' => 'events']);
$t('the hub collects the waiting reports', count($q['events'] ?? []) === 1 && !get_option(Mutqan_Hub::QUEUE, []), $q);

$o2 = Mutqan_Store::insert('orders', ['status' => 'new', 'clientName' => 'سعيد', 'clientPhone' => '0501112222', 'service' => 'غسيل مكيف'], null, [], $sys);
$flush();
$v = $call(['op' => 'order', 'number' => $o2['number']]);
$t('order by number', ($v['id'] ?? '') === $o2['id'] && $v['statusLabel'] === 'جديد', $v);
$v = $call(['op' => 'order', 'phone' => '966501112222', 'open' => true]);
$t('open order by the customer\'s phone', ($v['id'] ?? '') === $o2['id'], $v);
$w = $call(['op' => 'who', 'phone' => '966549648890']);
$t('a technician is known by his phone', $w['kind'] === 'tech' && $w['name'] === 'الفني علي', $w);
$t('anyone else is a customer', $call(['op' => 'who', 'phone' => '966500000099'])['kind'] === 'customer');
$a = $call(['op' => 'assign', 'id' => $o2['id'], 'name' => 'علي']);
$ev = $flush();
$t('«وجّه» by name assigns and the hub hears it', strpos($a['text'], 'اتوجّه') !== false && in_array('order.assigned', array_column($ev, 'type'), true), [$a, $ev]);
$w = $call(['op' => 'who', 'phone' => '0549648890']);
$t('…and the job shows in his open jobs', count($w['jobs']) === 1 && $w['jobs'][0]['id'] === $o2['id'], $w);
$v = $call(['op' => 'patch', 'id' => $o2['id'], 'patch' => ['status' => 'arrived', 'clientPhone' => 'hacked'], 'step' => ['kind' => 'arrived', 'text' => 'وصل']]);
$raw2 = Mutqan_Store::get_raw('orders', $o2['id']);
$t('patch moves the status but only touches allowed fields', $v['status'] === 'arrived' && $raw2['clientPhone'] === '0501112222', [$v, $raw2['clientPhone'] ?? null]);
$ev = $flush();
$t('…and the move is reported', in_array('order.status', array_column($ev, 'type'), true));
$n = $call(['op' => 'create', 'phone' => '0507778888', 'name' => 'زبون واتساب', 'service' => 'غسيل 2 مكيف', 'address' => 'العوالي', 'lat' => 21.39, 'lng' => 39.86, 'time' => 'بكرة العصر']);
$ev = $flush();
$t('an order built from a chat is created and reported', ($n['clientPhone'] ?? '') === '966507778888' && $n['preferredTime'] === 'بكرة العصر' && in_array('order.created', array_column($ev, 'type'), true), [$n, $ev]);
$n2 = $call(['op' => 'create', 'phone' => '0507778888', 'service' => 'كمان سخان']);
$t('a second message from the same customer joins his open order', $n2['id'] === $n['id']);
$rp = $call(['op' => 'report', 'what' => 'report']);
$t('reports for the room', strpos($rp['text'], 'تقرير') !== false, $rp);
$t('the site\'s settings for the hub', array_key_exists('testCustomer', $call(['op' => 'config'])));
$g = $call(['op' => 'greet', 'phone' => '0507778888', 'text' => 'السلام عليكم #raad من موقعكم']);
$t('greet: the site he came from and whether he is new', ($g['fromUs'] ?? false) === true && $g['kind'] === 'lead' && $g['site'] !== '', $g);
$t('converse without an AI provider: null, the hub uses its own rules', $call(['op' => 'converse', 'phone' => '0507778888', 'text' => 'بكم الغسيل']) === null);
$q = $call(['op' => 'quote', 'text' => 'بكام غسيل 2 مكيف سبليت']);
$t('quote: AC wash from the owner\'s calculator, with his floor', $q['fixed'] === true && $q['qty'] === 2 && $q['total'] > 0 && $q['floor'] > 0 && $q['floor'] <= $q['total'], $q);
$q2 = $call(['op' => 'quote', 'text' => 'عندي تسريب في الحمام']);
$t('quote: plumbing starts from a price, final after inspection', $q2['spec'] === 'plumbing' && $q2['fixed'] === false && $q2['from'] > 0, $q2);
$t('quote: no service named → null', $call(['op' => 'quote', 'text' => 'السلام عليكم']) === null);
$n3 = $call(['op' => 'create', 'phone' => '0509990000', 'service' => 'غسيل 2 مكيف سبليت', 'address' => 'الشوقية', 'lat' => 21.40, 'lng' => 39.78, 'time' => 'بكرة', 'price' => $q['floor'], 'acQuote' => $q['acQuote'], 'spec' => 'ac']);
$t('an order from the chat keeps the agreed price', (int) (Mutqan_Store::get_raw('orders', $n3['id'])['agreedPrice'] ?? 0) === (int) $q['floor'], Mutqan_Store::get_raw('orders', $n3['id']));
$k = Mutqan_Hub::save_ai_keys(['providers' => ['groq' => ['key' => 'gsk_test_key_1234567890'], 'nope' => ['key' => 'x_1234567890123']]]);
$t('AI keys from the secrets: known providers set, unknown ignored, keys never returned', $k['set'] === ['groq'] && in_array('groq', $k['live'], true) && strpos(json_encode($k), 'gsk_test') === false, $k);
$k2 = Mutqan_Hub::save_ai_keys(['providers' => ['groq' => ['key' => 'gsk_other_key_0987654321']]]);
$t('…a key already on the site is not overwritten', $k2['set'] === [] && Mutqan_AI::settings()['providers']['groq']['key'] === 'gsk_test_key_1234567890', $k2);
$t('unknown op refused', $call(['op' => 'drop_tables']) === 'ERR:bad_op');

/* ---------------------------------------------------------------- the order card and the one house policy */
$cd = Mutqan_Store::insert('orders', ['status' => 'new', 'clientName' => 'علي', 'clientPhone' => '0500000099', 'specialtyId' => 'ac', 'qty' => 2, 'preferredTime' => 'بكرة بعد العصر', 'source' => 'site', 'brand' => 'raad', 'lat' => 21.4, 'lng' => 39.8, 'acQuote' => ['mode' => 'wash', 'type' => 'سبليت', 'qty' => 2]], null, [], $sys);
$card = Mutqan_Rest::order_card(Mutqan_Store::get_raw('orders', $cd['id']) + ['id' => $cd['id']]);
$t('the card says where it came from, the service and count, the time in his words and its date, the map', mb_strpos($card, '🌐 جاي من: ') !== false && mb_strpos($card, 'زر الطلب في الموقع') !== false && mb_strpos($card, 'سبليت × 2') !== false && mb_strpos($card, 'بكرة بعد العصر') !== false && mb_strpos($card, '📅 التاريخ: ') !== false && mb_strpos($card, 'maps.google.com/?q=21.4,39.8') !== false, $card);
$card2 = Mutqan_Rest::order_card(Mutqan_Store::get_raw('orders', $cd['id']) + ['id' => $cd['id']]);
$t('one seal per order: the card is the same every time it is built', $card === $card2, [$card, $card2]);
$evs = $flush(); $ce = null; foreach ($evs as $e) { if (($e['type'] ?? '') === 'order.created' && ($e['order']['id'] ?? '') === $cd['id']) { $ce = $e; } }
$t('the hub hears the new order with its full card', $ce && mb_strpos((string) ($ce['card'] ?? ''), 'جاي من') !== false, $ce);
$hr = Mutqan_AI::house_rules();
// Appointments follow the clock (owner, 2026-11-20): 7 am–10 pm today (or tomorrow if he likes), after 11 pm tomorrow morning.
$bk = Mutqan_Deal::booking();
$t('one house policy: official prices and the appointment rule for this hour', mb_strpos($hr, 'غسيل مكيف سبليت') !== false && mb_strpos($hr, $bk['defaultTime']) !== false && mb_strpos($hr, 'ممنوع تقترح بكرة') === false, $hr);

/* ---------------------------------------------------------------- the confirm button's WhatsApp message claims the order */
$co = Mutqan_Store::insert('orders', ['status' => 'new', 'clientName' => '', 'clientPhone' => '', 'specialtyId' => 'ac', 'claimCode' => '4821', 'brand' => 'raad'], null, [], $sys);
$cn = (string) (Mutqan_Store::get_raw('orders', $co['id'])['number'] ?? '');
$v = $H::op('order', ['id' => $co['id']]);
$t('an order waiting for its WhatsApp message is marked so the hub does not ask for «تأكيد»', !empty($v['waClaim']), $v);
$t('a wrong code takes nothing', $H::op('claim', ['number' => $cn, 'code' => '1111', 'phone' => '+966500000077']) === null);
$r = $H::op('claim', ['number' => $cn, 'code' => '4821', 'phone' => '+966500000077']);
$raw = Mutqan_Store::get_raw('orders', $co['id']);
$t('the right code: his WhatsApp number becomes the order\'s, and it is confirmed', $r && $r['first'] && substr(preg_replace('/\D/', '', $raw['clientPhone']), -9) === '500000077' && !empty($raw['customerConfirmed']) && empty($r['waClaim']), [$r, $raw]);
$t('another number cannot take a claimed order', $H::op('claim', ['number' => $cn, 'code' => '4821', 'phone' => '+966511111111']) === null);

/* ---------------------------------------------------------------- the payment question before the rating (owner, 2026-11-23) */
$po = Mutqan_Store::insert('orders', ['status' => 'completed', 'clientPhone' => '0533334444', 'clientName' => 'دافع', 'agreedPrice' => 180, 'technicianId' => 'u-x', 'technicianName' => 'فني'], null, ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'], $sys);
$mn = $H::op('pay_menu', ['id' => $po['id']]);
$t('pay menu: the amount and the three ways', $mn && strpos($mn['text'], '180 ر.س') !== false && strpos($mn['text'], '1️⃣ كاش') !== false && strpos($mn['text'], '2️⃣ تحويل بنكي') !== false && strpos($mn['text'], '3️⃣ بوابة الدفع الآمنة') !== false, $mn);
Mutqan_Store::insert('payment_methods', ['type' => 'bank_transfer', 'name' => 'تحويل — الراجحي', 'bankName' => 'مصرف الراجحي', 'iban' => 'SA00 0000 1111', 'accountHolder' => 'أكسجين', 'enabled' => true], null, ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'], $sys);
$pk = $H::op('pay_pick', ['id' => $po['id'], 'method' => 'transfer']);
$t('…«2»: which bank, by number', $pk && strpos($pk['text'], '1️⃣ مصرف الراجحي') !== false && (int) $pk['banks'] === 1, $pk);
$bk = $H::op('pay_bank', ['id' => $po['id'], 'idx' => 1]);
$t('…the bank: its name, the IBAN alone (to copy), «send the receipt», nothing about deleting', $bk && strpos($bk['messages'][0]['text'], 'اسم الحساب: أكسجين') !== false && $bk['messages'][1]['text'] === 'SA0000001111' && strpos($bk['messages'][2]['text'], 'صورة الإيصال') !== false && strpos(json_encode($bk, JSON_UNESCAPED_UNICODE), 'بتتمسح') === false, $bk);
$t('…the transcript of a file outside the uploads is refused', $H::op('transcribe', ['url' => 'https://evil.test/x.ogg'])['text'] === '');
$t('…the hotline of the brand he came through', $H::op('hotline', ['phone' => '0599999990', 'brand' => 'raad'])['url'] === 'https://raad-maintenance-general-contracting.oxygen11.com/app/#/call');
$t('…his choice is on the order', (Mutqan_Store::get_raw('orders', $po['id'])['paymentChoice']['method'] ?? '') === 'transfer');
$pk = $H::op('pay_pick', ['id' => $po['id'], 'method' => 'online']);
$t('…«3»: the secure gateway link of this order', $pk && strpos($pk['text'], '#/pay?o=' . $po['id']) !== false, $pk);
$pk = $H::op('pay_pick', ['id' => $po['id'], 'method' => 'cash']);
$t('…«1»: cash to the technician', $pk && strpos($pk['text'], 'كاش للفني') !== false, $pk);
$up = wp_upload_dir(); @mkdir($up['basedir'] . '/mwc', 0777, true);
file_put_contents($up['basedir'] . '/mwc/r1.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
$pr = $H::op('pay_proof', ['id' => $po['id'], 'kind' => 'image', 'url' => $up['baseurl'] . '/mwc/r1.png']);
$pf = Mutqan_Store::get_raw('orders', $po['id'])['paymentProof'] ?? [];
$t('receipt: kept in our private folder, not public, the caption carries the order', $pr && $pr['saved'] && is_file($up['basedir'] . '/mutqan-private/proofs/' . $pf['file']) && is_file($up['basedir'] . '/mutqan-private/.htaccess') && strpos($pr['caption'], '#' . Mutqan_Store::num(Mutqan_Store::get_raw('orders', $po['id']) + ['id' => $po['id']])) !== false && strpos($pr['caption'], 'دافع') !== false, [$pr, $pf]);
$g = Mutqan_Proof::handle(['op' => 'get', 'id' => $po['id']], ['role' => 'operations']);
$t('…operations get it with the facts to stamp on it', strpos($g['image'], 'data:image/png;base64,') === 0 && $g['facts']['client'] === 'دافع', $g['facts']);
$err = null; try { Mutqan_Proof::handle(['op' => 'get', 'id' => $po['id']], ['role' => 'technician']); } catch (Mutqan_Error $e) { $err = $e->slug; }
$t('…a technician cannot open it', $err === 'forbidden');
Mutqan_Proof::handle(['op' => 'stamped', 'id' => $po['id'], 'png' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='], ['role' => 'operations', 'name' => 'عمليات']);
$t('…the stamped copy is kept next to it', !empty(Mutqan_Store::get_raw('orders', $po['id'])['paymentProof']['stamped']));
Mutqan_Store::update('orders', $po['id'], ['paymentStatus' => 'paid'], $sys);
$t('…nothing to ask once it is paid', $H::op('pay_menu', ['id' => $po['id']]) === null);

/* ---------------------------------------------------------------- the seal carries every fact (owner, 2026-11-23) */
$so = Mutqan_Store::insert('orders', ['status' => 'new', 'clientPhone' => '0533336666', 'clientName' => 'سالم', 'specialtyId' => 'ac', 'serviceLabel' => 'غسيل مكيفات — 3 سبليت', 'preferredTime' => 'بكره العصر', 'agreedPrice' => 180, 'lat' => 21.39, 'lng' => 39.86, 'acQuote' => ['mode' => 'wash', 'type' => 'سبليت', 'qty' => 3, 'full' => false]], null, ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'], $sys);
$url = Mutqan_Shop::seal_for_order(Mutqan_Store::get_raw('orders', $so['id']) + ['id' => $so['id']], 180);
preg_match('#/v/([A-Z0-9]{8}-[a-f0-9]{6})#', $url, $sm);
list($sst, $sf) = Mutqan_Shop::seal_check($sm[1] ?? '');
$fj = json_encode($sf['facts'] ?? [], JSON_UNESCAPED_UNICODE);
$t('seal: verified, with the wash scope, the units, the price, the day and the sent time', $sst === 'verified' && strpos($fj, 'سبليت × 3 — غسيل داخلي فقط') !== false && strpos($fj, '180 ر.س') !== false && strpos($fj, 'وقت إرسال الطلب') !== false && strpos($fj, 'تاريخ الموعد') !== false, [$sst, $sf['facts'] ?? null]);
$sf['facts'][0][1] = '#9999';
$t('…a changed fact no longer matches', !hash_equals(substr(hash_hmac('sha256', substr($sm[1], 0, 8) . '|' . wp_json_encode($sf, JSON_UNESCAPED_UNICODE), wp_salt('auth')), 0, 6), substr($sm[1], 9)));

/* ---------------------------------------------------------------- the detailed rating, only to a pleased customer (owner, 2026-11-23) */
$ro = Mutqan_Store::insert('orders', ['status' => 'completed', 'clientPhone' => '0533335555', 'technicianName' => 'الفني مصطفى'], null, ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'], $sys);
$t('detailed rating: asked after 5 stars', ($H::op('traits_ask', ['id' => $ro['id'], 'stars' => 5])['text'] ?? '') !== '');
$t('…not after 3 stars', $H::op('traits_ask', ['id' => $ro['id'], 'stars' => 3]) === null);
Mutqan_Store::update('orders', $ro['id'], ['nudges' => 2], $sys);
$t('…not to a customer who chased the technician twice', $H::op('traits_ask', ['id' => $ro['id'], 'stars' => 5]) === null);
Mutqan_Store::update('orders', $ro['id'], ['nudges' => 0, 'complaint' => ['at' => gmdate('c')]], $sys);
$t('…not to one who complained', $H::op('traits_ask', ['id' => $ro['id'], 'stars' => 5]) === null);
$ts = $H::op('traits_set', ['id' => $ro['id'], 'picks' => [1, 6, 9]]);
$t('…«1 6» saved as words on the order (9 ignored)', $ts['labels'] === ['مظهره وشكله', 'شاطر وشغله نضيف'] && Mutqan_Store::get_raw('orders', $ro['id'])['rating']['traits'] === ['مظهره وشكله', 'شاطر وشغله نضيف'], $ts);

/* ---------------------------------------------------------------- one rating, good and bad per point (owner, 2026-11-24) */
$r1 = Mutqan_Store::insert('orders', ['status' => 'completed', 'clientPhone' => '0533330001', 'technicianId' => 'u-x', 'technicianName' => 'فني'], null, ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'], $sys);
$rm = $H::op('rate_menu', ['id' => $r1['id']]);
$t('rate menu: four points, a good and a bad number each, «رأيك بيساعدنا», nothing about the technician hearing it', $rm && strpos($rm['text'], '1 نضيف ومرتب') !== false && strpos($rm['text'], '8 مش راضي عنه') !== false && strpos($rm['text'], 'رأيك بيساعدنا') !== false && strpos($rm['text'], 'شهادتك') === false && strpos($rm['text'], 'للفني') === false, $rm);
$rs = $H::op('rate_set', ['id' => $r1['id'], 'picks' => [1, 3, 6, 7]]);
$t('…«1 3 6 7»: 3 good, 1 bad → 4 stars, saved with pros and cons', $rs['stars'] === 4 && count($rs['pros']) === 3 && $rs['cons'] === ['التأخير'] && (Mutqan_Store::get_raw('orders', $r1['id'])['rating']['cons'] ?? null) === ['التأخير'], $rs);
$r2 = Mutqan_Store::insert('orders', ['status' => 'completed', 'clientPhone' => '0533330002', 'complaint' => ['text' => 'x']], null, ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'], $sys);
$t('…a customer who complained is not asked', ($H::op('rate_menu', ['id' => $r2['id']])['skip'] ?? false) === true);
$t('…«ممتاز» → 5 stars', $H::op('rate_set', ['id' => $r2['id'], 'picks' => [], 'all' => true])['stars'] === 5);

/* ---------------------------------------------------------------- off again: everything as before */
$H::h_admin(['op' => 'off'], ['role' => 'owner']);
$t('switched off: the site is back on its own WhatsApp code', !$H::on() && Mutqan_WA::open_account() !== 'hub');
exit($ok ? 0 : 1);
