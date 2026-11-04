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
    public static function live(): array
    {
        $stmt = oxy_pdo()->query(
            'SELECT p.id, p.name, p.area, l.lat, l.lng, l.updated FROM people p
             LEFT JOIN technician_locations l ON l.person_id = p.id
             WHERE p.role = \'technician\' AND p.active = 1 ORDER BY p.name'
        );
        return array_map(static function (array $row): array {
            $located = $row['lat'] !== null;
            return [
                'id' => $row['id'],
                'name' => $row['name'],
                'area' => $row['area'],
                'lat' => $located ? (float) $row['lat'] : null,
                'lng' => $located ? (float) $row['lng'] : null,
                'updated' => $located ? (int) $row['updated'] : null,
            ];
        }, $stmt->fetchAll());
    }

    private static function coordinate(mixed $value, float $limit): float
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
