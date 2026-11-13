<?php
if (!defined('ABSPATH')) { exit; }

/**
 * «مدير العمليات» — the operations manager the owner, operations and supervisors can ask.
 *
 * Separate from the dispatcher (Mutqan_Dispatch hands orders out) and from the owner's control assistant
 * (Mutqan_Agent changes things). This one only READS and EXPLAINS: what happened, the archive, an order's
 * price / cost / sequence of steps, where each technician and supervisor is now, money and expenses, and
 * what needs attention. It has no write tool at all, so nothing it says can change the business.
 *
 * Alerts are computed by plain rules (no model, no cost) so the screen can show them on open and refresh
 * them every minute; the model is only used to answer questions about them.
 *
 * Positions follow the same narrow rule as Mutqan_Track: one current point per person, overwritten, and
 * ignored after three hours. A supervisor's point is sent only while their app is open.
 */
final class Mutqan_Ops {

    const OPT_POS = 'mutqan_staff_pos';
    const STALE = 10800;
    const MAX_STEPS = 6;
    const ROLES = ['owner', 'operations', 'supervisor'];
    const TECH_TOOLS = ['my_jobs', 'my_job', 'my_account'];
    const OPEN = ['new', 'forced', 'assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress'];
    const WITH_TECH = ['assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress'];
    const DONE = ['completed', 'closed'];
    const STATUS = [
        'new' => 'جديد', 'forced' => 'عاجل', 'assigned' => 'مُسند', 'accepted' => 'الفني قبل', 'en_route' => 'في الطريق',
        'nearby' => 'قريب', 'arrived' => 'وصل', 'in_progress' => 'جاري التنفيذ', 'completed' => 'تم', 'closed' => 'مقفول', 'cancelled' => 'ملغي',
    ];
    /** Thresholds for the rule-based alerts (minutes unless stated). */
    const T = ['unassigned' => 15, 'not_accepted' => 30, 'appointment_late' => 30, 'long_job_h' => 4, 'unpaid_h' => 24, 'cash_limit' => 1000];

    /** The technician asking (tools are then limited to their own jobs). */
    private static $who = null;

    private static function sys() { return ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']]; }

    /* ------------------------------------------------------------------ entry (POST /ops) */

    public static function handle($in, $p) {
        Mutqan_Auth::require_user($p);
        $isTech = ($p['role'] ?? '') === 'technician';
        if (!$isTech && !in_array($p['role'] ?? '', self::ROLES, true)) { throw new Mutqan_Error('forbidden', 'المساعد للفريق فقط.', 403); }
        $op = (string) ($in['op'] ?? 'ask');
        if ($isTech && $op !== 'ask') { throw new Mutqan_Error('forbidden', 'غير مسموح.', 403); }
        if ($op === 'alerts') { return ['alerts' => self::alerts(), 'at' => gmdate('c')]; }
        if ($op === 'pos') { return self::ping($p, $in['lat'] ?? null, $in['lng'] ?? null); }
        $msgs = self::clean_messages($in['messages'] ?? []);
        if (!$msgs) { throw new Mutqan_Error('empty_prompt', 'اكتب سؤالك.', 400); }
        Mutqan_Limiter::hit('ops_ai', 150, 3600);
        if ($isTech) { self::$who = $p; }
        $tier = Mutqan_AI::allowed_tier('high', $p);           // owner/operations: high · supervisor: routine
        $model = Mutqan_AI::tier_model($tier);
        return $model !== '' ? self::loop($msgs, $p, $model) : self::free_turn($msgs, $p);
    }

    /* ------------------------------------------------------------------ supervisor position */

    /** A supervisor / operations member says where they are (one point, overwritten). Technicians use /track. */
    public static function ping($p, $lat, $lng) {
        if (!in_array($p['role'] ?? '', ['supervisor', 'operations'], true)) { return ['ok' => false, 'reason' => 'not_field_staff']; }
        if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180 || ((float) $lat === 0.0 && (float) $lng === 0.0)) { return ['ok' => false, 'reason' => 'bad_position']; }
        $all = self::positions(true);
        $all[(string) $p['id']] = ['lat' => round((float) $lat, 6), 'lng' => round((float) $lng, 6), 'at' => time(), 'role' => (string) $p['role'], 'name' => mb_substr((string) ($p['name'] ?? $p['displayName'] ?? ''), 0, 60)];
        update_option(self::OPT_POS, $all, false);
        return ['ok' => true, 'at' => time()];
    }

    /** Current points, stale ones dropped (and forgotten when $prune). */
    public static function positions($prune = false) {
        $all = (array) get_option(self::OPT_POS, []); $out = [];
        foreach ($all as $id => $x) { if (is_array($x) && time() - (int) ($x['at'] ?? 0) <= self::STALE) { $out[$id] = $x; } }
        if ($prune && count($out) !== count($all)) { update_option(self::OPT_POS, $out, false); }
        return $out;
    }

    /* ------------------------------------------------------------------ helpers */

    public static function amount($o) {
        foreach ([$o['price'] ?? null, $o['agreedPrice'] ?? null, $o['priceQuote']['total'] ?? null, $o['acQuote']['total'] ?? null] as $v) { if (is_numeric($v) && (float) $v > 0) { return (float) $v; } }
        return 0.0;
    }
    private static function ts($v) { return $v ? (int) strtotime((string) $v) : 0; }
    private static function mins($v) { $t = self::ts($v); return $t ? (int) floor((time() - $t) / 60) : null; }
    private static function local($v) { $t = self::ts($v); return $t ? wp_date('Y-m-d H:i', $t) : ''; }
    private static function share() { $r = Mutqan_Store::select('tech_pay', [], ['limit' => 1], self::sys()); return (float) ($r[0]['sharePct'] ?? 0); }
    private static function techs() {
        $out = [];
        foreach (Mutqan_Store::select('tech_profiles', [], ['limit' => 300], self::sys()) as $t) { $id = (string) ($t['userId'] ?? $t['id'] ?? ''); if ($id !== '') { $out[$id] = $t; } }
        return $out;
    }
    private static function tname($techs, $id, $o = null) {
        if ($id === '' || $id === null) { return ''; }
        return (string) ($techs[$id]['displayName'] ?? $techs[$id]['name'] ?? ($o['technicianName'] ?? 'فني'));
    }
    private static function is_cash($o) { $m = (string) ($o['paymentMethod'] ?? ''); return stripos($m, 'cash') !== false || $m === 'نقدًا'; }

    private static function brief($o, $techs) {
        return [
            'id' => (string) ($o['id'] ?? ''), 'number' => (string) ($o['number'] ?? ''), 'status' => self::STATUS[$o['status'] ?? ''] ?? ($o['status'] ?? ''),
            'client' => $o['clientName'] ?? '', 'phone' => $o['clientPhone'] ?? '', 'service' => $o['serviceLabel'] ?? ($o['service'] ?? ($o['specialtyId'] ?? '')),
            'address' => mb_substr((string) ($o['address'] ?? ''), 0, 120), 'amount' => self::amount($o), 'paid' => ($o['paymentStatus'] ?? '') === 'paid',
            'payment' => $o['paymentMethod'] ?? '', 'tech' => self::tname($techs, (string) ($o['technicianId'] ?? ''), $o),
            'created' => self::local($o['createdAt'] ?? ''), 'appointment' => self::local($o['appointmentAt'] ?? ''), 'brand' => $o['brand'] ?? '',
        ];
    }

    /** Orders in a window (by creation date), newest first. */
    private static function orders_between($from, $to, $limit = 2000) {
        $f = [];
        if ($from) { $f['createdAt'] = ['gte' => gmdate('c', $from)]; }
        $rows = Mutqan_Store::select('orders', $f, ['order' => '-createdAt', 'limit' => $limit], self::sys());
        return $to ? array_values(array_filter($rows, function ($o) use ($to) { return self::ts($o['createdAt'] ?? '') <= $to; })) : $rows;
    }
    private static function window($in, $defDays = 7) {
        $from = !empty($in['from']) ? strtotime((string) $in['from'] . (strlen((string) $in['from']) <= 10 ? ' 00:00:00' : '')) : 0;
        $to = !empty($in['to']) ? strtotime((string) $in['to'] . (strlen((string) $in['to']) <= 10 ? ' 23:59:59' : '')) : 0;
        if (!$from) { $from = time() - max(1, min(365, (int) ($in['days'] ?? $defDays))) * 86400; }
        return [$from, $to ?: time()];
    }

    /* ------------------------------------------------------------------ alerts (rules, no model) */

    public static function alerts() {
        $sys = self::sys(); $techs = self::techs(); $a = [];
        $add = function ($level, $kind, $text, $ref = '') use (&$a) { $a[] = ['level' => $level, 'kind' => $kind, 'text' => $text, 'ref' => $ref]; };
        $open = Mutqan_Store::select('orders', ['status' => ['in' => self::OPEN]], ['order' => '-createdAt', 'limit' => 500], $sys);
        foreach ($open as $o) {
            $s = (string) ($o['status'] ?? ''); $id = (string) ($o['id'] ?? ''); $n = '#' . ((string) ($o['number'] ?? '') ?: substr($id, -6));
            $who = trim(($o['clientName'] ?? '') . ' ' . ($o['serviceLabel'] ?? ''));
            $age = self::mins($o['createdAt'] ?? '');
            if (in_array($s, ['new', 'forced'], true) && $age !== null && $age >= self::T['unassigned']) {
                $add($age >= 2 * self::T['unassigned'] || $s === 'forced' ? 'high' : 'mid', 'unassigned', "طلب $n ($who) من غير فني بقاله $age دقيقة", $id);
            }
            $since = self::mins($o['assignedAt'] ?? ($o['updatedAt'] ?? ''));
            if ($s === 'assigned' && $since !== null && $since >= self::T['not_accepted']) { $add('mid', 'not_accepted', "طلب $n اتسند لـ " . self::tname($techs, (string) ($o['technicianId'] ?? ''), $o) . " ولسه ما قبلهوش ($since دقيقة)", $id); }
            $appt = self::ts($o['appointmentAt'] ?? '');
            if ($appt && in_array($s, ['new', 'forced', 'assigned', 'accepted', 'en_route', 'nearby'], true) && time() - $appt >= self::T['appointment_late'] * 60) {
                $add('high', 'late', "موعد طلب $n كان " . wp_date('H:i', $appt) . ' والفني لسه ما وصلش', $id);
            }
            $up = self::mins($o['updatedAt'] ?? '');
            if ($s === 'in_progress' && $up !== null && $up >= self::T['long_job_h'] * 60) { $add('mid', 'long_job', "طلب $n شغال بقاله " . floor($up / 60) . ' ساعة من غير تحديث', $id); }
            if (in_array($s, ['en_route', 'nearby'], true) && !empty($o['technicianId']) && class_exists('Mutqan_Track') && !Mutqan_Track::where((string) $o['technicianId'])) {
                $add('low', 'no_position', self::tname($techs, (string) $o['technicianId'], $o) . " في الطريق لطلب $n بس موقعه مش واصل", $id);
            }
        }
        // Finished but not paid.
        foreach (Mutqan_Store::select('orders', ['status' => ['in' => self::DONE]], ['order' => '-updatedAt', 'limit' => 300], $sys) as $o) {
            if (($o['paymentStatus'] ?? '') === 'paid' || !empty($o['inhouse'])) { continue; }
            $m = self::mins($o['completedAt'] ?? ($o['updatedAt'] ?? ''));
            if ($m !== null && $m >= self::T['unpaid_h'] * 60 && $m <= 30 * 86400 / 60) { $add('mid', 'unpaid', 'طلب #' . ((string) ($o['number'] ?? '') ?: substr((string) $o['id'], -6)) . ' خلص من ' . floor($m / 60) . ' ساعة ولسه مش مدفوع (' . self::amount($o) . ' ر.س)', (string) $o['id']); }
        }
        // Cash held by technicians.
        foreach (self::custody_all() as $c) { if ($c['balance'] >= self::T['cash_limit']) { $add('mid', 'cash', 'الفني ' . $c['name'] . ' معاه ' . round($c['balance']) . ' ر.س للشركة لسه ما سلّمهاش', $c['id']); } }
        // Stock under its minimum.
        $items = Mutqan_Store::select('stock_items', [], ['limit' => 300], $sys);
        if ($items) {
            $central = [];
            foreach (Mutqan_Store::select('stock_moves', [], ['limit' => 3000], $sys) as $m) {
                $q = (float) ($m['qty'] ?? 0); $k = (string) ($m['itemId'] ?? '');
                if (($m['kind'] ?? '') === 'in' || ($m['kind'] ?? '') === 'return') { $central[$k] = ($central[$k] ?? 0) + $q; }
                elseif (($m['kind'] ?? '') === 'issue') { $central[$k] = ($central[$k] ?? 0) - $q; }
            }
            foreach ($items as $i) { $min = (float) ($i['min'] ?? 0); $have = $central[(string) $i['id']] ?? 0; if ($min > 0 && $have <= $min) { $add('low', 'stock', 'المخزن: ' . ($i['name'] ?? '') . ' فاضل ' . $have . ' (الحد ' . $min . ')', (string) $i['id']); } }
        }
        // Open handoffs and unanswered calls.
        $h = Mutqan_Store::count('handoffs', ['status' => ['ne' => 'closed']], $sys);
        if ($h > 0) { $add('mid', 'handoff', "$h محادثة محوّلة لموظف لسه مفتوحة", ''); }
        $missed = 0;
        foreach (Mutqan_Store::select('calls', ['createdAt' => ['gte' => gmdate('c', time() - 86400)]], ['limit' => 200], $sys) as $c) { if (($c['status'] ?? '') !== 'answered' && empty($c['followedUp'])) { $missed++; } }
        if ($missed > 0) { $add('mid', 'calls', "$missed مكالمة آخر 24 ساعة ما اتردش عليها ولا اتعملها متابعة", ''); }
        $rank = ['high' => 0, 'mid' => 1, 'low' => 2];
        usort($a, function ($x, $y) use ($rank) { return $rank[$x['level']] <=> $rank[$y['level']]; });
        return $a;
    }

    /** custody per technician, same formula as the app's accounts screen (lib/custody.js). */
    public static function custody_all() {
        $sys = self::sys(); $techs = self::techs(); $share = self::share(); $r = [];
        foreach (Mutqan_Store::select('orders', ['status' => ['in' => self::DONE]], ['limit' => 3000], $sys) as $o) {
            $t = (string) ($o['technicianId'] ?? '');
            if ($t === '' || !empty($o['inhouse']) || ($o['dispatch'] ?? '') === 'site') { continue; }
            $r[$t] = $r[$t] ?? ['id' => $t, 'name' => self::tname($techs, $t, $o), 'jobs' => 0, 'sales' => 0.0, 'commission' => 0.0, 'cash' => 0.0, 'settledIn' => 0.0, 'settledOut' => 0.0];
            $a = self::amount($o); $r[$t]['jobs']++; $r[$t]['sales'] += $a; $r[$t]['commission'] += $a * $share / 100;
            if (($o['paymentStatus'] ?? '') === 'paid' && self::is_cash($o)) { $r[$t]['cash'] += $a; }
        }
        foreach (Mutqan_Store::select('settlements', [], ['limit' => 3000], $sys) as $s) {
            $t = (string) ($s['techId'] ?? ''); if (!isset($r[$t])) { continue; }
            if (($s['dir'] ?? 'in') === 'out') { $r[$t]['settledOut'] += (float) ($s['amount'] ?? 0); } else { $r[$t]['settledIn'] += (float) ($s['amount'] ?? 0); }
        }
        foreach ($r as &$x) { $x['balance'] = round($x['cash'] - $x['commission'] - $x['settledIn'] + $x['settledOut'], 2); } unset($x);
        return array_values($r);
    }

    /* ------------------------------------------------------------------ tools (read only) */

    public static function tools() {
        $s = function ($props, $req = []) { return ['type' => 'object', 'properties' => (object) $props, 'required' => $req]; };
        if (self::$who) {
            return [
                ['name' => 'my_jobs', 'description' => 'شغلي: الطلبات المفتوحة معايا (العنوان، العميل، الموعد، الحالة، السعر) واللي خلصته النهارده.', 'input_schema' => $s([])],
                ['name' => 'my_job', 'description' => 'تفاصيل طلب من طلباتي بالرقم أو المعرّف: العميل وجواله والعنوان ورابط الخريطة والتفاصيل والسعر والدفع والخطوات.', 'input_schema' => $s(['id' => ['type' => 'string'], 'number' => ['type' => 'string']])],
                ['name' => 'my_account', 'description' => 'حسابي: عدد الشغل والمبيعات وعمولتي والكاش اللي معايا وصافي اللي عليّا أو ليّا.', 'input_schema' => $s([])],
            ];
        }
        return self::staff_tools();
    }

    private static function staff_tools() {
        $s = function ($props, $req = []) { return ['type' => 'object', 'properties' => (object) $props, 'required' => $req]; };
        $str = ['type' => 'string']; $int = ['type' => 'integer'];
        return [
            ['name' => 'ops_overview', 'description' => 'الصورة الحالية: الطلبات المفتوحة بكل حالة، طلبات النهارده، المنجز والإيراد النهارده، والطلبات اللي من غير فني.', 'input_schema' => $s([])],
            ['name' => 'alerts', 'description' => 'التنبيهات الحالية: طلبات متأخرة أو من غير فني، مواعيد فاتت، شغل طويل، غير مدفوع، عهدة نقدية كبيرة مع فني، نقص مخزن، محادثات ومكالمات معلّقة.', 'input_schema' => $s([])],
            ['name' => 'orders_archive', 'description' => 'أرشيف الطلبات: فلترة بالتاريخ (from/to بصيغة YYYY-MM-DD أو days)، الحالة، الفني (اسم أو id)، أو بحث q في الاسم/الجوال/الرقم/العنوان. يرجع المبلغ والدفع والفني.', 'input_schema' => $s(['from' => $str, 'to' => $str, 'days' => $int, 'status' => $str, 'tech' => $str, 'q' => $str, 'limit' => $int])],
            ['name' => 'order_story', 'description' => 'قصة طلب واحد كاملة: العميل والخدمة والسعر وتفصيله، الدفع، عمولة الفني، المصاريف المربوطة بيه، صافي الربح، وتسلسل الخطوات بالوقت ومين عملها والمدة بين كل خطوة.', 'input_schema' => $s(['id' => $str, 'number' => $str])],
            ['name' => 'where_techs', 'description' => 'فين الفنيين دلوقتي: آخر موقع (لو عنده شغل مفتوح) وبقاله قد إيه، الشغل اللي معاه وحالته، ومتاح ولا لأ. name للفلترة.', 'input_schema' => $s(['name' => $str])],
            ['name' => 'where_supervisors', 'description' => 'فين المشرفين وموظفين العمليات الميدانيين دلوقتي (آخر موقع خلال 3 ساعات وبقاله قد إيه).', 'input_schema' => $s(['name' => $str])],
            ['name' => 'money_report', 'description' => 'تقرير الفلوس لفترة: الإيراد من الطلبات المنجزة، حسب طريقة الدفع، عمولات الفنيين، المصاريف المسجلة (حسب البند)، صرف المخزن، التسويات، الصافي، والعهدة النقدية مع كل فني.', 'input_schema' => $s(['from' => $str, 'to' => $str, 'days' => $int])],
            ['name' => 'activity_log', 'description' => 'إيه اللي حصل: سجل كل خطوات الطلبات (إسناد، قبول، وصول، إنهاء، إلغاء…) وأوامر مساعد التحكم في آخر عدد ساعات، مرتب بالوقت.', 'input_schema' => $s(['hours' => $int, 'limit' => $int])],
        ];
    }

    public static function run_tool($name, $in) {
        $sys = self::sys();
        if (self::$who) {
            if (!in_array($name, self::TECH_TOOLS, true)) { throw new Exception('الأداة دي مش للفني.'); }
            return self::tech_tool($name, $in, (string) self::$who['id']);
        }
        if (in_array($name, self::TECH_TOOLS, true)) { throw new Exception('أداة غير معروفة.'); }
        switch ($name) {
            case 'alerts': return self::alerts();
            case 'ops_overview': {
                $techs = self::techs(); $by = []; $unassigned = [];
                foreach (Mutqan_Store::select('orders', ['status' => ['in' => self::OPEN]], ['order' => '-createdAt', 'limit' => 500], $sys) as $o) {
                    $k = self::STATUS[$o['status']] ?? $o['status']; $by[$k] = ($by[$k] ?? 0) + 1;
                    if (in_array($o['status'], ['new', 'forced'], true)) { $unassigned[] = self::brief($o, $techs) + ['waitingMin' => self::mins($o['createdAt'] ?? '')]; }
                }
                $start = (new DateTime(wp_date('Y-m-d') . ' 00:00:00', function_exists('wp_timezone') ? wp_timezone() : null))->getTimestamp();
                $today = self::orders_between($start, 0, 1000); $done = 0; $rev = 0.0;
                foreach ($today as $o) { if (in_array($o['status'] ?? '', self::DONE, true)) { $done++; $rev += self::amount($o); } }
                $busy = 0; foreach ($techs as $t) { if (($t['available'] ?? true) !== false) { $busy++; } }
                return ['now' => wp_date('Y-m-d H:i'), 'openByStatus' => $by, 'todayCreated' => count($today), 'todayDone' => $done, 'todayRevenue' => $rev, 'availableTechs' => $busy, 'totalTechs' => count($techs), 'unassigned' => array_slice($unassigned, 0, 20), 'alertsCount' => count(self::alerts())];
            }
            case 'orders_archive': {
                list($from, $to) = self::window($in, 30); $techs = self::techs();
                $q = trim((string) ($in['q'] ?? '')); $st = trim((string) ($in['status'] ?? '')); $tech = trim((string) ($in['tech'] ?? ''));
                $stKey = $st !== '' ? (array_search($st, self::STATUS, true) ?: $st) : '';
                $out = []; $sum = 0.0;
                foreach (self::orders_between($from, $to, 3000) as $o) {
                    if ($stKey !== '' && ($o['status'] ?? '') !== $stKey) { continue; }
                    if ($tech !== '') { $tid = (string) ($o['technicianId'] ?? ''); if ($tid !== $tech && mb_strpos(self::tname($techs, $tid, $o), $tech) === false) { continue; } }
                    if ($q !== '') { $hay = implode(' ', [$o['clientName'] ?? '', $o['clientPhone'] ?? '', $o['number'] ?? '', $o['address'] ?? '', $o['id'] ?? '', $o['serviceLabel'] ?? '']); if (mb_stripos($hay, $q) === false) { continue; } }
                    $b = self::brief($o, $techs); $sum += $b['amount']; $out[] = $b;
                }
                return ['from' => wp_date('Y-m-d', $from), 'to' => wp_date('Y-m-d', $to), 'count' => count($out), 'totalAmount' => $sum, 'orders' => array_slice($out, 0, max(1, min(100, (int) ($in['limit'] ?? 40))))];
            }
            case 'order_story': {
                $o = null;
                if (!empty($in['id'])) { $o = Mutqan_Store::get_raw('orders', (string) $in['id']); }
                if (!$o && !empty($in['number'])) { $r = Mutqan_Store::select('orders', ['number' => (string) $in['number']], ['limit' => 1], $sys); $o = $r[0] ?? null; }
                if (!$o && !empty($in['id'])) { $r = Mutqan_Store::select('orders', ['number' => (string) $in['id']], ['limit' => 1], $sys); $o = $r[0] ?? null; }
                if (!$o) { throw new Exception('الطلب مش موجود.'); }
                $techs = self::techs(); $amount = self::amount($o); $share = self::share();
                $commission = (!empty($o['inhouse']) || ($o['dispatch'] ?? '') === 'site') ? 0 : round($amount * $share / 100, 2);
                $exp = []; $expSum = 0.0;
                foreach (Mutqan_Store::select('expenses', ['orderId' => (string) $o['id']], ['limit' => 100], $sys) as $e) { $exp[] = ['item' => $e['category'] ?? ($e['note'] ?? ''), 'amount' => (float) ($e['amount'] ?? 0), 'at' => self::local($e['at'] ?? ($e['createdAt'] ?? ''))]; $expSum += (float) ($e['amount'] ?? 0); }
                $steps = []; $prev = 0;
                foreach ((array) ($o['route'] ?? []) as $s) {
                    $t = self::ts($s['at'] ?? '');
                    $steps[] = ['at' => self::local($s['at'] ?? ''), 'what' => $s['text'] ?? ($s['kind'] ?? ''), 'by' => $s['by'] ?? '', 'afterMin' => $prev && $t ? (int) round(($t - $prev) / 60) : null, 'where' => isset($s['lat']) ? ($s['lat'] . ',' . $s['lng']) : ''];
                    if ($t) { $prev = $t; }
                }
                $c0 = self::ts($o['createdAt'] ?? ''); $c1 = self::ts($o['completedAt'] ?? '');
                return self::brief($o, $techs) + [
                    'priceDetail' => array_filter(['price' => $o['price'] ?? null, 'agreedPrice' => $o['agreedPrice'] ?? null, 'quote' => $o['priceQuote'] ?? ($o['acQuote'] ?? null), 'vat' => $o['vat'] ?? null, 'negotiated' => !empty($o['negotiated'])], function ($v) { return $v !== null && $v !== false; }),
                    'cost' => ['techCommission' => $commission, 'sharePct' => $share, 'expenses' => $exp, 'expensesTotal' => $expSum, 'net' => round($amount - $commission - $expSum, 2)],
                    'paymentStatus' => $o['paymentStatus'] ?? '', 'staffNote' => $o['staffNote'] ?? '', 'details' => mb_substr((string) ($o['details'] ?? ($o['notes'] ?? '')), 0, 400),
                    'totalMinutes' => $c0 && $c1 ? (int) round(($c1 - $c0) / 60) : null, 'steps' => $steps,
                ];
            }
            case 'where_techs': {
                $name = trim((string) ($in['name'] ?? '')); $out = [];
                $open = Mutqan_Store::select('orders', ['status' => ['in' => self::WITH_TECH]], ['limit' => 500], $sys);
                foreach (self::techs() as $id => $t) {
                    $nm = (string) ($t['displayName'] ?? $t['name'] ?? 'فني');
                    if ($name !== '' && mb_strpos($nm, $name) === false) { continue; }
                    $jobs = [];
                    foreach ($open as $o) { if ((string) ($o['technicianId'] ?? '') === $id) { $jobs[] = ['number' => $o['number'] ?? substr((string) $o['id'], -6), 'status' => self::STATUS[$o['status']] ?? $o['status'], 'client' => $o['clientName'] ?? '', 'address' => mb_substr((string) ($o['address'] ?? ''), 0, 100), 'appointment' => self::local($o['appointmentAt'] ?? '')]; } }
                    $pos = class_exists('Mutqan_Track') ? Mutqan_Track::where($t) : null;
                    $out[] = ['id' => $id, 'name' => $nm, 'phone' => $t['phone'] ?? '', 'available' => ($t['available'] ?? true) !== false,
                        'position' => $pos ? ['lat' => $pos['lat'], 'lng' => $pos['lng'], 'minutesAgo' => (int) floor($pos['ago'] / 60), 'map' => 'https://maps.google.com/?q=' . $pos['lat'] . ',' . $pos['lng']] : null,
                        'positionNote' => $pos ? '' : ($jobs ? 'عنده شغل بس التطبيق ما بعتش موقعه خلال آخر 3 ساعات' : 'مفيش شغل مفتوح، فالموقع مش بيتسجل'),
                        'lastSeen' => self::local($t['lastSeenAt'] ?? ''), 'openJobs' => $jobs];
                }
                return $out;
            }
            case 'where_supervisors': {
                $name = trim((string) ($in['name'] ?? '')); $out = [];
                foreach (self::positions() as $id => $x) {
                    if ($name !== '' && mb_strpos((string) ($x['name'] ?? ''), $name) === false) { continue; }
                    $out[] = ['id' => $id, 'name' => $x['name'] ?: 'مشرف', 'role' => $x['role'] === 'supervisor' ? 'مشرف' : 'عمليات', 'minutesAgo' => (int) floor((time() - (int) $x['at']) / 60), 'lat' => $x['lat'], 'lng' => $x['lng'], 'map' => 'https://maps.google.com/?q=' . $x['lat'] . ',' . $x['lng']];
                }
                return $out ?: ['note' => 'مفيش مشرف فاتح التطبيق وباعت موقعه خلال آخر 3 ساعات.'];
            }
            case 'money_report': {
                list($from, $to) = self::window($in, 30); $share = self::share();
                $rev = 0.0; $done = 0; $comm = 0.0; $byPay = []; $cancelled = 0; $unpaid = 0.0;
                foreach (self::orders_between($from, $to, 3000) as $o) {
                    if (($o['status'] ?? '') === 'cancelled') { $cancelled++; continue; }
                    if (!in_array($o['status'] ?? '', self::DONE, true)) { continue; }
                    $a = self::amount($o); $done++; $rev += $a;
                    if (empty($o['inhouse']) && ($o['dispatch'] ?? '') !== 'site') { $comm += $a * $share / 100; }
                    $pm = (string) ($o['paymentMethod'] ?? '') ?: 'غير محدد'; $byPay[$pm] = ($byPay[$pm] ?? 0) + $a;
                    if (($o['paymentStatus'] ?? '') !== 'paid') { $unpaid += $a; }
                }
                $in2 = function ($v) use ($from, $to) { $t = self::ts($v); return $t >= $from && $t <= $to; };
                $exp = []; $expSum = 0.0;
                foreach (Mutqan_Store::select('expenses', [], ['order' => '-createdAt', 'limit' => 3000], $sys) as $e) {
                    if (!$in2($e['at'] ?? ($e['createdAt'] ?? ''))) { continue; }
                    $k = (string) ($e['category'] ?? 'أخرى'); $exp[$k] = ($exp[$k] ?? 0) + (float) ($e['amount'] ?? 0); $expSum += (float) ($e['amount'] ?? 0);
                }
                $setIn = 0.0; $setOut = 0.0;
                foreach (Mutqan_Store::select('settlements', [], ['limit' => 3000], $sys) as $s) { if (!$in2($s['at'] ?? '')) { continue; } if (($s['dir'] ?? 'in') === 'out') { $setOut += (float) ($s['amount'] ?? 0); } else { $setIn += (float) ($s['amount'] ?? 0); } }
                $issued = [];
                foreach (Mutqan_Store::select('stock_moves', [], ['limit' => 3000], $sys) as $m) { if (($m['kind'] ?? '') === 'issue' && $in2($m['at'] ?? '')) { $k = (string) ($m['itemName'] ?? $m['itemId'] ?? ''); $issued[$k] = ($issued[$k] ?? 0) + (float) ($m['qty'] ?? 0); } }
                return ['from' => wp_date('Y-m-d', $from), 'to' => wp_date('Y-m-d', $to), 'doneOrders' => $done, 'cancelledOrders' => $cancelled, 'revenue' => round($rev, 2), 'unpaidOfDone' => round($unpaid, 2), 'byPayment' => $byPay,
                    'techCommission' => round($comm, 2), 'sharePct' => $share, 'expenses' => $exp, 'expensesTotal' => round($expSum, 2), 'net' => round($rev - $comm - $expSum, 2),
                    'settlements' => ['receivedFromTechs' => $setIn, 'paidToTechs' => $setOut], 'stockIssued' => $issued,
                    'cashWithTechs' => array_values(array_filter(array_map(function ($c) { return ['name' => $c['name'], 'balance' => $c['balance']]; }, self::custody_all()), function ($c) { return abs($c['balance']) >= 1; })),
                    'note' => $expSum ? '' : 'مفيش مصاريف مسجلة في الفترة دي — المالك يقدر يسجلها من «مساعد التحكم» (مثلاً: سجّل مصروف بنزين 50 ريال).'];
            }
            case 'activity_log': {
                $hours = max(1, min(168, (int) ($in['hours'] ?? 24))); $since = time() - $hours * 3600; $ev = [];
                foreach (Mutqan_Store::select('orders', ['updatedAt' => ['gte' => gmdate('c', $since)]], ['order' => '-updatedAt', 'limit' => 400], $sys) as $o) {
                    $n = '#' . ((string) ($o['number'] ?? '') ?: substr((string) $o['id'], -6));
                    foreach ((array) ($o['route'] ?? []) as $s) { $t = self::ts($s['at'] ?? ''); if ($t >= $since) { $ev[] = ['t' => $t, 'at' => self::local($s['at']), 'order' => $n, 'client' => $o['clientName'] ?? '', 'what' => $s['text'] ?? '', 'by' => $s['by'] ?? '']; } }
                    $c = self::ts($o['createdAt'] ?? ''); if ($c >= $since) { $ev[] = ['t' => $c, 'at' => self::local($o['createdAt']), 'order' => $n, 'client' => $o['clientName'] ?? '', 'what' => 'طلب جديد: ' . ($o['serviceLabel'] ?? ''), 'by' => $o['source'] ?? 'العميل']; }
                }
                foreach (Mutqan_Store::select('_ai_audit', ['createdAt' => ['gte' => gmdate('c', $since)]], ['order' => '-createdAt', 'limit' => 100], $sys) as $a) {
                    $t = self::ts($a['at'] ?? ''); $ev[] = ['t' => $t, 'at' => self::local($a['at'] ?? ''), 'order' => '', 'client' => '', 'what' => 'أمر للمساعد: ' . mb_substr((string) ($a['detail'] ?? $a['action'] ?? ''), 0, 160) . (empty($a['ok']) ? ' (فشل)' : ''), 'by' => 'مساعد التحكم'];
                }
                usort($ev, function ($x, $y) { return $y['t'] <=> $x['t']; });
                return array_map(function ($e) { unset($e['t']); return $e; }, array_slice($ev, 0, max(10, min(300, (int) ($in['limit'] ?? 120)))));
            }
        }
        throw new Exception('أداة غير معروفة.');
    }

    /** A technician's own work only: another technician's jobs, positions and the company's money are never reachable. */
    private static function tech_tool($name, $in, $me) {
        $sys = self::sys(); $techs = self::techs();
        if ($name === 'my_jobs') {
            $open = array_map(function ($o) use ($techs) { return self::brief($o, $techs) + ['map' => isset($o['lat'], $o['lng']) ? 'https://maps.google.com/?q=' . $o['lat'] . ',' . $o['lng'] : '']; }, Mutqan_Store::select('orders', ['technicianId' => $me, 'status' => ['in' => self::WITH_TECH]], ['order' => '-createdAt', 'limit' => 50], $sys));
            $start = (new DateTime(wp_date('Y-m-d') . ' 00:00:00', function_exists('wp_timezone') ? wp_timezone() : null))->getTimestamp(); $done = [];
            foreach (Mutqan_Store::select('orders', ['technicianId' => $me, 'status' => ['in' => self::DONE]], ['order' => '-updatedAt', 'limit' => 50], $sys) as $o) { if (self::ts($o['completedAt'] ?? ($o['updatedAt'] ?? '')) >= $start) { $done[] = self::brief($o, $techs); } }
            return ['open' => $open, 'doneToday' => $done];
        }
        if ($name === 'my_job') {
            $o = null;
            if (!empty($in['id'])) { $o = Mutqan_Store::get_raw('orders', (string) $in['id']); }
            foreach (['number', 'id'] as $k) { if (!$o && !empty($in[$k])) { $r = Mutqan_Store::select('orders', ['number' => (string) $in[$k], 'technicianId' => $me], ['limit' => 1], $sys); $o = $r[0] ?? null; } }
            if (!$o || (string) ($o['technicianId'] ?? '') !== $me) { throw new Exception('الطلب ده مش من طلباتك.'); }
            $steps = array_map(function ($s) { return ['at' => self::local($s['at'] ?? ''), 'what' => $s['text'] ?? '', 'by' => $s['by'] ?? '']; }, (array) ($o['route'] ?? []));
            return self::brief($o, $techs) + ['details' => mb_substr((string) ($o['details'] ?? ($o['notes'] ?? '')), 0, 600), 'staffNote' => $o['staffNote'] ?? '', 'map' => isset($o['lat'], $o['lng']) ? 'https://maps.google.com/?q=' . $o['lat'] . ',' . $o['lng'] : '', 'steps' => $steps];
        }
        foreach (self::custody_all() as $c) { if ($c['id'] === $me) { return $c + ['meaning' => $c['balance'] > 0 ? 'عليك للشركة' : ($c['balance'] < 0 ? 'ليك عند الشركة' : 'متسوّي')]; } }
        return ['jobs' => 0, 'balance' => 0, 'meaning' => 'لسه مفيش شغل منتهي محسوب عليك.'];
    }

    /* ------------------------------------------------------------------ the model */

    private static function clean_messages($m) {
        $out = [];
        foreach (array_slice(is_array($m) ? $m : [], -20) as $x) {
            $role = ($x['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $text = mb_substr(trim((string) ($x['text'] ?? '')), 0, 6000);
            if ($text === '') { continue; }
            if ($out && end($out)['role'] === $role) { $out[count($out) - 1]['content'] .= "\n\n" . $text; continue; }
            $out[] = ['role' => $role, 'content' => $text];
        }
        while ($out && $out[0]['role'] !== 'user') { array_shift($out); }
        return $out;
    }

    private static function system($p) {
        if (($p['role'] ?? '') === 'technician') {
            return "أنت «مساعد الفني» في شركة أكسجين/أكسجين لصيانة التكييف وغرف التبريد (مكة وجدة). بتكلم الفني " . ($p['name'] ?? '') . " بالعربي المختصر، وغالباً بالصوت وهو في الشغل، فخلي الرد قصير وواضح.\n"
                . "بتجاوب من أدواته بس: شغله المفتوح، تفاصيل طلباته، وحسابه. ما تقدرش تشوف شغل فنيين تانيين ولا فلوس الشركة ولا تغيّر حالة طلب — تغيير الحالة من زرار الطلب في التطبيق.\n"
                . "ممكن كمان تساعده بنصايح فنية عامة في صيانة التكييف (أعطال، فريون، تنظيف) بإيجاز.\n"
                . 'الوقت الآن ' . wp_date('Y-m-d H:i') . ' بتوقيت السعودية.';
        }
        $who = ['owner' => 'المالك', 'operations' => 'موظف العمليات', 'supervisor' => 'المشرف'][$p['role']] ?? 'الفريق';
        return "أنت «مدير العمليات» لشركة أكسجين/أكسجين لصيانة التكييف وغرف التبريد (مكة وجدة). بتكلم {$who} بالعربي المصري المختصر.\n"
            . "دورك تقرير ومتابعة وتحليل بس: إيه اللي حصل، الأرشيف، تفاصيل الطلبات وأسعارها وتكلفتها وتسلسل خطواتها، فين الفنيين والمشرفين دلوقتي، الفلوس والمصاريف، والتنبيهات.\n"
            . "إنت مش اللي بيوزّع الطلبات ومش بتعدّل أي حاجة؛ لو حد طلب تعديل أو تنفيذ قوله يستخدم «مساعد التحكم» (للمالك) أو شاشة الطلب.\n"
            . "دايماً هات الأرقام من الأدوات، ما تخمّنش. اذكر رقم الطلب والمبلغ بالريال والوقت. لو فيه مشكلة اقترح الإجراء الجاي بالترتيب.\n"
            . "الموقع بيتسجل للفني بس وهو عنده شغل مفتوح، وللمشرف وهو فاتح التطبيق، وبيتشال بعد 3 ساعات — لو مفيش موقع قول كده بوضوح.\n"
            . 'الوقت الآن ' . wp_date('Y-m-d H:i') . ' بتوقيت السعودية.';
    }

    private static function loop($msgs, $p, $model) {
        $steps = []; $text = '';
        for ($i = 0; $i < self::MAX_STEPS; $i++) {
            list($code, $data, $raw) = Mutqan_AI::anthropic_request(['model' => $model, 'max_tokens' => 2000, 'system' => self::system($p), 'tools' => self::tools(), 'messages' => $msgs]);
            if ($code < 200 || $code >= 300 || !isset($data['content'])) {
                error_log('[mutqan] ops ' . $code . ' ' . substr((string) $raw, 0, 300));
                throw new Mutqan_Error('ai_unavailable', 'مدير العمليات مش متاح دلوقتي (' . $code . ').', 503);
            }
            Mutqan_AI::usage('anthropic', $model, $data['usage'] ?? [], 'ops');
            $msgs[] = ['role' => 'assistant', 'content' => $data['content']];
            $results = [];
            foreach ($data['content'] as $b) {
                if (($b['type'] ?? '') === 'text') { $text .= ($text !== '' ? "\n" : '') . $b['text']; }
                if (($b['type'] ?? '') !== 'tool_use') { continue; }
                try { $out = self::run_tool((string) $b['name'], (array) ($b['input'] ?? [])); $ok = true; }
                catch (Throwable $e) { $out = ['error' => $e->getMessage()]; $ok = false; }
                $steps[] = ['tool' => (string) $b['name'], 'status' => $ok ? 'done' : 'error'];
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $b['id'], 'content' => mb_substr(wp_json_encode($out, JSON_UNESCAPED_UNICODE), 0, 14000), 'is_error' => !$ok];
            }
            if (($data['stop_reason'] ?? '') !== 'tool_use') { break; }
            $msgs[] = ['role' => 'user', 'content' => $results];
            $text = '';                                   // keep only the final answer, not the "let me check" lines
        }
        return ['text' => trim($text) ?: 'مفيش رد.', 'steps' => $steps];
    }

    /** No Claude key: the free engine picks one tool, the server runs it, the engine words the answer (or the raw data is shown). */
    private static function free_turn($msgs, $p) {
        $last = ''; foreach (array_reverse($msgs) as $m) { if ($m['role'] === 'user') { $last = $m['content']; break; } }
        $tool = self::$who ? (preg_match('/حساب|عمول|كاش|فلوس|عليّ|عليا/u', $last) ? 'my_account' : (preg_match('/[0-9]{3,}/', $last, $mm) ? 'my_job' : 'my_jobs')) : self::guess_tool($last); $input = [];
        if ($tool === 'my_job' && !empty($mm[0])) { $input = ['number' => $mm[0]]; }
        if (Mutqan_AI::any_enabled()) {
            $list = implode("\n", array_map(function ($t) { return '- ' . $t['name'] . ': ' . $t['description']; }, self::tools()));
            try {
                $r = Mutqan_AI::run("اختار أداة واحدة تجاوب سؤال مدير العمليات.\nالأدوات:\n$list\n\nالسؤال: $last\n\nرد JSON فقط: {\"tool\":\"الاسم\",\"input\":{}}", ['json' => true, 'temperature' => 0, 'tier' => 'low'], $p);
                $j = is_array($r['json'] ?? null) ? $r['json'] : [];
                if (in_array($j['tool'] ?? '', array_column(self::tools(), 'name'), true)) { $tool = $j['tool']; $input = is_array($j['input'] ?? null) ? $j['input'] : []; }
            } catch (Throwable $e) { /* keep the keyword guess */ }
        }
        try { $out = self::run_tool($tool, $input); } catch (Throwable $e) { return ['text' => 'ما قدرتش أجيب البيانات: ' . $e->getMessage(), 'steps' => [['tool' => $tool, 'status' => 'error']]]; }
        $data = mb_substr(wp_json_encode($out, JSON_UNESCAPED_UNICODE), 0, 6000); $text = '';
        if (Mutqan_AI::any_enabled()) {
            try { $w = Mutqan_AI::run(self::system($p) . "\n\nالسؤال: $last\nالبيانات (JSON): $data\nجاوب من البيانات دي بس، بسطور قصيرة وأرقام واضحة، من غير JSON.", ['temperature' => 0.2, 'tier' => 'low'], $p); $text = trim((string) ($w['text'] ?? '')); } catch (Throwable $e) { /* fall through */ }
        }
        return ['text' => $text !== '' ? $text : self::plain($tool, $out), 'steps' => [['tool' => $tool, 'status' => 'done']]];
    }

    /** Arabic keywords → tool, so the screen answers even with no AI provider at all. */
    public static function guess_tool($q) {
        $map = [
            'where_supervisors' => '/مشرف/u', 'where_techs' => '/فين|مكان|موقع|الفني|الفنيين/u', 'money_report' => '/فلوس|إيراد|ايراد|مصاريف|مصروف|ربح|عمول|عهد|تكلف|دخل/u',
            'activity_log' => '/حصل|سجل|النهارده حصل|إيه اللي|ايه اللي|تسلسل اليوم/u', 'order_story' => '/طلب\s*#?\s*[0-9A-Za-z]{3,}|تفاصيل الطلب/u', 'orders_archive' => '/أرشيف|ارشيف|قديم|الشهر|الأسبوع|الاسبوع|امبارح|أمس/u',
            'alerts' => '/تنبيه|متأخر|متاخر|مشاكل|مشكلة|تحذير/u',
        ];
        foreach ($map as $tool => $re) { if (preg_match($re, $q)) { return $tool; } }
        return 'ops_overview';
    }

    /** Readable text from a tool result when no model is available. */
    private static function plain($tool, $out) {
        if ($tool === 'alerts') { return $out ? implode("\n", array_map(function ($a) { return ($a['level'] === 'high' ? '🔴 ' : ($a['level'] === 'mid' ? '🟠 ' : '🟡 ')) . $a['text']; }, $out)) : 'مفيش تنبيهات دلوقتي ✅'; }
        if ($tool === 'where_techs') { return implode("\n", array_map(function ($t) { return '• ' . $t['name'] . ': ' . ($t['position'] ? 'آخر موقع من ' . $t['position']['minutesAgo'] . ' دقيقة ' . $t['position']['map'] : $t['positionNote']) . ($t['openJobs'] ? ' — ' . count($t['openJobs']) . ' شغل مفتوح' : ''); }, $out)) ?: 'مفيش فنيين.'; }
        return "النتيجة:\n" . wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
