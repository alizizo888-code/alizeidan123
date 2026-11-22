<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «أرقامنا» — the company's own WhatsApp numbers, each with one job (owner order 2026-11-21).
 *
 *   manager   «المدير»   one number. Receives every ready order, dispatches to technicians and operations, follows up,
 *                        and commands the other lines («رد 1234 …»). Meta Cloud API when Meta approves it, the VPS hub
 *                        until then. It becomes the hub's operations room ONLY once it is marked active (linked and
 *                        read by a person) — until then the room stays on the old number, so no report is lost.
 *   sales     «مبيعات»   the numbers behind ads, Google Maps, Haraj and the sites. They talk to their own customers and
 *                        prepare a ready order (service, location, details, time) for the manager. These are the
 *                        numbers that appear on the sites, the articles and the blogs, rotated so none stays fixed.
 *   outreach  «استقطاب»  kept apart from everything else: they send the daily invitation messages to the prospects
 *                        base (Mutqan_Outreach). A ban on one of them never touches a sales line or the manager.
 *   reserve   «احتياطي»  kept, not used (a number with a typo waits here until corrected).
 *
 * Every number is ours: the AI knows them (never a customer, never a lead, never a prospect), the WhatsApp hub treats
 * a message from one of them as staff, and the leads base drops them on import.
 */
final class Mutqan_Lines {
    const OPT = 'mutqan_lines';
    const ROLES = [
        'manager'  => 'المدير — يستلم الطلبات الجاهزة ويوزعها ويتابع ويوجه باقي الأرقام',
        'sales'    => 'مبيعات — إعلانات وخرائط جوجل وحراج والمواقع؛ بيجهز الطلب للمدير',
        'outreach' => 'استقطاب — رسائل الدعوة اليومية لقاعدة العملاء المحتملين',
        'reserve'  => 'احتياطي — محفوظ ومش مستخدم',
    ];
    /** The numbers the owner sent (2026-11-21), with the job each was given. The owner changes any of it from the desk. */
    const SEED = [
        ['phone' => '966549247636', 'role' => 'manager',  'label' => 'المدير', 'status' => 'pending', 'note' => 'الرقم الموحد على Meta — يتفعل كمدير بعد ربطه'],
        ['phone' => '966549423050', 'role' => 'sales',    'label' => 'مبيعات ١', 'status' => 'active', 'channels' => ['sites', 'articles', 'blogger'], 'note' => 'رقم المواقع الأساسي'],
        ['phone' => '966536554731', 'role' => 'sales',    'label' => 'مبيعات ٢ (الطوارئ)', 'status' => 'active', 'channels' => ['sites', 'emergency'], 'note' => 'واتساب أعمال على موبايل المالك + رقم الطوارئ', 'center' => true],
        ['phone' => '966549665320', 'role' => 'sales',    'label' => 'مبيعات ٣', 'status' => 'active', 'channels' => ['ads', 'maps', 'haraj', 'articles', 'blogger']],
        ['phone' => '966549715646', 'role' => 'sales',    'label' => 'مبيعات ٤', 'status' => 'active', 'channels' => ['ads', 'maps', 'haraj', 'articles', 'blogger']],
        ['phone' => '966546619205', 'role' => 'outreach', 'label' => 'استقطاب ١', 'status' => 'active'],
        ['phone' => '966547512660', 'role' => 'outreach', 'label' => 'استقطاب ٢', 'status' => 'active'],
        // corrected by the owner (2026-11-21): the short 96649648890 was 966549648890 — his own test-technician phone
        // («علي (فني تجربة)»), so it is kept in reserve: the technician flow on it keeps working, it is never a sales line.
        ['phone' => '966549648890', 'role' => 'reserve',  'label' => 'فني التجربة (علي)', 'status' => 'active', 'note' => 'رقم فني التجربة — احتياطي، مش بيظهر للعملاء'],
    ];
    const ROTATE_DAYS = 7;
    /** The services the WhatsApp menu offers (its sections, in order). The owner can stop any of them from the desk. */
    const SECTIONS = [1 => ['ac', 'المكيفات'], 2 => ['fridge', 'غرف التبريد والتجميد'], 3 => ['plumbing', 'السباكة'], 4 => ['electric', 'الكهرباء'], 5 => ['carpentry', 'النجارة'], 6 => ['cameras', 'الكاميرات والدش'], 7 => ['other', 'خدمات تانية']];
    /** The site's own specialties behind each WhatsApp section: a service the owner switched off in «تحكمات المالك ← الخدمات»
     *  (pricing row services_off) takes its section off WhatsApp too, once all its specialties are off. */
    const SECTION_SPECS = [1 => ['ac'], 2 => ['fridge'], 3 => ['plumbing'], 4 => ['electric'], 5 => [], 6 => ['cameras', 'satellite'], 7 => ['paint', 'tiling', 'appliances']];
    public static function off_sections() {
        $o = get_option(self::OPT, []);
        $off = array_map('intval', (array) (is_array($o) ? ($o['off'] ?? []) : []));
        $svc = [];
        if (class_exists('Mutqan_Store')) { try { $d = Mutqan_Store::peek_doc('pricing', 'services_off'); $svc = array_map('strval', (array) ($d['ids'] ?? [])); } catch (Throwable $e) { /* optional */ } }
        foreach (self::SECTION_SPECS as $n => $specs) { if ($specs && !array_diff($specs, $svc)) { $off[] = $n; } }
        $off = array_values(array_unique(array_intersect($off, array_keys(self::SECTIONS))));
        sort($off);
        return $off;
    }   // a page keeps its number a week, then moves to the next one

    /** 9665XXXXXXXX from any spelling (05…, 5…, +966…, ٠٥…); '' when it is not a Saudi mobile. */
    public static function norm($v) {
        $d = preg_replace('/\D+/', '', strtr((string) $v, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
        if (strpos($d, '00966') === 0) { $d = substr($d, 2); }
        if (strpos($d, '966') !== 0) { $d = '966' . ltrim($d, '0'); }
        return preg_match('/^9665\d{8}$/', $d) ? $d : '';
    }
    public static function local($intl) { $d = preg_replace('/\D+/', '', (string) $intl); return strpos($d, '966') === 0 ? '0' . substr($d, 3) : $d; }
    private static function key($v) { $d = preg_replace('/\D+/', '', strtr((string) $v, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'])); return strlen($d) >= 8 ? substr($d, -9) : ''; }

    /** Every number with its job: the seed, with the owner's edits on top (role, status, label, channels, note). */
    public static function all($own = null) {
        if ($own === null) { $own = function_exists('get_option') ? get_option(self::OPT, []) : []; }
        $own = is_array($own) ? $own : [];
        $edits = is_array($own['edits'] ?? null) ? $own['edits'] : [];
        $extra = is_array($own['extra'] ?? null) ? $own['extra'] : [];
        $out = [];
        foreach (array_merge(self::SEED, $extra) as $r) {
            $raw = preg_replace('/\D+/', '', (string) $r['phone']);
            $e = is_array($edits[$raw] ?? null) ? $edits[$raw] : [];
            $r = array_merge(['channels' => [], 'note' => '', 'status' => 'active', 'label' => '', 'center' => false], $r, $e);
            $n = self::norm($raw);
            if ($n === '') { $r['status'] = 'invalid'; }
            if (!isset(self::ROLES[$r['role']])) { $r['role'] = 'reserve'; }
            $r['raw'] = $raw; $r['phone'] = $n !== '' ? $n : $raw; $r['local'] = $n !== '' ? self::local($n) : $raw;
            $out[] = $r;
        }
        return $out;
    }

    /** The row of one of our numbers, or null. Matches on the last nine digits, so 05…, 9665… and +966… are the same. */
    public static function ours($phone) {
        $k = self::key($phone); if ($k === '') { return null; }
        foreach (self::all() as $r) { if ($r['status'] !== 'invalid' && self::key($r['phone']) === $k) { return $r; } }
        return null;
    }
    public static function is_ours($phone) { return self::ours($phone) !== null; }

    public static function pool($role) {
        return array_values(array_filter(self::all(), function ($r) use ($role) { return $r['role'] === $role && $r['status'] === 'active'; }));
    }

    /** The manager number, only once it is active (linked and read by a person); null before that. */
    public static function manager() { $p = self::pool('manager'); return $p ? $p[0] : null; }

    /**
     * The sales number a page shows. Spread over the sales pool by site and page, and moved every ROTATE_DAYS, so the
     * numbers never sit fixed on the same pages. Optional channel: only numbers carrying it (e.g. «articles»).
     * Returns ['wa' => 9665…, 'phone' => 05…, 'wa2' => another sales number or ''].
     */
    public static function public_for($site = '', $post = 0, $channel = '', $t = null) {
        $pool = self::pool('sales');
        if ($channel !== '') { $c = array_values(array_filter($pool, function ($r) use ($channel) { return in_array($channel, (array) $r['channels'], true); })); if ($c) { $pool = $c; } }
        $pool = array_values(array_filter($pool, function ($r) { return !in_array('emergency', (array) $r['channels'], true) || count((array) $r['channels']) > 1; }));
        if (!$pool) { return ['wa' => '', 'phone' => '', 'wa2' => '']; }
        $o = get_option(self::OPT, []);
        if (is_array($o) && isset($o['rotate']) && !$o['rotate']) { $t = 0; }
        $week = (int) floor((($t === null ? time() : (int) $t)) / (self::ROTATE_DAYS * 86400));
        $n = count($pool);
        $i = (int) ((sprintf('%u', crc32((string) $site . '|' . (int) $post)) + $week) % $n);
        $a = $pool[$i]; $b = $n > 1 ? $pool[($i + 1) % $n] : null;
        return ['wa' => $a['phone'], 'phone' => $a['local'], 'wa2' => $b ? $b['phone'] : '', 'label' => $a['label']];
    }

    /** What the AI is told. Customers hear only that these are the company's lines; staff get every job. */
    public static function facts($audience = 'customer') {
        $L = [];
        if ($audience === 'customer') {
            $s = array_map(function ($r) { return $r['local']; }, self::pool('sales'));
            if ($s) { $L[] = '- أرقام واتساب الشركة الرسمية: ' . implode('، ', $s) . '. لا تعطِ العميل أي رقم غيرها، ولا تقل إن رقمًا منها لشخص بعينه.'; }
            return $L ? "## أرقام الشركة\n" . implode("\n", $L) . "\n" : '';
        }
        foreach (self::all() as $r) {
            if ($r['status'] === 'invalid') { continue; }
            $L[] = '- ' . $r['local'] . ' — ' . ($r['label'] ?: $r['role']) . ': ' . (self::ROLES[$r['role']] ?? $r['role']) . ($r['status'] !== 'active' ? ' (لسه مش متفعّل)' : '');
        }
        return $L ? "## أرقامنا (كلها أرقام الشركة — أي رسالة منها من الفريق مش من عميل)\n" . implode("\n", $L) . "\n" : '';
    }

    /**
     * What the VPS WhatsApp hub needs: the role of each of our numbers, and the manager once it is active.
     * A number with `center: true` lives on the new WhatsApp Center (Evolution), not on this hub's own Baileys
     * lines: the hub logs it out and forgets it (so it never holds a second live session on the same phone —
     * the cause of «في انتظار هذه الرسالة») and never offers it a QR to re-link.
     */
    public static function hub_config() {
        $roles = [];
        foreach (self::all() as $r) { if ($r['status'] !== 'invalid' && $r['role'] !== 'reserve') { $roles[self::key($r['phone'])] = ['role' => $r['role'], 'label' => $r['label'], 'active' => $r['status'] === 'active', 'status' => $r['status'], 'local' => $r['local'], 'center' => !empty($r['center'])]; } }
        $m = self::manager();
        return ['ourNumbers' => $roles, 'managerPhone' => $m ? $m['phone'] : '', 'offSections' => self::off_sections()];
    }

    /* ------------------------------------------------------------------ owner edits */

    /** patch: [{phone, role?, status?, label?, channels?, note?}], add: [{phone, role, label}], rotate?: bool */
    public static function save($in) {
        $o = get_option(self::OPT, []); $o = is_array($o) ? $o : [];
        $o['edits'] = is_array($o['edits'] ?? null) ? $o['edits'] : [];
        $o['extra'] = is_array($o['extra'] ?? null) ? $o['extra'] : [];
        foreach ((array) ($in['patch'] ?? []) as $p) {
            $raw = preg_replace('/\D+/', '', (string) ($p['phone'] ?? '')); if ($raw === '') { continue; }
            $e = $o['edits'][$raw] ?? [];
            if (isset($p['role']) && isset(self::ROLES[$p['role']])) { $e['role'] = $p['role']; }
            if (isset($p['status']) && in_array($p['status'], ['active', 'pending', 'paused'], true)) { $e['status'] = $p['status']; }
            if (isset($p['label'])) { $e['label'] = mb_substr(trim((string) $p['label']), 0, 40); }
            if (isset($p['note'])) { $e['note'] = mb_substr(trim((string) $p['note']), 0, 160); }
            if (isset($p['channels']) && is_array($p['channels'])) { $e['channels'] = array_values(array_intersect(array_map('strval', $p['channels']), ['sites', 'articles', 'blogger', 'ads', 'maps', 'haraj', 'emergency'])); }
            if (isset($p['center'])) { $e['center'] = (bool) $p['center']; }
            if (isset($p['fix'])) { $f = self::norm($p['fix']); if ($f !== '') { $e = []; $o['extra'][] = ['phone' => $f, 'role' => (string) ($p['role'] ?? 'reserve'), 'label' => 'مصحَّح', 'status' => 'pending']; $e['status'] = 'invalid'; } }
            $o['edits'][$raw] = $e;
        }
        foreach ((array) ($in['add'] ?? []) as $a) {
            $n = self::norm($a['phone'] ?? ''); if ($n === '' || self::ours($n)) { continue; }
            $o['extra'][] = ['phone' => $n, 'role' => isset(self::ROLES[$a['role'] ?? '']) ? $a['role'] : 'reserve', 'label' => mb_substr((string) ($a['label'] ?? ''), 0, 40), 'status' => 'pending'];
        }
        if (isset($in['rotate'])) { $o['rotate'] = $in['rotate'] ? 1 : 0; }
        if (isset($in['off']) && is_array($in['off'])) { $o['off'] = array_values(array_intersect(array_map('intval', $in['off']), array_keys(self::SECTIONS))); }
        // one manager only: making a number the manager moves the old one to sales
        $mgr = array_values(array_filter(self::all($o), function ($r) { return $r['role'] === 'manager' && $r['status'] !== 'invalid'; }));
        if (count($mgr) > 1) { foreach (array_slice($mgr, 0, -1) as $old) { $o['edits'][$old['raw']]['role'] = 'sales'; } }
        update_option(self::OPT, $o, false);
        if (function_exists('delete_transient')) { delete_transient('mq_hub_cfg'); }
        return self::view();
    }

    /** Desk: view for staff, changes for the owner only. */
    public static function handle($in, $p) {
        if (!class_exists('Mutqan_Policy') || !Mutqan_Policy::is_staff($p)) { throw new Mutqan_Error('forbidden', 'للإدارة بس.', 403); }
        $op = (string) ($in['op'] ?? 'view');
        if ($op === 'view') { return self::view(); }
        if ($op === 'save') {
            if (($p['role'] ?? '') !== 'owner') { throw new Mutqan_Error('forbidden', 'تعديل الأرقام للمالك بس.', 403); }
            return self::save($in);
        }
        throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400);
    }

    public static function view() {
        $o = get_option(self::OPT, []);
        $sec = []; foreach (self::SECTIONS as $n => $x) { $sec[] = ['n' => $n, 'key' => $x[0], 'label' => $x[1], 'on' => !in_array($n, self::off_sections(), true)]; }
        return ['numbers' => self::all(), 'roles' => self::ROLES, 'sections' => $sec, 'rotate' => !(is_array($o) && isset($o['rotate']) && !$o['rotate']), 'rotateDays' => self::ROTATE_DAYS];
    }

    /* ------------------------------------------------------------------ REST */

    public static function boot() {
        add_action('rest_api_init', function () {
            // Public: the number a page or a Blogger post should show (sales numbers only — the same ones printed on the sites).
            register_rest_route('mutqan/v1', '/lines/public', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function ($req) {
                $site = preg_replace('/[^a-z0-9-]/', '', strtolower((string) $req->get_param('site')));
                return rest_ensure_response(self::public_for($site, (int) $req->get_param('post'), preg_replace('/[^a-z]/', '', (string) $req->get_param('channel'))));
            }]);
        });
    }
}
