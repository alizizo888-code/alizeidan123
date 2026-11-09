<?php
declare(strict_types=1);

/**
 * Live positions of technicians, for the map in the operations room.
 *
 * Only active technicians can report a position, and each one keeps a single last position
 * (replaced on each report). The source is the technician's own app, with the technician's consent;
 * until that app exists, a supervisor can enter a position by hand from the operations room.
 */
final class Locations
{
    /** Service areas with a centre point for the map. "other" has no point: it covers partners' work. */
    public const AREAS = [
        'makkah' => ['label' => 'مكة', 'lat' => 21.4225, 'lng' => 39.8262, 'radius' => 25000],
        'jeddah' => ['label' => 'جدة', 'lat' => 21.4858, 'lng' => 39.1925, 'radius' => 25000],
    ];

    /** Records the technician's last position. Refused for supervisors, inactive people, or bad numbers. */
    public static function record(string $personId, mixed $lat, mixed $lng): array
    {
        $person = People::find($personId);
        if ($person['role'] !== 'technician') {
            throw new OxyHttpError(400, 'not_technician');
        }
        if (!$person['active']) {
            throw new OxyHttpError(400, 'inactive');
        }
        $lat = self::coordinate($lat, 90.0);
        $lng = self::coordinate($lng, 180.0);

        $pdo = oxy_pdo();
        $pdo->prepare('DELETE FROM technician_locations WHERE person_id = ?')->execute([$personId]);
        $pdo->prepare('INSERT INTO technician_locations (person_id, lat, lng, updated) VALUES (?, ?, ?, ?)')
            ->execute([$personId, $lat, $lng, time()]);
        Audit::log('location_set', $personId, 'manual_or_app');
        return ['person_id' => $personId, 'lat' => $lat, 'lng' => $lng];
    }

    /** Active technicians with their last position (null when none reported yet). */
    /** Freshness window: a position older than this is shown as stale, not as current. */
    public const FRESH_SECONDS = 7200;

    /**
     * Technicians with their position, specialties and a status for the map:
     * busy (has an open order), available (position is fresh), stale (position is old), offline (no position).
     */
    public static function live(): array
    {
        $stmt = oxy_pdo()->query(
            "SELECT p.id, p.name, p.area, l.lat, l.lng, l.updated,
                (SELECT COUNT(*) FROM orders o WHERE o.technician_id = p.id AND o.status IN ('new','assigned','in_progress')) AS busy
             FROM people p
             LEFT JOIN technician_locations l ON l.person_id = p.id
             WHERE p.role = 'technician' AND p.active = 1 ORDER BY p.name"
        );
        $skills = Skills::all();
        $now = time();
        return array_map(static function (array $row) use ($skills, $now): array {
            $located = $row['lat'] !== null;
            $updated = $located ? (int) $row['updated'] : null;
            $busy = (int) $row['busy'] > 0;
            if ($busy) {
                $status = 'busy';
            } elseif (!$located) {
                $status = 'offline';
            } elseif ($updated !== null && $now - $updated <= self::FRESH_SECONDS) {
                $status = 'available';
            } else {
                $status = 'stale';
            }
            return [
                'id' => $row['id'],
                'name' => $row['name'],
                'area' => $row['area'],
                'lat' => $located ? (float) $row['lat'] : null,
                'lng' => $located ? (float) $row['lng'] : null,
                'updated' => $updated,
                'busy_orders' => (int) $row['busy'],
                'status' => $status,
                'skills' => $skills[$row['id']] ?? [],
            ];
        }, $stmt->fetchAll());
    }

    /** One technician's last known position, or null if they have never sent one. */
    public static function of(string $personId): ?array
    {
        if ($personId === '') {
            return null;
        }
        $stmt = oxy_pdo()->prepare('SELECT lat, lng, updated FROM technician_locations WHERE person_id = ?');
        $stmt->execute([$personId]);
        $row = $stmt->fetch();
        return $row === false ? null : ['lat' => (float) $row['lat'], 'lng' => (float) $row['lng'], 'updated' => (int) $row['updated']];
    }

    /** Straight-line distance in kilometres. Good enough to say how far, never how long. */
    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public static function coordinate(mixed $value, float $limit): float
    {
        if (is_string($value) && is_numeric($value)) {
            $value = (float) $value;
        }
        if (!is_float($value) && !is_int($value)) {
            throw new OxyHttpError(400, 'bad_location');
        }
        $value = (float) $value;
        if (!is_finite($value) || abs($value) > $limit) {
            throw new OxyHttpError(400, 'bad_location');
        }
        return round($value, 6);
    }
}
