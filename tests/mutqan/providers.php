<?php
// The free providers, and the chain that makes them one service.
//
// No free tier has no limit, so capacity comes from having several: when one runs out the next one
// answers and the customer never learns there was a limit. That only holds if every provider in the
// order is actually reachable from the code and actually shown in the admin page with the page where
// its free key is made — a provider nobody can set up is not a fallback.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/wpshim.php';
$ok = true;
$t = function ($n, $c, $x = null) use (&$ok) { echo ($c ? 'PASS ' : 'FAIL ') . 'providers: ' . $n . ($c ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; if (!$c) { $ok = false; } };
$d = Mutqan_AI::defaults();
$labels = Mutqan_AI::labels();
$src = file_get_contents(dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/mutqan/class-mutqan-ai.php');
$admin = file_get_contents(dirname(__DIR__, 2) . '/oxygen-ac-automation/includes/mutqan/class-mutqan-admin.php');

$t('the free chain is more than a couple deep', count($d['order']) >= 8, $d['order']);
$t('Claude is not in the free chain — it is the paid tier', !in_array('anthropic', $d['order'], true));

$eps = Mutqan_AI::endpoints();
$custom = ['custom', 'custom2'];
foreach ($d['order'] as $id) {
    $t("$id is a provider that exists", isset($d['providers'][$id]), array_keys($d['providers']));
    $t("$id is named for the owner", isset($labels[$id]));
    if (in_array($id, $custom, true)) {
        // The owner brings both the address and the model; until he does, it is not part of the chain.
        $t("$id has a place for its address", array_key_exists('url', $d['providers'][$id]));
        $t("$id is not usable without an address and a model", !Mutqan_AI::any_enabled(['providers' => [$id => ['enabled' => 1, 'key' => 'k', 'model' => '', 'url' => '']]]));
        $t("$id is usable once it has a public https address and a model", Mutqan_AI::any_enabled(['providers' => [$id => ['enabled' => 1, 'key' => 'k', 'model' => 'm', 'url' => 'https://api.example.com/v1/chat/completions']]]));
        $t("$id is reachable from call()", strpos($src, "\$id === 'custom' || \$id === 'custom2'") !== false);
        $t("$id says how to set it up", preg_match("/'$id' => '[^h]/u", $admin) === 1);
        $t("$id ships with no key in the source", ($d['providers'][$id]['key'] ?? 'x') === '');
        continue;
    }
    $t("$id has a model to call", !empty($d['providers'][$id]['model'] ?? ''));
    $t("$id is off until a key is pasted", (int) ($d['providers'][$id]['enabled'] ?? 1) === 0);
    $t("$id ships with no key in the source", ($d['providers'][$id]['key'] ?? 'x') === '');
    $t("$id is reachable from call()", isset($eps[$id]) || strpos($src, "case '$id':") !== false);
    $t("$id has an https endpoint or its own caller", isset($eps[$id]) ? strpos($eps[$id], 'https://') === 0 : true);
    $t("$id tells the owner where its free key is made", preg_match("/'$id' => 'https:/", $admin) === 1);
    $t("$id says what its free tier is", preg_match("/'$id' => '[^h]/u", $admin) === 1);
}
foreach (array_keys($labels) as $id) {
    $t("$id is in the order or is the paid one", in_array($id, $d['order'], true) || $id === 'anthropic');
}
$t('every provider in the chain gets a turn before the wait is too long (customers 5, team 12)', strpos($src, '$tried >= $max') !== false && strpos($src, '? 12 : 5') !== false);
$t('each one is tried through the same key check', strpos($src, 'self::usable($id, $c)') !== false);

echo $ok ? "OK providers\n" : "FAILED providers\n";
exit($ok ? 0 : 1);
