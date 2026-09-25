<?php
/** NetPulse – kde sa stratil čas medzi výpadkom a hlásením.
 *  php diag2.php 2026-09-17            (deň, ktorý chceme rozobrať)
 *  php diag2.php 2026-09-17 "Router1" (aj filter na názov)
 */
require __DIR__ . '/cli_guard.php';   // len z príkazového riadka, nie ako root
require __DIR__ . '/db.php';
np_cli_guard();
$pdo = db();
$day = $argv[1] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) { fwrite(STDERR, "Dátum v tvare RRRR-MM-DD\n"); exit(1); }
$dayFrom = "$day 00:00:00"; $dayTo = date('Y-m-d', strtotime("$day +1 day")) . ' 00:00:00';
$needle = $argv[2] ?? null;
echo "\n\033[1m== Výpadky dňa $day ==\033[0m\n";
echo "  started = kedy prestalo odpovedať | detected = kedy monitor vyhlásil down | lag = oneskorenie\n\n";
$sql = "SELECT o.id,o.device_id,o.started,o.ended,o.duration,d.name,d.ip
        FROM outages o LEFT JOIN devices d ON d.id=o.device_id
        WHERE o.started >= ? AND o.started < ? " . ($needle ? "AND d.name LIKE ? " : "") . "ORDER BY o.started";
$st = $pdo->prepare($sql);
$st->execute($needle ? [$dayFrom, $dayTo, '%'.$needle.'%'] : [$dayFrom, $dayTo]);
$rows = $st->fetchAll();
if (!$rows) { echo "  (žiadne zaznamenané výpadky v tento deň)\n"; }
$evD = $pdo->prepare("SELECT ts FROM events WHERE device_id=? AND status='down' AND ts>=? ORDER BY ts LIMIT 1");
$maxLag = 0;
foreach ($rows as $r) {
    $evD->execute([$r['device_id'], $r['started']]);
    $det = $evD->fetchColumn();
    $lag = $det ? (strtotime($det) - strtotime($r['started'])) : null;
    if ($lag !== null && $lag > $maxLag) $maxLag = $lag;
    $sec = (int)$r['duration'];
    $dur = $r['duration'] !== null ? (intdiv($sec, 86400) ? intdiv($sec, 86400).' d ' : '') . gmdate('H:i:s', $sec % 86400)
                                   : ($r['ended'] ? '?' : 'trvá');
    printf("  %s -> detected %-19s  \033[1mlag %s\033[0m | trvanie %s | %s (%s)\n",
        $r['started'], $det ?: 'ŽIADNA UDALOSŤ', $lag === null ? '?' : $lag.'s', $dur,
        substr((string)$r['name'],0,40), $r['ip']);
}
if ($maxLag > 120) echo "\n  \033[31mNajväčšie oneskorenie: {$maxLag}s\033[0m – monitor v tom čase nebežal alebo bol zaseknutý.\n";

echo "\n\033[1m== Medzery v behu monitoringu (podľa status_history) ==\033[0m\n";
echo "  Odozva sa vzorkuje raz za minútu, takže medzery do ~150 s sú normálne.\n\n";
$h = $pdo->prepare('SELECT DISTINCT ts FROM status_history WHERE ts >= ? AND ts < ? ORDER BY ts');
$h->execute([$dayFrom, $dayTo]);
$prev = null; $gaps = 0;
foreach ($h as $r) {
    if ($prev) {
        $g = strtotime($r['ts']) - strtotime($prev);
        if ($g > 150) { printf("  \033[31mDIERA %6d s\033[0m  %s  ->  %s\n", $g, $prev, $r['ts']); $gaps++; }
    }
    $prev = $r['ts'];
}
if (!$gaps) echo "  Žiadne diery – monitor bežal nepretržite.\n";
else echo "\n  Našlo sa $gaps dier – monitor v tom čase NEBEŽAL. Pozri: journalctl -u dudeweb-monitor --since '$day 00:00'\n";

echo "\n\033[1m== Zariadenia, ktoré monitor preskakuje ==\033[0m\n";
$stale = $pdo->query("SELECT name,ip,last_check,status FROM devices
    WHERE (monitored=1 OR monitored IS NULL) AND ip IS NOT NULL AND ip<>''
    ORDER BY last_check LIMIT 5")->fetchAll();
$newest = $pdo->query("SELECT MAX(last_check) FROM devices")->fetchColumn();
foreach ($stale as $s) {
    $age = $newest && $s['last_check'] ? strtotime($newest) - strtotime($s['last_check']) : null;
    printf("  %-19s (%5s s pozadu) %-6s %s (%s)\n", $s['last_check'] ?: '-', $age === null ? '?' : $age,
        $s['status'], substr((string)$s['name'],0,40), $s['ip']);
}
echo "\n  (ak je niektoré viac ako pár sekúnd pozadu, monitor ho vynecháva)\n\n";
