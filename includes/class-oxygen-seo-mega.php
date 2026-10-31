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
        return [
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
    }

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
        $title = str_replace(['بـ{P}', '{n}', '{P}'], ['ب' . $P, $n, $P], $T[0]);
        $title = self::compact($title);
        $suffix = ' | ' . Oxygen_SEO_Engine::brand();
        if (mb_strlen($title) + mb_strlen($suffix) <= 70) { $title .= $suffix; }
        $item['title'] = $title;
        $item['kw'] = str_replace(['{n}', '{P}'], [$n, ($e['t'] === 'b' ? 'في ' : '') . $P], $T[1]);
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

    /** Next item (from the persisted cursor) that is owned by this site and has no post yet. Scans at most $scan indexes per call. */
    public static function next(array $map, $owns, $scan = 4000) {
        $cur = (int) get_option('oxygen_seo_mega_cur', 0);
        $max = count(self::entities()) * 21 * count(self::templates());
        $found = null; $i = $cur;
        for ($n = 0; $n < $scan && $i < $max; $n++, $i++) {
            $it = self::item($i);
            if (!$it || isset($map[$it['key']]) || !$owns($it)) { continue; }
            $found = $it; $i++; break;
        }
        update_option('oxygen_seo_mega_cur', $i, false);
        return $found;
    }
}
