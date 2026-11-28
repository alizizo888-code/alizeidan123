<?php
if (!defined('ABSPATH')) { exit; }

/**
 * The technician's terms with the company (owner order 2026-11-22), as dispatch needs them — the PHP twin of
 * mutqan-app/src/lib/custody.js (keep the two in step):
 *   - frozen by the owner, or prepaid with credit under his minimum → he gets no new jobs;
 *   - scoreBoost (−30 … +30) set by the owner → added to his dispatch score.
 * Settings live in tech_wallet (one row per technician, field techId); money lines in settlements.
 */
final class Mutqan_Wallet {
    private static $w = null;
    private static $s = null;

    private static function sys() { return ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']]; }

    public static function settings($techId) {
        if (self::$w === null) {
            self::$w = [];
            foreach (Mutqan_Store::select('tech_wallet', [], ['limit' => 500], self::sys()) as $r) { $id = (string) ($r['techId'] ?? ''); if ($id !== '') { self::$w[$id] = $r; } }
        }
        return self::$w[(string) $techId] ?? [];
    }

    public static function boost($techId) { return max(-30, min(30, (int) (self::settings($techId)['scoreBoost'] ?? 0))); }

    /** Jobs that came back under warranty on his work in the last 60 days: −5 each in dispatch, at most −20. */
    public static function returns_count($techId, $orders) {
        $by = []; foreach ((array) $orders as $o) { $by[(string) ($o['id'] ?? '')] = $o; }
        $n = 0; $cut = time() - 60 * 86400;
        foreach ((array) $orders as $o) {
            $b = (string) ($o['warrantyOf'] ?? ($o['returnOf'] ?? ''));
            if ($b === '' || !isset($by[$b]) || ($by[$b]['technicianId'] ?? '') !== $techId) { continue; }
            if (strtotime((string) ($o['createdAt'] ?? '')) >= $cut) { $n++; }
        }
        return $n;
    }
    public static function returns_drag($techId, $orders) { return min(20, 5 * self::returns_count($techId, $orders)); }

    /** Commission mode: what he holds for the company (custody().balance). */
    public static function owes($techId, $orders, $share, $cashShare = 0) {
        $w = self::settings($techId);
        $sp = (float) ($w['sharePct'] ?? 0); if ($sp <= 0 || $sp > 100) { $sp = (float) $share; }
        $cs = 0; foreach ([(float) ($w['cashSharePct'] ?? 0), (float) $cashShare] as $v) { if ($v > 0 && $v <= 100) { $cs = $v; break; } } if (!$cs) { $cs = $sp; }
        // «نظام راتب» (2026-11-24): no share; the cash he collects is all the company's; pay/bonus/penalty are payroll
        $salary = ($w['mode'] ?? '') === 'salary';
        if ($salary) { $sp = 0.0; $cs = 0.0; }
        $b = 0.0;
        foreach ((array) $orders as $o) {
            if (($o['technicianId'] ?? null) !== $techId || !in_array($o['status'] ?? '', ['completed', 'closed'], true) || !empty($o['inhouse']) || ($o['dispatch'] ?? '') === 'site' || !empty($o['noCommission'])) { continue; }
            $a = class_exists('Mutqan_Ops') ? Mutqan_Ops::amount($o) : (float) ($o['price'] ?? 0);
            $paid = ($o['paymentStatus'] ?? '') === 'paid'; $cash = $paid && preg_match('/cash|نقد/i', (string) ($o['paymentMethod'] ?? ''));
            $b -= $a * (($cash || !$paid) ? $cs : $sp) / 100;
            if ($cash) { $b += $a; }
        }
        if (self::$s === null) { self::$s = Mutqan_Store::select('settlements', [], ['limit' => 3000], self::sys()); }
        foreach (self::$s as $s) {
            if (($s['techId'] ?? null) !== $techId) { continue; }
            $n = (float) ($s['amount'] ?? 0); $k = (string) ($s['kind'] ?? (($s['dir'] ?? '') === 'out' ? 'out' : 'in'));
            if ($k === 'in') { $b -= $n; } elseif ($salary) { continue; } elseif ($k === 'out' || $k === 'penalty') { $b += $n; } elseif ($k === 'bonus' || $k === 'adjust') { $b -= $n; }
        }
        return $b;
    }

    /** Prepaid credit, same sum as custody().credit. */
    public static function credit($techId, $orders, $share, $cashShare = 0) {
        $w = self::settings($techId);
        if (($w['mode'] ?? '') !== 'prepaid') { return null; }
        $sp = (float) ($w['sharePct'] ?? 0); if ($sp <= 0 || $sp > 100) { $sp = (float) $share; }
        $cs = 0; foreach ([(float) ($w['cashSharePct'] ?? 0), (float) $cashShare] as $v) { if ($v > 0 && $v <= 100) { $cs = $v; break; } } if (!$cs) { $cs = $sp; }
        $since = !empty($w['modeSince']) ? (int) strtotime((string) $w['modeSince']) : 0;
        $c = 0.0;
        foreach ((array) $orders as $o) {
            if (($o['technicianId'] ?? null) !== $techId || !in_array($o['status'] ?? '', ['completed', 'closed'], true) || !empty($o['inhouse']) || ($o['dispatch'] ?? '') === 'site' || !empty($o['noCommission'])) { continue; }
            $t = (int) strtotime((string) ($o['completedAt'] ?? ($o['updatedAt'] ?? ''))); if ($t < $since) { continue; }
            $a = class_exists('Mutqan_Ops') ? Mutqan_Ops::amount($o) : (float) ($o['price'] ?? 0);
            $cash = preg_match('/cash|نقد/i', (string) ($o['paymentMethod'] ?? ''));
            if ($cash || ($o['paymentStatus'] ?? '') !== 'paid') { $c -= $a * (100 - $cs) / 100; } else { $c += $a * $sp / 100; }
        }
        if (self::$s === null) { self::$s = Mutqan_Store::select('settlements', [], ['limit' => 3000], self::sys()); }
        foreach (self::$s as $s) {
            if (($s['techId'] ?? null) !== $techId) { continue; }
            $n = (float) ($s['amount'] ?? 0);
            $k = (string) ($s['kind'] ?? (($s['dir'] ?? '') === 'out' ? 'out' : 'in'));
            if (in_array($k, ['topup', 'bonus', 'adjust'], true)) { $c += $n; }
            elseif (in_array($k, ['penalty', 'refund', 'out'], true)) { $c -= $n; }
        }
        return $c;
    }

    /** Why he must not get a new job right now ('' = he may). */
    public static function blocked($t, $orders) {
        $id = (string) ($t['userId'] ?? '');
        if ($id === '') { return ''; }
        $w = self::settings($id);
        if (!empty($w['frozen'])) { return 'frozen'; }
        $r = Mutqan_Store::select('tech_pay', [], ['limit' => 1], self::sys());
        // commission mode: too much company cash in his pocket → hand it in first
        $cap = (float) ($w['cashCap'] ?? 0); if ($cap <= 0) { $cap = (float) ($r[0]['cashCap'] ?? 0); }
        if (($w['mode'] ?? '') !== 'prepaid' && $cap > 0 && $orders && self::owes($id, $orders, (float) ($r[0]['sharePct'] ?? 0), (float) ($r[0]['cashSharePct'] ?? 0)) > $cap) { return 'cash_cap'; }
        if (($w['mode'] ?? '') === 'prepaid') {
            $c = self::credit($id, $orders, (float) ($r[0]['sharePct'] ?? 0), (float) ($r[0]['cashSharePct'] ?? 0));
            if ($c !== null && $c < (float) ($w['minBalance'] ?? 0)) { return 'low_credit'; }
        }
        return '';
    }
}
