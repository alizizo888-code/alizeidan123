<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «وضع الصيانة» (owner, 2026-11-24): one switch, and every page a visitor or customer opens — the site, the
 * customer app, the WhatsApp order page — shows one picture: the plug pulled out, «بنجهّز لكم حاجة أحسن… راجعين قريب».
 *
 * What keeps working while it is on, so the owner can switch it off and the business does not stop:
 *  - wp-admin, wp-login, the REST API (the desk, the technicians' app, the WhatsApp bot and the center use it),
 *    cron, the WhatsApp center (/wa-center/), the order seal (/v/) and pay pages (/pay/);
 *  - anyone holding the bypass link (?mq_open=<key>, then a cookie for 12 hours) and logged-in administrators.
 * The answer is a 503 with Retry-After, so search engines do not drop the pages.
 */
final class Mutqan_Maintenance {

    const OPT = 'mutqan_maintenance';

    public static function boot() { add_action('init', [__CLASS__, 'gate'], 1); }

    public static function get() {
        $o = get_option(self::OPT, []);
        $o = is_array($o) ? $o : [];
        if (empty($o['key'])) { $o['key'] = wp_generate_password(20, false); update_option(self::OPT, $o, false); }
        return array_merge(['on' => false, 'title' => 'الموقع في صيانة سريعة', 'msg' => 'بنجهّز لكم حاجة أحسن 🔌 راجعين خلال وقت قصير. للطوارئ كلّمنا على الواتساب.', 'at' => '', 'by' => ''], $o);
    }

    public static function bypass_url() { return add_query_arg('mq_open', self::get()['key'], home_url('/')); }

    /** POST /maintenance — {op:get|set, on, title, msg}: the owner only. */
    public static function handle($in, $p) {
        if (($p['role'] ?? '') !== 'owner') { throw new Mutqan_Error('forbidden', 'للمالك فقط.', 403); }
        // the WhatsApp order-page link (the center's «رابط صفحة الطلب») — the same switch, from the owner's controls
        if (($in['op'] ?? '') === 'store_link' && class_exists('MWC_Route')) {
            if (isset($in['on'])) { MWC_Route::save_routing(['store_link' => $in['on'] ? 1 : 0]); }
            return ['storeLink' => !empty(MWC_Route::routing()['store_link'])];
        }
        $o = self::get();
        if (($in['op'] ?? 'get') === 'set') {
            if (isset($in['on'])) { $o['on'] = (bool) $in['on']; $o['at'] = gmdate('c'); $o['by'] = (string) ($p['name'] ?? 'المالك'); }
            if (isset($in['title'])) { $o['title'] = mb_substr(sanitize_text_field((string) $in['title']), 0, 80) ?: 'الموقع في صيانة سريعة'; }
            if (isset($in['msg'])) { $o['msg'] = mb_substr(sanitize_textarea_field((string) $in['msg']), 0, 300); }
            update_option(self::OPT, $o, false);
            if (function_exists('litespeed_purge_all')) { litespeed_purge_all(); }
            do_action('litespeed_purge_all');
        }
        return ['on' => (bool) $o['on'], 'title' => $o['title'], 'msg' => $o['msg'], 'at' => $o['at'], 'by' => $o['by'], 'bypass' => self::bypass_url(), 'storeLink' => class_exists('MWC_Route') ? !empty(MWC_Route::routing()['store_link']) : null];
    }

    public static function gate() {
        if (empty($_SERVER['REQUEST_URI']) || (defined('WP_CLI') && WP_CLI) || (defined('DOING_CRON') && DOING_CRON) || (defined('DOING_AJAX') && DOING_AJAX)) { return; }
        $o = get_option(self::OPT, []);
        if (!is_array($o) || empty($o['on'])) { return; }
        $path = (string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
        if (preg_match('#^/(wp-admin|wp-login\.php|wp-json|wp-cron\.php|xmlrpc\.php|wa-center|v/|pay/|wp-content/|wp-includes/)#', $path)) { return; }
        if (!empty($_GET['rest_route'])) { return; }
        if (preg_match('#^/app/(sw\.js|.*\.(png|svg|webmanifest|js|css))$#', $path)) { return; }
        $key = (string) ($o['key'] ?? '');
        if ($key !== '' && isset($_GET['mq_open']) && hash_equals($key, (string) $_GET['mq_open'])) {
            setcookie('mq_open', hash('sha256', $key), time() + 12 * 3600, '/', '', is_ssl(), true);
            return;
        }
        if ($key !== '' && isset($_COOKIE['mq_open']) && hash_equals(hash('sha256', $key), (string) $_COOKIE['mq_open'])) { return; }
        if (function_exists('is_user_logged_in') && is_user_logged_in() && current_user_can('manage_options')) { return; }
        self::page((string) ($o['title'] ?? ''), (string) ($o['msg'] ?? ''));
    }

    private static function page($title, $msg) {
        $title = esc_html($title !== '' ? $title : 'الموقع في صيانة سريعة');
        $msg = nl2br(esc_html($msg !== '' ? $msg : 'راجعين خلال وقت قصير.'));
        $name = class_exists('Oxygen_Network') && method_exists('Oxygen_Network', 'brand_config') ? esc_html((string) (Oxygen_Network::brand_config()['full'] ?? get_bloginfo('name'))) : esc_html(get_bloginfo('name'));
        if (!headers_sent()) {
            status_header(503); header('Retry-After: 1800'); header('Content-Type: text/html; charset=utf-8');
            nocache_headers(); header('X-LiteSpeed-Cache-Control: no-cache');
        }
        echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . $title . '</title><style>
:root{--bg:#f1f5f9;--fg:#0f172a;--mut:#475569;--card:#fff;--acc:#0e7490}
@media(prefers-color-scheme:dark){:root{--bg:#0b1220;--fg:#e2e8f0;--mut:#94a3b8;--card:#111a2e;--acc:#22d3ee}}
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--fg);font-family:Tahoma,"Segoe UI",sans-serif;padding:16px}
.c{max-width:440px;width:100%;background:var(--card);border-radius:24px;padding:28px 22px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.08)}
h1{font-size:24px;margin:14px 0 8px}p{color:var(--mut);line-height:1.9;margin:0}.n{margin-top:18px;font-size:12px;color:var(--mut)}
svg{width:100%;max-width:300px;height:auto}
.l{animation:pull 3s ease-in-out infinite}.r{animation:push 3s ease-in-out infinite}.z{animation:spark 3s infinite;opacity:0}
@keyframes pull{0%,100%{transform:translateX(0)}45%,70%{transform:translateX(-26px)}}
@keyframes push{0%,100%{transform:translateX(0)}45%,70%{transform:translateX(26px)}}
@keyframes spark{0%,40%{opacity:0}45%,55%{opacity:1}60%,100%{opacity:0}}
@media(prefers-reduced-motion:reduce){.l,.r,.z{animation:none}}
</style></head><body><div class="c">
<svg viewBox="0 0 320 120" aria-hidden="true">
<path d="M0 60 H96" stroke="var(--mut)" stroke-width="6" fill="none" stroke-linecap="round"/>
<g class="l"><rect x="92" y="38" width="46" height="44" rx="10" fill="var(--acc)"/><rect x="138" y="48" width="18" height="6" rx="3" fill="var(--mut)"/><rect x="138" y="66" width="18" height="6" rx="3" fill="var(--mut)"/></g>
<g class="z"><path d="M160 34 l-6 14 h10 l-6 14" stroke="#f59e0b" stroke-width="4" fill="none" stroke-linecap="round" stroke-linejoin="round"/></g>
<g class="r"><rect x="180" y="38" width="46" height="44" rx="10" fill="var(--fg)" opacity=".85"/><rect x="186" y="50" width="8" height="5" rx="2" fill="var(--card)"/><rect x="186" y="66" width="8" height="5" rx="2" fill="var(--card)"/></g>
<path d="M226 60 H320" stroke="var(--mut)" stroke-width="6" fill="none" stroke-linecap="round"/>
</svg>
<h1>' . $title . '</h1><p>' . $msg . '</p><div class="n">' . $name . '</div></div></body></html>';
        exit;
    }
}
