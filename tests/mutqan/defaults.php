<?php
// One-time site defaults: the site's own numbers, order routing «both», and the built-in technician «فني الموقع».
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-defaults-' . getmypid());
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "defaults: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];

// an old, different number and an old routing mode are already stored: the owner's request replaces them ONCE
Mutqan_Store::upsert('pricing', ['serviceId' => 'contact_numbers', 'whatsapp' => '966500000001', 'phone' => '966500000002'], 'contact_numbers', ['groupId' => 'main', 'writableBy' => 'admins'], $sys);
Mutqan_Store::upsert('pricing', ['serviceId' => 'order_routing', 'mode' => 'operations', 'opsCreate' => true], 'order_routing', ['groupId' => 'main', 'writableBy' => 'admins'], $sys);
Mutqan_Defaults::seed();
$c = Mutqan_Store::peek_doc('pricing', 'contact_numbers');
$t('WhatsApp number is this site\'s number', ($c['whatsapp'] ?? '') === Mutqan_Defaults::WHATSAPP && preg_match('/^9665\d{8}$/', Mutqan_Defaults::WHATSAPP), $c);
$t('call number is this site\'s number', ($c['phone'] ?? '') === Mutqan_Defaults::PHONE, $c);
$r = Mutqan_Store::peek_doc('pricing', 'order_routing');
$t('routing becomes «both» and keeps opsCreate', ($r['mode'] ?? '') === 'both' && !empty($r['opsCreate']), $r);

$u = Mutqan_Auth::ensure_site_tech();
$t('site technician is a real technician user', ($u['handle'] ?? '') === 'site' && ($u['role'] ?? '') === 'technician' && ($u['display_name'] ?? '') === 'فني الموقع', $u);
$pr = Mutqan_Store::peek_doc('tech_profiles', $u['id']);
$t('profile exists, active, marked as the site technician', is_array($pr) && ($pr['userId'] ?? '') === $u['id'] && ($pr['isSite'] ?? false) === true && ($pr['active'] ?? false) === true && ($pr['dailyCapacity'] ?? 0) > 0, $pr);
$s = Mutqan_Store::peek_doc('assignment_settings', 'default');
$t('assignment settings point to him and keep the fallback on', ($s['siteTechId'] ?? '') === $u['id'] && ($s['siteFallback'] ?? null) === true, $s);
$t('his password is random (a guess fails)', !password_verify('site', $GLOBALS['wpdb']->get_var("SELECT password_hash FROM wp_mutqan_users WHERE handle='site'") ?: ''));

// the owner edits afterwards: a second seed run must change NOTHING
Mutqan_Store::upsert('pricing', ['serviceId' => 'contact_numbers', 'whatsapp' => '966511111111', 'phone' => '966522222222'], 'contact_numbers', ['groupId' => 'main', 'writableBy' => 'admins'], $sys);
Mutqan_Store::upsert('pricing', ['serviceId' => 'order_routing', 'mode' => 'whatsapp'], 'order_routing', ['groupId' => 'main', 'writableBy' => 'admins'], $sys);
Mutqan_Store::upsert('assignment_settings', ['siteTechId' => 'someone-else'], 'default', ['groupId' => 'main', 'writableBy' => 'admins'], $sys);
Mutqan_Defaults::seed();
$c = Mutqan_Store::peek_doc('pricing', 'contact_numbers'); $r = Mutqan_Store::peek_doc('pricing', 'order_routing'); $s = Mutqan_Store::peek_doc('assignment_settings', 'default');
$t('seed never runs twice: the owner\'s own numbers stay', $c['whatsapp'] === '966511111111' && $c['phone'] === '966522222222', $c);
$t('…and his routing choice stays', $r['mode'] === 'whatsapp', $r);
$t('…and the technician he chose stays', $s['siteTechId'] === 'someone-else', $s);
$n = (int) $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM wp_mutqan_users WHERE handle='site'");
$t('only one «site» user ever exists', $n === 1, $n);
exit($ok ? 0 : 1);
