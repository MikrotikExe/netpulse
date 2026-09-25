<?php
/**
 * Jednorazové upratanie databázy NetPulse.
 * Zmaže starú históriu, vytvorí chýbajúce indexy a zmenší súbor (SQLite: VACUUM, MySQL: OPTIMIZE TABLE).
 *
 *   php cleanup.php                    – len ukáže, čo by sa stalo (nič nezmení)
 *   php cleanup.php --run              – vykoná mazanie podľa aktuálnych nastavení retencie
 *   php cleanup.php --run 30           – ponechá 30 dní histórie stavov
 *   php cleanup.php --run --tok 30     – históriu toku skráti na 30 dní (stavy podľa nastavenia)
 *   php cleanup.php --run 14 --tok 30  – oboje
 *   php cleanup.php --siroty           – vypíše zariadenia, ktoré nie sú na žiadnej mape
 *   php cleanup.php --siroty --run     – a odstráni ich (aj z monitoringu)
 * Čísla zadané v príkazovom riadku sa uložia aj do Nastavení, aby ich držal aj monitoring.
 *
 * POZOR: spusti pod tým istým používateľom, pod akým beží web (napr. sudo -u www-data)
 * a so zastavenými službami monitoringu – inak VACUUM súbor nezmenší.
 */
require __DIR__ . '/cli_guard.php';   // len z príkazového riadka, nie ako root
require __DIR__ . '/db.php';
np_cli_guard();
try { migrate(); } catch (Throwable $e) {}

$args = array_slice($argv, 1);
$run  = in_array('--run', $args, true);
$tDaysArg = null; $daysArg = null;
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--tok') {
        if (isset($args[$i+1]) && ctype_digit($args[$i+1])) { $tDaysArg = max(1, (int)$args[$i+1]); $i++; }
        continue;
    }
    if (ctype_digit($args[$i]) && $daysArg === null) $daysArg = max(1, (int)$args[$i]);
}
$days  = $daysArg  ?? max(1, (int) setting_get('history_days', (string)(cfg('HISTORY_DAYS') ?: 14)));
$tDays = $tDaysArg ?? max(1, (int) setting_get('traffic_days', (string)(cfg('TRAFFIC_DAYS') ?: 90)));

$pdo   = db();
$mysql = np_is_mysql();

// --- zariadenia mimo mapy:  php cleanup.php --siroty  (výpis)  /  --siroty --run  (odstránenie) ---
if (in_array('--siroty', $args, true)) {
    $orph = np_orphan_devices($pdo);
    echo "\n== Zariadenia, ktoré nie sú na žiadnej mape ==\n";
    if (!$orph) { echo "  žiadne\n\n"; exit(0); }
    foreach ($orph as $o) printf("  #%-8s %-40s %-16s %s%s\n", $o['id'], (function_exists('mb_substr') ? mb_substr((string)$o['name'], 0, 40) : substr((string)$o['name'], 0, 40)), $o['ip'] ?: '(bez IP)',
                                $o['status'], (string)$o['monitored'] === '0' ? ' (monitoring vypnutý)' : '');
    if (!$run) { echo "\n  (len výpis) Odstrániš ich: php cleanup.php --siroty --run\n\n"; exit(0); }
    $okN = 0;
    foreach ($orph as $o) if (db_tx(fn(PDO $p) => np_delete_device($p, (int)$o['id']))) $okN++;
    echo "\n  odstránených: $okN\n\n"; exit(0);
}
$path  = $mysql ? null : cfg('SQLITE_PATH');
$fmt = fn($b) => $b > 1073741824 ? round($b/1073741824,2).' GB' : round($b/1048576,1).' MB';

echo "\n== Stav databázy ==\n";
if ($mysql) echo "  MySQL/MariaDB: " . cfg('MYSQL_DB') . "\n";
else {
    echo "  súbor: $path\n";
    if (is_file($path)) echo "  veľkosť: " . $fmt(filesize($path)) . "\n";
    echo "  journal_mode: " . db_journal_mode() . "\n";
    $free = @disk_free_space(dirname($path));
    echo "  voľné miesto: " . ($free ? $fmt($free) : '?') . "\n";
}

echo "\n== Počty riadkov ==\n";
foreach (['status_history','traffic_history','events','outages','devices','notify_queue'] as $t) {
    try { $c = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(); } catch (Throwable $e) { $c = -1; }
    printf("  %-16s %12s\n", $t, number_format($c, 0, ',', ' '));
}
$cut  = date('Y-m-d H:i:s', time() - $days*86400);
$tCut = date('Y-m-d H:i:s', time() - $tDays*86400);
$cnt = function (string $t, string $c) use ($pdo) {
    try { $st = $pdo->prepare("SELECT COUNT(*) FROM $t WHERE ts < ?"); $st->execute([$c]); return (int)$st->fetchColumn(); }
    catch (Throwable $e) { return 0; }
};
$old = $cnt('status_history', $cut); $oldT = $cnt('traffic_history', $tCut);
echo "\n  na zmazanie:\n";
printf("  %14s riadkov  status_history  (starší ako %d dní, pred %s)\n", number_format($old,0,',',' '), $days, $cut);
printf("  %14s riadkov  traffic_history (starší ako %d dní, pred %s)\n", number_format($oldT,0,',',' '), $tDays, $tCut);

if (!$mysql && is_file($path) && !empty($free) && $free < filesize($path) * 1.1) {
    echo "\n  !! VACUUM potrebuje dočasne asi rovnako miesta ako má databáza.\n";
    echo "     Voľné miesto nemusí stačiť – uvoľni miesto alebo VACUUM preskoč.\n";
}

if (!$run) {
    echo "\n  (skúšobný režim – nič sa nezmenilo)\n";
    echo "  Spusti s parametrom --run, ak to takto chceš vykonať.\n\n";
    exit(0);
}

echo "\n== Mazanie ==\n";
$t0 = microtime(true);
/** Po dávkach s priebehom – odvodená tabuľka funguje na SQLite aj MySQL (chyby 1093/1235). */
$purge = function (string $t, string $c, string $label) use ($pdo) {
    $sql = "DELETE FROM $t WHERE id IN (SELECT id FROM (SELECT id FROM $t WHERE ts < ? LIMIT 50000) x)";
    $del = 0;
    while (true) {
        $n = db_retry(function () use ($pdo, $sql, $c) { $st = $pdo->prepare($sql); $st->execute([$c]); return $st->rowCount(); });
        if (!$n) break;
        $del += $n; echo "  $label: " . number_format($del,0,',',' ') . "\r";
        usleep(50000);
    }
    echo "  zmazaných z $t: " . number_format($del,0,',',' ') . "            \n";
};
$purge('status_history',  $cut,  'status_history');
$purge('traffic_history', $tCut, 'traffic_history');
// len hodnoty zadané v príkazovom riadku sa uložia do Nastavení
try {
    if ($daysArg  !== null) setting_set('history_days', (string)$days);
    if ($tDaysArg !== null) setting_set('traffic_days', (string)$tDays);
} catch (Throwable $e) {}

echo "\n== Indexy ==\n";
foreach ([['status_history','idx_sh_dev_ts','device_id, ts'], ['status_history','idx_sh_ts','ts'],
          ['traffic_history','idx_th','link_id, ts'], ['traffic_history','idx_tr_ts','ts'],
          ['events','idx_events_ts','ts'], ['outages','idx_out_dev','device_id, ended']] as [$t,$n,$c]) {
    np_ensure_index($pdo, $t, $n, $c); echo "  OK  $n ON $t($c)\n";
}

echo "\n== Session súbory ==\n";
$sdir = __DIR__ . '/data/sessions'; $gone = 0;
if (is_dir($sdir)) {
    $cutS = time() - 31*86400;
    foreach ((glob($sdir.'/sess_*') ?: []) as $sf) if (@filemtime($sf) < $cutS && @unlink($sf)) $gone++;
}
echo "  zmazaných starých sessions: $gone\n";

if ($mysql) {
    echo "\n== OPTIMIZE TABLE ==\n";
    try { $pdo->query('OPTIMIZE TABLE status_history, traffic_history, events')->fetchAll(); echo "  hotovo\n"; }
    catch (Throwable $e) { echo "  OPTIMIZE zlyhal: " . $e->getMessage() . "\n"; }
} else {
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
}
printf("\n  celkový čas: %.1f s\n\n", microtime(true)-$t0);
