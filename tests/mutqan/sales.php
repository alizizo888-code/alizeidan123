<?php
// «العقل التجاري»: who the customer is, what the day looks like, and the plan for one reply.
//
// What matters most here is what must never happen: a price under the floor, a discount at the first ask,
// a slot the day cannot take, and the plan reaching staff tools or leaking into a prompt with no customer.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'sales: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$S = 'Mutqan_Sales'; $PB = 'Mutqan_Playbook';
$sys = ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']];

/* ---------------------------------------------------------------- the library */
$t('more than 500 scenarios', $PB::count() >= 500, $PB::count());
$ids = array_keys($PB::all());
$t('every scenario has its own id', count($ids) === count(array_unique($ids)));
$bad = array_filter($PB::all(), function ($s) { return $s['tactic'] === '' || !$s['say'] || $s['persona'] === ''; });
$t('every scenario has a tactic, a persona and wording', !$bad, array_keys($bad));
$t('a tactic never carries a raw placeholder', !array_filter($PB::all(), function ($s) { return preg_match('/\{[a-z]\}/', $s['tactic'] . $s['persona'] . $s['timing']); }));
$t('a scenario never carries a figure of its own', !array_filter($PB::all(), function ($s) { return preg_match('/\d{2,}\s*ر\.?س/u', implode(' ', $s['say'])); }));

/* ---------------------------------------------------------------- reading the moment */
$t('«كم السعر» is a price question', $PB::detect('كم السعر لو سمحت') === 'price_first', $PB::detect('كم السعر لو سمحت'));
$t('«غالي» is too expensive', $PB::detect('والله غالي شوي', ['quoted' => true]) === 'too_expensive', $PB::detect('والله غالي شوي', ['quoted' => true]));
$t('after a quote, «كم السعر؟ غالي» is a complaint, not a price question', $PB::detect('كم السعر؟ غالي شوي', ['quoted' => true]) === 'too_expensive', $PB::detect('كم السعر؟ غالي شوي', ['quoted' => true]));
$t('before a quote it is still a price question', $PB::detect('كم السعر؟', ['quoted' => false]) === 'price_first');
$t('a first discount ask', $PB::detect('ممكن خصم؟', ['quoted' => true]) === 'discount_first');
$t('the same ask again is a different moment', $PB::detect('ممكن خصم؟', ['quoted' => true, 'asks' => 1]) === 'discount_repeat');
$t('a number after a quote under the floor is a low counter', $PB::detect('خلها 120', ['quoted' => true, 'floor' => 170]) === 'counter_low');
$t('a number at or above the floor is acceptable', $PB::detect('اعطيك 180', ['quoted' => true, 'floor' => 170]) === 'counter_ok');
$t('«تمام» before any price is not an agreement', $PB::detect('تمام', ['quoted' => false]) !== 'accept_price');
$t('«تمام» after a price closes', $PB::detect('تمام اتفقنا', ['quoted' => true]) === 'accept_price');
$t('Jeddah districts are Jeddah', $S::city_of('انا في حي السامر') === 'jeddah');
$t('Taif is outside the zone', $S::city_of('عندي مكيف في الطائف') === 'out');
$t('Makkah districts are Makkah', $S::city_of('العزيزية') === 'makkah');
$t('the Egyptian dialect has its own wording', strpos($PB::phrase($PB::get('greet_browse.new.calm'), 'eg', [], 0), 'إيه') !== false || strpos($PB::phrase($PB::get('greet_browse.new.calm'), 'eg', [], 0), 'أهلًا') !== false, $PB::phrase($PB::get('greet_browse.new.calm'), 'eg'));

/* ---------------------------------------------------------------- money: never under the floor */
$base = ['list' => 200, 'floor' => 170, 'peak' => false, 'archetype' => 'bargainer', 'sensitivity' => 0.9, 'city' => 'makkah'];
$st = $S::strategy(array_merge($base, ['asks' => 1, 'situation' => 'discount_first', 'give' => 0.2]));
$t('no discount on the first ask — value first', $st['now'] === 200, $st);
$all = [];
foreach (['discount_repeat', 'last_price', 'walk_away', 'needy_plea', 'counter_low', 'split_difference', 'budget_stated'] as $sit) {
    for ($a = 1; $a <= 8; $a++) { $x = $S::strategy(array_merge($base, ['asks' => $a, 'situation' => $sit, 'give' => 1.5, 'counter' => 50, 'budget' => 40])); $all[] = $x['now']; }
}
$t('whatever the moment, nothing under the floor', min($all) >= 170, min($all));
$t('the owner\'s global cap wins over a lower engine floor', $S::strategy(['list' => 200, 'floor' => 50, 'asks' => 9, 'situation' => 'walk_away', 'give' => 1.5, 'sensitivity' => 1, 'archetype' => 'bargainer'])['now'] >= 170);
$l = $S::strategy(array_merge($base, ['asks' => 2, 'situation' => 'discount_repeat', 'give' => 1]))['ladder'];
$steps = []; for ($i = 1; $i < count($l); $i++) { $steps[] = $l[$i - 1] - $l[$i]; }
$t('concessions get smaller each time', $steps === array_values($steps) && (count($steps) < 2 || $steps[0] >= end($steps)), $l);
$vip = $S::strategy(array_merge($base, ['archetype' => 'vip', 'sensitivity' => 0.1, 'asks' => 3, 'situation' => 'discount_repeat', 'give' => 1]));
$barg = $S::strategy(array_merge($base, ['asks' => 3, 'situation' => 'discount_repeat', 'give' => 1]));
$t('a VIP lands higher than a bargainer', $vip['target'] > $barg['target'], [$vip['target'], $barg['target']]);
$peak = $S::strategy(array_merge($base, ['peak' => true, 'asks' => 3, 'situation' => 'discount_repeat', 'give' => 1]));
$t('a peak slot gives less', $peak['target'] >= $barg['target'], [$peak['target'], $barg['target']]);
$t('a quiet-hours price is offered instead of a discount', $barg['offPeak'] < 200 && $barg['offPeak'] >= 170);
$acc = $S::strategy(array_merge($base, ['asks' => 1, 'situation' => 'counter_ok', 'give' => 0, 'counter' => 195]));
$t('a counter above the target is accepted as it is', $acc['accept'] === 195 && $acc['now'] === 195, $acc);
$bud = $S::strategy(array_merge($base, ['asks' => 1, 'situation' => 'budget_stated', 'give' => 1, 'budget' => 100]));
$t('a budget under the floor gets a smaller job, not a lower price', $bud['scope'] !== null && $bud['now'] >= 170, $bud);

/* ---------------------------------------------------------------- the customer, learned from his orders */
$mk = function ($phone, $list, $paid, $days, $extra = []) use ($sys) {
    return Mutqan_Store::insert('orders', array_merge(['clientPhone' => $phone, 'status' => 'completed', 'priceQuote' => ['total' => $list], 'agreedPrice' => $paid, 'address' => 'العزيزية مكة', 'appointmentAt' => gmdate('c', time() - $days * 86400)], $extra), null, [], $sys);
};
foreach ([[300, 250], [300, 255], [200, 170]] as $i => $x) { $mk('966500000001', $x[0], $x[1], 10 + $i); }
$pr = $S::profile('0500000001');
$t('three orders that each bargained make a bargainer', $pr['archetype'] === 'bargainer' && $pr['orders'] === 3, $pr);
$t('his phone number is never echoed in full', strpos((string) $pr['key'], '0500000001') === false);
foreach ([1, 2, 3] as $i) { $mk('966500000002', 900, 900, 5 + $i); }
$t('a high average ticket makes a VIP', $S::profile('0500000002')['archetype'] === 'vip', $S::profile('0500000002'));
$t('a stranger is new', $S::profile('0599999999')['archetype'] === 'new');

/* ---------------------------------------------------------------- the day: batching, cities, capacity */
// Fix the clock at 09:00 Makkah time on a Sunday and walk the ladder.
$sun = new DateTime('next sunday 09:00', new DateTimeZone('Asia/Riyadh')); $now = $sun->getTimestamp();
$S::save(['cities' => ['jeddah' => ['days' => [1, 3]]], 'batching' => [['after' => 3, 'mode' => 'cluster', 'clusterHours' => 2], ['after' => 5, 'mode' => 'windows', 'windows' => [['from' => '17:00', 'to' => '21:00']]], ['after' => 8, 'mode' => 'next_day']]]);
$js = $S::slots('jeddah', $now, 3, []);
$wd = array_map(function ($x) { return (int) (new DateTime($x['at']))->setTimezone(new DateTimeZone('Asia/Riyadh'))->format('w'); }, $js['slots']);
$t('Jeddah is offered only on its own days', $js['slots'] && !array_diff($wd, [1, 3]), $js);
$orders = [];
foreach ([10, 11, 12] as $h) { $orders[] = ['status' => 'new', 'address' => 'الشوقية', 'appointmentAt' => (new DateTime('@' . ($now + ($h - 9) * 3600)))->format('c')]; }
$ms = $S::slots('makkah', $now, 3, $orders);
$hrs = array_map(function ($x) { return (int) (new DateTime($x['at']))->setTimezone(new DateTimeZone('Asia/Riyadh'))->format('G'); }, array_filter($ms['slots'], function ($x) use ($now) { return substr($x['at'], 0, 10) === gmdate('Y-m-d', $now); }));
$t('after 3 orders the rule is «cluster»', ($ms['rule']['mode'] ?? '') === 'cluster', $ms['rule']);
$t('and today\'s new slots sit next to the existing visits', !array_filter($hrs, function ($h) { return $h < 8 || $h > 14; }), $hrs);
foreach ([13, 14] as $h) { $orders[] = ['status' => 'new', 'address' => 'الشوقية', 'appointmentAt' => (new DateTime('@' . ($now + ($h - 9) * 3600)))->format('c')]; }
$ms = $S::slots('makkah', $now, 3, $orders);
$today = array_filter($ms['slots'], function ($x) use ($now) { return substr($x['at'], 0, 10) === gmdate('Y-m-d', $now); });
$hrs = array_map(function ($x) { return (int) (new DateTime($x['at']))->setTimezone(new DateTimeZone('Asia/Riyadh'))->format('G'); }, $today);
$t('after 5 the rule is «windows»: evening only', ($ms['rule']['mode'] ?? '') === 'windows' && !array_filter($hrs, function ($h) { return $h < 17; }), [$ms['rule'], $hrs]);
foreach ([17, 18, 19] as $h) { $orders[] = ['status' => 'new', 'address' => 'الشوقية', 'appointmentAt' => (new DateTime('@' . ($now + ($h - 9) * 3600)))->format('c')]; }
$ms = $S::slots('makkah', $now, 3, $orders);
$t('after 8 today is closed and slots start tomorrow', ($ms['rule']['mode'] ?? '') === 'next_day' && $ms['slots'] && substr($ms['slots'][0]['at'], 0, 10) !== gmdate('Y-m-d', $now), $ms);
$t('cancelled orders do not count', count($S::load_for($S::ymd($now), [['status' => 'cancelled', 'address' => 'مكة', 'appointmentAt' => gmdate('c', $now)]])['makkah']) === 0);
$t('an order in Jeddah by coordinates is Jeddah', $S::order_city(['coords' => ['lat' => 21.54, 'lng' => 39.17]]) === 'jeddah');

/* ---------------------------------------------------------------- the plan reaches the model, and only customers */
$prompt = "تعليمات\nالعميل: السلام عليكم عندي مكيف\nMUTQAN AI: أبشر\nالعميل: كم السعر؟ غالي شوي";
$cust = ['kind' => 'guest', 'role' => null, 'id' => 'g1'];
$out = $S::inject($prompt, ['feature' => 'chat', 'sales' => ['list' => 200, 'floor' => 170]], $cust);
$t('a customer conversation gets the plan', strpos($out, 'العقل التجاري') !== false && strpos($out, $prompt) !== false);
$pb = $S::prompt_block($S::plan(['lines' => ['كم السعر', 'غالي', 'نزل شوي'], 'list' => 200, 'floor' => 170]));
preg_match('/السعر الذي تقوله الآن: (\d+)/u', $pb, $m1); preg_match('/بسعر (\d+) ر\.س/u', $pb, $m2);
$t('a quiet-hours alternative is only offered when it is cheaper than the price on the table', !$m2 || (int) $m2[1] < (int) $m1[1], $pb);
$t('the plan names the price to say, never the floor as a figure to quote', strpos($out, 'لا تقل أي رقم أقل من') !== false && strpos($out, 'لا تذكر أن هناك حدًا أدنى') !== false);
$lk = $S::inject($prompt, ['feature' => 'chat', 'sales' => ['list' => 200, 'floor' => 170, 'offer' => 180]], $cust);
$t('the app\'s ladder decides the number on a price-talk turn', strpos($lk, 'السعر الذي تقوله الآن: 180') !== false, $lk);
$lk2 = $S::inject("العميل: كم سعر غسيل مكيف؟", ['feature' => 'chat', 'sales' => ['list' => 200, 'floor' => 170, 'offer' => 180]], $cust);
$t('but a plain price question still gets the official price, not the ladder\'s offer', strpos($lk2, 'السعر الذي تقوله الآن: 200') !== false, $lk2);
$lk = $S::inject($prompt, ['feature' => 'chat', 'sales' => ['list' => 200, 'floor' => 170, 'offer' => 20]], $cust);
$t('even then, never under the floor', strpos($lk, 'السعر الذي تقوله الآن: 170') !== false);
$t('a prompt without a customer is untouched', $S::inject('اكتب مقال عن المكيفات', ['feature' => 'chat'], $cust) === 'اكتب مقال عن المكيفات');
$t('staff tools are untouched', $S::inject($prompt, ['feature' => 'agent'], $cust) === $prompt);
$t('a staff member asking is untouched', $S::inject($prompt, ['feature' => 'chat'], $sys) === $prompt);
$S::save(['enabled' => 0]);
$t('switched off, nothing is added', $S::inject($prompt, ['feature' => 'chat'], $cust) === $prompt);
$S::save(['enabled' => 1, 'note' => 'ركّز على الضمان قبل أي خصم']);
$t('the owner\'s teaching reaches the plan', strpos($S::inject($prompt, ['feature' => 'chat', 'sales' => ['list' => 200]], $cust), 'ركّز على الضمان') !== false);
$plan = $S::plan(['lines' => ['عندي 3 مكيفات في الطائف'], 'list' => 300]);
$t('out of the zone: no booking, partner handoff', $plan['city'] === 'out' && strpos($S::prompt_block($plan), 'خارج مكة وجدة') !== false, $plan['scenario']);

/* ---------------------------------------------------------------- policy edits are clamped */
$st = $S::save(['pricing' => ['maxDiscountPct' => 500, 'offPeakPct' => -4], 'cities' => ['jeddah' => ['days' => [9, 2, 2], 'from' => '25:99'], 'riyadh' => ['enabled' => 1]], 'batching' => [['after' => 0, 'mode' => 'evil']]]);
$t('a discount cap cannot pass 60%', $st['pricing']['maxDiscountPct'] == 60);
$t('a negative percentage becomes 0', $st['pricing']['offPeakPct'] == 0);
$t('impossible days and hours are dropped', $st['cities']['jeddah']['days'] === [2] && $st['cities']['jeddah']['from'] === '13:00', $st['cities']['jeddah']);
$t('no city appears from nowhere', !isset($st['cities']['riyadh']));
$t('an unknown rule mode becomes «cluster»', $st['batching'][0]['mode'] === 'cluster' && $st['batching'][0]['after'] === 1, $st['batching']);

/* ---------------------------------------------------------------- the owner hears about it, once */
delete_option(Mutqan_Sales::LOG);
$S::save(['batching' => [['after' => 3, 'mode' => 'cluster', 'clusterHours' => 2]], 'cities' => ['jeddah' => ['days' => [0, 1, 2, 3, 4, 5, 6], 'from' => '08:00', 'to' => '22:00']]]);
$when = gmdate('c', time() + 3 * 86400);
for ($i = 0; $i < 4; $i++) { Mutqan_Store::insert('orders', ['status' => 'new', 'address' => 'حي الصفا جدة', 'appointmentAt' => $when], null, [], $sys); }
$log = get_option(Mutqan_Sales::LOG, []);
$hits = array_filter((array) $log, function ($e) { return strpos($e['text'], 'جدة: 3 طلبات') !== false; });
$t('reaching 3 orders in a city tells the owner — once', count($hits) === 1, $log);
Mutqan_Store::insert('orders', ['status' => 'new', 'address' => 'العزيزية', 'agreedPrice' => 4000], null, [], $sys);
$t('a big deal tells the owner', (bool) array_filter((array) get_option(Mutqan_Sales::LOG, []), function ($e) { return strpos($e['text'], 'صفقة كبيرة') !== false; }));
$pushes = Mutqan_Store::select('_push', [], ['limit' => 50], $sys);
// The owner asked for a quiet day: these wait for the night report instead of ringing supervisors.
$t('notices wait for the night report instead of ringing the team', !array_filter($pushes, function ($x) { return ($x['event'] ?? '') === 'sales_brain'; }) && strpos(Mutqan_WA_Central::night_text(), 'صفقة كبيرة') !== false);
$t('with no priced orders, nothing is claimed about discounts', $S::insights(30, [])['avgDiscountPct'] === null);
$txt = $S::digest_text();
$t('the morning digest covers both cities', strpos($txt, 'مكة') !== false && strpos($txt, 'جدة') !== false, $txt);

/* ---------------------------------------------------------------- the owner screen */
try { $S::handle(['op' => 'overview'], ['kind' => 'user', 'role' => 'client', 'id' => 'c1']); $t('a customer cannot open the sales brain', false); }
catch (Mutqan_Error $e) { $t('a customer cannot open the sales brain', $e->status === 403 || $e->status === 401, $e->getMessage()); }
$ov = $S::handle(['op' => 'overview'], ['kind' => 'user', 'role' => 'owner', 'id' => 'o1', 'powers' => ['read', 'write', 'manage']]);
$t('the overview carries policy, plan, slots and the playbook', isset($ov['settings'], $ov['plan']['cities']['makkah'], $ov['slots']['jeddah'], $ov['playbook']['total']));
try { $S::handle(['op' => 'save', 'patch' => ['enabled' => 0]], ['kind' => 'user', 'role' => 'operations', 'id' => 'op1']); $t('operations cannot change the money policy', false); }
catch (Mutqan_Error $e) { $t('operations cannot change the money policy', $e->status === 403); }
$sim = $S::simulate(['text' => 'آخر سعر كم؟', 'list' => 300, 'floor' => 250, 'history' => ['كم سعر غسيل 3 مكيفات', 'غالي']]);
$t('the simulator names a scenario, a price and slots', !empty($sim['scenario']['id']) && $sim['price']['now'] >= 255 && isset($sim['slots']), [$sim['scenario'], $sim['price']]);
$sc = $S::handle(['op' => 'scenarios', 'q' => 'جدة'], ['kind' => 'user', 'role' => 'operations', 'id' => 'op1']);
$t('the playbook can be searched', count($sc['items']) > 0 && $sc['stats']['total'] >= 500);

/* ---------------------------------------------------------------- more doors for the AI */
$labels = Mutqan_AI::labels();
$t('the gateway knows more than 20 providers', count($labels) >= 20, count($labels));
$t('every OpenAI-shaped provider has an endpoint', !array_diff(array_keys(Mutqan_AI::endpoints()), array_keys($labels)));
update_option(Mutqan_AI::OPT, ['order' => ['groq', 'gemini']]);
$t('an old saved order does not hide the new providers', in_array('deepseek', Mutqan_AI::settings()['order'], true) && Mutqan_AI::settings()['order'][0] === 'groq');
$t('a custom endpoint must be public https', !Mutqan_AI::custom_url_ok('http://example.com/v1') && !Mutqan_AI::custom_url_ok('https://127.0.0.1/v1') && !Mutqan_AI::custom_url_ok('https://localhost/v1') && Mutqan_AI::custom_url_ok('https://api.example.com/v1/chat/completions'));

echo $ok ? "sales: all passed\n" : "sales: FAILURES\n";
exit($ok ? 0 : 1);
