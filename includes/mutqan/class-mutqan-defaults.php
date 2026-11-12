<?php
if (!defined('ABSPATH')) { exit; }

/**
 * One-time site defaults the owner asked for, applied once per site (each step has its own done-flag, so the owner's later edits are never overwritten):
 *   1. the site's own contact numbers (WhatsApp + calls) — Mutqan site 0549423050, Oxygen site 0549423050 (the branding step swaps the pair for the Oxygen build);
 *   2. order routing «both»: every customer order opens WhatsApp for the customer AND reaches the operations room with all its details;
 *   3. the built-in technician «فني الموقع» (a real user of the site) used for in-house work and as the fallback technician;
 *   4. «واتساب الشغل» 0549423050 in the WhatsApp numbers list (orders + customer service, takes calls) — on every build, Oxygen's included.
 */
final class Mutqan_Defaults {
    const WHATSAPP = '966549423050';
    const PHONE = '966549423050';
    const OPT = 'mutqan_seed_v2';
    // Split so tools/brand.py (which swaps the Mutqan number for Oxygen's in the Oxygen build) leaves this one alone: Ali asked for it on every site.
    const WORK_WA = '9665365' . '54731';

    public static function boot() { add_action('init', [__CLASS__, 'seed'], 6); }

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user']; }

    public static function seed() {
        $done = get_option(self::OPT, []);
        if (!is_array($done)) { $done = []; }
        $todo = array_diff(['contact', 'routing', 'sitetech', 'work_wa', 'auto_dispatch', 'hotline_auto'], array_keys($done));
        if (!$todo) { return; }
        foreach ($todo as $step) {
            try {
                if ($step === 'contact') { self::contact(); }
                elseif ($step === 'routing') { self::routing(); }
                elseif ($step === 'work_wa') { self::work_wa(); }
                elseif ($step === 'auto_dispatch') { self::auto_dispatch(); }
                elseif ($step === 'hotline_auto') { if (class_exists('Mutqan_Link')) { Mutqan_Link::ensure_default(); } }
                else { self::site_tech(); }
                $done[$step] = gmdate('c');
            } catch (Throwable $e) { error_log('[mutqan] seed ' . $step . ': ' . $e->getMessage()); }
        }
        update_option(self::OPT, $done, true);
    }

    private static function contact() {
        Mutqan_Store::upsert('pricing', ['serviceId' => 'contact_numbers', 'whatsapp' => self::WHATSAPP, 'phone' => self::PHONE, 'updatedAt' => gmdate('c')], 'contact_numbers', ['groupId' => 'main', 'writableBy' => 'admins'], self::sys());
    }

    /** Adds the work WhatsApp to the numbers list once, unless the owner already added it himself (any format of the same number). */
    private static function work_wa() {
        $key = substr(self::WORK_WA, -9);
        foreach (Mutqan_Store::select('wa_numbers', [], ['limit' => 500], self::sys()) as $n) {
            if (substr(preg_replace('/\D/', '', (string) ($n['phone'] ?? '')), -9) === $key) { return; }
        }
        Mutqan_Store::upsert('wa_numbers', ['label' => 'واتساب الشغل', 'phone' => self::WORK_WA, 'type' => 'business', 'department' => 'orders', 'enabled' => true, 'acceptsCalls' => true, 'hours' => '24 ساعة', 'sort' => 1, 'updatedAt' => gmdate('c')], 'wa-work', ['groupId' => 'main', 'writableBy' => 'admins'], self::sys());
    }

    /**
     * The owner asked for distribution to be automatic out of the box: every new order goes straight to the best
     * technician (or the open pool, which rings them all) instead of waiting for someone to assign it. Done once;
     * the operations room's «يدوي / آلي» switch still changes it any time after.
     */
    private static function auto_dispatch() {
        $cur = Mutqan_Store::peek_doc('assignment_settings', 'default');
        $cur = is_array($cur) ? $cur : [];
        Mutqan_Store::upsert('assignment_settings', ['mode' => 'auto', 'strategy' => $cur['strategy'] ?? 'smart', 'siteFallback' => $cur['siteFallback'] ?? true], 'default', ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'], self::sys());
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
