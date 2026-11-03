<?php
declare(strict_types=1);

/**
 * Durable job queue in MySQL. Work is processed in small batches so one request never
 * tries to reach 88 sites inside a shared-hosting time limit. Retries back off per attempt.
 */
final class Jobs
{
    public const MAX_ATTEMPTS = 3;
    private const TYPES = ['health', 'purge'];

    public static function enqueue(string $type, string $siteId): string
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new OxyHttpError(400, 'bad_job_type');
        }
        $id = oxy_id();
        $now = time();
        oxy_pdo()->prepare('INSERT INTO jobs (id, type, site_id, status, attempts, run_after, created, finished, result) VALUES (?, ?, ?, ?, 0, ?, ?, NULL, NULL)')
            ->execute([$id, $type, $siteId, 'queued', $now, $now]);
        return $id;
    }

    public static function enqueueForAll(string $type): int
    {
        $sites = Sites::all();
        foreach ($sites as $site) {
            self::enqueue($type, (string) $site['id']);
        }
        return count($sites);
    }

    /** Processes up to $limit due jobs. Safe to call from cron, from the UI, or both at once. */
    public static function tick(int $limit = 10): array
    {
        $pdo = oxy_pdo();
        $stmt = $pdo->prepare('SELECT id, type, site_id, attempts FROM jobs WHERE status = ? AND run_after <= ? ORDER BY created LIMIT ' . max(1, $limit));
        $stmt->execute(['queued', time()]);
        $summary = ['claimed' => 0, 'done' => 0, 'skipped' => 0, 'retry' => 0, 'failed' => 0];

        foreach ($stmt->fetchAll() as $job) {
            $claim = $pdo->prepare('UPDATE jobs SET status = ?, attempts = attempts + 1 WHERE id = ? AND status = ?');
            $claim->execute(['running', $job['id'], 'queued']);
            if ($claim->rowCount() !== 1) {
                continue; // another worker took it
            }
            $summary['claimed']++;
            $attempt = (int) $job['attempts'] + 1;

            try {
                $result = self::run((string) $job['type'], (string) $job['site_id']);
            } catch (Throwable $e) {
                $result = ['ok' => false, 'error' => 'exception:' . substr($e->getMessage(), 0, 120)];
            }

            if (!empty($result['ok'])) {
                self::finish($job['id'], 'done', $result);
                $summary['done']++;
            } elseif (($result['error'] ?? '') === 'unpaired') {
                self::finish($job['id'], 'skipped', $result);
                $summary['skipped']++;
            } elseif ($attempt < self::MAX_ATTEMPTS) {
                $pdo->prepare('UPDATE jobs SET status = ?, run_after = ?, result = ? WHERE id = ?')
                    ->execute(['queued', time() + 120 * $attempt, substr((string) json_encode($result), 0, 1000), $job['id']]);
                $summary['retry']++;
            } else {
                self::finish($job['id'], 'failed', $result);
                $summary['failed']++;
            }
        }
        $summary['at'] = time();
        return $summary;
    }

    public static function counts(): array
    {
        $rows = oxy_pdo()->query('SELECT status, COUNT(*) AS n FROM jobs GROUP BY status')->fetchAll();
        $out = ['queued' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($rows as $row) {
            $out[$row['status']] = (int) $row['n'];
        }
        return $out;
    }

    public static function recent(int $limit = 30): array
    {
        return oxy_pdo()->query('SELECT id, type, site_id, status, attempts, created, finished FROM jobs ORDER BY created DESC LIMIT ' . (int) $limit)->fetchAll();
    }

    private static function run(string $type, string $siteId): array
    {
        return match ($type) {
            'health' => Sites::health($siteId),
            'purge' => Sites::purge($siteId),
            default => throw new RuntimeException('unknown_job'),
        };
    }

    private static function finish(string $id, string $status, array $result): void
    {
        oxy_pdo()->prepare('UPDATE jobs SET status = ?, finished = ?, result = ? WHERE id = ?')
            ->execute([$status, time(), substr((string) json_encode($result, JSON_UNESCAPED_UNICODE), 0, 4000), $id]);
    }
}
