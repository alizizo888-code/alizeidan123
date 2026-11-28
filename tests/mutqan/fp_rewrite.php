<?php
// 2026-11-21: every new article was held as a draft because the AI rewrite asked for 700+ words with a fixed 900-token
// answer and a 6-second budget, so it could never pass. Normal calls stay short; the rewrite asks for room.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-fprw-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
if (!class_exists('Mutqan_Fp')) { require_once __DIR__ . '/../../oxygen-ac-automation/includes/mutqan/class-mutqan-fp.php'; }
if (!function_exists('wp_kses')) { function wp_kses($h, $a) { return strip_tags((string) $h, '<' . implode('><', array_keys($a)) . '>'); } }
if (!function_exists('get_transient')) { function get_transient($k) { return $GLOBALS['__tr'][$k] ?? false; } function set_transient($k, $v, $e = 0) { $GLOBALS['__tr'][$k] = $v; return true; } }
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "fp_rewrite: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$s = Mutqan_AI::settings(); $s['providers']['groq'] = array_merge($s['providers']['groq'], ['enabled' => 1, 'key' => 'gsk_test']); update_option('mutqan_ai_settings', $s);

$seen = []; $answer = '';
$GLOBALS['__post_hook'] = function ($url, $a) use (&$seen, &$answer) {
    $b = json_decode($a['body'], true); $seen[] = ['max' => $b['max_tokens'] ?? null, 'timeout' => $a['timeout'] ?? null];
    return ['code' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => $answer]]]], JSON_UNESCAPED_UNICODE), 'headers' => []];
};
$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];

$answer = 'تمام';
Mutqan_AI::run('سؤال قصير', ['feature' => 'test'], $sys);
$t('a normal answer keeps the short 900-token limit', end($seen)['max'] === 900, end($seen));

$words = [];
for ($i = 0; $i < 600; $i++) { $words[] = 'كلمة' . $i; }
$answer = '<h2>عنوان</h2><p>' . implode(' ', $words) . '</p>';
$r = Mutqan_Fp::rewrite_local('<p>' . str_repeat('نص قديم ', 200) . '</p>', 'صيانة مكيفات', 'أكسجين', '');
$last = end($seen);
$t('the rewrite asks for a long answer', (int) $last['max'] >= 6000, $last);
$t('the rewrite waits long enough for it', (int) $last['timeout'] >= 60, $last);
$t('a 600-word rewrite is accepted', ($r['html'] ?? '') !== '', $r);

Mutqan_AI::run('سؤال قصير تاني', ['feature' => 'test'], $sys);
$t('the next normal answer is short again', end($seen)['max'] === 900, end($seen));

if (!class_exists('Oxygen_Uniq')) { require_once __DIR__ . '/../../oxygen-ac-automation/includes/class-oxygen-uniq.php'; }
$t('a broken title of the old engine is repaired («في مكة المكرمة في جدة» → «في مكة المكرمة وجدة»)', Oxygen_Uniq::clean_title('صيانة الأجهزة المنزلية في مكة المكرمة في جدة') === 'صيانة الأجهزة المنزلية في مكة المكرمة وجدة');
echo $ok ? "ALL PASSED\n" : "SOME FAILED\n";
exit($ok ? 0 : 1);
