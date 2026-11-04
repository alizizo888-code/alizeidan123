<?php
declare(strict_types=1);

/**
 * Chat and WhatsApp settings, copied from the site's chat page: the business details the assistant uses,
 * its instructions, the services it offers, the payment methods it names, and whether it is switched on.
 *
 * Only plain, non-secret values live here. WhatsApp tokens and Phone Number IDs are never stored in the desk's
 * settings through this screen; they stay with the sites that send messages.
 */
final class Chat
{
    /** key => maximum length in characters (single-line fields) */
    public const FIELDS = [
        'company_name' => 120,
        'bot_name' => 40,
        'phone' => 20,
        'whatsapp' => 20,
        'email' => 120,
        'city' => 120,
        'banner_text' => 200,
    ];
    public const MAX_INSTRUCTIONS = 4000;
    public const MAX_SERVICES = 60;
    private const PREFIX = 'chat.';

    /** @return array<string,mixed> */
    public static function all(): array
    {
        $out = ['enabled' => Settings::get(self::PREFIX . 'enabled') === '1'];
        foreach (array_keys(self::FIELDS) as $key) {
            $out[$key] = Settings::get(self::PREFIX . $key) ?? '';
        }
        $out['instructions'] = Settings::get(self::PREFIX . 'instructions') ?? '';
        $out['services'] = self::lines(Settings::get(self::PREFIX . 'services') ?? '');
        $out['payment_methods'] = self::lines(Settings::get(self::PREFIX . 'payment_methods') ?? '');
        return $out;
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public static function save(array $in): array
    {
        $allowed = array_merge(array_keys(self::FIELDS), ['enabled', 'instructions', 'services', 'payment_methods']);
        if (array_diff(array_map('strval', array_keys($in)), $allowed) !== []) {
            throw new OxyHttpError(400, 'unknown_setting');
        }
        $clean = [];
        foreach (self::FIELDS as $key => $max) {
            if (array_key_exists($key, $in)) {
                $clean[$key] = self::text($in[$key], $max);
            }
        }
        if (array_key_exists('email', $in) && $clean['email'] !== '' && filter_var($clean['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new OxyHttpError(400, 'bad_email');
        }
        foreach (['phone', 'whatsapp'] as $key) {
            if (isset($clean[$key]) && $clean[$key] !== '' && preg_match('/^\+?[0-9]{9,15}$/', $clean[$key]) !== 1) {
                throw new OxyHttpError(400, 'bad_phone');
            }
        }
        if (array_key_exists('enabled', $in)) {
            if (!is_bool($in['enabled'])) {
                throw new OxyHttpError(400, 'bad_value');
            }
            $clean['enabled'] = $in['enabled'] ? '1' : '0';
        }
        if (array_key_exists('instructions', $in)) {
            $clean['instructions'] = self::block($in['instructions'], self::MAX_INSTRUCTIONS);
        }
        if (array_key_exists('services', $in)) {
            $clean['services'] = self::listText($in['services'], self::MAX_SERVICES, 80);
        }
        if (array_key_exists('payment_methods', $in)) {
            $clean['payment_methods'] = self::listText($in['payment_methods'], 10, 60);
        }
        foreach ($clean as $key => $value) {
            Settings::set(self::PREFIX . $key, (string) $value);
        }
        if ($clean !== []) {
            Audit::log('chat_settings', 'chat', implode(',', array_keys($clean)));
        }
        return self::all();
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $text)), static fn (string $l): bool => $l !== ''));
    }

    private static function text(mixed $value, int $max): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw new OxyHttpError(400, 'bad_value');
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $value));
        if (mb_strlen($text) > $max) {
            throw new OxyHttpError(400, 'too_long');
        }
        if (preg_match('/[<>]/', $text) === 1) {
            throw new OxyHttpError(400, 'bad_value');
        }
        return $text;
    }

    /** Long text keeps its line breaks; markup is refused the same way as single-line fields. */
    private static function block(mixed $value, int $max): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw new OxyHttpError(400, 'bad_value');
        }
        $text = trim(str_replace("\r\n", "\n", $value));
        if (mb_strlen($text) > $max) {
            throw new OxyHttpError(400, 'too_long');
        }
        if (preg_match('/[<>]/', $text) === 1) {
            throw new OxyHttpError(400, 'bad_value');
        }
        return $text;
    }

    /** A list of lines, one item per line; each item is trimmed and limited. */
    private static function listText(mixed $value, int $maxItems, int $maxLen): string
    {
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            $value = implode("\n", array_map('strval', $value));
        }
        if (!is_string($value)) {
            throw new OxyHttpError(400, 'bad_value');
        }
        $items = self::lines(str_replace("\r\n", "\n", $value));
        if (count($items) > $maxItems) {
            throw new OxyHttpError(400, 'too_many');
        }
        foreach ($items as $item) {
            if (mb_strlen($item) > $maxLen) {
                throw new OxyHttpError(400, 'too_long');
            }
            if (preg_match('/[<>]/', $item) === 1) {
                throw new OxyHttpError(400, 'bad_value');
            }
        }
        return implode("\n", $items);
    }
}
