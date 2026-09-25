<?php
/**
 * DudeWeb – konfigurácia
 * Aplikácia beží na SQLite alebo MySQL/MariaDB cez PDO.
 *   'sqlite' : najrýchlejší štart, DB je jeden súbor (data/app.db)
 *   'mysql'  : odporúčané pre produkciu / viac používateľov
 * Prepni DB_DRIVER a vyplň prístupy.
 */
return [
    // Názov aplikácie (zobrazí sa v hlavičke, titulku a prihlásení)
    'APP_NAME'    => 'NetPulse',
    // Časové pásmo aplikácie (časy vo výpisoch, udalostiach a Telegram správach)
    // Časové pásmo. Prázdne = zistí sa automaticky zo servera; zmeniť sa dá aj v Nastaveniach.
    'APP_TIMEZONE'=> getenv('DUDEWEB_TZ') ?: '',
    // Pre MySQL/MariaDB zmeň priamo tu ('mysql') – PHP-FPM premenné prostredia štandardne nevidí (clear_env).
    'DB_DRIVER'   => getenv('DUDEWEB_DRIVER') ?: 'sqlite',
    'SQLITE_PATH' => __DIR__ . '/data/app.db',
    'MYSQL_HOST'  => '127.0.0.1',
    'MYSQL_PORT'  => 3306,
    'MYSQL_DB'    => 'dudeweb',
    'MYSQL_USER'  => 'dudeweb',
    'MYSQL_PASS'  => 'zmen_ma',
    'DUDE_DB_PATH'=> __DIR__ . '/data/dude.db',
    'PING_TIMEOUT'=> 1,
    'PING_COUNT'  => 2,

    // Metóda kontroly dostupnosti:
    //  'auto' – ping ak je exec() povolený, inak TCP (odporúčané, funguje aj na hostingu)
    //  'tcp'  – vždy len TCP connect (shared hosting so zakázaným exec/ICMP)
    //  'icmp' – vždy ping (vlastný server/VPS)
    'CHECK_METHOD' => getenv('DUDEWEB_CHECK') ?: 'auto',
    // Porty pre TCP kontrolu dostupnosti (MikroTik winbox 8291, web, ssh, telnet)
    'TCP_FALLBACK_PORTS' => [8291, 80, 443, 22, 23, 8728],
    'TCP_TIMEOUT' => 1,   // sekundy na TCP connect

    // Trojstavový monitoring (ako Dude): up=zelená, pending=žltá, down=červená
    'DOWN_AFTER'  => 30,  // po koľkých sekundách nereagovania -> červená (down)
    'USE_FPING'   => true, // rýchly paralelný ping (odporúčané, `apt install fping`)

    // Retencia histórie (dá sa zmeniť aj v Nastaveniach → Meranie toku).
    // Bez nej databáza rastie o stovky tisíc riadkov denne.
    'HISTORY_DAYS'  => 14, // koľko dní držať históriu stavov
    'HISTORY_EVERY' => 60, // ako často vzorkovať odozvu (s); zmena stavu sa zapíše vždy
    'TRAFFIC_DAYS'  => 90, // koľko dní držať históriu toku (grafy Rx/Tx)
];
