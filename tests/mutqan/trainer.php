<?php
// The paid fallback by audience and the nightly trainer (owner, 2026-11-20):
//   customers: free providers first, then the cheapest Claude; technicians: free, then the next; owner/operations: the
//   strong model first; background jobs never start on Claude. The trainer: dispatch drills, a judged conversation,
//   a failed answer becomes a lesson for the owner, approval puts it in the FAQ, the morning report.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
require dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/mutqan/class-mutqan-trainer.php';
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'trainer: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
$owner = ['id' => 'u-owner', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
$tech = ['id' => 'u-tech', 'role' => 'technician', 'powers' => ['read', 'write'], 'kind' => 'user'];
$guest = ['id' => 'anon-1', 'role' => null, 'kind' => 'anon', 'powers' => []];

$s = Mutqan_AI::settings();
$s['providers']['groq'] = array_merge($s['providers']['groq'], ['enabled' => 1, 'key' => 'gsk_test']);
$s['providers']['anthropic'] = array_merge($s['providers']['anthropic'], ['enabled' => 1, 'key' => 'sk-ant-test']);
update_option('mutqan_ai_settings', $s);

$calls = [];
$freeDown = false;
$reply = function ($text) { return ['code' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => $text]]]]), 'headers' => []]; };
$claude = function ($text) { return ['code' => 200, 'body' => json_encode(['content' => [['type' => 'text', 'text' => $text]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]]), 'headers' => []]; };
$answer = '{"reply":"أهلًا وسهلًا، غسيل السبليت حسب الجدول. كم عدد المكيفات؟","lead":{"service":"غسيل"},"complete":false,"handoff":false}';
$GLOBALS['__post_hook'] = function ($url, $a) use (&$calls, &$freeDown, $reply, $claude, &$answer) {
    $body = json_decode((string) ($a['body'] ?? ''), true) ?: [];
    if (strpos($url, 'anthropic.com') !== false) { $calls[] = 'claude:' . ($body['model'] ?? ''); return $claude(strpos(json_encode($body, JSON_UNESCAPED_UNICODE), 'مشرف جودة') !== false ? '{"score":9,"issues":[],"better":""}' : $answer); }
    $calls[] = 'free';
    if ($freeDown) { return ['code' => 429, 'body' => '{"error":{"message":"rate limit"}}', 'headers' => ['retry-after' => '60']]; }
    $prompt = json_encode($body, JSON_UNESCAPED_UNICODE);
    if (strpos($prompt, 'مشرف جودة') !== false) { return $reply($GLOBALS['__judge'] ?? '{"score":9,"issues":[],"better":""}'); }
    return $reply($answer);
};
$model = function () use (&$calls) { foreach ($calls as $c) { if (strpos($c, 'claude:') === 0) { return substr($c, 7); } } return ''; };
$reset = function () use (&$calls) { $calls = []; foreach (['groq', 'anthropic'] as $id) { delete_transient('mq_cool_' . $id); } };

/* ---------------------------------------------------------------- who gets which model */
$t('audience: a guest is a customer', Mutqan_AI::audience([], $guest) === 'customer');
$t('audience: the hub answering a customer (system account, wa_hub) is still a customer', Mutqan_AI::audience(['feature' => 'wa_hub'], $sys) === 'customer');
$t('audience: a technician', Mutqan_AI::audience([], $tech) === 'technician');
$t('audience: the owner', Mutqan_AI::audience([], $owner) === 'manager');
$t('audience: a background job', Mutqan_AI::audience(['feature' => 'articles'], $sys) === 'system');

$reset(); $freeDown = false;
$r = Mutqan_AI::run('سؤال عميل عن السعر', ['feature' => 'site_chat', 'tier' => 'low'], $guest);
$t('customer, free provider up: answered for free, Claude not called', ($r['provider'] ?? '') === 'groq' && $model() === '', $calls);

$reset(); $freeDown = true;
$r = Mutqan_AI::run('سؤال عميل عن السعر', ['feature' => 'site_chat', 'tier' => 'low'], $guest);
$t('customer, free providers exhausted: the cheapest Claude (Haiku) answers', ($r['provider'] ?? '') === 'anthropic' && strpos($model(), 'haiku') !== false, $calls);

$reset(); $freeDown = true;
$r = Mutqan_AI::run('الفني يسأل عن خطوات', [], $tech);
$t('technician, free exhausted: the next model up (Sonnet)', strpos($model(), 'sonnet') !== false, $calls);

$reset(); $freeDown = false;
$r = Mutqan_AI::run('خطة الأسبوع للعمليات', [], $owner);
$t('owner: the strong model first (Opus), before any free provider', strpos($model(), 'opus') !== false && ($calls[0] ?? '') !== 'free', $calls);

$reset(); $freeDown = false;
$r = Mutqan_AI::run('لخص التقرير', ['feature' => 'articles'], $sys);
$t('background job: free first, Claude untouched while free answers', ($r['provider'] ?? '') === 'groq' && $model() === '', $calls);

/* ---------------------------------------------------------------- dispatch drills */
foreach (Mutqan_Trainer::drills() as $d) {
    $r = Mutqan_Trainer::run_one($d['id']);
    $t('drill ' . $d['id'] . ' picks the right technician', !empty($r['pass']), $r);
}

/* ---------------------------------------------------------------- a judged conversation */
$reset(); $freeDown = false;
$GLOBALS['__judge'] = '{"score":9,"issues":[],"better":""}';
$r = Mutqan_Trainer::run_one('price-split');
$t('a good conversation passes', !empty($r['pass']) && $r['score'] >= 7, $r);

$reset();
$answer = '{"reply":"تمام، نخلي الموعد بكرة الصبح إن شاء الله","lead":{"service":"صيانة"},"complete":false,"handoff":false}';
$GLOBALS['__judge'] = '{"score":4,"issues":["اقترح بكرة"],"better":"تمام، نسجل لك الطلب اليوم بأقرب وقت. في أي حي؟"}';
$r = Mutqan_Trainer::run_one('no-tomorrow');
$t('pushing the customer to tomorrow fails (rule + judge)', empty($r['pass']) && in_array('اقترح بكرة/الصبح من نفسه (ممنوع)', $r['issues'], true), $r);
$st = Mutqan_Trainer::status();
$lesson = $st['lessons'][0] ?? null;
$t('the better answer waits for the owner as a lesson', $lesson && strpos($lesson['a'], 'اليوم') !== false, $st['lessons']);
$t('the run is kept with its transcript', !empty($st['runs']) && !empty($st['runs'][0]['transcript']), $st['runs'][0] ?? null);

$out = Mutqan_Trainer::handle(['op' => 'lesson', 'id' => $lesson['id'], 'decision' => 'approved', 'a' => 'نسجل لك الطلب اليوم بأقرب وقت. في أي حي؟'], $owner);
$faq = (array) ((Mutqan_Store::get_raw('ai_settings', 'default') ?: [])['faq'] ?? []);
$t('approving puts the answer in the FAQ every channel reads', ($out['status'] ?? '') === 'approved' && count(array_filter($faq, function ($f) { return ($f['cat'] ?? '') === 'تدريب'; })) === 1, $faq);
$t('…and it is no longer waiting', count(Mutqan_Trainer::status()['lessons']) === 0);

$denied = false; try { Mutqan_Trainer::handle(['op' => 'status'], $tech); } catch (Mutqan_Error $e) { $denied = $e->status === 403; }
$t('a technician cannot open the trainer', $denied);

/* ---------------------------------------------------------------- the plan and the report */
$s = Mutqan_Trainer::state();
$t('runs for 60 days from the first day', (int) $s['days'] === 60 && Mutqan_Trainer::active($s));
$s['day'] = Mutqan_Trainer::today(); $s['plan'] = ['disp-skip-off', 'disp-specialty']; $s['results'] = []; $s['reported'] = '';
update_option('mutqan_trainer', $s);
Mutqan_Trainer::tick();
$rep = array_values(array_filter(Mutqan_Store::select('_push', [], ['limit' => 50], $sys), function ($m) { return ($m['event'] ?? '') === 'trainer_report'; }));
$t('a day with nothing to decide sends no report (quiet learning)', count($rep) === 0, $rep);
Mutqan_Trainer::tick();
$rep2 = array_filter(Mutqan_Store::select('_push', [], ['limit' => 50], $sys), function ($m) { return ($m['event'] ?? '') === 'trainer_report'; });
$t('…and stays quiet', count($rep2) === 0);


/* ---------------------------------------------------------------- «عقل Claude»: one read, one write */
$b = Mutqan_Coach::handle(['op' => 'brief']);
$t('brief: house rules, trainer runs and FAQ in one answer', strpos($b['rules'], 'الأسعار الرسمية') !== false && !empty($b['runs']) && is_array($b['faq']), array_keys($b));
$ap = Mutqan_Coach::handle(['op' => 'apply', 'faq' => [['q' => 'هل عندكم ضمان؟', 'a' => 'أيوه، ضمان على الشغل حسب نوع الخدمة.']], 'notes' => ['رحّب بالعميل باسم الشركة اللي جه منها'],
    'scenarios' => [['kind' => 'chat', 'turns' => ['عندي مكيف يطلع صوت عالي'], 'expect' => 'يسأل عن النوع ويسجّل الطلب اليوم']]]);
$t('apply: FAQ, instruction and a new scenario written in one call', $ap['faq'] === 1 && $ap['notes'] === 1 && $ap['scenarios'] === 1, $ap);
$ids = array_column(Mutqan_Trainer::all_scenarios(), 'id');
$t('…and the nightly trainer now practises Claude\'s scenario', count(array_filter($ids, function ($i) { return strpos($i, 'cl-') === 0; })) === 1, $ids);


/* ---------------------------------------------------------------- inside the control assistant */
$names = array_column(Mutqan_Agent::tools(), 'name');
$t('the control assistant has the trainer tools', !array_diff(['trainer_status', 'trainer_lesson', 'trainer_run'], $names), $names);
$ts = Mutqan_Agent::run_tool('trainer_status', [], $owner);
$t('…and «what did the assistant practise?» answers from the trainer', isset($ts['results']) && isset($ts['claude']), $ts);
$tr = Mutqan_Agent::run_tool('trainer_run', ['id' => 'disp-specialty'], $owner);
$t('…and can run one exercise on request', !empty($tr['pass']), $tr);

echo $ok ? "ALL PASS trainer\n" : "FAILED trainer\n";
exit($ok ? 0 : 1);
