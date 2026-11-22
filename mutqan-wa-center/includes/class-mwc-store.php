<?php
if (!defined('ABSPATH')) exit;

/**
 * قاعدة بيانات مركز الواتساب — كل البيانات على موقعك أنت (مش على Meta ولا على السيرفر)
 * عشان لو غيّرت السيرفر أو الربط، المحادثات والإعدادات تفضل موجودة.
 */
class MWC_Store {

    static function t($name) { global $wpdb; return $wpdb->prefix . 'mwc_' . $name; }

    static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE " . self::t('accounts') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            label VARCHAR(120) NOT NULL DEFAULT '',
            phone VARCHAR(32) NOT NULL DEFAULT '',
            provider VARCHAR(20) NOT NULL DEFAULT 'evolution',
            role VARCHAR(20) NOT NULL DEFAULT 'sales',
            branch VARCHAR(80) NOT NULL DEFAULT '',
            color VARCHAR(16) NOT NULL DEFAULT '#00a884',
            config LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'unknown',
            active TINYINT(1) NOT NULL DEFAULT 1,
            sort INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) $c;");

        dbDelta("CREATE TABLE " . self::t('contacts') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wa_id VARCHAR(40) NOT NULL,
            name VARCHAR(160) NOT NULL DEFAULT '',
            push_name VARCHAR(160) NOT NULL DEFAULT '',
            avatar VARCHAR(500) NOT NULL DEFAULT '',
            about TEXT NULL,
            notes TEXT NULL,
            tags VARCHAR(500) NOT NULL DEFAULT '',
            source VARCHAR(255) NOT NULL DEFAULT '',
            source_url VARCHAR(500) NOT NULL DEFAULT '',
            bot VARCHAR(8) NOT NULL DEFAULT 'default',
            extra LONGTEXT NULL,
            created INT UNSIGNED NOT NULL DEFAULT 0,
            updated INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY wa_id (wa_id)
        ) $c;");

        dbDelta("CREATE TABLE " . self::t('chats') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT UNSIGNED NOT NULL,
            contact_id BIGINT UNSIGNED NOT NULL,
            last_ts INT UNSIGNED NOT NULL DEFAULT 0,
            last_in_ts INT UNSIGNED NOT NULL DEFAULT 0,
            preview VARCHAR(255) NOT NULL DEFAULT '',
            unread INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            assigned VARCHAR(80) NOT NULL DEFAULT '',
            pinned TINYINT(1) NOT NULL DEFAULT 0,
            archived TINYINT(1) NOT NULL DEFAULT 0,
            labels VARCHAR(255) NOT NULL DEFAULT '',
            changed INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY acc_contact (account_id, contact_id),
            KEY changed (changed)
        ) $c;");

        dbDelta("CREATE TABLE " . self::t('messages') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            chat_id BIGINT UNSIGNED NOT NULL,
            direction VARCHAR(3) NOT NULL DEFAULT 'in',
            type VARCHAR(20) NOT NULL DEFAULT 'text',
            body LONGTEXT NULL,
            media_url VARCHAR(500) NOT NULL DEFAULT '',
            mime VARCHAR(100) NOT NULL DEFAULT '',
            file_name VARCHAR(255) NOT NULL DEFAULT '',
            duration INT NOT NULL DEFAULT 0,
            ext_id VARCHAR(190) NOT NULL DEFAULT '',
            reply_to VARCHAR(190) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'received',
            error VARCHAR(255) NOT NULL DEFAULT '',
            sender VARCHAR(80) NOT NULL DEFAULT '',
            ts INT UNSIGNED NOT NULL DEFAULT 0,
            changed INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY chat_ts (chat_id, ts),
            KEY ext_id (ext_id),
            KEY changed (changed)
        ) $c;");

        dbDelta("CREATE TABLE " . self::t('tokens') . " (
            token CHAR(64) NOT NULL,
            user_label VARCHAR(80) NOT NULL DEFAULT '',
            user_id VARCHAR(40) NOT NULL DEFAULT 'owner',
            expires INT UNSIGNED NOT NULL,
            PRIMARY KEY (token)
        ) $c;");

        dbDelta("CREATE TABLE " . self::t('leads') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            phone VARCHAR(40) NOT NULL,
            name VARCHAR(200) NOT NULL DEFAULT '',
            country VARCHAR(60) NOT NULL DEFAULT '',
            city VARCHAR(80) NOT NULL DEFAULT '',
            category VARCHAR(160) NOT NULL DEFAULT '',
            kind VARCHAR(16) NOT NULL DEFAULT '',
            source VARCHAR(200) NOT NULL DEFAULT '',
            tags VARCHAR(500) NOT NULL DEFAULT '',
            status VARCHAR(16) NOT NULL DEFAULT 'new',
            notes TEXT NULL,
            extra LONGTEXT NULL,
            sent_count INT NOT NULL DEFAULT 0,
            last_sent INT UNSIGNED NOT NULL DEFAULT 0,
            created INT UNSIGNED NOT NULL DEFAULT 0,
            updated INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY phone (phone),
            KEY status (status),
            KEY city (city)
        ) $c;");

        dbDelta("CREATE TABLE " . self::t('logs') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ts INT UNSIGNED NOT NULL DEFAULT 0,
            who VARCHAR(80) NOT NULL DEFAULT '',
            type VARCHAR(30) NOT NULL DEFAULT '',
            text TEXT NULL,
            PRIMARY KEY (id),
            KEY ts (ts)
        ) $c;");

        dbDelta("CREATE TABLE " . self::t('campaigns') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(160) NOT NULL DEFAULT '',
            status VARCHAR(16) NOT NULL DEFAULT 'draft',
            data LONGTEXT NULL,
            total INT NOT NULL DEFAULT 0,
            sent INT NOT NULL DEFAULT 0,
            failed INT NOT NULL DEFAULT 0,
            replied INT NOT NULL DEFAULT 0,
            created_by VARCHAR(80) NOT NULL DEFAULT '',
            created INT UNSIGNED NOT NULL DEFAULT 0,
            started INT UNSIGNED NOT NULL DEFAULT 0,
            finished INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) $c;");

        dbDelta("CREATE TABLE " . self::t('items') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id BIGINT UNSIGNED NOT NULL,
            lead_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            phone VARCHAR(40) NOT NULL,
            name VARCHAR(200) NOT NULL DEFAULT '',
            account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(16) NOT NULL DEFAULT 'queued',
            error VARCHAR(255) NOT NULL DEFAULT '',
            sent_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY camp_phone (campaign_id, phone),
            KEY camp_status (campaign_id, status),
            KEY phone (phone)
        ) $c;");

        dbDelta("CREATE TABLE " . self::t('jobs') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ext_id VARCHAR(80) NOT NULL DEFAULT '',
            keywords TEXT NULL,
            city VARCHAR(80) NOT NULL DEFAULT '',
            category VARCHAR(160) NOT NULL DEFAULT '',
            tags VARCHAR(255) NOT NULL DEFAULT '',
            depth INT NOT NULL DEFAULT 5,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            found INT NOT NULL DEFAULT 0,
            imported INT NOT NULL DEFAULT 0,
            error VARCHAR(255) NOT NULL DEFAULT '',
            created_by VARCHAR(80) NOT NULL DEFAULT '',
            created INT UNSIGNED NOT NULL DEFAULT 0,
            finished INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) $c;");

        dbDelta("CREATE TABLE " . self::t('push') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id VARCHAR(40) NOT NULL DEFAULT 'owner',
            endpoint VARCHAR(600) NOT NULL,
            p256dh VARCHAR(200) NOT NULL,
            auth VARCHAR(80) NOT NULL,
            created INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY user_id (user_id)
        ) $c;");

        if (!get_option('mwc_tick_key')) update_option('mwc_tick_key', wp_generate_password(32, false), false);
        if (!get_option('mwc_config')) update_option('mwc_config', self::default_config(), false);
        if (!get_option('mwc_webhook_secret')) update_option('mwc_webhook_secret', wp_generate_password(32, false), false);
        if (!get_option('mwc_meta_verify')) update_option('mwc_meta_verify', wp_generate_password(24, false), false);
        update_option('mwc_db_version', MWC_VERSION, false);
    }

    /** الإعدادات المفتوحة: ردود جاهزة، تصنيفات، أزرار خدمات — كلها بتتعدل من التطبيق */
    static function default_config() {
        return [
            'quick_replies' => [
                ['key' => 'ترحيب', 'text' => 'مرحبا بكم في {brand} 🌬️ معاك خدمة العملاء، كيف نقدر نخدمك؟ (غسيل مكيفات، تركيب، صيانة، غرف تبريد، سباكة، نجارة، كهرباء)'],
                ['key' => 'موقع', 'text' => 'فضلاً أرسل لنا الموقع (لوكيشن) عشان نرتب لك أقرب فني 📍'],
                ['key' => 'موعد', 'text' => 'تمام، موعدك {date}. الفني بيتواصل معك قبل الوصول بإذن الله.'],
                ['key' => 'شكر', 'text' => 'شكراً لتواصلك مع {brand} 🙏 نسعد بخدمتك دائماً.'],
            ],
            'labels' => [
                ['name' => 'طلب جديد', 'color' => '#25d366'],
                ['name' => 'متابعة', 'color' => '#ffb02e'],
                ['name' => 'تم التنفيذ', 'color' => '#53bdeb'],
                ['name' => 'شكوى', 'color' => '#ea0038'],
            ],
            /* أزرار الخدمات: message = يكتب رسالة جاهزة، link = يفتح رابط، webhook = يبعت بيانات العميل لأي نظام (مثلاً الـdesk) */
            'actions' => [
                ['label' => 'جدول الأسعار', 'icon' => '💲', 'type' => 'message', 'payload' => 'جدول أسعارنا: {site}/prices'],
                ['label' => 'فيديو طريقة الغسيل', 'icon' => '🎬', 'type' => 'message', 'payload' => 'شوف طريقة الغسيل عندنا: {site}/wash-video'],
                ['label' => 'فتح العميل في غرفة العمليات', 'icon' => '🗺️', 'type' => 'link', 'payload' => 'https://desk.oxygen11.com/?phone={phone}'],
                ['label' => 'إنشاء طلب', 'icon' => '🧾', 'type' => 'webhook', 'payload' => ''],
            ],
            'vars' => ['brand' => 'أكسجين للصيانة', 'site' => 'https://oxygen11.com'],
            'staff' => ['المالك', 'العمليات'],
            'sound' => 'whatsapp',
        ];
    }

    static function config() {
        $c = get_option('mwc_config');
        return is_array($c) ? array_merge(self::default_config(), $c) : self::default_config();
    }

    static function now() { return time(); }

    static function norm_phone($p) {
        $p = (string) $p;
        // معرّفات واتساب الجديدة (LID) اللي مش بتكشف الرقم
        if (strpos($p, 'lid:') === 0 || strpos($p, '@lid') !== false) return 'lid:' . preg_replace('/\D+/', '', explode('@', str_replace('lid:', '', $p))[0]);
        $p = preg_replace('/\D+/', '', explode('@', $p)[0]);
        if (strpos($p, '00') === 0) $p = substr($p, 2);
        if (strpos($p, '05') === 0 && strlen($p) === 10) $p = '966' . substr($p, 1);
        if (strpos($p, '5') === 0 && strlen($p) === 9) $p = '966' . $p;
        return $p;
    }

    /* ---------- accounts ---------- */
    static function accounts($with_secrets = false) {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM " . self::t('accounts') . " ORDER BY sort, id", ARRAY_A);
        foreach ($rows as &$r) {
            $cfg = json_decode($r['config'] ?: '{}', true) ?: [];
            if (!$with_secrets) {
                foreach (['token', 'apikey', 'app_secret'] as $k) if (!empty($cfg[$k])) $cfg[$k] = '••••' . substr($cfg[$k], -4);
            }
            $r['config'] = $cfg;
            $r['id'] = (int) $r['id'];
            $r['active'] = (int) $r['active'];
        }
        return $rows;
    }

    static function account($id) {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::t('accounts') . " WHERE id=%d", $id), ARRAY_A);
        if (!$r) return null;
        $r['config'] = json_decode($r['config'] ?: '{}', true) ?: [];
        return $r;
    }

    static function account_by($key, $val) {
        foreach (self::accounts(true) as $a) {
            if ((string) ($a['config'][$key] ?? '') === (string) $val) return $a;
        }
        return null;
    }

    static function save_account($d) {
        global $wpdb;
        $id = (int) ($d['id'] ?? 0);
        $old = $id ? self::account($id) : null;
        $cfg = is_array($d['config'] ?? null) ? $d['config'] : [];
        // الحقول السرية: لو جت مخفية (••••) نحتفظ بالقديمة
        if ($old) foreach ($cfg as $k => $v) if (is_string($v) && strpos($v, '••••') === 0) $cfg[$k] = $old['config'][$k] ?? '';
        $row = [
            'label' => sanitize_text_field($d['label'] ?? ''),
            'phone' => self::norm_phone($d['phone'] ?? ''),
            'provider' => in_array($d['provider'] ?? '', ['meta', 'evolution'], true) ? $d['provider'] : 'evolution',
            'role' => sanitize_key($d['role'] ?? 'sales'),
            'branch' => sanitize_text_field($d['branch'] ?? ''),
            'color' => sanitize_hex_color($d['color'] ?? '#00a884') ?: '#00a884',
            'config' => wp_json_encode($cfg),
            'active' => array_key_exists('active', $d) ? (empty($d['active']) ? 0 : 1) : 1,
            'sort' => (int) ($d['sort'] ?? 0),
        ];
        if ($id && $old) { $wpdb->update(self::t('accounts'), $row, ['id' => $id]); return $id; }
        $wpdb->insert(self::t('accounts'), $row);
        return (int) $wpdb->insert_id;
    }

    static function set_account_status($id, $status) {
        global $wpdb;
        $wpdb->update(self::t('accounts'), ['status' => sanitize_key($status)], ['id' => (int) $id]);
    }

    /* ---------- contacts / chats ---------- */
    static function contact_upsert($wa_id, $fields = []) {
        global $wpdb;
        $wa_id = self::norm_phone($wa_id);
        if (!$wa_id) return 0;
        $t = self::t('contacts');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE wa_id=%s", $wa_id), ARRAY_A);
        $now = self::now();
        $allowed = ['name', 'push_name', 'avatar', 'about', 'notes', 'tags', 'source', 'source_url', 'bot'];
        $upd = [];
        foreach ($allowed as $k) if (isset($fields[$k]) && $fields[$k] !== '' && $fields[$k] !== null) $upd[$k] = $fields[$k];
        if ($row) {
            // الاسم اللي انت كاتبه بنفسك ما يتغيرش من الواتساب
            if (isset($upd['name']) && !empty($fields['_auto']) && $row['name'] !== '') unset($upd['name']);
            if (isset($upd['source']) && !empty($fields['_keep_source']) && $row['source'] !== '') { unset($upd['source']); unset($upd['source_url']); }
            if ($upd) { $upd['updated'] = $now; $wpdb->update($t, $upd, ['id' => $row['id']]); }
            return (int) $row['id'];
        }
        $upd['wa_id'] = $wa_id; $upd['created'] = $now; $upd['updated'] = $now;
        $wpdb->insert($t, $upd);
        return (int) $wpdb->insert_id;
    }

    static function contact($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::t('contacts') . " WHERE id=%d", $id), ARRAY_A);
    }

    static function chat_get_or_create($account_id, $contact_id) {
        global $wpdb;
        $t = self::t('chats');
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE account_id=%d AND contact_id=%d", $account_id, $contact_id));
        if ($id) return (int) $id;
        $wpdb->insert($t, ['account_id' => $account_id, 'contact_id' => $contact_id, 'changed' => self::now()]);
        return (int) $wpdb->insert_id;
    }

    static function chat($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, k.wa_id, k.name, k.push_name, k.avatar, k.about, k.notes, k.tags, k.source, k.source_url, k.bot
             FROM " . self::t('chats') . " c JOIN " . self::t('contacts') . " k ON k.id=c.contact_id WHERE c.id=%d", $id), ARRAY_A);
    }

    static function chats($args) {
        global $wpdb;
        $w = ['1=1']; $p = [];
        if (!empty($args['account'])) { $w[] = 'c.account_id=%d'; $p[] = (int) $args['account']; }
        if (isset($args['allowed']) && is_array($args['allowed'])) $w[] = $args['allowed'] ? 'c.account_id IN (' . implode(',', array_map('intval', $args['allowed'])) . ')' : '0=1';
        if (!empty($args['since'])) { $w[] = 'c.changed>=%d'; $p[] = (int) $args['since']; }
        if (empty($args['archived']) && empty($args['since'])) $w[] = 'c.archived=0';
        if (!empty($args['q'])) {
            $like = '%' . $wpdb->esc_like($args['q']) . '%';
            $w[] = '(k.name LIKE %s OR k.push_name LIKE %s OR k.wa_id LIKE %s OR c.preview LIKE %s)';
            array_push($p, $like, $like, $like, $like);
        }
        $limit = min(500, max(1, (int) ($args['limit'] ?? 200)));
        $sql = "SELECT c.*, k.wa_id, k.name, k.push_name, k.avatar, k.tags, k.source, k.source_url, k.bot
                FROM " . self::t('chats') . " c JOIN " . self::t('contacts') . " k ON k.id=c.contact_id
                WHERE " . implode(' AND ', $w) . " ORDER BY c.pinned DESC, c.last_ts DESC LIMIT $limit";
        return $wpdb->get_results($p ? $wpdb->prepare($sql, $p) : $sql, ARRAY_A);
    }

    static function touch_chat($chat_id, $preview, $ts, $incoming) {
        global $wpdb;
        $t = self::t('chats');
        $preview = mb_substr(wp_strip_all_tags((string) $preview), 0, 240);
        if ($incoming) {
            $wpdb->query($wpdb->prepare("UPDATE $t SET last_ts=GREATEST(last_ts,%d), last_in_ts=%d, preview=%s, unread=unread+1, archived=0, changed=%d WHERE id=%d",
                $ts, $ts, $preview, self::now(), $chat_id));
        } else {
            $wpdb->query($wpdb->prepare("UPDATE $t SET last_ts=GREATEST(last_ts,%d), preview=%s, changed=%d WHERE id=%d",
                $ts, $preview, self::now(), $chat_id));
        }
    }

    static function update_chat($chat_id, $fields) {
        global $wpdb;
        $fields['changed'] = self::now();
        $wpdb->update(self::t('chats'), $fields, ['id' => (int) $chat_id]);
    }

    /* ---------- messages ---------- */
    static function add_message($m) {
        global $wpdb;
        $t = self::t('messages');
        if (!empty($m['ext_id'])) {
            $dup = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE ext_id=%s AND chat_id=%d", $m['ext_id'], $m['chat_id']));
            if ($dup) return (int) $dup;
        }
        $m['ts'] = $m['ts'] ?? self::now();
        $m['changed'] = self::now();
        $wpdb->insert($t, $m);
        return (int) $wpdb->insert_id;
    }

    static function update_message($id, $fields) {
        global $wpdb;
        $fields['changed'] = self::now();
        $wpdb->update(self::t('messages'), $fields, ['id' => (int) $id]);
    }

    /** تحديث حالة الرسالة (وصلت/اتقرت) — الحالة ما ترجعش لورا */
    static function status_by_ext($ext_id, $status, $error = '') {
        global $wpdb;
        $rank = ['pending' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'played' => 3, 'failed' => 9];
        $t = self::t('messages');
        $row = $wpdb->get_row($wpdb->prepare("SELECT id,status FROM $t WHERE ext_id=%s", $ext_id), ARRAY_A);
        if (!$row) return;
        if ($status !== 'failed' && ($rank[$row['status']] ?? 0) >= ($rank[$status] ?? 0)) return;
        self::update_message($row['id'], ['status' => $status, 'error' => mb_substr($error, 0, 250)]);
    }

    static function messages($chat_id, $before = 0, $limit = 60) {
        global $wpdb;
        $t = self::t('messages');
        $sql = $before
            ? $wpdb->prepare("SELECT * FROM $t WHERE chat_id=%d AND id<%d ORDER BY id DESC LIMIT %d", $chat_id, $before, $limit)
            : $wpdb->prepare("SELECT * FROM $t WHERE chat_id=%d ORDER BY id DESC LIMIT %d", $chat_id, $limit);
        return array_reverse($wpdb->get_results($sql, ARRAY_A));
    }

    static function messages_since($since, $account = 0) {
        global $wpdb;
        $m = self::t('messages'); $c = self::t('chats');
        $sql = "SELECT m.* FROM $m m JOIN $c c ON c.id=m.chat_id WHERE m.changed>=%d" . ($account ? " AND c.account_id=%d" : "") . " ORDER BY m.id LIMIT 300";
        return $wpdb->get_results($account ? $wpdb->prepare($sql, $since, $account) : $wpdb->prepare($sql, $since), ARRAY_A);
    }

    static function media($chat_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id,type,media_url,mime,file_name,ts FROM " . self::t('messages') . " WHERE chat_id=%d AND media_url<>'' ORDER BY id DESC LIMIT 200", $chat_id), ARRAY_A);
    }

    /* ---------- media files (stored in your own uploads folder) ---------- */
    static function save_media_bytes($bytes, $mime, $name = '') {
        $up = wp_upload_dir();
        $dir = trailingslashit($up['basedir']) . 'mwc/' . gmdate('Y/m');
        wp_mkdir_p($dir);
        if (!file_exists($up['basedir'] . '/mwc/index.html')) @file_put_contents($up['basedir'] . '/mwc/index.html', '');
        $ext = self::ext_for($mime, $name);
        $file = wp_generate_password(20, false) . '.' . $ext;
        file_put_contents($dir . '/' . $file, $bytes);
        return trailingslashit($up['baseurl']) . 'mwc/' . gmdate('Y/m') . '/' . $file;
    }

    static function url_to_path($url) {
        $up = wp_upload_dir();
        if (strpos($url, $up['baseurl']) !== 0) return '';
        $p = $up['basedir'] . substr($url, strlen($up['baseurl']));
        return (strpos(realpath($p) ?: '', realpath($up['basedir'])) === 0) ? $p : '';
    }

    static function ext_for($mime, $name = '') {
        $map = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
            'video/mp4' => 'mp4', 'video/3gpp' => '3gp', 'video/quicktime' => 'mov', 'video/webm' => 'webm',
            'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac', 'audio/amr' => 'amr', 'audio/webm' => 'webm',
            'application/pdf' => 'pdf',
        ];
        $base = strtolower(trim(explode(';', (string) $mime)[0]));
        if (isset($map[$base])) return $map[$base];
        $e = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
        $e = preg_replace('/[^a-z0-9]/', '', $e);
        $blocked = ['php', 'phtml', 'php5', 'phar', 'cgi', 'pl', 'asp', 'aspx', 'jsp', 'sh', 'htaccess', 'js', 'html', 'htm', 'svg'];
        if ($e && !in_array($e, $blocked, true) && strlen($e) <= 6) return $e;
        return 'bin';
    }

    /* ---------- tokens ---------- */
    static function new_token($label, $user_id = 'owner') {
        global $wpdb;
        $tok = bin2hex(random_bytes(32));
        $wpdb->insert(self::t('tokens'), ['token' => $tok, 'user_label' => mb_substr($label ?: 'staff', 0, 80), 'user_id' => $user_id, 'expires' => time() + 60 * DAY_IN_SECONDS]);
        return $tok;
    }

    /** @return array|null ['label'=>..,'user_id'=>..] */
    static function check_token($tok) {
        global $wpdb;
        if (!$tok || !preg_match('/^[a-f0-9]{64}$/', $tok)) return null;
        $r = $wpdb->get_row($wpdb->prepare("SELECT user_label, user_id FROM " . self::t('tokens') . " WHERE token=%s AND expires>%d", $tok, time()), ARRAY_A);
        return $r ? ['label' => $r['user_label'], 'user_id' => $r['user_id'] ?: 'owner'] : null;
    }

    static function log($type, $text, $who = '') {
        global $wpdb;
        $wpdb->insert(self::t('logs'), ['ts' => time(), 'who' => mb_substr($who ?: (class_exists('MWC_Users') ? MWC_Users::name() : ''), 0, 80), 'type' => $type, 'text' => mb_substr($text, 0, 2000)]);
    }

    /** يوم/ساعة بتوقيت السعودية */
    static function riyadh($fmt, $ts = null) { return (new DateTime('@' . ($ts ?? time())))->setTimezone(new DateTimeZone('Asia/Riyadh'))->format($fmt); }
}
