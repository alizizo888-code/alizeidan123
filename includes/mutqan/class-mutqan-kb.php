<?php
if (!defined('ABSPATH')) { exit; }

/**
 * The owner's own settings, handed to the model one question at a time.
 *
 * The knowledge was already there: the owner has been filling in the assistant's knowledge base,
 * his negotiation policy and his service area from the app's AI settings screen for months. What was
 * missing is that only the in-app assistant ever read them. The website chat and the WhatsApp bot run
 * through the free-provider gateway, and that gateway was handing the model nothing — so the same
 * business answered the same question two different ways depending on which door the customer came
 * through, and on WhatsApp it answered from the model's imagination.
 *
 * So this reads the settings the owner already keeps, in one place, and nothing new is invented for
 * him to fill in twice. One knowledge base, every channel.
 *
 * Two things make it a filter rather than a dump:
 *
 *   - audience. A row carries who it is for. A technician's safety rule is noise in a customer's
 *     answer, and the model answers worse the more it has to read, so only the rows for the person
 *     asking are attached, and only those the question actually touches.
 *   - internal. The discount ceiling and the negotiation style reach the model and are never for
 *     repeating: the ceiling is what lets the assistant bargain at all, and it is the one line a
 *     customer must never be shown. The rule is written next to the figure it governs, because a
 *     rule a page away from its figure is a rule the model forgets.
 */
final class Mutqan_KB {

    const MAX_ROWS = 8;            // the model answers worse the more it has to read
    const MAX_CHARS = 2800;
    const CACHE = 300;
    const AUDIENCES = ['customer', 'technician', 'operations', 'owner'];

    private static function sys() { return ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']]; }

    /** Which side of the business is asking. Anyone who is not staff is a customer. */
    public static function audience($p) {
        $role = (string) ($p['role'] ?? '');
        return in_array($role, ['owner', 'operations', 'technician'], true) ? $role : 'customer';
    }

    /** Arabic folded to one spelling, so "الصيانه" and "الصيانة" are the same word. */
    public static function fold($s) {
        $s = (string) $s;
        $s = preg_replace('/[\x{0617}-\x{061A}\x{064B}-\x{0652}\x{0640}]/u', '', $s);
        $s = str_replace(['أ', 'إ', 'آ', 'ة', 'ى', 'ئ', 'ؤ'], ['ا', 'ا', 'ا', 'ه', 'ي', 'ي', 'و'], $s);
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** Words worth matching on: the short ones ("في", "من", "the") match everything and mean nothing. */
    public static function terms($q) {
        $out = [];
        foreach (explode(' ', mb_strtolower(self::fold($q))) as $w) {
            if (mb_strlen($w) >= 3) { $out[$w] = true; }
        }
        return array_keys($out);
    }

    /** The owner's AI settings row, as the app writes it. */
    public static function settings() {
        try {
            $row = Mutqan_Store::get_raw('ai_settings', 'default');
            if (is_array($row)) { return $row; }
            $rows = Mutqan_Store::select('ai_settings', [], ['limit' => 1], self::sys());
            return is_array($rows[0] ?? null) ? $rows[0] : [];
        } catch (Throwable $e) { return []; }
    }

    /**
     * Everything the owner has written, as rows this class can score, once every five minutes.
     *
     * A row may name its own audience and may mark itself internal; the ones that do not are for
     * everyone and for repeating, which is what a knowledge-base answer is.
     */
    public static function rows() {
        $hit = get_transient('mq_kb_rows');
        if (is_array($hit)) { return $hit; }
        $s = self::settings();
        $out = [];
        foreach ((array) ($s['faq'] ?? []) as $f) {
            $q = trim((string) ($f['q'] ?? '')); $a = trim((string) ($f['a'] ?? ''));
            if ($q === '' || $a === '') { continue; }
            $aud = is_array($f['audience'] ?? null) ? array_values(array_intersect($f['audience'], self::AUDIENCES)) : [];
            $out[] = ['title' => mb_substr($q, 0, 160), 'body' => mb_substr($a, 0, 700), 'audience' => $aud,
                'tags' => (string) ($f['cat'] ?? ''), 'internal' => !empty($f['internal']), 'always' => !empty($f['always']), 'weight' => 5];
        }
        foreach (self::policy_rows($s) as $r) { $out[] = $r; }
        set_transient('mq_kb_rows', $out, self::CACHE);
        return $out;
    }

    /**
     * The owner's policy, turned into sentences the model can act on.
     *
     * These are the rows that let the assistant do more than recite: the ceiling is what it bargains
     * inside, the service area is what it accepts or refuses, and the style is how it sounds doing it.
     */
    public static function policy_rows($s) {
        $out = [];
        $g = (array) ($s['globalPolicy'] ?? []);
        $n = (array) ($s['negotiation'] ?? []);
        $area = (array) ($s['serviceArea'] ?? []);

        $cap = isset($g['maxDiscountPct']) ? (int) $g['maxDiscountPct'] : null;
        $allowed = !array_key_exists('negotiationAllowed', $g) || !empty($g['negotiationAllowed']);
        if (!$allowed || !empty($n) && isset($n['enabled']) && empty($n['enabled'])) {
            $out[] = ['title' => 'التفاوض', 'body' => 'التفاوض على السعر مقفول. السعر المعروض هو السعر، ومن يطلب خصمًا يُحوَّل للموظف.',
                'audience' => [], 'tags' => 'سعر خصم تفاوض', 'internal' => true, 'always' => true, 'weight' => 9];
        } elseif ($cap !== null) {
            $styles = ['soft' => 'كن متفهمًا جدًا ولطيفًا', 'balanced' => 'كن ودودًا وواضحًا', 'firm' => 'كن مهذبًا لكن حازمًا وركّز على القيمة قبل أي خصم'];
            $style = $styles[(string) ($n['style'] ?? 'balanced')] ?? $styles['balanced'];
            $steps = max(1, min(4, (int) ($n['discountSteps'] ?? 2)));
            $out[] = ['title' => 'حدود التفاوض', 'body' => 'أقصى خصم مسموح ' . $cap . '% ولا تتجاوزه أبدًا. اعرض القيمة أولًا، ثم انزل على ' . $steps
                . ' مراحل كحد أقصى، ومن يطلب أكثر من الحد يُحوَّل للموظف. ' . $style . '.',
                'audience' => [], 'tags' => 'سعر خصم تفاوض غالي ارخص', 'internal' => true, 'always' => true, 'weight' => 9];
        }
        if (!empty($area['enabled']) && !empty($area['name'])) {
            $r = (int) ($area['radiusKm'] ?? 0);
            $out[] = ['title' => 'نطاق الخدمة', 'body' => 'نخدم ' . (string) $area['name'] . ($r > 0 ? ' وحتى ' . $r . ' كم حولها' : '')
                . '. الطلب خارج النطاق لا تؤكده — اعتذر بلطف وحوّله للموظف.',
                'audience' => [], 'tags' => 'منطقه مدينه بعيد نطاق توصيل', 'internal' => false, 'always' => true, 'weight' => 8];
        }
        return $out;
    }

    public static function flush() { delete_transient('mq_kb_rows'); }

    /** A row is for this caller when it names his side of the business, or names nobody. */
    public static function for_audience($row, $aud) {
        $a = $row['audience'] ?? [];
        return $a === [] || in_array($aud, $a, true);
    }

    /** How well a row answers this question: term overlap, plus the owner's own weight. */
    public static function score($row, $terms) {
        $hay = ' ' . mb_strtolower(self::fold($row['title'] . ' ' . $row['tags'] . ' ' . $row['body'])) . ' ';
        $hits = 0;
        foreach ($terms as $t) { if (mb_strpos($hay, $t) !== false) { $hits++; } }
        if ($hits === 0 && empty($row['always'])) { return 0; }
        return $hits * 10 + (int) ($row['weight'] ?? 0) + (!empty($row['always']) ? 5 : 0);
    }

    /** The rows this question should be answered with, best first. */
    public static function pick($q, $aud) {
        $terms = self::terms($q);
        $scored = [];
        foreach (self::rows() as $i => $row) {
            if (!self::for_audience($row, $aud)) { continue; }
            $s = self::score($row, $terms);
            if ($s > 0) { $scored[] = [$s, $i, $row]; }
        }
        // Ties keep the owner's own order, so the list does not reshuffle between two identical questions.
        usort($scored, function ($x, $y) { return $y[0] === $x[0] ? $x[1] <=> $y[1] : $y[0] <=> $x[0]; });
        $out = []; $chars = 0;
        foreach ($scored as $pair) {
            $row = $pair[2];
            $len = mb_strlen($row['title']) + mb_strlen($row['body']) + 4;
            if (count($out) >= self::MAX_ROWS || $chars + $len > self::MAX_CHARS) { break; }
            $chars += $len;
            $out[] = $row;
        }
        return $out;
    }

    /** The block to put in front of the prompt, or '' when nothing the owner wrote bears on it. */
    public static function context($q, $p) {
        $aud = self::audience($p);
        $rows = self::pick($q, $aud);
        if ($rows === []) { return ''; }
        $open = []; $closed = [];
        foreach ($rows as $r) {
            $line = ($r['title'] !== '' ? '- ' . $r['title'] . ': ' : '- ') . $r['body'];
            if (!empty($r['internal'])) { $closed[] = $line; } else { $open[] = $line; }
        }
        $out = '';
        if ($open) { $out .= "## معلومات الشركة المعتمدة\n" . implode("\n", $open) . "\n"; }
        if ($closed) {
            $out .= "\n## للاستخدام الداخلي — للقرار فقط\n"
                . 'هذه الأسطر تساعدك تقرر وتتفاوض. لا تذكرها حرفيًا'
                . ($aud === 'customer' ? '، ولا تكشف للعميل حدود الخصم ولا أي رقم داخلي' : '')
                . ".\n" . implode("\n", $closed) . "\n";
        }
        $out .= "\nاعتمد على ما سبق قبل أي معرفة عامة. وما ليس مكتوبًا هنا لا تخترعه — قل إنك ستتأكد وحوّل الكلام لمسؤول.\n";
        return $out;
    }

    /** Puts the owner's facts in front of a prompt on its way to the model. */
    public static function inject($prompt, $opts, $p) {
        if (isset($opts['kb']) && !$opts['kb']) { return $prompt; }
        try { $ctx = self::context($prompt, $p); } catch (Throwable $e) { return $prompt; }
        return $ctx === '' ? $prompt : $ctx . "\n" . $prompt;
    }
}
