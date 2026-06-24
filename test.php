<?php
/**
 * Mirror Setup-Test
 * 
 * Einmal manuell ausführen um zu prüfen:
 * - DB-Verbindung
 * - Tabelle anlegen
 * - Auth auf beiden PDSen
 * - Letzten Post vom Source lesen
 * 
 * Aufruf: php test.php
 */

declare(strict_types=1);
error_reporting(E_ALL);

$config = require __DIR__ . '/config.php';
require __DIR__ . '/mirror.php';

echo "── DB-Verbindung\n";
try {
    $pdo = getDb();
    echo "   OK\n";
} catch (Throwable $e) {
    echo "   FEHLER: " . $e->getMessage() . "\n";
    exit(1);
}

echo "── Tabelle anlegen\n";
ensureTable();
echo "   OK\n";

echo "── Source-Auth ({$config['source']['pds']})\n";
$src = createSession($config['source']['pds'], $config['source']['handle'], $config['source']['password']);
if ($src) {
    echo "   OK – DID: {$src['did']}\n";
} else {
    echo "   FEHLER\n";
    exit(1);
}

echo "── Target-Auth ({$config['target']['pds']})\n";
$tgt = createSession($config['target']['pds'], $config['target']['handle'], $config['target']['password']);
if ($tgt) {
    echo "   OK – DID: {$tgt['did']}\n";
} else {
    echo "   FEHLER\n";
    exit(1);
}

echo "── Letzter Post vom Source\n";
$posts = fetchRecentPosts($config['source']['pds'], $src['did'], $src['accessJwt'], 1);
if (!empty($posts)) {
    $p = $posts[0];
    $text = mb_substr($p['value']['text'] ?? '(kein Text)', 0, 80);
    $isReply = isset($p['value']['reply']) ? ' [REPLY]' : ' [TOP-LEVEL]';
    $hasEmbed = isset($p['value']['embed']) ? ' [EMBED: ' . ($p['value']['embed']['$type'] ?? '?') . ']' : '';
    echo "   $text$isReply$hasEmbed\n";
    echo "   URI: {$p['uri']}\n";
} else {
    echo "   Keine Posts gefunden\n";
}

echo "\n✓ Alles OK. Cron einrichten:\n";
echo "  */5 * * * * php " . __DIR__ . "/mirror.php\n";
