<?php
/**
 * The technician's field tools (owner order 2026-11-22), all through POST /field so the server checks who may do what:
 *  - post     a field note: a new job he found (time, place, photos), a road warning (traffic / accident / a problem in
 *             an area), a map pin, an instruction, or a reminder for himself — to himself, operations, one colleague,
 *             or the whole team. A post with a time rings its audience at that time (reminders).
 *  - posts    what he may read: his own, those sent to him, to all; the office reads everything.
 *  - ack      «تم» / seen on a post.
 *  - claim    the radar: take an open job himself (no technician yet) — or, when another technician already has it,
 *             ask operations to give it to him.
 *  - decline  say sorry / refuse a job given to him, with a reason: it goes back to operations and is written on the
 *             order (`declines`) — the owner sees it in the suggested deductions and decides.
 *  - payout   ask for his dues to be transferred (amount + his own sum), operations and the owner are told.
 *  - invoice  ask operations to issue the customer's invoice for a job of his.
 * Operations always see every post and request.
 */
if (!defined('ABSPATH')) { exit; }

final class Mutqan_Fieldops {
    const KINDS = ['job' => '🆕 طلب جديد من الميدان', 'appt' => '📅 موعد', 'warn' => '⚠️ تحذير طريق', 'pin' => '📍 دبوس على الخريطة', 'note' => '📝 ملاحظة / تعليمات', 'remind' => '⏰ تذكير', 'voice' => '🎙️ رسالة صوتية'];
    const DECLINE = ['busy' => 'مشغول في طلب تاني', 'far' => 'المسافة بعيدة', 'skill' => 'خارج تخصصي', 'tools' => 'ناقصني عدة / قطع', 'personal' => 'ظرف شخصي', 'other' => 'سبب تاني'];

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user']; }
    private static function is_tech($p) { return ($p['role'] ?? '') === 'technician'; }
    private static function me($p) { return (string) ($p['id'] ?? ''); }
    private static function name($p) { return mb_substr(trim((string) ($p['displayName'] ?? ($p['name'] ?? ''))) ?: 'فني', 0, 60); }
    private static function push($target, $title, $body, $url = '#/staff/radar?tab=field') {
        try { Mutqan_Store::insert('_push', ['target' => $target, 'title' => mb_substr($title, 0, 120), 'body' => mb_substr($body, 0, 500), 'url' => $url, 'sound' => 'bell', 'priority' => 'high'], null, [], self::sys()); } catch (Throwable $e) { error_log('[mutqan] fieldops push ' . $e->getMessage()); }
    }

    public static function handle($op, $in, $p) {
        switch ($op) {
            case 'post': Mutqan_Limiter::hit('field_post', 60, 3600); return self::post($in, $p);
            case 'posts': return self::posts($p);
            case 'ack': return self::ack($in, $p);
            case 'radar': Mutqan_Limiter::hit('field_radar', 400, 3600); return self::radar($p);
            case 'claim': Mutqan_Limiter::hit('field_claim', 40, 3600); return self::claim($in, $p);
            case 'decline': Mutqan_Limiter::hit('field_decline', 30, 3600); return self::decline($in, $p);
            case 'declineReasons': return self::DECLINE;
            case 'payout': Mutqan_Limiter::hit('field_payout', 6, 86400); return self::payout($in, $p);
            case 'objection': Mutqan_Limiter::hit('field_obj', 20, 86400); return self::objection($in, $p);
            case 'topup': Mutqan_Limiter::hit('field_topup', 10, 86400); return self::topup($in, $p);
            case 'invoice': Mutqan_Limiter::hit('field_invoice', 30, 3600); return self::invoice($in, $p);
        }
        return null;
    }

    /* ------------------------------------------------------------------ posts */

    private static function post($in, $p) {
        $kind = (string) ($in['kind'] ?? 'note');
        if (!isset(self::KINDS[$kind])) { throw new Mutqan_Error('bad_input', 'نوع غير معروف.', 400); }
        $text = trim(mb_substr((string) ($in['text'] ?? ''), 0, 1200));
        if ($text === '' && !in_array($kind, ['pin', 'voice'], true)) { throw new Mutqan_Error('bad_input', 'اكتب التفاصيل.', 400); }
        $to = (string) ($in['to'] ?? 'ops');
        if (!in_array($to, ['self', 'ops', 'all'], true) && strpos($to, 'u:') !== 0) { $to = 'ops'; }
        $photos = array_values(array_filter(array_map(function ($u) { $u = esc_url_raw((string) $u); return $u !== '' ? $u : null; }, array_slice((array) ($in['photos'] ?? []), 0, 6))));
        $at = !empty($in['at']) ? gmdate('c', (int) strtotime((string) $in['at'])) : '';
        $doc = [
            'kind' => $kind, 'text' => $text, 'to' => $to, 'photos' => $photos,
            'lat' => is_numeric($in['lat'] ?? null) ? (float) $in['lat'] : null, 'lng' => is_numeric($in['lng'] ?? null) ? (float) $in['lng'] : null,
            'place' => mb_substr((string) ($in['place'] ?? ''), 0, 200), 'clientName' => mb_substr((string) ($in['clientName'] ?? ''), 0, 80),
            'clientPhone' => mb_substr(preg_replace('/[^\d+]/', '', (string) ($in['clientPhone'] ?? '')), 0, 20), 'specialtyId' => mb_substr((string) ($in['specialtyId'] ?? ''), 0, 40),
            'audio' => esc_url_raw((string) ($in['audio'] ?? '')), 'radiusKm' => max(0, min(50, (float) ($in['radiusKm'] ?? 0))),
            'at' => $at, 'rung' => false, 'byId' => self::me($p), 'byName' => self::name($p), 'byRole' => (string) ($p['role'] ?? ''),
            'orderId' => mb_substr((string) ($in['orderId'] ?? ''), 0, 64), 'createdAt' => gmdate('c'), 'acks' => [], 'status' => 'open',
        ];
        $row = Mutqan_Store::insert('field_posts', $doc, null, ['expiresAt' => gmdate('c', time() + 14 * 86400)], self::sys());
        $now = time();
        // a reminder for later rings at its time (tick()); everything else is told now
        if ($to !== 'self' && (!$at || strtotime($at) <= $now + 60)) { self::announce($doc); }
        return ['ok' => true, 'id' => $row['id'] ?? null];
    }

    private static function audience($doc) {
        $to = (string) $doc['to'];
        if ($to === 'self') { return [['user' => $doc['byId']]]; }
        if ($to === 'all') {
            // a road warning with a place goes to the technicians within its radius (default 5 km), not the whole team
            if (($doc['kind'] ?? '') === 'warn' && isset($doc['lat']) && $doc['lat'] !== null) {
                $r = (float) ($doc['radiusKm'] ?? 0) ?: 5.0; $out = [['role' => 'operations']];
                foreach (Mutqan_Store::select('tech_profiles', [], ['limit' => 300], self::sys()) as $t) {
                    $la = $t['lat'] ?? ($t['homeLat'] ?? null); $ln = $t['lng'] ?? ($t['homeLng'] ?? null);
                    if ($la === null || ($t['userId'] ?? '') === $doc['byId']) { continue; }
                    if (self::km((float) $doc['lat'], (float) $doc['lng'], (float) $la, (float) $ln) <= $r) { $out[] = ['user' => (string) $t['userId']]; }
                }
                return $out;
            }
            return [['role' => 'technician'], ['role' => 'operations'], ['role' => 'owner']];
        }
        if (strpos($to, 'u:') === 0) { return [['user' => substr($to, 2)], ['role' => 'operations']]; }
        return [['role' => 'operations'], ['role' => 'owner']];
    }

    private static function km($a, $b, $c, $d) { $r = 6371; $x = deg2rad($c - $a); $y = deg2rad($d - $b); $h = sin($x / 2) ** 2 + cos(deg2rad($a)) * cos(deg2rad($c)) * sin($y / 2) ** 2; return 2 * $r * asin(min(1, sqrt($h))); }

    private static function announce($doc) {
        $title = (self::KINDS[$doc['kind']] ?? '📣') . ' — ' . $doc['byName'];
        $body = $doc['text'] . ($doc['at'] ? "\n🕒 " . wp_date('D H:i', strtotime($doc['at'])) : '') . ($doc['place'] ? "\n📍 " . $doc['place'] : '');
        foreach (self::audience($doc) as $t) { if (($t['user'] ?? '') !== $doc['byId'] || $doc['to'] === 'self') { self::push($t, $title, $body); } }
        if (in_array($doc['kind'], ['job', 'warn'], true) && $doc['to'] !== 'self' && class_exists('Mutqan_Rest')) {
            Mutqan_Rest::admin_alert($title, $body, '#/staff/operations', 'field_post', $doc['kind'] === 'job' ? 'high' : 'normal');
        }
    }

    /** Reminders due now ring their audience; called at most once a minute from REST traffic. */
    public static function tick() {
        if (get_transient('mutqan_fieldops_tick')) { return; }
        set_transient('mutqan_fieldops_tick', 1, 55);
        try {
            foreach (Mutqan_Store::select('field_posts', [], ['limit' => 200, 'order' => '-createdAt'], self::sys()) as $d) {
                if (!empty($d['rung']) || empty($d['at']) || strtotime((string) $d['at']) > time()) { continue; }
                Mutqan_Store::update('field_posts', $d['id'], ['rung' => true], self::sys());
                $d['text'] = '⏰ ' . $d['text'];
                self::announce($d);
            }
        } catch (Throwable $e) { error_log('[mutqan] fieldops tick ' . $e->getMessage()); }
        try { self::weekly(); self::monthly(); } catch (Throwable $e) { error_log('[mutqan] fieldops reports ' . $e->getMessage()); }
    }

    /** Saturday morning (Makkah): last week per technician — declines, failed visits, radar takes, payout asks, late. */
    private static function weekly() {
        $tz = new DateTimeZone('Asia/Riyadh'); $now = new DateTime('now', $tz);
        $key = $now->format('o-W');
        if ((int) $now->format('N') !== 6 || (int) $now->format('G') < 9 || get_option('mutqan_tech_weekly') === $key) { return; }
        update_option('mutqan_tech_weekly', $key, false);
        $since = time() - 7 * 86400; $by = [];
        $row = function ($id, $name) use (&$by) { if (!isset($by[$id])) { $by[$id] = ['name' => $name ?: 'فني', 'done' => 0, 'decl' => 0, 'abort' => 0, 'radar' => 0, 'late' => 0, 'rate' => [], 'cash' => 0]; } return $id; };
        foreach (Mutqan_Store::select('orders', [], ['limit' => 1500, 'order' => '-createdAt'], self::sys()) as $o) {
            foreach ((array) ($o['declines'] ?? []) as $d) { if (strtotime((string) $d['at']) >= $since) { $by[$row($d['techId'], $d['techName'] ?? '')]['decl']++; } }
            foreach ((array) ($o['techAborts'] ?? []) as $d) { if (strtotime((string) ($d['at'] ?? '')) >= $since && !empty($d['techId'])) { $by[$row($d['techId'], $d['techName'] ?? '')]['abort']++; } }
            $tid = (string) ($o['technicianId'] ?? ''); if ($tid === '') { continue; }
            $t = strtotime((string) ($o['completedAt'] ?? ($o['updatedAt'] ?? '')));
            if (in_array($o['status'] ?? '', ['completed', 'closed'], true) && $t >= $since) {
                $k = $row($tid, $o['technicianName'] ?? ''); $by[$k]['done']++;
                $st = class_exists('Mutqan_Dispatch') ? Mutqan_Dispatch::stars($o) : 0; if ($st) { $by[$k]['rate'][] = $st; }
                if (preg_match('/cash|نقد/i', (string) ($o['paymentMethod'] ?? ''))) { $by[$k]['cash']++; }
                if (!empty($o['appointmentAt']) && !empty($o['arrivedAt']) && strtotime($o['arrivedAt']) - strtotime($o['appointmentAt']) > 3600) { $by[$k]['late']++; }
            }
            if (($o['claimedBy'] ?? '') === $tid && strtotime((string) ($o['acceptedAt'] ?? '')) >= $since) { $by[$row($tid, $o['technicianName'] ?? '')]['radar']++; }
        }
        $pay = 0; foreach (Mutqan_Store::select('payout_requests', [], ['limit' => 200], self::sys()) as $r) { if (strtotime((string) ($r['createdAt'] ?? '')) >= $since) { $pay++; } }
        if (!$by && !$pay) { return; }
        uasort($by, function ($a, $b) { return $b['done'] <=> $a['done']; });
        $L = ['📊 تقرير الفنيين — آخر 7 أيام'];
        foreach (array_slice($by, 0, 15, true) as $r) {
            $avg = $r['rate'] ? round(array_sum($r['rate']) / count($r['rate']), 1) : '-';
            $L[] = '• ' . $r['name'] . ': ' . $r['done'] . ' مكتمل · ⭐' . $avg . ($r['decl'] ? ' · اعتذر ' . $r['decl'] : '') . ($r['abort'] ? ' · فشل ' . $r['abort'] : '') . ($r['late'] ? ' · تأخر ' . $r['late'] : '') . ($r['radar'] ? ' · رادار ' . $r['radar'] : '') . ($r['cash'] ? ' · كاش ' . $r['cash'] : '');
        }
        if ($pay) { $L[] = '💸 طلبات تحويل مستحقات: ' . $pay; }
        $txt = implode("\n", $L);
        Mutqan_Rest::admin_alert('📊 تقرير الفنيين الأسبوعي', $txt, '#/staff/accounts', 'tech_weekly', 'normal');
        Mutqan_Store::insert('field_posts', ['kind' => 'note', 'text' => $txt, 'to' => 'ops', 'photos' => [], 'lat' => null, 'lng' => null, 'place' => '', 'clientName' => '', 'clientPhone' => '', 'specialtyId' => '', 'audio' => '', 'radiusKm' => 0, 'at' => '', 'rung' => true, 'byId' => 'system', 'byName' => 'النظام', 'byRole' => 'system', 'orderId' => '', 'createdAt' => gmdate('c'), 'acks' => [], 'status' => 'open'], null, ['expiresAt' => gmdate('c', time() + 14 * 86400)], self::sys());
    }

    /** First of the month: each technician gets his statement link on WhatsApp (the page prints to PDF). */
    private static function monthly() {
        $tz = new DateTimeZone('Asia/Riyadh'); $now = new DateTime('now', $tz);
        $key = $now->format('Y-m');
        if ((int) $now->format('j') !== 1 || (int) $now->format('G') < 10 || get_option('mutqan_tech_monthly') === $key) { return; }
        update_option('mutqan_tech_monthly', $key, false);
        if (!class_exists('Mutqan_WA')) { return; }
        $prev = (clone $now)->modify('-1 month')->format('Y-m');
        $base = 'https://desk.oxygen11.com/t/#/staff/statement?month=' . $prev;
        foreach (Mutqan_Store::select('tech_profiles', [], ['limit' => 300], self::sys()) as $t) {
            $ph = (string) ($t['phone'] ?? ''); if ($ph === '' || !empty($t['isSite']) || (($t['active'] ?? true) === false)) { continue; }
            try { $acct = Mutqan_WA::open_account(); if ($acct === '') { continue; } Mutqan_WA::send($acct, $ph, "📄 كشف حسابك لشهر {$prev} جاهز\nافتحه من التطبيق واحفظه PDF:\n{$base}", 'statement', 'system'); } catch (Throwable $e) { error_log('[mutqan] statement wa ' . $e->getMessage()); }
        }
    }

    private static function visible($d, $p) {
        if (!self::is_tech($p)) { return true; }
        $me = self::me($p); $to = (string) ($d['to'] ?? '');
        return ($d['byId'] ?? '') === $me || $to === 'all' || $to === 'u:' . $me;
    }

    private static function posts($p) {
        $rows = Mutqan_Store::select('field_posts', [], ['limit' => 300, 'order' => '-createdAt'], self::sys());
        $cut = time() - 4 * 86400;
        $out = [];
        foreach ($rows as $d) {
            if (strtotime((string) ($d['createdAt'] ?? '')) < $cut && (empty($d['at']) || strtotime((string) $d['at']) < $cut)) { continue; }
            if (!self::visible($d, $p)) { continue; }
            // a customer's number from the field reaches the office only
            if (self::is_tech($p) && ($d['byId'] ?? '') !== self::me($p)) { $d['clientPhone'] = ''; }
            $out[] = $d;
        }
        return ['posts' => array_slice($out, 0, 150)];
    }

    private static function ack($in, $p) {
        $d = Mutqan_Store::get_raw('field_posts', (string) ($in['id'] ?? ''));
        if (!$d || !self::visible($d, $p)) { throw new Mutqan_Error('not_found', 'مش موجود.', 404); }
        $acks = (array) ($d['acks'] ?? []);
        $acks[self::me($p)] = ['name' => self::name($p), 'at' => gmdate('c')];
        $patch = ['acks' => $acks];
        if (!empty($in['close']) && (($d['byId'] ?? '') === self::me($p) || !self::is_tech($p))) { $patch['status'] = 'done'; }
        Mutqan_Store::update('field_posts', $d['id'], $patch, self::sys());
        return ['ok' => true];
    }

    /* ------------------------------------------------------------------ jobs */

    /**
     * The technician's radar: every job still looking for a hand — no technician yet (any mode), or given to someone
     * who has not accepted it. No customer phone; the address is cut to the district.
     */
    private static function radar($p) {
        $me = self::me($p);
        $out = [];
        $cut = time() - 3 * 86400;
        foreach (Mutqan_Store::select('orders', [], ['limit' => 300, 'order' => '-createdAt'], self::sys()) as $o) {
            $st = (string) ($o['status'] ?? '');
            $tid = (string) ($o['technicianId'] ?? '');
            if (!in_array($st, ['new', 'pending', 'forced', 'assigned'], true)) { continue; }
            if ($st === 'assigned' && $tid === $me) { continue; }
            if (strtotime((string) ($o['createdAt'] ?? '')) < $cut) { continue; }
            if (in_array($me, array_map('strval', (array) ($o['abortedTechIds'] ?? [])), true)) { continue; }
            $addr = (string) ($o['district'] ?? ($o['area'] ?? ''));
            if ($addr === '') { $parts = preg_split('/[،,]/u', (string) ($o['address'] ?? '')); $addr = trim((string) ($parts[0] ?? '')); }
            $out[] = [
                'id' => $o['id'], 'status' => $st, 'specialtyId' => $o['specialtyId'] ?? '', 'serviceLabel' => mb_substr((string) ($o['serviceLabel'] ?? ($o['problem'] ?? '')), 0, 80),
                'district' => mb_substr($addr, 0, 60), 'lat' => $o['lat'] ?? null, 'lng' => $o['lng'] ?? null, 'priority' => $o['priority'] ?? '',
                'appointmentAt' => $o['appointmentAt'] ?? ($o['appointment']['at'] ?? ''), 'createdAt' => $o['createdAt'] ?? '',
                'price' => class_exists('Mutqan_Ops') ? Mutqan_Ops::amount($o) : 0,
                'takenBy' => $tid !== '' ? (string) ($o['technicianName'] ?? 'فني') : '', 'free' => $tid === '',
            ];
            if (count($out) >= 60) { break; }
        }
        return ['orders' => $out];
    }

    private static function claim($in, $p) {
        if (!self::is_tech($p)) { throw new Mutqan_Error('forbidden', 'للفنيين.', 403); }
        $o = Mutqan_Store::get_raw('orders', (string) ($in['orderId'] ?? ''));
        if (!$o) { throw new Mutqan_Error('not_found', 'الطلب مش موجود.', 404); }
        $o['id'] = (string) $in['orderId'];   // get_raw() carries no id (the invoice request updated «no order» and nobody heard it — 2026-11-24)
        $me = self::me($p);
        $st = (string) ($o['status'] ?? '');
        if (!in_array($st, ['new', 'pending', 'forced', 'assigned'], true)) { throw new Mutqan_Error('bad_state', 'الطلب اتاخد أو اتقفل.', 409); }
        if (in_array($me, array_map('strval', (array) ($o['abortedTechIds'] ?? [])), true)) { throw new Mutqan_Error('forbidden', 'الطلب ده اتسحب منك قبل كده — العمليات بس تقدر ترجعه لك.', 403); }
        $prof = Mutqan_Store::get_raw('tech_profiles', $me) ?: ['userId' => $me, 'displayName' => self::name($p)];
        if (class_exists('Mutqan_Wallet') && ($why = Mutqan_Wallet::blocked($prof, Mutqan_Store::select('orders', [], ['limit' => 1500, 'order' => '-createdAt'], self::sys()))) !== '') {
            $msg = ['frozen' => 'حسابك موقوف عن استلام الطلبات — كلّم الإدارة.', 'cash_cap' => 'معاك نقدية للشركة فوق الحد المسموح — سلّمها الأول عشان تستلم طلبات.', 'low_credit' => 'رصيدك أقل من الحد — اشحن الأول.'];
            throw new Mutqan_Error('blocked', $msg[$why] ?? 'مش متاح تاخد طلبات دلوقتي.', 403);
        }
        $cur = (string) ($o['technicianId'] ?? '');
        $num = Mutqan_Store::num($o);
        if ($cur !== '' && $cur !== $me) {
            // someone has it: ask the room
            $doc = ['kind' => 'note', 'text' => 'طلب أخذ الطلب #' . $num . ' (مسند حاليًا لـ ' . ((string) ($o['technicianName'] ?? 'فني')) . ')' . (!empty($in['note']) ? ' — ' . mb_substr((string) $in['note'], 0, 200) : ''),
                'to' => 'ops', 'photos' => [], 'lat' => null, 'lng' => null, 'place' => '', 'clientName' => '', 'clientPhone' => '', 'specialtyId' => '', 'at' => '', 'rung' => true,
                'byId' => $me, 'byName' => self::name($p), 'byRole' => 'technician', 'orderId' => (string) $o['id'], 'claim' => true, 'createdAt' => gmdate('c'), 'acks' => [], 'status' => 'open'];
            Mutqan_Store::insert('field_posts', $doc, null, ['expiresAt' => gmdate('c', time() + 7 * 86400)], self::sys());
            self::announce($doc);
            return ['ok' => true, 'requested' => true];
        }
        if ($cur === $me) { return ['ok' => true, 'mine' => true]; }
        $at = gmdate('c');
        Mutqan_Store::update('orders', $o['id'], [
            'technicianId' => $me, 'technicianName' => (string) ($prof['displayName'] ?? self::name($p)), 'assignedAt' => $at, 'forced' => false, 'status' => 'accepted', 'acceptedAt' => $at,
            'handler' => 'technician', 'dispatch' => 'tech', 'claimedBy' => $me,
            'statusHistory' => array_merge((array) ($o['statusHistory'] ?? []), [['status' => 'accepted', 'at' => $at, 'by' => 'technician', 'note' => 'أخذه من الرادار']]),
        ], self::sys());
        self::push(['role' => 'operations'], '📡 فني أخد طلب من الرادار', self::name($p) . ' أخد الطلب #' . $num, '#/staff/order/' . $o['id']);
        return ['ok' => true, 'taken' => true];
    }

    private static function decline($in, $p) {
        $o = Mutqan_Store::get_raw('orders', (string) ($in['orderId'] ?? ''));
        if (!$o) { throw new Mutqan_Error('not_found', 'الطلب مش موجود.', 404); }
        $o['id'] = (string) $in['orderId'];   // get_raw() carries no id (the invoice request updated «no order» and nobody heard it — 2026-11-24)
        $me = self::me($p);
        if (($o['technicianId'] ?? '') !== $me) { throw new Mutqan_Error('forbidden', 'الطلب مش مسند لك.', 403); }
        if (!in_array((string) ($o['status'] ?? ''), ['assigned', 'accepted', 'en_route'], true)) { throw new Mutqan_Error('bad_state', 'بعد الوصول استخدم «تعذّر الإصلاح».', 409); }
        $r = (string) ($in['reason'] ?? 'other'); if (!isset(self::DECLINE[$r])) { $r = 'other'; }
        $note = trim(mb_substr((string) ($in['note'] ?? ''), 0, 300));
        $at = gmdate('c');
        $rec = ['techId' => $me, 'techName' => (string) ($o['technicianName'] ?? self::name($p)), 'at' => $at, 'stage' => (string) $o['status'], 'reason' => $r, 'reasonLabel' => self::DECLINE[$r], 'note' => $note];
        Mutqan_Store::update('orders', $o['id'], [
            'declines' => array_slice(array_merge((array) ($o['declines'] ?? []), [$rec]), -10),
            'abortedTechIds' => array_values(array_unique(array_merge((array) ($o['abortedTechIds'] ?? []), [$me]))),
            'status' => 'new', 'technicianId' => null, 'technicianName' => null, 'handler' => 'operations', 'forced' => false,
            'statusHistory' => array_merge((array) ($o['statusHistory'] ?? []), [['status' => 'new', 'at' => $at, 'by' => 'technician', 'note' => 'اعتذار الفني: ' . self::DECLINE[$r] . ($note ? ' — ' . $note : '')]]),
        ], self::sys());
        Mutqan_Rest::admin_alert('🙅 فني اعتذر عن طلب', $rec['techName'] . ' اعتذر عن الطلب #' . Mutqan_Store::num($o) . ' — ' . self::DECLINE[$r] . ($note ? ': ' . $note : '') . "\nالطلب رجع للعمليات.", '#/staff/order/' . $o['id'], 'tech_decline', 'high', (string) $o['id']);
        return ['ok' => true];
    }

    /* ------------------------------------------------------------------ money & invoices */

    private static function payout($in, $p) {
        if (!self::is_tech($p)) { throw new Mutqan_Error('forbidden', 'للفنيين.', 403); }
        $amt = round((float) ($in['amount'] ?? 0), 2);
        if ($amt <= 0) { throw new Mutqan_Error('bad_input', 'اكتب المبلغ.', 400); }
        $me = self::me($p);
        foreach (Mutqan_Store::select('payout_requests', ['techId' => $me, 'status' => 'pending'], ['limit' => 5], self::sys()) as $x) {
            if (($x['type'] ?? 'payout') !== 'payout') { continue; }
            throw new Mutqan_Error('pending', 'عندك طلب تحويل لسه تحت التنفيذ (' . (float) ($x['amount'] ?? 0) . ' ر.س).', 409);
        }
        $sum = array_map(function ($v) { return is_numeric($v) ? round((float) $v, 2) : $v; }, array_intersect_key((array) ($in['sum'] ?? []), array_flip(['mode', 'jobs', 'sales', 'commission', 'cash', 'balance', 'credit', 'due'])));
        $row = Mutqan_Store::insert('payout_requests', ['techId' => $me, 'techName' => self::name($p), 'amount' => $amt, 'sum' => $sum, 'note' => mb_substr((string) ($in['note'] ?? ''), 0, 300), 'status' => 'pending', 'createdAt' => gmdate('c')], null, [], self::sys());
        Mutqan_Rest::admin_alert('💸 فني بيطلب تحويل مستحقاته', self::name($p) . ' طالب ' . $amt . ' ر.س — افتح الحسابات ← إدارة الفني.', '#/staff/accounts', 'payout_request', 'high');
        return ['ok' => true, 'id' => $row['id'] ?? null, 'eta' => 'خلال 24 ساعة عمل'];
    }

    /** He disagrees with a deduction — within 48 hours of it; the owner decides (cancel = a reverse line). */
    private static function objection($in, $p) {
        $s = Mutqan_Store::get_raw('settlements', (string) ($in['id'] ?? ''));
        if (!$s || ($s['techId'] ?? '') !== self::me($p) || ($s['kind'] ?? '') !== 'penalty') { throw new Mutqan_Error('not_found', 'الخصم مش موجود.', 404); }
        if (time() - strtotime((string) ($s['at'] ?? '')) > 48 * 3600) { throw new Mutqan_Error('late', 'مدة الاعتراض (48 ساعة) خلصت — كلّم الإدارة.', 409); }
        if (!empty($s['objection'])) { throw new Mutqan_Error('dup', 'اعتراضك متسجل بالفعل.', 409); }
        $txt = trim(mb_substr((string) ($in['text'] ?? ''), 0, 500)); if ($txt === '') { throw new Mutqan_Error('bad_input', 'اكتب سبب اعتراضك.', 400); }
        Mutqan_Store::update('settlements', $s['id'], ['objection' => ['text' => $txt, 'at' => gmdate('c'), 'status' => 'open']], self::sys());
        Mutqan_Rest::admin_alert('✋ فني بيعترض على خصم', self::name($p) . ' — ' . (float) $s['amount'] . ' ر.س: ' . $txt, '#/staff/accounts', 'tech_objection', 'high');
        return ['ok' => true];
    }

    /** Prepaid: he transferred money and uploads the receipt; the owner approves → credit. */
    private static function topup($in, $p) {
        if (!self::is_tech($p)) { throw new Mutqan_Error('forbidden', 'للفنيين.', 403); }
        $amt = round((float) ($in['amount'] ?? 0), 2); $rc = esc_url_raw((string) ($in['receiptUrl'] ?? ''));
        if ($amt <= 0 || $rc === '') { throw new Mutqan_Error('bad_input', 'اكتب المبلغ وارفع صورة الإيصال.', 400); }
        Mutqan_Store::insert('payout_requests', ['techId' => self::me($p), 'techName' => self::name($p), 'type' => 'topup', 'amount' => $amt, 'receiptUrl' => $rc, 'note' => mb_substr((string) ($in['note'] ?? ''), 0, 300), 'status' => 'pending', 'createdAt' => gmdate('c')], null, [], self::sys());
        Mutqan_Rest::admin_alert('💳 فني شحن رصيد', self::name($p) . ' حوّل ' . $amt . ' ر.س ورفع الإيصال — اعتمده من إدارة الفني.', '#/staff/accounts', 'tech_topup', 'high');
        return ['ok' => true];
    }

    private static function invoice($in, $p) {
        $o = Mutqan_Store::get_raw('orders', (string) ($in['orderId'] ?? ''));
        if (!$o) { throw new Mutqan_Error('not_found', 'الطلب مش موجود.', 404); }
        $o['id'] = (string) $in['orderId'];   // get_raw() carries no id (the invoice request updated «no order» and nobody heard it — 2026-11-24)
        if (self::is_tech($p) && ($o['technicianId'] ?? '') !== self::me($p)) { throw new Mutqan_Error('forbidden', 'الطلب مش مسند لك.', 403); }
        Mutqan_Store::update('orders', $o['id'], ['invoiceRequest' => ['by' => self::me($p), 'byName' => self::name($p), 'at' => gmdate('c'), 'note' => mb_substr((string) ($in['note'] ?? ''), 0, 200)]], self::sys());
        Mutqan_Rest::admin_alert('🧾 فني بيطلب إصدار فاتورة', self::name($p) . ' — الطلب #' . Mutqan_Store::num($o) . (!empty($in['note']) ? ': ' . mb_substr((string) $in['note'], 0, 120) : ''), '#/staff/order/' . $o['id'], 'invoice_request', 'high', (string) $o['id']);
        return ['ok' => true];
    }
}

add_action('rest_api_init', ['Mutqan_Fieldops', 'tick'], 99);
