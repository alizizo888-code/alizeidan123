<?php
declare(strict_types=1);

/**
 * Technician balance: every amount owed to a technician or taken from him, in one ledger.
 *
 *  - earning: written once when a job is finished (its price times the technician's share). Never written twice.
 *  - deduction: money taken from the balance (a warranty return, damage, a late job). Stored negative.
 *  - payment: money paid out to the technician. Stored negative, and refused beyond the balance.
 *  - adjustment: a correction either way, with a note. Must not be zero.
 *
 * Rows are never edited or deleted: a mistake is fixed with an adjustment, so the history stays whole.
 */
final class Ledger
{
    public const KINDS = ['earning' => 'أرباح', 'deduction' => 'خصم', 'payment' => 'صرف', 'adjustment' => 'تسوية'];
    public const MAX_AMOUNT = 1000000;

    /** Writes the earning of a finished job, once. Returns true when a row was written. */
    public static function earnOrder(string $orderId): bool
    {
        $order = Orders::find($orderId);
        if ($order['status'] !== 'completed' || $order['technician_id'] === null || $order['technician_amount'] === null) {
            return false;
        }
        $exists = oxy_pdo()->prepare("SELECT 1 FROM ledger WHERE order_id = ? AND kind = 'earning'");
        $exists->execute([$orderId]);
        if ($exists->fetchColumn() !== false) {
            return false;
        }
        self::insert($order['technician_id'], 'earning', (int) $order['technician_amount'], $orderId, $order['service'] ?? '');
        return true;
    }

    /** Records a deduction, payment or adjustment entered by the owner. @return array<string,mixed> the technician's balance */
    public static function record(string $personId, mixed $kind, mixed $amount, mixed $note): array
    {
        $person = People::find($personId);
        if ($person['role'] !== 'technician') {
            throw new OxyHttpError(400, 'not_technician');
        }
        if (!is_string($kind) || !in_array($kind, ['deduction', 'payment', 'adjustment'], true)) {
            throw new OxyHttpError(400, 'bad_kind');
        }
        if (!is_numeric($amount) || (float) (int) $amount !== (float) $amount) {
            throw new OxyHttpError(400, 'bad_amount');
        }
        $value = (int) $amount;
        if ($kind === 'adjustment') {
            if ($value === 0 || abs($value) > self::MAX_AMOUNT) {
                throw new OxyHttpError(400, 'bad_amount');
            }
        } elseif ($value <= 0 || $value > self::MAX_AMOUNT) {
            throw new OxyHttpError(400, 'bad_amount');
        }
        $text = is_string($note) ? trim((string) preg_replace('/\s+/u', ' ', $note)) : '';
        if (mb_strlen($text) > 200 || preg_match('/[<>]/', $text) === 1) {
            throw new OxyHttpError(400, 'bad_note');
        }
        if ($kind === 'adjustment' && $text === '') {
            throw new OxyHttpError(400, 'note_required');
        }
        if ($kind === 'payment' && $value > self::balance($personId)) {
            throw new OxyHttpError(409, 'insufficient_balance');
        }
        $signed = $kind === 'adjustment' ? $value : -$value;
        self::insert($personId, $kind, $signed, null, $text);
        return ['person_id' => $personId, 'balance' => self::balance($personId)];
    }

    public static function balance(string $personId): int
    {
        $stmt = oxy_pdo()->prepare('SELECT COALESCE(SUM(amount), 0) FROM ledger WHERE person_id = ?');
        $stmt->execute([$personId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> the latest rows for one technician, newest first */
    public static function rows(string $personId, int $limit = 100): array
    {
        $stmt = oxy_pdo()->prepare('SELECT id, kind, amount, order_id, note, created FROM ledger WHERE person_id = ? ORDER BY seq DESC LIMIT ' . (int) $limit);
        $stmt->execute([$personId]);
        return array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'kind' => $r['kind'],
            'kind_label' => self::KINDS[$r['kind']] ?? $r['kind'],
            'amount' => (int) $r['amount'],
            'order_id' => $r['order_id'],
            'note' => $r['note'],
            'created' => (int) $r['created'],
        ], $stmt->fetchAll());
    }

    /** Earnings for finished jobs that were done before the ledger existed. Idempotent. */
    public static function backfill(): int
    {
        $stmt = oxy_pdo()->query(
            "SELECT o.id FROM orders o
             WHERE o.status = 'completed' AND o.technician_id IS NOT NULL AND o.price IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM ledger l WHERE l.order_id = o.id AND l.kind = 'earning')"
        );
        $done = 0;
        foreach ($stmt->fetchAll() as $row) {
            if (self::earnOrder($row['id'])) {
                $done += 1;
            }
        }
        return $done;
    }

    private static function insert(string $personId, string $kind, int $amount, ?string $orderId, string $note): void
    {
        // Per-technician sequence keeps the history in the order it was written, even within one second.
        $next = oxy_pdo()->prepare('SELECT COALESCE(MAX(seq), 0) + 1 FROM ledger WHERE person_id = ?');
        $next->execute([$personId]);
        $seq = (int) $next->fetchColumn();
        oxy_pdo()->prepare('INSERT INTO ledger (id, person_id, kind, amount, order_id, note, created, seq) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([oxy_id(), $personId, $kind, $amount, $orderId, $note, time(), $seq]);
        Audit::log('ledger_' . $kind, $orderId ?? $personId, (string) $amount);
    }
}
