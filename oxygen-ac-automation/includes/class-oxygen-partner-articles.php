<?php
if (!defined('ABSPATH')) { exit; }

/**
 * "شركاء النجاح": a separate set of 120 articles per site (independent of the daily engine), built around the phone numbers the owner supplies.
 * The numbers are the owner's partners / affiliated companies / available branches / active locations. Nothing is invented: names, roles and places
 * appear only when the owner typed them; otherwise the article uses a neutral label. Published once, then refreshed every 5 days (rotating which
 * numbers lead the page and the wording), all automatically from an hourly tick. Does nothing until numbers exist.
 */
final class Oxygen_Partner_Articles {
    const OPT_NUM = 'oxygen_partner_numbers';
    const OPT_SET = 'oxygen_partner_settings';
    const LOG = 'oxygen_partner_log';
    const CRON = 'oxygen_pa_tick';
    const REFRESH_DAYS = 5;

    public static function boot() {
        add_action('init', [__CLASS__, 'ensure_cron'], 5);
        add_action(self::CRON, [__CLASS__, 'tick']);
        add_action('admin_menu', function () { add_submenu_page('oxygen-seo', 'شركاء النجاح (120 مقالة)', 'شركاء النجاح (120 مقالة)', 'manage_options', 'oxygen-partners', [__CLASS__, 'page']); }, 21);
        add_filter('wpseo_title', [__CLASS__, 'title'], 21);
    }
    public static function title($t) {
        if (is_singular('post')) { $id = get_queried_object_id(); if ($id && get_post_meta($id, '_oxy_pa_key', true)) { return html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8'); } }
        return $t;
    }
    public static function ensure_cron() { if (!wp_next_scheduled(self::CRON)) { wp_schedule_event(time() + 420, 'hourly', self::CRON); } }
    private static function brand() { return class_exists('Oxygen_SEO_Engine') ? Oxygen_SEO_Engine::brand() : get_bloginfo('name'); }
    private static function salt() { return class_exists('Oxygen_SEO_Engine') ? Oxygen_SEO_Engine::salt() : 0; }
    public static function settings() {
        $s = get_option(self::OPT_SET, []); $s = is_array($s) ? $s : [];
        return array_merge(['enabled' => 1, 'count' => 120, 'per_tick_new' => 20, 'per_tick_refresh' => 6, 'status' => 'publish'], $s);
    }

    /* ---------- the owner's numbers ---------- */
    public static function norm_phone($raw) {
        $d = preg_replace('/\D/', '', (string) $raw);
        if ($d === '') { return ['', '']; }
        if (strpos($d, '00') === 0) { $d = substr($d, 2); }
        if (strpos($d, '966') === 0) { $intl = $d; }
        elseif (strpos($d, '05') === 0 && strlen($d) === 10) { $intl = '966' . substr($d, 1); }
        elseif (strpos($d, '5') === 0 && strlen($d) === 9) { $intl = '966' . $d; }
        else { $intl = $d; }
        $local = (strpos($intl, '966') === 0 && strlen($intl) === 12) ? '0' . substr($intl, 3) : '+' . $intl;
        return [$intl, $local];
    }
    /** One per line:  phone | name | role (شريك / فرع / شركة تابعة / موقع نشط) | place.  Only the phone is required. */
    public static function numbers() {
        $raw = (string) get_option(self::OPT_NUM, '');
        if ($raw === '' && file_exists(__DIR__ . '/seo/data-partners.txt')) { $raw = (string) file_get_contents(__DIR__ . '/seo/data-partners.txt'); }
        $out = []; $seen = [];
        foreach (preg_split('/\R+/u', $raw) as $line) {
            $line = trim($line); if ($line === '' || $line[0] === '#') { continue; }
            $p = array_map('trim', preg_split('/\s*[|،;]\s*/u', $line));
            list($intl, $local) = self::norm_phone($p[0] ?? '');
            if (strlen($intl) < 9 || isset($seen[$intl])) { continue; }
            $seen[$intl] = 1;
            $role = (string) ($p[2] ?? '');
            $kind = preg_match('/فرع/u', $role) ? 'branch' : (preg_match('/تابع/u', $role) ? 'affiliate' : (preg_match('/موقع|نشط|مكان/u', $role) ? 'active' : 'partner'));
            $out[] = ['intl' => $intl, 'local' => $local, 'name' => (string) ($p[1] ?? ''), 'kind' => $kind, 'place' => (string) ($p[3] ?? '')];
        }
        return $out;
    }
    private static function kind_label($k) { return ['partner' => 'شريك نجاح', 'affiliate' => 'شركة تابعة', 'branch' => 'فرع متاح', 'active' => 'موقع نشط'][$k] ?? 'شريك نجاح'; }

    /* ---------- the 120 slots ---------- */
    private static function types() {
        return [
            'success' => ['شركاء النجاح', 'partner', ['شركاء النجاح {S} | شبكة {b} من الخبراء المختصين', 'نخبة شركاء النجاح {S}: خبرة {b} وأرقام مباشرة', 'شركاء {b} في النجاح {S}: خبراء مهندسون مختصون']],
            'affiliates' => ['الشركات التابعة', 'affiliate', ['الشركات التابعة لشبكة {b} {S}: ريادة وإتقان', 'شبكة {b} الرائدة {S}: الشركات التابعة وأرقام التواصل', 'أقوى الشركات التابعة لـ{b} {S} بخبرة مهندسين مختصين']],
            'branches' => ['الفروع المتاحة', 'branch', ['الفروع المتاحة {S}: خدمة {b} أقرب إليك', 'فروع {b} {S}: حضور أسرع وخبراء مختصون', 'أين أقرب فرع لـ{b} {S}؟ الفروع المتاحة وأرقامها']],
            'active' => ['الأماكن النشطة', 'active', ['الأماكن النشطة {S}: أين يعمل فريق {b} الآن؟', 'مواقع عمل {b} النشطة {S} وأرقام التواصل', 'خريطة الأماكن النشطة لـ{b} {S}: خدمة حيث أنت']],
            'network' => ['شبكة الخدمة', 'partner', ['شبكة {b} الرائدة {S}: شركاء وفروع ومواقع نشطة', 'اكتشف شبكة {b} {S}: شركاء النجاح والفروع المتاحة', '{b} {S}: شبكة خبراء وشركاء تغطي المنطقة']],
            'contact' => ['دليل التواصل', 'partner', ['دليل أرقام شركاء {b} {S}: تواصل مباشر مع النخبة', 'أرقام التواصل المباشر مع شركاء {b} {S}', 'دليلك السريع لشركاء {b} {S}: اتصل بالأقرب إليك']],
        ];
    }
    private static function scopes() {
        $sc = ['mk' => ['في مكة المكرمة', 'مكة المكرمة', ''], 'jd' => ['في جدة', 'جدة', ''], 'both' => ['في مكة وجدة', 'مكة وجدة', '']];
        foreach (['ac', 'plumbing', 'electric', 'fridge', 'appliances', 'pest', 'tanks', 'locks', 'cameras', 'solar', 'satellite', 'paint', 'tiling', 'cleaning', 'moving', 'general', 'contracting'] as $d) {
            $lbl = Oxygen_Visuals::specs()[$d][0]; $sc[$d] = ['لخدمات ' . $lbl . ' بمكة وجدة', 'مكة وجدة', $d];
        }
        return $sc;
    }
    public static function slots() {
        static $s = null; if ($s !== null) { return $s; }
        $s = [];
        foreach (self::scopes() as $sk => $sv) { foreach (array_keys(self::types()) as $tk) { $s[] = ['key' => 'oxy-pa-' . $tk . '-' . $sk, 'type' => $tk, 'scope' => $sk]; } }
        // Makkah first: scopes already start with Makkah. The order differs per site so the two sites never publish the same slot first.
        $k = (self::salt() * 7) % count($s); if ($k) { $s = array_merge(array_slice($s, $k), array_slice($s, 0, $k)); }
        foreach ($s as $i => &$x) { $x['idx'] = $i; } unset($x);
        return $s;
    }
    public static function slot_title($slot, $rev = 0) {
        $T = self::types()[$slot['type']]; $sv = self::scopes()[$slot['scope']];
        $tpl = $T[2][($slot['idx'] + self::salt()) % 3];
        $t = str_replace(['{S}', '{b}'], [$sv[0], self::brand()], $tpl);
        return $t;
    }

    /* ---------- content ---------- */
    public static function build($slot, $rev, $nums) {
        $T = self::types()[$slot['type']]; $sv = self::scopes()[$slot['scope']]; $b = self::brand();
        $n = count($nums); $take = min(8, $n);
        // rotate which numbers lead (changes every refresh), prefer the numbers whose role matches the article type
        $order = [];
        foreach ($nums as $i => $x) { $order[] = [$x['kind'] === $T[1] ? 0 : 1, ($i + $slot['idx'] * 3 + $rev * 2) % max(1, $n), $x]; }
        usort($order, function ($a, $c) { return $a[0] <=> $c[0] ?: $a[1] <=> $c[1]; });
        $pick = array_slice(array_map(function ($o) { return $o[2]; }, $order), 0, $take);
        $svc = $sv[2] ? Oxygen_Visuals::specs()[$sv[2]][0] : 'الصيانة والخدمات المنزلية';
        $city = $sv[1];
        $intro = [
            'في ' . $b . ' نؤمن أن النجاح لا يُبنى وحده؛ ولهذا تجد هنا الأرقام المباشرة لشركاء النجاح والشركات التابعة والفروع المتاحة في ' . $city . '، لتصل إلى الخدمة الأنسب بأسرع طريق.',
            'تتكوّن شبكة ' . $b . ' الرائدة من نخبة من الشركاء والخبراء المختصين في ' . $svc . '، وهذا الدليل يضع أرقام التواصل المباشر بين يديك في ' . $city . '.',
            'إذا كنت تبحث عن جهة موثوقة في ' . $svc . ' داخل ' . $city . '، فهذه قائمة أرقام شركاء ' . $b . ' وفروعها وأماكنها النشطة، محدَّثة باستمرار.',
            'خبرة مهندسين مختصين، وحضور في أكثر من موقع، وأرقام تتصل بها فتجد من يجيبك: هذا ما تقدمه شبكة ' . $b . ' في ' . $city . '.',
            'نعرّفك في هذا الدليل بشركاء النجاح لدى ' . $b . ' والشركات التابعة والفروع المتاحة، حتى تختار الأقرب إليك وتتواصل معه فوراً.',
        ];
        $h = '<p>' . esc_html($intro[($slot['idx'] + $rev) % count($intro)]) . '</p>';
        $h .= '<h2>' . esc_html($T[0]) . ' ' . esc_html($sv[0]) . '</h2><ul class="oxpa-list">';
        foreach ($pick as $i => $x) {
            $name = $x['name'] !== '' ? $x['name'] : (self::kind_label($x['kind']) . ' رقم ' . ($i + 1));
            $h .= '<li><strong>' . esc_html($name) . '</strong> — ' . esc_html(self::kind_label($x['kind'])) . ($x['place'] !== '' ? ' · ' . esc_html($x['place']) : '')
                . ' · <a href="tel:+' . esc_attr($x['intl']) . '">' . esc_html($x['local']) . '</a> · <a href="https://wa.me/' . esc_attr($x['intl']) . '" rel="nofollow noopener" target="_blank">واتساب</a></li>';
        }
        $h .= '</ul>';
        $h .= '<h2>كيف يخدمك هذا الدليل في ' . esc_html($svc) . '؟</h2><p>اتصل بالرقم الأقرب لموقعك واذكر نوع الخدمة المطلوبة (' . esc_html($svc) . ') وعنوانك أو أقرب معلم، وسيُرتَّب الموعد المناسب معك. الأرقام المذكورة أعلاه أدرجها مالك شبكة ' . esc_html($b) . ' بوصفها أرقام شركائه وفروعه ومواقعه النشطة.</p>';
        $tips = ['اسأل عن موعد الوصول المتوقع قبل الاتفاق.', 'اطلب شرح العمل المطلوب قبل التنفيذ.', 'تأكد من تفاصيل الضمان إن وُجد على الخدمة.', 'احتفظ برقم الطلب أو رسالة التأكيد على واتساب.', 'اذكر الموديل أو صورة العطل لتسريع التشخيص.'];
        $h .= '<h2>كيف تختار الأنسب لك؟</h2><ol>';
        for ($i = 0; $i < 4; $i++) { $h .= '<li>' . esc_html($tips[($i + $slot['idx'] + $rev) % count($tips)]) . '</li>'; }
        $h .= '</ol><h2>أسئلة شائعة</h2><h3>هل هذه الأرقام تابعة فعلاً لـ' . esc_html($b) . '؟</h3><p>نعم، هذه الأرقام هي أرقام شركاء النجاح والشركات التابعة والفروع والأماكن النشطة التي أدرجها مالك الشبكة، وتُراجع القائمة وتُحدَّث كل ' . self::REFRESH_DAYS . ' أيام.</p>'
            . '<h3>كيف أصل إلى أقرب فرع أو شريك؟</h3><p>راجع قائمة ' . esc_html($T[0]) . ' أعلاه، واتصل بالأقرب لموقعك في ' . esc_html($city) . '، أو اطلب الخدمة مباشرة من التطبيق وسنوجّهك للأنسب.</p>'
            . '<h3>هل يمكنني طلب خدمة فورية؟</h3><p>نعم، يمكنك الاتصال أو مراسلة واتساب على أي رقم في القائمة، أو استخدام زر «طلب خدمة فورية» في هذه الصفحة.</p>';
        $desc = $T[0] . ' ' . $sv[0] . ': أرقام مباشرة لشركاء ' . $b . ' وفروعها وأماكنها النشطة، بخبرة مهندسين مختصين. تواصل الآن.';
        if (mb_strlen($desc) > 158) { $desc = mb_substr($desc, 0, 155) . '...'; }
        return ['html' => $h, 'desc' => $desc, 'spec' => $sv[2] ?: ($slot['scope'] === 'jd' ? 'general' : 'general')];
    }

    /* ---------- publishing & refresh ---------- */
    private static function map() {
        $ids = get_posts(['post_type' => 'post', 'post_status' => 'any', 'meta_key' => '_oxy_pa_key', 'numberposts' => -1, 'fields' => 'ids', 'no_found_rows' => true]);
        $m = []; foreach ($ids as $id) { $k = get_post_meta($id, '_oxy_pa_key', true); if ($k) { $m[$k] = (int) $id; } }
        return $m;
    }
    private static function log($a, $id, $t) { $l = get_option(self::LOG, []); $l = is_array($l) ? $l : []; array_unshift($l, ['ts' => time(), 'a' => $a, 'id' => (int) $id, 't' => $t]); update_option(self::LOG, array_slice($l, 0, 400), false); }
    private static function cat() {
        $n = 'شركاء النجاح'; $t = term_exists($n, 'category'); if (!$t) { $t = wp_insert_term($n, 'category'); }
        return (!is_wp_error($t) && !empty($t['term_id'])) ? (int) $t['term_id'] : 0;
    }
    public static function create_next($force_status = null) {
        $nums = self::numbers(); if (!$nums) { return false; }
        $s = self::settings(); $map = self::map();
        if (count($map) >= (int) $s['count']) { return false; }
        foreach (self::slots() as $slot) {
            if ($slot['idx'] >= (int) $s['count'] || isset($map[$slot['key']])) { continue; }
            $b = self::build($slot, 0, $nums); $title = self::slot_title($slot);
            $author = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
            $id = wp_insert_post(['post_type' => 'post', 'post_status' => $force_status ?: $s['status'], 'post_title' => $title, 'post_name' => $slot['key'], 'post_content' => $b['html'], 'post_excerpt' => $b['desc'], 'post_author' => $author ? (int) $author[0] : 1], true);
            if (is_wp_error($id) || !$id) { return false; }
            update_post_meta($id, '_oxy_pa_key', $slot['key']); update_post_meta($id, '_oxy_pa_rev', 0); update_post_meta($id, '_oxy_pa_updated', current_time('mysql')); update_post_meta($id, '_oxy_pa_spec', $b['spec']);
            update_post_meta($id, '_yoast_wpseo_metadesc', $b['desc']); update_post_meta($id, 'rank_math_description', $b['desc']);
            $c = self::cat(); if ($c) { wp_set_post_categories($id, [$c]); }
            wp_set_post_tags($id, array_values(array_filter(['شركاء النجاح', self::brand(), self::scopes()[$slot['scope']][1]])), false);
            self::log('new', $id, $title);
            if (get_post_status($id) === 'publish' && class_exists('Oxygen_SEO_Engine')) { Oxygen_SEO_Engine::indexnow([get_permalink($id)]); }
            return $id;
        }
        return false;
    }
    public static function refresh_next() {
        $nums = self::numbers(); if (!$nums) { return false; }
        $map = self::map(); $by = []; foreach (self::slots() as $sl) { $by[$sl['key']] = $sl; }
        $old = time() - self::REFRESH_DAYS * DAY_IN_SECONDS; $cand = [];
        foreach ($map as $k => $id) { if (!isset($by[$k])) { continue; } $u = strtotime((string) get_post_meta($id, '_oxy_pa_updated', true)) ?: 0; if ($u <= $old) { $cand[] = [$u, $id, $k]; } }
        if (!$cand) { return false; }
        usort($cand, function ($a, $b) { return $a[0] <=> $b[0]; });
        $id = $cand[0][1]; $slot = $by[$cand[0][2]]; $rev = (int) get_post_meta($id, '_oxy_pa_rev', true) + 1;
        $b = self::build($slot, $rev, $nums); $title = self::slot_title($slot, $rev);
        wp_update_post(['ID' => $id, 'post_title' => $title, 'post_content' => $b['html'], 'post_excerpt' => $b['desc']]);
        update_post_meta($id, '_oxy_pa_rev', $rev); update_post_meta($id, '_oxy_pa_updated', current_time('mysql')); update_post_meta($id, '_oxy_pa_spec', $b['spec']);
        update_post_meta($id, '_yoast_wpseo_metadesc', $b['desc']); update_post_meta($id, 'rank_math_description', $b['desc']);
        self::log('refresh', $id, $title);
        if (get_post_status($id) === 'publish' && class_exists('Oxygen_SEO_Engine')) { Oxygen_SEO_Engine::indexnow([get_permalink($id)]); }
        return $id;
    }
    public static function tick() {
        $s = self::settings(); if (empty($s['enabled']) || !self::numbers()) { return; }
        for ($i = 0; $i < (int) $s['per_tick_new']; $i++) { if (!self::create_next()) { break; } }
        for ($i = 0; $i < (int) $s['per_tick_refresh']; $i++) { if (!self::refresh_next()) { break; } }
    }

    /* ---------- admin ---------- */
    public static function page() {
        if (!current_user_can('manage_options')) { return; }
        if (!empty($_POST['oxpa_save']) && check_admin_referer('oxpa_save')) {
            update_option(self::OPT_NUM, sanitize_textarea_field(wp_unslash($_POST['nums'] ?? '')), false);
            $s = self::settings(); $s['enabled'] = empty($_POST['enabled']) ? 0 : 1; $s['count'] = max(1, min(120, (int) ($_POST['count'] ?? 120))); update_option(self::OPT_SET, $s, false);
            echo '<div class="notice notice-success"><p>تم الحفظ. تبدأ المقالات بالنشر تلقائياً خلال ساعة.</p></div>';
        }
        if (!empty($_POST['oxpa_run']) && check_admin_referer('oxpa_save')) {
            $made = 0; for ($i = 0; $i < 5; $i++) { if (self::create_next()) { $made++; } }
            echo '<div class="notice notice-success"><p>تم إنشاء ' . (int) $made . ' مقالات الآن.</p></div>';
        }
        $s = self::settings(); $map = self::map(); $nums = self::numbers(); $pub = 0; $revs = 0;
        foreach ($map as $id) { if (get_post_status($id) === 'publish') { $pub++; } $revs += (int) get_post_meta($id, '_oxy_pa_rev', true); }
        echo '<div class="wrap" dir="rtl"><h1>شركاء النجاح — ' . (int) $s['count'] . ' مقالة</h1>';
        echo '<p><strong>الأرقام المدخلة:</strong> ' . count($nums) . ' &nbsp; <strong>المنشور:</strong> ' . (int) $pub . ' / ' . (int) $s['count'] . ' &nbsp; <strong>مرات التحديث:</strong> ' . (int) $revs . '</p>';
        if (!$nums) { echo '<div class="notice notice-warning"><p>لا توجد أرقام بعد، لذلك لن يُنشر شيء. الصق الأرقام أدناه.</p></div>'; }
        echo '<p>مجموعة مستقلة عن المقالات اليومية: تُنشر ثم تُحدَّث كل ' . self::REFRESH_DAYS . ' أيام تلقائياً مع تدوير الأرقام والصياغة.</p>';
        echo '<form method="post">'; wp_nonce_field('oxpa_save');
        echo '<table class="form-table"><tr><th>الأرقام</th><td><textarea name="nums" rows="14" class="large-text" style="direction:ltr" placeholder="0551234567 | اسم الشريك | شريك أو فرع أو شركة تابعة أو موقع نشط | الحي أو المدينة">' . esc_textarea((string) get_option(self::OPT_NUM, '')) . '</textarea><p class="description">سطر لكل رقم. الرقم فقط مطلوب؛ الاسم والنوع والمكان اختيارية ولا يُكتب في المقال إلا ما تدخله أنت.</p></td></tr>';
        echo '<tr><th>عدد المقالات</th><td><input type="number" name="count" min="1" max="120" value="' . (int) $s['count'] . '"></td></tr><tr><th>تشغيل</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked(!empty($s['enabled']), true, false) . '> مفعّل</label></td></tr></table>';
        echo '<p><button class="button button-primary" name="oxpa_save" value="1">حفظ</button> <button class="button" name="oxpa_run" value="1">إنشاء 5 مقالات الآن</button></p></form>';
        echo '<h2>آخر العمليات</h2><table class="widefat striped"><thead><tr><th>الوقت</th><th>العملية</th><th>العنوان</th></tr></thead><tbody>';
        foreach (array_slice((array) get_option(self::LOG, []), 0, 40) as $r) { echo '<tr><td>' . esc_html(wp_date('Y-m-d H:i', (int) $r['ts'])) . '</td><td>' . ($r['a'] === 'new' ? 'نشر' : 'تحديث') . '</td><td><a href="' . esc_url(get_permalink((int) $r['id'])) . '">' . esc_html($r['t']) . '</a></td></tr>'; }
        echo '</tbody></table></div>';
    }
}
