<?php
// «المستقبِل الموحّد» (owner, 2026-11-20): WhatsApp and the website chat answer from one brain — same orders, same
// prices, same offers, same «where is the technician», same cancel — and a WhatsApp order is priced and dispatched.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
require dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/mutqan/class-mutqan-command.php';
Mutqan_Store::maybe_install();
$ok = true; $n = 0;
$t = function ($name, $c, $x = null) use (&$ok, &$n) { $n++; echo ($c ? 'PASS ' : 'FAIL ') . 'concierge: ' . $name . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
$sh = ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'];
$C = 'Mutqan_Concierge';

$tech = Mutqan_Auth::make_member('فني التكييف', '0532223333', 'technician');
Mutqan_Store::upsert('tech_profiles', ['userId' => $tech['id'], 'displayName' => 'فني التكييف', 'active' => true, 'specialties' => ['ac'], 'lat' => 21.42, 'lng' => 39.82, 'lastSeenAt' => gmdate('c')], $tech['id'], $sh, $sys);
$cust = ['id' => 'u-cust-fahd'];
$GLOBALS['wpdb']->insert(Mutqan_Store::table('users'), ['id' => $cust['id'], 'handle' => '0551234567', 'display_name' => 'أبو فهد', 'phone' => '0551234567', 'password_hash' => 'x', 'role' => null, 'status' => 'active', 'created_at' => gmdate('Y-m-d H:i:s')]);
$cp = ['id' => $cust['id'], 'role' => 'customer', 'kind' => 'user', 'powers' => [], 'name' => 'أبو فهد'];
$guest = ['id' => 'g-1', 'role' => null, 'kind' => 'guest', 'powers' => []];
$o = Mutqan_Store::insert('orders', ['number' => '1024', 'status' => 'assigned', 'specialtyId' => 'ac', 'clientName' => 'أبو فهد', 'clientPhone' => '966551234567', 'clientId' => $cust['id'], 'technicianId' => $tech['id'], 'technicianName' => 'فني التكييف', 'preferredTime' => 'اليوم بعد العصر'], null, $sh, $sys);
$other = Mutqan_Store::insert('orders', ['number' => '1030', 'status' => 'new', 'specialtyId' => 'plumbing', 'clientName' => 'غيره', 'clientPhone' => '966559990000'], null, $sh, $sys);
Mutqan_Store::insert('offers', ['title' => 'عرض الصيف', 'description' => 'غسيل 3 مكيفات', 'price' => 180, 'enabled' => true], null, $sh, $sys);

$wa = function ($text, $phone = '966551234567') use ($C) { return $C::answer($text, ['channel' => 'wa', 'phone' => $phone, 'verified' => true]); };
$site = function ($text, $p, $phone = '') use ($C) { return $C::handle(['text' => $text, 'phone' => $phone], $p); };

/* ================================================================== the same answer on both doors */
foreach (['طلب 1024 وصل لفين؟', 'طرق الدفع', 'الضمان', 'العروض'] as $q) {
    $a = $wa($q); $b = $site($q, $cp);
    $first = function ($r) { return $r ? explode("\n", (string) $r['reply'])[0] : null; };   // what was done just now («asked the technician» / «he was asked») may differ
    $t('«' . $q . '» → the same reply on WhatsApp and on the site', $a && $b && $first($a) === $first($b) && $a['reply'] !== '' && $a['kind'] === $b['kind'], [$a['reply'] ?? null, $b['reply'] ?? null]);
}
$a = $wa('طلب 1024 وصل لفين؟');
$t('the order answer comes from the order: technician and step', mb_strpos($a['reply'], 'فني التكييف') !== false && $a['order'] === $o['id'], $a);
$b = $site('طلب 1024 وصل لفين؟', $cp);
$t('the site also gets a tap to the order page', ($b['links'][0]['href'] ?? '') === '#/order/' . $o['id'], $b);
$a = $wa('الفني فين؟'); $b = $site('الفني فين؟', $cp);
$t('«الفني فين؟» (signed in on the site, or on WhatsApp) → answered from the live order', $a && $b && $a['kind'] === 'service:where' && $b['kind'] === 'service:where' && mb_strpos($b['reply'], 'فني التكييف') !== false, [$a, $b]);
$t('«عرض الصيف» appears in the offers answer', mb_strpos($wa('العروض')['reply'], 'عرض الصيف') !== false);

/* ================================================================== who may see and do what */
$g = $site('الفني فين؟', $guest, '0551234567');
$t('a guest who typed a phone gets no order without its number', $g && $g['kind'] === 'need_ref' && mb_strpos($g['reply'], 'رقم الطلب') !== false, $g);
$g = $site('طلب 1024 فين؟', $guest, '0551234567');
$t('…with the order number AND the matching phone: the status', $g && $g['kind'] === 'order_status' && $g['order'] === $o['id'], $g);
$g = $site('طلب 1024 فين؟', $guest, '0500000000');
$t('…with someone else\'s phone: not found', $g && $g['kind'] === 'order_missing', $g);
$g = $site('الغي طلب 1024', $guest, '0551234567');
$t('a guest never cancels — status only', ($g['kind'] ?? '') === 'order_status' && (Mutqan_Store::get_raw('orders', $o['id'])['status'] ?? '') === 'assigned', $g);
$x = $wa('طلب 1030 فين', '966551234567');
$t('a customer cannot open another customer\'s order by its number', $x && $x['kind'] === 'order_missing', $x);
$t('«خصم» is a haggle, not the offers list (topic no longer hijacks it)', Mutqan_Customers::topic('خصم') === '' && $wa('خصم') === null);
$t('«بكام غسيل 3 مكيفات» goes to the model (sales), not a canned answer', $wa('بكام غسيل 3 مكيفات') === null);

/* ================================================================== the model's facts — the same block on both doors */
$ctx = $C::context('بكام غسيل 3 مكيفات سبليت', '966551234567', '');
$t('the model sees his orders', mb_strpos($ctx, '#1024') !== false && mb_strpos($ctx, 'فني التكييف') !== false, $ctx);
$t('…the official price of what he asks (3 split = 200)', mb_strpos($ctx, '200 ر.س') !== false, $ctx);
$t('…and the live offers', mb_strpos($ctx, 'عرض الصيف') !== false, $ctx);
$t('…never another customer\'s order', mb_strpos($ctx, '#1030') === false);
$ctx2 = $C::context('عندكم سباكة وكهربا؟', '', '');
$t('«عندكم سباكة وكهربا؟» → the matching services', mb_strpos($ctx2, 'سباكة') !== false && mb_strpos($ctx2, 'كهرباء') !== false, $ctx2);
$wp = $C::inject("العميل (آخر رسالة): بكام غسيل مكيفين", ['feature' => 'wa_reception', 'sales' => ['phone' => '966551234567']], $sys);
$sp = $C::inject("العميل: بكام غسيل مكيفين", ['feature' => 'chat', 'sales' => ['phone' => '966551234567']], $cp);
$t('WhatsApp and the signed-in site chat give the model the same facts', mb_strpos($wp, '#1024') !== false && mb_strpos($sp, '#1024') !== false && mb_strpos($wp, '140 ر.س') !== false && mb_strpos($sp, '140 ر.س') !== false, [mb_substr($wp, 0, 400), mb_substr($sp, 0, 400)]);
$gp = $C::inject("العميل: بكام غسيل مكيفين", ['feature' => 'chat', 'sales' => ['phone' => '966551234567']], $guest);
$t('a guest\'s typed phone does not open anyone\'s orders to the model', mb_strpos($gp, '#1024') === false && mb_strpos($gp, '140 ر.س') !== false, mb_substr($gp, 0, 400));
$t('staff assistants get none of it', $C::inject("العميل: x", ['feature' => 'agent'], ['id' => 'u-ops', 'role' => 'operations', 'kind' => 'user']) === "العميل: x");

/* ================================================================== a WhatsApp order is priced and dispatched */
Mutqan_Store::upsert('assignment_settings', ['mode' => 'auto', 'strategy' => 'smart'], 'default', $sh, $sys);
$acct = 'acct-test';
$id = Mutqan_WA::lead_to_order($acct, '966557000111', ['service' => 'غسيل 3 مكيفات سبليت', 'area' => 'العزيزية', 'name' => 'سالم', 'time' => 'اليوم — أقرب وقت ممكن', 'price' => '180 ريال'], 't-wa-1');
$w = $id ? Mutqan_Store::get_raw('orders', $id) : null;
$t('WhatsApp order: service type, quantity and official price on the order', $w && ($w['specialtyId'] ?? '') === 'ac' && (int) ($w['qty'] ?? 0) === 3 && (int) ($w['listPrice'] ?? 0) === 200, $w);
$t('…the agreed price from the chat (180)', $w && (int) ($w['agreedPrice'] ?? 0) === 180, $w['agreedPrice'] ?? null);
$t('…and it was dispatched to the AC technician at once', $w && ($w['technicianId'] ?? '') === $tech['id'], [$w['technicianId'] ?? null, $w['status'] ?? null]);
$id2 = Mutqan_WA::lead_to_order($acct, '966557000222', ['service' => 'غسيل مكيف سبليت', 'area' => 'الشرائع', 'price' => '40'], 't-wa-2');
$w2 = $id2 ? Mutqan_Store::get_raw('orders', $id2) : null;
$t('a price under the floor never blocks the order — kept as a note for operations', $w2 && !isset($w2['agreedPrice']) && mb_strpos((string) ($w2['note'] ?? ''), 'أقل من المسموح') !== false, $w2);

/* ================================================================== the WhatsApp reception uses the same brain first */
$out = Mutqan_WA_AI::decide([], 'طلب 1024 فين', ['brand' => 'oxygen'], '966551234567', time(), 't-x');
$t('WhatsApp reception: «طلب 1024 فين» answered by the shared brain, no model', !empty($out['replies'][0]) && mb_strpos($out['replies'][0], 'فني التكييف') !== false, $out);
$out = Mutqan_WA_AI::decide([], 'طرق الدفع', [], '966551234567', time(), 't-y');
$t('…and «طرق الدفع» the same text as the site', ($out['replies'][0] ?? '') === $site('طرق الدفع', $cp)['reply']);

/* ================================================================== the VPS WhatsApp line asks the same brain */
$cfg = Mutqan_Hub::op('config', []);
$t('the VPS line takes the owner\'s hours from the site (7 → 23)', ($cfg['hoursFrom'] ?? null) === 7 && ($cfg['hoursTo'] ?? null) === 23, $cfg);
$h = Mutqan_Hub::op('answer', ['phone' => '966551234567', 'text' => 'طلب 1024 وصل لفين؟']);
$t('VPS «answer» = the site\'s answer', in_array($h['kind'] ?? '', ['order_status', 'service:where'], true) && $h['order'] === $o['id'] && mb_strpos($h['reply'], 'فني التكييف') !== false, $h);
$h = Mutqan_Hub::op('answer', ['phone' => '966551234567', 'text' => 'بكام غسيل مكيف']);
$t('…and nothing canned for a sales question', array_key_exists('reply', $h) && $h['reply'] === null, $h);
$vo = Mutqan_Store::insert('orders', ['number' => '1077', 'status' => 'new', 'specialtyId' => 'ac', 'clientName' => 'ع', 'clientPhone' => '966554440000'], null, $sh, $sys);
$h = Mutqan_Hub::op('cancel', ['id' => $vo['id'], 'text' => 'الغي']);
$t('VPS «cancel» uses the one procedure (cancelled before a technician accepted)', ($h['done'] ?? '') === 'cancelled' && (Mutqan_Store::get_raw('orders', $vo['id'])['status'] ?? '') === 'cancelled', $h);
$hp = (new ReflectionMethod('Mutqan_Hub', 'converse'));
$t('the VPS conversation follows the site\'s appointment rule', strpos(file_get_contents(dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/mutqan/class-mutqan-hub.php'), "Mutqan_Deal::booking()['rule']") !== false);

/* ================================================================== the owner's test conversation (2026-11-21) */
$q = Mutqan_Hub::quote(Mutqan_Playbook::norm('غسيل مكيف اسبليت و مكيف شباك'));
$t('«مكيف اسبليت و مكيف شباك» = 1 split + 1 window = 160 (was read as one unit)', ($q['total'] ?? 0) === 160, $q);
$q = Mutqan_Hub::quote(Mutqan_Playbook::norm('غسيل ٢ مكيف و احد شباك و واحد اسبليت'));
$t('«٢ مكيف و احد شباك و واحد اسبليت» = 160', ($q['total'] ?? 0) === 160, $q);
$ap = Mutqan_Hub::op('ac_price', ['split' => 3, 'window' => 2]);
$t('ac_price: 3 split + 2 window = 300, with the lines and a floor under it', $ap['total'] === 300 && count($ap['lines']) === 2 && $ap['floor'] < 300 && $ap['floor'] >= 260, $ap);
$g = Mutqan_Hub::price_guard_reply('تمام اتفقنا على 100 ريال للمكيفين', ['service' => 'غسيل مكيف سبليت و مكيف شباك'], '');
$t('the model agreeing to 100 for the two is overruled with the floor', $g && $g['price'] >= 140 && mb_strpos($g['reply'], '160') !== false, $g);
$t('…a price at or above the floor is left alone', Mutqan_Hub::price_guard_reply('السعر 160 ريال', ['service' => 'غسيل مكيف سبليت و مكيف شباك'], '') === null);
$pa = Mutqan_Hub::parts_answer('بكام المكثف');
$t('«بكام المكثف» → that repair only (150), no list, no «شامل التركيب»', mb_strpos($pa['text'], '150 ر.س') !== false && mb_strpos($pa['text'], 'الفريون') === false && mb_strpos($pa['text'], 'شامل') === false && mb_strpos($pa['text'], 'سعر القطعة') === false, $pa);
$pa = Mutqan_Hub::parts_answer('الكمبروسر بكام');
$t('the compressor: no price, «after the inspection»', mb_strpos($pa['text'], 'بعد الكشف') !== false && !preg_match('/\d{3} ر\.س/u', $pa['text']), $pa);
$t('the thermostat: 50 + 50', mb_strpos(Mutqan_Hub::parts_answer('الترموستات')['text'], '100 ر.س') !== false);
$pp = Mutqan_Hub::parts_for_prompt();
$t('the model gets the strategy (we sell the repair) and the internal margins marked as internal', mb_strpos($pp, 'مش محل قطع غيار') !== false && mb_strpos($pp, 'داخلي') !== false);
$t('the parts reach the AI price sheet', mb_strpos(Mutqan_AI::price_sheet(), 'تغيير المكثف') !== false);
$oc = Mutqan_Hub::op('create', ['phone' => '966553330001', 'service' => 'غسيل 1 سبليت + 1 شباك', 'address' => 'الشرائع', 'lat' => 21.44, 'lng' => 39.88, 'time' => 'اليوم — أقرب وقت ممكن', 'price' => 150, 'spec' => 'ac', 'confirmed' => true, 'units' => 2, 'acQuote' => ['mode' => 'wash', 'items' => [['type' => 'سبليت', 'qty' => 1], ['type' => 'شباك', 'qty' => 1]]]]);
$or = Mutqan_Store::get_raw('orders', $oc['id']);
$t('a menu order arrives confirmed with its mixed AC quote and agreed price', !empty($or['customerConfirmed']) && (int) $or['agreedPrice'] === 150 && count($or['acQuote']['items'] ?? []) === 2, $or);
$nl = null; try { Mutqan_Hub::op('create', ['phone' => '966553330009', 'service' => 'غسيل 1 سبليت', 'address' => 'حي العوالي', 'time' => 'بكرة', 'spec' => 'ac', 'confirmed' => true]); } catch (Mutqan_Error $e) { $nl = $e->code_str ?? $e->getMessage(); }
$t('an order with a neighbourhood name and no pin is refused (need_location)', $nl !== null && !Mutqan_Store::select('orders', ['clientPhone' => '966553330009'], ['limit' => 1], $sys), $nl);
$o2 = Mutqan_Hub::op('create', ['phone' => '966553330002', 'lat' => 21.45, 'lng' => 39.86, 'service' => 'غسيل 1 سبليت + 1 شباك', 'price' => 100, 'spec' => 'ac', 'confirmed' => true, 'acQuote' => ['mode' => 'wash', 'items' => [['type' => 'سبليت', 'qty' => 1], ['type' => 'شباك', 'qty' => 1]]]]);
$r2 = Mutqan_Store::get_raw('orders', $o2['id']);
$t('…and 100 for the two never lands on an order: the price is dropped, operations get a note, the order is kept', empty($r2['agreedPrice']) && mb_strpos((string) ($r2['note'] ?? ''), 'أقل من المسموح') !== false, $r2);

/* ================================================================== «بيانات الخدمة» in the site's database */
delete_option('mutqan_service_kit');
$k = Mutqan_Kit::get();
$t('the service data is written to the database on first use', is_array(get_option('mutqan_service_kit')) && count($k['washVideos']) === 3 && count($k['parts']) >= 9);
$cf = Mutqan_Hub::op('config', []);
$t('the WhatsApp line (any server) takes its videos from the database', count($cf['washVideos'] ?? []) === 3 && ($cf['washVideos'][2]['url'] ?? '') === 'https://youtu.be/qz8gaYjDHmI', $cf);
$t('every customer AI gets the rules, the videos and «we sell the repair»', mb_strpos(Mutqan_AI::house_rules(), 'بيانات الخدمة') !== false && mb_strpos(Mutqan_AI::house_rules(), 'qz8gaYjDHmI') !== false && mb_strpos(Mutqan_AI::house_rules(), 'مش محل قطع غيار') !== false);
$owner = ['id' => 'u-owner', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'علي'];
$ops = ['id' => 'u-ops', 'role' => 'operations', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'العمليات'];
$r = Mutqan_Quick::try_run('بيانات الخدمة', $owner);
$t('owner: «بيانات الخدمة» shows what is stored', $r && mb_strpos($r['text'], 'فيديوهات الغسيل') !== false && mb_strpos($r['text'], 'تغيير المكثف') !== false, $r);
Mutqan_Command::run('kit_part', ['id' => 'capacitor', 'from' => 120], $owner);
$t('owner changes a repair price → every channel quotes it (120 + 50 = 170)', mb_strpos(Mutqan_Hub::parts_answer('بكام المكثف')['text'], '170 ر.س') !== false, Mutqan_Hub::parts_answer('بكام المكثف'));
Mutqan_Command::run('kit_part', ['id' => 'compressor', 'from' => 900, 'to' => 1400, 'noPrice' => false], $owner);
$t('…and the compressor gets a price when the owner has it from the shops', mb_strpos(Mutqan_Hub::parts_answer('الكمبروسر')['text'], '900') !== false);
Mutqan_Command::run('kit_video', ['type' => 'window', 'url' => 'https://youtu.be/NEWWINDOW', 'replace' => true], $owner);
$t('owner replaces the window video', in_array('https://youtu.be/NEWWINDOW', array_column(Mutqan_Kit::videos('window'), 'url'), true) && count(Mutqan_Kit::videos('window')) === 1);
$t('operations cannot change the service data', !Mutqan_Command::allowed('kit_part', $ops) && Mutqan_Command::allowed('kit_get', $ops));
delete_option('mutqan_service_kit');

echo $ok ? "ALL PASS concierge ($n)\n" : "FAILED concierge\n";
exit($ok ? 0 : 1);
