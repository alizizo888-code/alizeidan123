<?php
if (!defined('ABSPATH')) { exit; }

/**
 * In-app calls between the three sides of a job: the customer, the technician and operations.
 *
 * Who may ring whom is decided here, never in the browser:
 *   - operations (owner / operations / supervisor) may ring a technician, or the customer of an order;
 *   - a technician may ring operations, or the customer of HIS OWN open job;
 *   - a customer may ring operations (the hotline), and the technician of his order only the way operations set it:
 *       «auto»   allowed while the job is open,
 *       «manual» the customer asks, operations approves (or refuses) per order,
 *       «off»    never.
 *
 * Each call document says who is calling (role + name) so the phone that rings can show it, and `toUser` is the one
 * account that may see it, answer it and talk on it (see Mutqan_Policy rule 'call' and Mutqan_Relay::side()).
 */
final class Mutqan_Callnet {
    const OPEN = ['assigned', 'accepted', 'en_route', 'nearby', 'arrived', 'in_progress', 'forced'];
    const MODES = ['auto', 'manual', 'off'];

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user']; }

    /** 'ops' | 'tech' | 'client' */
    public static function side_of($p) {
        $r = $p['role'] ?? null;
        if (in_array($r, ['owner', 'operations', 'supervisor'], true)) { return 'ops'; }
        if ($r === 'technician') { return 'tech'; }
        return 'client';
    }

    /** The owner's / operations' rule for customer → technician calls (pricing row `call_policy`). Default: operations approves. */
    public static function policy() {
        try { $d = Mutqan_Store::peek_doc('pricing', 'call_policy'); } catch (Throwable $e) { $d = []; }
        $m = (string) ($d['clientToTech'] ?? 'manual');
        return [
            'clientToTech' => in_array($m, self::MODES, true) ? $m : 'manual',
            'techToClient' => !isset($d['techToClient']) || !empty($d['techToClient']),
        ];
    }

    private static function order($id) {
        $id = (string) $id;
        if ($id === '' || !preg_match('/^[A-Za-z0-9_.:@-]{1,64}$/', $id)) { throw new Mutqan_Error('bad_request', 'طلب غير صالح.', 400); }
        $o = Mutqan_Store::get_raw('orders', $id);
        if (!$o) { throw new Mutqan_Error('not_found', 'الطلب غير موجود.', 404); }
        $o['id'] = $id;
        $o['_owner'] = Mutqan_Store::owner_of('orders', $id);
        return $o;
    }
    private static function client_of($o) {
        $c = (string) ($o['clientId'] ?? '');
        return $c !== '' ? $c : (string) ($o['_owner'] ?? '');
    }
    private static function is_client($o, $p) {
        $me = (string) ($p['id'] ?? '#');
        return ($p['kind'] ?? 'guest') !== 'guest' && ($me === (string) ($o['_owner'] ?? '') || $me === (string) ($o['clientId'] ?? ''));
    }

    /** What this person may do about calls on this order. */
    public static function rights($o, $p) {
        $side = self::side_of($p);
        $me = (string) ($p['id'] ?? '');
        $pol = self::policy();
        $open = in_array($o['status'] ?? '', self::OPEN, true);
        $tech = (string) ($o['technicianId'] ?? '');
        $out = ['side' => $side, 'ops' => true, 'tech' => false, 'client' => false, 'techState' => 'none', 'techName' => (string) ($o['technicianName'] ?? ''), 'mode' => $pol['clientToTech']];
        if ($side === 'ops') {
            $out['tech'] = $tech !== '';
            $out['client'] = self::client_of($o) !== '';
            $out['ops'] = false;
        } elseif ($side === 'tech') {
            $mine = $tech !== '' && $tech === $me;
            $out['client'] = $mine && $open && $pol['techToClient'] && self::client_of($o) !== '';
        } elseif (self::is_client($o, $p)) {
            if ($tech === '' || !$open) { $out['techState'] = 'none'; }
            elseif ($pol['clientToTech'] === 'auto') { $out['techState'] = 'allowed'; }
            elseif ($pol['clientToTech'] === 'off') { $out['techState'] = 'off'; }
            else {
                $g = $o['techCall'] ?? null;
                $st = is_array($g) ? (string) ($g['state'] ?? '') : '';
                // An approval belongs to the technician it was given for: a new technician on the job starts over.
                if ($st !== '' && (string) ($g['techId'] ?? '') !== $tech) { $st = ''; }
                $out['techState'] = $st === 'approved' ? 'allowed' : ($st === 'pending' ? 'pending' : ($st === 'refused' ? 'refused' : 'ask'));
            }
            $out['tech'] = $out['techState'] === 'allowed';
        } else {
            throw new Mutqan_Error('forbidden', 'غير مسموح.', 403);
        }
        return $out;
    }

    public static function handle($in, $p) {
        if (($p['kind'] ?? 'guest') === 'guest') { throw new Mutqan_Error('unauthorized', 'افتح التطبيق من جديد.', 401); }
        $op = (string) ($in['op'] ?? '');
        if ($op === 'policy') { return self::policy(); }
        if ($op === 'setPolicy') {
            if (self::side_of($p) !== 'ops' || !Mutqan_Policy::has_power($p, 'write')) { throw new Mutqan_Error('forbidden', 'للعمليات فقط.', 403); }
            $m = (string) ($in['clientToTech'] ?? '');
            if (!in_array($m, self::MODES, true)) { throw new Mutqan_Error('bad_request', 'اختيار غير صالح.', 400); }
            $doc = ['serviceId' => 'call_policy', 'clientToTech' => $m, 'techToClient' => !isset($in['techToClient']) || !empty($in['techToClient'])];
            Mutqan_Store::upsert('pricing', $doc, 'call_policy', ['writableBy' => 'admins'], $p);
            return self::policy();
        }
        if ($op === 'can') { return self::rights(self::order($in['orderId'] ?? ''), $p); }
        if ($op === 'request') { return self::request(self::order($in['orderId'] ?? ''), $p); }
        if ($op === 'decide') { return self::decide(self::order($in['orderId'] ?? ''), !empty($in['allow']), $p); }
        if ($op === 'start') { return self::start($in, $p); }
        throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400);
    }

    /** The customer asks operations for leave to call the technician. */
    private static function request($o, $p) {
        $r = self::rights($o, $p);
        if ($r['side'] !== 'client') { throw new Mutqan_Error('forbidden', 'غير مسموح.', 403); }
        if ($r['techState'] !== 'ask' && $r['techState'] !== 'refused') { return $r; }
        Mutqan_Limiter::hit('call_request', 6, 3600);
        $state = ['state' => 'pending', 'techId' => (string) $o['technicianId'], 'at' => gmdate('c')];
        Mutqan_Store::update('orders', $o['id'], ['techCall' => $state], self::sys());
        $who = trim((string) ($o['clientName'] ?? '')) ?: 'عميل';
        Mutqan_Rest::admin_alert('📞 عميل يطلب الاتصال بالفني', $who . ' يطلب الاتصال بالفني ' . ((string) ($o['technicianName'] ?? '')) . ' — طلب #' . substr((string) $o['id'], -6) . '. وافق أو ارفض من صفحة الطلب.', '#/staff/order/' . $o['id'], 'call_request', 'high', $o['id']);
        return array_merge($r, ['techState' => 'pending']);
    }

    /** Operations approves or refuses the customer's request (or opens / closes the line on its own). */
    private static function decide($o, $allow, $p) {
        if (self::side_of($p) !== 'ops' || !Mutqan_Policy::has_power($p, 'write')) { throw new Mutqan_Error('forbidden', 'للعمليات فقط.', 403); }
        if (empty($o['technicianId'])) { throw new Mutqan_Error('no_tech', 'لا يوجد فني على الطلب بعد.', 409); }
        $state = ['state' => $allow ? 'approved' : 'refused', 'techId' => (string) $o['technicianId'], 'at' => gmdate('c'), 'by' => mb_substr((string) ($p['name'] ?? ''), 0, 60)];
        Mutqan_Store::update('orders', $o['id'], ['techCall' => $state], self::sys());
        $client = self::client_of($o);
        if ($client !== '') {
            self::push($client, $allow ? '✅ يمكنك الآن الاتصال بالفني' : 'طلب الاتصال بالفني', $allow ? 'وافقت العمليات — اضغط «اتصال بالفني» من صفحة طلبك.' : 'العمليات ستتواصل معك بنفسها بخصوص طلبك.', '#/order/' . $o['id'], 'call_decision');
        }
        return ['techCall' => $state];
    }

    /** Places the call: checks the right, writes the call document for exactly one callee, and wakes that callee's phone. */
    private static function start($in, $p) {
        $to = (string) ($in['to'] ?? '');
        $side = self::side_of($p);
        $target = '';
        $orderId = null;
        $o = null;
        if ($to === 'tech' && $side === 'ops' && !empty($in['toUser']) && empty($in['orderId'])) {
            // Operations rings a technician directly (from the team list), not about one order.
            $target = (string) $in['toUser'];
            global $wpdb;
            $role = $wpdb->get_var($wpdb->prepare('SELECT role FROM ' . Mutqan_Store::table('users') . " WHERE id=%s AND status='active'", $target));
            if ($role !== 'technician') { throw new Mutqan_Error('forbidden', 'هذا الحساب ليس فنيًا.', 403); }
        } elseif ($to === 'tech' || $to === 'client') {
            $o = self::order($in['orderId'] ?? '');
            $r = self::rights($o, $p);
            if (empty($r[$to])) {
                $msg = $to === 'tech' ? ($r['techState'] === 'pending' ? 'طلبك بانتظار موافقة العمليات.' : 'الاتصال بالفني يتم بإذن من العمليات.') : 'غير مسموح بالاتصال بالعميل على هذا الطلب.';
                throw new Mutqan_Error('forbidden', $msg, 403);
            }
            $target = $to === 'tech' ? (string) $o['technicianId'] : self::client_of($o);
            $orderId = (string) $o['id'];
        } else {
            throw new Mutqan_Error('bad_request', 'جهة غير معروفة.', 400);
        }
        if ($target === '' || $target === ($p['id'] ?? '')) { throw new Mutqan_Error('bad_request', 'لا يمكن الاتصال بهذه الجهة.', 400); }
        Mutqan_Limiter::hit('call_start', 20, 3600);
        $name = trim((string) ($in['name'] ?? '')) ?: trim((string) ($p['name'] ?? ''));
        // A customer without an account has no name on his session, but his order has one: the technician should see who is calling.
        if ($name === '' && $side === 'client' && $o) { $name = trim((string) ($o['clientName'] ?? '')); }
        $name = mb_substr($name, 0, 60);
        $doc = [
            'status' => 'ringing', 'to' => $to, 'toUser' => $target, 'orderId' => $orderId,
            'from' => ['role' => $side, 'name' => $name, 'id' => (string) $p['id']],
            'name' => $name, 'phone' => mb_substr((string) ($in['phone'] ?? ''), 0, 30), 'createdAt' => gmdate('c'),
        ];
        $row = Mutqan_Store::insert('calls', $doc, null, ['expiresAt' => gmdate('c', time() + 86400)], $p, true);
        $labels = ['ops' => 'العمليات', 'tech' => 'الفني', 'client' => 'العميل'];
        self::push($target, '📞 مكالمة من ' . $labels[$side] . ($name !== '' && $side !== 'ops' ? ' — ' . $name : ''), 'اضغط لفتح التطبيق والرد', $side === 'client' || $to === 'tech' ? '#/staff' : ($orderId ? '#/order/' . $orderId : '#/'), 'incoming_call');
        return ['id' => $row['id'] ?? null];
    }

    private static function push($user, $title, $body, $url, $event) {
        try { Mutqan_Store::insert('_push', ['target' => ['user' => (string) $user], 'title' => mb_substr($title, 0, 120), 'body' => mb_substr($body, 0, 300), 'url' => $url, 'sound' => 'bell', 'priority' => 'high', 'event' => $event, 'from' => 'system'], null, ['expiresAt' => gmdate('c', time() + 3600)], self::sys()); }
        catch (Throwable $e) { error_log('[mutqan] callnet push ' . $e->getMessage()); }
    }
}
