<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Google Places text search for the field map: find a shop or business by name (optionally biased
 * near a point) so staff drop a pin without typing coordinates by hand. Needs a Google Places API key,
 * pasted once in WordPress admin -> تطبيق أكسجين -> خرائط جوجل (Mutqan_Admin). Until a key is set this
 * returns an empty list rather than an error, so the map's own "استخدم مركز الخريطة" fallback still works.
 */
final class Mutqan_Places {
	const OPT_KEY = 'mutqan_google_places_key';

	public static function has_key() { return trim((string) get_option(self::OPT_KEY, '')) !== ''; }

	/** Free-text search ("مغسلة الأمانة" or "مكيفات النور حي الشرائع مكة"), optionally biased near lat/lng. */
	public static function search($query, $lat = null, $lng = null) {
		$key = trim((string) get_option(self::OPT_KEY, ''));
		$query = trim((string) $query);
		if ($key === '' || $query === '') { return []; }
		$ck = 'mq_places_' . md5($query . '|' . round((float) $lat, 2) . ',' . round((float) $lng, 2));
		$hit = get_transient($ck);
		if (is_array($hit)) { return $hit; }
		Mutqan_Limiter::hit('places', 60, 3600);
		$url = 'https://maps.googleapis.com/maps/api/place/textsearch/json?query=' . rawurlencode($query) . '&language=ar&region=sa&key=' . rawurlencode($key);
		if (is_numeric($lat) && is_numeric($lng)) { $url .= '&location=' . $lat . ',' . $lng . '&radius=30000'; }
		$res = wp_remote_get($url, ['timeout' => 10]);
		if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) { return []; }
		$d = json_decode(wp_remote_retrieve_body($res), true);
		if (!is_array($d) || !in_array($d['status'] ?? '', ['OK', 'ZERO_RESULTS'], true)) {
			error_log('[mutqan] places search: ' . ($d['status'] ?? 'bad_response') . ' ' . ($d['error_message'] ?? ''));
			return [];
		}
		$out = [];
		foreach (array_slice((array) ($d['results'] ?? []), 0, 10) as $r) {
			$loc = $r['geometry']['location'] ?? null;
			if (!$loc) { continue; }
			$out[] = [
				'name' => (string) ($r['name'] ?? ''),
				'address' => (string) ($r['formatted_address'] ?? ''),
				'lat' => (float) $loc['lat'],
				'lng' => (float) $loc['lng'],
				'placeId' => (string) ($r['place_id'] ?? ''),
			];
		}
		set_transient($ck, $out, DAY_IN_SECONDS);
		return $out;
	}
}
