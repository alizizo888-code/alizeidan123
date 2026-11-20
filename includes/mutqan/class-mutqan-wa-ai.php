<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «موظف الاستقبال الذكي» — every customer message on WhatsApp is answered by the AI, not by a numbered menu.
 *
 * The scripted bot (1️⃣ 2️⃣ 3️⃣, «مواعيد العمل من  إلى») read like a machine and broke on anything off-script. This
 * answers like a person from the company the customer came through, from what the business really knows:
 *   - the owner's facts and FAQ (Mutqan_KB, injected by Mutqan_AI::run) and the sales brain (prices, floor, slots);
 *   - the customer's file (name, past jobs, warranty) and the site / article he came from;
 *   - the last messages of this conversation.
 * The model answers in JSON: the reply, the order details it has gathered so far, whether the order is complete,
 * and whether a person must step in (complaint, refund, anger, anything it should not decide). A complete order
 * becomes a real order (Mutqan_WA::lead_to_order → «حلقة الوصل» confirms it). A model that cannot be reached never
 * leaves the customer unanswered: one short human line goes out and the team is alerted.
 *
 * It runs after the gateway has its answer (shutdown), so a slow model never holds the line.
 */
final class Mutqan_WA_AI {

    const OPT = 'mutqan_wa_ai';

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'موظف الاستقبال']; }

    public static function settings() {
        $s = get_option(self::OPT, []);
        return array_merge(['enabled' => 1, 'budget' => 14, 'maxPerHour' => 30, 'tone' => 'سعودية بيضاء ودودة ومختصرة'], is_array($s) ? $s : []);
    }
    public static function enabled() { return !empty(self::settings()['enabled']); }

    /** The conversation so far, oldest first. */
    public static function history($tid, $n = 12) {
        $rows = Mutqan_Store::select('wa_msgs', ['threadId' => (string) $tid], ['order' => '-at', 'limit' => $n], self::sys());
        $rows = array_reverse($rows);
        $L = [];
        foreach ($rows as $m) {
            $t = trim((string) ($m['text'] ?? ''));
            if ($t === '') { $t = '[' . ((string) ($m['kind'] ?? 'رسالة')) . ']'; }
            $L[] = (($m['dir'] ?? '') === 'in' ? 'العميل' : 'الشركة') . ': ' . mb_substr($t, 0, 400);
        }
        return $L;
    }

    public static function prompt($th, $text, $card, $phone, $tid = '') {
        $s = self::settings();
        $brand = (string) ($card['brand'] ?? '');
        $biz = class_exists('Mutqan_Customers') ? Mutqan_Customers::business($brand !== '' ? $brand : 'oxygen') : 'أكسجين';
        $prof = class_exists('Mutqan_Customers') ? Mutqan_Customers::brief($phone) : '';
        $lead = (array) ($th['ai']['lead'] ?? []);
        $hist = self::history($tid !== '' ? $tid : (string) ($th['id'] ?? ''), 12);
        $src = (string) ($card['source']['title'] ?? '');
        $specs = class_exists('Mutqan_Rest') ? implode('، ', array_values(Mutqan_Rest::SPEC_LABELS)) : '';
        $P = "أنت موظف خدمة عملاء حقيقي في شركة «{$biz}» للصيانة والمقاولات في مكة المكرمة وجدة، بترد على واتساب.\n"
            . "اكتب بلهجة {$s['tone']}، رسالة أو اتنين قصيرين زي البني آدمين، من غير قوائم أرقام ومن غير ما تقول إنك بوت أو ذكاء اصطناعي.\n"
            . "## الشركة\nالخدمات: {$specs}.\nلا تخترع سعر أو موعد أو خصم غير اللي في معلومات الشركة؛ لو مش متأكد قول إن الموظف هيأكد.\n"
            . "## العميل\n" . ($prof !== '' ? $prof : 'عميل جديد') . ($src !== '' ? "\nجه من مقال/صفحة: «{$src}»" : '') . "\n"
            . "## اللي اتجمع من الطلب لحد دلوقتي\n" . ($lead ? wp_json_encode($lead, JSON_UNESCAPED_UNICODE) : 'لا شيء') . "\n"
            . "## المحادثة\n" . ($hist ? implode("\n", $hist) : '') . "\nالعميل (آخر رسالة): " . mb_substr((string) $text, 0, 600) . "\n\n"
            . "## المطلوب\n"
            . "- جاوب سؤاله بالظبط. لو عايز خدمة: اجمع منه بالتدريج (بسؤال واحد في كل رسالة) الخدمة المطلوبة ونوع وعدد الأجهزة، الحي/المدينة، والوقت. الاسم مش شرط.\n"
            . "- الوقت: " . (class_exists('Mutqan_Deal') ? 'لو ما حددش اكتب في lead.time «' . Mutqan_Deal::booking()['defaultTime'] . '».' : 'الافتراضي اليوم — أقرب وقت.') . "\n"
            . "- الأسعار من جدول الأسعار الرسمي بس، والفصال حسب تعليمات العقل التجاري (لو موجودة). سجّل السعر اللي اتفقتوا عليه في lead.price.\n"
            . "- أول ما يبقى عندك الخدمة والحي على الأقل، سجّل الطلب (complete=true) وقوله إن الطلب اتسجل وهيوصله التأكيد حالًا.\n"
            . "- شكوى، استرجاع فلوس، زعل شديد، تهديد، أو أي قرار مش بتاعك: handoff=true وقوله إن المسؤول هيكلمه حالًا.\n"
            . "- رد بالـJSON ده بس:\n"
            . '{"reply":"نص الرد","lead":{"service":"","area":"","time":"","name":"","note":"","price":""},"complete":false,"handoff":false,"handoff_reason":""}';
        return $P;
    }

    /** Same contract as the old rule bot: replies / patch / notify / lead. */
    public static function decide($th, $text, $card, $phone, $ts, $tid = '') {
        $out = ['replies' => [], 'patch' => [], 'notify' => null, 'lead' => null];
        $ai = (array) ($th['ai'] ?? []);
        $hs = (int) ($ai['hourStart'] ?? 0); $hc = (int) ($ai['hourCount'] ?? 0);
        if ($ts - $hs > 3600) { $hs = $ts; $hc = 0; }
        if ($hc >= (int) self::settings()['maxPerHour']) { $out['notify'] = 'محادثة كتير جدًا مع عميل واحد — البوت وقف يرد ساعة، راجعها بنفسك.'; return $out; }
        // A customer with an order on the go asking about it («الفني فين؟», «الغي الطلب», «أجّله»): the system acts on the
        // order and answers from its facts — no model needed, and nothing invented.
        if (class_exists('Mutqan_Deal')) {
            try { $sv = Mutqan_Deal::service($phone, (string) $text); } catch (Throwable $e) { $sv = null; error_log('[mutqan] wa service ' . $e->getMessage()); }
            if ($sv && ($sv['reply'] ?? '') !== '') {
                $out['replies'][] = $sv['reply'];
                $out['patch'] = ['ai' => array_merge($ai, ['hourStart' => $hs, 'hourCount' => $hc + 1, 'lastAt' => $ts, 'service' => $sv['intent']])];
                return $out;
            }
        }
        $r = null;
        if (class_exists('Mutqan_AI') && Mutqan_AI::any_enabled()) {
            try {
                $r = Mutqan_AI::run(self::prompt($th, $text, $card, $phone, $tid), ['json' => true, 'kb' => true, 'temperature' => 0.4, 'tier' => 'low', 'feature' => 'wa_reception', 'budget' => (int) self::settings()['budget'], 'sales' => ['phone' => $phone]], self::sys());
            } catch (Throwable $e) { error_log('[mutqan] wa ai: ' . $e->getMessage()); $r = null; }
        }
        $j = is_array($r['json'] ?? null) ? $r['json'] : null;
        $reply = trim((string) ($j['reply'] ?? ''));
        if ($reply === '' && $r && trim((string) ($r['text'] ?? '')) !== '' && strpos((string) $r['text'], '{') === false) { $reply = trim((string) $r['text']); }
        if ($reply === '') {
            // The model is not answering: a person-like holding line, never silence, never a menu — and a person is called.
            $said = class_exists('Mutqan_Lore') ? Mutqan_Lore::answer((string) $text) : '';
            if ($said === '' && class_exists('Mutqan_Brain')) { $said = Mutqan_Brain::fallback((string) $text); }
            $out['replies'][] = $said !== '' ? $said : 'أهلًا بيك 🌷 وصلتني رسالتك، ثواني ويرد عليك أحد الزملاء بنفسه.';
            $out['notify'] = 'الذكاء الاصطناعي ما ردش على عميل — رد عليه بنفسك: «' . mb_substr((string) $text, 0, 80) . '»';
            $out['patch'] = ['ai' => array_merge($ai, ['hourStart' => $hs, 'hourCount' => $hc + 1, 'lastAt' => $ts, 'down' => true])];
            return $out;
        }
        $out['replies'][] = mb_substr($reply, 0, 1200);
        $lead = array_filter(array_map(function ($v) { return trim(mb_substr((string) $v, 0, 160)); }, (array) ($j['lead'] ?? [])), 'strlen');
        $lead = array_merge((array) ($ai['lead'] ?? []), $lead);
        $done = !empty($j['complete']) && trim((string) ($lead['service'] ?? '')) !== '';
        if ($done) {
            $out['lead'] = ['service' => $lead['service'], 'area' => (string) ($lead['area'] ?? ''), 'name' => (string) ($lead['name'] ?? ''), 'note' => trim((string) ($lead['note'] ?? '')), 'time' => (string) ($lead['time'] ?? '') ?: (class_exists('Mutqan_Deal') ? Mutqan_Deal::booking()['defaultTime'] : ''), 'price' => (string) ($lead['price'] ?? '')];
            $lead = [];
        }
        if (!empty($j['handoff'])) {
            $out['notify'] = '🙋 العميل محتاج موظف: ' . mb_substr((string) ($j['handoff_reason'] ?? '') ?: (string) $text, 0, 140);
            $bot = (array) ($th['bot'] ?? []); $bot['pausedUntil'] = $ts + 2 * 3600;
            $out['patch']['bot'] = $bot;
        }
        $out['patch']['ai'] = ['hourStart' => $hs, 'hourCount' => $hc + 1, 'lastAt' => $ts, 'lead' => $lead, 'provider' => (string) ($r['provider'] ?? '')];
        return $out;
    }
}
