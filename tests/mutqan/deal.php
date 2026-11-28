<?php
// «محرك الصفقة» + «موسوعة المساعد» (owner, 2026-11-20): the price bends with the day and the owner's limit, appointments
// follow the clock, a customer with an order gets things done, and the bot knows its trade.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
require dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/mutqan/class-mutqan-command.php';
if (!class_exists('Mutqan_Owner')) { require dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/mutqan/class-mutqan-owner.php'; }
Mutqan_Store::maybe_install();
$ok = true; $n = 0;
$t = function ($name, $c, $x = null) use (&$ok, &$n) { $n++; echo ($c ? 'PASS ' : 'FAIL ') . 'deal: ' . $name . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
$sh = ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'];
$who = [
    'owner' => ['id' => 'u-owner', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'علي'],
    'operations' => ['id' => 'u-ops', 'role' => 'operations', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'العمليات'],
    'supervisor' => ['id' => 'u-sup', 'role' => 'supervisor', 'powers' => ['read', 'write'], 'kind' => 'user', 'name' => 'المشرف'],
];
$D = 'Mutqan_Deal'; $Q = 'Mutqan_Quick';
$at = function ($hm, $day = 'today') { $d = new DateTime($day . ' ' . $hm, new DateTimeZone('Asia/Riyadh')); return $d->getTimestamp(); };
$list = function ($q) { return (int) round(Mutqan_Policy::ac_total(['mode' => 'wash', 'type' => 'سبليت', 'qty' => $q, 'full' => true])); };
$pushes = function ($ev) use ($sys) { return array_values(array_filter(Mutqan_Store::select('_push', [], ['limit' => 500], $sys), function ($m) use ($ev) { return ($m['event'] ?? '') === $ev; })); };

/* ================================================================== 1. the day decides how far the price bends */
$t('official prices: 4 = ' . $list(4) . ', 6 = ' . $list(6), $list(4) === 267 && $list(6) === 400, [$list(4), $list(6)]);
$d0 = $D::demand(null, []);
$t('no orders today → a quiet day', $d0['level'] === 'quiet' && $d0['today'] === 0, $d0);
$t('quiet: 4 split for 200 is allowed', $D::bulk_floor(4, 267, null, $d0) === 200, $D::bulk_floor(4, 267, null, $d0));
$t('quiet: 6 split for 250 is allowed', $D::bulk_floor(6, 400, null, $d0) === 250, $D::bulk_floor(6, 400, null, $d0));
$d1 = ['level' => 'normal', 'today' => 1];
$t('one order in: 6 split no lower than ~300', $D::bulk_floor(6, 400, null, $d1) === 305, $D::bulk_floor(6, 400, null, $d1));
$d2 = ['level' => 'busy', 'today' => 2];
$t('two orders in (busy): hold — 6 split no lower than 360', $D::bulk_floor(6, 400, null, $d2) === 360, $D::bulk_floor(6, 400, null, $d2));
$t('busy: 4 split no lower than 240', $D::bulk_floor(4, 267, null, $d2) === 240, $D::bulk_floor(4, 267, null, $d2));
$t('three units or fewer keep the engine floor', $D::bulk_floor(3, 200, 179, $d0) === 179 && $D::bulk_floor(1, 100, 89, $d0) === 89);
$t('never above the list, never under the three-unit price', $D::bulk_floor(4, 267, null, $d0) >= 200 && $D::bulk_floor(10, $list(10), null, $d2) <= $list(10));
$o1 = Mutqan_Store::insert('orders', ['status' => 'completed', 'specialtyId' => 'ac', 'clientName' => 'أ', 'clientPhone' => '0500000001'], null, $sh, $sys);
$t('one real order today → normal', $D::demand()['level'] === 'normal');
$o2 = Mutqan_Store::insert('orders', ['status' => 'assigned', 'specialtyId' => 'ac', 'clientName' => 'ب', 'clientPhone' => '0500000002'], null, $sh, $sys);
$o3 = Mutqan_Store::insert('orders', ['status' => 'cancelled', 'specialtyId' => 'ac', 'clientName' => 'ج', 'clientPhone' => '0500000003'], null, $sh, $sys);
$dm = $D::demand();
$t('two real orders (the cancelled one does not count) → busy', $dm['level'] === 'busy' && $dm['today'] === 2, $dm);

/* ================================================================== 2. the server accepts what the band allows */
update_option('mutqan_price_band', ['mode' => 'loose']);
$f6 = Mutqan_Policy::ac_floor(['mode' => 'wash', 'type' => 'سبليت', 'qty' => 6, 'full' => true]);
$t('order guard: 6 split at 250 passes when the band allows it', $f6 <= 250, $f6);
$f2 = Mutqan_Policy::ac_floor(['mode' => 'wash', 'type' => 'سبليت', 'qty' => 2, 'full' => true]);
$t('…while 2 units keep the usual percentage floor', $f2 >= 120, $f2);
update_option('mutqan_price_band', ['mode' => 'auto']);
$t('busy day: the guard floor for 6 rises back toward 360', Mutqan_Policy::ac_floor(['mode' => 'wash', 'type' => 'سبليت', 'qty' => 6, 'full' => true]) >= 359 - 1);

/* ================================================================== 3. the sales brain plays the day */
$base = ['list' => 400, 'qty' => 6, 'peak' => false, 'archetype' => 'bargainer', 'sensitivity' => 0.7, 'give' => 1, 'city' => 'makkah', 'situation' => 'walk_away', 'bulk' => true];
$quiet = Mutqan_Sales::strategy($base + ['floor' => 250, 'demand' => 'quiet', 'asks' => 4]);
$busy = Mutqan_Sales::strategy($base + ['floor' => 360, 'demand' => 'busy', 'asks' => 4]);
$t('quiet day, customer walking away: down to 250 for six', $quiet['now'] === 250, $quiet);
$t('busy day, same customer: not under 360', $busy['now'] >= 360 && $busy['floor'] === 360, $busy);
$q2 = Mutqan_Sales::strategy(['list' => 400, 'qty' => 6, 'floor' => 250, 'bulk' => true, 'demand' => 'quiet', 'archetype' => 'new', 'sensitivity' => 0.5, 'give' => 1, 'asks' => 0, 'situation' => 'price_first', 'city' => 'makkah']);
$t('first price is always the official one', $q2['now'] === 400, $q2);
$q3 = Mutqan_Sales::strategy(['list' => 400, 'qty' => 6, 'floor' => 250, 'bulk' => true, 'demand' => 'quiet', 'archetype' => 'new', 'sensitivity' => 0.5, 'give' => 1, 'asks' => 1, 'situation' => 'discount_first', 'city' => 'makkah']);
$t('first haggle: one step down, not straight to the bottom', $q3['now'] < 400 && $q3['now'] > 250, $q3);
$q4 = Mutqan_Sales::strategy(['list' => 400, 'qty' => 6, 'floor' => 250, 'bulk' => true, 'demand' => 'quiet', 'archetype' => 'new', 'sensitivity' => 0.5, 'give' => 1, 'asks' => 2, 'situation' => 'counter_ok', 'counter' => 300, 'city' => 'makkah']);
$t('quiet: «300 for the six?» is accepted', $q4['accept'] === 300 || $q4['now'] <= 300, $q4);
$q5 = Mutqan_Sales::strategy(['list' => 400, 'qty' => 6, 'floor' => 360, 'bulk' => true, 'demand' => 'busy', 'archetype' => 'new', 'sensitivity' => 0.5, 'give' => 1, 'asks' => 2, 'situation' => 'counter_low', 'counter' => 300, 'city' => 'makkah']);
$t('busy: «300 for the six?» is not accepted', $q5['accept'] === null && $q5['now'] >= 360, $q5);
$q6 = Mutqan_Sales::strategy(['list' => 267, 'qty' => 4, 'floor' => 200, 'bulk' => true, 'demand' => 'quiet', 'archetype' => 'bargainer', 'sensitivity' => 0.8, 'give' => 1, 'asks' => 4, 'situation' => 'last_price', 'counter' => 200, 'city' => 'makkah']);
$t('quiet: after a long haggle, four for 200', $q6['now'] === 200, $q6);
$pl = "العميل: السلام عليكم\nالعميل: بكام غسيل 6 مكيفات سبليت؟\n";
$out = Mutqan_Sales::inject($pl, ['feature' => 'wa_reception', 'sales' => ['phone' => '0559998877']], $sys);
$t('WhatsApp (wa_reception) now gets the sales brain with the price read from the chat', strpos($out, 'العقل التجاري') !== false && strpos($out, 'صفقة 6 مكيفات') !== false && strpos($out, '400') !== false, mb_substr($out, 0, 900));
$t('…and the day', strpos($out, 'حالة اليوم') !== false);
$band = ['mode' => 'fixed'];
update_option('mutqan_price_band', $band);
$t('«ممنوع فصال»: floor = official price', $D::bulk_floor(6, 400) === 400 && strpos($D::deal_hint(6, 400), 'الأسعار الرسمية فقط') !== false);
delete_option('mutqan_price_band');

/* ================================================================== 4. owner / operations steer the limit */
$r = $Q::try_run('الليميت كام', $who['owner']);
$t('owner: «الليميت كام» shows min, max, mode and the day', $r && mb_strpos($r['text'], 'المينيمم 42') !== false && mb_strpos($r['text'], '6 مكيف') !== false, $r);
$r = $Q::try_run('ارفع الليميت', $who['owner']);
$b = $D::band();
$t('owner: «ارفع الليميت» → +5 on both ends, confirmed', $b['minUnit'] === 47 && $b['maxUnit'] === 65 && mb_strpos($r['text'], '✅') !== false, [$b, $r['text'] ?? null]);
$Q::try_run('قلل الليميت 10', $who['operations']);
$b = $D::band();
$t('operations: «قلل الليميت 10» → −10', $b['minUnit'] === 37 && $b['maxUnit'] === 55, $b);
$Q::try_run('الليميت من 45 لـ 60', $who['operations']);
$b = $D::band();
$t('«الليميت من 45 لـ 60» sets the range', $b['minUnit'] === 45 && $b['maxUnit'] === 60, $b);
$Q::try_run('المينيمم 40 والماكسيمم 58', $who['owner']);
$b = $D::band();
$t('«المينيمم 40 والماكسيمم 58»', $b['minUnit'] === 40 && $b['maxUnit'] === 58, $b);
$Q::try_run('ثبت الليميت', $who['owner']);
$t('«ثبت الليميت» → hold on the maximum all day', $D::band()['mode'] === 'tight' && $D::unit_floor(['level' => 'quiet']) === 58);
$Q::try_run('ثبت الأسعار', $who['operations']);
$t('«ثبت الأسعار» → hold', $D::band()['mode'] === 'tight');
$Q::try_run('ممنوع فصال النهارده', $who['owner']);
$t('«ممنوع فصال» → official prices only', $D::band()['mode'] === 'fixed' && $D::unit_floor() === null);
$Q::try_run('خلي الليميت مرن', $who['owner']);
$t('«خلي الليميت مرن» → on the minimum', $D::band()['mode'] === 'loose' && $D::unit_floor(['level' => 'busy']) === 40);
$Q::try_run('رجع الليميت تلقائي', $who['owner']);
$t('«رجع الليميت تلقائي» → follows the day again', $D::band()['mode'] === 'auto');
$Q::try_run('ارفع الليميت لمدة 3 ساعات', $who['owner']);
$t('a timed change carries an end time', $D::band()['until'] !== '');
update_option('mutqan_price_band', array_merge($D::band(), ['mode' => 'tight', 'until' => gmdate('c', time() - 60)]));
$t('…and ends by itself (numbers kept, mode back to auto)', $D::band()['mode'] === 'auto' && $D::band()['minUnit'] === 45);
$r = $Q::try_run('ارفع الليميت', $who['supervisor']);
$t('supervisor cannot change the limit', $r && mb_strpos($r['text'], 'صلاحيات') !== false, $r);
$r = $Q::try_run('الليميت كام', $who['supervisor']);
$t('…but can read it', $r && mb_strpos($r['text'], 'المينيمم') !== false, $r);
$t('the office is told when the limit changes', count($pushes('price_band')) >= 2);
delete_option('mutqan_price_band');

/* ================================================================== 5. appointments follow the clock */
foreach ([['07:00', 'today'], ['08:30', 'today'], ['13:15', 'today'], ['21:59', 'today'], ['22:00', 'late'], ['22:45', 'late'], ['23:00', 'tomorrow'], ['23:59', 'tomorrow'], ['01:30', 'tomorrow'], ['06:59', 'tomorrow']] as $c) {
    $bk = $D::booking($at($c[0]));
    $t('at ' . $c[0] . ' → ' . $c[1], $bk['mode'] === $c[1], $bk);
}
$bk = $D::booking($at('10:00'));
$t('daytime: offer today or tomorrow, default today', $bk['offerTomorrow'] && mb_strpos($bk['rule'], 'اليوم ولا بكره') !== false && mb_strpos($bk['defaultTime'], 'اليوم') === 0);
$bk = $D::booking($at('23:20'));
$t('after 11 pm: tomorrow early morning or any time tomorrow', mb_strpos($bk['defaultTime'], 'بكره الصبح') === 0 && mb_strpos($bk['rule'], 'أي وقت') !== false, $bk);
$bk = $D::booking($at('02:00'));
$t('after midnight: «this morning», not «tomorrow»', mb_strpos($bk['defaultTime'], 'النهارده الصبح') === 0, $bk);
$bk = $D::booking($at('22:30'));
$t('10–11 pm: tomorrow by default, tonight only if he insists', mb_strpos($bk['rule'], 'مُصر') !== false && mb_strpos($bk['defaultTime'], 'بكره') === 0);
update_option('mutqan_intake', ['mode' => 'tomorrow', 'note' => '', 'until' => '']);
$t('owner\'s «حجز لبكره» wins over the daytime rule', $D::booking($at('11:00'))['mode'] === 'tomorrow');
delete_option('mutqan_intake');
$hr = Mutqan_AI::house_rules();
$t('the house rules carry the rule for this hour (no more «never suggest tomorrow»)', mb_strpos($hr, 'ممنوع تقترح بكرة') === false && (mb_strpos($hr, '7 الصبح') !== false || mb_strpos($hr, '11 بالليل') !== false || mb_strpos($hr, '10 بالليل') !== false), mb_substr($hr, 0, 1500));
$t('the house rules reach the WhatsApp reception AI too', in_array('wa_reception', Mutqan_AI::HOUSE_FEATURES, true));
$sc = Mutqan_Sales::settings();
$t('Makkah starts at 7', $sc['cities']['makkah']['from'] === '07:00' && Mutqan_Sales::schedule()['openFrom'] === '07:00');

/* ================================================================== 6. a customer with an order */
$tech = Mutqan_Auth::make_member('فني الخدمة', '0531112222', 'technician');
Mutqan_Store::upsert('tech_profiles', ['userId' => $tech['id'], 'displayName' => 'فني الخدمة', 'active' => true], $tech['id'], $sh, $sys);
$mk = function ($phone, $status, $extra = []) use ($sh, $sys, $tech) {
    return Mutqan_Store::insert('orders', array_merge(['status' => $status, 'specialtyId' => 'ac', 'clientName' => 'عميل', 'clientPhone' => $phone, 'technicianId' => $tech['id'], 'technicianName' => 'فني الخدمة', 'number' => (string) rand(2000, 9999)], $extra), null, $sh, $sys);
};
$techMsgs = function ($oid) use ($sys) { return array_values(array_filter(Mutqan_Store::select('order_msgs', ['orderId' => $oid], ['limit' => 50], $sys), function ($m) { return ($m['channel'] ?? '') === 'tech'; })); };

$a = $mk('966551000001', 'assigned');
$sv = $D::service('0551000001', 'السلام عليكم الفني فين؟');
$t('«الفني فين؟» → answered from the order, technician asked', $sv && $sv['intent'] === 'where' && mb_strpos($sv['reply'], 'فني الخدمة') !== false && count($techMsgs($a['id'])) === 1, $sv);
$sv = $D::service('0551000001', 'طولتو مره وين الفني');
$t('asked again soon → not pestering the technician twice', $sv && count($techMsgs($a['id'])) === 1 && mb_strpos($sv['reply'], 'اتسأل') !== false, $sv);
$sv = $D::service('0551000001', 'قول للفني يجيب معاه سلم طويل');
$t('«قول للفني يجيب سلم» → relayed to the technician', $sv && mb_strpos(json_encode($techMsgs($a['id']), JSON_UNESCAPED_UNICODE), 'سلم طويل') !== false, $sv);
$sv = $D::service('0551000001', 'ممكن نأجلها لبكره العصر؟');
$a2 = Mutqan_Store::get_raw('orders', $a['id']);
$t('«نأجلها لبكره العصر» → marked, operations and technician told', $sv && $sv['intent'] === 'reschedule' && !empty($a2['rescheduleRequest']) && mb_strpos($sv['reply'], 'العمليات') !== false, $sv);
$sv = $D::service('0551000001', 'لا خلاص الغي الطلب ما احتاجه');
$a3 = Mutqan_Store::get_raw('orders', $a['id']);
$t('cancel before the technician accepted → cancelled at once', $sv && $sv['done'] === 'cancelled' && ($a3['status'] ?? '') === 'cancelled' && mb_strpos($sv['reply'], 'تم إلغاء') !== false, [$sv, $a3['status'] ?? null]);
$t('…the technician is told not to go', mb_strpos(json_encode($techMsgs($a['id']), JSON_UNESCAPED_UNICODE), 'ما تتحركش') !== false);
$t('…and the customer is offered another time', mb_strpos($sv['reply'], 'وقت تاني') !== false);

$b1 = $mk('966551000002', 'en_route');
$sv = $D::service('0551000002', 'كنسل الطلب لو سمحت');
$b2 = Mutqan_Store::get_raw('orders', $b1['id']);
$t('cancel while the technician is on his way → a request, not a cancellation', $sv && $sv['done'] === 'requested' && ($b2['status'] ?? '') === 'en_route' && !empty($b2['cancelRequest']), [$sv, $b2['status'] ?? null]);
$t('…the technician holds', mb_strpos(json_encode($techMsgs($b1['id']), JSON_UNESCAPED_UNICODE), 'استنى مكانك') !== false);
$t('…and the customer is offered to move it instead', mb_strpos($sv['reply'], 'أأجله') !== false);

$t('no live order + «الفني فين» → asks for the order number, invents nothing', ($x = $D::service('0557777777', 'الفني وين؟')) && $x['order'] === null && mb_strpos($x['reply'], 'رقم الطلب') !== false, $x ?? null);
$t('a new customer «عايز فني أقرب وقت» is NOT taken for an order question', $D::service('0558888888', 'عايز فني اقرب وقت للمكيف') === null);
$t('«السلام عليكم» is nobody\'s order question', $D::service('0551000002', 'السلام عليكم') === null);
$t('«بكام غسيل 3 مكيفات» is a sales question, not service', $D::service('0551000002', 'بكام غسيل 3 مكيفات') === null);
$t('«خليها بكره» from a new customer (no order) goes to the sales chat', $D::service('0556666666', 'خليها بكره الصبح') === null);

// The WhatsApp reception: the order question is answered by the system before any model is asked.
$c1 = $mk('966551000003', 'accepted');
$out = Mutqan_WA_AI::decide([], 'وين الفني تأخر', [], '966551000003', time(), 't-1');
$t('WhatsApp: «وين الفني تأخر» answered from the order without the AI', !empty($out['replies'][0]) && mb_strpos($out['replies'][0], 'فني الخدمة') !== false && empty($out['notify']), $out);

/* ================================================================== 7. the encyclopedia */
$t('the encyclopedia has ' . Mutqan_Lore::count() . ' entries (≥ 55)', Mutqan_Lore::count() >= 55);
$bad = [];
foreach (Mutqan_Lore::entries() as $id => $e) { if (preg_match('/(ريال|ر\.س|SAR)/u', $e[3]) || preg_match('/\d{3,}/', $e[3])) { $bad[] = $id; } }
$t('no ready answer carries a price', !$bad, $bad);
$bot = [];
foreach (Mutqan_Lore::entries() as $id => $e) { if (preg_match('/(بوت|ذكاء اصطناعي|روبوت)/u', $e[3])) { $bot[] = $id; } }
$t('no ready answer says «I am a bot»', !$bot, $bot);
$cases = [
    'المكيف ينقط مويه على الجدار' => 'ac_drip', 'التكييف مش بيبرد خالص' => 'ac_no_cool', 'محتاج تعبئة فريون' => 'ac_freon',
    'فيه ريحة عفن من المكيف' => 'ac_smell', 'كل كم مره اغسل المكيف' => 'ac_wash_freq', 'الغسيل ياخد قد ايه' => 'ac_wash_time',
    'وش الفرق بين الغسيل الداخلي والكامل' => 'ac_wash_what', 'المكيف الخارجي فوق السطح عالي' => 'ac_ladder', 'غرفة التبريد حقت المحل خربانة' => 'cold_room',
    'عندي تسريب مويه في الحمام' => 'plumbing', 'طرق الدفع عندكم' => 'pay_methods', 'ابي فاتورة ضريبية' => 'invoice_vat',
    'الكشف بفلوس؟' => 'inspection_fee', 'انت بوت؟' => 'not_bot', 'تشتغلون الجمعه؟' => 'hours', 'غالي مره' => 'obj_expensive',
    'لقيت ارخص عند شركه ثانيه' => 'obj_competitor', 'خليني افكر وارد عليك' => 'obj_think', 'لازم اسال اخوي' => 'obj_decider',
    'المره اللي فاتت كان ارخص' => 'obj_last_time', 'بغسله بنفسي احسن' => 'obj_diy', 'جاني فني قبل وخربو المكيف' => 'obj_bad_exp',
    'عندي 6 مكيفات ابي باقه' => 'bulk_many', 'نخليها بالنص لا لك ولا لي' => 'story_split_diff', 'ابي اشتكي الفني ما سوى شي' => 'svc_complaint',
    'بعد الغسيل رجع يقطر' => 'svc_after_warranty', 'شكرا شغل نظيف' => 'svc_rating', 'في ريحه حريق ودخان' => 'svc_emergency',
    'عندي عقد صيانه لعماره' => 'company_contract', 'تصلحون جوالات؟' => 'out_of_scope', 'ابي نفس الفني اللي جاني' => 'same_tech',
    'تغطون الطائف؟' => 'areas', 'زوجتي لحالها في البيت' => 'women_home', 'المكيف يطلع صوت عالي' => 'ac_noise', 'يطلع كود e4' => 'ac_error_code',
];
foreach ($cases as $q => $want) {
    $m = Mutqan_Lore::match($q, 4);
    $ids = array_column($m, 0);
    $t('«' . $q . '» → ' . $want, in_array($want, $ids, true), $ids);
}
$t('«انت بوت؟» with the model down gets a human answer', Mutqan_Lore::answer('انت بوت؟') !== '' && mb_strpos(Mutqan_Lore::answer('انت بوت؟'), 'بوت') === false);
$t('nothing to say about «ok»', Mutqan_Lore::answer('ok') === '');
$pr = Mutqan_Lore::inject("تعليمات\nالعميل: السلام\nالعميل (آخر رسالة): المكيف ينقط مويه", ['feature' => 'wa_reception'], $sys);
$t('the model gets the matching entry for the customer\'s LAST message', mb_strpos($pr, 'موسوعة') !== false && mb_strpos($pr, 'خرطوم التصريف') !== false, mb_substr($pr, 0, 300));
$t('…and nothing for staff features', Mutqan_Lore::inject("العميل: المكيف ينقط", ['feature' => 'agent'], $sys) === "العميل: المكيف ينقط");

/* ================================================================== 8. a hundred customer lines, each lands somewhere sensible */
$lines = [
    // [text, expected Deal intent ('' = none), a playbook situation it should not be mistaken for]
    ['الفني فين يا جماعه', 'where'], ['متى يوصل الفني؟', 'where'], ['لسه ما جاش الفني', 'where'], ['وين طلبي', 'where'],
    ['الغي الطلب', 'cancel'], ['ابي الغي الحجز', 'cancel'], ['كنسل', 'cancel'], ['ما ابي الطلب خلاص', 'cancel'],
    ['ممكن نأجلها', 'reschedule'], ['غير الموعد للعصر', 'reschedule'], ['خليها بكره', 'reschedule'],
    ['قول للفني يتصل بي', 'tech_msg'], ['الفني يجيب معه سلم', 'tech_msg'],
    ['السلام عليكم', ''], ['بكام غسيل مكيف', ''], ['عندي 4 مكيفات', ''], ['غالي', ''], ['اخر سعر كم', ''], ['ابي فني اقرب وقت', ''],
    ['اقرب موعد متى', ''], ['المكيف ما يبرد', ''], ['شكرا', ''], ['تمام اكد', ''], ['ابي سباك', ''], ['السعر نهائي؟', ''],
];
foreach ($lines as $l) { $got = $D::intent($l[0]); $t('intent «' . $l[0] . '» → ' . ($l[1] ?: 'none'), $got === $l[1], $got); }

echo $ok ? "ALL PASS deal ($n)\n" : "FAILED deal\n";
exit($ok ? 0 : 1);
