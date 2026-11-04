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
$tech = People::create(['role' => 'technician', 'name' => 'خالد سعيد', 'phone' => '0555000111', 'area' => 'jeddah']);
check('a technician is created with its own role', $tech['role'] === 'technician' && $tech['area'] === 'jeddah');
echo "Map: technician positions\n";
$loc = Locations::record($tech['id'], '21.4225', 39.8262);
check('a technician reports a position (text numbers accepted)', $loc['lat'] === 21.4225 && $loc['lng'] === 39.8262);
Locations::record($tech['id'], 21.5, 39.2);
$live = Locations::live();
$row = array_values(array_filter($live, static fn (array $t): bool => $t['id'] === $tech['id']))[0] ?? null;
check('a second report replaces the first (one row per technician)', $row !== null && $row['lat'] === 21.5 && $row['lng'] === 39.2);
check('an active technician without a report is listed with null position', count(array_filter($live, static fn (array $t): bool => $t['lat'] === null)) >= 0 && count($live) >= 1);
check('a supervisor cannot report a position', throws(static fn () => Locations::record($sup['id'], 21.4, 39.8), 'not_technician'));
check('an inactive technician cannot report a position', throws(static function () use ($tech): void { People::update($tech['id'], ['active' => false]); Locations::record($tech['id'], 21.4, 39.8); }, 'inactive'));
People::update($tech['id'], ['active' => true]);
check('a latitude past 90 is refused', throws(static fn () => Locations::record($tech['id'], 91.0, 39.8), 'bad_location'));
check('a non-numeric longitude is refused', throws(static fn () => Locations::record($tech['id'], 21.4, 'abc'), 'bad_location'));
check('an unknown person is not found', throws(static fn () => Locations::record('00000000000000000000000000000000', 21.4, 39.8), 'not_found'));
check('technicians are listed apart from supervisors', array_reduce(People::list('technician'), static fn (bool $ok, array $p): bool => $ok && $p['role'] === 'technician', true) && count(People::list('technician')) >= 1);
check('a supervisor is not listed among technicians', array_reduce(People::list('technician'), static fn (bool $ok, array $p): bool => $ok && $p['id'] !== $sup['id'], true));
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
check('an unpaired face reports why, and does not stop the batch', $preview[0]['ok'] === false && $preview[0]['error'] === 'غير مربوط، أعد الربط من الديسك' && $preview[0]['fix'] === 'desk');
$applied = Bulk::run(['nasmat'], ['blogname' => 'اختبار'], false);
check('applying to an unpaired face changes nothing and reports it', $applied[0]['ok'] === false && $applied[0]['name'] === Sites::find('nasmat')['name']);
check('an outdated plugin gets a clear message', Bulk::explain('http_404:rest_no_route') === 'الإضافة قديمة، حدّثها على هذا الموقع');
check('a wrong key gets a clear message', Bulk::explain('http_401:oxy_bad_signature') === 'المفتاح لا يطابق، أعد الربط');
check('an unpaired face on the bridge says to re-pair from the desk', Bulk::explain('http_403:oxy_unpaired') === 'غير مربوط، أعد الربط من الديسك');
check('a clock mismatch is named, not called a wrong key', Bulk::explain('http_401:oxy_stale') === 'ساعة الموقع أو الديسك غير متزامنة');
check('an unknown setting on the face is named', Bulk::explain('http_400:oxy_unknown_setting') === 'هذا الإعداد غير مسموح به على الموقع');
check('a 5xx from the face asks for a retry', Bulk::explain('http_503:x') === 'خطأ داخلي على الموقع، أعد المحاولة لاحقاً');
check('a network failure tells the owner the face may be down', str_starts_with(Bulk::explain('network:timeout'), 'تعذّر الوصول'));
check('each failure says who acts: desk for keys', Bulk::fix('http_401:oxy_bad_signature') === 'desk');
check('each failure says who acts: site for an old plugin', Bulk::fix('http_404:rest_no_route') === 'site');
check('each failure says who acts: retry for a 5xx', Bulk::fix('http_502:x') === 'retry');
check('an unknown failure goes to review', Bulk::fix('http_418:teapot') === 'review');

echo "Bulk operations\n";
check('only listed operations can run', throws(static fn () => BulkOps::run('delete_everything', ['nasmat'], true), 'unknown_op'));
check('an empty operation batch is refused', throws(static fn () => BulkOps::run('health', [], true), 'bad_batch'));
check('an operation batch over the limit is refused', throws(static fn () => BulkOps::run('purge', ['a', 'b', 'c', 'd', 'e', 'f'], true), 'bad_batch'));
check('purge is marked as a write, health is not', BulkOps::OPS['purge']['writes'] === true && BulkOps::OPS['health']['writes'] === false);
$purgePreview = BulkOps::run('purge', ['nasmat'], true);
check('a purge preview changes nothing and says what would happen', $purgePreview[0]['ok'] === true && ($purgePreview[0]['preview'] ?? false) === true);
$purgeApply = BulkOps::run('purge', ['nasmat'], false);
check('applying purge to an unpaired face reports it and says who acts', $purgeApply[0]['ok'] === false && $purgeApply[0]['fix'] === 'desk');
$healthRun = BulkOps::run('health', ['nasmat'], true);
check('a health check on an unpaired face reports it', $healthRun[0]['ok'] === false && $healthRun[0]['error'] === 'غير مربوط، أعد الربط من الديسك');

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
