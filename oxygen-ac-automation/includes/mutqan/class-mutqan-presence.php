<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «مين واقف على الباب دلوقتي»: the real visitors on the sites, for the 3D headquarters (التوأم الرقمي).
 *
 * Every open customer page sends a small heartbeat (sendBeacon, text/plain — no preflight, works from every face
 * of the network to oxygen11.com). We keep only the last 15 minutes, no IP and no phone: a random session id,
 * the site, the page, and where the visitor came from (Google search, Google ad, Blogger, WhatsApp, social, direct,
 * the site assistant). A daily counter per source is kept for the twin's report.
 */
final class Mutqan_Presence {

    const OPT = 'mutqan_presence';
    const DAY = 'mutqan_presence_day';
    const KEEP = 900;      // seconds a visitor stays «on the door» after his last heartbeat
    const CAP = 400;

    public static function boot() {
        add_action('rest_api_init', function () {
            register_rest_route('mutqan/v1', '/presence', ['methods' => 'POST', 'permission_callback' => '__return_true',
                'callback' => function ($req) {
                    try { self::beat(json_decode((string) $req->get_body(), true)); } catch (Throwable $e) { /* a heartbeat never fails loudly */ }
                    $r = new WP_REST_Response(['ok' => true], 200);
                    $r->header('Access-Control-Allow-Origin', '*');
                    return $r;
                }]);
        });
    }

    private static function clean($s, $n = 120) { return mb_substr(trim(wp_strip_all_tags((string) $s)), 0, $n); }

    /** Where the visitor came from, in the words the twin shows. */
    public static function source($ref, $q) {
        $q = strtolower((string) $q); $ref = strtolower((string) $ref);
        if (strpos($q, 'gclid=') !== false || strpos($q, 'utm_source=google') !== false && strpos($q, 'utm_medium=cpc') !== false) { return 'ad'; }
        if (strpos($ref, 'google.') !== false) { return 'google'; }
        if (strpos($ref, 'blogspot.') !== false || strpos($ref, 'blogger.') !== false) { return 'blogger'; }
        if (strpos($ref, 'wa.me') !== false || strpos($ref, 'whatsapp') !== false) { return 'whatsapp'; }
        if (preg_match('#(tiktok|facebook|instagram|snapchat|t\.co|twitter|x\.com|haraj)#', $ref)) { return 'social'; }
        if (preg_match('#(bing\.|yahoo\.|duckduckgo)#', $ref)) { return 'google'; }
        return $ref === '' ? 'direct' : 'site';
    }

    public static function beat($in) {
        if (!is_array($in)) { return; }
        $sid = preg_replace('/[^a-z0-9]/i', '', (string) ($in['sid'] ?? ''));
        if (strlen($sid) < 8) { return; }
        $sid = substr($sid, 0, 24);
        $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (preg_match('#(bot|crawl|spider|slurp|lighthouse|headless)#', $ua)) { return; }   // search robots are not visitors
        $all = get_option(self::OPT, []); if (!is_array($all)) { $all = []; }
        $now = time();
        foreach ($all as $k => $v) { if ($now - (int) ($v['at'] ?? 0) > self::KEEP) { unset($all[$k]); } }
        $old = $all[$sid] ?? null;
        $src = $old['src'] ?? self::source($in['ref'] ?? '', $in['q'] ?? '');
        $row = [
            'id' => $sid, 'at' => $now, 'since' => (int) ($old['since'] ?? $now),
            'site' => self::clean(parse_url((string) ($in['url'] ?? ''), PHP_URL_HOST) ?: ($in['host'] ?? ''), 60),
            'path' => self::clean(parse_url((string) ($in['url'] ?? ''), PHP_URL_PATH) ?: '/', 120),
            'title' => self::clean($in['title'] ?? '', 90),
            'src' => !empty($in['bot']) ? 'bot' : $src,
            'mobile' => strpos($ua, 'mobile') !== false || strpos($ua, 'android') !== false,
            'pages' => (int) ($old['pages'] ?? 0) + (($old['path'] ?? '') !== (parse_url((string) ($in['url'] ?? ''), PHP_URL_PATH) ?: '/') ? 1 : 0),
            'ordered' => !empty($in['ordered']) || !empty($old['ordered']),
        ];
        $all[$sid] = $row;
        if (count($all) > self::CAP) { uasort($all, function ($a, $b) { return $b['at'] <=> $a['at']; }); $all = array_slice($all, 0, self::CAP, true); }
        update_option(self::OPT, $all, false);
        if (!$old) {   // a new visitor today: count him once per source
            $d = get_option(self::DAY, []); $day = wp_date('Y-m-d');
            if (!is_array($d) || ($d['day'] ?? '') !== $day) { $d = ['day' => $day, 'n' => []]; }
            $d['n'][$row['src']] = (int) ($d['n'][$row['src']] ?? 0) + 1;
            update_option(self::DAY, $d, false);
        }
    }

    /** For the twin (staff only — called from Mutqan_Ops). */
    public static function now() {
        $all = get_option(self::OPT, []); if (!is_array($all)) { $all = []; }
        $now = time(); $out = [];
        foreach ($all as $v) {
            $age = $now - (int) ($v['at'] ?? 0);
            if ($age > self::KEEP) { continue; }
            $v['ago'] = $age; $v['stay'] = max(0, (int) $v['at'] - (int) ($v['since'] ?? $v['at']));
            $v['live'] = $age < 120;
            $out[] = $v;
        }
        usort($out, function ($a, $b) { return $a['ago'] <=> $b['ago']; });
        $d = get_option(self::DAY, []);
        return ['visitors' => array_slice($out, 0, 120), 'today' => (is_array($d) && ($d['day'] ?? '') === wp_date('Y-m-d')) ? ($d['n'] ?? []) : [], 'at' => gmdate('c')];
    }
}
Mutqan_Presence::boot();
