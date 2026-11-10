<?php
declare(strict_types=1);

/**
 * Orders that a face website sends to the desk the moment the customer confirms.
 *
 * The face signs the request with the same key it already uses for the bridge (paired once from the desk),
 * so nothing new has to be typed anywhere. A repeated send with the same site reference returns the
 * order it already made, so a retry never creates a duplicate.
 */
final class Intake
{
    public static function receive(string $siteId, array $in): array
    {
        $site = Sites::find($siteId);
        $ref = self::text($in['site_ref'] ?? null, 40, 'bad_ref');
        if ($ref === null) {
            throw new OxyHttpError(400, 'bad_ref');
        }
        $code = $siteId . '-' . $ref;

        $existing = oxy_pdo()->prepare('SELECT order_id FROM site_orders WHERE site_id = ? AND site_ref = ?');
        $existing->execute([$siteId, $ref]);
        if ($row = $existing->fetch()) {
            return ['code' => $code, 'order_id' => $row['order_id'], 'duplicate' => true];
        }
        if (!Dispatch::intakeOpen()) {
            throw new OxyHttpError(503, 'intake_paused');
        }

        $customerIn = [
            'name' => self::text($in['name'] ?? null, 120, 'bad_name') ?? 'عميل',
            'phone' => $in['phone'] ?? null,
            'address' => self::text($in['address'] ?? null, 200, 'bad_address'),
            'lat' => $in['lat'] ?? null,
            'lng' => $in['lng'] ?? null,
        ];
        $customerId = self::customerFor($customerIn);

        $service = self::text($in['service'] ?? null, 80, 'bad_service');
        $qty = max(1, min(50, (int) ($in['qty'] ?? 1)));
        $issue = self::text($in['issue'] ?? null, 300, 'bad_issue');
        $when = self::text($in['when'] ?? null, 80, 'bad_when');
        $notes = self::text($in['notes'] ?? null, 300, 'bad_notes');
        $price = is_numeric($in['price'] ?? null) ? (int) $in['price'] : null;
        $source = in_array($in['source'] ?? '', ['chat', 'form'], true) ? (string) $in['source'] : 'form';

        $line = trim(($service ?? 'خدمة') . ($qty > 1 ? " × $qty" : '') . ($issue ? " — $issue" : ''));
        $order = Orders::create(['customer_id' => $customerId, 'service' => mb_substr($line, 0, 80)]);
        if ($price !== null) {
            Orders::update($order['id'], ['price' => $price]);
        }

        $payload = json_encode([
            'service' => $service, 'qty' => $qty, 'issue' => $issue, 'when' => $when,
            'notes' => $notes, 'price' => $price, 'source' => $source, 'site' => $site['name'],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        oxy_pdo()->prepare('INSERT INTO site_orders (id, site_id, site_ref, code, order_id, source, payload, received) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([oxy_id(), $siteId, $ref, $code, $order['id'], $source, $payload, time()]);
        if (Dispatch::mode() === 'auto') {
            // Automatic dispatch: a job nobody can take stays new for the supervisor, and the order is still saved.
            try {
                Orders::distribute($order['id'], Dispatch::rule());
            } catch (OxyHttpError $e) {
                Audit::log('auto_dispatch_skipped', $order['id'], $e->errCode);
            }
        }
        Audit::log('site_order_in', $order['id'], $code);
        return ['code' => $code, 'order_id' => $order['id'], 'duplicate' => false];
    }

    /** The customer is matched by phone. A new phone creates the customer; the location is refreshed when sent. */
    private static function customerFor(array $in): string
    {
        $phone = $in['phone'] ?? null;
        if (is_string($phone) && $phone !== '') {
            $c = Customers::findByPhone($phone);
            if ($c !== null) {
                $patch = [];
                if (($in['lat'] ?? null) !== null && ($in['lng'] ?? null) !== null) {
                    $patch += ['lat' => $in['lat'], 'lng' => $in['lng']];
                }
                if ($patch !== []) {
                    Customers::update($c['id'], $patch);
                }
                return $c['id'];
            }
        }
        $created = Customers::create($in + ['tier' => 'medium']);
        return $created['id'];
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

    /** Verifies a signed request from a paired face. Throws 401 when the signature or time does not match. */
    public static function verifySite(string $siteId, string $method, string $route, string $body, array $headers): void
    {
        $site = Sites::find($siteId);
        if (empty($site['secret_enc'])) {
            throw new OxyHttpError(403, 'unpaired');
        }
        $secret = Vault::open((string) $site['secret_enc']);
        $ok = Hmac::verify(
            $secret, $method, $route,
            (string) ($headers['X-Oxy-Timestamp'] ?? ''), (string) ($headers['X-Oxy-Nonce'] ?? ''),
            $body, (string) ($headers['X-Oxy-Signature'] ?? ''), time()
        );
        if (!$ok) {
            throw new OxyHttpError(401, 'bad_signature');
        }
    }
}
