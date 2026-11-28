<?php
// «جلب العملاء» (owner, 2026-11-23 — ideas 16–21).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'growth: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$sys = ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']];
$ops = ['kind' => 'user', 'role' => 'operations', 'id' => 'u-ops', 'powers' => ['read', 'write']];
$tech = ['kind' => 'user', 'role' => 'technician', 'id' => 'u-t', 'powers' => []];
$sh = ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'];
$G = 'Mutqan_Growth';
$o = ['id' => 'x1', 'clientPhone' => '+966551112222', 'clientName' => 'سامي'];

/* 16 — Google review: off until switched on with a link */
$t('16 · off by default: nothing after a 5-star rating', $G::after_rating($o, 5) === '');
$G::handle(['op' => 'set', 'cfg' => ['reviewOn' => true]], $ops);
$t('16 · switched on but no link yet: still nothing', $G::after_rating($o, 5) === '');
$err = ''; try { $G::handle(['op' => 'set', 'cfg' => ['reviewUrl' => 'http://x']], $ops); } catch (Mutqan_Error $e) { $err = $e->getMessage(); }
$t('16 · the link must be https', $err !== '');
$G::handle(['op' => 'set', 'cfg' => ['reviewUrl' => 'https://g.page/r/abc/review']], $ops);
$t('16 · on + link: 5 stars get the review link', strpos($G::after_rating($o, 5), 'https://g.page/r/abc/review') !== false);
$t('16 · 4 stars do not', strpos($G::after_rating($o, 4), 'g.page') === false);
$err = ''; try { $G::handle(['op' => 'set', 'cfg' => ['reviewOn' => false]], $tech); } catch (Mutqan_Error $e) { $err = $e->getMessage(); }
$t('a technician cannot change it', $err !== '');

/* 20 — referral */
$G::handle(['op' => 'set', 'cfg' => ['referralOn' => true, 'referralPct' => 15]], $ops);
$code = $G::ref_code($o['clientPhone']);
$txt = $G::after_rating($o, 5);
$t('20 · the customer gets a wa.me link carrying his own code and the discount', strpos($txt, 'wa.me/') !== false && strpos($txt, rawurlencode($code)) !== false && strpos($txt, '15%') !== false, $txt);
$t('20 · the code does not reveal his number', strpos($code, '1112222') === false && preg_match('/^R[0-9A-F]{6}$/', $code) === 1, $code);
$t('20 · a message with the code is remembered for the friend\'s number', $G::note_referral('0559998888', 'السلام عليكم، جاي بترشيح من صاحبي — كود الترشيح ' . $code) === $code);
$old = Mutqan_Store::insert('orders', ['status' => 'completed', 'clientPhone' => '+966551112222', 'clientName' => 'سامي', 'createdAt' => gmdate('c', time() - 90 * 86400)], null, $sh, $sys);
$new = Mutqan_Store::insert('orders', ['status' => 'new', 'clientPhone' => '+966559998888', 'clientName' => 'صاحبه', 'createdAt' => gmdate('c')], null, $sh, $sys);
$rows = Mutqan_Store::select('orders', [], ['limit' => 50], $sys);
$t('20 · the friend\'s order is marked with who brought him', $G::attach_referrals($rows) === 1 && (Mutqan_Store::get_raw('orders', $new['id'])['referral']['byName'] ?? '') === 'سامي', Mutqan_Store::get_raw('orders', $new['id'])['referral'] ?? null);

/* 17 — seasonal reminder */
$now = time();
$mk = function ($days, $phone, $extra = []) use ($now) { return array_merge(['id' => 'o' . $days . $phone, 'status' => 'completed', 'clientPhone' => $phone, 'createdAt' => gmdate('c', $now - $days * 86400), 'completedAt' => gmdate('c', $now - $days * 86400)], $extra); };
$due = $G::seasonal_due([$mk(123, '0551'), $mk(60, '0552'), $mk(125, '0553'), $mk(10, '0553', ['status' => 'new']), $mk(124, '0554', ['growth' => ['seasonalAt' => 'x']])], $now, 4);
$ids = array_map(function ($x) { return $x['clientPhone']; }, $due);
$t('17 · 4 months on: reminded', in_array('0551', $ids, true), $ids);
$t('17 · 2 months on: not yet', !in_array('0552', $ids, true));
$t('17 · ordered again since: left alone', !in_array('0553', $ids, true));
$t('17 · reminded once only', !in_array('0554', $ids, true));
$t('17 · off by default (no messages)', $G::seasonal_tick() === 0);
$t('17 · the text offers to book', strpos($G::seasonal_text(['clientName' => 'سامي'], $G::cfg()), 'احجز') !== false);

/* 19 — contracts */
$k = Mutqan_Store::insert('contracts', ['company' => 'مطعم البيك — فرع العزيزية', 'phone' => '0555555555', 'address' => 'مكة العزيزية', 'specialtyId' => 'ac', 'units' => 12, 'visitsPerYear' => 4, 'startDate' => gmdate('c', time() - 3600), 'active' => true], null, $sh, $sys);
$made = $G::contracts_tick();
$orders = Mutqan_Store::select('orders', ['contractId' => $k['id']], ['limit' => 10], $sys);
$kk = Mutqan_Store::get_raw('contracts', $k['id']);
$t('19 · a due visit becomes an order by itself', $made === 1 && count($orders) === 1 && ($orders[0]['source'] ?? '') === 'contract', [$made, count($orders)]);
$t('19 · … and the next visit is 3 months on', abs(strtotime($kk['nextVisitAt']) - strtotime('+3 months', strtotime(gmdate('c', time() - 3600)))) < 172800, $kk['nextVisitAt'] ?? null);
$t('19 · running again does not make a second order', $G::contracts_tick() === 0);

/* prices of the big units (gap closed 2026-11-23) */
$t('big units: nothing set → the old «بعد المعاينة» wording stays', Mutqan_Policy::big_price_text('duct', 2) === '');
Mutqan_Store::upsert('pricing', ['ductFrom' => 250, 'ductTo' => 400, 'bigInspectFee' => 100], 'ac_calc', ['groupId' => 'main', 'visibleTo' => 'everyone', 'writableBy' => 'admins'], $sys);
$bt = Mutqan_Policy::big_price_text('duct', 2);
$t('big units: range per unit, the total for the count, the inspection fee deducted when we do it', strpos($bt, 'من 250 لـ 400') !== false && strpos($bt, '500–800') !== false && strpos($bt, 'المعاينة 100 ر.س وبتتخصم') !== false, $bt);
$t('big units: a type without a range still shows the inspection fee', strpos(Mutqan_Policy::big_price_text('central', 1), 'المعاينة 100') !== false);

/* 18 / 21 already running */
$st = $G::handle(['op' => 'get'], $ops)['status'];
$t('18 / 21 are reported as already running parts', array_key_exists('winback', $st) && array_key_exists('returning', $st), $st);

echo $ok ? "OK growth\n" : "FAILED growth\n";
exit($ok ? 0 : 1);
