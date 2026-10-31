<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Daily article report: every article the SEO engine created or refreshed in a day (new, SEO variants, districts,
 * random refreshes, scheduled refreshes), with its link, keyword and an on-page SEO score.
 *
 * Score = an in-house checklist modelled on Rank Math's on-page tests (keyword in title / description / URL / first 10% /
 * headings / image alt, length, density, links, structure …). It is NOT Rank Math's own number: that one is computed in the
 * browser editor and is not available for programmatically created posts. Bands follow Rank Math: 81+ good, 51-80 ok, <=50 poor.
 *
 * Delivery (all free): e-mail (wp_mail), Telegram bot (token + chat id typed in wp-admin, never in code), and a public
 * read-only REST endpoint the GitHub workflow turns into an issue (which GitHub e-mails / pushes to the owner).
 */
class Oxygen_Daily_Report {
    const OPT = 'oxygen_report_settings';
    const HIST = 'oxygen_report_history';
    const CRON = 'oxygen_daily_report_check';
    const KEEP_DAYS = 21;

    public static $labels = [
        'new' => 'مقال جديد', 'new_seo' => 'جديد — SEO مختلف', 'new_dist' => 'جديد — حي مختلف',
        'refresh_rand' => 'تحديث عشوائي', 'refresh' => 'تحديث مجدول', 'publish' => 'نشر مسودة',
    ];
    /** check key => [weight, label shown when it fails] (weights sum to 100) */
    public static $checks = [
        'kw_title' => [10, 'الكلمة المفتاحية ليست في العنوان'],
        'kw_desc' => [8, 'الكلمة المفتاحية ليست في الوصف'],
        'kw_url' => [6, 'الكلمة المفتاحية ليست في الرابط'],
        'kw_first' => [8, 'الكلمة ليست في أول 10% من المقال'],
        'kw_content' => [4, 'الكلمة غير موجودة في المحتوى'],
        'len' => [8, 'المحتوى أقل من 600 كلمة'],
        'kw_heading' => [8, 'الكلمة ليست في عنوان فرعي'],
        'kw_alt' => [6, 'لا توجد صورة بنص بديل فيه الكلمة'],
        'density' => [6, 'كثافة الكلمة خارج 0.4%–2.5%'],
        'internal' => [6, 'لا يوجد رابط داخلي'],
        'external' => [4, 'لا يوجد رابط خارجي'],
        'desc_len' => [6, 'طول الوصف خارج 100–160 حرفًا'],
        'title_len' => [4, 'طول العنوان خارج 20–65 حرفًا'],
        'media' => [4, 'لا توجد صورة أو وسائط'],
        'paras' => [4, 'فقرات طويلة'],
        'title_start' => [4, 'العنوان لا يبدأ بالكلمة المفتاحية'],
        'structure' => [4, 'بنية المحتوى ضعيفة (عناوين/قوائم)'],
    ];

    /* ------------------------------------------------------------------ scoring (pure, testable) */

    public static function norm($s) {
        $s = html_entity_decode(strip_tags((string) $s), ENT_QUOTES, 'UTF-8');
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
        $s = preg_replace('/[\s\-_]+/u', ' ', $s);
        return trim(mb_strtolower($s));
    }

    /** @return array [score 0-100, failed check keys[]] */
    public static function score_html($title, $html, $kw, $slug, $desc, $home) {
        $nk = self::norm($kw);
        $nt = self::norm($title);
        $text = self::norm($html);
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $wc = count($words);
        $ok = [];
        $ok['kw_title'] = $nk !== '' && mb_strpos($nt, $nk) !== false;
        $ok['title_start'] = $nk !== '' && mb_strpos($nt, $nk) === 0;
        $ok['kw_desc'] = $nk !== '' && mb_strpos(self::norm($desc), $nk) !== false;
        $ok['kw_url'] = $nk !== '' && mb_strpos(self::norm(rawurldecode((string) $slug)), $nk) !== false;
        $first = implode(' ', array_slice($words, 0, max(1, (int) ceil($wc * 0.1))));
        $ok['kw_first'] = $nk !== '' && mb_strpos($first, $nk) !== false;
        $occ = $nk !== '' ? mb_substr_count($text, $nk) : 0;
        $ok['kw_content'] = $occ > 0;
        $ok['len'] = $wc >= 600;
        $dens = ($wc && $occ) ? ($occ * max(1, count(preg_split('/\s+/u', $nk))) / $wc) * 100 : 0;
        $ok['density'] = $dens >= 0.4 && $dens <= 2.5;
        $heads = '';
        if (preg_match_all('#<h[23][^>]*>(.*?)</h[23]>#isu', (string) $html, $m)) { $heads = self::norm(implode(' ', $m[1])); }
        $ok['kw_heading'] = $nk !== '' && $heads !== '' && mb_strpos($heads, $nk) !== false;
        $alt = false;
        if (preg_match_all('#<(?:img|svg)\b[^>]*?(?:alt|aria-label)=["\']([^"\']*)["\']#isu', (string) $html, $m)) {
            foreach ($m[1] as $a) { if ($nk !== '' && mb_strpos(self::norm($a), $nk) !== false) { $alt = true; break; } }
        }
        $ok['kw_alt'] = $alt;
        $internal = false; $external = false;
        $host = strtolower((string) parse_url($home, PHP_URL_HOST));
        if (preg_match_all('#<a\b[^>]*?href=["\']([^"\']+)["\']#isu', (string) $html, $m)) {
            foreach ($m[1] as $h) {
                $h = trim($h);
                if ($h === '' || $h[0] === '#' || stripos($h, 'tel:') === 0 || stripos($h, 'mailto:') === 0 || stripos($h, 'javascript:') === 0) { continue; }
                $ph = strtolower((string) parse_url($h, PHP_URL_HOST));
                if ($ph === '' || $ph === $host || ($host && substr($ph, -strlen($host) - 1) === '.' . $host)) { $internal = true; }
                elseif (stripos($h, 'wa.me') === false && stripos($h, 'whatsapp') === false) { $external = true; }
            }
        }
        $ok['internal'] = $internal;
        $ok['external'] = $external;
        $dl = mb_strlen(trim(strip_tags((string) $desc)));
        $ok['desc_len'] = $dl >= 100 && $dl <= 160;
        $tl = mb_strlen(trim((string) $title));
        $ok['title_len'] = $tl >= 20 && $tl <= 65;
        $ok['media'] = (bool) preg_match('#<(img|svg|figure|video|iframe)\b#i', (string) $html);
        $pw = [];
        if (preg_match_all('#<p\b[^>]*>(.*?)</p>#isu', (string) $html, $m)) { foreach ($m[1] as $p) { $pw[] = count(preg_split('/\s+/u', trim(strip_tags($p)), -1, PREG_SPLIT_NO_EMPTY)); } }
        $ok['paras'] = $pw ? (array_sum($pw) / count($pw)) <= 100 : false;
        $h2 = preg_match_all('#<h2\b#i', (string) $html);
        $ok['structure'] = $h2 >= 3 || (bool) preg_match('#<(ul|ol)\b#i', (string) $html);
        $score = 0; $fails = [];
        foreach (self::$checks as $k => $c) { if (!empty($ok[$k])) { $score += $c[0]; } else { $fails[] = $k; } }
        return [$score, $fails];
    }

    public static function grade($s) { return $s >= 81 ? 'ممتاز' : ($s >= 51 ? 'جيد' : 'ضعيف'); }
    public static function grade_icon($s) { return $s >= 81 ? '🟢' : ($s >= 51 ? '🟠' : '🔴'); }

    private static function post_score($id) {
        static $memo = [];
        if (isset($memo[$id])) { return $memo[$id]; }
        $p = get_post($id);
        if (!$p) { return $memo[$id] = [0, ['kw_content'], '']; }
        $kw = (string) get_post_meta($id, '_oxy_seo_kw', true);
        if ($kw === '') { $kw = (string) get_post_meta($id, 'rank_math_focus_keyword', true); }
        $desc = (string) get_post_meta($id, '_oxy_seo_desc', true);
        if ($desc === '') { $desc = (string) $p->post_excerpt; }
        // The front end wraps every engine article with links to the main site (see Oxygen_SEO_Engine::filter_content): score what a visitor/crawler gets.
        $main = class_exists('Oxygen_Domain_Guard') ? Oxygen_Domain_Guard::main_url() : '';
        $html = $p->post_content . ($main ? '<a href="' . $main . '">.</a>' : '');
        list($s, $f) = self::score_html($p->post_title, $html, $kw, $p->post_name, $desc, home_url('/'));
        return $memo[$id] = [$s, $f, $kw];
    }

    /* ------------------------------------------------------------------ building the report */

    public static function day_range($day) {
        $tz = wp_timezone();
        $start = new DateTimeImmutable($day . ' 00:00:00', $tz);
        return [$start->getTimestamp(), $start->modify('+1 day')->getTimestamp() - 1];
    }
    public static function resolve_day($day) {
        $day = (string) $day;
        if ($day === '' || $day === 'last') { return wp_date('Y-m-d', time() - DAY_IN_SECONDS); }
        if ($day === 'today') { return wp_date('Y-m-d'); }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? $day : wp_date('Y-m-d', time() - DAY_IN_SECONDS);
    }

    /** Live report for a site-local day, straight from the engine log. */
    public static function build($day) {
        list($a, $b) = self::day_range($day);
        $rows = [];
        foreach ((array) get_option(Oxygen_SEO_Engine::LOG, []) as $r) {
            $ts = (int) ($r['ts'] ?? 0);
            if ($ts < $a || $ts > $b || empty($r['id']) || !isset(self::$labels[$r['a'] ?? ''])) { continue; }
            list($s, $f, $kw) = self::post_score((int) $r['id']);
            $rows[] = ['id' => (int) $r['id'], 'a' => $r['a'], 'ts' => $ts, 't' => (string) ($r['t'] ?? ''), 'l' => (string) ($r['l'] ?? ''), 'kw' => $kw, 's' => $s, 'f' => $f];
        }
        usort($rows, function ($x, $y) { return $x['ts'] <=> $y['ts']; });
        return self::summarize($day, $rows);
    }

    public static function summarize($day, $rows) {
        $by = []; $sum = 0; $g = ['ممتاز' => 0, 'جيد' => 0, 'ضعيف' => 0]; $fc = [];
        foreach ($rows as $r) {
            $by[$r['a']] = ($by[$r['a']] ?? 0) + 1; $sum += $r['s']; $g[self::grade($r['s'])]++;
            foreach ($r['f'] as $k) { $fc[$k] = ($fc[$k] ?? 0) + 1; }
        }
        arsort($fc);
        $n = count($rows);
        $weak = $rows; usort($weak, function ($x, $y) { return $x['s'] <=> $y['s']; });
        return [
            'site' => wp_parse_url(home_url('/'), PHP_URL_HOST), 'brand' => Oxygen_SEO_Engine::brand(), 'day' => $day, 'total' => $n,
            'by_type' => $by, 'avg' => $n ? round($sum / $n, 1) : 0, 'grades' => $g, 'top_fails' => array_slice($fc, 0, 6, true),
            'weakest' => array_slice($weak, 0, 10), 'rows' => $rows,
        ];
    }

    public static function snapshot($day, $live = null) {
        $h = get_option(self::HIST, []);
        if (is_array($h) && isset($h[$day])) { return self::summarize($day, $h[$day]); }
        return $live ?: self::build($day);
    }

    private static function save_snapshot($rep) {
        $h = get_option(self::HIST, []); $h = is_array($h) ? $h : [];
        $h[$rep['day']] = $rep['rows'];
        krsort($h); $h = array_slice($h, 0, self::KEEP_DAYS, true);
        update_option(self::HIST, $h, false);
    }

    /* ------------------------------------------------------------------ formats */

    private static function type_line($rep) {
        $parts = [];
        foreach (self::$labels as $k => $l) { if (!empty($rep['by_type'][$k])) { $parts[] = $l . ': ' . $rep['by_type'][$k]; } }
        return $parts ? implode(' · ', $parts) : 'لا توجد عمليات';
    }
    private static function fail_label($k) { return self::$checks[$k][1] ?? $k; }

    public static function markdown($rep, $max = 60000) {
        $o = "## 📊 تقرير مقالات {$rep['brand']} — {$rep['day']}\n\n";
        $o .= "الموقع: https://{$rep['site']}/\n\n";
        $o .= "- **إجمالي العمليات:** {$rep['total']}\n- **التفصيل:** " . self::type_line($rep) . "\n";
        $o .= "- **متوسط تقييم SEO:** {$rep['avg']} / 100 — 🟢 {$rep['grades']['ممتاز']} ممتاز · 🟠 {$rep['grades']['جيد']} جيد · 🔴 {$rep['grades']['ضعيف']} ضعيف\n\n";
        $o .= "> التقييم داخلي بمعايير مشابهة لاختبار Rank Math (الكلمة في العنوان/الوصف/الرابط/المقدمة/العناوين الفرعية/الصور، الطول، الكثافة، الروابط، البنية). ليس رقم Rank Math الرسمي.\n\n";
        if ($rep['top_fails']) {
            $o .= "### أكثر نقاط الضعف تكرارًا\n";
            foreach ($rep['top_fails'] as $k => $c) { $o .= "- " . self::fail_label($k) . " — $c مقال\n"; }
            $o .= "\n";
        }
        if ($rep['weakest']) {
            $o .= "### أضعف 10 مقالات\n";
            foreach ($rep['weakest'] as $r) { $o .= "- " . self::grade_icon($r['s']) . " {$r['s']} — [" . str_replace(['[', ']'], '', $r['t']) . "]({$r['l']}) — «{$r['kw']}»\n"; }
            $o .= "\n";
        }
        $o .= "### كل المقالات\n\n| # | النوع | الكلمة المفتاحية | التقييم | المقال |\n|---|---|---|---|---|\n";
        $i = 0; $cut = false;
        foreach ($rep['rows'] as $r) {
            $i++;
            $line = "| $i | " . (self::$labels[$r['a']] ?? $r['a']) . " | " . str_replace('|', '/', $r['kw']) . " | " . self::grade_icon($r['s']) . " {$r['s']} | [" . str_replace(['[', ']', '|'], '', $r['t']) . "]({$r['l']}) |\n";
            if (strlen($o) + strlen($line) > $max - 200) { $cut = true; break; }
            $o .= $line;
        }
        if ($cut) { $o .= "\n… اختُصرت القائمة لطول الرسالة؛ الملف الكامل (CSV) في مرفقات التشغيل.\n"; }
        return $o;
    }

    public static function csv($rep) {
        $f = fopen('php://temp', 'w+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, ['#', 'التاريخ', 'الساعة', 'النوع', 'العنوان', 'الرابط', 'الكلمة المفتاحية', 'التقييم', 'الدرجة', 'نقاط الضعف']);
        $i = 0;
        foreach ($rep['rows'] as $r) {
            $i++;
            fputcsv($f, [$i, wp_date('Y-m-d', $r['ts']), wp_date('H:i', $r['ts']), self::$labels[$r['a']] ?? $r['a'], $r['t'], $r['l'], $r['kw'], $r['s'], self::grade($r['s']), implode(' | ', array_map([__CLASS__, 'fail_label'], $r['f']))]);
        }
        rewind($f); $out = stream_get_contents($f); fclose($f);
        return $out;
    }

    public static function html($rep) {
        $h = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;max-width:900px">';
        $h .= '<h2>📊 تقرير مقالات ' . esc_html($rep['brand']) . ' — ' . esc_html($rep['day']) . '</h2>';
        $h .= '<p><b>الموقع:</b> <a href="https://' . esc_attr($rep['site']) . '/">' . esc_html($rep['site']) . '</a><br><b>إجمالي العمليات:</b> ' . (int) $rep['total'] . '<br><b>التفصيل:</b> ' . esc_html(self::type_line($rep)) . '<br><b>متوسط تقييم SEO:</b> ' . esc_html($rep['avg']) . ' / 100 — 🟢 ' . (int) $rep['grades']['ممتاز'] . ' · 🟠 ' . (int) $rep['grades']['جيد'] . ' · 🔴 ' . (int) $rep['grades']['ضعيف'] . '</p>';
        $h .= '<p style="color:#666;font-size:12px">التقييم داخلي بمعايير مشابهة لاختبار Rank Math، وليس رقم Rank Math الرسمي.</p>';
        if ($rep['top_fails']) { $h .= '<h3>أكثر نقاط الضعف تكرارًا</h3><ul>'; foreach ($rep['top_fails'] as $k => $c) { $h .= '<li>' . esc_html(self::fail_label($k)) . ' — ' . (int) $c . ' مقال</li>'; } $h .= '</ul>'; }
        $h .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:13px"><tr style="background:#eef"><th>#</th><th>النوع</th><th>الكلمة</th><th>التقييم</th><th>المقال</th></tr>';
        $i = 0;
        foreach ($rep['rows'] as $r) {
            $i++;
            $h .= '<tr><td>' . $i . '</td><td>' . esc_html(self::$labels[$r['a']] ?? $r['a']) . '</td><td>' . esc_html($r['kw']) . '</td><td>' . self::grade_icon($r['s']) . ' ' . (int) $r['s'] . '</td><td><a href="' . esc_url($r['l']) . '">' . esc_html($r['t']) . '</a></td></tr>';
        }
        return $h . '</table></div>';
    }

    public static function telegram_text($rep) {
        $t = '📊 <b>تقرير مقالات ' . esc_html($rep['brand']) . '</b> — ' . esc_html($rep['day']) . "\n" . esc_html($rep['site']) . "\n\n";
        $t .= 'إجمالي العمليات: <b>' . (int) $rep['total'] . "</b>\n" . esc_html(self::type_line($rep)) . "\n";
        $t .= 'متوسط تقييم SEO: <b>' . esc_html($rep['avg']) . '</b>/100 — 🟢 ' . (int) $rep['grades']['ممتاز'] . ' · 🟠 ' . (int) $rep['grades']['جيد'] . ' · 🔴 ' . (int) $rep['grades']['ضعيف'] . "\n";
        if ($rep['top_fails']) { $t .= "\nأكثر نقاط الضعف:\n"; foreach (array_slice($rep['top_fails'], 0, 4, true) as $k => $c) { $t .= '• ' . esc_html(self::fail_label($k)) . ' (' . (int) $c . ")\n"; } }
        if ($rep['weakest']) { $t .= "\nأضعف المقالات:\n"; foreach (array_slice($rep['weakest'], 0, 5) as $r) { $t .= self::grade_icon($r['s']) . ' ' . (int) $r['s'] . ' <a href="' . esc_url($r['l']) . '">' . esc_html(mb_substr($r['t'], 0, 60)) . "</a>\n"; } }
        return $t . "\nالقائمة الكاملة بالروابط والتقييمات في ملف CSV المرفق.";
    }

    /* ------------------------------------------------------------------ delivery */

    public static function settings() {
        $d = ['email' => 1, 'email_to' => '', 'tg_token' => '', 'tg_chat' => '', 'hour' => 5, 'last_sent' => ''];
        $s = get_option(self::OPT, []);
        return array_merge($d, is_array($s) ? $s : []);
    }

    private static function tg_call($token, $method, $body, $headers = []) {
        return wp_remote_post('https://api.telegram.org/bot' . rawurlencode($token) . '/' . $method, ['timeout' => 30, 'headers' => $headers, 'body' => $body]);
    }

    /** @return array ['email' => string, 'telegram' => string] human-readable result per channel */
    public static function deliver($rep) {
        $s = self::settings(); $res = [];
        if (!empty($s['email'])) {
            $to = $s['email_to'] !== '' ? $s['email_to'] : get_option('admin_email');
            $ok = $to && wp_mail($to, '📊 تقرير مقالات ' . $rep['brand'] . ' — ' . $rep['day'] . ' (' . $rep['total'] . ' عملية)', self::html($rep), ['Content-Type: text/html; charset=UTF-8'], []);
            $res['email'] = $ok ? 'أُرسل إلى ' . $to : 'تعذر إرسال البريد (تحقق من إعدادات البريد في الاستضافة)';
        }
        if ($s['tg_token'] !== '' && $s['tg_chat'] !== '') {
            $r = self::tg_call($s['tg_token'], 'sendMessage', ['chat_id' => $s['tg_chat'], 'text' => self::telegram_text($rep), 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true']);
            $code = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
            if ($code === 200) {
                $b = '----oxyrep' . wp_generate_password(12, false);
                $name = 'report-' . $rep['site'] . '-' . $rep['day'] . '.csv';
                $body = "--$b\r\nContent-Disposition: form-data; name=\"chat_id\"\r\n\r\n{$s['tg_chat']}\r\n--$b\r\nContent-Disposition: form-data; name=\"document\"; filename=\"$name\"\r\nContent-Type: text/csv\r\n\r\n" . self::csv($rep) . "\r\n--$b--\r\n";
                $r2 = self::tg_call($s['tg_token'], 'sendDocument', $body, ['Content-Type' => 'multipart/form-data; boundary=' . $b]);
                $code2 = is_wp_error($r2) ? 0 : (int) wp_remote_retrieve_response_code($r2);
                $res['telegram'] = $code2 === 200 ? 'أُرسل (ملخص + ملف CSV)' : 'أُرسل الملخص وتعذر إرسال الملف (HTTP ' . $code2 . ')';
            } else {
                $res['telegram'] = 'تعذر الإرسال (HTTP ' . $code . ') — تأكد من التوكن ومن أنك بدأت محادثة مع البوت';
            }
        }
        return $res;
    }

    /** Hourly check: once the configured hour has passed, send yesterday's report (catches up after missed cron runs). */
    public static function maybe_send() {
        $s = self::settings();
        $yesterday = self::resolve_day('last');
        if ($s['last_sent'] === $yesterday || (int) wp_date('G') < (int) $s['hour']) { return; }
        if (!$s['email'] && ($s['tg_token'] === '' || $s['tg_chat'] === '')) { return; }
        $rep = self::build($yesterday);
        self::save_snapshot($rep);
        self::deliver($rep);
        $s['last_sent'] = $yesterday;
        update_option(self::OPT, $s, false);
    }

    /* ------------------------------------------------------------------ WP glue */

    public static function boot() {
        add_action('init', function () { if (!wp_next_scheduled(self::CRON)) { wp_schedule_event(time() + 600, 'hourly', self::CRON); } }, 5);
        add_action(self::CRON, [__CLASS__, 'maybe_send']);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('admin_menu', [__CLASS__, 'menu'], 20);
        add_action('admin_post_oxy_report_save', [__CLASS__, 'h_save']);
        add_action('admin_post_oxy_report_send', [__CLASS__, 'h_send']);
    }

    /** Public, read-only: titles, links, keywords and scores of published articles are already public. */
    public static function routes() {
        register_rest_route('oxygen/v1', '/daily-report', [
            'methods' => 'GET', 'permission_callback' => '__return_true',
            'callback' => function ($req) {
                $day = self::resolve_day($req->get_param('day'));
                $key = 'oxy_rep_' . md5($day . '|' . get_option(self::HIST . '_v', '1'));
                $rep = get_transient($key);
                if (!$rep) { $rep = self::snapshot($day); set_transient($key, $rep, 600); }
                $out = $rep; unset($out['rows']);
                $out['markdown'] = self::markdown($rep);
                $out['csv'] = self::csv($rep);
                $out['rows_count'] = count($rep['rows']);
                return rest_ensure_response($out);
            },
        ]);
    }

    public static function menu() {
        add_submenu_page('oxygen-seo', 'تقارير المقالات اليومية', 'تقارير يومية', 'manage_options', 'oxygen-report', [__CLASS__, 'page']);
    }
    private static function back($msg) { wp_safe_redirect(add_query_arg('oxy_msg', rawurlencode($msg), admin_url('admin.php?page=oxygen-report'))); exit; }
    public static function h_save() {
        if (!current_user_can('manage_options')) { wp_die('غير مصرح.'); }
        check_admin_referer('oxy_report_save');
        $s = self::settings();
        $s['email'] = !empty($_POST['email']) ? 1 : 0;
        $s['email_to'] = sanitize_email(wp_unslash($_POST['email_to'] ?? ''));
        $s['hour'] = max(0, min(23, (int) ($_POST['hour'] ?? 5)));
        $s['tg_chat'] = preg_replace('/[^\d\-@A-Za-z_]/', '', (string) wp_unslash($_POST['tg_chat'] ?? ''));
        $tok = trim((string) wp_unslash($_POST['tg_token'] ?? ''));
        if ($tok !== '') { $s['tg_token'] = preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', $tok) ? $tok : $s['tg_token']; }
        if (!empty($_POST['tg_clear'])) { $s['tg_token'] = ''; }
        update_option(self::OPT, $s, false);
        self::back('تم حفظ الإعدادات.');
    }
    public static function h_send() {
        if (!current_user_can('manage_options')) { wp_die('غير مصرح.'); }
        check_admin_referer('oxy_report_send');
        $day = self::resolve_day(sanitize_text_field(wp_unslash($_POST['day'] ?? 'today')));
        $rep = self::build($day);
        $res = self::deliver($rep);
        self::back('تقرير ' . $day . ' (' . $rep['total'] . ' عملية): ' . ($res ? implode(' — ', array_map(function ($k, $v) { return ($k === 'email' ? 'البريد' : 'تلجرام') . ': ' . $v; }, array_keys($res), $res)) : 'لا توجد قناة مفعّلة'));
    }

    public static function page() {
        if (!current_user_can('manage_options')) { return; }
        $s = self::settings();
        $day = isset($_GET['day']) ? self::resolve_day(sanitize_text_field(wp_unslash($_GET['day']))) : self::resolve_day('today');
        $rep = self::snapshot($day, self::build($day));
        echo '<div class="wrap" dir="rtl"><h1>تقارير المقالات اليومية</h1>';
        if (!empty($_GET['oxy_msg'])) { echo '<div class="notice notice-success"><p>' . esc_html(wp_unslash($_GET['oxy_msg'])) . '</p></div>'; }
        echo '<p>يُرسل يوميًا بعد الساعة المحددة تقرير اليوم السابق: كل مقال أُنشئ أو حُدّث مع رابطه وكلمته المفتاحية وتقييم SEO. التقييم داخلي بمعايير مشابهة لـ Rank Math (ليس رقمه الرسمي).</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="oxy_report_save">';
        wp_nonce_field('oxy_report_save');
        echo '<table class="form-table"><tr><th>البريد</th><td><label><input type="checkbox" name="email" value="1" ' . checked(!empty($s['email']), true, false) . '> إرسال التقرير بالبريد</label> <input type="email" name="email_to" value="' . esc_attr($s['email_to']) . '" placeholder="' . esc_attr(get_option('admin_email')) . '" style="min-width:260px"></td></tr>';
        echo '<tr><th>تلجرام (مجاني)</th><td><input type="password" name="tg_token" value="" autocomplete="off" placeholder="' . ($s['tg_token'] !== '' ? 'التوكن محفوظ — اتركه فارغًا للإبقاء عليه' : '123456:ABC… توكن البوت من @BotFather') . '" style="min-width:360px"><br><input type="text" name="tg_chat" value="' . esc_attr($s['tg_chat']) . '" placeholder="Chat ID (رقم محادثتك مع البوت)" style="min-width:260px"> ' . ($s['tg_token'] !== '' ? '<label><input type="checkbox" name="tg_clear" value="1"> حذف التوكن</label>' : '') . '<p class="description">أنشئ بوتًا من @BotFather ثم أرسل له رسالة، واعرف رقم محادثتك من @userinfobot. لا يُحفظ التوكن إلا في قاعدة بيانات موقعك.</p></td></tr>';
        echo '<tr><th>ساعة الإرسال (بتوقيت الموقع)</th><td><input type="number" name="hour" value="' . (int) $s['hour'] . '" min="0" max="23"></td></tr></table>';
        submit_button('حفظ');
        echo '</form><div style="display:flex;gap:8px;flex-wrap:wrap">';
        foreach ([['today', 'أرسل تقرير اليوم الآن'], ['last', 'أرسل تقرير أمس الآن']] as $b) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="oxy_report_send"><input type="hidden" name="day" value="' . esc_attr($b[0]) . '">';
            wp_nonce_field('oxy_report_send'); submit_button($b[1], 'secondary', '', false); echo '</form>';
        }
        echo '</div>';
        $hist = array_keys((array) get_option(self::HIST, []));
        echo '<h2>تقرير ' . esc_html($day) . '</h2><p>';
        foreach (array_unique(array_merge([self::resolve_day('today')], $hist)) as $d) { echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=oxygen-report&day=' . $d)) . '">' . esc_html($d) . '</a> '; }
        echo '</p><p><b>الإجمالي:</b> ' . (int) $rep['total'] . ' — ' . esc_html(self::type_line($rep)) . ' — <b>المتوسط:</b> ' . esc_html($rep['avg']) . '/100 (🟢 ' . (int) $rep['grades']['ممتاز'] . ' · 🟠 ' . (int) $rep['grades']['جيد'] . ' · 🔴 ' . (int) $rep['grades']['ضعيف'] . ')</p>';
        if ($rep['top_fails']) { echo '<p><b>أكثر نقاط الضعف:</b> ' . esc_html(implode(' — ', array_map(function ($k, $c) { return self::fail_label($k) . " ($c)"; }, array_keys($rep['top_fails']), $rep['top_fails']))) . '</p>'; }
        echo '<table class="widefat striped"><thead><tr><th>#</th><th>الساعة</th><th>النوع</th><th>الكلمة المفتاحية</th><th>التقييم</th><th>المقال</th></tr></thead><tbody>';
        $i = 0;
        foreach (array_slice($rep['rows'], 0, 400) as $r) {
            $i++;
            echo '<tr><td>' . $i . '</td><td>' . esc_html(wp_date('H:i', $r['ts'])) . '</td><td>' . esc_html(self::$labels[$r['a']] ?? $r['a']) . '</td><td>' . esc_html($r['kw']) . '</td><td>' . self::grade_icon($r['s']) . ' ' . (int) $r['s'] . '</td><td><a href="' . esc_url($r['l']) . '" target="_blank">' . esc_html($r['t']) . '</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
