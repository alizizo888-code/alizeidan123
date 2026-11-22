<?php
if (!defined('ABSPATH')) exit;

/**
 * سحب أرقام من خرائط جوجل — بأداة مجانية مفتوحة المصدر (gosom/google-maps-scraper) شغالة على سيرفرك.
 * الموقع بيبعت الكلمات للسيرفر، ولما الشغلانة تخلص النتايج بتدخل قاعدة العملاء تلقائي.
 */
class MWC_Maps {
    static function cfg() {
        $c = get_option('mwc_maps'); $c = is_array($c) ? $c : [];
        return array_merge(['base_url' => '', 'user' => '', 'pass' => ''], $c);
    }
    static function public_cfg() { $c = self::cfg(); if ($c['pass']) $c['pass'] = '••••' . substr($c['pass'], -3); return $c; }
    static function save_cfg($in) {
        $c = self::cfg();
        foreach (['base_url', 'user', 'pass'] as $k) if (isset($in[$k]) && strpos((string) $in[$k], '••••') !== 0) $c[$k] = trim(sanitize_text_field($in[$k]));
        $c['base_url'] = rtrim($c['base_url'], '/');
        update_option('mwc_maps', $c, false);
        return self::public_cfg();
    }

    private static function call($method, $path, $body = null, $timeout = 30) {
        $c = self::cfg();
        if (!$c['base_url']) return ['code' => 0, 'error' => 'رابط أداة السحب مش متظبط'];
        $h = ['Accept' => 'application/json'];
        if ($c['user'] !== '') $h['Authorization'] = 'Basic ' . base64_encode($c['user'] . ':' . $c['pass']);
        $args = ['method' => $method, 'headers' => $h, 'timeout' => $timeout];
        if ($body !== null) { $args['body'] = wp_json_encode($body); $args['headers']['Content-Type'] = 'application/json'; }
        $r = wp_remote_request($c['base_url'] . $path, $args);
        if (is_wp_error($r)) return ['code' => 0, 'error' => $r->get_error_message()];
        $raw = wp_remote_retrieve_body($r);
        return ['code' => (int) wp_remote_retrieve_response_code($r), 'raw' => $raw, 'body' => json_decode($raw, true), 'error' => ''];
    }

    static function jobs() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM " . MWC_Store::t('jobs') . " ORDER BY id DESC LIMIT 100", ARRAY_A);
    }

    static function create($in) {
        global $wpdb;
        $kw = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', (string) ($in['keywords'] ?? '')))));
        if (!$kw) return new WP_Error('kw', 'اكتب كلمة بحث واحدة على الأقل (مثلاً: صيانة مكيفات مكة)', ['status' => 400]);
        $kw = array_slice($kw, 0, 30);
        $city = sanitize_text_field($in['city'] ?? '');
        $depth = max(1, min(30, (int) ($in['depth'] ?? 5)));
        $row = ['keywords' => implode("\n", $kw), 'city' => $city, 'category' => sanitize_text_field($in['category'] ?? ''), 'tags' => sanitize_text_field($in['tags'] ?? ''),
                'depth' => $depth, 'status' => 'pending', 'created_by' => MWC_Users::name(), 'created' => time()];
        $wpdb->insert(MWC_Store::t('jobs'), $row);
        $id = (int) $wpdb->insert_id;
        $r = self::call('POST', '/api/v1/jobs', ['name' => 'mwc-' . $id . ' ' . mb_substr($kw[0], 0, 40), 'keywords' => $kw, 'lang' => 'ar', 'zoom' => 15,
            'depth' => $depth, 'fast_mode' => false, 'radius' => 10000, 'email' => !empty($in['email']), 'max_time' => max(300, min(7200, $depth * 240 * count($kw))), 'lat' => '', 'lon' => '']);
        $ext = $r['body']['id'] ?? ($r['body']['ID'] ?? '');
        if (!$ext) {
            $err = $r['error'] ?: ('أداة السحب ردت بـ ' . $r['code'] . ' ' . mb_substr((string) ($r['raw'] ?? ''), 0, 120));
            $wpdb->update(MWC_Store::t('jobs'), ['status' => 'failed', 'error' => mb_substr($err, 0, 250)], ['id' => $id]);
            MWC_Store::log('maps', 'فشل بدء سحب «' . $kw[0] . '»: ' . $err);
            return new WP_Error('maps', $err, ['status' => 502]);
        }
        $wpdb->update(MWC_Store::t('jobs'), ['ext_id' => $ext, 'status' => 'working'], ['id' => $id]);
        MWC_Store::log('maps', 'بدء سحب من الخرائط: ' . implode('، ', $kw) . ($city ? ' — ' . $city : ''));
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . MWC_Store::t('jobs') . " WHERE id=%d", $id), ARRAY_A);
    }

    /** بيتنادى كل دقيقة: يشوف الشغلانات اللي خلصت ويستورد نتايجها */
    static function tick() {
        global $wpdb;
        $jobs = $wpdb->get_results("SELECT * FROM " . MWC_Store::t('jobs') . " WHERE status='working' ORDER BY id LIMIT 5", ARRAY_A);
        foreach ($jobs as $j) self::check($j);
    }

    static function check($j) {
        global $wpdb;
        $r = self::call('GET', '/api/v1/jobs/' . rawurlencode($j['ext_id']), null, 20);
        $b = $r['body'] ?? [];
        $st = strtolower((string) ($b['status'] ?? ($b['Status'] ?? '')));
        if ($r['code'] === 404) { $wpdb->update(MWC_Store::t('jobs'), ['status' => 'failed', 'error' => 'الشغلانة مش موجودة على أداة السحب'], ['id' => $j['id']]); return; }
        if (in_array($st, ['failed', 'error'], true)) { $wpdb->update(MWC_Store::t('jobs'), ['status' => 'failed', 'error' => 'أداة السحب فشلت', 'finished' => time()], ['id' => $j['id']]); return; }
        if (!in_array($st, ['ok', 'done', 'completed', 'finished'], true)) return;
        $csv = self::call('GET', '/api/v1/jobs/' . rawurlencode($j['ext_id']) . '/download', null, 120);
        if ($csv['code'] !== 200) return;
        $rows = self::parse_csv((string) $csv['raw'], $j);
        $res = MWC_Leads::import($rows, ['source' => 'خرائط جوجل: ' . strtok($j['keywords'], "\n"), 'city' => $j['city'], 'category' => $j['category'], 'tags' => trim('خرائط,' . $j['tags'], ',')]);
        $wpdb->update(MWC_Store::t('jobs'), ['status' => 'done', 'found' => count($rows), 'imported' => $res['new'], 'finished' => time()], ['id' => $j['id']]);
        MWC_Store::log('maps', sprintf('خلص السحب «%s»: لقى %d مكان، منهم %d رقم جديد (%d متحدّث)', strtok($j['keywords'], "\n"), count($rows), $res['new'], $res['updated']), 'النظام');
    }

    /** أعمدة CSV بتاع الأداة: title, category, address, phone, website, link, review_rating, review_count, latitude, longitude, emails */
    static function parse_csv($raw, $j) {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $fh = fopen('php://temp', 'r+'); fwrite($fh, $raw); rewind($fh);
        $head = fgetcsv($fh, 0, ',', '"', '\\');
        if (!$head) return [];
        $head = array_map(function ($h) { return strtolower(trim($h)); }, $head);
        $out = [];
        while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if (count($row) < count($head)) $row = array_pad($row, count($head), '');
            $r = array_combine($head, array_slice($row, 0, count($head)));
            $phone = $r['phone'] ?? ($r['phone_number'] ?? '');
            if (trim($phone) === '') continue;
            $out[] = ['phone' => $phone, 'name' => $r['title'] ?? ($r['name'] ?? ''), 'category' => $r['category'] ?? $j['category'], 'city' => $j['city'],
                'address' => $r['address'] ?? ($r['complete_address'] ?? ''), 'website' => $r['website'] ?? '', 'maps' => $r['link'] ?? '',
                'rating' => $r['review_rating'] ?? '', 'reviews' => $r['review_count'] ?? '', 'lat' => $r['latitude'] ?? '', 'lng' => $r['longitude'] ?? '',
                'email' => trim(explode(',', (string) ($r['emails'] ?? ''))[0])];
        }
        fclose($fh);
        return $out;
    }

    static function status() {
        $c = self::cfg();
        if (!$c['base_url']) return ['ok' => false, 'error' => 'مش متظبط'];
        $r = self::call('GET', '/api/v1/jobs', null, 10);
        return ['ok' => $r['code'] === 200, 'code' => $r['code'], 'error' => $r['error']];
    }
}
