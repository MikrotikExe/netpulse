<?php
/** PDO pripojenie – jednotné pre SQLite aj MySQL. */
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = require __DIR__ . '/config.php';
    if ($c['DB_DRIVER'] === 'mysql') {
        $dsn = "mysql:host={$c['MYSQL_HOST']};port={$c['MYSQL_PORT']};dbname={$c['MYSQL_DB']};charset=utf8mb4";
        $h = new PDO($dsn, $c['MYSQL_USER'], $c['MYSQL_PASS'], [PDO::MYSQL_ATTR_FOUND_ROWS => true]);
        $h->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } else {
        $h = new PDO('sqlite:' . $c['SQLITE_PATH']);
        $h->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Zlyhanie PRAGMA nesmie nechať polokonfigurované spojenie – preto každá zvlášť.
        //   busy_timeout : počkaj na zámok namiesto chyby "database is locked"
        //   WAL          : súbežné čítanie + zápis (web, monitor, snmp poller)
        $bt = PHP_SAPI === 'cli' ? 2000 : 5000;   // workery radšej zopakujú, web počká dlhšie
        foreach (["PRAGMA busy_timeout=$bt", 'PRAGMA journal_mode=WAL',
                  'PRAGMA synchronous=NORMAL', 'PRAGMA foreign_keys=ON'] as $pr) {
            try { $h->exec($pr); } catch (Throwable $e) { error_log("NetPulse: $pr zlyhalo – ".$e->getMessage()); }
        }
    }
    $h->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo = $h;   // až teraz, keď je spojenie plne nastavené
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


/** Je chyba spôsobená zamknutou databázou? */
function db_is_locked(Throwable $e): bool {
    // SQLite: 5 BUSY, 6 LOCKED; MySQL/MariaDB: 1205 lock wait timeout, 1213 deadlock
    if ($e instanceof PDOException && isset($e->errorInfo[1]) && in_array((int)$e->errorInfo[1], [5, 6, 1205, 1213], true)) return true;
    $m = $e->getMessage();
    return stripos($m, 'database is locked') !== false || stripos($m, 'database is busy') !== false;
}

/** Zopakuj zápis, ak je DB práve zamknutá (web + monitor + snmp poller píšu súbežne).
 *  Vráti výsledok callbacku, alebo null ak sa to ani po $tries pokusoch nepodarilo. */
function db_retry(callable $fn, int $tries = 5) {
    $wait = 120000; // 0,12 s, zakaždým dvojnásobok
    for ($i = 1; $i <= $tries; $i++) {
        try { return $fn(); }
        catch (Throwable $e) {
            if (!db_is_locked($e) || $i === $tries) {
                if (db_is_locked($e)) error_log("NetPulse: DB zamknutá aj po $tries pokusoch – zápis preskočený");
                if (!db_is_locked($e)) throw $e;
                return null;
            }
            usleep($wait); $wait = min($wait * 2, 3000000);
        }
    }
    return null;
}

/** Transakcia s opakovaním pri zámku. Callback beží celý alebo vôbec (rollback).
 *  Vráti true pri úspechu, false ak DB ostala zamknutá; iné chyby prehodí ďalej. */
function db_tx(callable $fn, int $tries = 5): bool {
    $pdo = db(); $my = np_is_mysql(); $wait = 150000;
    for ($i = 1; $i <= $tries; $i++) {
        try {
            // SQLite: IMMEDIATE = zápisový zámok hneď na začiatku (busy_timeout sa uplatní),
            // inak by sa pri povýšení čítania na zápis mohol vrátiť BUSY bez čakania.
            $pdo->exec($my ? 'START TRANSACTION' : 'BEGIN IMMEDIATE');
            $fn($pdo);
            $pdo->exec('COMMIT');
            return true;
        } catch (Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) {}
            if (!db_is_locked($e)) throw $e;
            if ($i === $tries) { error_log("NetPulse: DB zamknutá aj po $tries pokusoch – transakcia preskočená"); return false; }
            usleep($wait); $wait = min($wait * 2, 2000000);
        }
    }
    return false;
}

function np_is_mysql(): bool { return cfg('DB_DRIVER') === 'mysql'; }

/** Overí, či je WAL naozaj zapnutý (bez neho sa čítanie a zápis blokujú navzájom). */
function db_journal_mode(): string {
    if (np_is_mysql()) return 'n/a';
    try { return (string) db()->query('PRAGMA journal_mode')->fetchColumn(); }
    catch (Throwable $e) { return '?'; }
}

/** Vytvor index, ak neexistuje (MySQL 8 nepozná CREATE INDEX IF NOT EXISTS). */
function np_ensure_index(PDO $pdo, string $table, string $name, string $cols): void {
    try {
        if (np_is_mysql()) {
            $st = $pdo->prepare('SELECT 1 FROM information_schema.STATISTICS
                                 WHERE table_schema=DATABASE() AND table_name=? AND index_name=? LIMIT 1');
            $st->execute([$table, $name]);
            if (!$st->fetchColumn()) $pdo->exec("CREATE INDEX $name ON $table($cols)");
        } else {
            $pdo->exec("CREATE INDEX IF NOT EXISTS $name ON $table($cols)");
        }
    } catch (Throwable $e) { error_log("NetPulse index $name: ".$e->getMessage()); }
}

/** Zmaž staré riadky po malých dávkach s časovým stropom (funguje na SQLite aj MySQL –
 *  obal cez odvodenú tabuľku obchádza MySQL chyby 1093/1235). Vráti počet zmazaných. */
function np_purge(PDO $pdo, string $table, string $cut, float $budget = 3.0, int $batch = 2000): int {
    $deadline = microtime(true) + $budget; $total = 0;
    $sql = "DELETE FROM $table WHERE id IN (SELECT id FROM (SELECT id FROM $table WHERE ts < ? LIMIT $batch) x)";
    while (microtime(true) < $deadline) {
        $n = db_retry(function() use ($pdo, $sql, $cut) { $st = $pdo->prepare($sql); $st->execute([$cut]); return $st->rowCount(); });
        if (!$n) break;
        $total += $n;
        if ($n < $batch) break;
        usleep(200000);   // pusti k slovu ostatné procesy
    }
    return $total;
}

/** Unix čas z epoch stĺpca, s rezervou na staršie riadky, kde je len lokálny reťazec.
 *  Epoch je nutný kvôli prechodu na zimný čas (hodina 02:00–03:00 sa opakuje). */
function np_epoch($ts, $str): ?int {
    if ($ts !== null && $ts !== '' && (int)$ts > 0) return (int)$ts;
    if ($str) { $t = strtotime((string)$str); return $t === false ? null : $t; }
    return null;
}

/** Platná IP adresa alebo DNS názov. Hodnota sa posiela do fping/ping/snmpget – nesmie
 *  začínať pomlčkou (bola by to voľba príkazu) ani obsahovať medzery či iné znaky. */
function np_valid_host(?string $h): bool {
    $h = trim((string)$h);
    if ($h === '') return false;
    if (filter_var($h, FILTER_VALIDATE_IP)) return true;
    return (bool) preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,62}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,62}[A-Za-z0-9])?)*\.?$/', $h);
}

/** CLI nástroje nesmú bežať ako root nad databázou iného vlastníka –
 *  vytvorili by súbory -wal/-shm patriace rootovi a web aj monitor by dostali "readonly database". */
function np_cli_guard(): void {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // CLI skripty sa nesmú dať spustiť cez web
    if (np_is_mysql() || !function_exists('posix_geteuid')) return;
    if (posix_geteuid() !== 0) return;
    $f = cfg('SQLITE_PATH');
    if (!$f || !is_file($f)) return;
    $owner = fileowner($f);
    if ($owner === 0) return;
    $name = function_exists('posix_getpwuid') ? (posix_getpwuid($owner)['name'] ?? $owner) : $owner;
    fwrite(STDERR, "Nespúšťaj ako root – databáza patrí používateľovi '$name'.\n"
                 . "Použi: sudo -u $name php " . basename($_SERVER['argv'][0] ?? 'skript.php') . "\n");
    exit(1);
}

/** Verzia schémy – zvýš pri každej zmene migrate(). Keď DB už má túto verziu,
 *  migrácie sa preskočia (inak by sa pri každej požiadavke skúšalo ~20× ALTER TABLE). */
const NP_SCHEMA_VERSION = 5;

/** Idempotentné migrácie – doplní chýbajúce stĺpce, tabuľky a indexy v existujúcich DB. */
function migrate(): void {
    static $done = false; if ($done) return; $done = true;
    $pdo = db();
    if ((int) setting_get('schema_ver', '0') >= NP_SCHEMA_VERSION) return;
    $mysql = np_is_mysql();
    $failed = false;   // schema_ver sa zapíše LEN ak všetko prebehlo (inak by chýbajúci stĺpec zabil monitor natrvalo)
    $eng  = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $intt = $mysql ? 'INT' : 'INTEGER';
    $big  = $mysql ? 'BIGINT' : 'INTEGER';
    $txt  = $mysql ? 'VARCHAR(64)' : 'TEXT';
    $dt   = $mysql ? 'DATETIME' : 'TEXT';
    $auto = $mysql ? 'BIGINT PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    // 1) tabuľky, ktoré v starších schémach chýbali
    foreach ([
        "CREATE TABLE IF NOT EXISTS app_settings(k " . ($mysql?'VARCHAR(64)':'TEXT') . " PRIMARY KEY, v TEXT)$eng",
        "CREATE TABLE IF NOT EXISTS link_types(id $big PRIMARY KEY, name $txt, style $intt, thickness $intt)$eng",
        "CREATE TABLE IF NOT EXISTS snmp_profiles(id $big PRIMARY KEY, name $txt, community $txt, version $intt, port $intt)$eng",
        "CREATE TABLE IF NOT EXISTS link_traffic(link_id $big PRIMARY KEY, in_oct $big, out_oct $big, ts $dt, rx_bps DOUBLE, tx_bps DOUBLE, speed_bps DOUBLE)$eng",
        "CREATE TABLE IF NOT EXISTS traffic_history(id $auto, link_id $big, ts $dt, rx_bps DOUBLE, tx_bps DOUBLE)$eng",
        // fronta Telegram správ – doručenie je oddelené od zistenia stavu
        "CREATE TABLE IF NOT EXISTS notify_queue(id $auto, device_id $big, status " . ($mysql?'VARCHAR(16)':'TEXT') . ", text TEXT,
            created $big, attempts $intt DEFAULT 0, next_try $big)$eng",
        // neúspešné prihlásenia (ochrana proti hádaniu hesla)
        "CREATE TABLE IF NOT EXISTS login_fail(id $auto, ip " . ($mysql?'VARCHAR(64)':'TEXT') . ", ts $big)$eng",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) { $failed = true; error_log('NetPulse migrate: '.$e->getMessage()); }
    }
    // 2) stĺpce doplnené neskôr
    $alter = [
        ['devices', 'down_since', $mysql ? 'DATETIME NULL' : 'TEXT'],
        ['devices', 'down_ts',    $big],                              // epoch začiatku nedostupnosti
        ['maps',    'sort_order', $intt],
        ['devices', 'monitored',  $mysql ? 'TINYINT DEFAULT 1' : 'INTEGER DEFAULT 1'],
        ['devices', 'password',   $mysql ? 'VARCHAR(255)' : 'TEXT'],
        ['devices', 'notified',   $mysql ? 'VARCHAR(16)' : 'TEXT'],   // posledný zaznamenaný prechod up/down
        ['outages', 'started_ts', $big],
        ['snmp_profiles','sec_name',  $mysql ? 'VARCHAR(128)' : 'TEXT'],
        ['snmp_profiles','auth_pass', $mysql ? 'VARCHAR(128)' : 'TEXT'],
        ['snmp_profiles','priv_pass', $mysql ? 'VARCHAR(128)' : 'TEXT'],
        ['snmp_profiles','auth_proto',$mysql ? 'VARCHAR(8)' : 'TEXT'],
        ['snmp_profiles','priv_proto',$mysql ? 'VARCHAR(8)' : 'TEXT'],
        ['map_links','style',     $intt],
        ['map_links','thickness', $intt],
        ['map_links','ltype',     $mysql ? 'VARCHAR(64)' : 'TEXT'],
        ['link_traffic','speed_bps', 'DOUBLE'],
        ['link_traffic','ts_unix',   $big],                           // epoch posledného merania
        ['link_traffic','ctype',     $mysql ? 'VARCHAR(4)' : 'TEXT'], // 'hc' 64-bit / '32' počítadlá
        ['link_traffic','uptime',    $big],                           // sysUpTime – odhalí reštart zariadenia
        ['map_links','snmp_device',  $big],
        ['map_links','snmp_ifindex', $intt],
        ['map_links','snmp_type',    $intt],
    ];
    foreach ($alter as [$t,$c,$ty]) {
        try { $pdo->exec("ALTER TABLE $t ADD COLUMN $c $ty"); }
        catch (Throwable $e) {
            $dup = stripos($e->getMessage(), 'duplicate column') !== false
                || ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1060);
            if (!$dup) { $failed = true; error_log("NetPulse migrate $t.$c: ".$e->getMessage()); }
        }
    }
    // 3) indexy – bez nich monitor pri veľkej histórii zamyká DB
    np_ensure_index($pdo, 'status_history',  'idx_sh_dev_ts', 'device_id, ts');
    np_ensure_index($pdo, 'status_history',  'idx_sh_ts',     'ts');
    np_ensure_index($pdo, 'traffic_history', 'idx_th',        'link_id, ts');
    np_ensure_index($pdo, 'traffic_history', 'idx_tr_ts',     'ts');
    np_ensure_index($pdo, 'events',          'idx_events_ts', 'ts');
    np_ensure_index($pdo, 'outages',         'idx_out_dev',   'device_id, ended');
    np_ensure_index($pdo, 'notify_queue',    'idx_nq_next',   'next_try');
    np_ensure_index($pdo, 'login_fail',      'idx_lf_ip_ts',  'ip, ts');
    // over, že kľúčové stĺpce naozaj existujú – až potom označ schému ako hotovú
    foreach ([['devices','down_ts'],['devices','notified'],['outages','started_ts'],['link_traffic','ts_unix'],
              ['link_traffic','ctype'],['link_traffic','uptime'],['notify_queue','next_try'],['login_fail','ts']] as [$t,$c]) {
        if (!np_column_exists($pdo, $t, $c)) { $failed = true; error_log("NetPulse migrate: chýba $t.$c"); }
    }
    if ($failed) { error_log('NetPulse migrate: nedokončené – zopakuje sa pri ďalšom behu'); return; }
    try { setting_set('schema_ver', (string)NP_SCHEMA_VERSION); } catch (Throwable $e) {}
}

function np_column_exists(PDO $pdo, string $t, string $c): bool {
    try {
        if (np_is_mysql()) {
            $st = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
            $st->execute([$t, $c]); return (bool)$st->fetchColumn();
        }
        foreach ($pdo->query("PRAGMA table_info($t)")->fetchAll() as $r) if ($r['name'] === $c) return true;
        return false;
    } catch (Throwable $e) { return false; }
}

/** Chyba „chýba stĺpec/tabuľka" => schéma nie je kompletná; vynúť migráciu pri ďalšom behu. */
function np_schema_error(Throwable $e): bool {
    $m = $e->getMessage(); $code = ($e instanceof PDOException) ? (int)($e->errorInfo[1] ?? 0) : 0;
    if (stripos($m, 'no such column') !== false || stripos($m, 'no such table') !== false || in_array($code, [1054, 1146], true)) {
        try { setting_set('schema_ver', '0'); } catch (Throwable $e2) {}
        return true;
    }
    return false;
}

/** Úplne odstráni zariadenie aj s jeho službami, výpadkami, históriou a čakajúcimi správami. */
function np_delete_device(PDO $pdo, int $id): void {
    foreach (['services','outages','status_history','notify_queue'] as $t)
        $pdo->prepare("DELETE FROM $t WHERE device_id=?")->execute([$id]);
    $pdo->prepare('UPDATE map_links SET snmp_device=NULL, snmp_ifindex=NULL WHERE snmp_device=?')->execute([$id]);
    $pdo->prepare('DELETE FROM devices WHERE id=?')->execute([$id]);
}

/** Zariadenia, ktoré nie sú na žiadnej mape (napr. zmazané z mapy staršou verziou) – monitorujú sa neviditeľne. */
function np_orphan_devices(PDO $pdo): array {
    return $pdo->query("SELECT id,name,ip,status,monitored FROM devices
                        WHERE id NOT IN (SELECT device_id FROM map_nodes WHERE device_id IS NOT NULL)
                        ORDER BY name")->fetchAll();
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
