<?php
/**
 * Customers and companies — «داتابيز لكل عميل» (owner, 2026-11-22). Built by itself from every invoice and quote
 * (matched by VAT number, then CR, then phone), and editable by hand. One customer's page holds his data, his papers
 * (CR, VAT certificate, national address… in the private vault), his quotes, invoices and returns, and the sums.
 */
if (!defined('ABSPATH')) { exit; }

final class Mutqan_Accounts {
    const COLL = 'accounts';
    const FIELDS = ['name' => 200, 'nameEn' => 200, 'responsible' => 120, 'phone' => 15, 'email' => 120, 'vatNo' => 15, 'crNo' => 10, 'city' => 60, 'district' => 60, 'province' => 60,
        'street' => 80, 'building' => 20, 'zip' => 10, 'addNo' => 20, 'number' => 30, 'activity' => 200, 'notes' => 1000, 'kind' => 20];

    private static function sys() { return ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'النظام']; }
    private static function digits($v, $n) { return substr(preg_replace('/\D/', '', strtr((string) $v, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'])), 0, $n); }
    private static function norm_name($s) { return preg_replace('/\s+/u', ' ', str_replace(['أ', 'إ', 'آ', 'ة', 'ى'], ['ا', 'ا', 'ا', 'ه', 'ي'], mb_strtolower(trim((string) $s)))); }

    public static function clean($in) {
        $o = [];
        foreach (self::FIELDS as $k => $n) {
            if (!array_key_exists($k, (array) $in)) { continue; }
            $v = $in[$k];
            $o[$k] = in_array($k, ['phone', 'vatNo', 'crNo', 'zip'], true) ? self::digits($v, $n) : mb_substr(trim((string) $v), 0, $n);
        }
        if (isset($o['vatNo']) && $o['vatNo'] !== '' && !preg_match('/^3\d{13}3$/', $o['vatNo'])) { throw new Mutqan_Error('bad_input', 'الرقم الضريبي لازم 15 رقم يبدأ وينتهي بـ 3.', 400); }
        if (isset($o['phone']) && preg_match('/^9665\d{8}$/', $o['phone'])) { $o['phone'] = '0' . substr($o['phone'], 3); }
        return $o;
    }

    /** The same customer whatever document he came from: VAT number, then CR, then phone (+ name), then the exact name. */
    public static function find($c) {
        $c = (array) $c;
        $all = Mutqan_Store::select(self::COLL, [], ['limit' => 5000], self::sys());
        $vat = self::digits($c['vatNo'] ?? '', 15); $cr = self::digits($c['crNo'] ?? '', 10); $ph = self::digits($c['phone'] ?? '', 15); $nm = self::norm_name($c['name'] ?? '');
        if (preg_match('/^9665\d{8}$/', $ph)) { $ph = '0' . substr($ph, 3); }
        foreach ([['vatNo', $vat], ['crNo', $cr]] as list($k, $v)) { if ($v !== '') { foreach ($all as $a) { if (($a[$k] ?? '') === $v) { return $a; } } } }
        if ($ph !== '') { foreach ($all as $a) { if (($a['phone'] ?? '') === $ph && ($nm === '' || self::norm_name($a['name'] ?? '') === $nm || ($a['vatNo'] ?? '') === '')) { return $a; } } }
        if ($nm !== '' && $ph === '' && $vat === '') { foreach ($all as $a) { if (self::norm_name($a['name'] ?? '') === $nm && ($a['phone'] ?? '') === '') { return $a; } } }
        return null;
    }

    /** From an invoice or a quote: the customer is found (and his empty fields filled) or created. @return string id */
    public static function link($client, $p) {
        $c = self::clean($client);
        if (trim((string) ($c['name'] ?? '')) === '') { return ''; }
        $a = self::find($c);
        if ($a) {
            $fill = [];
            foreach ($c as $k => $v) { if ($v !== '' && (string) ($a[$k] ?? '') === '') { $fill[$k] = $v; } }
            if ($fill) { Mutqan_Store::update(self::COLL, (string) $a['id'], $fill, self::sys()); }
            return (string) $a['id'];
        }
        $c['kind'] = $c['kind'] ?? ((($c['vatNo'] ?? '') !== '' || ($c['crNo'] ?? '') !== '') ? 'company' : 'person');
        $r = Mutqan_Store::insert(self::COLL, $c + ['files' => [], 'createdAt' => gmdate('c'), 'by' => (string) ($p['name'] ?? '')], null, ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'], self::sys());
        return (string) $r['id'];
    }

    public static function save($in, $p) {
        if (!Mutqan_Policy::has_power($p, 'write')) { throw new Mutqan_Error('forbidden', 'للمالك والعمليات.', 403); }
        $c = self::clean($in);
        if (trim((string) ($c['name'] ?? '')) === '') { throw new Mutqan_Error('bad_input', 'اكتب اسم العميل أو الشركة.', 400); }
        $id = (string) ($in['id'] ?? '');
        if ($id !== '') {
            if (!Mutqan_Store::get_raw(self::COLL, $id)) { throw new Mutqan_Error('not_found', 'العميل مش موجود.', 404); }
            foreach (['vatNo', 'crNo'] as $k) {
                if (($c[$k] ?? '') !== '') { $o = self::find([$k => $c[$k]]); if ($o && (string) $o['id'] !== $id) { throw new Mutqan_Error('exists', 'فيه عميل تاني بنفس ' . ($k === 'vatNo' ? 'الرقم الضريبي' : 'السجل التجاري') . ': ' . $o['name'], 409); } }
            }
            Mutqan_Store::update(self::COLL, $id, $c + ['editedAt' => gmdate('c'), 'editedBy' => (string) ($p['name'] ?? '')], self::sys());
        } else {
            if (self::find($c) && (($c['vatNo'] ?? '') !== '' || ($c['crNo'] ?? '') !== '')) { throw new Mutqan_Error('exists', 'العميل ده موجود قبل كده.', 409); }
            $id = self::link($c, $p);
        }
        foreach ((array) ($in['fileIds'] ?? []) as $fid) { self::add_file($id, (string) $fid, $p); }
        return self::profile($id);
    }

    /** A vault file onto the customer's shelf (from an upload, or from a document read by the AI). */
    public static function add_file($id, $fileId, $p) {
        if (!Mutqan_Policy::has_power($p, 'write')) { throw new Mutqan_Error('forbidden', 'للمالك والعمليات.', 403); }
        $a = Mutqan_Store::get_raw(self::COLL, (string) $id);
        if (!$a) { throw new Mutqan_Error('not_found', 'العميل مش موجود.', 404); }
        $f = Mutqan_Vault::get($fileId);
        if (!$f) { throw new Mutqan_Error('not_found', 'الملف مش موجود.', 404); }
        $files = array_values(array_unique(array_merge((array) ($a['files'] ?? []), [(string) $fileId])));
        Mutqan_Store::update(self::COLL, (string) $id, ['files' => $files], self::sys());
        Mutqan_Vault::attach($fileId, 'acc:' . $id);
        return ['ok' => true];
    }

    public static function upload($in, $p) {
        if (!Mutqan_Policy::has_power($p, 'write')) { throw new Mutqan_Error('forbidden', 'للمالك والعمليات.', 403); }
        $id = (string) ($in['id'] ?? '');
        if (!Mutqan_Store::get_raw(self::COLL, $id)) { throw new Mutqan_Error('not_found', 'العميل مش موجود.', 404); }
        $f = Mutqan_Vault::put($in['data'] ?? '', (string) ($in['mime'] ?? ''), (string) ($in['name'] ?? ''), (string) ($in['kind'] ?? 'other'), $p, 'acc:' . $id);
        self::add_file($id, $f['id'], $p);
        return $f;
    }

    public static function remove_file($id, $fileId, $p) {
        $a = Mutqan_Store::get_raw(self::COLL, (string) $id);
        if (!$a) { throw new Mutqan_Error('not_found', 'العميل مش موجود.', 404); }
        Mutqan_Vault::remove($fileId, $p);
        Mutqan_Store::update(self::COLL, (string) $id, ['files' => array_values(array_diff((array) ($a['files'] ?? []), [(string) $fileId]))], self::sys());
        return ['ok' => true];
    }

    /** A link to send (WhatsApp, e-mail) that opens the file for some days, then dies. */
    public static function share($fileId, $days, $p) {
        if (!Mutqan_Policy::has_power($p, 'write')) { throw new Mutqan_Error('forbidden', 'للمالك والعمليات.', 403); }
        if (!Mutqan_Vault::get($fileId)) { throw new Mutqan_Error('not_found', 'الملف مش موجود.', 404); }
        $d = max(1, min(30, (int) $days ?: 7));
        return ['url' => Mutqan_Vault::link((string) $fileId, $d * 86400), 'days' => $d];
    }

    /** Everything about one customer. */
    public static function profile($id) {
        $a = Mutqan_Store::get_raw(self::COLL, (string) $id);
        if (!$a) { throw new Mutqan_Error('not_found', 'العميل مش موجود.', 404); }
        $a['id'] = (string) $id;
        $files = [];
        foreach ((array) ($a['files'] ?? []) as $fid) { $f = Mutqan_Vault::get($fid); if ($f) { $files[] = Mutqan_Vault::pub($f); } }
        $inv = []; $cred = []; $S = ['invoices' => 0, 'invoiced' => 0.0, 'vat' => 0.0, 'returned' => 0.0, 'quotes' => 0, 'quoted' => 0.0, 'accepted' => 0, 'last' => '', 'first' => ''];
        foreach (Mutqan_Store::select(Mutqan_Invoice::COLL, ['clientId' => (string) $id], ['limit' => 1000], self::sys()) as $r) {
            $row = ['id' => (string) $r['id'], 'type' => $r['type'], 'number' => $r['number'], 'at' => $r['at'], 'net' => (float) ($r['totals']['net'] ?? 0), 'vat' => (float) ($r['totals']['vat'] ?? 0),
                'status' => $r['status'] ?? 'issued', 'items' => implode('، ', array_slice(array_map(function ($x) { return $x['name']; }, (array) ($r['rows'] ?? [])), 0, 3)), 'issuer' => $r['company']['nameAr'] ?? '',
                'ref' => $r['ref'] ?? null, 'returned' => (float) ($r['returnedNet'] ?? 0)];
            if (($r['type'] ?? '') === 'credit') { $cred[] = $row; if (($r['status'] ?? '') !== 'void') { $S['returned'] += $row['net']; } continue; }
            $inv[] = $row;
            if (($r['status'] ?? '') === 'void') { continue; }
            $S['invoices']++; $S['invoiced'] += $row['net']; $S['vat'] += $row['vat'];
            $S['last'] = max($S['last'], (string) $r['at']); $S['first'] = $S['first'] === '' ? (string) $r['at'] : min($S['first'], (string) $r['at']);
        }
        $qs = [];
        foreach (Mutqan_Store::select(Mutqan_Quote::COLL, ['clientId' => (string) $id], ['limit' => 1000], self::sys()) as $r) {
            $qs[] = ['id' => (string) $r['id'], 'number' => $r['number'] ?? '', 'issueDate' => $r['issueDate'] ?? '', 'status' => $r['status'] ?? 'draft', 'net' => (float) ($r['totals']['net'] ?? 0),
                'items' => implode('، ', array_slice(array_map(function ($x) { return $x['name']; }, (array) ($r['rows'] ?? [])), 0, 3)), 'invoice' => $r['invoice'] ?? null];
            $S['quotes']++; $S['quoted'] += (float) ($r['totals']['net'] ?? 0); if (($r['status'] ?? '') === 'accepted') { $S['accepted']++; }
        }
        foreach (['invoiced', 'vat', 'returned', 'quoted'] as $k) { $S[$k] = round($S[$k], 2); }
        $S['net'] = round($S['invoiced'] - $S['returned'], 2);
        // the items he took most, for the summary
        $top = [];
        foreach (Mutqan_Store::select(Mutqan_Invoice::COLL, ['clientId' => (string) $id], ['limit' => 1000], self::sys()) as $r) {
            if (($r['status'] ?? '') === 'void' || ($r['type'] ?? '') === 'credit') { continue; }
            foreach ((array) ($r['rows'] ?? []) as $x) { $k = (string) $x['name']; $top[$k] = ($top[$k] ?? ['name' => $k, 'qty' => 0, 'net' => 0]); $top[$k]['qty'] += (float) $x['qty']; $top[$k]['net'] = round($top[$k]['net'] + (float) $x['net'], 2); }
        }
        usort($top, function ($x, $y) { return $y['net'] <=> $x['net']; });
        return ['client' => $a, 'files' => $files, 'invoices' => $inv, 'credits' => $cred, 'quotes' => $qs, 'stats' => $S, 'top' => array_slice(array_values($top), 0, 8)];
    }

    public static function lst($in) {
        $q = self::norm_name($in['q'] ?? ''); $kind = (string) ($in['kind'] ?? '');
        $sum = [];
        foreach (Mutqan_Store::select(Mutqan_Invoice::COLL, [], ['limit' => 5000], self::sys()) as $r) {
            $cid = (string) ($r['clientId'] ?? ''); if ($cid === '' || ($r['status'] ?? '') === 'void') { continue; }
            $s = $sum[$cid] ?? ['n' => 0, 'net' => 0.0, 'last' => ''];
            if (($r['type'] ?? '') === 'credit') { $s['net'] -= (float) ($r['totals']['net'] ?? 0); } else { $s['n']++; $s['net'] += (float) ($r['totals']['net'] ?? 0); $s['last'] = max($s['last'], (string) $r['at']); }
            $sum[$cid] = $s;
        }
        $qn = [];
        foreach (Mutqan_Store::select(Mutqan_Quote::COLL, [], ['limit' => 5000], self::sys()) as $r) { $cid = (string) ($r['clientId'] ?? ''); if ($cid !== '') { $qn[$cid] = ($qn[$cid] ?? 0) + 1; } }
        $out = [];
        foreach (Mutqan_Store::select(self::COLL, [], ['limit' => 5000], self::sys()) as $a) {
            $id = (string) $a['id'];
            if ($kind !== '' && ($a['kind'] ?? 'person') !== $kind) { continue; }
            if ($q !== '' && mb_strpos(self::norm_name(implode(' ', [$a['name'] ?? '', $a['phone'] ?? '', $a['vatNo'] ?? '', $a['crNo'] ?? '', $a['responsible'] ?? ''])), $q) === false) { continue; }
            $s = $sum[$id] ?? ['n' => 0, 'net' => 0.0, 'last' => ''];
            $out[] = ['id' => $id, 'name' => $a['name'] ?? '', 'kind' => $a['kind'] ?? 'person', 'phone' => $a['phone'] ?? '', 'vatNo' => $a['vatNo'] ?? '', 'crNo' => $a['crNo'] ?? '', 'city' => $a['city'] ?? '',
                'invoices' => $s['n'], 'net' => round($s['net'], 2), 'last' => $s['last'], 'quotes' => $qn[$id] ?? 0, 'files' => count((array) ($a['files'] ?? []))];
        }
        usort($out, function ($x, $y) { return strcmp($y['last'] ?: '0', $x['last'] ?: '0') ?: strcmp($x['name'], $y['name']); });
        return $out;
    }

    public static function remove($id, $p) {
        if (($p['role'] ?? '') !== 'owner') { throw new Mutqan_Error('forbidden', 'مسح العملاء للمالك بس.', 403); }
        if (Mutqan_Store::select(Mutqan_Invoice::COLL, ['clientId' => (string) $id], ['limit' => 1], self::sys())) { throw new Mutqan_Error('locked', 'العميل ده عليه فواتير — مينفعش يتمسح (السجل الضريبي لازم يفضل).', 409); }
        $a = Mutqan_Store::get_raw(self::COLL, (string) $id);
        foreach ((array) ($a['files'] ?? []) as $fid) { Mutqan_Vault::remove($fid, $p); }
        Mutqan_Store::delete(self::COLL, (string) $id, self::sys());
        return ['ok' => true];
    }

    public static function op($op, $in, $p) {
        switch ($op) {
            case 'account_list': return ['items' => self::lst($in)];
            case 'account_get': return self::profile($in['id'] ?? '');
            case 'account_save': return self::save((array) ($in['account'] ?? []), $p);
            case 'account_upload': return self::upload($in, $p);
            case 'account_file_del': return self::remove_file($in['id'] ?? '', $in['fileId'] ?? '', $p);
            case 'account_share': return self::share((string) ($in['fileId'] ?? ''), (int) ($in['days'] ?? 7), $p);
            case 'account_del': return self::remove($in['id'] ?? '', $p);
        }
        throw new Mutqan_Error('bad_input', 'عملية غير معروفة.', 400);
    }
}
