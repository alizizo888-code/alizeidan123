<?php
if (!defined('ABSPATH')) { exit; }

/**
 * The system watching itself (audit 2026-11-20): things that fail silently get a voice.
 *
 *  1. Backups: UpdraftPlus was installed with no schedule at all. When its intervals are set, make sure the
 *     matching WordPress jobs exist (its own settings page is the only other place that creates them).
 *  2. Every 30 minutes, on the hub site only, the owner and operations are told — once per problem, not every tick — when:
 *       - an order has waited more than 30 minutes with no real technician (new / forced / site placeholder),
 *       - the WhatsApp inbox has been silent for 24 hours (the gateway is probably logged out).
 * Nothing here changes an order; it only raises the alarm.
 */
final class Oxygen_Health {
    const HOOK = 'oxygen_health_tick';
    const SEEN = 'oxygen_health_seen';

    public static function init() {
        add_filter('cron_schedules', function ($s) { $s['oxygen_30min'] = ['interval' => 1800, 'display' => 'Every 30 minutes']; return $s; });
        add_action('init', [__CLASS__, 'schedule'], 20);
        add_action(self::HOOK, [__CLASS__, 'tick']);
    }

    public static function schedule() {
        if (!wp_next_scheduled(self::HOOK)) { wp_schedule_event(time() + 300, 'oxygen_30min', self::HOOK); }
        self::backups();
    }

    /** UpdraftPlus: database daily, files weekly, at night Makkah time (00:30 UTC = 03:30 Makkah). */
    private static function backups() {
        if (!class_exists('UpdraftPlus')) { return; }
        $map = ['updraft_backup_database' => 'updraft_interval_database', 'updraft_backup' => 'updraft_interval'];
        foreach ($map as $hook => $opt) {
            $iv = (string) get_option($opt, '');
            if ($iv === '' || $iv === 'manual' || wp_next_scheduled($hook)) { continue; }
            $sched = wp_get_schedules();
            if (!isset($sched[$iv])) { continue; }
            $t = strtotime('tomorrow 00:30 UTC') + ($hook === 'updraft_backup' ? 1800 : 0);
            wp_schedule_event($t, $iv, $hook);
        }
    }

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user']; }

    /** One alarm per problem key; a key is forgotten after 12 hours so a problem that lasts is said again twice a day. */
    private static function once($key) {
        $seen = get_option(self::SEEN, []);
        if (!is_array($seen)) { $seen = []; }
        $now = time();
        foreach ($seen as $k => $t) { if ($now - (int) $t > 12 * 3600) { unset($seen[$k]); } }
        if (isset($seen[$key])) { update_option(self::SEEN, $seen, false); return false; }
        $seen[$key] = $now;
        update_option(self::SEEN, $seen, false);
        return true;
    }

    private static function tell($title, $body, $url, $event) {
        foreach (['owner', 'operations'] as $role) {
            Mutqan_Store::insert('_push', ['target' => ['role' => $role], 'title' => $title, 'body' => $body, 'url' => $url, 'sound' => 'bell', 'priority' => 'high', 'event' => $event, 'from' => 'system'], null, ['expiresAt' => gmdate('c', time() + 2 * 86400)], self::sys());
        }
    }

    public static function tick() {
        if (!class_exists('Mutqan_Store')) { return; }
        try { self::orders(); } catch (Throwable $e) { error_log('[oxygen-health] orders ' . $e->getMessage()); }
        try { self::whatsapp(); } catch (Throwable $e) { error_log('[oxygen-health] wa ' . $e->getMessage()); }
    }

    private static function site_ids() {
        static $ids = null;
        if ($ids === null) {
            $ids = [];
            foreach (Mutqan_Store::select('tech_profiles', [], ['limit' => 300], self::sys()) as $p) { if (!empty($p['isSite'])) { $ids[] = (string) ($p['userId'] ?? $p['id'] ?? ''); } }
        }
        return $ids;
    }

    private static function age_min($o) {
        $ts = strtotime((string) ($o['createdAt'] ?? ''));
        return $ts ? (time() - $ts) / 60 : 0;
    }

    private static function orders() {
        $rows = Mutqan_Store::select('orders', [], ['limit' => 200, 'order' => '-createdAt'], self::sys());
        foreach ($rows as $o) {
            $id = (string) ($o['id'] ?? '');
            $st = (string) ($o['status'] ?? '');
            if ($id === '' || in_array($st, ['completed', 'closed', 'cancelled', 'done'], true)) { continue; }
            $age = self::age_min($o);
            if ($age > 7 * 1440) { continue; }   // a week-old open order is a bookkeeping matter, not an alarm
            $short = '#' . substr($id, -5);
            // «no technician» = nobody real holds it: empty, or the site placeholder. A stale dispatch='site' on an order
            // that was later handed to a person must not ring (first live run, 2026-11-20).
            $tid = (string) ($o['technicianId'] ?? '');
            $noTech = $tid === '' || in_array($tid, self::site_ids(), true);
            if ($noTech && $age >= 30 && empty($o['inhouse']) && self::once('wait:' . $id)) {
                self::tell('⏳ طلب منتظر بدون فني ' . $short, 'مرّ عليه ' . (int) $age . ' دقيقة ولم يستلمه فني حقيقي — أسنده الآن.', '#/staff/order/' . $id, 'order_waiting');
            }
        }
    }

    private static function whatsapp() {
        global $wpdb;
        // Since 2026-11-16 WhatsApp runs through the VPS hub, which keeps the messages itself: wa_msgs going quiet is
        // then normal, not an outage (first live run raised a false «83 hours» alarm). Only the on-site gateway is judged here.
        if (class_exists('Mutqan_Hub') && Mutqan_Hub::on()) { return; }
        $t = Mutqan_Store::table('docs');
        $last = $wpdb->get_var($wpdb->prepare("SELECT MAX(created_at) FROM $t WHERE coll=%s", 'wa_msgs'));
        if (!$last) { return; }
        $hours = (time() - strtotime($last . ' UTC')) / 3600;
        if ($hours >= 24 && self::once('wa-silent')) {
            self::tell('📴 واتساب صامت منذ ' . (int) $hours . ' ساعة', 'لم تصل أي رسالة واتساب — غالبًا البوابة غير مسجّلة الدخول. افتح لوحة الواتساب وامسح رمز QR.', '#/staff/whatsapp', 'wa_silent');
        }
    }
}
Oxygen_Health::init();
