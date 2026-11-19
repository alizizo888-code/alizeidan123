<?php
/**
 * One article, one place on the web (owner order 2026-11-19: stop the duplicate / near-duplicate content that reads as
 * spam to Google). Runs on every WordPress site of the network, with the network's memory on the hub (Mutqan_Fp).
 *
 * 1. Before publishing: the new article is compared with every article of every site and blog. Too close (≥ GATE)
 *    → the AI rewrites it in this site's own voice and it is checked again; still too close → kept as a draft, not published.
 * 2. Older articles, a batch every hour, oldest first: each is registered; one that copies an article another site
 *    published earlier (≥ NOINDEX) is taken out of Google's index (noindex + out of the sitemap). Nothing is deleted.
 */
if (!defined('ABSPATH')) { exit; }

final class Oxygen_Uniq {
    const GATE = 0.30;
    const NOINDEX = 0.50;
    const CRON = 'oxygen_uniq_backfill';
    const BATCH = 25;

    public static function boot() {
        add_action('init', function () { if (!wp_next_scheduled(self::CRON)) { wp_schedule_event(time() + 300 + wp_rand(0, 1800), 'hourly', self::CRON); } });
        add_action(self::CRON, [__CLASS__, 'backfill']);
        // a copy that was taken out stays out: robots meta, Yoast, core sitemaps
        add_filter('wp_robots', function ($r) { if (is_singular() && self::is_dup(get_queried_object_id())) { $r['noindex'] = true; $r['follow'] = true; unset($r['index'], $r['max-image-preview']); } return $r; }, 99);
        add_filter('wpseo_robots', function ($s) { return is_singular() && self::is_dup(get_queried_object_id()) ? 'noindex, follow' : $s; }, 99);
        add_filter('wpseo_exclude_from_sitemap_by_post_ids', function ($ids) { return array_values(array_unique(array_merge((array) $ids, self::dup_ids()))); });
        add_filter('wp_sitemaps_posts_query_args', function ($a) { $x = self::dup_ids(); if ($x) { $a['post__not_in'] = array_merge((array) ($a['post__not_in'] ?? []), $x); } return $a; });
    }

    public static function is_dup($id) { return $id && get_post_meta($id, '_oxy_dup', true) !== ''; }

    private static function dup_ids() {
        static $ids = null;
        if ($ids === null) { global $wpdb; $ids = array_map('intval', $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_oxy_dup' LIMIT 5000")); }
        return $ids;
    }

    private static function site() { return class_exists('Oxygen_Network') ? (string) Oxygen_Network::current_id() : 'oxygen'; }
    private static function brand() { return class_exists('Oxygen_SEO_Engine') ? Oxygen_SEO_Engine::brand() : get_bloginfo('name'); }

    /**
     * Before a new article goes out. $b = ['html'=>…]. Returns ['html'=>…, 'status'=>null|'draft', 'sim'=>float, 'rewritten'=>bool].
     * When the hub cannot be reached the article goes out as before (the hourly pass catches a copy later).
     */
    public static function gate($item, $b) {
        $out = ['html' => $b['html'], 'status' => null, 'sim' => 0.0, 'rewritten' => false];
        if (!class_exists('Mutqan_Fp')) { return $out; }
        $r = Mutqan_Fp::call('check', ['text' => $b['html']]);
        if (!is_array($r)) { return $out; }
        $out['sim'] = (float) ($r['sim'] ?? 0);
        if ($out['sim'] < self::GATE) { return $out; }
        // too close to something already out there: a rewrite in this site's own voice (local AI first, then the hub's)
        $angle = class_exists('Oxygen_Network_SEO') && defined('Oxygen_Network_SEO::HOOKS') ? (Oxygen_Network_SEO::HOOKS[self::site()] ?? '') : '';
        $title = (string) ($item['title'] ?? '');
        $rw = (class_exists('Mutqan_AI') && Mutqan_AI::any_enabled()) ? Mutqan_Fp::rewrite_local($b['html'], $title, self::brand(), $angle) : Mutqan_Fp::call('rewrite', ['text' => $b['html'], 'title' => $title, 'brand' => self::brand(), 'angle' => $angle]);
        $new = is_array($rw) ? (string) ($rw['html'] ?? '') : '';
        if ($new !== '') {
            $img = preg_match('#^\s*<p><img[^>]+></p>#u', $b['html'], $m) ? $m[0] : '';
            $r2 = Mutqan_Fp::call('check', ['text' => $new]);
            $s2 = is_array($r2) ? (float) ($r2['sim'] ?? 1) : 1.0;
            if ($s2 < self::GATE) { return ['html' => $img . $new, 'status' => null, 'sim' => $s2, 'rewritten' => true]; }
            $out['sim'] = $s2;
        }
        $out['status'] = 'draft';
        self::note('held', $title, $out['sim'], (string) ($r['url'] ?? ''));
        return $out;
    }

    /** After the insert: a published article joins the network's memory. */
    public static function after($id, $g) {
        if (!$id || !class_exists('Mutqan_Fp')) { return; }
        update_post_meta($id, '_oxy_fp_sim', is_array($g) ? (float) $g['sim'] : 0);
        if (is_array($g) && !empty($g['rewritten'])) { update_post_meta($id, '_oxy_rewritten', 1); }
        if (get_post_status($id) !== 'publish') { if (is_array($g) && $g['status'] === 'draft') { update_post_meta($id, '_oxy_held_dup', (float) $g['sim']); } return; }
        $p = get_post($id);
        Mutqan_Fp::call('add', ['site' => self::site(), 'url' => (string) get_permalink($id), 'key' => (string) get_post_meta($id, '_oxy_seo_key', true), 'text' => $p ? $p->post_content : '', 'at' => $p ? $p->post_date_gmt : '']);
        update_post_meta($id, '_oxy_fp_done', 1);
    }

    /** The hourly pass over articles published before the gate existed, oldest first. */
    public static function backfill() {
        if (!class_exists('Mutqan_Fp')) { return; }
        $q = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => self::BATCH, 'orderby' => 'date', 'order' => 'ASC', 'no_found_rows' => true,
            'meta_query' => [['key' => '_oxy_fp_done', 'compare' => 'NOT EXISTS']]]);
        $n = 0; $dups = 0;
        foreach ($q->posts as $p) {
            $r = Mutqan_Fp::call('check_add', ['site' => self::site(), 'url' => (string) get_permalink($p), 'key' => (string) get_post_meta($p->ID, '_oxy_seo_key', true), 'text' => $p->post_content, 'at' => $p->post_date_gmt]);
            if (!is_array($r)) { break; }   // hub away: try next hour
            $s = (float) ($r['sim'] ?? 0);
            update_post_meta($p->ID, '_oxy_fp_sim', $s);
            // a copy of something that was out BEFORE this post (another site's or our own) leaves Google's index
            if ($s >= self::NOINDEX && !empty($r['at']) && strtotime((string) $r['at']) <= strtotime($p->post_date_gmt . ' UTC')) {
                update_post_meta($p->ID, '_oxy_dup', wp_json_encode(['sim' => $s, 'of' => (string) $r['url'], 'at' => gmdate('c')]));
                update_post_meta($p->ID, '_yoast_wpseo_meta-robots-noindex', '1');
                $dups++;
            }
            update_post_meta($p->ID, '_oxy_fp_done', 1);
            $n++;
        }
        if ($n) { self::note('backfill', "$n checked, $dups taken out of the index", 0, ''); }
    }

    private static function note($what, $title, $sim, $of) {
        $l = get_option('oxygen_uniq_log', []); $l = is_array($l) ? $l : [];
        $l[] = ['at' => gmdate('c'), 'what' => $what, 'title' => mb_substr((string) $title, 0, 100), 'sim' => round((float) $sim, 2), 'of' => $of];
        update_option('oxygen_uniq_log', array_slice($l, -200), false);
    }

    /** For reports: how much of this site is unique. */
    public static function summary() {
        global $wpdb;
        $c = function ($k) use ($wpdb) { return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", $k)); };
        return ['checked' => $c('_oxy_fp_done'), 'noindexed' => $c('_oxy_dup'), 'held' => $c('_oxy_held_dup'), 'rewritten' => $c('_oxy_rewritten'), 'log' => array_slice(array_reverse((array) get_option('oxygen_uniq_log', [])), 0, 20)];
    }
}
Oxygen_Uniq::boot();
