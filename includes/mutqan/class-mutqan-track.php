<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Where the work is, and where it has been.
 *
 * Two things were missing and both are needed before a live map or any travel estimate means
 * anything: a step in an order's trail recorded WHEN something happened but never WHERE, and a
 * technician's position was never recorded at all — the map could only show a fixed home address
 * somebody typed once.
 *
 * On a person's whereabouts this is deliberately narrow, because a maintenance business does not
 * need, and should not keep, a record of where its people are:
 *
 *   - a position is kept only while that technician has an open job. Off shift, nothing is stored.
 *   - it is one current point, overwritten each time. There is no history to follow afterwards.
 *   - it goes stale on its own after a few hours, so a phone left behind does not keep reporting
 *     a position that is no longer true.
 *   - an order's trail keeps the position of a step because that is a record of the JOB — where it
 *     was accepted, reached, finished — which is the business's own record and belongs to it.
 */
final class Mutqan_Track {

    const STALE = 10800;        // a position older than three hours is not where anyone is now
    const OPEN = ['assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress'];

    private static function sys() { return ['kind' => 'user', 'role' => 'owner', 'id' => 'system']; }

    private static function valid($lat, $lng) {
        return is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180
            && !((float) $lat === 0.0 && (float) $lng === 0.0);
    }

    /** A trail entry: what happened, when, who, and where if the place is known. */
    public static function step($kind, $text, $by = 'النظام', $lat = null, $lng = null) {
        $s = ['at' => gmdate('c'), 'kind' => (string) $kind, 'text' => mb_substr((string) $text, 0, 200), 'by' => mb_substr((string) $by, 0, 60)];
        if (self::valid($lat, $lng)) { $s['lat'] = round((float) $lat, 6); $s['lng'] = round((float) $lng, 6); }
        return $s;
    }

    /**
     * The technician says where they are.
     *
     * Refused unless they have a job open right now: there is no reason to hold the position of
     * someone who is not working, and no screen asks for it.
     */
    public static function ping($techId, $lat, $lng) {
        $techId = (string) $techId;
        if ($techId === '' || !self::valid($lat, $lng)) { return ['ok' => false, 'reason' => 'bad_position']; }
        $open = Mutqan_Store::select('orders', ['technicianId' => $techId, 'status' => ['in' => self::OPEN]], ['limit' => 1], self::sys());
        if (!$open) { return ['ok' => false, 'reason' => 'no_open_job']; }
        $t = Mutqan_Store::get_raw('tech_profiles', $techId);
        if (!$t) { return ['ok' => false, 'reason' => 'unknown_tech']; }
        // One current point, overwritten. Nothing accumulates, so there is no trail of a person.
        Mutqan_Store::update('tech_profiles', $techId, ['lat' => round((float) $lat, 6), 'lng' => round((float) $lng, 6), 'atAt' => time()], self::sys());
        $near = [];
        try { $near = self::approach($techId, (float) $lat, (float) $lng); } catch (Throwable $e) { error_log('[mutqan] approach ' . $e->getMessage()); }
        return ['ok' => true, 'at' => time(), 'nearby' => $near];
    }

    const NEAR_M = 300;

    /**
     * The customer's side of a position: on the way, the order carries where the technician is and how far, and the
     * moment he is within NEAR_M the order turns «الفني قريب» and the customer is told — by the server, from the
     * position the app sends in the background, not only while the technician keeps the order page open.
     */
    public static function approach($techId, $lat, $lng) {
        $out = [];
        $jobs = Mutqan_Store::select('orders', ['technicianId' => (string) $techId, 'status' => ['in' => ['accepted', 'en_route', 'nearby']]], ['limit' => 5], self::sys());
        foreach ($jobs as $o) {
            if (!self::valid($o['lat'] ?? null, $o['lng'] ?? null)) { continue; }
            $m = (int) round(Mutqan_Dispatch::meters($lat, $lng, (float) $o['lat'], (float) $o['lng']));
            $now = gmdate('c');
            $patch = ['technicianLocation' => ['lat' => round($lat, 6), 'lng' => round($lng, 6), 'updatedAt' => $now], 'technicianDistanceM' => $m];
            $limit = (int) (get_option('mutqan_near_m', self::NEAR_M) ?: self::NEAR_M);
            if (($o['status'] ?? '') === 'en_route' && $m <= $limit) {
                $patch['status'] = 'nearby';
                $patch['statusHistory'] = array_merge((array) ($o['statusHistory'] ?? []), [['status' => 'nearby', 'at' => $now, 'by' => 'system', 'note' => $m . ' م']]);
                $out[] = (string) $o['id'];
                self::tell_customer($o, $m);
            }
            Mutqan_Store::update('orders', (string) $o['id'], $patch, self::sys());
        }
        return $out;
    }

    private static function tell_customer($o, $m) {
        $short = '#' . ((string) ($o['number'] ?? '') ?: substr((string) $o['id'], -5));
        if (!empty($o['clientId'])) {
            Mutqan_Store::insert('_push', ['target' => ['user' => (string) $o['clientId']], 'title' => '📍 الفني قريب منك ' . $short, 'body' => 'على بُعد حوالي ' . $m . ' متر — استعد لاستقباله.',
                'url' => '#/order/' . $o['id'], 'sound' => 'chime', 'priority' => 'high', 'event' => 'order_status', 'from' => 'system'], null, ['expiresAt' => gmdate('c', time() + 86400)], self::sys());
        }
        $phone = class_exists('Mutqan_WA') ? Mutqan_WA::intl((string) ($o['clientPhone'] ?? '')) : '';
        if ($phone !== '' && get_option('mutqan_near_wa', 1)) {
            try { $acct = Mutqan_WA::open_account(); if ($acct !== '') { Mutqan_WA::send($acct, $phone, '📍 الفني قريب منك (حوالي ' . $m . ' متر) — طلبك ' . $short . '. استعد لاستقباله 🙏', 'auto', 'system'); } }
            catch (Throwable $e) { error_log('[mutqan] near wa ' . $e->getMessage()); }
        }
    }

    /** Where a technician is, or null when it is unknown or too old to mean anything. */
    public static function where($tech) {
        $t = is_array($tech) ? $tech : Mutqan_Store::get_raw('tech_profiles', (string) $tech);
        if (!$t || !self::valid($t['lat'] ?? null, $t['lng'] ?? null)) { return null; }
        $at = (int) ($t['atAt'] ?? 0);
        if ($at <= 0 || time() - $at > self::STALE) { return null; }
        return ['lat' => (float) $t['lat'], 'lng' => (float) $t['lng'], 'at' => $at, 'ago' => time() - $at];
    }

    /** Clears a position the moment the last job closes — nothing is held past the shift. */
    public static function clear_if_free($techId) {
        $techId = (string) $techId;
        if ($techId === '') { return false; }
        if (Mutqan_Store::select('orders', ['technicianId' => $techId, 'status' => ['in' => self::OPEN]], ['limit' => 1], self::sys())) { return false; }
        $t = Mutqan_Store::get_raw('tech_profiles', $techId);
        if (!$t || !isset($t['atAt'])) { return false; }
        Mutqan_Store::update('tech_profiles', $techId, ['lat' => null, 'lng' => null, 'atAt' => null], self::sys());
        return true;
    }
}
