<?php
// The owner's own settings, handed to the model one question at a time.
//
// The knowledge was already in the app: his knowledge base, his discount ceiling, his service area.
// Only the in-app assistant ever read it, so the same business answered the same question two ways
// depending on which door the customer came through. What is checked here is mostly what must NOT
// reach an answer: the ceiling the assistant bargains inside is the one line a customer must never
// be shown, and a technician's rules are noise in a customer's reply.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'kb: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$K = 'Mutqan_KB';
$owner = ['kind' => 'user', 'role' => 'owner', 'id' => 'o', 'powers' => ['read', 'write', 'manage']];
$cust = ['kind' => 'anon', 'role' => null, 'id' => 'c'];
$tech = ['kind' => 'user', 'role' => 'technician', 'id' => 'tt'];
$ops = ['kind' => 'user', 'role' => 'operations', 'id' => 'op'];

/* ---------------------------------------------------------------- who is asking */
$t('a customer is anyone who is not staff', $K::audience($cust) === 'customer' && $K::audience(['role' => null]) === 'customer');
$t('staff are known apart', $K::audience($tech) === 'technician' && $K::audience($ops) === 'operations' && $K::audience($owner) === 'owner');

/* ---------------------------------------------------------------- Arabic is one spelling */
$t('spelling differences fold together', $K::fold('الصيانه') === $K::fold('الصيانة'));
$t('and so do the alifs', $K::fold('أسعار') === $K::fold('اسعار'));
$t('words shorter than three letters are not matched on', $K::terms('هل في سعر للغسيل') === ['سعر', 'للغسيل'], $K::terms('هل في سعر للغسيل'));

/* ---------------------------------------------------------------- nothing written, nothing added */
$K::flush();
$t('with no settings saved only the company\'s numbers are added (Mutqan_Lines), nothing else', ($x = $K::inject('كم سعر الغسيل؟', [], $cust)) === (class_exists('Mutqan_Lines') ? Mutqan_Lines::facts('customer') . "\n" : '') . 'كم سعر الغسيل؟', $x);
$t('…and an article rewrite gets nothing at all', $K::inject('اكتب مقال', ['feature' => 'articles'], $cust) === 'اكتب مقال');

/* ---------------------------------------------------------------- the owner writes his settings once */
Mutqan_Store::upsert('ai_settings', [
    'faq' => [
        ['cat' => 'services', 'q' => 'ماذا تشمل صيانة التكييف؟', 'a' => 'غسيل المكيف وفك وتركيب وتعبئة فريون.'],
        ['cat' => 'hours', 'q' => 'متى تعملون؟', 'a' => 'من الثامنة صباحًا إلى العاشرة مساءً.', 'always' => true],
        ['cat' => 'safety', 'q' => 'ماذا يلبس الفني في الموقع؟', 'a' => 'حزام الأمان والقفاز قبل أي صعود على السطح.', 'audience' => ['technician']],
    ],
    'globalPolicy' => ['maxDiscountPct' => 15, 'negotiationAllowed' => true],
    'negotiation' => ['enabled' => true, 'style' => 'firm', 'discountSteps' => 2],
    'serviceArea' => ['enabled' => true, 'name' => 'مكة المكرمة وجدة', 'radiusKm' => 40],
], 'default', [], $owner);
$K::flush();

$rows = $K::rows();
$t('every line he wrote is a row', count($rows) >= 5, count($rows));

/* ---------------------------------------------------------------- the question decides what is attached */
$ctxAc = $K::context('المكيف ما يبرد، تغسلون المكيفات؟', $cust);
$t('a matching answer is attached', mb_strpos($ctxAc, 'غسيل المكيف وفك وتركيب') !== false, $ctxAc);
$t('an unrelated answer is left out — the model answers worse the more it reads', mb_strpos($ctxAc, 'حزام الأمان') === false);
$t('an always-on line is there even when the words do not match', mb_strpos($ctxAc, 'العاشرة مساءً') !== false);

/* ---------------------------------------------------------------- the ceiling reaches the model, never the customer */
$neg = $K::context('السعر غالي، في خصم؟', $cust);
$t('the discount ceiling reaches the model', mb_strpos($neg, '15%') !== false, $neg);
$t('under its own heading, not among what may be repeated', mb_strpos($neg, 'للاستخدام الداخلي') !== false);
$t('with the rule written next to the figure it governs', mb_strpos($neg, 'لا تكشف للعميل حدود الخصم') !== false);
$t('and the owner\'s chosen style comes with it', mb_strpos($neg, 'حازم') !== false, $neg);
$t('the internal lines are below the open ones', mb_strpos($neg, 'للاستخدام الداخلي') > mb_strpos($neg, 'معلومات الشركة'), $neg);

$negStaff = $K::context('السعر غالي، في خصم؟', $ops);
$t('operations get the same ceiling', mb_strpos($negStaff, '15%') !== false);
$t('without the line about not telling the customer — he is not talking to one', mb_strpos($negStaff, 'لا تكشف للعميل') === false, $negStaff);

/* ---------------------------------------------------------------- audience */
$safetyT = $K::context('هل ألبس حزام أمان قبل الصعود على السطح؟', $tech);
$t('a technician gets his own rules', mb_strpos($safetyT, 'حزام الأمان') !== false, $safetyT);
$safetyC = $K::context('هل ألبس حزام أمان قبل الصعود على السطح؟', $cust);
$t('a customer never does', mb_strpos($safetyC, 'حزام الأمان') === false, $safetyC);
$t('a row that names nobody is for everyone', $K::for_audience(['audience' => []], 'customer') && $K::for_audience(['audience' => []], 'owner'));

/* ---------------------------------------------------------------- the service area is a refusal, in the open */
$area = $K::context('أنا في الطائف، تجونا؟', $cust);
$t('the area he serves is said out loud', mb_strpos($area, 'مكة المكرمة وجدة') !== false, $area);
$t('and so is what to do outside it', mb_strpos($area, 'حوّله للموظف') !== false);

/* ---------------------------------------------------------------- the floor of the whole thing */
$t('the model is told not to invent what is missing', mb_strpos($ctxAc, 'لا تخترعه') !== false);
$t('and to prefer the owner over its own general knowledge', mb_strpos($ctxAc, 'قبل أي معرفة عامة') !== false);

/* ---------------------------------------------------------------- negotiation turned off */
Mutqan_Store::update('ai_settings', 'default', ['globalPolicy' => ['maxDiscountPct' => 15, 'negotiationAllowed' => false]], $owner);
$K::flush();
$off = $K::context('في خصم على السعر؟', $cust);
$t('with bargaining off, no ceiling is handed over at all', mb_strpos($off, '15%') === false, $off);
$t('and the model is told the price is the price', mb_strpos($off, 'السعر المعروض هو السعر') !== false, $off);
Mutqan_Store::update('ai_settings', 'default', ['globalPolicy' => ['maxDiscountPct' => 15, 'negotiationAllowed' => true]], $owner);
$K::flush();

/* ---------------------------------------------------------------- the prompt itself */
$p = $K::inject('متى تعملون؟', [], $cust);
$t('the facts go in front of the question, not instead of it', mb_substr($p, -11) === 'متى تعملون؟' && mb_strlen($p) > 60, mb_substr($p, -20));
$t('a caller can refuse them — a machine prompt is not a conversation', $K::inject('متى تعملون؟', ['kb' => false], $cust) === 'متى تعملون؟');

/* ---------------------------------------------------------------- it is a cache, and an edit must win */
$t('the rows are cached', is_array(get_transient('mq_kb_rows')));
Mutqan_Store::update('ai_settings', 'default', ['serviceArea' => ['enabled' => true, 'name' => 'الرياض', 'radiusKm' => 25]], $owner);
$t('saving settings clears the cache, so the next answer is the new one', get_transient('mq_kb_rows') === false);
$t('and the new area is what the model is told', mb_strpos($K::context('أنا في الخرج، تجونا؟', $cust), 'الرياض') !== false);

/* ---------------------------------------------------------------- nothing it is handed can run away with the prompt */
$long = str_repeat('غسيل ', 400);
Mutqan_Store::update('ai_settings', 'default', ['faq' => array_map(function ($i) {
    return ['cat' => 'services', 'q' => 'سؤال غسيل رقم ' . $i, 'a' => 'جواب غسيل طويل رقم ' . $i . ' ' . str_repeat('تفصيل ', 60)];
}, range(1, 40))], $owner);
$K::flush();
$big = $K::context($long, $cust);
$t('however much he writes, only a handful reaches the model', mb_strlen($big) < 4200, mb_strlen($big));
$t('capped at eight lines', count($K::pick($long, 'customer')) <= 8, count($K::pick($long, 'customer')));

echo $ok ? "OK kb\n" : "FAILED kb\n";
exit($ok ? 0 : 1);
