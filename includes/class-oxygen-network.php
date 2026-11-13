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
    const OPT_EXTRA = 'oxygen_network_extra';      // sites the owner linked from the app (hub only)
    const TR_REMOTE = 'oxygen_network_remote';     // the hub's list of linked sites, cached on every other site
    private static $reg = null;
    public static function reset() { self::$reg = null; }

    public static function registry() {
        if (self::$reg !== null) { return self::$reg; }
        $r = [
            // latin: the Latin wordmark the two original faces show beside their Arabic name. The other
            // faces have none and show their Arabic name in those places.
            'oxygen'      => ['name' => 'أكسجين',        'label' => '',            'color' => '#38bdf8', 'core' => true, 'latin' => 'OXYGEN'],
            'mutqan'      => ['name' => 'متقن',          'label' => 'mutqan',      'color' => '#12a39a', 'core' => true, 'latin' => 'MUTQAN'],
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
        self::$reg = $r;                                   // the built-in 22 first: extras() may ask current_id() on the way
        foreach (self::extras() as $id => $x) { if (!isset($r[$id])) { $r[$id] = $x + ['label' => '', 'extra' => true]; } }
        self::$reg = $r;
        return $r;
    }

    /* ------------------------------------------------------------------ sites linked later, from the owner's app */

    private static function own_host() {
        $h = function_exists('wp_parse_url') && function_exists('home_url') ? (string) wp_parse_url(home_url('/'), PHP_URL_HOST) : (string) ($_SERVER['HTTP_HOST'] ?? '');
        return strtolower(preg_replace('/^www\./', '', $h));
    }
    public static function is_hub() { return self::own_host() === self::HUB_HOST; }

    /** Linked sites: the hub keeps them; every other site reads the hub's list (cached six hours) so a new site knows who it is. */
    public static function extras() {
        if (!function_exists('get_option')) { return []; }
        if (self::is_hub()) { $o = get_option(self::OPT_EXTRA, []); return is_array($o) ? $o : []; }
        $c = function_exists('get_transient') ? get_transient(self::TR_REMOTE) : false;
        if (is_array($c)) { return $c; }
        $list = [];
        if (function_exists('wp_remote_get')) {
            $res = wp_remote_get('https://' . self::HUB_HOST . '/wp-json/oxygen/v1/net-registry', ['timeout' => 4]);
            $d = !is_wp_error($res) ? json_decode((string) wp_remote_retrieve_body($res), true) : null;
            if (is_array($d) && isset($d['sites']) && is_array($d['sites'])) { foreach ($d['sites'] as $id => $x) { if (self::valid_new_id($id) && is_array($x) && !empty($x['host'])) { $list[$id] = ['name' => (string) ($x['name'] ?? $id), 'host' => (string) $x['host'], 'color' => (string) ($x['color'] ?? '#64748b')]; } } }
        }
        if (function_exists('set_transient')) { set_transient(self::TR_REMOTE, $list, $list ? 21600 : 1800); }
        return $list;
    }
    private static function valid_new_id($id) { return is_string($id) && preg_match('/^[a-z][a-z0-9]{1,23}$/', $id); }
    public static function clean_host($h) {
        $h = strtolower(trim((string) $h));
        $h = preg_replace('#^https?://#', '', $h); $h = preg_replace('#[/?\#].*$#', '', $h); $h = preg_replace('/^www\./', '', $h);
        return preg_match('/^(?=.{4,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $h) ? $h : '';
    }

    /** Owner links a new site (hub only). Returns the new entry. */
    public static function add_extra($name, $host, $color = '') {
        $name = trim(wp_strip_all_tags((string) $name)); $host = self::clean_host($host);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 40) { throw new InvalidArgumentException('اكتب اسم الموقع.'); }
        if ($host === '') { throw new InvalidArgumentException('الدومين غير صحيح — مثال: example.com'); }
        foreach (array_keys(self::registry()) as $id) { if (self::host_of($id) === $host) { throw new InvalidArgumentException('الدومين ده مربوط بالفعل.'); } }
        $base = preg_replace('/[^a-z0-9]/', '', explode('.', $host)[0]); $base = preg_match('/^[a-z]/', $base) ? substr($base, 0, 20) : 's' . substr($base, 0, 19);
        if (strlen($base) < 2) { $base = 'site'; }
        $id = $base; $i = 1; while (isset(self::registry()[$id]) || isset(self::blog_brands()[$id])) { $id = $base . (++$i); }
        $color = preg_match('/^#[0-9a-f]{6}$/i', (string) $color) ? strtolower($color) : '#64748b';
        $o = get_option(self::OPT_EXTRA, []); if (!is_array($o)) { $o = []; }
        $o[$id] = ['name' => $name, 'host' => $host, 'color' => $color, 'at' => gmdate('c')];
        update_option(self::OPT_EXTRA, $o, false); self::reset();
        return ['id' => $id] + $o[$id];
    }
    public static function remove_extra($id) {
        $o = get_option(self::OPT_EXTRA, []); if (!is_array($o) || !isset($o[$id])) { return false; }
        unset($o[$id]); update_option(self::OPT_EXTRA, $o, false); self::reset();
        return true;
    }

    public static function is_satellite() { $id = self::current_id(); return !empty($id) && empty(self::registry()[$id]['core']); }
    /** 0..9 among the satellites (stable: registry order). */
    public static function slot($id = null) { $i = array_search($id ?: self::current_id(), self::satellites(), true); return $i === false ? -1 : (int) $i; }

    /** Blogger-only faces (blog-<id>.oxygen11.com): no WordPress site, but their orders are tagged with their own id. */
    public static function blog_brands() {
        return [
        'nasim' => ['name' => 'نسيم البارد', 'color' => '#0284c7', 'twin' => 'oxygen'],
        'qimma' => ['name' => 'القمة للتبريد', 'color' => '#0e7490', 'twin' => 'mutqan'],
        'baroda' => ['name' => 'برودة', 'color' => '#0369a1', 'twin' => 'motakhases'],
        'taqa' => ['name' => 'الطاقة الآمنة', 'color' => '#ca8a04', 'twin' => 'muhandis'],
        'nabea' => ['name' => 'النبع', 'color' => '#0891b2', 'twin' => 'aqsa'],
        'hisn' => ['name' => 'الحصن', 'color' => '#4d7c0f', 'twin' => 'raad'],
        'safaa' => ['name' => 'صفاء الماء', 'color' => '#2563eb', 'twin' => 'mutamakkin'],
        'muhtarif' => ['name' => 'المحترف', 'color' => '#9333ea', 'twin' => 'khabeer'],
        'baytak' => ['name' => 'بيتك', 'color' => '#16a34a', 'twin' => 'homeservice'],
        'manakh' => ['name' => 'المناخ', 'color' => '#ea580c', 'twin' => 'ibtikar'],
        'amin' => ['name' => 'الأمين', 'color' => '#475569', 'twin' => 'mutamad'],
        'dar' => ['name' => 'الدار', 'color' => '#be185d', 'twin' => 'misriya'],
        'ayn' => ['name' => 'العين الساهرة', 'color' => '#047857', 'twin' => 'nukhba'],
        'shams' => ['name' => 'شمس الحجاز', 'color' => '#d97706', 'twin' => 'riyada'],
        'lamsa' => ['name' => 'لمسة فن', 'color' => '#e11d48', 'twin' => 'baraa'],
        'naqaa' => ['name' => 'النقاء', 'color' => '#0d9488', 'twin' => 'safwa'],
        'itqan' => ['name' => 'الإتقان', 'color' => '#b91c1c', 'twin' => 'tamayuz'],
        'ishara' => ['name' => 'الإشارة', 'color' => '#1e40af', 'twin' => 'nasmat'],
        'rahhal' => ['name' => 'الرحّال', 'color' => '#a16207', 'twin' => 'wahat'],
        'miftah' => ['name' => 'المفتاح الذهبي', 'color' => '#6d28d9', 'twin' => 'amaan'],
        'idara' => ['name' => 'الإدارة الذكية', 'color' => '#1d4ed8', 'twin' => 'kafaa'],
        'bina' => ['name' => 'البناء المتين', 'color' => '#78350f', 'twin' => 'jawda'],
        ];
    }
    /** Registry entry or Blogger-only entry for an id (null when unknown). */
    public static function brand($id) { $r = self::registry(); if (isset($r[$id])) { return $r[$id]; } $b = self::blog_brands(); return $b[$id] ?? null; }

    public static function ids() { return array_keys(self::registry()); }
    public static function satellites() { return array_values(array_filter(self::ids(), function ($id) { return empty(self::registry()[$id]['core']); })); }
    public static function valid($id) { return is_string($id) && (isset(self::registry()[$id]) || isset(self::blog_brands()[$id])); }

    public static function host_of($id) {
        $r = self::registry();
        if (!isset($r[$id])) { return ''; }
        if (!empty($r[$id]['host'])) { return $r[$id]['host']; }
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
            if (isset(self::blog_brands()[$m[1]])) { return $m[1]; }
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
            'latin' => isset($b['latin']) ? $b['latin'] : '',
            'phone' => $c['phone'],
            'wa' => $c['wa'],
            'wa2' => $c['wa2'],
        ];
    }

    /**
     * Every site talks to the hub's REST API, so all 22 (and any site linked later) share ONE operations room,
     * one owner, one team and one customer list. Only the hub itself answers locally.
     * (Mutqan used to keep its own API and database: its orders never reached the operations room.)
     */
    public static function api_base($id = null) {
        $id = $id ?: self::current_id();
        $r = self::registry();
        if (!isset($r[$id]) || $id === 'oxygen') { return ''; }
        return 'https://' . self::HUB_HOST . '/wp-json/mutqan/v1';
    }

    /** Brand id to stamp on a new order: only registry ids are accepted, anything else becomes the hub brand. */
    public static function clean_brand($v) { return self::valid($v) ? $v : 'oxygen'; }
}

// The hub publishes the sites linked from the owner's app (names, domains, colours only), so a newly linked
// site finds out who it is and sends its orders to the shared operations room.
if (function_exists('add_action')) {
    add_action('rest_api_init', function () {
        register_rest_route('oxygen/v1', '/net-registry', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function () {
            $out = [];
            if (Oxygen_Network::is_hub()) { foreach (Oxygen_Network::extras() as $id => $x) { $out[$id] = ['name' => $x['name'], 'host' => $x['host'], 'color' => $x['color']]; } }
            return rest_ensure_response(['sites' => (object) $out]);
        }]);
    });
}
