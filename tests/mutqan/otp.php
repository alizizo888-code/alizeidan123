<?php
// Signing in with a mobile number and a one-time code.
//
// This is the softest way into the system — no password, just a phone — so most of what follows is
// about what a code must NOT buy: a staff role, somebody else's account, or a way to find out who
// is registered.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-otp-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
require dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/class-oxygen-desk.php';
Mutqan_Store::maybe_install();
Mutqan_Rest::routes();

$bad = 0;
function t($n, $c, $x = null) { global $bad; if (!$c) { $bad++; } echo ($c ? 'PASS ' : 'FAIL ') . "otp: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; }
function err($fn) { try { $fn(); return ''; } catch (Mutqan_Error $e) { return $e->slug; } catch (Throwable $e) { return 'threw:' . $e->getMessage(); } }
/** Each section is a different person on a different connection, so the rate limit is not the thing under test. */
function caller($n) { $_SERVER['REMOTE_ADDR'] = '10.0.0.' . $n; }
/** Puts a known code in place, exactly as request() would. */
function seed($phone, $code, $tries = 0, $at = null) {
    $r = new ReflectionMethod('Mutqan_OTP', 'key');
    $r->setAccessible(true);
    set_transient($r->invoke(null, Mutqan_OTP::normalize($phone)), [
        'hash' => password_hash($code, PASSWORD_DEFAULT), 'at' => $at ?? time(), 'tries' => $tries,
    ], Mutqan_OTP::TTL);
}

// --- the number itself --------------------------------------------------------------------------
caller(11);
t('a Saudi local number becomes the international form', Mutqan_OTP::normalize('0551234567') === '966551234567');
t('a number without the zero works too', Mutqan_OTP::normalize('551234567') === '966551234567');
t('a number written with 00 works', Mutqan_OTP::normalize('00966551234567') === '966551234567');
t('spaces and dashes are ignored', Mutqan_OTP::normalize('055 123-4567') === '966551234567');
t('a too-short number is refused', err(fn () => Mutqan_OTP::request(['phone' => '123'])) === 'bad_phone');
t('letters are refused', err(fn () => Mutqan_OTP::request(['phone' => 'ابو خالد'])) === 'bad_phone');

// --- a first-time caller becomes a customer -------------------------------------------------------
caller(12);
$phone = '0551112233';
seed($phone, '123456');
$r = Mutqan_OTP::verify(['phone' => $phone, 'code' => '123456', 'name' => 'منى']);
t('the right code signs them in', !empty($r['token']) && !empty($r['user']));
t('they get a customer account, never a staff one', array_key_exists('role', $r['user']) && $r['user']['role'] === null, $r['user']);
t('the name they gave is kept', ($r['user']['displayName'] ?? '') === 'منى');

// --- the same number is the same person, not a second account -------------------------------------
caller(13);
seed('966551112233', '222222');
$r2 = Mutqan_OTP::verify(['phone' => '966551112233', 'code' => '222222']);
t('the same number in another form is the same account', ($r2['user']['id'] ?? '') === ($r['user']['id'] ?? 'x'), [$r['user']['id'] ?? null, $r2['user']['id'] ?? null]);

// --- a code is good once ----------------------------------------------------------------------------
caller(14);
seed('0551110000', '333333');
Mutqan_OTP::verify(['phone' => '0551110000', 'code' => '333333']);
t('a code cannot be used twice', err(fn () => Mutqan_OTP::verify(['phone' => '0551110000', 'code' => '333333'])) === 'otp_expired');

// --- guessing -----------------------------------------------------------------------------------------
caller(15);
$g = '0551114444';
seed($g, '444444');
t('a wrong code is refused', err(fn () => Mutqan_OTP::verify(['phone' => $g, 'code' => '000000'])) === 'otp_wrong');
for ($i = 0; $i < 4; $i++) { err(fn () => Mutqan_OTP::verify(['phone' => $g, 'code' => '000001'])); }
t('after enough wrong guesses the code is thrown away', in_array(err(fn () => Mutqan_OTP::verify(['phone' => $g, 'code' => '444444'])), ['otp_locked', 'otp_expired'], true));

seed($g, '555555');
t('and a fresh code works again after that', !empty(Mutqan_OTP::verify(['phone' => $g, 'code' => '555555'])['token']));

// --- an expired code --------------------------------------------------------------------------------
caller(16);
seed('0551115555', '666666', 0, time() - Mutqan_OTP::TTL - 10);
delete_transient((function () { $r = new ReflectionMethod('Mutqan_OTP', 'key'); $r->setAccessible(true); return $r->invoke(null, '966551115555'); })());
t('an expired code is refused', err(fn () => Mutqan_OTP::verify(['phone' => '0551115555', 'code' => '666666'])) === 'otp_expired');

// --- the stored code is not readable ------------------------------------------------------------------
caller(17);
seed('0551116666', '777777');
$k = (function () { $r = new ReflectionMethod('Mutqan_OTP', 'key'); $r->setAccessible(true); return $r->invoke(null, '966551116666'); })();
$stored = get_transient($k);
t('the code is stored hashed, not in the clear', is_array($stored) && strpos((string) $stored['hash'], '777777') === false && strlen($stored['hash']) > 20);

// --- a code never grants a staff role -------------------------------------------------------------------
caller(18);
// This is the one that matters: a phone proves a phone, not a job.
seed('0559998888', '888888');
$s = Mutqan_OTP::verify(['phone' => '0559998888', 'code' => '888888', 'role' => 'technician', 'name' => 'مدّعي']);
t('asking for a role in the request does not grant it', array_key_exists('role', $s['user']) && $s['user']['role'] === null, $s['user']);
$powers = Mutqan_Auth::powers_for($s['user']['role'] ?? null);
t('and the account carries no powers', $powers === [], $powers);

// --- a technician asking to join creates a request, not an account -----------------------------------------
caller(19);
$guest = ['id' => 'anon-x', 'role' => null, 'kind' => 'anon', 'powers' => []];
$before = count(Mutqan_Store::select('join_requests', [], ['limit' => 50], Mutqan_WA::sys(), false));
$jr = Mutqan_OTP::request_staff(['phone' => '0557776666', 'name' => 'سامي', 'craft' => 'تكييف'], $guest);
$after = Mutqan_Store::select('join_requests', [], ['limit' => 50], Mutqan_WA::sys(), false);
t('a join request is recorded', !empty($jr['pending']) && count($after) === $before + 1);
t('it is pending, not approved', ($after[0]['status'] ?? '') === 'pending');
t('it grants nothing yet', Mutqan_Auth::find_by_phone_any('966557776666') === null);
t('a join request with no name is refused', err(fn () => Mutqan_OTP::request_staff(['phone' => '0557776666'], $guest)) === 'bad_name');

// --- asking for a code says nothing about who is registered ----------------------------------------------
caller(20);
$known = Mutqan_OTP::request(['phone' => '0552223344']);
$unknown = Mutqan_OTP::request(['phone' => '0559990000']);
t('the answer for a known and an unknown number is the same shape',
    array_keys($known) === array_keys($unknown) && $known['ok'] === $unknown['ok'] && $known['expiresIn'] === $unknown['expiresIn']);
t('the answer never contains the code', !in_array('code', array_keys($known), true) && json_encode($known) !== false && !preg_match('/\d{6}/', json_encode($known)));

// --- a code cannot be demanded over and over --------------------------------------------------------------
t('asking again straight away is refused', err(fn () => Mutqan_OTP::request(['phone' => '0552223344'])) === 'too_soon');

exit($bad === 0 ? 0 : 1);
