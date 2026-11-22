<?php
if (!defined('ABSPATH')) exit;

/**
 * سحب أرقام من خرائط جوجل — بأداة مجانية مفتوحة المصدر (gosom/google-maps-scraper) شغالة على سيرفرك.
 * الموقع بيبعت الكلمات للسيرفر، ولما الشغلانة تخلص النتايج بتدخل قاعدة العملاء تلقائي.
 */
class MWC_Maps {
    /** المجالات اللي بنلم أرقامها (صاحب الشغل حددها: 2026-11-22) */
    const TRADES = ['صيانة مكيفات', 'غسيل مكيفات', 'تركيب مكيفات', 'صيانة غرف تبريد وتجميد'];

    /**
     * أحياء مكة وجدة. السحب بيمشي على (مجال × حي) واحدة ورا التانية بدل ما يسأل «صيانة مكيفات مكة» مرة واحدة —
     * خرائط جوجل بترجع عدد محدود للبحث الواحد، فالبحث على مستوى الحي هو اللي بيوصل لكل المحلات فعلاً.
     */
    const AREAS = [
        'مكة' => ['العزيزية', 'الشوقية', 'النسيم', 'الششة', 'العوالي', 'الكعكية', 'بطحاء قريش', 'الزاهر', 'المسفلة',
                  'الخالدية', 'الرصيفة', 'النوارية', 'الشرائع', 'المعابدة', 'ولي العهد', 'التنعيم', 'الهجرة', 'جرهم'],
        'جدة' => ['السلامة', 'الصفا', 'المروة', 'النزهة', 'الربوة', 'الروضة', 'الحمراء', 'البوادي', 'الفيصلية',
                  'النعيم', 'الشاطئ', 'أبحر الشمالية', 'أبحر الجنوبية', 'الزهراء', 'السامر', 'مشرفة', 'بريمان', 'الجامعة'],
    ];

    /**
     * كل التباديل (مجال × حي × مدينة) بالترتيب اللي الجدول اليومي بيمشي عليه.
     * المدن بتتبادل واحدة ورا واحدة مقصود: لو مشينا مكة كلها الأول، جدة ما كانتش هتبدأ غير بعد 12 يوم —
     * وهو عايز الملفين يكبروا مع بعض كل يوم.
     */
    static function plan_list() {
        $cities = array_keys(self::AREAS);
        $cols = [];
        foreach ($cities as $city) {
            $col = [];
            foreach (self::AREAS[$city] as $area) {
                foreach (self::TRADES as $trade) { $col[] = ['city' => $city, 'area' => $area, 'trade' => $trade]; }
            }
            $cols[] = $col;
        }
        $out = []; $max = max(array_map('count', $cols));
        for ($i = 0; $i < $max; $i++) {
            foreach ($cols as $col) { if (isset($col[$i])) $out[] = $col[$i]; }
        }
        return $out;
    }

    static function plan() {
        $p = get_option('mwc_maps_plan');
        return array_merge(['on' => 0, 'per_day' => 6, 'hour' => 3, 'depth' => 5, 'cursor' => 0, 'last_run' => '',
                            'day' => '', 'started_today' => 0], is_array($p) ? $p : []);
    }
    static function save_plan($in) {
        $p = self::plan();
        if (isset($in['on']))      $p['on'] = empty($in['on']) ? 0 : 1;
        if (isset($in['per_day'])) $p['per_day'] = max(1, min(30, (int) $in['per_day']));
        if (isset($in['hour']))    $p['hour'] = max(0, min(23, (int) $in['hour']));
        if (isset($in['depth']))   $p['depth'] = max(1, min(30, (int) $in['depth']));
        if (isset($in['cursor']))  $p['cursor'] = max(0, (int) $in['cursor']);
        update_option('mwc_maps_plan', $p, false);
        return self::plan_out();
    }

    /** الحالة اللي بتتعرض في الشاشة: فين واقفين في التغطية، وإيه اللي جاي النهاردة */
    static function plan_out() {
        $p = self::plan();
        $all = self::plan_list(); $n = count($all);
        $next = [];
        for ($i = 0; $i < $p['per_day'] && $i < $n; $i++) {
            $c = $all[($p['cursor'] + $i) % $n];
            $next[] = $c['trade'] . ' — ' . $c['area'] . ' ' . $c['city'];
        }
        return [
            'on' => (int) $p['on'], 'per_day' => (int) $p['per_day'], 'hour' => (int) $p['hour'], 'depth' => (int) $p['depth'],
            'cursor' => (int) $p['cursor'], 'total' => $n, 'last_run' => $p['last_run'],
            'started_today' => (int) ($p['day'] === MWC_Store::riyadh('Y-m-d') ? $p['started_today'] : 0), 'max_running' => self::MAX_RUNNING,
            'cycle_days' => (int) ceil($n / max(1, $p['per_day'])),
            'next' => $next, 'today' => self::today_stats(),
            'trades' => self::TRADES, 'cities' => array_keys(self::AREAS),
        ];
    }

    /** كام رقم جديد دخل النهاردة، لكل مدينة — ده «الأوردر اليومي» اللي بيزيد كل يوم */
    static function today_stats() {
        global $wpdb;
        $start = (int) (new DateTime(MWC_Store::riyadh('Y-m-d') . ' 00:00:00', new DateTimeZone('Asia/Riyadh')))->format('U');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT city, COUNT(*) jobs, COALESCE(SUM(found),0) found, COALESCE(SUM(imported),0) imported
             FROM " . MWC_Store::t('jobs') . " WHERE finished>=%d AND status='done' GROUP BY city", $start), ARRAY_A);
        $out = ['new_today' => 0, 'found_today' => 0, 'jobs_today' => 0, 'by_city' => []];
        foreach ($rows as $r) {
            $out['by_city'][$r['city'] ?: '—'] = ['new' => (int) $r['imported'], 'found' => (int) $r['found'], 'jobs' => (int) $r['jobs']];
            $out['new_today'] += (int) $r['imported']; $out['found_today'] += (int) $r['found']; $out['jobs_today'] += (int) $r['jobs'];
        }
        foreach (array_keys(self::AREAS) as $c) if (!isset($out['by_city'][$c])) $out['by_city'][$c] = ['new' => 0, 'found' => 0, 'jobs' => 0];
        return $out;
    }

    /**
     * بيتنادى مع نبضة الدقيقة. بيشغّل بحث **واحد** في المرة ويستنى لحد ما يخلص قبل اللي بعده، لحد ما يكمّل
     * عدد اليوم. السبب: الـVPS معالج واحد وأداة السحب بتفتح متصفح — 6 عمليات مع بعض بتخليهم كلهم يزحفوا
     * ساعات من غير ما تخلص ولا واحدة (اتجرب 2026-11-23). واحدة ورا واحدة أسرع فعليًا وبتسيب نفس للواتساب.
     */
    const MAX_RUNNING = 2;

    static function run_daily() {
        global $wpdb;
        $p = self::plan();
        if (empty($p['on'])) return false;

        // بتوقيت الرياض زي الحملات بالظبط — توقيت الموقع نفسه UTC، فـ«3 الفجر» كانت بتطلع 6 الصبح عند صاحب
        // الشغل. الميعاد اللي بيكتبه لازم يكون الميعاد اللي بيحصل فعلًا عنده.
        $today = MWC_Store::riyadh('Y-m-d');
        if ($p['day'] !== $today) { $p['day'] = $today; $p['started_today'] = 0; update_option('mwc_maps_plan', $p, false); }
        if ((int) $p['started_today'] >= (int) $p['per_day']) return false;
        if ((int) MWC_Store::riyadh('G') < (int) $p['hour']) return false;

        // مزدحم؟ نستنى النبضة الجاية
        $running = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . MWC_Store::t('jobs') . " WHERE status IN ('working','pending')");
        if ($running >= self::MAX_RUNNING) return false;

        $all = self::plan_list(); $n = count($all);
        if (!$n) return false;
        $c = $all[$p['cursor'] % $n];
        $r = self::create([
            'keywords' => $c['trade'] . ' ' . $c['area'] . ' ' . $c['city'],
            'city' => $c['city'], 'category' => $c['trade'],
            'tags' => 'خرائط,يومي,' . $c['area'], 'depth' => $p['depth'],
        ]);
        if (is_wp_error($r)) {
            MWC_Store::log('maps', 'الجدول اليومي: فشل «' . $c['trade'] . ' ' . $c['area'] . '» — ' . $r->get_error_message(), 'النظام');
            return false;
        }
        $p['cursor'] = ($p['cursor'] + 1) % $n;
        $p['started_today'] = (int) $p['started_today'] + 1;
        $p['last_run'] = $today;
        update_option('mwc_maps_plan', $p, false);
        MWC_Store::log('maps', sprintf('الجدول اليومي (%d من %d النهاردة): %s — %s %s',
            $p['started_today'], $p['per_day'], $c['trade'], $c['area'], $c['city']), 'النظام');
        return true;
    }

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
        // الجدول اليومي بيركب على نفس النبضة دي بدل كرون لوحده: النبضة جاية من ticker السيرفر ومن ووردبريس
        // الاتنين، فلو واحد فيهم وقف الجدول بيفضل ماشي. وهي بتتحكم في الزحمة بنفسها.
        self::run_daily();
    }

    static function check($j) {
        global $wpdb;
        $r = self::call('GET', '/api/v1/jobs/' . rawurlencode($j['ext_id']), null, 20);
        $b = $r['body'] ?? [];
        // بعض إصدارات الأداة بترد ملفوفة: {"data":{...}} — من غير السطر ده الحالة بتطلع فاضية والشغلانة
        // تفضل «شغالة» للأبد وهي خلصت من زمان (اتشاف 2026-11-23: الأداة لقت 9 أماكن والمركز فضل مستني).
        if (isset($b['data']) && is_array($b['data'])) { $b = $b['data']; }
        $st = strtolower((string) ($b['status'] ?? ($b['Status'] ?? ($b['state'] ?? ''))));
        if ($r['code'] === 404) { $wpdb->update(MWC_Store::t('jobs'), ['status' => 'failed', 'error' => 'الشغلانة مش موجودة على أداة السحب'], ['id' => $j['id']]); return; }
        if (in_array($st, ['failed', 'error'], true)) { $wpdb->update(MWC_Store::t('jobs'), ['status' => 'failed', 'error' => 'أداة السحب فشلت', 'finished' => time()], ['id' => $j['id']]); return; }
        if (!in_array($st, ['ok', 'done', 'completed', 'finished', 'success'], true)) {
            // حالة مش معروفة: نسجّلها في الشغلانة نفسها بدل ما نبلعها — عشان تبان في الشاشة ونعرف نصلحها
            $seen = $st !== '' ? $st : ('رد ' . $r['code'] . ': ' . mb_substr(preg_replace('/\s+/', ' ', (string) ($r['raw'] ?? '')), 0, 90));
            if ((string) $j['error'] !== 'حالة الأداة: ' . $seen) { $wpdb->update(MWC_Store::t('jobs'), ['error' => mb_substr('حالة الأداة: ' . $seen, 0, 250)], ['id' => $j['id']]); }
            return;
        }
        $wpdb->update(MWC_Store::t('jobs'), ['error' => ''], ['id' => $j['id']]);
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
