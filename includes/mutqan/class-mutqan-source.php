<?php
/**
 * «جاي منين» — where a customer came from, and the article finder (owner order 2026-11-19).
 *
 * The WhatsApp links on our pages carry a short code: «#oxygen/a26» = site oxygen, WordPress post 26
 * (p = a page, b = a Blogger post id). The code stays as it is in the customer's message; this class turns it
 * into the article itself (title, link, site, WordPress or Blogger) and searches every site of the network for an
 * article by code, words or a pasted WhatsApp message.
 *
 * Also: the `origin` record every order carries (page, article, site, channel, referrer, campaign), cleaned here.
 */
if (!defined('ABSPATH')) { exit; }

final class Mutqan_Source {
    const TTL = 604800;      // a resolved code is cached a week
    const SEARCH_TTL = 600;  // a search ten minutes

    /* ------------------------------------------------------------------ codes */

    /** Every code in a text: «#oxygen/a26», «oxygen/a26», «أكسجين / A26», «#nasim/b1234…». Brand names in Arabic are understood too. */
    public static function codes($text) {
        $t = self::digits((string) $text);
        $out = [];
        if (preg_match_all('~#?\s*([a-z][a-z0-9]{1,23})\s*/\s*([apb])\s*(\d{1,20})~i', $t, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) { $b = strtolower($x[1]); if (self::valid($b)) { $out[] = ['brand' => $b, 'kind' => strtolower($x[2]), 'id' => $x[3]]; } }
        }
        // «أكسجين /A26»: an Arabic site name followed by the code
        $tn = self::norm($t);
        foreach (self::names() as $id => $name) {
            $n = preg_quote(self::norm($name), '~');
            if (preg_match_all('~' . $n . '\s*/?\s*([apb])\s*(\d{1,20})~iu', $tn, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) { $out[] = ['brand' => $id, 'kind' => strtolower($x[1]), 'id' => $x[2]]; }
            }
        }
        $seen = []; $uniq = [];
        foreach ($out as $c) { $k = $c['brand'] . '/' . $c['kind'] . $c['id']; if (!isset($seen[$k])) { $seen[$k] = 1; $uniq[] = $c; } }
        return $uniq;
    }

    public static function code_of($brand, $kind, $id) { return '#' . $brand . '/' . $kind . $id; }

    /** The site a «#brand» tag names when there is no article number («#raad» alone). */
    public static function brand_tag($text) {
        if (preg_match_all('/#([a-z][a-z0-9]{1,23})\b/i', (string) $text, $m)) { foreach ($m[1] as $c) { $c = strtolower($c); if (self::valid($c)) { return $c; } } }
        return '';
    }

    /* ------------------------------------------------------------------ one code → the article */

    public static function lookup($code) {
        $c = is_array($code) ? $code : (self::codes((string) $code)[0] ?? null);
        if (!$c) { return null; }
        $key = 'mq_src_' . md5($c['brand'] . '/' . $c['kind'] . $c['id']);
        $hit = get_transient($key);
        if (is_array($hit)) { return $hit; }
        $r = $c['kind'] === 'b' ? self::blogger_post($c['brand'], $c['id']) : self::wp_item($c['brand'], $c['kind'], (int) $c['id']);
        $r = array_merge(self::site_card($c['brand'], $c['kind'] === 'b' ? 'blogger' : 'wordpress'), [
            'code' => self::code_of($c['brand'], $c['kind'], $c['id']), 'kind' => $c['kind'], 'id' => (string) $c['id'],
            'type' => $c['kind'] === 'p' ? 'page' : 'article',
        ], $r ?: ['found' => false, 'title' => '', 'url' => '']);
        set_transient($key, $r, $r['found'] ? self::TTL : 3600);
        return $r;
    }

    private static function wp_item($brand, $kind, $id) {
        if ($id <= 0) { return null; }
        $host = self::wp_host($brand);
        if ($host === '') { return null; }
        if (class_exists('Oxygen_Network') && Oxygen_Network::current_id() === $brand) {
            $p = get_post($id);
            if (!$p || $p->post_status !== 'publish') { return null; }
            return ['found' => true, 'title' => wp_strip_all_tags(get_the_title($p)), 'url' => (string) get_permalink($p), 'date' => mysql2date('c', $p->post_date_gmt, false)];
        }
        $j = self::get_json('https://' . $host . '/wp-json/wp/v2/' . ($kind === 'p' ? 'pages' : 'posts') . '/' . $id . '?_fields=id,link,title,date');
        if (!is_array($j) || empty($j['id'])) { return null; }
        return ['found' => true, 'title' => wp_strip_all_tags(html_entity_decode((string) ($j['title']['rendered'] ?? ''), ENT_QUOTES, 'UTF-8')), 'url' => (string) ($j['link'] ?? ''), 'date' => (string) ($j['date'] ?? '')];
    }

    private static function blogger_post($brand, $id) {
        $host = self::blog_host($brand);
        if ($host === '' || !preg_match('/^\d{5,20}$/', (string) $id)) { return null; }
        $j = self::get_json('https://' . $host . '/feeds/posts/default/' . $id . '?alt=json');
        $e = is_array($j) ? ($j['entry'] ?? null) : null;
        if (!$e) { return null; }
        return ['found' => true, 'title' => (string) ($e['title']['$t'] ?? ''), 'url' => self::alt_link($e), 'date' => (string) ($e['published']['$t'] ?? '')];
    }

    /* ------------------------------------------------------------------ search the whole network */

    /**
     * $q: a code, a pasted WhatsApp message, or words from a title. $where: '' (all), 'wordpress', 'blogger'. $brand: one site or ''.
     * A code answers directly; a message's «بخصوص: <title>» becomes the words to search, limited to the site its #tag names.
     */
    public static function search($q, $where = '', $brand = '', $limit = 40) {
        $q = trim(self::digits((string) $q));
        if ($q === '') { return ['query' => '', 'items' => [], 'codes' => []]; }
        $found = []; $codes = self::codes($q);
        foreach (array_slice($codes, 0, 5) as $c) { $r = self::lookup($c); if ($r) { $found[] = $r + ['match' => 'code']; } }
        $words = $q;
        if (preg_match('/بخصوص:\s*([^#\n]{2,90})/u', $q, $m)) { $words = trim($m[1]); }
        elseif ($codes) { $words = ''; }
        $words = trim(preg_replace('~#?[a-z][a-z0-9]{1,23}\s*/\s*[apb]\s*\d{1,20}~i', ' ', $words));
        $words = trim(preg_replace('/(مرحبا|السلام عليكم|👋|#[a-z0-9]+)/iu', ' ', $words));
        if ($brand === '' && !$codes) { $brand = self::brand_tag($q); }
        if (mb_strlen($words) >= 2) { $found = array_merge($found, self::search_words($words, $where, $brand, $limit)); }
        $seen = []; $items = [];
        foreach ($found as $r) { $k = $r['url'] !== '' ? $r['url'] : $r['code']; if (isset($seen[$k])) { continue; } $seen[$k] = 1; $items[] = $r; }
        return ['query' => $q, 'words' => $words, 'brand' => $brand, 'codes' => array_map(function ($c) { return self::code_of($c['brand'], $c['kind'], $c['id']); }, $codes), 'items' => array_slice($items, 0, $limit)];
    }

    private static function search_words($words, $where, $brand, $limit) {
        $key = 'mq_srch_' . md5($words . '|' . $where . '|' . $brand);
        $hit = get_transient($key);
        if (is_array($hit)) { return $hit; }
        $reqs = []; $meta = []; $out = [];
        $ids = $brand !== '' ? [$brand] : array_keys(self::names());
        foreach ($ids as $id) {
            if ($where !== 'blogger' && self::wp_host($id) !== '') {
                if (class_exists('Oxygen_Network') && Oxygen_Network::current_id() === $id) { $out = array_merge($out, self::local_search($id, $words)); }
                else { $k = 'w:' . $id; $reqs[$k] = ['url' => 'https://' . self::wp_host($id) . '/wp-json/wp/v2/search?per_page=8&_fields=id,title,url,subtype&search=' . rawurlencode($words), 'type' => 'GET']; $meta[$k] = $id; }
            }
            if ($where !== 'wordpress' && self::blog_host($id) !== '') {
                $k = 'b:' . $id; $reqs[$k] = ['url' => 'https://' . self::blog_host($id) . '/feeds/posts/default?alt=json&max-results=8&q=' . rawurlencode($words), 'type' => 'GET']; $meta[$k] = $id;
            }
        }
        foreach (self::multi($reqs) as $k => $body) {
            $j = json_decode((string) $body, true);
            if (!is_array($j)) { continue; }
            $id = $meta[$k];
            if ($k[0] === 'w') {
                foreach ($j as $x) {
                    if (empty($x['id'])) { continue; }
                    $kind = ($x['subtype'] ?? 'post') === 'page' ? 'p' : 'a';
                    $out[] = array_merge(self::site_card($id, 'wordpress'), ['code' => self::code_of($id, $kind, (int) $x['id']), 'kind' => $kind, 'id' => (string) $x['id'], 'type' => $kind === 'p' ? 'page' : 'article', 'found' => true, 'title' => wp_strip_all_tags(html_entity_decode((string) ($x['title'] ?? ''), ENT_QUOTES, 'UTF-8')), 'url' => (string) ($x['url'] ?? ''), 'match' => 'words']);
                }
            } else {
                foreach ((array) ($j['feed']['entry'] ?? []) as $e) {
                    $pid = preg_match('/post-(\d+)$/', (string) ($e['id']['$t'] ?? ''), $m) ? $m[1] : '';
                    $out[] = array_merge(self::site_card($id, 'blogger'), ['code' => $pid !== '' ? self::code_of($id, 'b', $pid) : '#' . $id, 'kind' => 'b', 'id' => $pid, 'type' => 'article', 'found' => true, 'title' => (string) ($e['title']['$t'] ?? ''), 'url' => self::alt_link($e), 'date' => (string) ($e['published']['$t'] ?? ''), 'match' => 'words']);
                }
            }
        }
        $out = array_slice($out, 0, $limit);
        set_transient($key, $out, self::SEARCH_TTL);
        return $out;
    }

    private static function local_search($id, $words) {
        $q = new WP_Query(['s' => $words, 'post_type' => ['post', 'page'], 'post_status' => 'publish', 'posts_per_page' => 10, 'no_found_rows' => true, 'fields' => 'ids']);
        $out = [];
        foreach ($q->posts as $pid) {
            $kind = get_post_type($pid) === 'page' ? 'p' : 'a';
            $out[] = array_merge(self::site_card($id, 'wordpress'), ['code' => self::code_of($id, $kind, $pid), 'kind' => $kind, 'id' => (string) $pid, 'type' => $kind === 'p' ? 'page' : 'article', 'found' => true, 'title' => wp_strip_all_tags(get_the_title($pid)), 'url' => (string) get_permalink($pid), 'date' => (string) get_post_time('c', true, $pid), 'match' => 'words']);
        }
        return $out;
    }

    /* ------------------------------------------------------------------ the origin an order carries */

    const CHANNELS = ['google', 'google_ads', 'bing', 'tiktok', 'facebook', 'instagram', 'snapchat', 'x', 'youtube', 'whatsapp', 'blogger', 'network', 'direct', 'internal', 'manual', 'other'];
    const PAGES = ['home', 'app', 'article', 'sub_article', 'page', 'blogger', 'landing', 'webapp', 'whatsapp', 'manual', 'other'];

    /** Keep only the known fields, short; the server adds the host that really sent the order. */
    public static function clean_origin($in, $extra = []) {
        if (!is_array($in)) { $in = []; }
        $s = function ($v, $n = 160) { return mb_substr(trim(wp_strip_all_tags((string) $v)), 0, $n); };
        $u = function ($v) { $v = trim((string) $v); return preg_match('#^https?://#i', $v) ? esc_url_raw(mb_substr($v, 0, 400)) : ''; };
        $o = [
            'site' => preg_match('/^[a-z][a-z0-9]{1,23}$/', (string) ($in['site'] ?? '')) ? (string) $in['site'] : '',
            'host' => $s(strtolower((string) ($in['host'] ?? '')), 120),
            'page' => in_array($in['page'] ?? '', self::PAGES, true) ? $in['page'] : 'other',
            'channel' => in_array($in['channel'] ?? '', self::CHANNELS, true) ? $in['channel'] : 'other',
            'code' => preg_match('~^#[a-z][a-z0-9]{1,23}(/[apb]\d{1,20})?$~', (string) ($in['code'] ?? '')) ? (string) $in['code'] : '',
            'title' => $s($in['title'] ?? '', 160), 'url' => $u($in['url'] ?? ''), 'entry' => $u($in['entry'] ?? ''), 'referrer' => $u($in['referrer'] ?? ''),
            'utm' => [], 'gclid' => $s($in['gclid'] ?? '', 120), 'pwa' => !empty($in['pwa']), 'at' => $s($in['at'] ?? '', 40), 'first' => [],
        ];
        foreach (['source', 'medium', 'campaign', 'term', 'content'] as $k) { if (!empty($in['utm'][$k])) { $o['utm'][$k] = $s($in['utm'][$k], 80); } }
        if (!empty($in['first']) && is_array($in['first'])) {
            foreach (['site', 'host', 'page', 'channel', 'code', 'title', 'url', 'referrer', 'at'] as $k) { if (isset($in['first'][$k])) { $o['first'][$k] = in_array($k, ['url', 'referrer'], true) ? $u($in['first'][$k]) : $s($in['first'][$k], 160); } }
        }
        $o = array_merge($o, $extra);
        return array_filter($o, function ($v) { return $v !== '' && $v !== [] && $v !== false; });
    }

    /** A short Arabic line for screens and WhatsApp: «أكسجين (oxygen11.com) · مقالة: … · من جوجل». */
    public static function line($o) {
        if (!is_array($o) || !$o) { return ''; }
        $site = !empty($o['site']) ? self::site_card($o['site'])['site'] : '';
        $pages = ['home' => 'الصفحة الرئيسية', 'app' => 'التطبيق', 'article' => 'مقالة', 'sub_article' => 'مقالة فرعية', 'page' => 'صفحة', 'blogger' => 'مدونة بلوجر', 'landing' => 'صفحة هبوط', 'webapp' => 'الويب آب المثبّت', 'whatsapp' => 'واتساب', 'manual' => 'يدوي'];
        $ch = ['google' => 'بحث جوجل', 'google_ads' => 'إعلان جوجل', 'tiktok' => 'تيك توك', 'facebook' => 'فيسبوك', 'instagram' => 'إنستجرام', 'snapchat' => 'سناب', 'whatsapp' => 'واتساب', 'blogger' => 'بلوجر', 'network' => 'موقع من الشبكة', 'direct' => 'دخول مباشر', 'internal' => 'من داخل الموقع'];
        $parts = array_filter([
            $site !== '' ? $site . (!empty($o['host']) ? ' (' . $o['host'] . ')' : '') : ($o['host'] ?? ''),
            ($pages[$o['page'] ?? ''] ?? '') . (!empty($o['title']) ? ': ' . $o['title'] : ''),
            $o['code'] ?? '', $ch[$o['channel'] ?? ''] ?? '',
        ]);
        return implode(' · ', $parts);
    }

    /* ------------------------------------------------------------------ REST (staff) */

    public static function handle($in, $p) {
        if (!Mutqan_Policy::is_staff($p) && ($p['role'] ?? '') !== 'owner') { throw new Mutqan_Error('forbidden', 'للمالك وفريق العمليات فقط.', 403); }
        Mutqan_Limiter::hit('source_' . ($p['id'] ?? ''), 240, 3600);
        $op = (string) ($in['op'] ?? 'search');
        if ($op === 'lookup') { return self::lookup((string) ($in['code'] ?? '')); }
        if ($op === 'search') { return self::search((string) ($in['q'] ?? ''), (string) ($in['where'] ?? ''), (string) ($in['brand'] ?? ''), min(80, max(5, (int) ($in['limit'] ?? 40)))); }
        if ($op === 'sites') { $out = []; foreach (self::names() as $id => $n) { $out[] = self::site_card($id) + ['wp' => self::wp_host($id), 'blog' => self::blog_host($id)]; } return $out; }
        throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400);
    }

    /* ------------------------------------------------------------------ helpers */

    private static function valid($b) { return class_exists('Oxygen_Network') ? Oxygen_Network::valid($b) : $b === 'oxygen'; }

    /** id => Arabic name, every site and Blogger-only face. */
    public static function names() {
        static $n = null;
        if ($n !== null) { return $n; }
        $n = [];
        if (class_exists('Oxygen_Network')) {
            foreach (Oxygen_Network::registry() as $id => $b) { $n[$id] = (string) ($b['name'] ?? $id); }
            foreach (Oxygen_Network::blog_brands() as $id => $b) { $n[$id] = (string) ($b['name'] ?? $id); }
        }
        $n['oxygen'] = $n['oxygen'] ?? 'أكسجين';
        $n['mutqan'] = $n['mutqan'] ?? 'أكسجين';
        return $n;
    }

    public static function site_card($brand, $platform = '') {
        $names = self::names();
        return ['brand' => $brand, 'site' => $names[$brand] ?? $brand, 'platform' => $platform, 'domain' => $platform === 'blogger' ? self::blog_host($brand) : self::wp_host($brand)];
    }

    public static function wp_host($brand) {
        if (!class_exists('Oxygen_Network') || !isset(Oxygen_Network::registry()[$brand])) { return ''; }
        return (string) Oxygen_Network::host_of($brand);
    }

    public static function blog_host($brand) {
        if (!class_exists('Oxygen_Network')) { return ''; }
        $hub = Oxygen_Network::HUB_HOST;
        if ($brand === 'oxygen') { return 'blog.' . $hub; }
        $r = Oxygen_Network::registry();
        if (isset($r[$brand])) { return 'blog-' . (string) ($brand) . '.' . $hub; }
        return isset(Oxygen_Network::blog_brands()[$brand]) ? 'blog-' . $brand . '.' . $hub : '';
    }

    private static function alt_link($e) {
        foreach ((array) ($e['link'] ?? []) as $l) { if (($l['rel'] ?? '') === 'alternate') { return (string) ($l['href'] ?? ''); } }
        return '';
    }

    /** Arabic spelling variants folded: أ/إ/آ→ا، ة→ه، ى→ي، no tashkeel. */
    private static function norm($t) { return str_replace(['أ', 'إ', 'آ', 'ة', 'ى', 'ـ', 'ُ', 'َ', 'ِ', 'ّ', 'ْ'], ['ا', 'ا', 'ا', 'ه', 'ي', '', '', '', '', '', ''], (string) $t); }

    private static function digits($t) { return strtr((string) $t, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', 'A' => 'a', 'P' => 'p', 'B' => 'b']); }

    private static function get_json($url) {
        $r = wp_remote_get($url, ['timeout' => 6, 'redirection' => 3, 'headers' => ['Accept' => 'application/json']]);
        if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) { return null; }
        return json_decode((string) wp_remote_retrieve_body($r), true);
    }

    /** Parallel GETs (WordPress's Requests library); falls back to one by one. */
    private static function multi($reqs) {
        if (!$reqs) { return []; }
        $out = [];
        $cls = class_exists('\WpOrg\Requests\Requests') ? '\WpOrg\Requests\Requests' : (class_exists('Requests') ? 'Requests' : '');
        if ($cls !== '') {
            try {
                $res = $cls::request_multiple($reqs, ['timeout' => 6, 'connect_timeout' => 4, 'follow_redirects' => true]);
                foreach ($res as $k => $r) { if (is_object($r) && !($r instanceof Exception) && isset($r->status_code) && (int) $r->status_code === 200) { $out[$k] = $r->body; } }
                return $out;
            } catch (Throwable $e) { /* fall through */ }
        }
        foreach ($reqs as $k => $q) { $r = wp_remote_get($q['url'], ['timeout' => 5]); if (!is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) === 200) { $out[$k] = wp_remote_retrieve_body($r); } }
        return $out;
    }
}
