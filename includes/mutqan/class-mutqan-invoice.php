<?php
/**
 * Invoices (owner, 2026-11-22): a plain invoice and a tax invoice, numbered in sequence, kept for good (voided, never
 * deleted), with the company's data as it was the day it was issued. The amounts and the ZATCA QR are computed here —
 * the screen only shows them. Settings (company, VAT number, CR, items catalogue with codes, next numbers, templates)
 * are the owner's.
 */
if (!defined('ABSPATH')) { exit; }

final class Mutqan_Invoice {
    const COLL = 'invoices';
    const OPT = 'mutqan_invoice_cfg';
    const TYPES = ['normal' => 'فاتورة', 'tax' => 'فاتورة ضريبية'];
    const TEMPLATES = ['official' => 'الأزرق الرسمي', 'classic' => 'السند الكلاسيكي', 'modern' => 'الحديث', 'thermal' => 'الحراري 80مم'];
    const PAY = ['cash' => 'نقدي', 'transfer' => 'تحويل بنكي', 'card' => 'شبكة / مدى', 'cheque' => 'شيك', 'credit' => 'آجل'];

    /** From the VAT registration certificate and the templates he sent. */
    public static function defaults() {
        return [
            'nameAr' => 'مؤسسة فيصل حسين بن سعيد كليب للمقاولات العامة',
            'nameEn' => 'Faisal Hussein Saeed Bin Kulaib General Contracting Est',
            'brand' => 'OXYGEN', 'cityLine' => 'مكة المكرمة — العزيزية',
            'vatNo' => '310798423700003', 'crNo' => '4031244695',
            'phones' => ['0549423050', '0549423050'], 'address' => 'المملكة العربية السعودية – مكة المكرمة',
            'vatRate' => 15, 'inclusive' => true,
            'next' => ['normal' => 1001, 'tax' => 1],
            'template' => ['normal' => 'classic', 'tax' => 'official'],
            'logo' => '', 'stamp' => '', 'signature' => '',   // empty logo/stamp = the bundled ones; no signature until he uploads one
            'signer' => '', 'signDefault' => false,
            'note' => 'أي فاتورة لا تحمل ختم المؤسسة تعتبر لاغية',
            'items' => [
                ['code' => '101', 'name' => 'غسيل مكيف سبليت', 'unit' => 'عدد', 'price' => 0],
                ['code' => '102', 'name' => 'غسيل مكيف شباك', 'unit' => 'عدد', 'price' => 0],
                ['code' => '201', 'name' => 'صيانة وإصلاح مكيف', 'unit' => 'عدد', 'price' => 0],
                ['code' => '202', 'name' => 'شحن فريون', 'unit' => 'عدد', 'price' => 0],
                ['code' => '301', 'name' => 'تركيب مكيف سبليت', 'unit' => 'عدد', 'price' => 0],
                ['code' => '302', 'name' => 'فك وتركيب مكيف', 'unit' => 'عدد', 'price' => 0],
                ['code' => '401', 'name' => 'صيانة غرفة تبريد / تجميد', 'unit' => 'خدمة', 'price' => 0],
            ],
        ];
    }

    public static function cfg() {
        $c = get_option(self::OPT, []);
        $c = array_merge(self::defaults(), is_array($c) ? $c : []);
        $c['next'] = array_merge(self::defaults()['next'], (array) $c['next']);
        $c['template'] = array_merge(self::defaults()['template'], (array) $c['template']);
        return $c;
    }

    private static function s($v, $n = 160) { return mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $v)), 0, $n); }
    private static function digits($v, $n = 20) { return substr(preg_replace('/\D/', '', strtr((string) $v, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'])), 0, $n); }

    /** The owner's settings. VAT 15 digits starting and ending with 3; CR 10 digits. */
    public static function cfg_set($in, $p) {
        if (($p['role'] ?? '') !== 'owner') { throw new Mutqan_Error('forbidden', 'إعدادات الفواتير للمالك بس.', 403); }
        $c = self::cfg();
        foreach (['nameAr' => 160, 'nameEn' => 160, 'brand' => 40, 'cityLine' => 120, 'address' => 160, 'note' => 200] as $k => $n) { if (isset($in[$k])) { $c[$k] = self::s($in[$k], $n); } }
        if (isset($in['vatNo'])) {
            $v = self::digits($in['vatNo'], 15);
            if ($v !== '' && !preg_match('/^3\d{13}3$/', $v)) { throw new Mutqan_Error('bad_input', 'الرقم الضريبي لازم 15 رقم، يبدأ وينتهي بـ 3.', 400); }
            $c['vatNo'] = $v;
        }
        if (isset($in['crNo'])) { $c['crNo'] = self::digits($in['crNo'], 10); }
        if (isset($in['phones'])) { $c['phones'] = array_values(array_filter(array_map(function ($x) { return self::digits($x, 15); }, (array) $in['phones']))); }
        if (isset($in['vatRate']) && is_numeric($in['vatRate'])) { $c['vatRate'] = max(0, min(30, (float) $in['vatRate'])); }
        if (isset($in['inclusive'])) { $c['inclusive'] = (bool) $in['inclusive']; }
        foreach (['normal', 'tax'] as $t) {
            if (isset($in['next'][$t]) && is_numeric($in['next'][$t])) { $c['next'][$t] = max(1, (int) $in['next'][$t]); }
            if (isset($in['template'][$t]) && isset(self::TEMPLATES[$in['template'][$t]])) { $c['template'][$t] = (string) $in['template'][$t]; }
        }
        foreach (['logo', 'stamp', 'signature'] as $k) { if (isset($in[$k])) { $u = esc_url_raw((string) $in[$k]); $c[$k] = $u; } }
        if (isset($in['signer'])) { $c['signer'] = self::s($in['signer'], 80); }
        if (isset($in['signDefault'])) { $c['signDefault'] = (bool) $in['signDefault']; }
        if (isset($in['items']) && is_array($in['items'])) {
            $seen = []; $items = [];
            foreach ($in['items'] as $it) {
                $code = self::s($it['code'] ?? '', 20); $name = self::s($it['name'] ?? '', 120);
                if ($name === '') { continue; }
                if ($code === '' ) { $code = (string) (count($items) + 1); }
                if (isset($seen[$code])) { throw new Mutqan_Error('bad_input', 'كود الصنف «' . $code . '» متكرر.', 400); }
                $seen[$code] = 1;
                $items[] = ['code' => $code, 'name' => $name, 'unit' => self::s($it['unit'] ?? 'عدد', 20) ?: 'عدد', 'price' => max(0, round((float) ($it['price'] ?? 0), 2))];
            }
            $c['items'] = $items;
        }
        update_option(self::OPT, $c, false);
        return self::cfg();
    }

    /**
     * The amounts, line by line. Inclusive prices (the default, as in his template: 120 → 104.35 + 15.65): the price
     * the customer pays already holds the VAT. A plain invoice has no VAT at all.
     */
    public static function calc($items, $tax, $rate, $inclusive) {
        $rows = []; $T = ['total' => 0.0, 'discount' => 0.0, 'afterDiscount' => 0.0, 'vat' => 0.0, 'net' => 0.0];
        $k = 1 + $rate / 100;
        foreach ($items as $i => $it) {
            $qty = max(0, (float) ($it['qty'] ?? 1)); $price = max(0, (float) ($it['price'] ?? 0)); $disc = max(0, (float) ($it['discount'] ?? 0));
            $gross = round($qty * $price, 2); $disc = min($disc, $gross);
            if (!$tax) { $row = ['total' => $gross, 'discount' => $disc, 'afterDiscount' => $gross - $disc, 'vat' => 0.0, 'net' => round($gross - $disc, 2)]; }
            elseif ($inclusive) {
                $net = round($gross - $disc, 2); $before = round($net / $k, 2);
                $row = ['total' => round($gross / $k, 2), 'discount' => round($disc / $k, 2), 'afterDiscount' => $before, 'vat' => round($net - $before, 2), 'net' => $net];
            } else {
                $after = round($gross - $disc, 2); $vat = round($after * $rate / 100, 2);
                $row = ['total' => $gross, 'discount' => $disc, 'afterDiscount' => $after, 'vat' => $vat, 'net' => round($after + $vat, 2)];
            }
            $rows[] = ['no' => $i + 1, 'code' => self::s($it['code'] ?? '', 20), 'name' => self::s($it['name'] ?? '', 200), 'unit' => self::s($it['unit'] ?? 'عدد', 20) ?: 'عدد',
                'qty' => $qty, 'price' => round($price, 2)] + $row;
            foreach ($T as $f => $v) { $T[$f] = round($v + $row[$f], 2); }
        }
        return ['rows' => $rows, 'totals' => $T];
    }

    /** ZATCA simplified-invoice QR (phase 1): TLV of seller, VAT number, time, total with VAT, VAT — in base64. */
    public static function zatca_qr($seller, $vatNo, $isoTime, $total, $vat) {
        $tlv = '';
        foreach ([1 => $seller, 2 => $vatNo, 3 => $isoTime, 4 => number_format((float) $total, 2, '.', ''), 5 => number_format((float) $vat, 2, '.', '')] as $tag => $val) {
            $val = (string) $val; $tlv .= chr($tag) . chr(strlen($val)) . $val;
        }
        return base64_encode($tlv);
    }

    /** The next number of that type, taken under a lock so two people saving at once never share one. */
    private static function take_number($type) {
        global $wpdb;
        $lock = 'mutqan_invoice_n';
        $got = $wpdb && method_exists($wpdb, 'get_var') ? (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)) : 1;
        try {
            if (function_exists('wp_cache_delete')) { wp_cache_delete(self::OPT, 'options'); wp_cache_delete('alloptions', 'options'); }
            $c = self::cfg(); $n = (int) $c['next'][$type];
            // never reuse a number already in the register
            foreach (Mutqan_Store::select(self::COLL, ['type' => $type], ['limit' => 1], self::sys()) as $last) { $n = max($n, (int) ($last['number'] ?? 0) + 1); }
            $raw = get_option(self::OPT, []); $raw = is_array($raw) ? $raw : [];
            $raw['next'] = array_merge($c['next'], [$type => $n + 1]);
            update_option(self::OPT, $raw, false);
            return $n;
        } finally { if ($got && $wpdb && method_exists($wpdb, 'query')) { $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); } }
    }

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'النظام']; }

    /** Issue an invoice. $in: type, template, client{}, items[], tech{id,name}, pay, note, orderId, stamped, at. */
    public static function save($in, $p) {
        if (!Mutqan_Policy::has_power($p, 'write')) { throw new Mutqan_Error('forbidden', 'إصدار الفواتير للمالك والعمليات.', 403); }
        $type = isset(self::TYPES[$in['type'] ?? '']) ? (string) $in['type'] : 'normal';
        $tax = $type === 'tax';
        $c = self::cfg();
        if ($tax && !preg_match('/^3\d{13}3$/', (string) $c['vatNo'])) { throw new Mutqan_Error('bad_input', 'اكتب الرقم الضريبي للمؤسسة في إعدادات الفواتير الأول.', 400); }
        $cl = (array) ($in['client'] ?? []);
        $client = ['name' => self::s($cl['name'] ?? '', 160), 'phone' => self::digits($cl['phone'] ?? '', 15), 'vatNo' => self::digits($cl['vatNo'] ?? '', 15), 'number' => self::s($cl['number'] ?? '', 30),
            'city' => self::s($cl['city'] ?? '', 60), 'district' => self::s($cl['district'] ?? '', 60), 'province' => self::s($cl['province'] ?? '', 60), 'zip' => self::digits($cl['zip'] ?? '', 10),
            'street' => self::s($cl['street'] ?? '', 80), 'building' => self::s($cl['building'] ?? '', 20), 'addNo' => self::s($cl['addNo'] ?? '', 20)];
        if ($client['name'] === '') { throw new Mutqan_Error('bad_input', 'اكتب اسم العميل أو الشركة.', 400); }
        if ($client['vatNo'] !== '' && !preg_match('/^3\d{13}3$/', $client['vatNo'])) { throw new Mutqan_Error('bad_input', 'الرقم الضريبي للعميل لازم 15 رقم يبدأ وينتهي بـ 3 (أو سيبه فاضي).', 400); }
        $items = array_values(array_filter((array) ($in['items'] ?? []), function ($it) { return is_array($it) && trim((string) ($it['name'] ?? '')) !== ''; }));
        if (!$items) { throw new Mutqan_Error('bad_input', 'ضيف بند واحد على الأقل.', 400); }
        if (count($items) > 60) { throw new Mutqan_Error('bad_input', 'أقصى عدد 60 بند في الفاتورة.', 400); }
        $inclusive = array_key_exists('inclusive', $in) ? (bool) $in['inclusive'] : (bool) $c['inclusive'];
        $calc = self::calc($items, $tax, (float) $c['vatRate'], $inclusive);
        if ($calc['totals']['net'] <= 0) { throw new Mutqan_Error('bad_input', 'إجمالي الفاتورة صفر — اكتب الأسعار.', 400); }
        $at = !empty($in['at']) && strtotime((string) $in['at']) ? gmdate('c', strtotime((string) $in['at'])) : gmdate('c');
        $num = self::take_number($type);
        $tech = (array) ($in['tech'] ?? []);
        $doc = [
            'type' => $type, 'number' => $num, 'at' => $at, 'template' => isset(self::TEMPLATES[$in['template'] ?? '']) ? (string) $in['template'] : $c['template'][$type],
            'client' => $client, 'rows' => $calc['rows'], 'totals' => $calc['totals'], 'vatRate' => $tax ? (float) $c['vatRate'] : 0, 'inclusive' => $inclusive,
            'tech' => ['id' => self::s($tech['id'] ?? '', 64), 'name' => self::s($tech['name'] ?? '', 80)],
            'pay' => isset(self::PAY[$in['pay'] ?? '']) ? (string) $in['pay'] : 'cash', 'note' => self::s($in['note'] ?? '', 400),
            'orderId' => self::s($in['orderId'] ?? '', 64), 'stamped' => !empty($in['stamped']),
            // the signature (when he uploaded one) and the receiver's name, which he can hide
            'signed' => !empty($in['signed']) && $c['signature'] !== '', 'receiver' => self::s($in['receiver'] ?? '', 80), 'hideReceiver' => !empty($in['hideReceiver']),
            // the issuer as it is today: a later change of the settings never rewrites an issued invoice
            'company' => ['nameAr' => $c['nameAr'], 'nameEn' => $c['nameEn'], 'brand' => $c['brand'], 'cityLine' => $c['cityLine'], 'vatNo' => $c['vatNo'], 'crNo' => $c['crNo'],
                'phones' => $c['phones'], 'address' => $c['address'], 'logo' => $c['logo'], 'stamp' => $c['stamp'], 'signature' => $c['signature'], 'signer' => $c['signer'], 'note' => $c['note']],
            'by' => ['id' => (string) ($p['id'] ?? ''), 'name' => (string) ($p['name'] ?? '')], 'status' => 'issued',
        ];
        if ($tax) { $doc['qr'] = self::zatca_qr($c['nameAr'], $c['vatNo'], $at, $calc['totals']['net'], $calc['totals']['vat']); }
        $saved = Mutqan_Store::insert(self::COLL, $doc, null, ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'], self::sys());
        if ($doc['orderId'] !== '' && Mutqan_Store::get_raw('orders', $doc['orderId'])) {
            try { Mutqan_Store::update('orders', $doc['orderId'], ['invoice' => ['id' => (string) $saved['id'], 'type' => $type, 'number' => $num, 'net' => $calc['totals']['net']]], self::sys()); } catch (Throwable $e) { error_log('[mutqan] invoice→order ' . $e->getMessage()); }
        }
        return Mutqan_Store::get_raw(self::COLL, (string) $saved['id']) + ['id' => (string) $saved['id']];
    }

    public static function get($id) {
        $r = Mutqan_Store::get_raw(self::COLL, (string) $id);
        if (!$r) { throw new Mutqan_Error('not_found', 'الفاتورة مش موجودة.', 404); }
        return $r + ['id' => (string) $id];
    }

    public static function lst($in) {
        $q = self::s($in['q'] ?? '', 60); $type = (string) ($in['type'] ?? '');
        $out = [];
        foreach (Mutqan_Store::select(self::COLL, [], ['limit' => 500], self::sys()) as $r) {
            if ($type !== '' && ($r['type'] ?? '') !== $type) { continue; }
            if ($q !== '' && mb_stripos(implode(' ', [$r['number'] ?? '', $r['client']['name'] ?? '', $r['client']['phone'] ?? '', $r['tech']['name'] ?? '', $r['orderId'] ?? '']), $q) === false) { continue; }
            $out[] = ['id' => (string) $r['id'], 'type' => $r['type'], 'number' => (int) $r['number'], 'at' => $r['at'], 'client' => $r['client']['name'] ?? '', 'phone' => $r['client']['phone'] ?? '',
                'net' => (float) ($r['totals']['net'] ?? 0), 'vat' => (float) ($r['totals']['vat'] ?? 0), 'stamped' => !empty($r['stamped']), 'status' => $r['status'] ?? 'issued', 'tech' => $r['tech']['name'] ?? '', 'template' => $r['template'] ?? ''];
        }
        return $out;
    }

    /** Never deleted: the owner voids it, the number stays in the register. */
    public static function void($id, $reason, $p) {
        if (($p['role'] ?? '') !== 'owner') { throw new Mutqan_Error('forbidden', 'إلغاء الفواتير للمالك بس.', 403); }
        self::get($id);
        Mutqan_Store::update(self::COLL, (string) $id, ['status' => 'void', 'voidAt' => gmdate('c'), 'voidReason' => self::s($reason, 200), 'voidBy' => (string) ($p['name'] ?? '')], self::sys());
        return self::get($id);
    }

    /** One door for the app: op = invoice_cfg | invoice_cfg_set | invoice_save | invoice_list | invoice_get | invoice_void. */
    public static function op($op, $in, $p) {
        switch ($op) {
            case 'invoice_cfg': return self::cfg() + ['types' => self::TYPES, 'templates' => self::TEMPLATES, 'pays' => self::PAY];
            case 'invoice_cfg_set': return self::cfg_set((array) ($in['cfg'] ?? []), $p);
            case 'invoice_save': return self::save((array) ($in['invoice'] ?? []), $p);
            case 'invoice_list': return ['items' => self::lst($in)];
            case 'invoice_get': return self::get($in['id'] ?? '');
            case 'invoice_void': return self::void($in['id'] ?? '', $in['reason'] ?? '', $p);
        }
        throw new Mutqan_Error('bad_input', 'عملية غير معروفة.', 400);
    }
}
