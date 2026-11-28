<?php
// «أرقامنا» and «حملات الاستقطاب» (owner, 2026-11-21): every company number has one job; the AI, the WhatsApp hub and the
// leads base all know them as ours; the sites and articles spread the sales numbers and move them weekly; the campaigns
// go out only from outreach numbers, slowly, to people who are due, and never to ourselves or to anyone who said «إيقاف».
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-lines-' . getmypid() . '-' . bin2hex(random_bytes(4)));
$_SERVER['HTTP_X_SITE_HOST'] = 'oxygen11.com';
require __DIR__ . '/wpshim.php';
if (!function_exists('current_time')) { function current_time($t, $gmt = 0) { return $t === 'mysql' ? gmdate('Y-m-d H:i:s') : time(); } }
if (!function_exists('sanitize_email')) { function sanitize_email($e) { return (string) $e; } }
foreach (['leads', 'lines', 'outreach'] as $x) { if (!class_exists('Mutqan_' . ucfirst($x))) { require_once dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/mutqan/class-mutqan-' . $x . '.php'; } }
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "lines: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$owner = ['kind' => 'user', 'role' => 'owner', 'id' => 'o1', 'powers' => ['read', 'write', 'manage']];
$ops = ['kind' => 'user', 'role' => 'operations', 'id' => 'p1', 'powers' => ['read', 'write']];
$cust = ['kind' => 'user', 'role' => 'customer', 'id' => 'c1', 'powers' => ['read']];

/* ---------------- the registry ---------------- */
$all = Mutqan_Lines::all();
$by = []; foreach ($all as $r) { $by[$r['raw']] = $r; }
$t('the eight numbers are registered, none invalid', count($all) === 8 && !array_filter($all, function ($r) { return $r['status'] === 'invalid'; }), count($all));
$t('the corrected number 0549648890 (Ali\'s test technician) is ours, in reserve, never shown to customers', $by['966549648890']['role'] === 'reserve' && Mutqan_Lines::is_ours('0549648890') && !in_array('966549648890', array_map(function ($r) { return $r['phone']; }, Mutqan_Lines::pool('sales')), true));
$t('…and it stays out of the hub\'s list, so the technician flow on it keeps working', !isset(Mutqan_Lines::hub_config()['ourNumbers']['549648890']));
Mutqan_Store::insert('tech_profiles', ['userId' => 'u-test-ali-tech', 'displayName' => 'علي (فني تجربة)', 'phone' => '966549648890', 'available' => true], 'u-test-ali-tech', [], $owner);
$t('…and the WhatsApp hub still sees him as a technician', Mutqan_Hub::who('0549648890')['kind'] === 'tech', Mutqan_Hub::who('0549648890'));
$t('one manager (0549247636), four sales, two outreach', count(Mutqan_Lines::pool('sales')) === 4 && count(Mutqan_Lines::pool('outreach')) === 2 && $by['966549247636']['role'] === 'manager', array_map(function ($r) { return $r['local'] . ':' . $r['role']; }, $all));
$t('0536554731 moved to the new WhatsApp Center: still a sales number on the sites, flagged so the VPS hub drops its own session', $by['966536554731']['center'] === true && Mutqan_Lines::hub_config()['ourNumbers']['536554731']['center'] === true && in_array('966536554731', array_map(function ($r) { return $r['phone']; }, Mutqan_Lines::pool('sales')), true));
$t('0549423050 too (owner, 2026-11-23: one engine per number — it is linked in the Center)', $by['966549423050']['center'] === true && Mutqan_Lines::hub_config()['ourNumbers']['549423050']['center'] === true);
$t('…and every other number is unaffected (center false by default)', $by['966549665320']['center'] === false && Mutqan_Lines::hub_config()['ourNumbers']['549665320']['center'] === false);
$t('ours() matches any spelling: 05…, +966…, ٠٥…', Mutqan_Lines::is_ours('0549665320') && Mutqan_Lines::is_ours('+966 54 971 5646') && Mutqan_Lines::is_ours('٠٥٤٦٦١٩٢٠٥') && !Mutqan_Lines::is_ours('0500000000'));
$t('the manager waits until the owner marks it active: no manager, the hub keeps its room', Mutqan_Lines::manager() === null && Mutqan_Lines::hub_config()['managerPhone'] === '');

/* ---------------- rotation on the sites and articles ---------------- */
$sales = array_map(function ($r) { return $r['phone']; }, Mutqan_Lines::pool('sales'));
$outr = array_map(function ($r) { return $r['phone']; }, Mutqan_Lines::pool('outreach'));
$seen = []; $week0 = 1795000000;
foreach (range(1, 60) as $post) { $seen[Mutqan_Lines::public_for('raad', $post, '', $week0)['wa']] = 1; }
$t('60 articles spread over the sales numbers, never an outreach or manager number', count($seen) >= 3 && !array_intersect(array_keys($seen), $outr) && !isset($seen['966549247636']), array_keys($seen));
$a = Mutqan_Lines::public_for('raad', 7, '', $week0); $b = Mutqan_Lines::public_for('raad', 7, '', $week0 + 3600); $c = Mutqan_Lines::public_for('raad', 7, '', $week0 + 7 * 86400);
$t('a page keeps its number through the week and moves the next week', $a['wa'] === $b['wa'] && $a['wa'] !== $c['wa'], [$a, $c]);
$art = []; foreach (range(1, 40) as $p) { $art[Mutqan_Lines::public_for('oxygen', $p, 'articles', $week0)['wa']] = 1; }
$t('the «articles» channel uses only the numbers that carry it', !array_diff(array_keys($art), ['966549423050', '966549665320', '966549715646']), array_keys($art));
$k = Oxygen_Network::contacts('raad');
$t('a face with no pinned number shows two different sales numbers', in_array($k['wa'], $sales, true) && in_array($k['wa2'], $sales, true) && $k['wa'] !== $k['wa2'], $k);
$faces = []; foreach (array_keys(Oxygen_Network::registry()) as $id) { $c2 = Oxygen_Network::contacts($id); if ($c2['wa'] !== '') { $faces[$c2['wa']] = 1; } }
$t('across the network the faces use more than one sales number', count($faces) >= 2, array_keys($faces));
Mutqan_Lines::save(['rotate' => false]);
$t('rotation off: the same page shows the same number every week', Mutqan_Lines::public_for('raad', 7, '', $week0)['wa'] === Mutqan_Lines::public_for('raad', 7, '', $week0 + 70 * 86400)['wa']);
Mutqan_Lines::save(['rotate' => true]);

/* ---------------- the AI knows them ---------------- */
$fc = Mutqan_Lines::facts('customer'); $fs = Mutqan_Lines::facts('owner');
$t('customers are told only the sales numbers', strpos($fc, '0549665320') !== false && strpos($fc, '0546619205') === false && strpos($fc, '0549247636') === false, $fc);
$t('staff get every number with its job', strpos($fs, '0546619205') !== false && strpos($fs, 'استقطاب') !== false && strpos($fs, 'المدير') !== false && strpos($fs, '96649648890') === false, $fs);
$ctx = Mutqan_KB::context('عايز رقم الشركة', $cust);
$t('…and it reaches the model through the knowledge base on every channel', strpos($ctx, 'أرقام واتساب الشركة الرسمية') !== false, $ctx);

/* ---------------- the WhatsApp hub ---------------- */
$t('hub: a message from an outreach number is ours (kind line), never a customer', Mutqan_Hub::who('0546619205')['kind'] === 'line');
$t('hub: the manager before it is active is ours but not the room', Mutqan_Hub::who('0549247636')['kind'] === 'line');
$cfg = Mutqan_Hub::op('config', []);
$t('hub config carries every number\'s job', isset($cfg['ourNumbers']['546619205']) && $cfg['ourNumbers']['546619205']['role'] === 'outreach' && $cfg['managerPhone'] === '', $cfg);

/* ---------------- the owner's desk ---------------- */
$denied = function ($fn) { try { $fn(); return false; } catch (Mutqan_Error $e) { return $e->status === 403; } };
$t('a customer cannot see the numbers', $denied(function () use ($cust) { Mutqan_Lines::handle(['op' => 'view'], $cust); }));
$t('operations can see them but not change them', count(Mutqan_Lines::handle(['op' => 'view'], $ops)['numbers']) === 8 && $denied(function () use ($ops) { Mutqan_Lines::handle(['op' => 'save', 'patch' => [['phone' => '966549247636', 'status' => 'active']]], $ops); }));
Mutqan_Lines::handle(['op' => 'save', 'patch' => [['phone' => '966549247636', 'status' => 'active']]], $owner);
$t('the owner activates the manager → it becomes the room (hub config, who)', Mutqan_Lines::manager()['phone'] === '966549247636' && Mutqan_Lines::hub_config()['managerPhone'] === '966549247636' && Mutqan_Hub::who('0549247636')['kind'] === 'staff');
Mutqan_Lines::save(['patch' => [['phone' => '966549715646', 'role' => 'manager', 'status' => 'active']]]);
$mg = array_values(array_filter(Mutqan_Lines::all(), function ($r) { return $r['role'] === 'manager'; }));
$t('one manager only: naming a new one moves the old one to sales', count($mg) === 1 && $mg[0]['raw'] === '966549715646', array_map(function ($r) { return $r['raw']; }, $mg));
Mutqan_Lines::save(['patch' => [['phone' => '966549715646', 'role' => 'sales'], ['phone' => '966549247636', 'role' => 'manager']]]);
Mutqan_Lines::save(['off' => [5, 6, 99]]);
$v = Mutqan_Lines::view(); $offs = array_values(array_map(function ($x) { return $x['n']; }, array_filter($v['sections'], function ($x) { return !$x['on']; })));
$t('the owner stops services (carpentry, cameras): the desk shows them off and the hub hears it (junk ignored)', $offs === [5, 6] && Mutqan_Lines::hub_config()['offSections'] === [5, 6], [$offs, Mutqan_Lines::hub_config()['offSections']]);
Mutqan_Lines::save(['off' => []]);
Mutqan_Store::insert('pricing', ['serviceId' => 'services_off', 'ids' => ['plumbing', 'cameras']], 'services_off', [], $owner);
$t('a service the owner switched off on the site takes its WhatsApp section off too (cameras alone keeps «cameras and satellite» on)', Mutqan_Lines::off_sections() === [3], Mutqan_Lines::off_sections());
Mutqan_Store::delete('pricing', 'services_off', $owner);
Mutqan_Lines::save(['patch' => [['phone' => '966549715646', 'status' => 'paused']]]);
$t('a paused sales number leaves the sites at once, and the hub hears «paused»', !in_array('966549715646', array_map(function ($r) { return $r['phone']; }, Mutqan_Lines::pool('sales')), true) && Mutqan_Lines::hub_config()['ourNumbers']['549715646']['status'] === 'paused');
Mutqan_Lines::save(['patch' => [['phone' => '966549715646', 'status' => 'active']]]);

/* ---------------- the prospects base ---------------- */
$tb = Mutqan_Leads::table();
$wpdb->query("CREATE TABLE IF NOT EXISTS $tb (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT UNIQUE, name TEXT DEFAULT '', city TEXT DEFAULT '', field TEXT DEFAULT '', category TEXT DEFAULT '', address TEXT DEFAULT '', website TEXT DEFAULT '', email TEXT DEFAULT '', rating REAL, reviews INTEGER, lat REAL, lng REAL, link TEXT DEFAULT '', query TEXT DEFAULT '', mobile INTEGER DEFAULT 0, status TEXT DEFAULT 'new', source TEXT DEFAULT 'maps', line TEXT DEFAULT '', sent_count INTEGER DEFAULT 0, last_sent TEXT NULL, replied INTEGER DEFAULT 0, first_seen TEXT, last_seen TEXT)");
update_option(Mutqan_Leads::DBV, '2');
$imp = Mutqan_Leads::import([['phone' => '0546619205', 'city' => 'makkah', 'field' => 'ac', 'name' => 'رقمنا'], ['phone' => '0551112233', 'city' => 'makkah', 'field' => 'ac', 'name' => 'فندق النور']]);
$t('the maps import drops our own numbers', $imp['new'] === 1 && $imp['skipped'] === 1, $imp);
$now = gmdate('Y-m-d H:i:s');
for ($i = 0; $i < 70; $i++) { $wpdb->insert($tb, ['phone' => '9665600' . str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'name' => 'مطعم ' . $i, 'city' => $i % 2 ? 'jeddah' : 'makkah', 'field' => $i % 3 ? 'ac' : 'cold', 'mobile' => 1, 'status' => 'new', 'source' => 'maps', 'first_seen' => $now, 'last_seen' => $now]); }
$wpdb->insert($tb, ['phone' => '966570000001', 'mobile' => 1, 'status' => 'optout', 'source' => 'maps', 'first_seen' => $now, 'last_seen' => $now]);
$wpdb->insert($tb, ['phone' => '966570000002', 'mobile' => 1, 'status' => 'sent', 'source' => 'maps', 'sent_count' => 1, 'last_sent' => gmdate('Y-m-d H:i:s', time() - 3 * 86400), 'first_seen' => $now, 'last_seen' => $now]);
$wpdb->insert($tb, ['phone' => '966570000003', 'mobile' => 0, 'status' => 'new', 'source' => 'maps', 'first_seen' => $now, 'last_seen' => $now]);
$wpdb->insert($tb, ['phone' => '966549665320', 'mobile' => 1, 'status' => 'new', 'source' => 'maps', 'first_seen' => $now, 'last_seen' => $now]);   // slipped in before the registry
// customers who ordered before, one with an open order
Mutqan_Store::insert('orders', ['clientPhone' => '0580000001', 'clientName' => 'أبو خالد', 'service' => 'غسيل 3 مكيفات سبليت', 'status' => 'completed', 'address' => 'جدة الصفا'], null, [], $owner);
Mutqan_Store::insert('orders', ['clientPhone' => '0580000002', 'clientName' => 'سالم', 'service' => 'صيانة مكيف', 'status' => 'assigned'], null, [], $owner);
Mutqan_Store::insert('orders', ['clientPhone' => '0549423050', 'clientName' => 'تجربة', 'service' => 'اختبار', 'status' => 'completed'], null, [], $owner);
$sy = Mutqan_Outreach::sync();
$t('sync: past customers join the base, our own numbers never', $sy['system'] === 2 && !$wpdb->get_var("SELECT id FROM $tb WHERE phone = '966549423050'"), $sy);

/* ---------------- the daily plan ---------------- */
$t('the sending window is 13:00–20:00 Riyadh', Mutqan_Outreach::settings()['from'] === 13 && Mutqan_Outreach::settings()['to'] === 20);
$t('…12:00 and 20:00 are outside it', Mutqan_Outreach::batch([], strtotime('2026-11-22 09:00:00 UTC'))['why'] === 'hours' && Mutqan_Outreach::batch([], strtotime('2026-11-22 17:00:00 UTC'))['why'] === 'hours');
$noon = strtotime('2026-11-22 11:00:00 UTC');   // 14:00 in Riyadh
$lines = [['id' => 'L-out1', 'phone' => '966546619205', 'sentToday' => 0], ['id' => 'L-out2', 'phone' => '966547512660', 'sentToday' => 0], ['id' => 'L-sales', 'phone' => '966549665320', 'sentToday' => 0], ['id' => 'L-x', 'phone' => '966500000099', 'sentToday' => 0]];
$night = Mutqan_Outreach::batch($lines, strtotime('2026-11-22 20:00:00 UTC'));
$t('nobody is invited at night (23:00 Riyadh)', !$night['items'] && $night['why'] === 'hours', $night);
$b1 = Mutqan_Outreach::batch($lines, $noon);
$used = array_unique(array_column($b1['items'], 'line'));
sort($used);
$t('only the outreach lines send — never a sales line or a stranger\'s line', $used === ['L-out1', 'L-out2'], $used);
$t('at most perCall (4) per line per request', count($b1['items']) === 8, count($b1['items']));
$ph = array_column($b1['items'], 'phone');
$t('past customers first', $ph[0] === '966580000001' || in_array('966580000001', array_slice($ph, 0, 2), true), $ph);
$t('never to an opt-out, a landline, ourselves, someone with an open order, or someone inside the cooldown', !array_intersect($ph, ['966570000001', '966570000003', '966549665320', '966580000002', '966570000002']), $ph);
$cust1 = array_values(array_filter($b1['items'], function ($i) { return $i['phone'] === '966580000001'; }));
$t('a past customer\'s text: his name and his last job, and how to stop', $cust1 && strpos($cust1[0]['text'], 'أبو خالد') !== false && strpos($cust1[0]['text'], 'غسيل 3 مكيفات') !== false && strpos($cust1[0]['text'], 'إيقاف') !== false, $cust1);
$texts = array_unique(array_map(function ($i) { return mb_substr($i['text'], 0, 40); }, $b1['items']));
$t('the texts and the company names vary across the batch', count($texts) >= 3, $texts);
$t('no {placeholders} left in any text', !preg_match('/\{\w+\}/', implode("\n", array_column($b1['items'], 'text'))));
$row = $wpdb->get_row("SELECT * FROM $tb WHERE phone = '" . $ph[1] . "'");
$t('a sent prospect is marked: sent, count 1, the line that wrote to him', $row['status'] === 'sent' && (int) $row['sent_count'] === 1 && in_array($row['line'], ['546619205', '547512660'], true), $row);

// the line's ceiling: 20 on its first day
$capped = $lines; $capped[0]['sentToday'] = 20; $capped[1]['sentToday'] = 18;
$b2 = Mutqan_Outreach::batch($capped, $noon + 600);
$t('first day: a line stops at 20 (18 sent → 2 more; 20 sent → none)', count($b2['items']) === 2 && array_unique(array_column($b2['items'], 'line')) === ['L-out2'], array_column($b2['items'], 'line'));
$t('…the next day the ceiling is 25, and it never goes above 60', Mutqan_Outreach::cap_for('546619205', $noon + 86400) === 25 && Mutqan_Outreach::cap_for('546619205', $noon + 40 * 86400) === 60);
// sticky line: a prospect contacted by out1 is not taken by out2 when out1 has no room
$wpdb->query("UPDATE $tb SET last_sent = '2026-01-01 00:00:00' WHERE line = '546619205'");
$b3 = Mutqan_Outreach::batch([['id' => 'L-out2', 'phone' => '966547512660', 'sentToday' => 0]], $noon + 1200);
$moved = array_filter($b3['items'], function ($i) use ($wpdb, $tb) { return $wpdb->get_var("SELECT line FROM $tb WHERE phone = '" . $i['phone'] . "'") !== '547512660'; });
$t('a prospect keeps the line that wrote to him first (his reply lands in the same chat)', !$moved, $moved);

// «إيقاف»
$t('is_stop: «إيقاف», «stop», «لا ترسل» yes; «وقف الطلب» no', Mutqan_Outreach::is_stop('إيقاف') && Mutqan_Outreach::is_stop('STOP') && Mutqan_Outreach::is_stop('لا ترسل') && !Mutqan_Outreach::is_stop('وقف الطلب'));
Mutqan_Outreach::optout('0560000005');
$t('«إيقاف» takes him out for good', $wpdb->get_var("SELECT status FROM $tb WHERE phone = '966560000005'") === 'optout');
Mutqan_Outreach::optout('0591234567');
$t('…even someone who was never in the base (kept as opt-out)', $wpdb->get_var("SELECT status FROM $tb WHERE phone = '966591234567'") === 'optout');
// off
Mutqan_Outreach::save(['on' => 0]);
$t('campaigns off → nothing', Mutqan_Outreach::batch($lines, $noon + 3600)['why'] === 'off');
Mutqan_Outreach::save(['on' => 1, 'from' => 22, 'to' => 5, 'max' => 3, 'start' => 10]);
$s = Mutqan_Outreach::settings();
$t('bad settings are corrected (to after from, max not below start)', $s['to'] > $s['from'] && $s['max'] >= $s['start'], $s);
$t('operations can see the plan, only the owner changes it', isset(Mutqan_Outreach::handle(['op' => 'view'], $ops)['stats']) && $denied(function () use ($ops) { Mutqan_Outreach::handle(['op' => 'save', 'settings' => ['on' => 0]], $ops); }));
$t('the hub ops are wired (outreach_batch / optout / reply)', isset(Mutqan_Hub::op('outreach_batch', ['lines' => []])['items']) && Mutqan_Hub::op('outreach_optout', ['phone' => '0590000009'])['ok'] && Mutqan_Hub::op('outreach_reply', ['phone' => '0590000009'])['ok']);

echo $ok ? "ALL PASSED\n" : "SOME FAILED\n";
exit($ok ? 0 : 1);
