<?php
/** PDO pripojenie – jednotné pre SQLite aj MySQL. */
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = require __DIR__ . '/config.php';
    if ($c['DB_DRIVER'] === 'mysql') {
        $dsn = "mysql:host={$c['MYSQL_HOST']};port={$c['MYSQL_PORT']};dbname={$c['MYSQL_DB']};charset=utf8mb4";
        $pdo = new PDO($dsn, $c['MYSQL_USER'], $c['MYSQL_PASS']);
    } else {
        $pdo = new PDO('sqlite:' . $c['SQLITE_PATH']);
        $pdo->exec('PRAGMA busy_timeout=5000');   // počkaj na zámok namiesto chyby "database is locked"
        $pdo->exec('PRAGMA journal_mode=WAL');     // súbežné čítanie + zápis (web, monitor, snmp poller)
        $pdo->exec('PRAGMA synchronous=NORMAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}
/** Zisti časové pásmo servera (Debian/Ubuntu: /etc/timezone alebo symlink /etc/localtime). */
function np_system_tz(): string {
    $f = '/etc/timezone';
    if (@is_readable($f)) { $t = trim((string)@file_get_contents($f)); if ($t) return $t; }
    $l = @readlink('/etc/localtime');
    if ($l && preg_match('~zoneinfo/(.+)$~', $l, $m)) return $m[1];
    $ini = @ini_get('date.timezone');
    return $ini ?: 'UTC';
}

/** Zoznam platných pásiem vrátane starších názvov (US/Eastern, Europe/Kiev…). */
function np_tz_list(): array {
    static $l = null;
    if ($l === null) {
        $l = @DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
        if (!$l) $l = DateTimeZone::listIdentifiers();
    }
    return $l;
}
function np_tz_valid(?string $tz): bool {
    return $tz !== null && $tz !== '' && in_array($tz, np_tz_list(), true);
}

/** Časové pásmo aplikácie – poradie: nastavenie v UI → config.php → systém servera.
 *  POZOR: date_default_timezone_set() pri neplatnom pásme nevyhadzuje výnimku, len E_WARNING,
 *  ktorý by rozbil JSON odpoveď API – preto sa hodnota najprv overí. */
function np_init_tz(): void {
    static $done=false; if($done) return;
    $tz = null;
    try {  // uložené v Nastaveniach (tabuľka nemusí ešte existovať)
        $st = db()->prepare('SELECT v FROM app_settings WHERE k=?');
        $st->execute(['timezone']);
        $v = $st->fetchColumn(); if ($v) $tz = (string)$v;
    } catch (Throwable $e) {}
    if (!np_tz_valid($tz)) $tz = (string)(cfg('APP_TIMEZONE') ?: '');
    if (!np_tz_valid($tz)) $tz = np_system_tz();
    if (!np_tz_valid($tz)) $tz = 'UTC';
    if (@date_default_timezone_set($tz)) $done = true;   // pri zlyhaní skúsi znova nabudúce
    else date_default_timezone_set('UTC');
}

function cfg(string $k) { static $c=null; if(!$c)$c=require __DIR__.'/config.php'; return $c[$k]??null; }

/** Idempotentné migrácie – doplní chýbajúce stĺpce v existujúcich DB. */
function migrate(): void {
    static $done = false; if ($done) return; $done = true;
    $pdo = db();
    $mysql = cfg('DB_DRIVER') === 'mysql';
    $alter = [
        ['devices', 'down_since', $mysql ? 'DATETIME NULL' : 'TEXT'],
        ['maps',    'sort_order', $mysql ? 'INT' : 'INTEGER'],
        ['devices', 'monitored',  $mysql ? 'TINYINT DEFAULT 1' : 'INTEGER DEFAULT 1'],
        ['devices', 'password',   $mysql ? 'VARCHAR(255)' : 'TEXT'],
        ['devices', 'notified',   $mysql ? 'VARCHAR(16)' : 'TEXT'],
        ['snmp_profiles','sec_name',  $mysql ? 'VARCHAR(128)' : 'TEXT'],
        ['snmp_profiles','auth_pass', $mysql ? 'VARCHAR(128)' : 'TEXT'],
        ['snmp_profiles','priv_pass', $mysql ? 'VARCHAR(128)' : 'TEXT'],
        ['snmp_profiles','auth_proto',$mysql ? 'VARCHAR(8)' : 'TEXT'],
        ['snmp_profiles','priv_proto',$mysql ? 'VARCHAR(8)' : 'TEXT'],
        ['map_links','style',     $mysql ? 'INT' : 'INTEGER'],
        ['map_links','thickness', $mysql ? 'INT' : 'INTEGER'],
        ['map_links','ltype',     $mysql ? 'VARCHAR(64)' : 'TEXT'],
        ['link_traffic','speed_bps', 'DOUBLE'],
        ['map_links','snmp_device',  $mysql ? 'BIGINT' : 'INTEGER'],
        ['map_links','snmp_ifindex', $mysql ? 'INT' : 'INTEGER'],
        ['map_links','snmp_type',    $mysql ? 'INT' : 'INTEGER'],
    ];
    foreach ($alter as [$t,$c,$ty]) {
        try { $pdo->exec("ALTER TABLE $t ADD COLUMN $c $ty"); } catch (Throwable $e) {}
    }
    try {
        $intt = $mysql ? 'INT' : 'INTEGER';
        $pdo->exec("CREATE TABLE IF NOT EXISTS link_types(id " . ($mysql?'BIGINT':'INTEGER') . " PRIMARY KEY, name " . ($mysql?'VARCHAR(64)':'TEXT') . ", style $intt, thickness $intt)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings(k " . ($mysql?'VARCHAR(64)':'TEXT') . " PRIMARY KEY, v TEXT)");
        $big=$mysql?'BIGINT':'INTEGER'; $txt=$mysql?'VARCHAR(64)':'TEXT'; $dt=$mysql?'DATETIME':'TEXT';
        $pdo->exec("CREATE TABLE IF NOT EXISTS snmp_profiles(id $big PRIMARY KEY, name $txt, community $txt, version $intt, port $intt)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS link_traffic(link_id $big PRIMARY KEY, in_oct $big, out_oct $big, ts $dt, rx_bps DOUBLE, tx_bps DOUBLE, speed_bps DOUBLE)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS traffic_history(id " . ($mysql?'BIGINT PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT') . ", link_id $big, ts $dt, rx_bps DOUBLE, tx_bps DOUBLE)");
        try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_th ON traffic_history(link_id, ts)"); } catch (Throwable $e) {}
    } catch (Throwable $e) {}
}

/** Konfigurácia aplikácie (kľúč-hodnota). */
function setting_get(string $k, $default=null) {
    try { $st=db()->prepare('SELECT v FROM app_settings WHERE k=?'); $st->execute([$k]);
          $v=$st->fetchColumn(); return $v===false ? $default : $v; }
    catch (Throwable $e) { return $default; }
}
function setting_set(string $k, $v): void {
    $pdo=db(); $repl = cfg('DB_DRIVER')==='mysql' ? 'REPLACE INTO' : 'INSERT OR REPLACE INTO';
    $pdo->prepare("$repl app_settings(k,v) VALUES(?,?)")->execute([$k,(string)$v]);
}

np_init_tz();
