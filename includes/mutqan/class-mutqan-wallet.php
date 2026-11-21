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

    /** Prepaid credit, same sum as custody().credit. */
    public static function credit($techId, $orders, $share) {
        $w = self::settings($techId);
        if (($w['mode'] ?? '') !== 'prepaid') { return null; }
        $sp = (float) ($w['sharePct'] ?? 0); if ($sp <= 0 || $sp > 100) { $sp = (float) $share; }
        $since = !empty($w['modeSince']) ? (int) strtotime((string) $w['modeSince']) : 0;
        $c = 0.0;
        foreach ((array) $orders as $o) {
            if (($o['technicianId'] ?? null) !== $techId || !in_array($o['status'] ?? '', ['completed', 'closed'], true) || !empty($o['inhouse']) || ($o['dispatch'] ?? '') === 'site' || !empty($o['noCommission'])) { continue; }
            $t = (int) strtotime((string) ($o['completedAt'] ?? ($o['updatedAt'] ?? ''))); if ($t < $since) { continue; }
            $a = class_exists('Mutqan_Ops') ? Mutqan_Ops::amount($o) : (float) ($o['price'] ?? 0);
            $cash = preg_match('/cash|نقد/i', (string) ($o['paymentMethod'] ?? ''));
            if ($cash || ($o['paymentStatus'] ?? '') !== 'paid') { $c -= $a * (100 - $sp) / 100; } else { $c += $a * $sp / 100; }
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
        if (($w['mode'] ?? '') === 'prepaid') {
            $share = 0; $r = Mutqan_Store::select('tech_pay', [], ['limit' => 1], self::sys()); $share = (float) ($r[0]['sharePct'] ?? 0);
            $c = self::credit($id, $orders, $share);
            if ($c !== null && $c < (float) ($w['minBalance'] ?? 0)) { return 'low_credit'; }
        }
        return '';
    }
}
