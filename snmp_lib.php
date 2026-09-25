<?php
/** SNMP pomocné funkcie (net-snmp CLI snmpget/snmpwalk, alebo php-snmp). Podpora v1/v2c/v3. */
function snmp_cli(): bool { static $r=null; if($r!==null)return $r;
    if(!function_exists('exec'))return $r=false;
    @exec('command -v snmpget 2>/dev/null',$o,$rc); return $r=($rc===0 && !empty($o)); }
function snmpwalk_cli(): bool { static $r=null; if($r!==null)return $r;
    if(!function_exists('exec'))return $r=false;
    @exec('command -v snmpwalk 2>/dev/null',$o,$rc); return $r=($rc===0 && !empty($o)); }

/** Povolené SNMPv3 protokoly. Hodnoty idú do príkazu snmpget – nič iné nesmie prejsť. */
function snmp_auth_proto($v): string {
    $v = strtoupper(trim((string)$v));
    return in_array($v, ['MD5','SHA','SHA-224','SHA-256','SHA-384','SHA-512'], true) ? $v : 'MD5';
}
function snmp_priv_proto($v): string {
    $v = strtoupper(trim((string)$v));
    return in_array($v, ['DES','AES','AES-192','AES-256'], true) ? $v : 'DES';
}

/** Zostaví SNMP argumenty pre net-snmp podľa profilu (v1/v2c/v3). */
function snmp_authargs(array $p): string {
    $ver = (int)($p['version'] ?? 0);
    if ($ver === 2) { // v3
        $u = escapeshellarg($p['sec_name'] ?? '');
        $ap = (string)($p['auth_pass'] ?? ''); $pp = (string)($p['priv_pass'] ?? '');
        $a = escapeshellarg(snmp_auth_proto($p['auth_proto'] ?? 'MD5'));
        $x = escapeshellarg(snmp_priv_proto($p['priv_proto'] ?? 'DES'));
        if ($ap !== '' && $pp !== '') return "-v3 -l authPriv -u $u -a $a -A " . escapeshellarg($ap) . " -x $x -X " . escapeshellarg($pp);
        if ($ap !== '') return "-v3 -l authNoPriv -u $u -a $a -A " . escapeshellarg($ap);
        return "-v3 -l noAuthNoPriv -u $u";
    }
    $c = escapeshellarg($p['community'] ?? 'public');
    return ($ver === 1 ? '-v2c' : '-v1') . " -c $c";
}

/** SNMP GET viacerých OID naraz. @return array rovnakej dĺžky (null=chyba). */
function snmp_get(string $ip, array $prof, array $oids): array {
    if (snmp_cli()) {
        $cmd = 'snmpget ' . snmp_authargs($prof) . ' -Ovq -Ot -t 1 -r 1 '   // -Ot: TimeTicks ako číslo
             . escapeshellarg($ip) . ' ' . implode(' ', array_map('escapeshellarg', $oids)) . ' 2>/dev/null';
        $out = []; @exec($cmd, $out, $rc); $res = [];
        foreach ($oids as $i => $_) { $line=trim($out[$i]??''); $res[$i]=preg_match('/^"?(\d+)"?$/',$line,$m)?(float)$m[1]:null; }
        return $res;
    }
    if (function_exists('snmpget')) {   // fallback len v1/v2c
        @snmp_set_valueretrieval(SNMP_VALUE_PLAIN); $res=[]; $c=$prof['community']??'public'; $v2=((int)($prof['version']??0))===1;
        foreach ($oids as $i=>$oid) { $val=$v2?@snmp2_get($ip,$c,$oid,1000000,1):@snmpget($ip,$c,$oid,1000000,1);
            $res[$i]=($val!==false && preg_match('/^"?(\d+)"?$/',trim((string)$val),$m))?(float)$m[1]:null; }
        return $res;
    }
    return array_fill(0,count($oids),null);
}

/** SNMP WALK jedného podstromu. @return array [index => hodnota]. */
function snmp_walk(string $ip, array $prof, string $baseOid): array {
    $res = [];
    if (snmpwalk_cli()) {
        $cmd = 'snmpwalk ' . snmp_authargs($prof) . ' -Oqn -t 1 -r 1 '
             . escapeshellarg($ip) . ' ' . escapeshellarg($baseOid) . ' 2>/dev/null';
        $out = []; @exec($cmd, $out, $rc);
        foreach ($out as $line) if (preg_match('/^\.?[\d.]*\.(\d+)\s+(.*)$/', trim($line), $m)) $res[(int)$m[1]] = trim($m[2], " \"");
        return $res;
    }
    if (function_exists('snmpwalk')) {
        @snmp_set_valueretrieval(SNMP_VALUE_PLAIN); @snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
        $c=$prof['community']??'public'; $v2=((int)($prof['version']??0))===1;
        $arr = $v2 ? @snmp2_real_walk($ip,$c,$baseOid,1000000,1) : @snmprealwalk($ip,$c,$baseOid,1000000,1);
        if (is_array($arr)) foreach ($arr as $oid=>$val) if (preg_match('/\.(\d+)$/',$oid,$m)) $res[(int)$m[1]] = trim((string)$val," \"");
    }
    return $res;
}
