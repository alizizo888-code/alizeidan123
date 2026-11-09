<?php
declare(strict_types=1);

/**
 * Places that matter on the ground: spare-parts shops, suppliers, stores.
 *
 * A technician who needs a compressor at two in the afternoon should not be ringing the operations
 * room to ask where to buy one. Operations adds the places once, and every technician sees the
 * nearest ones on their own map with a phone number and a route.
 */
final class Places
{
    public const KINDS = ['parts' => 'قطع غيار', 'supplier' => 'مورّد', 'store' => 'مخزن', 'other' => 'أخرى'];

    /** How many a phone is handed. A technician needs the nearest few, not a directory. */
    public const NEAR_LIMIT = 8;

    public static function all(): array
    {
        $rows = oxy_pdo()->query('SELECT * FROM places ORDER BY name')->fetchAll();
        return array_map(static fn (array $r): array => self::shape($r), $rows);
    }

    public static function add(array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120 || preg_match('/[<>]/', $name) === 1) {
            throw new OxyHttpError(400, 'bad_name');
        }
        $kind = (string) ($in['kind'] ?? 'parts');
        if (!isset(self::KINDS[$kind])) {
            throw new OxyHttpError(400, 'bad_kind');
        }
        $lat = Locations::coordinate($in['lat'] ?? null, 90.0);
        $lng = Locations::coordinate($in['lng'] ?? null, 180.0);
        $phone = preg_replace('/[^\d+]/', '', (string) ($in['phone'] ?? '')) ?? '';
        if ($phone !== '' && !preg_match('/^\+?\d{9,15}$/', $phone)) {
            throw new OxyHttpError(400, 'bad_phone');
        }
        $note = trim((string) ($in['note'] ?? ''));
        if (mb_strlen($note) > 200 || preg_match('/[<>]/', $note) === 1) {
            throw new OxyHttpError(400, 'bad_note');
        }
        $id = oxy_id();
        oxy_pdo()->prepare('INSERT INTO places (id, name, kind, lat, lng, phone, note, created) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $name, $kind, $lat, $lng, $phone, $note, time()]);
        Audit::log('place_add', $id, $name);
        return self::shape(['id' => $id, 'name' => $name, 'kind' => $kind, 'lat' => $lat, 'lng' => $lng, 'phone' => $phone, 'note' => $note, 'created' => time()]);
    }

    public static function remove(string $id): array
    {
        $stmt = oxy_pdo()->prepare('DELETE FROM places WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            throw new OxyHttpError(404, 'no_place');
        }
        Audit::log('place_remove', $id, '');
        return ['ok' => true];
    }

    /**
     * The places nearest to a point, closest first, with the distance worked out.
     * Distance is straight-line: enough to choose between two shops, never a promise about the drive.
     */
    public static function near(?float $lat, ?float $lng, int $limit = self::NEAR_LIMIT): array
    {
        $places = self::all();
        if ($lat === null || $lng === null) {
            return array_slice($places, 0, $limit);
        }
        foreach ($places as &$p) {
            $p['km'] = round(Locations::km($lat, $lng, $p['lat'], $p['lng']), 1);
        }
        unset($p);
        usort($places, static fn (array $a, array $b): int => $a['km'] <=> $b['km']);
        return array_slice($places, 0, $limit);
    }

    private static function shape(array $r): array
    {
        return [
            'id' => $r['id'],
            'name' => $r['name'],
            'kind' => $r['kind'],
            'kind_label' => self::KINDS[$r['kind']] ?? $r['kind'],
            'lat' => (float) $r['lat'],
            'lng' => (float) $r['lng'],
            'phone' => $r['phone'] ?? '',
            'note' => $r['note'] ?? '',
        ];
    }
}
