<?php
/** Import z pôvodného Dude dude.db do appky. Zdieľané CLI aj webom (obnova/import). */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/DudeParser.php';

function dude_import(string $path): array {
    $pdo = db();
    $schema = cfg('DB_DRIVER') === 'mysql' ? 'schema_mysql.sql' : 'schema_sqlite.sql';
    foreach (array_filter(array_map('trim', explode(';', file_get_contents(__DIR__ . '/' . $schema)))) as $stmt)
        if ($stmt !== '') $pdo->exec($stmt);
    migrate();

    $data = (new DudeParser($path))->extract();
    $repl = fn($t) => cfg('DB_DRIVER') === 'mysql' ? "REPLACE INTO $t" : "INSERT OR REPLACE INTO $t";

    $pdo->beginTransaction();
    foreach (['map_nodes','map_links','maps','device_types','services','probes','link_types','snmp_profiles'] as $t) { try{$pdo->exec("DELETE FROM $t");}catch(Throwable $e){} }

    $mapsSorted = $data['maps'];
    // zachovaj pôvodné poradie z Dude (podľa ID = poradia vytvorenia), nie abecedne
    usort($mapsSorted, fn($a,$b)=>($a['id'] <=> $b['id']));
    $m = $pdo->prepare($repl('maps') . '(id,name,image_id,canvas_id,sort_order) VALUES(?,?,?,?,?)');
    $so = 0;
    foreach ($mapsSorted as $x) { $m->execute([$x['id'],$x['name'],$x['image_id'],$x['canvas_id'],$so]); $so += 10; }

    $t = $pdo->prepare($repl('device_types') . '(id,name,icon) VALUES(?,?,?)');
    foreach ($data['types'] as $x) $t->execute([$x['id'],$x['name'],$x['icon']]);

    $d = $pdo->prepare($repl('devices') .
        '(id,name,ip,dns,snmp_profile,username,password,type_id,status,last_check,rtt,monitored) VALUES(?,?,?,?,?,?,?,?,
           COALESCE((SELECT status FROM devices WHERE id=?),\'unknown\'),
           (SELECT last_check FROM devices WHERE id=?),
           (SELECT rtt FROM devices WHERE id=?),
           COALESCE((SELECT monitored FROM devices WHERE id=?),1))');
    foreach ($data['devices'] as $x)
        $d->execute([$x['id'],$x['name'],$x['ip'],$x['dns'],$x['snmp_profile'],
                     $x['username'],$x['password']??'',$x['type_id'],$x['id'],$x['id'],$x['id'],$x['id']]);

    $p = $pdo->prepare($repl('probes') . '(id,name,type,port,dns_name) VALUES(?,?,?,?,?)');
    foreach ($data['probes'] as $x) $p->execute([$x['id'],$x['name'],$x['type'],$x['port'],$x['dns_name']]);

    $ltp = $pdo->prepare($repl('link_types') . '(id,name,style,thickness) VALUES(?,?,?,?)');
    foreach ($data['link_types'] as $id=>$x) $ltp->execute([$id,$x['name'],$x['style'],$x['thickness']]);

    $spf = $pdo->prepare($repl('snmp_profiles') . '(id,name,community,version,port,sec_name,auth_pass,priv_pass,auth_proto,priv_proto) VALUES(?,?,?,?,?,?,?,?,?,?)');
    foreach ($data['snmp_profiles'] as $x) $spf->execute([$x['id'],$x['name'],$x['community'],$x['version'],$x['port'],$x['sec_name']??'',$x['auth_pass']??'',$x['priv_pass']??'',$x['auth_proto']??'MD5',$x['priv_proto']??'DES']);

    $probeById = $data['probes'];
    $s = $pdo->prepare($repl('services') . '(id,device_id,probe_id,ptype,port,name,enabled,down,acked) VALUES(?,?,?,?,?,?,?,?,?)');
    foreach ($data['services'] as $x) {
        $pr = $probeById[$x['probe_id']] ?? null;
        $s->execute([$x['id'],$x['device_id'],$x['probe_id'],$pr['type']??null,$pr['port']??0,
                     $x['name'],$x['enabled'],$x['down'],$x['acked']]);
    }

    $nn = $pdo->prepare($repl('map_nodes') . '(id,map_id,kind,device_id,submap_id,x,y,image,label) VALUES(?,?,?,?,?,?,?,?,?)');
    foreach ($data['nodes'] as $x)
        $nn->execute([$x['id'],$x['map_id'],$x['kind'],$x['device_id'],$x['submap_id'],$x['x'],$x['y'],$x['image'],$x['label']]);

    $ll = $pdo->prepare($repl('map_links') . '(id,map_id,from_node,to_node,width,style,thickness,ltype,snmp_device,snmp_ifindex,snmp_type,label) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($data['links'] as $x)
        $ll->execute([$x['id'],$x['map_id'],$x['from_node'],$x['to_node'],$x['width'],
                      $x['style']??0,$x['thickness']??2,$x['ltype']??'',
                      $x['snmp_device']??null,$x['snmp_ifindex']??null,$x['snmp_type']??null,$x['label']]);

    $pdo->commit();
    return ['maps'=>count($data['maps']),'types'=>count($data['types']),'probes'=>count($data['probes']),
            'devices'=>count($data['devices']),'services'=>count($data['services']),
            'nodes'=>count($data['nodes']),'links'=>count($data['links'])];
}
