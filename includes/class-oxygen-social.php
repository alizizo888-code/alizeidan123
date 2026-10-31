<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Social link-up (TikTok / Facebook) + comprehensive article description with hashtags.
 *  - The channel links are NOT shown as icons anywhere on the site: they feed Google (Organization sameAs) and the media gallery only.
 *  - Gallery: TikTok / Facebook videos the owner lists in the settings page appear as cards in the "من أعمالنا" gallery (both sites).
 *  - Articles: a long, specialty-related description with hashtags under the article; og:description carries the hashtags too.
 */
class Oxygen_Social {
    const OPT = 'oxygen_social';

    public static function boot() {
        add_action('admin_menu', function () { add_submenu_page('oxygen-seo', 'ربط تيك توك وفيسبوك', 'ربط تيك توك وفيسبوك', 'manage_options', 'oxygen-social', [__CLASS__, 'page']); }, 22);
        add_action('admin_post_oxygen_social_save', [__CLASS__, 'save']);
        add_filter('the_content', [__CLASS__, 'long_block'], 31);
        add_filter('wpseo_opengraph_desc', [__CLASS__, 'og_desc'], 40);
        add_filter('wpseo_schema_organization', [__CLASS__, 'schema_org'], 20);
        add_filter('oxygen_gallery_extra', [__CLASS__, 'gallery_cards']);
    }

    public static function settings() {
        $s = get_option(self::OPT, []);
        return array_merge(['tiktok' => '', 'facebook' => '', 'tiktok_videos' => '', 'facebook_videos' => ''], is_array($s) ? $s : []);
    }
    public static function urls() {
        $s = self::settings(); $o = [];
        foreach (['tiktok', 'facebook'] as $k) { if (preg_match('#^https://#', (string) $s[$k])) { $o[] = $s[$k]; } }
        return $o;
    }
    public static function schema_org($d) { $u = self::urls(); if ($u && is_array($d)) { $d['sameAs'] = array_values(array_unique(array_merge((array) ($d['sameAs'] ?? []), $u))); } return $d; }

    /* ---------- hashtags + long description ---------- */
    public static function tagify($t) {
        $t = trim(preg_replace('/[^\p{L}\p{N}\s_]+/u', ' ', (string) $t));
        $t = preg_replace('/\s+/u', '_', $t);
        return $t === '' ? '' : '#' . $t;
    }
    public static function hashtags($pid) {
        $out = [];
        $brand = class_exists('Oxygen_SEO_Engine') ? Oxygen_SEO_Engine::brand() : get_bloginfo('name');
        foreach (wp_get_post_categories($pid, ['fields' => 'names']) as $n) { if (strpos($n, 'خدمات ') !== 0 && $n !== 'أدلة وإرشادات') { $out[] = $n; } }
        foreach (wp_get_post_tags($pid, ['fields' => 'names']) as $n) { $out[] = $n; }
        $svc = $out[0] ?? '';
        $city = '';
        foreach ($out as $n) { if (in_array($n, ['مكة المكرمة', 'جدة'], true)) { $city = $n; } }
        if ($svc && $city) { $out[] = $svc . ' ' . $city; }
        $out[] = $brand;
        $tags = array_values(array_unique(array_filter(array_map([__CLASS__, 'tagify'], $out))));
        return array_slice($tags, 0, 8);
    }
    public static function long_text($pid) {
        $brand = class_exists('Oxygen_SEO_Engine') ? Oxygen_SEO_Engine::brand() : get_bloginfo('name');
        $cats = array_values(array_filter(wp_get_post_categories($pid, ['fields' => 'names']), function ($n) { return strpos($n, 'خدمات ') !== 0 && $n !== 'أدلة وإرشادات'; }));
        $svc = $cats[0] ?? 'الصيانة والخدمات المنزلية';
        $tags = wp_get_post_tags($pid, ['fields' => 'names']);
        $where = ''; foreach ($tags as $t) { if ($t !== $brand && $t !== $svc && !in_array($t, ['دليل إرشادي'], true) && mb_strlen($t) > 2) { $where = $t; break; } }
        $city = in_array('جدة', $tags, true) ? 'جدة' : (in_array('مكة المكرمة', $tags, true) ? 'مكة المكرمة' : 'مكة المكرمة وجدة');
        $a = 'في ' . $brand . ' نقدّم خدمة ' . $svc . ($where && $where !== $city ? ' في ' . $where : '') . ' بـ' . $city . ' عبر فنيين ومهندسين مختصين يعاينون المشكلة أولاً ثم ينفّذون العمل بإتقان.';
        $b = 'اطلب الفني من التطبيق دون تسجيل معقّد، وحدّد الحي ووقتك المناسب، وتابع طلبك خطوة بخطوة حتى إتمام الخدمة.';
        $c = 'تصفّح عروضنا الحالية أسفل الصفحة، أو تواصل معنا مباشرة بالاتصال أو الواتساب لحجز موعد عاجل.';
        return [$a, $b, $c];
    }
    public static function long_block($c) {
        if (!is_singular('post') || !in_the_loop() || !is_main_query() || strpos($c, 'oxs-long') !== false) { return $c; }
        $pid = get_the_ID();
        if (!$pid || !(get_post_meta($pid, '_oxy_seo_key', true) || get_post_meta($pid, '_oxy_pa_key', true))) { return $c; }
        $p = self::long_text($pid); $h = self::hashtags($pid);
        $css = '<style>.oxs-long{margin:22px 0;padding:16px 18px;border:1px solid #bae6fd;border-radius:16px;background:#f8fdff;line-height:2}.oxs-long h3{margin:0 0 6px;font-size:17px;color:#075985}.oxs-long p{margin:0 0 6px}.oxs-tags{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.oxs-tags span{background:#e0f2fe;color:#0369a1;border-radius:999px;padding:3px 11px;font-size:13px;font-weight:700}</style>';
        $html = $css . '<section class="oxs-long"><h3>وصف شامل للخدمة</h3>' . implode('', array_map(function ($x) { return '<p>' . esc_html($x) . '</p>'; }, $p))
            . ($h ? '<div class="oxs-tags">' . implode('', array_map(function ($x) { return '<span>' . esc_html($x) . '</span>'; }, $h)) . '</div>' : '') . '</section>';
        return $c . $html;
    }
    public static function og_desc($d) {
        if (!is_singular('post')) { return $d; }
        $pid = get_queried_object_id();
        if (!$pid || !(get_post_meta($pid, '_oxy_seo_key', true) || get_post_meta($pid, '_oxy_pa_key', true))) { return $d; }
        $h = self::hashtags($pid); if (!$h) { return $d; }
        $p = self::long_text($pid);
        $s = trim($d . ' ' . $p[0]);
        $out = $s . ' ' . implode(' ', $h);
        return mb_strlen($out) > 300 ? mb_substr($s, 0, max(0, 300 - mb_strlen(implode(' ', $h)) - 2)) . '… ' . implode(' ', $h) : $out;
    }

    /* ---------- gallery cards (TikTok / Facebook videos, listed by the owner) ---------- */
    private static function tt_thumb($url) {
        $k = 'oxs_tt_' . md5($url);
        $v = get_transient($k);
        if ($v !== false) { return $v; }
        $r = wp_remote_get('https://www.tiktok.com/oembed?url=' . rawurlencode($url), ['timeout' => 6]);
        $th = ['thumb' => '', 'title' => ''];
        if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) { $j = json_decode(wp_remote_retrieve_body($r), true); if (is_array($j)) { $th = ['thumb' => (string) ($j['thumbnail_url'] ?? ''), 'title' => (string) ($j['title'] ?? '')]; } }
        set_transient($k, $th, $th['thumb'] ? 12 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS);
        return $th;
    }
    public static function gallery_cards($html) {
        $s = self::settings(); $cards = '';
        foreach (array_slice(array_filter(array_map('trim', preg_split('/\R+/', (string) $s['tiktok_videos']))), 0, 12) as $u) {
            if (!preg_match('#^https://(www\.|vm\.|vt\.)?tiktok\.com/#', $u)) { continue; }
            $t = self::tt_thumb($u);
            $cards .= '<a class="oxs-card" href="' . esc_url($u) . '" target="_blank" rel="noopener nofollow" aria-label="فيديو تيك توك">' . ($t['thumb'] ? '<img src="' . esc_url($t['thumb']) . '" alt="' . esc_attr($t['title'] ?: 'فيديو من أعمالنا') . '" loading="lazy" width="120" height="212">' : '<b>▶ تيك توك</b>') . '<i>▶</i></a>';
        }
        foreach (array_slice(array_filter(array_map('trim', preg_split('/\R+/', (string) $s['facebook_videos']))), 0, 8) as $u) {
            if (!preg_match('#^https://(www\.|m\.|web\.)?(facebook\.com|fb\.watch)/#', $u)) { continue; }
            $cards .= '<span class="oxs-card oxs-fb"><iframe src="' . esc_url('https://www.facebook.com/plugins/video.php?show_text=false&width=200&href=' . rawurlencode($u)) . '" width="200" height="212" loading="lazy" style="border:0;overflow:hidden" allowfullscreen="true" title="فيديو فيسبوك"></iframe></span>';
        }
        if ($cards === '') { return $html; }
        return $html . '<div class="oxb"><style>.oxs-row{display:flex;gap:10px;overflow-x:auto;padding:4px 12px 10px;direction:ltr}.oxs-card{position:relative;flex:0 0 auto;width:120px;height:212px;border-radius:14px;overflow:hidden;background:#0b1220;color:#fff;display:flex;align-items:center;justify-content:center;text-decoration:none}.oxs-card img{width:100%;height:100%;object-fit:cover}.oxs-card i{position:absolute;font-style:normal;background:rgba(0,0,0,.55);border-radius:50%;width:34px;height:34px;display:flex;align-items:center;justify-content:center}.oxs-fb{width:200px}</style><div class="oxb-gal"><h4>🎬 فيديوهات من أعمالنا</h4><div class="oxs-row">' . $cards . '</div></div></div>';
    }

    /* ---------- settings page ---------- */
    public static function page() {
        if (!current_user_can('manage_options')) { return; }
        $s = self::settings();
        echo '<div class="wrap" dir="rtl"><h1>ربط تيك توك وفيسبوك</h1><p>الروابط هنا <b>لا تظهر</b> كأيقونات داخل الموقع؛ تُرسل لجوجل (ربط الموقع بالقناتين) وتُغذّي معرض الفيديو فقط.</p>';
        if (!empty($_GET['saved'])) { echo '<div class="notice notice-success"><p>تم الحفظ.</p></div>'; }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="oxygen_social_save">' . wp_nonce_field('oxygen_social', '_wpnonce', true, false) . '<table class="form-table">';
        $f = function ($l, $k, $ta = false, $h = '') use ($s) { echo '<tr><th>' . esc_html($l) . '</th><td>' . ($ta ? '<textarea name="' . $k . '" rows="6" class="large-text" dir="ltr">' . esc_textarea($s[$k]) . '</textarea>' : '<input type="url" name="' . $k . '" value="' . esc_attr($s[$k]) . '" class="regular-text" dir="ltr">') . ($h ? '<p class="description">' . esc_html($h) . '</p>' : '') . '</td></tr>'; };
        $f('رابط حساب تيك توك', 'tiktok', false, 'مثال: https://www.tiktok.com/@اسم_الحساب');
        $f('رابط صفحة فيسبوك', 'facebook');
        $f('فيديوهات تيك توك للمعرض (رابط في كل سطر)', 'tiktok_videos', true, 'ألصق رابط كل فيديو؛ تظهر صورته المصغّرة في معرض الصور والفيديو وتفتح على تيك توك.');
        $f('فيديوهات فيسبوك للمعرض (رابط في كل سطر)', 'facebook_videos', true);
        echo '</table><p><button class="button button-primary">حفظ</button></p></form></div>';
    }
    public static function save() {
        if (!current_user_can('manage_options')) { wp_die('غير مصرح.'); }
        check_admin_referer('oxygen_social');
        $n = [];
        foreach (['tiktok', 'facebook'] as $k) { $n[$k] = esc_url_raw(trim((string) wp_unslash($_POST[$k] ?? ''))); }
        foreach (['tiktok_videos', 'facebook_videos'] as $k) { $n[$k] = implode("\n", array_filter(array_map('esc_url_raw', array_map('trim', preg_split('/\R+/', (string) wp_unslash($_POST[$k] ?? '')))))); }
        update_option(self::OPT, $n, false);
        wp_safe_redirect(admin_url('admin.php?page=oxygen-social&saved=1')); exit;
    }
}
