<?php
/**
 * NetPulse – diagnostika oneskorených / chýbajúcich hlásení.
 * Spustenie:  php diag.php            (v priečinku aplikácie)
 *             php diag.php "Nazov"    (aj detail konkrétneho zariadenia)
 */
require __DIR__ . '/db.php';
require __DIR__ . '/telegram.php';
migrate();
$pdo = db();
$hl = fn($s) => "\n\033[1m== $s ==\033[0m\n";
$ok = fn($s) => "  \033[32mOK\033[0m   $s\n";
$wr = fn($s) => "  \033[33mPOZOR\033[0m $s\n";
$er = fn($s) => "  \033[31mCHYBA\033[0m $s\n";

echo $hl('Prostredie');
echo "  Čas aplikácie : " . date('Y-m-d H:i:s T') . "\n";
echo "  Pásmo         : " . date_default_timezone_get() . " (systém: " . np_system_tz() . ")\n";
echo "  PHP           : " . PHP_VERSION . "\n";

// --- exec / fping ---
echo $hl('Metóda kontroly dostupnosti');
$execOk = function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true);
echo $execOk ? $ok('exec() je povolený') : $er('exec() je ZAKÁZANÝ – ping nefunguje, ide sa len cez TCP (pomalé)');
$fping = false;
if ($execOk) { @exec('command -v fping 2>/dev/null', $o, $rc); $fping = ($rc === 0 && !empty($o)); }
echo $fping ? $ok('fping je nainštalovaný: ' . ($o[0] ?? '')) 
            : $er('fping CHÝBA -> pinguje sa postupne jedno po druhom (veľmi pomalé!). Riešenie: sudo apt install -y fping');
echo "  USE_FPING v config.php: " . (cfg('USE_FPING') ? 'true' : "\033[31mfalse\033[0m") . "\n";
echo "  CHECK_METHOD : " . (cfg('CHECK_METHOD') ?: 'auto') . "\n";
echo "  DOWN_AFTER   : " . (int)(cfg('DOWN_AFTER') ?: 30) . " s  (po tomto čase sa zariadenie označí ako nefunkčné)\n";
echo "  TCP_TIMEOUT  : " . (cfg('TCP_TIMEOUT') ?: 1) . " s × " . count(cfg('TCP_FALLBACK_PORTS') ?: []) . " portov = "
     . ((cfg('TCP_TIMEOUT') ?: 1) * count(cfg('TCP_FALLBACK_PORTS') ?: [])) . " s na KAŽDÉ nedostupné zariadenie\n";

// --- pocty zariadeni ---
echo $hl('Zariadenia');
$tot = (int)$pdo->query("SELECT COUNT(*) FROM devices")->fetchColumn();
$mon = (int)$pdo->query("SELECT COUNT(*) FROM devices WHERE ip IS NOT NULL AND ip<>'' AND (monitored=1 OR monitored IS NULL)")->fetchColumn();
$off = (int)$pdo->query("SELECT COUNT(*) FROM devices WHERE monitored=0")->fetchColumn();
$noip= (int)$pdo->query("SELECT COUNT(*) FROM devices WHERE ip IS NULL OR ip=''")->fetchColumn();
$down= (int)$pdo->query("SELECT COUNT(*) FROM devices WHERE status='down'")->fetchColumn();
echo "  celkom: $tot | monitorované: $mon | vypnutý monitoring: $off | bez IP: $noip | práve nefunkčné: $down\n";
if ($off)  echo $wr("$off zariadení má vypnutý monitoring – tie sa nikdy nenahlásia");
if ($noip) echo $wr("$noip zariadení nemá IP – tie sa vôbec nekontrolujú");

// --- odhad dlzky cyklu ---
echo $hl('Odhad dĺžky jedného cyklu');
$perDown = ((float)(cfg('TCP_TIMEOUT') ?: 1)) * count(cfg('TCP_FALLBACK_PORTS') ?: []);
if ($fping && cfg('USE_FPING')) {
    $est = 2 + $down * $perDown;
    echo "  fping zvládne všetkých $mon naraz (~2 s), ale $down nedostupných × {$perDown}s fallback\n";
} else {
    $est = $mon * 0.2 + $down * (1 + $perDown);
    echo "  BEZ fpingu: $mon × ping postupne + $down × fallback porty\n";
}
printf("  odhad: \033[1m~%d s\033[0m na cyklus\n", (int)$est);
if ($est > 30) echo $er("Cyklus trvá dlhšie ako DOWN_AFTER (" . (int)(cfg('DOWN_AFTER') ?: 30) . " s) – hlásenia MUSIA meškať.");

// --- posledna kontrola ---
echo $hl('Kedy naposledy bežal monitoring');
$last = $pdo->query("SELECT MAX(last_check) FROM devices")->fetchColumn();
if (!$last) { echo $er('Žiadne zariadenie nemá last_check – monitor.php zrejme VÔBEC nebeží.'); }
else {
    $age = time() - strtotime($last);
    echo "  posledná kontrola: $last (pred $age s)\n";
    if ($age > 120) echo $er('Monitor nebežal viac ako 2 minúty – služba je zastavená alebo padá.');
    elseif ($age > 60) echo $wr('Monitor bežal pred viac ako minútou – cyklus je pomalý.');
    else echo $ok('Monitor beží pravidelne.');
}
// rozptyl last_check = ako dlho trva jeden cyklus v skutocnosti
$rng = $pdo->query("SELECT MIN(last_check) a, MAX(last_check) b FROM devices WHERE last_check IS NOT NULL")->fetch();
if ($rng && $rng['a'] && $rng['b']) {
    $spread = strtotime($rng['b']) - strtotime($rng['a']);
    echo "  rozptyl last_check naprieč zariadeniami: {$spread} s\n";
}

// --- telegram ---
echo $hl('Telegram');
$en = setting_get('tg_enabled','0'); $tok = trim((string)setting_get('tg_token','')); $chat = trim((string)setting_get('tg_chat',''));
echo ($en === '1') ? $ok('notifikácie sú zapnuté') : $er('notifikácie sú VYPNUTÉ (Nastavenia → Telegram)');
echo ($tok !== '') ? $ok('token je vyplnený (' . substr($tok,0,10) . '…)') : $er('token je prázdny');
echo ($chat !== '') ? $ok("chat ID: $chat") : $er('chat ID je prázdne');

// --- posledne udalosti ---
echo $hl('Posledných 15 udalostí');
foreach ($pdo->query("SELECT ts,device_name,ip,status FROM events ORDER BY id DESC LIMIT 15") as $e)
    printf("  %s  %-6s %-34s %s\n", $e['ts'], $e['status'], substr((string)$e['device_name'],0,34), $e['ip']);

// --- konkretne zariadenie ---
$needle = $argv[1] ?? null;
if ($needle) {
    echo $hl("Zariadenie: $needle");
    $st = $pdo->prepare("SELECT * FROM devices WHERE name LIKE ? OR ip=? LIMIT 5");
    $st->execute(['%'.$needle.'%', $needle]);
    $rows = $st->fetchAll();
    if (!$rows) echo $er('Nenašlo sa.');
    foreach ($rows as $d) {
        echo "  #{$d['id']} {$d['name']} ({$d['ip']})\n";
        echo "     stav: {$d['status']} | monitored: " . var_export($d['monitored'], true)
             . " | down_since: " . ($d['down_since'] ?: '-') . " | notified: " . ($d['notified'] ?: '-')
             . " | last_check: " . ($d['last_check'] ?: '-') . "\n";
        if ((string)$d['monitored'] === '0') echo $er('     Toto zariadenie má VYPNUTÝ monitoring – preto žiadne hlásenie.');
        $h = $pdo->prepare("SELECT ts,status FROM status_history WHERE device_id=? ORDER BY id DESC LIMIT 12");
        $h->execute([$d['id']]);
        echo "     história: ";
        foreach ($h as $r) echo "{$r['ts']}={$r['status']}  ";
        echo "\n";
    }
}
echo "\n";
