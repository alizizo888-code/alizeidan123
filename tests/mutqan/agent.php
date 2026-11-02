<?php
// Owner control assistant: who may use it, workload tiers, what waits for confirmation, scope guards.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-agent-' . getmypid());
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "agent: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$owner = ['id' => 'u-own', 'role' => 'owner', 'kind' => 'user', 'powers' => ['read', 'write', 'manage']];
$ops = ['id' => 'u-ops', 'role' => 'operations', 'kind' => 'user', 'powers' => ['read', 'write', 'manage']];
$tech = ['id' => 'u-tech', 'role' => 'technician', 'kind' => 'user', 'powers' => ['read']];
$cust = ['id' => 'anon-cust000001', 'role' => null, 'kind' => 'anon', 'powers' => []];

// tiers
$t('customer asking high gets mid', Mutqan_AI::allowed_tier('high', $cust) === 'mid');
$t('customer default is low', Mutqan_AI::allowed_tier(null, $cust) === 'low');
$t('owner may use high', Mutqan_AI::allowed_tier('high', $owner) === 'high' && Mutqan_AI::allowed_tier('high', $ops) === 'high');
$t('no Claude key -> tiers fall back to free providers', Mutqan_AI::tier_model('high') === '' && Mutqan_AI::tier_model('mid') === '');
$s = Mutqan_AI::settings(); $s['providers']['anthropic'] = ['enabled' => 1, 'key' => 'sk-test', 'model' => 'claude-haiku-5-5']; update_option(Mutqan_AI::OPT, $s);
$t('tier models', Mutqan_AI::tier_model('mid') === 'claude-haiku-5-5' && Mutqan_AI::tier_model('routine') === 'claude-sonnet-5-5' && Mutqan_AI::tier_model('high') === 'claude-opus-5-5' && Mutqan_AI::tier_model('low') === '');

// access
$deny = function ($p) { try { Mutqan_Agent::handle(['messages' => [['role' => 'user', 'text' => 'hi']]], $p); return false; } catch (Mutqan_Error $e) { return $e->status === 403 || $e->status === 401; } };
$t('technician cannot use the assistant', $deny($tech));
$t('customer cannot use the assistant', $deny($cust));

// confirmation policy
$t('whatsapp waits for confirm', Mutqan_Agent::needs_confirm('wa_send', ['phone' => '1', 'text' => 'x']));
$t('dns waits for confirm', Mutqan_Agent::needs_confirm('dns_set', ['name' => 'a', 'type' => 'CNAME', 'content' => 'b']));
$t('reads run directly', !Mutqan_Agent::needs_confirm('orders_list', []) && !Mutqan_Agent::needs_confirm('dns_list', []));

// scope guards
$thr = function ($fn) { try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); } };
$t('protected DNS names refused', strpos($thr(function () use ($owner) { Mutqan_Agent::run_tool('dns_set', ['name' => 'www', 'type' => 'CNAME', 'content' => 'evil.com'], $owner); }), 'محمي') !== false);
$t('bad DNS name refused', strpos($thr(function () use ($owner) { Mutqan_Agent::run_tool('dns_set', ['name' => '../x', 'type' => 'A', 'content' => '1.2.3.4'], $owner); }), 'محمي') !== false);
$t('no Hostinger token -> clear message', strpos($thr(function () use ($owner) { Mutqan_Agent::run_tool('dns_list', [], $owner); }), 'Hostinger') !== false);
$t('unknown tool refused', $thr(function () use ($owner) { Mutqan_Agent::run_tool('shell', ['cmd' => 'ls'], $owner); }) !== '');
$names = array_column(Mutqan_Agent::tools(), 'name');
$t('no generic web/shell/file tools', !array_filter($names, function ($n) { return preg_match('/http|fetch|shell|exec|file|url/', $n); }), $names);
$t('confirm with unknown id expires', strpos($thr(function () use ($owner) { Mutqan_Agent::handle(['confirm' => 'nope'], $owner); }), 'انتهت') !== false);

// operations, customers, settings
$o1 = Mutqan_Store::insert('orders', ['status' => 'new', 'clientName' => 'أحمد', 'clientPhone' => '0551112222', 'address' => 'مكة', 'brand' => 'khabeer'], null, [], $owner);
Mutqan_Store::insert('orders', ['status' => 'completed', 'clientName' => 'أحمد', 'clientPhone' => '+966551112222', 'address' => 'مكة'], null, [], $owner);
Mutqan_Store::insert('orders', ['status' => 'new', 'clientName' => 'سارة', 'clientPhone' => '0503334444'], null, [], $owner);
$cs = Mutqan_Agent::run_tool('customers_list', [], $owner);
$ah = array_values(array_filter($cs, function ($c) { return $c['name'] === 'أحمد'; }));
$t('customers grouped by phone', count($cs) === 2 && $ah && $ah[0]['orders'] === 2 && $ah[0]['kind'] === 'returning', $cs);
$t('customers search', count(Mutqan_Agent::run_tool('customers_list', ['q' => 'سارة'], $owner)) === 1);
$as = Mutqan_Agent::run_tool('order_assign', ['id' => $o1['id'], 'techId' => 'u-tech'], $owner);
$t('assign sets technician + status', $as['technicianId'] === 'u-tech' && $as['status'] === 'assigned', $as);
$t('settings_set waits for confirm', Mutqan_Agent::needs_confirm('settings_set', ['key' => 'pricing', 'doc_id' => 'x', 'patch' => ['a' => 1]]));
$t('unknown settings key refused', $thr(function () use ($owner) { Mutqan_Agent::run_tool('settings_get', ['key' => '_users'], $owner); }) !== '');
$sd = Mutqan_Store::insert('ai_settings', ['tone' => 'ودود', 'apiKey' => 'sk-live-xyz'], null, [], $owner);
$g = Mutqan_Agent::run_tool('settings_get', ['key' => 'ai_settings'], $owner);
$t('secrets hidden in settings', $g && $g[0]['apiKey'] !== 'sk-live-xyz' && $g[0]['tone'] === 'ودود', $g);
$t('secret fields not writable', strpos($thr(function () use ($owner, $sd) { Mutqan_Agent::run_tool('settings_set', ['key' => 'ai_settings', 'doc_id' => $sd['id'], 'patch' => ['apiKey' => 'x']], $owner); }), 'مش بيتعدل') !== false);
$u = Mutqan_Agent::run_tool('settings_set', ['key' => 'ai_settings', 'doc_id' => $sd['id'], 'patch' => ['tone' => 'رسمي']], $owner);
$t('settings patch merges', $u['tone'] === 'رسمي' && $u['apiKey'] !== 'sk-live-xyz', $u);

// the site's own AI engine
$e = Mutqan_Agent::run_tool('ai_engine', [], $owner);
$t('ai engine status hides keys', isset($e['providers']['groq']) && !isset($e['providers']['groq']['key']) && strpos(json_encode($e), 'sk-test') === false, $e);
$t('ai engine changes need confirm', Mutqan_Agent::needs_confirm('ai_engine_set', ['order' => ['gemini']]));
Mutqan_Agent::run_tool('ai_engine_set', ['order' => ['gemini', 'groq', 'evil'], 'providers' => ['gemini' => ['enabled' => true, 'key' => 'hack', 'model' => 'gemini-x']]], $owner);
$st = Mutqan_AI::settings();
$t('ai engine set: order filtered, key not writable', $st['order'] === ['gemini', 'groq'] && $st['providers']['gemini']['key'] === '' && $st['providers']['gemini']['model'] === 'gemini-x' && $st['providers']['gemini']['enabled'] == 1, $st);

echo $ok ? "ALL PASSED\n" : "SOME FAILED\n";
exit($ok ? 0 : 1);
