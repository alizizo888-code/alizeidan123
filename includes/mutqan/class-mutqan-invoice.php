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
            'phones' => ['0536554731', '0549423050'], 'address' => 'المملكة العربية السعودية – مكة المكرمة',
            'vatRate' => 15, 'inclusive' => true,
            'next' => ['normal' => 1001, 'tax' => 1, 'credit' => 1],
            'cats' => ['غسيل', 'صيانة وإصلاح', 'تركيب', 'قطع غيار', 'غرف تبريد', 'أخرى'],
            'issuers' => [],   // more issuing companies, each with its own VAT number, numbers, stamp
            // branches: the place printed on the paper (Makkah — Al-Aziziyah, Jeddah…), chosen per invoice / quote
            'branches' => [['id' => 'mkk', 'name' => 'مكة المكرمة — العزيزية', 'cityLine' => 'مكة المكرمة — العزيزية', 'address' => 'المملكة العربية السعودية – مكة المكرمة – حي العزيزية', 'phones' => [], 'footer' => '']],
            'branch' => 'mkk',
            'template' => ['normal' => 'classic', 'tax' => 'official'],
            'logo' => '', 'stamp' => '', 'signature' => '',   // empty logo/stamp = the bundled ones; no signature until he uploads one
            'signer' => '', 'signDefault' => false,
            'note' => 'أي فاتورة لا تحمل ختم المؤسسة تعتبر لاغية',
            'items' => [
                ['code' => '101', 'name' => 'غسيل مكيف سبليت', 'unit' => 'عدد', 'price' => 0, 'cat' => 'غسيل'],
                ['code' => '102', 'name' => 'غسيل مكيف شباك', 'unit' => 'عدد', 'price' => 0, 'cat' => 'غسيل'],
                ['code' => '201', 'name' => 'صيانة وإصلاح مكيف', 'unit' => 'عدد', 'price' => 0, 'cat' => 'صيانة وإصلاح'],
                ['code' => '202', 'name' => 'شحن فريون', 'unit' => 'عدد', 'price' => 0, 'cat' => 'صيانة وإصلاح'],
                ['code' => '301', 'name' => 'تركيب مكيف سبليت', 'unit' => 'عدد', 'price' => 0, 'cat' => 'تركيب'],
                ['code' => '302', 'name' => 'فك وتركيب مكيف', 'unit' => 'عدد', 'price' => 0, 'cat' => 'تركيب'],
                ['code' => '401', 'name' => 'صيانة غرفة تبريد / تجميد', 'unit' => 'خدمة', 'price' => 0, 'cat' => 'غرف تبريد'],
            ],
        ];
    }

    public static function cfg() {
        $c = get_option(self::OPT, []);
        $c = array_merge(self::defaults(), is_array($c) ? $c : []);
        $c['next'] = array_merge(self::defaults()['next'], (array) $c['next']);
        $c['issuers'] = array_values(array_filter((array) $c['issuers'], 'is_array'));
        $c['branches'] = array_values(array_filter((array) $c['branches'], 'is_array'));
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
        if (isset($in['next']['credit']) && is_numeric($in['next']['credit'])) { $c['next']['credit'] = max(1, (int) $in['next']['credit']); }
        if (isset($in['cats'])) { $c['cats'] = array_values(array_unique(array_filter(array_map(function ($x) { return self::s($x, 40); }, (array) $in['cats'])))); }
        if (isset($in['issuers'])) { $c['issuers'] = self::clean_issuers((array) $in['issuers'], $c['issuers']); }
        if (isset($in['branches'])) {
            $c['branches'] = [];
            foreach ((array) $in['branches'] as $b) {
                $b = (array) $b; $name = self::s($b['name'] ?? '', 80); if ($name === '') { continue; }
                $c['branches'][] = ['id' => preg_replace('/[^a-z0-9]/', '', strtolower((string) ($b['id'] ?? ''))) ?: 'b' . substr(md5($name . count($c['branches'])), 0, 6), 'name' => $name,
                    'cityLine' => self::s($b['cityLine'] ?? $name, 120), 'address' => self::s($b['address'] ?? '', 160),
                    'phones' => array_values(array_filter(array_map(function ($x) { return self::digits($x, 15); }, (array) ($b['phones'] ?? [])))), 'footer' => esc_url_raw((string) ($b['footer'] ?? ''))];
            }
        }
        if (isset($in['branch'])) { $c['branch'] = preg_replace('/[^a-z0-9]/', '', strtolower((string) $in['branch'])); }
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
                $items[] = ['code' => $code, 'name' => $name, 'unit' => self::s($it['unit'] ?? 'عدد', 20) ?: 'عدد', 'price' => max(0, round((float) ($it['price'] ?? 0), 2)),
                    'cat' => self::s($it['cat'] ?? '', 40), 'specs' => mb_substr(trim((string) ($it['specs'] ?? '')), 0, 1000)];
                if ($items[count($items) - 1]['cat'] !== '' && !in_array($items[count($items) - 1]['cat'], $c['cats'], true)) { $c['cats'][] = $items[count($items) - 1]['cat']; }
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
        // the authority's reader wants UTC as 2026-01-31T09:30:00Z (not +00:00), lengths in bytes, each field under 256 bytes
        $ts = strtotime((string) $isoTime); $time = gmdate('Y-m-d\\TH:i:s\\Z', $ts ?: time());
        $seller = trim((string) $seller); if (strlen($seller) > 255) { $seller = mb_strcut($seller, 0, 255, 'UTF-8'); }
        $tlv = '';
        foreach ([1 => $seller, 2 => preg_replace('/\\D/', '', (string) $vatNo), 3 => $time, 4 => number_format(abs((float) $total), 2, '.', ''), 5 => number_format(abs((float) $vat), 2, '.', '')] as $tag => $val) {
            $val = (string) $val; $tlv .= chr($tag) . chr(strlen($val)) . $val;
        }
        return base64_encode($tlv);
    }

    /** The ZATCA QR as it should be now (also repairs invoices issued before the time-format fix). */
    private static function fresh_qr($r) {
        if (empty($r['qr']) || empty($r['company'])) { return $r['qr'] ?? ''; }
        return self::zatca_qr($r['company']['nameAr'] ?? '', $r['company']['vatNo'] ?? '', $r['at'] ?? '', $r['totals']['net'] ?? 0, $r['totals']['vat'] ?? 0);
    }

    /** More issuing companies: each its own name, VAT number, CR, stamp, signature and numbers. */
    private static function clean_issuers($list, $old) {
        $prev = []; foreach ((array) $old as $o) { $prev[$o['id']] = $o; }
        $out = [];
        foreach ($list as $x) {
            $x = (array) $x; $name = self::s($x['nameAr'] ?? '', 160); if ($name === '') { continue; }
            $id = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($x['id'] ?? ''))) ?: 'c' . substr(md5($name . microtime()), 0, 8);
            if ($id === 'main') { $id = 'c' . substr(md5($name), 0, 8); }
            $vat = self::digits($x['vatNo'] ?? '', 15);
            if ($vat !== '' && !preg_match('/^3\d{13}3$/', $vat)) { throw new Mutqan_Error('bad_input', 'الرقم الضريبي لـ «' . $name . '» لازم 15 رقم يبدأ وينتهي بـ 3.', 400); }
            $out[] = ['id' => $id, 'nameAr' => $name, 'nameEn' => self::s($x['nameEn'] ?? '', 160), 'brand' => self::s($x['brand'] ?? '', 40), 'cityLine' => self::s($x['cityLine'] ?? '', 120),
                'vatNo' => $vat, 'crNo' => self::digits($x['crNo'] ?? '', 10), 'phones' => array_values(array_filter(array_map(function ($p) { return self::digits($p, 15); }, (array) ($x['phones'] ?? [])))),
                'address' => self::s($x['address'] ?? '', 160), 'note' => self::s($x['note'] ?? '', 200), 'logo' => esc_url_raw((string) ($x['logo'] ?? '')), 'stamp' => esc_url_raw((string) ($x['stamp'] ?? '')),
                'signature' => esc_url_raw((string) ($x['signature'] ?? '')), 'signer' => self::s($x['signer'] ?? '', 80),
                'next' => array_merge(['normal' => 1, 'tax' => 1, 'credit' => 1], (array) ($prev[$id]['next'] ?? []), array_map('intval', array_filter((array) ($x['next'] ?? []), 'is_numeric')))];
        }
        return $out;
    }

    public static function branch($id = '') {
        $c = self::cfg(); $id = (string) ($id ?: $c['branch']);
        foreach ($c['branches'] as $b) { if ($b['id'] === $id) { return $b; } }
        return null;
    }

    /** The company as printed from that branch: its city line, address and (when it has them) its phones. */
    public static function apply_branch($company, $branchId) {
        $b = self::branch($branchId);
        if (!$b) { return $company; }
        $company['cityLine'] = $b['cityLine'] ?: ($company['cityLine'] ?? '');
        if ($b['address'] !== '') { $company['address'] = $b['address']; }
        if ($b['phones']) { $company['phones'] = $b['phones']; }
        $company['branch'] = $b['name']; $company['branchId'] = $b['id']; $company['branchFooter'] = $b['footer'];
        return $company;
    }

    /**
     * The second QR: a link any phone camera opens — the invoice's details (issuer, customer, items, prices, status)
     * on a page of the site. Signed, so nobody reaches another invoice by changing the number. The ZATCA QR stays
     * exactly the five fields the authority's reader expects.
     */
    public static function view_url($id) {
        $id = (string) $id; $h = self::hex_id($id);
        // short link (fewer QR squares, bigger ones, reads with any camera): /?mqi=<22-char id><12-char signature>
        if ($h !== '') { return home_url('/') . '?mqi=' . rtrim(strtr(base64_encode(hex2bin($h)), '+/', '-_'), '=') . substr(self::sig($id), 0, 12); }
        return rest_url('mutqan/v1/inv/' . rawurlencode($id)) . '?s=' . substr(self::sig($id), 0, 20);
    }

    private static function sig($id) { return hash_hmac('sha256', 'inv|' . $id, hash('sha256', (defined('AUTH_KEY') ? AUTH_KEY : 'mq') . '|inv')); }
    private static function hex_id($id) { $h = strtolower(str_replace('-', '', (string) $id)); return preg_match('/^[0-9a-f]{32}$/', $h) && strlen((string) $id) === 36 ? $h : ''; }

    /** A link's signature: 12 chars (short link) or 20 (links printed before the short form). */
    public static function check($id, $s) { $s = (string) $s; return in_array(strlen($s), [12, 20], true) && hash_equals(substr(self::sig((string) $id), 0, strlen($s)), $s); }

    /** /?mqi=… back to the invoice id and its signature. */
    public static function parse_short($q) {
        $q = (string) $q; if (!preg_match('/^([A-Za-z0-9_-]{22})([0-9a-f]{12})$/', $q, $m)) { return null; }
        $h = bin2hex((string) base64_decode(strtr($m[1], '-_', '+/') . '==')); if (strlen($h) !== 32) { return null; }
        return [substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20), $m[2]];
    }

    public static function serve_view($id, $s) {
        $id = (string) $id;
        $ok = self::check($id, $s);
        $r = $ok ? Mutqan_Store::get_raw(self::COLL, $id) : null;
        nocache_headers(); header('X-LiteSpeed-Cache-Control: no-cache'); header('Content-Type: text/html; charset=utf-8'); header('X-Robots-Tag: noindex, nofollow'); header('Referrer-Policy: no-referrer');
        if (!$r) { status_header(404); echo '<!doctype html><meta charset="utf-8"><body dir="rtl" style="font-family:Tahoma;padding:40px;text-align:center"><h2>⚠️ الفاتورة غير موجودة أو الرابط غير صحيح</h2></body>'; exit; }
        $e = function ($v) { return esc_html((string) $v); }; $m = function ($n) { return number_format((float) $n, 2); };
        $co = (array) ($r['company'] ?? []); $cl = (array) ($r['client'] ?? []); $T = (array) ($r['totals'] ?? []);
        $title = ($r['type'] ?? '') === 'credit' ? (!empty($r['tax']) ? 'إشعار دائن' : 'مرتجع') : (($r['type'] ?? '') === 'tax' ? 'فاتورة ضريبية' : 'فاتورة');
        $state = ($r['status'] ?? '') === 'void' ? '<p class="bad">❌ هذه الفاتورة ملغاة' . (!empty($r['voidReason']) ? ' — ' . $e($r['voidReason']) : '') . '</p>' : '<p class="good">✅ فاتورة صادرة من النظام وسليمة</p>';
        if (!empty($r['returnedNet'])) { $state .= '<p class="warn">↩️ عليها مرتجع بقيمة ' . $m($r['returnedNet']) . ' ر.س</p>'; }
        if (!empty($r['ref']['number'])) { $state .= '<p class="warn">↩️ مرتجع على الفاتورة رقم ' . $e($r['ref']['number']) . (!empty($r['reason']) ? ' — ' . $e($r['reason']) : '') . '</p>'; }
        $rows = '';
        foreach ((array) ($r['rows'] ?? []) as $x) { $rows .= '<tr><td>' . $e($x['name']) . '</td><td>' . $e($x['qty']) . '</td><td>' . $m($x['price']) . '</td>' . (($r['vatRate'] ?? 0) ? '<td>' . $m($x['vat']) . '</td>' : '') . '<td>' . $m($x['net']) . '</td></tr>'; }
        $when = wp_date('Y-m-d H:i', strtotime((string) $r['at']));
        echo '<!doctype html><html dir="rtl" lang="ar"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $title . ' ' . $e($r['number']) . '</title>'
            . '<style>body{margin:0;background:#f1f5f9;font-family:Tahoma,Arial,sans-serif;color:#0f172a}.w{max-width:620px;margin:0 auto;padding:16px}.c{background:#fff;border-radius:16px;padding:16px;margin-bottom:12px;box-shadow:0 1px 3px rgba(0,0,0,.08)}h1{font-size:20px;margin:0 0 4px}h2{font-size:14px;color:#2563eb;margin:0 0 8px}p{margin:4px 0;font-size:14px}.good{color:#15803d;font-weight:700}.bad{color:#b91c1c;font-weight:700}.warn{color:#b45309;font-weight:700}table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:7px 4px;border-bottom:1px solid #e2e8f0;text-align:center}th{background:#eff6ff}td:first-child{text-align:right}.t{display:flex;justify-content:space-between;font-size:15px;margin:4px 0}.n{font-weight:900;font-size:18px;color:#1d4ed8}small{color:#64748b}</style></head><body><div class="w">'
            . '<div class="c"><h1>' . $title . ' رقم ' . $e($r['number']) . '</h1><small>' . $e($when) . '</small>' . $state . '</div>'
            . '<div class="c"><h2>المُصدِر</h2><p><b>' . $e($co['nameAr'] ?? '') . '</b></p>' . (!empty($co['vatNo']) ? '<p>الرقم الضريبي: ' . $e($co['vatNo']) . '</p>' : '') . (!empty($co['crNo']) ? '<p>السجل التجاري: ' . $e($co['crNo']) . '</p>' : '') . '<p>' . $e($co['cityLine'] ?? '') . '</p>' . (!empty($co['phones']) ? '<p>📞 ' . $e(implode(' — ', (array) $co['phones'])) . '</p>' : '') . '</div>'
            . '<div class="c"><h2>العميل</h2><p><b>' . $e($cl['name'] ?? '') . '</b></p>' . (!empty($cl['vatNo']) ? '<p>الرقم الضريبي: ' . $e($cl['vatNo']) . '</p>' : '') . (!empty($cl['city']) ? '<p>' . $e(implode('، ', array_filter([$cl['district'] ?? '', $cl['city'] ?? '']))) . '</p>' : '') . (!empty($r['tech']['name']) ? '<p>الفني المنفذ: ' . $e($r['tech']['name']) . '</p>' : '') . '</div>'
            . '<div class="c"><h2>البنود</h2><table><tr><th>البند</th><th>الكمية</th><th>السعر</th>' . (($r['vatRate'] ?? 0) ? '<th>الضريبة</th>' : '') . '<th>الإجمالي</th></tr>' . $rows . '</table>'
            . (($r['vatRate'] ?? 0) ? '<div class="t"><span>قبل الضريبة</span><span>' . $m($T['afterDiscount'] ?? 0) . ' ر.س</span></div><div class="t"><span>الضريبة ' . $e($r['vatRate']) . '%</span><span>' . $m($T['vat'] ?? 0) . ' ر.س</span></div>' : '')
            . '<div class="t n"><span>الإجمالي</span><span>' . $m($T['net'] ?? 0) . ' ر.س</span></div></div>'
            . '<p style="text-align:center"><small>صفحة تحقق من الفاتورة — ' . $e($co['nameAr'] ?? '') . '</small></p></div></body></html>';
        exit;
    }

    public static function boot() {
        add_action('init', function () {
            if (empty($_GET['mqi']) || is_admin()) { return; }
            $p = self::parse_short(wp_unslash($_GET['mqi']));
            if ($p) { self::serve_view($p[0], $p[1]); }
        }, 1);
        add_action('rest_api_init', function () {
            register_rest_route('mutqan/v1', '/inv/(?P<id>[A-Za-z0-9\-]+)', ['methods' => 'GET', 'permission_callback' => '__return_true',
                'callback' => function ($req) { self::serve_view($req['id'], $req->get_param('s')); }]);
        });
    }

    /** The issuer: 'main' (the settings themselves) or one of the extra companies. */
    public static function issuer($id = 'main') {
        $c = self::cfg();
        foreach ($c['issuers'] as $i) { if ($i['id'] === $id) { return $i; } }
        return ['id' => 'main', 'nameAr' => $c['nameAr'], 'nameEn' => $c['nameEn'], 'brand' => $c['brand'], 'cityLine' => $c['cityLine'], 'vatNo' => $c['vatNo'], 'crNo' => $c['crNo'], 'phones' => $c['phones'],
            'address' => $c['address'], 'logo' => $c['logo'], 'stamp' => $c['stamp'], 'signature' => $c['signature'], 'signer' => $c['signer'], 'note' => $c['note'], 'next' => $c['next']];
    }

    /** The next number of that type for that issuer, taken under a lock so two people saving at once never share one. */
    private static function take_number($type, $iid = 'main') {
        global $wpdb;
        $lock = 'mutqan_invoice_n';
        $got = $wpdb && method_exists($wpdb, 'get_var') ? (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)) : 1;
        try {
            if (function_exists('wp_cache_delete')) { wp_cache_delete(self::OPT, 'options'); wp_cache_delete('alloptions', 'options'); }
            $c = self::cfg(); $is = self::issuer($iid); $n = (int) ($is['next'][$type] ?? 1);
            // never reuse a number already in the register (of this issuer)
            foreach (Mutqan_Store::select(self::COLL, ['type' => $type], ['limit' => 300], self::sys()) as $last) {
                if (($last['issuer'] ?? 'main') === $is['id']) { $n = max($n, (int) ($last['number'] ?? 0) + 1); }
            }
            $raw = get_option(self::OPT, []); $raw = is_array($raw) ? $raw : [];
            if ($is['id'] === 'main') { $raw['next'] = array_merge($c['next'], [$type => $n + 1]); }
            else { $raw['issuers'] = array_map(function ($x) use ($is, $type, $n) { if (($x['id'] ?? '') === $is['id']) { $x['next'][$type] = $n + 1; } return $x; }, $c['issuers']); }
            update_option(self::OPT, $raw, false);
            return $n;
        } finally { if ($got && $wpdb && method_exists($wpdb, 'query')) { $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); } }
    }

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'النظام']; }

    /** Issue an invoice. $in: type, template, client{}, items[], tech{id,name}, pay, note, orderId, stamped, at. */
    public static function save($in, $p) {
        if (!Mutqan_Policy::has_power($p, 'write')) { throw new Mutqan_Error('forbidden', 'إصدار الفواتير للمالك والعمليات.', 403); }
        $type = in_array($in['type'] ?? '', ['normal', 'tax'], true) ? (string) $in['type'] : 'normal';
        $tax = $type === 'tax';
        $c = array_merge(self::cfg(), self::issuer((string) ($in['issuer'] ?? 'main')));
        $c = self::apply_branch($c, (string) ($in['branch'] ?? ''));
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
        $num = self::take_number($type, $c['id']);
        $tech = (array) ($in['tech'] ?? []);
        // the customer's file: found or created, and the papers read for this invoice put on his shelf
        $clientId = (string) ($in['clientId'] ?? '');
        if ($clientId === '' || !Mutqan_Store::get_raw(Mutqan_Accounts::COLL, $clientId)) { $clientId = Mutqan_Accounts::link($client, $p); }
        foreach ((array) ($in['fileIds'] ?? []) as $fid) { try { Mutqan_Accounts::add_file($clientId, (string) $fid, $p); } catch (Throwable $e) { /* a lost paper never blocks the invoice */ } }
        $doc = [
            'type' => $type, 'number' => $num, 'at' => $at, 'issuer' => $c['id'], 'clientId' => $clientId, 'template' => isset(self::TEMPLATES[$in['template'] ?? '']) ? (string) $in['template'] : $c['template'][$type],
            'client' => $client, 'rows' => $calc['rows'], 'totals' => $calc['totals'], 'vatRate' => $tax ? (float) $c['vatRate'] : 0, 'inclusive' => $inclusive,
            'tech' => ['id' => self::s($tech['id'] ?? '', 64), 'name' => self::s($tech['name'] ?? '', 80)],
            'pay' => isset(self::PAY[$in['pay'] ?? '']) ? (string) $in['pay'] : 'cash', 'note' => self::s($in['note'] ?? '', 400),
            'orderId' => self::s($in['orderId'] ?? '', 64), 'stamped' => !empty($in['stamped']),
            // the signature (when he uploaded one) and the receiver's name, which he can hide
            'signed' => !empty($in['signed']) && $c['signature'] !== '', 'receiver' => self::s($in['receiver'] ?? '', 80), 'hideReceiver' => !empty($in['hideReceiver']),
            // the issuer as it is today: a later change of the settings never rewrites an issued invoice
            'company' => ['nameAr' => $c['nameAr'], 'nameEn' => $c['nameEn'], 'brand' => $c['brand'], 'cityLine' => $c['cityLine'], 'vatNo' => $c['vatNo'], 'crNo' => $c['crNo'],
                'phones' => $c['phones'], 'address' => $c['address'], 'logo' => $c['logo'], 'stamp' => $c['stamp'], 'signature' => $c['signature'], 'signer' => $c['signer'], 'note' => $c['note'],
                'branch' => $c['branch'] ?? '', 'branchId' => $c['branchId'] ?? ''],
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
        if (!empty($r['qr'])) { $r['qr'] = self::fresh_qr($r); }
        return $r + ['id' => (string) $id, 'viewUrl' => self::view_url((string) $id)];
    }

    public static function lst($in) {
        $q = self::s($in['q'] ?? '', 60); $type = (string) ($in['type'] ?? '');
        $out = [];
        foreach (Mutqan_Store::select(self::COLL, [], ['limit' => 500], self::sys()) as $r) {
            if ($type !== '' && ($r['type'] ?? '') !== $type) { continue; }
            if ($q !== '' && mb_stripos(implode(' ', [$r['number'] ?? '', $r['client']['name'] ?? '', $r['client']['phone'] ?? '', $r['tech']['name'] ?? '', $r['orderId'] ?? '']), $q) === false) { continue; }
            $out[] = ['id' => (string) $r['id'], 'type' => $r['type'], 'number' => (int) $r['number'], 'at' => $r['at'], 'client' => $r['client']['name'] ?? '', 'phone' => $r['client']['phone'] ?? '',
                'net' => (float) ($r['totals']['net'] ?? 0), 'vat' => (float) ($r['totals']['vat'] ?? 0), 'stamped' => !empty($r['stamped']), 'status' => $r['status'] ?? 'issued', 'tech' => $r['tech']['name'] ?? '', 'template' => $r['template'] ?? '',
                'clientId' => $r['clientId'] ?? '', 'issuer' => $r['company']['nameAr'] ?? '', 'ref' => $r['ref'] ?? null, 'returned' => (float) ($r['returnedNet'] ?? 0), 'tax' => !empty($r['tax']) || ($r['type'] ?? '') === 'tax'];
        }
        return $out;
    }

    /**
     * A return («مرتجع») — for a tax invoice, a credit note («إشعار دائن») — against an issued invoice: the lines and
     * quantities returned (never more than is left), the reason, its own numbers, the original invoice named on it.
     */
    public static function credit($in, $p) {
        if (!Mutqan_Policy::has_power($p, 'write')) { throw new Mutqan_Error('forbidden', 'المرتجعات للمالك والعمليات.', 403); }
        $orig = self::get($in['ref'] ?? '');
        if (($orig['type'] ?? '') === 'credit') { throw new Mutqan_Error('bad_input', 'ده مرتجع أصلاً.', 400); }
        if (($orig['status'] ?? '') === 'void') { throw new Mutqan_Error('bad_input', 'الفاتورة ملغاة — مفيش مرتجع عليها.', 400); }
        $reason = self::s($in['reason'] ?? '', 300);
        if ($reason === '') { throw new Mutqan_Error('bad_input', 'اكتب سبب المرتجع.', 400); }
        // what was returned before, per line
        $done = [];
        foreach (Mutqan_Store::select(self::COLL, ['type' => 'credit'], ['limit' => 2000], self::sys()) as $cr) {
            if (($cr['ref']['id'] ?? '') !== $orig['id'] || ($cr['status'] ?? '') === 'void') { continue; }
            foreach ((array) $cr['rows'] as $r) { $done[(int) $r['refNo']] = ($done[(int) $r['refNo']] ?? 0) + (float) $r['qty']; }
        }
        $want = [];
        foreach ((array) ($in['lines'] ?? []) as $l) { $no = (int) ($l['no'] ?? 0); $q = (float) ($l['qty'] ?? 0); if ($no > 0 && $q > 0) { $want[$no] = ($want[$no] ?? 0) + $q; } }
        if (!$want) { throw new Mutqan_Error('bad_input', 'اختار البنود والكميات المرتجعة.', 400); }
        $items = []; $refNos = [];
        foreach ((array) $orig['rows'] as $r) {
            $no = (int) $r['no']; if (!isset($want[$no])) { continue; }
            $left = round((float) $r['qty'] - ($done[$no] ?? 0), 4);
            if ($want[$no] > $left + 1e-9) { throw new Mutqan_Error('bad_input', 'البند «' . $r['name'] . '»: المتبقي للمرتجع ' . $left . ' بس.', 400); }
            $q = $want[$no]; $share = (float) $r['qty'] > 0 ? $q / (float) $r['qty'] : 0;
            $items[] = ['code' => $r['code'] ?? '', 'name' => $r['name'], 'unit' => $r['unit'], 'qty' => $q, 'price' => $r['price'], 'discount' => round((float) ($r['discount'] ?? 0) * ((bool) $orig['inclusive'] ? 1 + (float) $orig['vatRate'] / 100 : 1) * $share, 2)];
            $refNos[] = $no;
        }
        if (count($items) !== count($want)) { throw new Mutqan_Error('bad_input', 'فيه بند مش موجود في الفاتورة.', 400); }
        $tax = $orig['type'] === 'tax';
        $calc = self::calc($items, $tax, (float) ($orig['vatRate'] ?: 15), (bool) $orig['inclusive']);
        foreach ($calc['rows'] as $i => $r) { $calc['rows'][$i]['refNo'] = $refNos[$i]; }
        $iid = (string) ($orig['issuer'] ?? 'main');
        $num = self::take_number('credit', $iid);
        $at = gmdate('c');
        $doc = ['type' => 'credit', 'number' => $num, 'at' => $at, 'issuer' => $iid, 'clientId' => $orig['clientId'] ?? '', 'template' => $orig['template'],
            'ref' => ['id' => $orig['id'], 'number' => $orig['number'], 'type' => $orig['type'], 'at' => $orig['at']], 'reason' => $reason, 'tax' => $tax,
            'client' => $orig['client'], 'rows' => $calc['rows'], 'totals' => $calc['totals'], 'vatRate' => $tax ? (float) $orig['vatRate'] : 0, 'inclusive' => (bool) $orig['inclusive'],
            'tech' => $orig['tech'] ?? [], 'pay' => (string) ($in['pay'] ?? ($orig['pay'] ?? 'cash')), 'note' => self::s($in['note'] ?? '', 300), 'orderId' => $orig['orderId'] ?? '',
            'stamped' => !empty($in['stamped']), 'signed' => !empty($in['signed']) && !empty($orig['company']['signature']), 'receiver' => '', 'hideReceiver' => true,
            'company' => $orig['company'], 'by' => ['id' => (string) ($p['id'] ?? ''), 'name' => (string) ($p['name'] ?? '')], 'status' => 'issued'];
        if ($tax) { $doc['qr'] = self::zatca_qr($orig['company']['nameAr'], $orig['company']['vatNo'], $at, $calc['totals']['net'], $calc['totals']['vat']); }
        $saved = Mutqan_Store::insert(self::COLL, $doc, null, ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'], self::sys());
        $returns = array_merge((array) ($orig['returns'] ?? []), [['id' => (string) $saved['id'], 'number' => $num, 'net' => $calc['totals']['net']]]);
        Mutqan_Store::update(self::COLL, $orig['id'], ['returns' => $returns, 'returnedNet' => round((float) ($orig['returnedNet'] ?? 0) + $calc['totals']['net'], 2)], self::sys());
        return self::get((string) $saved['id']);
    }

    /** What is left to return on each line of an invoice. */
    public static function returnable($id) {
        $orig = self::get($id); $done = [];
        foreach (Mutqan_Store::select(self::COLL, ['type' => 'credit'], ['limit' => 2000], self::sys()) as $cr) {
            if (($cr['ref']['id'] ?? '') !== $orig['id'] || ($cr['status'] ?? '') === 'void') { continue; }
            foreach ((array) $cr['rows'] as $r) { $done[(int) $r['refNo']] = ($done[(int) $r['refNo']] ?? 0) + (float) $r['qty']; }
        }
        return ['invoice' => $orig, 'rows' => array_map(function ($r) use ($done) { return ['no' => $r['no'], 'name' => $r['name'], 'qty' => $r['qty'], 'returned' => $done[(int) $r['no']] ?? 0, 'left' => round((float) $r['qty'] - ($done[(int) $r['no']] ?? 0), 4), 'net' => $r['net']]; }, (array) $orig['rows'])];
    }

    /** Never deleted: the owner voids it, the number stays in the register. */
    public static function void($id, $reason, $p) {
        if (($p['role'] ?? '') !== 'owner') { throw new Mutqan_Error('forbidden', 'إلغاء الفواتير للمالك بس.', 403); }
        self::get($id);
        $inv = self::get($id);
        if (($inv['status'] ?? '') === 'void') { return $inv; }
        if (($inv['type'] ?? '') !== 'credit' && !empty($inv['returns'])) { throw new Mutqan_Error('locked', 'الفاتورة عليها مرتجعات — ألغِ المرتجعات الأول.', 409); }
        Mutqan_Store::update(self::COLL, (string) $id, ['status' => 'void', 'voidAt' => gmdate('c'), 'voidReason' => self::s($reason, 200), 'voidBy' => (string) ($p['name'] ?? '')], self::sys());
        // a voided return gives the quantities back to its invoice
        if (($inv['type'] ?? '') === 'credit' && !empty($inv['ref']['id']) && ($o = Mutqan_Store::get_raw(self::COLL, (string) $inv['ref']['id']))) {
            $rest = array_values(array_filter((array) ($o['returns'] ?? []), function ($x) use ($id) { return (string) $x['id'] !== (string) $id; }));
            Mutqan_Store::update(self::COLL, (string) $inv['ref']['id'], ['returns' => $rest, 'returnedNet' => round(array_sum(array_column($rest, 'net')), 2)], self::sys());
        }
        return self::get($id);
    }

    /** One door for the app: op = invoice_cfg | invoice_cfg_set | invoice_save | invoice_list | invoice_get | invoice_void. */
    public static function op($op, $in, $p) {
        switch ($op) {
            case 'invoice_cfg': return self::cfg() + ['branchList' => self::cfg()['branches'], 'types' => self::TYPES, 'templates' => self::TEMPLATES, 'pays' => self::PAY, 'issuerList' => array_merge([self::issuer('main')], self::cfg()['issuers'])];
            case 'invoice_cfg_set': return self::cfg_set((array) ($in['cfg'] ?? []), $p);
            case 'invoice_save': return self::save((array) ($in['invoice'] ?? []), $p);
            case 'invoice_list': return ['items' => self::lst($in)];
            case 'invoice_get': return self::get($in['id'] ?? '');
            case 'invoice_void': return self::void($in['id'] ?? '', $in['reason'] ?? '', $p);
            case 'invoice_credit': return self::credit($in, $p);
            case 'invoice_returnable': return self::returnable($in['id'] ?? '');
        }
        throw new Mutqan_Error('bad_input', 'عملية غير معروفة.', 400);
    }
}
