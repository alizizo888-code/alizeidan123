<?php
/**
 * What the order is, in one place (owner, 2026-11-21): wash, repair, install or move/remove — and which A/C, how many
 * of each: split, window, duct, concealed, package, central, standing, cassette. Every message, card, room post and
 * the map read the label from here, so no order is ever called just «صيانة» again.
 *
 * Sources, strongest first: the mode the WhatsApp menu chose (svcMode), the customer's own words (service,
 * serviceDetails, description, note), then the price quote (acQuote). When the words and the quote disagree
 * («صيانة 2 شباك» priced as a wash) the words win and the order carries a warning for operations.
 */
if (!defined('ABSPATH')) { exit; }

final class Mutqan_Service {
    const MODES = ['wash' => 'غسيل', 'repair' => 'صيانة وإصلاح', 'install' => 'تركيب', 'move' => 'فك وتركيب', 'remove' => 'فك', 'check' => 'كشف وفحص'];
    const TYPES = ['split' => 'سبليت', 'window' => 'شباك', 'duct' => 'داكت', 'concealed' => 'كونسيلد', 'package' => 'باكيدج',
        'central' => 'مركزي', 'standing' => 'دولابي', 'cassette' => 'كاسيت'];

    /** Orders saved before this reading get it once, in the background (300 per step). */
    public static function boot() {
        add_action('mutqan_service_fill', [__CLASS__, 'fill_step']);
        add_action('init', function () {
            if (get_option('mutqan_service_filled') || wp_next_scheduled('mutqan_service_fill')) { return; }
            wp_schedule_single_event(time() + 15, 'mutqan_service_fill');
        }, 31);
    }
    public static function fill_step() {
        if (get_option('mutqan_service_filled') || get_transient('mutqan_service_lock')) { return; }
        set_transient('mutqan_service_lock', 1, 300);
        global $wpdb; $t = Mutqan_Store::table('docs');
        $off = (int) get_option('mutqan_service_offset', 0);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT pk, data FROM $t WHERE coll = 'orders' ORDER BY pk ASC LIMIT 300 OFFSET %d", $off), ARRAY_A);
        foreach ((array) $rows as $r) {
            $d = json_decode($r['data'], true); if (!is_array($d)) { continue; }
            $n = self::stamp($d);
            if ($n !== $d) { $wpdb->update($t, ['data' => wp_json_encode($n, JSON_UNESCAPED_UNICODE)], ['pk' => $r['pk']]); }
        }
        if (count((array) $rows) < 300) { update_option('mutqan_service_filled', gmdate('c'), false); delete_option('mutqan_service_offset'); }
        else { update_option('mutqan_service_offset', $off + 300, false); wp_schedule_single_event(time() + 10, 'mutqan_service_fill'); }
        delete_transient('mutqan_service_lock');
    }

    /** Word → type. Order matters: «سبليت دولابي» is a standing unit, «مخفي داكت» a duct. */
    private static function type_rx() {
        return [
            'duct' => '(داكت|دكت|دكّت|دكتات|داكتات|duct)',
            'concealed' => '(كونسيلد|كونسلد|كونسيل|كونسيلت|كونسيلدات|مخفي|مخفى|concealed)',
            'package' => '(باكيدج|باكدج|باكج|بكج|بكيج|باكيج|package)',
            'central' => '(مركزي|مركزى|تشيلر|شيلر|chiller|central)',
            'standing' => '(دولابي|دولابى|فريستاند|فري\s?ستاند|عمودي)',
            'cassette' => '(كاسيت|كاسيتات|سقفي|سقفى|cassette)',
            'window' => '(شباك|شبّاك|شبابيك|window)',
            'split' => '(سبليت|اسبليت|سبلت|اسبلت|سبيلت|اسبيلت|split)',
        ];
    }
    private static function mode_rx() {
        return [
            'wash' => '(غسيل|غسل|اغسل|يغسل|تغسيل|تنظيف|نظافه|نظافة)',
            'remove' => '(فك\s*(فقط|بس)|فك\s+مكيف(?!.*تركيب))',
            'move' => '(فك\s*و\s*تركيب|فك\s*وتركيب|نقل|انقل|تحويل\s*مكان)',
            'install' => '(تركيب|ركب|يركب|تركيبه|تركيبة)',
            'repair' => '((مش|ما|مو|مب|ماهو)\s*(بي|ي)?برد|(بي|ي)?بردش|مش\s*شغال|واقف|وقف|بيطفي|يطفي|عطل|عطلان|خربان|خربانه|ما\s*يبرد|مايبرد|لا\s*يبرد|ميبردش|مبيبردش|بيبرد\s*ضعيف|تبريد\s*ضعيف|تسريب|يسرب|ينقط|يقطر|بيقطر|فريون|شحن|صوت|ريحه|ريحة|كمبروسر|كمبريسور|كباستور|كبستور|مكثف|ما\s*يشتغل|مايشتغل|ميشتغلش|يفصل|بيفصل|فاصل|بورده|بورد|شريحه|شريحة|كونتاكتور|ترموستات|ثلج|تلج|كود\s*[a-z]?\d|e\d|اصلاح|إصلاح|تصليح)',
            'check' => '(كشف|فحص|معاينه|معاينة)',
        ];
    }

    private static function norm($s) {
        $s = mb_strtolower((string) $s);
        $s = str_replace(['أ', 'إ', 'آ', 'ة', 'ى', 'ـ'], ['ا', 'ا', 'ا', 'ه', 'ي', ''], $s);
        $s = strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        return preg_replace('/\s+/u', ' ', $s);
    }

    /** Each type named in the text with its count: «2 سبليت وشباك» → split 2, window 1. «3 مكيفات» → any 3. */
    public static function units($text) {
        $n = self::norm($text);
        $words = ['واحد' => 1, 'واحده' => 1, 'وحده' => 1, 'احد' => 1, 'اتنين' => 2, 'اثنين' => 2, 'ثنين' => 2, 'تنين' => 2, 'تلاته' => 3, 'ثلاثه' => 3, 'تلات' => 3, 'ثلاث' => 3,
            'اربعه' => 4, 'اربع' => 4, 'خمسه' => 5, 'خمس' => 5, 'سته' => 6, 'ست' => 6, 'سبعه' => 7, 'تمانيه' => 8, 'ثمانيه' => 8, 'تسعه' => 9, 'عشره' => 10];
        $tok = preg_split('/[\s،,+\/\-]+/u', trim($n));
        $out = []; $rx = self::type_rx(); $any = 0;
        foreach ($tok as $i => $w) {
            $k = '';
            foreach ($rx as $t => $r) { if (preg_match('/' . $r . '/u', $w)) { $k = $t; break; } }
            if ($k === '') {
                if (preg_match('/^(مكيفين|جهازين)$/u', $w)) { $any = max($any, 2); }
                if (preg_match('/^(مكيف|مكيفات|جهاز|اجهزه|وحدات|وحده)$/u', $w)) {
                    $q = self::qty_before($tok, $i, $words); if ($q) { $any = max($any, $q); }
                }
                continue;
            }
            // «سبليت دولابي»: one unit described twice — the second word wins, the first is not counted.
            if ($i > 0 && $k === 'standing' && isset($out['split']) && preg_match('/' . $rx['split'] . '/u', $tok[$i - 1])) { $out['standing'] = ($out['standing'] ?? 0) + $out['split']; unset($out['split']); continue; }
            $q = self::qty_before($tok, $i, $words);
            // «كاسيت عدد 4», «سبليت ×3», «شباك 2»
            if (!$q) { for ($j = $i + 1; $j <= min(count($tok) - 1, $i + 2); $j++) { $t = $tok[$j]; if (preg_match('/^(\d{1,2})$/', $t)) { $q = (int) $t; break; } if (isset($words[$t])) { $q = $words[$t]; break; } if (!preg_match('/^(عدد|×|x|مكيف|مكيفات|جهاز)$/u', $t)) { break; } } }
            $out[$k] = ($out[$k] ?? 0) + ($q ?: 1);
        }
        if (!$out && $any) { $out['any'] = $any; }
        return $out;
    }
    private static function qty_before($tok, $i, $words) {
        for ($j = $i - 1; $j >= max(0, $i - 3); $j--) {
            $t = preg_replace('/^و(?=\d)/u', '', $tok[$j]);
            if (preg_match('/^\d{1,2}$/', $t)) { return (int) $t; }
            $b = preg_replace('/^و/u', '', $t);
            if (isset($words[$t])) { return $words[$t]; }
            if (isset($words[$b])) { return $words[$b]; }
            if (preg_match('/^(مكيفين|جهازين)$/u', $t)) { return 2; }
            if (!preg_match('/^(و|مكيف|مكيفات|جهاز|اجهزه|وحده|نوع|غسيل|تركيب|صيانه|فك|عدد|طن)$/u', $t)) { return 0; }
        }
        return 0;
    }

    /** Every mode the text names. «صيانة» alone is weak: it counts only when nothing more precise is said. */
    public static function modes($text) {
        $n = self::norm($text);
        $n = preg_replace('/(صيانه وتكييف|صيانه وتكيف|الصيانه العامه|للصيانه والمقاولات|صيانه عامه|(يسعر|يتسعر|بيتحدد|السعر)?\s*بعد\s*(الفحص|الكشف|المعاينه))/u', ' ', $n);
        $found = [];
        foreach (self::mode_rx() as $m => $r) { if (preg_match('/' . $r . '/u', $n)) { $found[] = $m; } }
        if (in_array('move', $found, true) || in_array('remove', $found, true)) { $found = array_values(array_diff($found, ['install'])); }
        if (in_array('move', $found, true)) { $found = array_values(array_diff($found, ['remove'])); }
        if (!$found && preg_match('/صيان/u', $n)) { $found[] = 'repair'; }
        return $found;
    }

    /** The words of the order, without the generic specialty names. */
    private static function text_of($o) {
        $parts = [];
        foreach (['service', 'serviceDetails', 'description', 'problem', 'note'] as $k) { if (!empty($o[$k]) && is_string($o[$k])) { $parts[] = $o[$k]; } }
        foreach ((array) ($o['items'] ?? []) as $it) { if (is_array($it)) { $parts[] = (string) ($it['name'] ?? $it['label'] ?? ''); } elseif (is_string($it)) { $parts[] = $it; } }
        // the operations warnings we add ourselves are not the customer's words
        return preg_replace('/⚠️[^\n]*/u', ' ', implode(' ، ', array_unique(array_filter($parts))));
    }

    /** The whole reading: mode(s), units per type, the label, and a warning when the quote says something else. */
    public static function read($o) {
        $o = is_array($o) ? $o : [];
        $spec = (string) ($o['specialtyId'] ?? '');
        $text = self::text_of($o);
        $aq = is_array($o['acQuote'] ?? null) ? $o['acQuote'] : [];
        $qMode = ['wash' => 'wash', 'install' => 'install', 'maintenance' => 'repair', 'repair' => 'repair', 'move' => 'move', 'remove' => 'remove'][(string) ($aq['mode'] ?? '')] ?? '';
        $menu = (string) ($o['svcMode'] ?? '');
        $modes = self::modes($text);
        if ($menu !== '' && isset(self::MODES[$menu])) { $modes = array_values(array_unique(array_merge([$menu], array_diff($modes, ['repair']) === $modes ? $modes : array_diff($modes, [$menu])))); }
        $warn = '';
        if (!$modes && $qMode) { $modes = [$qMode]; }
        elseif ($qMode && !in_array($qMode, $modes, true) && !empty($aq['total'])) {
            $warn = '⚠️ السعر اتحسب على «' . self::MODES[$qMode] . '» والعميل طالب «' . self::MODES[$modes[0]] . '» — راجع السعر.';
        }
        // units: the words first, then the quote's type/qty
        $units = self::units($text);
        $qt = self::units((string) ($aq['type'] ?? ''));
        if (!$units && !empty($aq['items']) && is_array($aq['items'])) {
            foreach ($aq['items'] as $it) { $u = self::units((string) ($it['type'] ?? '')); $k = $u ? array_key_first($u) : 'any'; $units[$k] = ($units[$k] ?? 0) + max(1, (int) ($it['qty'] ?? 1)); }
        }
        if (!$units && $qt) { $q = max(1, (int) ($aq['qty'] ?? 1)); $units = count($qt) === 1 ? [array_key_first($qt) => $q] : $qt; }
        if (!$units && !empty($aq['qty']) && (int) $aq['qty'] > 0) { $units = ['any' => (int) $aq['qty']]; }
        foreach (['split', 'window', 'other'] as $k) { if (!$units && !empty($aq[$k])) { $units[$k === 'other' ? 'any' : $k] = (int) $aq[$k]; } }
        if (!empty($o['acType']) && isset(self::TYPES[$o['acType']]) && (!$units || isset($units['any']))) { $q = (int) ($units['any'] ?? ($o['qty'] ?? 1)); $units = [(string) $o['acType'] => max(1, $q)]; }
        if (isset($units['any']) && count($units) === 1 && !empty($o['qty']) && (int) $o['qty'] > $units['any']) { $units['any'] = (int) $o['qty']; }
        $isAc = $spec === 'ac' || ($spec === '' && ($units || preg_match('/(مكيف|تكييف|تكيف)/u', self::norm($text))));
        $label = self::label_of($isAc, $spec, $modes, $units, $text);
        return ['ac' => $isAc, 'modes' => $modes, 'mode' => $modes[0] ?? '', 'units' => $units, 'label' => $label, 'warn' => $warn,
            'unitsText' => self::units_text($units), 'count' => array_sum($units)];
    }

    public static function units_text($units) {
        $p = [];
        foreach ((array) $units as $t => $q) { $p[] = $t === 'any' ? $q . ' مكيف (النوع لسه مش محدد)' : $q . ' ' . (self::TYPES[$t] ?? $t); }
        return implode(' + ', $p);
    }

    private static function label_of($isAc, $spec, $modes, $units, $text) {
        $mode = implode(' + ', array_map(function ($m) { return self::MODES[$m]; }, $modes));
        if ($isAc) {
            $u = self::units_text($units);
            $head = $mode !== '' ? $mode . ' ' . (array_sum($units) > 1 ? 'مكيفات' : 'مكيف') : 'مكيفات (الخدمة لسه مش محددة)';
            return $u !== '' ? $head . ' — ' . $u : $head . ' — النوع لسه مش محدد';
        }
        $sl = class_exists('Mutqan_Rest') && $spec !== '' ? (string) Mutqan_Rest::spec_label($spec) : '';
        $first = trim(mb_substr(preg_replace('/\s+/u', ' ', (string) $text), 0, 60));
        if ($sl !== '' && $first !== '') { return $sl . ' — ' . $first; }
        return $sl !== '' ? $sl : ($first !== '' ? $first : 'طلب خدمة');
    }

    /** The label alone (what every message, card and list shows). */
    public static function label($o) {
        if (!empty($o['serviceLabelLocked']) && !empty($o['serviceLabel'])) { return (string) $o['serviceLabel']; }
        // a label someone wrote on an order this reading never saw (not stamped yet) is kept as written
        if (!isset($o['svc']) && trim((string) ($o['serviceLabel'] ?? '')) !== '' && !preg_match('/^(صيانه|صيانة|صيانة وتكييف)$/u', trim((string) $o['serviceLabel']))) { return (string) $o['serviceLabel']; }
        return self::read($o)['label'];
    }

    /** Written onto the order on every save: serviceLabel, svc {mode, modes, units}, and the warning as a note line. */
    public static function stamp($o) {
        if (!is_array($o)) { return $o; }
        $r = self::read($o);
        if (empty($o['serviceLabelLocked'])) { $o['serviceLabel'] = $r['label']; }
        $o['svc'] = ['mode' => $r['mode'], 'modes' => $r['modes'], 'units' => (object) $r['units'], 'count' => $r['count'], 'ac' => $r['ac']];
        if ($r['warn'] !== '' && mb_strpos((string) ($o['note'] ?? ''), $r['warn']) === false) { $o['note'] = trim((string) ($o['note'] ?? '') . "\n" . $r['warn']); }
        return $o;
    }

    /** The full order, line by line — the confirmation to the customer, the room post and the technician's card. */
    public static function lines($o, $forCustomer = false) {
        $r = self::read($o);
        $L = ['🛠 الخدمة: ' . self::label($o)];
        $desc = trim((string) ($o['description'] ?? '') ?: (string) ($o['problem'] ?? ''));
        $svc = trim((string) ($o['service'] ?? ''));
        // the customer's own words about the problem, when they say more than the label
        $said = $desc !== '' ? $desc : (mb_strpos($svc, ' — ') !== false ? trim(explode(' — ', $svc, 2)[1]) : '');
        if ($said !== '' && mb_strpos(self::label($o), $said) === false) { $L[] = '📝 التفاصيل: ' . mb_substr($said, 0, 200); }
        if (!empty($o['address'])) { $L[] = '🏠 العنوان: ' . mb_substr((string) $o['address'], 0, 140); }
        if (is_numeric($o['lat'] ?? null)) { $L[] = '📍 اللوكيشن: https://maps.google.com/?q=' . $o['lat'] . ',' . $o['lng']; }
        if (!empty($o['appointmentAt']) && class_exists('Mutqan_Sales')) { $L[] = '📅 الموعد: ' . Mutqan_Sales::slot_label(strtotime((string) $o['appointmentAt'])); }
        elseif (!empty($o['preferredTime'])) { $L[] = '📅 الموعد: ' . mb_substr((string) $o['preferredTime'], 0, 80); }
        $price = $o['agreedPrice'] ?? ($o['plan']['agreedPrice'] ?? ($o['acQuote']['total'] ?? null));
        if (is_numeric($price) && $price > 0) { $L[] = '💰 السعر: ' . $price . ' ر.س'; }
        elseif ($r['ac'] && in_array($r['mode'], ['repair', 'check', 'install', 'move', 'remove'], true)) { $L[] = '💰 السعر: بيتحدد بعد الكشف وقبل أي شغل'; }
        if (!$forCustomer && $r['warn'] !== '') { $L[] = $r['warn']; }
        return $L;
    }
}
