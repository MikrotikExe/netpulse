<?php
/** JSON API pre DudeWeb (chránené prihlásením). */
require __DIR__ . '/auth.php';
require __DIR__ . '/telegram.php';
require __DIR__ . '/snmp_lib.php';
require_login(true);
header('Content-Type: application/json; charset=utf-8');
$pdo = db();
migrate();
$a = $_REQUEST['action'] ?? '';
$in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$nextId = fn(string $t) => (int)$pdo->query("SELECT COALESCE(MAX(id),1000000)+1 FROM $t")->fetchColumn();

// editačné akcie: len admin/administrator (user = len čítanie)
$editActions=['move','add_node','new_device','del_node','add_link','del_link','add_map',
              'move_map','reorder_maps','update_device','add_service','del_service',
              'toggle_service','toggle_monitor','update_link','snmp_profile_save','snmp_profile_delete'];
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
        $lq = $pdo->prepare('SELECT l.id,l.from_node,l.to_node,l.width,l.style,l.thickness,l.ltype,l.label,l.snmp_device,l.snmp_ifindex,t.rx_bps,t.tx_bps,t.speed_bps FROM map_links l LEFT JOIN link_traffic t ON t.link_id=l.id WHERE l.map_id=?');
        $lq->execute([$id]);
        echo json_encode([
            'map'   => $pdo->query('SELECT id,name FROM maps WHERE id=' . $id)->fetch(),
            'nodes' => $nodes, 'links' => $lq->fetchAll(),
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
        echo json_encode([
            'device'=>$dev,'services'=>$sv->fetchAll(),
            'history'=>array_reverse($hi->fetchAll()),'outages'=>$ou->fetchAll()]); break;

    case 'probes':
        echo json_encode($pdo->query('SELECT id,name,type,port FROM probes ORDER BY name')->fetchAll()); break;

    case 'update_device':
        $mon = empty($in['monitored']) ? 0 : 1;
        $pdo->prepare('UPDATE devices SET name=?,ip=?,dns=?,type_id=?,username=?,password=?,monitored=?,snmp_profile=? WHERE id=?')
            ->execute([trim($in['name']),trim($in['ip'] ?? ''),trim($in['dns'] ?? ''),
                       $in['type_id'] ?: null,trim($in['username'] ?? ''),(string)($in['password'] ?? ''),$mon,
                       (isset($in['snmp_profile'])&&$in['snmp_profile']!=='')?(int)$in['snmp_profile']:null,(int)$in['id']]);
        if (!$mon) $pdo->prepare("UPDATE devices SET status='unknown',rtt=NULL,down_since=NULL WHERE id=?")->execute([(int)$in['id']]);
        echo json_encode(['ok'=>true]); break;

    case 'toggle_monitor':
        $mon = empty($in['monitored']) ? 0 : 1;
        $pdo->prepare('UPDATE devices SET monitored=? WHERE id=?')->execute([$mon,(int)$in['id']]);
        if (!$mon) $pdo->prepare("UPDATE devices SET status='unknown',rtt=NULL,down_since=NULL WHERE id=?")->execute([(int)$in['id']]);
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
            "SELECT s.name,s.status,s.down,d.name dev_name,d.ip
             FROM services s JOIN devices d ON d.id=s.device_id
             ORDER BY (s.status='down') DESC, d.name LIMIT 500")->fetchAll()); break;

    case 'faults':
        echo json_encode($pdo->query(
            "SELECT id,name,ip,status,last_check,rtt FROM devices
             WHERE status='down' ORDER BY last_check DESC")->fetchAll()); break;

    case 'events':
        echo json_encode($pdo->query(
            'SELECT ts,device_name,ip,status,message FROM events ORDER BY ts DESC LIMIT 100')->fetchAll()); break;

    case 'summary':
        $r = $pdo->query(
            "SELECT COUNT(*) total,
               SUM(CASE WHEN status='up' THEN 1 ELSE 0 END) up,
               SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) pending,
               SUM(CASE WHEN status='down' THEN 1 ELSE 0 END) down,
               SUM(CASE WHEN status='unknown' OR status IS NULL THEN 1 ELSE 0 END) unknown
             FROM devices")->fetch();
        $r['maps'] = (int)$pdo->query('SELECT COUNT(*) FROM maps')->fetchColumn();
        $r['services'] = (int)$pdo->query('SELECT COUNT(*) FROM services')->fetchColumn();
        echo json_encode($r); break;

    case 'live_status':
        // rýchle prefarbenie: stavy zariadení danej mapy + súhrn (žltá/zelená/červená)
        $id = (int)($_GET['map'] ?? 0);
        $rows = $pdo->query("SELECT DISTINCT d.id,d.status FROM devices d
                             JOIN map_nodes n ON n.device_id=d.id WHERE n.map_id=$id")->fetchAll();
        $devs = []; foreach ($rows as $r) $devs[$r['id']] = $r['status'];
        $sum = $pdo->query("SELECT COUNT(*) total,
               SUM(CASE WHEN status='up' THEN 1 ELSE 0 END) up,
               SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) pending,
               SUM(CASE WHEN status='down' THEN 1 ELSE 0 END) down,
               SUM(CASE WHEN status='unknown' OR status IS NULL THEN 1 ELSE 0 END) unknown
             FROM devices")->fetch();
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
        $did = $nextId('devices');
        $pdo->prepare("INSERT INTO devices(id,name,ip,type_id,status) VALUES(?,?,?,?,'unknown')")
            ->execute([$did,trim($in['name']),trim($in['ip'] ?? ''),$in['type_id'] ?? null]);
        $nid = $nextId('map_nodes');
        $pdo->prepare("INSERT INTO map_nodes(id,map_id,kind,device_id,x,y,label) VALUES(?,?,'device',?,?,?,'')")
            ->execute([$nid,(int)$in['map'],$did,(int)$in['x'],(int)$in['y']]);
        echo json_encode(['ok'=>true,'node'=>$nid,'device_id'=>$did]); break;

    case 'del_node':
        $n = (int)$in['node'];
        $pdo->prepare('DELETE FROM map_links WHERE from_node=? OR to_node=?')->execute([$n,$n]);
        $pdo->prepare('DELETE FROM map_nodes WHERE id=?')->execute([$n]);
        echo json_encode(['ok'=>true]); break;

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
        echo json_encode($pdo->query('SELECT id,name,community,version,port,sec_name,auth_pass,priv_pass,auth_proto,priv_proto FROM snmp_profiles ORDER BY name')->fetchAll()); break;

    case 'snmp_profile_save':
        $id=(isset($in['id'])&&$in['id'])?(int)$in['id']:$nextId('snmp_profiles');
        $ver=(int)($in['version']??0);
        $repl2 = cfg('DB_DRIVER')==='mysql' ? 'REPLACE INTO' : 'INSERT OR REPLACE INTO';
        $pdo->prepare("$repl2 snmp_profiles(id,name,community,version,port,sec_name,auth_pass,priv_pass,auth_proto,priv_proto) VALUES(?,?,?,?,?,?,?,?,?,?)")
            ->execute([$id,trim($in['name']??''),trim($in['community']??'public'),$ver,(int)($in['port']??161)?:161,
                       trim($in['sec_name']??''),trim($in['auth_pass']??''),trim($in['priv_pass']??''),
                       trim($in['auth_proto']??'MD5'),trim($in['priv_proto']??'DES')]);
        echo json_encode(['ok'=>true,'id'=>$id]); break;

    case 'snmp_profile_delete':
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
        if(array_key_exists('snmp_interval',$in)) setting_set('snmp_interval', max(3,(int)$in['snmp_interval']));
        if(array_key_exists('map_refresh',$in)) setting_set('map_refresh', max(2,(int)$in['map_refresh']));
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
        if (!password_verify($in['old'] ?? '', $h)) { echo json_encode(['error'=>'Staré heslo nesedí']); break; }
        $pdo->prepare('UPDATE users SET pass_hash=? WHERE username=?')
            ->execute([password_hash($in['new'], PASSWORD_DEFAULT), $u]);
        echo json_encode(['ok'=>true]); break;

    case 'whoami':
        echo json_encode(['user'=>current_user(),'role'=>current_role()]); break;

    case 'client_config':
        echo json_encode(['map_refresh'=>(int)setting_get('map_refresh',10)]); break;

    case 'users_list':
        require_role('admin');
        echo json_encode($pdo->query('SELECT id,username,role,created FROM users ORDER BY username')->fetchAll()); break;

    case 'user_add':
        require_role('admin');
        $u=trim($in['username']??''); $pw=(string)($in['password']??''); $role=$in['role']??'user';
        if($u===''||$pw===''){ echo json_encode(['error'=>'Meno aj heslo sú povinné']); break; }
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
        $pdo->prepare('UPDATE users SET pass_hash=? WHERE id=?')->execute([password_hash($pw,PASSWORD_DEFAULT),$id]);
        echo json_encode(['ok'=>true]); break;

    case 'backup':
        require_role('admin');
        if(cfg('DB_DRIVER')!=='sqlite'){ echo json_encode(['error'=>'Záloha cez web je pre SQLite; pri MySQL použi mysqldump']); break; }
        $f=cfg('SQLITE_PATH');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="netpulse-backup-'.date('Y-m-d_His').'.db"');
        header('Content-Length: '.filesize($f));
        readfile($f); exit;

    case 'restore':
        require_role('administrator');
        if(cfg('DB_DRIVER')!=='sqlite'){ echo json_encode(['error'=>'Obnova cez web je len pre SQLite']); break; }
        if(empty($_FILES['file']['tmp_name'])){ echo json_encode(['error'=>'Chýba súbor zálohy']); break; }
        $tmp=$_FILES['file']['tmp_name'];
        if(strpos((string)file_get_contents($tmp,false,null,0,16),'SQLite format 3')!==0){ echo json_encode(['error'=>'Neplatný súbor (nie je SQLite záloha)']); break; }
        $dst=cfg('SQLITE_PATH'); @copy($dst,$dst.'.before-restore');
        echo json_encode(@copy($tmp,$dst)?['ok'=>true]:['error'=>'Nepodarilo sa zapísať databázu']); break;

    case 'import_dude':
        require_role('administrator');
        if(empty($_FILES['file']['tmp_name'])){ echo json_encode(['error'=>'Chýba súbor dude.db']); break; }
        $tmp=$_FILES['file']['tmp_name'];
        if(strpos((string)file_get_contents($tmp,false,null,0,16),'SQLite format 3')!==0){ echo json_encode(['error'=>'Neplatný dude.db (nie je SQLite)']); break; }
        require_once __DIR__.'/importer.php';
        try{ echo json_encode(['ok'=>true,'summary'=>dude_import($tmp)]); }
        catch(Throwable $e){ echo json_encode(['error'=>$e->getMessage()]); }
        break;

    default:
        http_response_code(400); echo json_encode(['error'=>'neznáma akcia']);
    }
} catch (Throwable $e) {
    http_response_code(500); echo json_encode(['error'=>$e->getMessage()]);
}
