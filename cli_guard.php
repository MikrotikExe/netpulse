<?php
/** Strážca CLI skriptov (monitor, SNMP poller, cleanup, diag, import).
 *  Vkladá sa ako PRVÝ – ešte pred db.php, ktorý otvára databázu už pri načítaní.
 *   1) cez web (nginx/php-fpm) sa tieto skripty spustiť nedajú – bez prihlásenia by
 *      spustili monitoring, poslali Telegram správy alebo vypísali údaje o sieti;
 *   2) nesmú bežať ako root nad databázou iného vlastníka – vytvorili by súbory
 *      -wal/-shm patriace rootovi a web aj monitor by dostali „readonly database". */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
(function () {
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) return;
    $c = @include __DIR__ . '/config.php';
    if (!is_array($c) || ($c['DB_DRIVER'] ?? 'sqlite') === 'mysql') return;
    $f = $c['SQLITE_PATH'] ?? '';
    if (!$f || !is_file($f)) return;
    $owner = fileowner($f);
    if ($owner === 0) return;
    $name = function_exists('posix_getpwuid') ? (posix_getpwuid($owner)['name'] ?? $owner) : $owner;
    fwrite(STDERR, "Nespúšťaj ako root – databáza patrí používateľovi '$name'.\n"
                 . "Použi: sudo -u $name php " . basename($_SERVER['argv'][0] ?? 'skript.php') . "\n");
    exit(1);
})();
