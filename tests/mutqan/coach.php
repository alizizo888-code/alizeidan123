<?php
// «مدرّب متقن»: the artifact's bridge — knowledge for every channel, learned answers, unanswered questions,
// standing instructions, commands with the confirm rule, and Claude Code tickets. WordPress admins only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-coach-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "coach: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
$C = 'Mutqan_Coach';
$err = function ($fn) { try { $fn(); return ''; } catch (Mutqan_Error $e) { return $e->slug; } };

$t('the door is WordPress administrators only', strpos(file_get_contents(__DIR__ . '/../../oxygen-ac-automation/includes/mutqan/class-mutqan-coach.php'), "current_user_can('manage_options')") !== false);

// teach a FAQ: model knowledge for every channel + instant WhatsApp reply
$r = $C::handle(['op' => 'faq_add', 'q' => 'هل عندكم ضمان؟', 'a' => 'أيوه، ضمان 3 شهور على التركيب و30 يوم على الصيانة.', 'cat' => 'الضمان', 'keywords' => 'ضمان، كفالة']);
$s = $C::handle(['op' => 'state']);
$t('a taught answer reaches the knowledge every channel reads', count($s['faq']) === 1 && $s['faq'][0]['q'] === 'هل عندكم ضمان؟', $s['faq']);
$kb = Mutqan_KB::pick('عندكم ضمان على التركيب؟', 'customer');
$t('the model sees it on the next customer question', (bool) array_filter((array) $kb, function ($r) { return strpos($r['body'], '3 شهور') !== false; }), $kb);
$tb = $C::handle(['op' => 'test_bot', 'text' => 'في كفالة؟']);
$t('and WhatsApp answers it instantly by keyword (no model)', in_array('أيوه، ضمان 3 شهور على التركيب و30 يوم على الصيانة.', $tb['rules'], true), $tb);
$C::handle(['op' => 'faq_add', 'q' => 'هل عندكم ضمان؟', 'a' => 'ضمان 6 شهور.']);
$s = $C::handle(['op' => 'state']);
$t('teaching the same question again updates it, no duplicate', count($s['faq']) === 1 && $s['faq'][0]['a'] === 'ضمان 6 شهور.', $s['faq']);

// learned answers and unanswered questions
$aiId = 'ai:' . substr(md5(Mutqan_WA_Bot::norm('بتشتغلوا يوم الجمعة؟')), 0, 16);
Mutqan_Store::upsert('wa_bot', ['kind' => 'ai', 'q' => 'بتشتغلوا يوم الجمعة؟', 'a' => 'أيوه من العصر.', 'count' => 3, 'status' => 'pending', 'at' => gmdate('c')], $aiId, [], $sys);
Mutqan_Store::upsert('wa_bot', ['kind' => 'miss', 'q' => 'بتصلحوا غسالات؟', 'count' => 5, 'at' => gmdate('c')], 'miss:y1', [], $sys);
$s = $C::handle(['op' => 'state']);
$t('what the bot learned waits for approval, unanswered questions listed', count($s['learned']) === 1 && count($s['misses']) === 1 && $s['misses'][0]['count'] === 5);
$C::handle(['op' => 'learned_approve', 'id' => $aiId, 'a' => 'أيوه، الجمعة من 4 العصر.', 'to_faq' => true]);
$t('approving (with a correction) makes it the bot\'s answer and a FAQ', Mutqan_Brain::recall('بتشتغلوا يوم الجمعة؟') === 'أيوه، الجمعة من 4 العصر.' && count($C::handle(['op' => 'state'])['faq']) === 2);
$C::handle(['op' => 'miss_answer', 'id' => 'miss:y1', 'a' => 'أيوه، صيانة غسالات أوتوماتيك وعادي.', 'keywords' => 'غسالة، غسالات']);
$s = $C::handle(['op' => 'state']);
$t('answering an unanswered question teaches it and clears it', count($s['misses']) === 0 && count($s['faq']) === 3, $s['misses']);

// standing instructions
$C::handle(['op' => 'note_add', 'text' => 'ركّز على الضمان قبل أي خصم.']);
$s = $C::handle(['op' => 'state']);
$t('a standing instruction is kept for every conversation', (bool) array_filter($s['notes'], function ($n) { return $n['text'] === 'ركّز على الضمان قبل أي خصم.'; }));
$i = array_values(array_filter($s['notes'], function ($n) { return $n['text'] === 'ركّز على الضمان قبل أي خصم.'; }))[0]['i'];
$C::handle(['op' => 'note_delete', 'i' => $i]);
$t('and removable', !array_filter($C::handle(['op' => 'state'])['notes'], function ($n) { return $n['text'] === 'ركّز على الضمان قبل أي خصم.'; }));

// execution
Mutqan_Store::insert('orders', ['status' => 'new', 'number' => '901', 'clientName' => 'ماجد'], null, [], $sys);
$r = $C::handle(['op' => 'run', 'text' => 'الطلبات الجديدة']);
$t('plain-words commands run on the internal engine', strpos($r['text'] ?? '', '#901') !== false, $r);
$r = $C::handle(['op' => 'run', 'text' => 'اعمل حاجة غريبة مش معروفة']);
$t('an unknown command says so instead of guessing', !empty($r['unknown']));
$r = $C::handle(['op' => 'tool', 'name' => 'wa_send', 'input' => ['phone' => '0551112222', 'text' => 'أهلًا']]);
$t('a WhatsApp send waits for confirmation', !empty($r['pending']['id']), $r);
$t('unknown tools refused', $err(function () use ($C) { $C::handle(['op' => 'tool', 'name' => 'shell', 'input' => []]); }) === 'bad_tool');
$r = $C::handle(['op' => 'tool', 'name' => 'orders_list', 'input' => ['limit' => 5]]);
$t('reads run at once with a readable answer', strpos($r['text'], '901') !== false, $r);

// Claude Code tickets
$tk = $C::handle(['op' => 'ticket_add', 'kind' => 'fix', 'title' => 'البوت بيرد بالفصحى', 'detail' => 'عايزه يرد بالعامية']);
$C::handle(['op' => 'ticket_update', 'id' => $tk['id'], 'status' => 'done', 'result' => 'اتظبط الأسلوب']);
$s = $C::handle(['op' => 'state']);
$t('requests for Claude Code are kept with their outcome', $s['tickets'][0]['status'] === 'done' && $s['tickets'][0]['result'] === 'اتظبط الأسلوب', $s['tickets']);
$t('the state carries tools, commands and the day\'s overview', count($s['tools']) > 20 && strpos($s['commands'], 'اسند') !== false && strpos($s['overview'], 'الوضع') !== false);
exit($ok ? 0 : 1);
