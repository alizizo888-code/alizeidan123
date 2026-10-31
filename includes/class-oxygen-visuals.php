<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Visual library for articles — free, offline, no API:
 *  - 18 specialties x 48 scene variants (8 palettes x 6 compositions), moving or still, drawn as SVG and served from /oxy-art/{spec}-{n}[-s].svg
 *  - hero banner (fixed aspect ratio, animated background), inline figures, promo + smart-assistant decision block
 *  - optional free photo libraries (Pexels / Pixabay) when the owner pastes a free API key: photos are hot-linked with credit
 */
final class Oxygen_Visuals {
    const VARIANTS = 48;

    public static function specs() {
        return [
            'ac' => ['التكييف', 'snow'], 'plumbing' => ['السباكة', 'drop'], 'electric' => ['الكهرباء', 'spark'], 'fridge' => ['الثلاجات', 'snow'], 'appliances' => ['الأجهزة المنزلية', 'bubble'],
            'pest' => ['مكافحة الحشرات', 'drift'], 'tanks' => ['خزانات المياه', 'drop'], 'locks' => ['الأقفال', 'spark'], 'cameras' => ['كاميرات المراقبة', 'drift'], 'solar' => ['الطاقة الشمسية', 'spark'],
            'satellite' => ['الدش والاستقبال', 'drift'], 'paint' => ['الدهانات والديكور', 'bubble'], 'tiling' => ['التبليط والعزل', 'drift'], 'cleaning' => ['التنظيف والتعقيم', 'bubble'],
            'moving' => ['نقل الأثاث', 'drift'], 'general' => ['الصيانة العامة', 'spark'], 'contracting' => ['المقاولات', 'drift'], 'corporate' => ['صيانة المنشآت', 'drift'],
        ];
    }
    private static function palettes() {
        return [['#0b3a6e', '#00b4d8'], ['#14532d', '#22c55e'], ['#7c2d12', '#f59e0b'], ['#3b0764', '#a855f7'], ['#134e4a', '#2dd4bf'], ['#1e3a8a', '#60a5fa'], ['#831843', '#fb7185'], ['#1f2937', '#38bdf8']];
    }
    /** Map any text (title, keyword, category) to a specialty id. */
    public static function detect($text) {
        $rules = ['ac' => 'مكيف|تكييف|سبليت|دكت', 'plumbing' => 'سباك|تسرب|مواسير|سخان|صرف|مياه', 'tanks' => 'خزان', 'electric' => 'كهرب|إنارة|قاطع|لمبات', 'fridge' => 'ثلاج|فريزر|برادات',
            'appliances' => 'غسالة|فرن|أجهزة|ديب فريزر|سخان', 'pest' => 'حشرات|صراصير|نمل|مكافحة|قوارض', 'locks' => 'قفل|أقفال|مفاتيح|كوالين', 'cameras' => 'كاميرا|مراقبة', 'solar' => 'شمسي|طاقة',
            'satellite' => 'دش|ستلايت|استقبال', 'paint' => 'دهان|ديكور|بوية', 'tiling' => 'تبليط|عزل|سيراميك|ترميم', 'cleaning' => 'تنظيف|تعقيم|نظافة', 'moving' => 'نقل|عفش|أثاث',
            'contracting' => 'مقاولات|بناء|تشطيب', 'corporate' => 'منشآت|شركات|عقود'];
        foreach ($rules as $k => $re) { if (preg_match('/' . $re . '/u', (string) $text)) { return $k; } }
        return 'general';
    }
    public static function url($spec, $n, $still = false) {
        $spec = isset(self::specs()[$spec]) ? $spec : 'general';
        return home_url('/oxy-art/' . $spec . '-' . ((int) $n % self::VARIANTS) . ($still ? '-s' : '') . '.svg');
    }
    public static function variant_for($seed) { return abs(crc32((string) $seed)) % self::VARIANTS; }

    /* ---------- the drawing ---------- */
    private static function motif($spec) {
        $s = 'stroke="#fff" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"';
        $f = 'fill="rgba(255,255,255,.18)"';
        $m = [
            'ac' => "<rect x='10' y='22' width='80' height='30' rx='8' $f $s/><path d='M20 42h60M20 34h60' $s fill='none'/><path d='M28 62c6 8 0 14 6 22M50 62c6 8 0 14 6 22M72 62c6 8 0 14 6 22' $s fill='none'/>",
            'plumbing' => "<path d='M8 30h46a10 10 0 0 1 10 10v22' $s fill='none'/><rect x='4' y='24' width='10' height='12' rx='3' $f $s/><path d='M64 70c-9 11-14 17-14 24a14 14 0 0 0 28 0c0-7-5-13-14-24z' $f $s/>",
            'electric' => "<path d='M56 6 22 54h24l-6 40 38-52H52z' $f $s/><circle cx='50' cy='50' r='44' fill='none' $s opacity='.5'/>",
            'fridge' => "<rect x='24' y='6' width='52' height='88' rx='8' $f $s/><path d='M24 38h52M36 18v10M36 48v18' $s fill='none'/>",
            'appliances' => "<rect x='18' y='8' width='64' height='84' rx='8' $f $s/><circle cx='50' cy='58' r='22' fill='none' $s/><circle cx='50' cy='58' r='10' fill='none' $s opacity='.6'/><path d='M28 20h12' $s/><circle cx='68' cy='20' r='3' fill='#fff'/>",
            'pest' => "<ellipse cx='50' cy='56' rx='20' ry='28' $f $s/><circle cx='50' cy='24' r='10' $f $s/><path d='M30 44H10M30 58H8M32 72l-18 12M70 44h20M70 58h22M68 72l18 12M44 15 36 4M56 15l8-11' $s fill='none'/>",
            'tanks' => "<path d='M22 24v56c0 8 12 14 28 14s28-6 28-14V24' $f $s/><ellipse cx='50' cy='24' rx='28' ry='10' $f $s/><path d='M30 54c7-6 13 6 20 0s13 6 20 0' $s fill='none'/>",
            'locks' => "<rect x='20' y='44' width='60' height='46' rx='10' $f $s/><path d='M32 44V30a18 18 0 0 1 36 0v14' $s fill='none'/><circle cx='50' cy='64' r='6' fill='#fff'/><path d='M50 70v10' $s/>",
            'cameras' => "<rect x='10' y='24' width='62' height='34' rx='8' $f $s/><circle cx='56' cy='41' r='10' fill='none' $s/><path d='M72 36l20-8v30l-20-8M24 58v26M12 84h30' $s fill='none'/>",
            'solar' => "<path d='M14 54l12-26h60l-12 26z' $f $s/><path d='M24 54l10-26M44 54l8-26M64 54l8-26M18 41h66' $s fill='none'/><path d='M50 54v30M34 86h32' $s/><circle cx='84' cy='14' r='8' $f $s/>",
            'satellite' => "<path d='M16 24c0 30 22 52 52 52' $s fill='none'/><path d='M22 18 74 70' $s/><path d='M18 20 80 82' $s opacity='.0'/><circle cx='80' cy='80' r='6' fill='#fff'/><path d='M78 52c10 0 20 10 20 20M78 36c18 0 34 16 34 34' $s fill='none' opacity='.7'/>",
            'paint' => "<rect x='14' y='10' width='60' height='24' rx='6' $f $s/><path d='M74 22h12a6 6 0 0 1 6 6v14a6 6 0 0 1-6 6H52v14' $s fill='none'/><rect x='44' y='62' width='16' height='32' rx='4' $f $s/>",
            'tiling' => "<rect x='10' y='10' width='38' height='38' rx='4' $f $s/><rect x='52' y='10' width='38' height='38' rx='4' $f $s/><rect x='10' y='52' width='38' height='38' rx='4' $f $s/><rect x='52' y='52' width='38' height='38' rx='4' $f $s/>",
            'cleaning' => "<path d='M34 38h22v10l10 8v36H24V56l10-8z' $f $s/><path d='M38 38V24h26l8-8' $s fill='none'/><path d='M78 30l3 8 8 3-8 3-3 8-3-8-8-3 8-3zM18 20l2 5 5 2-5 2-2 5-2-5-5-2 5-2z' fill='#fff'/>",
            'moving' => "<path d='M12 34 50 16l38 18v44L50 96 12 78z' $f $s/><path d='M12 34l38 18 38-18M50 52v44M31 25l38 18' $s fill='none'/>",
            'general' => "<path d='M70 12a18 18 0 0 0-16 26L14 78a9 9 0 0 0 13 13l40-40a18 18 0 0 0 26-16l-12 10-10-4-4-10z' $f $s/>",
            'contracting' => "<path d='M10 92h80M20 92V40h36v52M56 92V24h24v68' $s fill='none'/><path d='M28 52h8M28 66h8M64 40h8M64 56h8M64 72h8' $s/><path d='M10 40 38 18l28 22' $s fill='none' opacity='.0'/>",
            'corporate' => "<rect x='18' y='10' width='64' height='82' rx='4' $f $s/><path d='M30 26h12M58 26h12M30 44h12M58 44h12M30 62h12M58 62h12M42 92V76h16v16' $s fill='none'/>",
        ];
        return $m[$spec] ?? $m['general'];
    }
    public static function scene_svg($spec, $n, $still = false) {
        $spec = isset(self::specs()[$spec]) ? $spec : 'general';
        $n = ((int) $n % self::VARIANTS + self::VARIANTS) % self::VARIANTS;
        $pal = self::palettes()[$n % 8]; $comp = intdiv($n, 8) % 6;
        $kind = self::specs()[$spec][1];
        mt_srand(crc32($spec . $n));
        $id = 'g' . substr(md5($spec . $n), 0, 5);
        $bg = '';
        switch ($comp) {
            case 0: for ($i = 0; $i < 7; $i++) { $bg .= '<circle cx="' . mt_rand(0, 1200) . '" cy="' . mt_rand(0, 630) . '" r="' . mt_rand(60, 230) . '" fill="#fff" opacity="' . (mt_rand(4, 10) / 100) . '"/>'; } break;
            case 1: for ($i = -2; $i < 14; $i++) { $bg .= '<path d="M' . ($i * 120) . ' 630 L' . ($i * 120 + 260) . ' 0" stroke="#fff" stroke-width="' . mt_rand(18, 48) . '" opacity=".06"/>'; } break;
            case 2: $bg .= '<path d="M0 470c150-60 300 60 450 0s300-60 450 0 210 50 300 10v150H0z" fill="#fff" opacity=".10"/><path d="M0 530c150-50 300 50 450 0s300-50 450 0 210 40 300 8v92H0z" fill="#fff" opacity=".12"/>'; break;
            case 3: for ($x = 40; $x < 1200; $x += 60) { for ($y = 40; $y < 630; $y += 60) { $bg .= '<circle cx="' . $x . '" cy="' . $y . '" r="3" fill="#fff" opacity=".18"/>'; } } break;
            case 4: for ($i = 0; $i < 18; $i++) { $a = $i * M_PI / 9; $bg .= '<path d="M820 315 L' . round(820 + cos($a) * 1400) . ' ' . round(315 + sin($a) * 1400) . '" stroke="#fff" stroke-width="22" opacity=".05"/>'; } break;
            default: for ($i = 0; $i < 9; $i++) { $cx = mt_rand(0, 1200); $cy = mt_rand(0, 630); $r = mt_rand(40, 110); $p = ''; for ($k = 0; $k < 6; $k++) { $a = $k * M_PI / 3; $p .= round($cx + cos($a) * $r) . ',' . round($cy + sin($a) * $r) . ' '; } $bg .= '<polygon points="' . trim($p) . '" fill="#fff" opacity=".07"/>'; }
        }
        $anim = '';
        $css = '';
        if (!$still) {
            $css = '<style>@keyframes fall{from{transform:translateY(-40px)}to{transform:translateY(700px)}}@keyframes rise{from{transform:translateY(700px)}to{transform:translateY(-60px)}}@keyframes tw{0%,100%{opacity:.1;transform:scale(.6)}50%{opacity:.95;transform:scale(1.25)}}@keyframes dr{0%,100%{transform:translate(0,0)}50%{transform:translate(26px,-22px)}}@keyframes bob{0%,100%{transform:translateY(0)}50%{transform:translateY(-12px)}}.p{transform-box:fill-box;transform-origin:center}.m{animation:bob 4.5s ease-in-out infinite}@media (prefers-reduced-motion:reduce){*{animation:none!important}}</style>';
        }
        for ($i = 0; $i < 22; $i++) {
            $x = mt_rand(20, 1180); $y = mt_rand(10, 620); $r = mt_rand(3, 9); $d = mt_rand(40, 110) / 10; $dl = '-' . mt_rand(0, 90) / 10;
            $st = $still ? '' : ' style="animation:' . ($kind === 'snow' || $kind === 'drop' ? 'fall' : ($kind === 'bubble' ? 'rise' : ($kind === 'spark' ? 'tw' : 'dr'))) . ' ' . $d . 's ' . ($kind === 'spark' || $kind === 'drift' ? 'ease-in-out' : 'linear') . ' ' . $dl . 's infinite"';
            if ($kind === 'drop') { $anim .= '<path class="p" d="M' . $x . ' ' . $y . 'q' . $r . ' ' . ($r * 2) . ' 0 ' . ($r * 2.4) . 'q-' . $r . '-' . ($r * .4) . ' 0-' . ($r * 2.4) . 'z" fill="#fff" opacity=".55"' . $st . '/>'; }
            elseif ($kind === 'spark') { $anim .= '<path class="p" d="M' . $x . ' ' . ($y - $r * 2) . 'l' . $r . ' ' . ($r * 2) . ' ' . $r . ' ' . ($r * 2) . '-' . $r . ' -' . $r . '-' . ($r * 2) . ' ' . $r . 'z" fill="#fff" opacity=".7"' . $st . '/>'; }
            else { $anim .= '<circle class="p" cx="' . $x . '" cy="' . $y . '" r="' . $r . '" fill="#fff" opacity="' . ($kind === 'bubble' ? '.25' : '.5') . '"' . ($kind === 'bubble' ? ' stroke="#fff" stroke-opacity=".6"' : '') . $st . '/>'; }
        }
        $mot = self::motif($spec);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 630" width="1200" height="630" preserveAspectRatio="xMidYMid slice">' . $css
            . '<defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . $pal[0] . '"/><stop offset="1" stop-color="' . $pal[1] . '"/></linearGradient></defs>'
            . '<rect width="1200" height="630" fill="url(#' . $id . ')"/>' . $bg . $anim
            . '<g transform="translate(640 95) scale(4.2)"><g class="m">' . $mot . '</g></g></svg>';
        mt_srand();
        return $svg;
    }

    /** Serves /oxy-art/{spec}-{n}[-s].svg (cacheable, no WordPress templates). */
    public static function serve() {
        $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH) : '';
        if (!preg_match('#/oxy-art/([a-z]+)-(\d{1,3})(-s)?\.svg$#', $path, $m) || !isset(self::specs()[$m[1]])) { return; }
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: public, max-age=2592000, immutable');
        header('X-Content-Type-Options: nosniff');
        echo self::scene_svg($m[1], (int) $m[2], !empty($m[3]));
        exit;
    }

    /* ---------- HTML pieces ---------- */
    public static function css() {
        static $d = false; if ($d) { return ''; } $d = true;
        return '<style id="oxv-css">'
            . '.oxv-hero{position:relative;overflow:hidden;border-radius:18px;margin:0 0 18px;aspect-ratio:16/7;min-height:170px;max-height:340px;background:#0b3a6e;color:#fff;direction:rtl}'
            . '.oxv-hero>img{position:absolute;inset:0;width:100%!important;height:100%!important;object-fit:cover;border-radius:0!important;margin:0!important}'
            . '.oxv-hero:after{content:"";position:absolute;inset:0;background:linear-gradient(to right,rgba(4,16,40,.8) 6%,rgba(4,16,40,.1) 62%)}'
            . '.oxv-ov{position:absolute;z-index:2;left:16px;bottom:16px;max-width:56%;display:flex;flex-direction:column;gap:6px;align-items:flex-start}'
            . '.oxv-tag{background:#fbbf24;color:#4a2c00;font-size:12px;font-weight:800;border-radius:99px;padding:3px 12px}'
            . '.oxv-t{font-size:clamp(15px,4.2vw,24px);font-weight:800;line-height:1.5;margin:0;text-shadow:0 2px 10px rgba(0,0,0,.45)}'
            . '.oxv-fig{margin:18px 0;border-radius:16px;overflow:hidden;background:#f1f5f9}.oxv-fig img{display:block;width:100%!important;height:auto;aspect-ratio:16/7;object-fit:cover;margin:0!important;border-radius:0!important}'
            . '.oxv-fig figcaption{padding:8px 12px;font-size:14px;color:#334155;background:#f8fafc}'
            . '.oxv-promo{margin:22px 0;border-radius:18px;padding:16px;background:linear-gradient(135deg,#0f172a,#1e3a8a);color:#fff;direction:rtl;box-shadow:0 10px 26px rgba(15,23,42,.25)}'
            . '.oxv-promo h3{margin:0 0 4px!important;font-size:clamp(17px,4.6vw,21px)!important;color:#fff!important;line-height:1.5!important}.oxv-promo p{margin:0 0 12px!important;font-size:14px;opacity:.92;color:#fff}'
            . '.oxv-ch{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.oxv-ch a{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;text-align:center;text-decoration:none!important;color:#fff!important;font-weight:800;font-size:13px;line-height:1.4;border-radius:12px;padding:10px 6px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.28)}'
            . '.oxv-ch a:first-child{background:linear-gradient(135deg,#dc2626,#f97316);border-color:#fff}.oxv-ch a b{font-size:20px;line-height:1}'
            . '.oxpa-list{list-style:none;margin:0 0 16px;padding:0!important}.oxpa-list li{margin:0 0 10px;padding:12px 14px;border:1px solid #bae6fd;border-radius:14px;background:#f0f9ff;line-height:1.9}'
            . '.oxv-credit{font-size:11px;color:#64748b;text-align:left;direction:ltr;margin:-8px 0 12px}'
            . '@media(max-width:480px){.oxv-hero{aspect-ratio:16/9}.oxv-ch{grid-template-columns:1fr}.oxv-ch a{flex-direction:row;justify-content:flex-start;padding:10px 14px}}'
            . '</style>';
    }
    public static function hero($spec, $title, $seed = '') {
        $n = self::variant_for($seed . $spec); $lbl = self::specs()[$spec][0] ?? 'خدماتنا';
        return self::css() . '<div class="oxv-hero"><img src="' . esc_url(self::url($spec, $n)) . '" alt="' . esc_attr($lbl . ' — ' . $title) . '" width="1200" height="630" decoding="async">'
            . '<div class="oxv-ov"><span class="oxv-tag">' . esc_html($lbl) . '</span><div class="oxv-t">' . esc_html($title) . '</div></div></div>';
    }
    public static function figure($spec, $seed, $caption, $alt = '') {
        $n = (self::variant_for($seed . 'f' . $spec) + 7) % self::VARIANTS;
        return '<figure class="oxv-fig"><img src="' . esc_url(self::url($spec, $n, true)) . '" alt="' . esc_attr($alt ?: $caption) . '" width="1200" height="630" loading="lazy" decoding="async"><figcaption>' . esc_html($caption) . '</figcaption></figure>';
    }
    /** Promo + smart-assistant block that helps the visitor decide in one tap. */
    public static function promo($brand, $app, $wa, $phone, $topic = '') {
        $u = rawurlencode('🚨 أحتاج خدمة فورية' . ($topic ? ' (' . $topic . ')' : ''));
        $urgent = $wa ? 'https://wa.me/' . $wa . '?text=' . $u : $app . 'new';
        return '<div class="oxv-promo"><h3>محتار؟ مساعد ' . esc_html($brand) . ' الذكي يختار لك الأنسب في دقيقة</h3><p>اضغط على ما يناسب حالتك الآن — بلا تسجيل وبلا التزام.</p><div class="oxv-ch">'
            . '<a href="' . esc_url($urgent) . '" rel="nofollow"><b>⚡</b>طلب خدمة فورية</a>'
            . '<a href="' . esc_url($app . 'ai') . '" rel="nofollow"><b>🤖</b>اسأل المساعد الذكي</a>'
            . '<a href="' . esc_url($app . 'new') . '" rel="nofollow"><b>📅</b>احجز موعدًا مناسبًا</a></div></div>';
    }

    /** Optional free photo libraries (needs a free API key pasted by the owner). Cached 12 h. Returns [['src','alt','credit']]. */
    public static function photos($spec, $n = 6) {
        $q = [ 'ac' => 'air conditioner repair', 'plumbing' => 'plumber pipe repair', 'electric' => 'electrician wiring', 'fridge' => 'refrigerator repair', 'appliances' => 'washing machine repair', 'pest' => 'pest control',
            'tanks' => 'water tank cleaning', 'locks' => 'door lock', 'cameras' => 'cctv camera', 'solar' => 'solar panels roof', 'satellite' => 'satellite dish', 'paint' => 'house painting', 'tiling' => 'tile installation',
            'cleaning' => 'home cleaning', 'moving' => 'furniture moving', 'general' => 'home maintenance', 'contracting' => 'construction building', 'corporate' => 'office maintenance'];
        $kp = trim((string) get_option('oxygen_pexels_key', '')); $kx = trim((string) get_option('oxygen_pixabay_key', ''));
        if (!$kp && !$kx) { return []; }
        $ck = 'oxv_ph_' . $spec; $c = get_transient($ck); if (is_array($c)) { return array_slice($c, 0, $n); }
        $out = [];
        $term = $q[$spec] ?? 'home maintenance';
        if ($kp) {
            $r = wp_remote_get('https://api.pexels.com/v1/search?per_page=12&orientation=landscape&query=' . rawurlencode($term), ['timeout' => 8, 'headers' => ['Authorization' => $kp]]);
            $j = !is_wp_error($r) ? json_decode(wp_remote_retrieve_body($r), true) : null;
            foreach ((array) ($j['photos'] ?? []) as $p) { if (!empty($p['src']['large'])) { $out[] = ['src' => $p['src']['large'], 'alt' => (string) ($p['alt'] ?? $term), 'credit' => 'Photo: ' . ($p['photographer'] ?? 'Pexels') . ' / Pexels']; } }
        }
        if ($kx && count($out) < 6) {
            $r = wp_remote_get('https://pixabay.com/api/?image_type=photo&orientation=horizontal&safesearch=true&per_page=12&key=' . rawurlencode($kx) . '&q=' . rawurlencode($term), ['timeout' => 8]);
            $j = !is_wp_error($r) ? json_decode(wp_remote_retrieve_body($r), true) : null;
            foreach ((array) ($j['hits'] ?? []) as $p) { if (!empty($p['webformatURL'])) { $out[] = ['src' => $p['webformatURL'], 'alt' => (string) ($p['tags'] ?? $term), 'credit' => 'Image: ' . ($p['user'] ?? 'Pixabay') . ' / Pixabay']; } }
        }
        set_transient($ck, $out, 12 * HOUR_IN_SECONDS);
        return array_slice($out, 0, $n);
    }
    public static function boot() {
        add_action('init', [__CLASS__, 'serve'], 1);
        add_action('admin_menu', function () { add_submenu_page('oxygen-seo', 'مكتبة المشاهد والصور', 'مكتبة المشاهد والصور', 'manage_options', 'oxygen-visuals', [__CLASS__, 'page']); }, 20);
    }
    public static function page() {
        if (!current_user_can('manage_options')) { return; }
        if (!empty($_POST['oxv_save']) && check_admin_referer('oxv_save')) {
            update_option('oxygen_pexels_key', sanitize_text_field(wp_unslash($_POST['pexels'] ?? '')), false);
            update_option('oxygen_pixabay_key', sanitize_text_field(wp_unslash($_POST['pixabay'] ?? '')), false);
            foreach (array_keys(self::specs()) as $s) { delete_transient('oxv_ph_' . $s); }
            echo '<div class="notice notice-success"><p>تم الحفظ.</p></div>';
        }
        echo '<div class="wrap" dir="rtl"><h1>مكتبة المشاهد والصور</h1><p>' . count(self::specs()) . ' تخصصاً × ' . self::VARIANTS . ' مشهداً (متحرك وثابت) تُستخدم تلقائياً في بانر المقالات وأقسامها، مجاناً وبلا مفاتيح. ولإضافة صور فوتوغرافية حقيقية مجانية الصق مفتاح Pexels و/أو Pixabay (مجاني) أدناه.</p>';
        echo '<form method="post">'; wp_nonce_field('oxv_save');
        echo '<table class="form-table"><tr><th>مفتاح Pexels</th><td><input type="text" name="pexels" class="regular-text" value="' . esc_attr(get_option('oxygen_pexels_key', '')) . '"></td></tr><tr><th>مفتاح Pixabay</th><td><input type="text" name="pixabay" class="regular-text" value="' . esc_attr(get_option('oxygen_pixabay_key', '')) . '"></td></tr></table><p><button class="button button-primary" name="oxv_save" value="1">حفظ</button></p></form>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px">';
        foreach (self::specs() as $k => $v) { echo '<div><img src="' . esc_url(self::url($k, 3)) . '" style="width:100%;border-radius:10px" alt=""><div>' . esc_html($v[0]) . '</div></div>'; }
        echo '</div></div>';
    }
}
