<?php
declare(strict_types=1);

/**
 * Customers: who they are, where they are, how they have dealt with us, and how to price them.
 *
 * The tier is the owner's grade of the customer (weak, medium, high, premium). It guides the next price.
 * "Previous work" and "active" come from their orders, so they stay correct without manual flags.
 * Customers are never deleted, so their history stays.
 */
final class Customers
{
    public const TIERS = ['weak' => 'ضعيفة', 'medium' => 'متوسطة', 'high' => 'عالية', 'premium' => 'رفيعة'];

    /** @return list<array<string,mixed>> */
    public static function list(): array
    {
        $rows = oxy_pdo()->query(
            'SELECT c.*,
                (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.status IN (\'new\',\'assigned\',\'in_progress\')) AS active_orders,
                (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.status = \'done\') AS done_orders,
                (SELECT MAX(o.warranty_until) FROM orders o WHERE o.customer_id = c.id AND o.status = \'done\') AS warranty_until
             FROM customers c ORDER BY c.updated DESC'
        )->fetchAll();
        $now = time();
        return array_map(static function (array $row) use ($now): array {
            return self::shape($row) + [
                'active_orders' => (int) $row['active_orders'],
                'done_orders' => (int) $row['done_orders'],
                'under_warranty' => $row['warranty_until'] !== null && (int) $row['warranty_until'] >= $now,
            ];
        }, $rows);
    }

    /** @return array<string,mixed> */
    public static function find(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new OxyHttpError(404, 'not_found');
        }
        $stmt = oxy_pdo()->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new OxyHttpError(404, 'not_found');
        }
        return self::shape($row);
    }

    /** The customer with this phone, or null. The phone is normalised first, so any written form matches. */
    public static function findByPhone(string $raw): ?array
    {
        $phone = self::phone($raw);
        if ($phone === null) {
            return null;
        }
        $stmt = oxy_pdo()->prepare('SELECT * FROM customers WHERE phone = ? ORDER BY created LIMIT 1');
        $stmt->execute([$phone]);
        $row = $stmt->fetch();
        return $row ? self::shape($row) : null;
    }

    /** @param array<string,mixed> $in */
    public static function create(array $in): array
    {
        $name = self::name($in['name'] ?? null);
        $phone = self::phone($in['phone'] ?? null);
        $address = self::text($in['address'] ?? null, 200, 'bad_address');
        [$lat, $lng] = self::optionalPoint($in['lat'] ?? null, $in['lng'] ?? null);
        $tier = self::tier($in['tier'] ?? 'medium');
        $id = oxy_id();
        $now = time();
        oxy_pdo()->prepare(
            'INSERT INTO customers (id, name, phone, address, lat, lng, tier, rating, rating_note, last_price, created, updated)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?, ?)'
        )->execute([$id, $name, $phone, $address, $lat, $lng, $tier, $now, $now]);
        Audit::log('customer_add', $id, $tier);
        return self::find($id);
    }

    /** Updates the fields the owner changes. Unknown fields are ignored; an empty update is refused. */
    public static function update(string $id, array $in): array
    {
        self::find($id);
        $sets = [];
        $args = [];
        if (array_key_exists('tier', $in)) {
            $sets[] = 'tier = ?';
            $args[] = self::tier($in['tier']);
        }
        if (array_key_exists('rating', $in)) {
            $rating = $in['rating'];
            if ($rating !== null && (!is_int($rating) && !(is_string($rating) && ctype_digit($rating)) || (int) $rating < 1 || (int) $rating > 5)) {
                throw new OxyHttpError(400, 'bad_rating');
            }
            $sets[] = 'rating = ?';
            $args[] = $rating === null ? null : (int) $rating;
        }
        if (array_key_exists('rating_note', $in)) {
            $sets[] = 'rating_note = ?';
            $args[] = self::text($in['rating_note'], 200, 'bad_note');
        }
        if (array_key_exists('last_price', $in)) {
            $price = $in['last_price'];
            if ($price !== null && (!is_numeric($price) || (int) $price < 0 || (int) $price > 1000000)) {
                throw new OxyHttpError(400, 'bad_price');
            }
            $sets[] = 'last_price = ?';
            $args[] = $price === null ? null : (int) $price;
        }
        if (array_key_exists('lat', $in) || array_key_exists('lng', $in)) {
            [$lat, $lng] = self::optionalPoint($in['lat'] ?? null, $in['lng'] ?? null);
            $sets[] = 'lat = ?, lng = ?';
            array_push($args, $lat, $lng);
        }
        if ($sets === []) {
            throw new OxyHttpError(400, 'nothing_to_update');
        }
        $sets[] = 'updated = ?';
        $args[] = time();
        $args[] = $id;
        oxy_pdo()->prepare('UPDATE customers SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
        Audit::log('customer_update', $id, implode(',', array_keys($in)));
        return self::find($id);
    }

    /** Text to send on WhatsApp with the customer's location, so the owner can share it or forward it. */
    public static function whatsappLink(array $customer): ?string
    {
        if ($customer['lat'] === null || $customer['phone'] === null) {
            return null;
        }
        $map = 'https://maps.google.com/?q=' . $customer['lat'] . ',' . $customer['lng'];
        $text = 'موقع العميل ' . $customer['name'] . ': ' . $map;
        return 'https://wa.me/' . $customer['phone'] . '?text=' . rawurlencode($text);
    }

    /** @return array<string,mixed> */
    private static function shape(array $row): array
    {
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'phone' => $row['phone'],
            'address' => $row['address'],
            'lat' => $row['lat'] === null ? null : (float) $row['lat'],
            'lng' => $row['lng'] === null ? null : (float) $row['lng'],
            'tier' => $row['tier'],
            'tier_label' => self::TIERS[$row['tier']] ?? $row['tier'],
            'rating' => $row['rating'] === null ? null : (int) $row['rating'],
            'rating_note' => $row['rating_note'],
            'last_price' => $row['last_price'] === null ? null : (int) $row['last_price'],
            'created' => (int) $row['created'],
            'updated' => (int) $row['updated'],
        ];
    }

    private static function name(mixed $value): string
    {
        $name = self::text($value, 120, 'bad_name');
        if ($name === null || mb_strlen($name) < 2) {
            throw new OxyHttpError(400, 'bad_name');
        }
        return $name;
    }

    /** Saudi mobile numbers, stored as 9665XXXXXXXX so a WhatsApp link can use them directly. */
    public static function phone(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new OxyHttpError(400, 'bad_phone');
        }
        $digits = strtr(trim($value), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        $digits = preg_replace('/[\s\-+()]/', '', $digits) ?? '';
        if (str_starts_with($digits, '00966')) {
            $digits = '966' . substr($digits, 5);
        }
        if (preg_match('/^05\d{8}$/', $digits) === 1) {
            $digits = '966' . substr($digits, 1);
        }
        if (preg_match('/^9665\d{8}$/', $digits) !== 1) {
            throw new OxyHttpError(400, 'bad_phone');
        }
        return $digits;
    }

    private static function text(mixed $value, int $max, string $code): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new OxyHttpError(400, $code);
        }
        $text = trim($value);
        if (mb_strlen($text) > $max || preg_match('/[<>]/', $text) === 1) {
            throw new OxyHttpError(400, $code);
        }
        return $text === '' ? null : $text;
    }

    /** @return array{0: ?float, 1: ?float} */
    private static function optionalPoint(mixed $lat, mixed $lng): array
    {
        if (($lat === null || $lat === '') && ($lng === null || $lng === '')) {
            return [null, null];
        }
        return [Locations::coordinate($lat, 90.0), Locations::coordinate($lng, 180.0)];
    }

    private static function tier(mixed $value): string
    {
        if (!is_string($value) || !array_key_exists($value, self::TIERS)) {
            throw new OxyHttpError(400, 'bad_tier');
        }
        return $value;
    }
}
