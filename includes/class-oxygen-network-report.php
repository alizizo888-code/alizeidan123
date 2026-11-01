<?php
if (!defined('ABSPATH')) { exit; }
/**
 * Network health + publishing report, several times a day.
 *  - every site (satellites and hub) exposes a PUBLIC read-only summary at /wp-json/oxygen/v1/net-stats (counts and the latest
 *    public article only — nothing private);
 *  - the hub (oxygen11.com) collects all of them hourly and, at the configured hours (default 9 · 12 · 15 · 18 · 21, Riyadh time),
 *    sends one combined report to the owner: Telegram (if linked) + the admin alert centre / push inside the app.
 */
final class Oxygen_Network_Report {
    const OPT = 'oxygen_net_report';
    const LAST = 'oxygen_net_report_last';
    const CRON = 'oxygen_net_report_tick';

    public static function boot() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('init', function () { if (class_exists('Oxygen_Network') && Oxygen_Network::current_id() === 'oxygen' && !wp_next_scheduled(self::CRON)) { wp_schedule_event(time() + 900, 'hourly', self::CRON); } }, 6);
        add_action(self::CRON, [__CLASS__, 'maybe_send']);
    }

    public static function routes() {
        register_rest_route('oxygen/v1', '/net-stats', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function () { return rest_ensure_response(self::stats()); }]);
    }

    /** This site's own numbers. */
    public static function stats() {
        $id = Oxygen_Network::current_id();
        $q = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 'meta_key' => '_oxy_seo_key', 'posts_per_page' => 1, 'fields' => 'ids', 'date_query' => [['after' => wp_date('Y-m-d 00:00:00'), 'inclusive' => true]], 'no_found_rows' => false]);
        $today = (int) $q->found_posts;
        $t = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 'meta_key' => '_oxy_seo_key', 'posts_per_page' => 1, 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => false]);
        $last = $t->have_posts() ? ['title' => get_the_title($t->posts[0]), 'link' => get_permalink($t->posts[0]), 'at' => get_post_time('c', true, $t->posts[0])] : null;
        $sat = Oxygen_Network::is_satellite();
        $inx = get_option('oxygen_indexnow_day', []);
        $gsc = get_option('oxygen_gsc_tokens', []); if (is_string($gsc)) { $gsc = explode(',', $gsc); }
        return [
            'id' => $id, 'brand' => Oxygen_SEO_Engine::brand(), 'host' => (string) wp_parse_url(home_url('/'), PHP_URL_HOST), 'day' => wp_date('Y-m-d'),
            'phase' => $sat ? Oxygen_Network_SEO::phase() : 'core', 'day_no' => $sat ? Oxygen_Network_SEO::day_no() + 1 : null,
            'quota' => $sat ? Oxygen_Network_SEO::quota() : null, 'today' => $today, 'total' => (int) $t->found_posts, 'last' => $last,
            'indexnow_today' => (int) ($inx[wp_date('Y-m-d')] ?? 0), 'sitemap' => home_url('/wp-sitemap.xml'),
            'gsc_tokens' => count(array_filter(array_map('trim', (array) $gsc))), 'time' => time(),
        ];
    }

    public static function targets() { return array_values(array_filter(Oxygen_Network::ids(), function ($id) { return $id !== 'oxygen'; })); }

    /** Pull every site's summary. $fetch(host) returns the decoded array or null (injectable for tests). */
    public static function collect($fetch = null) {
        $fetch = $fetch ?: function ($host) {
            $r = wp_remote_get('https://' . $host . '/wp-json/oxygen/v1/net-stats', ['timeout' => 5, 'headers' => ['Accept' => 'application/json']]);
            if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) { return null; }
            $j = json_decode((string) wp_remote_retrieve_body($r), true);
            return is_array($j) ? $j : null;
        };
        $rows = [];
        foreach (self::targets() as $id) { $host = Oxygen_Network::host_of($id); $rows[$id] = ['host' => $host, 'name' => Oxygen_Network::registry()[$id]['name'], 'stats' => $fetch($host)]; }
        return $rows;
    }

    /** Pure: text of the report. */
    public static function format($rows, $own, $hour) {
        $up = 0; $down = []; $today = 0; $total = 0; $lines = [];
        foreach ($rows as $id => $r) {
            $s = $r['stats'];
            if (!$s) { $down[] = $r['name']; continue; }
            $up++; $today += (int) $s['today']; $total += (int) $s['total'];
            $ph = ($s['phase'] ?? '') === 'burst' ? '🚀 يوم ' . (int) $s['day_no'] : '🔁 معدل ثابت';
            $lines[] = '• ' . $r['name'] . ': ' . (int) $s['today'] . '/' . (int) ($s['quota'] ?? 0) . ' اليوم · ' . (int) $s['total'] . ' إجمالي · ' . $ph . ((int) ($s['gsc_tokens'] ?? 0) === 0 ? ' · ⚠️ بلا GSC' : '');
        }
        $h = '📡 تقرير شبكة المواقع — ' . sprintf('%02d:00', $hour) . "\n";
        $h .= 'يعمل ' . $up . ' من ' . count($rows) . ' موقعًا · مقالات اليوم في الشبكة: ' . ($today + (int) ($own['today'] ?? 0)) . ' · الإجمالي: ' . ($total + (int) ($own['total'] ?? 0)) . "\n";
        if ($own) { $h .= '• المركز (' . $own['brand'] . '): ' . (int) $own['today'] . " اليوم\n"; }
        if ($down) { $h .= '🔴 لا يستجيب: ' . implode('، ', $down) . "\n"; }
        return $h . "\n" . implode("\n", $lines);
    }

    public static function settings() { $o = get_option(self::OPT, []); return array_merge(['hours' => [9, 12, 15, 18, 21]], is_array($o) ? $o : []); }

    /** Hourly on the hub: send once per configured hour. */
    public static function maybe_send($fetch = null, $now_hour = null, $force = false, $own = null) {
        if (Oxygen_Network::current_id() !== 'oxygen') { return false; }
        $s = self::settings();
        $hour = $now_hour !== null ? (int) $now_hour : (int) wp_date('G');
        $key = wp_date('Y-m-d') . '@' . $hour;
        $last = get_option(self::LAST, []);
        if (!$force && (!in_array($hour, array_map('intval', (array) $s['hours']), true) || (is_array($last) && ($last['key'] ?? '') === $key))) { return false; }
        $rows = self::collect($fetch);
        $text = self::format($rows, $own !== null ? $own : self::stats(), $hour);
        update_option(self::LAST, ['key' => $key, 'at' => time(), 'text' => $text, 'rows' => array_map(function ($r) { return ['name' => $r['name'], 'host' => $r['host'], 'ok' => (bool) $r['stats'], 'today' => $r['stats']['today'] ?? 0, 'total' => $r['stats']['total'] ?? 0]; }, $rows)], false);
        if (class_exists('Mutqan_Telegram')) { try { Mutqan_Telegram::notify('تقرير شبكة المواقع', $text); } catch (Throwable $e) { /* optional */ } }
        if (class_exists('Mutqan_WA')) { try { Mutqan_WA::notify_admins('تقرير شبكة المواقع', mb_substr($text, 0, 280), 'net-report-' . $key, 3000); } catch (Throwable $e) { /* optional */ } }
        return $text;
    }
}
