<?php
// The internal engine: owner/operations commands that run without any AI provider, the points wallet,
// and the assistant never answering with a bare "busy" error.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-quick-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "quick: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$owner = ['id' => 'u-own', 'role' => 'owner', 'kind' => 'user', 'powers' => ['read', 'write', 'manage']];
$ops = ['id' => 'u-ops', 'role' => 'operations', 'kind' => 'user', 'powers' => ['read', 'write', 'manage']];
$sup = ['id' => 'u-sup', 'role' => 'supervisor', 'kind' => 'user', 'powers' => ['read']];

// recognising commands
$m = Mutqan_Quick::match('اسند طلب ١٠٢٤ لمحمود');
$t('assign with Arabic digits', $m && $m['tool'] === 'assign' && $m['input']['number'] === '1024' && $m['input']['tech'] === 'محمود', $m);
$m = Mutqan_Quick::match('نضف المواقع كلها من الكاش باك');
$t('network cache purge', $m && $m['tool'] === 'cache_purge' && $m['input']['all'], $m);
$t('order story', (Mutqan_Quick::match('طلب 77')['tool'] ?? '') === 'order_story');
$t('new orders', (Mutqan_Quick::match('الطلبات الجديدة')['input']['status'] ?? '') === 'new');
$t('where techs → the live map answer', (Mutqan_Quick::match('فين الفنيين')['tool'] ?? '') === 'route');
$t('money week', (Mutqan_Quick::match('فلوس الاسبوع')['input']['days'] ?? 0) === 7);
$t('free text goes to the model', Mutqan_Quick::match('اكتب مقال عن تنظيف المكيف الشباك') === null);

// running them with no AI provider at all
Mutqan_Store::insert('tech_profiles', ['userId' => 'u-t1', 'displayName' => 'محمود علي', 'available' => true], null, [], $owner);
Mutqan_Store::insert('tech_profiles', ['userId' => 'u-t2', 'displayName' => 'سامي', 'available' => true], null, [], $owner);
$o = Mutqan_Store::insert('orders', ['status' => 'new', 'number' => '1024', 'clientName' => 'أحمد', 'createdAt' => gmdate('c', time() - 600)], null, [], $owner);
$r = Mutqan_Agent::handle(['messages' => [['role' => 'user', 'text' => 'اسند طلب 1024 لمحمود']]], $owner);
$after = Mutqan_Store::get_raw('orders', $o['id']);
$t('assign runs instantly, no model', ($r['engine'] ?? '') === 'internal' && $after['technicianId'] === 'u-t1' && $after['status'] === 'assigned', $r);
$r = Mutqan_Agent::handle(['messages' => [['role' => 'user', 'text' => 'اسند طلب 999 لمحمود']]], $owner);
$t('unknown order explained', strpos($r['text'], '999') !== false, $r);
$r = Mutqan_Agent::handle(['messages' => [['role' => 'user', 'text' => 'الطلبات']]], $ops);
$t('orders list shows relative time', strpos($r['text'], '#1024') !== false && strpos($r['text'], 'من ') !== false, $r);
$r = Mutqan_Agent::handle(['messages' => [['role' => 'user', 'text' => 'نضف الكاش']]], $owner);
$t('cache purge answers', strpos($r['text'], 'كاش') !== false, $r);
$r = Mutqan_Ops::handle(['messages' => [['role' => 'user', 'text' => 'اسند طلب 1024 لسامي']]], $ops);
$t('ops tab refuses changes, points to the commands tab', strpos($r['text'], 'أوامر') !== false && Mutqan_Store::get_raw('orders', $o['id'])['technicianId'] === 'u-t1', $r);
$r = Mutqan_Ops::handle(['messages' => [['role' => 'user', 'text' => 'الوضع']]], $sup);
$t('supervisor gets the overview', ($r['engine'] ?? '') === 'internal' && strpos($r['text'], 'الوضع') !== false, $r);

// nobody can answer -> a useful reply, never a bare 503
$r = Mutqan_Agent::handle(['messages' => [['role' => 'user', 'text' => 'اكتب مقال عن تنظيف المكيف']]], $owner);
$t('no provider: guidance instead of an error', !empty($r['degraded']) && strpos($r['text'], 'اسند طلب') !== false, $r);

// all providers cooling: still tried once more
$s = Mutqan_AI::settings(); $s['providers']['groq'] = ['enabled' => 1, 'key' => 'k', 'model' => 'm']; update_option(Mutqan_AI::OPT, $s);
set_transient('mq_cool_groq', 1, 3600);
$res = Mutqan_AI::run('سؤال', [], $owner);
$t('cooling provider retried as last resort', $res['provider'] === 'groq' && !get_transient('mq_cool_groq'), $res);

// points wallet
$st = Mutqan_Points::set(['monthly' => 100]);
$t('wallet balance', $st['balance'] === 100, $st);
$c = Mutqan_Points::spend('claude-opus-5-5', 10000, 2000);
$t('opus costs 5x per 1k (output x5)', $c === 100 && !Mutqan_Points::available(), $c);
Mutqan_Points::set(['topup' => 50]);
$t('top-up refills', Mutqan_Points::available() && Mutqan_Points::state()['balance'] === 50);
$deny = false; try { Mutqan_Agent::handle(['points' => ['monthly' => 1]], $ops); } catch (Mutqan_Error $e) { $deny = $e->status === 403; }
$t('only the owner changes points', $deny);
$r = Mutqan_Agent::handle(['points' => true], $ops);
$t('operations can read points', isset($r['points']['balance']), $r);

exit($ok ? 0 : 1);
