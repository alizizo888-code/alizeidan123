<?php
// The phone's alert inbox: a busy week (more than 100 alerts) must never hide the newest one.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-pushbox-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "push_inbox: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
$ops = ['id' => 'u-ops', 'role' => 'operations', 'kind' => 'user', 'powers' => ['read', 'write', 'manage'], 'name' => 'x'];
global $wpdb;
// 150 alerts from earlier in the week
for ($i = 0; $i < 150; $i++) {
    $d = Mutqan_Store::insert('_push', ['target' => ['admins' => true], 'title' => 'old ' . $i, 'body' => '', 'event' => 'new_order'], null, ['expiresAt' => gmdate('c', time() + 86400)], $sys);
    $wpdb->query($wpdb->prepare('UPDATE ' . Mutqan_Store::table('docs') . ' SET created_at=%s WHERE doc_id=%s', gmdate('Y-m-d H:i:s', time() - 86400 * 3 + $i), $d['id']));
}
$since = time() - 60;
Mutqan_Rest::admin_alert('🆕 طلب جديد — اختبار', 'x', '#/staff/order/1', 'new_order', 'high', null);
$mk = function ($b) { $q = new WP_REST_Request(); $q->body = json_encode($b); return $q; };
$req = $mk(['op' => 'inbox', 'since' => $since * 1000]);
$r = Mutqan_Rest::h_push($req, $ops);
$titles = array_map(function ($m) { return $m['title']; }, $r['items']);
$t('the newest alert reaches the phone even after a busy week', in_array('🆕 طلب جديد — اختبار', $titles, true), $titles);
$t('and old ones are not replayed', count($titles) === 1, count($titles));
$r2 = Mutqan_Rest::h_push($mk(['op' => 'inbox']), $ops);
$t('a first poll (no since) gets the last hour, not the week', count($r2['items']) === 1, count($r2['items']));
echo $ok ? "ALL PASSED\n" : "SOME FAILED\n";
exit($ok ? 0 : 1);
