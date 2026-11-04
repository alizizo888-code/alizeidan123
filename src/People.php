<?php
declare(strict_types=1);

/**
 * Management registry: the people the owner runs the operation with.
 *
 * To add a new kind of person (for example technicians), add its role to ROLES and build its screen.
 * The table, routes and validation already handle it, so nothing else changes.
 * People are deactivated, never deleted, so past orders and audit entries keep their history.
 */
final class People
{
    /** Roles this phase manages. */
    public const ROLES = ['supervisor'];
    /** Coverage areas. "other" is for work outside Makkah and Jeddah handled by partners. */
    public const AREAS = ['makkah', 'jeddah', 'other'];

    /** @return list<array<string,mixed>> */
    public static function list(string $role): array
    {
        self::assertRole($role);
        $stmt = oxy_pdo()->prepare(
            'SELECT id, role, name, phone, area, active, created, updated FROM people WHERE role = ? ORDER BY active DESC, name'
        );
        $stmt->execute([$role]);
        return array_map(static fn (array $row): array => self::shape($row), $stmt->fetchAll());
    }

    /** @return array<string,mixed> */
    public static function find(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new OxyHttpError(404, 'not_found');
        }
        $stmt = oxy_pdo()->prepare('SELECT id, role, name, phone, area, active, created, updated FROM people WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new OxyHttpError(404, 'not_found');
        }
        return self::shape($row);
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public static function create(array $in): array
    {
        $role = self::role($in['role'] ?? null);
        $name = self::name($in['name'] ?? null);
        $phone = self::phone($in['phone'] ?? null);
        $area = self::area($in['area'] ?? null);
        $id = oxy_id();
        $now = time();
        oxy_pdo()->prepare(
            'INSERT INTO people (id, role, name, phone, area, active, created, updated) VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
        )->execute([$id, $role, $name, $phone, $area, $now, $now]);
        Audit::log('person_add', $id, $role);
        return self::find($id);
    }

    /** Changes name, phone, area or active. The role of an existing person never changes. @param array<string,mixed> $in */
    public static function update(string $id, array $in): array
    {
        self::find($id);
        $sets = [];
        $values = [];
        $changed = [];
        if (array_key_exists('name', $in)) {
            $sets[] = 'name = ?';
            $values[] = self::name($in['name']);
            $changed[] = 'name';
        }
        if (array_key_exists('phone', $in)) {
            $sets[] = 'phone = ?';
            $values[] = self::phone($in['phone']);
            $changed[] = 'phone';
        }
        if (array_key_exists('area', $in)) {
            $sets[] = 'area = ?';
            $values[] = self::area($in['area']);
            $changed[] = 'area';
        }
        if (array_key_exists('active', $in)) {
            if (!is_bool($in['active'])) {
                throw new OxyHttpError(400, 'bad_value');
            }
            $sets[] = 'active = ?';
            $values[] = $in['active'] ? 1 : 0;
            $changed[] = 'active';
        }
        if ($sets === []) {
            throw new OxyHttpError(400, 'nothing_to_update');
        }
        $sets[] = 'updated = ?';
        $values[] = time();
        $values[] = $id;
        oxy_pdo()->prepare('UPDATE people SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($values);
        Audit::log('person_update', $id, implode(',', $changed));
        return self::find($id);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function shape(array $row): array
    {
        $row['active'] = (bool) (int) $row['active'];
        $row['created'] = (int) $row['created'];
        $row['updated'] = (int) $row['updated'];
        return $row;
    }

    private static function assertRole(string $role): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new OxyHttpError(400, 'bad_role');
        }
    }

    private static function role(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::ROLES, true)) {
            throw new OxyHttpError(400, 'bad_role');
        }
        return $value;
    }

    private static function name(mixed $value): string
    {
        $name = is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';
        $length = mb_strlen($name);
        if ($length < 2 || $length > 80) {
            throw new OxyHttpError(400, 'bad_name');
        }
        return $name;
    }

    /** Accepts Latin or Arabic-Indic digits, spaces and dashes; stores the digits with an optional leading +. */
    private static function phone(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new OxyHttpError(400, 'bad_phone');
        }
        $digits = strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $phone = (string) preg_replace('/[\s\-]/', '', $digits);
        if (!preg_match('/^\+?[0-9]{9,15}$/', $phone)) {
            throw new OxyHttpError(400, 'bad_phone');
        }
        return $phone;
    }

    private static function area(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !in_array($value, self::AREAS, true)) {
            throw new OxyHttpError(400, 'bad_area');
        }
        return $value;
    }
}
