<?php
// «مدير العمليات»: who may ask, read-only tools, alerts by rules, positions, money and an order's story.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . (getenv('MQ_DATA') ?: sys_get_temp_dir() . '/mutqan-ops-' . getmypid()));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "ops: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$owner = ['id' => 'u-own', 'role' => 'owner', 'kind' => 'user', 'powers' => ['read', 'write', 'manage'], 'name' => 'علي'];
$sup = ['id' => 'u-sup', 'role' => 'supervisor', 'kind' => 'user', 'powers' => ['read', 'write'], 'name' => 'مشرف جدة'];
$tech = ['id' => 'u-tech', 'role' => 'technician', 'kind' => 'user', 'powers' => ['read']];
$cust = ['id' => 'anon-c1', 'role' => null, 'kind' => 'anon', 'powers' => []];
$thr = function ($fn) { try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); } };

// access
$deny = function ($p) { try { Mutqan_Ops::handle(['op' => 'alerts'], $p); return false; } catch (Mutqan_Error $e) { return in_array($e->status, [401, 403], true); } };
$t('technician cannot open it', $deny($tech));
$t('customer cannot open it', $deny($cust));
$t('supervisor can read alerts', is_array(Mutqan_Ops::handle(['op' => 'alerts'], $sup)['alerts']));

// read-only by construction
$names = array_column(Mutqan_Ops::tools(), 'name');
$t('no write tools', !array_filter($names, function ($n) { return preg_match('/set|add|update|send|create|delete|assign|publish/', $n); }), $names);
$t('unknown tool refused', $thr(function () { Mutqan_Ops::run_tool('order_update', []); }) !== '');

// data
Mutqan_Store::insert('tech_pay', ['sharePct' => 30], null, [], $owner);
Mutqan_Store::insert('tech_profiles', ['userId' => 't1', 'displayName' => 'محمود', 'available' => true], 't1', [], $owner);
$o1 = Mutqan_Store::insert('orders', ['status' => 'new', 'number' => '1001', 'clientName' => 'أحمد', 'serviceLabel' => 'غسيل سبليت', 'price' => 150], null, [], $owner);
global $wpdb; $wpdb->query($wpdb->prepare('UPDATE ' . Mutqan_Store::table('docs') . ' SET created_at=%s WHERE doc_id=%s', gmdate('Y-m-d H:i:s', time() - 3600), $o1['id']));
$o2 = Mutqan_Store::insert('orders', ['status' => 'assigned', 'number' => '1002', 'clientName' => 'سارة', 'technicianId' => 't1', 'price' => 400], null, [], $owner);
Mutqan_Store::update('orders', $o2['id'], ['status' => 'en_route'], $owner);
Mutqan_Store::update('orders', $o2['id'], ['status' => 'completed', 'paymentMethod' => 'cash', 'paymentStatus' => 'paid', 'completedAt' => gmdate('c')], $owner);
Mutqan_Store::insert('expenses', ['amount' => 50, 'category' => 'فريون', 'orderId' => $o2['id'], 'at' => gmdate('c')], null, [], $owner);
Mutqan_Store::insert('expenses', ['amount' => 30, 'category' => 'بنزين', 'at' => gmdate('c')], null, [], $owner);

$a = Mutqan_Ops::alerts();
$un = array_values(array_filter($a, function ($x) { return $x['kind'] === 'unassigned'; }));
$t('unassigned order raises an alert', count($un) === 1 && strpos($un[0]['text'], '1001') !== false, $a);

$s = Mutqan_Ops::run_tool('order_story', ['number' => '1002']);
$t('order story: price, commission, expenses, net', $s['amount'] == 400 && $s['cost']['techCommission'] == 120 && $s['cost']['expensesTotal'] == 50 && $s['cost']['net'] == 230, $s['cost'] ?? $s);
$t('order story: steps in sequence', count($s['steps']) >= 2, $s['steps']);

$m = Mutqan_Ops::run_tool('money_report', ['days' => 1]);
$t('money: revenue, commission, expenses by category', $m['revenue'] == 400 && $m['techCommission'] == 120 && $m['expensesTotal'] == 80 && $m['expenses']['بنزين'] == 30 && $m['net'] == 200, $m);
$t('money: cash held by technician', $m['cashWithTechs'] && $m['cashWithTechs'][0]['balance'] == 280, $m['cashWithTechs']);

$ar = Mutqan_Ops::run_tool('orders_archive', ['days' => 2, 'q' => 'سارة']);
$t('archive search', $ar['count'] === 1 && $ar['orders'][0]['tech'] === 'محمود', $ar);
$ar2 = Mutqan_Ops::run_tool('orders_archive', ['days' => 2, 'status' => 'جديد']);
$t('archive by Arabic status', $ar2['count'] === 1, $ar2);

$log = Mutqan_Ops::run_tool('activity_log', ['hours' => 24]);
$t('activity log has order steps', count($log) >= 3, $log);

// positions
$t('technician cannot report a supervisor position', Mutqan_Ops::ping($tech, 21.5, 39.2)['ok'] === false);
$t('bad position refused', Mutqan_Ops::ping($sup, 0, 0)['ok'] === false);
$t('supervisor reports position', Mutqan_Ops::handle(['op' => 'pos', 'lat' => 21.54, 'lng' => 39.17], $sup)['ok'] === true);
$w = Mutqan_Ops::run_tool('where_supervisors', []);
$t('where is the supervisor', isset($w[0]) && $w[0]['name'] === 'مشرف جدة' && $w[0]['minutesAgo'] === 0, $w);
$all = get_option(Mutqan_Ops::OPT_POS); $all['u-sup']['at'] = time() - 4 * 3600; update_option(Mutqan_Ops::OPT_POS, $all);
$w2 = Mutqan_Ops::run_tool('where_supervisors', []);
$t('stale supervisor position is not shown', isset($w2['note']), $w2);
$wt = Mutqan_Ops::run_tool('where_techs', ['name' => 'محمود']);
$t('where techs: explains missing position', count($wt) === 1 && $wt[0]['position'] === null && $wt[0]['positionNote'] !== '', $wt);

// keyword fallback (no AI at all)
$t('keywords → tools', Mutqan_Ops::guess_tool('فين المشرف دلوقتي') === 'where_supervisors' && Mutqan_Ops::guess_tool('فين الفني محمود') === 'where_techs' && Mutqan_Ops::guess_tool('المصاريف الشهر ده') === 'money_report' && Mutqan_Ops::guess_tool('في تنبيهات؟') === 'alerts');
$r = Mutqan_Ops::handle(['messages' => [['role' => 'user', 'text' => 'في أي تنبيهات؟']]], $sup);
$t('answers with no AI provider', is_string($r['text']) && strpos($r['text'], '1001') !== false, $r);

// owner assistant: expense needs confirm, ops_query reads
$t('expense waits for confirm', Mutqan_Agent::needs_confirm('expense_add', ['amount' => 5, 'category' => 'x']));
$e = Mutqan_Agent::run_tool('expense_add', ['amount' => 20, 'category' => 'مواصلات'], $owner);
$t('expense saved', $e['saved'] && $e['amount'] == 20, $e);
$t('bad expense refused', $thr(function () use ($owner) { Mutqan_Agent::run_tool('expense_add', ['amount' => -1, 'category' => 'x'], $owner); }) !== '');
$q = Mutqan_Agent::run_tool('ops_query', ['what' => 'money_report', 'input' => ['days' => 1]], $owner);
$t('owner assistant can ask the operations manager', $q['expensesTotal'] == 100, $q);

// technician mode: own jobs only, no company data
$o3 = Mutqan_Store::insert('orders', ['status' => 'en_route', 'number' => '2001', 'clientName' => 'خالد', 'technicianId' => 'u-tech', 'price' => 250, 'address' => 'العزيزية'], null, [], $owner);
Mutqan_Store::insert('orders', ['status' => 'en_route', 'number' => '2002', 'clientName' => 'غيره', 'technicianId' => 't1', 'price' => 99], null, [], $owner);
$t('technician cannot read alerts', $deny($tech));
$rt = Mutqan_Ops::handle(['messages' => [['role' => 'user', 'text' => 'إيه شغلي النهارده؟']]], $tech);
$t('technician gets own jobs', strpos($rt['text'], '2001') !== false && strpos($rt['text'], '2002') === false && $rt['steps'][0]['tool'] === 'my_jobs', $rt);
$t('technician tools are only his', array_column(Mutqan_Ops::tools(), 'name') === ['my_jobs', 'my_job', 'my_account']);
$t('technician cannot use staff tools', $thr(function () { Mutqan_Ops::run_tool('money_report', []); }) !== '');
$t('technician cannot open another technician job', strpos($thr(function () { Mutqan_Ops::run_tool('my_job', ['number' => '2002']); }), 'مش من طلباتك') !== false);
$mj = Mutqan_Ops::run_tool('my_job', ['number' => '2001']);
$t('technician reads own job', $mj['client'] === 'خالد' && $mj['amount'] == 250, $mj);

exit($ok ? 0 : 1);
