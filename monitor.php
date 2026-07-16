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
migrate();

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

$pdo = db();
$now = date('Y-m-d H:i:s'); $nowTs = time();
$pt = (int) cfg('PING_TIMEOUT');
$tcpTo = (float) (cfg('TCP_TIMEOUT') ?: 1);
$downAfter = (int) (cfg('DOWN_AFTER') ?: 30);
$fallbackPorts = cfg('TCP_FALLBACK_PORTS') ?: [8291,80,443,22,23];
$method = cfg('CHECK_METHOD') ?: 'auto';
$useIcmp = ($method === 'icmp') || ($method === 'auto' && exec_allowed());
$useFping = $useIcmp && cfg('USE_FPING') && fping_available();

// vypnutý monitoring -> sivé (unknown), nepingovať
$pdo->exec("UPDATE devices SET status='unknown', rtt=NULL, down_since=NULL
            WHERE monitored=0 AND status<>'unknown'");
$devs = $pdo->query("SELECT id,name,ip,status,down_since,notified FROM devices
                     WHERE ip IS NOT NULL AND ip<>'' AND (monitored=1 OR monitored IS NULL)")->fetchAll();

// služby zoskupené podľa zariadenia
$svcRows = $pdo->query("SELECT id,device_id,ptype,port FROM services WHERE enabled=1 OR enabled IS NULL")->fetchAll();
$svcByDev = [];
foreach ($svcRows as $r) $svcByDev[$r['device_id']][] = $r;

// hromadný ICMP ping
$alive = [];
if ($useFping) {
    $ips = array_values(array_unique(array_map(fn($d)=>$d['ip'], $devs)));
    $alive = fping_batch($ips, max(300, $pt*1000));
}

$updDev  = $pdo->prepare('UPDATE devices SET status=?,rtt=?,last_check=?,down_since=?,notified=? WHERE id=?');
$updSvc  = $pdo->prepare('UPDATE services SET status=?,last_check=? WHERE id=?');
$hist    = $pdo->prepare('INSERT INTO status_history(device_id,ts,status,rtt) VALUES(?,?,?,?)');
$evt     = $pdo->prepare('INSERT INTO events(ts,device_id,device_name,ip,status,message) VALUES(?,?,?,?,?,?)');
$openOut = $pdo->prepare("SELECT id,started FROM outages WHERE device_id=? AND ended IS NULL ORDER BY id DESC LIMIT 1");
$newOut  = $pdo->prepare('INSERT INTO outages(device_id,service,started) VALUES(?,?,?)');
$closeOut= $pdo->prepare('UPDATE outages SET ended=?,duration=? WHERE id=?');

foreach ($devs as $d) {
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
        $updSvc->execute([$su ? 'up' : 'down', $now, $sv['id']]);
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

    // NAJPRV zapíš stav (vrátane notified). Ak zápis zlyhá, notifikáciu nepošleme.
    try {
        $updDev->execute([$status,$rtt,$now,$downSince,$newNotified,$d['id']]);
    } catch (Throwable $e) { continue; }
    $hist->execute([$d['id'],$now,$status,$rtt]);

    if ($doDown) {
        $evt->execute([$now,$d['id'],$d['name'],$ip,'down',"Zariadenie: {$d['name']} IP:$ip; je nefunkčné"]);
        if (!($openOut->execute([$d['id']]) && $openOut->fetch()))
            $newOut->execute([$d['id'],'ping',$downSince ?: $now]);
        tg_notify_status($d['name'], $ip, 'down', $now);
    }
    if ($doUp) {
        $evt->execute([$now,$d['id'],$d['name'],$ip,'up',"Zariadenie: {$d['name']} IP:$ip; je funkčné"]);
        if ($openOut->execute([$d['id']]) && ($row = $openOut->fetch())) {
            $closeOut->execute([$now, max(0,$nowTs-strtotime($row['started'])), $row['id']]);
        }
        tg_notify_status($d['name'], $ip, 'up', $now);
    }
}
$m = $useFping ? 'fping+tcp' : ($useIcmp ? 'ping+tcp' : 'tcp');
fwrite(STDERR, count($devs) . " zariadení skontrolovaných ($m) @ $now\n");
