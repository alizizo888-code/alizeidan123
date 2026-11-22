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
    const FIX = 'oxygen_uniq_fix';      // hourly: held drafts and noindexed copies get an AI rewrite and go (back) out
    const FIX_BATCH = 5;
    const FIX_TRIES = 3;

    public static function boot() {
        add_action('init', function () { if (!wp_next_scheduled(self::CRON)) { wp_schedule_event(time() + 300 + wp_rand(0, 1800), 'hourly', self::CRON); } });
        add_action(self::CRON, [__CLASS__, 'backfill']);
        add_action('init', function () { if (!wp_next_scheduled(self::FIX)) { wp_schedule_event(time() + 120 + wp_rand(0, 600), 'hourly', self::FIX); } });
        add_action(self::FIX, [__CLASS__, 'fix']);
        // Similarity is a repair signal only. Never hide a page, noindex it, or remove it from a sitemap.
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
        // too close to something already out there: a rewrite in this site's own voice (local AI first, then the hub's).
        // A long rewrite takes up to a minute or two, so one request does one inline; the rest wait as drafts for fix().
        static $inline = 0;
        $title = (string) ($item['title'] ?? '');
        if ($inline < 1) {
            $inline++;
            $new = self::rewrite_html($b['html'], $title);
            if ($new !== '') {
                $r2 = Mutqan_Fp::call('check', ['text' => $new]);
                $s2 = is_array($r2) ? (float) ($r2['sim'] ?? 1) : 1.0;
                if ($s2 < self::GATE) { return ['html' => $new, 'status' => null, 'sim' => $s2, 'rewritten' => true]; }
                $out['sim'] = $s2;
            }
        }
        // Keep the publishing pipeline flowing if rewriting is unavailable or still too similar.
        // Record the issue for repair; do not move the article to draft or suppress its URL.
        self::note('published_needs_diversity_repair', $title, $out['sim'], (string) ($r['url'] ?? ''));
        $out['status'] = null;
        return $out;
    }

    /** The article rewritten by the AI in this site's voice (the cover image kept on top), or '' when the AI could not. */
    private static function rewrite_html($html, $title) {
        $angle = class_exists('Oxygen_Network_SEO') && defined('Oxygen_Network_SEO::HOOKS') ? (Oxygen_Network_SEO::HOOKS[self::site()] ?? '') : '';
        $rw = (class_exists('Mutqan_AI') && Mutqan_AI::any_enabled()) ? Mutqan_Fp::rewrite_local($html, $title, self::brand(), $angle) : Mutqan_Fp::call('rewrite', ['text' => $html, 'title' => $title, 'brand' => self::brand(), 'angle' => $angle]);
        $new = is_array($rw) ? (string) ($rw['html'] ?? '') : '';
        if ($new === '') { return ''; }
        $img = preg_match('#^\s*<p><img[^>]+></p>#u', (string) $html, $m) ? $m[0] : '';
        return $img . $new;
    }

    /**
     * Hourly repair (2026-11-21): every article the gate held as a draft, then every published article taken out of the
     * index as a copy, gets a full AI rewrite. Unique now (< GATE) → a held draft is published, a noindexed article goes
     * back into the index with its new text. Still too close after FIX_TRIES attempts → it stays as it is.
     */
    public static function fix() {
        if (!class_exists('Mutqan_Fp') || get_transient('oxygen_uniq_fix_lock')) { return; }
        set_transient('oxygen_uniq_fix_lock', 1, 15 * MINUTE_IN_SECONDS);
        if (function_exists('set_time_limit')) { @set_time_limit(900); }
        $t0 = time(); $done = 0; $pub = 0; $back = 0;
        $tries = ['relation' => 'OR', ['key' => '_oxy_uniq_tries', 'compare' => 'NOT EXISTS'], ['key' => '_oxy_uniq_tries', 'value' => self::FIX_TRIES, 'compare' => '<', 'type' => 'NUMERIC']];
        $held = get_posts(['post_type' => 'post', 'post_status' => 'draft', 'numberposts' => self::FIX_BATCH, 'orderby' => 'date', 'order' => 'ASC', 'fields' => 'ids',
            'meta_query' => [['key' => '_oxy_held_dup', 'compare' => 'EXISTS'], $tries]]);
        // the old daily engine left a batch as drafts (2026-11-15: 120 short articles, some titles «في مكة المكرمة في جدة»)
        $legacy = count($held) < self::FIX_BATCH ? get_posts(['post_type' => 'post', 'post_status' => 'draft', 'numberposts' => self::FIX_BATCH - count($held), 'orderby' => 'date', 'order' => 'ASC', 'fields' => 'ids',
            'meta_query' => [['key' => '_oxygen_ac_managed', 'compare' => 'EXISTS'], ['key' => '_oxy_held_dup', 'compare' => 'NOT EXISTS'], $tries]]) : [];
        $held = array_merge($held, $legacy);
        $dups = count($held) < self::FIX_BATCH ? get_posts(['post_type' => 'post', 'post_status' => 'publish', 'numberposts' => self::FIX_BATCH - count($held), 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids',
            'meta_query' => [['key' => '_oxy_dup', 'compare' => 'EXISTS'], $tries]]) : [];
        foreach (array_merge($held, $dups) as $id) {
            if (time() - $t0 > 600) { break; }
            $p = get_post($id);
            if (!$p) { continue; }
            $live = $p->post_status === 'publish';
            $new = self::rewrite_html($p->post_content, $p->post_title);
            $url = $live ? (string) get_permalink($p) : '';
            $r = $new !== '' ? Mutqan_Fp::call('check', ['text' => $new, 'url' => $url]) : null;
            $sim = is_array($r) ? (float) ($r['sim'] ?? 1) : 1.0;
            if ($new === '' || $sim >= self::GATE) {
                $attempts = (int) get_post_meta($id, '_oxy_uniq_tries', true) + 1;
                update_post_meta($id, '_oxy_uniq_tries', $attempts);
                if ($attempts >= self::FIX_TRIES) {
                    // Last-resort availability guarantee: keep the URL public and indexable.
                    if (!$live) {
                        $now = current_time('mysql');
                        wp_update_post(['ID' => $id, 'post_status' => 'publish', 'post_date' => $now, 'post_date_gmt' => get_gmt_from_date($now), 'edit_date' => true]);
                        delete_post_meta($id, '_oxy_held_dup');
                        self::after($id, ['sim' => $sim, 'rewritten' => false, 'status' => null]);
                        self::note('published_after_repair_attempts', $p->post_title, $sim, $url);
                    } else {
                        delete_post_meta($id, '_oxy_dup');
                        delete_post_meta($id, '_yoast_wpseo_meta-robots-noindex');
                        self::note('visibility_restored_after_repair_attempts', $p->post_title, $sim, $url);
                    }
                    delete_post_meta($id, '_oxy_uniq_tries');
                }
                if ($new === '' && !is_array($r)) { break; }   // the AI / the hub is not answering now: try next hour
                continue;
            }
            $upd = ['ID' => $id, 'post_content' => $new];
            $tt = self::clean_title($p->post_title); if ($tt !== $p->post_title) { $upd['post_title'] = $tt; }
            if (!$live) { $now = current_time('mysql'); $upd += ['post_status' => 'publish', 'post_date' => $now, 'post_date_gmt' => get_gmt_from_date($now), 'edit_date' => true]; }
            $ok = wp_update_post($upd, true);
            if (is_wp_error($ok) || !$ok) { continue; }
            update_post_meta($id, '_oxy_rewritten', 1);
            update_post_meta($id, '_oxy_fp_sim', $sim);
            delete_post_meta($id, '_oxy_uniq_tries');
            if ($live) {
                delete_post_meta($id, '_oxy_dup'); delete_post_meta($id, '_yoast_wpseo_meta-robots-noindex');
                Mutqan_Fp::call('replace', ['site' => self::site(), 'url' => $url, 'key' => (string) get_post_meta($id, '_oxy_seo_key', true), 'text' => $new, 'at' => $p->post_date_gmt]);
                update_post_meta($id, '_oxy_fp_done', 1);
                $back++;
            } else {
                delete_post_meta($id, '_oxy_held_dup');
                self::after($id, ['sim' => $sim, 'rewritten' => true, 'status' => null]);
                $pub++;
            }
            if (class_exists('Oxygen_SEO_Engine')) { Oxygen_SEO_Engine::indexnow([get_permalink($id)]); }
            $done++;
        }
        if ($done) { self::note('fixed', "$pub drafts published, $back back in the index", 0, ''); }
        delete_transient('oxygen_uniq_fix_lock');
    }

    /** «في مكة المكرمة في جدة» → «في مكة المكرمة وجدة»; doubled spaces and a doubled «في» go. */
    public static function clean_title($t) {
        $t = preg_replace('/في\s+(مكة المكرمة|مكة|جدة)\s+في\s+(مكة المكرمة|مكة|جدة)/u', 'في $1 و$2', (string) $t);
        $t = preg_replace('/\bفي\s+في\b/u', 'في', $t);
        return trim(preg_replace('/\s{2,}/u', ' ', $t));
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
            // Record high similarity for editorial repair, but never apply noindex or remove the URL.
            if ($s >= self::NOINDEX && !empty($r['at']) && strtotime((string) $r['at']) <= strtotime($p->post_date_gmt . ' UTC')) {
                update_post_meta($p->ID, '_oxy_similarity_review', wp_json_encode(['sim' => $s, 'reference' => (string) ($r['url'] ?? ''), 'at' => gmdate('c')]));
                $dups++;
            }
            update_post_meta($p->ID, '_oxy_fp_done', 1);
            $n++;
        }
        if ($n) { self::note('backfill', "$n checked, $dups flagged for content improvement; no pages hidden", 0, ''); }
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
        return ['checked' => $c('_oxy_fp_done'), 'noindexed' => $c('_oxy_dup'), 'held' => $c('_oxy_held_dup'), 'rewritten' => $c('_oxy_rewritten'), 'given_up' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_oxy_uniq_tries' AND CAST(meta_value AS UNSIGNED) >= %d", self::FIX_TRIES)), 'log' => array_slice(array_reverse((array) get_option('oxygen_uniq_log', [])), 0, 20)];
    }
}
Oxygen_Uniq::boot();
