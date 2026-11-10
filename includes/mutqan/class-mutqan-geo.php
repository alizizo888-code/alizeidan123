<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Puts every order on the map, quietly, whether anyone is looking at a map or not.
 *
 * The field map, nearest-technician assignment and any travel estimate all read lat/lng and skip
 * whatever has none — so an order whose customer typed an address instead of sharing a pin is
 * invisible to all three. Most orders are like that.
 *
 * Addresses are turned into coordinates here, in the background:
 *
 *   - never while someone is placing an order. A customer must not wait on a lookup to a service
 *     we do not control, and must never be refused because it was slow.
 *   - a few at a time on a schedule, so the free service we use is not hammered. Nominatim asks
 *     for no more than one request a second and a real User-Agent; a handful every five minutes is
 *     far inside that and still fills a day's orders many times over.
 *   - each answer is kept for a month, because the same streets come back again and again.
 *   - an address that cannot be found is tried a few times and then left alone. Asking forever
 *     about a place that does not resolve spends the budget that new orders need.
 */
final class Mutqan_Geo {

    const PER_RUN = 5;              // per five-minute pass
    const MAX_TRIES = 3;
    const CACHE_DAYS = 30;
    const HOOK = 'mutqan_geo';

    public static function boot() {
        add_action(self::HOOK, [__CLASS__, 'fill_batch']);
        add_action('init', function () {
            if (!wp_next_scheduled(self::HOOK)) { wp_schedule_event(time() + 300, 'mutqan_5min', self::HOOK); }
        }, 3);
    }

    private static function sys() { return ['kind' => 'user', 'role' => 'owner', 'id' => 'system']; }

    /** The text we ask about: the address, and the city so a street name lands in the right one. */
    public static function query($doc) {
        $parts = array_filter([
            trim((string) ($doc['address'] ?? '')),
            trim((string) ($doc['district'] ?? '')),
            trim((string) ($doc['city'] ?? '')),
        ], static function ($s) { return $s !== ''; });
        if (!$parts) { return ''; }
        $q = implode('، ', array_unique($parts));
        // A house number and a flat tell a search engine nothing and only make the match worse.
        $q = preg_replace('/\b(شقة|شقه|الدور|دور|مبنى|عمارة|فيلا)\s*\S{0,10}/u', '', $q);
        return trim(preg_replace('/\s+/u', ' ', $q));
    }

    /** An address to a point, or null. Cached; the only place that talks to the service. */
    public static function forward($q, $country = 'sa') {
        $q = trim((string) $q);
        if (mb_strlen($q) < 4) { return null; }
        $key = 'mq_fwd_' . md5($country . '|' . $q);
        $hit = get_transient($key);
        if (is_array($hit)) { return $hit; }
        if ($hit === 'none') { return null; }        // a miss is remembered too, or we ask forever
        $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&accept-language=ar'
            . '&countrycodes=' . rawurlencode($country) . '&q=' . rawurlencode($q);
        $res = wp_remote_get($url, ['timeout' => 12, 'headers' => ['User-Agent' => 'OxygenMutqan/1.0 (' . home_url('/') . ')']]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) { return null; }  // not cached: try again later
        $d = json_decode(wp_remote_retrieve_body($res), true);
        $row = is_array($d) && isset($d[0]) ? $d[0] : null;
        if (!$row || !isset($row['lat'], $row['lon'])) { set_transient($key, 'none', self::CACHE_DAYS * DAY_IN_SECONDS); return null; }
        $lat = (float) $row['lat']; $lng = (float) $row['lon'];
        if (abs($lat) > 90 || abs($lng) > 180 || ($lat == 0.0 && $lng == 0.0)) { set_transient($key, 'none', self::CACHE_DAYS * DAY_IN_SECONDS); return null; }
        $out = ['lat' => round($lat, 6), 'lng' => round($lng, 6), 'from' => 'address'];
        set_transient($key, $out, self::CACHE_DAYS * DAY_IN_SECONDS);
        return $out;
    }

    /** Orders that have somewhere to look up and nowhere on the map yet. */
    public static function pending($limit = 50) {
        $out = [];
        foreach (Mutqan_Store::select('orders', [], ['limit' => 300, 'order' => 'desc'], self::sys()) as $o) {
            if (count($out) >= $limit) { break; }
            if (isset($o['lat'], $o['lng']) && is_numeric($o['lat']) && is_numeric($o['lng'])) { continue; }
            if ((int) ($o['geoTries'] ?? 0) >= self::MAX_TRIES) { continue; }
            if (self::query($o) === '') { continue; }
            $out[] = $o;
        }
        return $out;
    }

    /** One scheduled pass. Returns how many were placed. */
    public static function fill_batch() {
        $done = 0;
        foreach (self::pending(self::PER_RUN) as $o) {
            $hit = self::forward(self::query($o));
            $patch = ['geoTries' => (int) ($o['geoTries'] ?? 0) + 1];
            if ($hit) { $patch['lat'] = $hit['lat']; $patch['lng'] = $hit['lng']; $patch['geoFrom'] = 'address'; $done++; }
            try { Mutqan_Store::update('orders', (string) $o['id'], $patch, self::sys()); }
            catch (Throwable $e) { error_log('[mutqan] geo ' . $e->getMessage()); }
            // Nominatim asks for no more than one request a second, and we are its guest.
            if (!defined('MQ_TESTING')) { sleep(1); }
        }
        return $done;
    }
}
