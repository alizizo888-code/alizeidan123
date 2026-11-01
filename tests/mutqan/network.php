<?php
// The 12-site network: host -> brand, runtime brand config, per-brand contacts, order stamping (client cannot claim another face).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-net-' . getmypid());
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "network: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$R = Oxygen_Network::registry();
$t('12 faces', count($R) === 12 && count(Oxygen_Network::satellites()) === 10, count($R));
$labels = array_column(array_filter($R, function ($b) { return empty($b['core']); }), 'label');
$t('labels unique + latin', count(array_unique($labels)) === count($labels) && !array_filter($labels, function ($l) { return !preg_match('/^[a-z0-9]+$/', $l) && $l !== ''; }), $labels);
$names = array_column($R, 'name');
$t('names unique', count(array_unique($names)) === 12);
foreach (['المتخصص', 'المهندس', 'الأقصى', 'رعد السماء', 'المتمكن', 'الخبير', 'هوم سيرفيس', 'ابتكار راحة', 'المعتمد', 'المصرية'] as $n) { $t("name $n", in_array($n, $names, true)); }
$t('host oxygen', Oxygen_Network::id_for_host('oxygen11.com') === 'oxygen' && Oxygen_Network::id_for_host('www.oxygen11.com') === 'oxygen');
$t('host mutqan', Oxygen_Network::id_for_host('mutqan.oxygen11.com') === 'mutqan');
$t('host satellite', Oxygen_Network::id_for_host('raad.oxygen11.com') === 'raad' && Oxygen_Network::id_for_host('RAAD.oxygen11.com:443') === 'raad');
$t('unknown host -> hub', Oxygen_Network::id_for_host('evil.example.com') === 'oxygen' && Oxygen_Network::id_for_host('x.oxygen11.com') === 'oxygen');
$c = Oxygen_Network::brand_config('raad');
$t('config', $c['name'] === 'رعد السماء' && $c['full'] === 'رعد السماء للصيانة والمقاولات العامة' && $c['core'] === false && preg_match('/^#[0-9a-f]{6}$/', $c['color']), $c);
$t('core flag', Oxygen_Network::brand_config('mutqan')['core'] === true && Oxygen_Network::brand_config('oxygen')['core'] === true);
$t('api base: satellites -> hub, core -> own', Oxygen_Network::api_base('aqsa') === 'https://oxygen11.com/wp-json/mutqan/v1' && Oxygen_Network::api_base('mutqan') === '');
$t('contacts empty by default', Oxygen_Network::contacts('khabeer') === ['phone' => '', 'wa' => '']);
Oxygen_Network::set_contacts('khabeer', '+966 50 123 4567');
$t('contacts digits only, wa defaults to phone', Oxygen_Network::contacts('khabeer') === ['phone' => '966501234567', 'wa' => '966501234567'], Oxygen_Network::contacts('khabeer'));
$t('unknown id refused', Oxygen_Network::set_contacts('nope', '1') === false && Oxygen_Network::clean_brand('nope') === 'oxygen');

// the JS mirror (desk filter chips) must equal the PHP registry
$js = file_get_contents(dirname(__DIR__, 2) . '/mutqan-app/src/lib/netBrand.js');
preg_match_all("/\\{ id: '(\\w+)', name: '([^']+)', color: '(#[0-9a-f]{6})'/u", $js, $mm, PREG_SET_ORDER);
$jsmap = []; foreach ($mm as $m) { $jsmap[$m[1]] = [$m[2], $m[3]]; }
$same = count($jsmap) === 12;
foreach ($R as $id => $b) { $n = $b['name'] === 'أكسجين' ? 'مُتقن' : $b['name']; if (!isset($jsmap[$id]) || $jsmap[$id][1] !== $b['color'] || ($id !== 'mutqan' && $jsmap[$id][0] !== $b['name'])) { $same = false; } }
$t('JS registry mirrors PHP (ids, names, colours)', $same, $jsmap);

// orders carry the face they came from
$cust = ['id' => 'anon-cust000001', 'role' => null, 'kind' => 'anon', 'powers' => []];
$ops = ['id' => 'u-ops', 'role' => 'operations', 'kind' => 'user', 'powers' => ['read', 'write', 'manage'], 'name' => 'x'];
$o = ['visibleTo' => 'creator-and-admin', 'writableBy' => 'admins'];
$mk = function ($p, $extra = []) use ($o) { return Mutqan_Store::insert('orders', array_merge(['specialtyId' => 'ac', 'clientName' => 'س', 'clientPhone' => '0501234567', 'address' => 'ج'], $extra), null, $o, $p, false); };
$_SERVER['HTTP_ORIGIN'] = 'https://homeservice.oxygen11.com';
$a = $mk($cust, ['brand' => 'raad']);
$t('customer cannot claim another face: origin wins', $a['brand'] === 'homeservice', $a['brand'] ?? null);
$_SERVER['HTTP_ORIGIN'] = 'https://evil.example.com';
$b = $mk($cust); $t('foreign origin -> hub brand', $b['brand'] === 'oxygen', $b['brand'] ?? null);
unset($_SERVER['HTTP_ORIGIN']);
$c2 = $mk($ops, ['brand' => 'misriya']); $t('staff may set the face (manual order)', $c2['brand'] === 'misriya', $c2['brand'] ?? null);
$c3 = $mk($ops, ['brand' => 'bogus']); $t('staff with a bogus face -> current site', $c3['brand'] === 'oxygen', $c3['brand'] ?? null);
$u = Mutqan_Store::update('orders', $a['id'], ['brand' => 'aqsa', 'address' => 'جدة'], $cust);
$t('customer cannot change the face afterwards', $u['brand'] === 'homeservice' && $u['address'] === 'جدة', $u['brand'] ?? null);
echo $ok ? "ALL PASSED\n" : "SOME FAILED\n";
exit($ok ? 0 : 1);
