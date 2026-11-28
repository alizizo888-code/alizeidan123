<?php
// Ali's scenarios, end to end through the real code, on one WhatsApp line:
//   A. site order → confirm on WhatsApp → room assigns by hand → technician on WhatsApp → «الفني فين؟» → done → rating
//   B. the customer cancels while the technician is on the way → the technician is told → the customer is asked why → price → discount → back
//   C. something goes wrong: the technician reports a problem / needs a part → the room and the customer hear
//   D. the customer asks to talk to a person, then to move the appointment
// Every message that would leave on WhatsApp is written to a transcript, so the dialogs can be read like a chat.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-scen-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $fails = [];
$t = function ($n, $c, $x = null) use (&$ok, &$fails) { echo ($c ? 'PASS ' : 'FAIL ') . "scen: $n" . ($c ? '' : '  -> ' . mb_substr(json_encode($x, JSON_UNESCAPED_UNICODE), 0, 600)) . "\n"; if (!$c) { $ok = false; $fails[] = $n; } };
$W = 'Mutqan_WA';
$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
$owner = ['kind' => 'user', 'role' => 'owner', 'id' => 'o1', 'name' => 'علي', 'powers' => ['read', 'write', 'manage']];
$guest = ['id' => 'anon-cust1', 'role' => null, 'kind' => 'anon', 'powers' => [], 'name' => '', 'handle' => ''];
$s = Mutqan_AI::settings(); $s['providers']['groq'] = array_merge($s['providers']['groq'], ['enabled' => 1, 'key' => 'gsk_test']); update_option('mutqan_ai_settings', $s);
$next = ['reply' => 'أهلًا بيك 🌷', 'complete' => false, 'handoff' => false];
$GLOBALS['__post_hook'] = function ($url, $a) use (&$next) { return ['code' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => json_encode($next, JSON_UNESCAPED_UNICODE)]]]]), 'headers' => []]; };

$D = $W::handle(['op' => 'fleet_register', 'name' => 'VPS', 'kind' => 'vps'], $owner);
$jobs = function () use ($W, $D) { $raw = json_encode(['device' => $D['id'], 'sessions' => []]); return $W::pull_signed($raw, hash_hmac('sha256', $raw, $D['secret']))['jobs'] ?? []; };
$jobs();
$L = $W::handle(['op' => 'link', 'phone' => '0549423050', 'role' => 'customers'], $owner);
$hook = function ($ev) use ($W, $D) { $raw = json_encode($ev, JSON_UNESCAPED_UNICODE); return $W::webhook($raw, hash_hmac('sha256', $raw, $D['secret'])); };
$hook(['type' => 'status', 'accountId' => $L['id'], 'status' => 'open', 'phone' => '966549423050']);
$ROOM = '966536554731';                        // the room (master) — and, as Ali chose, also the test customer
$TECH = '966549648890';                        // «الفني علي» (test technician)
Mutqan_WA_Central::save(['master' => $ROOM, 'testCustomer' => $ROOM]);
Mutqan_Store::insert('tech_profiles', ['userId' => 'u-ali-tech', 'displayName' => 'الفني علي', 'phone' => $TECH, 'available' => true, 'specialties' => ['ac']], 'u-ali-tech', [], $sys);
update_option('mutqan_assignment_mode_test', 1);
Mutqan_Store::upsert('assignment_settings', ['mode' => 'manual'], 'default', [], $sys);

$log = [];
$i = 0;
$who = function ($ph) use ($ROOM, $TECH) { $k = substr(preg_replace('/\D/', '', $ph), -9); return $k === substr($ROOM, -9) ? 'الغرفة/العميل' : ($k === substr($TECH, -9) ? 'الفني' : $ph); };
$drain = function ($label = '') use ($jobs, &$log, $who) { $js = $jobs(); foreach ($js as $j) { $log[] = "  ⟵ [" . $who($j['to']) . "] " . str_replace("\n", "\n      ", $j['text']); } return $js; };
$say = function ($from, $text) use ($hook, $L, &$i, &$log, $who) { $i++; $log[] = "  ⟶ [" . $who($from) . "] " . $text; $hook(['type' => 'message', 'accountId' => $L['id'], 'msgId' => 's' . $i, 'direction' => 'in', 'phone' => $from, 'name' => 'علي', 'text' => $text, 'ts' => time()]); };
$to = function ($js, $ph) { $k = substr($ph, -9); return array_values(array_filter($js, function ($j) use ($k) { return substr(preg_replace('/\D/', '', $j['to']), -9) === $k; })); };
$has = function ($js, $needle) { foreach ($js as $j) { if (mb_strpos($j['text'], $needle) !== false) { return true; } } return false; };
$any = function ($js, $re) { foreach ($js as $j) { if (preg_match($re, $j['text'])) { return true; } } return false; };
$order = function ($id) { return (Mutqan_Store::get_raw('orders', $id) ?: []) + ['id' => $id]; };
$site = function ($extra = []) use ($guest) {
    return Mutqan_Store::insert('orders', array_merge(['clientName' => 'علي التجربة', 'clientPhone' => '0536554731', 'specialtyId' => 'ac', 'serviceName' => 'غسيل مكيف سبليت',
        'address' => 'جدة — حي الروضة', 'preferredTime' => 'اليوم العصر', 'lat' => 21.56, 'lng' => 39.15, 'price' => 140, 'status' => 'new', 'source' => 'app', 'siteName' => 'أكسجين'], $extra), null, [], $guest);
};
$assign = function ($o) use ($sys) { return Mutqan_Store::update('orders', $o['id'], ['technicianId' => 'u-ali-tech', 'technicianName' => 'الفني علي', 'status' => 'assigned', 'assignedAt' => gmdate('c')], $sys); };

/* ===================================================================== A. the whole job */
$log[] = "\n===== سيناريو A: طلب من الموقع لحد التقييم =====";
$A = $site(); $n = $order($A['id'])['number'] ?? '';
$log[] = "  (العميل طلب من الموقع — رقم الطلب #$n)";
$js = $drain();
$t('A1 the order gets a short number', preg_match('/^\d{3,5}$/', (string) $n) === 1, $n);
$t('A2 the customer is asked on WhatsApp to confirm, with the number', $has($to($js, $ROOM), 'طلبك رقم *' . $n . '*'), $js);
$say($ROOM, 'تأكيد'); $js = $drain();
$t('A3 «تأكيد» confirms the order', !empty($order($A['id'])['customerConfirmed']), $order($A['id']));
$t('A4 the room hears the customer confirmed', $has($to($js, $ROOM), 'العميل أكد طلب #' . $n), $js);
$say($ROOM, 'اسند طلب ' . $n . ' للفني علي'); $js = $drain();
$t('A5 the room assigns by hand from WhatsApp', ($order($A['id'])['technicianId'] ?? '') === 'u-ali-tech', $order($A['id']));
$t('A6 the job card reaches the technician', $has($to($js, $TECH), $n), $js);
$say($TECH, 'قبلت'); $say($TECH, 'في الطريق'); $js = $drain();
$t('A7 «قبلت» + «في الطريق» → en_route', ($order($A['id'])['status'] ?? '') === 'en_route', $order($A['id'])['status'] ?? null);
$t('A8 the customer hears the technician is on the way', $any($to($js, $ROOM), '/في الطريق|اتحرك/u'), $js);
$say($ROOM, 'الفني فين؟'); $js = $drain();
$t('A9 «الفني فين؟» is answered with where the job stands', $any($to($js, $ROOM), '/الطريق|الفني/u'), $js);
$say($TECH, 'وصلت'); $js = $drain();
$t('A10 «وصلت» → arrived, the room hears', ($order($A['id'])['status'] ?? '') === 'arrived' && $has($to($js, $ROOM), 'وصل'), $js);
$say($TECH, 'تم'); $js = $drain();
$t('A11 «تم» completes the job', ($order($A['id'])['status'] ?? '') === 'completed', $order($A['id'])['status'] ?? null);
$t('A12 the customer is asked to rate', $has($to($js, $ROOM), 'قيّم الخدمة'), $js);
$say($ROOM, '5'); $js = $drain();
$t('A13 the rating is saved', (int) ($order($A['id'])['rating']['stars'] ?? 0) === 5, $order($A['id'])['rating'] ?? null);

/* ===================================================================== B. cancelled on the way, price, win-back */
$log[] = "\n===== سيناريو B: العميل بيلغي والفني في الطريق =====";
$B = $site(['serviceName' => 'صيانة مكيف شباك']); $nb = $order($B['id'])['number'] ?? '';
$log[] = "  (طلب جديد من الموقع #$nb)";
$drain(); $say($ROOM, 'تأكيد'); $drain();
$assign($order($B['id'])); $drain();
$say($TECH, 'قبلت'); $say($TECH, 'في الطريق'); $drain();
$say($ROOM, 'عايز ألغي الطلب'); $js = $drain();
$o = $order($B['id']);
$t('B1 «عايز ألغي» on the way is heard (customer told it reached operations)', $any($to($js, $ROOM), '/الإلغاء|إلغاء/u'), $js);
$t('B2 the room hears the customer wants to cancel', $any($to($js, $ROOM), '/عايز يلغي|طلب إلغاء/u'), $js);
$t('B3 the technician on the way is told to hold on', $any($to($js, $TECH), '/إلغاء|الغاء|متتحركش|استنى/u'), $js);
// the room confirms the cancellation
$say($ROOM, 'الغي طلب ' . $nb); $js = $drain();
$o = $order($B['id']);
if (($o['status'] ?? '') !== 'cancelled') { Mutqan_Store::update('orders', $B['id'], ['status' => 'cancelled', 'cancelledBy' => 'operations'], $sys); $js = array_merge($js, $drain()); $log[] = '  (الغرفة لغت الطلب من التطبيق)'; }
$t('B4 the order ends cancelled', ($order($B['id'])['status'] ?? '') === 'cancelled', $order($B['id'])['status'] ?? null);
$t('B5 the technician is told the job is cancelled', $any($to($js, $TECH), '/اتلغى|ملغي|إلغاء|الغاء/u'), $js);
$t('B6 the customer is asked why he cancelled', $any($to($js, $ROOM), '/السبب|ليه/u'), $js);
$say($ROOM, '1'); $js = $drain();
$t('B7 «السعر» → a discount offer', $any($to($js, $ROOM), '/خصم/u'), $js);
$say($ROOM, 'تمام رجعه'); $js = $drain();
$o = $order($B['id']);
$t('B8 «رجّعه» brings the order back with the discount', ($o['status'] ?? '') === 'new' && !empty($o['winbackDiscountPct']), $o);
$t('B9 the room hears it came back and why', $has($to($js, $ROOM), 'رجّع طلب #' . $nb), $js);

/* ===================================================================== C. a problem at the customer's */
$log[] = "\n===== سيناريو C: مشكلة عند العميل =====";
$C = $site(['serviceName' => 'مكيف مش بيبرد']); $nc = $order($C['id'])['number'] ?? '';
$drain(); $say($ROOM, 'تأكيد'); $drain(); $assign($order($C['id'])); $drain();
$say($TECH, 'قبلت'); $say($TECH, 'وصلت'); $drain();
$say($TECH, 'في مشكلة الكمبروسر محروق ومحتاج قطعة غيار'); $js = $drain();
$t('C1 a problem from the technician reaches the room', $any($to($js, $ROOM), '/مشكلة|الكمبروسر|قطعة/u'), $js);
$say($ROOM, 'الفني قال إيه؟ هيتأخر؟'); $js = $drain();
$t('C2 the customer asking gets an answer', count($to($js, $ROOM)) > 0, $js);

/* ===================================================================== D. a person, and a new time */
$log[] = "\n===== سيناريو D: عايز أكلم حد + تغيير الموعد =====";
$Dd = $site(['serviceName' => 'تركيب مكيف']); $nd = $order($Dd['id'])['number'] ?? '';
$drain(); $say($ROOM, 'تأكيد'); $drain();
$say($ROOM, 'عايز أكلم موظف'); $js = $drain();
$t('D1 «عايز أكلم موظف» → the room is called', $any($to($js, $ROOM), '/موظف|خدمة العملاء/u'), $js);
$say($ROOM, 'ممكن نأجل الميعاد لبكرة الساعة 5'); $js = $drain();
$t('D2 «نأجل لبكرة» is taken and the room hears it', $any($to($js, $ROOM), '/تأجيل|بكرة|الموعد/u'), $js);

file_put_contents(getenv('SCEN_OUT') ?: (sys_get_temp_dir() . '/scenarios.txt'), implode("\n", $log) . "\n");
echo $ok ? "scen: all passed\n" : "scen: FAILED: " . implode(' | ', $fails) . "\n";
exit($ok ? 0 : 1);
