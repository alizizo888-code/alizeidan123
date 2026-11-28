<?php
// «امسح الكاش / امسح الطلبات / هات طلب 1024 / ابحث عن… / افتح صفحة… / سعر…» from the owner, operations and the
// supervisor (owner, 2026-11-20): it runs at once, says so, and hands back a tap that opens the page or the order.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
require dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/mutqan/class-mutqan-command.php';
if (!class_exists('Mutqan_Owner')) { require dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/mutqan/class-mutqan-owner.php'; }
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'links: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
$sh = ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'];
$who = [
    'owner' => ['id' => 'u-owner', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'علي'],
    'operations' => ['id' => 'u-ops', 'role' => 'operations', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user', 'name' => 'العمليات'],
    'supervisor' => ['id' => 'u-sup', 'role' => 'supervisor', 'powers' => ['read', 'write'], 'kind' => 'user', 'name' => 'المشرف'],
];
$Q = 'Mutqan_Quick'; $A = 'Mutqan_Agent';
$hrefs = function ($r) { return array_column((array) ($r['links'] ?? []), 'href'); };

$o1 = Mutqan_Store::insert('orders', ['number' => '1024', 'status' => 'new', 'specialtyId' => 'ac', 'clientName' => 'أبو خالد', 'clientPhone' => '0551112233', 'address' => 'العزيزية'], null, $sh, $sys);
$o2 = Mutqan_Store::insert('orders', ['number' => '1025', 'status' => 'cancelled', 'specialtyId' => 'ac', 'clientName' => 'عميل ملغي', 'clientPhone' => '0551112244'], null, $sh, $sys);
$o3 = Mutqan_Store::insert('orders', ['number' => '1026', 'status' => 'new', 'specialtyId' => 'ac', 'clientName' => 'طلب تجربة', 'clientPhone' => '0551112255'], null, $sh, $sys);
$o4 = Mutqan_Store::insert('orders', ['number' => '1027', 'status' => 'new', 'specialtyId' => 'ac', 'clientName' => 'عميل حقيقي', 'clientPhone' => '0551112266'], null, $sh, $sys);
$exists = function ($o) { return (bool) Mutqan_Store::get_raw('orders', $o['id']); };

/* ---------------------------------------------------------------- the words are understood */
$m = function ($s) use ($Q) { $r = $Q::match($s); return $r ? $r['tool'] : null; };
$t('«امسح الكاش» / «نضف الكاش كله»', $m('امسح الكاش') === 'cache_purge' && $Q::match('نضف الكاش كله')['input']['all'] === true);
$t('«امسح طلب 1025» / «امسح الطلبات الملغية» / «احذف الطلبات التجريبية»', $m('امسح طلب 1025') === 'orders_delete' && $Q::match('امسح الطلبات الملغية')['input']['filter'] === 'cancelled' && $Q::match('احذف الطلبات التجريبية')['input']['filter'] === 'test');
$t('«هات لي طلب 1024» / «افتح الطلب ١٠٢٤»', $m('هات لي طلب 1024') === 'order_open' && $Q::match('افتح الطلب ١٠٢٤')['input']['ref'] === '1024');
$t('«ابحث عن أبو خالد» / «دور على العزيزية»', $m('ابحث عن أبو خالد') === 'find' && $m('دور على العزيزية') === 'find');
$t('«افتح صفحة الأسعار» / «وديني على الخريطة»', $m('افتح صفحة الأسعار') === 'find' && $m('وديني على الخريطة') === 'find');
$t('«سعر غسيل السبليت» / «بكام الشباك»', $m('سعر غسيل السبليت') === 'find' && $m('بكام الشباك') === 'find');
$t('«طلب 1024» alone still tells its story', $m('طلب 1024') === 'order_story');

foreach ($who as $role => $p) {
    /* ---------------------------------------------------------------- cache */
    $r = $Q::try_run('امسح الكاش', $p);
    $t("$role: «امسح الكاش» runs at once and confirms", $r && empty($r['pending']) && mb_strpos($r['text'], 'اتمسح') !== false, $r);

    /* ---------------------------------------------------------------- open an order */
    $r = $Q::try_run('هات لي طلب 1024', $p);
    $t("$role: «هات لي طلب 1024» answers with the order and a tap to it", $r && in_array('#/staff/order/' . $o1['id'], $hrefs($r), true) && mb_strpos($r['text'], '1024') !== false, $r);

    /* ---------------------------------------------------------------- search */
    $r = $Q::try_run('ابحث عن أبو خالد', $p);
    $t("$role: search by name → the order link", $r && in_array('#/staff/order/' . $o1['id'], $hrefs($r), true), $r);
    $r = $Q::try_run('ابحث عن 0551112233', $p);
    $t("$role: search by phone → the order link", $r && in_array('#/staff/order/' . $o1['id'], $hrefs($r), true), $r);
    $r = $Q::try_run('افتح صفحة الأسعار', $p);
    $t("$role: «افتح صفحة الأسعار» → the pricing page", $r && in_array('#/staff/pricing', $hrefs($r), true), $r);
    $r = $Q::try_run('وديني على الخريطة', $p);
    $t("$role: «وديني على الخريطة» → the map", $r && in_array('#/staff/map', $hrefs($r), true), $r);
    $r = $Q::try_run('سعر غسيل السبليت', $p);
    $t("$role: «سعر غسيل السبليت» → prices with a tap to the pricing page", $r && in_array('#/staff/pricing', $hrefs($r), true), $r);
    $r = $Q::try_run('ابحث عن كلمةمالهاششبيه', $p);
    $t("$role: nothing found says so plainly", $r && mb_strpos($r['text'], 'ملقتش') !== false && !$hrefs($r), $r);
}

/* ---------------------------------------------------------------- deleting orders: owner only, after «تنفيذ» */
foreach (['operations', 'supervisor'] as $role) {
    $r = $Q::try_run('امسح طلب 1025', $who[$role]);
    $t("$role: deleting orders is refused", $r && empty($r['pending']) && mb_strpos($r['text'], 'صلاحيات') !== false && $exists($o2), $r);
}
$r = $Q::try_run('امسح طلب 1025', $who['owner']);
$t('owner: «امسح طلب 1025» waits for «تنفيذ» and has not deleted yet', $r && !empty($r['pending']['id']) && $exists($o2), $r);
$c = $A::handle(['confirm' => $r['pending']['id']], $who['owner']);
$t('owner: after «تنفيذ» it is gone, with a confirmation and a tap to the orders board', !$exists($o2) && $exists($o1) && mb_strpos($c['text'], 'اتمسح') !== false && in_array('#/staff/orders-board', $hrefs($c), true), $c);
$r = $Q::try_run('احذف الطلبات التجريبية', $who['owner']);
$c = $A::handle(['confirm' => $r['pending']['id']], $who['owner']);
$t('owner: «احذف الطلبات التجريبية» removes only the test order', !$exists($o3) && $exists($o4) && $exists($o1), $c);
$r = $Q::try_run('امسح طلب 1027', $who['owner']);
$A::handle(['cancel' => $r['pending']['id']], $who['owner']);
$t('owner: «إلغاء» keeps the order', $exists($o4));
$err = ''; try { $A::handle(['confirm' => $r['pending']['id']], $who['owner']); } catch (Exception $e) { $err = $e->getMessage(); }
$t('…and the cancelled request cannot be confirmed later', $err !== '' && $exists($o4), $err);

/* ---------------------------------------------------------------- link builder */
$L = Mutqan_Command::links('orders_list', [['id' => $o1['id'], 'number' => '1024', 'status' => 'new', 'clientName' => 'أبو خالد'], ['id' => $o1['id'], 'status' => 'new']]);
$t('order lists become taps, once each', count($L) === 1 && $L[0]['href'] === '#/staff/order/' . $o1['id'] && mb_strpos($L[0]['label'], '1024') !== false, $L);

echo $ok ? "ALL PASS command_links\n" : "FAILED command_links\n";
exit($ok ? 0 : 1);
