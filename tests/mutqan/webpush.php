<?php
// Background notifications (Web Push) from this server alone: keys, the standard encryption, the VAPID
// signature, who an alert reaches, and that a gone device is forgotten.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('MQ_DATA=' . sys_get_temp_dir() . '/mutqan-wpush-' . getmypid() . '-' . bin2hex(random_bytes(4)));
require __DIR__ . '/wpshim.php';
Mutqan_Store::maybe_install();
$ok = true; $t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . "webpush: $n" . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$W = 'Mutqan_WebPush';
$t('this PHP can do it with OpenSSL alone', $W::supported());
$k = $W::keys();
$t('VAPID key: a 65-byte P-256 point, made once and kept', strlen($W::unb64u($k['public'])) === 65 && $W::keys()['public'] === $k['public']);

// the browser's side of a subscription
$ua = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$ud = openssl_pkey_get_details($ua);
$uaPub = "\x04" . str_pad($ud['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ud['ec']['y'], 32, "\0", STR_PAD_LEFT);
$auth = random_bytes(16);
$msg = '{"title":"🆕 طلب جديد","body":"صيانة وتكييف"}';
$body = $W::encrypt($msg, $uaPub, $auth);
// decrypt exactly as a browser does (RFC 8291)
$salt = substr($body, 0, 16); $rs = unpack('N', substr($body, 16, 4))[1]; $idl = ord($body[20]); $asPub = substr($body, 21, $idl); $ct = substr($body, 21 + $idl);
$shared = openssl_pkey_derive(openssl_pkey_get_public($W::point_pem($asPub)), $ua, 32);
$hk = function ($s, $i, $info, $l) { $p = hash_hmac('sha256', $i, $s, true); return substr(hash_hmac('sha256', $info . "\x01", $p, true), 0, $l); };
$ikm = $hk($auth, $shared, "WebPush: info\0" . $uaPub . $asPub, 32);
$pt = openssl_decrypt(substr($ct, 0, -16), 'aes-128-gcm', $hk($salt, $ikm, "Content-Encoding: aes128gcm\0", 16), OPENSSL_RAW_DATA, $hk($salt, $ikm, "Content-Encoding: nonce\0", 12), substr($ct, -16));
$t('header: salt, record size 4096, the server\'s one-time key', $rs === 4096 && $idl === 65 && $asPub[0] === "\x04");
$t('the device decrypts the notification exactly', $pt === $msg . "\x02", $pt);
$t('a different device cannot', (function () use ($W, $msg, $uaPub, $auth) { $b = $W::encrypt($msg, $uaPub, random_bytes(16)); return $b !== '' && $b !== $W::encrypt($msg, $uaPub, $auth); })());

$jwt = $W::jwt('https://fcm.googleapis.com', $k['pem']);
list($h, $c, $s) = explode('.', $jwt);
$raw = $W::unb64u($s);
$int = function ($v) { $v = ltrim($v, "\0"); if (ord($v[0]) & 0x80) { $v = "\0" . $v; } return "\x02" . chr(strlen($v)) . $v; };
$der = $int(substr($raw, 0, 32)) . $int(substr($raw, 32)); $der = "\x30" . chr(strlen($der)) . $der;
$claims = json_decode($W::unb64u($c), true);
$t('VAPID token: ES256, signed by our key, for the push service, expiring', strlen($raw) === 64 && openssl_verify($h . '.' . $c, $der, $W::point_pem($W::unb64u($k['public'])), OPENSSL_ALGO_SHA256) === 1 && $claims['aud'] === 'https://fcm.googleapis.com' && $claims['exp'] > time());

// subscriptions and targets
$sub = function ($ep) use ($W, $uaPub, $auth) { return ['endpoint' => 'https://fcm.googleapis.com/fcm/send/' . $ep, 'keys' => ['p256dh' => $W::b64u($uaPub), 'auth' => $W::b64u($auth)]]; };
$owner = ['id' => 'u-owner', 'role' => 'owner', 'kind' => 'user', 'powers' => ['read', 'write', 'manage']];
$tech = ['id' => 'u-tech', 'role' => 'technician', 'kind' => 'user', 'powers' => []];
$cust = ['id' => 'anon-cust0001', 'role' => null, 'kind' => 'anon', 'powers' => []];
$guest = ['id' => 'guest', 'role' => null, 'kind' => 'guest', 'powers' => []];
$code = function ($fn) { try { $fn(); return 200; } catch (Mutqan_Error $e) { return $e->status; } };
$t('the key is public', !empty($W::handle(['op' => 'key'], $guest)['key']));
$t('a stranger cannot subscribe', $code(function () use ($W, $guest, $sub) { $W::handle(['op' => 'subscribe', 'sub' => $sub('g')], $guest); }) === 401);
$t('a bad subscription is refused', $code(function () use ($W, $owner) { $W::handle(['op' => 'subscribe', 'sub' => ['endpoint' => 'http://x', 'keys' => []]], $owner); }) === 400);
$W::handle(['op' => 'subscribe', 'sub' => $sub('owner')], $owner);
$W::handle(['op' => 'subscribe', 'sub' => $sub('tech')], $tech);
$W::handle(['op' => 'subscribe', 'sub' => $sub('cust')], $cust);
$W::handle(['op' => 'subscribe', 'sub' => $sub('owner')], $owner);   // same device twice = one row
$ends = function ($rows) { $e = array_map(function ($r) { return substr($r['endpoint'], -5); }, $rows); sort($e); return $e; };
$t('an admin alert reaches the owner\'s devices only', $ends($W::targets(['admins' => true])) === ['owner'], $ends($W::targets(['admins' => true])));
$t('a role alert reaches that role', $ends($W::targets(['role' => 'technician'])) === ['/tech'], $ends($W::targets(['role' => 'technician'])));
$t('an anonymous customer gets the messages on his own order', $ends($W::targets(['user' => 'anon-cust0001'])) === ['/cust']);
$leak = null; try { $leak = Mutqan_Store::select('_push_subs', [], ['limit' => 5], $cust, false); } catch (Mutqan_Error $e) { $leak = []; }
$t('customers cannot read the subscriptions', $leak === [], $leak);

// delivery: the push service answers; a gone device is forgotten
$sent = [];
$GLOBALS['__post_hook'] = function ($url, $a) use (&$sent) { if (strpos($url, 'fcm.googleapis.com') === false) { return null; } $sent[] = ['url' => $url, 'h' => $a['headers']]; return ['code' => strpos($url, 'tech') !== false ? 410 : 201, 'body' => '', 'headers' => []]; };
$n = $W::deliver(['id' => 'x1', 'target' => ['admins' => true], 'title' => 'طلب', 'priority' => 'high']);
$t('an alert is delivered, encrypted, with VAPID and high urgency', $n === 1 && $sent[0]['h']['Content-Encoding'] === 'aes128gcm' && strpos($sent[0]['h']['Authorization'], 'vapid t=') === 0 && $sent[0]['h']['Urgency'] === 'high', $sent);
$W::deliver(['id' => 'x2', 'target' => ['role' => 'technician'], 'title' => 'x']);
$t('a device the push service says is gone is forgotten', $W::targets(['role' => 'technician']) === []);
$sent = [];
Mutqan_Rest::admin_alert('🆕 طلب جديد', 'x', '#/', 'new_order', 'high', null);
Mutqan_WebPush::flush();
$t('every server alert now also goes out in the background', count($sent) === 1, count($sent));
echo $ok ? "ALL PASSED\n" : "SOME FAILED\n";
exit($ok ? 0 : 1);
