<?php
/**
 * eurosky → wsocial Post Mirror
 * 
 * Spiegelt Top-Level-Posts (keine Replies, keine Reposts) von einem
 * AT Protocol PDS zu einem anderen. Läuft als Cronjob auf All-Inkl.
 * 
 * Cron-Beispiel (alle 5 Min): siehe README
 */

declare(strict_types=1);
error_reporting(E_ALL);

// ─── Config ────────────────────────────────────────────────────────
$config = require __DIR__ . '/config.php';

// ─── Logger ────────────────────────────────────────────────────────
function mirrorLog(string $msg, string $level = 'INFO'): void {
    global $config;
    $line = date('Y-m-d H:i:s') . " [$level] $msg\n";
    file_put_contents($config['log_file'], $line, FILE_APPEND | LOCK_EX);
    if (php_sapi_name() === 'cli') {
        echo $line;
    }
}

// ─── DB ────────────────────────────────────────────────────────────
function getDb(): PDO {
    global $config;
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function ensureTable(): void {
    getDb()->exec("
        CREATE TABLE IF NOT EXISTS `mirror_posts` (
            `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `source_uri`     VARCHAR(512) NOT NULL UNIQUE,
            `source_cid`     VARCHAR(128) NOT NULL,
            `target_uri`     VARCHAR(512) DEFAULT NULL,
            `target_cid`     VARCHAR(128) DEFAULT NULL,
            `created_at`     DATETIME NOT NULL,
            `mirrored_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function isAlreadyMirrored(string $sourceUri): bool {
    $stmt = getDb()->prepare("SELECT 1 FROM mirror_posts WHERE source_uri = ? LIMIT 1");
    $stmt->execute([$sourceUri]);
    return (bool) $stmt->fetchColumn();
}

function recordMirror(string $sourceUri, string $sourceCid, string $createdAt, ?string $targetUri, ?string $targetCid): void {
    $stmt = getDb()->prepare("
        INSERT INTO mirror_posts (source_uri, source_cid, created_at, target_uri, target_cid)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$sourceUri, $sourceCid, $createdAt, $targetUri, $targetCid]);
}

// ─── AT Protocol HTTP Client ──────────────────────────────────────
function xrpc(string $pdsUrl, string $method, string $nsid, ?array $params = null, ?string $token = null, bool $isBlob = false, ?string $blobData = null, ?string $blobMime = null): array|string|null {
    $url = rtrim($pdsUrl, '/') . '/xrpc/' . $nsid;

    $headers = [];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    if ($method === 'GET') {
        if ($params) {
            // ATProto erwartet "true"/"false", nicht "1"/"0"
            $clean = array_map(fn($v) => is_bool($v) ? ($v ? 'true' : 'false') : $v, $params);
            $url .= '?' . http_build_query($clean);
        }
    } elseif ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($blobData !== null) {
            // Blob-Upload: raw binary body
            curl_setopt($ch, CURLOPT_POSTFIELDS, $blobData);
            $headers[] = 'Content-Type: ' . ($blobMime ?: 'application/octet-stream');
        } elseif ($params !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            $headers[] = 'Content-Type: application/json';
        }
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_URL, $url);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        mirrorLog("cURL error: $error", 'ERROR');
        return null;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        mirrorLog("HTTP $httpCode from $nsid: $response", 'ERROR');
        return null;
    }

    // getBlob gibt Binärdaten zurück
    if ($isBlob) {
        return $response;
    }

    return json_decode($response, true);
}

// ─── Auth ──────────────────────────────────────────────────────────
function createSession(string $pdsUrl, string $handle, string $password): ?array {
    $result = xrpc($pdsUrl, 'POST', 'com.atproto.server.createSession', [
        'identifier' => $handle,
        'password'   => $password,
    ]);
    if (!$result || empty($result['accessJwt'])) {
        mirrorLog("Auth fehlgeschlagen für $handle @ $pdsUrl", 'ERROR');
        return null;
    }
    return $result;
}

// ─── Posts holen ───────────────────────────────────────────────────
function fetchRecentPosts(string $pdsUrl, string $did, string $token, int $limit): array {
    $result = xrpc($pdsUrl, 'GET', 'com.atproto.repo.listRecords', [
        'repo'       => $did,
        'collection' => 'app.bsky.feed.post',
        'limit'      => $limit,
    ], $token);

    if (!$result || !isset($result['records'])) {
        return [];
    }

    return $result['records'];
}

// ─── Blob-Handling ─────────────────────────────────────────────────
function reuploadBlob(string $sourcePds, string $sourceDid, string $sourceToken, string $targetPds, string $targetToken, array $blobRef): ?array {
    // CID aus der Blob-Referenz extrahieren
    $cid = $blobRef['ref']['$link'] ?? null;
    if (!$cid) {
        mirrorLog("Blob-Referenz ohne CID", 'WARN');
        return null;
    }

    // Blob vom Quell-PDS laden
    $blobData = xrpc($sourcePds, 'GET', 'com.atproto.sync.getBlob', [
        'did' => $sourceDid,
        'cid' => $cid,
    ], $sourceToken, true);

    if (!$blobData || !is_string($blobData)) {
        mirrorLog("Blob $cid konnte nicht geladen werden", 'ERROR');
        return null;
    }

    $mime = $blobRef['mimeType'] ?? 'application/octet-stream';
    mirrorLog("Blob geladen: $cid (" . strlen($blobData) . " bytes, $mime)");

    // Blob auf Ziel-PDS hochladen
    $uploadResult = xrpc($targetPds, 'POST', 'com.atproto.repo.uploadBlob', null, $targetToken, false, $blobData, $mime);

    if (!$uploadResult || !isset($uploadResult['blob'])) {
        mirrorLog("Blob-Upload fehlgeschlagen", 'ERROR');
        return null;
    }

    mirrorLog("Blob hochgeladen auf Ziel-PDS");
    return $uploadResult['blob'];
}

// ─── Post-Record für Ziel anpassen ─────────────────────────────────
function remapRecord(array $record, string $sourcePds, string $sourceDid, string $sourceToken, string $targetPds, string $targetToken): ?array {
    // Embed-Images: Blobs re-uploaden
    if (isset($record['embed']['$type'])) {
        $embedType = $record['embed']['$type'];

        // Einzel-Bilder
        if ($embedType === 'app.bsky.embed.images' && isset($record['embed']['images'])) {
            foreach ($record['embed']['images'] as $i => $image) {
                if (isset($image['image'])) {
                    $newBlob = reuploadBlob($sourcePds, $sourceDid, $sourceToken, $targetPds, $targetToken, $image['image']);
                    if (!$newBlob) {
                        mirrorLog("Bild $i konnte nicht re-uploaded werden, überspringe Post", 'ERROR');
                        return null;
                    }
                    $record['embed']['images'][$i]['image'] = $newBlob;
                }
            }
        }

        // Video
        if ($embedType === 'app.bsky.embed.video' && isset($record['embed']['video'])) {
            $newBlob = reuploadBlob($sourcePds, $sourceDid, $sourceToken, $targetPds, $targetToken, $record['embed']['video']);
            if (!$newBlob) {
                mirrorLog("Video konnte nicht re-uploaded werden, überspringe Post", 'WARN');
                return null;
            }
            $record['embed']['video'] = $newBlob;
        }

        // Record-with-media (z.B. Zitat + Bilder)
        if ($embedType === 'app.bsky.embed.recordWithMedia' && isset($record['embed']['media'])) {
            $mediaType = $record['embed']['media']['$type'] ?? '';
            if ($mediaType === 'app.bsky.embed.images' && isset($record['embed']['media']['images'])) {
                foreach ($record['embed']['media']['images'] as $i => $image) {
                    if (isset($image['image'])) {
                        $newBlob = reuploadBlob($sourcePds, $sourceDid, $sourceToken, $targetPds, $targetToken, $image['image']);
                        if (!$newBlob) {
                            return null;
                        }
                        $record['embed']['media']['images'][$i]['image'] = $newBlob;
                    }
                }
            }
        }

        // External embed (Link-Card) mit Thumbnail
        if ($embedType === 'app.bsky.embed.external' && isset($record['embed']['external']['thumb'])) {
            $newBlob = reuploadBlob($sourcePds, $sourceDid, $sourceToken, $targetPds, $targetToken, $record['embed']['external']['thumb']);
            if ($newBlob) {
                $record['embed']['external']['thumb'] = $newBlob;
            } else {
                // Thumb ist optional – Post trotzdem spiegeln, nur ohne Preview-Bild
                unset($record['embed']['external']['thumb']);
                mirrorLog("Link-Card Thumb konnte nicht übertragen werden, poste ohne", 'WARN');
            }
        }
    }

    return $record;
}

// ─── Post erstellen ────────────────────────────────────────────────
function createPost(string $pdsUrl, string $did, string $token, array $record): ?array {
    return xrpc($pdsUrl, 'POST', 'com.atproto.repo.createRecord', [
        'repo'       => $did,
        'collection' => 'app.bsky.feed.post',
        'record'     => $record,
    ], $token);
}

// ─── Hauptlogik ────────────────────────────────────────────────────
function main(): void {
    global $config;

    mirrorLog("=== Mirror-Lauf gestartet ===");

    // DB vorbereiten
    ensureTable();

    // Auth auf beiden Seiten
    $sourceSession = createSession(
        $config['source']['pds'],
        $config['source']['handle'],
        $config['source']['password']
    );
    if (!$sourceSession) {
        mirrorLog("Abbruch: Source-Auth fehlgeschlagen", 'ERROR');
        return;
    }
    $sourceDid   = $sourceSession['did'];
    $sourceToken = $sourceSession['accessJwt'];
    mirrorLog("Source-Auth OK: $sourceDid");

    $targetSession = createSession(
        $config['target']['pds'],
        $config['target']['handle'],
        $config['target']['password']
    );
    if (!$targetSession) {
        mirrorLog("Abbruch: Target-Auth fehlgeschlagen", 'ERROR');
        return;
    }
    $targetDid   = $targetSession['did'];
    $targetToken = $targetSession['accessJwt'];
    mirrorLog("Target-Auth OK: $targetDid");

    // Posts holen
    $posts = fetchRecentPosts(
        $config['source']['pds'],
        $sourceDid,
        $sourceToken,
        $config['batch_size']
    );
    mirrorLog(count($posts) . " Posts abgerufen");

    $mirrored = 0;
    $skipped  = 0;

    foreach ($posts as $post) {
        $uri = $post['uri'] ?? '';
        $cid = $post['cid'] ?? '';
        $val = $post['value'] ?? [];

        // Nur Top-Level-Posts (kein reply-Feld)
        if (isset($val['reply'])) {
            $skipped++;
            continue;
        }

        // Schon gespiegelt?
        if (isAlreadyMirrored($uri)) {
            $skipped++;
            continue;
        }

        $createdAt = $val['createdAt'] ?? date('c');
        mirrorLog("Spiegele: $uri");

        // Record für Ziel-PDS anpassen (Blobs re-uploaden)
        $remapped = remapRecord(
            $val,
            $config['source']['pds'], $sourceDid, $sourceToken,
            $config['target']['pds'], $targetToken
        );

        if ($remapped === null) {
            mirrorLog("Übersprungen (Blob-Fehler): $uri", 'WARN');
            continue;
        }

        // Post auf Ziel erstellen
        $result = createPost(
            $config['target']['pds'],
            $targetDid,
            $targetToken,
            $remapped
        );

        $targetUri = $result['uri'] ?? null;
        $targetCid = $result['cid'] ?? null;

        if ($result && $targetUri) {
            mirrorLog("Gespiegelt: $uri → $targetUri");
            $mirrored++;
        } else {
            mirrorLog("Erstellen fehlgeschlagen für: $uri", 'ERROR');
        }

        // In jedem Fall tracken, damit wir es nicht endlos neu versuchen
        recordMirror($uri, $cid, $createdAt, $targetUri, $targetCid);
    }

    mirrorLog("Fertig: $mirrored gespiegelt, $skipped übersprungen");
}

// ─── Run (nur wenn direkt aufgerufen) ──────────────────────────────
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    try {
        main();
    } catch (Throwable $e) {
        mirrorLog("FATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString(), 'ERROR');
        exit(1);
    }
}
