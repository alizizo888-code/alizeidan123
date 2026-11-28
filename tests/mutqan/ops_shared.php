<?php
// One staff address and one password for the operations team.
//
// Ali chose a shared operations password over one account per person. A shared password alone
// would leave every assignment and every settlement signed by "العمليات", with nobody to ask.
// So the password opens the door, and the name typed next to it becomes that person's own
// operations account: actions stay attributed, and the owner can stop one name alone.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'ops_shared: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$A = 'Mutqan_Auth';
$err = function ($f) { try { $f(); return null; } catch (Mutqan_Error $e) { return $e->slug; } };
$A::set_owner('ali', 'Ali', 'Owner-Long-Pass-1');
$owner = ['kind' => 'user', 'role' => 'owner', 'id' => $A::owner_row()['id'], 'powers' => ['read', 'write', 'manage']];
$ops = ['kind' => 'user', 'role' => 'operations', 'id' => 'u-x', 'powers' => ['read', 'write', 'manage']];

$t('closed until the owner opens it', $err(function () use ($A) { $A::ops_login(['name' => 'سعيد', 'password' => 'x']); }) === 'ops_closed');
$t('the public config says it is closed', $A::team_config()['opsShared'] === false);
$t('only the owner sets it (operations cannot)', $err(function () use ($A, $ops) { $A::set_ops_shared($ops, ['password' => 'Shared-Ops-1']); }) === 'forbidden');
$t('a short password is refused', $err(function () use ($A, $owner) { $A::set_ops_shared($owner, ['password' => '123']); }) === 'weak_password');
$st = $A::set_ops_shared($owner, ['password' => 'Shared-Ops-1']);
$t('the owner opens it', $st['on'] === true && $A::team_config()['opsShared'] === true, $st);

$t('a wrong password is refused', $err(function () use ($A) { $A::ops_login(['name' => 'سعيد', 'password' => 'nope']); }) === 'bad_credentials');
$t('a name is required', $err(function () use ($A) { $A::ops_login(['name' => ' ', 'password' => 'Shared-Ops-1']); }) === 'bad_name');
$s1 = $A::ops_login(['name' => 'سعيد', 'password' => 'Shared-Ops-1']);
$t('right password + a name → a session', !empty($s1['token']));
$t('as operations, never owner', ($s1['user']['role'] ?? '') === 'operations', $s1['user']);
$t('signed with the name typed', ($s1['user']['displayName'] ?? '') === 'سعيد', $s1['user']);
$s2 = $A::ops_login(['name' => '  سعيد ', 'password' => 'Shared-Ops-1']);
$t('the same name on another device is the same person', $s2['user']['id'] === $s1['user']['id']);
$s3 = $A::ops_login(['name' => 'منى', 'password' => 'Shared-Ops-1']);
$t('another name is another person', $s3['user']['id'] !== $s1['user']['id']);
$t('the owner sees how many people use it', $A::ops_shared_state()['people'] === 2);

global $wpdb; $S = Mutqan_Store::table('sessions'); $U = Mutqan_Store::table('users');
$live = function ($tok) use ($wpdb, $S) { return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $S WHERE token_hash=%s", hash('sha256', $tok))); };
$ownTok = $A::issue_session($owner['id']);
$wpdb->update($U, ['status' => 'disabled'], ['id' => $s3['user']['id']]);
$t('the owner can stop one name alone', $err(function () use ($A) { $A::ops_login(['name' => 'منى', 'password' => 'Shared-Ops-1']); }) === 'disabled');

$A::set_ops_shared($owner, ['password' => 'Shared-Ops-2']);
$t('a new password ends every shared session', $live($s1['token']) === 0 && $live($s2['token']) === 0);
$t('but not the owner\'s own session', $live($ownTok) === 1);
$t('the old password stops working', $err(function () use ($A) { $A::ops_login(['name' => 'سعيد', 'password' => 'Shared-Ops-1']); }) === 'bad_credentials');
$t('the new one works for the same person', $A::ops_login(['name' => 'سعيد', 'password' => 'Shared-Ops-2'])['user']['id'] === $s1['user']['id']);
$A::set_ops_shared($owner, ['password' => '']);
$t('an empty password closes the door again', $A::team_config()['opsShared'] === false);

/* ------------------------------------------------------------------ the switch: /staff on every face → one address */
$t('off by default: every site keeps its own /staff', $A::team_config()['staffHome'] === '');
$t('only https addresses', $err(function () use ($A, $owner) { $A::set_staff_home($owner, ['url' => 'http://desk.oxygen11.com/ops/']); }) === 'bad_url');
$t('only the owner flips it', $err(function () use ($A, $ops) { $A::set_staff_home($ops, ['url' => 'https://desk.oxygen11.com/ops']); }) === 'forbidden');
$A::set_staff_home($owner, ['url' => 'https://desk.oxygen11.com/ops']);
$t('on: the public config carries the address', $A::team_config()['staffHome'] === 'https://desk.oxygen11.com/ops');
$A::set_staff_home($owner, ['url' => '']);
$t('and it switches back', $A::team_config()['staffHome'] === '');
exit($ok ? 0 : 1);
