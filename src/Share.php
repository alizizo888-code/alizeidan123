<?php
declare(strict_types=1);

/**
 * Sending a job, or a place, to somebody's WhatsApp.
 *
 * The desk has no WhatsApp gateway and does not need one. A wa.me link opens WhatsApp with the
 * message already written, on whatever phone the person is holding, and it is sent by a human
 * pressing send — which is also why it never needs an approved template, a business number, or a
 * provider that can suspend the account on a Friday night.
 *
 * Nothing here sends anything by itself. It builds the link and the words; a person sends them.
 */
final class Share
{
    /** WhatsApp wants digits only, with the country code and no plus. */
    public static function number(string $phone): string
    {
        $d = preg_replace('/\D/', '', $phone) ?? '';
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (preg_match('/^05\d{8}$/', $d)) {
            return '966' . substr($d, 1);
        }
        if (preg_match('/^5\d{8}$/', $d)) {
            return '966' . $d;
        }
        return $d;
    }

    /** A map link anyone can open, whatever phone they hold. */
    public static function mapLink(?float $lat, ?float $lng): ?string
    {
        if ($lat === null || $lng === null) {
            return null;
        }
        return 'https://maps.google.com/?q=' . $lat . ',' . $lng;
    }

    /**
     * The job as a technician needs to read it on WhatsApp: what, for whom, where, and how much.
     * Short on purpose — a wall of text on a phone gets skimmed and the address gets missed.
     */
    public static function jobText(array $order): string
    {
        $lines = ['🔧 ' . ($order['service'] ?? 'طلب')];
        if (!empty($order['customer_name'])) {
            $lines[] = '👤 ' . $order['customer_name'];
        }
        if (!empty($order['customer_phone'])) {
            $lines[] = '📞 ' . $order['customer_phone'];
        }
        if (!empty($order['address'])) {
            $lines[] = '📍 ' . $order['address'];
        }
        $map = self::mapLink($order['lat'] ?? null, $order['lng'] ?? null);
        if ($map !== null) {
            $lines[] = '🗺 ' . $map;
        }
        if (($order['price'] ?? null) !== null) {
            $lines[] = '💵 ' . $order['price'] . ' ر.س';
        }
        if (($order['technician_amount'] ?? null) !== null) {
            $lines[] = 'نصيبك: ' . $order['technician_amount'] . ' ر.س';
        }
        return implode("\n", $lines);
    }

    /**
     * Everything needed to hand one job to one person on WhatsApp.
     * @return array{phone:string,text:string,link:string}
     */
    public static function job(string $orderId, string $toPhone): array
    {
        $order = Orders::find($orderId);
        $phone = self::number($toPhone);
        if (!preg_match('/^\d{9,15}$/', $phone)) {
            throw new OxyHttpError(400, 'bad_phone');
        }
        $text = self::jobText($order);
        return ['phone' => $phone, 'text' => $text, 'link' => self::link($phone, $text)];
    }

    /** The job sent to the technician it belongs to. */
    public static function jobToTechnician(string $orderId): array
    {
        $order = Orders::find($orderId);
        if (($order['technician_id'] ?? null) === null) {
            throw new OxyHttpError(409, 'no_technician');
        }
        $tech = People::find((string) $order['technician_id']);
        if (trim((string) ($tech['phone'] ?? '')) === '') {
            throw new OxyHttpError(409, 'technician_has_no_phone');
        }
        return self::job($orderId, (string) $tech['phone']) + ['to' => $tech['name']];
    }

    /** Where the technician is now, sent to the customer so they can see for themselves. */
    public static function technicianPlace(string $orderId): array
    {
        $order = Orders::find($orderId);
        $loc = Locations::of((string) ($order['technician_id'] ?? ''));
        if ($loc === null) {
            throw new OxyHttpError(409, 'no_location');
        }
        $phone = self::number((string) ($order['customer_phone'] ?? ''));
        if (!preg_match('/^\d{9,15}$/', $phone)) {
            throw new OxyHttpError(409, 'customer_has_no_phone');
        }
        $text = "الفني في الطريق لك.\n🗺 " . self::mapLink($loc['lat'], $loc['lng']);
        return ['phone' => $phone, 'text' => $text, 'link' => self::link($phone, $text)];
    }

    private static function link(string $phone, string $text): string
    {
        return 'https://wa.me/' . $phone . '?text=' . rawurlencode($text);
    }
}
