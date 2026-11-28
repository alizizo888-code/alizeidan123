<?php
// Customers & companies (owner, 2026-11-22): one file per customer built from invoices and quotes, his papers in a
// private vault (signed links, shared links that die), his history and sums, returns against his invoices, and more
// than one issuing company with its own numbers.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $n = 0;
$t = function ($name, $c, $x = null) use (&$ok, &$n) { $n++; echo ($c ? 'PASS ' : 'FAIL ') . 'accounts: ' . $name . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$owner = ['id' => 'u1', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'علي'];
$ops = ['id' => 'u2', 'role' => 'operations', 'powers' => ['read', 'write'], 'kind' => 'user', 'name' => 'العمليات'];
$sup = ['id' => 'u3', 'role' => 'supervisor', 'powers' => ['read'], 'kind' => 'user', 'name' => 'مشرف'];
$deny = function ($fn) { try { $fn(); return false; } catch (Mutqan_Error $e) { return $e->getMessage() ?: true; } };
$A = 'Mutqan_Accounts'; $I = 'Mutqan_Invoice'; $Q = 'Mutqan_Quote'; $V = 'Mutqan_Vault';

// a company from a tax invoice, the same company from a quote with a different spelling of the phone
$i1 = $I::save(['type' => 'tax', 'client' => ['name' => 'شركة جواهر المعادن', 'vatNo' => '310132544200003', 'phone' => '0510145304'], 'items' => [['name' => 'غسيل مكيف سبليت', 'qty' => 4, 'price' => 115]], 'stamped' => true], $ops);
$t('a tax invoice creates the company\'s file', $i1['clientId'] !== '' && $A::profile($i1['clientId'])['client']['kind'] === 'company');
$q1 = $Q::save(['client' => ['name' => 'شركة جواهر المعادن للتصنيع', 'vatNo' => '310132544200003', 'crNo' => '4030000001', 'responsible' => 'اسلام'], 'items' => [['name' => 'طلمبة', 'price' => 52000]]], $ops);
$t('a quote for the same VAT number lands in the same file, and fills what was missing (CR, responsible)', $q1['clientId'] === $i1['clientId'] && $A::profile($i1['clientId'])['client']['crNo'] === '4030000001' && $A::profile($i1['clientId'])['client']['responsible'] === 'اسلام');
$t('…the first name is kept (not overwritten by the quote\'s)', $A::profile($i1['clientId'])['client']['name'] === 'شركة جواهر المعادن');
$i2 = $I::save(['type' => 'normal', 'client' => ['name' => 'أبو سعد', 'phone' => '966551110001'], 'items' => [['name' => 'شحن فريون', 'price' => 200]]], $ops);
$i3 = $I::save(['type' => 'normal', 'client' => ['name' => 'أبو سعد', 'phone' => '0551110001'], 'items' => [['name' => 'غسيل', 'price' => 100]]], $ops);
$t('a person by phone (966… or 05…) is one file', $i2['clientId'] === $i3['clientId'] && $A::profile($i2['clientId'])['client']['phone'] === '0551110001');
$i4 = $I::save(['type' => 'normal', 'clientId' => $i2['clientId'], 'client' => ['name' => 'أبو سعد'], 'items' => [['name' => 'تركيب', 'price' => 300]]], $ops);
$t('a new invoice from his file stays in it', $i4['clientId'] === $i2['clientId']);

// his page: invoices, quotes, sums, top items
$pf = $A::profile($i1['clientId']);
$t('the company page: 1 invoice 460, VAT, 1 quote, top item', $pf['stats']['invoices'] === 1 && $pf['stats']['invoiced'] == 460.0 && $pf['stats']['vat'] == 60.0 && $pf['stats']['quotes'] === 1 && $pf['top'][0]['name'] === 'غسيل مكيف سبليت' && $pf['top'][0]['qty'] == 4.0, $pf['stats']);
$pp = $A::profile($i2['clientId']);
$t('the person page: 3 invoices, 600', $pp['stats']['invoices'] === 3 && $pp['stats']['invoiced'] == 600.0);
$l = $A::lst(['q' => 'جواهر']);
$t('the list finds by name, with counts and sums', count($l) === 1 && $l[0]['invoices'] === 1 && $l[0]['quotes'] === 1 && $l[0]['net'] == 460.0);
$t('…and by VAT number', count($A::lst(['q' => '310132544200003'])) === 1);
$t('…companies only', count($A::lst(['kind' => 'company'])) === 1);

// papers: private, signed, shared links die
$pdf = base64_encode("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
$f = $A::upload(['id' => $i1['clientId'], 'data' => $pdf, 'mime' => 'application/pdf', 'name' => 'السجل التجاري', 'kind' => 'cr'], $ops);
$t('a paper is uploaded to his shelf', count($A::profile($i1['clientId'])['files']) === 1 && $A::profile($i1['clientId'])['files'][0]['kindLabel'] === 'السجل التجاري');
$raw = $V::get($f['id']);
$t('…kept in the private folder (with a deny-all .htaccess), not in public uploads', is_file($V::dir() . '/' . $raw['file']) && strpos((string) file_get_contents($V::dir() . '/.htaccess'), 'denied') !== false);
parse_str(parse_url($f['url'], PHP_URL_QUERY), $qs);
$t('…opened by a signed link (expiry + signature)', !empty($qs['e']) && !empty($qs['s']) && strpos($f['url'], '/vault/') !== false);
$sh = $A::share($f['id'], 3, $ops);
parse_str(parse_url($sh['url'], PHP_URL_QUERY), $q2);
$t('a shared link lives the days asked (3), never more than 30', abs((int) $q2['e'] - (time() + 3 * 86400)) < 5 && $A::share($f['id'], 99, $ops)['days'] === 30);
$t('a supervisor cannot upload or share', $deny(function () use ($A, $i1, $pdf, $sup) { $A::upload(['id' => $i1['clientId'], 'data' => $pdf, 'mime' => 'application/pdf'], $sup); }) !== false && $deny(function () use ($A, $f, $sup) { $A::share($f['id'], 7, $sup); }) !== false);
$t('only pictures and PDFs', $deny(function () use ($A, $i1, $ops) { $A::upload(['id' => $i1['clientId'], 'data' => base64_encode('<html>'), 'mime' => 'text/html'], $ops); }) !== false);
$t('a disguised file (HTML named PDF) is refused', $deny(function () use ($A, $i1, $ops) { $A::upload(['id' => $i1['clientId'], 'data' => base64_encode('<html><script>x</script></html>'), 'mime' => 'application/pdf'], $ops); }) !== false);
$A::remove_file($i1['clientId'], $f['id'], $ops);
$t('a paper is removed from the shelf and the disk', count($A::profile($i1['clientId'])['files']) === 0 && !is_file($V::dir() . '/' . $raw['file']));

// a read document becomes his paper when the invoice is saved
$s = Mutqan_AI::settings(); $s['providers']['gemini'] = array_merge($s['providers']['gemini'], ['enabled' => 1, 'key' => 'g']); update_option('mutqan_ai_settings', $s);
$GLOBALS['__post_hook'] = function ($url, $a) { return ['code' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode(['docType' => 'vat', 'name' => 'مؤسسة الندى', 'vatNo' => '300000000000003'], JSON_UNESCAPED_UNICODE)]]]]]], JSON_UNESCAPED_UNICODE), 'headers' => []]; };
$ex = $Q::extract(['mime' => 'application/pdf', 'data' => $pdf], $ops);
$t('a read VAT certificate comes back with its kept file', !empty($ex['file']['id']) && $ex['file']['kind'] === 'vat' && $ex['vatNo'] === '300000000000003', $ex);
$i5 = $I::save(['type' => 'tax', 'client' => ['name' => $ex['name'], 'vatNo' => $ex['vatNo']], 'items' => [['name' => 'صيانة', 'price' => 1150]], 'fileIds' => [$ex['file']['id']]], $ops);
$t('…and lands on the new company\'s shelf with the invoice', count($A::profile($i5['clientId'])['files']) === 1);

// returns
$rt = $I::returnable($i1['id']);
$t('what is left to return: 4 on the line', $rt['rows'][0]['left'] == 4.0);
$t('a return needs a reason and lines', $deny(function () use ($I, $i1, $ops) { $I::credit(['ref' => $i1['id'], 'reason' => '', 'lines' => [['no' => 1, 'qty' => 1]]], $ops); }) !== false && $deny(function () use ($I, $i1, $ops) { $I::credit(['ref' => $i1['id'], 'reason' => 'x', 'lines' => []], $ops); }) !== false);
$c1 = $I::credit(['ref' => $i1['id'], 'reason' => 'مكيف ما اتغسلش', 'lines' => [['no' => 1, 'qty' => 1]], 'stamped' => true], $ops);
$t('a credit note: its own number 1, names the invoice, 115 incl. 15 VAT, QR', $c1['type'] === 'credit' && $c1['number'] === 1 && $c1['ref']['number'] === $i1['number'] && $c1['totals']['net'] == 115.0 && $c1['totals']['vat'] == 15.0 && !empty($c1['qr']), $c1['totals']);
$t('…the invoice knows it (returned 115), 3 left', (float) $I::get($i1['id'])['returnedNet'] === 115.0 && $I::returnable($i1['id'])['rows'][0]['left'] == 3.0);
$t('never more than is left', $deny(function () use ($I, $i1, $ops) { $I::credit(['ref' => $i1['id'], 'reason' => 'x', 'lines' => [['no' => 1, 'qty' => 4]]], $ops); }) !== false);
$t('an invoice with returns cannot be voided', $deny(function () use ($I, $i1, $owner) { $I::void($i1['id'], 'x', $owner); }) !== false);
$t('the company page: returned 115, net 345', $A::profile($i1['clientId'])['stats']['returned'] == 115.0 && $A::profile($i1['clientId'])['stats']['net'] == 345.0 && count($A::profile($i1['clientId'])['credits']) === 1);
$I::void($c1['id'], 'غلط', $owner);
$t('voiding the return gives the quantity back', $I::returnable($i1['id'])['rows'][0]['left'] == 4.0 && (float) $I::get($i1['id'])['returnedNet'] === 0.0);
$cn = $I::credit(['ref' => $i2['id'], 'reason' => 'رجّع', 'lines' => [['no' => 1, 'qty' => 1]]], $ops);
$t('a plain invoice\'s return: no VAT, no QR, next number 2', $cn['number'] === 2 && $cn['totals']['vat'] == 0 && empty($cn['qr']) && $cn['totals']['net'] == 200.0);
$t('a return of a return is refused', $deny(function () use ($I, $cn, $ops) { $I::credit(['ref' => $cn['id'], 'reason' => 'x', 'lines' => [['no' => 1, 'qty' => 1]]], $ops); }) !== false);

// more than one issuing company
$I::cfg_set(['issuers' => [['nameAr' => 'مؤسسة متقن للصيانة', 'vatNo' => '311111111100003', 'crNo' => '4031111111', 'next' => ['tax' => 500]]], 'cats' => ['غسيل', 'قطع غيار', 'كهرباء'],
    'items' => [['code' => '9', 'name' => 'فيوز', 'cat' => 'كهرباء', 'price' => 20, 'specs' => '10 أمبير'], ['code' => '10', 'name' => 'كابل', 'cat' => 'جديد', 'price' => 5]]], $owner);
$cfg = $I::cfg();
$t('an extra issuer is saved with its own numbers', count($cfg['issuers']) === 1 && $cfg['issuers'][0]['next']['tax'] === 500);
$t('categories, and a new category from an item is added', in_array('كهرباء', $cfg['cats'], true) && in_array('جديد', $cfg['cats'], true) && $cfg['items'][0]['specs'] === '10 أمبير');
$m = $I::save(['type' => 'tax', 'issuer' => $cfg['issuers'][0]['id'], 'client' => ['name' => 'س'], 'items' => [['name' => 'ص', 'price' => 115]]], $ops);
$t('an invoice from the other company: its VAT number, its numbers (500)', $m['number'] === 500 && $m['company']['vatNo'] === '311111111100003' && $m['issuer'] === $cfg['issuers'][0]['id']);
$m2 = $I::save(['type' => 'tax', 'client' => ['name' => 'س'], 'items' => [['name' => 'ص', 'price' => 115]]], $ops);
$t('…the main company keeps its own sequence', $m2['number'] === 3 && $m2['company']['vatNo'] === '310798423700003', $m2['number']);
$t('…and the other moves on (501)', $I::save(['type' => 'tax', 'issuer' => $cfg['issuers'][0]['id'], 'client' => ['name' => 'س'], 'items' => [['name' => 'ص', 'price' => 115]]], $ops)['number'] === 501);
$t('a wrong VAT number for an issuer is refused', $deny(function () use ($I, $owner) { $I::cfg_set(['issuers' => [['nameAr' => 'x', 'vatNo' => '123']]], $owner); }) !== false);

// branches: the place printed on the paper
$I::cfg_set(['branches' => [['id' => 'mkk', 'name' => 'مكة — العزيزية', 'cityLine' => 'مكة المكرمة — العزيزية', 'address' => 'مكة – العزيزية'], ['name' => 'جدة', 'cityLine' => 'جدة — حي الصفا', 'address' => 'جدة – حي الصفا', 'phones' => ['0550000001']]], 'branch' => 'mkk'], $owner);
$br = $I::cfg()['branches'];
$t('two branches saved (Makkah, Jeddah)', count($br) === 2 && $br[1]['cityLine'] === 'جدة — حي الصفا');
$j = $I::save(['type' => 'normal', 'branch' => $br[1]['id'], 'client' => ['name' => 'س'], 'items' => [['name' => 'ص', 'price' => 10]]], $ops);
$t('an invoice from Jeddah prints Jeddah (city, address, phone)', $j['company']['cityLine'] === 'جدة — حي الصفا' && $j['company']['address'] === 'جدة – حي الصفا' && $j['company']['phones'] === ['0550000001'] && $j['company']['branch'] === 'جدة', $j['company']);
$mk = $I::save(['type' => 'normal', 'client' => ['name' => 'س'], 'items' => [['name' => 'ص', 'price' => 10]]], $ops);
$t('…the default branch is Makkah', $mk['company']['cityLine'] === 'مكة المكرمة — العزيزية');
$qj = $Q::save(['branch' => $br[1]['id'], 'client' => ['name' => 'س'], 'items' => [['name' => 'ص', 'price' => 10]]], $ops);
$t('a quote from Jeddah carries Jeddah', $qj['company']['cityLine'] === 'جدة — حي الصفا' && $qj['branch'] === $br[1]['id']);

// the details QR: a signed page any camera opens
$g = $I::get($i1['id']);
$q1 = explode('?mqi=', $g['viewUrl']);
$t('every invoice has its details link: short and signed (/?mqi=…, 34 chars)', count($q1) === 2 && strlen($q1[1]) === 34 && strlen($g['viewUrl']) < 70, $g['viewUrl']);
$pp = $I::parse_short($q1[1]);
$t('…the short link gives back the invoice and a valid signature', $pp && $pp[0] === $i1['id'] && $I::check($pp[0], $pp[1]));
$t('…another invoice has another link', $I::get($i2['id'])['viewUrl'] !== $g['viewUrl']);
$t('…a changed letter is refused', !$I::check($i1['id'], substr_replace($pp[1], $pp[1][0] === 'a' ? 'b' : 'a', 0, 1)) && $I::parse_short('xx') === null);
$old = substr(hash_hmac('sha256', 'inv|' . $i1['id'], hash('sha256', (defined('AUTH_KEY') ? AUTH_KEY : 'mq') . '|inv')), 0, 20);
$t('…links printed before (20-char signature) still open', $I::check($i1['id'], $old));
$raw = base64_decode($g['qr']); $tags = []; for ($k = 0; $k < strlen($raw);) { $tag = ord($raw[$k]); $len = ord($raw[$k + 1]); $tags[$tag] = substr($raw, $k + 2, $len); $k += 2 + $len; }
$t('the ZATCA QR stays exactly the 5 fields (seller, VAT, time, total, VAT)', array_keys($tags) === [1, 2, 3, 4, 5] && $tags[2] === '310798423700003' && $tags[4] === '460.00' && $tags[5] === '60.00', $tags);
$t('…the time is UTC with Z, as the authority\'s reader expects', (bool) preg_match('/^\\d{4}-\\d\\d-\\d\\dT\\d\\d:\\d\\d:\\d\\dZ$/', $tags[3]), $tags[3]);
$z = base64_decode($I::zatca_qr('شركة', '310798423700003', '2026-11-22T06:45:00+03:00', 115, 15)); 
$t('…a local time is turned into UTC Z', strpos($z, '2026-11-22T03:45:00Z') !== false);
Mutqan_Store::update('invoices', $i1['id'], ['qr' => base64_encode(chr(1) . chr(1) . 'x')], $owner);
$raw2 = base64_decode($I::get($i1['id'])['qr']);
$t('…an invoice issued before the fix is repaired when opened', strpos($raw2, '310798423700003') !== false && strpos($raw2, 'Z') !== false);

// each QR can be hidden from the settings
$I::cfg_set(['qrDetails' => false], $owner); $h1 = $I::get($i1['id']);
$t('details QR hidden → no details link, ZATCA kept', $h1['viewUrl'] === '' && $h1['qr'] !== '');
$I::cfg_set(['qrDetails' => true, 'qrZatca' => false], $owner); $h2 = $I::get($i1['id']);
$t('ZATCA QR hidden → no ZATCA code, details kept', $h2['qr'] === '' && $h2['viewUrl'] !== '');
$I::cfg_set(['qrZatca' => true], $owner); $h3 = $I::get($i1['id']);
$t('both shown again', $h3['qr'] !== '' && $h3['viewUrl'] !== '');

// a customer with invoices is never deleted; one without can be (owner)
$t('a customer with invoices cannot be deleted', $deny(function () use ($A, $i1, $owner) { $A::remove($i1['clientId'], $owner); }) !== false);
$e = $A::save(['name' => 'عميل تجربة', 'phone' => '0500000000'], $ops);
$t('a customer added by hand', $e['client']['name'] === 'عميل تجربة');
$t('…edited, a duplicate VAT number refused', $deny(function () use ($A, $e, $ops) { $A::save(['id' => $e['client']['id'], 'name' => 'عميل تجربة', 'vatNo' => '310132544200003'], $ops); }) !== false);
$t('…deleted by the owner only', $deny(function () use ($A, $e, $ops) { $A::remove($e['client']['id'], $ops); }) !== false && $A::remove($e['client']['id'], $owner)['ok'] === true);

echo ($ok ? 'ALL PASS' : 'SOME FAILED') . " ($n)\n";
exit($ok ? 0 : 1);
