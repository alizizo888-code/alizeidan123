<?php
// Automatic dispatch on the server, at the moment an order is created.
//
// In «آلي» mode the room said every new order is assigned at once — but the assigning ran in
// whoever's browser had the operations room open. With nobody on the screen, orders waited. These
// checks run with no browser at all, which is the whole point.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'dispatch: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$sys = ['kind' => 'user', 'role' => 'owner', 'id' => 'system', 'powers' => ['read', 'write', 'manage']];
$sh = ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'];
$D = 'Mutqan_Dispatch';
$now = gmdate('c');
$order = function ($extra = []) use ($sys, $sh) {
    return Mutqan_Store::insert('orders', array_merge(['status' => 'new', 'specialtyId' => 'ac', 'lat' => 21.5433, 'lng' => 39.1728, 'clientName' => 'عميل', 'createdAt' => gmdate('c')], $extra), null, $sh, $sys);
};
$prof = function ($id, $extra) use ($sys, $sh) { Mutqan_Store::upsert('tech_profiles', array_merge(['userId' => $id, 'displayName' => $id, 'active' => true], $extra), $id, $sh, $sys); };

/* ------------------------------------------------------------------ manual mode is untouched */
$prof('near', ['lat' => 21.545, 'lng' => 39.173, 'lastSeenAt' => $now, 'specialties' => ['ac']]);
$o = $order();
$t('manual mode (the default): a new order waits for the supervisor', ($o['status'] ?? '') === 'new' && empty($o['technicianId']), $o);

/* ------------------------------------------------------------------ auto: assigned at creation */
Mutqan_Store::upsert('assignment_settings', ['mode' => 'auto', 'strategy' => 'smart'], 'default', $sh, $sys);
$prof('far', ['lat' => 21.70, 'lng' => 39.30, 'lastSeenAt' => $now, 'specialties' => ['ac']]);
$o = $order();
$t('auto mode: the order is assigned the moment it is created — no browser involved', ($o['status'] ?? '') === 'assigned', $o['status'] ?? null);
$t('to the nearer, connected technician', ($o['technicianId'] ?? '') === 'near', $o['technicianId'] ?? null);
$t('with the same fields the room writes', ($o['handler'] ?? '') === 'technician' && !empty($o['assignedAt']) && ($o['dispatch'] ?? '') === 'tech' && end($o['statusHistory'])['status'] === 'assigned', $o);
$t('and says the server did it', ($o['dispatchedBy'] ?? '') === 'server');
$t('the route records the hand-over (trace_order ran)', !empty($o['route']), array_keys($o));

/* ------------------------------------------------------------------ who is not eligible */
$prof('off', ['lat' => 21.5433, 'lng' => 39.1728, 'lastSeenAt' => $now, 'active' => false]);
$o = $order();
$t('a technician who switched himself off is never chosen, however close', ($o['technicianId'] ?? '') !== 'off', $o['technicianId'] ?? null);
$prof('plumber', ['lat' => 21.5433, 'lng' => 39.1728, 'lastSeenAt' => $now, 'specialties' => ['plumbing']]);
$o = $order();
$t('a plumber standing at the door loses an AC job to an AC technician nearby', ($o['technicianId'] ?? '') === 'near', $o['technicianId'] ?? null);
$prof('noCap', ['lat' => 21.544, 'lng' => 39.1729, 'lastSeenAt' => $now, 'specialties' => ['ac']]);   // no dailyCapacity at all
$o = $order();
$t('a profile with no capacity set is still eligible (default 8, not 0)', in_array($o['technicianId'] ?? '', ['noCap', 'near'], true) && $D::capacity(['userId' => 'x']) == 8, $o['technicianId'] ?? null);

/* ------------------------------------------------------------------ nobody can take it */
foreach (['near', 'far', 'plumber', 'noCap'] as $id) { Mutqan_Store::update('tech_profiles', $id, ['active' => false], $sys); }
$o = $order();
$t('with nobody available, the order goes to the open pool instead of vanishing', ($o['status'] ?? '') === 'forced' && !empty($o['forced']), $o['status'] ?? null);

/* ------------------------------------------------------------------ what it must leave alone */
Mutqan_Store::update('tech_profiles', 'near', ['active' => true], $sys);
$o = $order(['technicianId' => 'far', 'technicianName' => 'far', 'status' => 'assigned']);
$t('an order created already assigned is not re-dispatched', ($o['technicianId'] ?? '') === 'far');
$o = $order(['inhouse' => true]);
$t('an in-house order is left to the in-house flow', empty($o['technicianId']));

/* ------------------------------------------------------------------ the browser's arithmetic, ported */
$t('distance: about 2 km between two Jeddah points', abs($D::meters(21.5433, 39.1728, 21.56, 39.18) - 2000) < 300, $D::meters(21.5433, 39.1728, 21.56, 39.18));
$t('presence: seen now is online, an hour and a half ago is offline', $D::presence(['lastSeenAt' => gmdate('c')]) === 'online' && $D::presence(['lastSeenAt' => gmdate('c', time() - 5400)]) === 'offline');

/* ------------------------------------------------------------------ the website chat files to the hub */
$src = file_get_contents(dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/class-oxygen-orders-chat.php');
$w = file_get_contents(dirname(__DIR__, 2) . '/oxygen-ac-automation/assets/oxygen-chat-widget.html');
$t('a chat order comes back with a hub-shaped copy', strpos($src, "\$out['hubOrder']") !== false && strpos($src, "'source' => 'site_chat'") !== false);
$t('the widget files it to the hub from the customer\'s browser', strpos($w, "toHub(d.hubOrder)") !== false && strpos($w, "C.hub+'/db'") !== false);
$t('and is told where the hub is', strpos($src, "'hub' =>") !== false);
$t('the desk mirror (a second dispatcher) is off unless the owner turns it on', strpos($src, "if (get_option('oxy_desk_mirror')) self::push_to_desk(") !== false);

echo $ok ? "OK dispatch\n" : "FAILED dispatch\n";
exit($ok ? 0 : 1);
