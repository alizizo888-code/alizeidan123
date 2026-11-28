<?php
// Seeds one business the way Ali's is set up, so a rehearsal runs against real code and real data.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$PW = 'Rehearsal-1234';
Mutqan_Auth::set_owner('ali', 'علي زيدان', $PW);
$owner = Mutqan_Auth::login(['handle' => 'ali', 'password' => $PW], true);
$p = ['kind' => 'user', 'role' => 'owner', 'id' => $owner['user']['id'], 'powers' => ['read', 'write', 'manage']];

$ops  = Mutqan_Auth::team_op('createMember', ['handle' => 'ops', 'displayName' => 'مشرف العمليات — سعد', 'role' => 'operations', 'password' => $PW], $p);
$tech = Mutqan_Auth::team_op('createMember', ['handle' => 'fahd', 'displayName' => 'فهد الفني', 'role' => 'technician', 'password' => $PW], $p);

$gid = 'main';
$sh = ['groupId' => $gid, 'visibleTo' => 'group', 'writableBy' => 'admins'];
// The technician's own profile: what operations dispatches to.
Mutqan_Store::upsert('tech_profiles', [
    'userId' => $tech['userId'], 'displayName' => 'فهد الفني', 'phone' => '966549423051',
    'specialties' => ['ac'], 'active' => true, 'isSite' => false, 'city' => 'جدة',
], $tech['userId'], $sh, $p);
// His cut of every job.
Mutqan_Store::upsert('tech_pay', ['sharePct' => 30], 'default', $sh, $p);
// Cash, because that is how the money actually moves in the field.
Mutqan_Store::upsert('payment_methods', ['type' => 'cash', 'name' => 'نقدًا للفني', 'enabled' => true], 'pm-cash', $sh, $p);
// And the owner's own facts, so the assistant answers from them.
Mutqan_Store::upsert('ai_settings', [
    'faq' => [
        ['cat' => 'services', 'q' => 'ماذا تشمل صيانة التكييف؟', 'a' => 'غسيل المكيف، فك وتركيب، وتعبئة فريون.'],
        ['cat' => 'hours', 'q' => 'متى تعملون؟', 'a' => 'من الثامنة صباحًا للعاشرة مساءً.', 'always' => true],
    ],
    'globalPolicy' => ['maxDiscountPct' => 15, 'negotiationAllowed' => true],
    'negotiation' => ['enabled' => true, 'style' => 'balanced', 'discountSteps' => 2],
    'serviceArea' => ['enabled' => true, 'name' => 'مكة المكرمة وجدة', 'radiusKm' => 40],
], 'default', $sh, $p);

echo json_encode(['owner' => 'ali', 'ops' => $ops['handle'], 'tech' => $tech['handle'], 'techUserId' => $tech['userId'], 'password' => $PW], JSON_UNESCAPED_UNICODE), "\n";
