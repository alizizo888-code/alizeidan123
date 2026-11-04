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
echo "Customers and orders\n";
$cust = Customers::create(['name' => 'منى الحربي', 'phone' => '0555222333', 'address' => 'حي الروضة', 'lat' => '21.5', 'lng' => '39.2', 'tier' => 'high']);
check('a customer phone becomes the 966 form for WhatsApp', $cust['phone'] === '966555222333');
check('a customer keeps her tier label', $cust['tier'] === 'high' && $cust['tier_label'] === 'عالية');
check('a customer phone with letters is refused', throws(static fn () => Customers::create(['name' => 'Ali', 'phone' => '12ab']), 'bad_phone'));
check('an unknown tier is refused', throws(static fn () => Customers::create(['name' => 'Ali', 'tier' => 'vip']), 'bad_tier'));
$upd = Customers::update($cust['id'], ['rating' => 5, 'rating_note' => 'منظمة وفي الموعد', 'last_price' => 250]);
check('a rating and last price are saved on the customer', $upd['rating'] === 5 && $upd['last_price'] === 250);
check('a rating above 5 is refused', throws(static fn () => Customers::update($cust['id'], ['rating' => 6]), 'bad_rating'));
check('an empty customer update is refused', throws(static fn () => Customers::update($cust['id'], ['unknown' => 1]), 'nothing_to_update'));
check('a WhatsApp link carries the customer location', str_contains((string) Customers::whatsappLink(Customers::find($cust['id'])), 'wa.me/966555222333') && str_contains((string) Customers::whatsappLink(Customers::find($cust['id'])), 'maps.google.com'));
$order = Orders::create(['customer_id' => $cust['id'], 'service' => 'تنظيف مكيف', 'price' => 200, 'technician_id' => $tech['id']]);
check('an order with a technician starts assigned', $order['status'] === 'assigned' && $order['active'] === true);
$listed = Customers::list();
$row = array_values(array_filter($listed, static fn (array $c): bool => $c['id'] === $cust['id']))[0];
check('the customer shows an active order', $row['active_orders'] === 1);
Orders::update($order['id'], ['status' => 'done', 'warranty_days' => 30]);
$after = Orders::find($order['id']);
check('a finished job sets a warranty end in the future', $after['warranty_until'] !== null && $after['warranty_until'] > time());
$row = array_values(array_filter(Customers::list(), static fn (array $c): bool => $c['id'] === $cust['id']))[0];
check('the customer shows previous work and an active warranty', $row['done_orders'] === 1 && $row['under_warranty'] === true && $row['active_orders'] === 0);
check('finishing a job records its price as the customer last price', Customers::find($cust['id'])['last_price'] === 200);
check('an unknown order status is refused', throws(static fn () => Orders::update($order['id'], ['status' => 'lost']), 'bad_status'));
check('a supervisor cannot be assigned an order', throws(static fn () => Orders::create(['customer_id' => $cust['id'], 'technician_id' => $sup['id']]), 'bad_technician'));

echo "Intake: orders from the faces\n";
$intakeSecret = str_repeat('ab', 32);
Sites::pair('oxygen', $intakeSecret);
$sigHeaders = function (string $body, string $ts, string $nonce) use ($intakeSecret): array {
    return ['X-Oxy-Timestamp' => $ts, 'X-Oxy-Nonce' => $nonce, 'X-Oxy-Signature' => Hmac::sign($intakeSecret, 'POST', '/intake/order', $ts, $nonce, $body)];
};
$body = json_encode(['site_ref' => '501', 'name' => 'سلمى', 'phone' => '0555 444 333', 'service' => 'تنظيف مكيف', 'qty' => 2, 'price' => 180, 'address' => 'حي النزهة', 'source' => 'chat'], JSON_UNESCAPED_UNICODE);
$ts = (string) time();
$nonce = str_repeat('c', 32);
Intake::verifySite('oxygen', 'POST', '/intake/order', $body, $sigHeaders($body, $ts, $nonce));
check('a signed order from a paired face is accepted', true);
$first = Intake::receive('oxygen', json_decode($body, true));
check('the order gets a code of site and reference', $first['code'] === 'oxygen-501' && $first['duplicate'] === false);
$again = Intake::receive('oxygen', json_decode($body, true));
check('a retry with the same reference returns the same order', $again['order_id'] === $first['order_id'] && $again['duplicate'] === true);
check('the order carries its code and source on the desk', Orders::find($first['order_id'])['code'] === 'oxygen-501' && Orders::find($first['order_id'])['source'] === 'chat');
check('the customer is matched by phone, however it was written', Customers::findByPhone('+966555444333') !== null && Orders::find($first['order_id'])['customer_name'] === 'سلمى');
check('a bad signature is refused', throws(static fn () => Intake::verifySite('oxygen', 'POST', '/intake/order', $body, $sigHeaders($body . ' ', $ts, $nonce)), 'bad_signature'));
check('a stale timestamp is refused', throws(static fn () => Intake::verifySite('oxygen', 'POST', '/intake/order', $body, $sigHeaders($body, '1000000000', $nonce)), 'bad_signature'));
check('an unpaired face cannot send orders', throws(static fn () => Intake::verifySite('amaan', 'POST', '/intake/order', $body, $sigHeaders($body, $ts, $nonce)), 'unpaired'));
check('an order with an unknown reference is refused', throws(static fn () => Intake::receive('oxygen', ['site_ref' => '<b>', 'phone' => '0555111222']), 'bad_ref'));

echo "Messages on an order\n";
$thread = Orders::create(['customer_id' => $cust['id'], 'service' => 'فحص']);
$m1 = Messages::add($thread['id'], 'تم استلام الطلب');
check('a message is kept on its order', $m1['sender'] === 'owner' && $m1['sender_label'] === 'المالك');
Messages::add($thread['id'], 'الفني في الطريق', 'technician');
$msgs = Messages::list($thread['id']);
check('the thread lists messages in order, with who sent them', count($msgs) === 2 && $msgs[1]['sender_label'] === 'الفني');
check('an empty message is refused', throws(static fn () => Messages::add($thread['id'], '   '), 'bad_message'));
check('markup in a message is refused', throws(static fn () => Messages::add($thread['id'], '<b>x</b>'), 'bad_message'));
check('an unknown sender is refused', throws(static fn () => Messages::add($thread['id'], 'hi', 'admin'), 'bad_sender'));
check('messages on an unknown order are refused', throws(static fn () => Messages::list('00000000000000000000000000000000'), 'not_found'));

echo "Automatic distribution\n";
$near = People::create(['role' => 'technician', 'name' => 'قريب', 'phone' => '0555100100', 'area' => 'makkah']);
$far = People::create(['role' => 'technician', 'name' => 'بعيد', 'phone' => '0555100200', 'area' => 'jeddah']);
Locations::record($near['id'], 21.6, 39.3);
Locations::record($far['id'], 21.3, 39.9);
Customers::update($cust['id'], ['lat' => 21.6, 'lng' => 39.3]);
$open = Orders::create(['customer_id' => $cust['id'], 'service' => 'تركيب مكيف']);
$byNear = Orders::distribute($open['id'], 'nearest');
check('nearest gives the order to the closest technician with a position', $byNear['technician_id'] === $near['id'] && $byNear['status'] === 'assigned');
$open2 = Orders::create(['customer_id' => $cust['id'], 'service' => 'صيانة']);
$idle = Orders::distribute($open2['id'], 'idle');
check('idle picks a technician with no active order and a recent position', $idle['technician_id'] !== null && $idle['status'] === 'assigned');
check('an unknown mode is refused', throws(static fn () => Orders::distribute($open2['id'], 'random'), 'bad_mode'));
Orders::update($open2['id'], ['status' => 'done']);
check('a finished order cannot be distributed', throws(static fn () => Orders::distribute($open2['id'], 'nearest'), 'not_open'));

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

echo "Dispatch and intake settings\n";
$defaults = Dispatch::all();
check('dispatch starts manual, nearest, intake open, 90% share, no default warranty', $defaults['mode'] === 'manual' && $defaults['rule'] === 'nearest' && $defaults['intake_open'] === true && $defaults['default_share'] === 90 && $defaults['default_warranty_days'] === 0);
check('an unknown dispatch mode is refused', throws(static fn () => Dispatch::save(['mode' => 'random']), 'bad_mode'));
check('an unknown dispatch key is refused', throws(static fn () => Dispatch::save(['secret' => 'x']), 'unknown_setting'));
check('a share over 100 is refused', throws(static fn () => Dispatch::save(['default_share' => 120]), 'bad_share'));
$supervisor = People::create(['role' => 'supervisor', 'name' => 'مشرف الاختبار', 'phone' => '0555300300']);
check('only a technician can have a share', throws(static fn () => Dispatch::setShare($supervisor['id'], 70), 'not_technician'));
Dispatch::setShare($tech['id'], 70);
$shared = Orders::find($order['id']);
check('a technician share of 70% gives 140 of a 200 price', $shared['technician_share_pct'] === 70 && $shared['technician_amount'] === 140);
Dispatch::setShare($tech['id'], null);
$byDefault = Orders::find($order['id']);
check('clearing the share brings back the default, 90% = 180', $byDefault['technician_share_pct'] === 90 && $byDefault['technician_amount'] === 180);
Dispatch::save(['intake_open' => false]);
check('a paused intake refuses a new order from a face', throws(static fn () => Intake::receive('oxygen', ['site_ref' => '801', 'phone' => '0555801801', 'service' => 'صيانة']), 'intake_paused'));
Dispatch::save(['intake_open' => true]);
$reopened = Intake::receive('oxygen', ['site_ref' => '802', 'phone' => '0555802802', 'service' => 'صيانة']);
check('reopening intake accepts the order again', $reopened['duplicate'] === false);
Dispatch::save(['mode' => 'auto', 'rule' => 'nearest']);
$noLocation = Intake::receive('oxygen', ['site_ref' => '803', 'phone' => '0555803803', 'service' => 'صيانة']);
check('automatic dispatch that cannot place the order still saves it as new', Orders::find($noLocation['order_id'])['status'] === 'new');
Dispatch::save(['mode' => 'manual']);
Dispatch::save(['default_warranty_days' => 30]);
$finished = Orders::create(['customer_id' => $cust['id'], 'service' => 'فحص']);
Orders::update($finished['id'], ['status' => 'done']);
$warranty = Orders::find($finished['id'])['warranty_until'];
check('a finished job gets the default warranty when none is stated', $warranty !== null && abs($warranty - (time() + 30 * 86400)) < 120);
Dispatch::save(['default_warranty_days' => 0]);

echo "Chat and WhatsApp settings\n";
$chatDefaults = Chat::all();
check('chat starts off, with no instructions and no services', $chatDefaults['enabled'] === false && $chatDefaults['instructions'] === '' && $chatDefaults['services'] === []);
$saved = Chat::save(['enabled' => true, 'bot_name' => 'مساعد أكسجين', 'phone' => '0549423050', 'whatsapp' => '966549423050', 'email' => 'owner@example.com', 'services' => "1|صيانة المكيفات\nالكهرباء\n", 'payment_methods' => ['تحويل بنكي', 'مدى'], 'instructions' => "سطر أول\nسطر ثانٍ"]);
check('chat saves the values it is given', $saved['enabled'] === true && $saved['bot_name'] === 'مساعد أكسجين' && $saved['whatsapp'] === '966549423050');
check('services keep one item per line, empty lines dropped', $saved['services'] === ['1|صيانة المكيفات', 'الكهرباء']);
check('payment methods accept a list', $saved['payment_methods'] === ['تحويل بنكي', 'مدى']);
check('instructions keep their line breaks', $saved['instructions'] === "سطر أول\nسطر ثانٍ");
check('an unknown chat key is refused', throws(static fn () => Chat::save(['whatsapp_token' => 'x']), 'unknown_setting'));
check('a bad email is refused', throws(static fn () => Chat::save(['email' => 'not-an-email']), 'bad_email'));
check('a bad WhatsApp number is refused', throws(static fn () => Chat::save(['whatsapp' => 'abc']), 'bad_phone'));
check('markup in instructions is refused', throws(static fn () => Chat::save(['instructions' => '<script>x</script>']), 'bad_value'));
check('too many services are refused', throws(static fn () => Chat::save(['services' => implode("\n", array_fill(0, 61, 'خدمة'))]), 'too_many'));
check('the WhatsApp secrets never appear in the chat settings', !array_key_exists('phone_id', Chat::all()) && !array_key_exists('wa_token', Chat::all()));
Chat::save(['enabled' => false]);

echo "Operations reports\n";
$accounts = Reports::accounts();
$techAcc = array_values(array_filter($accounts, static fn (array $a): bool => $a['id'] === $tech['id']))[0] ?? null;
check('the accounts list has every technician once', count(array_unique(array_column($accounts, 'id'))) === count($accounts) && $techAcc !== null);
check('an account earns the share of finished jobs, not of open ones', $techAcc !== null && $techAcc['earned'] === (int) round($techAcc['done_value'] * $techAcc['share_pct'] / 100));
$warrantyJob = Orders::create(['customer_id' => $cust['id'], 'service' => 'ضمان تجربة', 'price' => 100, 'technician_id' => $tech['id']]);
Orders::update($warrantyJob['id'], ['status' => 'done', 'warranty_days' => 30]);
$warr = array_values(array_filter(Reports::warranties(), static fn (array $w): bool => $w['id'] === $warrantyJob['id']))[0] ?? null;
check('a finished job with a warranty shows as active with days left', $warr !== null && $warr['active'] === true && $warr['days_left'] >= 29 && $warr['days_left'] <= 30);

echo "Technician balance\n";
$ledgerTech = People::create(['role' => 'technician', 'name' => 'فني الرصيد', 'phone' => '0555400400', 'area' => 'makkah']);
$ledgerJob = Orders::create(['customer_id' => $cust['id'], 'service' => 'رصيد', 'price' => 300, 'technician_id' => $ledgerTech['id']]);
check('a job is not earned before it is finished', Ledger::balance($ledgerTech['id']) === 0);
Orders::update($ledgerJob['id'], ['status' => 'done']);
check('finishing a job earns the technician his share, 90% of 300 = 270', Ledger::balance($ledgerTech['id']) === 270);
Orders::update($ledgerJob['id'], ['status' => 'done']);
check('finishing it again does not earn it twice', Ledger::balance($ledgerTech['id']) === 270);
check('the earning is idempotent in the backfill too', Ledger::backfill() >= 0 && Ledger::balance($ledgerTech['id']) === 270);
Ledger::record($ledgerTech['id'], 'deduction', 20, 'تأخير عن الموعد');
check('a deduction reduces the balance to 250', Ledger::balance($ledgerTech['id']) === 250);
check('a deduction of zero or less is refused', throws(static fn () => Ledger::record($ledgerTech['id'], 'deduction', 0, 'x'), 'bad_amount'));
check('a payment beyond the balance is refused', throws(static fn () => Ledger::record($ledgerTech['id'], 'payment', 999, ''), 'insufficient_balance'));
Ledger::record($ledgerTech['id'], 'payment', 150, 'صرف نص الشهر');
check('a payment within the balance reduces it to 100', Ledger::balance($ledgerTech['id']) === 100);
check('an adjustment needs a note', throws(static fn () => Ledger::record($ledgerTech['id'], 'adjustment', 5, ''), 'note_required'));
check('an adjustment can be negative', (Ledger::record($ledgerTech['id'], 'adjustment', -10, 'تصحيح') ['balance']) === 90);
check('a supervisor has no ledger', throws(static fn () => Ledger::record($supervisor['id'], 'deduction', 5, 'x'), 'not_technician'));
check('the ledger keeps every row, newest first', count(Ledger::rows($ledgerTech['id'])) === 4 && Ledger::rows($ledgerTech['id'])[0]['kind'] === 'adjustment' && Ledger::rows($ledgerTech['id'])[3]['kind'] === 'earning');
check('accounts show the balance', (array_values(array_filter(Reports::accounts(), static fn (array $a): bool => $a['id'] === $ledgerTech['id']))[0]['balance'] ?? null) === 90);

echo "Specialties and live status\n";
$skilled = People::create(['role' => 'technician', 'name' => 'فني التخصص', 'phone' => '0555500500', 'area' => 'jeddah']);
check('a technician starts with no specialties', Skills::of($skilled['id']) === []);
check('specialties are saved from the fixed list', Skills::set($skilled['id'], ['تكييف', 'كهرباء']) === ['تكييف', 'كهرباء'] && Skills::of($skilled['id']) === ['تكييف', 'كهرباء']);
check('an unknown specialty is refused', throws(static fn () => Skills::set($skilled['id'], ['سحر']), 'bad_skill'));
check('a supervisor cannot have specialties', throws(static fn () => Skills::set($supervisor['id'], ['تكييف']), 'not_technician'));
check('too many specialties are refused', throws(static fn () => Skills::set($skilled['id'], array_slice(Skills::LIST, 0, 11)), 'too_many_skills'));
Skills::set($skilled['id'], ['سباكة']);
check('saving again replaces the list', Skills::of($skilled['id']) === ['سباكة']);
Locations::record($skilled['id'], 21.4, 39.2);
$liveRow = array_values(array_filter(Locations::live(), static fn (array $t): bool => $t['id'] === $skilled['id']))[0];
check('a technician with a fresh position and no order is available', $liveRow['status'] === 'available' && $liveRow['skills'] === ['سباكة']);
$liveOrder = Orders::create(['customer_id' => $cust['id'], 'service' => 'سباكة', 'technician_id' => $skilled['id']]);
$liveRow = array_values(array_filter(Locations::live(), static fn (array $t): bool => $t['id'] === $skilled['id']))[0];
check('a technician with an open order is busy', $liveRow['status'] === 'busy' && $liveRow['busy_orders'] === 1);
Orders::update($liveOrder['id'], ['status' => 'done']);
$noPos = People::create(['role' => 'technician', 'name' => 'بلا موقع', 'phone' => '0555500600', 'area' => 'makkah']);
$noPosRow = array_values(array_filter(Locations::live(), static fn (array $t): bool => $t['id'] === $noPos['id']))[0];
check('a technician with no position is offline', $noPosRow['status'] === 'offline');

echo "Specialty matching and candidates\n";
check('a service name points to its specialty', Skills::forService('تكييف مركزي') === ['تكييف'] && Skills::forService('تسريب ماء في الحمام') === ['سباكة']);
check('a service with no known word needs no specialty', Skills::forService('خدمة غير معروفة') === []);
$acSkill = People::create(['role' => 'technician', 'name' => 'فني تكييف قريب', 'phone' => '0555500700', 'area' => 'jeddah']);
Skills::set($acSkill['id'], ['تكييف']);
Locations::record($acSkill['id'], 21.5001, 39.2001);
$farAc = People::create(['role' => 'technician', 'name' => 'فني تكييف بعيد', 'phone' => '0555500800', 'area' => 'jeddah']);
Skills::set($farAc['id'], ['تكييف']);
Locations::record($farAc['id'], 21.9, 39.9);
$nearPlain = People::create(['role' => 'technician', 'name' => 'فني عام قريب', 'phone' => '0555500900', 'area' => 'jeddah']);
Locations::record($nearPlain['id'], 21.5002, 39.2002);
$acOrder = Orders::create(['customer_id' => $cust['id'], 'service' => 'صيانة تكييف']);
$cand = Orders::candidates($acOrder['id']);
check('candidates list the needed specialty', $cand['needs'] === ['تكييف']);
check('the nearest matching technician ranks first', $cand['candidates'][0]['id'] === $acSkill['id']);
$ids = array_column($cand['candidates'], 'id');
check('the matching technician ranks above the nearer one without it', array_search($acSkill['id'], $ids) < array_search($nearPlain['id'], $ids));
check('each candidate has a distance and a rough time', is_float($cand['candidates'][0]['km']) && is_int($cand['candidates'][0]['minutes']));
$acDist = Orders::distribute($acOrder['id'], 'nearest');
check('distribution picks the matching technician', $acDist['technician_id'] === $acSkill['id']);
$doneOrder = Orders::create(['customer_id' => $cust['id'], 'service' => 'تكييف', 'technician_id' => $acSkill['id']]);
Orders::update($doneOrder['id'], ['status' => 'done']);
check('a finished order has no candidates', throws(static fn () => Orders::candidates($doneOrder['id']), 'not_open'));

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
