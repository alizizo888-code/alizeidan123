<?php
// Offline test of the keyword universe: php tests/seo/mega.php
define('ABSPATH', 1);
$GLOBALS['__o'] = [];
function get_option($k, $d = false) { return $GLOBALS['__o'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['__o'][$k] = $v; return true; }
function add_filter() {} function add_action() {}
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); } function home_url() { return 'https://oxygen11.com'; }
require __DIR__ . '/../../oxygen-ac-automation/includes/class-oxygen-seo-engine.php';
$f = 0; $ok = function ($c, $m) use (&$f) { if (!$c) { $f++; echo "FAIL: $m\n"; } };
$counts = Oxygen_SEO_Mega::counts();
echo "districts={$counts['districts']} places={$counts['places']} brands={$counts['brands']} items={$counts['items']}\n";
$ok($counts['items'] >= 138000, 'at least 80x the base plan (1,732)');
$max = count(Oxygen_SEO_Mega::entities()) * 21 * 20; $keys = []; $kws = []; $n = 0; $titles = [];
for ($i = 0; $i < $max; $i++) {
    $it = Oxygen_SEO_Mega::item($i); if (!$it) { continue; } $n++;
    if (isset($keys[$it['key']])) { $ok(false, 'dup key ' . $it['key']); break; } $keys[$it['key']] = 1;
    if (isset($kws[$it['kw']])) { $ok(false, 'dup keyword ' . $it['kw']); break; } $kws[$it['kw']] = 1;
    $ok(!preg_match('/سعر|أسعار|ريال|ر\.س|\d/u', $it['title']), 'price/digit in title: ' . $it['title']);
    $titles[$it['title']] = 1;
    $r = Oxygen_SEO_Mega::item_by_key($it['key']);
    $ok($r && $r['title'] === $it['title'] && $r['kw'] === $it['kw'] && $r['idx'] === $it['idx'], 'reverse key ' . $it['key']);
    if ($f) { break; }
}
$ok($n === $counts['items'], 'enumeration equals count');
$ok(count($titles) === $n, 'every title unique');
// Makkah first: in the first 300 items, Makkah outnumber Jeddah ~3:1 and many different districts appear
$mk = 0; $jd = 0; $d = [];
for ($i = 0; $i < 300; $i++) { $it = Oxygen_SEO_Mega::item($i); if (!$it) { continue; } if (mb_strpos($it['city'], 'جدة') !== false) { $jd++; } else { $mk++; } if (!empty($it['district'])) { $d[$it['district']] = 1; } }
echo "first300: makkah=$mk jeddah=$jd districts=" . count($d) . "\n";
$ok($mk > 2 * $jd, 'Makkah first'); $ok(count($d) >= 40, 'many districts early (not one district for every service)');
// content builds with real words for each kind
foreach ([0, 5, 9, 11] as $i) {
    $it = Oxygen_SEO_Mega::item($i); $b = Oxygen_SEO_Engine::build($it, 0, ['app' => '#', 'wa' => '']);
    $w = Oxygen_SEO_Engine::word_count($b['text']);
    echo "[$i] {$it['title']} words=$w\n";
    $ok($w >= 250, "words >= 250 for $i"); $ok(mb_strlen($b['desc']) <= 160, 'desc length');
}
echo $f ? "FAILED $f\n" : "ALL OK\n"; exit($f ? 1 : 0);
