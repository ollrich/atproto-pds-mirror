<?php
/**
 * Seed – Bestehende Posts als "bereits gespiegelt" markieren
 * 
 * EINMAL vor dem ersten Cronjob-Lauf ausführen:
 *   php seed.php
 * 
 * Lädt alle bisherigen Posts vom Source-PDS und trägt sie
 * in die DB ein, OHNE sie auf wsocial zu posten.
 * Danach erkennt mirror.php nur noch neue Posts.
 */

declare(strict_types=1);
error_reporting(E_ALL);

$config = require __DIR__ . '/config.php';
require __DIR__ . '/mirror.php';

echo "── Tabelle vorbereiten\n";
ensureTable();

echo "── Source-Auth\n";
$src = createSession($config['source']['pds'], $config['source']['handle'], $config['source']['password']);
if (!$src) {
    echo "   FEHLER: Auth fehlgeschlagen\n";
    exit(1);
}
echo "   OK – DID: {$src['did']}\n";

// Alle Posts paginated laden
$cursor  = null;
$total   = 0;
$skipped = 0;

echo "── Posts laden und als geseedet markieren\n";

do {
    $params = [
        'repo'       => $src['did'],
        'collection' => 'app.bsky.feed.post',
        'limit'      => 100,
    ];
    if ($cursor) {
        $params['cursor'] = $cursor;
    }

    $result = xrpc($config['source']['pds'], 'GET', 'com.atproto.repo.listRecords', $params, $src['accessJwt']);
    if (!$result || !isset($result['records'])) {
        break;
    }

    foreach ($result['records'] as $post) {
        $uri       = $post['uri'] ?? '';
        $cid       = $post['cid'] ?? '';
        $createdAt = $post['value']['createdAt'] ?? date('c');

        if (getRecord($uri) !== null) {
            $skipped++;
            continue;
        }

        // Als geseedet markieren (status = 'seeded', wird nie gespiegelt)
        markSeeded($uri, $cid, $createdAt);
        $total++;
    }

    $cursor = $result['cursor'] ?? null;
    echo "   $total Posts markiert...\r";

} while ($cursor);

echo "\n── Fertig: $total Posts als geseedet eingetragen, $skipped waren schon drin\n";
echo "   Du kannst jetzt den Cronjob einrichten.\n";
