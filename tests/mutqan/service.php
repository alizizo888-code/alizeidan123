<?php
// «السستم مش بيفرّق بين غسيل وصيانة وتركيب ولا بين أنواع المكيفات» (owner, 2026-11-21): one reading of every order —
// the mode, the A/C types with their counts — written on the order and used by every message.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $n = 0;
$t = function ($name, $c, $x = null) use (&$ok, &$n) { $n++; echo ($c ? 'PASS ' : 'FAIL ') . 'service: ' . $name . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$S = 'Mutqan_Service';
$L = function ($o) use ($S) { return $S::read($o)['label']; };

$t('wash, two types with counts', $L(['service' => 'غسيل 2 مكيف سبليت وشباك', 'specialtyId' => 'ac']) === 'غسيل مكيفات — 2 سبليت + 1 شباك', $L(['service' => 'غسيل 2 مكيف سبليت وشباك', 'specialtyId' => 'ac']));
$t('repair named, wash quoted → repair, with a warning for operations', ($r = $S::read(['description' => 'صيانة 2 مكيف شباك (يُسعَّر بعد الفحص)', 'specialtyId' => 'ac', 'acQuote' => ['mode' => 'wash', 'type' => 'شباك', 'qty' => 2, 'total' => 100]]))['mode'] === 'repair' && $r['units'] === ['window' => 2] && $r['warn'] !== '', $r);
$t('«مش بيبرد» on a duct → repair / duct', $L(['service' => 'المكيف الداكت مش بيبرد', 'specialtyId' => 'ac']) === 'صيانة وإصلاح مكيف — 1 داكت');
$t('concealed + package with counts', $L(['service' => 'غسيل 3 كونسيلد و 1 باكيدج']) === 'غسيل مكيفات — 3 كونسيلد + 1 باكيدج');
$t('«فك وتركيب سبليت دولابي» → move / one standing', $L(['service' => 'فك وتركيب مكيف سبليت دولابي']) === 'فك وتركيب مكيف — 1 دولابي');
$t('central with a leak → repair / central', $L(['service' => 'تكييف مركزي فيه تسريب مية']) === 'صيانة وإصلاح مكيف — 1 مركزي');
$t('«كاسيت عدد 4 تنظيف» → wash 4 cassette', $L(['service' => 'كاسيت عدد 4 تنظيف']) === 'غسيل مكيفات — 4 كاسيت');
$t('«فك مكيف شباك بس» → remove', $L(['service' => 'فك مكيف شباك بس', 'specialtyId' => 'ac']) === 'فك مكيف — 1 شباك');
$t('«المكيف واقف» is a fault, not a standing unit', $S::read(['service' => 'المكيف السبليت واقف', 'specialtyId' => 'ac'])['mode'] === 'repair' && !isset($S::read(['service' => 'المكيف السبليت واقف', 'specialtyId' => 'ac'])['units']['standing']));
$t('the specialty name «صيانة وتكييف» is not read as repair', $L(['service' => 'صيانة وتكييف', 'specialtyId' => 'ac', 'acQuote' => ['mode' => 'wash', 'type' => 'سبليت', 'qty' => 1, 'total' => 100]]) === 'غسيل مكيف — 1 سبليت');
$t('the menu\'s mode and type win over guessing', $L(['service' => 'صيانة وإصلاح — مكيف باكيدج — صوت عالي', 'svcMode' => 'repair', 'acType' => 'package', 'specialtyId' => 'ac']) === 'صيانة وإصلاح مكيف — 1 باكيدج');
$t('nothing known says so, never a bare «صيانة»', $L(['specialtyId' => 'ac']) === 'مكيفات (الخدمة لسه مش محددة) — النوع لسه مش محدد');
$t('install with a quote type «تركيب مكيف» and qty 2 → install, 2 units, type unknown', $L(['specialtyId' => 'ac', 'acQuote' => ['mode' => 'install', 'type' => 'تركيب مكيف', 'qty' => 2]]) === 'تركيب مكيفات — 2 مكيف (النوع لسه مش محدد)');
$t('multi-item wash quote', $L(['specialtyId' => 'ac', 'acQuote' => ['mode' => 'wash', 'items' => [['type' => 'سبليت', 'qty' => 2], ['type' => 'شباك', 'qty' => 1]]]]) === 'غسيل مكيفات — 2 سبليت + 1 شباك');
$t('other specialties keep their own name with the problem', $L(['specialtyId' => 'plumbing', 'description' => 'تسريب في الحمام']) === 'سباكة — تسريب في الحمام');

$t('chat lines in the note do not add units to an order that says what it is', $L(['service' => 'غسيل مكيف سبليت', 'specialtyId' => 'ac', 'note' => "لا ابغى غسيل ٢ مكيف و احد شباك و واحد اسبليت", 'acQuote' => ['mode' => 'wash', 'type' => 'سبليت', 'qty' => 1, 'total' => 100]]) === 'غسيل مكيف — 1 سبليت');
$t('…but the note is read when the order says nothing', $L(['specialtyId' => 'ac', 'note' => 'غسيل 3 سبليت']) === 'غسيل مكيفات — 3 سبليت');

// written on the order, on insert and on every update
$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
$o = Mutqan_Store::insert('orders', ['status' => 'new', 'specialtyId' => 'ac', 'service' => 'غسيل 2 سبليت', 'clientPhone' => '966500000001'], null, ['groupId' => 'main'], $sys);
$g = Mutqan_Store::get_raw('orders', $o['id']);
$t('the order carries serviceLabel and svc {mode, units}', $g['serviceLabel'] === 'غسيل مكيفات — 2 سبليت' && $g['svc']['mode'] === 'wash' && (array) $g['svc']['units'] === ['split' => 2], $g);
Mutqan_Store::update('orders', $o['id'], ['description' => 'وكمان واحد شباك'], $sys);
$t('…and is read again when the order changes', strpos(Mutqan_Store::get_raw('orders', $o['id'])['serviceLabel'], 'شباك') !== false, Mutqan_Store::get_raw('orders', $o['id'])['serviceLabel']);
Mutqan_Store::update('orders', $o['id'], ['serviceLabel' => 'غسيل خاص', 'serviceLabelLocked' => true], $sys);
$t('an owner\'s own label (locked) is kept', Mutqan_Store::get_raw('orders', $o['id'])['serviceLabel'] === 'غسيل خاص');

// the WhatsApp menu's order: exact mode and type, full confirmation lines
$v = Mutqan_Hub::op('create', ['phone' => '966500000077', 'service' => 'غسيل 2 مكيف كونسيلد', 'mode' => 'wash', 'acType' => 'concealed', 'spec' => 'ac', 'lat' => 21.41, 'lng' => 39.82, 'address' => 'العوالي', 'time' => 'بكرة العصر', 'confirmed' => true]);
$g = Mutqan_Store::get_raw('orders', $v['id']);
$t('a menu order is stamped exactly (wash / concealed ×2)', $g['svcMode'] === 'wash' && $g['acType'] === 'concealed' && $g['serviceLabel'] === 'غسيل مكيفات — 2 كونسيلد', $g);
$d = implode("\n", (array) $v['details']);
$t('the confirmation lines: service, address, pin, time, and «السعر بعد الكشف» when no price', mb_strpos($d, '🛠 الخدمة: غسيل مكيفات — 2 كونسيلد') !== false && mb_strpos($d, '🏠 العنوان: العوالي') !== false && mb_strpos($d, 'maps.google.com') !== false && mb_strpos($d, 'بكرة العصر') !== false, $d);
$v2 = Mutqan_Hub::op('create', ['phone' => '966500000078', 'service' => 'صيانة وإصلاح — مكيف باكيدج — مش بيبرد', 'mode' => 'repair', 'acType' => 'package', 'desc' => 'مش بيبرد', 'spec' => 'ac', 'lat' => 21.41, 'lng' => 39.82, 'time' => 'اليوم', 'confirmed' => true]);
$d2 = implode("\n", (array) $v2['details']);
$t('repair confirmation: type, the problem, price after inspection', mb_strpos($d2, 'صيانة وإصلاح مكيف — 1 باكيدج') !== false && mb_strpos($d2, 'مش بيبرد') !== false && mb_strpos($d2, 'بعد الكشف') !== false, $d2);
$t('the customer\'s lines never carry the operations warning', !preg_grep('/⚠️/u', (array) $v['details']));
$t('ac_price names a cassette as a cassette', mb_strpos(Mutqan_Hub::ac_price(['other' => 2, 'otherType' => 'cassette'])['label'], 'كاسيت') !== false && mb_strpos(Mutqan_Hub::ac_price(['other' => 2, 'otherType' => 'cassette'])['label'], 'مخفي') === false);

// parts by every name the customers use (owner, 2026-11-21)
$ids = function ($t) { return array_column(Mutqan_Hub::parts_in($t), 'id'); };
$t('«الماتور الكبير» is the compressor, not the fan motor', $ids('بكام الماتور الكبير') === ['compressor'], $ids('بكام الماتور الكبير'));
$t('«الضاغط» is the compressor', $ids('الضاغط باظ') === ['compressor'], $ids('الضاغط باظ'));
$t('«الفانة» and «الدينمو» are the fan motor', $ids('الفانة بكام') === ['fan_motor'] && $ids('الدينمو واقف') === ['fan_motor'] && $ids('الدنمو بكام') === ['fan_motor'], [$ids('الفانة بكام'), $ids('الدينمو واقف')]);
$t('«ماتور المروحة» stays the fan motor', $ids('ماتور المروحة بكام') === ['fan_motor'], $ids('ماتور المروحة بكام'));
$t('«الكونتكتر» is the contactor', $ids('الكونتكتر 220 بكام') === ['contactor'], $ids('الكونتكتر 220 بكام'));
$ca = Mutqan_Hub::parts_answer('بكام الماتور الكبير');
$t('the compressor gets no price, the technician decides after the inspection', mb_strpos($ca['text'], 'بعد الكشف') !== false && !preg_match('/\d+\s*ر\.س/u', $ca['text']), $ca['text']);
// a stored kit from before (v2) gets the new names, his prices untouched
$old = Mutqan_Kit::defaults(); $old['v'] = 2;
foreach ($old['parts'] as $i => $p) { if ($p['id'] === 'fan_motor') { $old['parts'][$i]['words'] = ['موتور', 'ماتور']; $old['parts'][$i]['from'] = 333; } }
update_option('mutqan_service_kit', $old, false);
$fm = array_values(array_filter(Mutqan_Kit::get()['parts'], function ($p) { return $p['id'] === 'fan_motor'; }))[0];
$t('a stored kit gets the new names (الفانة، الدينمو) and keeps his price', in_array('فانه', $fm['words'], true) && in_array('دينمو', $fm['words'], true) && (int) $fm['from'] === 333, $fm);

// the background stamp of old orders
$GLOBALS['wpdb']->query('UPDATE ' . Mutqan_Store::table('docs') . " SET data = JSON_REMOVE(data, '$.serviceLabel', '$.svc') WHERE coll = 'orders'");
delete_option('mutqan_service_filled2'); $S::fill_step();
$t('old orders get their label in the background', get_option('mutqan_service_filled2') && !empty(Mutqan_Store::get_raw('orders', $o['id'])['svc']));

echo ($ok ? 'ALL PASS' : 'SOME FAILED') . " ($n)\n";
exit($ok ? 0 : 1);
