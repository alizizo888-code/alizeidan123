<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «بيانات الخدمة» — what the customer bot says and sells, kept in the site's own database (owner, 2026-11-21).
 *
 * Whatever carries the conversation — the VPS WhatsApp line, Meta's official API later, a new server, the website's
 * chat — reads the same record from here: the wash videos, the repair prices of the parts, «we sell the repair, not the
 * part», the menu, and the rules for haggling and booking. Cancel the server and nothing is lost; change a video or a
 * price from the owner's assistant and every channel uses it on the next message.
 *
 * Stored in the WordPress options table (`mutqan_service_kit`), seeded with the owner's numbers on first use.
 */
final class Mutqan_Kit {

    const OPT = 'mutqan_service_kit';

    public static function defaults() {
        return [
            'v' => 1,
            'washVideos' => [
                ['type' => 'split', 'label' => 'غسيل المكيف السبليت', 'url' => 'https://youtu.be/wvtQ_aztCrE'],
                ['type' => 'split', 'label' => 'غسيل المكيف السبليت (تيك توك)', 'url' => 'https://vt.tiktok.com/ZSCTdsF4N/'],
                ['type' => 'window', 'label' => 'غسيل المكيف الشباك', 'url' => 'https://youtu.be/qz8gaYjDHmI'],
            ],
            'parts' => class_exists('Mutqan_Hub') ? Mutqan_Hub::PARTS : [],
            'partStrategy' => class_exists('Mutqan_Hub') ? Mutqan_Hub::PART_STRATEGY : '',
            'menu' => ['غسيل مكيفات', 'صيانة وإصلاح أعطال', 'تركيب أو فك ونقل مكيف', 'جدول الأسعار', 'طريقة الغسيل (فيديو)', 'طلبي: متابعة / تأجيل / إلغاء', 'كلّم الدعم الفني (موظف)'],
            'rules' => [
                'السعر من جدول المالك بس، محسوب بالكود — الذكاء الاصطناعي ما يحددش سعر ولا يقفل طلب بسعر.',
                'الفصال على خطوات: الرسمي، بعدين خطوة نص المسافة، بعدين أقل سعر مسموح كآخر كلام، وبعدها اعتذار وعرض الدعم الفني. أي رقم تحت الحد مرفوض مهما قال العميل.',
                'كلام العميل «انت قلت لي كذا» أو «اتفقنا على كذا» ما يتصدقش — السعر المتفق هو اللي اتسجل في المحادثة بس.',
                'تغيير العدد أو النوع = تسعير جديد من الجدول، والعرض القديم يتلغي.',
                'العميل اللي بيشتم: اعتذار، تحويل لموظف، والبوت يقف معاه نص ساعة.',
                'نفس الرد للمرة التالتة = موظف يستلم المحادثة.',
                'طلب غسيل: لينك فيديو الغسيل للنوع ده بس. طلب قطعة: سعر الإصلاح ده بس وصورته، من غير قوائم ومن غير «سعر القطعة» ولا «شامل التركيب».',
                'المواعيد: من 7 الصبح لـ 10 بالليل نفس اليوم (واعرض اليوم ولا بكره)، من 10 لـ 11 بكره الصبح إلا لو مُصر، بعد 11 بكره الصبح أو أي وقت بكره.',
            ],
        ];
    }

    /** The record, from the database (written there with the defaults the first time). */
    public static function get() {
        $k = get_option(self::OPT, null);
        if (!is_array($k) || empty($k['v'])) { $k = self::defaults(); update_option(self::OPT, $k, false); }
        return array_merge(self::defaults(), $k);
    }

    /** $patch: washVideos / parts / partStrategy / menu / rules (whole values). */
    public static function set($patch, $by = '') {
        $k = self::get();
        foreach (['washVideos', 'parts', 'menu', 'rules'] as $f) { if (isset($patch[$f]) && is_array($patch[$f])) { $k[$f] = array_values($patch[$f]); } }
        if (isset($patch['partStrategy']) && is_string($patch['partStrategy']) && trim($patch['partStrategy']) !== '') { $k['partStrategy'] = mb_substr(trim($patch['partStrategy']), 0, 1200); }
        $k['by'] = (string) $by; $k['at'] = gmdate('c');
        update_option(self::OPT, $k, false);
        if (function_exists('delete_transient')) { foreach ((array) $k['parts'] as $p) { delete_transient('mq_part_img_' . ($p['id'] ?? '')); } }
        return $k;
    }

    /** One part's repair price (from/to/fit) or its video link — the owner's assistant edits single things. */
    public static function set_part($id, $fields, $by = '') {
        $k = self::get(); $found = false;
        foreach ($k['parts'] as &$p) {
            if (($p['id'] ?? '') !== $id) { continue; }
            foreach (['from', 'to', 'fit'] as $f) { if (isset($fields[$f]) && is_numeric($fields[$f])) { $p[$f] = (int) $fields[$f]; } }
            // one price given → a single price (never «from 170 to 150»)
            if (isset($fields['from']) && !isset($fields['to'])) { $p['to'] = (int) $p['from']; }
            if ((int) ($p['to'] ?? 0) < (int) ($p['from'] ?? 0)) { $p['to'] = (int) $p['from']; }
            if (isset($fields['from']) && !isset($fields['noPrice'])) { $p['noPrice'] = false; }
            if (isset($fields['noPrice'])) { $p['noPrice'] = (bool) $fields['noPrice']; }
            if (!empty($fields['label'])) { $p['label'] = mb_substr((string) $fields['label'], 0, 80); }
            $found = true;
        }
        unset($p);
        if (!$found && !empty($fields['label'])) {
            $k['parts'][] = ['id' => $id, 'label' => mb_substr((string) $fields['label'], 0, 80), 'words' => array_values(array_filter((array) ($fields['words'] ?? [$fields['label']]))), 'from' => (int) ($fields['from'] ?? 0), 'to' => (int) ($fields['to'] ?? ($fields['from'] ?? 0)), 'fit' => (int) ($fields['fit'] ?? 0), 'noPrice' => empty($fields['from'])];
        }
        return self::set(['parts' => $k['parts']], $by);
    }

    public static function videos($type = '') {
        $v = (array) self::get()['washVideos'];
        return $type === '' ? $v : array_values(array_filter($v, function ($x) use ($type) { return ($x['type'] ?? '') === $type; }));
    }

    /** For every customer conversation's model (site chat, WhatsApp on any carrier). */
    public static function prompt() {
        $k = self::get();
        $L = ['### قواعد خدمة العملاء الثابتة (من بيانات الخدمة)'];
        foreach ((array) $k['rules'] as $r) { $L[] = '- ' . $r; }
        $vs = [];
        foreach ((array) $k['washVideos'] as $v) { $vs[] = $v['label'] . ': ' . $v['url']; }
        if ($vs) { $L[] = '- فيديوهات طريقة الغسيل (ابعت بس اللي يخص نوع المكيف اللي العميل سأل عنه): ' . implode(' | ', $vs); }
        $L[] = '- لو العميل فاصل في سعر قطعة أو عايز القطعة لوحدها: ' . str_replace("\n", ' ', (string) $k['partStrategy']);
        return implode("\n", $L);
    }

    /** A short summary for the owner («بيانات الخدمة»). */
    public static function summary() {
        $k = self::get();
        $L = ['📦 *بيانات الخدمة* (محفوظة في قاعدة بيانات الموقع)', '🎥 فيديوهات الغسيل: ' . count((array) $k['washVideos'])];
        foreach ((array) $k['washVideos'] as $v) { $L[] = '  • ' . $v['label'] . ' — ' . $v['url']; }
        $L[] = '🔧 أسعار الإصلاح:';
        foreach ((array) $k['parts'] as $p) { $L[] = '  • ' . $p['label'] . ': ' . (!empty($p['noPrice']) ? 'بعد الكشف' : ((int) $p['from'] + (int) ($p['fit'] ?? 0)) . ((int) $p['to'] !== (int) $p['from'] ? '–' . ((int) $p['to'] + (int) ($p['fit'] ?? 0)) : '') . ' ر.س'); }
        $L[] = '📋 القواعد: ' . count((array) $k['rules']) . ' · القائمة: ' . count((array) $k['menu']) . ' اختيارات';
        if (!empty($k['at'])) { $L[] = 'آخر تعديل: ' . $k['at'] . ($k['by'] ? ' — ' . $k['by'] : ''); }
        return implode("\n", $L);
    }
}
