<?php
if (!defined('ABSPATH')) exit;

/**
 * الحملات (الإرسال الجماعي): تجهيز العدد والكمية، توزيع على الأرقام، حد يومي لكل رقم،
 * فاصل عشوائي بين كل رسالة، نافذة إرسال (1 الضهر – 8 بالليل بتوقيت السعودية)، نصوص بديلة + صوت/صور/فيديو/ملفات.
 */
class MWC_Campaigns {

    static function defaults() {
        return ['accounts' => [], 'texts' => [], 'attachments' => [], 'template' => null, 'filter' => [], 'limit' => 0,
                'daily_cap' => 150, 'delay_min' => 45, 'delay_max' => 120, 'window_start' => 13, 'window_end' => 20,
                'avoid_days' => 7, 'only_mobile' => 1, 'stop_on_reply' => 1];
    }

    static function out($c) {
        $d = array_merge(self::defaults(), json_decode($c['data'] ?: '{}', true) ?: []);
        global $wpdb;
        $q = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . MWC_Store::t('items') . " WHERE campaign_id=%d AND status='queued'", $c['id']));
        return ['id' => (int) $c['id'], 'name' => $c['name'], 'status' => $c['status'], 'data' => $d, 'total' => (int) $c['total'],
            'sent' => (int) $c['sent'], 'failed' => (int) $c['failed'], 'replied' => (int) $c['replied'], 'queued' => $q,
            'created_by' => $c['created_by'], 'created' => (int) $c['created'], 'started' => (int) $c['started'], 'finished' => (int) $c['finished']];
    }

    static function all() {
        global $wpdb;
        return array_map([__CLASS__, 'out'], $wpdb->get_results("SELECT * FROM " . MWC_Store::t('campaigns') . " ORDER BY id DESC LIMIT 200", ARRAY_A));
    }

    static function get($id) {
        global $wpdb;
        $c = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . MWC_Store::t('campaigns') . " WHERE id=%d", $id), ARRAY_A);
        return $c ? self::out($c) : null;
    }

    /** المستلمين المتاحين حسب الفلتر (من غير الممنوعين/الثابت/اللي اتبعتلهم قريب) */
    static function audience($d, $count_only = false) {
        global $wpdb; $t = MWC_Store::t('leads'); $p = [];
        $f = (array) ($d['filter'] ?? []);
        if (!empty($d['avoid_days'])) $f['not_contacted_days'] = (int) $d['avoid_days'];
        $w = MWC_Leads::where($f, $p);
        $w .= " AND status NOT IN ('blocked','invalid')";
        if (!empty($d['only_mobile'])) $w .= " AND kind IN ('mobile','intl')";
        if ($count_only) return (int) $wpdb->get_var($p ? $wpdb->prepare("SELECT COUNT(*) FROM $t WHERE $w", $p) : "SELECT COUNT(*) FROM $t WHERE $w");
        $lim = (int) ($d['limit'] ?? 0); $lim = $lim > 0 ? min($lim, 50000) : 50000;
        $sql = "SELECT id, phone, name FROM $t WHERE $w ORDER BY last_sent ASC, id ASC LIMIT $lim";
        return $wpdb->get_results($p ? $wpdb->prepare($sql, $p) : $sql, ARRAY_A);
    }

    /** معاينة: كام واحد هيتبعتله، وهياخد كام يوم */
    static function preview($d) {
        $d = array_merge(self::defaults(), $d);
        $avail = self::audience($d, true);
        $n = $d['limit'] > 0 ? min($avail, (int) $d['limit']) : $avail;
        $accs = max(1, count((array) $d['accounts']));
        $perDay = $accs * max(1, (int) $d['daily_cap']);
        $hours = max(1, (int) $d['window_end'] - (int) $d['window_start']);
        $avgDelay = max(5, ((int) $d['delay_min'] + (int) $d['delay_max']) / 2);
        $perDayBySpeed = (int) floor($accs * $hours * 3600 / $avgDelay);
        $realPerDay = max(1, min($perDay, $perDayBySpeed));
        return ['available' => $avail, 'will_send' => $n, 'per_day' => $realPerDay, 'days' => (int) ceil($n / $realPerDay), 'accounts' => $accs];
    }

    static function save($in) {
        global $wpdb;
        $id = (int) ($in['id'] ?? 0);
        $d = array_merge(self::defaults(), (array) ($in['data'] ?? []));
        $d['accounts'] = array_values(array_filter(array_map('intval', (array) $d['accounts']), [MWC_Users::class, 'can_account']));
        $d['texts'] = array_values(array_filter(array_map('wp_kses_post', (array) $d['texts']), function ($x) { return trim($x) !== ''; }));
        foreach (['daily_cap', 'delay_min', 'delay_max', 'window_start', 'window_end', 'avoid_days', 'limit'] as $k) $d[$k] = max(0, (int) $d[$k]);
        if ($d['delay_max'] < $d['delay_min']) $d['delay_max'] = $d['delay_min'];
        $d['delay_min'] = max(10, $d['delay_min']);
        $row = ['name' => sanitize_text_field($in['name'] ?? 'حملة'), 'data' => wp_json_encode($d, JSON_UNESCAPED_UNICODE)];
        if ($id) { $wpdb->update(MWC_Store::t('campaigns'), $row, ['id' => $id]); }
        else { $wpdb->insert(MWC_Store::t('campaigns'), $row + ['status' => 'draft', 'created_by' => MWC_Users::name(), 'created' => time()]); $id = (int) $wpdb->insert_id; }
        return self::get($id);
    }

    /** تجهيز قايمة المستلمين وبدء الإرسال */
    static function start($id) {
        global $wpdb;
        $c = self::get($id);
        if (!$c) return new WP_Error('nf', 'الحملة مش موجودة', ['status' => 404]);
        $d = $c['data'];
        if (!$d['accounts']) return new WP_Error('acc', 'اختار رقم واحد على الأقل للإرسال', ['status' => 400]);
        if (!$d['texts'] && !$d['attachments'] && empty($d['template']['name'])) return new WP_Error('msg', 'اكتب الرسالة أو ضيف مرفق', ['status' => 400]);
        if ($c['status'] === 'draft') {
            $aud = self::audience($d);
            if (!$aud) return new WP_Error('aud', 'مفيش عملاء مطابقين للفلتر (أو كلهم اتبعتلهم قريب)', ['status' => 400]);
            $it = MWC_Store::t('items'); $lt = MWC_Store::t('leads');
            foreach ($aud as $i => $l) {
                $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $it (campaign_id, lead_id, phone, name, account_id, status) VALUES (%d,%d,%s,%s,%d,'queued')",
                    $id, $l['id'], $l['phone'], $l['name'], $d['accounts'][$i % count($d['accounts'])]));
            }
            $ids = implode(',', array_map('intval', array_column($aud, 'id')));
            $wpdb->query("UPDATE $lt SET status='queued' WHERE id IN ($ids) AND status='new'");
            $wpdb->update(MWC_Store::t('campaigns'), ['total' => count($aud), 'started' => time()], ['id' => $id]);
        }
        $wpdb->update(MWC_Store::t('campaigns'), ['status' => 'running'], ['id' => $id]);
        MWC_Store::log('campaign', 'تشغيل الحملة «' . $c['name'] . '»');
        return self::get($id);
    }

    static function set_status($id, $st) {
        global $wpdb;
        if (!in_array($st, ['paused', 'stopped', 'running'], true)) return self::get($id);
        if ($st === 'running') return self::start($id);
        $wpdb->update(MWC_Store::t('campaigns'), ['status' => $st] + ($st === 'stopped' ? ['finished' => time()] : []), ['id' => $id]);
        if ($st === 'stopped') {
            $wpdb->query($wpdb->prepare("UPDATE " . MWC_Store::t('leads') . " SET status='new' WHERE status='queued' AND id IN (SELECT lead_id FROM " . MWC_Store::t('items') . " WHERE campaign_id=%d AND status='queued')", $id));
        }
        $c = self::get($id);
        MWC_Store::log('campaign', ($st === 'paused' ? 'إيقاف مؤقت' : 'إنهاء') . ' الحملة «' . ($c['name'] ?? $id) . '»');
        return $c;
    }

    static function items($id, $status = '', $limit = 300) {
        global $wpdb;
        $sql = "SELECT * FROM " . MWC_Store::t('items') . " WHERE campaign_id=%d" . ($status ? " AND status=%s" : "") . " ORDER BY sent_at DESC, id ASC LIMIT " . (int) $limit;
        return $wpdb->get_results($status ? $wpdb->prepare($sql, $id, $status) : $wpdb->prepare($sql, $id), ARRAY_A);
    }

    /** {أهلاً|مرحبا|السلام عليكم} → اختيار عشوائي، و {name} بالاسم */
    static function spin($t, $vars) {
        $t = preg_replace_callback('/\{([^{}]*\|[^{}]*)\}/u', function ($m) { $o = explode('|', $m[1]); return $o[array_rand($o)]; }, $t);
        return preg_replace_callback('/\{(\w+)\}/u', function ($m) use ($vars) { return $vars[$m[1]] ?? ''; }, $t);
    }

    /* ---------------- المحرّك: بيتنادى كل دقيقة ---------------- */
    static function tick() {
        global $wpdb;
        $lock = get_transient('mwc_camp_lock');
        if ($lock && time() - (int) $lock < 50) return ['skipped' => 'locked'];
        set_transient('mwc_camp_lock', time(), 120);
        $done = 0;
        try {
            $hour = (int) MWC_Store::riyadh('G');
            $today = MWC_Store::riyadh('Y-m-d');
            $state = get_option('mwc_send_state'); if (!is_array($state)) $state = [];
            $camps = $wpdb->get_results("SELECT * FROM " . MWC_Store::t('campaigns') . " WHERE status='running'", ARRAY_A);
            $deadline = time() + 40;
            foreach ($camps as $row) {
                $c = self::out($row); $d = $c['data'];
                if ($hour < (int) $d['window_start'] || $hour >= (int) $d['window_end']) continue;
                foreach ($d['accounts'] as $acc_id) {
                    if (time() > $deadline) break 2;
                    $st = $state[$acc_id] ?? ['next' => 0, 'day' => '', 'n' => 0];
                    if ($st['day'] !== $today) { $st['day'] = $today; $st['n'] = 0; }
                    if ($st['next'] > time() || $st['n'] >= (int) $d['daily_cap']) { $state[$acc_id] = $st; continue; }
                    $acc = MWC_Store::account($acc_id);
                    if (!$acc || !$acc['active']) continue;
                    $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . MWC_Store::t('items') . " WHERE campaign_id=%d AND status='queued' AND account_id=%d ORDER BY id LIMIT 1", $c['id'], $acc_id), ARRAY_A)
                        ?: $wpdb->get_row($wpdb->prepare("SELECT * FROM " . MWC_Store::t('items') . " WHERE campaign_id=%d AND status='queued' ORDER BY id LIMIT 1", $c['id']), ARRAY_A);
                    if (!$item) break;
                    $wpdb->update(MWC_Store::t('items'), ['status' => 'sending', 'account_id' => $acc_id], ['id' => $item['id']]);
                    $r = self::send_one($c, $acc, $item);
                    $ok = $r === true;
                    $wpdb->update(MWC_Store::t('items'), ['status' => $ok ? 'sent' : 'failed', 'error' => $ok ? '' : mb_substr((string) $r, 0, 250), 'sent_at' => time()], ['id' => $item['id']]);
                    $wpdb->query($wpdb->prepare("UPDATE " . MWC_Store::t('campaigns') . " SET " . ($ok ? 'sent=sent+1' : 'failed=failed+1') . " WHERE id=%d", $c['id']));
                    $leadSt = $ok ? 'sent' : (preg_match('/exist|not.*whatsapp|not registered|غير مسجل|131026/i', (string) $r) ? 'invalid' : 'new');
                    $wpdb->query($wpdb->prepare("UPDATE " . MWC_Store::t('leads') . " SET status=IF(status IN ('replied','customer','blocked'), status, %s), sent_count=sent_count+%d, last_sent=%d, updated=%d WHERE id=%d",
                        $leadSt, $ok ? 1 : 0, $ok ? time() : 0, time(), $item['lead_id']));
                    $st['n']++; $st['next'] = time() + random_int((int) $d['delay_min'], max((int) $d['delay_min'], (int) $d['delay_max']));
                    $state[$acc_id] = $st; $done++;
                }
                $left = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . MWC_Store::t('items') . " WHERE campaign_id=%d AND status IN ('queued','sending')", $c['id']));
                if (!$left) { $wpdb->update(MWC_Store::t('campaigns'), ['status' => 'done', 'finished' => time()], ['id' => $c['id']]); MWC_Store::log('campaign', 'الحملة «' . $c['name'] . '» خلصت', 'النظام'); }
            }
            update_option('mwc_send_state', $state, false);
        } finally { delete_transient('mwc_camp_lock'); }
        return ['sent' => $done];
    }

    /** @return true|string */
    static function send_one($c, $acc, $item) {
        $d = $c['data'];
        $cid = MWC_Store::contact_upsert($item['phone'], ['name' => $item['name'], 'source' => 'حملة: ' . $c['name'], '_keep_source' => 1, '_auto' => 1]);
        if (!$cid) return 'رقم غير صالح';
        $chat_id = MWC_Store::chat_get_or_create($acc['id'], $cid);
        $vars = ['name' => $item['name'] ?: '', 'phone' => $item['phone'], 'brand' => $acc['config']['brand'] ?? (MWC_Store::config()['vars']['brand'] ?? '')];
        $sender = 'حملة: ' . $c['name'];
        if ($acc['provider'] === 'meta') {
            if (empty($d['template']['name'])) return 'الرقم الرسمي (Meta) محتاج قالب معتمد للحملات';
            $params = array_map(function ($p) use ($vars) { return self::spin($p, $vars); }, (array) ($d['template']['params'] ?? []));
            $m = MWC_Rest::do_send($chat_id, ['type' => 'template', 'body' => 'قالب: ' . $d['template']['name'], 'template' => ['name' => $d['template']['name'], 'lang' => $d['template']['lang'] ?? 'ar', 'params' => $params]], $sender);
            return is_wp_error($m) ? $m->get_error_message() : ($m['status'] === 'failed' ? $m['error'] : true);
        }
        $parts = [];
        if ($d['texts']) $parts[] = ['type' => 'text', 'body' => self::spin($d['texts'][array_rand($d['texts'])], $vars)];
        foreach ((array) $d['attachments'] as $a) $parts[] = ['type' => $a['type'], 'media_url' => $a['url'], 'mime' => $a['mime'] ?? '', 'file_name' => $a['name'] ?? '', 'duration' => (int) ($a['duration'] ?? 0), 'body' => self::spin($a['caption'] ?? '', $vars)];
        foreach ($parts as $i => $p) {
            $m = MWC_Rest::do_send($chat_id, $p, $sender);
            if (is_wp_error($m)) return $m->get_error_message();
            if ($m['status'] === 'failed') return $m['error'] ?: 'فشل';
            if ($i < count($parts) - 1) sleep(2);
        }
        return true;
    }
}
