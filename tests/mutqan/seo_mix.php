<?php
// The two daily mixes: 70% cooling / refrigeration / cold & freezer rooms and 70% Makkah — core sites (mega engine) and the satellite plan.
define('ABSPATH', 1);
$OPT = [];
function get_option($k, $d = false) { global $OPT; return $OPT[$k] ?? $d; }
function update_option($k, $v, $a = null) { global $OPT; $OPT[$k] = $v; return true; }
function current_time($f) { return gmdate('Y-m-d'); }
class Oxygen_SEO_Engine { static function brand() { return 'x'; } }
class Oxygen_Network { static function current_id() { return 'aqsa'; } static function slot($id) { return 2; } static function registry() { return ['aqsa' => ['name' => 'x']]; } }
$inc = dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/';
require $inc . 'class-oxygen-seo-mega.php'; require $inc . 'class-oxygen-network-seo.php';
$fail = 0; $ok = function ($n, $c, $i = '') use (&$fail) { if (!$c) { $fail++; echo "FAIL $n $i\n"; } };
$map = []; $n = $f = $m = 0;
for ($i = 0; $i < 100; $i++) { $it = Oxygen_SEO_Mega::next($map, function () { return true; }); if (!$it) { break; } $ok('mega:unique', !isset($map[$it['key']])); $map[$it['key']] = 1; $n++; $f += Oxygen_SEO_Mega::is_focus($it) ? 1 : 0; $m += Oxygen_SEO_Mega::is_makkah($it) ? 1 : 0; }
$ok('mega:count', $n === 100, $n); $ok('mega:focus70', abs($f / $n - 0.7) <= 0.03, $f); $ok('mega:makkah70', abs($m / $n - 0.7) <= 0.03, $m);
$p = Oxygen_Network_SEO::plan('aqsa'); $n = count($p); $f = $m = 0;
foreach ($p as $it) { if (!empty($it['focus'])) { $f++; } if (mb_strpos($it['city'], 'مكة') !== false) { $m++; } }
$ok('net:focus70', abs($f / $n - 0.7) <= 0.03, $f / $n); $ok('net:makkah70', abs($m / $n - 0.7) <= 0.03, $m / $n);
// buy-intent wording: about 70% of the newest articles (core: key tN with N >= 20; satellites: key ...-cN) read like "أفضل شركة / فني / معلم / مهندس ..."
$cm = 0; foreach (array_keys($map) as $k) { if (preg_match('/-t(\d+)$/', $k, $q) && (int) $q[1] >= 20) { $cm++; } }
$ok('mega:buy-intent>=55%', $cm / 100 >= 0.55, $cm);
$cn = 0; foreach ($p as $it) { if (preg_match('/-c\d+(-f[0-9a-f]+)?$/', $it['key'])) { $cn++; } }
$ok('net:buy-intent~70%', abs($cn / $n - 0.7) <= 0.06, $cn / $n);
$ok('titles:real roles', (bool) preg_match('/فني|معلم|مهندس|أفضل شركة/u', implode(' ', array_map(function ($i) { return $i['title']; }, array_slice($p, 0, 40)))));
$ok('titles:unique in plan', count(array_unique(array_column($p, 'key'))) === $n);
echo $fail ? "seo_mix: $fail failed\n" : "seo_mix: 9/9 passed\n"; exit($fail ? 1 : 0);
