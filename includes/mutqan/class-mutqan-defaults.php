<?php
if (!defined('ABSPATH')) { exit; }

/**
 * One-time site defaults the owner asked for, applied once per site (each step has its own done-flag, so the owner's later edits are never overwritten):
 *   1. the site's own contact numbers (WhatsApp + calls) — Mutqan site 0536554731, Oxygen site 0549423050 (the branding step swaps the pair for the Oxygen build);
 *   2. order routing «both»: every customer order opens WhatsApp for the customer AND reaches the operations room with all its details;
 *   3. the built-in technician «فني الموقع» (a real user of the site) used for in-house work and as the fallback technician.
 */
final class Mutqan_Defaults {
    const WHATSAPP = '966536554731';
    const PHONE = '966536554731';
    const OPT = 'mutqan_seed_v2';

    public static function boot() { add_action('init', [__CLASS__, 'seed'], 6); }

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user']; }

    public static function seed() {
        $done = get_option(self::OPT, []);
        if (!is_array($done)) { $done = []; }
        $todo = array_diff(['contact', 'routing', 'sitetech'], array_keys($done));
        if (!$todo) { return; }
        foreach ($todo as $step) {
            try {
                if ($step === 'contact') { self::contact(); }
                elseif ($step === 'routing') { self::routing(); }
                else { self::site_tech(); }
                $done[$step] = gmdate('c');
            } catch (Throwable $e) { error_log('[mutqan] seed ' . $step . ': ' . $e->getMessage()); }
        }
        update_option(self::OPT, $done, true);
    }

    private static function contact() {
        Mutqan_Store::upsert('pricing', ['serviceId' => 'contact_numbers', 'whatsapp' => self::WHATSAPP, 'phone' => self::PHONE, 'updatedAt' => gmdate('c')], 'contact_numbers', ['groupId' => 'main', 'writableBy' => 'admins'], self::sys());
    }

    private static function routing() {
        $cur = Mutqan_Store::peek_doc('pricing', 'order_routing');
        $keep = is_array($cur) ? ['opsCreate' => !empty($cur['opsCreate'])] : ['opsCreate' => false];
        Mutqan_Store::upsert('pricing', ['serviceId' => 'order_routing', 'mode' => 'both'] + $keep + ['updatedAt' => gmdate('c')], 'order_routing', ['groupId' => 'main', 'writableBy' => 'admins'], self::sys());
    }

    private static function site_tech() {
        $u = Mutqan_Auth::ensure_site_tech();
        $p = ['id' => $u['id'], 'role' => 'technician', 'kind' => 'user', 'powers' => [], 'name' => 'فني الموقع'];
        $profile = ['userId' => $u['id'], 'displayName' => 'فني الموقع', 'dailyCapacity' => 50, 'scope' => 'wash_maintenance_install', 'active' => true, 'specialties' => [], 'level' => 'expert', 'isSite' => true, 'homeLat' => null, 'homeLng' => null];
        if (Mutqan_Store::peek_doc('tech_profiles', $u['id'])) { Mutqan_Store::update('tech_profiles', $u['id'], ['displayName' => 'فني الموقع', 'isSite' => true, 'active' => true], self::sys()); }
        else { Mutqan_Store::insert('tech_profiles', $profile, $u['id'], ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'creator-and-admin'], $p); }
        $cur = Mutqan_Store::peek_doc('assignment_settings', 'default');
        $cur = is_array($cur) ? $cur : [];
        $next = ['mode' => $cur['mode'] ?? 'manual', 'strategy' => $cur['strategy'] ?? 'smart', 'siteFallback' => $cur['siteFallback'] ?? true];
        if (empty($cur['siteTechId'])) { $next['siteTechId'] = $u['id']; }
        Mutqan_Store::upsert('assignment_settings', $next, 'default', ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'], self::sys());
    }
}
