<?php
// What the team writes about a customer never reaches the customer — not on screen, not through the API.
//
// The order is the customer's own document, and he can read it. The technician's opinion of him
// («بيفاصل في السعر», «صعب التعامل») is written on that same order, so without this he could read
// it with one request. These checks come at it from every side a curious customer could try.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'hidden: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$S = 'Mutqan_Store';
$cust = ['kind' => 'anon', 'role' => null, 'id' => 'anon-cust-1', 'powers' => []];
$other = ['kind' => 'anon', 'role' => null, 'id' => 'anon-cust-2', 'powers' => []];
$tech = ['kind' => 'user', 'role' => 'technician', 'id' => 'u-fahd', 'powers' => []];
$ops = ['kind' => 'user', 'role' => 'operations', 'id' => 'u-ops', 'powers' => ['read', 'write', 'manage']];
$sys = ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']];

$o = $S::insert('orders', ['status' => 'new', 'specialtyId' => 'ac', 'clientName' => 'خالد', 'clientPhone' => '966500000001', 'description' => 'غسيل'], null, ['groupId' => 'main', 'visibleTo' => 'creator-and-admin'], $cust);
$id = $o['id'];
$S::update('orders', $id, ['technicianId' => 'u-fahd', 'status' => 'in_progress'], $sys);
$S::update('orders', $id, ['techOnClient' => ['stars' => 2, 'tags' => ['بيفاصل في السعر'], 'note' => 'صعب'], 'priceReview' => true, 'quotedTotal' => 120], $sys);

/* ------------------------------------------------------------------ the team sees it */
$asTech = $S::select('orders', ['technicianId' => 'u-fahd'], ['limit' => 5], $tech);
$t('the technician reads his opinion back', ($asTech[0]['techOnClient']['tags'][0] ?? '') === 'بيفاصل في السعر', $asTech[0] ?? null);
$asOps = $S::select('orders', [], ['limit' => 5], $ops);
$t('operations read it', isset($asOps[0]['techOnClient']) && !empty($asOps[0]['priceReview']));

/* ------------------------------------------------------------------ the customer does not, from any side */
$mine = $S::select('orders', [], ['limit' => 5], $cust);
$t('the customer still reads his own order', count($mine) === 1 && ($mine[0]['description'] ?? '') === 'غسيل', $mine);
$t('without the technician\'s opinion of him', !array_key_exists('techOnClient', $mine[0]), array_keys($mine[0]));
$t('without the price-review flag or the replaced quote', !array_key_exists('priceReview', $mine[0]) && !array_key_exists('quotedTotal', $mine[0]));
$e = null; try { $S::select('orders', ['techOnClient.stars' => 2], [], $cust); } catch (Mutqan_Error $x) { $e = $x->slug; }
$t('filtering on it to guess its value is refused', $e === 'bad_field', $e);
$e = null; try { $S::group_by('orders', 'techOnClient.note', [], 10, $cust); } catch (Mutqan_Error $x) { $e = $x->slug; }
$t('grouping by it (which would print it as a key) is refused', $e === 'bad_field', $e);
$e = null; try { $S::count('orders', ['priceReview' => true], $cust); } catch (Mutqan_Error $x) { $e = $x->slug; }
$t('counting on it is refused', $e === 'bad_field', $e);
$e = null; try { $S::sum('orders', 'quotedTotal', [], $cust); } catch (Mutqan_Error $x) { $e = $x->slug; }
$t('summing it is refused', $e === 'bad_field', $e);
$e = null; try { $S::select('orders', [], ['order' => '-techOnClient.stars'], $cust); } catch (Mutqan_Error $x) { $e = $x->slug; }
$t('sorting by it is refused', $e === 'bad_field', $e);
$back = $S::update('orders', $id, ['status' => 'completed'], $sys);
$t('an internal write still gets the whole order back', isset($back['techOnClient']));
$S::update('orders', $id, ['status' => 'completed'], $sys);
$rated = $S::update('orders', $id, ['rating' => 5, 'ratingComment' => 'ممتاز'], $cust);
$t('when the customer rates his order, the reply he gets has no opinion in it', is_array($rated) && !array_key_exists('techOnClient', $rated) && ($rated['rating'] ?? 0) == 5, $rated ? array_keys($rated) : $rated);
$t('another customer sees nothing at all', $S::select('orders', [], [], $other) === []);
$t('and a plain field filter still works for the customer', count($S::select('orders', ['status' => 'completed'], [], $cust)) === 1);

echo $ok ? "OK hidden\n" : "FAILED hidden\n";
exit($ok ? 0 : 1);
