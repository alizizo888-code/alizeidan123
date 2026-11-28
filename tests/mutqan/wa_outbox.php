<?php
// The phone asks for work instead of being called.
//
// A gateway running on a handset has no address anyone can reach, so WordPress cannot dial it to
// send anything — and sending is most of the system: the job card, the login code, every bot reply.
// The message is queued and the phone collects it. These checks are the rules that keep that queue
// honest when the thing collecting from it is a phone that sleeps, loses signal and gets killed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
$GLOBALS['__pushed'] = [];
function wp_remote_request($url, $a) {
    $GLOBALS['__pushed'][] = $url;
    return ['code' => 200, 'body' => json_encode(['msgId' => 'P' . count($GLOBALS['__pushed']), 'sessions' => [['id' => 'acct1', 'status' => 'open']]]), 'headers' => []];
}
Mutqan_Store::maybe_install();
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'wa-outbox: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$W = 'Mutqan_WA';
$sign = function ($body) { $c = Mutqan_WA::config_get(); return hash_hmac('sha256', $body, $c['secret']); };

/* ---------------------------------------------------------------- with no URL, nothing is dialled */
Mutqan_WA::config_set(['url' => '', 'token' => '']);
update_option('mutqan_wa_last_acct', 'acct1', false);
$GLOBALS['__pushed'] = [];
$r = $W::auto_send('0551234567', 'كارت المهمة', 'dispatch');
$t('a message still goes out when there is no gateway to dial', $r === 'queued', $r);
$t('and nothing was dialled', count($GLOBALS['__pushed']) === 0, $GLOBALS['__pushed']);
$pending = Mutqan_Store::select('wa_outbox', [], ['limit' => 10], $W::sys());
$t('it is waiting in the queue instead', count($pending) === 1 && $pending[0]['text'] === 'كارت المهمة', $pending);
$t('addressed in international form', ($pending[0]['to'] ?? '') === '966551234567', $pending[0]['to'] ?? null);

/* ---------------------------------------------------------------- the phone collects it */
$out = $W::pull(['limit' => 10]);
$t('the phone is handed the job', count($out['jobs']) === 1 && $out['jobs'][0]['text'] === 'كارت المهمة', $out);
$t('with the number it should send from', ($out['jobs'][0]['acct'] ?? '') === 'acct1', $out['jobs'][0] ?? null);
$again = $W::pull(['limit' => 10]);
$t('a second phone, or a double poll, is not handed the same job twice', count($again['jobs']) === 0, $again);

/* ---------------------------------------------------------------- it reports back */
$jobId = $out['jobs'][0]['id'];
$W::pull(['sent' => [['id' => $jobId, 'ok' => true, 'msgId' => 'W1']], 'limit' => 10]);
$t('an acknowledged job leaves the queue', count(Mutqan_Store::select('wa_outbox', [], ['limit' => 10], $W::sys())) === 0);
$msgs = Mutqan_Store::select('wa_msgs', [], ['limit' => 10], $W::sys());
$t('and is written into the conversation, so the team sees what was sent',
   count($msgs) === 1 && $msgs[0]['text'] === 'كارت المهمة' && $msgs[0]['dir'] === 'out', $msgs);

/* ---------------------------------------------------------------- a phone that fails to send */
$W::queue('acct1', '0559999999', 'رسالة هتفشل');
$j = $W::pull(['limit' => 5])['jobs'][0];
// Reporting a failure also hands the job straight back out, so the phone keeps working; the count
// of attempts is what decides when to stop, not who is holding it.
$out2 = $W::pull(['sent' => [['id' => $j['id'], 'ok' => false]], 'limit' => 5]);
$row = Mutqan_Store::get_raw('wa_outbox', $j['id']);
$t('a failed send is counted and offered again', (int) ($row['tries'] ?? 0) === 1 && count($out2['jobs']) === 1, $row);
for ($i = 0; $i < 4; $i++) { $W::pull(['sent' => [['id' => $j['id'], 'ok' => false]], 'limit' => 5]); }
$t('but it is dropped rather than retried forever into a number that keeps failing',
   Mutqan_Store::get_raw('wa_outbox', $j['id']) === null, Mutqan_Store::get_raw('wa_outbox', $j['id']));

/* ---------------------------------------------------------------- a phone that dies mid-send */
$W::queue('acct1', '0551112222', 'الموبايل فصل');
$taken = $W::pull(['limit' => 5])['jobs'][0];
$t('handed out, so not offered again straight away', count($W::pull(['limit' => 5])['jobs']) === 0);
Mutqan_Store::update('wa_outbox', $taken['id'], ['takenAt' => time() - (Mutqan_WA::TAKEN_STUCK + 60)], $W::sys());
$t('but a job nobody ever acknowledged is offered again, not stranded', count($W::pull(['limit' => 5])['jobs']) === 1);

/* ---------------------------------------------------------------- the signature */
$body = json_encode(['limit' => 1]);
$caught = '';
try { $W::pull_signed($body, 'not-the-signature'); } catch (Throwable $e) { $caught = $e->getMessage(); }
$t('an unsigned request is refused — the queue holds customers\' numbers', $caught !== '', $caught);
$okCall = $W::pull_signed($body, $sign($body));
$t('a properly signed one is answered', isset($okCall['jobs']));

/* ---------------------------------------------------------------- a dialable gateway is unchanged */
Mutqan_WA::config_set(['url' => 'https://wa.test', 'token' => 'tok-abcdefghijklmnopqrstuvwx']);
delete_transient('mq_wa_open');
$GLOBALS['__pushed'] = [];
$before = count(Mutqan_Store::select('wa_outbox', [], ['limit' => 20], $W::sys()));
$W::send('acct1', '966551234567', 'عبر الوسيط', 'auto');
$t('with a URL to dial, the message is pushed as before', count(array_filter($GLOBALS['__pushed'], function ($u) { return strpos($u, '/send') !== false; })) === 1, $GLOBALS['__pushed']);
$t('and nothing new is left in the queue', count(Mutqan_Store::select('wa_outbox', [], ['limit' => 20], $W::sys())) === $before);

/* ---------------------------------------------------------------- the QR has to reach a second screen */
// You cannot hold the handset running the gateway up to itself, so the code it generates has to
// appear in the operations room. The phone reports it; the screen shows it.
Mutqan_WA::config_set(['url' => '', 'token' => '']);
$cfg = Mutqan_WA::config_get();
$t('the setup panel says the gateway is on a phone', ($cfg['onPhone'] ?? null) === true && !empty($cfg['pullUrl']), $cfg);
$qr = 'data:image/png;base64,' . base64_encode(str_repeat('q', 64));
$ev = json_encode(['type' => 'status', 'accountId' => 'main', 'status' => 'qr', 'label' => 'رقم الشركة', 'qr' => $qr]);
$W::webhook($ev, hash_hmac('sha256', $ev, $cfg['secret']));
$list = $W::sessions();
$t('the number the phone opened shows up on the screen', count($list['sessions']) === 1 && $list['sessions'][0]['id'] === 'main', $list);
$t('with its QR, ready to be scanned from another phone', ($list['sessions'][0]['qr'] ?? '') === $qr);
$t('and marked as coming from a phone', ($list['sessions'][0]['via'] ?? '') === 'phone');

$ev2 = json_encode(['type' => 'status', 'accountId' => 'main', 'status' => 'open', 'phone' => '966501112233', 'name' => 'أكسجين']);
$W::webhook($ev2, hash_hmac('sha256', $ev2, $cfg['secret']));
$list = $W::sessions();
$t('once it is linked the number is shown instead', ($list['sessions'][0]['status'] ?? '') === 'open' && ($list['sessions'][0]['phone'] ?? '') === '966501112233', $list['sessions'][0]);
$t('and the QR is gone, because a scanned code is not for showing', empty($list['sessions'][0]['qr']));
$t('that number is now the one queued messages are sent from', $W::open_account() === 'main');

// A WhatsApp QR dies after about a minute. Showing a dead one is worse than showing none: it is
// scanned, it fails, and nobody can tell why.
$seen = get_option('mutqan_wa_seen', []);
$seen['main'] = ['status' => 'qr', 'label' => 'رقم الشركة', 'qr' => $qr, 'at' => time() - 600];
update_option('mutqan_wa_seen', $seen, false);
$list = $W::sessions();
$t('an expired code is not offered for scanning', empty($list['sessions'][0]['qr']), $list['sessions'][0]);
$t('and the number reads as stopped, not as waiting', ($list['sessions'][0]['status'] ?? '') === 'stopped', $list['sessions'][0]);

$rubbish = json_encode(['type' => 'status', 'accountId' => 'main', 'status' => 'qr', 'qr' => 'javascript:alert(1)']);
$W::webhook($rubbish, hash_hmac('sha256', $rubbish, $cfg['secret']));
$t('anything that is not an image is refused — this ends up in an <img> tag', empty($W::sessions()['sessions'][0]['qr']));

/* ---------------------------------------------------------------- the customer's order copy, before the number was ever connected */
// Ali: pressing «تأكيد» must not send the customer to WhatsApp. The server sends his copy itself;
// if the business number has not connected yet, the copy waits for it — but never days late.
foreach (Mutqan_Store::select('wa_outbox', [], ['limit' => 100], $W::sys()) as $j) { Mutqan_Store::delete('wa_outbox', $j['id'], $W::sys()); }
Mutqan_WA::config_set(['url' => '', 'token' => '']);
delete_option('mutqan_wa_last_acct');
$r = $W::auto_send('0551234567', 'تم استلام طلبك', 'order');
$t('no number connected yet: the order copy is queued, not handed back to the customer', $r === 'queued', $r);
$t('a login code is not queued — it must fail now so its screen can fall back', $W::auto_send('0551234567', 'رمز 1234', 'otp') === false);
$t('nor a technician job card', $W::auto_send('0551234567', 'كارت', 'dispatch') === false);
$q = Mutqan_Store::select('wa_outbox', [], ['limit' => 10], $W::sys());
$t('exactly one job waiting', count($q) === 1 && $q[0]['text'] === 'تم استلام طلبك', $q);
$out = $W::pull(['limit' => 10]);
$t('the phone sends it the moment it connects', count($out['jobs']) === 1, $out);
$W::pull(['sent' => [['id' => $out['jobs'][0]['id'], 'ok' => true, 'msgId' => 'W9']], 'limit' => 10]);
$W::auto_send('0551234567', 'طلب قديم', 'order');
$old = Mutqan_Store::select('wa_outbox', [], ['limit' => 10], $W::sys())[0];
Mutqan_Store::update('wa_outbox', $old['id'], ['at' => gmdate('c', time() - 7 * 3600)], $W::sys());
$late = $W::pull(['limit' => 10]);
$t('a copy older than 6 hours is dropped, not sent late', count($late['jobs']) === 0 && count(Mutqan_Store::select('wa_outbox', [], ['limit' => 10], $W::sys())) === 0, $late);

exit($ok ? 0 : 1);
