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

echo "Management: supervisors\n";
$sup = People::create(['role' => 'supervisor', 'name' => '  أحمد   علي ', 'phone' => '+966 55 123 4567', 'area' => 'makkah']);
check('name is trimmed and spaces are collapsed', $sup['name'] === 'أحمد علي');
check('phone is normalised to digits with a leading plus', $sup['phone'] === '+966551234567');
check('Arabic-Indic digits in a phone are accepted', People::create(['role' => 'supervisor', 'name' => 'سارة', 'phone' => '٠٥٥٥١٢٣٤٥٦'])['phone'] === '0555123456');
check('a new person is active', $sup['active'] === true);
check('an unknown role is refused', throws(static fn () => People::create(['role' => 'admin', 'name' => 'Test']), 'bad_role'));
check('a name shorter than two characters is refused', throws(static fn () => People::create(['role' => 'supervisor', 'name' => 'a']), 'bad_name'));
check('a phone with letters is refused', throws(static fn () => People::create(['role' => 'supervisor', 'name' => 'Sara', 'phone' => '12ab']), 'bad_phone'));
check('an unknown area is refused', throws(static fn () => People::create(['role' => 'supervisor', 'name' => 'Sara', 'area' => 'riyadh']), 'bad_area'));
$off = People::update($sup['id'], ['active' => false]);
check('a supervisor is deactivated, not deleted', $off['active'] === false && People::find($sup['id'])['name'] === 'أحمد علي');
check('a non-boolean active flag is refused', throws(static fn () => People::update($sup['id'], ['active' => 'no']), 'bad_value'));
check('changing the role is ignored, so nothing is left to update', throws(static fn () => People::update($sup['id'], ['role' => 'admin']), 'nothing_to_update'));
check('listing only returns the requested role', array_reduce(People::list('supervisor'), static fn (bool $ok, array $p): bool => $ok && $p['role'] === 'supervisor', true));
check('an unknown role cannot be listed', throws(static fn () => People::list('admin'), 'bad_role'));
check('a malformed id is not found', throws(static fn () => People::find('../etc'), 'not_found'));

echo "General settings\n";
$saved = General::save(['company.name' => ' مجموعة أكسجين ', 'company.tax_number' => '300123456700003']);
check('general settings save and read back', $saved['company.name'] === 'مجموعة أكسجين' && General::all()['company.tax_number'] === '300123456700003');
check('a field left out keeps its value', General::save(['company.hours' => '9ص - 9م'])['company.name'] === 'مجموعة أكسجين');
check('an unknown setting key is refused', throws(static fn () => General::save(['bridge.secret' => 'x']), 'unknown_setting'));
check('the PIN cannot be written through general settings', throws(static fn () => General::save(['pin_hash' => 'x']), 'unknown_setting'));
check('markup in a plain field is refused', throws(static fn () => General::save(['company.name' => '<b>x</b>']), 'bad_value'));
check('a value over its length limit is refused', throws(static fn () => General::save(['company.tax_number' => str_repeat('1', 31)]), 'too_long'));
check('a non-text value is refused', throws(static fn () => General::save(['company.phone' => 5]), 'bad_value'));

echo "Bulk settings\n";
check('valid values pass the master check', Bulk::validate(['blogname' => 'متقن', 'posts_per_page' => '10']) === ['blogname' => 'متقن', 'posts_per_page' => 10]);
check('a field outside the list is refused', throws(static fn () => Bulk::validate(['admin_email' => 'a@b.c']), 'unknown_setting'));
check('an empty change is refused', throws(static fn () => Bulk::validate([]), 'nothing_to_change'));
check('a site name cannot be empty', throws(static fn () => Bulk::validate(['blogname' => '   ']), 'bad_value'));
check('a number outside its range is refused', throws(static fn () => Bulk::validate(['posts_per_page' => 500]), 'bad_value'));
check('markup in a text setting is refused', throws(static fn () => Bulk::validate(['blogdescription' => '<script>']), 'bad_value'));
check('a batch larger than the limit is refused', throws(static fn () => Bulk::run(['a', 'b', 'c', 'd', 'e', 'f'], ['blogname' => 'x'], true), 'bad_batch'));
check('an empty batch is refused', throws(static fn () => Bulk::run([], ['blogname' => 'x'], true), 'bad_batch'));
$preview = Bulk::run(['nasmat'], ['blogname' => 'اختبار'], true);
check('an unpaired face reports why, and does not stop the batch', $preview[0]['ok'] === false && $preview[0]['error'] === 'غير مربوط');
$applied = Bulk::run(['nasmat'], ['blogname' => 'اختبار'], false);
check('applying to an unpaired face changes nothing and reports it', $applied[0]['ok'] === false && $applied[0]['name'] === Sites::find('nasmat')['name']);
check('an outdated plugin gets a clear message', Bulk::explain('http_404:rest_no_route') === 'الإضافة قديمة، حدّثها على هذا الموقع');
check('a wrong key gets a clear message', Bulk::explain('http_401:oxy_bad_signature') === 'المفتاح لا يطابق، أعد الربط');

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
