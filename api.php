<?php
/** JSON API pre DudeWeb (chránené prihlásením). */
require __DIR__ . '/auth.php';
require __DIR__ . '/telegram.php';
require __DIR__ . '/snmp_lib.php';
require __DIR__ . '/probe_lib.php';
require_login(true);
header('Content-Type: application/json; charset=utf-8');
$pdo = db();
migrate();
$a = (string)($_GET['action'] ?? '');
// Akcie, ktoré len čítajú. Všetko ostatné mení dáta => len POST s hlavičkou X-NetPulse
// (cudzia stránka ju bez CORS povolenia poslať nevie – ochrana proti CSRF).
$readActions = ['maps','map','device','probes','ping_now','probe_now','devices','services','faults','events','summary',
                'live_status','link_types','snmp_profiles_list','snmp_interfaces','get_settings','timezones',
                'graph_sources','graph_data','device_types','whoami','client_config','users_list','backup'];
if (!in_array($a, $readActions, true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_NETPULSE'] ?? '') !== '1') {
        http_response_code(403); echo json_encode(['error'=>'Neplatná požiadavka']); exit;
    }
}
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
$nextId = fn(string $t) => (int)$pdo->query("SELECT COALESCE(MAX(id),1000000)+1 FROM $t")->fetchColumn();

// editačné akcie: len admin/administrator (user = len čítanie)
$editActions=['move','add_node','new_device','del_node','add_link','del_link','add_map',
              'move_map','reorder_maps','update_device','add_service','del_service',
              'toggle_service','toggle_monitor','update_link','snmp_profile_save','snmp_profile_delete','delete_device'];
if(in_array($a,$editActions,true)) require_role('admin');

/** Počty zariadení podľa mapy: total/up/down/unknown (pre submapy aj panel). */
function mapStats(PDO $pdo): array {
    $rows = $pdo->query(
        "SELECT n.map_id AS m,
           COUNT(*) total,
           SUM(CASE WHEN d.status='up' THEN 1 ELSE 0 END) up,
           SUM(CASE WHEN d.status='down' THEN 1 ELSE 0 END) down
         FROM map_nodes n JOIN devices d ON d.id=n.device_id
         WHERE n.kind='device' GROUP BY n.map_id")->fetchAll();
    $s = [];
    foreach ($rows as $r) $s[$r['m']] = ['total'=>(int)$r['total'],'up'=>(int)$r['up'],'down'=>(int)$r['down']];
    return $s;
}

try {

/** Súhrn stavov pre horný panel – vypnutý monitoring sa ráta ako „neznáme". */
function np_summary(PDO $pdo): array {
    return $pdo->query(
        "SELECT COUNT(*) total,
           SUM(CASE WHEN COALESCE(monitored,1)=1 AND status='up' THEN 1 ELSE 0 END) up,
           SUM(CASE WHEN COALESCE(monitored,1)=1 AND status='pending' THEN 1 ELSE 0 END) pending,
           SUM(CASE WHEN COALESCE(monitored,1)=1 AND status='down' THEN 1 ELSE 0 END) down,
           SUM(CASE WHEN COALESCE(monitored,1)=0 OR status='unknown' OR status IS NULL THEN 1 ELSE 0 END) unknown
         FROM devices")->fetch();
}

/** Obnova SQLite zálohy BEZ prepísania súboru pod bežiacimi procesmi (monitor, SNMP poller
 *  majú DB otvorenú vo WAL – prepis súboru by ju mohol poškodiť). Tabuľky sa skopírujú
 *  z pripojenej zálohy v jednej transakcii, takže ostatné procesy len chvíľu počkajú. */
function np_restore_sqlite(PDO $pdo, string $file): array {
    try {
        $chk = new PDO('sqlite:' . $file); $chk->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $integ = (string)$chk->query('PRAGMA quick_check')->fetchColumn();
        $hasDev = (int)$chk->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='devices'")->fetchColumn();
        $chk = null;
    } catch (Throwable $e) { return ['error'=>'Súbor zálohy sa nedá otvoriť']; }
    if ($integ !== 'ok') return ['error'=>'Záloha je poškodená (integrity check zlyhal)'];
    if (!$hasDev)        return ['error'=>'Súbor nie je záloha NetPulse (chýba tabuľka devices)'];

    $dst = cfg('SQLITE_PATH'); $pre = $dst . '.before-restore';
    @unlink($pre);
    try { $pdo->exec('VACUUM INTO ' . $pdo->quote($pre)); } catch (Throwable $e) { error_log('NetPulse restore – záloha pred obnovou: '.$e->getMessage()); }

    $pdo->exec('ATTACH DATABASE ' . $pdo->quote($file) . ' AS src');
    try {
        $ok = db_tx(function (PDO $pdo) {
            $tables = $pdo->query("SELECT name FROM src.sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
                          ->fetchAll(PDO::FETCH_COLUMN);
            // používateľov prevezmi len ak záloha obsahuje administrátora s heslom (inak by sa nikto neprihlásil
            // alebo by vznikol predvolený admin/admin)
            $usersOk = false;
            if (in_array('users', $tables, true)) {
                try { $usersOk = (int)$pdo->query("SELECT COUNT(*) FROM src.users WHERE role='administrator'
                                                    AND pass_hash IS NOT NULL AND pass_hash<>''")->fetchColumn() > 0; }
                catch (Throwable $e) { $usersOk = false; }
            }
            foreach ($tables as $t) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$t)) continue;
                if ($t === 'login_fail' || ($t === 'users' && !$usersOk)) continue;
                $exists = (int)$pdo->query("SELECT COUNT(*) FROM main.sqlite_master WHERE type='table' AND name=" . $pdo->quote($t))->fetchColumn();
                if (!$exists) {   // tabuľka zo zálohy, ktorú aktuálna DB nemá
                    $sql = (string)$pdo->query("SELECT sql FROM src.sqlite_master WHERE type='table' AND name=" . $pdo->quote($t))->fetchColumn();
                    if ($sql) $pdo->exec($sql);
                }
                $cm = array_column($pdo->query("PRAGMA main.table_info($t)")->fetchAll(), 'name');
                $cs = array_column($pdo->query("PRAGMA src.table_info($t)")->fetchAll(), 'name');
                $cols = array_values(array_intersect($cm, $cs));
                if (!$cols) continue;
                $list = implode(',', array_map(fn($c) => '"' . str_replace('"', '""', $c) . '"', $cols));
                $pdo->exec("DELETE FROM main.$t");
                $pdo->exec("INSERT INTO main.$t($list) SELECT $list FROM src.$t");
            }
            $pdo->exec('DELETE FROM main.notify_queue');   // staré neodoslané správy zo zálohy neposielaj
        }, 8);
    } catch (Throwable $e) {
        error_log('NetPulse restore: '.$e->getMessage());
        $ok = false;
    } finally {
        try { $pdo->exec('DETACH DATABASE src'); } catch (Throwable $e) {}
    }
    if (!$ok) return ['error'=>'Obnova zlyhala, databáza ostala nezmenená'];
    try { setting_set('schema_ver', '0'); } catch (Throwable $e) {}   // doplň prípadné nové stĺpce
    return ['ok'=>true];
}

/** Vypnutý monitoring: zariadenie zmizne z porúch, otvorený výpadok sa uzavrie
 *  a po opätovnom zapnutí sa prípadný výpadok nahlási odznova. */
function np_monitoring_off(PDO $pdo, int $id): void {
    $pdo->prepare("UPDATE devices SET status='unknown',rtt=NULL,down_since=NULL,down_ts=NULL,notified='up' WHERE id=?")->execute([$id]);
    $now = date('Y-m-d H:i:s');
    $st = $pdo->prepare('SELECT id,started,started_ts FROM outages WHERE device_id=? AND ended IS NULL');
    $st->execute([$id]);
    $cl = $pdo->prepare('UPDATE outages SET ended=?,duration=? WHERE id=?');
    foreach ($st->fetchAll() as $o) { $s = np_epoch($o['started_ts'], $o['started']); $cl->execute([$now, $s ? max(0, time() - $s) : null, $o['id']]); }
}

    switch ($a) {

    case 'maps':
        $stats = mapStats($pdo);
        $maps = $pdo->query('SELECT id,name FROM maps ORDER BY COALESCE(sort_order,999999), name')->fetchAll();
        foreach ($maps as &$m) {
            $st = $stats[$m['id']] ?? ['total'=>0,'up'=>0,'down'=>0];
            $m['nodes'] = $st['total']; $m['down'] = $st['down'];
        }
        echo json_encode($maps); break;

    case 'map':
        $id = (int)($_GET['id'] ?? 0);
        $stats = mapStats($pdo);
        $nq = $pdo->prepare(
            "SELECT n.id,n.x,n.y,n.kind,n.device_id,n.submap_id,n.label,
                    d.name dev_name,d.ip,d.status,d.rtt,dt.icon
             FROM map_nodes n
             LEFT JOIN devices d ON d.id=n.device_id
             LEFT JOIN device_types dt ON dt.id=d.type_id
             WHERE n.map_id=?");
        $nq->execute([$id]);
        $nodes = $nq->fetchAll();
        foreach ($nodes as &$n) {
            if ($n['kind'] === 'submap') {
                $sm = $pdo->query('SELECT name FROM maps WHERE id=' . (int)$n['submap_id'])->fetchColumn();
                $st = $stats[$n['submap_id']] ?? ['total'=>0,'up'=>0,'down'=>0];
                $n['sub_name'] = $sm ?: $n['label'];
                $n['sub_total'] = $st['total']; $n['sub_down'] = $st['down'];
                $n['status'] = $st['down'] > 0 ? 'down' : ($st['total'] ? 'up' : 'unknown');
            }
        }
        $lq = $pdo->prepare('SELECT l.id,l.from_node,l.to_node,l.width,l.style,l.thickness,l.ltype,l.label,l.snmp_device,l.snmp_ifindex,t.rx_bps,t.tx_bps,t.speed_bps,t.ts,t.ts_unix FROM map_links l LEFT JOIN link_traffic t ON t.link_id=l.id WHERE l.map_id=?');
        $lq->execute([$id]);
        $links = $lq->fetchAll();
        // zastaraný tok (zariadenie neodpovedá na SNMP) nezobrazuj ako aktuálny
        $stale = max(120, 3 * (int) setting_get('snmp_interval', 30)); $tnow = time();
        foreach ($links as &$lk) {
            $t = np_epoch($lk['ts_unix'] ?? null, $lk['ts'] ?? null);
            if ($t !== null && $tnow - $t > $stale) { $lk['rx_bps'] = null; $lk['tx_bps'] = null; }
            unset($lk['ts'], $lk['ts_unix']);
        }
        unset($lk);
        echo json_encode([
            'map'   => $pdo->query('SELECT id,name FROM maps WHERE id=' . $id)->fetch(),
            'nodes' => $nodes, 'links' => $links,
        ]); break;

    case 'device':
        $id = (int)($_GET['id'] ?? 0);
        $dev = $pdo->query(
            "SELECT d.*,dt.name type_name,dt.icon FROM devices d
             LEFT JOIN device_types dt ON dt.id=d.type_id WHERE d.id=$id")->fetch();
        $sv = $pdo->prepare('SELECT id,name,ptype,port,status,down,enabled FROM services WHERE device_id=? ORDER BY name');
        $sv->execute([$id]);
        $hi = $pdo->prepare('SELECT ts,rtt,status FROM status_history WHERE device_id=? ORDER BY ts DESC LIMIT 300');
        $hi->execute([$id]);
        $ou = $pdo->prepare('SELECT service,started,ended,duration FROM outages WHERE device_id=? ORDER BY id DESC LIMIT 100');
        $ou->execute([$id]);
        if ($dev && !has_role('admin')) { unset($dev['password'], $dev['username']); }   // prihlasovacie údaje len editorom
        echo json_encode([
            'device'=>$dev,'services'=>$sv->fetchAll(),
            'history'=>array_reverse($hi->fetchAll()),'outages'=>$ou->fetchAll()]); break;

    case 'probes':
        echo json_encode($pdo->query('SELECT id,name,type,port FROM probes ORDER BY name')->fetchAll()); break;

    case 'update_device':
        $ipIn = trim((string)($in['ip'] ?? '')); $dnsIn = trim((string)($in['dns'] ?? ''));
        if ($ipIn !== '' && !np_valid_host($ipIn))  { echo json_encode(['error'=>'Neplatná IP adresa']); break; }
        if ($dnsIn !== '' && !np_valid_host($dnsIn)) { echo json_encode(['error'=>'Neplatný DNS názov']); break; }
        if (trim((string)($in['name'] ?? '')) === '') { echo json_encode(['error'=>'Názov je povinný']); break; }
        $mon = empty($in['monitored']) ? 0 : 1;
        $pdo->prepare('UPDATE devices SET name=?,ip=?,dns=?,type_id=?,username=?,password=?,monitored=?,snmp_profile=? WHERE id=?')
            ->execute([trim($in['name']),trim($in['ip'] ?? ''),trim($in['dns'] ?? ''),
                       $in['type_id'] ?: null,trim($in['username'] ?? ''),(string)($in['password'] ?? ''),$mon,
                       (isset($in['snmp_profile'])&&$in['snmp_profile']!=='')?(int)$in['snmp_profile']:null,(int)$in['id']]);
        if (!$mon) np_monitoring_off($pdo, (int)$in['id']);
        echo json_encode(['ok'=>true]); break;

    case 'delete_device':
        // zmaže zariadenie zo všetkých máp aj z monitoringu (aj keď na žiadnej mape nie je)
        $did = (int)($in['id'] ?? 0);
        if ($did <= 0) { echo json_encode(['error'=>'Chýba zariadenie']); break; }
        $ok = db_tx(function (PDO $pdo) use ($did) {
            $nodes = $pdo->prepare('SELECT id FROM map_nodes WHERE device_id=?'); $nodes->execute([$did]);
            $dl = $pdo->prepare('DELETE FROM map_links WHERE from_node=? OR to_node=?');
            foreach ($nodes->fetchAll(PDO::FETCH_COLUMN) as $nid) $dl->execute([$nid, $nid]);
            $pdo->prepare('DELETE FROM map_nodes WHERE device_id=?')->execute([$did]);
            np_delete_device($pdo, $did);
        });
        echo json_encode($ok ? ['ok'=>true] : ['error'=>'Databáza je zaneprázdnená – skús znova']); break;

    case 'toggle_monitor':
        $mon = empty($in['monitored']) ? 0 : 1;
        $pdo->prepare('UPDATE devices SET monitored=? WHERE id=?')->execute([$mon,(int)$in['id']]);
        if (!$mon) np_monitoring_off($pdo, (int)$in['id']);
        echo json_encode(['ok'=>true]); break;

    case 'add_service':
        $pr = $pdo->query('SELECT name,type,port FROM probes WHERE id=' . (int)$in['probe_id'])->fetch();
        if (!$pr) { echo json_encode(['error'=>'sonda nenájdená']); break; }
        $sid = $nextId('services');
        $pdo->prepare("INSERT INTO services(id,device_id,probe_id,ptype,port,name,enabled,down,acked,status)
                       VALUES(?,?,?,?,?,?,1,0,0,'unknown')")
            ->execute([$sid,(int)$in['device_id'],(int)$in['probe_id'],$pr['type'],$pr['port'],$pr['name']]);
        echo json_encode(['ok'=>true,'service'=>$sid]); break;

    case 'del_service':
        $pdo->prepare('DELETE FROM services WHERE id=?')->execute([(int)$in['service']]);
        echo json_encode(['ok'=>true]); break;

    case 'toggle_service':
        $pdo->prepare('UPDATE services SET enabled=? WHERE id=?')
            ->execute([(int)$in['enabled'],(int)$in['service']]);
        echo json_encode(['ok'=>true]); break;

    case 'ping_now':
        $ip = trim($_GET['ip'] ?? '');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) { echo json_encode(['error'=>'neplatná IP']); break; }
        $win = stripos(PHP_OS,'WIN')===0;
        $cmd = $win ? 'ping -n 3 '.escapeshellarg($ip) : 'ping -c 3 -W 1 '.escapeshellarg($ip);
        $out=[]; exec($cmd,$out,$rc);
        echo json_encode(['ok'=>$rc===0,'output'=>implode("\n",$out)]); break;

    case 'probe_now':
        // Okamžitý test zariadenia presne tak, ako ho robí monitor: ping + každá služba.
        // Nič nezapisuje – stav v mape aktualizuje monitor v najbližšom cykle.
        $id = (int)($_GET['id'] ?? 0);
        $st = $pdo->prepare('SELECT id,name,ip FROM devices WHERE id=?'); $st->execute([$id]); $dv = $st->fetch();
        if (!$dv) { echo json_encode(['error'=>'Zariadenie nenájdené']); break; }
        $ip = trim((string)$dv['ip']);
        if (!np_valid_host($ip)) { echo json_encode(['error'=>'Zariadenie nemá platnú IP adresu']); break; }
        $out = [];
        $pingOk = false; $pingMs = null;
        if (exec_allowed()) {
            if (fping_available()) { $al = fping_batch([$ip], 1000); if (array_key_exists($ip, $al)) { $pingOk = true; $pingMs = $al[$ip]; } }
            else { $o = []; @exec('ping -c 1 -W 1 '.escapeshellarg($ip).' 2>/dev/null', $o, $rc); $pingOk = ($rc === 0);
                   if ($pingOk && preg_match('/time[=<]([\d.]+)/', implode("\n",$o), $m)) $pingMs = (float)$m[1]; }
            $out[] = ['check'=>'Ping (ICMP)', 'ok'=>$pingOk, 'ms'=>$pingMs];
        }
        $sv = $pdo->prepare('SELECT id,name,ptype,port,enabled FROM services WHERE device_id=? ORDER BY name'); $sv->execute([$id]);
        $svcs = $sv->fetchAll(); $tcp = [];
        foreach ($svcs as $s) if ($s['ptype'] === 'tcp') $tcp['s'.$s['id']] = [$ip, (int)$s['port']];
        $tcpOk = $tcp ? tcp_probe_batch($tcp, 2.0) : [];
        foreach ($svcs as $s) {
            $label = $s['name'] . ' (' . strtoupper((string)$s['ptype']) . ($s['port'] ? ' '.$s['port'] : '') . ')';
            switch ($s['ptype']) {
                case 'tcp':  $ok = isset($tcpOk['s'.$s['id']]); $ms = $tcpOk['s'.$s['id']] ?? null; break;
                case 'dns':  $ok = dns_check($ip); $ms = null; break;
                case 'snmp': $ok = snmp_check($ip, (int)$s['port'] ?: 161); $ms = null; break;
                default:     $ok = $pingOk; $ms = $pingMs;
            }
            $out[] = ['check'=>$label, 'ok'=>$ok, 'ms'=>$ms, 'service'=>true, 'enabled'=>(string)$s['enabled'] !== '0'];
        }
        echo json_encode(['ok'=>true, 'results'=>$out]); break;

    case 'devices':
        $q = '%' . ($_GET['q'] ?? '') . '%';
        $s = $pdo->prepare(
            "SELECT d.id,d.name,d.ip,d.status,dt.name type_name FROM devices d
             LEFT JOIN device_types dt ON dt.id=d.type_id
             WHERE d.name LIKE ? OR d.ip LIKE ? ORDER BY d.name LIMIT 200");
        $s->execute([$q, $q]);
        echo json_encode($s->fetchAll()); break;

    case 'services':
        echo json_encode($pdo->query(
            "SELECT s.id,s.name,s.status,s.down,s.device_id,s.ptype,s.port,s.enabled,d.name dev_name,d.ip
             FROM services s JOIN devices d ON d.id=s.device_id
             ORDER BY (s.status='down') DESC, d.name LIMIT 500")->fetchAll()); break;

    case 'faults':
        $rows = $pdo->query(
            "SELECT id,name,ip,status,last_check,rtt,down_since FROM devices
             WHERE status='down' AND (monitored=1 OR monitored IS NULL)
             ORDER BY down_since")->fetchAll();
        $t = time();
        foreach ($rows as &$r) $r['duration'] = $r['down_since'] ? max(0, $t - strtotime($r['down_since'])) : null;
        unset($r);
        echo json_encode($rows); break;

    case 'events':
        echo json_encode($pdo->query(
            'SELECT ts,device_name,ip,status,message FROM events ORDER BY ts DESC LIMIT 100')->fetchAll()); break;

    case 'summary':
        $r = np_summary($pdo);
        $r['maps'] = (int)$pdo->query('SELECT COUNT(*) FROM maps')->fetchColumn();
        $r['services'] = (int)$pdo->query('SELECT COUNT(*) FROM services')->fetchColumn();
        echo json_encode($r); break;

    case 'live_status':
        // rýchle prefarbenie: stavy zariadení danej mapy + súhrn (žltá/zelená/červená)
        $id = (int)($_GET['map'] ?? 0);
        $rows = $pdo->query("SELECT DISTINCT d.id,d.status FROM devices d
                             JOIN map_nodes n ON n.device_id=d.id WHERE n.map_id=$id")->fetchAll();
        $devs = []; foreach ($rows as $r) $devs[$r['id']] = $r['status'];
        $sum = np_summary($pdo);
        $mp = mapStats($pdo);
        echo json_encode(['devices'=>$devs,'summary'=>$sum,'mapstats'=>$mp]); break;

    // ---- editovanie ----
    case 'move':
        $pdo->prepare('UPDATE map_nodes SET x=?,y=? WHERE id=?')
            ->execute([(int)$in['x'],(int)$in['y'],(int)$in['node']]);
        echo json_encode(['ok'=>true]); break;

    case 'add_node':
        $nid = $nextId('map_nodes');
        $pdo->prepare("INSERT INTO map_nodes(id,map_id,kind,device_id,x,y,label) VALUES(?,?,'device',?,?,?,'')")
            ->execute([$nid,(int)$in['map'],(int)$in['device_id'],(int)$in['x'],(int)$in['y']]);
        echo json_encode(['ok'=>true,'node'=>$nid]); break;

    case 'new_device':
        if (trim((string)($in['ip'] ?? '')) !== '' && !np_valid_host((string)$in['ip'])) { echo json_encode(['error'=>'Neplatná IP adresa']); break; }
        $did = $nextId('devices');
        $pdo->prepare("INSERT INTO devices(id,name,ip,type_id,status) VALUES(?,?,?,?,'unknown')")
            ->execute([$did,trim($in['name']),trim($in['ip'] ?? ''),$in['type_id'] ?? null]);
        $nid = $nextId('map_nodes');
        $pdo->prepare("INSERT INTO map_nodes(id,map_id,kind,device_id,x,y,label) VALUES(?,?,'device',?,?,?,'')")
            ->execute([$nid,(int)$in['map'],$did,(int)$in['x'],(int)$in['y']]);
        echo json_encode(['ok'=>true,'node'=>$nid,'device_id'=>$did]); break;

    case 'del_node':
        // Zmazanie ikony z mapy. Ak to bol POSLEDNÝ výskyt zariadenia, odstráni sa aj zariadenie –
        // inak by ostalo neviditeľne v databáze a monitorovalo by sa (a hlásilo výpadky) ďalej.
        $n = (int)$in['node'];
        $st = $pdo->prepare('SELECT device_id FROM map_nodes WHERE id=?'); $st->execute([$n]);
        $devId = (int)($st->fetchColumn() ?: 0);
        $devDeleted = false;
        $ok = db_tx(function (PDO $pdo) use ($n, $devId, &$devDeleted) {
            $devDeleted = false;
            $pdo->prepare('DELETE FROM map_links WHERE from_node=? OR to_node=?')->execute([$n,$n]);
            $pdo->prepare('DELETE FROM map_nodes WHERE id=?')->execute([$n]);
            if ($devId) {
                $c = $pdo->prepare('SELECT COUNT(*) FROM map_nodes WHERE device_id=?'); $c->execute([$devId]);
                if ((int)$c->fetchColumn() === 0) { np_delete_device($pdo, $devId); $devDeleted = true; }
            }
        });
        echo json_encode($ok ? ['ok'=>true,'device_deleted'=>$devDeleted] : ['error'=>'Databáza je zaneprázdnená – skús znova']); break;

    case 'add_link':
        $lid = $nextId('map_links');
        $pdo->prepare('INSERT INTO map_links(id,map_id,from_node,to_node,width) VALUES(?,?,?,?,1)')
            ->execute([$lid,(int)$in['map'],(int)$in['from'],(int)$in['to']]);
        echo json_encode(['ok'=>true,'link'=>$lid]); break;

    case 'del_link':
        $pdo->prepare('DELETE FROM map_links WHERE id=?')->execute([(int)$in['link']]);
        echo json_encode(['ok'=>true]); break;

    case 'add_map':
        $mid = $nextId('maps');
        $pdo->prepare('INSERT INTO maps(id,name) VALUES(?,?)')->execute([$mid,trim($in['name'])]);
        echo json_encode(['ok'=>true,'map'=>$mid]); break;

    case 'move_map':
        // presun mapy hore/dole; prepočíta sort_order všetkých máp
        $dir = ($in['dir'] ?? 'up') === 'down' ? 1 : -1;
        $mid = (int)$in['map'];
        $list = $pdo->query('SELECT id FROM maps ORDER BY COALESCE(sort_order,999999), name')->fetchAll(PDO::FETCH_COLUMN);
        $i = array_search($mid, $list);
        if ($i !== false) {
            $j = $i + $dir;
            if ($j >= 0 && $j < count($list)) { $t=$list[$i]; $list[$i]=$list[$j]; $list[$j]=$t; }
        }
        $u = $pdo->prepare('UPDATE maps SET sort_order=? WHERE id=?');
        foreach ($list as $k=>$id) $u->execute([$k*10, $id]);
        echo json_encode(['ok'=>true]); break;

    case 'reorder_maps':
        // uloží nové poradie máp podľa poľa ID
        $order = $in['order'] ?? [];
        if (is_array($order)) {
            $u = $pdo->prepare('UPDATE maps SET sort_order=? WHERE id=?');
            foreach ($order as $k=>$id) $u->execute([$k*10, (int)$id]);
        }
        echo json_encode(['ok'=>true]); break;

    case 'link_types':
        echo json_encode($pdo->query('SELECT id,name,style,thickness FROM link_types ORDER BY thickness DESC, name')->fetchAll()); break;

    case 'update_link':
        $lid=(int)($in['link']??0);
        if(isset($in['type_id']) && $in['type_id']!==''){
            $t=$pdo->query('SELECT name,style,thickness FROM link_types WHERE id='.(int)$in['type_id'])->fetch();
            if($t) $pdo->prepare('UPDATE map_links SET ltype=?,style=?,thickness=? WHERE id=?')
                       ->execute([$t['name'],$t['style'],$t['thickness'],$lid]);
        }
        if(array_key_exists('snmp_device',$in)){
            $sd=$in['snmp_device']?(int)$in['snmp_device']:null;
            $si=(isset($in['snmp_ifindex'])&&$in['snmp_ifindex']!=='')?(int)$in['snmp_ifindex']:null;
            $pdo->prepare('UPDATE map_links SET snmp_device=?,snmp_ifindex=?,snmp_type=? WHERE id=?')
                ->execute([$sd,$si,($sd&&$si)?1:0,$lid]);
        }
        echo json_encode(['ok'=>true]); break;

    case 'snmp_profiles_list':
        // community a v3 heslá sú prístupové údaje k sieti – vidí ich len administrator
        $cols = has_role('administrator') ? 'id,name,community,version,port,sec_name,auth_pass,priv_pass,auth_proto,priv_proto'
                                          : 'id,name,version,port';
        echo json_encode($pdo->query("SELECT $cols FROM snmp_profiles ORDER BY name")->fetchAll()); break;

    case 'snmp_profile_save':
        require_role('administrator');
        $id=(isset($in['id'])&&$in['id'])?(int)$in['id']:$nextId('snmp_profiles');
        $ver=(int)($in['version']??0);
        if (!in_array($ver, [0,1,2], true)) { echo json_encode(['error'=>'Neplatná verzia SNMP']); break; }
        $in['auth_proto'] = snmp_auth_proto($in['auth_proto'] ?? 'MD5');   // len povolené hodnoty (idú do príkazu snmpget)
        $in['priv_proto'] = snmp_priv_proto($in['priv_proto'] ?? 'DES');
        $port = (int)($in['port'] ?? 161); if ($port < 1 || $port > 65535) $port = 161; $in['port'] = $port;
        $repl2 = cfg('DB_DRIVER')==='mysql' ? 'REPLACE INTO' : 'INSERT OR REPLACE INTO';
        $pdo->prepare("$repl2 snmp_profiles(id,name,community,version,port,sec_name,auth_pass,priv_pass,auth_proto,priv_proto) VALUES(?,?,?,?,?,?,?,?,?,?)")
            ->execute([$id,trim($in['name']??''),trim($in['community']??'public'),$ver,(int)($in['port']??161)?:161,
                       trim($in['sec_name']??''),trim($in['auth_pass']??''),trim($in['priv_pass']??''),
                       trim($in['auth_proto']??'MD5'),trim($in['priv_proto']??'DES')]);
        echo json_encode(['ok'=>true,'id'=>$id]); break;

    case 'snmp_profile_delete':
        require_role('administrator');
        $pdo->prepare('DELETE FROM snmp_profiles WHERE id=?')->execute([(int)$in['id']]);
        echo json_encode(['ok'=>true]); break;

    case 'snmp_interfaces':
        $id=(int)($_GET['device_id']??0);
        $dev=$pdo->query("SELECT d.ip,sp.community,sp.version,sp.sec_name,sp.auth_pass,sp.priv_pass,sp.auth_proto,sp.priv_proto
                          FROM devices d LEFT JOIN snmp_profiles sp ON sp.id=d.snmp_profile WHERE d.id=$id")->fetch();
        if(!$dev||!$dev['ip']){ echo json_encode(['error'=>'Zariadenie nemá IP']); break; }
        if($dev['version']===null || (int)$dev['version']<0){ echo json_encode(['error'=>'Zariadenie nemá SNMP profil – nastav ho v okne zariadenia → SNMP']); break; }
        $prof=['version'=>(int)$dev['version'],'community'=>$dev['community'],'sec_name'=>$dev['sec_name'],
               'auth_pass'=>$dev['auth_pass'],'priv_pass'=>$dev['priv_pass'],'auth_proto'=>$dev['auth_proto'],'priv_proto'=>$dev['priv_proto']];
        $names=snmp_walk($dev['ip'],$prof,'1.3.6.1.2.1.31.1.1.1.1');   // ifName
        if(!$names) $names=snmp_walk($dev['ip'],$prof,'1.3.6.1.2.1.2.2.1.2'); // ifDescr
        if(!$names){ echo json_encode(['error'=>'Zariadenie neodpovedá na SNMP (skontroluj community/verziu/firewall)']); break; }
        $out=[]; foreach($names as $idx=>$nm) $out[]=['idx'=>(int)$idx,'name'=>$nm];
        usort($out,fn($a,$b)=>$a['idx']-$b['idx']);
        echo json_encode($out); break;

    case 'get_settings':
        require_role('administrator');
        echo json_encode(['tg_enabled'=>setting_get('tg_enabled','0'),
            'tg_token'=>setting_get('tg_token',''), 'tg_chat'=>setting_get('tg_chat',''),
            'snmp_interval'=>setting_get('snmp_interval','30'), 'map_refresh'=>setting_get('map_refresh','10'),
            'history_days'=>setting_get('history_days',(string)(cfg('HISTORY_DAYS') ?: 14)),
            'history_every'=>setting_get('history_every',(string)(cfg('HISTORY_EVERY') ?: 60)),
            'traffic_days'=>setting_get('traffic_days',(string)(cfg('TRAFFIC_DAYS') ?: 90)),
            'timezone'=>setting_get('timezone',''), 'timezone_eff'=>date_default_timezone_get(),
            'timezone_sys'=>np_system_tz(), 'now'=>date('Y-m-d H:i:s T')]); break;

    case 'timezones':
        require_role('administrator');
        echo json_encode(np_tz_list()); break;

    case 'save_settings':
        require_role('administrator');
        if(array_key_exists('tg_enabled',$in)) setting_set('tg_enabled', !empty($in['tg_enabled'])?'1':'0');
        if(array_key_exists('tg_token',$in)) setting_set('tg_token', trim($in['tg_token'] ?? ''));
        if(array_key_exists('tg_chat',$in)) setting_set('tg_chat', trim($in['tg_chat'] ?? ''));
        if(array_key_exists('tg_token',$in) || array_key_exists('tg_chat',$in) || array_key_exists('tg_enabled',$in)) {
            setting_set('tg_block_until', '0'); setting_set('tg_fail_streak', '0');
        }
        if(array_key_exists('snmp_interval',$in)) setting_set('snmp_interval', max(3,(int)$in['snmp_interval']));
        if(array_key_exists('map_refresh',$in)) setting_set('map_refresh', max(2,(int)$in['map_refresh']));
        if(isset($in['history_days'])  && is_numeric($in['history_days'])  && (int)$in['history_days'] >= 1)  setting_set('history_days',  (int)$in['history_days']);
        if(isset($in['history_every']) && is_numeric($in['history_every']) && (int)$in['history_every'] >= 0) setting_set('history_every', (int)$in['history_every']);
        if(isset($in['traffic_days'])  && is_numeric($in['traffic_days'])  && (int)$in['traffic_days'] >= 1)  setting_set('traffic_days',  (int)$in['traffic_days']);
        if(array_key_exists('timezone',$in)) {
            $tz = trim((string)$in['timezone']);
            if ($tz !== '' && !np_tz_valid($tz)) {
                echo json_encode(['error'=>'Neznáme časové pásmo']); break;
            }
            setting_set('timezone', $tz);          // prázdne = automaticky zo servera
            $eff = $tz !== '' ? $tz : np_system_tz();
            if (!np_tz_valid($eff)) $eff = 'UTC';
            @date_default_timezone_set($eff);
        }
        echo json_encode(['ok'=>true]); break;

    case 'test_telegram':
        require_role('administrator');
        $token=trim($in['tg_token'] ?? setting_get('tg_token',''));
        $chat=trim($in['tg_chat'] ?? setting_get('tg_chat',''));
        if($token===''||$chat===''){ echo json_encode(['error'=>'Zadaj token aj chat ID']); break; }
        $r=tg_send($token,$chat,'✅ NetPulse test — Telegram funguje.');
        if(!empty($r['ok'])){ setting_set('tg_block_until','0'); setting_set('tg_fail_streak','0'); }
        echo json_encode($r['ok']?['ok'=>true]:['error'=>'Nepodarilo sa: '.substr((string)($r['resp']??$r['err']??'chyba'),0,200)]); break;

    case 'graph_sources':
        $rows=$pdo->query("SELECT l.id, l.snmp_ifindex ifidx, md.name master,
            (SELECT dn.name FROM map_nodes nf JOIN devices dn ON dn.id=nf.device_id WHERE nf.id=l.from_node) fromname,
            (SELECT dn.name FROM map_nodes nt JOIN devices dn ON dn.id=nt.device_id WHERE nt.id=l.to_node) toname
            FROM map_links l JOIN devices md ON md.id=l.snmp_device
            WHERE l.snmp_type=1 AND l.snmp_ifindex>0")->fetchAll();
        $seen=[]; $out=[];
        foreach($rows as $r){
            $name = ($r['fromname']&&$r['toname']) ? ($r['fromname'].' ↔ '.$r['toname'])
                    : ($r['master'].' – ifIndex '.$r['ifidx']);
            $out[]=['id'=>$r['id'],'name'=>$name,'sub'=>$r['master'].' · if'.$r['ifidx']];
        }
        usort($out,fn($a,$b)=>strcasecmp($a['name'],$b['name']));
        echo json_encode($out); break;

    case 'graph_data':
        $id=(int)($_GET['id']??0);
        $range=$_GET['range']??'day';
        $secs=['hour'=>3600,'day'=>86400,'week'=>604800,'month'=>2592000,'year'=>31536000][$range]??86400;
        $from=date('Y-m-d H:i:s', time()-$secs);
        $q=$pdo->prepare('SELECT ts,rx_bps,tx_bps FROM traffic_history WHERE link_id=? AND ts>=? ORDER BY ts');
        $q->execute([$id,$from]);
        $rows=$q->fetchAll();
        // bucketovanie na ~120 bodov (priemer)
        $nb=120; $t0=time()-$secs; $bw=$secs/$nb;
        $buck=[];
        foreach($rows as $r){ $t=strtotime($r['ts']); $bi=(int)(($t-$t0)/$bw); if($bi<0||$bi>=$nb)continue;
            if(!isset($buck[$bi]))$buck[$bi]=['rx'=>0,'tx'=>0,'n'=>0];
            $buck[$bi]['rx']+=(float)$r['rx_bps']; $buck[$bi]['tx']+=(float)$r['tx_bps']; $buck[$bi]['n']++; }
        $pts=[];
        for($i=0;$i<$nb;$i++){ if(isset($buck[$i])&&$buck[$i]['n']>0){
            $pts[]=['t'=>(int)($t0+$i*$bw),'rx'=>$buck[$i]['rx']/$buck[$i]['n'],'tx'=>$buck[$i]['tx']/$buck[$i]['n']]; } }
        echo json_encode(['points'=>$pts]); break;

    case 'device_types':
        echo json_encode($pdo->query('SELECT id,name,icon FROM device_types ORDER BY name')->fetchAll()); break;

    case 'change_password':
        $u = current_user();
        $st = $pdo->prepare('SELECT pass_hash FROM users WHERE username=?'); $st->execute([$u]);
        $h = $st->fetchColumn();
        if (!password_verify((string)($in['old'] ?? ''), (string)$h)) { echo json_encode(['error'=>'Staré heslo nesedí']); break; }
        if (!np_password_ok((string)($in['new'] ?? ''))) { echo json_encode(['error'=>'Heslo musí mať aspoň '.NP_MIN_PASSWORD.' znakov']); break; }
        $pdo->prepare('UPDATE users SET pass_hash=? WHERE username=?')
            ->execute([password_hash((string)$in['new'], PASSWORD_DEFAULT), $u]);
        session_regenerate_id(true);
        echo json_encode(['ok'=>true]); break;

    case 'whoami':
        echo json_encode(['user'=>current_user(),'role'=>current_role(),'default_pw'=>np_default_password_active()]); break;

    case 'client_config':
        echo json_encode(['map_refresh'=>(int)setting_get('map_refresh',10)]); break;

    case 'users_list':
        require_role('admin');
        echo json_encode($pdo->query('SELECT id,username,role,created FROM users ORDER BY username')->fetchAll()); break;

    case 'user_add':
        require_role('admin');
        $u=trim($in['username']??''); $pw=(string)($in['password']??''); $role=$in['role']??'user';
        if($u===''||$pw===''){ echo json_encode(['error'=>'Meno aj heslo sú povinné']); break; }
        if(!np_password_ok($pw)){ echo json_encode(['error'=>'Heslo musí mať aspoň '.NP_MIN_PASSWORD.' znakov']); break; }
        if(!in_array($role,['user','admin','administrator'],true)) $role='user';
        if(!has_role('administrator') && $role!=='user'){ echo json_encode(['error'=>'Vyššiu rolu môže prideliť len administrator']); break; }
        try{ $pdo->prepare('INSERT INTO users(username,pass_hash,role,created) VALUES(?,?,?,?)')
                ->execute([$u,password_hash($pw,PASSWORD_DEFAULT),$role,date('Y-m-d H:i:s')]);
             echo json_encode(['ok'=>true]); }
        catch(Throwable $e){ echo json_encode(['error'=>'Používateľ už existuje']); }
        break;

    case 'user_delete':
        require_role('admin');
        $id=(int)($in['id']??0);
        if($id===current_uid()){ echo json_encode(['error'=>'Nemôžeš zmazať sám seba']); break; }
        $tg=$pdo->query('SELECT role FROM users WHERE id='.$id)->fetch();
        if(!$tg){ echo json_encode(['error'=>'Používateľ nenájdený']); break; }
        if($tg['role']==='administrator' && !has_role('administrator')){ echo json_encode(['error'=>'Administrátora môže mazať len administrator']); break; }
        if($tg['role']==='administrator' && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='administrator'")->fetchColumn()<=1){ echo json_encode(['error'=>'Musí zostať aspoň 1 administrator']); break; }
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
        echo json_encode(['ok'=>true]); break;

    case 'user_set_role':
        require_role('administrator');
        $id=(int)($in['id']??0); $role=$in['role']??'user';
        if(!in_array($role,['user','admin','administrator'],true)){ echo json_encode(['error'=>'Neplatná rola']); break; }
        $cur=$pdo->query('SELECT role FROM users WHERE id='.$id)->fetch();
        if($cur && $cur['role']==='administrator' && $role!=='administrator' && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='administrator'")->fetchColumn()<=1){ echo json_encode(['error'=>'Musí zostať aspoň 1 administrator']); break; }
        $pdo->prepare('UPDATE users SET role=? WHERE id=?')->execute([$role,$id]);
        echo json_encode(['ok'=>true]); break;

    case 'user_reset_password':
        require_role('administrator');
        $id=(int)($in['id']??0); $pw=(string)($in['new']??'');
        if($pw===''){ echo json_encode(['error'=>'Zadaj nové heslo']); break; }
        if(!np_password_ok($pw)){ echo json_encode(['error'=>'Heslo musí mať aspoň '.NP_MIN_PASSWORD.' znakov']); break; }
        $pdo->prepare('UPDATE users SET pass_hash=? WHERE id=?')->execute([password_hash($pw,PASSWORD_DEFAULT),$id]);
        echo json_encode(['ok'=>true]); break;

    case 'backup':
        // obsahuje hashe hesiel, heslá zariadení, SNMP údaje a Telegram token => len administrator
        require_role('administrator');
        if(cfg('DB_DRIVER')!=='sqlite'){ echo json_encode(['error'=>'Záloha cez web je pre SQLite; pri MySQL použi mysqldump']); break; }
        @set_time_limit(600);
        // Konzistentná kópia aj s dátami, ktoré sú ešte vo WAL (obyčajné kopírovanie súboru by ich vynechalo)
        $f = tempnam(sys_get_temp_dir(), 'npbak'); @unlink($f); $tmpUsed = true;
        try { $pdo->exec('VACUUM INTO '.$pdo->quote($f)); }
        catch (Throwable $e) {
            error_log('NetPulse backup VACUUM INTO: '.$e->getMessage());
            try { $pdo->exec('PRAGMA wal_checkpoint(FULL)'); } catch (Throwable $e2) {}
            $f = cfg('SQLITE_PATH'); $tmpUsed = false;
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="netpulse-backup-'.date('Y-m-d_His').'.db"');
        header('Content-Length: '.filesize($f));
        readfile($f); if ($tmpUsed) @unlink($f); exit;

    case 'restore':
        require_role('administrator');
        if(cfg('DB_DRIVER')!=='sqlite'){ echo json_encode(['error'=>'Obnova cez web je len pre SQLite']); break; }
        if(empty($_FILES['file']['tmp_name'])){ echo json_encode(['error'=>'Chýba súbor zálohy']); break; }
        $tmp=$_FILES['file']['tmp_name'];
        if(strpos((string)file_get_contents($tmp,false,null,0,16),'SQLite format 3')!==0){ echo json_encode(['error'=>'Neplatný súbor (nie je SQLite záloha)']); break; }
        @set_time_limit(600);
        $r = np_restore_sqlite($pdo, $tmp);
        if (!empty($r['ok'])) logout();   // používatelia sa mohli zmeniť – prihlás sa znova
        echo json_encode($r); break;

    case 'import_dude':
        require_role('administrator');
        if(empty($_FILES['file']['tmp_name'])){ echo json_encode(['error'=>'Chýba súbor dude.db']); break; }
        $tmp=$_FILES['file']['tmp_name'];
        if(strpos((string)file_get_contents($tmp,false,null,0,16),'SQLite format 3')!==0){ echo json_encode(['error'=>'Neplatný dude.db (nie je SQLite)']); break; }
        require_once __DIR__.'/importer.php';
        try{ echo json_encode(['ok'=>true,'summary'=>dude_import($tmp)]); }
        catch(Throwable $e){ error_log('NetPulse import: '.$e->getMessage()); echo json_encode(['error'=>'Import zlyhal: '.$e->getMessage()]); }
        break;

    default:
        http_response_code(400); echo json_encode(['error'=>'neznáma akcia']);
    }
} catch (Throwable $e) {
    error_log('NetPulse API ['.$a.']: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    http_response_code(500);
    echo json_encode(['error'=> db_is_locked($e) ? 'Databáza je práve zaneprázdnená – skús to znova'
                                                  : 'Interná chyba servera (podrobnosti v logu)']);
}
