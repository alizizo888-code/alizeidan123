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
            'motakhases'  => ['name' => 'المتخصص',       'label' => 'motakhases-maintenance-general-contracting',  'color' => '#1d4ed8'],
            'muhandis'    => ['name' => 'المهندس',       'label' => 'muhandis-maintenance-general-contracting',    'color' => '#0f766e'],
            'aqsa'        => ['name' => 'الأقصى',        'label' => 'aqsa-maintenance-general-contracting',        'color' => '#b45309'],
            'raad'        => ['name' => 'رعد السماء',    'label' => 'raad-maintenance-general-contracting',        'color' => '#6d28d9'],
            'mutamakkin'  => ['name' => 'المتمكن',       'label' => 'mutamakkin-maintenance-general-contracting',  'color' => '#be123c'],
            'khabeer'     => ['name' => 'الخبير',        'label' => 'khabeer-maintenance-general-contracting',     'color' => '#0369a1'],
            'homeservice' => ['name' => 'هوم سيرفيس',    'label' => 'homeservice-maintenance-general-contracting', 'color' => '#15803d'],
            'ibtikar'     => ['name' => 'ابتكار راحة',   'label' => 'ibtikar-maintenance-general-contracting',     'color' => '#c2410c'],
            'mutamad'     => ['name' => 'المعتمد',       'label' => 'mutamad-maintenance-general-contracting',     'color' => '#334155'],
            'misriya'     => ['name' => 'المصرية',       'label' => 'misriya-maintenance-general-contracting',     'color' => '#a21caf'],
            // the second company of each site's pair: its own name, domain, app, logo, articles and admin identity (twin => the site it is paired with)
            'nukhba'      => ['name' => 'النخبة',        'label' => 'nukhba-maintenance-general-contracting',      'color' => '#0e7490', 'twin' => 'motakhases'],
            'riyada'      => ['name' => 'الريادة',       'label' => 'riyada-maintenance-general-contracting',      'color' => '#7c3aed', 'twin' => 'muhandis'],
            'baraa'       => ['name' => 'البراعة',       'label' => 'baraa-maintenance-general-contracting',       'color' => '#ca8a04', 'twin' => 'aqsa'],
            'safwa'       => ['name' => 'الصفوة',        'label' => 'safwa-maintenance-general-contracting',       'color' => '#db2777', 'twin' => 'raad'],
            'tamayuz'     => ['name' => 'التميز',        'label' => 'tamayuz-maintenance-general-contracting',     'color' => '#0d9488', 'twin' => 'mutamakkin'],
            'nasmat'      => ['name' => 'نسمة الحجاز',   'label' => 'nasmat-maintenance-general-contracting',      'color' => '#2563eb', 'twin' => 'khabeer'],
            'wahat'       => ['name' => 'واحة الراحة',   'label' => 'wahat-maintenance-general-contracting',       'color' => '#65a30d', 'twin' => 'homeservice'],
            'amaan'       => ['name' => 'الأمان',        'label' => 'amaan-maintenance-general-contracting',       'color' => '#dc2626', 'twin' => 'ibtikar'],
            'kafaa'       => ['name' => 'الكفاءة',       'label' => 'kafaa-maintenance-general-contracting',       'color' => '#0891b2', 'twin' => 'mutamad'],
            'jawda'       => ['name' => 'الجودة',        'label' => 'jawda-maintenance-general-contracting',       'color' => '#9a3412', 'twin' => 'misriya'],
        ];
        return $r;
    }

    public static function is_satellite() { $id = self::current_id(); return !empty($id) && empty(self::registry()[$id]['core']); }
    /** 0..9 among the satellites (stable: registry order). */
    public static function slot($id = null) { $i = array_search($id ?: self::current_id(), self::satellites(), true); return $i === false ? -1 : (int) $i; }

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
        // The Blogger face of each brand: blog.oxygen11.com (Oxygen) and blog-<label>.oxygen11.com (the other 11).
        if ($h === 'blog.' . self::HUB_HOST) { return 'oxygen'; }
        if (preg_match('/^blog-([a-z0-9]+)\.' . preg_quote(self::HUB_HOST, '/') . '$/', $h, $m)) {
            foreach (self::registry() as $id => $b) { if ($id === $m[1] || ($b['label'] !== '' && $b['label'] === $m[1])) { return $id; } }
        }
        return 'oxygen';
    }

    public static function current_id() {
        $h = function_exists('wp_parse_url') && function_exists('home_url') ? (string) wp_parse_url(home_url('/'), PHP_URL_HOST) : (string) ($_SERVER['HTTP_HOST'] ?? '');
        return self::id_for_host($h);
    }

    /** Until each brand has its own numbers, every satellite uses the company's two lines (set per brand with set_contacts()). */
    const DEFAULT_PHONE = '966549423050';
    const DEFAULT_PHONE2 = '966536554731';

    public static function contacts($id) {
        $o = function_exists('get_option') ? get_option(self::OPT_CONTACTS, []) : [];
        $c = is_array($o) && isset($o[$id]) && is_array($o[$id]) ? $o[$id] : [];
        $digits = function ($v) { return substr(preg_replace('/\D+/', '', (string) $v), 0, 15); };
        $sat = isset(self::registry()[$id]) && empty(self::registry()[$id]['core']);
        $own = $digits($c['phone'] ?? '') !== '';
        $phone = $own ? $digits($c['phone']) : ($sat ? self::DEFAULT_PHONE : '');
        $wa = $digits($c['wa'] ?? '') !== '' ? $digits($c['wa']) : ($own ? $phone : ($sat ? self::DEFAULT_PHONE : ''));
        $wa2 = $digits($c['wa2'] ?? '') !== '' ? $digits($c['wa2']) : ($own ? '' : ($sat ? self::DEFAULT_PHONE2 : ''));
        return ['phone' => $phone, 'wa' => $wa, 'wa2' => $wa2];
    }

    public static function set_contacts($id, $phone, $wa = '', $wa2 = '') {
        if (!self::valid($id)) { return false; }
        $o = get_option(self::OPT_CONTACTS, []); if (!is_array($o)) { $o = []; }
        $o[$id] = ['phone' => substr(preg_replace('/\D+/', '', (string) $phone), 0, 15), 'wa' => substr(preg_replace('/\D+/', '', (string) ($wa !== '' ? $wa : $phone)), 0, 15), 'wa2' => substr(preg_replace('/\D+/', '', (string) $wa2), 0, 15)];
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
            'wa2' => $c['wa2'],
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
