<?php
/**
 * The owner's delete controls (owner order 2026-11-19): delete an order, a warranty, a technician, a service.
 * Owner only — not operations, not supervisors. Every delete is written to a small log the owner can read back.
 * Services are switched off (pricing row «services_off»), never cut out of the code, so turning one back on is one tap.
 */
if (!defined('ABSPATH')) { exit; }

final class Mutqan_Owner {
    const LOG = 'mutqan_owner_deletes';

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user']; }

    public static function handle($in, $p) {
        Mutqan_Auth::require_owner($p);
        $op = (string) ($in['op'] ?? '');
        switch ($op) {
            case 'deleteOrder': return self::delete_order((string) ($in['id'] ?? ''), $p);
            case 'deleteOrders': { $n = 0; foreach (array_slice((array) ($in['ids'] ?? []), 0, 200) as $id) { if (self::delete_order((string) $id, $p)['deleted']) { $n++; } } return ['deleted' => $n]; }
            case 'voidWarranty': return self::void_warranty((string) ($in['orderId'] ?? ''), $p);
            case 'deleteWarrantyRecord': return self::delete_doc('warranties', (string) ($in['id'] ?? ''), 'ضمان (سجل)', $p);
            // A warranty claim file (ملف ضمان), and many warranties at once from the warranty center (owner, 2026-11-21).
            case 'deleteClaim': return self::delete_doc('warranty_claims', (string) ($in['id'] ?? ''), 'ملف ضمان', $p);
            case 'deleteWarranties': {
                $n = 0;
                foreach (array_slice((array) ($in['records'] ?? []), 0, 300) as $id) { if (self::delete_doc('warranties', (string) $id, 'ضمان (سجل)', $p)['deleted']) { $n++; } }
                foreach (array_slice((array) ($in['claims'] ?? []), 0, 300) as $id) { if (self::delete_doc('warranty_claims', (string) $id, 'ملف ضمان', $p)['deleted']) { $n++; } }
                foreach (array_slice((array) ($in['orders'] ?? []), 0, 300) as $id) { try { self::void_warranty((string) $id, $p); $n++; } catch (Throwable $e) { /* gone or not completed */ } }
                return ['deleted' => $n];
            }
            case 'deleteTech': return self::delete_tech((string) ($in['userId'] ?? ''), $p);
            case 'trash': return class_exists('Mutqan_Trash') ? Mutqan_Trash::list() : [];
            case 'restore': {
                $r = Mutqan_Trash::restore((int) ($in['id'] ?? 0), $p);
                // a technician comes back with his sign-in (it was only switched off)
                if ($r['coll'] === 'tech_profiles') { try { Mutqan_Auth::team_op('enableMember', ['userId' => $r['id']], $p); } catch (Throwable $e) { /* profile without an account */ } }
                self::log('استرجاع', $r['coll'] . ' ' . $r['id'], $p);
                return $r;
            }
            case 'log': { $l = get_option(self::LOG, []); return array_reverse(is_array($l) ? $l : []); }
        }
        throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400);
    }

    private static function delete_order($id, $p) {
        $o = Mutqan_Store::get_raw('orders', $id);
        if (!$o) { return ['deleted' => false]; }
        $label = '#' . ((string) ($o['number'] ?? '') ?: substr($id, -5)) . ' ' . trim((string) ($o['clientName'] ?? '') . ' ' . (string) ($o['clientPhone'] ?? ''));
        // the order's own conversation and warranty claims go with it
        foreach (['order_msgs' => 'orderId', 'warranty_claims' => 'orderId'] as $coll => $f) {
            try { foreach (Mutqan_Store::select($coll, [$f => $id], ['limit' => 500], self::sys()) as $r) { Mutqan_Store::delete($coll, (string) $r['id'], self::sys()); } } catch (Throwable $e) { /* collection may not exist */ }
        }
        $ok = Mutqan_Store::delete('orders', $id, self::sys());
        self::log('طلب', $label, $p);
        return ['deleted' => (bool) $ok];
    }

    /** A warranty that comes from a finished order: the order stays, its warranty is cancelled (0 days, marked void). */
    private static function void_warranty($orderId, $p) {
        $o = Mutqan_Store::get_raw('orders', $orderId);
        if (!$o) { throw new Mutqan_Error('not_found', 'الطلب مش موجود.', 404); }
        Mutqan_Store::update('orders', $orderId, ['warrantyDays' => 0, 'warrantyVoid' => true, 'warrantyVoidAt' => gmdate('c')], self::sys());
        self::log('ضمان', '#' . ((string) ($o['number'] ?? '') ?: substr($orderId, -5)), $p);
        return ['ok' => true];
    }

    private static function delete_doc($coll, $id, $what, $p) {
        $ok = Mutqan_Store::delete($coll, $id, self::sys());
        if ($ok) { self::log($what, $id, $p); }
        return ['deleted' => (bool) $ok];
    }

    /** The technician's profile and account. His open orders go back to «new» for operations to reassign. */
    private static function delete_tech($uid, $p) {
        if ($uid === '' || $uid === ($p['id'] ?? '')) { throw new Mutqan_Error('bad_input', 'اختار فني.', 400); }
        $prof = Mutqan_Store::get_raw('tech_profiles', $uid) ?: [];
        if (!empty($prof['isSite'])) { throw new Mutqan_Error('forbidden', '«فني الموقع» جزء من النظام ومينفعش يتمسح.', 403); }
        $moved = 0;
        foreach (Mutqan_Store::select('orders', ['technicianId' => $uid], ['limit' => 500], self::sys()) as $o) {
            if (in_array($o['status'] ?? '', ['completed', 'closed', 'cancelled'], true)) { continue; }
            Mutqan_Store::update('orders', (string) $o['id'], ['technicianId' => null, 'technicianName' => null, 'status' => 'new'], self::sys()); $moved++;
        }
        Mutqan_Store::delete('tech_profiles', $uid, self::sys());
        // switched off, not erased, so «استرجاع» brings him back able to sign in
        try { Mutqan_Auth::team_op('disableMember', ['userId' => $uid], $p); } catch (Throwable $e) { /* profile without an account */ }
        self::log('فني', (string) ($prof['displayName'] ?? $uid) . ($moved ? " (رجّع $moved طلب للتوزيع)" : ''), $p);
        return ['deleted' => true, 'ordersReturned' => $moved];
    }

    private static function log($what, $label, $p) {
        $l = get_option(self::LOG, []); $l = is_array($l) ? $l : [];
        $l[] = ['what' => $what, 'label' => mb_substr((string) $label, 0, 120), 'by' => (string) ($p['name'] ?? $p['id'] ?? ''), 'at' => gmdate('c')];
        update_option(self::LOG, array_slice($l, -300), false);
    }
}
