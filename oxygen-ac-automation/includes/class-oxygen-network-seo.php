<?php
if (!defined('ABSPATH')) { exit; }
/**
 * Article plan of the 10 satellite sites. One code base, ten different plans:
 *  - service mix: 70% A/C + cold / freezer rooms, 30% every other field;
 *  - place mix:   70% Makkah + Jeddah (cities and their districts), 30% the rest of the Kingdom;
 *  - each site has its own rotation (different service/place order, title wording, hook and angle salt), its own key namespace
 *    `oxy-net-<id>-…` (so no article can ever collide with another site's or with the two core sites'), and
 *  - a gradual volume: it starts small and rises on a schedule (option `oxygen_net_seo`), so ten sites never switch on at full speed.
 * The article body itself comes from the same builder as the core sites (Oxygen_SEO_Engine::build), rotated by the site's salt.
 */
final class Oxygen_Network_SEO {
    const OPT = 'oxygen_net_seo';
    const TOTAL = 1500;        // plan length per site (grows later by raising this)

    const FOCUS = ['ac-maint', 'ac-wash', 'cold-room', 'ac-install', 'freezer-room', 'ac-central', 'ac-wash', 'ac-maint', 'fridge', 'cold-room'];
    const OTHER = ['plumbing', 'electric', 'appliances', 'pest', 'tanks', 'locks', 'cameras', 'solar', 'paint', 'tiling', 'cleaning', 'moving', 'corporate', 'general', 'contracting'];

    /** one selling angle per site, used inside titles so ten sites never share a headline */
    const HOOKS = [
        'motakhases' => 'تخصص دقيق وخبرة ميدانية', 'muhandis' => 'إشراف هندسي على كل زيارة', 'aqsa' => 'خدمة بلا حدود وسرعة وصول',
        'raad' => 'سرعة تنفيذ تسبق الحاجة', 'mutamakkin' => 'تمكّن وإتقان في التنفيذ', 'khabeer' => 'رأي الخبير قبل أي إصلاح',
        'homeservice' => 'خدمة منزلية بسعر واضح', 'ibtikar' => 'حلول مبتكرة تمنحك الراحة', 'mutamad' => 'فنيون معتمدون وضمان مكتوب', 'misriya' => 'خبرة مصرية وجودة موثوقة',
    ];
    /** title / keyword wording — every site walks this list from a different start */
    const TPL = [
        ['{n} في {c} — {h}', '{n} في {c}'],
        ['أفضل خدمة {n} في {c} | {h}', 'أفضل {n} {c}'],
        ['{n} {c}: فنيون محترفون وأسعار واضحة', '{n} {c} أسعار'],
        ['احجز {n} في {c} اليوم — {h}', 'حجز {n} في {c}'],
        ['شركة {n} في {c} بضمان معتمد', 'شركة {n} {c}'],
        ['{n} على مدار الساعة في {c}', '{n} {c} 24 ساعة'],
        ['كم تكلفة {n} في {c}؟ دليلك مع {h}', 'تكلفة {n} {c}'],
        ['خدمة {n} للمنازل والمنشآت في {c}', '{n} للمنازل والشركات {c}'],
        ['{n} في {c}: زيارة سريعة وتقرير بعد الصيانة', '{n} {c} زيارة'],
        ['صيانة وإصلاح: {n} في {c} — {h}', 'إصلاح {n} {c}'],
        ['لماذا تختار متخصصي {n} في {c}؟', 'أفضل شركة {n} في {c}'],
        ['{n} في {c} — فحص مجاني للمعاينة مع {h}', '{n} {c} معاينة'],
    ];

    private static function data($name) { static $c = []; if (!isset($c[$name])) { $c[$name] = include __DIR__ . '/seo/data-' . $name . '.php'; } return $c[$name]; }
    private static function rot($a, $k) { $n = count($a); if (!$n) { return $a; } $k = $k % $n; return array_merge(array_slice($a, $k), array_slice($a, 0, $k)); }

    /** places grouped: [makkah/jeddah list, rest-of-kingdom list], rotated per site */
    public static function places($slot) {
        $a = ['مكة المكرمة', 'جدة', 'مكة وجدة'];
        $mk = []; foreach (self::data('districts') as $d) { $mk[] = ['حي ' . $d . ' بمكة المكرمة', $d]; }
        $jd = []; foreach (self::data('districts-jeddah') as $d) { $jd[] = ['حي ' . $d . ' بجدة', $d]; }
        $A = [];
        foreach ($a as $c) { $A[] = [$c, '']; }
        $m = max(count($mk), count($jd));
        for ($i = 0; $i < $m; $i++) { if (isset($mk[$i])) { $A[] = $mk[$i]; } if (isset($jd[$i])) { $A[] = $jd[$i]; } }
        $B = []; foreach (self::data('cities') as $c) { $B[] = [$c, '']; }
        return [self::rot($A, $slot * 5), self::rot($B, $slot * 3)];
    }

    public static function plan($id = null) {
        static $cache = [];
        $id = $id ?: Oxygen_Network::current_id();
        if (isset($cache[$id])) { return $cache[$id]; }
        $slot = Oxygen_Network::slot($id);
        if ($slot < 0) { return $cache[$id] = []; }
        $name = Oxygen_Network::registry()[$id]['name'];
        $hook = self::HOOKS[$id] ?? 'خدمة موثوقة';
        $svc = array_merge(self::data('services'), self::data('net-services'));
        list($A, $B) = self::places($slot);
        $F = self::rot(self::FOCUS, $slot * 3); $O = self::rot(self::OTHER, $slot * 2);
        $fi = $oi = $ai = $bi = 0; $plan = []; $seen = [];
        for ($p = 0; $p < self::TOTAL; $p++) {
            $focus = (($p * 7 + $slot) % 10) < 7;
            $makkah = (($p * 3 + $slot * 7) % 10) < 7;
            $sid = $focus ? $F[$fi++ % count($F)] : $O[$oi++ % count($O)];
            if (!isset($svc[$sid])) { continue; }
            $pl = $makkah ? $A[$ai++ % count($A)] : $B[$bi++ % count($B)];
            // the same service + place comes back later with another wording (never a repeated key; the 70/30 rhythm is never broken by skipping)
            $ti0 = ($p + $slot * 5) % count(self::TPL); $key = '';
            for ($b = 0; $b < count(self::TPL); $b++) {
                $ti = ($ti0 + $b) % count(self::TPL);
                $k = 'oxy-net-' . $id . '-' . $sid . '-' . substr(md5($pl[0]), 0, 6) . '-t' . $ti;
                if (!isset($seen[$k])) { $key = $k; break; }
            }
            if ($key === '') { continue; }
            $seen[$key] = 1;
            $n = $svc[$sid]['n']; list($tt, $kk) = self::TPL[$ti];
            $rep = ['{n}' => $n, '{c}' => $pl[0], '{h}' => $hook];
            $it = ['key' => $key, 'track' => 'commercial', 'kind' => 'seo', 'sid' => $sid, 'city' => $pl[0], 'alt' => ($p % 5) + 1,
                'title' => strtr($tt, $rep) . ' | ' . $name, 'kw' => strtr($kk, $rep), 'dom' => $svc[$sid]['spec'] ?? 'general', 'idx' => $p,
                'focus' => $focus, 'local' => $makkah];
            if ($pl[1] !== '') { $it['district'] = $pl[1]; }
            $plan[] = $it;
        }
        return $cache[$id] = $plan;
    }

    /** gradual volume: start, +step every `every` days, never above cap */
    public static function settings() {
        $o = get_option(self::OPT, []); if (!is_array($o)) { $o = []; }
        $s = array_merge(['start' => 6, 'step' => 4, 'every' => 3, 'cap' => 40, 'since' => 0], $o);
        if (empty($s['since'])) { $s['since'] = time(); update_option(self::OPT, $s, false); }
        return $s;
    }
    public static function quota($now = null) {
        $s = self::settings(); $now = $now ?: time();
        $days = max(0, (int) floor(($now - (int) $s['since']) / 86400));
        return max(0, min((int) $s['cap'], (int) $s['start'] + (int) $s['step'] * (int) floor($days / max(1, (int) $s['every']))));
    }
    public static function tick() {
        if (!Oxygen_Network::is_satellite()) { return; }
        $quota = self::quota();
        $allowed = min($quota, (int) ceil($quota * ((int) wp_date('G') + 1) / 24));
        for ($i = 0; $i < 4 && Oxygen_SEO_Engine::today_count('new_seo') < $allowed; $i++) { if (!Oxygen_SEO_Engine::create_next(null, 'seo')) { break; } }
    }
}
