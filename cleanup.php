<?php
/**
 * Jednorazové upratanie databázy NetPulse.
 * Zmaže starú históriu, vytvorí chýbajúce indexy a zmenší súbor (VACUUM).
 *
 *   php cleanup.php            – len ukáže, čo by sa stalo (nič nezmení)
 *   php cleanup.php --run      – vykoná mazanie + VACUUM
 *   php cleanup.php --run 30   – ponechá 30 dní histórie stavov (predvolene 14)
 *   php cleanup.php --run 14 --tok 30  – aj históriu toku skráti na 30 dní (predvolene 90)
 *
 * POZOR: pusti to pod tým istým používateľom, pod akým beží web (napr. www-data)
 * a najlepšie so zastavenými službami monitoringu.
 */
require __DIR__ . '/db.php';
$run  = in_array('--run', $argv, true);
$days = 14; $tDays = 90;
$nums = array_values(array_filter($argv, 'ctype_digit'));
if (isset($nums[0])) $days  = max(1, (int)$nums[0]);
$ti = array_search('--tok', $argv, true);
if ($ti !== false && isset($argv[$ti+1]) && ctype_digit($argv[$ti+1])) $tDays = max(1, (int)$argv[$ti+1]);
elseif (isset($nums[1])) $tDays = max(1, (int)$nums[1]);
$pdo = db();
$path = cfg('SQLITE_PATH');
$fmt = fn($b) => $b > 1073741824 ? round($b/1073741824,2).' GB' : round($b/1048576,1).' MB';

echo "\n== Stav databázy ==\n";
echo "  súbor: $path\n";
if (is_file($path)) echo "  veľkosť: " . $fmt(filesize($path)) . "\n";
echo "  journal_mode: " . db_journal_mode() . "\n";
$free = @disk_free_space(dirname($path));
echo "  voľné miesto: " . ($free ? $fmt($free) : '?') . "\n";

echo "\n== Počty riadkov ==\n";
$tabs = ['status_history','traffic_history','events','outages','devices'];
$counts = [];
foreach ($tabs as $t) {
    try { $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(); }
    catch (Throwable $e) { $counts[$t] = -1; }
    printf("  %-16s %12s\n", $t, number_format($counts[$t], 0, ',', ' '));
}
$cut = date('Y-m-d H:i:s', time() - $days*86400);
$tCut = date('Y-m-d H:i:s', time() - $tDays*86400);
$old = 0; $oldT = 0;
try { $old  = (int)$pdo->query("SELECT COUNT(*) FROM status_history WHERE ts < '$cut'")->fetchColumn(); } catch (Throwable $e) {}
try { $oldT = (int)$pdo->query("SELECT COUNT(*) FROM traffic_history WHERE ts < '$tCut'")->fetchColumn(); } catch (Throwable $e) {}
echo "\n  na zmazanie:\n";
printf("  %14s riadkov  status_history  (starší ako %d dní, pred %s)\n", number_format($old,0,',',' '), $days, $cut);
printf("  %14s riadkov  traffic_history (starší ako %d dní, pred %s)\n", number_format($oldT,0,',',' '), $tDays, $tCut);

if (is_file($path) && $free && $free < filesize($path) * 1.1) {
    echo "\n  !! VACUUM potrebuje dočasne asi rovnako miesta ako má databáza.\n";
    echo "     Voľné miesto nemusí stačiť – uvoľni miesto alebo VACUUM preskoč.\n";
}

if (!$run) {
    echo "\n  (skúšobný režim – nič sa nezmenilo)\n";
    echo "  Spusti s parametrom --run, ak to takto chceš vykonať.\n\n";
    exit(0);
}

echo "\n== Mazanie ==\n";
$t0 = microtime(true); $del = 0;
while (true) {
    $n = db_retry(fn() => $pdo->exec("DELETE FROM status_history WHERE id IN
          (SELECT id FROM status_history WHERE ts < '$cut' LIMIT 50000)"));
    if (!$n) break;
    $del += $n; echo "  zmazaných: " . number_format($del,0,',',' ') . "\r";
    usleep(50000);
}
echo "  zmazaných zo status_history: " . number_format($del,0,',',' ') . "\n";
$delT = 0;
while (true) {
    $n = db_retry(fn() => $pdo->exec("DELETE FROM traffic_history WHERE rowid IN
          (SELECT rowid FROM traffic_history WHERE ts < '$tCut' LIMIT 50000)"));
    if (!$n) break;
    $delT += $n; echo "  zmazaných z toku: " . number_format($delT,0,',',' ') . "\r";
    usleep(50000);
}
echo "  zmazaných z traffic_history: " . number_format($delT,0,',',' ') . "\n";
// nastavenia nech to udržujú aj naďalej
try { setting_set('history_days', (string)$days); setting_set('traffic_days', (string)$tDays); } catch (Throwable $e) {}

echo "\n== Indexy ==\n";
foreach ([
  "CREATE INDEX IF NOT EXISTS idx_sh_dev_ts ON status_history(device_id, ts)",
  "CREATE INDEX IF NOT EXISTS idx_sh_ts ON status_history(ts)",
  "CREATE INDEX IF NOT EXISTS idx_th ON traffic_history(link_id, ts)",
  "CREATE INDEX IF NOT EXISTS idx_events_ts ON events(ts)",
] as $sql) {
    try { $pdo->exec($sql); echo "  OK  " . substr($sql, 27, 40) . "\n"; }
    catch (Throwable $e) { echo "  !!  " . $e->getMessage() . "\n"; }
}

echo "\n== Session súbory ==\n";
$sdir = __DIR__ . '/data/sessions'; $gone = 0;
if (is_dir($sdir)) {
    $cutS = time() - 31*86400;
    foreach ((glob($sdir.'/sess_*') ?: []) as $sf) if (@filemtime($sf) < $cutS && @unlink($sf)) $gone++;
}
echo "  zmazaných starých sessions: $gone\n";

echo "\n== VACUUM (zmenšenie súboru) ==\n";
echo "  môže to trvať aj niekoľko minút, databáza je zatiaľ zamknutá…\n";
try {
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    $pdo->exec('VACUUM');
    $pdo->exec('ANALYZE');
    clearstatcache();
    echo "  hotovo, nová veľkosť: " . (is_file($path) ? $fmt(filesize($path)) : '?') . "\n";
} catch (Throwable $e) {
    echo "  VACUUM zlyhal: " . $e->getMessage() . "\n";
    echo "  (zastav služby monitoringu a skús znova)\n";
}
printf("\n  celkový čas: %.1f s\n\n", microtime(true)-$t0);
