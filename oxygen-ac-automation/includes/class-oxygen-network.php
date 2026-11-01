<?php
if (!defined('ABSPATH')) { exit; }
/**
 * The 12-site network: ONE code base, ONE hub (oxygen11.com holds the orders, staff, WhatsApp and desk),
 * twelve public faces. A face is picked at run time from the host name, so a single plugin build serves all of them
 * and an update reaches every site with the same deploy.
 *
 *  - oxygen / mutqan : the two original sites (their name is already baked in by tools/brand.py)
 *  - the 10 satellites: runtime brand = name + colour + phone, taken from this registry (phone/wa can be overridden in the option
 *    `oxygen_network_contacts` = [id => ['phone'=>..,'wa'=>..]] without a release).
 *
 * Every order carries `brand` (the registry id of the site it came from) so the single desk can filter by brand.
 */
final class Oxygen_Network {
    const SUFFIX = 'للصيانة والمقاولات العامة';
    const HUB_HOST = 'oxygen11.com';
    const OPT_CONTACTS = 'oxygen_network_contacts';

    /** id => [name, sub-domain label, colour, SEO focus: share of A/C + cold rooms, share of Makkah/Jeddah] */
    public static function registry() {
        static $r = null;
        if ($r !== null) { return $r; }
        $r = [
            'oxygen'      => ['name' => 'أكسجين',        'label' => '',            'color' => '#38bdf8', 'core' => true],
            'mutqan'      => ['name' => 'متقن',          'label' => 'mutqan',      'color' => '#12a39a', 'core' => true],
            'motakhases'  => ['name' => 'المتخصص',       'label' => 'motakhases',  'color' => '#1d4ed8'],
            'muhandis'    => ['name' => 'المهندس',       'label' => 'muhandis',    'color' => '#0f766e'],
            'aqsa'        => ['name' => 'الأقصى',        'label' => 'aqsa',        'color' => '#b45309'],
            'raad'        => ['name' => 'رعد السماء',    'label' => 'raad',        'color' => '#6d28d9'],
            'mutamakkin'  => ['name' => 'المتمكن',       'label' => 'mutamakkin',  'color' => '#be123c'],
            'khabeer'     => ['name' => 'الخبير',        'label' => 'khabeer',     'color' => '#0369a1'],
            'homeservice' => ['name' => 'هوم سيرفيس',    'label' => 'homeservice', 'color' => '#15803d'],
            'ibtikar'     => ['name' => 'ابتكار راحة',   'label' => 'ibtikar',     'color' => '#c2410c'],
            'mutamad'     => ['name' => 'المعتمد',       'label' => 'mutamad',     'color' => '#334155'],
            'misriya'     => ['name' => 'المصرية',       'label' => 'misriya',     'color' => '#a21caf'],
        ];
        return $r;
    }

    public static function ids() { return array_keys(self::registry()); }
    public static function satellites() { return array_values(array_filter(self::ids(), function ($id) { return empty(self::registry()[$id]['core']); })); }
    public static function valid($id) { return is_string($id) && isset(self::registry()[$id]); }

    public static function host_of($id) {
        $r = self::registry();
        if (!isset($r[$id])) { return ''; }
        return $id === 'oxygen' ? self::HUB_HOST : $r[$id]['label'] . '.' . self::HUB_HOST;
    }

    /** Which face does this host belong to? Unknown hosts (previews, www, localhost) fall back to the hub brand. */
    public static function id_for_host($host) {
        $h = strtolower(preg_replace('/^www\./', '', (string) $host));
        $h = preg_replace('/:\d+$/', '', $h);
        foreach (self::ids() as $id) { if (self::host_of($id) === $h) { return $id; } }
        return 'oxygen';
    }

    public static function current_id() {
        $h = function_exists('wp_parse_url') && function_exists('home_url') ? (string) wp_parse_url(home_url('/'), PHP_URL_HOST) : (string) ($_SERVER['HTTP_HOST'] ?? '');
        return self::id_for_host($h);
    }

    public static function contacts($id) {
        $o = function_exists('get_option') ? get_option(self::OPT_CONTACTS, []) : [];
        $c = is_array($o) && isset($o[$id]) && is_array($o[$id]) ? $o[$id] : [];
        $digits = function ($v) { return substr(preg_replace('/\D+/', '', (string) $v), 0, 15); };
        return ['phone' => $digits($c['phone'] ?? ''), 'wa' => $digits($c['wa'] ?? ($c['phone'] ?? ''))];
    }

    public static function set_contacts($id, $phone, $wa = '') {
        if (!self::valid($id)) { return false; }
        $o = get_option(self::OPT_CONTACTS, []); if (!is_array($o)) { $o = []; }
        $o[$id] = ['phone' => substr(preg_replace('/\D+/', '', (string) $phone), 0, 15), 'wa' => substr(preg_replace('/\D+/', '', (string) ($wa !== '' ? $wa : $phone)), 0, 15)];
        update_option(self::OPT_CONTACTS, $o, false);
        return true;
    }

    /** What the front end needs: shown name, full legal-style name, colour, own contact numbers (empty = use the hub's). */
    public static function brand_config($id = null) {
        $id = $id ?: self::current_id();
        $r = self::registry();
        if (!isset($r[$id])) { $id = 'oxygen'; }
        $b = $r[$id]; $c = self::contacts($id);
        return [
            'id' => $id,
            'name' => $b['name'],
            'full' => $b['name'] . ' ' . self::SUFFIX,
            'color' => $b['color'],
            'core' => !empty($b['core']),
            'phone' => $c['phone'],
            'wa' => $c['wa'],
        ];
    }

    /** Satellites talk to the hub's REST API; the two original sites keep their own. */
    public static function api_base($id = null) {
        $id = $id ?: self::current_id();
        $r = self::registry();
        if (!isset($r[$id]) || !empty($r[$id]['core'])) { return ''; }
        return 'https://' . self::HUB_HOST . '/wp-json/mutqan/v1';
    }

    /** Brand id to stamp on a new order: only registry ids are accepted, anything else becomes the hub brand. */
    public static function clean_brand($v) { return self::valid($v) ? $v : 'oxygen'; }
}
