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
    const HASH_O = 'a5c96105189e909cb49f7e07d291a683fa4ca03501e152d7b6268aa6b972a8ff';
    const HASH_C = 'fe6efae08e6093bb79c65b92a38d5f56243b10faaf4efde6852467ddba150301';
    const COOKIE = 'oxd_';

    public static function boot() {
        add_action('init', [__CLASS__, 'route'], 1);
        add_action('admin_menu', function () { add_submenu_page('oxygen-seo', 'المكتب السري', 'المكتب السري', 'manage_options', 'oxygen-desk', [__CLASS__, 'admin_page']); }, 23);
        add_action('admin_post_oxygen_desk_rotate', [__CLASS__, 'rotate']);
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

    /* ---------------- WP admin: rotate secrets ---------------- */
    public static function admin_page() {
        if (!current_user_can('manage_options')) { return; }
        echo '<div class="wrap" dir="rtl"><h1>المكتب السري</h1><p>واجهتان خاصتان بك بلا كلمات مرور: <b>طلبات العملاء</b> و<b>التحكم بالموقع</b>. تُفتحان برابط سري فقط، ولا تظهران في الموقع ولا محركات البحث ولا خريطة الموقع.</p>';
        foreach (['o' => 'طلبات العملاء', 'c' => 'التحكم بالموقع'] as $k => $n) {
            $new = get_transient('oxd_new_' . $k);
            echo '<div class="card" style="padding:12px;max-width:760px;margin:10px 0"><h2>' . esc_html($n) . '</h2>';
            if ($new) { echo '<p>الرابط الجديد (يظهر مرة واحدة، احفظه): <br><code dir="ltr" style="user-select:all">' . esc_html(home_url('/oxy-desk/' . $k . '/' . $new)) . '</code></p>'; delete_transient('oxd_new_' . $k); }
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="oxygen_desk_rotate"><input type="hidden" name="k" value="' . $k . '">' . wp_nonce_field('oxd_rot', '_wpnonce', true, false) . '<button class="button">إنشاء رابط جديد (يُبطل القديم وكل الأجهزة المفتوحة)</button></form></div>';
        }
        echo '</div>';
    }
    public static function rotate() {
        if (!current_user_can('manage_options')) { wp_die('غير مصرح.'); }
        check_admin_referer('oxd_rot');
        $k = ($_POST['k'] ?? '') === 'c' ? 'c' : 'o';
        $tok = rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
        update_option('oxygen_desk_hash_' . $k, hash('sha256', $tok), false);
        set_transient('oxd_new_' . $k, $tok, 120);
        wp_safe_redirect(admin_url('admin.php?page=oxygen-desk')); exit;
    }
}
