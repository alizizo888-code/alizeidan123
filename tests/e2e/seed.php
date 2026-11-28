<?php
// Accounts and the owner's two money settings — nothing else. Every profile, order, assignment and
// payment in the run is made through the screens, the way it happens in the field.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/mutqan/wpshim.php';
Mutqan_Store::maybe_install();
$PW = getenv('E2E_PW') ?: 'Flow-Test-1234';
Mutqan_Auth::set_owner('ali', 'علي', $PW);
$o = Mutqan_Auth::login(['handle' => 'ali', 'password' => $PW], true);
$p = ['kind' => 'user', 'role' => 'owner', 'id' => $o['user']['id'], 'powers' => ['read', 'write', 'manage']];
Mutqan_Auth::team_op('createMember', ['handle' => 'ops', 'displayName' => 'سعد — العمليات', 'role' => 'operations', 'password' => $PW], $p);
Mutqan_Auth::team_op('createMember', ['handle' => 'fahd', 'displayName' => 'فهد', 'role' => 'technician', 'password' => $PW], $p);
$sh = ['groupId' => 'main', 'visibleTo' => 'group', 'writableBy' => 'admins'];
Mutqan_Store::upsert('tech_pay', ['sharePct' => 30], 'default', $sh, $p);
Mutqan_Store::upsert('payment_methods', ['type' => 'cash', 'name' => 'نقدًا للفني', 'enabled' => true], 'pm-cash', $sh, $p);
// the desk's owner PIN (Oxygen_Desk) — the e2e server loads that class when MQ_DESK is set
update_option('oxygen_desk_pin', password_hash('2468', PASSWORD_DEFAULT), false);
echo "seeded\n";
