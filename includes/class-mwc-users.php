<?php
if (!defined('ABSPATH')) exit;

/**
 * المستخدمين والصلاحيات: المالك بيدخل بالرقم السري الرئيسي (من لوحة ووردبريس)،
 * وكل موظف ليه رقم سري خاص بيه + دور + الأرقام اللي يشوفها.
 */
class MWC_Users {
    const PERMS = [
        'chat_view' => 'يشوف المحادثات', 'chat_send' => 'يرد ويبعت', 'contacts_edit' => 'يعدّل بيانات العملاء والرد الآلي',
        'leads' => 'قاعدة العملاء', 'leads_export' => 'تصدير Excel', 'leads_delete' => 'حذف عملاء',
        'maps' => 'سحب من الخرائط', 'campaigns' => 'الحملات والإرسال الجماعي', 'logs' => 'السجلات',
        'settings' => 'الإعدادات والردود', 'accounts' => 'إدارة الأرقام', 'users' => 'المستخدمين والصلاحيات',
    ];
    const ROLES = [
        'admin' => ['name' => 'مدير', 'perms' => ['chat_view', 'chat_send', 'contacts_edit', 'leads', 'leads_export', 'leads_delete', 'maps', 'campaigns', 'logs', 'settings', 'accounts']],
        'supervisor' => ['name' => 'مشرف عمليات', 'perms' => ['chat_view', 'chat_send', 'contacts_edit', 'leads', 'logs']],
        'agent' => ['name' => 'خدمة عملاء', 'perms' => ['chat_view', 'chat_send', 'contacts_edit']],
        'marketer' => ['name' => 'تسويق', 'perms' => ['chat_view', 'leads', 'leads_export', 'maps', 'campaigns']],
        'viewer' => ['name' => 'مشاهدة فقط', 'perms' => ['chat_view']],
    ];

    static function all() { $u = get_option('mwc_users'); return is_array($u) ? $u : []; }

    static function current() { return $GLOBALS['mwc_user_obj'] ?? self::owner(); }
    static function owner() { return ['id' => 'owner', 'name' => 'المالك', 'role' => 'owner', 'accounts' => 'all', 'perms' => array_keys(self::PERMS), 'active' => 1]; }
    static function name() { return self::current()['name'] ?? ''; }
    static function is_owner() { return (self::current()['role'] ?? '') === 'owner'; }

    static function get($id) {
        if ($id === 'owner') return self::owner();
        foreach (self::all() as $u) if ($u['id'] === $id) return $u;
        return null;
    }

    static function can($perm) {
        $u = self::current();
        if (($u['role'] ?? '') === 'owner') return true;
        return in_array($perm, (array) ($u['perms'] ?? []), true);
    }

    /** null = كل الأرقام، أو مصفوفة أرقام الحسابات المسموحة */
    static function allowed_accounts() {
        $u = self::current();
        if (($u['role'] ?? '') === 'owner' || ($u['accounts'] ?? 'all') === 'all') return null;
        return array_map('intval', (array) $u['accounts']);
    }
    static function can_account($id) { $a = self::allowed_accounts(); return $a === null || in_array((int) $id, $a, true); }

    /** دخول بالرقم السري: الرئيسي = المالك، غيره = موظف */
    static function login($pin) {
        $master = get_option('mwc_pin_hash');
        if ($master && wp_check_password($pin, $master)) return self::owner();
        foreach (self::all() as $u) {
            if (!empty($u['active']) && !empty($u['pin_hash']) && wp_check_password($pin, $u['pin_hash'])) return $u;
        }
        return null;
    }

    static function public_list() {
        return array_map(function ($u) { unset($u['pin_hash']); $u['has_pin'] = true; return $u; }, self::all());
    }

    static function save($d) {
        $list = self::all();
        $id = sanitize_key($d['id'] ?? '') ?: 'u' . strtolower(wp_generate_password(8, false, false));
        $role = isset(self::ROLES[$d['role'] ?? '']) ? $d['role'] : 'agent';
        $perms = isset($d['perms']) && is_array($d['perms']) ? array_values(array_intersect($d['perms'], array_keys(self::PERMS))) : self::ROLES[$role]['perms'];
        $perms = array_values(array_diff($perms, ['users'])); // إدارة المستخدمين للمالك بس
        $row = null; $i = null;
        foreach ($list as $k => $u) if ($u['id'] === $id) { $row = $u; $i = $k; }
        $pin = preg_replace('/\D/', '', (string) ($d['pin'] ?? ''));
        if (!$row && strlen($pin) < 4) return new WP_Error('pin', 'الرقم السري لازم 4 أرقام على الأقل', ['status' => 400]);
        if ($pin !== '') {
            if (strlen($pin) < 4) return new WP_Error('pin', 'الرقم السري لازم 4 أرقام على الأقل', ['status' => 400]);
            $master = get_option('mwc_pin_hash');
            if ($master && wp_check_password($pin, $master)) return new WP_Error('pin', 'الرقم ده مستخدم — اختار رقم تاني', ['status' => 400]);
            foreach ($list as $u) if ($u['id'] !== $id && wp_check_password($pin, $u['pin_hash'])) return new WP_Error('pin', 'الرقم ده مستخدم لموظف تاني', ['status' => 400]);
        }
        $acc = $d['accounts'] ?? 'all';
        $new = [
            'id' => $id, 'name' => sanitize_text_field($d['name'] ?? 'موظف'), 'role' => $role, 'perms' => $perms,
            'accounts' => $acc === 'all' ? 'all' : array_map('intval', (array) $acc),
            'active' => empty($d['active']) ? 0 : 1,
            'pin_hash' => $pin !== '' ? wp_hash_password($pin) : ($row['pin_hash'] ?? ''),
        ];
        if ($i !== null) $list[$i] = $new; else $list[] = $new;
        update_option('mwc_users', array_values($list), false);
        MWC_Store::log('users', ($row ? 'تعديل' : 'إضافة') . ' مستخدم: ' . $new['name'] . ' (' . self::ROLES[$role]['name'] . ')');
        if ($row && (!$new['active'] || $pin !== '')) self::kill_tokens($id);
        unset($new['pin_hash']);
        return $new;
    }

    static function delete($id) {
        $list = array_values(array_filter(self::all(), function ($u) use ($id) { return $u['id'] !== $id; }));
        update_option('mwc_users', $list, false);
        self::kill_tokens($id);
        MWC_Store::log('users', 'حذف مستخدم ' . $id);
    }

    static function kill_tokens($id) {
        global $wpdb;
        $wpdb->delete(MWC_Store::t('tokens'), ['user_id' => $id]);
        $wpdb->delete(MWC_Store::t('push'), ['user_id' => $id]);
    }
}
