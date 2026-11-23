<?php
/**
 * Oxygen SEO engine: 120 planned articles (60 commercial + 60 informational), endless refresh cycle with 24 scenarios per article,
 * archive log, owner follow-up page. Template/data driven: free, no AI credits. Optional polish through Mutqan_AI is NOT used by default.
 */
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/class-oxygen-seo-mega.php';

class Oxygen_SEO_Engine {
    const OPT = 'oxygen_seo_settings';
    const LOG = 'oxygen_seo_log';
    const CRON = 'oxygen_seo_tick';
    const LOG_MAX = 3000;
    const MAIN = 'oxygen-ac-automation.php';

    /* ---------- data ---------- */
    private static $cache = [];
    private static function data($name) {
        if (!isset(self::$cache[$name])) {
            self::$cache[$name] = include __DIR__ . '/seo/data-' . $name . '.php';
            // Satellite sites also write about cold / freezer rooms (their A/C-and-cold focus); the core sites' plan is untouched.
            if ($name === 'services' && class_exists('Oxygen_Network') && Oxygen_Network::is_satellite()) { self::$cache[$name] = array_merge(self::$cache[$name], include __DIR__ . '/seo/data-net-services.php'); }
        }
        return self::$cache[$name];
    }

    /* ---------- one code base, many sites: every site gets its own brand and its own rotation, so texts never duplicate ---------- */
    /**
     * Whose site this is.
     *
     * The host decides. Every face in the network is registered against its own domain, so a site
     * already knows who it is before anyone configures anything, and that answer cannot drift.
     *
     * oxygen_seo_brand used to win over the registry. Nothing in this plugin ever writes it and no
     * screen can edit it, so a value left behind by hand — or copied in with a database — silently
     * renamed a whole site and there was nowhere to go and put it right. It is now only consulted
     * for a host the registry does not know.
     */
    public static function brand() {
        $id = self::registered_id();
        if ($id !== '') {
            $row = Oxygen_Network::brand($id);   // registry or Blogger-only face
            if (is_array($row) && !empty($row['name'])) { return $row['name']; }
        }
        // An unregistered host (a preview, a staging copy, a domain not in the registry yet) answers
        // with the name its owner gave the site. That is the one name on the site we know is theirs.
        $bn = trim((string) get_option('blogname'));
        if ($bn !== '') { return $bn; }
        $o = get_option('oxygen_seo_brand');
        if (is_string($o) && $o !== '') { return $o; }
        $h = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        return strpos($h, 'mutqan') !== false ? 'متقن' : 'أكسجين';
    }

    /**
     * The face this host is registered as, or '' when the registry does not know the host.
     *
     * Oxygen_Network::id_for_host() answers 'oxygen' for anything it does not recognise, which is a
     * sensible default for the front end but would hand every preview and staging host the hub's
     * name. Here we need the difference, so an unknown host gets '' and keeps whatever name it has.
     */
    private static function registered_id() {
        if (!class_exists('Oxygen_Network')) { return ''; }
        $host = strtolower(preg_replace('/^www\./', '', (string) wp_parse_url(home_url('/'), PHP_URL_HOST)));
        $host = preg_replace('/:\d+$/', '', $host);
        foreach (Oxygen_Network::ids() as $id) { if (Oxygen_Network::host_of($id) === $host) { return $id; } }
        // The Blogger face of a brand (blog.<hub> / blog-<label>.<hub>) belongs to that same brand.
        if (strpos($host, 'blog.') === 0 || strpos($host, 'blog-') === 0) { return Oxygen_Network::id_for_host($host); }
        return '';
    }

    /** Every brand name in the network, used to spot a site wearing somebody else's name. */
    private static function all_brand_names() {
        if (!class_exists('Oxygen_Network')) { return []; }
        $names = [];
        foreach (array_merge(Oxygen_Network::ids(), array_keys(Oxygen_Network::blog_brands())) as $id) {
            $row = Oxygen_Network::brand($id);
            if (is_array($row) && !empty($row['name'])) { $names[] = $row['name']; }
        }
        return array_values(array_unique($names));
    }
    /** Offset added to angle / paragraph rotation and to the plan order: the same topic is written from a different angle on each site. */
    public static function salt() {
        if (class_exists('Oxygen_Network') && Oxygen_Network::is_satellite()) { return 13 + 7 * Oxygen_Network::slot(); }   // a different rotation for each of the 10 satellites
        return self::brand() === 'متقن' ? 11 : 0;
    }

    /* ---------- automatic indexing (IndexNow: free, Bing/Yandex/Seznam/Naver; Google follows the XML sitemap) ---------- */
    public static function indexnow_key() {
        $k = get_option('oxygen_indexnow_key');
        if (!is_string($k) || !preg_match('/^[a-f0-9]{32}$/', $k)) { $k = bin2hex(random_bytes(16)); update_option('oxygen_indexnow_key', $k, false); }
        return $k;
    }
    public static function indexnow($urls) {
        $urls = array_values(array_unique(array_filter((array) $urls)));
        if (!$urls || !function_exists('wp_remote_post')) { return; }
        $key = self::indexnow_key();
        $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $d = wp_date('Y-m-d'); $cnt = get_option('oxygen_indexnow_day', []); $cnt = is_array($cnt) ? array_slice($cnt, -7, null, true) : [];
        $cnt[$d] = (int) ($cnt[$d] ?? 0) + min(100, count($urls)); update_option('oxygen_indexnow_day', $cnt, false);
        wp_remote_post('https://api.indexnow.org/indexnow', [
            'timeout' => 5, 'blocking' => false, 'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body' => wp_json_encode(['host' => $host, 'key' => $key, 'keyLocation' => home_url('/' . $key . '.txt'), 'urlList' => array_slice($urls, 0, 100)]),
        ]);
    }
    public static function serve_key() {
        $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH) : '';
        if (!preg_match('#/([a-f0-9]{32})\.txt$#', $path, $m) || $m[1] !== get_option('oxygen_indexnow_key')) { return; }
        header('Content-Type: text/plain; charset=utf-8');
        echo $m[1];
        exit;
    }
    /** Real, clean URLs + visible to search engines (a fresh WordPress ships with ?p=123 links and sometimes "discourage search engines"). */
    public static function ensure_real_pages() {
        if (get_option('oxygen_seo_site_ready') === '1') { return; }
        if (get_option('permalink_structure') === '') {
            global $wp_rewrite;
            if ($wp_rewrite) { $wp_rewrite->set_permalink_structure('/%postname%/'); flush_rewrite_rules(false); }
        }
        if (get_option('blog_public') === '0') { update_option('blog_public', '1'); }
        update_option('oxygen_seo_site_ready', '1', false);
    }
    /** Engine titles already end with «| brand»: stop Yoast appending the site name («- oxygen») a second time. */
    public static function yoast_title($t) {
        if (is_singular('post')) { $id = get_queried_object_id(); if ($id && get_post_meta($id, '_oxy_seo_key', true)) { return html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8'); } }
        return $t;
    }
    /** Satellite sites present as independent companies: home title = brand + service line in Arabic and English, never the hub host. */
    const TAG_AR = 'صيانة تكييف ومقاولات عامة';
    const TAG_EN = 'AC Service, Maintenance & General Contracting';
    public static function site_name($v) { return (class_exists('Oxygen_Network') && Oxygen_Network::is_satellite()) ? self::brand() : $v; }
    public static function site_tagline($v) { return (class_exists('Oxygen_Network') && Oxygen_Network::is_satellite()) ? self::TAG_AR . ' | ' . self::TAG_EN : $v; }
    public static function front_title($t) {
        if (!(class_exists('Oxygen_Network') && Oxygen_Network::is_satellite()) || !(is_front_page() || is_home() || trim((string) wp_parse_url(isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/', PHP_URL_PATH), '/') === '')) { return $t; }
        return self::brand() . ' | ' . self::TAG_AR . ' | ' . self::TAG_EN;
    }
    public static function front_title_yoast($t) { return self::front_title($t); }
    /** WordPress ships a public «Hello world!» post and a «Sample Page»: thin, duplicate-looking pages that get indexed. Move the untouched originals to the trash (recoverable). */
    public static function trash_defaults() {
        if (get_option('oxygen_defaults_trashed') === '1') { return; }
        foreach (['hello-world' => 'post', 'sample-page' => 'page'] as $slug => $type) {
            $p = get_page_by_path($slug, OBJECT, $type);
            if ($p && $p->post_status === 'publish' && (int) $p->post_author <= 1 && $p->post_date === $p->post_modified) { wp_trash_post($p->ID); }
        }
        update_option('oxygen_defaults_trashed', '1', false);
    }
    public static function ensure_hub() {
        if (get_page_by_path('oxygen-seo-archive')) { return; }
        wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'مقالات ' . self::brand(), 'post_name' => 'oxygen-seo-archive', 'post_content' => '[oxygen_seo_archive]']);
    }
    public static function sitemap_entry($e, $post) {
        if (!empty($post->post_modified_gmt) && $post->post_modified_gmt !== '0000-00-00 00:00:00') { $e['lastmod'] = gmdate('c', strtotime($post->post_modified_gmt . ' UTC')); }
        return $e;
    }
    private static function e($s) { return function_exists('esc_html') ? esc_html($s) : htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
    private static function sub($tpl, $r) { return str_replace(['{t}', '{kw}', '{d}', '{n}'], [$r['t'] ?? '', $r['kw'] ?? '', $r['d'] ?? '', $r['n'] ?? ''], $tpl); }

    /** The full plan: alternating commercial / informational items. */
    public static function plan() {
        if (isset(self::$cache['plan'])) { return self::$cache['plan']; }
        if (class_exists('Oxygen_Network') && class_exists('Oxygen_Network_SEO') && Oxygen_Network::is_satellite()) { return self::$cache['plan'] = Oxygen_Network_SEO::plan(); }
        $svc = self::data('services');
        $topics = self::data('topics');
        $variants = [
            ['city' => 'مكة المكرمة', 'title' => 'أفضل شركة {n} في مكة المكرمة | خبراء مهندسون مختصون', 'kw' => '{n} في مكة', 'slug' => 'makkah'],
            ['city' => 'جدة', 'title' => 'الشركة الرائدة في {n} بجدة — مهندسون وخصومات', 'kw' => '{n} في جدة', 'slug' => 'jeddah'],
            ['city' => 'مكة وجدة', 'title' => '{n} عاجل في مكة وجدة: خبراء مهندسون على مدار الساعة', 'kw' => '{n} عاجل مكة وجدة', 'slug' => 'urgent'],
        ];
        $com = [];
        foreach ($variants as $v) {
            foreach ($svc as $sid => $s) {
                $com[] = ['key' => 'oxy-' . $sid . '-' . $v['slug'], 'track' => 'commercial', 'sid' => $sid, 'city' => $v['city'], 'title' => str_replace('{n}', $s['n'], $v['title']), 'kw' => str_replace('{n}', $s['n'], $v['kw']), 'dom' => $s['spec']];
            }
        }
        $off = [];
        foreach ($topics as $t) {
            $off[] = ['key' => 'oxy-guide-' . $t[0], 'track' => 'official', 'sid' => $t[0], 'city' => '', 'title' => $t[1], 'kw' => $t[2], 'dom' => $t[3], 'points' => $t[4]];
        }
        $plan = [];
        $n = max(count($com), count($off));
        for ($i = 0; $i < $n; $i++) {
            if (isset($com[$i])) { $plan[] = $com[$i]; }
            if (isset($off[$i])) { $plan[] = $off[$i]; }
        }
        $k = count($plan) ? (self::salt() * 5) % count($plan) : 0;
        if ($k) { $plan = array_merge(array_slice($plan, $k), array_slice($plan, 0, $k)); }
        foreach ($plan as $i => &$p) { $p['idx'] = $i; $p['kind'] = 'base'; $p['title'] .= ' | ' . self::brand(); }
        unset($p);
        $idx = count($plan);
        // Same service, same site, different SEO: another title / focus keyword / meta and a different angle (never a copy).
        $alts = [
            ['t' => 'خصومات {n} في {c}: نخبة الخبراء والمهندسين المختصين', 'k' => 'سعر {n} في {c}', 'slug' => 's1'],
            ['t' => 'احجز أفضل مهندس {n} في {c} الآن بخصم مميز', 'k' => 'حجز {n} {c} اليوم', 'slug' => 's2'],
            ['t' => 'أفضل شركة {n} في {c}: ريادة وإتقان لا يضاهى', 'k' => 'أفضل شركة {n} في {c}', 'slug' => 's3'],
            ['t' => 'رواد {n} في {c} للمنازل والشركات: خبرة ومهندسون', 'k' => '{n} {c} للمنازل والشركات', 'slug' => 's4'],
            ['t' => 'لماذا نحن الأفضل في {c}؟ {n} بخبراء مهندسين مختصين', 'k' => 'أسئلة عن {n} في {c}', 'slug' => 's5'],
        ];
        foreach ($alts as $ai => $alt) {
            foreach ($svc as $sid => $sv) {
                foreach ($variants as $v) {
                    $c = $v['city'];
                    $plan[] = ['key' => 'oxy-' . $sid . '-' . $v['slug'] . '-' . $alt['slug'], 'track' => 'commercial', 'kind' => 'seo', 'sid' => $sid, 'city' => $c, 'alt' => $ai + 1,
                        'title' => str_replace(['{n}', '{c}'], [$sv['n'], $c], $alt['t']) . ' | ' . self::brand(), 'kw' => str_replace(['{n}', '{c}'], [$sv['n'], $c], $alt['k']),
                        'dom' => $sv['spec'] ?? 'general', 'idx' => $idx++];
                }
            }
        }
        // Local variants: the service in a named Makkah neighbourhood (the order differs per site so two sites never publish the same one).
        $dist = array_values(self::data('districts'));
        $k = (self::salt() * 3) % max(1, count($dist));
        if ($k) { $dist = array_merge(array_slice($dist, $k), array_slice($dist, 0, $k)); }
        foreach ($dist as $di => $d) {
            foreach ($svc as $sid => $sv) {
                $plan[] = ['key' => 'oxy-' . $sid . '-d' . md5($d), 'track' => 'commercial', 'kind' => 'district', 'sid' => $sid, 'city' => 'حي ' . $d . ' بمكة المكرمة', 'district' => $d, 'alt' => 3 + ($di % 3),
                    'title' => $sv['n'] . ' في حي ' . $d . ' بمكة المكرمة | ' . self::brand(), 'kw' => $sv['n'] . ' حي ' . $d . ' مكة',
                    'dom' => $sv['spec'] ?? 'general', 'idx' => $idx++];
            }
        }
        // Jeddah neighbourhoods (the business covers Makkah and Jeddah).
        $jd = array_values(self::data('districts-jeddah'));
        $k = (self::salt() * 4) % max(1, count($jd));
        if ($k) { $jd = array_merge(array_slice($jd, $k), array_slice($jd, 0, $k)); }
        foreach ($jd as $di => $d) {
            foreach ($svc as $sid => $sv) {
                $plan[] = ['key' => 'oxy-' . $sid . '-j' . md5($d), 'track' => 'commercial', 'kind' => 'district', 'sid' => $sid, 'city' => 'حي ' . $d . ' بجدة', 'district' => $d, 'alt' => 3 + ($di % 3),
                    'title' => $sv['n'] . ' في حي ' . $d . ' بجدة | ' . self::brand(), 'kw' => $sv['n'] . ' حي ' . $d . ' جدة',
                    'dom' => $sv['spec'] ?? 'general', 'idx' => $idx++];
            }
        }
        return self::$cache['plan'] = self::cooling_first($plan);
    }
    /** Owner order 2026-11-19: A/C and cold / freezer rooms are the top priority. The publisher takes the first unpublished
     *  item of the plan, so A/C + cold items move to the front (stable: their own order is kept); other trades follow. */
    public static function cooling_first(array $plan) {
        $hot = []; $rest = [];
        foreach ($plan as $it) { if (in_array($it['dom'] ?? '', ['ac', 'fridge'], true)) { $hot[] = $it; } else { $rest[] = $it; } }
        return array_merge($hot, $rest);
    }
    private static function domain($dom) {
        $d = self::data('domains');
        $map = ['ac' => 'ac', 'plumbing' => 'plumbing', 'electric' => 'electric', 'fridge' => 'fridge', 'appliances' => 'appliances', 'pest' => 'pest', 'tanks' => 'tanks', 'locks' => 'locks', 'cameras' => 'cameras', 'solar' => 'solar'];
        return $d[$map[$dom] ?? 'general'] ?? $d['general'];
    }
    public static function angle_count() { return count(self::data('angles')); }
    public static function angle_index($idx, $rev) { return (($idx * 7) + $rev + self::salt()) % self::angle_count(); }
    public static function angle_label($idx, $rev) { $a = self::data('angles'); return $a[self::angle_index($idx, $rev)][0]; }

    /* ---------- content builders (pure, testable) ---------- */
    public static function cover_svg($title, $label, $seed) {
        $pal = [['#0b3a6e', '#00b4d8'], ['#14532d', '#22c55e'], ['#7c2d12', '#f59e0b'], ['#3b0764', '#a855f7'], ['#134e4a', '#2dd4bf'], ['#1e3a8a', '#60a5fa']];
        $c = $pal[abs(crc32($seed)) % count($pal)];
        $words = preg_split('/\s+/u', trim($title));
        $lines = []; $cur = '';
        foreach ($words as $w) {
            if ($cur !== '' && mb_strlen($cur . ' ' . $w) > 24 && count($lines) < 2) { $lines[] = $cur; $cur = $w; } else { $cur = ($cur === '' ? $w : $cur . ' ' . $w); }
        }
        $lines[] = $cur;
        $lines = array_slice($lines, 0, 3);
        $txt = '';
        foreach ($lines as $i => $l) { $txt .= '<text x="600" y="' . (250 + $i * 82) . '" text-anchor="middle" font-size="64" font-weight="700" fill="#fff">' . self::e($l) . '</text>'; }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 630" width="1200" height="630" direction="rtl" font-family="Tahoma,Arial,sans-serif"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . $c[0] . '"/><stop offset="1" stop-color="' . $c[1] . '"/></linearGradient></defs><rect width="1200" height="630" fill="url(#g)"/><circle cx="1050" cy="120" r="220" fill="#fff" opacity=".07"/><circle cx="120" cy="560" r="260" fill="#fff" opacity=".06"/><text x="600" y="120" text-anchor="middle" font-size="34" fill="#fff" opacity=".85">' . self::e($label) . '</text>' . $txt . '<text x="600" y="580" text-anchor="middle" font-size="30" fill="#fff" opacity=".9">' . self::e(self::brand()) . ' · خدمات الصيانة في مكة وجدة</text></svg>';
    }
    private static function steps_html($app, $wa) {
        $h = '<ol><li>افتح تطبيق ' . self::brand() . ' أو صفحة الطلب.</li><li>اختر الخدمة المطلوبة وحدد موقعك بضغطة واحدة أو بلصق رابط الخريطة.</li><li>أرسل الطلب بدون تسجيل؛ يصلك التأكيد عبر واتساب.</li><li>يصل الفني في الموعد، ويُعرض عليك السعر قبل البدء.</li></ol>';
        $h .= '<p class="oxy-seo-cta"><a href="' . self::e($app) . '">اطلب الخدمة من التطبيق</a>';
        if ($wa) { $h .= ' · <a href="https://wa.me/' . self::e($wa) . '">تواصل عبر واتساب</a>'; }
        return $h . '</p>';
    }
    private static function links_html($links) {
        if (!$links) { return ''; }
        $h = '<h2>مقالات ذات صلة</h2><ul>';
        foreach ($links as $l) { $h .= '<li><a href="' . self::e($l[1]) . '">' . self::e($l[0]) . '</a></li>'; }
        return $h . '</ul>';
    }
    private static function rot($arr, $start, $n) { $out = []; $c = count($arr); for ($i = 0; $i < min($n, $c); $i++) { $out[] = $arr[($start + $i) % $c]; } return $out; }
    private static function faq_html($faq) {
        $h = '<h2>أسئلة شائعة</h2>';
        foreach ($faq as $q) { $h .= '<h3>' . self::e($q[0]) . '</h3><p>' . self::e($q[1]) . '</p>'; }
        return $h;
    }
    /* generic, practical sections shared by the guides and (to reach a full-length article) the service pages */
    private static function checks_html() { return '<h2>خطوات الفحص الأولي</h2><ol><li>سجّل ما تلاحظه بالضبط: متى بدأت المشكلة وما الذي تغيّر قبلها.</li><li>افصل الكهرباء أو أغلق المحبس المعني إن كان في الأمر خطر، ولا تبدأ بأي فك قبل ذلك.</li><li>افحص الأمور البسيطة أولاً كالنظافة والفلاتر والتوصيلات الظاهرة والإعدادات.</li><li>صوّر الجزء المتأثر أو رسالة الخطأ لتشاركها مع الفني عند الحاجة.</li><li>قارن النتيجة بعد كل خطوة ولا تغيّر عدة أمور دفعة واحدة، حتى تعرف ما الذي أثّر فعلاً.</li></ol>'; }
    private static function mistakes_html() { return '<h2>أخطاء يجب تجنبها</h2><ul><li>تأجيل الفحص بعد ظهور أول عَرَض، فالأعطال الصغيرة تتراكم مع الوقت.</li><li>استخدام قطع أو مواد رخيصة غير مطابقة للمواصفات لمجرد توفير مبلغ بسيط.</li><li>إصلاح العرض دون البحث عن السبب، فتعود المشكلة بعد أيام.</li><li>تجاهل تعليمات الشركة المصنعة عن التنظيف والتشغيل وحدود الاستخدام.</li></ul>'; }
    private static function schedule_html() { return '<h2>جدول متابعة مقترح</h2><ul><li>أسبوعياً: نظرة سريعة على الأداء والأصوات والروائح والرطوبة.</li><li>شهرياً: تنظيف الأجزاء القابلة للتنظيف حسب دليل الاستخدام.</li><li>كل موسم: فحص أوسع قبل الصيف وبعده وتسجيل الملاحظات.</li><li>سنوياً: زيارة صيانة شاملة من مختص مع حفظ تقرير الزيارة.</li></ul>'; }
    private static function call_html($dm) { return '<h2>متى تحتاج إلى فني مختص؟</h2><p>تنصحك بالاستعانة بمختص ' . self::e($dm['call']) . ' فتأخير التدخل في هذه الحالات يرفع الكلفة ويزيد المخاطر.</p>'; }

    /** Returns ['html','faq','desc','text'] for one item at a revision. $ctx: app, wa, links, img */
    public static function build($item, $rev, $ctx = []) {
        $angles = self::data('angles');
        $a = $angles[self::angle_index($item['idx'], $rev)];
        $dm = self::domain($item['dom']);
        $app = $ctx['app'] ?? '#';
        $wa = $ctx['wa'] ?? '';
        $img = $ctx['img'] ?? '';
        $html = '';
        if ($img) { $html .= '<p><img src="' . self::e($img) . '" alt="' . self::e($item['title']) . '" width="1200" height="630" loading="lazy" style="max-width:100%;height:auto;border-radius:12px"></p>'; }
        if ($item['track'] === 'commercial') {
            $s = self::data('services')[$item['sid']];
            $r = ['t' => $item['title'], 'kw' => $item['kw'], 'd' => $s['n'], 'n' => $s['n']];
            $html .= '<p>تبحث عن <strong>' . self::e($item['kw']) . '</strong>؟ تقدم ' . self::brand() . ' خدمة ' . self::e($s['svc']) . ' في ' . self::e($item['city']) . ' بفنيين مدربين، وحجز بدون تسجيل، وسعر واضح يُعرض عليك قبل بدء العمل. ' . self::e(self::sub($a[1], $r)) . '</p>';
            $html .= '<h2>ماذا تشمل خدمة ' . self::e($s['n']) . '؟</h2><ul>';
            foreach ($s['items'] as $it) { $html .= '<li>' . self::e($it) . '</li>'; }
            $html .= '</ul><h2>' . self::e($a[2]) . '</h2>';
            foreach ($a[3] as $p) { $html .= '<p>' . self::e(self::sub($p, $r)) . '</p>'; }
            $html .= '<h2>لماذا تختار ' . self::brand() . '؟</h2><p>نعمل بنظام طلب بسيط بلا تسجيل، ونرسل لك التأكيد عبر واتساب، ونعرض السعر قبل بدء العمل حتى لا تفاجأ بالفاتورة. فنيونا يشرحون السبب ويقترحون الخيارات، ويوثقون العمل بما يسهل عليك المتابعة لاحقاً. تغطي فرقنا مكة المكرمة وجدة، ويمكنك طلب موعد عاجل أو مجدول بحسب ظروفك.</p>';
            $html .= '<h2>متى تحتاج إلى ' . self::e($s['n']) . '؟</h2><ul>';
            foreach ($s['signs'] as $it) { $html .= '<li>' . self::e($it) . '</li>'; }
            $html .= '</ul><h2>ما الذي يميز التعامل مع ' . self::brand() . ' في ' . self::e($item['city']) . '؟</h2>';
            foreach (self::rot($dm['paras'], $rev + self::salt(), 2) as $p) { $html .= '<p>' . self::e($p) . '</p>'; }
            if (!empty($item['district'])) {
                $dn = $item['district'];
                $tpl = [
                    'يطلب سكان حي ' . $dn . ' خدمة ' . $s['n'] . ' بحسب طبيعة المباني عندهم، ونحرص أن يكون الفني مجهزاً بما يناسب نوع الوحدة قبل الوصول.',
                    'إذا كنت في حي ' . $dn . ' فاذكر في الطلب رقم الدور وأقرب معلم، فهذا يسرّع وصول الفني ويقلل المكالمات الإضافية.',
                    'تختلف الأعطال في ' . $s['n'] . ' بين بيت وآخر داخل حي ' . $dn . '، ولذلك نبدأ بفحص الحالة ثم نعرض السعر قبل أي تنفيذ.',
                    'يؤكد فريق الجدولة موعد زيارتك في حي ' . $dn . ' بعد استلام الطلب، وتصلك رسالة تأكيد على واتساب.',
                    'نتعامل في حي ' . $dn . ' مع الشقق والفلل والمحلات، ويُحدَّد وقت الزيارة بحسب ازدحام اليوم وتوفر الفنيين.',
                    'سواء كانت المشكلة بسيطة أو تحتاج قطعة غيار، تُشرح لك الخطوات في حي ' . $dn . ' قبل البدء حتى تقرر وأنت مطمئن.',
                ];
                $html .= '<h2>' . self::e($s['n']) . ' في حي ' . self::e($dn) . '</h2>';
                foreach (self::rot($tpl, $item['idx'] + $rev + self::salt(), 3) as $p) { $html .= '<p>' . self::e($p) . '</p>'; }
            }
            if (!empty($item['place'])) {
                $pn = $item['place'];
                $tplp = [
                    'إذا كنت في محيط ' . $pn . ' وتحتاج ' . $s['n'] . ' فاكتب في الطلب أقرب علامة مميزة وسيصلك الفني المناسب مع تأكيد الموعد على واتساب.',
                    'يطلب عملاؤنا القريبون من ' . $pn . ' خدمة ' . $s['n'] . ' بحسب نوع المبنى، ونجهّز الفني بما يناسب الحالة قبل التحرك.',
                    'نذكر ' . $pn . ' هنا كمعلم يساعدك على تحديد موقعك فقط، ويُحدَّد الموعد النهائي بعد استلام الطلب وتأكيد العنوان.',
                    'سواء كنت بجوار ' . $pn . ' أو في الشوارع المحيطة، فبإمكانك طلب ' . $s['n'] . ' بخطوات بسيطة بلا تسجيل.',
                    'تتفاوت الأعطال في ' . $s['n'] . ' من مبنى لآخر حول ' . $pn . '، ولذلك نبدأ بفحص الحالة ثم نشرح الإجراء قبل التنفيذ.',
                ];
                $html .= '<h2>' . self::e($s['n']) . ' في محيط ' . self::e($pn) . '</h2>';
                foreach (self::rot($tplp, $item['idx'] + $rev + self::salt(), 3) as $p) { $html .= '<p>' . self::e($p) . '</p>'; }
            }
            if (!empty($item['brand'])) {
                $bn = $item['brand'];
                $tplb = [
                    'يستفسر كثير من العملاء عن ' . $s['n'] . ' لأجهزة ' . $bn . '، ونتعامل مع الأعطال الشائعة بفحص دقيق يحدد السبب قبل أي إجراء.',
                    'لكل جهاز من ' . $bn . ' طبيعته في التشغيل والصيانة، لذلك نرجو ذكر الموديل وسنة الشراء عند الطلب لنجهّز الفني المناسب.',
                    'نقدّم خدمة ' . $s['n'] . ' لأجهزة ' . $bn . ' بفريق من المهندسين والفنيين المختصين، ونوضح لك ما يلزم قبل البدء.',
                    'اسم ' . $bn . ' هنا لتحديد نوع الجهاز فقط؛ نحن شركة صيانة مستقلة ولسنا الوكيل الرسمي للعلامة.',
                    'قبل طلب الفني افحص دليل جهاز ' . $bn . ' وسجّل رمز العطل إن ظهر على الشاشة، فهذا يسرّع التشخيص.',
                ];
                $html .= '<h2>' . self::e($s['n']) . ' لأجهزة ' . self::e($bn) . '</h2>';
                foreach (self::rot($tplb, $item['idx'] + $rev + self::salt(), 3) as $p) { $html .= '<p>' . self::e($p) . '</p>'; }
            }
            $html .= '<h2>خطوات الطلب</h2>' . self::steps_html($app, $wa);
            $faq = $s['faq'];
            $faq[] = ['هل تغطون ' . $item['city'] . ' بالكامل؟', 'نغطي معظم أحياء ' . $item['city'] . ' ويؤكد فريق الجدولة الموعد المناسب لموقعك بعد استلام الطلب.'];
            $desc = self::desc_for($item);
            if (false) {
                $hook = [' — ' . self::brand() . ': الشركة الرائدة، مهندسون خبراء مختصون وخصومات على الخدمة. احجز الآن بلا تسجيل.', ' — نخبة من المهندسين المختصين وخبرة طويلة وخصومات لعملائنا. اطلب الخدمة الآن.', ' — أفضل خدمة بإشراف مهندسين خبراء، مع خصومات وحجز سريع بلا تسجيل.'];
                $desc = $item['kw'] . $hook[(int) ($item['idx'] % 3)];
            }
        } else {
            $r = ['t' => $item['title'], 'kw' => $item['kw'], 'd' => $dm['label'], 'n' => $dm['label']];
            $html .= '<p>يبحث كثيرون عن <strong>' . self::e($item['kw']) . '</strong>، وهذا الدليل يجمع لك الخلاصة العملية في صفحة واحدة. ' . self::e(self::sub($a[1], $r)) . '</p><h2>أهم النقاط</h2><ol>';
            foreach ($item['points'] as $pt) { $html .= '<li>' . self::e($pt) . '</li>'; }
            $html .= '</ol><h2>' . self::e($a[2]) . '</h2>';
            foreach ($a[3] as $p) { $html .= '<p>' . self::e(self::sub($p, $r)) . '</p>'; }
            $html .= '<h2>شرح أعمق: ' . self::e($dm['label']) . '</h2>';
            $paras = self::rot($dm['paras'], $rev + self::salt(), count($dm['paras']));
            foreach ($paras as $p) { $html .= '<p>' . self::e($p) . '</p>'; }
            $html .= '<h2>لماذا يهمك هذا الموضوع؟</h2><p>' . self::e(self::sub('موضوع «{t}» ليس ترفاً معرفياً؛ فهو يحدد كم تدفع في الفواتير، وكم يعيش الجهاز أو النظام، ومتى تظهر الأعطال. من يفهم الأساسيات في {d} يتخذ قرارات أهدأ وأرخص، ويتعامل مع الفني بثقة أكبر ويعرف ما يُطلب منه فعلاً.', $r)) . '</p>';
            $html .= self::checks_html() . self::mistakes_html() . self::schedule_html() . self::call_html($dm);
            $faq = [
                ['هل أستطيع معالجة الأمر بنفسي؟', 'يمكنك إجراء الفحوص الأولية المذكورة في هذا الدليل، أما الأعمال التي تتعلق بالكهرباء أو الغاز أو الضغط العالي أو الارتفاعات فاتركها لمختص حفاظاً على السلامة.'],
                ['كم يستغرق التشخيص عادة؟', 'تشخيص معظم الحالات يتم في الزيارة الأولى، وقد يحتاج طلب قطعة أو فحص إضافي إلى زيارة ثانية.'],
                ['كيف أمنع تكرار المشكلة؟', 'الصيانة الدورية وتدوين الملاحظات والانتباه للأعراض المبكرة هي أقوى وسائل منع التكرار.'],
            ];
            $html .= self::faq_html($faq);
            $html .= '<h2>ملخص سريع</h2><p>' . self::e(self::sub('إذا أردت تلخيص «{t}» في جملة واحدة: راقب الأعراض مبكراً، وابدأ بالفحوص البسيطة الآمنة، ولا تتردد في طلب مختص حين يتعلق الأمر بالسلامة أو يتكرر العطل. بهذا تحمي {d} في منزلك وتوفر المال والوقت، وتبقى {kw} مسألة تحت سيطرتك بدل أن تتحول إلى مفاجأة مكلفة في وقت غير مناسب.', $r)) . '</p>';
            $html .= '<h2>هل تحتاج مساعدة مباشرة؟</h2><p>إن رغبت بفني يعاين الحالة في مكة أو جدة فيمكنك الطلب بسهولة بدون تسجيل.</p>' . self::steps_html($app, $wa);
            $desc = self::desc_for($item);
            $html .= self::links_html($ctx['links'] ?? []);
            return ['html' => $html, 'faq' => $faq, 'desc' => self::trim_desc($desc), 'text' => strip_tags($html)];
        }
        $html .= self::faq_html($faq);
        $html .= self::links_html($ctx['links'] ?? []);
        return ['html' => $html, 'faq' => $faq, 'desc' => self::trim_desc($desc), 'text' => strip_tags($html)];
    }
    /** Search-result description: starts with the focus keyword, then what the service covers + the call to order. 100-160 characters, no claims. */
    public static function desc_for($item) {
        $item = self::seo_item($item);
        $svc = self::data('services');
        if (($item['track'] ?? '') === 'commercial' && isset($svc[$item['sid']])) {
            $bits = array_values($svc[$item['sid']]['items'] ?? []);
            $cta = ' اطلب فنياً الآن وتصفّح العروض.';
        } else {
            $bits = array_values($item['points'] ?? []);
            $cta = ' اطلب فنياً أو تصفّح العروض.';
        }
        return self::compose_desc((string) ($item['kw'] ?? ''), $bits, $cta);
    }
    /** keyword + as many of $bits as fit + call to action, between 100 and 160 characters */
    public static function compose_desc($kw, $bits, $cta = ' اطلب فنياً الآن وتصفّح العروض.') {
        $kw = trim((string) $kw);
        $bits = array_values(array_filter(array_map(function ($b) { return trim(preg_replace('/[\s.،:]+$/u', '', self::strip_claims_text(wp_strip_all_tags((string) $b)))); }, (array) $bits), 'strlen'));
        $lead = $kw;
        foreach ($bits as $i => $b) {
            $sep = ($lead === '' ? '' : ($i === 0 ? ': ' : '، '));
            $room = 158 - mb_strlen($cta) - mb_strlen($lead) - mb_strlen($sep) - 1;
            if (mb_strlen($b) > $room) {
                if ($i > 0 || $room < 25) { break; }
                $b = mb_substr($b, 0, $room); $sp = mb_strrpos($b, ' '); if ($sp > 15) { $b = mb_substr($b, 0, $sp); }
                $b = preg_replace('/[\s،.:]+$/u', '', $b);
                $lead .= $sep . $b; break;
            }
            $lead .= $sep . $b;
            if (mb_strlen($lead) + mb_strlen($cta) >= 115) { break; }
        }
        $d = rtrim($lead, '.') . '.' . $cta;
        if (mb_strlen($d) < 100) { $d .= ' حجز بلا تسجيل وتأكيد على واتساب.'; }
        if (mb_strlen($d) > 160) { $d = mb_substr($d, 0, 157); $sp = mb_strrpos($d, ' '); if ($sp > max(40, mb_strlen($kw))) { $d = mb_substr($d, 0, $sp); } $d = preg_replace('/[\s،.:]+$/u', '', $d) . '…'; }
        return $d;
    }
    private static function item_of_post($id) {
        $k = (string) get_post_meta($id, '_oxy_seo_key', true); if ($k === '') { return null; }
        if (Oxygen_SEO_Mega::is_key($k)) { return Oxygen_SEO_Mega::item_by_key($k); }
        foreach (self::plan() as $it) { if ($it['key'] === $k) { return $it; } }
        return null;
    }
    public static function seo_meta_desc($d) {
        if (!is_singular('post')) { return $d; }
        $it = self::item_of_post(get_queried_object_id());
        return $it ? self::desc_for($it) : $d;
    }
    public static function brand_author($n) { return self::brand(); }
    public static function hide_author_pages() { if (is_author()) { wp_safe_redirect(home_url('/'), 301); exit; } }
    public static function og_title($t) { if (is_singular('post') && get_post_meta(get_queried_object_id(), '_oxy_seo_key', true)) { return html_entity_decode(get_the_title(get_queried_object_id()), ENT_QUOTES, 'UTF-8'); } return $t; }
    public static function no_author_sitemap($providers) { return array_values(array_filter((array) $providers, function ($p) { return stripos(get_class($p), 'Author') === false; })); }
    /**
     * Fills in an identity the site never had.
     *
     * It used to replace any name written in Latin letters, on the theory that such a name was a
     * leftover from installation. "OXYGEN" is written in Latin letters and was the site's real
     * name, so the rule renamed a live business. A name somebody chose is never ours to replace;
     * only an empty one is ours to fill.
     */
    public static function clean_identity() {
        if (get_option('oxygen_identity_v1') === '1') { return; }
        $b = self::brand();
        if (trim((string) get_option('blogname')) === '') { update_option('blogname', $b); }
        foreach (get_users(['role' => 'administrator', 'fields' => 'ID']) as $uid) { wp_update_user(['ID' => (int) $uid, 'display_name' => $b, 'nickname' => $b]); }
        update_option('oxygen_identity_v1', '1', false);
    }

    /**
     * Puts back a name that was taken by another brand in the network.
     *
     * Runs once. It only acts on the one thing that is unambiguous damage: this site is calling
     * itself by the registered name of a DIFFERENT face. A name nobody else in the network uses is
     * the owner's own choice and is left exactly as it is, whatever it says.
     */
    public static function repair_identity() {
        if (get_option('oxygen_identity_repair_v1') === '1') { return; }
        update_option('oxygen_identity_repair_v1', '1', false);
        $mine = self::brand();
        $current = trim((string) get_option('blogname'));
        if ($current === '' || $current === $mine) { return; }
        // Compare without the Arabic vowel marks: متقن and مُتقن are the same word wearing a hat.
        $bare = static fn ($s) => preg_replace('/[\x{0617}-\x{061A}\x{064B}-\x{0652}\x{0670}]/u', '', (string) $s);
        foreach (self::all_brand_names() as $name) {
            if ($name !== $mine && $bare($name) === $bare($current)) {
                update_option('blogname', $mine);
                if (get_option('oxygen_seo_brand') !== false) { delete_option('oxygen_seo_brand'); }
                return;
            }
        }
    }
    private static function trim_desc($d) { return mb_strlen($d) > 158 ? mb_substr($d, 0, 155) . '...' : $d; }
    public static function word_count($text) { return count(preg_split('/\s+/u', trim(strip_tags($text)), -1, PREG_SPLIT_NO_EMPTY)); }

    /* ---------- settings / log ---------- */
    public static function settings() {
        $d = ['enabled' => 1, 'status' => 'publish', 'new_per_day' => 60, 'seo_per_day' => 60, 'district_per_day' => 60, 'random_per_day' => 60, 'refresh_per_day' => 60, 'min_age_days' => 7, 'wa' => ''];
        $s = get_option(self::OPT, []);
        $s = is_array($s) ? $s : [];
        // One-time rate upgrade on every site: 60 new / 60 SEO-variant / 60 district / 60 refreshes per day.
        if (get_option('oxygen_rate_v60') !== '1') {
            $s['new_per_day'] = 60; $s['seo_per_day'] = 60; $s['district_per_day'] = 60; $s['refresh_per_day'] = 60;
            update_option(self::OPT, $s, false); update_option('oxygen_rate_v60', '1', false);
        }
        if (get_option('oxygen_rate_v60b') !== '1') { $s['random_per_day'] = 60; update_option(self::OPT, $s, false); update_option('oxygen_rate_v60b', '1', false); }
        return array_merge($d, $s);
    }
    public static function log($action, $post_id, $title, $extra = []) {
        $log = get_option(self::LOG, []);
        if (!is_array($log)) { $log = []; }
        array_unshift($log, array_merge(['ts' => time(), 'a' => $action, 'id' => (int) $post_id, 't' => $title, 'l' => $post_id ? get_permalink($post_id) : ''], $extra));
        update_option(self::LOG, array_slice($log, 0, self::LOG_MAX), false);
    }
    public static function today_count($action) {
        $log = get_option(self::LOG, []);
        $start = strtotime(wp_date('Y-m-d 00:00:00'));
        $n = 0;
        foreach ((array) $log as $r) { if (($r['ts'] ?? 0) >= $start && ($r['a'] ?? '') === $action) { $n++; } }
        return $n;
    }

    /* ---------- WP glue ---------- */
    public static function boot() {
        add_action('init', [__CLASS__, 'ensure_cron'], 3);
        add_action('init', [__CLASS__, 'serve_key'], 1);
        add_action('init', [__CLASS__, 'ensure_real_pages'], 30);
        add_filter('wp_sitemaps_enabled', function ($on) { return defined('WPSEO_VERSION') ? $on : true; }, 20);
        add_filter('wpseo_title', [__CLASS__, 'yoast_title'], 20);
        add_filter('pre_get_document_title', [__CLASS__, 'front_title'], 99);
        add_filter('wpseo_title', [__CLASS__, 'front_title_yoast'], 25);
        add_filter('pre_option_blogname', [__CLASS__, 'site_name'], 20);
        add_filter('pre_option_blogdescription', [__CLASS__, 'site_tagline'], 20);
        add_action('init', [__CLASS__, 'trash_defaults'], 31);
        add_filter('wpseo_metadesc', [__CLASS__, 'seo_meta_desc'], 30);
        add_filter('wpseo_opengraph_desc', [__CLASS__, 'seo_meta_desc'], 30);
        add_filter('wpseo_opengraph_site_name', [__CLASS__, 'brand_author'], 30);
        add_filter('wpseo_opengraph_title', [__CLASS__, 'og_title'], 30);
        add_filter('wpseo_twitter_title', [__CLASS__, 'og_title'], 30);
        add_filter('wpseo_enhanced_slack_data', '__return_empty_array', 30);
        add_filter('wpseo_sitemaps_providers', [__CLASS__, 'no_author_sitemap'], 30);
        add_filter('the_author', [__CLASS__, 'brand_author'], 30);
        add_filter('get_the_author_display_name', [__CLASS__, 'brand_author'], 30);
        add_action('template_redirect', [__CLASS__, 'hide_author_pages'], 1);
        add_action('init', [__CLASS__, 'clean_identity'], 32);
        add_action('init', [__CLASS__, 'repair_identity'], 33);
        add_filter('wp_sitemaps_posts_entry', [__CLASS__, 'sitemap_entry'], 10, 2);
        add_action(self::CRON, [__CLASS__, 'tick']);
        add_filter('the_content', [__CLASS__, 'filter_content'], 5);
        add_action('wp_head', [__CLASS__, 'head'], 4);
        add_action('wp_head', [__CLASS__, 'gsc_meta'], 1);
        add_filter('get_site_icon_url', [__CLASS__, 'site_icon_url'], 20, 2);   // Google / browser tab icon = the brand's own mark (sizes are multiples of 48 px, as Google requires)
        add_shortcode('oxygen_seo_archive', [__CLASS__, 'archive_shortcode']);
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_oxy_seo_save', [__CLASS__, 'h_save']);
        add_action('admin_post_oxy_seo_run', [__CLASS__, 'h_run']);
        add_action('admin_post_oxy_seo_pub', [__CLASS__, 'h_publish_drafts']);
        add_action('admin_post_oxy_seo_page', [__CLASS__, 'h_archive_page']);
    }
    public static function ensure_cron() {
        if (!wp_next_scheduled(self::CRON)) { wp_schedule_event(time() + 300, 'hourly', self::CRON); }
    }
    public static function active() { $s = self::settings(); return !empty($s['enabled']); }

    private static function app_url() { return plugins_url('app/index.html', dirname(__DIR__) . '/' . self::MAIN) . '#/'; }
    private static function map() {
        if (isset(self::$cache['map'])) { return self::$cache['map']; }
        $ids = get_posts(['post_type' => 'post', 'post_status' => 'any', 'meta_key' => '_oxy_seo_key', 'numberposts' => -1, 'fields' => 'ids', 'no_found_rows' => true]);
        $m = [];
        foreach ($ids as $id) { $k = get_post_meta($id, '_oxy_seo_key', true); if ($k) { $m[$k] = (int) $id; } }
        return self::$cache['map'] = $m;
    }
    private static function ctx($item, $map, $key_img = true) {
        $s = self::settings();
        $links = [];
        foreach (self::plan() as $p) {
            if ($p['key'] === $item['key'] || $p['dom'] !== $item['dom'] || empty($map[$p['key']])) { continue; }
            if (get_post_status($map[$p['key']]) !== 'publish') { continue; }
            $links[] = [$p['title'], get_permalink($map[$p['key']])];
            if (count($links) >= 4) { break; }
        }
        $img = '';
        if ($key_img) {
            $u = wp_upload_dir();
            if (empty($u['error'])) {
                $dir = $u['basedir'] . '/oxygen-seo';
                if (wp_mkdir_p($dir)) {
                    $rev = !empty($map[$item['key']]) ? (int) get_post_meta($map[$item['key']], '_oxy_seo_rev', true) : 0;
                    $label = $item['track'] === 'commercial' ? 'خدمات ' . self::brand() . '' : 'دليل ' . self::brand() . '';
                    $fn = $item['key'] . '.svg';
                    if (@file_put_contents($dir . '/' . $fn, self::cover_svg($item['title'], $label, $item['key'] . $rev))) { $img = $u['baseurl'] . '/oxygen-seo/' . $fn . '?v=' . $rev; }
                }
            }
        }
        $wa = preg_replace('/\D/', '', (string) $s['wa']);
        // Each new article carries one of the company's sales numbers, picked by the article (Mutqan_Lines), so the
        // network's articles spread over all the sales lines instead of one fixed number.
        if (class_exists('Mutqan_Lines')) { $r = Mutqan_Lines::public_for(class_exists('Oxygen_Network') ? Oxygen_Network::current_id() : '', (int) sprintf('%u', crc32((string) $item['key'])), 'articles'); if ($r['wa'] !== '') { $wa = $r['wa']; } }
        return ['app' => self::app_url(), 'wa' => $wa, 'links' => $links, 'img' => $img];
    }
    private static function term($name) {
        if (!function_exists('wp_insert_term')) { return 0; }
        $t = term_exists($name, 'category');
        if (!$t) { $t = wp_insert_term($name, 'category'); }
        return is_array($t) ? (int) $t['term_id'] : 0;
    }

    /** Two sites, two different article sets: every plan item belongs to exactly one site (stable hash of its key), so the two
     *  domains never publish the same article / compete for the same keyword. Already published items are never touched.
     *  Turn off with the option oxygen_seo_partition = '0'. */
    public static function owns($item) {
        if (get_option('oxygen_seo_partition', '1') === '0') { return true; }
        if (class_exists('Oxygen_Network') && Oxygen_Network::is_satellite()) { return strpos((string) $item['key'], 'oxy-net-') === 0; }   // a satellite only ever publishes its own namespaced plan
        return (crc32((string) $item['key']) & 1) === (self::salt() ? 1 : 0);
    }
    public static function create_next($force_status = null, $kind = 'base') {
        $map = self::map();
        if ($kind === 'district') {
            // Local articles come from the keyword universe: every neighbourhood / named place / brand, Makkah first (never one district repeated for every service).
            $item = Oxygen_SEO_Mega::next($map, function ($it) { return self::owns($it); });
            return $item ? self::publish_item($item, 'district', $force_status, $map) : false;
        }
        foreach (self::plan() as $item) {
            if (($item['kind'] ?? 'base') !== $kind || isset($map[$item['key']]) || !self::owns($item)) { continue; }
            return self::publish_item($item, $kind, $force_status, $map);
        }
        return false;
    }
    /* ---------- on-page SEO (the checks of Oxygen_Daily_Report) + the owner's content rules, applied before any article is saved ---------- */

    /**
     * Owner rule: an article never states a price, a percentage, a phone number, a licence, years of experience or a rating
     * (the same list the network review applies, see network/common.py BAD). Matched on the visible text only, never on links.
     */
    const CLAIM_RE = '/ريال|ر\.\s?س(?!\p{L})|(?<![A-Za-z])SAR(?![A-Za-z])|[0-9٠-٩]+(?:[.,٫][0-9٠-٩]+)?\s?(?:%|٪|بالمئة|بالمائة|في\s?المئة|في\s?المائة)|(?<![0-9٠-٩])(?:\+?966|00966|0)\s?5[0-9٠-٩](?:[\s-]?[0-9٠-٩]){7}(?![0-9٠-٩])|(?<![0-9٠-٩])(?:800|920)[\s-]?[0-9٠-٩]{3}[\s-]?[0-9٠-٩]{3,4}(?![0-9٠-٩])|مرخص|ترخيص|تراخيص|رخص[ةه]|(?:سنوات|سنة|أعوام|عام[ا\x{064B}]*|عقود)\s+(?:من\s+)?(?:ال)?خبر[ةه]|خبر[ةه]\s+(?:تمتد\s+|تزيد\s+(?:عن|على)\s+|تتجاوز\s+|تفوق\s+|أكثر\s+من\s+|لأكثر\s+من\s+|منذ\s+)?[0-9٠-٩]+|[0-9٠-٩]+\s*(?:سنة|سنوات|عام[ا\x{064B}]*|أعوام)\s+(?:من\s+)?(?:ال)?خبر|★|⭐|تقييم\s*[0-9٠-٩]|(?<![0-9٠-٩])[0-9٠-٩](?:[.,][0-9٠-٩])?\s?(?:\/|من)\s?(?:5|٥|10|١٠)(?![0-9٠-٩])|نجوم/u';
    const MIN_WORDS = 620;     // the report wants 600+; a little margin
    const SEO_FIX_VER = 1;     // bump to make the backfill revisit every managed post

    private static function visible($html) { return html_entity_decode(wp_strip_all_tags(preg_replace('#<[^>]+>#', ' $0 ', (string) $html)), ENT_QUOTES, 'UTF-8'); }
    public static function has_claim($html) { return (bool) preg_match(self::CLAIM_RE, self::visible($html)); }
    /** Plain text: drops every sentence that makes a forbidden claim. */
    public static function strip_claims_text($s) {
        $s = (string) $s;
        if (!preg_match(self::CLAIM_RE, $s)) { return $s; }
        $keep = [];
        foreach (preg_split('/(?<=[.!؟?…؛])\s+/u', $s) as $x) { if (!preg_match(self::CLAIM_RE, $x)) { $keep[] = $x; } }
        return trim(implode(' ', $keep));
    }
    /** HTML: drops the sentences (or the list item / heading) that make a forbidden claim; links, images and markup stay as they are. */
    public static function strip_claims($html) {
        $html = (string) $html;
        if (!self::has_claim($html)) { return $html; }
        $html = preg_replace_callback('#<(p|li|h[1-6]|td|th|dd|dt|blockquote|figcaption)\b([^>]*)>(.*?)</\1>#isu', function ($m) {
            if (!self::has_claim($m[3])) { return $m[0]; }
            if ($m[1][0] === 'h' || $m[1] === 'li' || $m[1] === 'dt') { return ''; }
            $keep = [];
            foreach (preg_split('/(?<=[.!؟?…؛])\s+/u', $m[3]) as $x) { if (!self::has_claim($x)) { $keep[] = $x; } }
            $in = trim(implode(' ', $keep));
            return trim(wp_strip_all_tags($in)) === '' ? '' : '<' . $m[1] . $m[2] . '>' . (function_exists('force_balance_tags') ? force_balance_tags($in) : $in) . '</' . $m[1] . '>';
        }, $html);
        // text outside those blocks (a bare line, a div or a span)
        if (self::has_claim($html)) { $html = preg_replace_callback('#(^|>)([^<>]+)(?=<|$)#u', function ($m) { return $m[1] . (preg_match(self::CLAIM_RE, html_entity_decode($m[2], ENT_QUOTES, 'UTF-8')) ? self::strip_claims_text(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8')) : $m[2]); }, $html); }
        return preg_replace('#<(ul|ol)\b[^>]*>\s*</\1>#iu', '', $html);
    }
    private static function strip_claims_faq($faq) {
        return array_values(array_filter((array) $faq, function ($q) { return is_array($q) && !preg_match(self::CLAIM_RE, (string) ($q[0] ?? '') . ' ' . (string) ($q[1] ?? '')); }));
    }

    /** Same normalisation as the report (Oxygen_Daily_Report::norm), so what we aim at is what gets measured. */
    private static function norm($s) {
        if (class_exists('Oxygen_Daily_Report')) { return Oxygen_Daily_Report::norm($s); }
        $s = html_entity_decode(strip_tags((string) $s), ENT_QUOTES, 'UTF-8');
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
        return trim(mb_strtolower(preg_replace('/[\s\-_]+/u', ' ', $s)));
    }
    private static function seo_words($html) { return count(preg_split('/\s+/u', self::norm($html), -1, PREG_SPLIT_NO_EMPTY)); }
    /** Keyword density in % the way the report computes it. */
    public static function kw_density($html, $kw) {
        $nk = self::norm($kw); $t = self::norm($html); $wc = count(preg_split('/\s+/u', $t, -1, PREG_SPLIT_NO_EMPTY));
        if ($nk === '' || !$wc) { return 0.0; }
        return mb_substr_count($t, $nk) * count(preg_split('/\s+/u', $nk)) / $wc * 100;
    }
    /** sanitize_title outside WordPress too (the test suite loads this class without WordPress) */
    private static function slugify($s) { return function_exists('sanitize_title') ? sanitize_title($s) : strtolower(rawurlencode(str_replace(' ', '-', trim((string) $s)))); }
    /** A keyword is letters, digits and spaces only, so the address (sanitize_title) carries all of it. */
    public static function clean_kw($kw) { return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', (string) $kw))); }

    /** Place wordings the generators put in titles (longest first); [found in title, short form used in the keyword]. */
    private static function place_forms($item) {
        $c = [];
        $add = function ($full, $short = null) use (&$c) { $full = trim((string) $full); if ($full !== '') { $c[$full] = $short !== null ? $short : $full; } };
        $city = (string) ($item['city'] ?? '');
        if (!empty($item['district'])) { $d = $item['district']; $add('حي ' . $d . ' بمكة المكرمة', 'حي ' . $d . ' بمكة'); $add('حي ' . $d . ' بمكة'); $add('حي ' . $d . ' بجدة'); $add('حي ' . $d); }
        if (!empty($item['place'])) { $p = $item['place']; $add('محيط ' . $p . ' بمكة'); $add('محيط ' . $p . ' بجدة'); $add('محيط ' . $p); $add($p); }
        $add($city, str_replace(['مكة المكرمة وجدة', 'بمكة المكرمة'], ['مكة وجدة', 'بمكة'], $city));
        foreach (['مكة المكرمة وجدة' => 'مكة وجدة', 'مكة وجدة' => null, 'مكة المكرمة' => 'مكة', 'مكة' => null, 'جدة' => null] as $f => $s) { $add($f, $s); }
        uksort($c, function ($a, $b) { return mb_strlen($b) <=> mb_strlen($a); });
        return $c;
    }
    /** Service wordings the generators put in titles (longest first). */
    private static function service_forms($item) {
        $sid = (string) ($item['sid'] ?? ''); $svc = self::data('services'); $f = [];
        if (isset($svc[$sid]['n'])) { $f[] = $svc[$sid]['n']; }
        if (class_exists('Oxygen_SEO_Mega')) { $f = array_merge($f, Oxygen_SEO_Mega::variants($sid), [Oxygen_SEO_Mega::short($sid)]); if (!empty($item['brand'])) { $f[] = Oxygen_SEO_Mega::brand_phrase($sid, $item['brand']); } }
        $f = array_values(array_unique(array_filter($f)));
        if (!empty($item['facet']) && mb_strpos((string) $item['facet'], '#') === false) { foreach ($f as $x) { $f[] = $x . ' ' . $item['facet']; } }
        usort($f, function ($a, $b) { return mb_strlen($b) <=> mb_strlen($a); });
        return $f;
    }
    /** Title tidy-up after a phrase was taken out of it. */
    private static function tidy_rest($t) {
        $t = preg_replace('/(^|\s)(في|ب|بـ|لـ|عن|من|على)(?=\s*(?:$|[—|:؟?،!]))/u', '$1', (string) $t);
        $t = preg_replace('/(^|\s)(?:في|على|عن|من)\s+(?=(?:في|على|عن|من|مع)\s)/u', '$1', $t);   // «خصومات حصرية على مع …» → «… مع …»
        $t = preg_replace('/\s+([؟?:،!])/u', '$1', $t);
        $t = preg_replace('/«\s*»|\(\s*\)/u', '', $t);
        $t = preg_replace('/\s{2,}/u', ' ', $t);
        $t = preg_replace('/^[\s—|:،\-]+|[\s—|:،\-]+$/u', '', $t);
        $t = preg_replace('/\s*([—|])\s*(?:[—|]\s*)+/u', ' $1 ', $t);
        return trim($t);
    }
    /** Title within 20-65 characters, never cutting into the keyword at its start. */
    private static function fit_title($t, $kw, $suffix) {
        $t = trim($t); $kl = mb_strlen($kw);
        while (mb_strlen($t) > 65) {
            $head = mb_substr($t, 0, 66); $cut = 0;
            foreach ([' — ', ' | ', ': ', '، ', '؟ '] as $sep) { $p = mb_strrpos($head, $sep); if ($p !== false && $p > $kl && $p > $cut) { $cut = $p; } }
            if (!$cut) { $p = mb_strrpos($head, ' '); if ($p !== false && $p >= $kl) { $cut = $p; } }
            if (!$cut) { break; }
            $t = self::tidy_rest(mb_substr($t, 0, $cut) . (mb_substr($t, $cut, 2) === '؟ ' ? '؟' : ''));
        }
        if ($suffix !== '' && mb_strlen($t . $suffix) <= 65) { $t .= $suffix; }
        return $t;
    }

    /**
     * The article as it is published: focus keyword = what the article is about (service + place for a service page, the topic for a
     * guide), title starting with that keyword. The key, the angle and the body builders are unchanged. Idempotent.
     */
    public static function seo_item($item) {
        if (!is_array($item) || !empty($item['seo_v'])) { return $item; }
        $item['seo_v'] = 1;
        $brand = self::brand();
        $title = trim((string) ($item['title'] ?? ''));
        $suffix = '';
        if ($brand !== '' && preg_match('/\s*\|\s*' . preg_quote($brand, '/') . '\s*$/u', $title, $m)) { $suffix = ' | ' . $brand; $title = trim(mb_substr($title, 0, mb_strlen($title) - mb_strlen($m[0]))); }
        $old = self::clean_kw($item['kw'] ?? '');
        $kw = ''; $rest = null;
        if (($item['track'] ?? '') === 'commercial') {
            $sv = ''; $pl = ''; $short = '';
            foreach (self::service_forms($item) as $f) { if (mb_strpos($title, $f) !== false) { $sv = $f; break; } }
            foreach (self::place_forms($item) as $f => $s) { if (mb_strpos($sv !== '' ? str_replace($sv, '', $title) : $title, $f) !== false) { $pl = $f; $short = $s; break; } }
            if ($sv !== '' && $pl !== '') {
                // the whole keyword has to fit in the address (kw_slug keeps 185 encoded characters): shorter wordings, best first
                $svs = [$sv]; if (!empty($item['facet']) && mb_substr($sv, -mb_strlen(' ' . $item['facet'])) === ' ' . $item['facet']) { $svs[] = mb_substr($sv, 0, mb_strlen($sv) - mb_strlen(' ' . $item['facet'])); }
                $pls = [$short]; if (!empty($item['district'])) { $pls[] = 'حي ' . $item['district']; } if (!empty($item['place'])) { $pls[] = $item['place']; } $pls[] = mb_strpos($short, 'جدة') !== false && mb_strpos($short, 'مكة') === false ? 'جدة' : 'مكة';
                foreach (array_unique($pls) as $b2) { foreach ($svs as $a2) { foreach ([' في ', ' '] as $j) { $c = self::clean_kw($a2 . $j . $b2); if (strlen(self::slugify($c)) <= 185) { $kw = $c; break 3; } } } }
                if ($kw === '') { $kw = self::clean_kw($sv . ' في ' . $short); }
                $r = $title;
                $p = mb_strpos($r, $sv); $r = mb_substr($r, 0, $p) . ' ' . mb_substr($r, $p + mb_strlen($sv));
                $p = mb_strpos($r, $pl);
                if ($p !== false) {
                    $pre = mb_substr($r, 0, $p); $post = mb_substr($r, $p + mb_strlen($pl));
                    $pre = preg_replace('/(?:\s+في\s*|بـ|ب)$/u', '', $pre);
                    $r = $pre . ' ' . $post;
                }
                $rest = self::tidy_rest(preg_replace('/^\s*[^\s—|:،؟]+\s*[—|:]\s*/u', '', self::tidy_rest($r)));   // a one-word stub left before a separator («رواد — …»)
            }
        }
        if ($kw === '') {
            if ($old !== '' && mb_strpos(self::norm($title), self::norm($old)) === 0) { $kw = $old; }
            elseif ($old !== '' && ($item['track'] ?? '') !== 'commercial') { $kw = $old; $rest = $title; }   // a guide: «topic: the original question»
            else { $kw = self::clean_kw(preg_split('/\s*(?:—|\||:|؟|\?)\s*/u', $title)[0]); $rest = null; }
        }
        if ($kw !== '' && mb_strpos(self::norm($title), self::norm($kw)) !== 0) {
            $title = $kw . ($rest !== null && count(preg_split('/\s+/u', $rest, -1, PREG_SPLIT_NO_EMPTY)) >= 2 ? (preg_match('/^[—|:]/u', $rest) ? ' ' : (($item['track'] ?? '') === 'commercial' ? ' — ' : ': ')) . $rest : '');
        }
        $item['title'] = self::fit_title($title, $kw, $suffix);
        $item['kw'] = $kw !== '' ? $kw : $old;
        return $item;
    }

    /** The address carries the whole keyword (Arabic, like the title) + 4 characters so two sites never share it. */
    public static function kw_slug($item) {
        $kw = self::clean_kw($item['kw'] ?? '');
        $tail = '-' . substr(md5((string) $item['key'] . home_url()), 0, 4);
        if ($kw === '') { return (string) $item['key']; }
        // WordPress keeps 200 encoded characters (an Arabic letter takes 6) and Oxygen_Domain_Guard may add «-dN»: stay well under it, cut on a word.
        $w = preg_split('/\s+/u', $kw); $s = '';
        while ($w) { $s = self::slugify(implode(' ', $w)); if (strlen($s) <= 185) { break; } array_pop($w); }
        return ($s !== '' ? $s : (string) $item['key']) . $tail;
    }
    /** A sub-heading with the exact keyword right after the opening paragraph, when the article has none. */
    public static function kw_heading($html, $kw) {
        $html = (string) $html;
        if ($kw === '') { return $html; }
        if (preg_match_all('#<h[23][^>]*>(.*?)</h[23]>#isu', $html, $m) && mb_strpos(self::norm(implode(' ', $m[1])), self::norm($kw)) !== false) { return $html; }
        $h = '<h2>' . esc_html($kw) . ': كل ما تحتاج معرفته</h2>';
        $at = self::after_intro($html);
        return $at === null ? $h . $html : substr($html, 0, $at) . $h . substr($html, $at);
    }
    /** byte offset right after the first paragraph that has words (the cover image paragraph does not count) */
    private static function after_intro($html) {
        if (preg_match_all('#<p\b[^>]*>(.*?)</p>#isu', $html, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $i => $x) { if (trim(wp_strip_all_tags($m[1][$i][0])) !== '') { return $x[1] + strlen($x[0]); } }
        }
        return null;
    }
    /** The keyword inside the first 10% of the text (an AI rewrite may open without it). */
    public static function kw_lead($html, $kw) {
        $html = (string) $html;
        if ($kw === '') { return $html; }
        $words = preg_split('/\s+/u', self::norm($html), -1, PREG_SPLIT_NO_EMPTY);
        if (mb_strpos(implode(' ', array_slice($words, 0, max(1, (int) ceil(count($words) * 0.1)))), self::norm($kw)) !== false) { return $html; }
        $p = '<p>تبحث عن <strong>' . esc_html($kw) . '</strong>؟ هنا خلاصة عملية تساعدك على فهم الحالة واتخاذ القرار المناسب قبل طلب الخدمة.</p>';
        // after a leading cover image, before everything else
        if (preg_match('#^\s*(?:<p>\s*<img[^>]*>\s*</p>\s*)+#u', $html, $m)) { return $m[0] . $p . substr($html, strlen($m[0])); }
        return $p . $html;
    }
    /**
     * Brings a short article to full length with the practical sections the engine already has (the domain's explanatory paragraphs,
     * the check / mistakes / follow-up lists, more scenario angles). Nothing new is claimed; it stops once the article is 600+ words
     * and the keyword density is in range. The sections go before the order steps / FAQ so the article still ends with them.
     */
    public static function extend($html, $item, $rev = 0) {
        $html = (string) $html; $kw = (string) ($item['kw'] ?? '');
        $need = function ($h) use ($kw) { return self::seo_words($h) < self::MIN_WORDS || ($kw !== '' && self::kw_density($h, $kw) > 2.4 && self::seo_words($h) < 1100); };
        if (!$need($html)) { return $html; }
        $dm = self::domain($item['dom'] ?? 'general');
        $svc = self::data('services'); $n = isset($svc[$item['sid'] ?? '']['n']) ? $svc[$item['sid']]['n'] : $dm['label'];
        $r = ['t' => $n, 'kw' => $n, 'd' => $dm['label'], 'n' => $n];   // the added text names the service, not the full keyword (density)
        $has = function ($heading) use (&$html) { return mb_strpos($html, '<h2>' . self::e($heading) . '</h2>') !== false; };
        $blocks = [];
        $left = array_values(array_filter($dm['paras'], function ($p) use ($html) { return mb_strpos($html, self::e($p)) === false; }));
        if ($left && !$has('معلومات مفيدة عن ' . $dm['label'])) { $b = '<h2>معلومات مفيدة عن ' . self::e($dm['label']) . '</h2>'; foreach ($left as $p) { $b .= '<p>' . self::e($p) . '</p>'; } $blocks[] = $b; }
        $lists = [];
        foreach ([['خطوات الفحص الأولي', self::checks_html()], ['أخطاء يجب تجنبها', self::mistakes_html()], ['جدول متابعة مقترح', self::schedule_html()]] as $x) { if (!$has($x[0])) { $lists[] = $x[1]; } }
        $k = $lists ? ((int) ($item['idx'] ?? 0) + self::salt()) % count($lists) : 0;
        $blocks = array_merge($blocks, array_slice($lists, $k), array_slice($lists, 0, $k));
        if (!$has('متى تحتاج إلى فني مختص؟')) { $blocks[] = self::call_html($dm); }
        $angles = self::data('angles'); $base = self::angle_index((int) ($item['idx'] ?? 0), (int) $rev);
        for ($j = 1; $j < count($angles); $j++) {
            $a = $angles[($base + $j) % count($angles)];
            if ($has($a[2])) { continue; }
            $b = '<h2>' . self::e($a[2]) . '</h2><p>' . self::e(self::sub($a[1], $r)) . '</p>';
            foreach ($a[3] as $p) { $b .= '<p>' . self::e(self::sub($p, $r)) . '</p>'; }
            $blocks[] = $b;
        }
        $add = '';
        foreach ($blocks as $b) { $b = self::strip_claims($b); if (!$need($html . $add)) { break; } $add .= $b; }
        if ($add === '') { return $html; }
        foreach (['<h2>خطوات الطلب</h2>', '<h2>هل تحتاج مساعدة مباشرة؟</h2>', '<h2>أسئلة شائعة</h2>', '<h2>مقالات ذات صلة</h2>'] as $mark) {
            $p = strpos($html, $mark); if ($p !== false) { return substr($html, 0, $p) . $add . substr($html, $p); }
        }
        return $html . $add;
    }
    /** Every rule above on one built article. $b = build() output. Returns ['item', 'b', 'slug']. */
    public static function prepare($item, $b, $rev = 0) {
        $item = self::seo_item($item);
        $kw = (string) $item['kw'];
        $html = self::strip_claims((string) ($b['html'] ?? ''));
        $html = self::kw_lead($html, $kw);
        $html = self::kw_heading($html, $kw);
        $html = self::extend($html, $item, $rev);
        $b['html'] = $html;
        $b['text'] = strip_tags($html);
        $b['faq'] = self::strip_claims_faq($b['faq'] ?? []);
        $desc = self::strip_claims_text((string) ($b['desc'] ?? ''));
        if ($kw !== '' && (mb_strpos(self::norm($desc), self::norm($kw)) === false || mb_strlen($desc) < 100 || mb_strlen($desc) > 160)) { $desc = self::desc_for($item); }
        $b['desc'] = $desc;
        return ['item' => $item, 'b' => $b, 'slug' => self::kw_slug($item)];
    }
    private static function seo_meta($id, $kw, $desc) {
        update_post_meta($id, '_yoast_wpseo_focuskw', $kw); update_post_meta($id, '_yoast_wpseo_metadesc', $desc);
        update_post_meta($id, 'rank_math_focus_keyword', $kw); update_post_meta($id, 'rank_math_description', $desc);
    }

    /**
     * Repairs one already published managed article in place (engine articles and the older `_oxygen_ac_managed` ones): focus keyword,
     * meta description, keyword sub-heading / opening, full length, forbidden claims. The address (post_name) is never changed:
     * those URLs are already indexed. Returns true when something was written.
     */
    public static function fix_post($id) {
        $p = get_post($id);
        if (!$p || $p->post_type !== 'post') { return false; }
        $item = self::item_of_post($id);
        $html = (string) $p->post_content; $title = (string) $p->post_title;
        if ($item) {
            $item = self::seo_item($item);
            $kw = (string) $item['kw'];
            $title = $item['title'];
        } else {
            $kw = self::clean_kw(get_post_meta($id, '_oxy_seo_kw', true) ?: (get_post_meta($id, '_oxygen_ac_focus_keyword', true) ?: get_post_meta($id, 'rank_math_focus_keyword', true)));
        }
        $html = self::strip_claims($html);
        if ($kw !== '') { $html = self::kw_lead($html, $kw); $html = self::kw_heading($html, $kw); }
        if ($item) { $html = self::extend($html, $item, (int) get_post_meta($id, '_oxy_seo_rev', true)); }
        if ($item) { $desc = self::desc_for($item); }
        else {
            $sent = preg_split('/(?<=[.!؟?])\s+/u', trim(preg_replace('/\s+/u', ' ', self::strip_claims_text(self::visible($html)))));
            $desc = self::compose_desc($kw, array_slice($sent, 0, 3), ' اطلب فنياً الآن.');
        }
        $upd = ['ID' => $id];
        if ($html !== $p->post_content) { $upd['post_content'] = $html; }
        if ($title !== $p->post_title) { $upd['post_title'] = $title; }
        if ($desc !== $p->post_excerpt) { $upd['post_excerpt'] = $desc; }
        if (count($upd) > 1) { $ok = wp_update_post($upd, true); if (is_wp_error($ok) || !$ok) { return false; } }
        if ($kw !== '') { self::seo_meta($id, $kw, $desc); }
        if (get_post_meta($id, '_oxy_seo_key', true)) {
            if ($kw !== '') { update_post_meta($id, '_oxy_seo_kw', $kw); }
            update_post_meta($id, '_oxy_seo_desc', $desc);
            $faq = json_decode((string) get_post_meta($id, '_oxy_seo_faq', true), true);
            if (is_array($faq)) { update_post_meta($id, '_oxy_seo_faq', wp_json_encode(self::strip_claims_faq($faq), JSON_UNESCAPED_UNICODE)); }
        }
        return count($upd) > 1;
    }
    /** Hourly backfill: managed posts not yet repaired by this version, newest first, 20 per run. */
    public static function seo_backfill($limit = 20) {
        $ids = get_posts(['post_type' => 'post', 'post_status' => ['publish', 'draft', 'pending', 'future'], 'numberposts' => (int) $limit, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids', 'no_found_rows' => true,
            'meta_query' => ['relation' => 'AND',
                ['relation' => 'OR', ['key' => '_oxy_seo_key', 'compare' => 'EXISTS'], ['key' => '_oxygen_ac_managed', 'compare' => 'EXISTS']],
                ['relation' => 'OR', ['key' => '_oxy_seo_fix', 'compare' => 'NOT EXISTS'], ['key' => '_oxy_seo_fix', 'value' => self::SEO_FIX_VER, 'compare' => '<', 'type' => 'NUMERIC']]]]);
        $n = 0;
        foreach ($ids as $id) {
            try { if (self::fix_post((int) $id)) { $n++; } } catch (Throwable $e) { /* one bad post never stops the batch */ }
            update_post_meta((int) $id, '_oxy_seo_fix', self::SEO_FIX_VER);
        }
        return $n;
    }

    public static function publish_item($item, $kind, $force_status, $map) {
        $s = self::settings();
        $item = self::seo_item($item);
        $ctx = self::ctx($item, $map);
        $b = self::prepare($item, self::build($item, 0, $ctx))['b'];
        // one article, one place on the web: too close to anything of the network → AI rewrite, else held as a draft
        $g = class_exists('Oxygen_Uniq') ? Oxygen_Uniq::gate($item, $b) : null;
        if ($g) { $b['html'] = $g['html']; if ($g['status']) { $force_status = $g['status']; } }
        if (!empty($g['rewritten'])) { $b = self::prepare($item, $b)['b']; }   // the AI's text gets the same rules
        $author = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        $id = wp_insert_post([
            'post_type' => 'post', 'post_status' => $force_status ?: $s['status'], 'post_title' => $item['title'], 'post_name' => self::kw_slug($item),
            'post_content' => $b['html'], 'post_excerpt' => $b['desc'], 'post_author' => $author ? (int) $author[0] : 1,
        ], true);
        if (is_wp_error($id) || !$id) { return false; }
        self::$cache['map'][$item['key']] = (int) $id;
        update_post_meta($id, '_oxy_seo_key', $item['key']);
        update_post_meta($id, '_oxy_seo_track', $item['track']);
        update_post_meta($id, '_oxy_seo_kind', $kind);
        update_post_meta($id, '_oxy_seo_kw', $item['kw']);
        update_post_meta($id, '_oxy_seo_rev', 0);
        update_post_meta($id, '_oxy_seo_angle', self::angle_label($item['idx'], 0));
        update_post_meta($id, '_oxy_seo_updated', current_time('mysql'));
        update_post_meta($id, '_oxy_seo_desc', $b['desc']);
        update_post_meta($id, '_oxy_seo_faq', wp_json_encode($b['faq'], JSON_UNESCAPED_UNICODE));
        update_post_meta($id, '_oxy_seo_img', $ctx['img']);
        update_post_meta($id, '_oxy_seo_fix', self::SEO_FIX_VER);   // already written to the current rules: the backfill can skip it
        // Real WordPress post data: tags from the keyword system + compatibility with Yoast / Rank Math so editors see the same focus keyword.
        self::tag_post($id, $item, $b);
        $cat = self::term($item['track'] === 'commercial' ? 'خدمات ' . self::brand() . '' : 'أدلة وإرشادات');
        if ($cat) { wp_set_post_categories($id, [$cat]); }
        self::log($kind === 'seo' ? 'new_seo' : ($kind === 'district' ? 'new_dist' : 'new'), $id, $item['title'], ['track' => $item['track'], 'st' => get_post_status($id), 'rev' => 0, 'kind' => $kind]);
        if ($g && class_exists('Oxygen_Uniq')) { Oxygen_Uniq::after($id, $g); }
        if (get_post_status($id) === 'publish') { self::indexnow([get_permalink($id), home_url('/oxygen-seo-archive/')]); }
        return $id;
    }
    /** Plan items + the keyword-universe items that already have a post (so they can be refreshed). */
    private static function by_key($map) {
        $by = [];
        foreach (self::plan() as $it) { $by[$it['key']] = $it; }
        foreach ($map as $k => $id) { if (!isset($by[$k]) && Oxygen_SEO_Mega::is_key($k)) { $m = Oxygen_SEO_Mega::item_by_key($k); if ($m) { $by[$k] = $m; } } }
        return $by;
    }
    public static function refresh_next($ignore_age = false) {
        $map = self::map();
        $s = self::settings();
        $by_key = self::by_key($map);
        $cand = [];
        $min = time() - max(0, (int) $s['min_age_days']) * DAY_IN_SECONDS;
        foreach ($map as $key => $id) {
            if (!isset($by_key[$key])) { continue; }
            $upd = strtotime((string) get_post_meta($id, '_oxy_seo_updated', true)) ?: 0;
            if (!$ignore_age && $upd > $min) { continue; }
            $cand[] = [(int) get_post_meta($id, '_oxy_seo_rev', true), $upd, $id, $key];
        }
        if (!$cand) { return false; }
        usort($cand, function ($a, $b) { return $a[0] <=> $b[0] ?: $a[1] <=> $b[1]; });
        return self::do_refresh($cand[0][2], $by_key[$cand[0][3]], $map, 'refresh');
    }
    /** Random refresh of an already published article (older than ~20 hours), so old pages keep getting fresh angles. */
    public static function refresh_random() {
        $map = self::map();
        $by_key = self::by_key($map);
        $pool = [];
        foreach ($map as $key => $id) {
            if (!isset($by_key[$key]) || get_post_status($id) !== 'publish') { continue; }
            $upd = strtotime((string) get_post_meta($id, '_oxy_seo_updated', true)) ?: 0;
            if ($upd > time() - 20 * HOUR_IN_SECONDS) { continue; }
            $pool[] = [$id, $key];
        }
        if (!$pool) { return false; }
        $pick = $pool[array_rand($pool)];
        return self::do_refresh($pick[0], $by_key[$pick[1]], $map, 'refresh_rand');
    }
    /** Keyword system: tags + focus keyword/description for Yoast and Rank Math (so the posts behave like hand-written WordPress posts). */
    /** One category per service (+ «أدلة إرشادية» for official guides) so the site is organised and crawlable by topic. */
    /** Search keywords for every specialty: service, service + each city, service + «أفضل/أرخص/عاجل» intents. */
    public static function all_keywords() {
        $out = [];
        foreach ((array) self::data('services') as $sv) {
            $n = $sv['n'] ?? ''; if ($n === '') { continue; }
            foreach ([$n, $n . ' مكة', $n . ' جدة', $n . ' في مكة المكرمة', $n . ' في جدة', 'افضل ' . $n, $n . ' عاجل', $n . ' قريب مني', 'شركة ' . $n, 'فني ' . $n, 'اسعار ' . $n] as $k) { $out[] = $k; }
        }
        return array_values(array_unique($out));
    }
    private static function categorize($id, $name, $track) {
        $names = array_values(array_filter([$name, $track === 'official' ? 'أدلة إرشادية' : '']));
        if (!$names) { return; }
        $ids = [];
        foreach ($names as $n) {
            $t = term_exists($n, 'category');
            if (!$t) { $t = wp_insert_term($n, 'category'); }
            if (!is_wp_error($t) && !empty($t['term_id'])) { $ids[] = (int) $t['term_id']; }
        }
        if ($ids) { wp_set_post_categories($id, $ids, false); }
    }
    /** Backfill: classify engine posts that still sit in the default category (30 per run). */
    public static function classify_batch() {
        $svcs = self::data('services');
        $q = get_posts(['numberposts' => 30, 'post_status' => 'any', 'meta_key' => '_oxy_seo_key', 'category__in' => [(int) get_option('default_category')], 'fields' => 'ids']);
        foreach ($q as $id) {
            $key = (string) get_post_meta($id, '_oxy_seo_key', true);
            $name = ''; $track = (strpos($key, 'oxy-guide-') === 0) ? 'official' : 'commercial';
            foreach ((array) $svcs as $sid => $sv) { if ($sid !== '' && strpos($key, 'oxy-' . $sid . '-') === 0) { $name = $sv['n']; break; } }
            if (!$name) { $name = 'الصيانة العامة'; }
            self::categorize($id, $name, $track);
        }
    }
    private static function tag_post($id, $item, $b) {
        $svcs = self::data('services');
        if (!empty($item['mega'])) {
            $tags = [Oxygen_SEO_Mega::short($item['sid']), $item['district'] ?? ($item['place'] ?? ($item['brand'] ?? '')), $item['city'], self::brand()];
            wp_set_post_tags($id, array_values(array_unique(array_filter($tags))), false);
            self::categorize($id, isset($svcs[$item['sid'] ?? '']) ? $svcs[$item['sid']]['n'] : '', $item['track'] ?? '');
            update_post_meta($id, '_yoast_wpseo_focuskw', $item['kw']); update_post_meta($id, '_yoast_wpseo_metadesc', $b['desc']);
            update_post_meta($id, 'rank_math_focus_keyword', $item['kw']); update_post_meta($id, 'rank_math_description', $b['desc']);
            return;
        }
        $tags = [$item['kw'], self::brand(), $item['track'] === 'commercial' ? ($item['city'] ?? '') : 'دليل إرشادي', isset($svcs[$item['sid'] ?? '']) ? $svcs[$item['sid']]['n'] : ''];
        wp_set_post_tags($id, array_values(array_unique(array_filter($tags))), false);
        self::categorize($id, isset($svcs[$item['sid'] ?? '']) ? $svcs[$item['sid']]['n'] : '', $item['track'] ?? '');
        update_post_meta($id, '_yoast_wpseo_focuskw', $item['kw']); update_post_meta($id, '_yoast_wpseo_metadesc', $b['desc']);
        update_post_meta($id, 'rank_math_focus_keyword', $item['kw']); update_post_meta($id, 'rank_math_description', $b['desc']);
    }
    private static function do_refresh($id, $item, $map, $action) {
        $rev = (int) get_post_meta($id, '_oxy_seo_rev', true) + 1;
        // an AI-rewritten article keeps its own text; a copy taken out of the index is not worth refreshing
        if (get_post_meta($id, '_oxy_rewritten', true) || get_post_meta($id, '_oxy_dup', true) !== '') { update_post_meta($id, '_oxy_seo_updated', current_time('mysql')); return false; }
        $item = self::seo_item($item);
        $ctx = self::ctx($item, $map);
        $b = self::prepare($item, self::build($item, $rev, $ctx), $rev)['b'];
        // a refresh must not turn a unique article back into the template text other sites carry
        if (class_exists('Oxygen_Uniq') && class_exists('Mutqan_Fp')) {
            $chk = Mutqan_Fp::call('check', ['text' => $b['html'], 'url' => (string) get_permalink($id)]);
            if (is_array($chk) && (float) ($chk['sim'] ?? 0) >= Oxygen_Uniq::GATE) { update_post_meta($id, '_oxy_seo_updated', current_time('mysql')); return false; }
        }
        wp_update_post(['ID' => $id, 'post_title' => $item['title'], 'post_content' => $b['html'], 'post_excerpt' => $b['desc']]);
        update_post_meta($id, '_oxy_seo_rev', $rev);
        update_post_meta($id, '_oxy_seo_kw', $item['kw']);
        update_post_meta($id, '_oxy_seo_fix', self::SEO_FIX_VER);
        self::tag_post($id, $item, $b);
        update_post_meta($id, '_oxy_seo_angle', self::angle_label($item['idx'], $rev));
        update_post_meta($id, '_oxy_seo_updated', current_time('mysql'));
        update_post_meta($id, '_oxy_seo_desc', $b['desc']);
        update_post_meta($id, '_oxy_seo_faq', wp_json_encode($b['faq'], JSON_UNESCAPED_UNICODE));
        update_post_meta($id, '_oxy_seo_img', $ctx['img']);
        if (get_post_status($id) === 'publish') { self::indexnow([get_permalink($id)]); }
        self::log($action, $id, $item['title'], ['track' => $item['track'], 'st' => get_post_status($id), 'rev' => $rev, 'angle' => self::angle_label($item['idx'], $rev)]);
        return $id;
    }
    /** Runs hourly (and on traffic): five streams, each paced evenly over the day, up to 3 catch-up actions per stream per run. */
    public static function tick() {
        if (!self::active()) { return; }
        try { self::classify_batch(); } catch (Throwable $e) { /* optional */ }
        try { self::seo_backfill(20); } catch (Throwable $e) { /* optional: repairs older posts, 20 an hour */ }
        self::ensure_hub();
        if (class_exists('Oxygen_Network') && Oxygen_Network::is_satellite()) { if (class_exists('Oxygen_Network_SEO')) { Oxygen_Network_SEO::tick(); } return; }
        $s = self::settings();
        $hour = (int) wp_date('G');
        $streams = [
            ['new', (int) $s['new_per_day'], function () { return self::create_next(null, 'base'); }],
            ['new_seo', (int) $s['seo_per_day'], function () { return self::create_next(null, 'seo'); }],
            ['new_dist', (int) $s['district_per_day'], function () { return self::create_next(null, 'district'); }],
            ['refresh_rand', (int) $s['random_per_day'], function () { return self::refresh_random(); }],
            ['refresh', (int) $s['refresh_per_day'], function () { return self::refresh_next(); }],
        ];
        foreach ($streams as $st) {
            list($act, $quota, $fn) = $st;
            $allowed = min($quota, (int) ceil($quota * ($hour + 1) / 24));
            for ($i = 0; $i < 6 && self::today_count($act) < $allowed; $i++) { if (!$fn()) { break; } }
        }
    }

    /* ---------- front end ---------- */
    private static function meta_for($pid) { return get_post_meta($pid, '_oxy_seo_key', true) ? $pid : 0; }
    public static function filter_content($c) {
        if (!is_singular('post') || !in_the_loop() || !is_main_query()) { return $c; }
        $id = get_the_ID();
        if (!self::meta_for($id)) { return $c; }
        $main = esc_url(Oxygen_Domain_Guard::main_url());
        $mh = preg_replace('#^www\.#', '', (string) wp_parse_url($main, PHP_URL_HOST));
        $top = '<p class="oxy-mainsite" style="background:#0ea5e9;color:#fff;border-radius:10px;padding:8px 12px;font-weight:700;text-align:center;margin:0 0 14px"><a href="' . $main . '" style="color:#fff;text-decoration:none">← الموقع الرئيسي ل' . self::brand() . ' · ' . esc_html($mh) . '</a></p>';
        $bottom = '<div class="oxy-mainsite-cta" style="margin-top:22px;border:1px solid #bae6fd;background:#f0f9ff;border-radius:12px;padding:12px 14px;text-align:center"><strong>خدمات الصيانة والتبريد</strong><br><a href="' . $main . '" style="font-weight:700">زيارة الموقع الرئيسي ل' . self::brand() . ' (' . esc_html($mh) . ') للخدمات والحجز</a></div>';
        $rev = (int) get_post_meta($id, '_oxy_seo_rev', true);
        $upd = '';
        if ($rev >= 1) {
            $d = get_post_meta($id, '_oxy_seo_updated', true);
            $ts = strtotime((string) $d) ?: time();
            $upd = '<p class="oxy-seo-updated" style="background:#ecfdf5;border:1px solid #10b981;border-radius:10px;padding:8px 12px;font-weight:700">✅ تم التحديث بتاريخ ' . esc_html(wp_date('j F Y', $ts)) . '</p>';
        }
        return $top . $upd . $c . $bottom;
    }
    public static function site_icon_url($url, $size) {
        $size = (int) $size; $pick = 512;
        foreach ([48, 96, 192, 512] as $n) { if ($n >= $size) { $pick = $n; break; } }
        return plugins_url('assets/site-icon-' . $pick . '.png', dirname(__FILE__));
    }
    /** Google Search Console ownership (HTML tag method). The token is a public verification id, not a secret; more can be added in the
     *  option oxygen_gsc_tokens (array or comma separated). Printed on the home page, which is where Google looks. */
    public static function gsc_meta_html() {
        $t = ['YHUq91C4uLadSWxWGw0Y4mVJ5Tw2c9IriStGTt1GVcE'];
        $o = get_option('oxygen_gsc_tokens', []);
        if (is_string($o)) { $o = explode(',', $o); }
        $h = '';
        foreach (array_merge($t, (array) $o) as $x) {
            $x = trim((string) $x);
            if ($x !== '' && preg_match('/^[A-Za-z0-9_-]{20,80}$/', $x)) { $h .= '<meta name="google-site-verification" content="' . esc_attr($x) . '" />' . "\n"; }
        }
        return $h;
    }
    public static function gsc_meta() {
        if (!is_front_page() && !is_home()) { return; }
        echo self::gsc_meta_html();
    }
    public static function head() {
        if (!is_singular('post') || defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION')) { return; }
        $id = get_queried_object_id();
        if (!self::meta_for($id)) { return; }
        $desc = (string) get_post_meta($id, '_oxy_seo_desc', true);
        $img = (string) get_post_meta($id, '_oxy_seo_img', true);
        $url = get_permalink($id);
        $title = get_the_title($id);
        echo "\n<meta name=\"description\" content=\"" . esc_attr($desc) . "\">\n";
        echo '<meta property="og:type" content="article"><meta property="og:title" content="' . esc_attr($title) . '"><meta property="og:description" content="' . esc_attr($desc) . '"><meta property="og:url" content="' . esc_url($url) . '"><meta property="og:locale" content="ar_AR">' . "\n";
        if ($img) { echo '<meta property="og:image" content="' . esc_url($img) . '"><meta name="twitter:card" content="summary_large_image">' . "\n"; }
        $faq = json_decode((string) get_post_meta($id, '_oxy_seo_faq', true), true) ?: [];
        $graph = [[
            '@type' => 'Article', 'headline' => $title, 'description' => $desc, 'inLanguage' => 'ar', 'mainEntityOfPage' => $url,
            'datePublished' => get_post_time('c', true, $id), 'dateModified' => get_post_modified_time('c', true, $id),
            'author' => ['@type' => 'Organization', 'name' => self::brand()], 'publisher' => ['@type' => 'Organization', 'name' => self::brand()],
        ] + ($img ? ['image' => $img] : []),
        ['@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'الرئيسية', 'item' => home_url('/')], ['@type' => 'ListItem', 'position' => 2, 'name' => $title, 'item' => $url]]]];
        if ($faq) {
            $ents = [];
            foreach ($faq as $q) { $ents[] = ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]]; }
            $graph[] = ['@type' => 'FAQPage', 'mainEntity' => $ents];
        }
        echo '<script type="application/ld+json">' . wp_json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";
    }
    public static function archive_shortcode() {
        $q = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'meta_key' => '_oxy_seo_key', 'numberposts' => 200, 'orderby' => 'modified', 'order' => 'DESC']);
        if (!$q) { return '<p>لا توجد مقالات بعد.</p>'; }
        $g = [];
        foreach ($q as $p) { $g[wp_date('F Y', get_post_timestamp($p))][] = $p; }
        $h = '<div class="oxy-seo-archive">';
        foreach ($g as $m => $ps) {
            $h .= '<h2>' . esc_html($m) . '</h2><ul>';
            foreach ($ps as $p) {
                $rev = (int) get_post_meta($p->ID, '_oxy_seo_rev', true);
                $h .= '<li><a href="' . esc_url(get_permalink($p)) . '">' . esc_html(get_the_title($p)) . '</a>' . ($rev ? ' <small>(تم التحديث)</small>' : '') . '</li>';
            }
            $h .= '</ul>';
        }
        return $h . '</div>';
    }

    /* ---------- admin ---------- */
    public static function menu() {
        add_menu_page('أرشفة ومقالات SEO', 'مقالات وأرشفة SEO', 'manage_options', 'oxygen-seo', [__CLASS__, 'page'], 'dashicons-chart-line', 58);
    }
    private static function guard($nonce) { if (!current_user_can('manage_options')) { wp_die('غير مصرح.'); } check_admin_referer($nonce); }
    private static function back($msg) { wp_safe_redirect(add_query_arg('oxy_msg', rawurlencode($msg), admin_url('admin.php?page=oxygen-seo'))); exit; }
    public static function h_save() {
        self::guard('oxy_seo_save');
        $s = self::settings();
        $s['enabled'] = !empty($_POST['enabled']) ? 1 : 0;
        $s['status'] = (($_POST['status'] ?? '') === 'draft') ? 'draft' : 'publish';
        $s['new_per_day'] = max(0, min(120, (int) ($_POST['new_per_day'] ?? 10)));
        $s['seo_per_day'] = max(0, min(120, (int) ($_POST['seo_per_day'] ?? 10)));
        $s['district_per_day'] = max(0, min(120, (int) ($_POST['district_per_day'] ?? 10)));
        $s['random_per_day'] = max(0, min(120, (int) ($_POST['random_per_day'] ?? 10)));
        $s['refresh_per_day'] = max(0, min(120, (int) ($_POST['refresh_per_day'] ?? 4)));
        $s['min_age_days'] = max(0, min(365, (int) ($_POST['min_age_days'] ?? 7)));
        $s['wa'] = preg_replace('/\D/', '', (string) ($_POST['wa'] ?? ''));
        update_option(self::OPT, $s, false);
        self::back('تم حفظ الإعدادات.');
    }
    public static function h_run() {
        self::guard('oxy_seo_run');
        $w = sanitize_key($_POST['what'] ?? 'new');
        if ($w === 'refresh') { $id = self::refresh_next(true); self::back($id ? 'تم تحديث مقال.' : 'لا توجد مقالات للتحديث بعد.'); }
        $n = max(1, min(10, (int) ($_POST['count'] ?? 1)));
        $done = 0;
        for ($i = 0; $i < $n; $i++) { if (self::create_next()) { $done++; } else { break; } }
        self::back($done ? "تم إنشاء $done مقال." : 'اكتملت الخطة (كل المقالات منشورة).');
    }
    public static function h_publish_drafts() {
        self::guard('oxy_seo_pub');
        $ids = get_posts(['post_type' => 'post', 'post_status' => 'draft', 'meta_key' => '_oxy_seo_key', 'numberposts' => -1, 'fields' => 'ids']);
        $ids = array_values(array_filter($ids, function ($i) { return get_post_meta($i, '_oxy_held_dup', true) === ''; }));   // drafts held as copies stay drafts
        foreach ($ids as $id) { wp_update_post(['ID' => $id, 'post_status' => 'publish']); self::log('publish', $id, get_the_title($id), ['st' => 'publish']); }
        self::indexnow(array_map('get_permalink', $ids));
        self::back('تم نشر ' . count($ids) . ' مسودة.');
    }
    public static function h_archive_page() {
        self::guard('oxy_seo_page');
        $p = get_page_by_path('oxygen-seo-archive');
        if (!$p) { wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'أرشيف المقالات', 'post_name' => 'oxygen-seo-archive', 'post_content' => '[oxygen_seo_archive]']); }
        self::back('صفحة الأرشيف جاهزة: /oxygen-seo-archive/');
    }
    private static function days_ar() { return ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت']; }
    public static function page() {
        if (!current_user_can('manage_options')) { return; }
        $s = self::settings();
        $map = self::map();
        $plan = self::plan();
        $pub = 0; $dr = 0; $revs = 0; $minrev = 9999;
        foreach ($plan as $it) {
            if (isset($map[$it['key']])) {
                $st = get_post_status($map[$it['key']]);
                $st === 'publish' ? $pub++ : $dr++;
                $r = (int) get_post_meta($map[$it['key']], '_oxy_seo_rev', true); $revs += $r; $minrev = min($minrev, $r);
            }
        }
        $log = (array) get_option(self::LOG, []);
        $days = self::days_ar();
        echo '<div class="wrap" dir="rtl"><h1>مقالات وأرشفة SEO — أكسجين</h1>';
        if (!empty($_GET['oxy_msg'])) { echo '<div class="notice notice-success"><p>' . esc_html(wp_unslash($_GET['oxy_msg'])) . '</p></div>'; }
        echo '<p><strong>المنشور:</strong> ' . (int) $pub . ' / ' . count($plan) . ' &nbsp; <strong>مسودات:</strong> ' . (int) $dr . ' &nbsp; <strong>إجمالي التحديثات:</strong> ' . (int) $revs . ' &nbsp; <strong>أقل عدد تحديثات لمقال:</strong> ' . ($minrev === 9999 ? 0 : (int) $minrev) . '</p>';
        $mc = Oxygen_SEO_Mega::counts();
        echo '<p><strong>بنك الكلمات المحلية:</strong> ' . number_format_i18n($mc['items']) . ' عنواناً فريداً = ' . (int) $mc['districts'] . ' حيّاً + ' . (int) $mc['places'] . ' موقعاً معروفاً + ' . (int) $mc['brands'] . ' علامة/مدينة، بأولوية لمكة؛ يُنشر منها حسب حصة «حي مختلف» اليومية، والمؤشر الحالي: ' . (int) get_option('oxygen_seo_mega_cur', 0) . '.</p>';
        echo '<p>الخطة: 60 مقالاً تجارياً + 60 مقالاً معلوماتياً (500+ كلمة)، ثم دورة تحديث لا تنتهي بـ24 سيناريو لكل مقال، وتظهر على المقال عبارة «تم التحديث».</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="oxy_seo_save">';
        wp_nonce_field('oxy_seo_save');
        echo '<table class="form-table"><tr><th>تشغيل المحرك</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked(!empty($s['enabled']), true, false) . '> مفعّل</label></td></tr>';
        echo '<tr><th>حالة المقال الجديد</th><td><select name="status"><option value="publish" ' . selected($s['status'], 'publish', false) . '>نشر مباشر</option><option value="draft" ' . selected($s['status'], 'draft', false) . '>مسودة للمراجعة قبل النشر</option></select></td></tr>';
        echo '<tr><th>مقالات جديدة / يوم</th><td><input type="number" name="new_per_day" value="' . (int) $s['new_per_day'] . '" min="0" max="120"></td></tr>';
        echo '<tr><th>نفس الخدمة بعنوان وكلمات SEO مختلفة / يوم</th><td><input type="number" name="seo_per_day" value="' . (int) $s['seo_per_day'] . '" min="0" max="120"></td></tr>';
        echo '<tr><th>نفس الخدمة بحي مختلف في مكة / يوم</th><td><input type="number" name="district_per_day" value="' . (int) $s['district_per_day'] . '" min="0" max="120"></td></tr>';
        echo '<tr><th>تحديث مقالات قديمة عشوائياً / يوم</th><td><input type="number" name="random_per_day" value="' . (int) $s['random_per_day'] . '" min="0" max="120"></td></tr>';
        echo '<tr><th>تحديثات / يوم</th><td><input type="number" name="refresh_per_day" value="' . (int) $s['refresh_per_day'] . '" min="0" max="120"></td></tr>';
        echo '<tr><th>أقل عمر قبل التحديث (أيام)</th><td><input type="number" name="min_age_days" value="' . (int) $s['min_age_days'] . '" min="0" max="365"></td></tr>';
        echo '<tr><th>رقم واتساب للمقالات</th><td><input type="text" name="wa" value="' . esc_attr($s['wa']) . '" placeholder="9665xxxxxxxx"></td></tr></table>';
        submit_button('حفظ الإعدادات');
        echo '</form><h2>تشغيل يدوي</h2><div style="display:flex;gap:8px;flex-wrap:wrap">';
        foreach ([['new', 1, 'إنشاء مقال'], ['new', 5, 'إنشاء 5 مقالات'], ['refresh', 1, 'تحديث مقال الآن']] as $b) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="oxy_seo_run"><input type="hidden" name="what" value="' . esc_attr($b[0]) . '"><input type="hidden" name="count" value="' . (int) $b[1] . '">';
            wp_nonce_field('oxy_seo_run'); submit_button($b[2], 'secondary', '', false); echo '</form>';
        }
        foreach ([['oxy_seo_pub', 'نشر كل المسودات'], ['oxy_seo_page', 'إنشاء صفحة الأرشيف']] as $b) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . esc_attr($b[0]) . '">';
            wp_nonce_field($b[0]); submit_button($b[1], 'secondary', '', false); echo '</form>';
        }
        echo '</div><p class="description">للتشغيل المنتظم على Hostinger: أضف مهمة Cron كل ساعة تستدعي <code>wget -q -O - ' . esc_html(home_url('/wp-cron.php?doing_wp_cron')) . '</code> مع تعطيل DISABLE_WP_CRON إن رغبت.</p>';
        echo '<h2>المقالات والأرشيف</h2><table class="widefat striped"><thead><tr><th>#</th><th>المسار</th><th>العنوان</th><th>الكلمة المفتاحية</th><th>الحالة</th><th>التحديثات</th><th>آخر سيناريو</th><th>آخر تحديث</th><th>روابط</th></tr></thead><tbody>';
        foreach ($plan as $it) {
            $id = $map[$it['key']] ?? 0;
            echo '<tr><td>' . ($it['idx'] + 1) . '</td><td>' . ($it['track'] === 'commercial' ? 'تجاري' : 'معلوماتي') . '</td><td>' . esc_html($it['title']) . '</td><td>' . esc_html($it['kw']) . '</td>';
            if ($id) {
                $st = get_post_status($id);
                echo '<td>' . ($st === 'publish' ? 'منشور' : 'مسودة') . '</td><td>' . (int) get_post_meta($id, '_oxy_seo_rev', true) . '</td><td>' . esc_html(get_post_meta($id, '_oxy_seo_angle', true)) . '</td><td>' . esc_html(get_post_meta($id, '_oxy_seo_updated', true)) . '</td><td><a href="' . esc_url($st === 'publish' ? get_permalink($id) : get_preview_post_link($id)) . '" target="_blank">' . ($st === 'publish' ? 'عرض' : 'معاينة') . '</a> | <a href="' . esc_url(get_edit_post_link($id)) . '">تحرير</a></td>';
            } else { echo '<td>في الانتظار</td><td>-</td><td>-</td><td>-</td><td>-</td>'; }
            echo '</tr>';
        }
        echo '</tbody></table><h2>سجل العمليات</h2><table class="widefat striped"><thead><tr><th>التاريخ</th><th>اليوم</th><th>الساعة</th><th>العملية</th><th>العنوان</th><th>الرابط</th></tr></thead><tbody>';
        $names = ['new' => 'مقال جديد', 'refresh' => 'تحديث', 'publish' => 'نشر مسودة', 'new_seo' => 'مقال بنفس الخدمة وSEO مختلف', 'new_dist' => 'مقال لحي في مكة', 'refresh_rand' => 'تحديث عشوائي'];
        foreach (array_slice($log, 0, 200) as $r) {
            $ts = (int) ($r['ts'] ?? 0);
            echo '<tr><td>' . esc_html(wp_date('Y-m-d', $ts)) . '</td><td>' . esc_html($days[(int) wp_date('w', $ts)]) . '</td><td>' . esc_html(wp_date('H:i', $ts)) . '</td><td>' . esc_html(($names[$r['a']] ?? $r['a']) . (isset($r['rev']) && $r['a'] === 'refresh' ? ' #' . $r['rev'] . ' — ' . ($r['angle'] ?? '') : '')) . '</td><td>' . esc_html($r['t'] ?? '') . '</td><td>' . (!empty($r['l']) ? '<a target="_blank" href="' . esc_url($r['l']) . '">فتح</a>' : '') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
