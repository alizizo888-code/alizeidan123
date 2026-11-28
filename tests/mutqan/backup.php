<?php
// «النسخ الاحتياطية»: a copy holds every row, the owner restores it (safety copy first), 4 weekly copies are kept,
// a cut-off file is refused, and only the owner may touch any of it.
require __DIR__ . '/wpshim.php';
$ok = 0; $bad = 0;
$t = function ($n, $c, $x = null) use (&$ok, &$bad) { if ($c) { $ok++; echo "PASS backup: $n\n"; } else { $bad++; echo "FAIL backup: $n  -> " . json_encode($x, JSON_UNESCAPED_UNICODE) . "\n"; } };
$own = ['id' => 'u-own', 'role' => 'owner', 'name' => 'علي', 'kind' => 'user', 'powers' => ['read', 'write', 'manage']];
$ops = ['id' => 'u-ops', 'role' => 'operations', 'name' => 'مشرف', 'kind' => 'user', 'powers' => ['read', 'write']];
$find = function ($id) { return Mutqan_Store::get_raw('orders', $id); };
Mutqan_Store::insert('orders', ['clientName' => 'عميل النسخة', 'status' => 'new'], 'bk-1', [], $own);
$a = Mutqan_Backup::handle(['op' => 'create', 'note' => 'قبل التجربة'], $own);
$t('a copy is made and counts the rows', !empty($a['item']['name']) && ($a['item']['rows']['docs'] ?? 0) >= 1, $a['item'] ?? null);
Mutqan_Store::delete('orders', 'bk-1', $own);
Mutqan_Store::insert('orders', ['clientName' => 'طلب بعد النسخة'], 'bk-2', [], $own);
$t('the order is gone before the restore', !$find('bk-1'));
$denied = false; try { Mutqan_Backup::handle(['op' => 'list'], $ops); } catch (Mutqan_Error $e) { $denied = $e->status === 403; }
$t('operations cannot open the backups', $denied);
$nc = false; try { Mutqan_Backup::handle(['op' => 'restore', 'name' => $a['item']['name']], $own); } catch (Mutqan_Error $e) { $nc = $e->slug === 'confirm'; }
$t('a restore needs the word «استرجاع»', $nc);
$r = Mutqan_Backup::handle(['op' => 'restore', 'name' => $a['item']['name'], 'confirm' => 'استرجاع'], $own);
$t('restore brings the deleted order back', (bool) $find('bk-1'), $r);
$t('and removes what was added after the copy', !$find('bk-2'));
$t('a safety copy of the data before the restore was kept', !empty($r['safety']) && in_array('safety', array_column($r['items'], 'kind'), true));
$l = Mutqan_Backup::handle(['op' => 'link', 'name' => $a['item']['name']], $own);
$t('export gives a signed download link', strpos($l['url'], '/backup-file?t=') !== false);
$raw = file_get_contents(Mutqan_Backup::dir() . '/' . $a['item']['name']);
$im = Mutqan_Backup::handle(['op' => 'import', 'file' => base64_encode($raw)], $own);
$t('a downloaded copy can be imported back', strpos($im['name'], 'mq-import-') === 0);
$cut = false; try { Mutqan_Backup::handle(['op' => 'import', 'file' => base64_encode(gzencode("{\"mutqan_backup\":2}\n{\"t\":\"docs\",\"r\":{}}\n"))], $own); } catch (Mutqan_Error $e) { $cut = $e->slug === 'bad_file'; }
$t('a cut-off file is refused', $cut);
for ($i = 0; $i < 6; $i++) { Mutqan_Backup::make('auto', 'تلقائي'); usleep(1100000); }
$autos = array_filter(Mutqan_Backup::items(), function ($x) { return $x['kind'] === 'auto'; });
$t('only the last 4 weekly copies are kept', count($autos) === 4, count($autos));
echo $bad ? "FAILED $bad\n" : "ALL PASSED\n"; exit($bad ? 1 : 0);
