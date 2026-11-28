<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «حملات الاستقطاب» — one prospects base, a daily invitation plan, sent only from the outreach numbers
 * (owner order 2026-11-21).
 *
 * The base is the leads table (Mutqan_Leads, {prefix}mutqan_leads), fed from three doors:
 *   maps      — Google Maps Scraper Kit (GitHub Actions → /leads/import), businesses in Makkah and Jeddah;
 *   system    — customers who ordered from us before (orders), invited back;
 *   whatsapp  — people who wrote to our lines and never ordered (wa_leads).
 * Our own numbers never enter it (Mutqan_Lines), and «إيقاف» from anyone takes him out for good.
 *
 * Sending happens on the VPS WhatsApp hub, only from lines whose number is an outreach number. Every few minutes the
 * hub asks for a batch (op outreach_batch with its outreach lines and what each sent today); this answers with who
 * gets which message from which line:
 *   - each line has its own daily ceiling, starting low and rising a few messages a day (a new number that blasts
 *     hundreds on day one is banned), and the hub waits a random minute or more between two messages of one line;
 *   - a prospect keeps the line that first wrote to him, so his reply lands where the conversation is;
 *   - nobody hears from us twice inside the cooldown, nor more than maxTouches times ever;
 *   - the message rotates between several texts and between the network's company names, so no two days look alike.
 * A reply on an outreach line is a normal customer conversation (the hub's bot takes it like any other).
 */
final class Mutqan_Outreach {
    const OPT = 'mutqan_outreach';
    const STATE = 'mutqan_outreach_state';   // per line: first day used, sent counts
    const CRON = 'mutqan_outreach_sync';
    const PAGE = 'mutqan-outreach';
    const STOP = '/^(ايقاف|إيقاف|وقف|توقف|الغاء الاشتراك|إلغاء الاشتراك|لا ترسل|لاترسل|stop|unsubscribe|بلوك|لا تراسلني|ما ابغى رسايل|ما ابي رسايل)$/u';

    public static function defaults() {
        return [
            'on' => 1,
            'from' => 13, 'to' => 20,          // Riyadh, 1 pm–8 pm (owner, 2026-11-21): people are awake and it is the hours of breakdowns
            'start' => 20, 'ramp' => 5, 'max' => 60,   // per line: 20 on its first day, +5 a day, never above 60
            'perCall' => 4,                    // messages handed to one line per hub request (the hub paces them)
            'cooldown' => 21, 'maxTouches' => 3,
            'sources' => ['system' => 1, 'whatsapp' => 1, 'maps' => 1],
            'cold' => "السلام عليكم ورحمة الله 🌷\nمعاك {brand} للصيانة في {city}.\nعندنا عرض على {field}: صيانة دورية وغسيل وفحص مجاني لحد جهازين، وفنيين يوصلوك في نفس اليوم.\nتحب نحجزلك موعد؟ رد بكلمة *حجز* 👍\n\nلو مش حابب توصلك رسايلنا اكتب *إيقاف*.\n---\nمساء الخير 🌷 {brand} في {city}.\nالصيف داخل والضغط على {field} بيزيد — إحنا متخصصين في الصيانة والغسيل والإصلاح بضمان.\nابعت *حجز* ونرتب معاك أنسب وقت.\n\nللإيقاف اكتب *إيقاف*.\n---\nأهلًا 👋 من {brand}.\nلو عندك أي مشكلة في {field} (ما يبرد، تسريب، صوت، فاتورة كهربا عالية) فنيينا جاهزين اليوم.\nاكتب *حجز* أو اسأل عن السعر وهنرد عليك فورًا.\n\nللإيقاف اكتب *إيقاف*.",
            'customer' => "أهلًا بيك تاني{name} 🌷 معاك {brand}.\nوحشتنا! آخر مرة خدمناك في «{last}». لو حابب نعمل صيانة أو غسيل للمكيفات قبل الموسم، عندنا خصم لعملائنا.\nابعت *حجز* ونرتبلك موعد.\n\nللإيقاف اكتب *إيقاف*.\n---\nالسلام عليكم{name} 🌷 معاك {brand}، بنطمن عليك.\nآخر مرة عملنالك «{last}».\nالمكيفات محتاجة غسيل كل كام شهر عشان تبرد كويس وتوفر كهربا — تحب نحجزلك؟ رد بـ *حجز*.\n\nللإيقاف اكتب *إيقاف*.",
        ];
    }
    public static function settings() {
        $s = get_option(self::OPT, []); $s = is_array($s) ? $s : [];
        // owner, 2026-11-21: the sending window moved to 13:00–20:00 — once, over whatever was saved before
        if (($s['hoursV'] ?? 0) < 2) { $s['from'] = 13; $s['to'] = 20; $s['hoursV'] = 2; if (function_exists('update_option')) { update_option(self::OPT, $s, false); } }
        $d = self::defaults();
        $s['sources'] = array_merge($d['sources'], is_array($s['sources'] ?? null) ? $s['sources'] : []);
        return array_merge($d, $s);
    }
    private static function here() { return !class_exists('Oxygen_Network') || Oxygen_Network::is_hub(); }
    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user']; }

    public static function boot() {
        add_action(self::CRON, [__CLASS__, 'sync']);
        add_action('init', function () { if (self::here() && !wp_next_scheduled(self::CRON)) { wp_schedule_event(time() + 900, 'twicedaily', self::CRON); } }, 9);
        add_action('admin_menu', function () { if (self::here()) { add_options_page('حملات الاستقطاب والأرقام', 'حملات الاستقطاب والأرقام', 'manage_options', self::PAGE, [__CLASS__, 'page']); } });
        add_action('admin_post_mutqan_outreach_save', [__CLASS__, 'h_save']);
        add_action('admin_post_mutqan_outreach_sync', [__CLASS__, 'h_sync']);
        add_action('admin_post_mutqan_lines_save', [__CLASS__, 'h_lines']);
    }

    /* ------------------------------------------------------------------ the base: three doors in */

    /** Customers from the system and people who wrote and never ordered join the base (once, by phone). */
    public static function sync() {
        if (!self::here() || !class_exists('Mutqan_Leads')) { return ['ok' => false]; }
        Mutqan_Leads::install();
        global $wpdb; $t = Mutqan_Leads::table(); $now = current_time('mysql', true);
        $s = self::settings(); $add = ['system' => 0, 'whatsapp' => 0];
        $put = function ($phone, $source, $name, $category, $city) use ($wpdb, $t, $now, &$add) {
            $p = Mutqan_Leads::phone($phone);
            if ($p === '' || (class_exists('Mutqan_Lines') && Mutqan_Lines::is_ours($p))) { return; }
            $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE phone = %s", $p));
            if ($id) { if ($source === 'system') { $wpdb->update($t, ['source' => 'system', 'category' => $category], ['id' => (int) $id]); } return; }
            $wpdb->insert($t, ['phone' => $p, 'name' => mb_substr((string) $name, 0, 190), 'city' => $city, 'field' => 'ac', 'category' => mb_substr($category, 0, 120), 'mobile' => strpos($p, '9665') === 0 ? 1 : 0,
                'status' => 'new', 'source' => $source, 'first_seen' => $now, 'last_seen' => $now]);
            $add[$source]++;
        };
        if (!empty($s['sources']['system']) && class_exists('Mutqan_Store')) {
            foreach ((array) Mutqan_Store::select('orders', [], ['limit' => 3000, 'order' => 'desc'], self::sys()) as $o) {
                if (empty($o['clientPhone']) || in_array((string) ($o['source'] ?? ''), ['test', 'import'], true)) { continue; }
                $city = preg_match('/جد[ةه]/u', (string) ($o['address'] ?? '') . ' ' . (string) ($o['city'] ?? '')) ? 'jeddah' : 'makkah';
                $put($o['clientPhone'], 'system', (string) ($o['clientName'] ?? ''), mb_substr('عميل سابق: ' . (string) ($o['service'] ?? ''), 0, 120), $city);
            }
        }
        if (!empty($s['sources']['whatsapp']) && class_exists('Mutqan_Store')) {
            foreach ((array) Mutqan_Store::select('wa_leads', [], ['limit' => 2000, 'order' => 'desc'], self::sys()) as $l) {
                if (!empty($l['phone'])) { $put($l['phone'], 'whatsapp', (string) ($l['name'] ?? ''), 'كلمنا على واتساب', 'makkah'); }
            }
        }
        update_option(self::STATE . '_sync', ['at' => gmdate('c')] + $add, false);
        return ['ok' => true] + $add;
    }

    /* ------------------------------------------------------------------ the daily plan */

    /** Riyadh hour now (the hub and the customers live on Saudi time). */
    public static function hour($t = null) { $d = new DateTime('@' . ($t === null ? time() : (int) $t)); $d->setTimezone(new DateTimeZone('Asia/Riyadh')); return (int) $d->format('G'); }
    public static function today($t = null) { $d = new DateTime('@' . ($t === null ? time() : (int) $t)); $d->setTimezone(new DateTimeZone('Asia/Riyadh')); return $d->format('Y-m-d'); }

    /** This line's ceiling today: start on its first day, +ramp each day after, never above max. */
    public static function cap_for($lineKey, $t = null) {
        $s = self::settings(); $st = get_option(self::STATE, []); $st = is_array($st) ? $st : [];
        $first = (string) ($st[$lineKey]['first'] ?? '');
        if ($first === '') { return (int) $s['start']; }
        $days = max(0, (int) floor((strtotime(self::today($t)) - strtotime($first)) / 86400));
        return (int) min((int) $s['max'], (int) $s['start'] + $days * (int) $s['ramp']);
    }

    /**
     * The hub's question: lines = [{id, phone, sentToday}] (its connected lines). Only those whose number is one of our
     * outreach numbers take part. Returns {items:[{id, line, phone, text}], why}.
     */
    public static function batch($lines, $t = null) {
        $s = self::settings();
        if (!self::here()) { return ['items' => [], 'why' => 'not_hub']; }
        if (empty($s['on'])) { return ['items' => [], 'why' => 'off']; }
        $h = self::hour($t);
        if ($h < (int) $s['from'] || $h >= (int) $s['to']) { return ['items' => [], 'why' => 'hours']; }
        $ours = [];
        foreach ((array) $lines as $l) {
            $row = class_exists('Mutqan_Lines') ? Mutqan_Lines::ours((string) ($l['phone'] ?? '')) : null;
            if (!$row || $row['role'] !== 'outreach' || $row['status'] !== 'active') { continue; }
            $k = substr(preg_replace('/\D/', '', $row['phone']), -9);
            $left = min((int) $s['perCall'], self::cap_for($k, $t) - (int) ($l['sentToday'] ?? 0));
            if ($left > 0) { $ours[] = ['id' => (string) ($l['id'] ?? ''), 'key' => $k, 'left' => $left]; }
        }
        if (!$ours) { return ['items' => [], 'why' => 'no_outreach_line_or_cap']; }
        Mutqan_Leads::install();
        global $wpdb; $tb = Mutqan_Leads::table();
        $need = array_sum(array_column($ours, 'left'));
        $since = gmdate('Y-m-d H:i:s', ($t === null ? time() : (int) $t) - (int) $s['cooldown'] * 86400);
        $src = array_keys(array_filter($s['sources']));
        if (!$src) { return ['items' => [], 'why' => 'no_sources']; }
        $ph = implode(',', array_fill(0, count($src), '%s'));
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $tb WHERE mobile = 1 AND status IN ('new','sent') AND source IN ($ph) AND sent_count < %d AND (last_sent IS NULL OR last_sent < %s)
             ORDER BY CASE source WHEN 'system' THEN 0 WHEN 'whatsapp' THEN 1 ELSE 2 END, (last_sent IS NULL) DESC, last_sent ASC, id ASC LIMIT %d",
            array_merge($src, [(int) $s['maxTouches'], $since, $need * 3])), ARRAY_A);
        $busy = self::busy_phones();
        $items = []; $st = get_option(self::STATE, []); $st = is_array($st) ? $st : [];
        $now = gmdate('Y-m-d H:i:s', $t === null ? time() : (int) $t);
        foreach ($rows as $r) {
            if (count($items) >= $need) { break; }
            if (class_exists('Mutqan_Lines') && Mutqan_Lines::is_ours($r['phone'])) { $wpdb->update($tb, ['status' => 'ours'], ['id' => (int) $r['id']]); continue; }
            if (isset($busy[substr($r['phone'], -9)])) { continue; }   // an open order: the room talks to him, not a campaign
            // sticky: the line that wrote to him first, if it is here and has room; otherwise the line with most room
            $pick = null;
            foreach ($ours as $i => $l) { if ($l['left'] > 0 && $r['line'] !== '' && $l['key'] === $r['line']) { $pick = $i; break; } }
            if ($pick === null) { if ($r['line'] !== '' && in_array($r['line'], array_column($ours, 'key'), true)) { continue; } $max = 0; foreach ($ours as $i => $l) { if ($l['left'] > $max) { $max = $l['left']; $pick = $i; } } }
            if ($pick === null) { break; }
            $l = &$ours[$pick]; $l['left']--;
            $items[] = ['id' => (int) $r['id'], 'line' => $l['id'], 'phone' => $r['phone'], 'text' => self::text($r, $t)];
            $wpdb->update($tb, ['status' => 'sent', 'line' => $l['key'], 'sent_count' => (int) $r['sent_count'] + 1, 'last_sent' => $now], ['id' => (int) $r['id']]);
            if (empty($st[$l['key']]['first'])) { $st[$l['key']]['first'] = self::today($t); }
            $st[$l['key']]['sent'][self::today($t)] = (int) ($st[$l['key']]['sent'][self::today($t)] ?? 0) + 1;
            $st[$l['key']]['sent'] = array_slice($st[$l['key']]['sent'], -14, null, true);
            unset($l);
        }
        update_option(self::STATE, $st, false);
        return ['items' => $items, 'why' => $items ? '' : 'nobody_due'];
    }

    /** Phones with an order that is still open: no campaign message while the room is serving them. */
    private static function busy_phones() {
        $b = [];
        if (!class_exists('Mutqan_Store')) { return $b; }
        foreach ((array) Mutqan_Store::select('orders', ['status' => ['in' => ['new', 'forced', 'inhouse', 'assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress']]], ['limit' => 500], self::sys()) as $o) {
            $k = substr(preg_replace('/\D/', '', (string) ($o['clientPhone'] ?? '')), -9); if ($k !== '') { $b[$k] = 1; }
        }
        return $b;
    }

    /** One of the owner's texts, picked by the prospect and the day; the company name rotates over the network. */
    public static function text($r, $t = null) {
        $s = self::settings();
        $kind = ($r['source'] ?? '') === 'system' ? 'customer' : 'cold';
        $opts = array_values(array_filter(array_map('trim', preg_split('/^\s*---\s*$/m', (string) $s[$kind]))));
        if (!$opts) { $opts = array_values(array_filter(array_map('trim', preg_split('/^\s*---\s*$/m', self::defaults()[$kind])))); }
        $day = (int) floor(($t === null ? time() : (int) $t) / 86400);
        $seed = (int) sprintf('%u', crc32((string) ($r['phone'] ?? '')));
        $tx = $opts[($seed + $day + (int) ($r['sent_count'] ?? 0)) % count($opts)];
        $brands = [];
        if (class_exists('Oxygen_Network')) { foreach (Oxygen_Network::registry() as $id => $b) { $brands[] = (string) $b['name']; } }
        $brand = $brands ? $brands[($seed + (int) ($r['sent_count'] ?? 0)) % count($brands)] : 'أكسجين';
        $last = trim(preg_replace('/^عميل سابق:\s*/u', '', (string) ($r['category'] ?? ''))) ?: 'صيانة المكيفات';
        $name = trim((string) ($r['name'] ?? ''));
        return strtr($tx, ['{brand}' => $brand, '{city}' => ($r['city'] ?? '') === 'jeddah' ? 'جدة' : 'مكة', '{field}' => ($r['field'] ?? '') === 'cold' ? 'غرف التبريد والتجميد' : 'المكيفات',
            '{name}' => $name !== '' && mb_strlen($name) <= 25 && !preg_match('/\d/', $name) ? ' يا ' . $name : '', '{last}' => mb_substr($last, 0, 60)]);
    }

    /** «إيقاف»: out of every list, for good. */
    public static function optout($phone) {
        $p = class_exists('Mutqan_Leads') ? Mutqan_Leads::phone($phone) : '';
        if ($p === '') { return ['ok' => false]; }
        Mutqan_Leads::install();
        global $wpdb; $t = Mutqan_Leads::table(); $now = current_time('mysql', true);
        if (!$wpdb->update($t, ['status' => 'optout'], ['phone' => $p])) {
            $wpdb->insert($t, ['phone' => $p, 'status' => 'optout', 'source' => 'whatsapp', 'mobile' => strpos($p, '9665') === 0 ? 1 : 0, 'first_seen' => $now, 'last_seen' => $now]);
        }
        return ['ok' => true];
    }
    public static function is_stop($text) {
        $n = trim(preg_replace('/[!.،,؟?\s]+/u', ' ', mb_strtolower((string) $text)));
        return (bool) preg_match(self::STOP, $n);
    }
    /** He answered a campaign: he is a conversation now, not a list entry. */
    public static function replied($phone) {
        $p = class_exists('Mutqan_Leads') ? Mutqan_Leads::phone($phone) : '';
        if ($p === '') { return ['ok' => false]; }
        global $wpdb; $wpdb->update(Mutqan_Leads::table(), ['replied' => 1], ['phone' => $p]);
        return ['ok' => true];
    }

    public static function stats() {
        if (!class_exists('Mutqan_Leads')) { return []; }
        Mutqan_Leads::install();
        global $wpdb; $t = Mutqan_Leads::table();
        $by = (array) $wpdb->get_results("SELECT source, status, COUNT(*) n, SUM(replied) r FROM $t WHERE mobile = 1 GROUP BY source, status", ARRAY_A);
        $st = get_option(self::STATE, []); $lines = [];
        foreach ((is_array($st) ? $st : []) as $k => $v) { $lines[$k] = ['first' => $v['first'] ?? '', 'today' => (int) ($v['sent'][self::today()] ?? 0), 'cap' => self::cap_for($k)]; }
        return ['by' => $by, 'lines' => $lines, 'sync' => get_option(self::STATE . '_sync', [])];
    }

    /* ------------------------------------------------------------------ desk REST */

    public static function handle($in, $p) {
        if (!class_exists('Mutqan_Policy') || !Mutqan_Policy::is_staff($p)) { throw new Mutqan_Error('forbidden', 'للإدارة بس.', 403); }
        $op = (string) ($in['op'] ?? 'view');
        if ($op === 'view') { return ['settings' => self::settings(), 'stats' => self::stats()]; }
        if (($p['role'] ?? '') !== 'owner') { throw new Mutqan_Error('forbidden', 'للمالك بس.', 403); }
        if ($op === 'save') { return ['settings' => self::save((array) ($in['settings'] ?? []))]; }
        if ($op === 'sync') { return self::sync(); }
        if ($op === 'optout') { return self::optout((string) ($in['phone'] ?? '')); }
        throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400);
    }

    public static function save($in) {
        $s = self::settings();
        if (isset($in['on'])) { $s['on'] = $in['on'] ? 1 : 0; }
        foreach (['from' => [0, 23], 'to' => [1, 24], 'start' => [5, 200], 'ramp' => [0, 50], 'max' => [5, 300], 'perCall' => [1, 20], 'cooldown' => [3, 180], 'maxTouches' => [1, 10]] as $k => $r) {
            if (isset($in[$k]) && is_numeric($in[$k])) { $s[$k] = max($r[0], min($r[1], (int) $in[$k])); }
        }
        if ($s['to'] <= $s['from']) { $s['to'] = min(24, $s['from'] + 1); }
        if ($s['max'] < $s['start']) { $s['max'] = $s['start']; }
        if (isset($in['sources']) && is_array($in['sources'])) { foreach (['system', 'whatsapp', 'maps'] as $k) { $s['sources'][$k] = empty($in['sources'][$k]) ? 0 : 1; } }
        foreach (['cold', 'customer'] as $k) { if (isset($in[$k]) && trim((string) $in[$k]) !== '') { $s[$k] = mb_substr(sanitize_textarea_field((string) $in[$k]), 0, 6000); } }
        update_option(self::OPT, $s, false);
        return $s;
    }

    /* ------------------------------------------------------------------ wp-admin page (hub) */

    private static function back($msg) { wp_safe_redirect(add_query_arg('mq_msg', rawurlencode($msg), admin_url('options-general.php?page=' . self::PAGE))); exit; }
    public static function h_save() {
        if (!current_user_can('manage_options')) { wp_die('غير مصرح.'); }
        check_admin_referer('mutqan_outreach_save');
        $in = wp_unslash($_POST); $in['on'] = !empty($in['on']); $in['sources'] = (array) ($in['sources'] ?? []);
        self::save($in);
        self::back('اتحفظت إعدادات الحملات.');
    }
    public static function h_sync() {
        if (!current_user_can('manage_options')) { wp_die('غير مصرح.'); }
        check_admin_referer('mutqan_outreach_sync');
        $r = self::sync();
        self::back('اتضاف ' . (int) ($r['system'] ?? 0) . ' عميل سابق و' . (int) ($r['whatsapp'] ?? 0) . ' من الواتساب للقاعدة.');
    }
    public static function h_lines() {
        if (!current_user_can('manage_options')) { wp_die('غير مصرح.'); }
        check_admin_referer('mutqan_lines_save');
        $patch = [];
        foreach ((array) wp_unslash($_POST['n'] ?? []) as $raw => $f) {
            $patch[] = ['phone' => (string) $raw, 'role' => (string) ($f['role'] ?? ''), 'status' => (string) ($f['status'] ?? ''), 'label' => (string) ($f['label'] ?? ''), 'channels' => array_keys((array) ($f['ch'] ?? []))] + (trim((string) ($f['fix'] ?? '')) !== '' ? ['fix' => (string) $f['fix']] : []);
        }
        $add = trim((string) wp_unslash($_POST['add_phone'] ?? ''));
        $on = array_map('intval', array_keys((array) ($_POST['sec'] ?? [])));
        Mutqan_Lines::save(['patch' => $patch, 'add' => $add !== '' ? [['phone' => $add, 'role' => (string) ($_POST['add_role'] ?? 'reserve'), 'label' => (string) wp_unslash($_POST['add_label'] ?? '')]] : [], 'rotate' => !empty($_POST['rotate']),
            'off' => array_values(array_diff(array_keys(Mutqan_Lines::SECTIONS), $on))]);
        self::back('اتحفظت الأرقام.');
    }

    public static function page() {
        if (!current_user_can('manage_options')) { return; }
        $s = self::settings(); $st = self::stats(); $L = Mutqan_Lines::view();
        $msg = isset($_GET['mq_msg']) ? sanitize_text_field(wp_unslash($_GET['mq_msg'])) : '';
        $post = esc_url(admin_url('admin-post.php'));
        echo '<div class="wrap" dir="rtl"><h1>الأرقام وحملات الاستقطاب</h1>';
        if ($msg) { echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>'; }

        // 1. the numbers and their jobs
        echo '<h2>أرقامنا وأدوارها</h2><p>كل رقم ليه شغلانة واحدة. «المدير» بيبقى غرفة العمليات في مركز الواتساب أول ما تخليه «مفعّل» (بعد ما يتربط وتتأكد إنه بيوصلك). أرقام «مبيعات» هي اللي بتظهر في المواقع والمقالات والبلوجر وبتتبدل كل ' . (int) $L['rotateDays'] . ' أيام. أرقام «استقطاب» بس هي اللي بتبعت رسايل الحملات.</p>';
        echo '<form method="post" action="' . $post . '">'; wp_nonce_field('mutqan_lines_save');
        echo '<input type="hidden" name="action" value="mutqan_lines_save"><table class="widefat striped" style="max-width:1100px"><thead><tr><th>الرقم</th><th>الاسم</th><th>الدور</th><th>الحالة</th><th>بيظهر في</th><th>ملاحظة / تصحيح</th></tr></thead><tbody>';
        $chs = ['sites' => 'المواقع', 'articles' => 'المقالات', 'blogger' => 'بلوجر', 'ads' => 'إعلانات', 'maps' => 'خرائط', 'haraj' => 'حراج', 'emergency' => 'طوارئ'];
        foreach ($L['numbers'] as $r) {
            $n = 'n[' . esc_attr($r['raw']) . ']';
            echo '<tr><td dir="ltr">' . esc_html($r['local']) . '</td><td><input name="' . $n . '[label]" value="' . esc_attr($r['label']) . '" size="14"></td><td><select name="' . $n . '[role]">';
            foreach (Mutqan_Lines::ROLES as $k => $lbl) { echo '<option value="' . esc_attr($k) . '"' . selected($r['role'], $k, false) . '>' . esc_html(strtok($lbl, '—')) . '</option>'; }
            echo '</select></td><td>' . ($r['status'] === 'invalid' ? '<b style="color:#b91c1c">رقم غلط</b>' : '<select name="' . $n . '[status]"><option value="active"' . selected($r['status'], 'active', false) . '>مفعّل</option><option value="pending"' . selected($r['status'], 'pending', false) . '>مستني الربط</option><option value="paused"' . selected($r['status'], 'paused', false) . '>موقوف</option></select>') . '</td><td>';
            foreach ($chs as $ck => $cl) { echo '<label style="margin-left:6px"><input type="checkbox" name="' . $n . '[ch][' . $ck . ']" value="1"' . checked(in_array($ck, (array) $r['channels'], true), true, false) . '>' . esc_html($cl) . '</label>'; }
            echo '</td><td><small>' . esc_html($r['note']) . '</small>' . ($r['status'] === 'invalid' ? '<br><input name="' . $n . '[fix]" placeholder="الرقم الصح 05…" dir="ltr" size="14">' : '') . '</td></tr>';
        }
        echo '</tbody></table><p>رقم جديد: <input name="add_phone" placeholder="05…" dir="ltr" size="14"> <input name="add_label" placeholder="الاسم" size="12"> <select name="add_role">';
        foreach (Mutqan_Lines::ROLES as $k => $lbl) { echo '<option value="' . esc_attr($k) . '">' . esc_html(strtok($lbl, '—')) . '</option>'; }
        echo '</select></p><p><label><input type="checkbox" name="rotate" value="1"' . checked($L['rotate'], true, false) . '> بدّل أرقام المبيعات على الصفحات كل ' . (int) $L['rotateDays'] . ' أيام</label></p>';
        echo '<h3>الخدمات اللي بيعرضها الواتساب</h3><p>شيل العلامة عن أي خدمة عايز توقفها مؤقتًا — بتختفي من قائمة الواتساب، واللي يطلبها يتقاله إنها متوقفة ويتحول لموظف.</p><p>';
        foreach ($L['sections'] as $x) { echo '<label style="margin-left:14px"><input type="checkbox" name="sec[' . (int) $x['n'] . ']" value="1"' . checked($x['on'], true, false) . '> ' . esc_html($x['label']) . '</label>'; }
        echo '</p>';
        submit_button('حفظ الأرقام والخدمات'); echo '</form>';

        // 2. the base
        echo '<h2>قاعدة العملاء المحتملين</h2><table class="widefat striped" style="max-width:700px"><thead><tr><th>المصدر</th><th>الحالة</th><th>العدد</th><th>ردّوا</th></tr></thead><tbody>';
        $sn = ['maps' => 'خرائط جوجل', 'system' => 'عملاء سابقين', 'whatsapp' => 'واتساب'];
        $stn = ['new' => 'لسه ما اتبعتلهمش', 'sent' => 'اتبعتلهم', 'optout' => 'لا ترسل', 'ours' => 'أرقامنا'];
        foreach ((array) ($st['by'] ?? []) as $r) { echo '<tr><td>' . esc_html($sn[$r['source']] ?? $r['source']) . '</td><td>' . esc_html($stn[$r['status']] ?? $r['status']) . '</td><td>' . (int) $r['n'] . '</td><td>' . (int) $r['r'] . '</td></tr>'; }
        echo '</tbody></table>';
        $sy = (array) ($st['sync'] ?? []);
        echo '<p>آخر تجميع: ' . (!empty($sy['at']) ? esc_html($sy['at']) : 'لسه') . '</p><form method="post" action="' . $post . '">'; wp_nonce_field('mutqan_outreach_sync');
        echo '<input type="hidden" name="action" value="mutqan_outreach_sync">'; submit_button('جمّع العملاء السابقين ورسايل الواتساب دلوقتي', 'secondary'); echo '</form>';
        echo '<h3>خطوط الاستقطاب النهارده</h3><table class="widefat striped" style="max-width:600px"><thead><tr><th>الخط</th><th>أول يوم</th><th>اتبعت النهارده</th><th>الحد النهارده</th></tr></thead><tbody>';
        foreach ((array) ($st['lines'] ?? []) as $k => $v) { echo '<tr><td dir="ltr">0' . esc_html($k) . '</td><td>' . esc_html($v['first']) . '</td><td>' . (int) $v['today'] . '</td><td>' . (int) $v['cap'] . '</td></tr>'; }
        echo '</tbody></table>';

        // 3. the plan
        echo '<h2>خطة الإرسال</h2><form method="post" action="' . $post . '">'; wp_nonce_field('mutqan_outreach_save');
        echo '<input type="hidden" name="action" value="mutqan_outreach_save"><table class="form-table">';
        echo '<tr><th>تشغيل الحملات</th><td><label><input type="checkbox" name="on" value="1"' . checked(!empty($s['on']), true, false) . '> شغّالة (بتبعت بس من أرقام الاستقطاب المربوطة في مركز الواتساب)</label></td></tr>';
        echo '<tr><th>ساعات الإرسال (توقيت السعودية)</th><td>من <input type="number" name="from" min="0" max="23" value="' . (int) $s['from'] . '"> لـ <input type="number" name="to" min="1" max="24" value="' . (int) $s['to'] . '"></td></tr>';
        echo '<tr><th>حد كل خط في اليوم</th><td>أول يوم <input type="number" name="start" value="' . (int) $s['start'] . '" style="width:70px"> + <input type="number" name="ramp" value="' . (int) $s['ramp'] . '" style="width:60px"> كل يوم، وبحد أقصى <input type="number" name="max" value="' . (int) $s['max'] . '" style="width:70px"><p class="description">التدرّج ده بيحمي الرقم من الحظر.</p></td></tr>';
        echo '<tr><th>نفس العميل</th><td>مش أكتر من مرة كل <input type="number" name="cooldown" value="' . (int) $s['cooldown'] . '" style="width:60px"> يوم، و<input type="number" name="maxTouches" value="' . (int) $s['maxTouches'] . '" style="width:50px"> مرات بالكتير</td></tr>';
        echo '<tr><th>المصادر</th><td>';
        foreach ($sn as $k => $lbl) { echo '<label style="margin-left:10px"><input type="checkbox" name="sources[' . $k . ']" value="1"' . checked(!empty($s['sources'][$k]), true, false) . '> ' . esc_html($lbl) . '</label>'; }
        echo '</td></tr>';
        echo '<tr><th>رسايل العملاء الجدد</th><td><textarea name="cold" rows="12" cols="70">' . esc_textarea($s['cold']) . '</textarea><p class="description">رسالة ورا التانية مفصولين بسطر فيه <code>---</code>. المتغيرات: {brand} {city} {field}</p></td></tr>';
        echo '<tr><th>رسايل العملاء السابقين</th><td><textarea name="customer" rows="8" cols="70">' . esc_textarea($s['customer']) . '</textarea><p class="description">المتغيرات: {brand} {name} {last}</p></td></tr>';
        echo '</table>'; submit_button('حفظ الخطة'); echo '</form></div>';
    }
}
