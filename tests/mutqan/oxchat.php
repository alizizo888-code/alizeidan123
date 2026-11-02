<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
foreach (['add_action', 'add_filter'] as $f) { if (!function_exists($f)) { eval("function $f() { return true; }"); } }
if (!function_exists('wp_parse_args')) { function wp_parse_args($a, $d = []) { return array_merge($d, (array) $a); } }
if (!function_exists('register_post_type')) { function register_post_type() {} }
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!class_exists('Oxygen_Orders_Chat')) { require __DIR__ . '/../../oxygen-ac-automation/includes/class-oxygen-orders-chat.php'; }
Mutqan_Store::maybe_install();
$bad = 0;
$t = function ($n, $ok, $i = '') use (&$bad) { if (!$ok) { $bad++; } echo ($ok ? 'PASS ' : 'FAIL ') . "oxchat: $n" . ($ok ? '' : " ($i)") . "\n"; };
$s = Oxygen_Orders_Chat::get();
$cat = Oxygen_Orders_Chat::site_catalog();
$names = array_column($cat, 'name');
$t('catalog has AC wash split', in_array('غسيل مكيف سبليت', $names));
$t('catalog has electric', in_array('كهرباء', $names));
$split = null; foreach ($cat as $c) { if ($c['name'] === 'غسيل مكيف سبليت') { $split = $c; } }
$t('split 3 = 200', $split && $split['tiers'][3] === 200, json_encode($split['tiers'] ?? null));
$t('split floor < base', $split && $split['min'] < $split['base']);
$s['services'] = "غسيل مكيف سبليت|120|90\nخدمة خاصة|50|40";
$all = Oxygen_Orders_Chat::all_services($s);
$own = null; foreach ($all as $a) { if ($a['name'] === 'غسيل مكيف سبليت') { $own = $a; } }
$t('owner line overrides site', $own && $own['source'] === 'owner' && $own['base'] == 120);
$t('owner extra service added', in_array('خدمة خاصة', array_column($all, 'name')));
$t('default prompt is the playbook', mb_strpos($s['instructions'], 'اقتناص العملاء') !== false);
$r = new ReflectionClass('Oxygen_Orders_Chat');
$m = $r->getMethod('build_system'); $m->setAccessible(true);
$sys = $m->invoke(null, $s, Oxygen_Orders_Chat::all_services(Oxygen_Orders_Chat::get()), null);
$t('prompt has the clock', mb_strpos($sys, 'الوقت الآن') !== false);
$t('prompt has price tiers', mb_strpos($sys, 'وحدتان 140') !== false, mb_substr($sys, 0, 0));
$t('prompt has warranty add-ons', mb_strpos($sys, 'ضمان إضافي') !== false);
$ph = $r->getMethod('customer_phone'); $ph->setAccessible(true);
$t('phone read from customer text', $ph->invoke(null, [['role' => 'user', 'content' => 'جوالي ٠٥٠١٢٣٤٥٦٧٨']]) === '0501234567' . '8' || $ph->invoke(null, [['role' => 'user', 'content' => 'جوالي ٠٥٠١٢٣٤٥٦٧٨']]) === '05012345678');
$hs = $r->getMethod('handle_submit'); $hs->setAccessible(true);
$services = Oxygen_Orders_Chat::all_services(Oxygen_Orders_Chat::get());
$bad_price = $hs->invoke(null, ['service' => 'غسيل مكيف سبليت', 'issue' => 'غسيل', 'qty' => 3, 'price' => 100, 'phone' => '0501234567', 'address' => 'العزيزية'], null, Oxygen_Orders_Chat::get(), $services);
$t('3 splits at 100 rejected (below floor)', $bad_price['ok'] === false, json_encode($bad_price, JSON_UNESCAPED_UNICODE));
exit($bad ? 1 : 0);
