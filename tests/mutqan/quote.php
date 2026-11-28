<?php
// Quotations (owner, 2026-11-22): his template's number and fields, draft until approved, AI wording that never sets a
// price, saved wordings, company data read from a CR / VAT certificate, a quote turned into a tax invoice.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $n = 0;
$t = function ($name, $c, $x = null) use (&$ok, &$n) { $n++; echo ($c ? 'PASS ' : 'FAIL ') . 'quote: ' . $name . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$Q = 'Mutqan_Quote';
$owner = ['id' => 'u1', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'علي'];
$ops = ['id' => 'u2', 'role' => 'operations', 'powers' => ['read', 'write'], 'kind' => 'user', 'name' => 'حسن باجابر'];
$sup = ['id' => 'u3', 'role' => 'supervisor', 'powers' => ['read'], 'kind' => 'user', 'name' => 'مشرف'];
$deny = function ($fn) { try { $fn(); return false; } catch (Mutqan_Error $e) { return $e->getMessage() ?: true; } };

$c = $Q::cfg();
$t('defaults from his quote: SAL-QTN, 4 terms, 3 IBANs, 14 days', $c['prefix'] === 'SAL-QTN' && count($c['terms']) === 4 && count($c['banks']) === 3 && $c['validDays'] === 14);

// his quote: FIRE PUMP 52000 + 7800 = 59800
$q = $Q::save(['client' => ['name' => 'شركة مصنع جواهر المعادن للتصنيع والطلاء', 'responsible' => 'اسلام', 'phone' => '0510145304'],
    'items' => [['name' => 'FIRE PUMP - ANC SASO - 750 GPM 10 BAR -E.D.J 380 V', 'qty' => 1, 'price' => 52000]], 'issueDate' => '2025-10-16'], $ops);
$t('numbered SAL-QTN-<year>-00001, draft, by the salesman', preg_match('/^SAL-QTN-\d{4}-00001$/', $q['number']) && $q['status'] === 'draft' && $q['staff']['name'] === 'حسن باجابر', $q);
$t('his totals: 52000 + 7800 VAT = 59800 (prices before VAT)', $q['totals']['afterDiscount'] == 52000.0 && $q['totals']['vat'] == 7800.0 && $q['totals']['net'] == 59800.0, $q['totals']);
$t('valid till = issue + 14 days, the default terms and banks', $q['validTill'] === '2025-10-30' && count($q['terms']) === 4 && $q['showBanks'] === true);
$q2 = $Q::save(['client' => ['name' => 'ب'], 'items' => [['name' => 'x', 'price' => 10]]], $owner);
$t('the next quote is 00002', substr($q2['number'], -5) === '00002');
$t('a supervisor cannot write quotes', $deny(function () use ($Q, $sup) { $Q::save(['client' => ['name' => 'x'], 'items' => [['name' => 'y', 'price' => 1]]], $sup); }) !== false);
$t('a wrong customer VAT number is refused', $deny(function () use ($Q, $ops) { $Q::save(['client' => ['name' => 'x', 'vatNo' => '111'], 'items' => [['name' => 'y', 'price' => 1]]], $ops); }) !== false);

// draft → approved → sent → accepted; a draft cannot be «sent»
$t('a draft cannot be sent before approval', $deny(function () use ($Q, $q, $ops) { $Q::status($q['id'], 'sent', $ops); }) !== false);
$a = $Q::status($q['id'], 'approved', $ops);
$t('approved by operations, stamped with who and when', $a['status'] === 'approved' && $a['approvedBy'] === 'حسن باجابر');
$e = $Q::save(['id' => $q['id'], 'client' => ['name' => 'شركة مصنع جواهر المعادن للتصنيع والطلاء'], 'items' => [['name' => 'FIRE PUMP', 'qty' => 1, 'price' => 51000]]], $ops);
$t('editing an approved quote sends it back to draft', $e['status'] === 'draft' && $e['totals']['afterDiscount'] == 51000.0 && $e['number'] === $q['number']);
$Q::status($q['id'], 'approved', $owner); $Q::status($q['id'], 'sent', $owner);
$inv = $Q::to_invoice($q['id'], ['type' => 'tax', 'stamped' => true], $owner);
$t('a quote becomes a tax invoice with its client and items', $inv['type'] === 'tax' && $inv['totals']['net'] == 58650.0 && $inv['client']['name'] === 'شركة مصنع جواهر المعادن للتصنيع والطلاء' && mb_strpos($inv['note'], $q['number']) !== false, $inv['totals']);
$t('…the quote is accepted and remembers its invoice; a second invoice is refused', $Q::get($q['id'])['status'] === 'accepted' && $Q::get($q['id'])['invoice']['number'] === $inv['number'] && $deny(function () use ($Q, $q, $owner) { $Q::to_invoice($q['id'], [], $owner); }) !== false);
$t('an accepted quote is not edited (make a copy)', $deny(function () use ($Q, $q, $ops) { $Q::save(['id' => $q['id'], 'client' => ['name' => 'x'], 'items' => [['name' => 'y', 'price' => 1]]], $ops); }) !== false);
$t('the register finds by client and by status', count($Q::lst(['q' => 'جواهر'])) === 1 && count($Q::lst(['status' => 'draft'])) === 1);

// settings
$t('settings are the owner\'s', $deny(function () use ($Q, $ops) { $Q::cfg_set(['prefix' => 'X'], $ops); }) !== false);
$t('a wrong IBAN is refused', $deny(function () use ($Q, $owner) { $Q::cfg_set(['banks' => [['bank' => 'x', 'iban' => 'SA12']]], $owner); }) !== false);
$Q::cfg_set(['prefix' => 'OXY-QTN', 'banks' => [['bank' => 'الراجحي', 'iban' => 'SA31 8000 0501 6080 1033 6547']], 'staffTitle' => ['ar' => 'مهندس الصيانة', 'en' => 'Maintenance engineer']], $owner);
$q3 = $Q::save(['client' => ['name' => 'ج'], 'items' => [['name' => 'x', 'price' => 10]]], $ops);
$t('a new prefix, IBAN with spaces cleaned, the title «مهندس الصيانة»', strpos($q3['number'], 'OXY-QTN-') === 0 && $Q::cfg()['banks'][0]['iban'] === 'SA3180000501608010336547' && $q3['staff']['title'] === 'مهندس الصيانة');

// saved wordings
$s = $Q::snippet_save(['kind' => 'warranty', 'title' => 'ضمان سنة', 'text' => 'الضمان : سنة كاملة على الكمبروسر'], $ops);
$t('a wording is saved and listed', $s['id'] !== '' && count(array_filter($Q::snippets(), function ($x) { return $x['title'] === 'ضمان سنة'; })) === 1);
$Q::snippet_del($s['id'], $ops);
$t('…and deleted', count($Q::snippets()) === 0);
$t('an empty wording is refused', $deny(function () use ($Q, $ops) { $Q::snippet_save(['text' => ' '], $ops); }) !== false);

// the AI writer: his prices and quantities win, a missing price is flagged
$s = Mutqan_AI::settings(); $s['providers']['groq'] = array_merge($s['providers']['groq'], ['enabled' => 1, 'key' => 'gsk_test']); $s['providers']['gemini'] = array_merge($s['providers']['gemini'], ['enabled' => 1, 'key' => 'g_test']); update_option('mutqan_ai_settings', $s);
$ans = '';
$GLOBALS['__post_hook'] = function ($url, $a) use (&$ans) {
    if (strpos($url, 'generativelanguage') !== false) { return ['code' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => $ans]]]]]], JSON_UNESCAPED_UNICODE), 'headers' => []]; }
    return ['code' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => $ans]]]], JSON_UNESCAPED_UNICODE), 'headers' => []];
};
$ans = json_encode(['items' => [['name' => 'مضخة حريق كهربائية 750 GPM', 'specs' => "تدفق 750 جالون/دقيقة\nضغط 10 بار\nجهد 380 فولت", 'qty' => 5, 'price' => 99999], ['name' => 'لوحة تحكم', 'specs' => 'معتمدة', 'qty' => 1, 'price' => 3000]],
    'intro' => 'يسرنا تقديم عرضنا', 'terms' => ['الدفع : 50% مقدم', 'الضمان : 3 شهور'], 'extraNote' => '', 'warnings' => []], JSON_UNESCAPED_UNICODE);
$w = $Q::ai_write(['brief' => 'عايز عرض لطلمبة حريق 750 جالون 10 بار 380 فولت ولوحتها', 'items' => [['name' => 'طلمبة حريق', 'qty' => 1, 'price' => 52000], ['name' => 'لوحة', 'qty' => 1]]], $ops);
$t('AI wording: formal names and specs', $w['items'][0]['name'] === 'مضخة حريق كهربائية 750 GPM' && mb_strpos($w['items'][0]['specs'], '10 بار') !== false, $w);
$t('…his price and quantity win (52000 ×1, not 99999 ×5)', $w['items'][0]['price'] == 52000.0 && $w['items'][0]['qty'] == 1.0, $w['items'][0]);
$t('…a price he did not give stays 0 and is flagged', $w['items'][1]['price'] == 0 && count(array_filter($w['warnings'], function ($x) { return mb_strpos($x, 'من غير سعر') !== false; })) === 1, $w);
$t('…the terms come back to edit', count($w['terms']) === 2);
$t('an empty brief is refused', $deny(function () use ($Q, $ops) { $Q::ai_write(['brief' => '', 'items' => []], $ops); }) !== false);

// the document reader
$ans = json_encode(['docType' => 'vat', 'name' => 'مؤسسة فيصل حسين بن سعيد كليب للمقاولات العامة', 'crNo' => '4031244695', 'vatNo' => '310798423700003', 'city' => 'مكة', 'district' => 'العزيزية', 'zip' => '24243'], JSON_UNESCAPED_UNICODE);
$f = $Q::extract(['mime' => 'application/pdf', 'data' => base64_encode('%PDF-1.4 test')], $ops);
$t('a VAT certificate fills the customer (name, CR, VAT, city)', $f['vatNo'] === '310798423700003' && $f['crNo'] === '4031244695' && $f['district'] === 'العزيزية', $f);
$ans = json_encode(['name' => 'x', 'vatNo' => '12345']);
$f = $Q::extract(['mime' => 'image/jpeg', 'data' => base64_encode('jpg')], $ops);
$t('…a VAT number read wrong is not filled, he is warned', $f['vatNo'] === '' && !empty($f['vatWarn']), $f);
$t('…only photos and PDFs', $deny(function () use ($Q, $ops) { $Q::extract(['mime' => 'text/html', 'data' => 'eA=='], $ops); }) !== false);
$s['providers']['gemini']['enabled'] = 0; update_option('mutqan_ai_settings', $s);
$t('…without Gemini he is told what to enable', mb_strpos((string) $deny(function () use ($Q, $ops) { $Q::extract(['mime' => 'image/png', 'data' => 'eA=='], $ops); }), 'Gemini') !== false);

// invoice: signature and the receiver's name
Mutqan_Invoice::cfg_set(['signature' => 'https://x.test/sign.png', 'signer' => 'علي زيدان'], $owner);
$iv = Mutqan_Invoice::save(['type' => 'normal', 'client' => ['name' => 'ع'], 'items' => [['name' => 'غسيل', 'price' => 100]], 'signed' => true, 'receiver' => 'أحمد', 'hideReceiver' => true], $owner);
$t('an invoice carries the signature, the signer and the receiver (hidden on request)', $iv['signed'] === true && $iv['company']['signature'] === 'https://x.test/sign.png' && $iv['receiver'] === 'أحمد' && $iv['hideReceiver'] === true, $iv);
Mutqan_Invoice::cfg_set(['signature' => ''], $owner);
$iv2 = Mutqan_Invoice::save(['type' => 'normal', 'client' => ['name' => 'ع'], 'items' => [['name' => 'غسيل', 'price' => 100]], 'signed' => true], $owner);
$t('…no signature uploaded → not signed', $iv2['signed'] === false);
$t('the ops door answers quote_cfg with statuses and the company', isset($Q::op('quote_cfg', [], $owner)['statuses']['approved']) && $Q::op('quote_cfg', [], $owner)['company']['vatNo'] === '310798423700003');

echo ($ok ? 'ALL PASS' : 'SOME FAILED') . " ($n)\n";
exit($ok ? 0 : 1);
