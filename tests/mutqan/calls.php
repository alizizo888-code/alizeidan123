<?php
// Hotline call register: who may read / write call_log + call_contacts, create-once (409) semantics, and what staff may do to a `calls` document.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-calls-' . getmypid());
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "calls: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$owner = ['id' => 'u-owner', 'role' => 'owner', 'kind' => 'user', 'powers' => ['read', 'write', 'manage'], 'name' => 'o'];
$ops = ['id' => 'u-ops', 'role' => 'operations', 'kind' => 'user', 'powers' => ['read', 'write', 'manage'], 'name' => 'x'];
$tech = ['id' => 'u-tech', 'role' => 'technician', 'kind' => 'user', 'powers' => [], 'name' => 't'];
$cust = ['id' => 'u-cust', 'role' => null, 'kind' => 'user', 'powers' => []];
$anon = ['id' => 'anon-abc123456', 'role' => null, 'kind' => 'anon', 'powers' => []];
$guest = ['id' => 'guest', 'role' => null, 'kind' => 'guest', 'powers' => []];
$denied = function ($fn) { try { $fn(); return false; } catch (Mutqan_Error $e) { return in_array($e->status, [401, 403], true); } };
$code = function ($fn) { try { $fn(); return 200; } catch (Mutqan_Error $e) { return $e->slug; } };
$o = ['visibleTo' => 'creator-and-admin', 'writableBy' => 'admins'];

// --- a customer (anonymous visitor) rings: allowed, and the doc keeps his deviceId; staff can then answer it
$call = Mutqan_Store::insert('calls', ['status' => 'ringing', 'name' => 'عميل', 'phone' => '', 'userId' => '', 'deviceId' => 'dGUEST000000000000000000000000001', 'cSig' => ['sdp' => 'v=0', 'c' => []]], null, $o, $anon, false);
$t('visitor can ring the hotline (calls insert)', !empty($call['id']) && ($call['deviceId'] ?? '') === 'dGUEST000000000000000000000000001', $call);
$u = Mutqan_Store::update('calls', $call['id'], ['status' => 'active', 'answeredBy' => 'x', 'answeredAt' => gmdate('c')], $ops);
$t('operations answers it (status/answeredAt)', $u['status'] === 'active' && !empty($u['answeredAt']), $u);
$u = Mutqan_Store::update('calls', $call['id'], ['status' => 'ended', 'endedAt' => gmdate('c'), 'cSig' => null, 'sSig' => null], $ops);
$t('ending a call wipes the signalling data', $u['status'] === 'ended' && $u['cSig'] === null, $u);
$t('another visitor cannot touch it', $denied(function () use ($call, $cust) { Mutqan_Store::update('calls', $call['id'], ['status' => 'ended'], $cust); }));
$list = Mutqan_Store::select('calls', [], ['limit' => 20, 'order' => '-createdAt'], $ops, false);
$t('operations lists recent calls', count($list) === 1, $list);

// --- register: operations / owner read + write
$row = Mutqan_Store::insert('call_log', ['callId' => 'c1', 'code' => 'C-ABCDE', 'status' => 'ringing', 'links' => [], 'note' => ''], 'c1', $o, $ops, false);
$t('operations creates a call_log row', ($row['code'] ?? '') === 'C-ABCDE', $row);
$t('creating the same id twice is refused (409 exists) — never overwrites', $code(function () use ($o, $ops) { Mutqan_Store::insert('call_log', ['callId' => 'c1', 'code' => 'C-OTHER', 'links' => []], 'c1', $o, $ops, false); }) === 'exists');
$u = Mutqan_Store::update('call_log', 'c1', ['links' => [['type' => 'order', 'ref' => 'o1', 'label' => 'غسيل']], 'status' => 'answered'], $ops);
$t('patch keeps untouched fields, replaces given ones', $u['code'] === 'C-ABCDE' && $u['status'] === 'answered' && count($u['links']) === 1, $u);
$ct = Mutqan_Store::insert('call_contacts', ['code' => 'C-ABCDE', 'keys' => ['DdGUEST'], 'kind' => 'guest', 'links' => []], 'C-ABCDE', $o, $owner, false);
$t('owner creates the contact (id = code)', ($ct['id'] ?? '') === 'C-ABCDE', $ct);
$rows = Mutqan_Store::select('call_log', ['id' => 'c1'], ['limit' => 1], $owner, false);
$t('select by id works for the owner', count($rows) === 1 && $rows[0]['id'] === 'c1', $rows);
$t('ids with a dash / colon are valid', !empty(Mutqan_Store::insert('call_log', ['callId' => 'm-lx1y2z', 'code' => 'C-ABCDE'], 'm-lx1y2z', $o, $ops, false)['id']));
$t('customer card key p<last9> is valid', !empty(Mutqan_Store::upsert('customer_profiles', ['name' => 'أحمد', 'phone' => '966501234567'], 'p501234567', $o, $ops)['id']));

// --- everybody else: no reading, no writing
foreach (['technician' => $tech, 'customer' => $cust, 'visitor' => $anon, 'guest' => $guest] as $who => $p) {
    foreach (['call_log', 'call_contacts'] as $coll) {
        $t("$who cannot read $coll", count(Mutqan_Store::select($coll, [], ['limit' => 50], $p, false)) === 0);
        $t("$who cannot insert into $coll", $denied(function () use ($coll, $p, $o) { Mutqan_Store::insert($coll, ['code' => 'C-FAKE1', 'links' => []], 'fake-' . $coll, $o, $p, false); }));
    }
    $t("$who cannot edit a call_log row", $denied(function () use ($p) { Mutqan_Store::update('call_log', 'c1', ['note' => 'x'], $p); }));
    $t("$who cannot delete a call_contacts row", $denied(function () use ($p) { Mutqan_Store::delete('call_contacts', 'C-ABCDE', $p); }));
}
$after = Mutqan_Store::select('call_log', ['id' => 'c1'], ['limit' => 1], $owner, false);
$t('the row was not changed by any of them', ($after[0]['note'] ?? '') === '' && $after[0]['status'] === 'answered', $after);
exit($ok ? 0 : 1);
