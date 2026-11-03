<?php
declare(strict_types=1);

// Usage: php tests/run.php   (no network, no database server needed)
putenv('OXY_CONFIG=' . __DIR__ . '/config.test.php');
require dirname(__DIR__) . '/src/bootstrap.php';

$passed = 0;
$failed = 0;
function check(string $name, bool $cond): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed++;
        echo "  FAIL $name\n";
    }
}
function throws(callable $fn, string $code): bool
{
    try {
        $fn();
        return false;
    } catch (OxyHttpError $e) {
        return $e->errCode === $code;
    }
}

echo "HMAC signing\n";
$secret = str_repeat('f', 64);
$ts = '1767225600';
$nonce = str_repeat('a', 32);
$body = '{"scope":"all"}';
$sig = Hmac::sign($secret, 'POST', '/oxy-bridge/v1/purge', $ts, $nonce, $body);
check('canonical string is METHOD, ROUTE, TS, NONCE, sha256(body)', Hmac::canonical('post', '/oxy-bridge/v1/purge', $ts, $nonce, $body) === "POST\n/oxy-bridge/v1/purge\n$ts\n$nonce\n" . hash('sha256', $body));
check('valid signature verifies', Hmac::verify($secret, 'POST', '/oxy-bridge/v1/purge', $ts, $nonce, $body, $sig, (int) $ts + 10));
check('tampered body is rejected', !Hmac::verify($secret, 'POST', '/oxy-bridge/v1/purge', $ts, $nonce, '{"scope":"x"}', $sig, (int) $ts + 10));
check('different route is rejected', !Hmac::verify($secret, 'POST', '/oxy-bridge/v1/status', $ts, $nonce, $body, $sig, (int) $ts + 10));
check('timestamp older than 5 minutes is rejected', !Hmac::verify($secret, 'POST', '/oxy-bridge/v1/purge', $ts, $nonce, $body, $sig, (int) $ts + 301));
check('malformed nonce is rejected', !Hmac::verify($secret, 'POST', '/oxy-bridge/v1/purge', $ts, 'xyz', $body, $sig, (int) $ts));

echo "Vault\n";
$sealed = Vault::seal($secret);
check('vault round-trips the bridge secret', Vault::open($sealed) === $secret);
check('vault does not store the plain secret', strpos($sealed, $secret) === false);
$tampered = base64_encode(substr(base64_decode($sealed), 0, -1) . 'x');
$threw = false;
try {
    Vault::open($tampered);
} catch (RuntimeException $e) {
    $threw = true;
}
check('vault detects tampering', $threw);

echo "Schema and registry\n";
$pdo = oxy_pdo();
foreach (array_filter(array_map('trim', explode(';', (string) file_get_contents(OXY_ROOT . '/sql/schema.sql'))), static fn ($s) => $s !== '') as $statement) {
    $pdo->exec(preg_replace('/^--.*$/m', '', $statement) ?? $statement);
}
check('seed adds the 22 faces', Sites::seed() === 22);
check('seed is idempotent', Sites::seed() === 0);
check('every face has an unpaired status before pairing', count(array_filter(Sites::all(), static fn ($s) => $s['status'] === 'unpaired')) === 22);

echo "Pairing\n";
check('pairing rejects a malformed secret', throws(static fn () => Sites::pair('oxygen', 'short'), 'secret_format'));
Sites::pair('oxygen', $secret);
$row = Sites::find('oxygen');
check('pairing stores the secret sealed, not in clear', $row['secret_enc'] !== null && strpos((string) $row['secret_enc'], $secret) === false);
check('pairing marks the face as known', Sites::find('oxygen')['status'] === 'unknown');

echo "Owner PIN and lockout\n";
check('PIN must be exactly six digits', throws(static fn () => Auth::setPin('12345'), 'pin_format'));
Auth::setPin('246810');
check('wrong PIN is rejected', throws(static fn () => Auth::checkPin('000000'), 'bad_pin'));
for ($i = 0; $i < 3; $i++) {
    throws(static fn () => Auth::checkPin('000000'), 'bad_pin');
}
check('fifth failure locks the panel', throws(static fn () => Auth::checkPin('000000'), 'locked'));
check('correct PIN is refused while locked', throws(static fn () => Auth::checkPin('246810'), 'locked'));
check('PIN is stored hashed', Settings::get('pin_hash') !== null && strpos((string) Settings::get('pin_hash'), '246810') === false);

echo "Queue\n";
Jobs::enqueue('health', 'nasmat');
$summary = Jobs::tick(50);
check('unpaired faces are skipped, not retried forever', $summary['skipped'] >= 1 && $summary['retry'] === 0);
check('queue counts reflect the skipped job', Jobs::counts()['skipped'] >= 1);
check('unknown job types are refused', throws(static fn () => Jobs::enqueue('delete_everything', 'oxygen'), 'bad_job_type'));

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
