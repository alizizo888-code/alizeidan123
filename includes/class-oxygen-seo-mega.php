<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Keyword universe for local SEO articles — generated lazily (never stored as a giant array):
 *   entity (neighbourhood / named place / brand in Makkah or Jeddah, Makkah first)  x  service  x  attention-grabbing title template.
 * An item is a pure function of its integer index, so a post can always be rebuilt from its key, and a cursor walks the universe.
 * Titles never contain prices: they sell reputation (leading company, expert engineers, discounts) in a grand tone.
 */
class Oxygen_SEO_Mega {
    private static $c = [];

    /** Short service names that read well inside a title. */
    public static function short($sid) {
        static $m = ['ac-wash' => 'غسيل المكيفات', 'ac-maint' => 'صيانة المكيفات', 'ac-install' => 'تركيب المكيفات', 'ac-central' => 'التكييف المركزي', 'plumbing' => 'السباكة', 'electric' => 'الكهرباء',
            'fridge' => 'صيانة الثلاجات', 'appliances' => 'صيانة الأجهزة المنزلية', 'pest' => 'مكافحة الحشرات', 'tanks' => 'تنظيف خزانات المياه', 'locks' => 'الأقفال والمفاتيح', 'cameras' => 'كاميرات المراقبة',
            'satellite' => 'تركيب الدش', 'paint' => 'الدهانات والديكور', 'tiling' => 'التبليط والعزل', 'cleaning' => 'التنظيف والتعقيم', 'moving' => 'نقل الأثاث', 'solar' => 'الطاقة الشمسية',
            'corporate' => 'صيانة المنشآت', 'general' => 'الصيانة العامة', 'contracting' => 'المقاولات العامة'];
        return $m[$sid] ?? $sid;
    }
    /** How a service reads next to a brand name ("صيانة ثلاجات LG"). */
    private static function brand_phrase($sid, $b) {
        static $m = ['ac-wash' => 'غسيل مكيفات {b}', 'ac-maint' => 'صيانة مكيفات {b}', 'ac-install' => 'تركيب مكيفات {b}', 'ac-central' => 'صيانة تكييف {b} المركزي', 'plumbing' => 'صيانة سخانات ومضخات {b}',
            'fridge' => 'صيانة ثلاجات {b}', 'appliances' => 'صيانة أجهزة {b}', 'cameras' => 'تركيب كاميرات {b}', 'locks' => 'تركيب أقفال {b}', 'solar' => 'تركيب أنظمة {b} للطاقة الشمسية',
            'satellite' => 'تركيب أجهزة {b}', 'paint' => 'دهانات {b}', 'tiling' => 'تركيب مواد {b}'];
        return str_replace('{b}', $b, $m[$sid] ?? ('صيانة {b}'));
    }

    /** 20 grand, price-free title styles. {n} = service (or brand service), {P} = place phrase. [title, keyword] */
    public static function templates() {
        static $all = null; if ($all !== null) { return $all; }
        $legacy = [
            ['أفضل شركة {n} في {P} | خبراء مهندسون مختصون', 'أفضل شركة {n} {P}'],
            ['الشركة الرائدة في {n} بـ{P} — مهندسون وخصومات', 'شركة رائدة {n} {P}'],
            ['خصومات حصرية على {n} في {P} مع نخبة الخبراء', 'خصومات {n} {P}'],
            ['خبراء {n} في {P}: مهندسون مختصون بأعلى مستوى', 'خبراء {n} {P}'],
            ['نخبة مهندسي {n} في {P} — جودة تليق بك', 'مهندس {n} {P}'],
            ['أفضل خدمة {n} في {P}: ريادة وإتقان وخصومات', 'أفضل خدمة {n} {P}'],
            ['رواد {n} في {P} — مختصون وخصومات لعملائنا', 'رواد {n} {P}'],
            ['{n} في {P} بإشراف مهندسين خبراء | خصومات مميزة', '{n} بإشراف مهندسين {P}'],
            ['الأولى في {n} بـ{P}: فريق مهندسين مختصين', 'أول شركة {n} {P}'],
            ['شركة {n} الأفضل في {P} — إتقان وخبرة وخصومات', 'شركة {n} الأفضل {P}'],
            ['احجز أفضل مهندس {n} في {P} الآن بخصم مميز', 'احجز مهندس {n} {P}'],
            ['تميّز لا يضاهى في {n} بـ{P} | خبراء مختصون', 'تميز {n} {P}'],
            ['{n} في {P} على أيدي أمهر المهندسين المختصين', 'أمهر مهندسين {n} {P}'],
            ['سر أفضل شركة {n} في {P}: خبراء وخدمة فاخرة', 'خدمة {n} فاخرة {P}'],
            ['ريادة وإتقان في {n} بـ{P} — عروض وخصومات', 'عروض {n} {P}'],
            ['مهندسون خبراء في {n} بـ{P}: الخيار الأول', 'الخيار الأول {n} {P}'],
            ['عرض خاص: {n} في {P} بخصم من الشركة الرائدة', 'عرض {n} {P}'],
            ['الشركة المتخصصة الأولى في {n} بـ{P}', 'شركة متخصصة {n} {P}'],
            ['أقوى خصومات {n} في {P} من الرواد', 'أقوى خصومات {n} {P}'],
            ['لماذا نحن الأفضل في {n} بـ{P}؟ خبراء مهندسون', 'لماذا الأفضل {n} {P}'],
        ];
        return $all = array_merge($legacy, self::commercial());
    }

    /**
     * Search-intent titles that make the reader call or order ("أفضل شركة غسيل مكيفات"، "فني اصلاح تكيفات"، "معلم مكيفات"...).
     * {v} = one of the real search wordings of the service (see variants()). Appended after the 20 legacy styles, so old keys keep working;
     * 48 of the 68 styles are commercial, which is what makes ~70% of new articles buy-intent and the rest the older, broader style.
     */
    public static function commercial() {
        static $c = null; if ($c !== null) { return $c; }
        $roles = [
            ['أفضل شركة {v} في {P} تصلك فوراً', 'أفضل شركة {v} {P} اتصل'],
            ['أفضل فني {v} في {P}', 'أفضل فني {v} {P}'],
            ['مهندس {v} في {P} يعاين ويصلح', 'اتصل بمهندس {v} {P}'],
            ['معلم {v} في {P} يصلك فوراً', 'معلم {v} {P}'],
            ['فني {v} في {P} جاهز للزيارة', 'فني {v} {P}'],
            ['رقم أفضل شركة {v} في {P}', 'رقم شركة {v} {P}'],
            ['شركة {v} قريبة منك في {P}', 'شركة {v} قريبة {P}'],
            ['احجز فني {v} في {P} الآن', 'حجز فني {v} {P}'],
            ['أرقام فنيين {v} في {P} للتواصل والحجز', 'أرقام فنيين {v} {P}'],
            ['خدمة {v} في {P} على مدار الساعة', '{v} {P} 24 ساعة'],
            ['هل تبحث عن فني {v} في {P}؟ اتصل بنا', 'ابحث عن فني {v} {P}'],
            ['اطلب أفضل شركة {v} في {P} بخطوة واحدة', 'اطلب شركة {v} {P}'],
            ['أسرع فني {v} في {P} للمنازل والمحلات', 'أسرع فني {v} {P}'],
            ['شركة {v} المعتمدة في {P} بضمان على العمل', 'شركة {v} معتمدة {P}'],
            ['مكتب {v} في {P} بفنيين جاهزين للزيارة', 'مكتب {v} {P}'],
            ['تواصل مع أفضل معلم {v} في {P}', 'أفضل معلم {v} {P}'],
        ];
        // three wordings of each role; the tails carry no separator, so Oxygen_SEO_Mega::compact() can never collapse two of them into one title
        $tail = [['', ''], [' واطلب الآن', ' الآن'], [' وزيارة اليوم', ' عاجل']];
        $c = [];
        foreach ($tail as $t) { foreach ($roles as $r) { $c[] = [$r[0] . $t[0], $r[1] . $t[1]]; } }
        return $c;
    }

    /** Real search wordings of each service (what customers type), used by the commercial titles. */
    public static function variants($sid) {
        static $m = [
            'ac-wash' => ['غسيل مكيفات', 'غسيل تكييف', 'تنظيف مكيفات', 'غسيل مكيفات سبليت', 'غسيل مكيف شباك'],
            'ac-maint' => ['صيانة مكيفات', 'اصلاح تكيفات', 'تصليح مكيفات', 'صيانة تكييف', 'اصلاح مكيفات', 'شحن فريون مكيفات'],
            'ac-install' => ['تركيب مكيفات', 'تركيب سبليت', 'فك وتركيب مكيفات', 'نقل وتركيب مكيفات'],
            'ac-central' => ['صيانة تكييف مركزي', 'صيانة مكيفات دكت', 'صيانة التكييف المركزي', 'صيانة شيلر'],
            'fridge' => ['صيانة ثلاجات', 'تصليح ثلاجات', 'صيانة فريزر', 'صيانة غرف التبريد', 'صيانة غرف التجميد', 'صيانة غرف التبريد والتجميد'],
            'cold-room' => ['صيانة غرف التبريد', 'صيانة غرف التبريد والتجميد', 'غرف تبريد'],
            'freezer-room' => ['صيانة غرف التجميد', 'صيانة غرف التبريد والتجميد', 'غرف تجميد'],
        ];
        static $o = ['plumbing' => ['سباكة', 'كشف تسربات المياه'], 'electric' => ['كهرباء', 'صيانة كهرباء'], 'appliances' => ['صيانة أجهزة منزلية', 'صيانة غسالات'], 'pest' => ['مكافحة حشرات', 'مكافحة صراصير ونمل'],
            'tanks' => ['تنظيف خزانات', 'تنظيف وعزل خزانات المياه'], 'locks' => ['أقفال ومفاتيح', 'فتح وتركيب أقفال'], 'cameras' => ['كاميرات مراقبة', 'تركيب كاميرات مراقبة'], 'satellite' => ['تركيب دش', 'ضبط وتركيب ستلايت'],
            'paint' => ['دهانات وديكور', 'دهانات'], 'tiling' => ['تبليط وعزل', 'ترميم وتبليط'], 'cleaning' => ['تنظيف وتعقيم', 'تنظيف منازل'], 'moving' => ['نقل أثاث', 'فك ونقل وتركيب أثاث'],
            'solar' => ['طاقة شمسية', 'تركيب ألواح شمسية'], 'corporate' => ['صيانة منشآت', 'عقود صيانة للشركات'], 'general' => ['صيانة عامة', 'صيانة منازل'], 'contracting' => ['مقاولات', 'مقاولات عامة وتشطيب']];
        return $m[$sid] ?? ($o[$sid] ?? [self::short($sid)]);
    }
    public static function variant($sid, $seed) { $v = self::variants($sid); return $v[abs(crc32((string) $seed)) % count($v)]; }

    private static function hash($s) { return substr(md5($s), 0, 6); }

    /** Entity list, Makkah first (3 Makkah for every 1 Jeddah), kinds interleaved proportionally. */
    public static function entities() {
        if (isset(self::$c['E'])) { return self::$c['E']; }
        $pl = include __DIR__ . '/seo/data-places.php';
        $br = include __DIR__ . '/seo/data-brands.php';
        $svc = include __DIR__ . '/seo/data-services.php';
        $all = array_keys($svc);
        $mk = []; $jd = [];
        foreach ($pl['mk_districts'] as $x) { $mk['d'][] = ['t' => 'd', 'label' => $x, 'city' => 'mk']; }
        foreach ($pl['jd_districts'] as $x) { $jd['d'][] = ['t' => 'd', 'label' => $x, 'city' => 'jd']; }
        foreach ($pl['mk_places'] as $x) { $mk['p'][] = ['t' => 'p', 'label' => $x, 'city' => 'mk']; }
        foreach ($pl['jd_places'] as $x) { $jd['p'][] = ['t' => 'p', 'label' => $x, 'city' => 'jd']; }
        foreach ($br as $b => $sids) { $mk['b'][] = ['t' => 'b', 'label' => $b, 'city' => 'mk', 'sids' => $sids]; $jd['b'][] = ['t' => 'b', 'label' => $b, 'city' => 'jd', 'sids' => $sids]; }
        $weave = function (array $groups) {
            $out = []; $pos = array_fill_keys(array_keys($groups), 0); $left = true;
            while ($left) {
                $left = false;
                foreach ($groups as $k => $g) {
                    $take = max(1, (int) round(count($g) / 20));
                    for ($j = 0; $j < $take && $pos[$k] < count($g); $j++) { $out[] = $g[$pos[$k]++]; }
                    if ($pos[$k] < count($g)) { $left = true; }
                }
            }
            return $out;
        };
        $MK = $weave($mk); $JD = $weave($jd);
        $E = []; $a = 0; $b2 = 0;
        while ($a < count($MK) || $b2 < count($JD)) {
            for ($j = 0; $j < 3 && $a < count($MK); $j++) { $E[] = $MK[$a++]; }
            if ($b2 < count($JD)) { $E[] = $JD[$b2++]; }
        }
        foreach ($E as &$e) { $e['h'] = self::hash($e['t'] . $e['city'] . $e['label']); if (empty($e['sids'])) { $e['sids'] = $all; } }
        unset($e);
        return self::$c['E'] = $E;
    }
    public static function count_total() {
        if (isset(self::$c['N'])) { return self::$c['N']; }
        $n = 0; $nt = count(self::templates());
        foreach (self::entities() as $e) { $n += count($e['sids']) * $nt; }
        return self::$c['N'] = $n;
    }
    public static function counts() {
        $o = ['districts' => 0, 'places' => 0, 'brands' => 0, 'items' => self::count_total()];
        foreach (self::entities() as $e) { if ($e['t'] === 'd') { $o['districts']++; } elseif ($e['t'] === 'p') { $o['places']++; } else { $o['brands']++; } }
        return $o;
    }

    /** Pure: the item at integer index $i, or null when that entity has no more combinations (the cursor then moves on). */
    public static function item($i) {
        $E = self::entities(); $ne = count($E); if ($ne === 0 || $i < 0) { return null; }
        $ei = $i % $ne; $e = $E[$ei]; $r = intdiv($i, $ne);
        $T = self::templates(); $nt = count($T); $S = $e['sids']; $ns = count($S);
        if ($r >= $ns * $nt) { return null; }
        $sid = $S[($r + $ei) % $ns];
        $t = (intdiv($r, $ns) + $ei * 7) % $nt;
        return self::make($e, $sid, $t, $i);
    }
    private static function ensure_city($label, $city) { return (mb_strpos($label, 'مكة') !== false || mb_strpos($label, 'جدة') !== false) ? $label : $label . ($city === 'mk' ? ' بمكة' : ' بجدة'); }
    public static function make($e, $sid, $t, $i) {
        $svc = include __DIR__ . '/seo/data-services.php';
        $T = self::templates()[$t];
        $cityName = $e['city'] === 'mk' ? 'مكة المكرمة' : 'جدة';
        $cityShort = $e['city'] === 'mk' ? 'مكة' : 'جدة';
        $item = ['key' => 'oxy-m-' . $e['h'] . '-' . $sid . '-t' . $t, 'track' => 'commercial', 'kind' => 'district', 'sid' => $sid, 'dom' => $svc[$sid]['spec'] ?? 'general', 'idx' => 0, 'mega' => 1, 'mt' => $e['t'], 'alt' => 3 + ($t % 3)];
        $item['idx'] = 100000 + (crc32($item['key']) & 0xFFFFF);
        if ($e['t'] === 'd') {
            $P = 'حي ' . $e['label'] . ' ' . ($e['city'] === 'mk' ? 'بمكة' : 'بجدة'); $n = self::short($sid);
            $item['city'] = 'حي ' . $e['label'] . ' ' . ($e['city'] === 'mk' ? 'بمكة المكرمة' : 'بجدة'); $item['district'] = $e['label'];
        } elseif ($e['t'] === 'p') {
            $P = 'محيط ' . self::ensure_city($e['label'], $e['city']); $n = self::short($sid);
            $item['city'] = $cityName; $item['place'] = $e['label'];
        } else {
            $P = $cityShort; $n = self::brand_phrase($sid, $e['label']);
            $item['city'] = $cityName; $item['brand'] = $e['label'];
        }
        $v = ($e['t'] === 'b') ? $n : self::variant($sid, $e['h'] . $t);
        $title = str_replace(['بـ{P}', '{n}', '{v}', '{P}'], ['ب' . $P, $n, $v, $P], $T[0]);
        $title = self::compact($title);
        $suffix = ' | ' . Oxygen_SEO_Engine::brand();
        if (mb_strlen($title) + mb_strlen($suffix) <= 70) { $title .= $suffix; }
        $item['title'] = $title;
        $item['kw'] = str_replace(['{n}', '{v}', '{P}'], [$n, $v, ($e['t'] === 'b' ? 'في ' : '') . $P], $T[1]);
        return $item;
    }

    /** Keep titles near what Google shows (~70 chars): drop the trailing claim after the last separator when too long. */
    private static function compact($t) {
        while (mb_strlen($t) > 70) {
            $cut = max((int) mb_strrpos($t, ' — '), (int) mb_strrpos($t, ' | '), (int) mb_strrpos($t, ': '), (int) mb_strrpos($t, '، '), (int) mb_strrpos($t, ' مع '), (int) mb_strrpos($t, ' بخصم '), (int) mb_strrpos($t, ' بإشراف '));
            if ($cut < 30) { break; }
            $t = mb_substr($t, 0, $cut);
        }
        return $t;
    }

    /** Reverse of the key: used to refresh an existing mega post. */
    public static function item_by_key($key) {
        if (!preg_match('/^oxy-m-([0-9a-f]{6})-([a-z\-]+)-t(\d+)$/', (string) $key, $m)) { return null; }
        foreach (self::entities() as $ei => $e) {
            if ($e['h'] !== $m[1] || !in_array($m[2], $e['sids'], true)) { continue; }
            $t = (int) $m[3]; if ($t >= count(self::templates())) { return null; }
            // reconstruct an index consistent with item(): any i with this entity/service/template
            $ns = count($e['sids']); $nt = count(self::templates()); $ne = count(self::entities());
            $si = array_search($m[2], $e['sids'], true);
            for ($tb = 0; $tb < $nt; $tb++) { if (($tb + $ei * 7) % $nt === $t) { break; } }
            for ($s0 = 0; $s0 < $ns; $s0++) { if (($s0 + $ei) % $ns === $si) { break; } }
            $r = $tb * $ns + $s0;
            return self::make($e, $m[2], $t, $r * $ne + $ei);
        }
        return null;
    }
    public static function is_key($k) { return strncmp((string) $k, 'oxy-m-', 6) === 0; }

    /** Cooling, refrigeration, freezer and cold-room services: the focus of the daily mix. */
    const FOCUS_SIDS = ['ac-wash', 'ac-maint', 'ac-install', 'ac-central', 'fridge'];
    const FOCUS_SHARE = 0.7;   // share of each day's articles about A/C + cold / freezer rooms
    const MAKKAH_SHARE = 0.7;  // share of each day's articles located in Makkah

    public static function is_focus($it) { return in_array($it['sid'] ?? '', self::FOCUS_SIDS, true); }
    public static function is_makkah($it) { return mb_strpos((string) ($it['city'] ?? ''), 'مكة') !== false; }

    /** Today's running counts (reset each day) used to steer the next article toward the 70% / 70% targets. */
    private static function mix() {
        $m = get_option('oxygen_seo_mix', []); $d = function_exists('current_time') ? current_time('Y-m-d') : gmdate('Y-m-d');
        if (!is_array($m) || ($m['d'] ?? '') !== $d) { $m = ['d' => $d, 'n' => 0, 'f' => 0, 'm' => 0]; }
        return $m;
    }

    /**
     * Next item that is owned by this site and has no post yet. The day's mix is steered: each call decides whether the next article
     * should be a focus (cooling / freezer) one and a Makkah one, from what was already published today, then reads from the cursor of
     * that (focus x city) bucket. Every bucket has its own persisted cursor, so no unpublished item is ever skipped for good.
     */
    public static function next(array $map, $owns, $scan = 4000) {
        $m = self::mix();
        $wantF = $m['n'] === 0 || ($m['f'] / $m['n']) < self::FOCUS_SHARE;
        $wantM = $m['n'] === 0 || ($m['m'] / $m['n']) < self::MAKKAH_SHARE;
        $legacy = (int) get_option('oxygen_seo_mega_cur', 0);
        $cur = get_option('oxygen_seo_mega_bk', []); if (!is_array($cur)) { $cur = []; }
        $max = count(self::entities()) * 21 * count(self::templates());
        $order = [[$wantF, $wantM], [$wantF, !$wantM], [!$wantF, $wantM], [!$wantF, !$wantM]];
        foreach ($order as $o) {
            list($f, $mk) = $o; $bk = ($f ? 'f' : 'o') . ($mk ? 'm' : 'j');
            $i = isset($cur[$bk]) ? (int) $cur[$bk] : $legacy; $found = null;
            for ($n = 0; $n < $scan && $i < $max; $n++, $i++) {
                $it = self::item($i);
                if (!$it || self::is_focus($it) !== $f || self::is_makkah($it) !== $mk || isset($map[$it['key']]) || !$owns($it)) { continue; }
                $found = $it; $i++; break;
            }
            $cur[$bk] = $i;
            if ($found) {
                update_option('oxygen_seo_mega_bk', $cur, false);
                $m['n']++; if (self::is_focus($found)) { $m['f']++; } if (self::is_makkah($found)) { $m['m']++; }
                update_option('oxygen_seo_mix', $m, false);
                return $found;
            }
        }
        update_option('oxygen_seo_mega_bk', $cur, false);
        return null;
    }
}
