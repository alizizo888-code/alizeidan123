<?php
/**
 * The face's own configuration, section by section.
 *
 * The settings screens on the site cover two dozen subjects — pricing, warranties, the bot, payment
 * methods, and so on. Giving each one a hand-written field list here would mean a plugin release on
 * every face each time a single field is added, and that is the trap the earlier attempts fell into.
 *
 * So a section is stored as one JSON document, and this file guards its shape rather than its meaning:
 * the section must be a known one, the document must be small, shallow, and free of markup. What the
 * fields mean is the desk's business, which is where they are edited and where the forms live.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Bytes one section's document may take. Generous for a settings form, far below an options row's limit. */
define('MUTQAN_CONFIG_MAX_BYTES', 65536);

/** How deeply a document may nest. Settings are flat or nearly so; anything deeper is a mistake. */
define('MUTQAN_CONFIG_MAX_DEPTH', 6);

/** Keys one section may hold, at any level. */
define('MUTQAN_CONFIG_MAX_KEYS', 400);

/**
 * The sections, exactly as the site's own side menu lists them. Keep in step with Config::SECTIONS
 * on the desk; a test there fails if the two drift apart.
 */
function mutqan_config_sections()
{
    return array(
        'admin'           => 'الإدارة الشاملة',
        'reports'         => 'التقارير',
        'pricing'         => 'التسعير والخصومات',
        'ac_services'     => 'خدمات المكيفات',
        'used_store'      => 'متجر المستعمل',
        'schedule'        => 'نظام المواعيد',
        'pay_links'       => 'روابط الدفع',
        'assistant'       => 'مساعد التحكم',
        'ai_command'      => 'مركز قيادة الذكاء',
        'ai_settings'     => 'إعدادات البوت',
        'ai_owner'        => 'تحكم المالك بالبوت',
        'hotline'         => 'ربط الخط الساخن وتيليجرام',
        'wp_links'        => 'روابط ووردبريس',
        'whatsapp'        => 'أرقام الواتساب',
        'notify'          => 'الإشعارات والرنين',
        'warranties'      => 'الضمانات',
        'tech_requests'   => 'طلبات الفنيين',
        'accounts'        => 'الحسابات والعمولات والعهد',
        'inventory'       => 'المخزون وعهدة الفنيين',
        'payment_methods' => 'طرق الدفع',
        'contact'         => 'أرقام الموقع: الهاتف والواتساب',
        'business'        => 'إعدادات المنشأة والفاتورة',
        'security'        => 'الأمان والدخول',
        'theme'           => 'قوالب المظهر',
        'order_routing'   => 'توجيه الطلبات',
        'assignment'      => 'أسلوب التوزيع',
    );
}

function mutqan_config_option($section)
{
    return 'mutqan_cfg_' . $section;
}

/** One section's document, or an empty one if it was never written. */
function mutqan_config_get($section)
{
    if (!isset(mutqan_config_sections()[$section])) {
        return null;
    }
    $raw = get_option(mutqan_config_option($section), null);
    return is_array($raw) ? $raw : array();
}

/** Every section at once, for the desk's first read after pairing. */
function mutqan_config_all()
{
    $out = array();
    foreach (array_keys(mutqan_config_sections()) as $section) {
        $out[$section] = mutqan_config_get($section);
    }
    return $out;
}

/**
 * Checks a section document's shape. Returns the document, or a WP_Error naming what was wrong.
 * Nothing here judges what a field means: that belongs to the desk, which owns the forms.
 */
function mutqan_config_check($document)
{
    if (!is_array($document)) {
        return new WP_Error('mutqan_bad_config', 'a document was expected', array('status' => 400));
    }
    $encoded = wp_json_encode($document, JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        return new WP_Error('mutqan_bad_config', 'the document could not be encoded', array('status' => 400));
    }
    if (strlen($encoded) > MUTQAN_CONFIG_MAX_BYTES) {
        return new WP_Error('mutqan_config_too_big', 'the document is too large', array('status' => 400));
    }
    $count = 0;
    $problem = mutqan_config_walk($document, 1, $count);
    if ($problem !== null) {
        return new WP_Error('mutqan_bad_config', $problem, array('status' => 400));
    }
    if ($count > MUTQAN_CONFIG_MAX_KEYS) {
        return new WP_Error('mutqan_config_too_big', 'too many keys', array('status' => 400));
    }
    return $document;
}

/** Walks the document, counting keys and refusing anything that does not belong in settings. */
function mutqan_config_walk($value, $depth, &$count)
{
    if ($depth > MUTQAN_CONFIG_MAX_DEPTH) {
        return 'the document nests too deeply';
    }
    if (!is_array($value)) {
        return mutqan_config_leaf($value);
    }
    foreach ($value as $key => $item) {
        $count = (int) $count + 1;
        if (is_string($key) && !preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $key)) {
            return 'a key is not allowed: ' . substr($key, 0, 40);
        }
        $problem = mutqan_config_walk($item, $depth + 1, $count);
        if ($problem !== null) {
            return $problem;
        }
    }
    return null;
}

/**
 * A single value. Text carries no markup, because some of it ends up on the face in front of a customer.
 *
 * Naming a handful of tags is not enough: <img src=x onerror=…> carries no listed tag and runs anyway.
 * So anything shaped like a tag is refused, which still leaves ordinary text alone — "السعر < 100" has
 * a digit after the bracket, not a tag name, so it passes.
 */
function mutqan_config_leaf($value)
{
    if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
        return null;
    }
    if (!is_string($value)) {
        return 'a value has a type that cannot be stored';
    }
    if (mutqan_len($value) > 4000) {
        return 'a value is too long';
    }
    if (preg_match('/<\s*\/?[a-z][a-z0-9]*[\s\/>]/i', $value) || stripos($value, 'javascript:') !== false) {
        return 'a value contains markup';
    }
    return null;
}

/** Writes one section whole. A section is replaced, never merged: the desk holds the whole form. */
function mutqan_config_save($section, array $document)
{
    update_option(mutqan_config_option($section), $document, false);
}

function mutqan_rest_config_read(WP_REST_Request $request)
{
    mutqan_fresh();
    $section = (string) $request->get_param('section');
    if ($section === '') {
        return array('ok' => true, 'sections' => mutqan_config_sections(), 'config' => mutqan_config_all());
    }
    $document = mutqan_config_get($section);
    if ($document === null) {
        return new WP_Error('mutqan_unknown_section', 'no such section: ' . $section, array('status' => 404));
    }
    return array('ok' => true, 'section' => $section, 'config' => $document);
}

function mutqan_rest_config_write(WP_REST_Request $request)
{
    $section = (string) $request->get_param('section');
    if (!isset(mutqan_config_sections()[$section])) {
        return new WP_Error('mutqan_unknown_section', 'no such section: ' . $section, array('status' => 404));
    }
    $body = json_decode((string) $request->get_body(), true);
    if (!is_array($body) || !array_key_exists('config', $body)) {
        return new WP_Error('mutqan_bad_request', 'no document was sent', array('status' => 400));
    }
    $checked = mutqan_config_check($body['config']);
    if (is_wp_error($checked)) {
        return $checked;
    }
    $before = mutqan_config_get($section);
    mutqan_config_save($section, $checked);
    mutqan_fresh();
    return array(
        'ok'      => true,
        'section' => $section,
        'changed' => mutqan_config_get($section) !== $before,
        'config'  => mutqan_config_get($section),
    );
}
