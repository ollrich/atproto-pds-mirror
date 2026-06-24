<?php
/**
 * eurosky → wsocial Mirror – Konfiguration
 * 
 * Kopiere diese Datei nach config.php und trage deine Zugangsdaten ein.
 * config.php NICHT ins Repo committen!
 */

return [
    // Quell-PDS (eurosky)
    'source' => [
        'pds'      => 'https://eurosky.social',
        'handle'   => 'eichhof.me',           // dein Handle auf eurosky
        'password' => 'DEIN_APP_PASSWORD',      // App Password, NICHT dein Login-PW
    ],

    // Ziel-PDS (wsocial)
    'target' => [
        'pds'      => 'https://wsocial.eu',
        'handle'   => 'eichhof.me',            // dein Handle auf wsocial
        'password' => 'DEIN_APP_PASSWORD',
    ],

    // MySQL (All-Inkl)
    'db' => [
        'host' => 'localhost',
        'name' => 'db_XXXXXX',
        'user' => 'db_XXXXXX',
        'pass' => 'DEIN_DB_PASSWORT',
    ],

    // Wie viele Posts pro Durchlauf maximal abholen
    'batch_size' => 20,

    // Log-Datei (relativ zum Script-Verzeichnis)
    'log_file' => __DIR__ . '/mirror.log',
];
