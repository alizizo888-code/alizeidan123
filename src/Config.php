<?php
declare(strict_types=1);

/**
 * The settings sections a face holds, read and written from the desk over the signed bridge.
 *
 * The site has two dozen settings screens. Writing a field list for each one here would mean a desk
 * release and a plugin release on every face for a single new field, which is how the earlier attempts
 * became impossible to keep working. So a section travels as one document: the desk owns the form and
 * the meaning, the plugin guards the shape, and a new field needs neither side to be released.
 *
 * Keep SECTIONS in step with mutqan_config_sections() in bridge/mutqan/inc/config.php. A test fails
 * if they drift apart.
 */
final class Config
{
    /** key => the label the site's own side menu uses, so the owner sees the same words in both places. */
    public const SECTIONS = [
        'admin' => 'الإدارة الشاملة',
        'reports' => 'التقارير',
        'pricing' => 'التسعير والخصومات',
        'ac_services' => 'خدمات المكيفات',
        'used_store' => 'متجر المستعمل',
        'schedule' => 'نظام المواعيد',
        'pay_links' => 'روابط الدفع',
        'assistant' => 'مساعد التحكم',
        'ai_command' => 'مركز قيادة الذكاء',
        'ai_settings' => 'إعدادات البوت',
        'ai_owner' => 'تحكم المالك بالبوت',
        'hotline' => 'ربط الخط الساخن وتيليجرام',
        'wp_links' => 'روابط ووردبريس',
        'whatsapp' => 'أرقام الواتساب',
        'notify' => 'الإشعارات والرنين',
        'warranties' => 'الضمانات',
        'tech_requests' => 'طلبات الفنيين',
        'accounts' => 'الحسابات والعمولات والعهد',
        'inventory' => 'المخزون وعهدة الفنيين',
        'payment_methods' => 'طرق الدفع',
        'contact' => 'أرقام الموقع: الهاتف والواتساب',
        'business' => 'إعدادات المنشأة والفاتورة',
        'security' => 'الأمان والدخول',
        'theme' => 'قوالب المظهر',
        'order_routing' => 'توجيه الطلبات',
        'assignment' => 'أسلوب التوزيع',
    ];

    /** Must match MUTQAN_CONFIG_MAX_BYTES on the plugin, so the desk refuses before the face has to. */
    public const MAX_BYTES = 65536;
    public const MAX_DEPTH = 6;
    public const MAX_KEYS = 400;

    /** Reads one section from a face, or every section when no section is named. */
    public static function read(string $siteId, ?string $section = null): array
    {
        $site = Sites::find($siteId);
        $route = '/mutqan/v1/config' . ($section === null ? '' : '/' . self::section($section));
        $result = Sites::call($site, 'GET', $route);
        if (!$result['ok']) {
            throw new OxyHttpError(502, self::reason((string) $result['error']));
        }
        return $result['data'];
    }

    /** Replaces one section on a face. The document is checked here first, so a bad one never leaves the desk. */
    public static function write(string $siteId, string $section, array $document): array
    {
        $section = self::section($section);
        self::check($document);
        $site = Sites::find($siteId);
        $result = Sites::call($site, 'POST', '/mutqan/v1/config/' . $section, ['config' => $document]);
        if (!$result['ok']) {
            Audit::log('config_write_failed', $siteId, $section . ':' . substr((string) $result['error'], 0, 160));
            throw new OxyHttpError(502, self::reason((string) $result['error']));
        }
        Audit::log('config_write', $siteId, $section);
        return $result['data'];
    }

    private static function section(string $section): string
    {
        if (!isset(self::SECTIONS[$section])) {
            throw new OxyHttpError(400, 'unknown_section');
        }
        return $section;
    }

    /**
     * The same shape rules the plugin applies, applied here first: the owner gets the reason straight
     * away instead of a 502 relayed from a face.
     */
    public static function check(array $document): void
    {
        $encoded = json_encode($document, JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) > self::MAX_BYTES) {
            throw new OxyHttpError(400, 'config_too_big');
        }
        $keys = 0;
        self::walk($document, 1, $keys);
        if ($keys > self::MAX_KEYS) {
            throw new OxyHttpError(400, 'config_too_big');
        }
    }

    private static function walk(mixed $value, int $depth, int &$keys): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new OxyHttpError(400, 'config_too_deep');
        }
        if (!is_array($value)) {
            self::leaf($value);
            return;
        }
        foreach ($value as $key => $item) {
            $keys++;
            if (is_string($key) && !preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $key)) {
                throw new OxyHttpError(400, 'bad_config_key');
            }
            self::walk($item, $depth + 1, $keys);
        }
    }

    private static function leaf(mixed $value): void
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return;
        }
        if (!is_string($value)) {
            throw new OxyHttpError(400, 'bad_config_value');
        }
        if (mb_strlen($value) > 4000) {
            throw new OxyHttpError(400, 'bad_config_value');
        }
        // Naming a few tags is not enough: <img src=x onerror=…> carries none of them and still runs.
        // Anything shaped like a tag is refused; "السعر < 100" has a digit after the bracket and passes.
        if (preg_match('/<\s*\/?[a-z][a-z0-9]*[\s\/>]/i', $value) || stripos($value, 'javascript:') !== false) {
            throw new OxyHttpError(400, 'bad_config_value');
        }
    }

    /** Turns a bridge failure into a code the panel can show in Arabic. */
    private static function reason(string $error): string
    {
        if ($error === 'unpaired' || str_contains($error, 'unpaired')) {
            return 'face_unpaired';
        }
        if (str_contains($error, 'unknown_section')) {
            return 'unknown_section';
        }
        if (str_starts_with($error, 'network') || str_starts_with($error, 'non_json')) {
            return 'face_unreachable';
        }
        if (str_contains($error, '404')) {
            // The face is answering, but with the old plugin that has no config routes.
            return 'face_plugin_old';
        }
        return 'face_refused';
    }
}
