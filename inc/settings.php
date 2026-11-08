<?php
/**
 * Everything the desk may read or change on this face, and nothing else.
 *
 * A key missing from this list is refused, so a mistake on the desk can never write a stray option.
 * Core WordPress keys are stored by WordPress itself; the rest are this plugin's own options.
 *
 * To add a setting: add it here AND to FIELDS in src/Bulk.php (or Chat.php) on the desk.
 */

if (!defined('ABSPATH')) {
    exit;
}

function mutqan_fields()
{
    return array(
        // WordPress core, kept in step with Bulk::FIELDS on the desk.
        'blogname'        => array('type' => 'text', 'min' => 1, 'max' => 200, 'core' => true),
        'blogdescription' => array('type' => 'text', 'min' => 0, 'max' => 200, 'core' => true),
        'posts_per_page'  => array('type' => 'int',  'min' => 1, 'max' => 50,  'core' => true),

        // The business, as the face shows it. Kept in step with Chat::FIELDS on the desk.
        'company_name'    => array('type' => 'text', 'min' => 0, 'max' => 120),
        'bot_name'        => array('type' => 'text', 'min' => 0, 'max' => 40),
        'phone'           => array('type' => 'phone', 'max' => 20),
        'whatsapp'        => array('type' => 'phone', 'max' => 20),
        'email'           => array('type' => 'email', 'max' => 120),
        'city'            => array('type' => 'text', 'min' => 0, 'max' => 120),
        'banner_text'     => array('type' => 'text', 'min' => 0, 'max' => 200),

        // The order form and the chat.
        'services'        => array('type' => 'list', 'max_items' => 60, 'max' => 80),
        'chat_enabled'    => array('type' => 'bool'),
        'intake_enabled'  => array('type' => 'bool'),
    );
}

/** Core keys live in WordPress options under their own name; ours are namespaced. */
function mutqan_option_name($key)
{
    $fields = mutqan_fields();
    return !empty($fields[$key]['core']) ? $key : 'mutqan_' . $key;
}

function mutqan_get($key)
{
    $fields = mutqan_fields();
    if (!isset($fields[$key])) {
        return null;
    }
    $raw = get_option(mutqan_option_name($key), null);
    switch ($fields[$key]['type']) {
        case 'int':
            return (int) $raw;
        case 'bool':
            return $raw === '1' || $raw === 1 || $raw === true;
        case 'list':
            $list = is_array($raw) ? $raw : array();
            return array_values(array_map('strval', $list));
        default:
            return (string) $raw;
    }
}

/** Every allowed value as it stands right now. */
function mutqan_all()
{
    $out = array();
    foreach (array_keys(mutqan_fields()) as $key) {
        $out[$key] = mutqan_get($key);
    }
    return $out;
}

/**
 * Checks the whole input before a single value is written: an unknown key, a wrong type, a value out of
 * range, or markup refuses the entire request. Returns the clean values or a WP_Error.
 */
function mutqan_validate(array $input)
{
    $fields = mutqan_fields();
    $clean = array();
    foreach ($input as $key => $value) {
        $key = (string) $key;
        if (!isset($fields[$key])) {
            return new WP_Error('mutqan_unknown_setting', 'setting not allowed: ' . $key, array('status' => 400));
        }
        $rule = $fields[$key];
        $bad = function ($why) use ($key) {
            return new WP_Error('mutqan_bad_value', $why . ' for ' . $key, array('status' => 400));
        };

        if ($rule['type'] === 'int') {
            if (is_string($value) && preg_match('/^\d+$/', $value)) {
                $value = (int) $value;
            }
            if (!is_int($value) || $value < $rule['min'] || $value > $rule['max']) {
                return $bad('value out of range');
            }
            $clean[$key] = $value;
            continue;
        }

        if ($rule['type'] === 'bool') {
            if (!is_bool($value)) {
                return $bad('true or false expected');
            }
            $clean[$key] = $value;
            continue;
        }

        if ($rule['type'] === 'list') {
            if (!is_array($value)) {
                return $bad('a list was expected');
            }
            if (count($value) > $rule['max_items']) {
                return $bad('too many items');
            }
            $items = array();
            foreach ($value as $item) {
                if (!is_string($item)) {
                    return $bad('text items expected');
                }
                $item = trim($item);
                if ($item === '' || mutqan_len($item) > $rule['max'] || preg_match('/[<>]/', $item)) {
                    return $bad('an item was rejected');
                }
                $items[] = $item;
            }
            $clean[$key] = array_values(array_unique($items));
            continue;
        }

        if (!is_string($value)) {
            return $bad('text expected');
        }
        $text = trim($value);

        if ($rule['type'] === 'phone') {
            // Digits with an optional leading +, the shape every gateway accepts.
            if ($text !== '' && !preg_match('/^\+?\d{9,15}$/', $text)) {
                return $bad('phone number rejected');
            }
            $clean[$key] = $text;
            continue;
        }

        if ($rule['type'] === 'email') {
            if ($text !== '' && !is_email($text)) {
                return $bad('email rejected');
            }
            $clean[$key] = $text;
            continue;
        }

        $length = mutqan_len($text);
        if ($length < $rule['min'] || $length > $rule['max'] || preg_match('/[<>]/', $text)) {
            return $bad('text rejected');
        }
        $clean[$key] = $text;
    }
    return $clean;
}

function mutqan_len($text)
{
    return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
}

/** Writes values already checked by mutqan_validate. Booleans are stored as '1' or '0'. */
function mutqan_save(array $clean)
{
    $fields = mutqan_fields();
    foreach ($clean as $key => $value) {
        if ($fields[$key]['type'] === 'bool') {
            $value = $value ? '1' : '0';
        }
        update_option(mutqan_option_name($key), $value, false);
    }
}

/**
 * A reply to the desk must show what is stored now. A page or object cache on the face can keep an old
 * value and make a change the desk just wrote look as if it never happened.
 */
function mutqan_fresh()
{
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    if (!defined('DONOTCACHEOBJECT')) {
        define('DONOTCACHEOBJECT', true);
    }
    if (function_exists('nocache_headers')) {
        nocache_headers();
    }
    if (function_exists('wp_cache_delete')) {
        wp_cache_delete('alloptions', 'options');
        foreach (array_keys(mutqan_fields()) as $key) {
            wp_cache_delete(mutqan_option_name($key), 'options');
        }
    }
}
