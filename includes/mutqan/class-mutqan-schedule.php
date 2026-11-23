<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «جدول المهام» — orders booked for a later day (owner, 2026-11-23).
 *
 * An order for tomorrow, after tomorrow or a named weekday is not sent to a technician the moment it comes in: a
 * technician holding a job three days ahead blocks his day and the job gets lost. Instead:
 *  - it is filed (`scheduled` = {day, at, released:false}) and operations hear «📅 طلب محجوز ليوم …»;
 *  - the evening before (from 9 pm, Makkah) operations and the owner get the list of tomorrow's orders, once;
 *  - on the day, from 7 am (the start of the working day), each one is released: operations hear it and, in «آلي» mode,
 *    it is dispatched to a technician then — so a technician always has it hours before the appointment (at least 1½ h);
 *  - every step is written in the schedule's log, shown on the «جدول المهام» page.
 * Maintenance contracts (Mutqan_Growth) create their visits as orders for their day, so they follow the same road.
 */
final class Mutqan_Schedule {

    const LOG = 'mutqan_sched_log';
    const START_HOUR = 7;
    const EVE_HOUR = 21;

    private static function sys() { return ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']]; }

    public static function boot() { add_action('mutqan_remind', [__CLASS__, 'tick'], 12); }

    private static function now($ts = null) {
        $d = new DateTime('@' . ($ts ?? time()));
        $d->setTimezone(new DateTimeZone('Asia/Riyadh'));
        return $d;
    }

    /** The day (Y-m-d, Makkah) the order is for: its appointment, else what he wrote («بكره العصر», «الخميس»). Null = no day said. */
    public static function day_of($o, $ts = null) {
        $now = self::now($ts);
        if (!empty($o['appointmentAt']) && ($t = strtotime((string) $o['appointmentAt']))) { return self::now($t)->format('Y-m-d'); }
        $t = (string) ($o['preferredTime'] ?? '');
        if ($t === '') { return null; }
        $add = null;
        if (preg_match('/بعد\s*(بكر[ةها]|بكرا|غد)/u', $t)) { $add = 2; }
        elseif (preg_match('/بكر[ةها]|بكرا|غدا|غدًا|الغد/u', $t)) { $add = 1; }
        elseif (preg_match('/اليوم|النهارد|الحين|الآن|الان|دلوقتي|حالا|أقرب وقت|اقرب وقت|الليله|الليلة/u', $t)) { $add = 0; }
        elseif (preg_match('/بعد\s*(\d{1,2})\s*(يوم|ايام|أيام)/u', $t, $m)) { $add = (int) $m[1]; }
        else {
            $wd = ['الأحد' => 0, 'الاحد' => 0, 'الاثنين' => 1, 'الإثنين' => 1, 'الثلاثاء' => 2, 'الأربعاء' => 3, 'الاربعاء' => 3, 'الخميس' => 4, 'الجمعة' => 5, 'الجمعه' => 5, 'السبت' => 6];
            foreach ($wd as $name => $w) { if (mb_strpos($t, $name) !== false) { $add = ($w - (int) $now->format('w') + 7) % 7; break; } }
        }
        if ($add === null) { return null; }
        // «بكره الصبح» said after midnight and before 7 am means this morning (the customer's «tomorrow» is the coming day)
        if ($add === 1 && (int) $now->format('G') < self::START_HOUR && preg_match('/الصبح|بدري|صباح/u', $t)) { $add = 0; }
        return $now->modify('+' . $add . ' day')->format('Y-m-d');
    }

    /** Is it for a later day than today? */
    public static function is_later($o, $ts = null) {
        $d = self::day_of($o, $ts);
        return $d !== null && $d > self::now($ts)->format('Y-m-d');
    }

    public static function held($o) { return !empty($o['scheduled']) && empty($o['scheduled']['released']); }

    public static function log($text) {
        $L = get_option(self::LOG, []); $L = is_array($L) ? $L : [];
        array_unshift($L, ['at' => gmdate('c'), 'text' => mb_substr((string) $text, 0, 300)]);
        update_option(self::LOG, array_slice($L, 0, 200), false);
    }

    private static function day_label($day) {
        $days = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        $t = strtotime($day . ' 12:00:00');
        return $t ? $days[(int) gmdate('w', $t)] . ' ' . gmdate('j/n', $t) : $day;
    }

    private static function ops($title, $body, $url, $event, $owner = false) {
        $sys = self::sys();
        foreach (array_merge(['operations', 'supervisor'], $owner ? ['owner'] : []) as $role) {
            try { Mutqan_Store::insert('_push', ['target' => ['role' => $role], 'title' => mb_substr($title, 0, 120), 'body' => mb_substr($body, 0, 700), 'url' => $url, 'sound' => 'bell', 'priority' => 'normal', 'event' => $event, 'from' => 'system'], null, ['expiresAt' => gmdate('c', time() + 3 * 86400)], $sys); } catch (Throwable $e) { /* never block */ }
        }
    }

    /** A new order for a later day: filed, not dispatched. Returns the order as it stands, or null when it is for today. */
    public static function file_if_later($doc, $ts = null) {
        if (!is_array($doc) || empty($doc['id']) || !empty($doc['technicianId']) || !empty($doc['scheduled'])) { return null; }
        if (!self::is_later($doc, $ts)) { return null; }
        $day = self::day_of($doc, $ts);
        $n = Mutqan_Store::num($doc);
        $back = Mutqan_Store::update('orders', (string) $doc['id'], ['scheduled' => ['day' => $day, 'at' => gmdate('c'), 'released' => false], 'handler' => 'operations'], self::sys());
        $who = trim((string) ($doc['clientName'] ?? '') . ' · ' . (string) ($doc['preferredTime'] ?? ''), ' ·');
        self::ops('📅 طلب محجوز ليوم ' . self::day_label($day) . ' #' . $n, $who . ' — هيتوزع على الفني يوم الطلب من الساعة 7 الصبح', '#/staff/tasks', 'order_scheduled');
        self::log('📅 #' . $n . ' اتحجز ليوم ' . self::day_label($day) . ' — ' . $who);
        return is_array($back) && !empty($back['id']) ? $back : array_merge($doc, ['scheduled' => ['day' => $day, 'released' => false]]);
    }

    /** Every five minutes: the evening list (once), and the morning release of today's orders. */
    public static function tick($ts = null) {
        $now = self::now($ts); $h = (int) $now->format('G'); $today = $now->format('Y-m-d');
        $tomorrow = (clone $now)->modify('+1 day')->format('Y-m-d');
        $out = ['evening' => 0, 'released' => 0];
        try { $rows = Mutqan_Store::select('orders', [], ['limit' => 300, 'order' => '-createdAt'], self::sys()); }
        catch (Throwable $e) { return $out; }
        $held = array_values(array_filter($rows, function ($o) { return self::held($o) && !in_array((string) ($o['status'] ?? ''), ['cancelled', 'completed', 'closed'], true); }));
        // the evening before: tomorrow's list, to operations and the owner, once a day
        if ($h >= self::EVE_HOUR && get_option('mutqan_sched_eve') !== $today) {
            $tm = array_values(array_filter($held, function ($o) use ($tomorrow) { return ($o['scheduled']['day'] ?? '') === $tomorrow; }));
            update_option('mutqan_sched_eve', $today, false);
            if ($tm) {
                $L = array_map(function ($o) { return '• #' . Mutqan_Store::num($o) . ' ' . ((string) ($o['clientName'] ?? '') ?: (string) ($o['clientPhone'] ?? '')) . ' — ' . ((string) ($o['preferredTime'] ?? '')); }, array_slice($tm, 0, 20));
                self::ops('📅 بكره عندك ' . count($tm) . ' طلب محجوز', implode("\n", $L), '#/staff/tasks', 'schedule_eve', true);
                self::log('🌙 تنبيه الليلة: بكره ' . count($tm) . ' طلب');
                $out['evening'] = count($tm);
            }
        }
        // the day itself, from 7 am: released and (in «آلي» mode) dispatched
        if ($h >= self::START_HOUR) {
            foreach ($held as $o) {
                if (($o['scheduled']['day'] ?? '9999') > $today) { continue; }
                $n = Mutqan_Store::num($o);
                Mutqan_Store::update('orders', (string) $o['id'], ['scheduled' => array_merge((array) $o['scheduled'], ['released' => gmdate('c')])], self::sys());
                self::ops('📅 طلب النهارده اتفتح للتوزيع #' . $n, ((string) ($o['clientName'] ?? '') ?: (string) ($o['clientPhone'] ?? '')) . ' — ' . ((string) ($o['preferredTime'] ?? '')), '#/staff/order/' . $o['id'], 'schedule_release');
                $to = '';
                if (class_exists('Mutqan_Dispatch') && Mutqan_Dispatch::is_auto() && empty($o['technicianId'])) {
                    try { $d = Mutqan_Dispatch::dispatch(Mutqan_Store::get_raw('orders', (string) $o['id']) + ['id' => (string) $o['id']]); $to = (string) ($d['technicianName'] ?? ''); } catch (Throwable $e) { error_log('[mutqan] schedule dispatch ' . $e->getMessage()); }
                }
                self::log('☀️ #' . $n . ' اتفتح للتوزيع' . ($to !== '' ? ' واتسند لـ ' . $to : ''));
                $out['released']++;
            }
        }
        return $out;
    }

    /** POST /schedule — {op: list}: the coming days and the log (owner, operations, supervisor). */
    public static function handle($in, $p) {
        if (!in_array((string) ($p['role'] ?? ''), ['owner', 'operations', 'supervisor'], true)) { throw new Mutqan_Error('forbidden', 'للإدارة بس.', 403); }
        $op = (string) ($in['op'] ?? 'list');
        if ($op === 'release' && !empty($in['id'])) {
            $o = Mutqan_Store::get_raw('orders', (string) $in['id']);
            if (!$o) { throw new Mutqan_Error('not_found', 'الطلب مش موجود.', 404); }
            Mutqan_Store::update('orders', (string) $in['id'], ['scheduled' => array_merge((array) ($o['scheduled'] ?? []), ['released' => gmdate('c'), 'by' => (string) ($p['id'] ?? '')])], self::sys());
            self::log('▶️ #' . Mutqan_Store::num($o + ['id' => (string) $in['id']]) . ' اتفتح بإيد ' . ((string) ($p['name'] ?? '') ?: 'الإدارة'));
        }
        $rows = Mutqan_Store::select('orders', [], ['limit' => 300, 'order' => '-createdAt'], self::sys());
        $days = [];
        foreach ($rows as $o) {
            if (empty($o['scheduled']) || in_array((string) ($o['status'] ?? ''), ['cancelled'], true)) { continue; }
            $d = (string) ($o['scheduled']['day'] ?? '');
            if ($d === '' || $d < self::now()->modify('-1 day')->format('Y-m-d')) { continue; }
            $days[$d][] = ['id' => (string) $o['id'], 'number' => Mutqan_Store::num($o), 'client' => (string) ($o['clientName'] ?? ''), 'phone' => (string) ($o['clientPhone'] ?? ''), 'service' => (string) ($o['serviceLabel'] ?? ($o['service'] ?? '')), 'time' => (string) ($o['preferredTime'] ?? ''), 'released' => !empty($o['scheduled']['released']), 'tech' => (string) ($o['technicianName'] ?? ''), 'status' => (string) ($o['status'] ?? '')];
        }
        ksort($days);
        $out = [];
        foreach ($days as $d => $list) { $out[] = ['day' => $d, 'label' => self::day_label($d), 'orders' => $list]; }
        return ['days' => $out, 'log' => array_slice((array) get_option(self::LOG, []), 0, 60)];
    }
}
