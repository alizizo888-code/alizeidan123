<?php
declare(strict_types=1);

/**
 * Orders: one job for one customer, assigned to a technician, with a status and a warranty end date.
 *
 * Statuses: new, assigned, in_progress (active), done, cancelled. A finished job starts its warranty.
 * Orders are never deleted; a cancelled one stays in the customer's history.
 */
final class Orders
{
    public const STATUSES = ['new' => 'جديد', 'assigned' => 'مُسند', 'in_progress' => 'جاري', 'done' => 'مكتمل', 'cancelled' => 'ملغي'];
    public const ACTIVE = ['new', 'assigned', 'in_progress'];

    /** @return list<array<string,mixed>> */
    public static function list(?string $filter = null): array
    {
        $sql = 'SELECT o.*, c.name AS customer_name, c.lat, c.lng, p.name AS technician_name
                FROM orders o
                JOIN customers c ON c.id = o.customer_id
                LEFT JOIN people p ON p.id = o.technician_id';
        $args = [];
        if ($filter === 'active') {
            $sql .= ' WHERE o.status IN (\'new\',\'assigned\',\'in_progress\')';
        }
        $sql .= ' ORDER BY o.updated DESC LIMIT 200';
        $stmt = oxy_pdo()->prepare($sql);
        $stmt->execute($args);
        return array_map([self::class, 'shape'], $stmt->fetchAll());
    }

    /** @return array<string,mixed> */
    public static function find(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new OxyHttpError(404, 'not_found');
        }
        $stmt = oxy_pdo()->prepare(
            'SELECT o.*, c.name AS customer_name, c.lat, c.lng, p.name AS technician_name
             FROM orders o JOIN customers c ON c.id = o.customer_id LEFT JOIN people p ON p.id = o.technician_id
             WHERE o.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new OxyHttpError(404, 'not_found');
        }
        return self::shape($row);
    }

    /** @param array<string,mixed> $in */
    public static function create(array $in): array
    {
        $customerId = (string) ($in['customer_id'] ?? '');
        Customers::find($customerId);
        $service = self::text($in['service'] ?? null, 80);
        $price = self::price($in['price'] ?? null);
        $technician = self::technician($in['technician_id'] ?? null);
        $status = $technician === null ? 'new' : 'assigned';
        $id = oxy_id();
        $now = time();
        oxy_pdo()->prepare(
            'INSERT INTO orders (id, customer_id, technician_id, service, status, price, warranty_until, created, updated)
             VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?)'
        )->execute([$id, $customerId, $technician, $service, $status, $price, $now, $now]);
        Audit::log('order_add', $id, $status);
        return self::find($id);
    }

    /** Changes status, technician, or price. Finishing a job with a warranty length sets the warranty end. */
    public static function update(string $id, array $in): array
    {
        $order = self::find($id);
        $sets = [];
        $args = [];
        if (array_key_exists('status', $in)) {
            $status = $in['status'];
            if (!is_string($status) || !array_key_exists($status, self::STATUSES)) {
                throw new OxyHttpError(400, 'bad_status');
            }
            $sets[] = 'status = ?';
            $args[] = $status;
        }
        if (array_key_exists('technician_id', $in)) {
            $sets[] = 'technician_id = ?';
            $args[] = self::technician($in['technician_id']);
        }
        if (array_key_exists('price', $in)) {
            $sets[] = 'price = ?';
            $args[] = self::price($in['price']);
        }
        if (array_key_exists('warranty_days', $in)) {
            $days = $in['warranty_days'];
            if ($days !== null && (!is_numeric($days) || (int) $days < 0 || (int) $days > 730)) {
                throw new OxyHttpError(400, 'bad_warranty');
            }
            $sets[] = 'warranty_until = ?';
            $args[] = $days === null ? null : time() + ((int) $days) * 86400;
        }
        if ($sets === []) {
            throw new OxyHttpError(400, 'nothing_to_update');
        }
        $sets[] = 'updated = ?';
        $args[] = time();
        $args[] = $id;
        oxy_pdo()->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);

        // A finished job records its price on the customer, so the next quote starts from it.
        $next = self::find($id);
        if ($next['status'] === 'done' && $next['price'] !== null && $order['status'] !== 'done') {
            Customers::update($next['customer_id'], ['last_price' => $next['price']]);
        }
        Audit::log('order_update', $id, implode(',', array_keys($in)));
        return $next;
    }

    /** @return array<string,mixed> */
    private static function shape(array $row): array
    {
        return [
            'id' => $row['id'],
            'customer_id' => $row['customer_id'],
            'customer_name' => $row['customer_name'],
            'lat' => $row['lat'] === null ? null : (float) $row['lat'],
            'lng' => $row['lng'] === null ? null : (float) $row['lng'],
            'technician_id' => $row['technician_id'],
            'technician_name' => $row['technician_name'],
            'service' => $row['service'],
            'status' => $row['status'],
            'status_label' => self::STATUSES[$row['status']] ?? $row['status'],
            'active' => in_array($row['status'], self::ACTIVE, true),
            'price' => $row['price'] === null ? null : (int) $row['price'],
            'warranty_until' => $row['warranty_until'] === null ? null : (int) $row['warranty_until'],
            'created' => (int) $row['created'],
            'updated' => (int) $row['updated'],
        ];
    }

    private static function technician(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new OxyHttpError(400, 'bad_technician');
        }
        $person = People::find($value);
        if ($person['role'] !== 'technician' || !$person['active']) {
            throw new OxyHttpError(400, 'bad_technician');
        }
        return $value;
    }

    private static function price(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (int) $value < 0 || (int) $value > 1000000) {
            throw new OxyHttpError(400, 'bad_price');
        }
        return (int) $value;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new OxyHttpError(400, 'bad_service');
        }
        $text = trim($value);
        if (mb_strlen($text) > $max || preg_match('/[<>]/', $text) === 1) {
            throw new OxyHttpError(400, 'bad_service');
        }
        return $text === '' ? null : $text;
    }
}
