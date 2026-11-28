<?php
/**
 * «بث موقعي» — live location sharing between the team (owner 2026-11-28).
 *
 * Anyone on the team (owner · operations · supervisor · technician) switches «بث موقعي» on from any map: his blue dot
 * is sent every few seconds and shows on the others' maps with his name and role. Off = his point is erased at once.
 *
 * Who sees whom is decided here, on the server, never by the screen:
 *   - the owner sees everyone who is sharing.
 *   - owner / operations control: show or hide ALL technicians, hide any one person, what technicians may see
 *     (everyone · the management only · nobody), and the owner alone decides whether operations see him.
 *   - a hidden person still sees his own dot; nobody else does (except the owner).
 *
 * Privacy as in Mutqan_Track: one current point per person, overwritten, gone after STALE seconds; no history.
 */
if (!defined('ABSPATH')) { exit; }

final class Mutqan_TeamGeo {
    const OPT = 'mutqan_teamgeo';
    const CFG = 'mutqan_teamgeo_cfg';
    const STALE = 900;           // 15 minutes without a point = not live any more
    const STAFF = ['owner', 'operations', 'supervisor', 'technician'];
    const ROLE_LABEL = ['owner' => 'المالك', 'operations' => 'العمليات', 'supervisor' => 'مشرف', 'technician' => 'فني'];

    public static function defaults() { return ['enabled' => true, 'showTechs' => true, 'techsSee' => 'staff', 'opsSeeOwner' => true, 'hidden' => []]; }
    public static function cfg() {
        $c = get_option(self::CFG, []);
        $c = array_merge(self::defaults(), is_array($c) ? $c : []);
        $c['hidden'] = is_array($c['hidden']) ? array_filter($c['hidden']) : [];
        if (!in_array($c['techsSee'], ['all', 'staff', 'none'], true)) { $c['techsSee'] = 'staff'; }
        return $c;
    }
    private static function all() {
        $a = get_option(self::OPT, []); $a = is_array($a) ? $a : [];
        $now = time(); $changed = false;
        foreach ($a as $id => $r) { if (!is_array($r) || $now - (int) ($r['at'] ?? 0) > self::STALE) { unset($a[$id]); $changed = true; } }
        if ($changed) { update_option(self::OPT, $a, false); }
        return $a;
    }

    private static function staff($p) {
        $role = (string) ($p['role'] ?? '');
        if (($p['kind'] ?? 'guest') !== 'user' || !in_array($role, self::STAFF, true) || (string) ($p['id'] ?? '') === '') {
            throw new Mutqan_Error('forbidden', 'مشاركة الموقع لفريق العمل بس.', 403);
        }
        return $role;
    }

    /** May $viewer (principal) see $t (stored point)? */
    public static function can_see($viewer, $t, $cfg) {
        $vr = (string) ($viewer['role'] ?? ''); $vid = (string) ($viewer['id'] ?? '');
        if ((string) ($t['id'] ?? '') === $vid) { return true; }
        if ($vr === 'owner') { return true; }
        if (empty($cfg['enabled'])) { return false; }
        if (!empty($cfg['hidden'][(string) ($t['id'] ?? '')])) { return false; }
        $tr = (string) ($t['role'] ?? '');
        if ($tr === 'technician' && empty($cfg['showTechs'])) { return false; }
        if (in_array($vr, ['operations', 'supervisor'], true)) { return $tr !== 'owner' || !empty($cfg['opsSeeOwner']); }
        if ($vr === 'technician') {
            if ($cfg['techsSee'] === 'none') { return false; }
            if ($cfg['techsSee'] === 'staff') { return $tr !== 'technician' && ($tr !== 'owner' || !empty($cfg['opsSeeOwner'])); }
            return $tr !== 'owner' || !empty($cfg['opsSeeOwner']);
        }
        return false;
    }

    /** POST /teamgeo {op: ping | stop | list | cfg | cfg_set} */
    public static function handle($in, $p) {
        $role = self::staff($p);
        $id = (string) $p['id'];
        $op = (string) ($in['op'] ?? 'list');
        $cfg = self::cfg();
        if ($op === 'ping') {
            Mutqan_Limiter::hit('teamgeo', 120, 60, $id);
            $lat = (float) ($in['lat'] ?? 0); $lng = (float) ($in['lng'] ?? 0);
            if (!is_numeric($in['lat'] ?? null) || !is_numeric($in['lng'] ?? null) || abs($lat) > 90 || abs($lng) > 180 || ($lat == 0.0 && $lng == 0.0)) { throw new Mutqan_Error('bad_position', 'الموقع غير صالح.', 422); }
            $a = self::all();
            $a[$id] = ['id' => $id, 'role' => $role, 'name' => mb_substr((string) ($p['name'] ?? $p['display_name'] ?? ''), 0, 60) ?: self::ROLE_LABEL[$role],
                'lat' => round($lat, 6), 'lng' => round($lng, 6), 'acc' => is_numeric($in['acc'] ?? null) ? (int) $in['acc'] : null,
                'heading' => is_numeric($in['heading'] ?? null) ? (int) $in['heading'] : null, 'at' => time(), 'since' => (int) ($a[$id]['since'] ?? time())];
            update_option(self::OPT, $a, false);
            // A technician's shared point also feeds the existing fleet map / «الفني قريب» logic.
            if ($role === 'technician' && class_exists('Mutqan_Track')) { try { Mutqan_Track::ping($id, $lat, $lng, $in['acc'] ?? null); } catch (Throwable $e) { /* fleet is best effort */ } }
            return ['ok' => true, 'visible' => empty($cfg['hidden'][$id]) && ($role !== 'technician' || !empty($cfg['showTechs'])) && !empty($cfg['enabled'])] + self::list_for($p, $cfg);
        }
        if ($op === 'stop') {
            $a = self::all(); unset($a[$id]); update_option(self::OPT, $a, false);
            return ['ok' => true];
        }
        if ($op === 'list') { return self::list_for($p, $cfg); }
        if ($op === 'cfg') {
            if (!in_array($role, ['owner', 'operations'], true)) { throw new Mutqan_Error('forbidden', 'للمالك والعمليات.', 403); }
            return ['cfg' => $cfg, 'people' => self::people()];
        }
        if ($op === 'cfg_set') {
            if (!in_array($role, ['owner', 'operations'], true)) { throw new Mutqan_Error('forbidden', 'التحكم في الرؤية للمالك والعمليات بس.', 403); }
            $n = $cfg;
            foreach (['enabled', 'showTechs'] as $k) { if (array_key_exists($k, $in)) { $n[$k] = !empty($in[$k]); } }
            if (isset($in['techsSee']) && in_array($in['techsSee'], ['all', 'staff', 'none'], true)) { $n['techsSee'] = $in['techsSee']; }
            if ($role === 'owner' && array_key_exists('opsSeeOwner', $in)) { $n['opsSeeOwner'] = !empty($in['opsSeeOwner']); }
            if (isset($in['hide']) && is_array($in['hide'])) {
                $owners = self::owner_ids();
                foreach ($in['hide'] as $uid => $h) {
                    $uid = (string) $uid;
                    if ($role !== 'owner' && in_array($uid, $owners, true)) { continue; }   // operations cannot change the owner's visibility
                    if ($h) { $n['hidden'][$uid] = true; } else { unset($n['hidden'][$uid]); }
                }
            }
            update_option(self::CFG, $n, false);
            return ['ok' => true, 'cfg' => self::cfg(), 'people' => self::people()];
        }
        throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400);
    }

    private static function list_for($viewer, $cfg) {
        $out = [];
        foreach (self::all() as $t) {
            if (!self::can_see($viewer, $t, $cfg)) { continue; }
            $out[] = ['id' => $t['id'], 'role' => $t['role'], 'roleLabel' => self::ROLE_LABEL[$t['role']] ?? '', 'name' => $t['name'], 'lat' => $t['lat'], 'lng' => $t['lng'],
                'acc' => $t['acc'] ?? null, 'ago' => time() - (int) $t['at'], 'me' => (string) $t['id'] === (string) $viewer['id'],
                'hidden' => !empty($cfg['hidden'][$t['id']]) || ($t['role'] === 'technician' && empty($cfg['showTechs']))];
        }
        return ['people' => $out, 'enabled' => !empty($cfg['enabled'])];
    }

    /** Every staff member, sharing or not, for the visibility controls. */
    private static function people() {
        global $wpdb;
        $live = self::all(); $cfg = self::cfg(); $out = [];
        $rows = $wpdb->get_results("SELECT id, display_name, handle, role FROM " . Mutqan_Store::table('users') . " WHERE role IN ('owner','operations','supervisor','technician') AND status='active' ORDER BY role, display_name LIMIT 300", ARRAY_A) ?: [];
        foreach ($rows as $r) {
            $out[] = ['id' => (string) $r['id'], 'name' => $r['display_name'] ?: $r['handle'], 'role' => $r['role'], 'roleLabel' => self::ROLE_LABEL[$r['role']] ?? $r['role'],
                'live' => isset($live[$r['id']]), 'ago' => isset($live[$r['id']]) ? time() - (int) $live[$r['id']]['at'] : null, 'hidden' => !empty($cfg['hidden'][$r['id']])];
        }
        return $out;
    }
    private static function owner_ids() {
        global $wpdb;
        $ids = array_map('strval', $wpdb->get_col("SELECT id FROM " . Mutqan_Store::table('users') . " WHERE role='owner'") ?: []);
        foreach (self::all() as $r) { if (($r['role'] ?? '') === 'owner') { $ids[] = (string) $r['id']; } }
        return array_values(array_unique($ids));
    }
}
