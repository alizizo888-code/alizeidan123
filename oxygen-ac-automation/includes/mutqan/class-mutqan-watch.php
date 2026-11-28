<?php
if (!defined('ABSPATH')) { exit; }

/**
 * The follow-up watch over open orders (owner, 2026-11-23 — items 1, 3 and 8 of the ideas list).
 *
 * Every five minutes (with the appointment reminders) it looks at the open orders and says out loud what is stuck:
 *  - an order the customer never confirmed on WhatsApp after 10 minutes            → «⏳ طلب ما اتأكدش»
 *  - a technician who was given an order and did not accept it within 5 minutes    → in «آلي» mode the order moves on to
 *    the next technician by itself (at most 3 times, then the standby technician, then operations hear it); in «يدوي»
 *    mode operations hear it
 *  - a technician who accepted and then stopped moving (an hour on the road, 3 hours on site)  → «🐢 طلب متعطل»
 *  - an appointment 30 minutes past with nobody there                                → «🚨 متأخر عن الموعد»
 *
 * Each mark is written once on the order (`watch`), so an order rings once per problem, not every five minutes.
 * The rules are flags_of(); their twin for the screens is lib/orderWatch.js — the same minutes, the same words.
 */
final class Mutqan_Watch {

    const ACTIVE = ['new', 'forced', 'assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress'];
    const MOVING = ['accepted', 'en_route', 'nearby'];
    const ONSITE = ['arrived', 'in_progress'];
    const MAX_TRANSFERS = 3;

    private static function sys() { return ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']]; }

    public static function boot() {
        add_action('mutqan_remind', [__CLASS__, 'sweep'], 15);
    }

    /** The minutes, from the owner's distribution settings (the operations room writes them). */
    public static function cfg($s = null) {
        $s = is_array($s) ? $s : (class_exists('Mutqan_Dispatch') ? Mutqan_Dispatch::settings() : []);
        $n = function ($k, $d) use ($s) { $v = (int) ($s[$k] ?? 0); return $v > 0 ? $v : $d; };
        return [
            'unconfirmed' => $n('unconfirmedMinutes', 10),
            'accept' => $n('acceptMinutes', 5),
            'stall' => $n('stallMinutes', 60),
            'onsite' => $n('onsiteMinutes', 180),
            'late' => $n('lateMinutes', 30),
            'transfer' => ($s['autoTransfer'] ?? true) !== false,
            'auto' => ($s['mode'] ?? 'manual') === 'auto',
        ];
    }

    private static function ts($iso) { $t = strtotime((string) $iso); return $t ?: 0; }

    /** When the order last changed status (the newest line of its history), else when it was assigned / created. */
    public static function last_move($o) {
        $h = (array) ($o['statusHistory'] ?? []);
        $last = $h ? end($h) : null;
        return self::ts($last['at'] ?? '') ?: (self::ts($o['assignedAt'] ?? '') ?: self::ts($o['createdAt'] ?? ''));
    }

    /**
     * What is wrong with one order right now: [key => sentence]. Pure — the screens and the tests use the same rules.
     * Keys: unconfirmed · not_accepted · stalled · late.
     */
    public static function flags_of($o, $now = null, $cfg = null) {
        $now = $now ?? time();
        $cfg = $cfg ?? self::cfg();
        $st = (string) ($o['status'] ?? 'new');
        if (!in_array($st, self::ACTIVE, true)) { return []; }
        if (class_exists('Mutqan_Schedule') && Mutqan_Schedule::held($o)) { return []; }   // filed for its day: nothing is late yet
        $out = [];
        $age = ($now - self::ts($o['createdAt'] ?? '')) / 60;
        if (empty($o['customerConfirmed']) && $age >= $cfg['unconfirmed'] && $age < 2 * 1440) {
            $out['unconfirmed'] = 'العميل ما أكدش على واتساب من ' . (int) $age . ' دقيقة';
        }
        if ($st === 'assigned' && !empty($o['technicianId']) && ($o['dispatch'] ?? '') !== 'site' && empty($o['inhouse'])) {
            $wait = ($now - (self::ts($o['assignedAt'] ?? '') ?: self::last_move($o))) / 60;
            if ($wait >= $cfg['accept']) { $out['not_accepted'] = ($o['technicianName'] ?? 'الفني') . ' ما قبلش الطلب من ' . (int) $wait . ' دقيقة'; }
        }
        $still = ($now - self::last_move($o)) / 60;
        if (in_array($st, self::MOVING, true) && $still >= $cfg['stall']) {
            $out['stalled'] = ($o['technicianName'] ?? 'الفني') . ' مااتحركش في الطلب من ' . (int) $still . ' دقيقة';
        } elseif (in_array($st, self::ONSITE, true) && $still >= $cfg['onsite']) {
            $out['stalled'] = ($o['technicianName'] ?? 'الفني') . ' عند العميل من ' . (int) $still . ' دقيقة ومقفلش الطلب';
        }
        $appt = self::ts($o['appointmentAt'] ?? '');
        if ($appt && !in_array($st, self::ONSITE, true) && ($now - $appt) / 60 >= $cfg['late'] && ($now - $appt) < 86400) {
            $out['late'] = 'الموعد عدّى من ' . (int) (($now - $appt) / 60) . ' دقيقة والفني لسه ما وصلش';
        }
        return $out;
    }

    /** Idea 14: finished 2–24 hours ago, the customer has a number and hasn't rated. */
    public static function rate_due($o, $now = null) {
        $now = $now ?? time();
        if (!in_array((string) ($o['status'] ?? ''), ['completed'], true)) { return false; }
        if (!empty($o['rating']) || !empty($o['customerRating']) || (string) ($o['clientPhone'] ?? '') === '') { return false; }
        $done = self::ts($o['completedAt'] ?? '');
        if (!$done) { foreach ((array) ($o['statusHistory'] ?? []) as $h) { if (($h['status'] ?? '') === 'completed') { $done = self::ts($h['at'] ?? ''); } } }
        return $done > 0 && $now - $done >= 2 * 3600 && $now - $done < 86400;
    }

    const TITLES = [
        'unconfirmed' => '⏳ طلب ما اتأكدش',
        'not_accepted' => '⌛ الفني ما قبلش الطلب',
        'stalled' => '🐢 طلب متعطل',
        'late' => '🚨 متأخر عن الموعد',
    ];

    /** One pass over the open orders. Returns how many alerts it raised / orders it moved (for the tests). */
    public static function sweep($now = null) {
        $now = $now ?? time();
        $cfg = self::cfg();
        $sys = self::sys();
        try { $rows = Mutqan_Store::select('orders', [], ['limit' => 300, 'order' => '-createdAt'], $sys); }
        catch (Throwable $e) { error_log('[mutqan] watch ' . $e->getMessage()); return ['alerts' => 0, 'moved' => 0]; }
        $alerts = 0; $moved = 0;
        // a new order from a number that came through a referral link (Mutqan_Growth, idea 20)
        if (class_exists('Mutqan_Growth')) { try { Mutqan_Growth::attach_referrals($rows); } catch (Throwable $e) { error_log('[mutqan] referral ' . $e->getMessage()); } }
        foreach ($rows as $o) {
            try {
                $seen = is_array($o['watch'] ?? null) ? $o['watch'] : [];
                if (self::rate_due($o, $now) && empty($seen['rate'])) {
                    $sent = class_exists('Mutqan_WA_Journey') ? Mutqan_WA_Journey::rate_again($o) : false;
                    Mutqan_Store::update('orders', $o['id'], ['watch' => array_merge($seen, ['rate' => $sent ? 'sent' : 'skipped'])], $sys);
                    continue;
                }
                $flags = self::flags_of($o, $now, $cfg);
                if (!$flags) { continue; }
                // a technician who doesn't take it: in «آلي» mode the order goes on to the next one by itself
                if (isset($flags['not_accepted']) && $cfg['auto'] && $cfg['transfer'] && (int) ($o['transferCount'] ?? 0) < self::MAX_TRANSFERS) {
                    if (self::transfer($o)) { $moved++; unset($flags['not_accepted']); }
                }
                $mark = [];
                foreach ($flags as $k => $why) {
                    // once per problem — and once more for «not accepted» when it is a different technician
                    $stamp = $k === 'not_accepted' ? (string) ($o['technicianId'] ?? '') : '1';
                    if (($seen[$k] ?? '') === $stamp) { continue; }
                    $mark[$k] = $stamp;
                    self::alert($o, self::TITLES[$k] ?? '⚠️ طلب محتاج تدخل', $why, 'order_watch_' . $k);
                    $alerts++;
                }
                if ($mark) {
                    $fresh = Mutqan_Store::get_raw('orders', (string) $o['id']);
                    $w = is_array($fresh['watch'] ?? null) ? $fresh['watch'] : [];
                    Mutqan_Store::update('orders', $o['id'], ['watch' => array_merge($w, $mark)], $sys);
                }
            } catch (Throwable $e) { error_log('[mutqan] watch order ' . $e->getMessage()); }
        }
        return ['alerts' => $alerts, 'moved' => $moved];
    }

    private static function alert($o, $title, $why, $event) {
        $id = (string) ($o['id'] ?? '');
        $who = trim((string) ($o['clientName'] ?? '') . ' · ' . mb_substr((string) ($o['address'] ?? ''), 0, 40), ' ·');
        $body = $why . ($who !== '' ? ' — ' . $who : '');
        $sys = self::sys();
        foreach (['operations', 'supervisor'] as $role) {
            Mutqan_Store::insert('_push', ['target' => ['role' => $role], 'title' => $title . ' #' . Mutqan_Store::num($o), 'body' => mb_substr($body, 0, 300), 'url' => '#/staff/order/' . $id, 'sound' => 'bell', 'priority' => 'high', 'event' => $event, 'from' => 'system'], null, ['expiresAt' => gmdate('c', time() + 2 * 86400)], $sys);
        }
        if (class_exists('Mutqan_Rest')) { Mutqan_Rest::admin_alert($title . ' #' . Mutqan_Store::num($o), $body, '#/staff/order/' . $id, $event, 'high', $id); }
    }

    /**
     * Takes the order back from a technician who didn't accept it and hands it to the next one (Mutqan_Dispatch, which
     * now skips him, then tries the standby technician, then the site technician / the open pool). True when it moved.
     */
    public static function transfer($o) {
        $id = (string) ($o['id'] ?? '');
        $was = (string) ($o['technicianId'] ?? '');
        if ($id === '' || $was === '' || !class_exists('Mutqan_Dispatch')) { return false; }
        $sys = self::sys();
        $at = gmdate('c');
        $skip = array_values(array_unique(array_merge(array_map('strval', (array) ($o['skipTechIds'] ?? [])), [$was])));
        $back = Mutqan_Store::update('orders', $id, [
            'technicianId' => null, 'technicianName' => null, 'status' => 'new', 'handler' => 'operations',
            'skipTechIds' => $skip, 'transferCount' => (int) ($o['transferCount'] ?? 0) + 1,
            'statusHistory' => array_merge((array) ($o['statusHistory'] ?? []), [['status' => 'new', 'at' => $at, 'by' => 'transfer', 'note' => ($o['technicianName'] ?? 'الفني') . ' ما قبلش خلال المهلة']]),
            'transferLog' => array_merge((array) ($o['transferLog'] ?? []), [['from' => 'technician', 'to' => 'operations', 'at' => $at, 'by' => 'transfer', 'note' => (string) ($o['technicianName'] ?? '')]]),
        ], $sys);
        Mutqan_Store::insert('_push', ['target' => ['user' => $was], 'title' => '↪️ الطلب #' . Mutqan_Store::num($o) . ' اتحوّل لفني تاني', 'body' => 'ما اتقبلش خلال المهلة، فاتحوّل لغيرك.', 'url' => '#/staff', 'sound' => 'chime', 'priority' => 'normal', 'event' => 'order_transferred', 'from' => 'system'], null, ['expiresAt' => gmdate('c', time() + 2 * 86400)], $sys);
        $after = Mutqan_Dispatch::dispatch(is_array($back) && !empty($back['id']) ? $back : array_merge($o, ['technicianId' => null, 'skipTechIds' => $skip, 'id' => $id]));
        return is_array($after) && ($after['technicianId'] ?? '') !== $was;
    }
}
