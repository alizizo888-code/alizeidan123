<?php
/**
 * «سلة المحذوفات» (owner order 2026-11-22): anything important that is deleted — an order, a technician, a customer,
 * a warranty, an invoice, a price list … — is first copied here whole (the stored row with its owner and visibility),
 * then removed from its place, so it disappears from every screen at once. From the owner's delete controls
 * («سجل الحذف») every item has «استرجاع»: the row goes back exactly as it was, same id.
 * Kept 90 days. Collections that are traffic, not records (calls, pushes, notifications, chat logs …) are not kept.
 */
if (!defined('ABSPATH')) { exit; }

final class Mutqan_Trash {
    const DBV = 'mutqan_trash_db';
    const KEEP = ['orders', 'tech_profiles', 'customers', 'customer_profiles', 'warranties', 'warranty_claims', 'invoices', 'quotes', 'settlements', 'tech_wallet', 'shops', 'stock_items', 'payment_methods', 'pay_links', 'expenses', 'client_pins', 'vault_files', 'tech_applications', 'accounts', 'order_msgs', 'payout_requests', 'quote_snippets', 'services', 'specialties', 'pricing_internal'];

    public static function table() { global $wpdb; return $wpdb->prefix . 'mutqan_trash'; }

    private static function install() {
        if (get_option(self::DBV) === '1') { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table(); $cs = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $t (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  coll varchar(64) NOT NULL,
  doc_id varchar(190) NOT NULL,
  label varchar(255) NOT NULL DEFAULT '',
  row_json longtext NOT NULL,
  deleted_by varchar(120) NOT NULL DEFAULT '',
  deleted_at datetime NOT NULL,
  restored_at datetime NULL,
  PRIMARY KEY  (id),
  KEY coll (coll, doc_id),
  KEY deleted_at (deleted_at)
) $cs;");
        update_option(self::DBV, '1', false);
    }

    private static function label($coll, $d, $id) {
        $n = (string) ($d['number'] ?? '');
        foreach (['clientName', 'displayName', 'name', 'title', 'label', 'techName'] as $k) { if (!empty($d[$k]) && is_string($d[$k])) { $nm = $d[$k]; break; } }
        $ph = (string) ($d['clientPhone'] ?? ($d['phone'] ?? ''));
        return mb_substr(trim(($n !== '' ? '#' . $n . ' ' : '') . ($nm ?? '') . ' ' . $ph) ?: $id, 0, 250);
    }

    /** Called by Mutqan_Store::delete() just before the row goes. */
    public static function keep($coll, $row, $p) {
        if (!in_array($coll, self::KEEP, true) || !$row) { return; }
        try {
            self::install();
            global $wpdb;
            $d = json_decode((string) ($row['data'] ?? ''), true) ?: [];
            $wpdb->insert(self::table(), ['coll' => $coll, 'doc_id' => (string) $row['doc_id'], 'label' => self::label($coll, $d, (string) $row['doc_id']), 'row_json' => wp_json_encode($row), 'deleted_by' => mb_substr((string) ($p['name'] ?? ($p['id'] ?? '')), 0, 120), 'deleted_at' => gmdate('Y-m-d H:i:s')]);
            if (wp_rand(1, 50) === 1) { $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE deleted_at < %s', gmdate('Y-m-d H:i:s', time() - 90 * 86400))); }
        } catch (Throwable $e) { error_log('[mutqan] trash keep ' . $e->getMessage()); }
    }

    public static function names() {
        return ['orders' => 'طلب', 'tech_profiles' => 'فني', 'customers' => 'عميل', 'customer_profiles' => 'ملف عميل', 'warranties' => 'ضمان', 'warranty_claims' => 'ملف ضمان', 'invoices' => 'فاتورة', 'quotes' => 'عرض سعر', 'settlements' => 'حركة مالية', 'tech_wallet' => 'إعدادات فني', 'shops' => 'محل', 'stock_items' => 'صنف مخزن', 'payment_methods' => 'طريقة دفع', 'pay_links' => 'رابط دفع', 'expenses' => 'مصروف', 'client_pins' => 'دبوس', 'vault_files' => 'ملف', 'tech_applications' => 'طلب توظيف', 'accounts' => 'حساب', 'order_msgs' => 'رسالة طلب', 'payout_requests' => 'طلب تحويل', 'quote_snippets' => 'نص جاهز', 'services' => 'خدمة', 'specialties' => 'تخصص', 'pricing_internal' => 'تسعير'];
    }

    public static function list($q = '') {
        self::install();
        global $wpdb; $t = self::table();
        $rows = $wpdb->get_results("SELECT id, coll, doc_id, label, deleted_by, deleted_at, restored_at FROM $t WHERE restored_at IS NULL AND coll <> 'order_msgs' ORDER BY id DESC LIMIT 400", ARRAY_A) ?: [];
        $n = self::names();
        return array_map(function ($r) use ($n) { return ['id' => (int) $r['id'], 'coll' => $r['coll'], 'what' => $n[$r['coll']] ?? $r['coll'], 'docId' => $r['doc_id'], 'label' => $r['label'], 'by' => $r['deleted_by'], 'at' => str_replace(' ', 'T', $r['deleted_at']) . 'Z']; }, $rows);
    }

    public static function restore($id, $p) {
        self::install();
        global $wpdb; $t = self::table();
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d AND restored_at IS NULL", (int) $id), ARRAY_A);
        if (!$r) { throw new Mutqan_Error('not_found', 'العنصر مش موجود في السلة.', 404); }
        $row = json_decode((string) $r['row_json'], true);
        if (!is_array($row) || empty($row['doc_id'])) { throw new Mutqan_Error('bad_state', 'النسخة تالفة.', 500); }
        $docs = Mutqan_Store::table('docs');
        if (Mutqan_Store::peek_doc($r['coll'], $row['doc_id'])) { throw new Mutqan_Error('exists', 'فيه عنصر بنفس الرقم موجود دلوقتي — مش هينفع يتبدّل.', 409); }
        unset($row['pk']);
        $row['expires_at'] = null;
        $row['updated_at'] = gmdate('Y-m-d H:i:s');
        $cols = $wpdb->get_col("SHOW COLUMNS FROM $docs") ?: array_keys($row);
        $row = array_intersect_key($row, array_flip($cols));
        if (!$wpdb->insert($docs, $row)) { throw new Mutqan_Error('server_error', 'تعذّر الاسترجاع.', 500); }
        $wpdb->update($t, ['restored_at' => gmdate('Y-m-d H:i:s')], ['id' => (int) $id]);
        Mutqan_Store::touch($r['coll']);
        // an order comes back with its conversation and warranty files
        if ($r['coll'] === 'orders') {
            $like = '%' . $wpdb->esc_like('\\"orderId\\":\\"' . $row['doc_id'] . '\\"') . '%';
            foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM $t WHERE restored_at IS NULL AND coll IN ('order_msgs','warranty_claims') AND row_json LIKE %s", $like)) ?: [] as $cid) {
                try { self::restore((int) $cid, $p); } catch (Throwable $e) { /* already back */ }
            }
        }
        return ['ok' => true, 'coll' => $r['coll'], 'id' => $row['doc_id']];
    }
}
