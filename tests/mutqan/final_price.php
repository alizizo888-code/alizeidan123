<?php
// The price at the end of a job.
//
// Found by walking one real order through the app end to end: the technician typed 200 into
// «السعر الإجمالي» and pressed «تأكيد الإنهاء»; the job closed with no price at all. Policy strips
// money fields from technician writes, and price was one of them — so his share read zero, the
// accounts read zero, and the owner had nothing to collect. Nobody saw an error.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'final_price: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$P = 'Mutqan_Policy';
$tech = ['kind' => 'user', 'role' => 'technician', 'id' => 'u-fahd', 'powers' => []];   // a technician's default: no office powers
$other = ['kind' => 'user', 'role' => 'technician', 'id' => 'u-other', 'powers' => []];
$row = ['owner_id' => 'anon-x'];
$job = ['status' => 'in_progress', 'technicianId' => 'u-fahd', 'specialtyId' => 'ac',
        'acQuote' => ['mode' => 'wash', 'type' => 'سبليت', 'qty' => 2, 'full' => true, 'warranty' => 'بدون', 'vat' => false, 'total' => 120, 'welcomeOff' => 20],
        'welcomeDiscountPct' => 30, 'qty' => 2];
$close = function ($price, $extra = []) { return array_merge(['status' => 'completed', 'report' => 'تم', 'price' => $price], $extra); };

/* ------------------------------------------------------------------ the one that was lost */
$out = $P::sanitize_update('orders', $job, $close(200), $tech, $row);
$t('the technician\'s final price is kept when he closes his own job', ($out['price'] ?? null) == 200, $out);
$t('it says who set it', ($out['priceSetBy'] ?? '') === 'technician');
$t('and keeps the quote it replaced next to it', ($out['quotedTotal'] ?? null) == 120, $out);
$t('the report still goes through with it', ($out['report'] ?? '') === 'تم');

/* ------------------------------------------------------------------ a big jump is flagged, not refused */
$near = $P::sanitize_update('orders', $job, $close(170), $tech, $row);
$t('170 on a 120 quote is within half again — no flag', ($near['price'] ?? null) == 170 && empty($near['priceReview']), $near);
$t('200 on a 120 quote is kept', ($out['price'] ?? null) == 200);
$t('but is more than half again, so it is flagged for a look before it is collected', !empty($out['priceReview']), $out);

/* ------------------------------------------------------------------ what it must not open */
$low = null; try { $P::sanitize_update('orders', $job, $close(10), $tech, $row); } catch (Mutqan_Error $e) { $low = $e->slug; }
$t('below the owner\'s floor is refused out loud, not dropped quietly', $low === 'price_below_floor', $low);
$mid = $P::sanitize_update('orders', $job, ['price' => 200, 'report' => 'x'], $tech, $row);
$t('a price without closing the job is still stripped', !isset($mid['price']), $mid);
$notHis = $P::sanitize_update('orders', $job, $close(200), $other, $row);
$t('another technician cannot price a job that is not his', !isset($notHis['price']), $notHis);
$priced = $P::sanitize_update('orders', $job + ['price' => 150], $close(200), $tech, $row);
$t('a price operations already set is not overwritten by the technician', !isset($priced['price']), $priced);
$fresh = $P::sanitize_update('orders', array_merge($job, ['status' => 'assigned']), $close(200), $tech, $row);
$t('a job he never started cannot be closed with a price', !isset($fresh['price']), $fresh);
$silly = null; try { $P::sanitize_update('orders', $job, $close(5000000), $tech, $row); } catch (Mutqan_Error $e) { $silly = $e->slug; }
$t('a mistyped number with extra zeros is refused', $silly === 'bad_price', $silly);
$sneak = $P::sanitize_update('orders', $job, $close(200, ['agreedPrice' => 1, 'paidRecordedBy' => 'x', 'priceReview' => false]), $tech, $row);
$t('the other money fields stay stripped', !isset($sneak['agreedPrice']) && ($sneak['price'] ?? null) == 200);

/* ------------------------------------------------------------------ the quote, read one way */
$t('the AC quote counts as what was quoted', $P::quoted_total(['acQuote' => ['total' => 120]]) == 120.0);
$t('an agreed price wins over the calculator', $P::quoted_total(['agreedPrice' => 180, 'acQuote' => ['total' => 120]]) == 180.0);
$t('nothing quoted is zero, not an error', $P::quoted_total([]) == 0.0);

/* ------------------------------------------------------------------ his own account, nobody else's */
$mine = ['owner_id' => 'u-owner', 'data' => json_encode(['techId' => 'u-fahd', 'amount' => 140, 'dir' => 'in'])];
$theirs = ['owner_id' => 'u-owner', 'data' => json_encode(['techId' => 'u-other', 'amount' => 999, 'dir' => 'in'])];
$t('a technician can read what he handed in', $P::can('read', 'settlements', $tech, $mine) === true);
$t('but not what a colleague handed in', $P::can('read', 'settlements', $tech, $theirs) === false);
$t('and cannot write a settlement himself', $P::can('insert', 'settlements', $tech, null, ['techId' => 'u-fahd']) === false);
list($where, $args) = $P::read_clause('settlements', $tech);
$t('his list is filtered to his own lines on the server', strpos($where, 'techId') !== false && $args === ['u-fahd'], [$where, $args]);
$t('a customer reads none', $P::read_clause('settlements', ['kind' => 'anon', 'role' => null, 'id' => 'c'])[0] === '0');
$opsP = ['kind' => 'user', 'role' => 'operations', 'id' => 'o', 'powers' => ['read', 'write', 'manage']];
$t('the office reads every line', $P::read_clause('settlements', $opsP)[0] === '');

echo $ok ? "OK final_price\n" : "FAILED final_price\n";
exit($ok ? 0 : 1);
