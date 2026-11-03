<?php
// Article pages are laid out like the app's home: shop front first (hero + contact, offers banner, specialties, destinations, gallery),
// the article explanation comes last under «شرح المقال».
define('ABSPATH', 1); define('MINUTE_IN_SECONDS', 60);
$OPT = ['oxygen_business_control' => ['phone' => '+966500000000', 'whatsapp' => '966500000000', 'gallery_urls' => "https://x/a.jpg\nhttps://x/b.jpg\nhttps://x/c.jpg"]];
function get_option($k, $d = false) { global $OPT; return $OPT[$k] ?? $d; }
function get_transient($k) { return false; } function set_transient($k, $v, $t) { return true; }
function apply_filters($t, $v) { return $v; } function add_filter() {} function add_action() {}
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); } function esc_attr($s) { return esc_html($s); } function esc_url($s) { return $s; }
function home_url($p = '/') { return 'https://oxygen11.com' . $p; } function plugins_url($p = '', $f = '') { return 'https://oxygen11.com/wp-content/plugins/x/' . $p; }
function is_admin() { return false; } function is_feed() { return false; } function is_singular($t = null) { return true; } function in_the_loop() { return true; } function is_main_query() { return true; }
function get_the_ID() { return 7; } function get_the_title($i = 0) { return 'أفضل فني اصلاح تكيفات في حي العزيزية بمكة'; }
function get_post_meta($id, $k, $s = true) { return ['_oxy_seo_key' => 'oxy-m-abc123-ac-maint-t30', '_oxy_seo_kw' => 'أفضل فني اصلاح تكيفات العزيزية'][$k] ?? ''; }
function wp_strip_all_tags($s) { return strip_tags($s); } function get_page_by_path($p) { return null; }
class Oxygen_SEO_Engine { static function brand() { return 'أكسجين'; } static function settings() { return ['wa' => '966500000000']; } }
class Oxygen_Visuals { static function specs() { return ['ac' => ['التكييف', 'snow']]; } static function detect($t) { return 'ac'; } static function hero($s, $t, $x = '') { return '<div class="oxv-hero">HERO</div>'; }
  static function promo() { return '<div class="oxv-promo">PROMO</div>'; } static function figure() { return '<figure>FIG</figure>'; } static function photos() { return []; } }
require dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/class-oxygen-article-boost.php';
$out = Oxygen_Article_Boost::content('<p>مقدمة</p><h2>الأول</h2><p>نص</p><h2>الثاني</h2><p>نص</p>');
$fail = 0; $ok = function ($n, $c) use (&$fail) { if (!$c) { $fail++; echo "FAIL $n\n"; } };
$pos = function ($needle) use ($out) { $p = strpos($out, $needle); return $p === false ? -1 : $p; };
$ok('storefront present', $pos('class="oxf"') >= 0);
$ok('hero + 3 contact cards', $pos('oxf-hero') >= 0 && substr_count($out, 'oxf-card oxf-c') === 3 && strpos($out, 'tel:+966500000000') !== false && strpos($out, 'wa.me/966500000000') !== false);
$ok('banner carousel', $pos('oxb-car') >= 0);
$ok('specialties grid, cooling first', $pos('تخصصاتنا') >= 0 && ($t0 = $pos('تخصصاتنا')) >= 0 && strpos($out, 'تكييف: غسيل وصيانة وتركيب', $t0) < strpos($out, '>كهرباء<', $t0 - 1) || strpos($out, 'تكييف: غسيل وصيانة وتركيب', $t0) < strpos($out, '</span>كهرباء', $t0));
$ok('destinations, Makkah first', $pos('وجهاتنا') >= 0 && $pos('class="mk"') > 0 && $pos('class="mk"') < $pos('>جدة<'));
$ok('gallery', $pos('oxb-gal') >= 0);
$ok('explanation heading comes after the storefront', $pos('شرح المقال') > $pos('oxb-gal') && $pos('شرح المقال') > $pos('وجهاتنا'));
$ok('article text comes after the explanation heading', $pos('مقدمة') > $pos('شرح المقال') && $pos('الثاني') > $pos('مقدمة'));
$ok('article text is not before the storefront', $pos('مقدمة') > $pos('class="oxf"'));
echo $fail ? "article_front: $fail failed\n" : "article_front: 9/9 passed\n"; exit($fail ? 1 : 0);
