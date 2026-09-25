<?php
/** Sondovanie dostupnosti – spoločné pre monitor.php aj okno zariadenia (Nástroje → Otestovať teraz). */

function exec_allowed(): bool {
    if (!function_exists('exec')) return false;
    $dis = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('exec', $dis, true);
}
function fping_available(): bool {
    if (!exec_allowed()) return false;
    @exec('command -v fping 2>/dev/null', $o, $rc); return $rc === 0 && !empty($o);
}
/** Hromadný ping. Vráti host => odozva v ms (alebo null) pre odpovedajúce. */
function fping_batch(array $hosts, int $timeoutMs): array {
    $alive = []; if (!$hosts) return $alive;
    $args = implode(' ', array_map('escapeshellarg', $hosts));
    @exec("fping -a -e -t $timeoutMs -r 1 $args 2>/dev/null", $out);
    foreach ($out as $line) {
        if (preg_match('/^(\S+)\s+(?:is alive\s+)?\(([\d.]+)\s*ms\)/', $line, $m)) $alive[$m[1]] = (float)$m[2];
        elseif (preg_match('/^(\S+)\s+is alive/', $line, $m)) $alive[$m[1]] = null;
        elseif (preg_match('/^(\S+)\s*$/', $line, $m)) $alive[$m[1]] = null;
    }
    return $alive;
}
/** Paralelné TCP spojenia (neblokujúci connect + stream_select).
 *  $targets: kľúč => [host, port]. Vráti kľúč => odozva v ms pre úspešné spojenia.
 *  Všetky ciele sa skúšajú naraz – 100 nedostupných zariadení × 6 portov trvá
 *  rovnako ako jedno, najviac $timeout sekúnd. */
function tcp_probe_batch(array $targets, float $timeout, int $maxOpen = 700): array {
    $ok = []; $open = []; $dns = [];
    $queue = $targets;
    while ($queue || $open) {
        while ($queue && count($open) < $maxOpen) {
            $key = array_key_first($queue); [$host, $port] = $queue[$key]; unset($queue[$key]);
            $port = (int)$port; $host = (string)$host;
            if ($port <= 0 || $port > 65535 || $host === '') continue;
            if (!filter_var($host, FILTER_VALIDATE_IP)) {      // DNS názov: prelož raz, nie pri každom porte
                if (!isset($dns[$host])) { $r = gethostbyname($host); $dns[$host] = ($r !== $host) ? $r : $host; }
                $host = $dns[$host];
            }
            $h = (strpos($host, ':') !== false && $host[0] !== '[') ? "[$host]" : $host;   // IPv6
            $errno = 0; $errstr = '';
            $s = @stream_socket_client("tcp://$h:$port", $errno, $errstr, $timeout,
                                       STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
            if ($s === false) continue;                       // okamžite odmietnuté
            stream_set_blocking($s, false);
            $open[$key] = [$s, microtime(true)];
        }
        if (!$open) break;
        $now = microtime(true); $left = $timeout;
        foreach ($open as [$s, $t0]) $left = min($left, $t0 + $timeout - $now);
        $left = max(0.0, $left);
        $w = []; foreach ($open as $k => [$s]) $w[$k] = $s;
        $r = null; $e = null;
        $n = @stream_select($r, $w, $e, (int)$left, (int)(($left - (int)$left) * 1e6));
        $now = microtime(true);
        if ($n) {
            foreach ($w as $k => $s) {
                if (!isset($open[$k])) continue;
                // zapisovateľný = spojené ALEBO zlyhané; spojené má známeho partnera
                if (@stream_socket_get_name($s, true) !== false) $ok[$k] = round(($now - $open[$k][1]) * 1000, 1);
                @fclose($s); unset($open[$k]);
            }
        }
        foreach ($open as $k => [$s, $t0]) {                  // vypršané
            if ($now - $t0 >= $timeout) { @fclose($s); unset($open[$k]); }
        }
    }
    return $ok;
}
function dns_check(string $host): bool {
    if (!exec_allowed()) return false;
    @exec('nslookup -timeout=1 -retry=1 www.mikrotik.com ' . escapeshellarg($host) . ' 2>/dev/null', $o, $rc);
    return $rc === 0;
}
function snmp_check(string $host, int $port): bool {
    if (function_exists('snmpget')) { $r = @snmpget("$host:$port", 'public', '1.3.6.1.2.1.1.1.0', 1000000, 1); return $r !== false; }
    return false;
}
