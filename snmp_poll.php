<?php
/**
 * SNMP poller – načíta počítadlá bajtov na interface a spočíta reálny tok (Rx/Tx bps).
 * Pre každú linku so SNMP interface (z Dude) urobí SNMP GET ifHCInOctets/ifHCOutOctets
 * (fallback 32-bit ifInOctets/ifOutOctets), z rozdielu počítadiel v čase spočíta bity/s.
 *
 * Spúšťaj v slučke (systemd) každých ~30 s. Potrebuje net-snmp (`snmpget`) alebo php-snmp.
 * Verzie SNMP: profil version 0 = v1, 1 = v2c, -1 = bez SNMP (preskočí sa).
 */
require __DIR__ . '/db.php';
require __DIR__ . '/snmp_lib.php';
try { migrate(); } catch (Throwable $e) { error_log('NetPulse snmp migrate: '.$e->getMessage()); }

const OID_HC_IN  = '1.3.6.1.2.1.31.1.1.1.6';   // ifHCInOctets
const OID_HC_OUT = '1.3.6.1.2.1.31.1.1.1.10';  // ifHCOutOctets
const OID_IN     = '1.3.6.1.2.1.2.2.1.10';     // ifInOctets (32-bit)
const OID_OUT    = '1.3.6.1.2.1.2.2.1.16';     // ifOutOctets (32-bit)
const OID_HS     = '1.3.6.1.2.1.31.1.1.1.15';  // ifHighSpeed (Mbps)
const OID_SPEED  = '1.3.6.1.2.1.2.2.1.5';       // ifSpeed (bps)


function poll_once(){
$pdo = db();
$now = date('Y-m-d H:i:s'); $nowTs = time();

// linky so SNMP interface + master zariadenie + jeho profil
$links = $pdo->query(
    "SELECT l.id, l.snmp_ifindex ifidx, d.ip, sp.community, sp.version, sp.sec_name, sp.auth_pass, sp.priv_pass, sp.auth_proto, sp.priv_proto
     FROM map_links l
     JOIN devices d ON d.id = l.snmp_device
     LEFT JOIN snmp_profiles sp ON sp.id = d.snmp_profile
     WHERE l.snmp_type = 1 AND l.snmp_ifindex > 0 AND d.ip IS NOT NULL AND d.ip <> ''")->fetchAll();

$prev = [];
foreach ($pdo->query("SELECT link_id, in_oct, out_oct, ts FROM link_traffic")->fetchAll() as $r)
    $prev[$r['link_id']] = $r;

$up = $pdo->prepare(($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'REPLACE INTO':'INSERT OR REPLACE INTO')
    . ' link_traffic(link_id,in_oct,out_oct,ts,rx_bps,tx_bps,speed_bps) VALUES(?,?,?,?,?,?,?)');
$hist = $pdo->prepare('INSERT INTO traffic_history(link_id,ts,rx_bps,tx_bps) VALUES(?,?,?,?)');

$done = 0;
foreach ($links as $l) {
    if ($l['version'] === null || (int)$l['version'] < 0) continue;   // bez SNMP
    $idx = (int)$l['ifidx'];
    $prof = ['version'=>(int)$l['version'],'community'=>$l['community'],'sec_name'=>$l['sec_name'],
             'auth_pass'=>$l['auth_pass'],'priv_pass'=>$l['priv_pass'],'auth_proto'=>$l['auth_proto'],'priv_proto'=>$l['priv_proto']];
    [$in,$out,$hs] = snmp_get($l['ip'], $prof, [OID_HC_IN.".$idx", OID_HC_OUT.".$idx", OID_HS.".$idx"]);
    $speed = ($hs !== null && $hs > 0) ? $hs * 1e6 : null;
    if ($in === null || $out === null) {
        [$in,$out,$sp] = snmp_get($l['ip'], $prof, [OID_IN.".$idx", OID_OUT.".$idx", OID_SPEED.".$idx"]);
        if ($speed === null && $sp !== null && $sp > 0) $speed = $sp;
    }
    if ($in === null || $out === null) continue;   // nedostupné

    $rx = null; $tx = null;
    if (isset($prev[$l['id']])) {
        $p = $prev[$l['id']]; $dt = $nowTs - strtotime($p['ts']);
        if ($dt > 0) {
            $din = $in - $p['in_oct']; $dout = $out - $p['out_oct'];
            if ($din >= 0 && $dout >= 0) {              // bez pretečenia
                $rx = ($din * 8) / $dt;                 // bity/s
                $tx = ($dout * 8) / $dt;
            }
        }
    }
    db_retry(fn() => $up->execute([$l['id'], $in, $out, $now, $rx, $tx, $speed]));
    if ($rx !== null || $tx !== null) db_retry(fn() => $hist->execute([$l['id'], $now, $rx, $tx]));
    $done++;
}
// veľký DELETE drží zápisový zámok a blokuje monitor – stačí raz za hodinu
$lastPurge = (int) setting_get('last_purge', '0');
if ($nowTs - $lastPurge > 3600) {
    setting_set('last_purge', (string)$nowTs);
    db_retry(fn() => $pdo->exec("DELETE FROM traffic_history WHERE ts < '" . date('Y-m-d H:i:s', $nowTs - 90*86400) . "'"));
}
fwrite(STDERR, "$done SNMP liniek spracovaných @ $now" . (snmp_cli()?' (snmpget)':(function_exists('snmpget')?' (php-snmp)':' (SNMP nedostupné!)')) . "\n");
}

if (($argv[1] ?? '') === 'loop') {
    while (true) {
        try { poll_once(); } catch (Throwable $e) { error_log('NetPulse snmp: '.$e->getMessage()); }
        $iv = (int) setting_get('snmp_interval', 30); if ($iv < 3) $iv = 3; sleep($iv);
    }
} else {
    try { poll_once(); } catch (Throwable $e) { error_log('NetPulse snmp: '.$e->getMessage()); }
}
