<?php
// What the rule bot learns from the model, for the moments the model is not there.
//
// The assistant is mostly the model with a bot behind it, and the bot only ever learned from the
// owner. So a weak connection or a rate limit meant questions the model had answered a hundred
// times went unanswered again. Most of what is checked here is what must NOT be replayed: a
// remembered price is a quote the business has to honour, and prices move.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'brain: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$B = 'Mutqan_Brain';
$Q = 'هل عندكم صيانة غرف تبريد؟';
$A = 'نعم، نقوم بصيانة غرف التبريد والتجميد وأعمال الفريون بالكامل.';

/* ---------------------------------------------------------------- said once is not the usual answer */
$B::remember($Q, $A, 'wa');
$t('the first answer is kept', Mutqan_Store::get_raw('wa_bot', 'ai:' . substr(md5(Mutqan_WA_Bot::norm($Q)), 0, 16)) !== null);
$t('but not yet repeated — one answer is as likely a misunderstanding as a habit', $B::recall($Q) === '');
$B::remember($Q, $A, 'wa');
$t('said twice, it is the usual answer', $B::recall($Q) === $A, $B::recall($Q));

/* ---------------------------------------------------------------- a figure is a promise */
$QP = 'كم سعر غسيل المكيف السبليت؟';
$AP = 'غسيل المكيف السبليت يبدأ من 120 ريال شامل الضمان.';
$B::remember($QP, $AP); $B::remember($QP, $AP);
$t('a price is recognised as a price', $B::has_figure($AP) === true);
$t('and is never repeated on its own, however often it was said', $B::recall($QP) === '', $B::recall($QP));
$id = 'ai:' . substr(md5(Mutqan_WA_Bot::norm($QP)), 0, 16);
Mutqan_Store::update('wa_bot', $id, ['status' => 'approved'], ['kind' => 'user', 'role' => 'owner', 'id' => 'o']);
$t('once the owner approves that line, it may be said', $B::recall($QP) === $AP);

/* ---------------------------------------------------------------- the owner's no is final */
$QR = 'هل تشتغلون يوم الجمعة الصبح؟';
$AR = 'نعم نعمل طوال الأسبوع من الثامنة صباحًا.';
$B::remember($QR, $AR); $B::remember($QR, $AR);
$rid = 'ai:' . substr(md5(Mutqan_WA_Bot::norm($QR)), 0, 16);
Mutqan_Store::update('wa_bot', $rid, ['status' => 'rejected'], ['kind' => 'user', 'role' => 'owner', 'id' => 'o']);
$t('a rejected line is not said', $B::recall($QR) === '');
$B::remember($QR, $AR);
$t('and a counter ticking over does not ask the owner again',
   (Mutqan_Store::get_raw('wa_bot', $rid)['status'] ?? '') === 'rejected');

/* ---------------------------------------------------------------- things change */
$QO = 'عندكم خدمة تنظيف الخزانات؟';
$AO = 'نعم، لدينا فريق متخصص لتنظيف وتعقيم الخزانات.';
$B::remember($QO, $AO); $B::remember($QO, $AO);
$oid = 'ai:' . substr(md5(Mutqan_WA_Bot::norm($QO)), 0, 16);
Mutqan_Store::update('wa_bot', $oid, ['at' => gmdate('c', time() - (Mutqan_Brain::MAX_AGE + 86400))], ['kind' => 'user', 'role' => 'owner', 'id' => 'o']);
$t('an answer older than a season is not repeated — hours and services move', $B::recall($QO) === '');

/* ---------------------------------------------------------------- nothing worth keeping */
$t('a question too short to mean anything is not kept', $B::remember('ها', 'إجابة طويلة كفاية للحفظ') === false);
$t('a one-word answer is not kept', $B::remember('سؤال كامل وواضح', 'نعم') === false);
$t('and neither is an essay', $B::remember('سؤال كامل وواضح', str_repeat('ا', 900)) === false);
$t('nothing was ever learned for a question never asked', $B::recall('سؤال لم يُسأل من قبل أبدًا') === '');

/* ---------------------------------------------------------------- only while the model is away */
$s = Mutqan_AI::settings();
$s['providers']['groq'] = array_merge($s['providers']['groq'], ['enabled' => 1, 'key' => 'gsk_test']);
update_option(Mutqan_AI::OPT, $s, false);
delete_transient('mq_cool_groq');
$t('with a provider reachable, the model answers and nothing is replayed', $B::ai_down() === false && $B::fallback($Q) === '');
set_transient('mq_cool_groq', 1, 600);
$t('with every provider cooling, the model is away', $B::ai_down() === true);
$t('and then the bot says what it learned', $B::fallback($Q) === $A, $B::fallback($Q));
$t('but still not a price', $B::fallback('كم سعر غسيل المكيف السبليت؟ شي تاني') === '');
update_option(Mutqan_AI::OPT, array_merge($s, ['providers' => array_merge($s['providers'], ['groq' => array_merge($s['providers']['groq'], ['enabled' => 0])])]), false);
$t('no provider configured at all is also away', $B::ai_down() === true);

/* ---------------------------------------------------------------- it is a fallback, not a shortcut */
$src = file_get_contents(dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/mutqan/class-mutqan-brain.php');
$t('nothing is replayed without first checking the model is unreachable',
   strpos($src, 'if (!self::ai_down()) { return \'\'; }') !== false);

exit($ok ? 0 : 1);
