<?php
if (!defined('ABSPATH')) { exit; }

/**
 * The owner's AI points wallet.
 *
 * Every paid Claude call made for the owner and operations (the control assistant, the operations manager, the
 * higher tiers of the chat) spends points from one monthly allowance the owner sets. Commands the internal engine
 * (Mutqan_Quick) runs itself cost nothing, and neither do the free providers.
 *
 * Points per 1,000 tokens: Haiku 1, Sonnet 3, Opus 5 — output tokens count five times, as they cost about that much
 * more. When the wallet is empty the paid tiers step aside and the free chain + the internal engine keep answering,
 * so a spent allowance never means a silent assistant.
 */
final class Mutqan_Points {
    const OPT = 'mutqan_ai_points';

    public static function state() {
        $s = array_merge(['monthly' => 5000, 'topup' => 0, 'used' => 0, 'month' => '', 'calls' => 0, 'internal' => 0, 'last' => ''], (array) get_option(self::OPT, []));
        $m = gmdate('Y-m');
        if ($s['month'] !== $m) { $s['month'] = $m; $s['used'] = 0; $s['calls'] = 0; $s['internal'] = 0; update_option(self::OPT, $s, false); }
        $s['unlimited'] = (int) $s['monthly'] <= 0;
        $s['balance'] = $s['unlimited'] ? null : max(0, (int) $s['monthly'] + (int) $s['topup'] - (int) $s['used']);
        return $s;
    }

    /** Whether the paid tiers may run right now. */
    public static function available() { $s = self::state(); return $s['unlimited'] || $s['balance'] > 0; }

    public static function rate($model) {
        $m = strtolower((string) $model);
        if (strpos($m, 'opus') !== false) { return 5; }
        if (strpos($m, 'sonnet') !== false) { return 3; }
        return 1;
    }

    public static function cost($model, $in, $out) { return (int) max(1, ceil(((int) $in + 5 * (int) $out) / 1000 * self::rate($model))); }

    public static function spend($model, $in, $out) {
        $s = self::state(); $c = self::cost($model, $in, $out);
        $s['used'] = (int) $s['used'] + $c; $s['calls'] = (int) $s['calls'] + 1; $s['last'] = gmdate('c');
        unset($s['balance'], $s['unlimited']);
        update_option(self::OPT, $s, false);
        return $c;
    }

    /** A command the internal engine ran without a model: counted, free. */
    public static function internal() {
        $s = self::state(); $s['internal'] = (int) $s['internal'] + 1; unset($s['balance'], $s['unlimited']);
        update_option(self::OPT, $s, false);
    }

    /** Owner only: monthly allowance (0 = no cap) and one-off top-up for this month. */
    public static function set($in) {
        $s = self::state();
        if (isset($in['monthly']) && is_numeric($in['monthly'])) { $s['monthly'] = max(0, min(10000000, (int) $in['monthly'])); }
        if (isset($in['topup']) && is_numeric($in['topup'])) { $s['topup'] = max(0, (int) $s['topup'] + (int) $in['topup']); }
        if (!empty($in['reset'])) { $s['used'] = 0; $s['topup'] = 0; }
        unset($s['balance'], $s['unlimited']);
        update_option(self::OPT, $s, false);
        return self::state();
    }
}
