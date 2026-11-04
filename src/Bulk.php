<?php
declare(strict_types=1);

/**
 * Bulk settings: change the same allowed WordPress settings on several faces in one action.
 *
 * Every change goes through the signed bridge (no WordPress login, so no permission errors),
 * is checked here first, and can be previewed before it is applied.
 *
 * To add a setting: add it to FIELDS here AND to oxy_bridge_settings_fields() in
 * bridge/oxy-bridge/oxy-bridge.php, then ship the new plugin to the faces. The panel reads FIELDS from this class.
 */
final class Bulk
{
    /** key => rule. The label is what the panel shows. */
    public const FIELDS = [
        'blogname' => ['type' => 'text', 'min' => 1, 'max' => 200, 'label' => 'اسم الموقع'],
        'blogdescription' => ['type' => 'text', 'min' => 0, 'max' => 200, 'label' => 'الوصف المختصر'],
        'posts_per_page' => ['type' => 'int', 'min' => 1, 'max' => 50, 'label' => 'عدد المقالات في الصفحة'],
    ];

    /** Faces per request. The panel sends larger selections in several requests. */
    public const MAX_BATCH = 5;

    /**
     * Checks the values before any face is touched. Throws on the first problem.
     * @param array<string,mixed> $values
     * @return array<string,string|int>
     */
    public static function validate(array $values): array
    {
        if ($values === []) {
            throw new OxyHttpError(400, 'nothing_to_change');
        }
        $clean = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            if (!isset(self::FIELDS[$key])) {
                throw new OxyHttpError(400, 'unknown_setting');
            }
            $rule = self::FIELDS[$key];
            if ($rule['type'] === 'int') {
                if (is_string($value) && preg_match('/^\d+$/', $value)) {
                    $value = (int) $value;
                }
                if (!is_int($value) || $value < $rule['min'] || $value > $rule['max']) {
                    throw new OxyHttpError(400, 'bad_value');
                }
                $clean[$key] = $value;
                continue;
            }
            if (!is_string($value)) {
                throw new OxyHttpError(400, 'bad_value');
            }
            $text = trim($value);
            $length = mb_strlen($text);
            if ($length < $rule['min'] || $length > $rule['max'] || preg_match('/[<>]/', $text)) {
                throw new OxyHttpError(400, 'bad_value');
            }
            $clean[$key] = $text;
        }
        return $clean;
    }

    /**
     * Previews (dry run: reads each face and reports what would change) or applies the values to the faces.
     * Each face gets its own result, so one failing face never hides the others.
     * @param list<string> $siteIds
     * @param array<string,mixed> $values
     * @return list<array<string,mixed>>
     */
    public static function run(array $siteIds, array $values, bool $dryRun): array
    {
        if ($siteIds === [] || count($siteIds) > self::MAX_BATCH) {
            throw new OxyHttpError(400, 'bad_batch');
        }
        $clean = self::validate($values);
        $results = [];
        foreach ($siteIds as $id) {
            $site = Sites::find($id);
            $results[] = $dryRun ? self::preview($site, $clean) : self::apply($site, $clean);
        }
        return $results;
    }

    /** @return array<string,mixed> */
    private static function preview(array $site, array $clean): array
    {
        $read = Sites::call($site, 'GET', '/oxy-bridge/v1/settings');
        if (!$read['ok']) {
            return self::failed($site, $read['error']);
        }
        $now = $read['data']['values'] ?? [];
        $changes = [];
        foreach ($clean as $key => $value) {
            $from = $now[$key] ?? null;
            $changes[$key] = ['from' => $from, 'to' => $value, 'same' => $from === $value];
        }
        return ['site_id' => $site['id'], 'name' => $site['name'], 'ok' => true, 'changes' => $changes];
    }

    /** @return array<string,mixed> */
    private static function apply(array $site, array $clean): array
    {
        $write = Sites::call($site, 'POST', '/oxy-bridge/v1/settings', ['values' => $clean]);
        if (!$write['ok']) {
            Audit::log('bulk_settings_failed', $site['id'], substr((string) $write['error'], 0, 200));
            return self::failed($site, $write['error']);
        }
        Audit::log('bulk_settings_ok', $site['id'], implode(',', array_keys($clean)));
        return [
            'site_id' => $site['id'],
            'name' => $site['name'],
            'ok' => true,
            'changed' => $write['data']['changed'] ?? [],
            'before' => $write['data']['before'] ?? [],
            'after' => $write['data']['after'] ?? [],
        ];
    }

    /** @return array<string,mixed> */
    private static function failed(array $site, ?string $error): array
    {
        return ['site_id' => $site['id'], 'name' => $site['name'], 'ok' => false, 'error' => self::explain((string) $error)];
    }

    /** Turns a transport or bridge error into a short Arabic message for the owner. */
    public static function explain(string $error): string
    {
        if ($error === 'unpaired') {
            return 'غير مربوط';
        }
        if (str_starts_with($error, 'network')) {
            return 'تعذّر الوصول للموقع';
        }
        if ($error === 'http_404:rest_no_route') {
            return 'الإضافة قديمة، حدّثها على هذا الموقع';
        }
        if (str_starts_with($error, 'http_401')) {
            return 'المفتاح لا يطابق، أعد الربط';
        }
        if (str_starts_with($error, 'non_json')) {
            return 'الموقع أرجع صفحة غير متوقعة (ربما حماية أو صيانة)';
        }
        if (str_starts_with($error, 'http_400')) {
            return 'القيمة مرفوضة على الموقع';
        }
        return 'فشل: ' . substr($error, 0, 120);
    }
}
