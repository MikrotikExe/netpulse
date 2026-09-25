<?php
/**
 * Trojstavový živý monitoring (ako Dude):
 *   up=zelená (odpovedá), pending=žltá (práve nereaguje), down=červená (>DOWN_AFTER s).
 *
 * Jeden beh = jeden cyklus (systemd ho spúšťa v slučke). Cyklus má tri fázy:
 *   1) SONDOVANIE celej siete naraz – fping + paralelné TCP (bez zápisu do DB),
 *   2) ZÁPIS výsledkov v JEDNEJ krátkej transakcii (stav, história, udalosti, výpadky,
 *      fronta Telegram správ) – všetko alebo nič, takže sa nič nestratí ani nezdvojí,
 *   3) DORUČENIE Telegram správ z fronty (pri výpadku Telegramu počkajú na ďalší pokus).
 *
 * Zariadenie je "up", ak odpovie ping ALEBO ktorákoľvek jeho TCP/SNMP/DNS služba,
 * alebo (pri CHECK_METHOD=auto) niektorý záložný TCP port.
 */
require __DIR__ . '/cli_guard.php';   // len z príkazového riadka, nie ako root
require __DIR__ . '/db.php';
require __DIR__ . '/telegram.php';
np_cli_guard();

// ---- len jedna inštancia naraz (ručné spustenie popri službe by zdvojilo prácu) ----
$lockPath = is_writable(__DIR__ . '/data') ? __DIR__ . '/data/monitor.lock'
                                           : sys_get_temp_dir() . '/netpulse-monitor-' . md5(__DIR__) . '.lock';
$lockFh = @fopen($lockPath, 'c');
if ($lockFh && !flock($lockFh, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Monitor už beží v inom procese – tento beh preskakujem\n"); exit(0);
}

try { migrate(); } catch (Throwable $e) { error_log('NetPulse migrate: '.$e->getMessage()); }

require __DIR__ . '/probe_lib.php';

try { $pdo = db(); } catch (Throwable $e) {
    fwrite(STDERR, "DB nedostupná – cyklus preskočený: ".$e->getMessage()."\n"); exit(0);
}
$t0Cycle = microtime(true);
$pt        = max(1, (int) cfg('PING_TIMEOUT'));
$tcpTo     = (float) (cfg('TCP_TIMEOUT') ?: 1);
$downAfter = (int) (cfg('DOWN_AFTER') ?: 30);
$histDays  = max(1, (int) setting_get('history_days',  (string)(cfg('HISTORY_DAYS')  ?: 14)));
$histEvery = max(0, (int) setting_get('history_every', (string)(cfg('HISTORY_EVERY') ?? 60)));
$fallbackPorts = cfg('TCP_FALLBACK_PORTS') ?: [8291,80,443,22,23];
$method   = cfg('CHECK_METHOD') ?: 'auto';
$useIcmp  = ($method === 'icmp') || ($method === 'auto' && exec_allowed());
$useFping = $useIcmp && cfg('USE_FPING') && fping_available();

// ======================================================================
// 0) ÚDRŽBA – vypnutý monitoring / chýbajúca IP => sivé, uzavri ich výpadky;
//    uzavri aj výpadky, ktoré ostali visieť zo staršej verzie.
// ======================================================================
$now = date('Y-m-d H:i:s'); $nowTs = time();
try {
    db_tx(function (PDO $pdo) use ($now, $nowTs) {
        $cl = $pdo->prepare('UPDATE outages SET ended=?,duration=? WHERE id=?');
        foreach ($pdo->query("SELECT o.id,o.started,o.started_ts FROM outages o JOIN devices d ON d.id=o.device_id
                              WHERE o.ended IS NULL AND (d.monitored=0 OR d.ip IS NULL OR d.ip='')")->fetchAll() as $o) {
            $st = np_epoch($o['started_ts'], $o['started']);
            $cl->execute([$now, $st ? max(0, $nowTs - $st) : null, $o['id']]);
        }
        // funkčné zariadenie, ktorého návrat už bol zaznamenaný, nemá mať otvorený výpadok
        $orph = $pdo->query("SELECT o.id,o.device_id,o.started,o.started_ts FROM outages o JOIN devices d ON d.id=o.device_id
                             WHERE o.ended IS NULL AND d.status='up' AND (d.notified='up' OR d.notified IS NULL)")->fetchAll();
        if ($orph) {
            $fu = $pdo->prepare("SELECT MIN(ts) FROM status_history WHERE device_id=? AND ts > ? AND status='up'");
            foreach ($orph as $o) {
                $fu->execute([$o['device_id'], $o['started']]); $end = $fu->fetchColumn() ?: $now; $fu->closeCursor();
                $st = np_epoch($o['started_ts'], $o['started']); $en = strtotime((string)$end);
                $cl->execute([$end, ($st && $en) ? max(0, $en - $st) : null, $o['id']]);
            }
            error_log('NetPulse: uzavretých '.count($orph).' visiacich výpadkov');
        }
        $pdo->exec("UPDATE devices SET status='unknown', rtt=NULL, down_since=NULL, down_ts=NULL, notified='up'
                    WHERE (monitored=0 OR ip IS NULL OR ip='')
                      AND (status<>'unknown' OR status IS NULL OR notified<>'up' OR notified IS NULL OR down_since IS NOT NULL)");
    });
} catch (Throwable $e) { error_log('NetPulse údržba: '.$e->getMessage()); }

$devs = db_retry(fn() => $pdo->query("SELECT id,name,ip,status,down_since,down_ts,notified,last_check FROM devices
                     WHERE ip IS NOT NULL AND ip<>'' AND (monitored=1 OR monitored IS NULL)")->fetchAll());
if ($devs === null) { fwrite(STDERR, "Zoznam zariadení sa nepodarilo načítať (DB zamknutá) – cyklus preskočený\n"); exit(0); }
// prázdny zoznam: sondovanie a zápis sa preskočia, fronta správ a upratovanie bežia ďalej
$devById = []; foreach ($devs as $d) $devById[$d['id']] = $d;
$svcRows = db_retry(fn() => $pdo->query("SELECT id,device_id,ptype,port FROM services WHERE enabled=1 OR enabled IS NULL")->fetchAll()) ?: [];

// ======================================================================
// 1) SONDOVANIE – bez zápisu do databázy
// ======================================================================
$now = date('Y-m-d H:i:s'); $nowTs = time();          // čas merania
$res = [];                                             // id => ping, reach, rtt
$bad = 0;
$hosts = [];
foreach ($devs as $d) {
    $ok = np_valid_host($d['ip']);
    if (!$ok) $bad++;
    $res[$d['id']] = ['ping' => false, 'reach' => false, 'rtt' => null, 'valid' => $ok];
    if ($ok) $hosts[$d['ip']] = true;
}
if ($useFping) {
    $alive = fping_batch(array_keys($hosts), max(300, $pt * 1000));
    foreach ($devs as $d) if (array_key_exists($d['ip'], $alive)) {
        $res[$d['id']]['ping'] = $res[$d['id']]['reach'] = true; $res[$d['id']]['rtt'] = $alive[$d['ip']];
    }
} elseif ($useIcmp) {                                  // bez fping: po jednom (pomalšie)
    foreach ($devs as $d) {
        if (!$res[$d['id']]['valid']) continue;
        $o = []; @exec('ping -c 1 -W ' . $pt . ' ' . escapeshellarg($d['ip']) . ' 2>/dev/null', $o, $rc);
        if ($rc === 0) {
            $res[$d['id']]['ping'] = $res[$d['id']]['reach'] = true;
            if (preg_match('/time[=<]([\d.]+)/', implode("\n", $o), $m)) $res[$d['id']]['rtt'] = (float)$m[1];
        }
    }
}
// služby zariadení
$svcUp = []; $tcpT = [];
foreach ($svcRows as $sv) {
    $d = $devById[$sv['device_id']] ?? null;
    if (!$d || !$res[$d['id']]['valid']) continue;
    switch ($sv['ptype']) {
        case 'tcp':  $tcpT['s' . $sv['id']] = [$d['ip'], (int)$sv['port']]; break;
        case 'dns':  $svcUp[$sv['id']] = dns_check($d['ip']); break;
        case 'snmp': $svcUp[$sv['id']] = snmp_check($d['ip'], (int)$sv['port'] ?: 161); break;
        case 'icmp': default: $svcUp[$sv['id']] = $res[$d['id']]['ping'];
    }
}
$tcpOk = $tcpT ? tcp_probe_batch($tcpT, $tcpTo) : [];
foreach ($svcRows as $sv) {
    if (!isset($devById[$sv['device_id']])) continue;
    $id = $sv['device_id'];
    if ($sv['ptype'] === 'tcp' && $res[$id]['valid']) {
        $u = isset($tcpOk['s' . $sv['id']]); $svcUp[$sv['id']] = $u;
        if ($u && $res[$id]['rtt'] === null) $res[$id]['rtt'] = $tcpOk['s' . $sv['id']];
    }
    if (!empty($svcUp[$sv['id']])) $res[$id]['reach'] = true;
}
// posledná záchrana – záložné porty (winbox/web…), všetky nedostupné zariadenia naraz
if ($method === 'auto') {
    $fb = [];
    foreach ($devs as $d) if (!$res[$d['id']]['reach'] && $res[$d['id']]['valid'])
        foreach ($fallbackPorts as $p) $fb[$d['id'] . ':' . (int)$p] = [$d['ip'], (int)$p];
    foreach (($fb ? tcp_probe_batch($fb, $tcpTo) : []) as $k => $ms) {
        $id = (int) strtok($k, ':');
        if (!$res[$id]['reach']) { $res[$id]['reach'] = true; $res[$id]['rtt'] = $res[$id]['rtt'] ?? $ms; }
    }
}
$probeSec = microtime(true) - $t0Cycle;

// ======================================================================
// 2) ZÁPIS – jedna transakcia: buď sa zapíše celý cyklus, alebo nič
//    (pri zámku sa zopakuje; ďalší cyklus by aj tak zistil ten istý stav)
// ======================================================================
$tr = ['down' => 0, 'up' => 0];
$written = !$devs;
if ($devs) try {
    $written = db_tx(function (PDO $pdo) use ($devs, $res, $svcRows, $svcUp, $devById, $now, $nowTs,
                                             $downAfter, $histEvery, &$tr) {
        $tr = ['down' => 0, 'up' => 0];                // pri opakovaní transakcie začni odznova
        // Podmienka zabráni zápisu výsledku, ak admin počas sondovania zariadeniu vypol monitoring,
        // zmenil IP alebo ho zmazal – inak by vznikla falošná udalosť a správa do Telegramu.
        $updDev  = $pdo->prepare('UPDATE devices SET status=?,rtt=?,last_check=?,down_since=?,down_ts=?,notified=?
                                  WHERE id=? AND ip=? AND COALESCE(monitored,1)=1');
        $updSvc  = $pdo->prepare('UPDATE services SET status=?,last_check=? WHERE id=?');
        $hist    = $pdo->prepare('INSERT INTO status_history(device_id,ts,status,rtt) VALUES(?,?,?,?)');
        $evt     = $pdo->prepare('INSERT INTO events(ts,device_id,device_name,ip,status,message) VALUES(?,?,?,?,?,?)');
        $openOut = $pdo->prepare('SELECT id,started,started_ts FROM outages WHERE device_id=? AND ended IS NULL ORDER BY id');
        $newOut  = $pdo->prepare('INSERT INTO outages(device_id,service,started,started_ts) VALUES(?,?,?,?)');
        $closeOut= $pdo->prepare('UPDATE outages SET ended=?,duration=? WHERE id=?');

        foreach ($devs as $d) {
            $r = $res[$d['id']]; $ip = $d['ip']; $prev = $d['status'];
            // posledný zaznamenaný prechod (pre staré DB bez stĺpca: podľa stavu)
            $notified  = !empty($d['notified']) ? $d['notified'] : ($prev === 'down' ? 'down' : 'up');
            $downSince = $d['down_since'];
            $downTs    = np_epoch($d['down_ts'], $d['down_since']);   // epoch – odolné voči zmene času
            if ($r['reach']) { $status = 'up'; $downSince = null; $downTs = null; }
            else {
                if (!$downTs) { $downTs = $nowTs; $downSince = $now; }
                $status = (($nowTs - $downTs) >= $downAfter) ? 'down' : 'pending';
            }
            $doDown = ($status === 'down' && $notified !== 'down');
            $doUp   = ($status === 'up'   && $notified === 'down');
            $newN   = $doDown ? 'down' : ($doUp ? 'up' : $notified);
            $updDev->execute([$status, $r['rtt'], $now, $downSince, $downTs, $newN, $d['id'], $ip]);
            if ($updDev->rowCount() === 0) continue;          // medzitým zmenené – výsledok neplatí

            // história: zmena stavu vždy, odozva len vzorkovaná (inak DB rastie o 500k riadkov denne)
            $sample = ($status !== $prev) || $histEvery === 0 || empty($d['last_check'])
                   || intdiv($nowTs, max(1, $histEvery)) !== intdiv((int)strtotime((string)$d['last_check']), max(1, $histEvery));
            if ($sample) $hist->execute([$d['id'], $now, $status, $r['rtt']]);

            if ($doDown) {
                $tr['down']++;
                $evt->execute([$now, $d['id'], $d['name'], $ip, 'down', "Zariadenie: {$d['name']} IP:$ip; je nefunkčné"]);
                $openOut->execute([$d['id']]); $has = (bool)$openOut->fetch(); $openOut->closeCursor();
                if (!$has) $newOut->execute([$d['id'], 'ping', $downSince ?: $now, $downTs ?: $nowTs]);
                tg_enqueue($pdo, (int)$d['id'], (string)$d['name'], $ip, 'down', $now, $nowTs);
            }
            if ($doUp) {
                $tr['up']++;
                $evt->execute([$now, $d['id'], $d['name'], $ip, 'up', "Zariadenie: {$d['name']} IP:$ip; je funkčné"]);
                $openOut->execute([$d['id']]); $open = $openOut->fetchAll(); $openOut->closeCursor();
                foreach ($open as $o) {
                    $st = np_epoch($o['started_ts'], $o['started']);
                    $closeOut->execute([$now, $st ? max(0, $nowTs - $st) : null, $o['id']]);
                }
                tg_enqueue($pdo, (int)$d['id'], (string)$d['name'], $ip, 'up', $now, $nowTs);
            }
        }
        foreach ($svcRows as $sv) {
            if (!isset($devById[$sv['device_id']]) || !array_key_exists($sv['id'], $svcUp)) continue;
            $updSvc->execute([$svcUp[$sv['id']] ? 'up' : 'down', $now, $sv['id']]);
        }
    });
} catch (Throwable $e) {
    error_log('NetPulse monitor – zápis zlyhal: '.$e->getMessage()
              . (np_schema_error($e) ? ' (neúplná schéma – migrácia sa zopakuje)' : ''));
}

// ======================================================================
// 3) DORUČENIE Telegram správ z fronty
// ======================================================================
$tg = [0, 0, 0];
try { $tg = tg_flush($pdo); } catch (Throwable $e) { error_log('NetPulse Telegram: '.$e->getMessage()); }

// ======================================================================
// 4) UPRATOVANIE – každých 10 min, s časovým stropom (nesmie brzdiť monitoring)
// ======================================================================
try {
    if ($nowTs - (int) setting_get('last_purge_hist', '0') >= 600) {
        setting_set('last_purge_hist', (string)$nowTs);
        np_purge($pdo, 'status_history', date('Y-m-d H:i:s', $nowTs - $histDays * 86400), 3.0);
        np_purge($pdo, 'events',         date('Y-m-d H:i:s', $nowTs - 365 * 86400), 1.0);
        $sdir = __DIR__ . '/data/sessions';            // PHP vo vlastnom priečinku sessions sám nemaže
        if (is_dir($sdir)) {
            $cutS = $nowTs - 31 * 86400; $stop = microtime(true) + 1.0;
            foreach ((glob($sdir . '/sess_*') ?: []) as $sf) {
                if (@filemtime($sf) < $cutS) @unlink($sf);
                if (microtime(true) > $stop) break;
            }
        }
    }
} catch (Throwable $e) { error_log('NetPulse upratovanie: '.$e->getMessage()); }

// ---- súhrn do logu ----
$m  = $useFping ? 'fping+tcp' : ($useIcmp ? 'ping+tcp' : 'tcp');
$jm = db_journal_mode();
fwrite(STDERR, sprintf('%d zariadení skontrolovaných (%s) @ %s  [%.1f s]', count($devs), $m, $now, microtime(true) - $t0Cycle)
    . ($written ? '' : '  !! výsledky NEZAPÍSANÉ (DB zamknutá) – zopakuje sa v ďalšom cykle')
    . ($tr['down'] || $tr['up'] ? "  výpadky: +{$tr['down']} / návraty: +{$tr['up']}" : '')
    . ($tg[0] || $tg[1] ? "  Telegram: odoslané {$tg[0]}" . ($tg[1] ? ", zlyhalo – čaká {$tg[2]}" : '') : '')
    . ($bad ? "  [$bad zariadení s neplatnou IP/DNS]" : '')
    . (($jm !== 'wal' && !np_is_mysql()) ? "  !! journal_mode=$jm (nie WAL – hrozia zámky)" : '')
    . "\n");
