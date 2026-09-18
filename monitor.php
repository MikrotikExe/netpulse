<?php
/**
 * Trojstavový živý monitoring (ako Dude):
 *   up=zelená (odpovedá), pending=žltá (práve nereaguje), down=červená (>DOWN_AFTER s).
 *
 * Dostupnosť: paralelný ICMP ping (fping) A/ALEBO TCP služby zariadenia.
 * Zariadenie je "up" ak odpovie ping ALEBO ktorákoľvek jeho TCP/SNMP služba
 * (napr. OSCam port 8888) – preto fungujú aj zariadenia, čo sa nedajú pingnúť.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/telegram.php';
try { migrate(); } catch (Throwable $e) { error_log('NetPulse migrate: '.$e->getMessage()); }

function exec_allowed(): bool {
    if (!function_exists('exec')) return false;
    $dis = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('exec', $dis, true);
}
function fping_available(): bool {
    if (!exec_allowed()) return false;
    @exec('command -v fping 2>/dev/null', $o, $rc); return $rc === 0 && !empty($o);
}
function fping_batch(array $ips, int $timeoutMs): array {
    $alive = []; if (!$ips) return $alive;
    $args = implode(' ', array_map('escapeshellarg', $ips));
    @exec("fping -a -e -t $timeoutMs -r 1 $args 2>/dev/null", $out);
    foreach ($out as $line) {
        if (preg_match('/^(\S+)\s+\(([\d.]+)\s*ms\)/', $line, $m)) $alive[$m[1]] = (float)$m[2];
        elseif (preg_match('/^(\S+)\s+is alive/', $line, $m)) $alive[$m[1]] = null;
    }
    return $alive;
}
function tcp_connect(string $ip, int $port, float $to): array {
    if ($port <= 0) return [false, null];
    $t0 = microtime(true);
    $f = @fsockopen($ip, $port, $e, $es, $to);
    if ($f) { fclose($f); return [true, round((microtime(true)-$t0)*1000, 1)]; }
    return [false, null];
}
function tcp_reach(string $ip, array $ports, float $to): array {
    foreach ($ports as $p) { [$ok,$rtt] = tcp_connect($ip, (int)$p, $to); if ($ok) return [true,$rtt]; }
    return [false, null];
}
function dns_check(string $ip): bool {
    if (!exec_allowed()) return false;
    @exec('nslookup www.mikrotik.com ' . escapeshellarg($ip) . ' 2>/dev/null', $o, $rc); return $rc === 0;
}
function snmp_check(string $ip, int $port): bool {
    if (function_exists('snmpget')) { $r=@snmpget($ip,'public','1.3.6.1.2.1.1.1.0',1000000,1); return $r!==false; }
    return false;
}

try { $pdo = db(); } catch (Throwable $e) {
    fwrite(STDERR, "DB nedostupná – cyklus preskočený: ".$e->getMessage()."\n"); exit(0);
}
$now = date('Y-m-d H:i:s'); $nowTs = time();
$pt = (int) cfg('PING_TIMEOUT');
$tcpTo = (float) (cfg('TCP_TIMEOUT') ?: 1);
$downAfter = (int) (cfg('DOWN_AFTER') ?: 30);
// Retencia histórie. Bez nej tabuľka status_history rástla o ~500 000 riadkov denne.
$histDays  = max(1, (int) (setting_get('history_days', (string)(cfg('HISTORY_DAYS') ?: 14))));
$histEvery = max(0, (int) (setting_get('history_every', (string)(cfg('HISTORY_EVERY') ?: 60))));
$fallbackPorts = cfg('TCP_FALLBACK_PORTS') ?: [8291,80,443,22,23];
$method = cfg('CHECK_METHOD') ?: 'auto';
$useIcmp = ($method === 'icmp') || ($method === 'auto' && exec_allowed());
$useFping = $useIcmp && cfg('USE_FPING') && fping_available();

// vypnutý monitoring -> sivé (unknown), nepingovať
db_retry(fn() => $pdo->exec("UPDATE devices SET status='unknown', rtt=NULL, down_since=NULL
            WHERE monitored=0 AND status<>'unknown'"));
$devs = db_retry(fn() => $pdo->query("SELECT id,name,ip,status,down_since,notified,last_check FROM devices
                     WHERE ip IS NOT NULL AND ip<>'' AND (monitored=1 OR monitored IS NULL)")->fetchAll());
if ($devs === null) { fwrite(STDERR, "Zoznam zariadení sa nepodarilo načítať (DB zamknutá) – cyklus preskočený\n"); exit(0); }
if (!$devs)         { fwrite(STDERR, "Žiadne monitorované zariadenia\n"); exit(0); }

// služby zoskupené podľa zariadenia
$svcRows = db_retry(fn() => $pdo->query("SELECT id,device_id,ptype,port FROM services WHERE enabled=1 OR enabled IS NULL")->fetchAll()) ?: [];
$svcByDev = [];
foreach ($svcRows as $r) $svcByDev[$r['device_id']][] = $r;

// hromadný ICMP ping
$alive = [];
if ($useFping) {
    $ips = array_values(array_unique(array_map(fn($d)=>$d['ip'], $devs)));
    $alive = fping_batch($ips, max(300, $pt*1000));
}

try {
$updDev  = $pdo->prepare('UPDATE devices SET status=?,rtt=?,last_check=?,down_since=?,notified=? WHERE id=?');
$updSvc  = $pdo->prepare('UPDATE services SET status=?,last_check=? WHERE id=?');
$hist    = $pdo->prepare('INSERT INTO status_history(device_id,ts,status,rtt) VALUES(?,?,?,?)');
$evt     = $pdo->prepare('INSERT INTO events(ts,device_id,device_name,ip,status,message) VALUES(?,?,?,?,?,?)');
$openOut = $pdo->prepare("SELECT id,started FROM outages WHERE device_id=? AND ended IS NULL ORDER BY id DESC LIMIT 1");
$newOut  = $pdo->prepare('INSERT INTO outages(device_id,service,started) VALUES(?,?,?)');
$closeOut= $pdo->prepare('UPDATE outages SET ended=?,duration=? WHERE id=?');
} catch (Throwable $e) {
    fwrite(STDERR, "Príprava dotazov zlyhala – cyklus preskočený: ".$e->getMessage()."\n"); exit(0);
}

$failed = 0; $tgFail = [];
foreach ($devs as $d) {
  try {
    $ip = $d['ip'];
    // 1) ICMP
    if ($useFping)    { $pingUp = array_key_exists($ip, $alive); $rtt = $pingUp ? $alive[$ip] : null; }
    elseif ($useIcmp) { @exec("ping -c 1 -W $pt ".escapeshellarg($ip), $o, $rc); $pingUp=($rc===0); $rtt=null; $o=[]; }
    else              { $pingUp=false; $rtt=null; }

    $reach = $pingUp;

    // 2) služby zariadenia (a zisti či niektorá TCP/SNMP odpovedá)
    foreach ($svcByDev[$d['id']] ?? [] as $sv) {
        switch ($sv['ptype']) {
            case 'tcp':  [$su,$srtt] = tcp_connect($ip, (int)$sv['port'], $tcpTo);
                         if ($su) { $reach = true; if ($rtt===null) $rtt=$srtt; } break;
            case 'dns':  $su = dns_check($ip); if ($su) $reach = true; break;
            case 'snmp': $su = snmp_check($ip, (int)$sv['port'] ?: 161); if ($su) $reach = true; break;
            case 'icmp': default: $su = $pingUp;
        }
        db_retry(fn() => $updSvc->execute([$su ? 'up' : 'down', $now, $sv['id']]));
    }

    // 3) posledná záchrana – fallback porty (winbox/web) ak nič neodpovedalo
    if (!$reach && $method === 'auto') {
        [$tu,$trtt] = tcp_reach($ip, $fallbackPorts, $tcpTo);
        if ($tu) { $reach = true; if ($rtt===null) $rtt=$trtt; }
    }

    // ---- trojstavová logika ----
    $prev = $d['status']; $downSince = $d['down_since'];
    // posledný OZNÁMENÝ stav (spätná kompatibilita, ak stĺpec ešte prázdny)
    $notified = !empty($d['notified']) ? $d['notified'] : (($prev === 'down') ? 'down' : 'up');
    if ($reach) { $status='up'; $downSince=null; }
    else {
        if (!$downSince) $downSince = $now;
        $status = (($nowTs - strtotime($downSince)) >= $downAfter) ? 'down' : 'pending';
    }

    // rozhodnutie o notifikácii podľa POSLEDNE OZNÁMENÉHO stavu -> žiadne duplicity
    $doDown = ($status === 'down' && $notified !== 'down');
    $doUp   = ($status === 'up'   && $notified === 'down');
    $newNotified = $doDown ? 'down' : ($doUp ? 'up' : $notified);

    // NAJPRV zapíš stav (vrátane notified). Ak sa zápis nepodaril, notifikáciu nepošleme
    // a skúsi sa znova v ďalšom cykle – nikdy však nezhodíme celý beh.
    $written = db_retry(fn() => $updDev->execute([$status,$rtt,$now,$downSince,$newNotified,$d['id']]));
    if (!$written) { $failed++; continue; }
    // Zmenu stavu zapíš vždy; meranie odozvy len vzorkuj, inak história rastie donekonečna.
    $changed = ($status !== $prev);
    $sample = $histEvery === 0 || $changed;
    if (!$sample && !empty($d['last_check'])) {
        $sample = intdiv($nowTs, $histEvery) !== intdiv((int)strtotime($d['last_check']), $histEvery);
    } elseif (!$sample) { $sample = true; }
    if ($sample) db_retry(fn() => $hist->execute([$d['id'],$now,$status,$rtt]));

    if ($doDown) {
        db_retry(fn() => $evt->execute([$now,$d['id'],$d['name'],$ip,'down',"Zariadenie: {$d['name']} IP:$ip; je nefunkčné"]));
        db_retry(function() use ($openOut,$newOut,$d,$downSince,$now) {
            if (!($openOut->execute([$d['id']]) && $openOut->fetch()))
                $newOut->execute([$d['id'],'ping',$downSince ?: $now]);
            return true;
        });
        if (!tg_notify_status($d['name'], $ip, 'down', $now)) $tgFail[] = [$d['id'], $notified];
    }
    if ($doUp) {
        db_retry(fn() => $evt->execute([$now,$d['id'],$d['name'],$ip,'up',"Zariadenie: {$d['name']} IP:$ip; je funkčné"]));
        db_retry(function() use ($openOut,$closeOut,$d,$now,$nowTs) {
            if ($openOut->execute([$d['id']]) && ($row = $openOut->fetch()))
                $closeOut->execute([$now, max(0,$nowTs-strtotime($row['started'])), $row['id']]);
            return true;
        });
        if (!tg_notify_status($d['name'], $ip, 'up', $now)) $tgFail[] = [$d['id'], $notified];
    }
  } catch (Throwable $e) {
    // chyba jedného zariadenia nesmie zhodiť celý cyklus
    $failed++; error_log('NetPulse monitor ['.($d['name'] ?? '?').']: '.$e->getMessage());
  }
}
// ---- hodinové upratovanie histórie (drží DB malú a rýchlu) ----
$lastPurge = (int) setting_get('last_purge_hist', '0');
if ($nowTs - $lastPurge > 3600) {
    setting_set('last_purge_hist', (string)$nowTs);
    $cut = date('Y-m-d H:i:s', $nowTs - $histDays * 86400);
    // po častiach, aby veľký DELETE nedržal zámok dlho
    for ($i = 0; $i < 20; $i++) {
        $n = db_retry(fn() => $pdo->exec("DELETE FROM status_history WHERE id IN
              (SELECT id FROM status_history WHERE ts < '$cut' LIMIT 20000)"));
        if (!$n) break;
        usleep(200000);
    }
    db_retry(fn() => $pdo->exec("DELETE FROM events WHERE ts < '"
        . date('Y-m-d H:i:s', $nowTs - 365*86400) . "'"));
    // staré session súbory (PHP ich vo vlastnom priečinku sám nemaže)
    $sdir = __DIR__ . '/data/sessions';
    if (is_dir($sdir)) {
        $cutS = $nowTs - 31*86400; $gone = 0;
        foreach ((glob($sdir . '/sess_*') ?: []) as $sf) {
            if (@filemtime($sf) < $cutS && @unlink($sf)) $gone++;
            if ($gone > 20000) break;
        }
    }
}

// Telegram zlyhal -> vráť posledný oznámený stav, nech sa správa pošle v ďalšom cykle
if ($tgFail) {
    $rv = $pdo->prepare('UPDATE devices SET notified=? WHERE id=?');
    foreach ($tgFail as [$did, $prevNotified]) db_retry(fn() => $rv->execute([$prevNotified, $did]));
}

$m = $useFping ? 'fping+tcp' : ($useIcmp ? 'ping+tcp' : 'tcp');
$jm = db_journal_mode();
$warn = ($jm !== 'wal' && cfg('DB_DRIVER') !== 'mysql') ? "  !! journal_mode=$jm (nie WAL – hrozia zámky)" : '';
fwrite(STDERR, count($devs) . " zariadení skontrolovaných ($m) @ $now"
    . ($failed ? "  [$failed preskočených]" : '')
    . ($tgFail ? "  [Telegram zlyhal ".count($tgFail)."x – skúsi znova]" : '') . $warn . "\n");
