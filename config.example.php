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
        'pds'      => 'https://pds.wsocial.network',
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

    // Wie viele Posts pro listRecords-Seite abholen
    'batch_size' => 20,

    // Wie viele Seiten pro Lauf maximal durchblättern.
    // batch_size * max_pages = max. erfassbare neue Posts pro Lauf.
    // Schützt vor Endlos-Schleifen und fängt größere Bursts ab.
    'max_pages' => 25,

    // Wie oft ein fehlgeschlagener Post erneut versucht wird, bevor
    // er endgültig als 'failed' aufgegeben wird (transiente Fehler)
    'max_attempts' => 5,

    // HTTP-Timeout in Sekunden für normale XRPC-Calls …
    'http_timeout' => 30,

    // … und für Blob-Up-/Downloads (Bilder/Videos können größer sein)
    'blob_timeout' => 120,

    // Sofort-Retries bei transienten Fehlern (HTTP 429 / Verbindungsfehler)
    'retry_count' => 2,    // zusätzliche Versuche pro Request
    'retry_delay' => 3,    // Sekunden Pause zwischen den Versuchen

    // Lockdatei – verhindert, dass sich zwei Cron-Läufe überlappen
    'lock_file' => __DIR__ . '/mirror.lock',

    // Log-Datei (relativ zum Script-Verzeichnis)
    'log_file' => __DIR__ . '/mirror.log',

    // Log ab dieser Größe (Bytes) rotieren; 0 = nie rotieren
    'log_max_bytes' => 5000000,
];
