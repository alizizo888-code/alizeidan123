<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Two private, password-less desks for the owner (secret-link system):
 *   /oxy-desk/o/<secret>  → orders inbox   (then /oxy-desk/o)
 *   /oxy-desk/c/<secret>  → site control   (settings, social, articles/pages editor)
 * - Not WordPress posts/pages, not in sitemaps, no links anywhere, X-Robots-Tag noindex, no cache, 404 without the secret/cookie.
 * - Only the SHA-256 of each secret lives in code/options; the secrets themselves are never stored. Rotate them from WP admin → «المكتب السري».
 * - Everything reads/writes the same WordPress options, posts and Mutqan orders store, so changes show up in WordPress immediately.
 */
class Oxygen_Desk {
    const HASH_O = '';
    const HASH_C = '';
    const COOKIE = 'oxd_';

    public static function boot() {
        // The old /oxy-desk/<secret> pages are retired: every secret link is cancelled. The desk apps use ONE numeric PIN (set below).
        add_action('admin_menu', function () { add_submenu_page('oxygen-seo', 'المكتب السري', 'المكتب السري', 'manage_options', 'oxygen-desk', [__CLASS__, 'admin_page']); }, 23);
        add_action('admin_post_oxygen_desk_pin', [__CLASS__, 'save_pin']);
        add_action('admin_post_oxygen_desk_pin_test', [__CLASS__, 'test_pin']);
        add_action('admin_post_oxygen_desk_sso', [__CLASS__, 'save_sso']);
        // Direct, persistent entry to WordPress admin from the desk apps (one-time link → long-lived login) + a «back to the app» button inside wp-admin.
        add_action('init', [__CLASS__, 'sso_consume'], 1);
        add_action('admin_bar_menu', [__CLASS__, 'admin_bar'], 1);
        add_action('admin_head', [__CLASS__, 'back_css']);
        add_action('wp_head', [__CLASS__, 'back_css']);
    }

    /* ---------------- what the devices report (no PIN, no token — only what happened) ---------------- */
    const LOG = 'oxygen_desk_log';
    /** Ring buffer of the last 80 events (PIN accepted / refused / locked, device diagnostics, WordPress entries). Read it in wp-admin → المكتب السري. */
    public static function log($kind, $info = []) {
        try {
            $rows = get_option(self::LOG, []);
            if (!is_array($rows)) { $rows = []; }
            $row = ['t' => time(), 'k' => (string) $kind, 'ip' => substr(md5((string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 6)];
            foreach (['app', 'build', 'step', 'info', 'ua'] as $f) { if (isset($info[$f]) && $info[$f] !== '') { $row[$f] = mb_substr(preg_replace('/[\x00-\x1F]/u', ' ', (string) $info[$f]), 0, $f === 'ua' ? 120 : 160); } }
            $last = $rows ? end($rows) : null;
            if ($last && ($last['k'] ?? '') === $row['k'] && ($last['app'] ?? '') === ($row['app'] ?? '') && ($last['info'] ?? '') === ($row['info'] ?? '') && ($last['step'] ?? '') === ($row['step'] ?? '') && ($last['ip'] ?? '') === $row['ip'] && $row['t'] - (int) ($last['t'] ?? 0) < 900) {
                $rows[count($rows) - 1]['t'] = $row['t']; $rows[count($rows) - 1]['n'] = (int) ($last['n'] ?? 1) + 1;   // same thing again within 15 min → one line with a counter
            } else {
                $rows[] = $row;
            }
            if (count($rows) > 80) { $rows = array_slice($rows, -80); }
            update_option(self::LOG, $rows, false);
        } catch (Throwable $e) { /* logging must never break a login */ }
    }

    /** The desk apps' single PIN (stored only as a salted hash in this site's database, never in code). Every old secret link is cancelled. */
    public static function pin_is_set() { return (string) get_option('oxygen_desk_pin', '') !== ''; }
    /** The PIN as the server compares it: digits of any script folded to 0-9, no spaces or invisible direction marks (an Arabic keyboard types ٧٨٩ where a Western one types 789). */
    public static function norm_pin($s) {
        $s = strtr((string) $s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        $r = preg_replace('/[\s\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}\x{00A0}]+/u', '', $s);
        return is_string($r) ? $r : '';
    }
    /** Every spelling a typed PIN may have been saved under (older hashes were made from the raw text, possibly in Arabic-Indic digits). */
    private static function pin_variants($tok) {
        $raw = trim((string) $tok); $n = self::norm_pin($raw);
        $ar = strtr($n, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
        $fa = strtr($n, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
        return array_values(array_unique(array_filter([$n, $raw, $ar, $fa], function ($v) { return $v !== ''; })));
    }
    public static function valid_secret($tok) {
        $h = (string) get_option('oxygen_desk_pin', '');
        if ($h === '' || mb_strlen((string) $tok) > 64) { return false; }
        foreach (self::pin_variants($tok) as $v) { if (password_verify($v, $h)) { return true; } }
        return false;
    }
    private static function hash_for($k) { $o = get_option('oxygen_desk_hash_' . $k, ''); return preg_match('/^[a-f0-9]{64}$/', (string) $o) ? $o : ($k === 'o' ? self::HASH_O : self::HASH_C); }
    private static function cookie_val($k) { return hash_hmac('sha256', 'desk|' . $k . '|' . self::hash_for($k), defined('AUTH_KEY') && AUTH_KEY ? AUTH_KEY : (string) get_option('siteurl')); }
    private static function authed($k) { $c = $_COOKIE[self::COOKIE . $k] ?? ''; return is_string($c) && $c !== '' && hash_equals(self::cookie_val($k), $c); }
    private static function ip() { return md5((string) ($_SERVER['REMOTE_ADDR'] ?? '')); }
    private static function shut($code = 404) { status_header($code); header('X-Robots-Tag: noindex, nofollow, noarchive'); header('Cache-Control: no-store'); echo $code === 429 ? 'Too many attempts' : 'Not found'; exit; }
    private static function headers() { if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); } do_action('litespeed_control_set_nocache', 'oxy-desk'); header('Content-Type: text/html; charset=utf-8'); header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet'); header('Cache-Control: no-store, private'); header('Referrer-Policy: no-referrer'); header('X-Content-Type-Options: nosniff'); }

    public static function route() {
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $base = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        if (!preg_match('#^' . preg_quote(rtrim($base, '/'), '#') . '/oxy-desk/(.*)$#', $path, $m)) { return; }
        $rest = trim($m[1], '/'); $p = explode('/', $rest);
        if (($p[0] ?? '') === 'api') { self::api($p[1] ?? ''); }
        if (preg_match('/^manifest-([oc])\.json$/', (string) ($p[0] ?? ''), $mm)) { self::manifest($mm[1]); }
        if (($p[0] ?? '') === 'sw.js') { header('Content-Type: application/javascript'); header('Service-Worker-Allowed: /oxy-desk/'); header('Cache-Control: no-cache'); echo "self.addEventListener('install',function(){self.skipWaiting()});self.addEventListener('activate',function(e){e.waitUntil(self.clients.claim())});self.addEventListener('fetch',function(){});"; exit; }
        $k = $p[0] ?? '';
        if (!in_array($k, ['o', 'c'], true)) { self::shut(); }
        $tok = '';
        if (isset($p[1]) && $p[1] !== '') { // secret link: validates, remembers this device, and shows the desk right here (no redirect, no cookie dependence)
            $fk = 'oxd_f_' . self::ip(); $fails = (int) get_transient($fk);
            if ($fails >= 8) { self::shut(429); }
            if (!hash_equals(self::hash_for($k), hash('sha256', (string) $p[1]))) { set_transient($fk, $fails + 1, HOUR_IN_SECONDS); self::shut(); }
            setcookie(self::COOKIE . $k, self::cookie_val($k), ['expires' => time() + 180 * DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
            $tok = (string) $p[1];
        } elseif (!self::authed($k)) {
            self::headers();
            echo self::shell($k); exit; // no secret in the request: tiny shell that re-opens with the token saved on this device (if any)
        }
        self::headers();
        echo self::page($k, $tok);
        exit;
    }

    private static function manifest($k) {
        header('Content-Type: application/manifest+json; charset=utf-8'); header('Cache-Control: no-cache'); header('X-Robots-Tag: noindex');
        $n = $k === 'o' ? 'طلبات العملاء' : 'التحكم بالموقع';
        $ic = home_url('/app/');
        echo wp_json_encode(['name' => $n, 'short_name' => $n, 'start_url' => '/oxy-desk/' . $k, 'scope' => '/oxy-desk/', 'display' => 'standalone', 'background_color' => '#0b1220', 'theme_color' => '#0b1220', 'lang' => 'ar', 'dir' => 'rtl',
            'icons' => [['src' => $ic . 'icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'], ['src' => $ic . 'icon-512.png', 'sizes' => '512x512', 'type' => 'image/png']]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    private static function shell($k) {
        return '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>مكتب سري</title></head><body style="margin:0;background:#0b1220;color:#fff;font:16px system-ui;display:flex;min-height:100vh;align-items:center;justify-content:center;text-align:center;padding:24px"><p id=m>جارِ الفتح…</p><script>try{var t=localStorage.getItem("oxd_' . $k . '");if(t){location.replace("/oxy-desk/' . $k . '/"+encodeURIComponent(t))}else{document.getElementById("m").textContent="افتح الرابط السري الخاص بك مرة واحدة على هذا الجهاز."}}catch(e){document.getElementById("m").textContent="افتح الرابط السري الخاص بك."}</script></body></html>';
    }

    /* ---------------- API ---------------- */
    private static function api($action) {
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); } do_action('litespeed_control_set_nocache', 'oxy-desk');
        header('Content-Type: application/json; charset=utf-8'); header('X-Robots-Tag: noindex'); header('Cache-Control: no-store');
        if (($_SERVER['HTTP_X_OXD'] ?? '') !== '1') { self::shut(); }
        $t = (string) ($_SERVER['HTTP_X_OXD_T'] ?? '');
        $o = self::authed('o') || ($t !== '' && hash_equals(self::hash_for('o'), hash('sha256', $t)));
        $c = self::authed('c') || ($t !== '' && hash_equals(self::hash_for('c'), hash('sha256', $t)));
        if (!$o && !$c) { self::shut(); }
        $in = json_decode((string) file_get_contents('php://input'), true); $in = is_array($in) ? $in : [];
        $need = in_array($action, ['orders', 'order_status'], true) ? $o : $c;
        if (!$need) { self::shut(); }
        try { $out = self::handle($action, $in); } catch (Throwable $e) { status_header(400); $out = ['error' => $e->getMessage()]; }
        echo wp_json_encode($out, JSON_UNESCAPED_UNICODE); exit;
    }
    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user']; }

    private static function handle($a, $in) {
        switch ($a) {
            case 'orders':
                if (!class_exists('Mutqan_Store')) { return ['orders' => [], 'note' => 'نظام الطلبات غير مفعّل']; }
                $f = []; if (!empty($in['status']) && in_array($in['status'], ['new', 'accepted', 'completed', 'cancelled'], true)) { $f['status'] = $in['status']; }
                $rows = Mutqan_Store::select('orders', $f, ['limit' => 100, 'order' => '-createdAt'], self::sys());
                $keep = ['id', 'createdAt', 'status', 'specialtyId', 'serviceName', 'clientName', 'clientPhone', 'address', 'lat', 'lng', 'description', 'agreedPrice', 'priority', 'appointmentAt', 'technicianName', 'paymentStatus', 'note'];
                return ['orders' => array_map(function ($r) use ($keep) { return array_intersect_key($r, array_flip($keep)); }, $rows)];
            case 'order_status':
                $st = (string) ($in['status'] ?? ''); if (!in_array($st, ['new', 'accepted', 'completed', 'cancelled'], true)) { throw new Exception('حالة غير صالحة'); }
                $patch = ['status' => $st];
                if (!empty($in['note'])) { $patch['note'] = mb_substr(sanitize_textarea_field((string) $in['note']), 0, 500); }
                Mutqan_Store::update('orders', (string) $in['id'], $patch, self::sys());
                return ['ok' => true];
            case 'settings_get':
                $s = Oxygen_Business_Control::settings(); $soc = class_exists('Oxygen_Social') ? Oxygen_Social::settings() : [];
                return ['biz' => array_intersect_key($s, array_flip(self::biz_keys())), 'social' => $soc];
            case 'settings_save':
                $s = Oxygen_Business_Control::settings();
                foreach (self::biz_keys() as $k) {
                    if (!array_key_exists($k, (array) ($in['biz'] ?? []))) { continue; }
                    $v = (string) $in['biz'][$k];
                    $s[$k] = in_array($k, ['banner_image', 'banner_video'], true) ? esc_url_raw($v) : (in_array($k, ['services', 'price_rules', 'offers', 'banner_text', 'gallery_urls'], true) ? sanitize_textarea_field($v) : sanitize_text_field($v));
                }
                $s['last_saved'] = current_time('mysql');
                update_option(Oxygen_Business_Control::OPT, $s, false);
                delete_transient('oxygen_business_public_config'); wp_cache_delete('oxygen_business_public_config', 'oxygen');
                if (isset($in['social']) && is_array($in['social']) && class_exists('Oxygen_Social')) {
                    $n = [];
                    foreach (['tiktok', 'facebook'] as $k) { $n[$k] = esc_url_raw(trim((string) ($in['social'][$k] ?? ''))); }
                    foreach (['tiktok_videos', 'facebook_videos'] as $k) { $n[$k] = implode("\n", array_filter(array_map('esc_url_raw', array_map('trim', preg_split('/\R+/', (string) ($in['social'][$k] ?? '')))))); }
                    update_option(Oxygen_Social::OPT, $n, false);
                }
                return ['ok' => true];
            case 'posts':
                $type = ($in['type'] ?? 'post') === 'page' ? 'page' : 'post';
                $q = get_posts(['post_type' => $type, 'post_status' => ['publish', 'draft', 'pending', 'private'], 'numberposts' => 40, 's' => sanitize_text_field((string) ($in['q'] ?? '')), 'orderby' => 'modified', 'order' => 'DESC']);
                return ['posts' => array_map(function ($p) { return ['id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status, 'link' => get_permalink($p), 'modified' => $p->post_modified]; }, $q)];
            case 'post_get':
                $p = get_post((int) ($in['id'] ?? 0)); if (!$p || !in_array($p->post_type, ['post', 'page'], true)) { throw new Exception('غير موجود'); }
                return ['id' => $p->ID, 'title' => $p->post_title, 'excerpt' => $p->post_excerpt, 'content' => $p->post_content, 'status' => $p->post_status, 'link' => get_permalink($p)];
            case 'post_save':
                $p = get_post((int) ($in['id'] ?? 0)); if (!$p || !in_array($p->post_type, ['post', 'page'], true)) { throw new Exception('غير موجود'); }
                $st = in_array($in['status'] ?? '', ['publish', 'draft', 'private'], true) ? $in['status'] : $p->post_status;
                $r = wp_update_post(['ID' => $p->ID, 'post_title' => sanitize_text_field((string) ($in['title'] ?? $p->post_title)), 'post_excerpt' => sanitize_textarea_field((string) ($in['excerpt'] ?? '')), 'post_content' => wp_kses_post((string) ($in['content'] ?? $p->post_content)), 'post_status' => $st], true);
                if (is_wp_error($r)) { throw new Exception($r->get_error_message()); }
                return ['ok' => true, 'link' => get_permalink($p->ID)];
        }
        throw new Exception('إجراء غير معروف');
    }
    private static function biz_keys() { return ['company_name', 'phone', 'whatsapp', 'email', 'city', 'banner_text', 'banner_image', 'banner_video', 'offers', 'services', 'price_rules', 'gallery_urls']; }

    /* ---------------- pages ---------------- */
    private static function page($k, $tok = '') {
        $title = $k === 'o' ? 'طلبات العملاء' : 'التحكم بالموقع';
        $css = ':root{--bg:#f4f7fb;--card:#fff;--tx:#0f172a;--mu:#64748b;--ac:#0369a1;--bd:#dbe4ef}@media(prefers-color-scheme:dark){:root{--bg:#0b1220;--card:#111a2e;--tx:#e8eefb;--mu:#93a4c0;--ac:#38bdf8;--bd:#1f2c47}}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--tx);font:15px/1.8 system-ui,Tahoma,sans-serif}header{position:sticky;top:0;background:var(--card);border-bottom:1px solid var(--bd);padding:12px 16px;font-weight:800;z-index:5;display:flex;gap:10px;align-items:center}main{max-width:820px;margin:0 auto;padding:14px 16px 60px}.c{background:var(--card);border:1px solid var(--bd);border-radius:16px;padding:14px;margin:0 0 12px}.m{color:var(--mu);font-size:13px}button,select,input,textarea{font:inherit;color:var(--tx)}button{border:0;border-radius:12px;padding:9px 14px;font-weight:700;background:var(--ac);color:#fff;cursor:pointer}button.g{background:transparent;color:var(--ac);border:1px solid var(--bd)}input,textarea,select{width:100%;background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:9px 11px;margin:3px 0 10px}textarea{min-height:90px}label{font-size:13px;color:var(--mu);font-weight:700}.r{display:flex;gap:8px;flex-wrap:wrap}.t{display:inline-block;border-radius:999px;padding:1px 10px;font-size:12px;font-weight:800;background:var(--bd)}a{color:var(--ac)}.ok{color:#059669;font-weight:800}';
        $js = $k === 'o' ? self::js_orders() : self::js_control();
        return '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><meta name="theme-color" content="#0b1220"><link rel="manifest" href="/oxy-desk/manifest-' . $k . '.json"><link rel="apple-touch-icon" href="' . esc_url(home_url('/app/apple-touch-icon.png')) . '"><meta name="apple-mobile-web-app-capable" content="yes"><title>' . $title . '</title><style>' . $css . '</style></head><body><header>🔒 ' . $title . '<button id=ins class=g style="margin-right:auto;display:none">تثبيت التطبيق</button></header><main id="app">جارِ التحميل…</main><script>' . ($tok !== '' ? 'try{localStorage.setItem("oxd_' . $k . '",' . wp_json_encode($tok) . ')}catch(e){}' : '') . self::js_common($k) . $js . '</script></body></html>';
    }
    private static function js_common($k = 'o') {
        return 'var $=function(s,r){return(r||document).querySelector(s)};function esc(s){return String(s==null?"":s).replace(/[&<>"]/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;"}[c]})}function api(a,d){return fetch("/oxy-desk/api/"+a,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","X-Oxd":"1","X-Oxd-T":(function(){try{return localStorage.getItem("oxd_' . $k . '")||""}catch(e){return""}})()},body:JSON.stringify(d||{})}).then(function(r){return r.json().then(function(j){if(!r.ok)throw new Error(j.error||"فشل");return j})})}function toast(m){var t=document.createElement("div");t.textContent=m;t.style.cssText="position:fixed;bottom:18px;left:50%;transform:translateX(-50%);background:#0f172a;color:#fff;padding:10px 18px;border-radius:999px;z-index:9;font-weight:700";document.body.appendChild(t);setTimeout(function(){t.remove()},2200)};var dp=null,ib=$("#ins");try{if("serviceWorker"in navigator)navigator.serviceWorker.register("/oxy-desk/sw.js",{scope:"/oxy-desk/"}).catch(function(){})}catch(e){}window.addEventListener("beforeinstallprompt",function(e){e.preventDefault();dp=e;ib.style.display=""});if(/iphone|ipad|ipod/i.test(navigator.userAgent)&&!window.navigator.standalone){ib.style.display="";ib.onclick=function(){toast("اضغط زر المشاركة ثم «إضافة إلى الشاشة الرئيسية»")}}ib.onclick=ib.onclick||function(){if(dp){dp.prompt();dp=null;ib.style.display="none"}};window.addEventListener("appinstalled",function(){ib.style.display="none"});';
    }
    private static function js_orders() {
        return 'var ST={new:"جديد",accepted:"مقبول",completed:"مكتمل",cancelled:"ملغي"},cur="";function load(){api("orders",{status:cur}).then(function(j){var h="<div class=r style=margin-bottom:10px><select id=f onchange=\"cur=this.value;load()\"><option value=\"\">كل الحالات</option>"+Object.keys(ST).map(function(k){return"<option value="+k+(cur===k?" selected":"")+">"+ST[k]+"</option>"}).join("")+"</select><button class=g onclick=load()>تحديث</button></div>";if(!j.orders.length)h+="<p class=m>لا توجد طلبات.</p>";j.orders.forEach(function(o){var ph=(o.clientPhone||"").replace(/\D/g,"");h+="<div class=c><div class=r><b>#"+esc(String(o.id).slice(-6))+"</b><span class=t>"+esc(ST[o.status]||o.status)+"</span><span class=m>"+esc((o.createdAt||"").replace("T"," ").slice(0,16))+"</span></div><div>"+esc(o.serviceName||o.specialtyId||"")+(o.agreedPrice?" — "+esc(o.agreedPrice):"")+"</div>"+(o.clientName?"<div>"+esc(o.clientName)+"</div>":"")+(o.description?"<div class=m>"+esc(o.description)+"</div>":"")+(o.address?"<div class=m>📍 "+esc(o.address)+"</div>":"")+(o.appointmentAt?"<div class=m>🕒 "+esc(o.appointmentAt)+"</div>":"")+"<div class=r style=margin-top:8px>"+(ph?"<a href=\"tel:"+ph+"\"><button class=g>اتصال</button></a><a href=\"https://wa.me/"+ph+"\" target=_blank rel=noopener><button class=g>واتساب</button></a>":"")+(o.lat&&o.lng?"<a href=\"https://maps.google.com/?q="+o.lat+","+o.lng+"\" target=_blank rel=noopener><button class=g>الموقع</button></a>":"")+Object.keys(ST).filter(function(k){return k!==o.status}).map(function(k){return"<button onclick=\"setS(\x27"+o.id+"\x27,\x27"+k+"\x27)\">"+ST[k]+"</button>"}).join("")+"</div></div>"});$("#app").innerHTML=h}).catch(function(e){$("#app").innerHTML="<p>"+esc(e.message)+"</p>"})}function setS(id,s){api("order_status",{id:id,status:s}).then(function(){toast("تم التحديث");load()}).catch(function(e){toast(e.message)})}load();setInterval(function(){if(!document.hidden)load()},60000);';
    }
    private static function js_control() {
        return 'var B=[["company_name","اسم الشركة","i"],["phone","الجوال","i"],["whatsapp","واتساب (أرقام فقط)","i"],["email","البريد","i"],["city","المدينة","i"],["banner_text","نص البانر","t"],["banner_image","رابط صورة البانر","i"],["offers","العروض (سطر لكل عرض)","t"],["services","الخدمات (1|اسم)","t"],["price_rules","قواعد الأسعار","t"],["gallery_urls","روابط صور المعرض","t"]],S=[["tiktok","رابط تيك توك","i"],["facebook","رابط فيسبوك","i"],["tiktok_videos","فيديوهات تيك توك للمعرض","t"],["facebook_videos","فيديوهات فيسبوك للمعرض","t"]];function fld(p,a,v){return"<label>"+a[1]+"</label>"+(a[2]==="t"?"<textarea data-p="+p+" data-k="+a[0]+">"+esc(v||"")+"</textarea>":"<input data-p="+p+" data-k="+a[0]+" value=\""+esc(v||"")+"\">")}function tab(t){$("#app").innerHTML="<div class=r style=margin-bottom:12px><button class="+(t==="s"?"":"g")+" onclick=tab(\x27s\x27)>الإعدادات</button><button class="+(t==="p"?"":"g")+" onclick=tab(\x27p\x27)>المقالات</button><button class="+(t==="g"?"":"g")+" onclick=tab(\x27g\x27)>الصفحات</button></div><div id=v>…</div>";if(t==="s")sets();else list(t==="g"?"page":"post","")}function sets(){api("settings_get").then(function(j){var h="<div class=c><b>بيانات الموقع والعروض</b><br>"+B.map(function(a){return fld("biz",a,j.biz[a[0]])}).join("")+"</div><div class=c><b>تيك توك وفيسبوك</b><br>"+S.map(function(a){return fld("social",a,j.social[a[0]])}).join("")+"</div><button onclick=saveS()>حفظ (يظهر في ووردبريس فورًا)</button>";$("#v").innerHTML=h})}function saveS(){var d={biz:{},social:{}};document.querySelectorAll("[data-p]").forEach(function(e){d[e.dataset.p][e.dataset.k]=e.value});api("settings_save",d).then(function(){toast("تم الحفظ")}).catch(function(e){toast(e.message)})}function list(type,q){api("posts",{type:type,q:q}).then(function(j){var h="<input id=q placeholder=\"بحث…\" value=\""+esc(q)+"\" onchange=\"list(\x27"+type+"\x27,this.value)\">";j.posts.forEach(function(p){h+="<div class=c><b>"+esc(p.title||"(بدون عنوان)")+"</b> <span class=t>"+esc(p.status)+"</span><div class=r style=margin-top:6px><button onclick=\"edit("+p.id+")\">تعديل</button><a href=\""+esc(p.link)+"\" target=_blank rel=noopener><button class=g>عرض</button></a></div></div>"});$("#v").innerHTML=h})}function edit(id){api("post_get",{id:id}).then(function(p){$("#app").innerHTML="<button class=g onclick=tab(\x27p\x27)>← رجوع</button><div class=c><label>العنوان</label><input id=t value=\""+esc(p.title)+"\"><label>الوصف المختصر</label><textarea id=x>"+esc(p.excerpt)+"</textarea><label>المحتوى (HTML)</label><textarea id=b style=min-height:260px;direction:ltr>"+esc(p.content)+"</textarea><label>الحالة</label><select id=s><option value=publish"+(p.status==="publish"?" selected":"")+">منشور</option><option value=draft"+(p.status==="draft"?" selected":"")+">مسودة</option><option value=private"+(p.status==="private"?" selected":"")+">خاص</option></select><button onclick=\"savePost("+p.id+")\">حفظ</button></div>"})}function savePost(id){api("post_save",{id:id,title:$("#t").value,excerpt:$("#x").value,content:$("#b").value,status:$("#s").value}).then(function(){toast("تم الحفظ")}).catch(function(e){toast(e.message)})}tab("s");';
    }

    /* ---------------- WordPress admin from inside the app ---------------- */
    const SSO_TTL = 90;
    public static function sso_enabled() { return get_option('oxygen_desk_sso', '1') !== '0'; }
    /** The WordPress administrator the app logs in as: the one who last saved the PIN here, else the first administrator. */
    public static function sso_user_id() {
        $id = (int) get_option('oxygen_desk_wp_user', 0);
        if ($id && ($u = get_user_by('id', $id)) && user_can($u, 'manage_options')) { return $id; }
        $a = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID']);
        return $a ? (int) $a[0] : 0;
    }
    /** Only a plain wp-admin screen: `edit.php?post_type=page`, `admin.php?page=oxygen-desk`… never another host, never a path outside wp-admin. */
    public static function safe_admin_path($to) {
        $to = ltrim(trim((string) $to), '/');
        $to = preg_replace('#^wp-admin/#', '', $to);
        if ($to === '') { $to = 'index.php'; }
        if (strlen($to) > 200 || strpos($to, '..') !== false || !preg_match('#^[A-Za-z0-9_\-/]+\.php(\?[A-Za-z0-9_\-=&%.,:+\[\]]*)?$#', $to)) { throw new Mutqan_Error('bad_request', 'مسار غير مسموح.', 400); }
        return $to;
    }
    private static function safe_back($u) { $u = (string) $u; return preg_match('#^https://desk\.oxygen11\.com/[A-Za-z0-9_./\-]*(\?[A-Za-z0-9_=&.\-]*)?$#', $u) ? $u : ''; }
    /** One-time address (valid 90 s) that logs the site's administrator in and lands on `$to`. Called by the REST op `wp_sso`, owner session only. */
    public static function make_sso_url($to, $back = '') {
        if (!self::sso_enabled()) { throw new Mutqan_Error('forbidden', 'الدخول المباشر إلى ووردبريس متوقف من ووردبريس ← المكتب السري.', 403); }
        $to = self::safe_admin_path($to);
        $uid = self::sso_user_id();
        if (!$uid) { throw new Mutqan_Error('server_error', 'لا يوجد مدير ووردبريس لإتمام الدخول.', 500); }
        $tok = bin2hex(random_bytes(24));
        set_transient('oxsso_' . hash('sha256', $tok), ['uid' => $uid, 'to' => $to, 'back' => self::safe_back($back)], self::SSO_TTL);
        self::log('wp_sso', ['info' => $to]);
        return add_query_arg('oxygen_sso', $tok, home_url('/'));
    }
    /** Redeems a one-time token (single use, 90 s). Returns ['user' => WP_User, 'to' => path, 'back' => url] or null. */
    public static function sso_redeem($tok) {
        if (!is_string($tok) || !preg_match('/^[a-f0-9]{48}$/', $tok)) { return null; }
        $k = 'oxsso_' . hash('sha256', $tok);
        $d = get_transient($k); delete_transient($k);   // single use, even when it turns out to be unusable
        if (!is_array($d) || !self::sso_enabled()) { return null; }
        $u = get_user_by('id', (int) ($d['uid'] ?? 0));
        if (!$u || !user_can($u, 'manage_options')) { return null; }
        return ['user' => $u, 'to' => (string) ($d['to'] ?? 'index.php'), 'back' => (string) ($d['back'] ?? '')];
    }
    public static function sso_consume() {
        if (empty($_GET['oxygen_sso']) || !is_string($_GET['oxygen_sso'])) { return; }
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        do_action('litespeed_control_set_nocache', 'oxygen-sso');
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow'); header('Referrer-Policy: no-referrer');
        $r = self::sso_redeem(wp_unslash($_GET['oxygen_sso']));
        if (!$r) { wp_die('انتهت صلاحية رابط الدخول — افتح الصفحة من التطبيق من جديد.', 'رابط منتهٍ', ['response' => 403]); }
        $u = $r['user'];
        add_filter('auth_cookie_expiration', function () { return YEAR_IN_SECONDS; }, 99);   // «persistent login»: a year, renewed every time the app opens WordPress
        wp_set_current_user($u->ID);
        wp_set_auth_cookie($u->ID, true);
        if ($r['back'] !== '') { setcookie('oxygen_desk_back', $r['back'], ['expires' => time() + YEAR_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']); }
        wp_safe_redirect(admin_url($r['to'])); exit;
    }
    private static function back_url() { return isset($_COOKIE['oxygen_desk_back']) && is_user_logged_in() ? self::safe_back(wp_unslash($_COOKIE['oxygen_desk_back'])) : ''; }
    public static function admin_bar($bar) {
        $b = self::back_url(); if ($b === '') { return; }
        $bar->add_node(['id' => 'oxygen-desk-back', 'title' => '◀ العودة للتطبيق', 'href' => $b, 'meta' => ['class' => 'oxygen-desk-back']]);
    }
    public static function back_css() {
        if (self::back_url() === '') { return; }
        echo '<style>#wpadminbar #wp-admin-bar-oxygen-desk-back{display:block!important}#wpadminbar #wp-admin-bar-oxygen-desk-back>a{background:#0ea5e9!important;color:#04121f!important;font-weight:700!important}</style>';
    }
    /** Numbers shown on the app's WordPress cards (what is inside each area). */
    public static function overview() {
        $n = function ($o, $k) { return is_object($o) && isset($o->$k) ? (int) $o->$k : 0; };
        $pc = function_exists('wp_count_posts') ? wp_count_posts('page') : null; $po = function_exists('wp_count_posts') ? wp_count_posts('post') : null;
        $cm = function_exists('wp_count_comments') ? wp_count_comments() : null;
        $att = function_exists('wp_count_attachments') ? array_sum(array_map('intval', (array) wp_count_attachments())) : 0;
        $us = function_exists('count_users') ? count_users() : ['total_users' => 0];
        return [
            'site' => ['name' => get_bloginfo('name'), 'url' => home_url('/'), 'wp' => get_bloginfo('version')],
            'pages' => ['publish' => $n($pc, 'publish'), 'draft' => $n($pc, 'draft')],
            'posts' => ['publish' => $n($po, 'publish'), 'draft' => $n($po, 'draft'), 'future' => $n($po, 'future')],
            'comments' => ['pending' => $n($cm, 'moderated'), 'total' => $n($cm, 'total_comments')],
            'media' => (int) $att,
            'users' => (int) ($us['total_users'] ?? 0),
            'plugins' => count((array) get_option('active_plugins', [])),
            'sso' => self::sso_enabled(),
        ];
    }
    /** Pages or posts of THIS site with the wp-admin address that edits each: the app's «الصفحات / المقالات» lists. */
    public static function pages($type = 'page', $q = '', $limit = 60) {
        $type = $type === 'post' ? 'post' : 'page';
        $args = ['post_type' => $type, 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'posts_per_page' => max(1, min(100, (int) $limit)), 'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => true, 'suppress_filters' => true];
        $q = trim((string) $q); if ($q !== '') { $args['s'] = mb_substr($q, 0, 80); }
        $out = [];
        foreach (get_posts($args) as $p) {
            $out[] = ['id' => (int) $p->ID, 'title' => $p->post_title !== '' ? mb_substr($p->post_title, 0, 120) : '(بدون عنوان)', 'status' => $p->post_status, 'modified' => mysql2date('c', $p->post_modified_gmt ?: $p->post_modified, false), 'view' => get_permalink($p), 'edit' => 'post.php?post=' . (int) $p->ID . '&action=edit'];
        }
        return $out;
    }
    public static function save_sso() {
        if (!current_user_can('manage_options')) { wp_die('غير مصرح.'); }
        check_admin_referer('oxd_sso');
        update_option('oxygen_desk_sso', !empty($_POST['sso']) ? '1' : '0', false);
        wp_safe_redirect(admin_url('admin.php?page=oxygen-desk&sso=1')); exit;
    }

    /* ---------------- WP admin: the desk PIN ---------------- */
    public static function admin_page() {
        if (!current_user_can('manage_options')) { return; }
        $host = (string) parse_url(home_url(), PHP_URL_HOST);
        $at = (int) get_option('oxygen_desk_pin_at', 0);
        echo '<div class="wrap" dir="rtl"><h1>المكتب السري — رمز الدخول</h1><p>تطبيقا المكتب (غرفة العمليات / الإعدادات) يدخلان برمز سري واحد فقط، بلا روابط ولا كلمات مرور. الرمز يُحفظ مشفّرًا في قاعدة بيانات هذا الموقع فقط. الروابط السرية القديمة أُلغيت كلها.</p>';
        echo '<p><b>هذا الموقع:</b> <code>' . esc_html($host) . '</code> — <b>اضبط نفس الرمز على موقع أكسجين وموقع متقن كلٍّ على حدة.</b></p>';
        if (isset($_GET['saved'])) { echo '<div class="notice notice-success"><p>تم حفظ الرمز على هذا الموقع. الأجهزة تدخل بهذا الرمز (الأرقام العربية والإنجليزية سواء).</p></div>'; }
        if (isset($_GET['test'])) { echo $_GET['test'] === 'ok' ? '<div class="notice notice-success"><p>✅ الرمز الذي كتبته <b>صحيح</b> على هذا الموقع (' . esc_html($host) . ').</p></div>' : '<div class="notice notice-error"><p>❌ الرمز الذي كتبته <b>لا يطابق</b> المحفوظ على هذا الموقع (' . esc_html($host) . ') — اضبطه من جديد بالنموذج الأول.</p></div>'; }
        echo '<p><b>الحالة:</b> ' . (self::pin_is_set() ? 'الرمز مضبوط' . ($at ? ' — آخر ضبط: ' . esc_html(date_i18n('Y-m-d H:i', $at)) : '') : '<span style="color:#b91c1c">الرمز غير مضبوط — لا يمكن لأحد الدخول الآن</span>') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="oxygen_desk_pin">' . wp_nonce_field('oxd_pin', '_wpnonce', true, false) . '<p><label>الرمز الجديد (٦ إلى ٣٢ حرفًا أو رقمًا):<br><input type="password" name="pin" autocomplete="new-password" inputmode="numeric" style="min-width:260px" required></label></p><button class="button button-primary">حفظ الرمز</button></form>';
        echo '<hr><h2>الدخول المباشر إلى ووردبريس من التطبيق</h2><p>زر «روابط ووردبريس» في التطبيق يفتح لوحة ووردبريس لهذا الموقع مباشرة وبدخول ثابت (سنة، يتجدد كل مرة) دون كتابة كلمة مرور، وفي لوحة ووردبريس يظهر زر «العودة للتطبيق». الدخول يتم بحساب المدير الذي حفظ الرمز آخر مرة.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="oxygen_desk_sso">' . wp_nonce_field('oxd_sso', '_wpnonce', true, false) . '<label><input type="checkbox" name="sso" value="1"' . (self::sso_enabled() ? ' checked' : '') . '> السماح للتطبيق بفتح ووردبريس مباشرة</label> <button class="button">حفظ</button></form>';
        if (isset($_GET['sso'])) { echo '<div class="notice notice-success"><p>تم الحفظ.</p></div>'; }
        $rows = array_reverse((array) get_option(self::LOG, []));
        echo '<hr><h2>ماذا تقول الأجهزة (آخر الأحداث)</h2><p>هنا يظهر كل دخول وفشل وحالة تقرير من التطبيقين — دون أي رمز أو كلمة مرور — لنعرف سبب أي مشكلة على جهازك.</p>';
        if (!$rows) { echo '<p>لا أحداث بعد.</p>'; }
        else {
            $names = ['desk_ok' => '✅ دخول', 'desk_bad_pin' => '❌ رمز خاطئ', 'desk_locked' => '⛔ قفل مؤقت', 'desk_unset' => '⚠️ الرمز غير مضبوط', 'wp_sso' => '🔗 فتح ووردبريس', 'diag' => '🩺 تقرير جهاز'];
            echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>الوقت</th><th>الحدث</th><th>التطبيق</th><th>الإصدار</th><th>التفاصيل</th><th>×</th></tr></thead><tbody>';
            foreach (array_slice($rows, 0, 60) as $r) {
                echo '<tr><td>' . esc_html(date_i18n('m-d H:i:s', (int) ($r['t'] ?? 0))) . '</td><td>' . esc_html($names[$r['k'] ?? ''] ?? ($r['k'] ?? '')) . '</td><td>' . esc_html($r['app'] ?? '') . '</td><td>' . esc_html($r['build'] ?? '') . '</td><td>' . esc_html(trim(($r['step'] ?? '') . ' ' . ($r['info'] ?? ''))) . '</td><td>' . (int) ($r['n'] ?? 1) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<hr><h2>اختبار الرمز على هذا الموقع</h2><p>اكتب الرمز لتعرف هل هو مطابق للمحفوظ هنا (لا يغيّر شيئًا).</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="oxygen_desk_pin_test">' . wp_nonce_field('oxd_pin_test', '_wpnonce', true, false) . '<p><input type="password" name="pin" autocomplete="off" inputmode="numeric" style="min-width:260px" required></p><button class="button">اختبار</button></form></div>';
    }
    public static function save_pin() {
        if (!current_user_can('manage_options')) { wp_die('غير مصرح.'); }
        check_admin_referer('oxd_pin');
        $pin = self::norm_pin(wp_unslash($_POST['pin'] ?? ''));
        if (strlen($pin) < 6 || strlen($pin) > 32) { wp_die('الرمز من ٦ إلى ٣٢ خانة.'); }
        update_option('oxygen_desk_pin', password_hash($pin, PASSWORD_DEFAULT), false);
        update_option('oxygen_desk_pin_at', time(), false);
        update_option('oxygen_desk_wp_user', get_current_user_id(), false);
        wp_safe_redirect(admin_url('admin.php?page=oxygen-desk&saved=1')); exit;
    }
    public static function test_pin() {
        if (!current_user_can('manage_options')) { wp_die('غير مصرح.'); }
        check_admin_referer('oxd_pin_test');
        $ok = self::valid_secret(wp_unslash($_POST['pin'] ?? ''));
        wp_safe_redirect(admin_url('admin.php?page=oxygen-desk&test=' . ($ok ? 'ok' : 'bad'))); exit;
    }
}
