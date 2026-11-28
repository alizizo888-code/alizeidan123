<?php
// The WhatsApp control desk: one number, three different people on the other end.
//
// The number decides who is writing, because the alternative is a stranger typing "وصلت" and moving
// somebody's job. A technician gets job control, the team gets reports and dispatch, everyone else
// goes to the reception bot untouched.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
$GLOBALS['__sent'] = [];
function wp_remote_request($url, $a) {
    if (preg_match('#/sessions/([^/]+)/send$#', $url, $m)) { $b = json_decode($a['body'], true); $GLOBALS['__sent'][] = [$m[1], $b['to'], $b['text']]; return ['code' => 200, 'body' => json_encode(['msgId' => 'G' . count($GLOBALS['__sent'])]), 'headers' => []]; }
    return ['code' => 200, 'body' => json_encode(['sessions' => [['id' => 'acct1', 'status' => 'open']]]), 'headers' => []];
}
Mutqan_Store::maybe_install();
Mutqan_WA::config_set(['url' => 'https://wa.test', 'token' => 'tok']);   // a number is connected
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'wa-desk: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$D = 'Mutqan_WA_Desk';
$sys = Mutqan_WA::sys();

/* ---------------------------------------------------------------- the words a technician types */
$t('«وصلت» is arrival', $D::step_of('وصلت') === 'arrived', $D::step_of('وصلت'));
$t('«بدأت العمل» starts the job', $D::step_of('بدأت العمل') === 'in_progress', $D::step_of('بدأت العمل'));
$t('«بدات العمل» without the hamza is the same word', $D::step_of('بدات العمل') === 'in_progress', $D::step_of('بدات العمل'));
$t('«تم إنجاز المهمة» completes it', $D::step_of('تم إنجاز المهمة') === 'completed', $D::step_of('تم إنجاز المهمة'));
$t('«في الطريق» is on the way', $D::step_of('في الطريق') === 'en_route', $D::step_of('في الطريق'));
$t('«وصلت 1234» still reads as arrival', $D::step_of('وصلت 1234') === 'arrived', $D::step_of('وصلت 1234'));
$t('a sentence that only mentions arriving is not a command',
   $D::step_of('العميل بيقول إن الفني ما وصلت له امبارح') === '', $D::step_of('العميل بيقول إن الفني ما وصلت له امبارح'));
$t('an ordinary question is not a command', $D::step_of('كم سعر غسيل المكيف؟') === '');
$t('an empty message is not a command', $D::step_of('   ') === '');

/* ---------------------------------------------------------------- who is writing */
Mutqan_Store::insert('tech_profiles', ['displayName' => 'وليد', 'phone' => '0551234567', 'available' => true], 'u-walid', [], $sys);
Mutqan_Store::insert('tech_profiles', ['displayName' => 'علي طعت', 'phone' => '0559999999', 'available' => true], 'u-ali', [], $sys);
$who = $D::identify('966551234567');
$t('a technician is known by their number, in any international form', $who['kind'] === 'tech' && $who['id'] === 'u-walid', $who);
$t('and their name comes with it', ($who['name'] ?? '') === 'وليد', $who);
$t('a number nobody has is a customer', $D::identify('966500000001')['kind'] === 'customer');
$t('a number too short to be real is a customer', $D::identify('123')['kind'] === 'customer');

/* ---------------------------------------------------------------- a technician moves their job */
$order = Mutqan_Store::insert('orders', ['number' => '1041', 'clientName' => 'أبو محمد', 'clientPhone' => '0565555555',
    'serviceLabel' => 'صيانة تكييف', 'address' => 'حي النزهة', 'lat' => 21.42, 'lng' => 39.82,
    'status' => 'assigned', 'technicianId' => 'u-walid'], null, [], $sys);
$GLOBALS['__sent'] = [];
$handled = $D::inbound('acct1', '966551234567', 'وصلت', 't1', []);
$after = Mutqan_Store::get_raw('orders', $order['id']);
$t('the desk answers the technician, so the reception bot never sees it', $handled === true);
$t('and the order actually moved', ($after['status'] ?? '') === 'arrived', $after['status'] ?? null);
// The customer now also hears «arrived» on WhatsApp (Mutqan_OrderBot::on_status); only the technician's line is counted here.
$toTech = array_values(array_filter($GLOBALS['__sent'], function ($x) { return $x[1] === '966551234567'; }));
$t('the technician gets a short confirmation with the order number',
   count($toTech) === 1 && strpos($toTech[0][2], '1041') !== false, $GLOBALS['__sent']);

$GLOBALS['__sent'] = [];
$D::inbound('acct1', '966551234567', 'تم إنجاز المهمة', 't1', []);
$t('finishing the job completes the order', (Mutqan_Store::get_raw('orders', $order['id'])['status'] ?? '') === 'completed');

/* ---------------------------------------------------------------- free text from a technician */
$o2 = Mutqan_Store::insert('orders', ['number' => '1042', 'serviceLabel' => 'تبريد', 'status' => 'assigned', 'technicianId' => 'u-walid'], null, [], $sys);
$GLOBALS['__sent'] = [];
$D::inbound('acct1', '966551234567', 'الكمبروسر محتاج تغيير، بيتزر 3 حصان', 't1', []);
$t('a sentence that is not a command is filed as a note on the job',
   count($GLOBALS['__sent']) === 1 && preg_match('/ملاحظتك|سجلتها/u', $GLOBALS['__sent'][0][2]), $GLOBALS['__sent']);
// «محتاج تغيير» is a part the job needs: the technician is told the room heard it (sim_scenarios checks the room side).
$t('a part or a problem is answered «بلّغت غرفة العمليات»', strpos($GLOBALS['__sent'][0][2], 'بلّغت غرفة العمليات') !== false, $GLOBALS['__sent']);
$t('and the order is not moved by it', (Mutqan_Store::get_raw('orders', $o2['id'])['status'] ?? '') === 'assigned');

/* ---------------------------------------------------------------- two open jobs is ambiguous */
$o2b = Mutqan_Store::insert('orders', ['number' => '1043', 'serviceLabel' => 'سباكة', 'status' => 'assigned', 'technicianId' => 'u-walid'], null, [], $sys);
$GLOBALS['__sent'] = [];
$D::inbound('acct1', '966551234567', 'وصلت', 't1', []);
$t('with two jobs open, a bare "وصلت" asks which one instead of guessing',
   count($GLOBALS['__sent']) === 1 && strpos($GLOBALS['__sent'][0][2], 'رقم الطلب') !== false, $GLOBALS['__sent']);
$t('and nothing was moved', (Mutqan_Store::get_raw('orders', $o2['id'])['status'] ?? '') === 'assigned');
$GLOBALS['__sent'] = [];
$D::inbound('acct1', '966551234567', 'وصلت 1042', 't1', []);
$t('naming the order moves that one', (Mutqan_Store::get_raw('orders', $o2['id'])['status'] ?? '') === 'arrived');

/* ---------------------------------------------------------------- a technician with no job */
$GLOBALS['__sent'] = [];
$D::inbound('acct1', '966559999999', 'وصلت', 't2', []);
$t('a technician with nothing open is told so, and no order is touched',
   count($GLOBALS['__sent']) === 1 && strpos($GLOBALS['__sent'][0][2], 'مفيش طلب مفتوح') !== false, $GLOBALS['__sent']);

/* ---------------------------------------------------------------- the job card */
$card = $D::job_card(['number' => '1055', 'clientName' => 'أبو محمد', 'clientPhone' => '0565555555',
    'serviceLabel' => 'صيانة تكييف', 'address' => 'حي النزهة', 'lat' => 21.42, 'lng' => 39.82]);
foreach (['1055' => 'the order number', 'أبو محمد' => "the customer's name", '0565555555' => 'their phone',
          'صيانة تكييف' => 'what the job is', 'حي النزهة' => 'the address', 'maps.google.com/?q=21.42,39.82' => 'a map link'] as $needle => $what) {
    $t('the job card carries ' . $what, strpos($card, $needle) !== false, $needle);
}
$t('and it says which numbers to answer with (owner, 2026-11-24)', strpos($card, '1️⃣ قبلت الطلب') !== false && strpos($card, '6️⃣ خلصت الشغل') !== false);

/* ---------------------------------------------------------------- assigning sends it, once */
$GLOBALS['__sent'] = [];
$o3 = Mutqan_Store::insert('orders', ['number' => '1077', 'serviceLabel' => 'كهرباء', 'status' => 'new'], null, [], $sys);
Mutqan_Store::update('orders', $o3['id'], ['technicianId' => 'u-ali', 'status' => 'assigned'], $sys);
$t('assigning an order sends the card to that technician',
   count($GLOBALS['__sent']) === 1 && $GLOBALS['__sent'][0][1] === '966559999999' && strpos($GLOBALS['__sent'][0][2], '1077') !== false, $GLOBALS['__sent']);
$GLOBALS['__sent'] = [];
Mutqan_Store::update('orders', $o3['id'], ['status' => 'accepted'], $sys);
$t('a later change to the same order does not send it again', count($GLOBALS['__sent']) === 0, $GLOBALS['__sent']);

/* ---------------------------------------------------------------- the operations room */
$GLOBALS['wpdb']->insert(Mutqan_Store::table('users'), ['id' => 'u-ops', 'handle' => '540000000', 'display_name' => 'مشرف العمليات',
    'phone' => '0540000000', 'role' => 'operations', 'status' => 'active', 'password_hash' => 'x', 'created_at' => Mutqan_Store::now()]);
$boss = $D::identify('966540000000');
$t('someone from the team is known by their number too', ($boss['kind'] ?? '') === 'staff', $boss);
$rep = $D::report();
$t('the report counts what is waiting and what is running', strpos($rep, 'تقرير العمليات') !== false && strpos($rep, 'شغالة مع فنيين') !== false, $rep);
$techs = $D::techs_report();
$t('the technician roster names each one with a state', strpos($techs, 'وليد') !== false && strpos($techs, 'علي طعت') !== false, $techs);
$t('and marks whoever is on site', strpos($techs, 'في الموقع') !== false, $techs);

$GLOBALS['__sent'] = [];
$D::inbound('acct1', '966540000000', 'وجّه 1077 لوليد', 't3', []);
$moved = Mutqan_Store::get_raw('orders', $o3['id']);
$t('the operations room can dispatch an order by name, in plain words', ($moved['technicianId'] ?? '') === 'u-walid', $moved['technicianId'] ?? null);
$t('and the technician is told on WhatsApp — once, not twice',
   count(array_filter($GLOBALS['__sent'], function ($m) { return $m[1] === '966551234567'; })) === 1, $GLOBALS['__sent']);
$GLOBALS['__sent'] = [];
$D::inbound('acct1', '966540000000', 'اسحب 1077', 't3', []);
$t('and pull it back again', empty(Mutqan_Store::get_raw('orders', $o3['id'])['technicianId']),
   Mutqan_Store::get_raw('orders', $o3['id'])['technicianId'] ?? '(missing)');
$GLOBALS['__sent'] = [];
$D::inbound('acct1', '966540000000', 'وجّه 1077 لمحمود', 't3', []);
$t('a name nobody has is refused, not guessed at',
   count($GLOBALS['__sent']) === 1 && strpos($GLOBALS['__sent'][0][2], 'مالقيتش فني') !== false, $GLOBALS['__sent']);

/* ---------------------------------------------------------------- a customer is left to the bot */
$t('a customer is not handled here at all', $D::inbound('acct1', '966500000001', 'وصلت', 't4', []) === false);

/* ---------------------------------------------------------------- the model's action block */
$o4 = Mutqan_Store::insert('orders', ['number' => '2001', 'status' => 'assigned', 'technicianId' => 'u-walid'], null, [], $sys);
list($clean, $act) = $D::apply_action("تمام يا وليد، سجّلت وصولك. 📍\n{\"action\": \"update_status\", \"order_id\": \"2001\", \"new_status\": \"arrived\"}");
$t('an action block the model appends is carried out', ($act['status'] ?? '') === 'arrived' && (Mutqan_Store::get_raw('orders', $o4['id'])['status'] ?? '') === 'arrived', $act);
$t('and stripped out, so nobody is shown the machinery', strpos($clean, '{') === false && strpos($clean, 'سجّلت وصولك') !== false, $clean);
list($clean2, $act2) = $D::apply_action('رسالة عادية من غير أي بلوك.');
$t('a plain message is left exactly as it is', $clean2 === 'رسالة عادية من غير أي بلوك.' && $act2 === null);
list($clean3, $act3) = $D::apply_action('نص {"action": "update_status", "order_id": "2001", "new_status": "hacked"}');
$t('a status that is not one of ours is refused', $act3 === null && (Mutqan_Store::get_raw('orders', $o4['id'])['status'] ?? '') === 'arrived');

exit($ok ? 0 : 1);
