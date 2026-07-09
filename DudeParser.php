<?php
/**
 * DudeParser – číta pôvodnú Dude databázu (dude.db = SQLite) a rozparsuje
 * binárny "obj" formát. Overené na reálnej DB.
 *
 * Formát objektu: opakovane [1B dĺžka názvu][názov][1B tag][1B dĺžka][hodnota].
 * Kľúč k mapám: prvok mapy má sys-type == elementsID svojej mapy.
 * Typy objektov: 10=mapa, 15=zariadenie, 14=typ zariadenia, 17=služba,
 *                5=súbor(ikona), 41=sonda/funkcia, 31=linka(dáta).
 */
class DudeParser
{
    private PDO $src;

    public function __construct(string $dudeDbPath)
    {
        if (!is_file($dudeDbPath)) throw new RuntimeException("Nenašiel som $dudeDbPath");
        $this->src = new PDO('sqlite:' . $dudeDbPath);
        $this->src->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public static function kv(string $b): array
    {
        $d = []; $i = 0; $n = strlen($b);
        while ($i < $n) {
            $nl = ord($b[$i]); $i++;
            if ($nl === 0 || $i + $nl > $n) break;
            $name = substr($b, $i, $nl); $i += $nl;
            if ($i + 2 > $n) break;
            $len = ord($b[$i + 1]); $i += 2;
            $d[$name] = substr($b, $i, $len); $i += $len;
        }
        return $d;
    }

    public static function i32(?string $v): ?int
    {
        if ($v === null || strlen($v) !== 4) return null;
        $u = unpack('V', $v)[1];
        return ($u > 0x7fffffff) ? $u - 0x100000000 : $u;
    }

    public static function str(?string $v): string
    {
        if ($v === null) return '';
        $out = '';
        for ($i = 0, $n = strlen($v); $i < $n; $i++) {
            $c = ord($v[$i]);
            if ($c >= 32 || $c === 9) $out .= $v[$i];
        }
        return trim($out);
    }

    public static function ip(?string $v): ?string
    {
        if ($v === null || strlen($v) < 4) return null;
        for ($k = 0; $k + 4 <= strlen($v); $k += 4) {
            $o = array_values(unpack('C4', substr($v, $k, 4)));
            $sum = $o[0] + $o[1] + $o[2] + $o[3];
            if ($sum === 0 || $sum === 1020) continue;
            return "$o[0].$o[1].$o[2].$o[3]";
        }
        return null;
    }

    public function extract(): array
    {
        $rows = $this->src->query('SELECT obj FROM objs')->fetchAll(PDO::FETCH_COLUMN);
        $parsed = [];
        foreach ($rows as $b) $parsed[] = self::kv($b);

        // index podľa sys-id
        $bySid = [];
        foreach ($parsed as $o) {
            $s = self::i32($o['sys-id'] ?? null);
            if ($s !== null && !isset($bySid[$s])) $bySid[$s] = $o;
        }

        $maps = []; $canvas2eid = []; $devices = []; $types = [];
        $nodes = []; $links = []; $services = [];

        // mapy (10)
        foreach ($parsed as $o) {
            if (self::i32($o['sys-type'] ?? null) === 10) {
                $eid = self::i32($o['elementsID'] ?? null);
                $cid = self::i32($o['sys-id'] ?? null);
                $maps[$eid] = ['id'=>$eid, 'name'=>self::str($o['sys-name'] ?? ''),
                               'image_id'=>self::i32($o['imageID'] ?? null), 'canvas_id'=>$cid];
                $canvas2eid[$cid] = $eid;
            }
        }
        // typy zariadení (14) -> ikona zo súboru (5)
        foreach ($parsed as $o) {
            if (self::i32($o['sys-type'] ?? null) === 14) {
                $img = self::i32($o['imageID'] ?? null);
                $file = $bySid[$img] ?? null;
                $icon = $file ? self::str($file['fileName'] ?? '') : '';
                $types[self::i32($o['sys-id'])] = ['id'=>self::i32($o['sys-id']),
                    'name'=>self::str($o['sys-name'] ?? ''), 'icon'=>$icon];
            }
        }
        // typy spojov (34): štýl + hrúbka čiary
        $linkTypes = [];
        foreach ($parsed as $o) {
            if (self::i32($o['sys-type'] ?? null) === 34) {
                $linkTypes[self::i32($o['sys-id'])] = [
                    'style'=>self::i32($o['style'] ?? null) ?: 0,
                    'thickness'=>self::i32($o['thickness'] ?? null) ?: 2,
                    'name'=>self::str($o['sys-name'] ?? '')];
            }
        }
        // (typy spojov už máme v $linkTypes)
        // SNMP profily (58)
        $snmpProfiles = [];
        foreach ($parsed as $o) {
            if (self::i32($o['sys-type'] ?? null) === 58) {
                $snmpProfiles[self::i32($o['sys-id'])] = [
                    'id'=>self::i32($o['sys-id']), 'name'=>self::str($o['sys-name'] ?? ''),
                    'community'=>self::str($o['community'] ?? ''),
                    'version'=>self::i32($o['version'] ?? null),   // 0=v1, 1=v2c, 2=v3, -1=none
                    'port'=>self::i32($o['port'] ?? null) ?: 161,
                    'sec_name'=>self::str($o['v3Security'] ?? ''),
                    'auth_pass'=>self::str($o['v3AuthPassword'] ?? ''),
                    'priv_pass'=>self::str($o['v3CryptPassword'] ?? ''),
                    'auth_proto'=>self::i32($o['v3AuthMethod'] ?? null) === 1 ? 'SHA' : 'MD5',
                    'priv_proto'=>self::i32($o['v3CryptMethod'] ?? null) === 1 ? 'AES' : 'DES'];
            }
        }
        // link-dáta (31): netMapElementID -> typeID + master device/interface/type
        $linkMeta = [];
        foreach ($parsed as $o) {
            if (self::i32($o['sys-type'] ?? null) === 31) {
                $linkMeta[self::i32($o['netMapElementID'] ?? null)] = [
                    'typeID'=>self::i32($o['typeID'] ?? null),
                    'md'=>self::i32($o['masterDevice'] ?? null),
                    'ifidx'=>self::i32($o['masterInterface'] ?? null),
                    'mtype'=>self::i32($o['masteringType'] ?? null)];
            }
        }
        // zariadenia (15)
        foreach ($parsed as $o) {
            if (self::i32($o['sys-type'] ?? null) === 15) {
                $sid = self::i32($o['sys-id']);
                $devices[$sid] = ['id'=>$sid, 'name'=>self::str($o['sys-name'] ?? ''),
                    'ip'=>self::ip($o['addresses'] ?? null), 'dns'=>self::str($o['dnsNames'] ?? ''),
                    'snmp_profile'=>self::i32($o['snmpProfileID'] ?? null),
                    'username'=>self::str($o['user'] ?? ''), 'password'=>self::str($o['pwd'] ?? ''), 'type_id'=>self::i32($o['typeID'] ?? null)];
            }
        }
        // sondy (13) – typ + port pre monitoring
        $typeMap = [1=>'icmp',2=>'random',3=>'tcp',4=>'dns',5=>'snmp',6=>'tcp',8=>'function'];
        $probes = [];
        foreach ($parsed as $o) {
            if (self::i32($o['sys-type'] ?? null) === 13) {
                $pid = self::i32($o['sys-id']);
                $tid = self::i32($o['typeID'] ?? null);
                $probes[$pid] = ['id'=>$pid, 'name'=>self::str($o['sys-name'] ?? ''),
                    'type'=>$typeMap[$tid] ?? 'tcp', 'port'=>self::i32($o['defaultPort'] ?? null) ?: 0,
                    'dns_name'=>self::str($o['dnsName'] ?? '')];
            }
        }
        // služby (17) – prepojené na sondu (probe_id + typ + port)
        foreach ($parsed as $o) {
            if (self::i32($o['sys-type'] ?? null) === 17) {
                $pid = self::i32($o['probeID'] ?? null);
                $pr = $probes[$pid] ?? null;
                $pname = $pr ? $pr['name'] : (isset($bySid[$pid]) ? self::str($bySid[$pid]['sys-name'] ?? '') : '');
                $services[] = ['id'=>self::i32($o['sys-id']), 'device_id'=>self::i32($o['deviceID'] ?? null),
                    'probe_id'=>$pid, 'name'=>$pname ?: self::str($o['sys-name'] ?? ''),
                    'enabled'=>self::i32($o['enabled'] ?? null) === 0 ? 0 : 1,
                    'down'=>self::i32($o['probesDown'] ?? null) ?: 0,
                    'acked'=>self::i32($o['acked'] ?? null) ? 1 : 0];
            }
        }
        // prvky máp (sys-type == elementsID mapy)
        foreach ($parsed as $o) {
            $st = self::i32($o['sys-type'] ?? null);
            if ($st === null || !isset($maps[$st])) continue;
            $sid = self::i32($o['sys-id']);
            $lf  = self::i32($o['linkFrom'] ?? null);
            if ($lf === null || $lf === -1) {
                $iid = self::i32($o['itemID'] ?? null);
                $isSub = isset($canvas2eid[$iid]);
                $nodes[] = ['id'=>$sid, 'map_id'=>$st,
                    'kind'=>$isSub ? 'submap' : 'device',
                    'device_id'=>$isSub ? null : $iid,
                    'submap_id'=>$isSub ? $canvas2eid[$iid] : null,
                    'x'=>self::i32($o['itemX'] ?? null), 'y'=>self::i32($o['itemY'] ?? null),
                    'image'=>self::i32($o['itemImage'] ?? null),
                    'label'=>self::str($o['sys-name'] ?? '')];
            } else {
                $meta = $linkMeta[$sid] ?? null;
                $tid = $meta['typeID'] ?? null;
                $lt = ($tid !== null && isset($linkTypes[$tid])) ? $linkTypes[$tid] : null;
                $links[] = ['id'=>$sid, 'map_id'=>$st, 'from_node'=>$lf,
                    'to_node'=>self::i32($o['linkTo'] ?? null),
                    'width'=>self::i32($o['linkWidth'] ?? null),
                    'style'=>$lt['style'] ?? 0,
                    'thickness'=>$lt['thickness'] ?? 2,
                    'ltype'=>$lt['name'] ?? '',
                    'snmp_device'=>$meta['md'] ?? null,
                    'snmp_ifindex'=>$meta['ifidx'] ?? null,
                    'snmp_type'=>$meta['mtype'] ?? null,
                    'label'=>self::str($o['sys-name'] ?? '')];
            }
        }
        return ['maps'=>$maps,'devices'=>$devices,'types'=>$types,'probes'=>$probes,
                'link_types'=>$linkTypes,'snmp_profiles'=>$snmpProfiles,
                'nodes'=>$nodes,'links'=>$links,'services'=>$services];
    }
}
