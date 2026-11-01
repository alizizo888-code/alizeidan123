<?php
// The 10 satellite article plans: 70/30 service mix, 70/30 place mix, per-site rotation, no collisions, gradual ramp.
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
$GLOBALS['__host'] = 'raad.oxygen11.com';
$GLOBALS['__opts'] = [];
function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; }
function home_url($p = '/') { return 'https://' . $GLOBALS['__host'] . $p; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function wp_date($f) { return date($f); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return $s; }
function esc_attr($s) { return esc_html($s); }
function plugins_url($p = '', $f = '') { return 'https://x/' . $p; }
$I = __DIR__ . '/../../oxygen-ac-automation/includes/';
require $I . 'class-oxygen-network.php'; require $I . 'class-oxygen-seo-engine.php'; require $I . 'class-oxygen-network-seo.php';
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "network_seo: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$E = 'Oxygen_SEO_Engine';
$reset = function ($host) use ($E) { $GLOBALS['__host'] = $host; $r = new ReflectionClass($E); $pc = $r->getProperty('cache'); $pc->setAccessible(true); $pc->setValue(null, []); };
$sats = Oxygen_Network::satellites();
$t('20 satellites', count($sats) === 20);
$all = []; $titles = []; $keys = [];
foreach ($sats as $id) {
    $reset($id . '.oxygen11.com');
    $t("$id is a satellite with its own brand", Oxygen_Network::is_satellite() && $E::brand() === Oxygen_Network::registry()[$id]['name'] && $E::salt() === 13 + 7 * Oxygen_Network::slot($id));
    $plan = $E::plan(); $all[$id] = $plan;
    $n = count($plan);
    $head = array_slice($plan, 0, 1000);
    $foc = count(array_filter($head, function ($i) { return in_array($i['sid'], ['ac-wash', 'ac-maint', 'ac-install', 'ac-central', 'cold-room', 'freezer-room', 'fridge'], true); })) / 1000;
    $loc = count(array_filter($head, function ($i) { return $i['city'] === 'مكة المكرمة' || $i['city'] === 'جدة' || $i['city'] === 'مكة وجدة' || (strpos($i['city'], 'بمكة المكرمة') !== false) || (strpos($i['city'], 'بجدة') !== false); })) / 1000;
    $t("$id plan size + 70% A/C-cold ($foc) + 70% Makkah/Jeddah ($loc)", $n >= 2900 && $foc >= 0.67 && $foc <= 0.73 && $loc >= 0.67 && $loc <= 0.73, [$n, $foc, $loc]);
    $t("$id has both cold-room kinds and other fields", count(array_filter($plan, function ($i) { return $i['sid'] === 'cold-room'; })) > 20 && count(array_filter($plan, function ($i) { return $i['sid'] === 'plumbing'; })) > 5);
    $t("$id keys namespaced + owned", !array_filter($plan, function ($i) use ($E, $id) { return strpos($i['key'], 'oxy-net-' . $id . '-') !== 0 || !$E::owns($i); }));
    $t("$id titles end with its brand", !array_filter($head, function ($i) use ($id) { return substr($i['title'], -strlen(' | ' . Oxygen_Network::registry()[$id]['name'])) !== ' | ' . Oxygen_Network::registry()[$id]['name']; }));
    foreach ($plan as $it) { $keys[$it['key']] = ($keys[$it['key']] ?? 0) + 1; }
    $titles[$id] = array_column(array_slice($plan, 0, 300), 'title');
}
$t('no key shared by two satellites', max($keys) === 1);
$flat = []; foreach ($titles as $id => $ts) { foreach ($ts as $x) { $flat[$x] = ($flat[$x] ?? 0) + 1; } }
$t('first 300 titles unique across all 10 sites', max($flat) === 1, array_slice(array_filter($flat, function ($v) { return $v > 1; }), 0, 3));
$firsts = array_map(function ($p) { return $p[0]['sid'] . '|' . $p[0]['city'] . '|' . $p[0]['title']; }, $all);
$t('every site starts with a different article', count(array_unique($firsts)) === 20);
// how alike are two sites' first 300 articles (same service in the same place)? must stay low
$pairs = []; foreach ($all as $id => $pl) { $pairs[$id] = array_flip(array_map(function ($i) { return $i['sid'] . '|' . $i['city'] . '|' . ($i['facet'] ?? ''); }, array_slice($pl, 0, 300))); }
$ids = array_keys($pairs); $worst = 0; for ($i = 0; $i < 20; $i++) { for ($j = $i + 1; $j < 20; $j++) { $worst = max($worst, count(array_intersect_key($pairs[$ids[$i]], $pairs[$ids[$j]])) / 300); } }
$t('no two sites overlap on more than 15% of (service, place, facet) topics in the first 300 articles (worst ' . round($worst, 3) . ')', $worst <= 0.15, $worst);
// body: same builder, different rotation per site; never an empty / broken article
$reset('raad.oxygen11.com'); $pa = $E::plan(); $reset('khabeer.oxygen11.com'); $pb = $E::plan();
$ha = null;
$reset('raad.oxygen11.com'); $ha = $E::build($pa[0], 0, [])['html'];
$reset('khabeer.oxygen11.com'); $hb = $E::build($pb[0], 0, [])['html'];
$t('bodies built, brand-named', strlen($ha) > 1500 && strpos($ha, 'رعد السماء') !== false && strpos($hb, 'الخبير') !== false && strpos($ha, 'الخبير') === false);
$cold = null; foreach ($pb as $i) { if ($i['sid'] === 'cold-room') { $cold = $i; break; } }
$hc = $E::build($cold, 0, [])['html'];
$t('a cold-room article is built from its own service data', strpos($hc, 'غرف التبريد') !== false && strlen($hc) > 1500);
$pq = $E::build($pb[3], 0, []); $t('faq + desc present', !empty($pq['faq']) && !empty($pq['desc']));
// core sites unaffected
$reset('oxygen11.com'); $core = $E::plan(); $t('core plan untouched (no oxy-net keys, no cold-room)', !array_filter($core, function ($i) { return strpos($i['key'], 'oxy-net-') === 0 || $i['sid'] === 'cold-room'; }) && $E::brand() === 'أكسجين');
$reset('mutqan.oxygen11.com'); $t('mutqan core unchanged', $E::brand() === 'متقن' && $E::salt() === 11);
// rhythm: 6 burst days at the core sites' pace, then 10 a day
$GLOBALS['__opts'] = ['oxygen_net_seo' => ['burst' => 180, 'burst_days' => 6, 'steady' => 10, 'since' => 1000000]];
$day = 86400;
$t('burst: first day 180', Oxygen_Network_SEO::quota(1000000 + 100) === 180 && Oxygen_Network_SEO::phase(1000000 + 100) === 'burst');
$t('burst: still 180 on day 6 (index 5)', Oxygen_Network_SEO::quota(1000000 + 5 * $day + 80000) === 180);
$t('then 10 a day from the 7th day', Oxygen_Network_SEO::quota(1000000 + 6 * $day) === 10 && Oxygen_Network_SEO::phase(1000000 + 6 * $day) === 'steady' && Oxygen_Network_SEO::quota(1000000 + 90 * $day) === 10);
$t('plan is long enough for 6 burst days + 40 steady days', count($all['raad']) >= 6 * 180 + 40 * 10);
echo $ok ? "ALL PASSED\n" : "SOME FAILED\n";
exit($ok ? 0 : 1);
