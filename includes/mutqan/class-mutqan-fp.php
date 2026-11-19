<?php
/**
 * The network's memory of every article (owner order 2026-11-19: «no spam on Google»).
 *
 * One store on the hub (oxygen11.com) holds a fingerprint (MinHash of 5-word shingles) of every article published by any
 * of the WordPress sites or the Blogger blogs. Before a site publishes, it asks: «how close is this to anything already
 * out there?». Brand names are taken out before fingerprinting, so the same text with only the site name swapped counts
 * as the copy it is. Faces call the hub over REST (/mutqan/v1/fp); the hub answers locally.
 *   check      → {sim, url, site, at}      the closest article already stored (sim 0..1)
 *   add        → stores this article (once per URL)
 *   check_add  → both, in that order
 *   rewrite    → the hub's free AI rewrites a too-similar article in the site's own voice (daily cap)
 */
if (!defined('ABSPATH')) { exit; }

final class Mutqan_Fp {
    const HUB = 'https://oxygen11.com/wp-json/mutqan/v1/fp';
    const PERMS = 64;
    const BANDS = 16;          // 16 bands × 4 rows: pairs above ~0.45 almost always meet in a band
    const P = 2147483647;
    const REWRITE_CAP = 600;   // AI rewrites per day on the hub

    /* ------------------------------------------------------------------ text → fingerprint */

    /** Plain words of the article, brand names removed, Arabic spelling folded. */
    public static function words($html) {
        $t = html_entity_decode(wp_strip_all_tags((string) $html), ENT_QUOTES, 'UTF-8');
        $t = str_replace(['أ', 'إ', 'آ', 'ة', 'ى', 'ـ'], ['ا', 'ا', 'ا', 'ه', 'ي', ''], $t);
        $t = preg_replace('/[\x{064B}-\x{065F}]/u', '', $t);
        foreach (self::brand_words() as $b) { $t = str_replace($b, ' ', $t); }
        $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($t));
        return array_values(array_filter(explode(' ', $t), function ($w) { return mb_strlen($w) > 1; }));
    }

    private static function brand_words() {
        static $b = null;
        if ($b !== null) { return $b; }
        $b = ['للصيانة والمقاولات العامه', 'للصيانه والمقاولات العامه', 'مجموعه'];
        if (class_exists('Oxygen_Network')) {
            foreach (array_merge(Oxygen_Network::registry(), Oxygen_Network::blog_brands()) as $x) {
                $n = str_replace(['أ', 'إ', 'آ', 'ة', 'ى'], ['ا', 'ا', 'ا', 'ه', 'ي'], (string) ($x['name'] ?? ''));
                if ($n !== '') { $b[] = $n; }
            }
        }
        usort($b, function ($x, $y) { return mb_strlen($y) - mb_strlen($x); });
        return $b;
    }

    private static function coeffs() {
        static $c = null;
        if ($c !== null) { return $c; }
        $c = [];
        $s = 2654435761;
        for ($i = 0; $i < self::PERMS; $i++) {
            $s = ($s * 1103515245 + 12345) % 4294967296; $a = 1 + ($s % (self::P - 1));
            $s = ($s * 1103515245 + 12345) % 4294967296; $bb = $s % self::P;
            $c[] = [$a, $bb];
        }
        return $c;
    }

    /** MinHash signature (64 ints), or null for a text too short to judge. */
    public static function sig($html) {
        $w = self::words($html);
        if (count($w) < 60) { return null; }
        $hs = [];
        for ($i = 0, $n = count($w) - 4; $i < $n; $i++) { $hs[crc32($w[$i] . ' ' . $w[$i + 1] . ' ' . $w[$i + 2] . ' ' . $w[$i + 3] . ' ' . $w[$i + 4])] = 1; }
        $hs = array_keys($hs);
        $sig = [];
        foreach (self::coeffs() as $ab) {
            $m = PHP_INT_MAX;
            foreach ($hs as $h) { $v = ($ab[0] * ($h % self::P) + $ab[1]) % self::P; if ($v < $m) { $m = $v; } }
            $sig[] = $m;
        }
        return $sig;
    }

    private static function bands($sig) {
        $r = intdiv(self::PERMS, self::BANDS); $out = [];
        for ($i = 0; $i < self::BANDS; $i++) { $out[] = substr(md5($i . ':' . implode(',', array_slice($sig, $i * $r, $r))), 0, 14); }
        return $out;
    }

    public static function est($a, $b) { $s = 0; for ($i = 0; $i < self::PERMS; $i++) { if ((int) $a[$i] === (int) $b[$i]) { $s++; } } return $s / self::PERMS; }

    /* ------------------------------------------------------------------ the store (hub only) */

    private static function t($n) { global $wpdb; return $wpdb->prefix . 'mutqan_' . $n; }

    private static function ensure() {
        if (get_option('mutqan_fp_db') === '1') { return; }
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE IF NOT EXISTS " . self::t('fp') . " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, site VARCHAR(24) NOT NULL, url VARCHAR(255) NOT NULL, k VARCHAR(100) NOT NULL DEFAULT '', created DATETIME NOT NULL, sig TEXT NOT NULL, PRIMARY KEY (id), UNIQUE KEY url (url(191)), KEY site (site)) $c");
        $wpdb->query("CREATE TABLE IF NOT EXISTS " . self::t('fpb') . " (b CHAR(14) NOT NULL, fp BIGINT UNSIGNED NOT NULL, KEY b (b)) $c");
        update_option('mutqan_fp_db', '1', false);
    }

    /** The closest stored article (other than this URL). */
    public static function check_local($sig, $url = '') {
        self::ensure();
        global $wpdb;
        $bands = self::bands($sig);
        $ph = implode(',', array_fill(0, count($bands), '%s'));
        $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT fp FROM " . self::t('fpb') . " WHERE b IN ($ph) LIMIT 400", $bands));
        $best = ['sim' => 0.0, 'url' => '', 'site' => '', 'at' => ''];
        if (!$ids) { return $best; }
        $rows = $wpdb->get_results("SELECT site, url, created, sig FROM " . self::t('fp') . " WHERE id IN (" . implode(',', array_map('intval', $ids)) . ")", ARRAY_A);
        foreach ($rows as $r) {
            if ($url !== '' && $r['url'] === $url) { continue; }
            $s = self::est($sig, json_decode($r['sig'], true) ?: []);
            if ($s > $best['sim']) { $best = ['sim' => round($s, 3), 'url' => $r['url'], 'site' => $r['site'], 'at' => $r['created']]; }
        }
        return $best;
    }

    public static function add_local($sig, $site, $url, $key = '', $at = '') {
        self::ensure();
        global $wpdb;
        if ($url === '' || $wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::t('fp') . " WHERE url = %s", $url))) { return false; }
        $wpdb->insert(self::t('fp'), ['site' => mb_substr($site, 0, 24), 'url' => mb_substr($url, 0, 255), 'k' => mb_substr($key, 0, 100), 'created' => $at ?: current_time('mysql', true), 'sig' => wp_json_encode($sig)]);
        $id = (int) $wpdb->insert_id;
        if ($id) { foreach (self::bands($sig) as $b) { $wpdb->insert(self::t('fpb'), ['b' => $b, 'fp' => $id]); } }
        return (bool) $id;
    }

    public static function stats() {
        self::ensure();
        global $wpdb;
        return ['articles' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::t('fp')), 'bySite' => $wpdb->get_results("SELECT site, COUNT(*) n FROM " . self::t('fp') . " GROUP BY site ORDER BY n DESC", ARRAY_A),
            'rewritesToday' => (int) get_transient('mq_fp_rw_' . gmdate('Ymd'))];
    }

    /* ------------------------------------------------------------------ REST (hub) */

    private static function is_hub() { return class_exists('Oxygen_Network') && Oxygen_Network::current_id() === 'oxygen'; }

    public static function handle($in, $p) {
        if (!self::is_hub()) { throw new Mutqan_Error('forbidden', 'الخدمة على الموقع الرئيسي بس.', 403); }
        $op = (string) ($in['op'] ?? 'check');
        if ($op === 'stats') { return self::stats(); }
        Mutqan_Limiter::hit('fp', 3000, 3600);
        $site = preg_match('/^[a-z][a-z0-9]{1,23}$/', (string) ($in['site'] ?? '')) ? (string) $in['site'] : '';
        $url = esc_url_raw(mb_substr((string) ($in['url'] ?? ''), 0, 255));
        if ($url !== '' && !preg_match('#^https?://([a-z0-9-]+\.)*(oxygen11\.com|blogspot\.com)/#i', $url)) { throw new Mutqan_Error('bad_input', 'رابط خارج الشبكة.', 400); }
        $text = mb_substr((string) ($in['text'] ?? ''), 0, 80000);
        if ($op === 'rewrite') { return self::rewrite_local($text, (string) ($in['title'] ?? ''), (string) ($in['brand'] ?? ''), (string) ($in['angle'] ?? '')); }
        $sig = self::sig($text);
        if (!$sig) { return ['sim' => 0, 'short' => true]; }
        $out = ['sim' => 0];
        if ($op === 'check' || $op === 'check_add') { $out = self::check_local($sig, $url); }
        if (($op === 'add' || $op === 'check_add') && $site !== '' && $url !== '' && empty($in['dry'])) { $out['added'] = self::add_local($sig, $site, $url, (string) ($in['key'] ?? ''), (string) ($in['at'] ?? '')); }
        return $out;
    }

    /* ------------------------------------------------------------------ for every site (local on the hub, REST elsewhere) */

    public static function call($op, $args) {
        if (self::is_hub()) {
            try { return self::handle(['op' => $op] + $args, ['id' => 'system', 'role' => 'owner', 'kind' => 'user']); } catch (Throwable $e) { return null; }
        }
        $r = wp_remote_post(self::HUB, ['timeout' => $op === 'rewrite' ? 90 : 15, 'headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode(['op' => $op] + $args)]);
        if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) { return null; }
        $j = json_decode((string) wp_remote_retrieve_body($r), true);
        return is_array($j) ? ($j['data'] ?? null) : null;
    }

    /** A full rewrite in the site's own voice: new structure, new sentences, same facts. Returns HTML or ''. */
    public static function rewrite_local($html, $title, $brand, $angle) {
        if (!class_exists('Mutqan_AI') || !Mutqan_AI::any_enabled()) { return ['html' => '']; }
        $k = 'mq_fp_rw_' . gmdate('Ymd'); $n = (int) get_transient($k);
        if ($n >= self::REWRITE_CAP) { return ['html' => '', 'cap' => true]; }
        set_transient($k, $n + 1, 2 * DAY_IN_SECONDS);
        $plain = mb_substr(trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($html))), 0, 9000);
        $prompt = "أنت كاتب محتوى عربي محترف لموقع خدمات صيانة اسمه «" . $brand . "»" . ($angle !== '' ? " وتخصصه المميز: " . $angle : '') . ".\n"
            . "المقال التالي مكتوب بقالب يشبه مواقع أخرى. أعد كتابته بالكامل بأسلوب جديد تمامًا حتى لا يشبه أي مقال آخر على الإنترنت:\n"
            . "- رتّب الأقسام ترتيبًا مختلفًا، واكتب عناوين H2 جديدة، وجمل جديدة كليًا (لا تنسخ أي جملة).\n"
            . "- أضف تفاصيل عملية حقيقية وأمثلة محلية من مكة وجدة وخطوات وأسئلة شائعة جديدة، من زاوية «" . ($angle ?: $brand) . "».\n"
            . "- ممنوع ذكر أسعار أو أرقام هواتف أو نسب أو تراخيص أو سنوات خبرة أو تقييمات، وممنوع اختراع قصص عملاء.\n"
            . "- الطول 1200 كلمة على الأقل. أرجع HTML فقط (h2, h3, p, ul, li, strong) بدون أي شرح وبدون ```.\n\n"
            . "العنوان: " . $title . "\n\nالمقال:\n" . $plain;
        try { $r = Mutqan_AI::run($prompt, ['tier' => 'low', 'temperature' => 0.8, 'feature' => 'articles', 'budget' => 6], ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user']); }
        catch (Throwable $e) { return ['html' => '']; }
        $out = trim(preg_replace('/^```(?:html)?|```$/m', '', (string) ($r['text'] ?? '')));
        $out = wp_kses($out, ['h2' => [], 'h3' => [], 'p' => [], 'ul' => [], 'ol' => [], 'li' => [], 'strong' => [], 'em' => [], 'br' => []]);
        return ['html' => count(self::words($out)) >= 700 ? $out : '', 'provider' => (string) ($r['provider'] ?? '')];
    }
}
