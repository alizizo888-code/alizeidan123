<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Automatic dispatch, on the server, at the moment an order is created.
 *
 * In «آلي» mode the operations room promised «كل طلب جديد يُسند فورًا لأنسب فني» — but the
 * assigning ran inside whoever's browser had the room open. With nobody on the screen (night,
 * prayer, a supervisor on a call), a new order just waited, unassigned, in a system that said it
 * was handling it. So it runs here, for every door an order comes through: the app, the website
 * chat, WhatsApp.
 *
 * It is a port of pickTechnician()/scoreTechnicians() in assignment.js, weights included, so the
 * server and the room choose the same technician for the same order. The room keeps its own loop
 * for anything that slips through; an order this has already assigned is not "unassigned" there.
 *
 * Manual mode is untouched: then the order waits for the supervisor, as it always has.
 */
final class Mutqan_Dispatch {

    const DEFAULT_CAPACITY = 8;     // = DEFAULT_CAPACITY in assignment.js
    const RUN = ['accepted', 'en_route', 'nearby', 'arrived', 'in_progress'];

    private static function sys() { return ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']]; }

    /** The owner's distribution settings, as the operations room writes them. */
    public static function settings() {
        $s = Mutqan_Store::get_raw('assignment_settings', 'default');
        return is_array($s) ? $s : [];
    }

    public static function is_auto() { return (self::settings()['mode'] ?? 'manual') === 'auto'; }

    /**
     * Called once for every order that is created. Returns the order as it stands afterwards.
     * Never throws: an order that could not be dispatched is still an order, and the room sees it.
     */
    public static function on_created($doc) {
        try {
            if (!is_array($doc) || empty($doc['id'])) { return $doc; }
            if (!self::is_auto()) { return $doc; }
            if (!empty($doc['technicianId']) || !empty($doc['inhouse'])) { return $doc; }
            if (!in_array((string) ($doc['status'] ?? 'new'), ['new', ''], true)) { return $doc; }
            return self::dispatch($doc);
        } catch (Throwable $e) {
            error_log('[mutqan] dispatch ' . $e->getMessage());
            return $doc;
        }
    }

    /** Assigns one order now: the best technician, else the site technician, else the open pool. */
    public static function dispatch($order) {
        $s = self::settings();
        $profiles = self::profiles();
        $orders = self::recent_orders();
        $site = self::site_tech($profiles, $s);
        $pool = $site ? array_values(array_filter($profiles, function ($t) use ($site) { return ($t['userId'] ?? '') !== $site['userId']; })) : $profiles;
        $tech = self::pick($order, $pool, $orders, (string) ($s['strategy'] ?? 'smart'));
        if ($tech) { return self::link($order, $tech, 'auto'); }
        if ($site && ($s['siteFallback'] ?? true) !== false) { return self::link($order, $site, 'site-fallback'); }
        return self::force($order);
    }

    /* ------------------------------------------------------------------ the people and the load */

    public static function profiles() {
        $rows = Mutqan_Store::select('tech_profiles', [], ['limit' => 300], self::sys());
        return array_values(array_filter($rows, function ($t) { return !empty($t['userId']); }));
    }

    /** Orders that can affect today's capacity or a technician's current load. */
    public static function recent_orders() {
        return Mutqan_Store::select('orders', [], ['limit' => 400, 'order' => '-updatedAt'], self::sys());
    }

    private static function site_tech($profiles, $s) {
        $id = (string) ($s['siteTechId'] ?? '');
        foreach ($profiles as $t) { if ($id !== '' && $t['userId'] === $id && ($t['active'] ?? true) !== false) { return $t; } }
        foreach ($profiles as $t) { if (!empty($t['isSite']) && ($t['active'] ?? true) !== false) { return $t; } }
        return null;
    }

    public static function capacity($t) { $c = (float) ($t['dailyCapacity'] ?? 0); return $c > 0 ? $c : self::DEFAULT_CAPACITY; }

    private static function is_today($iso) {
        $ts = strtotime((string) $iso);
        return $ts && self::ymd($ts) === self::ymd(time());
    }

    /** The site's calendar day — "today" means today in Makkah, not in UTC. */
    private static function ymd($ts) {
        if (function_exists('wp_date')) { return wp_date('Y-m-d', $ts); }
        return (new DateTime('@' . $ts))->setTimezone(new DateTimeZone('Asia/Riyadh'))->format('Y-m-d');
    }

    public static function assigned_today($uid, $orders) {
        $n = 0;
        foreach ($orders as $o) { if (($o['technicianId'] ?? null) === $uid && self::is_today($o['assignedAt'] ?? ($o['createdAt'] ?? ''))) { $n++; } }
        return $n;
    }

    public static function remaining($t, $orders) { return max(0, self::capacity($t) - self::assigned_today($t['userId'], $orders)); }

    public static function available($t, $orders) {
        if (($t['active'] ?? true) === false) { return false; }
        return self::remaining($t, $orders) > 0;
    }

    private static function mins_since($iso) { $ts = strtotime((string) $iso); return $ts ? (time() - $ts) / 60 : INF; }

    public static function presence($t) {
        $m = self::mins_since($t['lastSeenAt'] ?? '');
        return $m <= 10 ? 'online' : ($m <= 60 ? 'recent' : 'offline');
    }

    private static function running($t, $orders) {
        $n = 0;
        foreach ($orders as $o) { if (($o['technicianId'] ?? null) === $t['userId'] && in_array($o['status'] ?? '', self::RUN, true)) { $n++; } }
        return $n;
    }

    private static function done($t, $orders, $days = 30) {
        $n = 0;
        foreach ($orders as $o) {
            if (($o['technicianId'] ?? null) === $t['userId'] && in_array($o['status'] ?? '', ['completed', 'closed'], true)
                && self::mins_since($o['completedAt'] ?? ($o['updatedAt'] ?? '')) <= $days * 1440) { $n++; }
        }
        return $n;
    }

    public static function meters($lat1, $lng1, $lat2, $lng2) {
        $r = 6371000; $dl = deg2rad($lat2 - $lat1); $dn = deg2rad($lng2 - $lng1);
        $a = sin($dl / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dn / 2) ** 2;
        return 2 * $r * asin(min(1, sqrt($a)));
    }

    /** Live position if fresh, home otherwise, else unknown — the same order of trust as techDistance(). */
    public static function distance($t, $order) {
        if (!isset($order['lat'], $order['lng']) || !is_numeric($order['lat']) || !is_numeric($order['lng'])) { return INF; }
        if (isset($t['lat'], $t['lng']) && is_numeric($t['lat']) && self::mins_since($t['lastSeenAt'] ?? '') <= 10) {
            return self::meters((float) $t['lat'], (float) $t['lng'], (float) $order['lat'], (float) $order['lng']);
        }
        if (isset($t['homeLat'], $t['homeLng']) && is_numeric($t['homeLat'])) {
            return self::meters((float) $t['homeLat'], (float) $t['homeLng'], (float) $order['lat'], (float) $order['lng']);
        }
        return INF;
    }

    /* ------------------------------------------------------------------ choosing */

    /** scoreTechnicians() — the same eight measures and weights. */
    public static function score($order, $t, $orders, $all) {
        $urgent = ($order['priority'] ?? '') === 'urgent';
        $dist = self::distance($t, $order);
        $km = is_finite($dist) ? $dist / 1000 : null;
        $distS = $km === null ? 0.3 : max(0, 1 - $km / 40);
        $specs = is_array($t['specialties'] ?? null) ? $t['specialties'] : [];
        $specS = !$specs ? 0.5 : (in_array($order['specialtyId'] ?? null, $specs, true) ? 1 : 0);
        $cap = self::capacity($t);
        $workS = $cap ? self::remaining($t, $orders) / $cap : 0;
        $scope = $t['scope'] ?? '';
        $scopeS = $scope === 'wash_maintenance_install' ? 1 : ($scope === 'wash_maintenance' ? 0.75 : 0.5);
        $levels = ['junior' => 0.4, 'senior' => 0.7, 'expert' => 1];
        $lw = $levels[$t['level'] ?? 'senior'] ?? 0.7;
        $rated = array_filter($orders, function ($o) use ($t) { return ($o['technicianId'] ?? null) === $t['userId'] && !empty($o['rating']); });
        $rating = $rated ? array_sum(array_map(function ($o) { return (float) $o['rating']; }, $rated)) / count($rated) : null;
        $qualS = $lw * 0.6 + ($rating ? $rating / 5 : 0.7) * 0.4;
        $pres = self::presence($t);
        $presS = $pres === 'online' ? 1 : ($pres === 'recent' ? 0.5 : 0);
        $run = self::running($t, $orders);
        $freeS = $run === 0 ? 1 : ($run === 1 ? 0.4 : 0);
        $maxDone = 1; foreach ($all as $x) { $maxDone = max($maxDone, self::done($x, $orders)); }
        $prodS = self::done($t, $orders) / $maxDone;
        $w = $urgent
            ? ['dist' => 0.3, 'pres' => 0.2, 'free' => 0.16, 'spec' => 0.12, 'work' => 0.04, 'scope' => 0.04, 'qual' => 0.07, 'prod' => 0.07]
            : ['dist' => 0.22, 'pres' => 0.16, 'free' => 0.12, 'spec' => 0.14, 'work' => 0.1, 'scope' => 0.04, 'qual' => 0.1, 'prod' => 0.12];
        $s = $distS * $w['dist'] + $presS * $w['pres'] + $freeS * $w['free'] + $specS * $w['spec'] + $workS * $w['work'] + $scopeS * $w['scope'] + $qualS * $w['qual'] + $prodS * $w['prod'];
        if ($pres === 'offline') { $s *= 0.7; }
        return (int) round($s * 100);
    }

    /** pickTechnician() — smart, capacity or nearest. Null when nobody can take it. */
    public static function pick($order, $techs, $orders, $strategy = 'smart') {
        $avail = array_values(array_filter($techs, function ($t) use ($orders) { return self::available($t, $orders); }));
        if (!$avail) { return null; }
        if ($strategy === 'capacity') {
            usort($avail, function ($a, $b) use ($orders) { return self::remaining($b, $orders) <=> self::remaining($a, $orders); });
            return $avail[0];
        }
        if ($strategy === 'nearest') {
            usort($avail, function ($a, $b) use ($order) { return self::distance($a, $order) <=> self::distance($b, $order); });
            return $avail[0];
        }
        $ranked = [];
        foreach ($avail as $t) { $sc = self::score($order, $t, $orders, $avail); if ($sc > 0) { $ranked[] = [$sc, $t]; } }
        usort($ranked, function ($a, $b) { return $b[0] <=> $a[0]; });
        foreach ($ranked as $r) { if (self::presence($r[1]) !== 'offline') { return $r[1]; } }   // connected first
        return $ranked ? $ranked[0][1] : null;
    }

    /* ------------------------------------------------------------------ writing it down */

    /** linkTech() — the same fields the room writes, so nothing downstream can tell who did it. */
    private static function link($order, $t, $by) {
        $at = gmdate('c');
        $patch = [
            'technicianId' => $t['userId'],
            'technicianName' => (string) ($t['displayName'] ?? ($t['name'] ?? 'فني')),
            'assignedAt' => $at, 'forced' => false, 'status' => 'assigned', 'handler' => 'technician',
            'dispatch' => (!empty($t['isSite']) || $by === 'site-fallback') ? 'site' : 'tech',
            'transferLog' => array_merge((array) ($order['transferLog'] ?? []), [['from' => $order['handler'] ?? 'operations', 'to' => 'technician', 'at' => $at, 'by' => $by, 'note' => (string) ($t['displayName'] ?? '')]]),
            'statusHistory' => array_merge((array) ($order['statusHistory'] ?? []), [['status' => 'assigned', 'at' => $at, 'by' => $by]]),
            'dispatchedBy' => 'server',
        ];
        return Mutqan_Store::update('orders', $order['id'], $patch, self::sys());
    }

    /** forceOrder() — nobody fits: the order goes to the open pool every technician can see. */
    private static function force($order) {
        $at = gmdate('c');
        return Mutqan_Store::update('orders', $order['id'], [
            'forced' => true, 'status' => 'forced', 'dispatchedBy' => 'server',
            'statusHistory' => array_merge((array) ($order['statusHistory'] ?? []), [['status' => 'forced', 'at' => $at, 'by' => 'auto']]),
        ], self::sys());
    }
}
