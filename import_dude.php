<?php
/** CLI import:  php import_dude.php [cesta/k/dude.db] */
require __DIR__ . '/cli_guard.php';   // len z príkazového riadka, nie ako root
require __DIR__ . '/importer.php';
$path = $argv[1] ?? cfg('DUDE_DB_PATH');
fwrite(STDERR, "Importujem z: $path\n");
$r = dude_import($path);
printf("Hotovo: %d máp, %d typov, %d sond, %d zariadení, %d služieb, %d uzlov, %d spojov.\n",
    $r['maps'],$r['types'],$r['probes'],$r['devices'],$r['services'],$r['nodes'],$r['links']);
