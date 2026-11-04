<?php
declare(strict_types=1);

/**
 * Dispatch and intake settings: how new orders get a technician, whether the faces may send orders,
 * the default technician share of a job's price, and the default warranty for a finished job.
 *
 * Only the keys below can be read or written. Values live in the settings table with a "dispatch." prefix.
 */
final class Dispatch
{
    public const MODES = ['manual', 'auto'];
    public const RULES = ['nearest', 'available', 'idle'];
    private const PREFIX = 'dispatch.';

    /** @return array<string,mixed> */
    public static function all(): array
    {
        return [
            'mode' => self::mode(),
            'rule' => self::rule(),
            'intake_open' => self::intakeOpen(),
            'default_share' => self::defaultShare(),
            'default_warranty_days' => self::defaultWarrantyDays(),
        ];
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public static function save(array $in): array
    {
        $allowed = ['mode', 'rule', 'intake_open', 'default_share', 'default_warranty_days'];
        if (array_diff(array_map('strval', array_keys($in)), $allowed) !== []) {
            throw new OxyHttpError(400, 'unknown_setting');
        }
        if (array_key_exists('mode', $in)) {
            if (!in_array($in['mode'], self::MODES, true)) {
                throw new OxyHttpError(400, 'bad_mode');
            }
            Settings::set(self::PREFIX . 'mode', (string) $in['mode']);
        }
        if (array_key_exists('rule', $in)) {
            if (!in_array($in['rule'], self::RULES, true)) {
                throw new OxyHttpError(400, 'bad_mode');
            }
            Settings::set(self::PREFIX . 'rule', (string) $in['rule']);
        }
        if (array_key_exists('intake_open', $in)) {
            if (!is_bool($in['intake_open'])) {
                throw new OxyHttpError(400, 'bad_value');
            }
            Settings::set(self::PREFIX . 'intake_open', $in['intake_open'] ? '1' : '0');
        }
        if (array_key_exists('default_share', $in)) {
            Settings::set(self::PREFIX . 'default_share', (string) self::percent($in['default_share']));
        }
        if (array_key_exists('default_warranty_days', $in)) {
            Settings::set(self::PREFIX . 'default_warranty_days', (string) self::days($in['default_warranty_days']));
        }
        Audit::log('dispatch_settings', 'dispatch', implode(',', array_keys($in)));
        return self::all();
    }

    public static function mode(): string
    {
        $value = Settings::get(self::PREFIX . 'mode');
        return in_array($value, self::MODES, true) ? (string) $value : 'manual';
    }

    public static function rule(): string
    {
        $value = Settings::get(self::PREFIX . 'rule');
        return in_array($value, self::RULES, true) ? (string) $value : 'nearest';
    }

    /** Intake is open unless the owner paused it. */
    public static function intakeOpen(): bool
    {
        return Settings::get(self::PREFIX . 'intake_open') !== '0';
    }

    public static function defaultShare(): int
    {
        $value = Settings::get(self::PREFIX . 'default_share');
        return $value === null ? 90 : (int) $value;
    }

    public static function defaultWarrantyDays(): int
    {
        $value = Settings::get(self::PREFIX . 'default_warranty_days');
        return $value === null ? 0 : (int) $value;
    }

    /** Share per technician id, only for those set by hand. @return array<string,int> */
    public static function shares(): array
    {
        $out = [];
        foreach (oxy_pdo()->query('SELECT person_id, share_pct FROM technician_shares')->fetchAll() as $row) {
            $out[$row['person_id']] = (int) $row['share_pct'];
        }
        return $out;
    }

    /** Sets a technician's own share, or clears it (null) so the default applies again. */
    public static function setShare(string $personId, mixed $value): array
    {
        $person = People::find($personId);
        if ($person['role'] !== 'technician') {
            throw new OxyHttpError(400, 'not_technician');
        }
        $pdo = oxy_pdo();
        $pdo->prepare('DELETE FROM technician_shares WHERE person_id = ?')->execute([$personId]);
        if ($value !== null) {
            $pdo->prepare('INSERT INTO technician_shares (person_id, share_pct) VALUES (?, ?)')
                ->execute([$personId, self::percent($value)]);
        }
        Audit::log('technician_share', $personId, $value === null ? 'default' : (string) $value);
        return ['person_id' => $personId, 'share_pct' => $value === null ? self::defaultShare() : (int) $value, 'custom' => $value !== null];
    }

    private static function percent(mixed $value): int
    {
        if (!is_numeric($value) || (int) $value < 0 || (int) $value > 100) {
            throw new OxyHttpError(400, 'bad_share');
        }
        return (int) $value;
    }

    private static function days(mixed $value): int
    {
        if (!is_numeric($value) || (int) $value < 0 || (int) $value > 730) {
            throw new OxyHttpError(400, 'bad_warranty');
        }
        return (int) $value;
    }
}
