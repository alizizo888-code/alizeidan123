<?php
// The 2026-11-20 audit fixes, checked with no browser:
//  - automatic distribution never gives a job to a technician profile whose account does not exist (it was lost there),
//  - an order that falls back to the site placeholder alarms the owner and operations,
//  - the health watchdog raises «waiting order» and «no phone» once, not every tick.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
require dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/class-oxygen-health.php';
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'audit: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$sys = ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']];
$sh = ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'];
$now = gmdate('c');
$prof = function ($id, $extra = []) use ($sys, $sh) { Mutqan_Store::upsert('tech_profiles', array_merge(['userId' => $id, 'displayName' => $id, 'active' => true, 'specialties' => ['ac']], $extra), $id, $sh, $sys); };
$order = function ($extra = []) use ($sys, $sh) { return Mutqan_Store::insert('orders', array_merge(['status' => 'new', 'specialtyId' => 'ac', 'lat' => 21.5433, 'lng' => 39.1728, 'clientName' => 'عميل'], $extra), null, $sh, $sys); };
$pushes = function ($event) use ($sys) { return array_values(array_filter(Mutqan_Store::select('_push', [], ['limit' => 500], $sys), function ($m) use ($event) { return ($m['event'] ?? '') === $event; })); };

/* ---------------------------------------------------------------- only real technicians get jobs */
$real = Mutqan_Auth::make_member('فني حقيقي', '0531112222', 'technician');
$rid = is_array($real) ? (string) ($real['id'] ?? '') : '';
$t('a technician account can be made for the test', $rid !== '', $real);
$prof($rid, ['lat' => 21.60, 'lng' => 39.25, 'lastSeenAt' => $now]);                 // a bit further away
$prof('ghost', ['lat' => 21.5434, 'lng' => 39.1729, 'lastSeenAt' => $now]);           // at the door, but no account
Mutqan_Store::upsert('assignment_settings', ['mode' => 'auto', 'strategy' => 'nearest'], 'default', $sh, $sys);
$o = $order(['clientPhone' => '0500000001']);
$t('the job goes to the real technician, not to a profile with no account standing closer', ($o['technicianId'] ?? '') === $rid, $o['technicianId'] ?? null);
$t('the profile list itself leaves the ghost out', !in_array('ghost', array_map(function ($p) { return $p['userId']; }, Mutqan_Dispatch::profiles()), true));

/* ---------------------------------------------------------------- site fallback is loud */
Mutqan_Store::update('tech_profiles', $rid, ['active' => false], $sys);
$site = Mutqan_Auth::make_member('فني الموقع', '', 'technician');
$sid = (string) ($site['id'] ?? '');
$prof($sid, ['isSite' => true, 'dailyCapacity' => 50]);
$o = $order(['clientPhone' => '0500000002']);
$t('with nobody free the order goes to the site placeholder', ($o['dispatch'] ?? '') === 'site', $o['dispatch'] ?? null);
$w = $pushes('order_unassigned');
$t('…and the owner and operations are alarmed at once', count($w) >= 2 && strpos($w[0]['title'], Mutqan_Store::num($o)) !== false, $w);

Mutqan_Store::upsert('assignment_settings', ['mode' => 'manual'], 'default', $sh, $sys);
// The number comes from WhatsApp when the customer confirms (owner, 2026-11-20): an order may be created with none.
$o2 = $order(['clientPhone' => '']);
$t('an order with no number is accepted as it is (WhatsApp confirmation brings it)', !empty($o2['id']) && empty($o2['needsPhone']), $o2);

/* ---------------------------------------------------------------- the watchdog */
global $wpdb;
$old = gmdate('Y-m-d H:i:s', time() - 45 * 60);
$wpdb->query($wpdb->prepare('UPDATE ' . Mutqan_Store::table('docs') . " SET created_at=%s WHERE coll='orders' AND doc_id=%s", $old, $o2['id']));
Oxygen_Health::tick();
$wait = array_filter($pushes('order_waiting'), function ($m) use ($o2) { return strpos($m['url'], $o2['id']) !== false; });
$t('an order waiting 45 minutes with no technician is reported', count($wait) >= 1, count($wait));
$t('no «no phone» alarm: waiting for the WhatsApp confirmation is normal', count($pushes('order_no_phone')) === 0);
Oxygen_Health::tick();
$wait2 = array_filter($pushes('order_waiting'), function ($m) use ($o2) { return strpos($m['url'], $o2['id']) !== false; });
$t('…once, not on every tick', count($wait2) === count($wait), [count($wait), count($wait2)]);

$o3 = $order(['clientPhone' => '0500000003', 'status' => 'assigned', 'technicianId' => $rid, 'technicianName' => 'فني حقيقي', 'dispatch' => 'site']);
$wpdb->query($wpdb->prepare('UPDATE ' . Mutqan_Store::table('docs') . " SET created_at=%s WHERE coll='orders' AND doc_id=%s", $old, $o3['id']));
update_option('oxygen_health_seen', []);
Oxygen_Health::tick();
$w3 = array_filter($pushes('order_waiting'), function ($m) use ($o3) { return strpos($m['url'], $o3['id']) !== false; });
$t('an order a real technician holds is not «waiting», even with an old dispatch=site mark', count($w3) === 0, count($w3));

echo $ok ? "ALL PASS audit\n" : "FAILED audit\n";
exit($ok ? 0 : 1);
