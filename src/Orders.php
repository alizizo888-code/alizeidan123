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
    /**
     * The states an order moves through. Kept in Workflow, which carries the whole chain, so the
     * desk and the people using it never disagree about what a stage is called.
     *
     * 'done' is still accepted on the way in, because orders written before this change use it, and
     * a reader should not have to know which week an order was created to understand its status.
     */
    public const STATUSES = Workflow::STATUSES;
    private const LEGACY_STATUS = ['done' => 'completed'];
    public const ACTIVE = ['new', 'assigned', 'in_progress'];

    /** @return list<array<string,mixed>> */
    public static function list(?string $filter = null): array
    {
        $sql = 'SELECT o.*, c.name AS customer_name, c.lat, c.lng, p.name AS technician_name, so.code, so.source, ts.share_pct
                FROM orders o
                JOIN customers c ON c.id = o.customer_id
                LEFT JOIN people p ON p.id = o.technician_id
                LEFT JOIN technician_shares ts ON ts.person_id = o.technician_id
                LEFT JOIN site_orders so ON so.order_id = o.id';
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
            'SELECT o.*, c.name AS customer_name, c.lat, c.lng, p.name AS technician_name, so.code, so.source, ts.share_pct
             FROM orders o JOIN customers c ON c.id = o.customer_id LEFT JOIN people p ON p.id = o.technician_id
             LEFT JOIN technician_shares ts ON ts.person_id = o.technician_id
             LEFT JOIN site_orders so ON so.order_id = o.id
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
            $status = is_string($status) ? (self::LEGACY_STATUS[$status] ?? $status) : $status;
            if (!is_string($status) || !array_key_exists($status, self::STATUSES)) {
                throw new OxyHttpError(400, 'bad_status');
            }
            $sets[] = 'status = ?';
            $args[] = $status;
        }
        if (array_key_exists('handler', $in)) {
            $handler = $in['handler'];
            if (!is_string($handler) || !array_key_exists($handler, Workflow::HANDLERS)) {
                throw new OxyHttpError(400, 'bad_handler');
            }
            $sets[] = 'handler = ?';
            $args[] = $handler;
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
        $wanted = array_key_exists('status', $in) && is_string($in['status']) ? (self::LEGACY_STATUS[$in['status']] ?? $in['status']) : null;
        $finishing = $wanted === 'completed' && $order['status'] !== 'completed';
        if ($finishing && !array_key_exists('warranty_days', $in) && $order['warranty_until'] === null && Dispatch::defaultWarrantyDays() > 0) {
            $sets[] = 'warranty_until = ?';
            $args[] = time() + Dispatch::defaultWarrantyDays() * 86400;
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
        if ($next['status'] === 'completed' && $next['price'] !== null && $order['status'] !== 'completed') {
            Customers::update($next['customer_id'], ['last_price' => $next['price']]);
        }
        // A finished job with a technician earns him his share, once, in the ledger.
        if ($next['status'] === 'completed') {
            Ledger::earnOrder($id);
        }
        Audit::log('order_update', $id, implode(',', array_keys($in)));
        return $next;
    }

    /**
     * Assigns an open order to a technician by rule. The supervisor can always assign by hand; this is for when
     * no supervisor is on duty. Modes:
     *  - nearest: the closest active technician with a known position (busy ones count too, as a fallback)
     *  - available: an active technician with no active order
     *  - idle: available AND reported a position in the last two hours (free and active)
     */
    public static function distribute(string $orderId, string $mode): array
    {
        if (!in_array($mode, ['nearest', 'available', 'idle'], true)) {
            throw new OxyHttpError(400, 'bad_mode');
        }
        $order = self::find($orderId);
        if (!in_array($order['status'], ['new', 'assigned'], true)) {
            throw new OxyHttpError(409, 'not_open');
        }
        $techs = oxy_pdo()->query(
            "SELECT p.id, p.name, l.lat, l.lng, l.updated,
                (SELECT COUNT(*) FROM orders o WHERE o.technician_id = p.id AND o.status IN ('new','assigned','in_progress')) AS busy
             FROM people p LEFT JOIN technician_locations l ON l.person_id = p.id
             WHERE p.role = 'technician' AND p.active = 1"
        )->fetchAll();

        $now = time();
        $candidates = array_values(array_filter($techs, static function (array $t) use ($mode, $now): bool {
            return match ($mode) {
                'nearest' => $t['lat'] !== null,
                'available' => (int) $t['busy'] === 0,
                'idle' => (int) $t['busy'] === 0 && $t['lat'] !== null && (int) $t['updated'] >= $now - 7200,
            };
        }));
        if ($candidates === []) {
            throw new OxyHttpError(409, 'no_technician');
        }
        // A technician with the specialty the service needs comes first. If nobody has it, the rule still applies to all.
        $wanted = Skills::forService((string) ($order['service'] ?? ''));
        if ($wanted !== []) {
            $skills = Skills::all();
            $matching = array_values(array_filter($candidates, static fn (array $t): bool =>
                array_intersect($skills[$t['id']] ?? [], $wanted) !== []));
            if ($matching !== []) {
                $candidates = $matching;
            }
        }

        $pick = $candidates[0];
        if ($mode === 'nearest') {
            if ($order['lat'] === null) {
                throw new OxyHttpError(409, 'no_customer_location');
            }
            usort($candidates, static fn (array $a, array $b): int =>
                self::km((float) $a['lat'], (float) $a['lng'], (float) $order['lat'], (float) $order['lng'])
                <=> self::km((float) $b['lat'], (float) $b['lng'], (float) $order['lat'], (float) $order['lng']));
            $pick = $candidates[0];
        }
        $updated = self::update($orderId, ['technician_id' => $pick['id'], 'status' => 'assigned']);
        Audit::log('order_distributed', $orderId, $mode . ':' . $pick['id']);
        return $updated;
    }

    /**
     * Technicians ranked for one open order: distance in km (straight line) and a rough time in minutes,
     * assuming 30 km/h plus 5 minutes. Both are estimates for the owner, not a route.
     */
    public static function candidates(string $orderId): array
    {
        $order = self::find($orderId);
        if (!in_array($order['status'], ['new', 'assigned'], true)) {
            throw new OxyHttpError(409, 'not_open');
        }
        $wanted = Skills::forService((string) ($order['service'] ?? ''));
        $rows = [];
        foreach (Locations::live() as $t) {
            $km = ($t['lat'] !== null && $order['lat'] !== null)
                ? round(self::km((float) $t['lat'], (float) $t['lng'], (float) $order['lat'], (float) $order['lng']), 1)
                : null;
            $rows[] = [
                'id' => $t['id'],
                'name' => $t['name'],
                'status' => $t['status'],
                'busy_orders' => $t['busy_orders'],
                'skills' => $t['skills'],
                'matches' => array_intersect($t['skills'], $wanted) !== [],
                'km' => $km,
                'minutes' => $km === null ? null : (int) round($km / 30 * 60 + 5),
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            if ($a['matches'] !== $b['matches']) {
                return $a['matches'] ? -1 : 1;
            }
            if ($a['km'] === null || $b['km'] === null) {
                return ($a['km'] === null ? 1 : 0) <=> ($b['km'] === null ? 1 : 0);
            }
            return $a['km'] <=> $b['km'];
        });
        return ['order_id' => $orderId, 'needs' => $wanted, 'candidates' => $rows];
    }

    private static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
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
            // Who is holding this order right now, read the same way by every screen.
            'handler' => Workflow::handler($row),
            'handler_label' => Workflow::HANDLERS[Workflow::handler($row)],
            'technician_name' => $row['technician_name'],
            'service' => $row['service'],
            'code' => $row['code'] ?? null,
            'source' => $row['source'] ?? 'desk',
            'status' => $row['status'],
            'status_label' => self::STATUSES[$row['status']] ?? $row['status'],
            'active' => in_array($row['status'], self::ACTIVE, true),
            'price' => $row['price'] === null ? null : (int) $row['price'],
            // The technician's own share if one was set for them, otherwise what this service pays.
            'technician_share_pct' => $row['technician_id'] === null ? null
                : (int) ($row['share_pct'] ?? Dispatch::shareFor($row['technician_id'], (string) $row['service'])),
            'technician_amount' => ($row['technician_id'] === null || $row['price'] === null)
                ? null
                : (int) round((int) $row['price'] * (int) ($row['share_pct'] ?? Dispatch::shareFor($row['technician_id'], (string) $row['service'])) / 100),
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
