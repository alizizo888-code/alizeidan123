<?php
/**
 * The field (owner order 2026-11-20):
 *  - «pin»: tap anything on a live map (technician's map, operations room, field map) → who it is, his rating, the job,
 *    where it is, and — for a returning customer — every earlier visit: where, what was done, by whom, the report.
 *  - «near»: the old customers around a point, so a technician sees who he already served on his way.
 *  - «abort»: the technician on site cannot fix it → a report (why + the state of the fault). The job goes back to
 *    operations (or is cancelled) and never counts as his: no completed job, no share (noCommission).
 * Technicians see names, places and history; a phone number only on a job that is his. Staff see everything.
 */
if (!defined('ABSPATH')) { exit; }

final class Mutqan_Field {
    const DONE = ['completed', 'closed'];
    const ABORT_REASONS = [
        'bigger' => 'العطل أكبر من المتاح / محتاج ورشة',
        'part' => 'محتاج قطعة مش متوفرة',
        'specialist' => 'محتاج فني تخصص تاني',
        'price' => 'العميل رفض السعر',
        'absent' => 'العميل مش موجود / مفيش رد',
        'site' => 'المكان مش مناسب / مفيش وصول للجهاز',
        'other' => 'سبب تاني',
    ];

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user']; }
    private static function is_tech($p) { return ($p['role'] ?? '') === 'technician'; }

    public static function handle($in, $p) {
        Mutqan_Auth::require_user($p);
        if (!Mutqan_Policy::is_staff($p)) { throw new Mutqan_Error('forbidden', 'للفريق فقط.', 403); }
        $op = (string) ($in['op'] ?? '');
        if ($op === 'pin') { Mutqan_Limiter::hit('field_pin', 600, 3600); return self::pin($in, $p); }
        if ($op === 'near') { Mutqan_Limiter::hit('field_near', 300, 3600); return self::near((float) ($in['lat'] ?? 0), (float) ($in['lng'] ?? 0), (float) ($in['km'] ?? 5), $p); }
        if ($op === 'abort') { return self::abort($in, $p); }
        if ($op === 'reasons') { return self::ABORT_REASONS; }
        throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400);
    }

    /* ------------------------------------------------------------------ helpers */

    private static function key($phone) { return class_exists('Mutqan_Customers') ? Mutqan_Customers::key($phone) : preg_replace('/\D/', '', (string) $phone); }
    private static function dist($a, $b, $c, $d) {
        $r = 6371000; $p1 = deg2rad($a); $p2 = deg2rad($c); $dp = deg2rad($c - $a); $dl = deg2rad($d - $b);
        $h = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return 2 * $r * asin(min(1, sqrt($h)));
    }
    private static function amount($o) { return class_exists('Mutqan_Ops') ? Mutqan_Ops::amount($o) : (float) ($o['price'] ?? 0); }
    private static function svc($o) { return class_exists('Mutqan_Rest') ? Mutqan_Rest::spec_label($o['specialtyId'] ?? '') : (string) ($o['specialtyId'] ?? ''); }
    private static function done_at($o) {
        $t = strtotime((string) ($o['completedAt'] ?? '')) ?: 0;
        if (!$t) { foreach (array_reverse((array) ($o['statusHistory'] ?? [])) as $h) { if (in_array($h['status'] ?? '', self::DONE, true)) { $t = strtotime((string) ($h['at'] ?? '')) ?: 0; break; } } }
        return $t ?: (strtotime((string) ($o['createdAt'] ?? '')) ?: 0);
    }
    private static function mask($phone) { $d = preg_replace('/\D/', '', (string) $phone); return strlen($d) >= 6 ? substr($d, 0, 3) . '•••' . substr($d, -2) : ''; }
    private static function orders() { return Mutqan_Store::select('orders', [], ['order' => '-createdAt', 'limit' => 3000], self::sys()); }

    /** One visit, as the card shows it. A technician does not see other jobs' prices or phone numbers. */
    private static function visit($o, $p) {
        $mine = !self::is_tech($p) || ($o['technicianId'] ?? '') === ($p['id'] ?? '');
        $w = class_exists('Mutqan_OrderBot') ? Mutqan_OrderBot::warranty($o) : null;
        $abort = is_array($o['techAbort'] ?? null) ? $o['techAbort'] : null;
        return array_filter([
            'id' => (string) ($o['id'] ?? ''), 'n' => (string) ($o['number'] ?? '') ?: substr((string) ($o['id'] ?? ''), -5),
            'at' => gmdate('c', self::done_at($o) ?: time()), 'status' => (string) ($o['status'] ?? ''), 'service' => self::svc($o),
            'details' => trim((string) ($o['serviceDetails'] ?? '') . (!empty($o['acQuote']['type']) ? ' — ' . $o['acQuote']['type'] . ' × ' . ($o['acQuote']['qty'] ?? 1) : '')),
            'fault' => mb_substr((string) ($o['description'] ?? ($o['problem'] ?? '')), 0, 300),
            'report' => mb_substr((string) ($o['report'] ?? ($o['completionReport'] ?? '')), 0, 500),
            'tech' => (string) ($o['technicianName'] ?? ''), 'price' => $mine ? self::amount($o) : null,
            'paid' => $mine ? (($o['paymentStatus'] ?? '') === 'paid') : null,
            'warranty' => $w ? ($o['warrantyVoid'] ?? false ? 'ملغي' : ($w['active'] ? 'ساري — باقي ' . $w['left'] . ' يوم' : 'انتهى ' . $w['until'])) : '',
            'stars' => !empty($o['rating']['stars']) ? (int) $o['rating']['stars'] : null,
            'onClient' => !empty($o['techOnClient']) ? ['stars' => (int) ($o['techOnClient']['stars'] ?? 0), 'note' => mb_substr((string) ($o['techOnClient']['note'] ?? ''), 0, 200), 'tags' => array_slice((array) ($o['techOnClient']['tags'] ?? []), 0, 6)] : null,
            'abort' => $abort ? ['reason' => self::ABORT_REASONS[$abort['reason'] ?? ''] ?? ($abort['reason'] ?? ''), 'condition' => (string) ($abort['condition'] ?? ''), 'by' => (string) ($abort['byName'] ?? '')] : null,
            'address' => mb_substr((string) ($o['address'] ?? ''), 0, 160), 'lat' => $o['lat'] ?? null, 'lng' => $o['lng'] ?? null,
        ], function ($v) { return $v !== null && $v !== ''; });
    }

    /* ------------------------------------------------------------------ pin card */

    private static function pin($in, $p) {
        $all = self::orders();
        $cur = null;
        $oid = (string) ($in['orderId'] ?? '');
        if ($oid !== '') { foreach ($all as $o) { if ((string) ($o['id'] ?? '') === $oid) { $cur = $o; break; } } }
        $isTech = self::is_tech($p); $me = (string) ($p['id'] ?? '');
        $own = $cur && ($cur['technicianId'] ?? '') === $me;
        // A technician opens an order card only for his own job or one still waiting for a technician.
        if ($cur && $isTech && !$own && !in_array($cur['status'] ?? '', ['new', 'forced'], true) && !in_array($cur['status'] ?? '', self::DONE, true)) { $cur = null; }
        $phone = $cur ? (string) ($cur['clientPhone'] ?? '') : ($isTech ? '' : (string) ($in['phone'] ?? ''));
        $k = self::key($phone !== '' ? $phone : (string) ($in['key'] ?? ''));
        $lat = is_numeric($in['lat'] ?? null) ? (float) $in['lat'] : ($cur['lat'] ?? null);
        $lng = is_numeric($in['lng'] ?? null) ? (float) $in['lng'] : ($cur['lng'] ?? null);

        // His record: same phone; with no phone, the same place (within 60 m).
        $hist = [];
        foreach ($all as $o) {
            if ($cur && ($o['id'] ?? '') === ($cur['id'] ?? '')) { continue; }
            $same = $k !== '' && strlen($k) >= 8 && self::key($o['clientPhone'] ?? '') === $k;
            if (!$same && $lat !== null && is_numeric($o['lat'] ?? null) && self::dist((float) $lat, (float) $lng, (float) $o['lat'], (float) $o['lng']) <= 60) { $same = true; }
            if ($same) { $hist[] = $o; }
        }
        usort($hist, function ($a, $b) { return self::done_at($b) <=> self::done_at($a); });
        $done = array_values(array_filter($hist, function ($o) { return in_array($o['status'] ?? '', self::DONE, true); }));

        $name = trim((string) ($cur['clientName'] ?? '')); foreach ($hist as $o) { if ($name === '' && trim((string) ($o['clientName'] ?? '')) !== '') { $name = trim((string) $o['clientName']); } }
        $stars = []; $views = []; $tags = [];
        foreach (array_merge($cur ? [$cur] : [], $hist) as $o) {
            if (!empty($o['rating']['stars'])) { $stars[] = (int) $o['rating']['stars']; }
            if (!empty($o['techOnClient']['stars'])) { $views[] = (int) $o['techOnClient']['stars']; }
            foreach ((array) ($o['techOnClient']['tags'] ?? []) as $t) { $tags[(string) $t] = ($tags[(string) $t] ?? 0) + 1; }
        }
        arsort($tags);
        $showPhone = !$isTech || $own;
        $last = $done[0] ?? null;
        return [
            'customer' => [
                'name' => $name, 'phone' => $showPhone ? (string) ($cur['clientPhone'] ?? $phone) : '', 'phoneMasked' => $showPhone ? '' : self::mask($cur['clientPhone'] ?? ($hist[0]['clientPhone'] ?? '')),
                'kind' => count($done) >= 4 ? 'vip' : (count($done) >= 1 ? 'returning' : 'new'),
                'visits' => count($done), 'orders' => count($hist) + ($cur ? 1 : 0),
                'lastAt' => $last ? gmdate('c', self::done_at($last)) : null, 'lastService' => $last ? self::svc($last) : '', 'lastTech' => $last ? (string) ($last['technicianName'] ?? '') : '',
                'gaveStars' => $stars ? round(array_sum($stars) / count($stars), 1) : null,            // how he rates us
                'techStars' => $views ? round(array_sum($views) / count($views), 1) : null,           // how the technicians rate him
                'tags' => array_slice(array_keys($tags), 0, 6),
                'spent' => $isTech ? null : array_sum(array_map([__CLASS__, 'amount'], $done)),
            ],
            'order' => $cur ? array_merge(self::visit($cur, $p), [
                'priority' => (string) ($cur['priority'] ?? ''), 'when' => (string) ($cur['preferredTime'] ?? ''), 'appointmentAt' => (string) ($cur['appointmentAt'] ?? ''),
                'mine' => $own, 'site' => class_exists('Mutqan_Customers') ? Mutqan_Customers::brand_name($cur['brand'] ?? '') : '',
            ]) : null,
            'history' => array_map(function ($o) use ($p) { return self::visit($o, $p); }, array_slice($hist, 0, 25)),
            'lat' => $lat, 'lng' => $lng,
        ];
    }

    /** Old customers (at least one finished job) around a point, nearest first. */
    private static function near($lat, $lng, $km, $p) {
        if (!$lat || !$lng) { return []; }
        $km = max(0.5, min(25, $km));
        $by = [];
        foreach (self::orders() as $o) {
            if (!in_array($o['status'] ?? '', self::DONE, true) || !is_numeric($o['lat'] ?? null)) { continue; }
            $d = self::dist($lat, $lng, (float) $o['lat'], (float) $o['lng']);
            if ($d > $km * 1000) { continue; }
            $k = self::key($o['clientPhone'] ?? '') ?: ('o' . round((float) $o['lat'], 4) . ',' . round((float) $o['lng'], 4));
            $t = self::done_at($o);
            if (!isset($by[$k])) { $by[$k] = ['key' => $k, 'name' => (string) ($o['clientName'] ?? ''), 'lat' => (float) $o['lat'], 'lng' => (float) $o['lng'], 'm' => (int) $d, 'n' => 0, 'lastAt' => 0, 'lastService' => '', 'lastTech' => '', 'orderId' => '', 'address' => '', 'mine' => false]; }
            $c = &$by[$k]; $c['n']++;
            if (($o['technicianId'] ?? '') === ($p['id'] ?? '')) { $c['mine'] = true; }
            if ($c['name'] === '' && !empty($o['clientName'])) { $c['name'] = (string) $o['clientName']; }
            if ($t >= $c['lastAt']) { $c['lastAt'] = $t; $c['lastService'] = self::svc($o); $c['lastTech'] = (string) ($o['technicianName'] ?? ''); $c['orderId'] = (string) ($o['id'] ?? ''); $c['address'] = mb_substr((string) ($o['address'] ?? ''), 0, 120); $c['lat'] = (float) $o['lat']; $c['lng'] = (float) $o['lng']; $c['m'] = (int) $d; }
            unset($c);
        }
        $out = array_values($by);
        usort($out, function ($a, $b) { return $a['m'] <=> $b['m']; });
        foreach ($out as &$c) { $c['days'] = $c['lastAt'] ? (int) floor((time() - $c['lastAt']) / 86400) : null; $c['lastAt'] = $c['lastAt'] ? gmdate('c', $c['lastAt']) : null; } unset($c);
        return array_slice($out, 0, 80);
    }

    /* ------------------------------------------------------------------ couldn't fix it */

    private static function abort($in, $p) {
        Mutqan_Limiter::hit('field_abort', 30, 3600);
        $id = (string) ($in['orderId'] ?? '');
        $o = Mutqan_Store::get_raw('orders', $id);
        if (!$o) { throw new Mutqan_Error('not_found', 'الطلب مش موجود.', 404); }
        $me = (string) ($p['id'] ?? '');
        $isTech = self::is_tech($p);
        if ($isTech && ($o['technicianId'] ?? '') !== $me) { throw new Mutqan_Error('forbidden', 'الطلب ده مش مسند لك.', 403); }
        if (!$isTech && !Mutqan_Policy::has_power($p, 'write')) { throw new Mutqan_Error('forbidden', 'غير مسموح.', 403); }
        $was = (string) ($o['status'] ?? '');
        if (!in_array($was, ['assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress'], true)) { throw new Mutqan_Error('bad_state', 'الطلب في حالة ماينفعش يتوقف منها.', 409); }
        $reason = (string) ($in['reason'] ?? '');
        if (!isset(self::ABORT_REASONS[$reason])) { throw new Mutqan_Error('bad_input', 'اختار السبب.', 400); }
        $cond = trim(mb_substr((string) ($in['condition'] ?? ''), 0, 800));
        if (mb_strlen($cond) < 5) { throw new Mutqan_Error('bad_input', 'اكتب حالة العطل باختصار (اللي لقيته في الموقع).', 400); }
        $action = ($in['action'] ?? '') === 'cancel' ? 'cancel' : 'return';
        $now = gmdate('c');
        $techId = (string) ($o['technicianId'] ?? '');
        $techName = (string) ($o['technicianName'] ?? '');
        $rec = ['at' => $now, 'by' => $me, 'byName' => $isTech ? ((string) ($p['displayName'] ?? '') ?: $techName) : ((string) ($p['displayName'] ?? '') ?: 'العمليات'),
            'techId' => $techId, 'techName' => $techName, 'stage' => $was, 'reason' => $reason, 'reasonLabel' => self::ABORT_REASONS[$reason], 'condition' => $cond,
            'note' => trim(mb_substr((string) ($in['note'] ?? ''), 0, 400)), 'action' => $action];
        $log = is_array($o['techAborts'] ?? null) ? $o['techAborts'] : [];
        $log[] = $rec;
        $patch = [
            'techAbort' => $rec, 'techAborts' => array_slice($log, -10),
            // This visit is not his job: it never counts in his completed work or his share.
            'abortedTechIds' => array_values(array_unique(array_merge((array) ($o['abortedTechIds'] ?? []), [$techId]))),
            'statusHistory' => array_merge((array) ($o['statusHistory'] ?? []), [['status' => $action === 'cancel' ? 'cancelled' : 'new', 'at' => $now, 'by' => $isTech ? 'technician' : 'operations', 'note' => 'تعذّر الإصلاح: ' . self::ABORT_REASONS[$reason]]]),
            'route' => array_merge((array) ($o['route'] ?? []), [['at' => $now, 'kind' => 'abort', 'text' => 'تعذّر الإصلاح — ' . self::ABORT_REASONS[$reason] . ': ' . mb_substr($cond, 0, 140), 'by' => $rec['byName']]]),
        ];
        if ($action === 'cancel') {
            $patch += ['status' => 'cancelled', 'cancelledAt' => $now, 'cancelReason' => 'تعذّر الإصلاح — ' . self::ABORT_REASONS[$reason], 'noCommission' => true];
        } else {
            // Back to the operations room, without a technician, to send someone else or call the customer.
            $patch += ['status' => 'new', 'technicianId' => null, 'technicianName' => null, 'handler' => 'operations', 'forced' => false,
                'transferLog' => array_merge((array) ($o['transferLog'] ?? []), [['from' => 'technician', 'to' => 'operations', 'at' => $now, 'by' => $isTech ? 'technician' : 'operations', 'note' => 'تعذّر الإصلاح: ' . self::ABORT_REASONS[$reason]]])];
        }
        $doc = Mutqan_Store::update('orders', $id, $patch, self::sys());
        $n = '#' . ((string) ($o['number'] ?? '') ?: substr($id, -5));
        if (class_exists('Mutqan_Rest')) {
            Mutqan_Rest::admin_alert('⚠️ تعذّر الإصلاح — ' . $n,
                ($techName ?: 'الفني') . ' — ' . self::ABORT_REASONS[$reason] . "\nحالة العطل: " . mb_substr($cond, 0, 300) . "\n" . ($action === 'cancel' ? 'اتلغى الطلب.' : 'رجع لغرفة العمليات من غير فني — محتاج قرار: فني تاني ولا إلغاء.'),
                '#/staff/order/' . $id, 'order_abort', 'high', $id);
        }
        return ['ok' => true, 'status' => $doc['status'] ?? ($action === 'cancel' ? 'cancelled' : 'new'), 'action' => $action];
    }
}
