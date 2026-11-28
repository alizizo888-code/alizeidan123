<?php
/**
 * Site watch, run by a Hostinger cron job every 15 minutes (GitHub's scheduler fired 3 times in a whole day).
 *
 *   php …/wp-content/plugins/oxygen-ac-automation-new/cron/uptime.php
 *
 * For each of the 22 WordPress sites and the desk: the home page, the app (/app/) and the server
 * (POST /wp-json/mutqan/v1/team {op: config}), all at once, with the time each took.
 * One JSON line per site goes to wp-content/uploads/mq-uptime/<date>.jsonl (Riyadh day);
 * the folder refuses web access. Read it with Hostinger's file reader, or network/uptime.py.
 * Command line only: opened from a browser it is a 404.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$HANG = 15;
$root = dirname(__DIR__, 3);   // wp-content
$dir = $root . '/uploads/mq-uptime';
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
if (!file_exists($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n"); }

$names = ['jawda', 'kafaa', 'amaan', 'wahat', 'nasmat', 'tamayuz', 'safwa', 'baraa', 'riyada', 'nukhba', 'misriya', 'mutamad', 'ibtikar', 'homeservice', 'khabeer', 'mutamakkin', 'raad', 'aqsa', 'muhandis', 'motakhases'];
$sites = [['oxygen', 'oxygen11.com'], ['mutqan', 'mutqan.oxygen11.com']];
foreach ($names as $n) { $sites[] = [$n, $n . '-maintenance-general-contracting.oxygen11.com']; }

$mh = curl_multi_init();
$jobs = [];
$add = function ($id, $kind, $url, $post = null) use ($mh, &$jobs, $HANG) {
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => $HANG, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (uptime-watch; server cron)', CURLOPT_HTTPHEADER => array_merge(['Cache-Control: no-cache'], $post ? ['Content-Type: application/json'] : [])]);
    if ($post) { curl_setopt($c, CURLOPT_POST, true); curl_setopt($c, CURLOPT_POSTFIELDS, $post); }
    curl_multi_add_handle($mh, $c);
    $jobs[] = [$id, $kind, $c];
};
$t = time();
foreach ($sites as [$id, $h]) {
    $add($id, 'home', "https://$h/?nocache=$t");
    $add($id, 'app', "https://$h/app/?nocache=$t");
    $add($id, 'api', "https://$h/wp-json/mutqan/v1/team", '{"op":"config"}');
}
$add('desk', 'home', 'https://desk.oxygen11.com/ops');
do { $st = curl_multi_exec($mh, $running); if ($running) { curl_multi_select($mh, 1.0); } } while ($running && $st === CURLM_OK);

$tz = new DateTimeZone('Asia/Riyadh');
$now = new DateTime('now', $tz);
$at = $now->format('Y-m-d\TH:i');
$rows = [];
foreach ($jobs as [$id, $kind, $c]) {
    $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $sec = round((float) curl_getinfo($c, CURLINFO_TOTAL_TIME), 2);
    $len = strlen((string) curl_multi_getcontent($c));
    $err = curl_errno($c) ? curl_error($c) : '';
    $rows[$id] = $rows[$id] ?? ['at' => $at, 'id' => $id, 'from' => 'server'];
    $rows[$id][$kind] = array_filter(['code' => $code, 's' => $sec, 'ok' => $code > 0 && $code < 400 && $len > 50, 'err' => $err ? substr($err, 0, 80) : null], function ($v) { return $v !== null; });
    curl_multi_remove_handle($mh, $c); curl_close($c);
}
curl_multi_close($mh);
$out = '';
foreach ($rows as $r) { $out .= json_encode($r, JSON_UNESCAPED_UNICODE) . "\n"; }
file_put_contents($dir . '/' . $now->format('Y-m-d') . '.jsonl', $out, FILE_APPEND | LOCK_EX);
$bad = [];
foreach ($rows as $r) { foreach (['home', 'app', 'api'] as $k) { if (isset($r[$k]) && (empty($r[$k]['ok']) || $r[$k]['s'] >= 3)) { $bad[] = "$r[id]/$k " . ($r[$k]['code'] ?? 0) . ' ' . $r[$k]['s'] . 's'; } } }
echo $at . ' — ' . count($rows) . ' sites; ' . ($bad ? 'problems: ' . implode(', ', $bad) : 'all fine') . "\n";
