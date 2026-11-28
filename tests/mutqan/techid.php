<?php
// «سجل هوية الفني» (owner, 2026-11-24): he fills it once, then only operations change it; sharing needs his consent
// clause, a reason, and is logged.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'techid: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$tech = ['role' => 'technician', 'id' => 'u-t9', 'name' => 'سامي'];
$ops = ['role' => 'operations', 'id' => 'u-o1', 'name' => 'العمليات'];
$png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
$err = function ($f) { try { $f(); return ''; } catch (Mutqan_Error $e) { return $e->slug; } };

$r = Mutqan_TechId::handle(['op' => 'file', 'kind' => 'photo', 'image' => $png], $tech);
$t('the technician adds his own photo the first time', strpos($r['files']['photo'], 'data:image/png') === 0, $r);
$up = wp_upload_dir();
$t('…kept in the private folder (deny all)', is_file($up['basedir'] . '/mutqan-private/.htaccess') && count(glob($up['basedir'] . '/mutqan-private/techid/u-t9-photo-*')) === 1);
Mutqan_TechId::handle(['op' => 'save', 'data' => ['fullName' => 'سامي أحمد', 'iqamaNo' => '2000000001', 'phone' => '0500000009']], $tech);
$t('…locking without the sharing consent is refused', $err(function () use ($tech) { Mutqan_TechId::handle(['op' => 'save', 'data' => [], 'done' => true], $tech); }) === 'consent');
$r = Mutqan_TechId::handle(['op' => 'save', 'data' => [], 'done' => true, 'consent' => true], $tech);
$t('…with it: locked, consent dated', $r['locked'] && $r['consentAt'] !== '', $r);
$t('…after that he cannot change his phone', $err(function () use ($tech) { Mutqan_TechId::handle(['op' => 'save', 'data' => ['phone' => '0599999999']], $tech); }) === 'locked');
$t('…nor his photo', $err(function () use ($tech, $png) { Mutqan_TechId::handle(['op' => 'file', 'kind' => 'photo', 'image' => $png], $tech); }) === 'locked');
$r = Mutqan_TechId::handle(['op' => 'save', 'techId' => 'u-t9', 'data' => ['phone' => '0511111111']], $ops);
$t('operations change it, and it is logged with who', $r['data']['phone'] === '0511111111' && $r['log'][0]['by'] === 'العمليات', $r['log'][0] ?? null);
$t('another technician cannot read his record', Mutqan_TechId::handle(['op' => 'get', 'techId' => 'u-t9'], ['role' => 'technician', 'id' => 'u-x'])['techId'] === 'u-x');
$t('sharing needs a reason', $err(function () use ($ops) { Mutqan_TechId::handle(['op' => 'share', 'techId' => 'u-t9', 'reason' => ''], $ops); }) === 'bad_input');
$r = Mutqan_TechId::handle(['op' => 'share', 'techId' => 'u-t9', 'reason' => 'طلب 1022 شغل برّه باسم المؤسسة', 'to' => 'العميل خالد'], $ops);
$t('…and every share is written in his record', strpos($r['log'][0]['what'], 'مشاركة') === 0 && strpos($r['log'][0]['what'], '1022') !== false, $r['log'][0]);
$t('a technician cannot share', $err(function () use ($tech) { Mutqan_TechId::handle(['op' => 'share', 'reason' => 'xxxxxx'], $tech); }) !== '');
echo $ok ? "OK techid\n" : "FAILED techid\n";
exit($ok ? 0 : 1);
