<?php
declare(strict_types=1);

/**
 * Messages on one order, kept with it so the history stays with the job.
 * Senders: owner (the desk), supervisor, technician, customer. Writing here never sends WhatsApp by itself.
 */
final class Messages
{
    public const SENDERS = ['owner' => 'المالك', 'supervisor' => 'المشرف', 'technician' => 'الفني', 'customer' => 'العميل'];

    /** @return list<array<string,mixed>> */
    public static function list(string $orderId): array
    {
        Orders::find($orderId);
        $stmt = oxy_pdo()->prepare('SELECT id, order_id, sender, body, created FROM order_messages WHERE order_id = ? ORDER BY created, seq');
        $stmt->execute([$orderId]);
        return array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'sender' => $r['sender'],
            'sender_label' => self::SENDERS[$r['sender']] ?? $r['sender'],
            'body' => $r['body'],
            'created' => (int) $r['created'],
        ], $stmt->fetchAll());
    }

    public static function add(string $orderId, mixed $body, string $sender = 'owner'): array
    {
        Orders::find($orderId);
        if (!array_key_exists($sender, self::SENDERS)) {
            throw new OxyHttpError(400, 'bad_sender');
        }
        if (!is_string($body)) {
            throw new OxyHttpError(400, 'bad_message');
        }
        $text = trim($body);
        if ($text === '' || mb_strlen($text) > 500 || preg_match('/[<>]/', $text) === 1) {
            throw new OxyHttpError(400, 'bad_message');
        }
        $id = oxy_id();
        $pdo = oxy_pdo();
        // Sequence within the order keeps messages in the order they were written, even within one second.
        $next = $pdo->prepare('SELECT COALESCE(MAX(seq), 0) + 1 FROM order_messages WHERE order_id = ?');
        $next->execute([$orderId]);
        $seq = (int) $next->fetchColumn();
        $pdo->prepare('INSERT INTO order_messages (id, order_id, sender, body, created, seq) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, $orderId, $sender, $text, time(), $seq]);
        Audit::log('order_message', $orderId, $sender);
        return ['id' => $id, 'sender' => $sender, 'sender_label' => self::SENDERS[$sender], 'body' => $text, 'created' => time()];
    }
}
