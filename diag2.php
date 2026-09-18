<?php
/** NetPulse – kde sa stratil čas medzi výpadkom a hlásením.
 *  php diag2.php 2026-09-17            (deň, ktorý chceme rozobrať)
 *  php diag2.php 2026-09-17 "SK_Basic" (aj filter na názov)
 */
require __DIR__ . '/db.php';
$pdo = db();
$day = $argv[1] ?? date('Y-m-d');
$needle = $argv[2] ?? null;
echo "\n\033[1m== Výpadky dňa $day ==\033[0m\n";
echo "  started = kedy prestalo odpovedať | detected = kedy monitor vyhlásil down | lag = oneskorenie\n\n";
$sql = "SELECT o.id,o.device_id,o.started,o.ended,o.duration,d.name,d.ip
        FROM outages o LEFT JOIN devices d ON d.id=o.device_id
        WHERE o.started LIKE ? " . ($needle ? "AND d.name LIKE ? " : "") . "ORDER BY o.started";
$st = $pdo->prepare($sql);
$st->execute($needle ? [$day.'%', '%'.$needle.'%'] : [$day.'%']);
$rows = $st->fetchAll();
if (!$rows) { echo "  (žiadne zaznamenané výpadky v tento deň)\n"; }
$evD = $pdo->prepare("SELECT ts FROM events WHERE device_id=? AND status='down' AND ts>=? ORDER BY ts LIMIT 1");
$maxLag = 0;
foreach ($rows as $r) {
    $evD->execute([$r['device_id'], $r['started']]);
    $det = $evD->fetchColumn();
    $lag = $det ? (strtotime($det) - strtotime($r['started'])) : null;
    if ($lag !== null && $lag > $maxLag) $maxLag = $lag;
    $dur = $r['duration'] !== null ? gmdate('H:i:s', (int)$r['duration']) : ($r['ended'] ? '?' : 'trvá');
    printf("  %s -> detected %-19s  \033[1mlag %s\033[0m | trvanie %s | %s (%s)\n",
        $r['started'], $det ?: 'ŽIADNA UDALOSŤ', $lag === null ? '?' : $lag.'s', $dur,
        substr((string)$r['name'],0,40), $r['ip']);
}
if ($maxLag > 120) echo "\n  \033[31mNajväčšie oneskorenie: {$maxLag}s\033[0m – monitor v tom čase nebežal alebo bol zaseknutý.\n";

echo "\n\033[1m== Medzery v behu monitoringu (podľa status_history) ==\033[0m\n";
echo "  Ak monitor stál, medzi dvoma kontrolami bude veľká diera.\n\n";
$h = $pdo->query("SELECT DISTINCT ts FROM status_history WHERE ts LIKE '" . $day . "%' ORDER BY ts");
$prev = null; $gaps = 0;
foreach ($h as $r) {
    if ($prev) {
        $g = strtotime($r['ts']) - strtotime($prev);
        if ($g > 90) { printf("  \033[31mDIERA %6d s\033[0m  %s  ->  %s\n", $g, $prev, $r['ts']); $gaps++; }
    }
    $prev = $r['ts'];
}
if (!$gaps) echo "  Žiadne diery > 90 s – monitor bežal nepretržite.\n";
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
