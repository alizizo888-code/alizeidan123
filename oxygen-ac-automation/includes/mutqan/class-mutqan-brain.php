<?php
if (!defined('ABSPATH')) { exit; }

/**
 * What the rule bot learns from the model, for the moments the model is not there.
 *
 * The assistant is meant to be mostly the model with a rule bot behind it, and the bot was only
 * ever learning from the owner's own replies. So on a weak connection, a rate limit, or a provider
 * outage, every question the model had already answered a hundred times went unanswered again.
 *
 * Every answer the model gives a customer is kept here as a candidate the bot can fall back on.
 * It is a fallback and nothing more: while any provider is reachable the model answers, because it
 * can read the question in front of it and a remembered line cannot.
 *
 * What is NOT replayed, and why it matters more than what is:
 *
 *   - anything with a price or a number in it, unless the owner approved that line. A remembered
 *     price is a quote the business has to honour, and prices move. Getting this wrong costs money
 *     and trust, so it waits for a human.
 *   - anything the model said only once. A one-off answer is as likely to be a misunderstanding as
 *     a useful reply.
 *   - anything older than a season. Hours, services and offers change.
 *   - anything the owner rejected, ever.
 */
final class Mutqan_Brain {

    const MIN_SEEN = 2;             // said twice before it is treated as the usual answer
    const MAX_AGE = 7776000;        // ninety days
    const MIN_Q = 6;
    const MIN_A = 12;
    const MAX_A = 700;

    private static function sys() { return ['kind' => 'user', 'role' => 'owner', 'id' => 'system']; }
    private static function key($q) { return 'ai:' . substr(md5(Mutqan_WA_Bot::norm($q)), 0, 16); }

    /** A line that commits the business to a figure is never replayed on its own. */
    public static function has_figure($a) {
        return (bool) preg_match('/[0-9٠-٩]{2,}/u', (string) $a)
            || (bool) preg_match('/(ريال|ر\.?س|SAR|سعر|تكلفة|خصم|%)/ui', (string) $a);
    }

    /** Keeps what the model told a customer, so the bot can say it when the model is away. */
    public static function remember($q, $a, $feature = '') {
        $q = trim((string) $q); $a = trim((string) $a);
        if (mb_strlen($q) < self::MIN_Q || mb_strlen($a) < self::MIN_A || mb_strlen($a) > self::MAX_A) { return false; }
        if (!class_exists('Mutqan_WA_Bot') || !class_exists('Mutqan_Store')) { return false; }
        try {
            $id = self::key($q);
            $cur = Mutqan_Store::get_raw('wa_bot', $id);
            // An owner who said no once is not asked again by a counter ticking over.
            if ($cur && ($cur['status'] ?? '') === 'rejected') { return false; }
            Mutqan_Store::upsert('wa_bot', [
                'kind' => 'ai', 'q' => mb_substr($q, 0, 300), 'a' => mb_substr($a, 0, self::MAX_A),
                'count' => (int) ($cur['count'] ?? 0) + 1,
                'status' => $cur['status'] ?? 'pending',
                'figure' => self::has_figure($a),
                'feature' => mb_substr((string) $feature, 0, 40),
                'at' => gmdate('c'),
            ], $id, [], self::sys());
            return true;
        } catch (Throwable $e) { error_log('[mutqan] brain remember ' . $e->getMessage()); return false; }
    }

    /** The answer the bot may give to this question right now, or '' when there is none it should. */
    public static function recall($q) {
        $q = trim((string) $q);
        if (mb_strlen($q) < self::MIN_Q || !class_exists('Mutqan_WA_Bot')) { return ''; }
        try { $row = Mutqan_Store::get_raw('wa_bot', self::key($q)); } catch (Throwable $e) { return ''; }
        if (!$row || ($row['status'] ?? '') === 'rejected') { return ''; }
        $approved = ($row['status'] ?? '') === 'approved';
        if (!$approved) {
            if (!empty($row['figure'])) { return ''; }                      // a price waits for a human
            if ((int) ($row['count'] ?? 0) < self::MIN_SEEN) { return ''; }  // said once is not the usual answer
        }
        $at = strtotime((string) ($row['at'] ?? '')) ?: 0;
        if ($at > 0 && time() - $at > self::MAX_AGE) { return ''; }          // a season is long enough for things to change
        return (string) ($row['a'] ?? '');
    }

    /**
     * True when no provider can answer right now — the only time a remembered line is used.
     *
     * Only a provider that is actually set up counts. One that was never configured is not
     * "reachable" merely because it is not in a cooldown.
     */
    public static function ai_down() {
        if (!class_exists('Mutqan_AI')) { return true; }
        try {
            $live = Mutqan_AI::live_providers();
            return $live === [];
        } catch (Throwable $e) { return true; }
    }

    /** What the bot says when it has nothing of its own and the model cannot be reached. */
    public static function fallback($q) {
        if (!self::ai_down()) { return ''; }
        return self::recall($q);
    }
}
