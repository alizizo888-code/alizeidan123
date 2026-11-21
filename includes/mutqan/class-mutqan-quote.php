<?php
/**
 * Quotations — «عروض الأسعار» (owner, 2026-11-22), on his own template (SAL-QTN number, salesman/engineer, valid till,
 * status, customer details, items, totals, terms, bank accounts, header and footer). A quote is a draft until the owner
 * or operations approve it («لا يعتبر عرض السعر معتمداً بشكل رسمي في حال كانت حالته Draft»).
 *
 * The AI writes the wording from what he explains — item names and specs, scope, terms — and he edits and approves it;
 * it never sets a price he did not give. Wordings he likes are saved («صيغ») and reused. A company's commercial
 * registration, VAT certificate or national address, uploaded as a photo or PDF, is read by the AI and fills the customer.
 */
if (!defined('ABSPATH')) { exit; }

final class Mutqan_Quote {
    const COLL = 'quotes';
    const SNIP = 'quote_snippets';
    const OPT = 'mutqan_quote_cfg';
    const STATUS = ['draft' => 'مسودة / Draft', 'approved' => 'معتمد / Approved', 'sent' => 'مرسل / Sent', 'accepted' => 'مقبول / Accepted', 'rejected' => 'مرفوض / Rejected'];
    const KINDS = ['terms' => 'الشروط والأحكام', 'scope' => 'نطاق العمل', 'payment' => 'الدفع', 'warranty' => 'الضمان', 'duration' => 'مدة التنفيذ', 'note' => 'ملاحظة', 'item' => 'وصف صنف / مواصفات', 'intro' => 'مقدمة العرض'];

    /** From his template and the quote he sent (SAL-QTN-2025-04254). */
    public static function defaults() {
        return [
            'prefix' => 'SAL-QTN', 'next' => 1, 'validDays' => 14, 'vatRate' => 15, 'inclusive' => false,
            'staffTitle' => ['ar' => 'أخصائي المبيعات', 'en' => 'Sales man'],
            'header' => '', 'footer' => '', 'title' => '',      // empty = the bundled images from his template
            'note' => 'ملاحظة : لا يعتبر عرض السعر معتمدا بشكل رسمي في حال كانت حالته Draft',
            'terms' => [
                'الدفع : 50% عند التعميد , 50% قبل التوريد.',
                'مدة العمل أو التوريد ( 2-3 ) يوم عمل من تاريخ استلام الدفعة الأولى.',
                'نطاق العمل : السعر يشمل التوريد ، الفحص والتشغيل .',
                'الضمان : " 3 شهر " من تاريخ التسليم وفقاً لشروط وأحكام الضمان المعتمدة لدى الشركة .',
            ],
            'banks' => [
                ['bank' => 'الراجحي', 'iban' => 'SA3180000501608010336547'],
                ['bank' => 'الأهلي', 'iban' => 'SA4610000022368556000102'],
                ['bank' => 'الانماء', 'iban' => 'SA9305000068202851127000'],
            ],
        ];
    }

    public static function cfg() {
        $c = get_option(self::OPT, []);
        $c = array_merge(self::defaults(), is_array($c) ? $c : []);
        $c['staffTitle'] = array_merge(self::defaults()['staffTitle'], (array) $c['staffTitle']);
        return $c;
    }

    private static function s($v, $n = 200) { return mb_substr(trim(preg_replace('/[ \t]+/u', ' ', (string) $v)), 0, $n); }
    private static function ml($v, $n = 4000) { return mb_substr(trim(str_replace("\r", '', (string) $v)), 0, $n); }
    private static function digits($v, $n = 20) { return substr(preg_replace('/\D/', '', strtr((string) $v, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'])), 0, $n); }
    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'النظام']; }
    private static function can_write($p) { return Mutqan_Policy::has_power($p, 'write'); }

    public static function cfg_set($in, $p) {
        if (($p['role'] ?? '') !== 'owner') { throw new Mutqan_Error('forbidden', 'إعدادات العروض للمالك بس.', 403); }
        $c = self::cfg();
        if (isset($in['prefix'])) { $c['prefix'] = preg_replace('/[^A-Za-z0-9\-]/', '', (string) $in['prefix']) ?: 'SAL-QTN'; }
        foreach (['next' => [1, 9999999], 'validDays' => [1, 365], 'vatRate' => [0, 30]] as $k => $r) { if (isset($in[$k]) && is_numeric($in[$k])) { $c[$k] = max($r[0], min($r[1], $k === 'vatRate' ? (float) $in[$k] : (int) $in[$k])); } }
        if (isset($in['inclusive'])) { $c['inclusive'] = (bool) $in['inclusive']; }
        if (isset($in['staffTitle'])) { $c['staffTitle'] = ['ar' => self::s($in['staffTitle']['ar'] ?? '', 40) ?: 'أخصائي المبيعات', 'en' => self::s($in['staffTitle']['en'] ?? '', 40) ?: 'Sales man']; }
        foreach (['header', 'footer', 'title'] as $k) { if (isset($in[$k])) { $c[$k] = esc_url_raw((string) $in[$k]); } }
        if (isset($in['note'])) { $c['note'] = self::s($in['note'], 300); }
        if (isset($in['terms'])) { $c['terms'] = array_values(array_filter(array_map(function ($x) { return self::s($x, 400); }, (array) $in['terms']))); }
        if (isset($in['banks'])) {
            $c['banks'] = [];
            foreach ((array) $in['banks'] as $b) {
                $iban = strtoupper(preg_replace('/\s+/', '', (string) ($b['iban'] ?? '')));
                if ($iban === '') { continue; }
                if (!preg_match('/^SA\d{22}$/', $iban)) { throw new Mutqan_Error('bad_input', 'الآيبان «' . $iban . '» لازم يبدأ بـ SA وبعده 22 رقم.', 400); }
                $c['banks'][] = ['bank' => self::s($b['bank'] ?? '', 40), 'iban' => $iban];
            }
        }
        update_option(self::OPT, $c, false);
        return self::cfg();
    }

    /** SAL-QTN-2026-00001: one sequence, the year of issue in the number. */
    private static function take_number() {
        global $wpdb;
        $lock = 'mutqan_quote_n';
        $got = $wpdb && method_exists($wpdb, 'get_var') ? (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)) : 1;
        try {
            if (function_exists('wp_cache_delete')) { wp_cache_delete(self::OPT, 'options'); wp_cache_delete('alloptions', 'options'); }
            $c = self::cfg(); $n = (int) $c['next'];
            foreach (Mutqan_Store::select(self::COLL, [], ['limit' => 1], self::sys()) as $last) { $n = max($n, (int) ($last['seq'] ?? 0) + 1); }
            $raw = get_option(self::OPT, []); $raw = is_array($raw) ? $raw : []; $raw['next'] = $n + 1;
            update_option(self::OPT, $raw, false);
            return [$n, $c['prefix'] . '-' . wp_date('Y') . '-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT)];
        } finally { if ($got && $wpdb && method_exists($wpdb, 'query')) { $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); } }
    }

    private static function clean_client($cl) {
        $cl = (array) $cl;
        $out = ['name' => self::s($cl['name'] ?? '', 200), 'responsible' => self::s($cl['responsible'] ?? '', 120), 'phone' => self::digits($cl['phone'] ?? '', 15), 'email' => self::s($cl['email'] ?? '', 120),
            'vatNo' => self::digits($cl['vatNo'] ?? '', 15), 'crNo' => self::digits($cl['crNo'] ?? '', 10), 'city' => self::s($cl['city'] ?? '', 60), 'district' => self::s($cl['district'] ?? '', 60),
            'street' => self::s($cl['street'] ?? '', 80), 'building' => self::s($cl['building'] ?? '', 20), 'zip' => self::digits($cl['zip'] ?? '', 10), 'addNo' => self::s($cl['addNo'] ?? '', 20)];
        if ($out['vatNo'] !== '' && !preg_match('/^3\d{13}3$/', $out['vatNo'])) { throw new Mutqan_Error('bad_input', 'الرقم الضريبي للعميل لازم 15 رقم يبدأ وينتهي بـ 3.', 400); }
        return $out;
    }

    /** Create (numbered) or update a quote. Saving an approved quote sends it back to draft: it changed. */
    public static function save($in, $p) {
        if (!self::can_write($p)) { throw new Mutqan_Error('forbidden', 'عروض الأسعار للمالك والعمليات.', 403); }
        $c = self::cfg();
        $client = self::clean_client($in['client'] ?? []);
        if ($client['name'] === '') { throw new Mutqan_Error('bad_input', 'اكتب اسم العميل.', 400); }
        $items = [];
        foreach ((array) ($in['items'] ?? []) as $it) {
            if (!is_array($it) || trim((string) ($it['name'] ?? '')) === '') { continue; }
            $items[] = ['code' => self::s($it['code'] ?? '', 20), 'name' => self::s($it['name'] ?? '', 300), 'specs' => self::ml($it['specs'] ?? '', 1500), 'unit' => self::s($it['unit'] ?? 'عدد', 20) ?: 'عدد',
                'qty' => max(0, (float) ($it['qty'] ?? 1)), 'price' => max(0, (float) ($it['price'] ?? 0)), 'discount' => max(0, (float) ($it['discount'] ?? 0))];
        }
        if (!$items) { throw new Mutqan_Error('bad_input', 'ضيف صنف واحد على الأقل.', 400); }
        if (count($items) > 80) { throw new Mutqan_Error('bad_input', 'أقصى عدد 80 صنف.', 400); }
        $inclusive = array_key_exists('inclusive', $in) ? (bool) $in['inclusive'] : (bool) $c['inclusive'];
        $calc = Mutqan_Invoice::calc($items, true, (float) $c['vatRate'], $inclusive);
        foreach ($calc['rows'] as $i => $r) { $calc['rows'][$i]['specs'] = $items[$i]['specs']; }
        $issue = !empty($in['issueDate']) && strtotime((string) $in['issueDate']) ? gmdate('Y-m-d', strtotime((string) $in['issueDate'])) : wp_date('Y-m-d');
        $valid = !empty($in['validTill']) && strtotime((string) $in['validTill']) ? gmdate('Y-m-d', strtotime((string) $in['validTill'])) : gmdate('Y-m-d', strtotime($issue . ' +' . (int) $c['validDays'] . ' days'));
        if ($valid < $issue) { throw new Mutqan_Error('bad_input', 'تاريخ الصلاحية قبل تاريخ الإصدار.', 400); }
        $staff = (array) ($in['staff'] ?? []);
        $doc = [
            'issueDate' => $issue, 'validTill' => $valid, 'client' => $client, 'remark' => self::ml($in['remark'] ?? '', 600), 'intro' => self::ml($in['intro'] ?? '', 1500),
            'rows' => $calc['rows'], 'totals' => $calc['totals'], 'vatRate' => (float) $c['vatRate'], 'inclusive' => $inclusive,
            'terms' => array_values(array_filter(array_map(function ($x) { return self::ml($x, 600); }, (array) ($in['terms'] ?? $c['terms'])))),
            'extraNote' => self::ml($in['extraNote'] ?? '', 800), 'showBanks' => array_key_exists('showBanks', $in) ? (bool) $in['showBanks'] : true,
            'staff' => ['name' => self::s($staff['name'] ?? ($p['name'] ?? ''), 80), 'title' => self::s($staff['title'] ?? $c['staffTitle']['ar'], 40), 'titleEn' => self::s($staff['titleEn'] ?? $c['staffTitle']['en'], 40)],
            'stamped' => !empty($in['stamped']), 'signed' => !empty($in['signed']), 'orderId' => self::s($in['orderId'] ?? '', 64),
        ];
        // the customer's file, and the papers read for this quote put on his shelf
        $cid = (string) ($in['clientId'] ?? '');
        if ($cid === '' || !Mutqan_Store::get_raw(Mutqan_Accounts::COLL, $cid)) { $cid = Mutqan_Accounts::link($client, $p); }
        $doc['clientId'] = $cid;
        foreach ((array) ($in['fileIds'] ?? []) as $fid) { try { Mutqan_Accounts::add_file($cid, (string) $fid, $p); } catch (Throwable $e) { /* never blocks the quote */ } }
        $id = (string) ($in['id'] ?? '');
        if ($id !== '') {
            $old = Mutqan_Store::get_raw(self::COLL, $id);
            if (!$old) { throw new Mutqan_Error('not_found', 'العرض مش موجود.', 404); }
            if (in_array($old['status'] ?? '', ['accepted'], true)) { throw new Mutqan_Error('locked', 'العرض اتقبل من العميل — اعمل نسخة جديدة بدل التعديل.', 409); }
            $doc['status'] = 'draft'; $doc['editedBy'] = (string) ($p['name'] ?? ''); $doc['editedAt'] = gmdate('c');
            Mutqan_Store::update(self::COLL, $id, $doc, self::sys());
            return self::get($id);
        }
        list($seq, $num) = self::take_number();
        $doc += ['seq' => $seq, 'number' => $num, 'status' => 'draft', 'createdAt' => gmdate('c'), 'by' => ['id' => (string) ($p['id'] ?? ''), 'name' => (string) ($p['name'] ?? '')],
            'company' => self::company()];
        $saved = Mutqan_Store::insert(self::COLL, $doc, null, ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'], self::sys());
        return self::get((string) $saved['id']);
    }

    /** The issuer as on the invoices (name, VAT, CR, logo, stamp, signature), plus the quote's own images. */
    public static function company() {
        $i = Mutqan_Invoice::cfg(); $q = self::cfg();
        return ['nameAr' => $i['nameAr'], 'nameEn' => $i['nameEn'], 'vatNo' => $i['vatNo'], 'crNo' => $i['crNo'], 'phones' => $i['phones'], 'address' => $i['address'],
            'logo' => $i['logo'], 'stamp' => $i['stamp'], 'signature' => $i['signature'] ?? '', 'header' => $q['header'], 'footer' => $q['footer'], 'title' => $q['title'], 'note' => $q['note'], 'banks' => $q['banks']];
    }

    public static function get($id) {
        $r = Mutqan_Store::get_raw(self::COLL, (string) $id);
        if (!$r) { throw new Mutqan_Error('not_found', 'العرض مش موجود.', 404); }
        $r['id'] = (string) $id;
        // the quote shows the company as it is now while it is a draft; once approved it keeps what it was approved with
        if (($r['status'] ?? 'draft') === 'draft') { $r['company'] = self::company(); }
        $r['expired'] = ($r['validTill'] ?? '9999') < wp_date('Y-m-d') && !in_array($r['status'] ?? '', ['accepted', 'rejected'], true);
        return $r;
    }

    public static function lst($in) {
        $q = self::s($in['q'] ?? '', 60); $st = (string) ($in['status'] ?? '');
        $out = [];
        foreach (Mutqan_Store::select(self::COLL, [], ['limit' => 500], self::sys()) as $r) {
            if ($st !== '' && ($r['status'] ?? '') !== $st) { continue; }
            if ($q !== '' && mb_stripos(implode(' ', [$r['number'] ?? '', $r['client']['name'] ?? '', $r['client']['phone'] ?? '', $r['staff']['name'] ?? '']), $q) === false) { continue; }
            $out[] = ['id' => (string) $r['id'], 'number' => $r['number'] ?? '', 'issueDate' => $r['issueDate'] ?? '', 'validTill' => $r['validTill'] ?? '', 'status' => $r['status'] ?? 'draft',
                'client' => $r['client']['name'] ?? '', 'phone' => $r['client']['phone'] ?? '', 'net' => (float) ($r['totals']['net'] ?? 0), 'staff' => $r['staff']['name'] ?? '',
                'invoice' => $r['invoice'] ?? null, 'expired' => ($r['validTill'] ?? '9999') < wp_date('Y-m-d') && !in_array($r['status'] ?? '', ['accepted', 'rejected'], true)];
        }
        return $out;
    }

    /** approve (owner / operations) · sent · accepted · rejected · back to draft. Approving freezes the company data. */
    public static function status($id, $to, $p) {
        if (!self::can_write($p)) { throw new Mutqan_Error('forbidden', 'للمالك والعمليات.', 403); }
        if (!isset(self::STATUS[$to])) { throw new Mutqan_Error('bad_input', 'حالة غير معروفة.', 400); }
        $q = self::get($id);
        $patch = ['status' => $to, 'statusAt' => gmdate('c'), 'statusBy' => (string) ($p['name'] ?? '')];
        if ($to === 'approved') { $patch += ['approvedBy' => (string) ($p['name'] ?? ''), 'approvedAt' => gmdate('c'), 'company' => self::company()]; }
        if (in_array($to, ['sent', 'accepted'], true) && ($q['status'] ?? 'draft') === 'draft') { throw new Mutqan_Error('not_approved', 'اعتمد العرض الأول قبل ما يتبعت أو يتقبل.', 409); }
        Mutqan_Store::update(self::COLL, (string) $id, $patch, self::sys());
        return self::get($id);
    }

    /** An accepted (or approved) quote becomes a tax invoice with the same customer and items. */
    public static function to_invoice($id, $in, $p) {
        $q = self::get($id);
        if (!in_array($q['status'] ?? '', ['approved', 'sent', 'accepted'], true)) { throw new Mutqan_Error('not_approved', 'اعتمد العرض الأول.', 409); }
        if (!empty($q['invoice']['id'])) { throw new Mutqan_Error('exists', 'العرض ده اتعمل له فاتورة رقم ' . $q['invoice']['number'] . ' قبل كده.', 409); }
        $cl = $q['client'];
        $inv = Mutqan_Invoice::save(['type' => in_array($in['type'] ?? 'tax', ['tax', 'normal'], true) ? ($in['type'] ?? 'tax') : 'tax', 'inclusive' => (bool) ($q['inclusive'] ?? false),
            'clientId' => $q['clientId'] ?? '', 'client' => ['name' => $cl['name'], 'phone' => $cl['phone'], 'vatNo' => $cl['vatNo'], 'city' => $cl['city'], 'district' => $cl['district'], 'street' => $cl['street'], 'building' => $cl['building'], 'zip' => $cl['zip'], 'addNo' => $cl['addNo']],
            'items' => array_map(function ($r) { return ['code' => $r['code'] ?? '', 'name' => $r['name'], 'unit' => $r['unit'], 'qty' => $r['qty'], 'price' => $r['price'], 'discount' => $r['discount'] ?? 0]; }, $q['rows']),
            'note' => 'بناءً على عرض السعر ' . $q['number'], 'stamped' => !empty($in['stamped']), 'signed' => !empty($in['signed']), 'orderId' => $q['orderId'] ?? ''], $p);
        Mutqan_Store::update(self::COLL, (string) $id, ['invoice' => ['id' => (string) $inv['id'], 'number' => $inv['number'], 'type' => $inv['type']], 'status' => 'accepted'], self::sys());
        return $inv;
    }

    /* ---------------------------------------------------------------- saved wordings («صيغ») */

    public static function snippets() {
        return array_map(function ($x) { return ['id' => (string) $x['id'], 'kind' => (string) ($x['kind'] ?? 'terms'), 'title' => (string) ($x['title'] ?? ''), 'text' => (string) ($x['text'] ?? '')]; },
            Mutqan_Store::select(self::SNIP, [], ['limit' => 500, 'order' => 'title'], self::sys()));
    }
    public static function snippet_save($in, $p) {
        if (!self::can_write($p)) { throw new Mutqan_Error('forbidden', 'للمالك والعمليات.', 403); }
        $doc = ['kind' => isset(self::KINDS[$in['kind'] ?? '']) ? (string) $in['kind'] : 'terms', 'title' => self::s($in['title'] ?? '', 80), 'text' => self::ml($in['text'] ?? '', 4000), 'by' => (string) ($p['name'] ?? '')];
        if ($doc['text'] === '') { throw new Mutqan_Error('bad_input', 'الصيغة فاضية.', 400); }
        if ($doc['title'] === '') { $doc['title'] = mb_substr(preg_replace('/\s+/u', ' ', $doc['text']), 0, 40); }
        if (!empty($in['id'])) { Mutqan_Store::update(self::SNIP, (string) $in['id'], $doc, self::sys()); return ['id' => (string) $in['id']] + $doc; }
        $r = Mutqan_Store::insert(self::SNIP, $doc, null, ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'], self::sys());
        return ['id' => (string) $r['id']] + $doc;
    }
    public static function snippet_del($id, $p) {
        if (!self::can_write($p)) { throw new Mutqan_Error('forbidden', 'للمالك والعمليات.', 403); }
        Mutqan_Store::delete(self::SNIP, (string) $id, self::sys()); return ['ok' => true];
    }

    /* ---------------------------------------------------------------- the AI */

    /**
     * Writes the wording from what he explains: item names (formal, with specs), the scope, the terms. Prices are his:
     * the AI keeps the ones he gave and never invents one (a missing price stays 0 and is flagged).
     */
    public static function ai_write($in, $p) {
        if (!self::can_write($p)) { throw new Mutqan_Error('forbidden', 'للمالك والعمليات.', 403); }
        $brief = self::ml($in['brief'] ?? '', 3000);
        $items = array_slice((array) ($in['items'] ?? []), 0, 30);
        if ($brief === '' && !$items) { throw new Mutqan_Error('bad_input', 'اشرح المطلوب أو اكتب الأصناف الأول.', 400); }
        $c = self::cfg();
        $style = array_slice(array_map(function ($s) { return '- (' . (self::KINDS[$s['kind']] ?? $s['kind']) . ') ' . mb_substr($s['text'], 0, 400); }, self::snippets()), 0, 12);
        $have = array_map(function ($it) { return ['name' => self::s($it['name'] ?? '', 200), 'specs' => self::s($it['specs'] ?? '', 400), 'qty' => (float) ($it['qty'] ?? 1), 'price' => (float) ($it['price'] ?? 0)]; }, $items);
        $prompt = "أنت كاتب عروض أسعار رسمية لمؤسسة مقاولات وصيانة سعودية (تكييف، غرف تبريد، مضخات، كهرباء، سباكة). اكتب بالعربية الفصحى الرسمية المختصرة، مع اسم إنجليزي تقني للصنف عند اللزوم.\n"
            . "المطلوب كما شرحه صاحب المؤسسة:\n«" . $brief . "»\n\n"
            . "الأصناف التي كتبها (لا تغيّر الكميات ولا الأسعار، ولا تخترع سعراً؛ إن لم يكتب سعراً اترك price = 0 وأضف تنبيهاً في warnings):\n" . wp_json_encode($have, JSON_UNESCAPED_UNICODE) . "\n\n"
            . "الشروط الافتراضية للمؤسسة:\n- " . implode("\n- ", $c['terms']) . "\n"
            . ($style ? "\nصيغ محفوظة يفضّلها (التزم بأسلوبها):\n" . implode("\n", $style) . "\n" : '')
            . "\nأعد JSON فقط بالشكل:\n{\"items\":[{\"name\":\"اسم الصنف الرسمي\",\"specs\":\"المواصفات في سطور قصيرة\",\"unit\":\"عدد\",\"qty\":1,\"price\":0}],\"intro\":\"سطر أو سطران يقدمان العرض للعميل\",\"terms\":[\"الدفع : ...\",\"مدة التنفيذ : ...\",\"نطاق العمل : ...\",\"الضمان : ...\"],\"extraNote\":\"ملاحظة مهمة إن وجدت أو فارغة\",\"warnings\":[\"ما يحتاج مراجعة من المؤسسة\"]}\n"
            . "قواعد: المواصفات حقيقية ومعروفة لهذا النوع فقط (سعة، قدرة، جهد، ماركة إن ذكرها)، لا تعد بشيء لم يذكره، والضمان والمدة من كلامه أو من الشروط الافتراضية.";
        $r = Mutqan_AI::run($prompt, ['json' => true, 'feature' => 'quote_write', 'tier' => 'high', 'long' => true, 'budget' => 60, 'max_tokens' => 3000, 'temperature' => 0.3], $p);
        $j = is_array($r['json'] ?? null) ? $r['json'] : Mutqan_AI::extract_json((string) ($r['text'] ?? ''));
        if (!is_array($j)) { throw new Mutqan_Error('ai_failed', 'الذكاء الاصطناعي ما رجعش صيغة مفهومة — جرّب تاني.', 502); }
        $out = ['items' => [], 'intro' => self::ml($j['intro'] ?? '', 1500), 'terms' => array_values(array_filter(array_map(function ($x) { return self::ml($x, 600); }, (array) ($j['terms'] ?? [])))),
            'extraNote' => self::ml($j['extraNote'] ?? '', 800), 'warnings' => array_values(array_filter(array_map(function ($x) { return self::s($x, 200); }, (array) ($j['warnings'] ?? [])))), 'provider' => (string) ($r['provider'] ?? '')];
        foreach ((array) ($j['items'] ?? []) as $i => $it) {
            // his prices and quantities win over anything the model wrote
            $mine = $have[$i] ?? null;
            $out['items'][] = ['name' => self::s($it['name'] ?? ($mine['name'] ?? ''), 300), 'specs' => self::ml($it['specs'] ?? '', 1500), 'unit' => self::s($it['unit'] ?? 'عدد', 20) ?: 'عدد',
                'qty' => $mine ? $mine['qty'] : max(1, (float) ($it['qty'] ?? 1)), 'price' => $mine && $mine['price'] > 0 ? $mine['price'] : 0];
        }
        if (array_filter($out['items'], function ($x) { return $x['price'] <= 0; })) { $out['warnings'][] = 'فيه أصناف من غير سعر — اكتب السعر قبل الحفظ.'; }
        return $out;
    }

    /**
     * Lifts a company's data from its commercial registration, VAT certificate or national address (photo or PDF).
     * Nothing is saved: the fields come back for him to check.
     */
    public static function extract($in, $p) {
        if (!self::can_write($p)) { throw new Mutqan_Error('forbidden', 'للمالك والعمليات.', 403); }
        $mime = (string) ($in['mime'] ?? '');
        if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic'], true)) { throw new Mutqan_Error('bad_input', 'ارفع صورة أو PDF.', 400); }
        $b64 = preg_replace('/^data:[^,]+,/', '', (string) ($in['data'] ?? ''));
        if ($b64 === '' || strlen($b64) > 9 * 1024 * 1024) { throw new Mutqan_Error('bad_input', 'الملف فاضي أو أكبر من 6 ميجا.', 400); }
        $prompt = "هذا مستند سعودي رسمي لمنشأة (سجل تجاري، أو شهادة تسجيل ضريبة القيمة المضافة، أو العنوان الوطني). استخرج البيانات الموجودة فعلاً فقط، ولا تخمّن.\n"
            . "أعد JSON فقط: {\"docType\":\"cr|vat|address|other\",\"name\":\"اسم المنشأة كما هو\",\"nameEn\":\"\",\"crNo\":\"رقم السجل التجاري 10 أرقام\",\"vatNo\":\"الرقم الضريبي 15 رقم\",\"city\":\"\",\"district\":\"\",\"street\":\"\",\"building\":\"رقم المبنى\",\"zip\":\"الرمز البريدي\",\"addNo\":\"الرقم الإضافي\",\"province\":\"المنطقة\",\"phone\":\"\",\"email\":\"\",\"responsible\":\"اسم المالك أو المدير إن وجد\",\"activity\":\"النشاط\",\"expiry\":\"تاريخ الانتهاء إن وجد\"}\n"
            . "الحقول غير الموجودة تبقى فارغة. الأرقام بالأرقام الإنجليزية.";
        $r = Mutqan_AI::vision($b64, $mime, $prompt, true);
        if (!$r['ok']) { throw new Mutqan_Error('ai_unavailable', $r['error'] === 'no_gemini' ? 'قراءة المستندات محتاجة مفتاح Gemini مفعّل في إعدادات الذكاء الاصطناعي.' : 'ما قدرتش أقرأ الملف دلوقتي — جرّب تاني أو بصورة أوضح.', 503); }
        $j = Mutqan_AI::extract_json($r['text']);
        if (!is_array($j)) { throw new Mutqan_Error('ai_failed', 'ما طلعتش بيانات مفهومة من الملف.', 502); }
        $f = [];
        foreach (['docType' => 20, 'name' => 200, 'nameEn' => 200, 'city' => 60, 'district' => 60, 'street' => 80, 'building' => 20, 'addNo' => 20, 'province' => 60, 'email' => 120, 'responsible' => 120, 'activity' => 200, 'expiry' => 40] as $k => $n) { $f[$k] = self::s($j[$k] ?? '', $n); }
        $f['crNo'] = self::digits($j['crNo'] ?? '', 10); $f['vatNo'] = self::digits($j['vatNo'] ?? '', 15); $f['zip'] = self::digits($j['zip'] ?? '', 10); $f['phone'] = self::digits($j['phone'] ?? '', 15);
        if ($f['vatNo'] !== '' && !preg_match('/^3\d{13}3$/', $f['vatNo'])) { $f['vatWarn'] = 'الرقم الضريبي المقروء مش مظبوط (' . $f['vatNo'] . ') — راجعه.'; $f['vatNo'] = ''; }
        // the paper itself is kept in the private vault: it lands on the customer's shelf when the invoice / quote / customer is saved
        $kind = in_array($f['docType'], ['cr', 'vat', 'address'], true) ? $f['docType'] : 'other';
        try { $v = Mutqan_Vault::put($b64, $mime, self::s($in['name'] ?? '', 100) ?: (Mutqan_Vault::KINDS[$kind] . ' — ' . ($f['name'] ?: 'عميل')), $kind, $p, (string) ($in['accountId'] ?? '') !== '' ? 'acc:' . $in['accountId'] : ''); $f['file'] = $v; }
        catch (Throwable $e) { $f['file'] = null; }
        if (!empty($in['accountId']) && !empty($f['file']['id'])) { try { Mutqan_Accounts::add_file((string) $in['accountId'], $f['file']['id'], $p); } catch (Throwable $e) {} }
        return $f;
    }

    public static function op($op, $in, $p) {
        switch ($op) {
            case 'quote_cfg': $ic = Mutqan_Invoice::cfg(); return self::cfg() + ['statuses' => self::STATUS, 'kinds' => self::KINDS, 'company' => self::company(), 'catalog' => $ic['items'], 'cats' => $ic['cats']];
            case 'quote_cfg_set': return self::cfg_set((array) ($in['cfg'] ?? []), $p);
            case 'quote_save': return self::save((array) ($in['quote'] ?? []), $p);
            case 'quote_get': return self::get($in['id'] ?? '');
            case 'quote_list': return ['items' => self::lst($in)];
            case 'quote_status': return self::status($in['id'] ?? '', (string) ($in['to'] ?? ''), $p);
            case 'quote_invoice': return self::to_invoice($in['id'] ?? '', $in, $p);
            case 'quote_ai': return self::ai_write($in, $p);
            case 'quote_snippets': return ['items' => self::snippets()];
            case 'quote_snippet_save': return self::snippet_save($in, $p);
            case 'quote_snippet_del': return self::snippet_del($in['id'] ?? '', $p);
            case 'doc_extract': return self::extract($in, $p);
        }
        throw new Mutqan_Error('bad_input', 'عملية غير معروفة.', 400);
    }
}
