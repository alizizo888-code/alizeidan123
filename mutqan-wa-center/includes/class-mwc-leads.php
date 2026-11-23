<?php
if (!defined('ABSPATH')) exit;

/** قاعدة العملاء المحتملين: استيراد/تصدير/فلترة/تصنيف + معرفة الدولة ونوع الرقم + مصدر العميل */
class MWC_Leads {
    const COUNTRIES = [
        '966' => 'السعودية', '971' => 'الإمارات', '965' => 'الكويت', '974' => 'قطر', '973' => 'البحرين', '968' => 'عُمان',
        '962' => 'الأردن', '963' => 'سوريا', '961' => 'لبنان', '964' => 'العراق', '967' => 'اليمن', '970' => 'فلسطين',
        '20' => 'مصر', '249' => 'السودان', '212' => 'المغرب', '213' => 'الجزائر', '216' => 'تونس', '218' => 'ليبيا',
        '92' => 'باكستان', '91' => 'الهند', '880' => 'بنجلاديش', '977' => 'نيبال', '94' => 'سريلانكا', '63' => 'الفلبين',
        '62' => 'إندونيسيا', '90' => 'تركيا', '251' => 'إثيوبيا', '254' => 'كينيا', '234' => 'نيجيريا', '44' => 'بريطانيا', '1' => 'أمريكا/كندا',
    ];
    const STATUSES = ['new' => 'جديد', 'queued' => 'في حملة', 'sent' => 'اتبعتله', 'replied' => 'رد', 'customer' => 'عميل', 'blocked' => 'ممنوع / طلب الإيقاف', 'invalid' => 'مش على واتساب'];
    const OPTOUT = ['الغاء', 'إلغاء', 'ايقاف', 'إيقاف', 'stop', 'لا ترسل', 'لاترسل', 'ازعاج', 'إزعاج', 'بلوك', 'حظر', 'unsubscribe'];

    /** توحيد الرقم: الافتراضي السعودية (05.. / 01.. / 9200..) */
    static function norm($p) {
        $raw = trim((string) $p);
        $d = preg_replace('/\D+/', '', strtr($raw, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
        if ($d === '') return '';
        if (strpos($d, '00') === 0) $d = substr($d, 2);
        elseif ($d[0] === '0' && strlen($d) === 10) $d = '966' . substr($d, 1);            // 05xxxxxxxx / 01xxxxxxxx
        elseif ($d[0] === '5' && strlen($d) === 9) $d = '966' . $d;                          // 5xxxxxxxx
        elseif (preg_match('/^(9200|800)\d{5,7}$/', $d) && strpos($raw, '+') === false) $d = '966' . $d;
        return (strlen($d) >= 8 && strlen($d) <= 15) ? $d : '';
    }

    static function country($d) {
        foreach ([3, 2, 1] as $n) { $k = substr($d, 0, $n); if (isset(self::COUNTRIES[$k])) return self::COUNTRIES[$k]; }
        return '';
    }

    /** نوع الرقم: جوال (ينفع واتساب) / ثابت / موحد */
    static function kind($d) {
        if (preg_match('/^9665\d{8}$/', $d)) return 'mobile';
        if (preg_match('/^966(9200|800)/', $d)) return 'unified';
        if (preg_match('/^9661\d{8}$/', $d)) return 'landline';
        if (strpos($d, '966') === 0) return 'other';
        return 'intl';
    }
    static function kind_label($k) { return ['mobile' => 'جوال', 'landline' => 'ثابت', 'unified' => 'موحّد', 'intl' => 'دولي', 'other' => 'غير معروف'][$k] ?? $k; }

    static function out($r) {
        $r['id'] = (int) $r['id']; $r['sent_count'] = (int) $r['sent_count']; $r['last_sent'] = (int) $r['last_sent'];
        $r['extra'] = json_decode($r['extra'] ?: '{}', true) ?: [];
        return $r;
    }

    static function where($f, &$p) {
        global $wpdb;
        $w = ['1=1'];
        foreach (['status', 'city', 'country', 'kind', 'category'] as $k) {
            if (!empty($f[$k])) {
                $vals = (array) $f[$k];
                $w[] = "$k IN (" . implode(',', array_fill(0, count($vals), '%s')) . ')';
                foreach ($vals as $v) $p[] = (string) $v;
            }
        }
        if (!empty($f['source'])) { $w[] = 'source LIKE %s'; $p[] = '%' . $wpdb->esc_like($f['source']) . '%'; }
        if (!empty($f['tag'])) { $w[] = "CONCAT(',', tags, ',') LIKE %s"; $p[] = '%,' . $wpdb->esc_like(trim($f['tag'])) . ',%'; }
        if (!empty($f['q'])) { $l = '%' . $wpdb->esc_like($f['q']) . '%'; $w[] = '(name LIKE %s OR phone LIKE %s OR category LIKE %s OR notes LIKE %s)'; array_push($p, $l, $l, $l, $l); }
        if (!empty($f['ids'])) $w[] = 'id IN (' . implode(',', array_map('intval', (array) $f['ids'])) . ')';
        if (!empty($f['not_contacted_days'])) { $w[] = 'last_sent < %d'; $p[] = time() - (int) $f['not_contacted_days'] * DAY_IN_SECONDS; }
        return implode(' AND ', $w);
    }

    static function query($f, $limit = 100, $offset = 0) {
        global $wpdb; $t = MWC_Store::t('leads'); $p = [];
        $w = self::where($f, $p);
        $total = (int) $wpdb->get_var($p ? $wpdb->prepare("SELECT COUNT(*) FROM $t WHERE $w", $p) : "SELECT COUNT(*) FROM $t WHERE $w");
        $sql = "SELECT * FROM $t WHERE $w ORDER BY id DESC LIMIT " . (int) $limit . " OFFSET " . (int) $offset;
        $rows = $wpdb->get_results($p ? $wpdb->prepare($sql, $p) : $sql, ARRAY_A);
        return ['total' => $total, 'rows' => array_map([__CLASS__, 'out'], $rows)];
    }

    static function facets() {
        global $wpdb; $t = MWC_Store::t('leads');
        $f = [];
        foreach (['status', 'city', 'country', 'kind', 'category'] as $k) {
            $f[$k] = $wpdb->get_results("SELECT $k AS v, COUNT(*) AS n FROM $t WHERE $k<>'' GROUP BY $k ORDER BY n DESC LIMIT 60", ARRAY_A);
        }
        $tags = [];
        foreach ($wpdb->get_col("SELECT tags FROM $t WHERE tags<>'' LIMIT 20000") as $s) foreach (explode(',', $s) as $tg) { $tg = trim($tg); if ($tg !== '') $tags[$tg] = ($tags[$tg] ?? 0) + 1; }
        arsort($tags); $f['tags'] = array_slice(array_map(function ($k, $v) { return ['v' => $k, 'n' => $v]; }, array_keys($tags), $tags), 0, 60);
        $f['total'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
        return $f;
    }

    /** استيراد صفوف (من Excel/CSV أو الخرائط أو أي سكرابر خارجي) — بيدمج المكرر */
    static function import($rows, $defaults = []) {
        global $wpdb; $t = MWC_Store::t('leads');
        $res = ['new' => 0, 'updated' => 0, 'invalid' => 0, 'duplicate_in_file' => 0, 'total' => count($rows)];
        $seen = []; $now = time();
        foreach ($rows as $r) {
            $phone = self::norm($r['phone'] ?? '');
            if (!$phone) { $res['invalid']++; continue; }
            if (isset($seen[$phone])) { $res['duplicate_in_file']++; continue; }
            $seen[$phone] = 1;
            $tags = array_filter(array_map('trim', array_merge(explode(',', (string) ($r['tags'] ?? '')), explode(',', (string) ($defaults['tags'] ?? '')))));
            $extra = array_filter([
                'address' => $r['address'] ?? '', 'website' => $r['website'] ?? '', 'maps' => $r['maps'] ?? ($r['link'] ?? ''),
                'rating' => $r['rating'] ?? '', 'reviews' => $r['reviews'] ?? '', 'lat' => $r['lat'] ?? '', 'lng' => $r['lng'] ?? '', 'email' => $r['email'] ?? '',
            ], function ($v) { return $v !== '' && $v !== null; });
            $data = [
                'name' => mb_substr(sanitize_text_field($r['name'] ?? ''), 0, 200),
                'city' => mb_substr(sanitize_text_field($r['city'] ?? ($defaults['city'] ?? '')), 0, 80),
                'category' => mb_substr(sanitize_text_field($r['category'] ?? ($defaults['category'] ?? '')), 0, 160),
                'source' => mb_substr(sanitize_text_field($r['source'] ?? ($defaults['source'] ?? 'استيراد')), 0, 200),
                'country' => sanitize_text_field($r['country'] ?? '') ?: self::country($phone),
                'kind' => self::kind($phone),
            ];
            $old = $wpdb->get_row($wpdb->prepare("SELECT id, tags, extra, name, city, category, notes FROM $t WHERE phone=%s", $phone), ARRAY_A);
            if ($old) {
                $upd = ['updated' => $now];
                foreach (['name', 'city', 'category'] as $k) if ($data[$k] !== '' && $old[$k] === '') $upd[$k] = $data[$k];
                $allTags = array_unique(array_filter(array_merge(explode(',', $old['tags']), $tags)));
                $upd['tags'] = implode(',', $allTags);
                $upd['extra'] = wp_json_encode(array_merge(json_decode($old['extra'] ?: '{}', true) ?: [], $extra), JSON_UNESCAPED_UNICODE);
                if (!empty($r['notes'])) $upd['notes'] = trim(($old['notes'] ?? '') . "\n" . sanitize_textarea_field($r['notes']));
                $wpdb->update($t, $upd, ['id' => $old['id']]);
                $res['updated']++;
            } else {
                $wpdb->insert($t, $data + ['phone' => $phone, 'tags' => implode(',', array_unique($tags)), 'status' => 'new',
                    'notes' => sanitize_textarea_field($r['notes'] ?? ''), 'extra' => wp_json_encode($extra, JSON_UNESCAPED_UNICODE), 'created' => $now, 'updated' => $now]);
                $res['new']++;
            }
        }
        MWC_Store::log('import', sprintf('استيراد %d صف (%s): جديد %d، محدّث %d، أرقام غلط %d، مكرر في الملف %d',
            $res['total'], $defaults['source'] ?? 'ملف', $res['new'], $res['updated'], $res['invalid'], $res['duplicate_in_file']));
        return $res;
    }

    static function bulk($f, $action, $value = '') {
        global $wpdb; $t = MWC_Store::t('leads'); $p = [];
        $w = self::where($f, $p);
        $ids = $wpdb->get_col($p ? $wpdb->prepare("SELECT id FROM $t WHERE $w", $p) : "SELECT id FROM $t WHERE $w");
        if (!$ids) return 0;
        $in = implode(',', array_map('intval', $ids));
        $value = sanitize_text_field($value);
        switch ($action) {
            case 'status': if (isset(self::STATUSES[$value])) $wpdb->query($wpdb->prepare("UPDATE $t SET status=%s, updated=%d WHERE id IN ($in)", $value, time())); break;
            case 'city': $wpdb->query($wpdb->prepare("UPDATE $t SET city=%s WHERE id IN ($in)", $value)); break;
            case 'category': $wpdb->query($wpdb->prepare("UPDATE $t SET category=%s WHERE id IN ($in)", $value)); break;
            case 'tag':
                foreach ($wpdb->get_results("SELECT id, tags FROM $t WHERE id IN ($in)", ARRAY_A) as $r) {
                    $tg = array_unique(array_filter(array_merge(explode(',', $r['tags']), [$value])));
                    $wpdb->update($t, ['tags' => implode(',', $tg)], ['id' => $r['id']]);
                } break;
            case 'untag':
                foreach ($wpdb->get_results("SELECT id, tags FROM $t WHERE id IN ($in)", ARRAY_A) as $r) {
                    $tg = array_diff(explode(',', $r['tags']), [$value]);
                    $wpdb->update($t, ['tags' => implode(',', $tg)], ['id' => $r['id']]);
                } break;
            case 'delete': $wpdb->query("DELETE FROM $t WHERE id IN ($in)"); break;
            default: return 0;
        }
        MWC_Store::log('leads', 'تعديل جماعي (' . $action . ($value !== '' ? ': ' . $value : '') . ') على ' . count($ids) . ' عميل');
        return count($ids);
    }

    static function save($d) {
        global $wpdb; $t = MWC_Store::t('leads');
        $id = (int) ($d['id'] ?? 0);
        if (!$id) { $r = self::import([$d], ['source' => $d['source'] ?? 'إضافة يدوية']); return $r; }
        $f = [];
        foreach (['name', 'city', 'country', 'category', 'source', 'tags'] as $k) if (isset($d[$k])) $f[$k] = sanitize_text_field($d[$k]);
        if (isset($d['notes'])) $f['notes'] = sanitize_textarea_field($d['notes']);
        if (isset($d['status']) && isset(self::STATUSES[$d['status']])) $f['status'] = $d['status'];
        $f['updated'] = time();
        $wpdb->update($t, $f, ['id' => $id]);
        return self::out($wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id), ARRAY_A));
    }

    static function by_phone($phone) {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . MWC_Store::t('leads') . " WHERE phone=%s", $phone), ARRAY_A);
        return $r ? self::out($r) : null;
    }

    /**
     * «شيت عملائنا» (صاحب الشغل 2026-11-24): كل واحد كلّمنا على أي واتساب من واتساباتنا بيدخل قاعدة العملاء لوحده،
     * تصنيفه «عميل واتساب» ومصدره الواتساب اللي كلّمه — فيطلع شيت لوحده جنب شيت مكة وشيت جدة. أرقامنا والجروبات لأ.
     */
    const OWN = 'عميل واتساب';
    static function from_whatsapp($wa_id, $name = '', $label = '') {
        global $wpdb;
        $phone = self::norm($wa_id);
        if (!$phone || strlen(preg_replace('/\D/', '', (string) $wa_id)) > 15) return false;
        $ours = $wpdb->get_col("SELECT phone FROM " . MWC_Store::t('accounts'));
        foreach ($ours as $o) { $o = self::norm($o); if ($o && $o === $phone) return false; }
        $t = MWC_Store::t('leads');
        $old = $wpdb->get_row($wpdb->prepare("SELECT id, tags, category FROM $t WHERE phone=%s", $phone), ARRAY_A);
        $now = time();
        if ($old) {
            if (in_array('واتساب', explode(',', (string) $old['tags']), true)) return true;
            $tags = array_unique(array_filter(array_merge(explode(',', (string) $old['tags']), ['واتساب'])));
            $wpdb->update($t, ['tags' => implode(',', $tags), 'updated' => $now], ['id' => $old['id']]);
            return true;
        }
        $wpdb->insert($t, ['phone' => $phone, 'name' => mb_substr(sanitize_text_field($name), 0, 200), 'city' => '', 'category' => self::OWN,
            'source' => mb_substr('واتساب — ' . ($label ?: 'رقمنا'), 0, 200), 'country' => self::country($phone), 'kind' => self::kind($phone),
            'tags' => 'عملاء,واتساب', 'status' => 'replied', 'notes' => '', 'extra' => '{}', 'created' => $now, 'updated' => $now]);
        return true;
    }

    /** مرة واحدة على دفعات: كل اللي كلّمونا قبل كده يدخلوا الشيت (500 في كل نبضة لحد ما يخلصوا). */
    static function backfill_whatsapp() {
        global $wpdb;
        $at = (int) get_option('mwc_wa_backfill', 0);
        if ($at < 0) return 0;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT k.id, k.wa_id, k.name, k.push_name, MIN(a.label) label FROM " . MWC_Store::t('contacts') . " k
            JOIN " . MWC_Store::t('chats') . " c ON c.contact_id=k.id LEFT JOIN " . MWC_Store::t('accounts') . " a ON a.id=c.account_id
            WHERE k.id>%d AND c.last_in_ts>0 GROUP BY k.id ORDER BY k.id LIMIT 500", $at), ARRAY_A);
        if (!$rows) { update_option('mwc_wa_backfill', -1, false); return 0; }
        $n = 0;
        foreach ($rows as $r) { if (self::from_whatsapp($r['wa_id'], $r['name'] ?: $r['push_name'], $r['label'] ?? '')) $n++; $at = (int) $r['id']; }
        update_option('mwc_wa_backfill', $at, false);
        return $n;
    }

    /** لما عميل يرد: نحدّث حالته في قاعدة العملاء والحملة، ونوقف الإرسال لو طلب الإيقاف */
    static function on_incoming($wa_id, $text) {
        global $wpdb;
        $lead = self::by_phone($wa_id);
        $stop = false;
        $norm = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $text)));
        if ($norm !== '' && mb_strlen($norm) <= 40) foreach (self::OPTOUT as $w) if (mb_strpos($norm, $w) !== false) $stop = true;
        if ($lead) {
            $st = $stop ? 'blocked' : (in_array($lead['status'], ['sent', 'queued', 'new'], true) ? 'replied' : $lead['status']);
            if ($st !== $lead['status']) $wpdb->update(MWC_Store::t('leads'), ['status' => $st, 'updated' => time()], ['id' => $lead['id']]);
        }
        $items = $wpdb->get_results($wpdb->prepare("SELECT id, campaign_id, status FROM " . MWC_Store::t('items') . " WHERE phone=%s AND status IN ('sent','queued')", $wa_id), ARRAY_A);
        foreach ($items as $it) {
            if ($it['status'] === 'sent') {
                $wpdb->update(MWC_Store::t('items'), ['status' => 'replied'], ['id' => $it['id']]);
                $wpdb->query($wpdb->prepare("UPDATE " . MWC_Store::t('campaigns') . " SET replied=replied+1 WHERE id=%d", $it['campaign_id']));
            } elseif ($stop) $wpdb->update(MWC_Store::t('items'), ['status' => 'skipped', 'error' => 'طلب الإيقاف'], ['id' => $it['id']]);
        }
        if ($stop) MWC_Store::log('optout', 'العميل ' . $wa_id . ' طلب إيقاف الرسايل — اتمنع من الحملات', 'النظام');
    }

    /** مصدر العميل من أول رسالة: كود المقالة (#oxygen/a26) أو رابط أو «بخصوص:» أو «المصدر:» */
    static function detect_source($text) {
        $text = (string) $text;
        if (preg_match('~#([a-z0-9_-]+)/([a-z])(\d+)~i', $text, $m)) {
            $brand = strtolower($m[1]); $id = (int) $m[3];
            $src = ['source' => 'كود ' . $m[0], 'source_url' => ''];
            $site = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
            $brandSite = ['oxygen' => 'oxygen11.com', 'mutqan' => 'mutqan.oxygen11.com'];
            if (($brandSite[$brand] ?? '') === $site && function_exists('get_post') && ($post = get_post($id)) && $post->post_status === 'publish') {
                $src['source'] = ($brand === 'oxygen' ? 'أكسجين' : 'متقن') . ' — مقالة: ' . get_the_title($post);
                $src['source_url'] = get_permalink($post);
            } elseif (isset($brandSite[$brand])) {
                $src['source'] = ($brand === 'oxygen' ? 'أكسجين' : ($brand === 'mutqan' ? 'متقن' : $brand)) . ' — مقالة رقم ' . $id;
                $src['source_url'] = 'https://' . $brandSite[$brand] . '/?p=' . $id;
            } else { $src['source'] = $brand . ' — صفحة ' . $id; }
            return $src;
        }
        if (preg_match('~(?:المصدر|جاي من|من موقع|source)\s*[:：]\s*([^\n]{2,120})~u', $text, $m)) return ['source' => trim($m[1]), 'source_url' => ''];
        if (preg_match('~بخصوص\s*[:：]\s*([^\n]{2,140})~u', $text, $m)) {
            $u = ''; if (preg_match('~https?://\S+~', $text, $mu)) $u = $mu[0];
            return ['source' => 'بخصوص: ' . trim($m[1]), 'source_url' => $u];
        }
        if (preg_match('~https?://([a-z0-9.-]*(?:oxygen11\.com|blogspot\.com|mutqan[a-z0-9.-]*))(\S*)~i', $text, $m)) return ['source' => $m[1], 'source_url' => $m[0]];
        return null;
    }
}
