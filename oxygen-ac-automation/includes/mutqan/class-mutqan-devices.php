<?php
if (!defined('ABSPATH')) { exit; }

/**
 * One-device activation links: how the owner hands an app to a technician or to operations.
 *
 * The owner picks the app (technician / operations), picks a team member (or types a new one's name and phone),
 * and gets a link plus a 6-digit code to send on WhatsApp. The link page offers the Android app to download and
 * activates the device it is opened on; inside the installed app the person types the code instead.
 *
 * The FIRST device that activates owns the link. Any other device is refused, the same device can activate again
 * (reinstall), the person's other sessions are signed out on activation (one account → one phone), and the owner
 * sees every link's state (waiting / active on which device / revoked / expired) and can revoke a device at once.
 * Nobody types or receives a password: the account's password is random and never shown.
 */
final class Mutqan_Devices {

    const COLL = '_device_links';
    const DESK = 'https://desk.oxygen11.com/';
    const TTL = 259200;          // an unused link works for three days
    const APPS = [
        'tech' => ['label' => 'تطبيق الفني', 'page' => 'desk-tech.html', 'apk' => 'oxygen-desktech.apk', 'roles' => ['technician']],
        'ops' => ['label' => 'تطبيق العمليات', 'page' => 'desk-ops.html', 'apk' => 'oxygen-deskops.apk', 'roles' => ['operations', 'supervisor']],
    ];
    const ROLE_LABEL = ['technician' => 'فني', 'operations' => 'عمليات', 'supervisor' => 'مشرف'];

    private static function sys() { return ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']]; }

    /** The apps the owner hands out, with where each one lives (shown on the owner's apps screen). */
    public static function catalog() {
        $out = [];
        foreach (self::APPS as $k => $a) { $out[] = ['app' => $k, 'label' => $a['label'], 'url' => self::DESK . $a['page'], 'apk' => Mutqan_Native::apk_url($a['apk']), 'roles' => $a['roles'], 'linkable' => true]; }
        $out[] = ['app' => 'owner', 'label' => 'تطبيق المالك', 'url' => self::DESK . 'desk.html', 'apk' => Mutqan_Native::apk_url('oxygen-deskctl.apk'), 'roles' => ['owner'], 'linkable' => false];
        $out[] = ['app' => 'customer', 'label' => 'تطبيق العميل', 'url' => home_url('/app/'), 'apk' => Mutqan_Native::apk_url('oxygen-app.apk'), 'roles' => [], 'linkable' => false];
        return $out;
    }

    /* ------------------------------------------------------------------ entry (POST /devices) */

    public static function handle($in, $p) {
        $op = (string) ($in['op'] ?? '');
        if ($op === 'peek') { return self::peek((string) ($in['token'] ?? '')); }
        if ($op === 'activate') { return self::activate($in); }
        Mutqan_Auth::require_owner($p);
        switch ($op) {
            case 'catalog': return ['apps' => self::catalog()];
            case 'members': return ['members' => Mutqan_Auth::staff_members((string) ($in['role'] ?? ''))];
            case 'create': return self::create($in, $p);
            case 'list': return ['links' => self::list_links()];
            case 'revoke': return self::revoke((string) ($in['id'] ?? ''));
        }
        throw new Mutqan_Error('bad_op', 'عملية غير معروفة.', 400);
    }

    /* ------------------------------------------------------------------ owner */

    public static function create($in, $p) {
        $app = (string) ($in['app'] ?? '');
        if (!isset(self::APPS[$app])) { throw new Mutqan_Error('bad_app', 'اختار التطبيق.', 422); }
        $A = self::APPS[$app];
        if (!empty($in['userId'])) {
            $u = Mutqan_Auth::user_row((string) $in['userId']);
            if (!$u || ($u['status'] ?? '') !== 'active') { throw new Mutqan_Error('not_found', 'الشخص ده مش موجود أو موقوف.', 404); }
            if (!in_array($u['role'], $A['roles'], true)) { throw new Mutqan_Error('bad_role', 'الشخص ده دوره «' . (self::ROLE_LABEL[$u['role']] ?? $u['role']) . '» — اختار التطبيق المناسب له.', 422); }
        } else {
            $role = (string) ($in['role'] ?? $A['roles'][0]);
            if (!in_array($role, $A['roles'], true)) { $role = $A['roles'][0]; }
            $u = Mutqan_Auth::make_member((string) ($in['name'] ?? ''), (string) ($in['phone'] ?? ''), $role);
            if ($role === 'technician' && !Mutqan_Store::peek_doc('tech_profiles', $u['id'])) {
                Mutqan_Store::insert('tech_profiles', ['userId' => $u['id'], 'displayName' => $u['display_name'], 'phone' => $u['phone'], 'dailyCapacity' => 10, 'active' => true, 'available' => true, 'specialties' => [], 'level' => 'expert'], $u['id'], ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'creator-and-admin'], self::sys());
            }
        }
        // Earlier waiting links for the same person and app stop working: only the newest one can be used.
        foreach (Mutqan_Store::select(self::COLL, ['userId' => $u['id'], 'app' => $app, 'status' => 'pending'], ['limit' => 20], self::sys()) as $old) {
            Mutqan_Store::update(self::COLL, $old['id'], ['status' => 'replaced'], self::sys());
        }
        $token = bin2hex(random_bytes(20));
        $code = self::fresh_code();
        $doc = Mutqan_Store::insert(self::COLL, [
            'app' => $app, 'userId' => $u['id'], 'name' => $u['display_name'] ?: $u['handle'], 'role' => $u['role'], 'phone' => $u['phone'],
            'tokenHash' => hash('sha256', $token), 'code' => $code, 'status' => 'pending', 'expiresAt' => time() + self::TTL, 'by' => (string) ($p['id'] ?? ''),
        ], null, ['visibleTo' => 'creator-and-admin', 'writableBy' => 'admins'], self::sys());
        $url = self::DESK . $A['page'] . '#/activate/' . $token;
        $who = $doc['name'];
        $msg = "أهلاً {$who} 👋\nده رابط {$A['label']} — أكسجين/متقن:\n{$url}\n\n1) افتح الرابط من كروم ونزّل التطبيق.\n2) افتح التطبيق واكتب كود التفعيل: {$code}\n\nالرابط لجهاز واحد بس وصالح 3 أيام.";
        return ['link' => self::public_link($doc), 'url' => $url, 'code' => $code, 'apk' => Mutqan_Native::apk_url($A['apk']), 'message' => $msg,
            'whatsapp' => 'https://wa.me/' . self::wa_phone($u['phone']) . '?text=' . rawurlencode($msg)];
    }

    private static function fresh_code() {
        for ($i = 0; $i < 20; $i++) {
            $c = (string) random_int(100000, 999999);
            if (!Mutqan_Store::select(self::COLL, ['code' => $c, 'status' => 'pending'], ['limit' => 1], self::sys())) { return $c; }
        }
        throw new Mutqan_Error('busy', 'جرّب تاني.', 503);
    }

    private static function wa_phone($phone) {
        $d = preg_replace('/\D/', '', (string) $phone);
        if ($d === '') { return ''; }
        if (strpos($d, '00') === 0) { $d = substr($d, 2); }
        if (strpos($d, '05') === 0 && strlen($d) === 10) { $d = '966' . substr($d, 1); }
        return $d;
    }

    private static function state($d) {
        $s = (string) ($d['status'] ?? 'pending');
        return $s === 'pending' && (int) ($d['expiresAt'] ?? 0) < time() ? 'expired' : $s;
    }

    public static function public_link($d) {
        $A = self::APPS[$d['app']] ?? ['label' => $d['app']];
        return ['id' => (string) $d['id'], 'app' => $d['app'], 'appLabel' => $A['label'], 'name' => $d['name'] ?? '', 'role' => self::ROLE_LABEL[$d['role'] ?? ''] ?? ($d['role'] ?? ''),
            'phone' => $d['phone'] ?? '', 'status' => self::state($d), 'code' => self::state($d) === 'pending' ? ($d['code'] ?? '') : '',
            'createdAt' => $d['createdAt'] ?? '', 'expiresAt' => !empty($d['expiresAt']) ? gmdate('c', (int) $d['expiresAt']) : '',
            'activatedAt' => $d['activatedAt'] ?? '', 'device' => $d['device'] ?? '', 'lastAt' => $d['lastAt'] ?? ''];
    }

    public static function list_links() {
        return array_map([__CLASS__, 'public_link'], Mutqan_Store::select(self::COLL, ['status' => ['ne' => 'replaced']], ['order' => '-createdAt', 'limit' => 200], self::sys()));
    }

    public static function revoke($id) {
        $d = Mutqan_Store::get_raw(self::COLL, $id);
        if (!$d) { throw new Mutqan_Error('not_found', 'الرابط مش موجود.', 404); }
        Mutqan_Store::update(self::COLL, $id, ['status' => 'revoked', 'revokedAt' => gmdate('c')], self::sys());
        if (($d['status'] ?? '') === 'active') { Mutqan_Auth::revoke_sessions($d['userId']); }
        return ['ok' => true, 'link' => self::public_link(['id' => $id] + (array) Mutqan_Store::get_raw(self::COLL, $id))];
    }

    /* ------------------------------------------------------------------ the person's device */

    private static function find($in) {
        $t = (string) ($in['token'] ?? '');
        if ($t !== '' && preg_match('/^[a-f0-9]{40}$/', $t)) { $r = Mutqan_Store::select(self::COLL, ['tokenHash' => hash('sha256', $t)], ['limit' => 1], self::sys()); return $r[0] ?? null; }
        $c = preg_replace('/\D/', '', Mutqan_Auth::fold_digits((string) ($in['code'] ?? '')));
        if (strlen($c) === 6) {
            $r = Mutqan_Store::select(self::COLL, ['code' => $c, 'status' => ['in' => ['pending', 'active']]], ['order' => '-createdAt', 'limit' => 1], self::sys());
            return $r[0] ?? null;
        }
        return null;
    }

    /** What the link page shows before anything is activated. */
    public static function peek($token) {
        Mutqan_Limiter::hit('dev_peek', 60, 900);
        $d = self::find(['token' => $token]);
        if (!$d) { throw new Mutqan_Error('not_found', 'الرابط غير صحيح.', 404); }
        $A = self::APPS[$d['app']];
        $st = self::state($d);
        return ['app' => $d['app'], 'appLabel' => $A['label'], 'name' => $d['name'], 'role' => self::ROLE_LABEL[$d['role']] ?? '', 'status' => $st,
            'code' => $st === 'pending' ? $d['code'] : '', 'apk' => Mutqan_Native::apk_url($A['apk']), 'page' => self::DESK . $A['page']];
    }

    public static function activate($in) {
        Mutqan_Limiter::hit('dev_activate', 20, 900);
        $dev = (string) ($in['deviceId'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/', $dev)) { throw new Mutqan_Error('bad_device', 'افتح الرابط من التطبيق أو من كروم.', 422); }
        $d = self::find($in);
        if (!$d) { throw new Mutqan_Error('not_found', 'الكود أو الرابط غير صحيح — راجع الرسالة اللي وصلتك.', 404); }
        $st = self::state($d);
        if ($st === 'revoked') { throw new Mutqan_Error('revoked', 'الرابط ده اتلغى. اطلب رابط جديد من المالك.', 410); }
        if ($st === 'expired') { throw new Mutqan_Error('expired', 'الرابط انتهت صلاحيته (3 أيام). اطلب رابط جديد من المالك.', 410); }
        $hash = hash('sha256', $dev);
        $info = mb_substr(self::device_label((string) ($in['ua'] ?? '')), 0, 80);
        if ($st === 'active' && !hash_equals((string) ($d['deviceHash'] ?? ''), $hash)) {
            throw new Mutqan_Error('other_device', 'الرابط ده اتفعّل على جهاز تاني. لو غيّرت موبايلك اطلب رابط جديد من المالك.', 409);
        }
        $u = Mutqan_Auth::user_row($d['userId']);
        if (!$u || ($u['status'] ?? '') !== 'active') { throw new Mutqan_Error('disabled', 'الحساب موقوف — كلّم المالك.', 403); }
        if ($st === 'pending') {
            Mutqan_Auth::revoke_sessions($u['id']);      // one account, one phone
            Mutqan_Store::update(self::COLL, $d['id'], ['status' => 'active', 'deviceHash' => $hash, 'device' => $info, 'activatedAt' => gmdate('c'), 'lastAt' => gmdate('c')], self::sys());
            // Other active links of the same person for the same app end: the new phone replaces the old one.
            foreach (Mutqan_Store::select(self::COLL, ['userId' => $u['id'], 'app' => $d['app'], 'status' => 'active'], ['limit' => 20], self::sys()) as $o) {
                if ($o['id'] !== $d['id']) { Mutqan_Store::update(self::COLL, $o['id'], ['status' => 'revoked', 'revokedAt' => gmdate('c')], self::sys()); }
            }
            try { Mutqan_Store::insert('notifications', ['userId' => null, 'audience' => 'ops', 'event' => 'device', 'title' => 'جهاز جديد اتفعّل', 'body' => $d['name'] . ' فعّل ' . self::APPS[$d['app']]['label'] . ' على ' . $info, 'read' => false, 'at' => gmdate('c')], null, ['visibleTo' => 'creator-and-admin', 'writableBy' => 'admins', 'expiresAt' => gmdate('c', time() + 30 * 86400)], self::sys()); } catch (Throwable $e) { /* never block */ }
        } else {
            Mutqan_Store::update(self::COLL, $d['id'], ['lastAt' => gmdate('c')], self::sys());
        }
        return Mutqan_Auth::session_for($u) + ['app' => $d['app']];
    }

    private static function device_label($ua) {
        $os = preg_match('/Android\s*([0-9.]+)/i', $ua, $m) ? 'أندرويد ' . $m[1] : (preg_match('/iPhone|iPad/i', $ua) ? 'آيفون' : (preg_match('/Windows/i', $ua) ? 'ويندوز' : (preg_match('/Mac OS/i', $ua) ? 'ماك' : 'جهاز')));
        $model = preg_match('/;\s*([^;)]+?)\s+Build\//', $ua, $m) ? ' ' . trim($m[1]) : '';
        $how = stripos($ua, 'wv') !== false ? ' (التطبيق)' : ' (المتصفح)';
        return $os . $model . $how;
    }
}
