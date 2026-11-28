<?php
if (!defined('ABSPATH')) { exit; }

/**
 * The whole operation, in one small answer.
 *
 * A live view needs very little: what is in motion, where it is, and who is near it. It was being
 * assembled by pulling whole collections — three hundred orders with every field, eight hundred
 * customer profiles — every few seconds, from every open screen, against the one server all
 * twenty-two faces share. That is the load that was answering 503 and leaving screens half empty.
 *
 * So the server does the picking. One request returns the fields a map or a board actually draws,
 * and nothing else: no notes, no history, no addresses nobody is reading.
 *
 * It also carries a version. A screen that polls sends the version it has, and when nothing has
 * changed the answer is that one word — no rows at all. Most polls are that, which is what makes
 * a few seconds between refreshes affordable instead of expensive.
 */
final class Mutqan_Live {

    const WATCH = ['orders', 'tech_profiles', 'shops'];
    const OPEN = ['new', 'forced', 'assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress'];
    const WITH_TECH = ['assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress'];

    private static function sys() { return ['kind' => 'user', 'role' => 'owner', 'id' => 'system']; }

    /** Changes to anything a live view draws move this on; nothing else does. */
    public static function version() {
        $parts = [];
        foreach (self::WATCH as $c) { $parts[] = $c . ':' . (string) get_option('mutqan_v_' . $c, '0'); }
        return substr(md5(implode('|', $parts)), 0, 16);
    }

    /**
     * $since is the version the caller already has. When it still matches, the rows are left out
     * entirely — the point of asking often is that asking is usually cheap.
     */
    public static function snapshot($since = '') {
        $v = self::version();
        if ($since !== '' && hash_equals($v, (string) $since)) { return ['v' => $v, 'fresh' => true]; }

        $orders = [];
        $count = ['new' => 0, 'open' => 0, 'today' => 0, 'unplaced' => 0];
        $today = substr(gmdate('c'), 0, 10);
        foreach (Mutqan_Store::select('orders', [], ['limit' => 300, 'order' => 'desc'], self::sys()) as $o) {
            $s = (string) ($o['status'] ?? '');
            if (substr((string) ($o['createdAt'] ?? ''), 0, 10) === $today) { $count['today']++; }
            if (!in_array($s, self::OPEN, true)) { continue; }
            if ($s === 'new' || $s === 'forced') { $count['new']++; } else { $count['open']++; }
            $has = isset($o['lat'], $o['lng']) && is_numeric($o['lat']) && is_numeric($o['lng']);
            if (!$has) { $count['unplaced']++; }
            // Only what a map pin or a board row shows. A screen that needs the rest opens the order.
            $row = [
                'id' => (string) ($o['id'] ?? ''), 'n' => (string) ($o['number'] ?? ''), 's' => $s,
                'name' => mb_substr((string) ($o['clientName'] ?? ''), 0, 40),
                'svc' => mb_substr((string) ($o['serviceLabel'] ?? $o['service'] ?? ''), 0, 40),
                'tech' => (string) ($o['technicianId'] ?? ''), 'at' => (string) ($o['createdAt'] ?? ''),
            ];
            if ($has) { $row['lat'] = (float) $o['lat']; $row['lng'] = (float) $o['lng']; }
            $orders[] = $row;
        }

        $techs = [];
        foreach (Mutqan_Store::select('tech_profiles', [], ['limit' => 200], self::sys()) as $t) {
            $id = (string) ($t['id'] ?? '');
            $busy = 0;
            foreach ($orders as $o) { if ($o['tech'] === $id && in_array($o['s'], self::WITH_TECH, true)) { $busy++; } }
            $row = ['id' => $id, 'name' => mb_substr((string) ($t['displayName'] ?? $t['name'] ?? 'فني'), 0, 40),
                'busy' => $busy, 'off' => isset($t['available']) && !$t['available']];
            // Where they are now if they are working; otherwise where they are based, if anywhere.
            $pos = class_exists('Mutqan_Track') ? Mutqan_Track::where($t) : null;
            if ($pos) { $row['lat'] = $pos['lat']; $row['lng'] = $pos['lng']; $row['ago'] = $pos['ago']; }
            elseif (isset($t['homeLat'], $t['homeLng']) && is_numeric($t['homeLat'])) { $row['lat'] = (float) $t['homeLat']; $row['lng'] = (float) $t['homeLng']; $row['home'] = true; }
            $techs[] = $row;
        }

        $shops = [];
        foreach (Mutqan_Store::select('shops', [], ['limit' => 300], self::sys()) as $s) {
            if (!isset($s['lat'], $s['lng']) || !is_numeric($s['lat'])) { continue; }
            $shops[] = ['id' => (string) ($s['id'] ?? ''), 'name' => mb_substr((string) ($s['name'] ?? ''), 0, 40),
                'lat' => (float) $s['lat'], 'lng' => (float) $s['lng'], 'off' => ($s['available'] ?? true) === false];
        }

        return ['v' => $v, 'fresh' => false, 'orders' => $orders, 'techs' => $techs, 'shops' => $shops, 'count' => $count];
    }
}
