<?php
// Installed desk apps stay apart: team alerts skip the technician / WhatsApp apps, messages go to the WhatsApp app,
// and an owner with the operations app does not get a second copy in the owner app.
require __DIR__ . '/wpshim.php';
require_once dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/mutqan/class-mutqan-webpush.php';
$ok = 0; $bad = 0;
$t = function ($name, $cond) use (&$ok, &$bad) { if ($cond) { $ok++; echo "PASS $name\n"; } else { $bad++; echo "FAIL $name\n"; } };
$subs = [
  ['endpoint' => 'a', 'userId' => 'own', 'app' => 'control'], ['endpoint' => 'b', 'userId' => 'own', 'app' => 'ops'],
  ['endpoint' => 'c', 'userId' => 'own', 'app' => 'wa'], ['endpoint' => 'd', 'userId' => 'tec', 'app' => 'tech'],
  ['endpoint' => 'e', 'userId' => 'old'],
];
$eps = function ($r) { return implode(',', array_map(function ($s) { return $s['endpoint']; }, $r)); };
$t('team order alert: ops app only for the owner, never the tech app, old devices still ring', $eps(Mutqan_WebPush::route_apps($subs, ['target' => ['admins' => true], 'event' => 'new_order'])) === 'b,e');
$t('whatsapp message: rings the WhatsApp app instead of the others', $eps(Mutqan_WebPush::route_apps($subs, ['target' => ['admins' => true], 'event' => 'wa_message'])) === 'c,e');
$t('personal alert to the technician reaches his app', $eps(Mutqan_WebPush::route_apps([$subs[3]], ['target' => ['user' => 'tec'], 'event' => 'assigned'])) === 'd');
echo $bad ? "FAILED $bad\n" : "ALL PASSED\n"; exit($bad ? 1 : 0);
