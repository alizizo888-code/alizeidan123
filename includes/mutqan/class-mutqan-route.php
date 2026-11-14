<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «الخريطة الحية»: where every technician and operations member is right now, the road each one took today, and the
 * open jobs that lie on the way.
 *
 *   - Each technician gets a fixed code and colour (ف-01 أزرق، ف-02 برتقالي…) so the operations room tells them apart
 *     at a glance on the map, in the lists and in the assistant's answers.
 *   - Trail: the last points of a person's road (one every ~30 m), kept only while they are working — a technician
 *     with an open job, a supervisor with the app open — and gone after 12 hours. It is the day's route, not a history.
 *   - From the trail: speed, heading, whether they are moving or parked, and an arrival estimate to the current job.
 *   - «مهام على الطريق»: open jobs nobody has yet, scored by how little they add to the road the technician is already
 *     driving (detour between where he is and where he is going) or, with no job, by how close they are. Rules, so it
 *     answers instantly and costs nothing; the assistant can then explain or act on it.
 */
final class Mutqan_Route {
    const TRAIL_MAX = 240;          // points per person
    const TRAIL_TTL = 43200;        // 12 hours
    const MIN_STEP_M = 30;          // a new point only after this much movement
    const LIVE_S = 600;             // a position younger than 10 minutes is «live»
    const NEAR_KM = 4.0;            // a job this close (or this little detour) is «on the way»
    const CODES = 'mutqan_tech_codes';
    const COLORS = ['#3b82f6', '#f97316', '#a855f7', '#10b981', '#ec4899', '#eab308', '#06b6d4', '#ef4444', '#84cc16', '#6366f1', '#14b8a6', '#f43f5e', '#8b5cf6', '#22c55e', '#0ea5e9', '#d946ef'];
    const WITH_TECH = ['assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress'];

    private static function sys() { return ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']]; }

    public static function km($a, $b, $c, $d) { return Mutqan_Dispatch::meters((float) $a, (float) $b, (float) $c, (float) $d) / 1000; }

    /* ------------------------------------------------------------------ codes and colours */

    /** A technician's fixed number: given once, in order of first appearance, never reused. */
    public static function code_of($techId) {
        $techId = (string) $techId;
        $m = get_option(self::CODES, []); $m = is_array($m) ? $m : [];
        if (!isset($m[$techId])) { $m[$techId] = $m ? max($m) + 1 : 1; update_option(self::CODES, $m, false); }
        $n = (int) $m[$techId];
        return ['n' => $n, 'code' => 'ف-' . str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'color' => self::COLORS[($n - 1) % count(self::COLORS)]];
    }

    /* ------------------------------------------------------------------ trail */

    public static function record($who, $lat, $lng) {
        $k = 'mq_trail_' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $who);
        $t = get_transient($k); $t = is_array($t) ? $t : [];
        $now = time();
        $t = array_values(array_filter($t, function ($p) use ($now) { return $now - (int) $p[2] <= self::TRAIL_TTL; }));
        $last = $t ? end($t) : null;
        if ($last && self::km($last[0], $last[1], $lat, $lng) * 1000 < self::MIN_STEP_M) {
            $t[count($t) - 1][3] = $now;            // still here: remember until when
        } else {
            $t[] = [round((float) $lat, 6), round((float) $lng, 6), $now, $now];
        }
        if (count($t) > self::TRAIL_MAX) { $t = array_slice($t, -self::TRAIL_MAX); }
        set_transient($k, $t, self::TRAIL_TTL);
        return count($t);
    }

    public static function trail($who) {
        $t = get_transient('mq_trail_' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $who));
        return is_array($t) ? $t : [];
    }

    public static function clear($who) { delete_transient('mq_trail_' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $who)); }

    /** Speed (km/h), heading (degrees), moving or parked, and road covered today, from the trail. */
    public static function motion($trail) {
        $n = count($trail);
        $out = ['kmh' => 0, 'heading' => null, 'moving' => false, 'km' => 0.0, 'parkedMin' => 0];
        if ($n === 0) { return $out; }
        for ($i = 1; $i < $n; $i++) { $out['km'] += self::km($trail[$i - 1][0], $trail[$i - 1][1], $trail[$i][0], $trail[$i][1]); }
        $out['km'] = round($out['km'], 1);
        $last = $trail[$n - 1];
        $out['parkedMin'] = (int) floor((time() - (int) $last[2]) / 60);
        if ($n >= 2) {
            $a = $trail[$n - 2]; $dt = max(1, (int) $last[2] - (int) $a[3]);
            $d = self::km($a[0], $a[1], $last[0], $last[1]);
            $out['kmh'] = (int) round(min(140, $d / ($dt / 3600)));
            $out['heading'] = (int) round(fmod(rad2deg(atan2(
                sin(deg2rad($last[1] - $a[1])) * cos(deg2rad($last[0])),
                cos(deg2rad($a[0])) * sin(deg2rad($last[0])) - sin(deg2rad($a[0])) * cos(deg2rad($last[0])) * cos(deg2rad($last[1] - $a[1]))
            )) + 360, 360));
            $out['moving'] = $out['kmh'] >= 5 && time() - (int) $last[3] < 300;
        }
        return $out;
    }

    /* ------------------------------------------------------------------ the picture */

    /** Everything the live map draws. One call, every ~10 s. */
    public static function fleet() {
        $sys = self::sys();
        $orders = Mutqan_Store::select('orders', ['status' => ['in' => array_merge(['new', 'forced'], self::WITH_TECH)]], ['limit' => 500, 'order' => '-createdAt'], $sys);
        $byTech = []; $free = [];
        foreach ($orders as $o) {
            $tid = (string) ($o['technicianId'] ?? '');
            if ($tid !== '' && in_array($o['status'], self::WITH_TECH, true)) { $byTech[$tid][] = $o; }
            elseif (in_array($o['status'], ['new', 'forced'], true) && empty($o['inhouse']) && is_numeric($o['lat'] ?? null)) { $free[] = $o; }
        }
        $techs = [];
        foreach (Mutqan_Store::select('tech_profiles', [], ['limit' => 300], $sys) as $t) {
            $id = (string) ($t['userId'] ?? $t['id'] ?? '');
            if ($id === '') { continue; }
            $c = self::code_of($id);
            $pos = Mutqan_Track::where($t);
            $trail = self::trail($id);
            $mo = self::motion($trail);
            $jobs = $byTech[$id] ?? [];
            // The job he is heading to: the one on the way, else the earliest accepted one.
            usort($jobs, function ($a, $b) { $r = ['en_route' => 0, 'nearby' => 1, 'arrived' => 2, 'in_progress' => 3, 'accepted' => 4, 'assigned' => 5]; return ($r[$a['status']] ?? 9) <=> ($r[$b['status']] ?? 9); });
            $next = null;
            foreach ($jobs as $j) { if (is_numeric($j['lat'] ?? null)) { $next = $j; break; } }
            $row = ['id' => $id, 'name' => mb_substr((string) ($t['displayName'] ?? $t['name'] ?? 'فني'), 0, 40), 'phone' => (string) ($t['phone'] ?? ''),
                'code' => $c['code'], 'color' => $c['color'], 'off' => isset($t['available']) && !$t['available'], 'jobs' => count($jobs),
                'live' => false, 'trail' => array_map(function ($p) { return [$p[0], $p[1]]; }, array_slice($trail, -120)), 'motion' => $mo];
            if ($pos) { $row += ['lat' => $pos['lat'], 'lng' => $pos['lng'], 'ago' => $pos['ago']]; $row['live'] = $pos['ago'] <= self::LIVE_S; }
            elseif (is_numeric($t['homeLat'] ?? null)) { $row += ['lat' => (float) $t['homeLat'], 'lng' => (float) $t['homeLng'], 'home' => true]; }
            if ($next) {
                $row['job'] = ['id' => (string) $next['id'], 'n' => (string) ($next['number'] ?? ''), 's' => (string) $next['status'], 'client' => mb_substr((string) ($next['clientName'] ?? ''), 0, 40), 'lat' => (float) $next['lat'], 'lng' => (float) $next['lng']];
                if (isset($row['lat']) && empty($row['home'])) {
                    $d = self::km($row['lat'], $row['lng'], $next['lat'], $next['lng']);
                    $speed = $mo['kmh'] >= 10 ? $mo['kmh'] : 30;   // city driving when standing still
                    $row['job']['km'] = round($d, 1); $row['job']['etaMin'] = (int) max(1, round($d / $speed * 60));
                }
            }
            $row['onRoute'] = isset($row['lat']) && !$row['off'] ? self::on_route($row, $free) : [];
            $techs[] = $row;
        }
        $staff = [];
        foreach (Mutqan_Ops::positions() as $id => $x) {
            $staff[] = ['id' => (string) $id, 'name' => (string) ($x['name'] ?? '') ?: 'مشرف', 'role' => (string) ($x['role'] ?? ''), 'lat' => (float) $x['lat'], 'lng' => (float) $x['lng'],
                'ago' => time() - (int) $x['at'], 'trail' => array_map(function ($p) { return [$p[0], $p[1]]; }, array_slice(self::trail('s' . $id), -60))];
        }
        $customers = [];
        foreach ($orders as $o) {
            if (!is_numeric($o['lat'] ?? null)) { continue; }
            $tid = (string) ($o['technicianId'] ?? '');
            $customers[] = ['id' => (string) $o['id'], 'n' => (string) ($o['number'] ?? ''), 's' => (string) $o['status'], 'name' => mb_substr((string) ($o['clientName'] ?? ''), 0, 40),
                'svc' => mb_substr((string) ($o['serviceLabel'] ?? ''), 0, 40), 'lat' => (float) $o['lat'], 'lng' => (float) $o['lng'], 'tech' => $tid,
                'color' => $tid !== '' ? self::code_of($tid)['color'] : null, 'at' => (string) ($o['createdAt'] ?? '')];
        }
        return ['at' => gmdate('c'), 'techs' => $techs, 'staff' => $staff, 'orders' => $customers,
            'count' => ['live' => count(array_filter($techs, function ($t) { return $t['live']; })), 'moving' => count(array_filter($techs, function ($t) { return $t['motion']['moving']; })), 'free' => count($free)]];
    }

    /** Unassigned jobs that cost this technician the least extra road. */
    public static function on_route($t, $free, $limit = 3) {
        $out = [];
        foreach ($free as $o) {
            $here = self::km($t['lat'], $t['lng'], $o['lat'], $o['lng']);
            if (!empty($t['job']['lat'])) {
                // extra road = (here → job → his destination) − (here → his destination)
                $direct = self::km($t['lat'], $t['lng'], $t['job']['lat'], $t['job']['lng']);
                $via = $here + self::km($o['lat'], $o['lng'], $t['job']['lat'], $t['job']['lng']);
                $extra = max(0, $via - $direct);
                $why = 'على طريقه لطلب #' . ($t['job']['n'] ?: substr($t['job']['id'], -5)) . ' (زيادة ' . round($extra, 1) . ' كم)';
            } else {
                $extra = $here; $why = 'قريب منه (' . round($here, 1) . ' كم)';
            }
            if ($extra > self::NEAR_KM) { continue; }
            $out[] = ['id' => (string) $o['id'], 'n' => (string) ($o['number'] ?? ''), 'client' => mb_substr((string) ($o['clientName'] ?? ''), 0, 40),
                'svc' => mb_substr((string) ($o['serviceLabel'] ?? ''), 0, 40), 'extraKm' => round($extra, 1), 'km' => round($here, 1), 'why' => $why];
        }
        usort($out, function ($a, $b) { return $a['extraKm'] <=> $b['extraKm']; });
        return array_slice($out, 0, $limit);
    }

    /** Give a technician a job from his road, and tell him. */
    public static function assign($orderId, $techId, $p) {
        $o = Mutqan_Store::get_raw('orders', (string) $orderId);
        if (!$o) { throw new Mutqan_Error('not_found', 'الطلب مش موجود.', 404); }
        if (!in_array($o['status'] ?? '', ['new', 'forced'], true)) { throw new Mutqan_Error('taken', 'الطلب ده اتسند خلاص.', 409); }
        $at = gmdate('c');
        $doc = Mutqan_Store::update('orders', (string) $orderId, ['technicianId' => (string) $techId, 'status' => 'assigned', 'assignedAt' => $at,
            'statusHistory' => array_merge((array) ($o['statusHistory'] ?? []), [['status' => 'assigned', 'at' => $at, 'by' => (string) ($p['name'] ?? 'العمليات'), 'note' => 'على خط السير']])], $p);
        Mutqan_Store::insert('_push', ['target' => ['user' => (string) $techId], 'title' => '🧭 مهمة على طريقك #' . ((string) ($o['number'] ?? '') ?: substr((string) $orderId, -5)),
            'body' => trim(($o['serviceLabel'] ?? '') . ' · ' . mb_substr((string) ($o['address'] ?? ''), 0, 80), ' ·'), 'url' => '#/staff/order/' . $orderId, 'sound' => 'bell', 'priority' => 'high', 'event' => 'order_assigned', 'from' => 'system'],
            null, ['expiresAt' => gmdate('c', time() + 86400)], self::sys());
        return ['ok' => true, 'order' => (string) $doc['id'], 'tech' => (string) $techId];
    }

    /** One line per technician, for the assistant and WhatsApp. */
    public static function brief($fleet, $name = '') {
        $lines = [];
        foreach ($fleet['techs'] as $t) {
            if ($name !== '' && mb_strpos(Mutqan_Quick::norm($t['name']), Mutqan_Quick::norm($name)) === false && $t['code'] !== $name) { continue; }
            if (!isset($t['lat'])) { if ($name !== '') { $lines[] = $t['code'] . ' ' . $t['name'] . ': مفيش موقع (مش فاتح التطبيق أو مفيش شغل مفتوح).'; } continue; }
            $m = $t['motion'];
            $s = $t['code'] . ' ' . $t['name'] . ': ' . ($t['live'] ? ($m['moving'] ? 'ماشي ' . $m['kmh'] . ' كم/س' : 'واقف') : 'آخر موقع من ' . (int) floor(($t['ago'] ?? 0) / 60) . ' دقيقة')
                . ($m['km'] > 0 ? ' · قطع ' . $m['km'] . ' كم النهارده' : '');
            if (!empty($t['job'])) { $s .= ' · رايح #' . $t['job']['n'] . ' ' . $t['job']['client'] . (isset($t['job']['etaMin']) ? ' (' . $t['job']['km'] . ' كم، يوصل خلال ~' . $t['job']['etaMin'] . ' د)' : ''); }
            $s .= ' · https://maps.google.com/?q=' . $t['lat'] . ',' . $t['lng'];
            foreach ($t['onRoute'] as $r) { $s .= "\n   🧭 مهمة على طريقه: #" . $r['n'] . ' ' . $r['client'] . ' — ' . $r['why']; }
            $lines[] = $s;
        }
        return $lines ? implode("\n", $lines) : 'مفيش فنيين ظاهرين على الخريطة دلوقتي.';
    }
}
