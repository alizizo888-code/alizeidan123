<?php
// Nothing depends on the owner's computer: the article publisher borrows a one-hour Blogger key with GitHub's identity
// token (no secrets), and the VPS gateway is enrolled over the API with a fresh key each time.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-srvauto-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "server_auto: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$B = 'Mutqan_Blogger';
$u = function ($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); };
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$det = openssl_pkey_get_details($key);
$jwks = ['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'n' => $u($det['rsa']['n']), 'e' => $u($det['rsa']['e'])]]];
$sign = function ($claims, $kid = 'k1') use ($key, $u) {
    $h = $u(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid])); $c = $u(json_encode($claims));
    openssl_sign("$h.$c", $sig, $key, OPENSSL_ALGO_SHA256); return "$h.$c." . $u($sig);
};
$good = ['iss' => 'https://token.actions.githubusercontent.com', 'aud' => 'oxygen11-blogger', 'repository' => 'alizizo888-code/mutaqin-oxygen-app',
    'workflow_ref' => 'alizizo888-code/mutaqin-oxygen-app/.github/workflows/blogger-api.yml@refs/heads/mutqan', 'exp' => time() + 300, 'nbf' => time() - 5];
$err = function ($fn) { try { $fn(); return ''; } catch (Mutqan_Error $e) { return $e->slug; } };
$t('the JWK → PEM conversion gives a key openssl accepts', (bool) openssl_pkey_get_public($B::jwk_pem($det['rsa']['n'], $det['rsa']['e'])));
$c = $B::claims($sign($good), $jwks);
$t('a genuine GitHub token from the company repo is accepted', $c['repository'] === 'alizizo888-code/mutaqin-oxygen-app');
$t('another repository is refused', $err(function () use ($B, $sign, $good, $jwks) { $B::claims($sign(['repository' => 'someone/else'] + $good), $jwks); }) === 'forbidden');
$t('another workflow of the repo is refused', $err(function () use ($B, $sign, $good, $jwks) { $B::claims($sign(['workflow_ref' => 'alizizo888-code/mutaqin-oxygen-app/.github/workflows/evil.yml@x'] + $good), $jwks); }) === 'forbidden');
$t('another audience is refused', $err(function () use ($B, $sign, $good, $jwks) { $B::claims($sign(['aud' => 'x'] + $good), $jwks); }) === 'forbidden');
$t('an expired token is refused', $err(function () use ($B, $sign, $good, $jwks) { $B::claims($sign(['exp' => time() - 10] + $good), $jwks); }) === 'forbidden');
$bad = $sign($good); $p = explode('.', $bad); $p[1] = $u(json_encode(['repository' => 'alizizo888-code/mutaqin-oxygen-app'] + $good + ['x' => 1]));
$t('a tampered token fails the signature', $err(function () use ($B, $p, $jwks) { $B::claims(implode('.', $p), $jwks); }) === 'forbidden');
$t('garbage is refused', $err(function () use ($B, $jwks) { $B::claims('nope', $jwks); }) === 'forbidden');

// VPS enrollment
$a = Mutqan_Coach::handle(['op' => 'vps_enroll']);
$b = Mutqan_Coach::handle(['op' => 'vps_enroll']);
$t('enrolling the VPS twice keeps one device', $a['device'] === $b['device'] && count(array_filter(Mutqan_WA_Fleet::devices(), function ($d) { return $d['name'] === 'سيرفر VPS (تلقائي)'; })) === 1);
preg_match('/WEBHOOK_SECRET=(\w+)/', $a['environment'], $s1); preg_match('/WEBHOOK_SECRET=(\w+)/', $b['environment'], $s2);
$t('…with a fresh key each time (an old key stops working)', $s1[1] !== $s2[1] && Mutqan_WA_Fleet::signer('x', hash_hmac('sha256', 'x', $s1[1])) === null && Mutqan_WA_Fleet::signer('x', hash_hmac('sha256', 'x', $s2[1])) === $b['device']);
$t('the environment carries everything the Docker service needs', strpos($b['environment'], 'GATEWAY_TOKEN=') !== false && strpos($b['environment'], 'DEVICE_ID=' . $b['device']) !== false && strpos($b['environment'], '/wa-hook') !== false);
$y = file_get_contents(__DIR__ . '/../../wa-gateway/docker-compose.yml');
$t('the compose file restarts itself, keeps sessions, needs no port', strpos($y, 'restart: always') !== false && strpos($y, 'wa_data:/data') !== false && strpos($y, 'ports:') === false);
exit($ok ? 0 : 1);
