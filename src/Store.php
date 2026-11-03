<?php
declare(strict_types=1);

/** Key-value settings (PIN hash, lockout counters, install flag). Portable upsert: delete then insert. */
final class Settings
{
    public static function get(string $key): ?string
    {
        $stmt = oxy_pdo()->prepare('SELECT v FROM settings WHERE k = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public static function set(string $key, string $value): void
    {
        $pdo = oxy_pdo();
        $pdo->prepare('DELETE FROM settings WHERE k = ?')->execute([$key]);
        $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?)')->execute([$key, $value]);
    }
}

/** Append-only log of every owner action and every job decision. */
final class Audit
{
    public static function log(string $action, ?string $target = null, ?string $detail = null): void
    {
        oxy_pdo()->prepare('INSERT INTO audit (id, ts, action, target, detail) VALUES (?, ?, ?, ?, ?)')
            ->execute([oxy_id(), time(), $action, $target, $detail === null ? null : substr($detail, 0, 1000)]);
    }

    public static function recent(int $limit = 50): array
    {
        return oxy_pdo()->query('SELECT ts, action, target, detail FROM audit ORDER BY ts DESC LIMIT ' . (int) $limit)->fetchAll();
    }
}
