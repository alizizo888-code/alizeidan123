<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . $n . "\n"; if (!$c) { $ok = false; } };
$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
$S = 'Mutqan_Shop';
// pure ladder
$t('offer >= price -> accepted at list price', $S::ladder_reply(1000, 800, 1200, 1) === ['accepted', 1000]);
$t('offer between floor and price -> accepted at offer', $S::ladder_reply(1000, 800, 900, 1) === ['accepted', 900]);
list($s1, $c1) = $S::ladder_reply(1000, 800, 500, 1); list($s2, $c2) = $S::ladder_reply(1000, 800, 500, 2); list($s3, $c3) = $S::ladder_reply(1000, 800, 500, 3);
$t('counter 1 above floor and below list', $s1 === 'counter' && $c1 > 800 && $c1 < 1000);
$t('counter 2 lower than counter 1', $s2 === 'counter' && $c2 < $c1 && $c2 >= 800);
$t('3rd low offer -> final = floor', $s3 === 'final' && $c3 === 800);
// end to end
Mutqan_Store::insert('used_ac', ['title' => 'سبليت 18000 مستعمل', 'kind' => 'split', 'price' => 1000, 'status' => 'available'], 'u1', [], $sys);
Mutqan_Store::insert('used_ac_private', ['floor' => 800], 'u1', [], $sys);
$r = $S::handle(['op' => 'offer', 'unitId' => 'u1', 'price' => 500, 'name' => 'أحمد', 'phone' => '0551234567'], ['kind' => 'guest']);
$t('first low offer -> counter, floor not leaked', $r['status'] === 'counter' && $r['price'] > 800 && strpos(json_encode($r, JSON_UNESCAPED_UNICODE), '800') === false);
$r2 = $S::handle(['op' => 'offer', 'unitId' => 'u1', 'price' => 600, 'name' => 'أحمد', 'phone' => '0551234567'], ['kind' => 'guest']);
$t('attempt counted per phone+unit', $r2['attempt'] === 2);
$r3 = $S::handle(['op' => 'offer', 'unitId' => 'u1', 'price' => $r['price'], 'name' => 'أحمد', 'phone' => '0551234567'], ['kind' => 'guest']);
$t('accepting the counter -> accepted', $r3['status'] === 'accepted' && $r3['price'] === $r['price']);
$saved = Mutqan_Store::get_raw('used_offers', 'o' . substr(md5('u1|966551234567'), 0, 24));
$t('offer stored for the team with phone', !empty($saved) && $saved['phone'] === '966551234567' && $saved['attempts'] === 3);
try { $S::handle(['op' => 'offer', 'unitId' => 'u1', 'price' => 900, 'name' => 'x', 'phone' => '123'], ['kind' => 'guest']); $t('bad phone rejected', false); } catch (Mutqan_Error $e) { $t('bad phone rejected', $e->slug === 'bad_phone'); }
Mutqan_Store::update('used_ac', 'u1', ['status' => 'sold'], $sys);
try { $S::handle(['op' => 'offer', 'unitId' => 'u1', 'price' => 900, 'name' => 'x', 'phone' => '0551234567'], ['kind' => 'guest']); $t('sold unit rejected', false); } catch (Mutqan_Error $e) { $t('sold unit rejected', $e->slug === 'unavailable'); }
// public catalogue is readable by guests, the private floor is not
$guest = ['id' => 'anon1', 'role' => null, 'powers' => [], 'kind' => 'guest'];
$pub = Mutqan_Store::select('used_ac', [], [], $guest);
$t('guest can read catalogue', is_array($pub) && count($pub) >= 1);
$prv = Mutqan_Store::select('used_ac_private', [], [], $guest);
$t('guest cannot read private floor', empty($prv));
// order seal
$sr = $S::handle(['op' => 'seal', 'service' => 'صيانة مكيف', 'price' => 250, 'time' => 'اليوم 5م', 'address' => 'مكة', 'ref' => 'AB123'], ['kind' => 'guest']);
$t('seal returns short url', preg_match('#/v/[A-Z0-9]{8}-[a-f0-9]{6}$#', $sr['url']) === 1);
$tok = substr($sr['url'], strrpos($sr['url'], '/v/') + 3);
$t('seal verified', $S::seal_check($tok)[0] === 'verified');
$t('seal wrong checksum -> invalid', $S::seal_check(substr($tok, 0, 9) . 'ffffff')[0] === 'invalid');
$t('seal no checksum -> invalid', $S::seal_check(substr($tok, 0, 8))[0] === 'invalid');
$t('seal unknown code -> missing', $S::seal_check('ZZZZZZZZ-aaaaaa')[0] === 'missing');
$t('seal garbage -> missing', $S::seal_check('x')[0] === 'missing');
$t('seal not readable by guest', empty(Mutqan_Store::select('order_seals', [], [], $guest)));
exit($ok ? 0 : 1);
