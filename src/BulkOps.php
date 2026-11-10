<?php
declare(strict_types=1);

/**
 * Bulk operations: run one named action on several faces in one request.
 *
 * Settings changes stay in Bulk. This class covers actions that are not a settings change:
 * a health check (read-only) and a cache purge. Every action has the same shape as Bulk:
 * preview by default, batches of Bulk::MAX_BATCH faces, one result per face, and a readable
 * failure with the party that has to act (see Bulk::explain and Bulk::fix).
 *
 * To add an action: add it to OPS here, add a case in one(), and make sure the face's bridge
 * exposes the route it calls. Actions that change a face must have writes => true.
 */
final class BulkOps
{
    public const OPS = [
        'health' => ['label' => 'فحص الاتصال', 'writes' => false],
        'purge' => ['label' => 'مسح الكاش', 'writes' => true],
    ];

    /**
     * @param list<string> $siteIds
     * @return list<array<string,mixed>>
     */
    public static function run(string $op, array $siteIds, bool $dryRun): array
    {
        if (!isset(self::OPS[$op])) {
            throw new OxyHttpError(400, 'unknown_op');
        }
        if ($siteIds === [] || count($siteIds) > Bulk::MAX_BATCH) {
            throw new OxyHttpError(400, 'bad_batch');
        }
        $results = [];
        foreach ($siteIds as $id) {
            $site = Sites::find($id);
            $results[] = self::one($op, $site, $dryRun);
        }
        return $results;
    }

    /** @return array<string,mixed> */
    private static function one(string $op, array $site, bool $dryRun): array
    {
        $base = ['site_id' => $site['id'], 'name' => $site['name']];

        if ($op === 'health') {
            $result = Sites::health($site['id']);
            if (!$result['ok']) {
                return Bulk::failed($site, (string) $result['error']);
            }
            return $base + ['ok' => true, 'detail' => 'متصل'];
        }

        // purge: a preview only says what would happen; nothing is cleared until the owner applies.
        if ($dryRun) {
            return $base + ['ok' => true, 'preview' => true, 'detail' => 'سيتم مسح الكاش'];
        }
        $result = Sites::purge($site['id']);
        if (!$result['ok']) {
            Audit::log('bulk_op_failed', $site['id'], 'purge: ' . substr((string) $result['error'], 0, 160));
            return Bulk::failed($site, (string) $result['error']);
        }
        Audit::log('bulk_op_ok', $site['id'], 'purge');
        return $base + ['ok' => true, 'detail' => 'تم مسح الكاش', 'purged' => $result['data']['purged'] ?? []];
    }
}
