<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «عملاء الخرائط» (owner order 2026-11-21): business leads for Makkah and Jeddah, A/C and cold rooms, pulled from
 * Google Maps by the free open-source scraper gosom/google-maps-scraper (MIT), run weekly by GitHub Actions
 * (.github/workflows/maps-leads.yml). This class is the control side, on the hub only:
 *   - Settings → عملاء الخرائط: on/off, cities, districts, the kinds of customers per field, depth, the run token.
 *   - GET  /mutqan/v1/leads/config  (token) → the search phrases the workflow runs.
 *   - POST /mutqan/v1/leads/import  (token) → rows upserted by phone into {prefix}mutqan_leads.
 *   - Export per city × field as CSV (Excel opens it, Arabic intact).
 * The targets are CUSTOMERS (hotels, restaurants, supermarkets …), never maintenance shops: those are competitors.
 * A number marked «لا ترسل» (opt-out) never leaves in a WhatsApp list again.
 */
final class Mutqan_Leads {
    const OPT = 'mutqan_leads';
    const DBV = 'mutqan_leads_db';
    const PAGE = 'mutqan-leads';

    public static function boot() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('admin_menu', function () {
            if (!self::here()) { return; }
            add_options_page('عملاء الخرائط', 'عملاء الخرائط', 'manage_options', self::PAGE, [__CLASS__, 'page']);
        });
        add_action('admin_post_mutqan_leads_save', [__CLASS__, 'save']);
        add_action('admin_post_mutqan_leads_token', [__CLASS__, 'new_token']);
        add_action('admin_post_mutqan_leads_export', [__CLASS__, 'export']);
        add_action('admin_post_mutqan_leads_mark', [__CLASS__, 'mark']);
    }

    /** The hub keeps the list; every other face shows nothing. */
    private static function here() { return !class_exists('Oxygen_Network') || Oxygen_Network::is_hub(); }

    public static function table() { global $wpdb; return $wpdb->prefix . 'mutqan_leads'; }

    public static function install() {
        if (get_option(self::DBV) === '1') { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table(); $cs = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $t (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  phone varchar(20) NOT NULL,
  name varchar(190) NOT NULL DEFAULT '',
  city varchar(20) NOT NULL DEFAULT '',
  field varchar(10) NOT NULL DEFAULT '',
  category varchar(120) NOT NULL DEFAULT '',
  address varchar(255) NOT NULL DEFAULT '',
  website varchar(255) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  rating decimal(3,1) NULL,
  reviews int(11) NULL,
  lat decimal(10,7) NULL,
  lng decimal(10,7) NULL,
  link varchar(500) NOT NULL DEFAULT '',
  query varchar(190) NOT NULL DEFAULT '',
  mobile tinyint(1) NOT NULL DEFAULT 0,
  status varchar(12) NOT NULL DEFAULT 'new',
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY phone (phone),
  KEY cf (city, field, status)
) $cs;");
        update_option(self::DBV, '1', false);
    }

    public static function defaults() {
        return [
            'on' => 1,
            'depth' => 6,
            'cities' => [
                'makkah' => ['on' => 1, 'name' => 'مكة المكرمة', 'areas' => "العزيزية\nالشرائع\nالنسيم\nالعوالي\nالزاهر\nالرصيفة\nالكعكية\nبطحاء قريش\nالشوقية\nالعتيبية\nالمسفلة\nالحمراء\nالنوارية"],
                'jeddah' => ['on' => 1, 'name' => 'جدة', 'areas' => "الصفا\nالسامر\nالروضة\nالحمدانية\nالنعيم\nالبوادي\nالمروة\nالفيصلية\nالعزيزية\nالشاطئ\nالبلد\nالربوة\nأبحر"],
            ],
            'fields' => [
                'ac' => ['on' => 1, 'name' => 'تكييف', 'kinds' => "فندق\nشقق مفروشة\nعمارة سكنية\nشركة\nمدرسة أهلية\nمستوصف\nقاعة أفراح\nمعرض\nمكتب عقار"],
                'cold' => ['on' => 1, 'name' => 'غرف تبريد', 'kinds' => "مطعم\nسوبر ماركت\nملحمة\nمخبز\nكافيه\nإعاشة وتموين\nمستودع مواد غذائية\nخضار وفواكه\nمحل حلويات"],
            ],
            'token_hash' => '', 'last' => [],
        ];
    }

    public static function cfg() {
        $c = get_option(self::OPT, []);
        $c = is_array($c) ? $c : [];
        $d = self::defaults();
        foreach (['cities', 'fields'] as $k) { $c[$k] = array_replace_recursive($d[$k], is_array($c[$k] ?? null) ? $c[$k] : []); }
        return $c + $d;
    }

    private static function lines($s) { return array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', (string) $s)))); }

    /** Every phrase the scraper should search, tagged so each result knows its city and field. */
    public static function queries() {
        $c = self::cfg(); $out = [];
        foreach ($c['cities'] as $cid => $city) {
            if (empty($city['on'])) { continue; }
            $areas = self::lines($city['areas']);
            foreach ($c['fields'] as $fid => $f) {
                if (empty($f['on'])) { continue; }
                foreach (self::lines($f['kinds']) as $kind) {
                    $out[] = ['q' => $kind . ' ' . $city['name'], 'city' => $cid, 'field' => $fid, 'kind' => $kind];
                    foreach ($areas as $a) { $out[] = ['q' => $kind . ' حي ' . $a . ' ' . $city['name'], 'city' => $cid, 'field' => $fid, 'kind' => $kind]; }
                }
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------ REST (the workflow) */

    public static function routes() {
        register_rest_route('mutqan/v1', '/leads/config', ['methods' => 'GET', 'permission_callback' => [__CLASS__, 'token_ok'],
            'callback' => function () { $c = self::cfg(); return ['on' => !empty($c['on']), 'depth' => max(1, min(20, (int) $c['depth'])), 'queries' => self::queries()]; }]);
        register_rest_route('mutqan/v1', '/leads/import', ['methods' => 'POST', 'permission_callback' => [__CLASS__, 'token_ok'],
            'callback' => function ($req) { $b = json_decode((string) $req->get_body(), true); return self::import(is_array($b['rows'] ?? null) ? $b['rows'] : [], (string) ($b['run'] ?? '')); }]);
    }

    public static function token_ok($req) {
        if (!self::here()) { return false; }
        $h = (string) self::cfg()['token_hash'];
        $t = (string) $req->get_header('x-leads-token');
        return $h !== '' && strlen($t) >= 32 && hash_equals($h, hash('sha256', $t));
    }

    /** Saudi number → 9665XXXXXXXX (mobile) or 9661XXXXXXXX (landline); anything else is dropped. */
    public static function phone($v) {
        $d = preg_replace('/\D+/', '', strtr((string) $v, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
        if (strpos($d, '00966') === 0) { $d = substr($d, 2); }
        if (strpos($d, '966') !== 0) { $d = '966' . ltrim($d, '0'); }
        return preg_match('/^966(5\d{8}|1\d{7,8}|9200\d{5}|800\d{7})$/', $d) ? $d : '';
    }

    public static function import(array $rows, $run = '') {
        self::install();
        global $wpdb; $t = self::table(); $now = current_time('mysql', true);
        $new = 0; $seen = 0; $bad = 0;
        foreach (array_slice($rows, 0, 2000) as $r) {
            $p = self::phone($r['phone'] ?? '');
            if ($p === '' || !in_array($r['city'] ?? '', ['makkah', 'jeddah'], true) || !in_array($r['field'] ?? '', ['ac', 'cold'], true)) { $bad++; continue; }
            $s = function ($k, $n) use ($r) { return mb_substr(sanitize_text_field((string) ($r[$k] ?? '')), 0, $n); };
            $row = ['name' => $s('name', 190), 'category' => $s('category', 120), 'address' => $s('address', 255), 'website' => esc_url_raw(mb_substr((string) ($r['website'] ?? ''), 0, 255)),
                'email' => sanitize_email((string) ($r['email'] ?? '')), 'link' => esc_url_raw(mb_substr((string) ($r['link'] ?? ''), 0, 500)), 'query' => $s('query', 190),
                'rating' => is_numeric($r['rating'] ?? null) ? (float) $r['rating'] : null, 'reviews' => is_numeric($r['reviews'] ?? null) ? (int) $r['reviews'] : null,
                'lat' => is_numeric($r['lat'] ?? null) ? (float) $r['lat'] : null, 'lng' => is_numeric($r['lng'] ?? null) ? (float) $r['lng'] : null,
                'mobile' => strpos($p, '9665') === 0 ? 1 : 0, 'last_seen' => $now];
            $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE phone = %s", $p));
            if ($id) { $wpdb->update($t, $row, ['id' => (int) $id]); $seen++; }
            else { $wpdb->insert($t, $row + ['phone' => $p, 'city' => $r['city'], 'field' => $r['field'], 'status' => 'new', 'first_seen' => $now]); $new++; }
        }
        $c = self::cfg(); $c['last'] = ['at' => gmdate('c'), 'new' => $new, 'seen' => $seen, 'bad' => $bad, 'run' => mb_substr((string) $run, 0, 120)];
        update_option(self::OPT, $c, false);
        return ['ok' => true, 'new' => $new, 'updated' => $seen, 'skipped' => $bad];
    }

    /* ------------------------------------------------------------------ admin */

    public static function stats() {
        self::install();
        global $wpdb; $t = self::table();
        $out = [];
        foreach ((array) $wpdb->get_results("SELECT city, field, status, mobile, COUNT(*) n FROM $t GROUP BY city, field, status, mobile", ARRAY_A) as $r) {
            $k = $r['city'] . '|' . $r['field'];
            $out[$k] = $out[$k] ?? ['all' => 0, 'mobile' => 0, 'optout' => 0];
            $out[$k]['all'] += (int) $r['n'];
            if ((int) $r['mobile'] === 1 && $r['status'] !== 'optout') { $out[$k]['mobile'] += (int) $r['n']; }
            if ($r['status'] === 'optout') { $out[$k]['optout'] += (int) $r['n']; }
        }
        return $out;
    }

    private static function back($msg) { wp_safe_redirect(add_query_arg('mq_msg', rawurlencode($msg), admin_url('options-general.php?page=' . self::PAGE))); exit; }

    public static function save() {
        if (!current_user_can('manage_options')) { wp_die('غير مصرح.'); }
        check_admin_referer('mutqan_leads_save');
        $c = self::cfg(); $in = wp_unslash($_POST);
        $c['on'] = empty($in['on']) ? 0 : 1;
        $c['depth'] = max(1, min(20, (int) ($in['depth'] ?? 6)));
        foreach (['makkah', 'jeddah'] as $k) { $c['cities'][$k]['on'] = empty($in['city_' . $k]) ? 0 : 1; $c['cities'][$k]['areas'] = sanitize_textarea_field((string) ($in['areas_' . $k] ?? '')); }
        foreach (['ac', 'cold'] as $k) { $c['fields'][$k]['on'] = empty($in['field_' . $k]) ? 0 : 1; $c['fields'][$k]['kinds'] = sanitize_textarea_field((string) ($in['kinds_' . $k] ?? '')); }
        update_option(self::OPT, $c, false);
        self::back('اتحفظت الإعدادات. التشغيل الجاي هيستخدمها.');
    }

    /** A fresh run token, shown once; only its hash is kept. */
    public static function new_token() {
        if (!current_user_can('manage_options')) { wp_die('غير مصرح.'); }
        check_admin_referer('mutqan_leads_token');
        $tok = bin2hex(random_bytes(24));
        $c = self::cfg(); $c['token_hash'] = hash('sha256', $tok); update_option(self::OPT, $c, false);
        set_transient('mutqan_leads_tok_' . get_current_user_id(), $tok, 600);
        self::back('اتعمل مفتاح جديد — انسخه دلوقتي، مش هيظهر تاني.');
    }

    public static function mark() {
        if (!current_user_can('manage_options')) { wp_die('غير مصرح.'); }
        check_admin_referer('mutqan_leads_mark');
        self::install();
        global $wpdb; $n = 0;
        foreach (self::lines((string) wp_unslash($_POST['phones'] ?? '')) as $l) {
            $p = self::phone($l);
            if ($p !== '') { $n += (int) $wpdb->update(self::table(), ['status' => 'optout'], ['phone' => $p]); }
        }
        self::back('اتعلّم ' . $n . ' رقم «لا ترسل».');
    }

    /** CSV with a BOM so Excel shows the Arabic right. kind=wa: mobiles only, without opt-outs, ready for WhatsApp. */
    public static function export() {
        if (!current_user_can('manage_options')) { wp_die('غير مصرح.'); }
        check_admin_referer('mutqan_leads_export');
        self::install();
        global $wpdb; $t = self::table();
        $city = in_array($_GET['city'] ?? '', ['makkah', 'jeddah'], true) ? $_GET['city'] : '';
        $field = in_array($_GET['field'] ?? '', ['ac', 'cold'], true) ? $_GET['field'] : '';
        $wa = ($_GET['kind'] ?? '') === 'wa';
        $w = ['1=1']; $a = [];
        if ($city) { $w[] = 'city = %s'; $a[] = $city; }
        if ($field) { $w[] = 'field = %s'; $a[] = $field; }
        if ($wa) { $w[] = "mobile = 1 AND status <> 'optout'"; }
        $sql = "SELECT phone, name, city, field, category, address, website, email, rating, reviews, lat, lng, link, status, first_seen FROM $t WHERE " . implode(' AND ', $w) . ' ORDER BY reviews DESC';
        $rows = (array) $wpdb->get_results($a ? $wpdb->prepare($sql, $a) : $sql, ARRAY_A);
        $name = 'leads-' . ($city ?: 'all') . '-' . ($field ?: 'all') . ($wa ? '-whatsapp' : '') . '-' . gmdate('Y-m-d') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        $f = fopen('php://output', 'w');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, ['الرقم', 'الاسم', 'المدينة', 'المجال', 'التصنيف', 'العنوان', 'الموقع الإلكتروني', 'الإيميل', 'التقييم', 'عدد التقييمات', 'lat', 'lng', 'رابط الخريطة', 'الحالة', 'أول ظهور']);
        $cn = ['makkah' => 'مكة', 'jeddah' => 'جدة']; $fn = ['ac' => 'تكييف', 'cold' => 'غرف تبريد'];
        foreach ($rows as $r) {
            $r['city'] = $cn[$r['city']] ?? $r['city']; $r['field'] = $fn[$r['field']] ?? $r['field'];
            // a leading = + - @ would run as a formula in Excel
            fputcsv($f, array_map(function ($v) { $v = (string) $v; return preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v; }, array_values($r)));
        }
        fclose($f);
        exit;
    }

    public static function page() {
        if (!current_user_can('manage_options')) { return; }
        $c = self::cfg(); $st = self::stats();
        $tok = get_transient('mutqan_leads_tok_' . get_current_user_id());
        if ($tok) { delete_transient('mutqan_leads_tok_' . get_current_user_id()); }
        $msg = isset($_GET['mq_msg']) ? sanitize_text_field(wp_unslash($_GET['mq_msg'])) : '';
        $ex = function ($city, $field, $kind = '') { return wp_nonce_url(admin_url('admin-post.php?action=mutqan_leads_export&city=' . $city . '&field=' . $field . ($kind ? '&kind=' . $kind : '')), 'mutqan_leads_export'); };
        echo '<div class="wrap" dir="rtl"><h1>عملاء الخرائط — مكة وجدة</h1>';
        if ($msg) { echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>'; }
        echo '<p>بيانات أنشطة تجارية من خرائط جوجل (فنادق، مطاعم، سوبرماركت…) كعملاء محتملين للتكييف وغرف التبريد. السحب بيشتغل أسبوعيًا من GitHub بأداة مجانية مفتوحة المصدر، والنتايج بتنزل هنا وفي ملفات على GitHub.</p>';
        echo '<h2>الأرقام</h2><table class="widefat striped" style="max-width:760px"><thead><tr><th>المدينة</th><th>المجال</th><th>الكل</th><th>جوالات صالحة للواتساب</th><th>لا ترسل</th><th>تنزيل</th></tr></thead><tbody>';
        foreach (['makkah' => 'مكة', 'jeddah' => 'جدة'] as $ck => $cn) {
            foreach (['ac' => 'تكييف', 'cold' => 'غرف تبريد'] as $fk => $fn) {
                $s = $st[$ck . '|' . $fk] ?? ['all' => 0, 'mobile' => 0, 'optout' => 0];
                echo '<tr><td>' . esc_html($cn) . '</td><td>' . esc_html($fn) . '</td><td>' . (int) $s['all'] . '</td><td>' . (int) $s['mobile'] . '</td><td>' . (int) $s['optout'] . '</td><td><a class="button" href="' . esc_url($ex($ck, $fk)) . '">إكسل كامل</a> <a class="button" href="' . esc_url($ex($ck, $fk, 'wa')) . '">قائمة واتساب</a></td></tr>';
            }
        }
        echo '</tbody></table>';
        $l = $c['last'];
        echo '<p>آخر تشغيل: ' . (!empty($l['at']) ? esc_html($l['at']) . ' — جديد ' . (int) $l['new'] . '، اتحدّث ' . (int) $l['seen'] . '، اترفض ' . (int) $l['bad'] : 'لسه ما اشتغلش') . '</p>';

        echo '<h2>الإعدادات</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('mutqan_leads_save');
        echo '<input type="hidden" name="action" value="mutqan_leads_save"><table class="form-table">';
        echo '<tr><th>تشغيل السحب</th><td><label><input type="checkbox" name="on" value="1"' . checked(!empty($c['on']), true, false) . '> شغّال</label></td></tr>';
        echo '<tr><th>العمق (صفحات لكل بحث)</th><td><input type="number" min="1" max="20" name="depth" value="' . (int) $c['depth'] . '"></td></tr>';
        foreach ($c['cities'] as $k => $city) {
            echo '<tr><th>' . esc_html($city['name']) . '</th><td><label><input type="checkbox" name="city_' . esc_attr($k) . '" value="1"' . checked(!empty($city['on']), true, false) . '> مفعّلة</label><br><small>الأحياء (سطر لكل حي):</small><br><textarea name="areas_' . esc_attr($k) . '" rows="6" cols="40">' . esc_textarea($city['areas']) . '</textarea></td></tr>';
        }
        foreach ($c['fields'] as $k => $f) {
            echo '<tr><th>' . esc_html($f['name']) . '</th><td><label><input type="checkbox" name="field_' . esc_attr($k) . '" value="1"' . checked(!empty($f['on']), true, false) . '> مفعّل</label><br><small>أنواع العملاء اللي بندور عليهم (سطر لكل نوع):</small><br><textarea name="kinds_' . esc_attr($k) . '" rows="8" cols="40">' . esc_textarea($f['kinds']) . '</textarea></td></tr>';
        }
        echo '</table><p>عدد عمليات البحث مع الإعدادات دي: <b>' . count(self::queries()) . '</b></p>';
        submit_button('حفظ');
        echo '</form>';

        echo '<h2>مفتاح التشغيل</h2>';
        if ($tok) { echo '<p><b>المفتاح الجديد:</b> <code style="font-size:14px;user-select:all">' . esc_html($tok) . '</code><br>حطه في GitHub → المستودع mutaqin-oxygen-app → Settings → Secrets → Actions باسم <code>LEADS_TOKEN</code>.</p>'; }
        else { echo '<p>' . ($c['token_hash'] ? 'فيه مفتاح متسجّل.' : 'لسه مفيش مفتاح — من غيره السحب بيستخدم الإعدادات الافتراضية ومش بيرفع النتايج هنا.') . '</p>'; }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('mutqan_leads_token');
        echo '<input type="hidden" name="action" value="mutqan_leads_token">';
        submit_button($c['token_hash'] ? 'مفتاح جديد (القديم يتلغي)' : 'اعمل مفتاح', 'secondary');
        echo '</form>';

        echo '<h2>لا ترسل</h2><p>أي حد طلب إننا ما نبعتلوش: حط رقمه هنا (سطر لكل رقم) وهيتشال من قوائم الواتساب للأبد.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('mutqan_leads_mark');
        echo '<input type="hidden" name="action" value="mutqan_leads_mark"><textarea name="phones" rows="4" cols="40" dir="ltr"></textarea>';
        submit_button('تعليم «لا ترسل»', 'secondary');
        echo '</form></div>';
    }
}
