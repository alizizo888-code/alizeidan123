<?php
declare(strict_types=1);

/**
 * Reports the operations room reads: what each technician has finished and earned, and which finished jobs are
 * still under warranty. Computed here from the orders, so every screen shows the same numbers.
 */
final class Reports
{
    /** @return list<array<string,mixed>> one row per technician */
    public static function accounts(): array
    {
        $rows = oxy_pdo()->query(
            "SELECT p.id, p.name, p.active, ts.share_pct,
                COUNT(CASE WHEN o.status = 'done' THEN 1 END) AS done_count,
                COALESCE(SUM(CASE WHEN o.status = 'done' THEN o.price END), 0) AS done_value,
                COUNT(CASE WHEN o.status IN ('new','assigned','in_progress') THEN 1 END) AS open_count,
                (SELECT COALESCE(SUM(l.amount), 0) FROM ledger l WHERE l.person_id = p.id) AS balance
             FROM people p
             LEFT JOIN orders o ON o.technician_id = p.id
             LEFT JOIN technician_shares ts ON ts.person_id = p.id
             WHERE p.role = 'technician'
             GROUP BY p.id, p.name, p.active, ts.share_pct
             ORDER BY p.active DESC, p.name"
        )->fetchAll();
        $default = Dispatch::defaultShare();
        return array_map(static function (array $r) use ($default): array {
            $share = $r['share_pct'] === null ? $default : (int) $r['share_pct'];
            $value = (int) $r['done_value'];
            return [
                'id' => $r['id'],
                'name' => $r['name'],
                'active' => (bool) (int) $r['active'],
                'done_count' => (int) $r['done_count'],
                'done_value' => $value,
                'share_pct' => $share,
                'earned' => (int) round($value * $share / 100),
                'open_count' => (int) $r['open_count'],
                'balance' => (int) $r['balance'],
            ];
        }, $rows);
    }

    /** @return list<array<string,mixed>> finished jobs with a warranty end date, soonest first */
    public static function warranties(): array
    {
        $now = time();
        $rows = oxy_pdo()->query(
            'SELECT o.id, o.service, o.warranty_until, c.name AS customer_name, p.name AS technician_name
             FROM orders o
             JOIN customers c ON c.id = o.customer_id
             LEFT JOIN people p ON p.id = o.technician_id
             WHERE o.warranty_until IS NOT NULL
             ORDER BY o.warranty_until ASC
             LIMIT 200'
        )->fetchAll();
        return array_map(static function (array $r) use ($now): array {
            $until = (int) $r['warranty_until'];
            return [
                'id' => $r['id'],
                'service' => $r['service'],
                'customer_name' => $r['customer_name'],
                'technician_name' => $r['technician_name'],
                'warranty_until' => $until,
                'active' => $until >= $now,
                'days_left' => (int) floor(($until - $now) / 86400),
            ];
        }, $rows);
    }
}
