<?php
declare(strict_types=1);

/**
 * General settings: plain business details the owner edits from the panel (used on invoices and in messages).
 *
 * Only the keys listed in FIELDS can be read or written. Anything else is refused, so a request
 * can never change secrets or internal settings such as the PIN, the install flag or the schema version.
 */
final class General
{
    /** key => maximum length in characters */
    public const FIELDS = [
        'company.name' => 120,
        'company.tax_number' => 30,
        'company.phone' => 20,
        'company.service_area' => 200,
        'company.hours' => 200,
    ];

    private const PREFIX = 'gen.';

    /** @return array<string,string> */
    public static function all(): array
    {
        $values = [];
        foreach (array_keys(self::FIELDS) as $key) {
            $values[$key] = Settings::get(self::PREFIX . $key) ?? '';
        }
        return $values;
    }

    /** Saves the given fields. Fields left out keep their value. @param array<string,mixed> $values @return array<string,string> */
    public static function save(array $values): array
    {
        $unknown = array_diff(array_map('strval', array_keys($values)), array_keys(self::FIELDS));
        if ($unknown !== []) {
            throw new OxyHttpError(400, 'unknown_setting');
        }
        $clean = [];
        foreach ($values as $key => $value) {
            if ($value !== null && !is_string($value)) {
                throw new OxyHttpError(400, 'bad_value');
            }
            $text = trim((string) $value);
            if (mb_strlen($text) > self::FIELDS[(string) $key]) {
                throw new OxyHttpError(400, 'too_long');
            }
            // Plain text only: the panel escapes output, and markup in a business name has no use.
            if (preg_match('/[<>]/', $text)) {
                throw new OxyHttpError(400, 'bad_value');
            }
            $clean[(string) $key] = $text;
        }
        foreach ($clean as $key => $text) {
            Settings::set(self::PREFIX . $key, $text);
        }
        if ($clean !== []) {
            Audit::log('settings_update', 'general', implode(',', array_keys($clean)));
        }
        return self::all();
    }
}
