<?php
// «ملف العملاء»: profile from the orders, welcome by site/article/history, CSV for a WhatsApp sender, opt-out,
// regular follow-ups (capped, once a month, never after «إيقاف»), quick information for customers / technicians / team.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-customers-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "customers: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$C = 'Mutqan_Customers'; $W = 'Mutqan_WA';
$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
$owner = ['kind' => 'user', 'role' => 'owner', 'id' => 'o1', 'name' => 'علي', 'powers' => ['read', 'write', 'manage']];
Mutqan_WA_Journey::set_enabled(false);
$sat = Oxygen_Network::satellites(); $site = $sat[0] ?? 'khabeer';

$old = gmdate('c', time() - 95 * 86400);
Mutqan_Store::insert('orders', ['status' => 'completed', 'clientName' => 'أبو سعد', 'clientPhone' => '0555111222', 'service' => 'غسيل مكيف سبليت', 'specialtyId' => 'ac', 'brand' => $site, 'createdAt' => $old, 'completedAt' => $old, 'agreedPrice' => 200, 'rating' => ['stars' => 5]], null, [], $sys);
Mutqan_Store::insert('orders', ['status' => 'completed', 'clientName' => 'أبو سعد', 'clientPhone' => '966555111222', 'service' => 'صيانة مكيف', 'specialtyId' => 'ac', 'brand' => $site, 'completedAt' => gmdate('c', time() - 200 * 86400), 'agreedPrice' => 150], null, [], $sys);

$p = $C::profile('+966555111222');
$t('one customer across phone spellings', $p['orders'] === 2 && $p['done'] === 2 && $p['name'] === 'أبو سعد', $p);
$t('he is a returning customer with his last service', $p['kind'] === 'returning' && $p['lastService'] === 'غسيل مكيف — 1 سبليت' && $p['rating'] == 5, $p);
$b = $C::brief('0555111222');
$t('a one-line brief for the team', strpos($b, 'عميل سابق') !== false && strpos($b, 'غسيل مكيف — 1 سبليت') !== false, $b);
$t('an unknown number is a new customer', strpos($C::brief('0500000001'), 'عميل جديد') !== false);

$w = $C::welcome('966555111222', ['brand' => $site]);
$t('an old customer is greeted by name with his last job', strpos($w, 'أهلًا بيك تاني يا أبو سعد') !== false && strpos($w, 'غسيل مكيف — 1 سبليت') !== false, $w);
$w = $C::welcome('966500000009', ['brand' => $site, 'source' => ['title' => 'غسيل المكيفات في مكة']]);
$biz = $C::business($site);
$t('a new one is greeted by the site\'s business and the article he read', strpos($w, '*' . $biz . '*') !== false && strpos($w, 'غسيل المكيفات في مكة') !== false, $w);

// the file
Mutqan_Store::insert('tech_profiles', ['userId' => 'u-t9', 'displayName' => 'سامي', 'phone' => '0577000999'], 'u-t9', [], $sys);
$C::set_optout('0555111222');
$x = $C::export_csv();
$t('the CSV has a header, the customer and the technician', strpos($x['csv'], 'الجوال') !== false && strpos($x['csv'], '+966555111222') !== false && strpos($x['csv'], 'سامي') !== false && $x['count'] === 2, $x);
$t('…with the opt-out marked', (bool) preg_match('/\+966555111222[^\n]*,نعم\n/u', $x['csv']), $x['csv']);
$r = $W::handle(['op' => 'contacts_export'], $owner);
$t('the owner downloads it from the app', ($r['count'] ?? 0) === 2);

// follow-ups: opted-out customer is skipped; a fresh one is reminded once
$C::set_optout('0555111222', false);
$D = $W::handle(['op' => 'fleet_register', 'name' => 'VPS', 'kind' => 'vps'], $owner);
$raw = json_encode(['device' => $D['id'], 'sessions' => []]); $W::pull_signed($raw, hash_hmac('sha256', $raw, $D['secret']));
$L1 = $W::handle(['op' => 'link', 'phone' => '0501110001', 'role' => 'customers'], $owner);
$ev = json_encode(['type' => 'status', 'accountId' => $L1['id'], 'status' => 'open', 'phone' => '966501110001']); $W::webhook($ev, hash_hmac('sha256', $ev, $D['secret']));
define('MQ_FORCE_FOLLOWUPS', 1);
$n = $C::followups();
$jobs = function () use ($W, $D) { $raw = json_encode(['device' => $D['id'], 'sessions' => []]); return $W::pull_signed($raw, hash_hmac('sha256', $raw, $D['secret']))['jobs'] ?? []; };
$js = $jobs();
$m = array_values(array_filter($js, function ($j) { return substr($j['to'], -9) === '555111222'; }));
$t('a 3-month A/C maintenance reminder goes out', $n === 1 && $m && strpos($m[0]['text'], 'الغسيل الدوري') !== false && strpos($m[0]['text'], 'إيقاف') !== false, [$n, $js]);
$t('…signed with the business', $m && strpos($m[0]['text'], '— *' . $biz . '*') !== false, $m[0]['text'] ?? null);
$t('not twice in a month', $C::followups() === 0);

// «إيقاف» from WhatsApp
$hook = function ($ev) use ($W, $D) { $raw = json_encode($ev, JSON_UNESCAPED_UNICODE); return $W::webhook($raw, hash_hmac('sha256', $raw, $D['secret'])); };
$hook(['type' => 'message', 'accountId' => $L1['id'], 'msgId' => 'x1', 'direction' => 'in', 'phone' => '966555111222', 'text' => 'إيقاف', 'ts' => time()]);
$t('«إيقاف» stops the regular messages', $C::opted_out('0555111222'));

// quick information
$hook(['type' => 'message', 'accountId' => $L1['id'], 'msgId' => 'x2', 'direction' => 'in', 'phone' => '966555333444', 'text' => 'طرق الدفع', 'ts' => time()]);
$m = array_values(array_filter($jobs(), function ($j) { return substr($j['to'], -9) === '555333444'; }));
$t('a customer asking «طرق الدفع» gets the methods', $m && strpos($m[0]['text'], 'نقدًا للفني') !== false, $m);
$t('the team sees the discount policy, the customer does not', strpos($C::info('offers', 'staff'), 'أقصى خصم') !== false && strpos($C::info('offers', 'customer'), 'أقصى خصم') === false);
$t('specialties listed', strpos($C::info('specs', 'tech'), 'سباكة') !== false);

// a technician not on file
$hook(['type' => 'message', 'accountId' => $L1['id'], 'msgId' => 'x3', 'direction' => 'in', 'phone' => '966577000111', 'text' => 'انا فني تبعكم في طلبات؟', 'ts' => time()]);
$m = array_values(array_filter($jobs(), function ($j) { return substr($j['to'], -9) === '577000111'; }));
$t('an unknown technician is told his number is being added', $m && strpos($m[0]['text'], 'مش متسجل') !== false, $m);

echo $ok ? "customers: all passed\n" : "customers: FAILED\n";
exit($ok ? 0 : 1);
