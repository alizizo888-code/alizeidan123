<?php
// SEO engine: two sites (oxygen11.com / mutqan.oxygen11.com) must never publish the same text.
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
$GLOBALS['__host'] = 'oxygen11.com';
$GLOBALS['__opts'] = [];
function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; }
function home_url($p = '/') { return 'https://' . $GLOBALS['__host'] . $p; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return $s; }
function esc_attr($s) { return esc_html($s); }
function plugins_url($p = '', $f = '') { return 'https://x/' . $p; }
require __DIR__ . '/../../oxygen-ac-automation/includes/class-oxygen-seo-engine.php';
$ok = true; $t = function ($n, $c) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . $n . "\n"; if (!$c) { $ok = false; } };
$E = 'Oxygen_SEO_Engine';
$sets = [];
foreach (['oxygen11.com', 'mutqan.oxygen11.com'] as $h) {
    $GLOBALS['__host'] = $h; $r = new ReflectionClass($E); $pc = $r->getProperty('cache'); $pc->setAccessible(true); $pc->setValue(null, []);
    $plan = $E::plan(); $sets[$h] = ['brand' => $E::brand(), 'plan' => $plan, 'html' => $E::build($plan[0], 0, [])['html'], 'first' => $plan[0]['key']];
}
$a = $sets['oxygen11.com']; $b = $sets['mutqan.oxygen11.com'];
$t('brands differ', $a['brand'] === 'أكسجين' && $b['brand'] === 'متقن');
$t('same plan size', count($a['plan']) === count($b['plan']) && count($a['plan']) >= 100);
$t('first article differs between sites', $a['first'] !== $b['first']);
$t('titles carry the site brand', str_ends_with($a['plan'][0]['title'], '| أكسجين') && str_ends_with($b['plan'][0]['title'], '| متقن'));
$words = function ($h) { return array_unique(preg_split('/\s+/u', trim(strip_tags($h)), -1, PREG_SPLIT_NO_EMPTY)); };
// same topic on both sites -> different angle & paragraph rotation
$ka = $a['plan'][0]['key']; $other = null; foreach ($b['plan'] as $it) { if ($it['key'] === $ka) { $other = $it; } }
$GLOBALS['__host'] = 'mutqan.oxygen11.com'; $pc->setValue(null, []); $hb = $E::build($other, 0, [])['html'];
$sa = $words($a['html']); $sb = $words($hb);
$jac = count(array_intersect($sa, $sb)) / max(1, count(array_unique(array_merge($sa, $sb))));
$t('same topic on both sites is not a copy (jaccard ' . round($jac, 2) . ')', $jac < 0.85 && strpos($hb, 'متقن') !== false && strpos($hb, 'أكسجين') === false);
$GLOBALS['__host'] = 'oxygen11.com'; $pc->setValue(null, []);
exit($ok ? 0 : 1);
