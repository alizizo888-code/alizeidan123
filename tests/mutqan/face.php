<?php
// Whose site is this? One code base and one app build serve 22 faces, so the name a visitor reads
// must come from the host and from nowhere else. This is the test that failed in the field: the متقن
// build went live on oxygen11.com and the site called itself متقن, because the brand had nowhere to
// come from but the bundle. Covers the brand a host resolves to, the identity options the plugin
// writes, and the app shell it serves.
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
$GLOBALS['__host'] = 'oxygen11.com';
$GLOBALS['__opts'] = [];
$GLOBALS['__users'] = [];
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['__opts']) ? $GLOBALS['__opts'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['__opts'][$k]); return true; }
function get_users($a = []) { return array_keys($GLOBALS['__users']); }
function wp_update_user($a) { $GLOBALS['__users'][$a['ID']] = $a['display_name']; return $a['ID']; }
function home_url($p = '/') { return 'https://' . $GLOBALS['__host'] . $p; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return $s; }
// The real plugins_url takes the FOLDER of the file it is handed, which is the whole point of the
// check below: a path that names app/ again lands the browser on .../app/app/assets/ and the app
// never loads. This stand-in reproduces that behaviour rather than ignoring the second argument.
function plugins_url($p = '', $f = '') {
    $folder = $f === '' ? '' : trim(str_replace(dirname(__DIR__, 2) . '/oxygen-ac-automation', '', dirname($f)), '/');
    return 'https://x/plugins/oxygen-ac-automation' . ($folder === '' ? '' : '/' . $folder) . '/' . $p;
}
function plugin_dir_path($f) { return dirname(__DIR__, 2) . '/oxygen-ac-automation/'; }
function wp_json_encode($v, $o = 0) { return json_encode($v, $o); }
$I = dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/';
require_once __DIR__ . '/wp_text_shim.php';
require $I . 'class-oxygen-network.php';
require $I . 'class-oxygen-seo-engine.php';
require $I . 'mutqan/class-mutqan-rest.php';

$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "face: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$at = function ($host, $opts = []) { $GLOBALS['__host'] = $host; $GLOBALS['__opts'] = $opts; $GLOBALS['__users'] = [7 => 'old']; return Oxygen_SEO_Engine::brand(); };

/* ---------- the host decides, and nothing overrides it ---------- */
$t('oxygen11.com is أكسجين even with a stale override and another face\'s name on the site',
   $at('oxygen11.com', ['oxygen_seo_brand' => 'متقن', 'blogname' => 'متقن MUTQAN']) === 'أكسجين');
$t('www. and a port do not hide the host',
   $at('www.oxygen11.com', ['oxygen_seo_brand' => 'متقن']) === 'أكسجين' && $at('oxygen11.com:8080') === 'أكسجين');
$t('mutqan.oxygen11.com is متقن', $at('mutqan.oxygen11.com', ['oxygen_seo_brand' => 'أكسجين']) === 'متقن');
$t('a satellite keeps its own name', $at('raad-maintenance-general-contracting.oxygen11.com', ['blogname' => 'متقن']) === 'رعد السماء');
$t('a Blogger face maps to its brand', $at('blog-baroda.oxygen11.com') === 'برودة');
$t('an unregistered host keeps the name its owner gave the site', $at('staging.example.com', ['blogname' => 'شركة فلان']) === 'شركة فلان');
$t('an unregistered host with no name at all falls back to the old override', $at('staging.example.com', ['oxygen_seo_brand' => 'اسم قديم']) === 'اسم قديم');

/* ---------- a name somebody chose is never ours to replace ---------- */
$at('oxygen11.com', ['blogname' => 'OXYGEN']);
Oxygen_SEO_Engine::clean_identity();
$t('a Latin name is left alone (this rule renamed a live site)', get_option('blogname') === 'OXYGEN');
$at('oxygen11.com', ['blogname' => 'اسم العميل']);
Oxygen_SEO_Engine::clean_identity();
$t('an Arabic name is left alone', get_option('blogname') === 'اسم العميل');
$at('oxygen11.com', ['blogname' => '   ']);
Oxygen_SEO_Engine::clean_identity();
$t('an empty name is filled in, once', get_option('blogname') === 'أكسجين' && get_option('oxygen_identity_v1') === '1');

/* ---------- the one-time repair: only a name taken by another face ---------- */
$at('oxygen11.com', ['blogname' => 'متقن', 'oxygen_seo_brand' => 'متقن']);
Oxygen_SEO_Engine::repair_identity();
$t('another face\'s name is put back, and the stale override dropped',
   get_option('blogname') === 'أكسجين' && get_option('oxygen_seo_brand') === false);
$at('oxygen11.com', ['blogname' => 'مُتقن']);
Oxygen_SEO_Engine::repair_identity();
$t('vowel marks do not hide it', get_option('blogname') === 'أكسجين');
$at('raad-maintenance-general-contracting.oxygen11.com', ['blogname' => 'متقن']);
Oxygen_SEO_Engine::repair_identity();
$t('a satellite renamed to متقن is restored', get_option('blogname') === 'رعد السماء');
$at('oxygen11.com', ['blogname' => 'شركة العميل']);
Oxygen_SEO_Engine::repair_identity();
$t('a name no other face uses is the owner\'s own', get_option('blogname') === 'شركة العميل');
$at('oxygen11.com', ['blogname' => 'متقن MUTQAN']);
Oxygen_SEO_Engine::repair_identity();
$t('a name that merely contains another face\'s is not touched either', get_option('blogname') === 'متقن MUTQAN');
$at('oxygen11.com', ['blogname' => 'متقن', 'oxygen_identity_repair_v1' => '1']);
Oxygen_SEO_Engine::repair_identity();
$t('it never runs twice', get_option('blogname') === 'متقن');
$at('staging.example.com', ['blogname' => 'متقن']);
Oxygen_SEO_Engine::repair_identity();
$t('an unregistered host is never repaired', get_option('blogname') === 'متقن');

/* ---------- the app shell: every face, not just the satellites ---------- */
$shell = '<html><head><title>متقن MUTQAN — صيانة</title><meta property="og:title" content="متقن MUTQAN — الصيانة" />'
    . '<meta name="apple-mobile-web-app-title" content="MUTQAN للصيانة" /><meta name="application-name" content="MUTQAN للصيانة" />'
    . '<script type="application/ld+json">{"name":"متقن MUTQAN للصيانة"}</script><meta name="theme-color" content="#eff7f5" />'
    . '</head><body><div class="mark"><svg viewBox="0 0 24 24"><path d="M12 2.5"/></svg></div><div class="bt">MUTQAN</div></body></html>';
$named = function ($id) { $b = Oxygen_Network::brand_config($id); return Mutqan_Rest::brand_html($GLOBALS['shell_src'], $b); };
$GLOBALS['shell_src'] = $shell;
$GLOBALS['__host'] = 'oxygen11.com';
$ox = $named('oxygen');
$t('the shell wears the host\'s name: no متقن and no MUTQAN left on an أكسجين site',
   strpos($ox, 'متقن') === false && strpos($ox, 'MUTQAN') === false && strpos($ox, 'أكسجين') !== false, substr($ox, 0, 160));
$t('and its wordmark, title and colour are its own',
   strpos($ox, '<div class="bt">OXYGEN</div>') !== false
   && strpos($ox, '<title>أكسجين للصيانة والمقاولات العامة') === 0 + strpos($ox, '<title>')
   && strpos($ox, 'content="#38bdf8"') !== false);
$t('the structured data of another brand is dropped, not translated', strpos($ox, 'ld+json') === false);
$t('a core face with no emblem of its own keeps the plain shield', strpos($ox, '<div class="mark"><svg') !== false);
$GLOBALS['__host'] = 'raad-maintenance-general-contracting.oxygen11.com';
$raad = $named('raad');
$t('a satellite with its own emblem shows it', strpos($raad, '<div class="mark"><img') !== false && strpos($raad, 'رعد السماء') !== false);
$GLOBALS['__host'] = 'mutqan.oxygen11.com';
$mq = $named('mutqan');
$t('متقن still reads as متقن MUTQAN', strpos($mq, '<div class="bt">MUTQAN</div>') !== false && strpos($mq, 'متقن') !== false);

/* ---------- the app loads its own files from the plugin, not from the web root ---------- */
$built = file_get_contents(dirname(__DIR__, 2) . '/oxygen-ac-automation/app/index.html');
preg_match('#<script[^>]+src="([^"]+)"#', $built, $m);
$t('the shipped shell points at the plugin\'s own app folder, not /assets/ at the site root',
   isset($m[1]) && strpos($m[1], '/wp-content/plugins/') === 0 && strpos($m[1], '/app/assets/') !== false, $m[1] ?? null);
$fixed = Mutqan_Rest::brand_html($built, Oxygen_Network::brand_config('oxygen'));
$fixed = preg_replace('#(src|href)="[^"]*/app/assets/#', '${1}="https://x/app/assets/', $fixed);
$t('and a plugin folder under another name is pointed back at the real files',
   strpos($fixed, 'src="https://x/app/assets/') !== false && strpos($fixed, '/wp-content/plugins/') === false);

$t('the asset URL points at the build\'s own folder, with app/ named once',
   Mutqan_Rest::app_assets_url() === 'https://x/plugins/oxygen-ac-automation/app/assets/', Mutqan_Rest::app_assets_url());

/* ---------- a chunk loaded later must find itself, whatever the folder is called ---------- */
// The staff area loads on demand. With the folder compiled in, installing the plugin under any
// other name made every one of those pages 404 and the app reload itself onto the WordPress page.
// The build keeps earlier chunks for clients still holding an old page, so read the entry index.html loads.
$appDir = dirname(__DIR__, 2) . '/oxygen-ac-automation/app';
$entry = preg_match('#assets/(index-[\w-]+\.js)#', (string) @file_get_contents($appDir . '/index.html'), $em) ? $appDir . '/assets/' . $em[1] : '';
$js = $entry !== '' && is_file($entry) ? [$entry] : glob($appDir . '/assets/index-*.js');
$bundle = $js ? file_get_contents($js[0]) : '';
$t('the build asks the page where its files are', strpos($bundle, 'window.__MQ_ASSETS__') !== false);
$t('and the shell tells it the APP folder — the build adds «assets/» itself, so naming it twice 404s every on-demand screen',
   strpos(file_get_contents(dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/mutqan/class-mutqan-rest.php'), "preg_replace('#assets/\$#', '', trailingslashit(\$assets_path))") !== false);
$t('the build itself never asks for assets/assets/ even if the page says assets/', strpos($bundle, ".replace(/assets.$/,'')") !== false);
$t('and the shell tells it, before the first module runs',
   strpos(file_get_contents(dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/mutqan/class-mutqan-rest.php'), 'window.__MQ_ASSETS__=') !== false);

/* ---------- the app build must not carry a brand name of its own ---------- */
$js = dirname(__DIR__, 2) . '/mutqan-app/src/lib/netBrand.js';
$src = file_get_contents($js);
$t('the app takes its name from the injected face, never from the build',
   preg_match('/export (const|let) BN = NET.name \|\| BAKED/', $src) && strpos($src, "NET.core ? 'متقن'") === false, '');
$t('and renames every screen whenever the injected face differs from the build',
   strpos($src, 'NET_RENAMED = !!NET.name && NET.name !== BAKED') !== false);

exit($ok ? 0 : 1);
