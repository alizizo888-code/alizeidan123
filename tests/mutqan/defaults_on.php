<?php
// Out of the box: automatic distribution and an answered hotline, without anybody setting them up — and the
// owner's later choice is never overwritten.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-defon-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "defaults_on: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$t('a fresh site starts manual and unlinked', !Mutqan_Dispatch::is_auto() && !Mutqan_Link::is_set());
Mutqan_Defaults::seed();
$t('after the first visit distribution is automatic', Mutqan_Dispatch::is_auto());
$t('and the hotline has a (hidden) code with automatic joining', Mutqan_Link::is_set() && Mutqan_Link::auto());
$ops = ['id' => 'u-ops', 'role' => 'operations', 'kind' => 'user', 'powers' => ['read', 'write', 'manage'], 'name' => 'x'];
$p = Mutqan_Link::handle(['op' => 'ping', 'deviceId' => 'devops000777'], $ops);
$t('so an operations device answers the line just by opening the app', !empty($p['linked']) && Mutqan_Link::public_state()['online'] === true);
$sys = ['id' => 'system', 'role' => 'owner', 'powers' => ['read', 'write', 'manage'], 'kind' => 'user'];
Mutqan_Store::update('assignment_settings', 'default', ['mode' => 'manual'], $sys);
$h = get_option(Mutqan_Link::OPT)['h'];
Mutqan_Defaults::seed();
$t('the owner switching back to manual is respected', !Mutqan_Dispatch::is_auto());
$t('and an existing code is never replaced', get_option(Mutqan_Link::OPT)['h'] === $h);
echo $ok ? "ALL PASSED\n" : "SOME FAILED\n";
exit($ok ? 0 : 1);
