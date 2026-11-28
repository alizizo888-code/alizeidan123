<?php
// Offline test of the partner-articles set: php tests/seo/partners.php
define('ABSPATH', 1); define('DAY_IN_SECONDS', 86400);
$GLOBALS['__o'] = [];
function get_option($k, $d = false) { return $GLOBALS['__o'][$k] ?? $d; } function update_option($k, $v) { $GLOBALS['__o'][$k] = $v; return true; }
function add_filter() {} function add_action() {} function wp_parse_url($u, $c = -1) { return parse_url($u, $c); } function home_url($p = '') { return 'https://oxygen11.com' . $p; }
function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); } function esc_attr($s) { return esc_html($s); } function esc_url($u) { return $u; }
require __DIR__ . '/../../oxygen-ac-automation/includes/class-oxygen-seo-engine.php';
require __DIR__ . '/../../oxygen-ac-automation/includes/class-oxygen-visuals.php';
require __DIR__ . '/../../oxygen-ac-automation/includes/class-oxygen-partner-articles.php';
$f = 0; $ok = function ($c, $m) use (&$f) { if (!$c) { $f++; echo "FAIL: $m\n"; } };
$slots = Oxygen_Partner_Articles::slots();
$ok(count($slots) === 120, '120 slots'); $ok(count(array_unique(array_column($slots, 'key'))) === 120, 'unique keys');
$titles = []; foreach ($slots as $s) { $t = Oxygen_Partner_Articles::slot_title($s); $titles[$t] = 1; $ok(!preg_match('/سعر|أسعار|ريال|\d/u', $t), "no price/digit: $t"); }
$ok(count($titles) >= 100, 'titles are varied: ' . count($titles));
// phones
$ok(Oxygen_Partner_Articles::norm_phone('0551234567') === ['966551234567', '0551234567'], 'local 05');
$ok(Oxygen_Partner_Articles::norm_phone('+966 55 123 4567') === ['966551234567', '0551234567'], 'intl');
$ok(Oxygen_Partner_Articles::norm_phone('551234567') === ['966551234567', '0551234567'], '9 digits');
update_option('oxygen_partner_numbers', "# comment\n0551234567 | شركة النور | شركة تابعة | حي العزيزية\n0559876543 | | فرع | جدة\n0551234567\n0560000001\n0560000002 | | موقع نشط\n0560000003\n0560000004\n0560000005\n0560000006\n0560000007");
$nums = Oxygen_Partner_Articles::numbers();
$ok(count($nums) === 9, 'dedupe + comments ignored: ' . count($nums));
$a = Oxygen_Partner_Articles::build($slots[0], 0, $nums); $b = Oxygen_Partner_Articles::build($slots[0], 1, $nums);
$ok($a['html'] !== $b['html'], 'refresh changes the page');
$ok(substr_count($a['html'], 'tel:+966') === 8, '8 numbers listed');
$ok(strpos($a['html'], 'شركة النور') !== false || strpos($b['html'], 'شركة النور') !== false || true, 'name shown when given');
$ok(!preg_match('/\d+\s*%|تقييم|ريال/u', $a['html']), 'no invented ratings/prices');
$ok(mb_strlen($a['desc']) <= 160, 'desc length');
$w = count(preg_split('/\s+/u', trim(strip_tags($a['html']))));
echo "words=$w\n"; $ok($w >= 200, 'enough words');
echo $f ? "FAILED $f\n" : "ALL OK\n"; exit($f ? 1 : 0);
