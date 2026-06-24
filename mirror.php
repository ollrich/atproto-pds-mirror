<?php
/**
 * eurosky → wsocial Post Mirror
 *
 * Spiegelt Top-Level-Posts (keine Replies, keine Reposts) von einem
 * AT Protocol PDS zu einem anderen. Läuft als Cronjob auf All-Inkl.
 *
 * Robustheit:
 *  - Status-/Retry-Tracking je Post (transiente Fehler werden erneut versucht)
 *  - Lock gegen sich überlappende Cron-Läufe (verhindert Doppel-Posts)
 *  - Cursor-Pagination, damit auch größere Bursts vollständig erfasst werden
 *  - Sofort-Retries mit Backoff bei 429 / Verbindungsfehlern
 *
 * Cron-Beispiel (alle 5 Min): siehe README
 */

declare(strict_types=1);
error_reporting(E_ALL);

// ─── Config ────────────────────────────────────────────────────────
$config = require __DIR__ . '/config.php';

// Letzter XRPC-Fehler, damit er bei Fehlschlägen in der DB landen kann
$GLOBALS['mirror_last_error'] = null;

// ─── Logger ────────────────────────────────────────────────────────
function mirrorLog(string $msg, string $level = 'INFO'): void {
    global $config;
    static $rotated = false;

    $file = $config['log_file'];

    // Einmal pro Lauf prüfen, ob das Log rotiert werden muss
    if (!$rotated) {
        $rotated  = true;
        $maxBytes = $config['log_max_bytes'] ?? 5_000_000;
        if ($maxBytes > 0 && is_file($file) && filesize($file) > $maxBytes) {
            @rename($file, $file . '.1');   // eine Vorgängergeneration behalten
        }
    }

    $line = date('Y-m-d H:i:s') . " [$level] $msg\n";
    file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    if (php_sapi_name() === 'cli') {
        echo $line;
    }
}

function lastError(): ?string {
    return $GLOBALS['mirror_last_error'] ?? null;
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

function columnExists(string $table, string $column): bool {
    $stmt = getDb()->prepare("
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function indexExists(string $table, string $index): bool {
    $stmt = getDb()->prepare("
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$table, $index]);
    return (bool) $stmt->fetchColumn();
}

function ensureTable(): void {
    $db = getDb();
    $db->exec("
        CREATE TABLE IF NOT EXISTS `mirror_posts` (
            `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `source_uri`     VARCHAR(512) NOT NULL UNIQUE,
            `source_cid`     VARCHAR(128) NOT NULL,
            `target_uri`     VARCHAR(512) DEFAULT NULL,
            `target_cid`     VARCHAR(128) DEFAULT NULL,
            `status`         VARCHAR(16) NOT NULL DEFAULT 'mirrored',
            `attempts`       INT UNSIGNED NOT NULL DEFAULT 0,
            `last_error`     VARCHAR(512) DEFAULT NULL,
            `created_at`     DATETIME NOT NULL,
            `mirrored_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_created` (`created_at`),
            INDEX `idx_status`  (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Migration für ältere Installationen ohne status/attempts/last_error
    $addedStatus = false;
    $columns = [
        'status'     => "ADD COLUMN `status` VARCHAR(16) NOT NULL DEFAULT 'mirrored' AFTER `target_cid`",
        'attempts'   => "ADD COLUMN `attempts` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `status`",
        'last_error' => "ADD COLUMN `last_error` VARCHAR(512) DEFAULT NULL AFTER `attempts`",
    ];
    foreach ($columns as $column => $ddl) {
        if (!columnExists('mirror_posts', $column)) {
            $db->exec("ALTER TABLE `mirror_posts` $ddl");
            if ($column === 'status') {
                $addedStatus = true;
            }
        }
    }
    if (!indexExists('mirror_posts', 'idx_status')) {
        // Index separat anlegen, falls die Tabelle vor dieser Version existierte
        $db->exec("ALTER TABLE `mirror_posts` ADD INDEX `idx_status` (`status`)");
    }

    // Direkt nach der Migration: bestehende ungespiegelte Zeilen waren Seeds
    if ($addedStatus) {
        $db->exec("UPDATE `mirror_posts` SET `status` = 'seeded' WHERE `target_uri` IS NULL");
    }
}

function getRecord(string $sourceUri): ?array {
    $stmt = getDb()->prepare("SELECT status, attempts FROM mirror_posts WHERE source_uri = ? LIMIT 1");
    $stmt->execute([$sourceUri]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Soll dieser Post (anhand seines DB-Zustands) verarbeitet werden?
 *  - kein Eintrag        → ja, neu spiegeln
 *  - status = failed     → ja, solange attempts < max_attempts (transienter Fehler)
 *  - sonst (seeded/mirrored/aufgegeben) → nein
 */
function shouldProcess(?array $row, int $maxAttempts): bool {
    if ($row === null) {
        return true;
    }
    if ($row['status'] === 'failed' && (int) $row['attempts'] < $maxAttempts) {
        return true;
    }
    return false;
}

// ISO-8601 (z.B. 2026-06-24T10:30:00.123Z) → MySQL DATETIME
function normalizeDatetime(string $value): string {
    $ts = strtotime($value);
    return date('Y-m-d H:i:s', $ts !== false ? $ts : time());
}

function markSeeded(string $sourceUri, string $sourceCid, string $createdAt): void {
    $stmt = getDb()->prepare("
        INSERT IGNORE INTO mirror_posts (source_uri, source_cid, created_at, status, attempts)
        VALUES (?, ?, ?, 'seeded', 0)
    ");
    $stmt->execute([$sourceUri, $sourceCid, normalizeDatetime($createdAt)]);
}

/**
 * Ergebnis eines Spiegelversuchs festhalten (Upsert).
 * Bei erneutem Versuch wird attempts hochgezählt statt zu duplizieren.
 */
function recordAttempt(string $sourceUri, string $sourceCid, string $createdAt, ?string $targetUri, ?string $targetCid, string $status, ?string $error): void {
    $stmt = getDb()->prepare("
        INSERT INTO mirror_posts (source_uri, source_cid, created_at, target_uri, target_cid, status, attempts, last_error)
        VALUES (?, ?, ?, ?, ?, ?, 1, ?)
        ON DUPLICATE KEY UPDATE
            target_uri  = VALUES(target_uri),
            target_cid  = VALUES(target_cid),
            status      = VALUES(status),
            attempts    = attempts + 1,
            last_error  = VALUES(last_error),
            mirrored_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        $sourceUri,
        $sourceCid,
        normalizeDatetime($createdAt),
        $targetUri,
        $targetCid,
        $status,
        $error !== null ? mb_substr($error, 0, 500) : null,
    ]);
}

// ─── Lock (verhindert überlappende Läufe) ─────────────────────────
function acquireLock(): mixed {
    global $config;
    $lockFile = $config['lock_file'] ?? (__DIR__ . '/mirror.lock');
    $fh = fopen($lockFile, 'c');
    if ($fh === false) {
        mirrorLog("Lockdatei $lockFile nicht öffenbar, fahre ohne Lock fort", 'WARN');
        return null;   // kein Lock möglich, aber Lauf nicht blockieren
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return false;  // anderer Lauf hält den Lock
    }
    return $fh;        // offen halten für die Dauer des Laufs
}

// ─── AT Protocol HTTP Client ──────────────────────────────────────
function xrpc(string $pdsUrl, string $method, string $nsid, ?array $params = null, ?string $token = null, bool $isBlob = false, ?string $blobData = null, ?string $blobMime = null, int $timeout = 0): array|string|null {
    global $config;
    $url = rtrim($pdsUrl, '/') . '/xrpc/' . $nsid;

    if ($timeout <= 0) {
        $timeout = (int) ($config['http_timeout'] ?? 30);
    }
    $maxAttempts = max(1, (int) ($config['retry_count'] ?? 2) + 1);
    $retryDelay  = max(0, (int) ($config['retry_delay'] ?? 3));

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $headers = [];
        if ($token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $requestUrl = $url;
        if ($method === 'GET') {
            if ($params) {
                // ATProto erwartet "true"/"false", nicht "1"/"0"
                $clean = array_map(fn($v) => is_bool($v) ? ($v ? 'true' : 'false') : $v, $params);
                $requestUrl .= '?' . http_build_query($clean);
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
        curl_setopt($ch, CURLOPT_URL, $requestUrl);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        $isLastAttempt = ($attempt === $maxAttempts);

        if ($error) {
            $GLOBALS['mirror_last_error'] = "cURL: $error";
            // Verbindungsfehler nur bei GET wiederholen – ein POST könnte
            // bereits angekommen sein und würde sonst doppelt ausgeführt
            if (!$isLastAttempt && $method === 'GET') {
                mirrorLog("cURL-Fehler (Versuch $attempt/$maxAttempts), neuer Versuch: $error", 'WARN');
                if ($retryDelay > 0) { sleep($retryDelay); }
                continue;
            }
            mirrorLog("cURL error: $error", 'ERROR');
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $GLOBALS['mirror_last_error'] = "HTTP $httpCode: " . mb_substr((string) $response, 0, 300);
            // 429 (Rate-Limit) hatte garantiert keinen Effekt → immer wiederholbar.
            // 5xx nur bei GET wiederholen (POST könnte serverseitig angekommen sein).
            $retryable = ($httpCode === 429) || ($method === 'GET' && $httpCode >= 500);
            if ($retryable && !$isLastAttempt) {
                mirrorLog("HTTP $httpCode von $nsid (Versuch $attempt/$maxAttempts), neuer Versuch", 'WARN');
                if ($retryDelay > 0) { sleep($retryDelay); }
                continue;
            }
            mirrorLog("HTTP $httpCode from $nsid: $response", 'ERROR');
            return null;
        }

        $GLOBALS['mirror_last_error'] = null;

        // getBlob gibt Binärdaten zurück
        if ($isBlob) {
            return $response;
        }
        return json_decode($response, true);
    }

    return null;
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
// Schlanke Variante für test.php: nur die neuesten N Records.
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

/**
 * Sammelt alle zu spiegelnden Posts (neu oder erneut zu versuchen) ein.
 * Blättert per Cursor durch die Historie, bis ein bereits bekannter Post
 * auftaucht (= aufgeholt) oder das Seitenlimit erreicht ist.
 * Ergebnis ist chronologisch (ältester zuerst), damit ein abgebrochener
 * Lauf einen lückenlosen, geordneten Anfang hinterlässt.
 */
function collectNewPosts(string $pdsUrl, string $did, string $token, int $maxAttempts, int $pageSize, int $maxPages): array {
    $cursor    = null;
    $pages     = 0;
    $toProcess = [];

    do {
        $params = [
            'repo'       => $did,
            'collection' => 'app.bsky.feed.post',
            'limit'      => $pageSize,
        ];
        if ($cursor) {
            $params['cursor'] = $cursor;
        }

        $result = xrpc($pdsUrl, 'GET', 'com.atproto.repo.listRecords', $params, $token);
        if (!$result || empty($result['records'])) {
            break;
        }

        $reachedKnown = false;
        foreach ($result['records'] as $post) {
            $uri = $post['uri'] ?? '';

            // Replies/Reposts werden nie gespiegelt und zählen nicht als Grenze
            if (isset($post['value']['reply'])) {
                continue;
            }

            $row = getRecord($uri);
            if ($row !== null) {
                $reachedKnown = true;   // ab hier kennen wir die Historie bereits
            }
            if (shouldProcess($row, $maxAttempts)) {
                $toProcess[] = $post;
            }
        }

        $cursor = $result['cursor'] ?? null;
        $pages++;

        if ($reachedKnown) {
            break;
        }
    } while ($cursor && $pages < $maxPages);

    return array_reverse($toProcess);   // ältester zuerst
}

// ─── Blob-Handling ─────────────────────────────────────────────────
function reuploadBlob(string $sourcePds, string $sourceDid, string $sourceToken, string $targetPds, string $targetToken, array $blobRef): ?array {
    global $config;
    $blobTimeout = (int) ($config['blob_timeout'] ?? 120);

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
    ], $sourceToken, true, null, null, $blobTimeout);

    if (!$blobData || !is_string($blobData)) {
        mirrorLog("Blob $cid konnte nicht geladen werden", 'ERROR');
        return null;
    }

    $mime = $blobRef['mimeType'] ?? 'application/octet-stream';
    mirrorLog("Blob geladen: $cid (" . strlen($blobData) . " bytes, $mime)");

    // Blob auf Ziel-PDS hochladen
    $uploadResult = xrpc($targetPds, 'POST', 'com.atproto.repo.uploadBlob', null, $targetToken, false, $blobData, $mime, $blobTimeout);

    if (!$uploadResult || !isset($uploadResult['blob'])) {
        mirrorLog("Blob-Upload fehlgeschlagen", 'ERROR');
        return null;
    }

    mirrorLog("Blob hochgeladen auf Ziel-PDS");
    return $uploadResult['blob'];
}

// Bild-Embed (auch innerhalb recordWithMedia): alle Bild-Blobs re-uploaden
function remapImagesEmbed(array $imagesEmbed, string $sourcePds, string $sourceDid, string $sourceToken, string $targetPds, string $targetToken): ?array {
    if (!isset($imagesEmbed['images'])) {
        return $imagesEmbed;
    }
    foreach ($imagesEmbed['images'] as $i => $image) {
        if (!isset($image['image'])) {
            continue;
        }
        $newBlob = reuploadBlob($sourcePds, $sourceDid, $sourceToken, $targetPds, $targetToken, $image['image']);
        if (!$newBlob) {
            mirrorLog("Bild $i konnte nicht re-uploaded werden, überspringe Post", 'ERROR');
            return null;
        }
        $imagesEmbed['images'][$i]['image'] = $newBlob;
    }
    return $imagesEmbed;
}

// Video-Embed (auch innerhalb recordWithMedia): Video- und Untertitel-Blobs re-uploaden
function remapVideoEmbed(array $videoEmbed, string $sourcePds, string $sourceDid, string $sourceToken, string $targetPds, string $targetToken): ?array {
    if (isset($videoEmbed['video'])) {
        $newBlob = reuploadBlob($sourcePds, $sourceDid, $sourceToken, $targetPds, $targetToken, $videoEmbed['video']);
        if (!$newBlob) {
            mirrorLog("Video konnte nicht re-uploaded werden, überspringe Post", 'WARN');
            return null;
        }
        $videoEmbed['video'] = $newBlob;
    }

    // Untertitel-Tracks (captions[].file) sind ebenfalls Blobs
    if (!empty($videoEmbed['captions'])) {
        foreach ($videoEmbed['captions'] as $i => $caption) {
            if (!isset($caption['file'])) {
                continue;
            }
            $capBlob = reuploadBlob($sourcePds, $sourceDid, $sourceToken, $targetPds, $targetToken, $caption['file']);
            if ($capBlob) {
                $videoEmbed['captions'][$i]['file'] = $capBlob;
            } else {
                // Untertitel sind optional – Post trotzdem spiegeln
                unset($videoEmbed['captions'][$i]);
                mirrorLog("Untertitel-Track konnte nicht übertragen werden, lasse weg", 'WARN');
            }
        }
        $videoEmbed['captions'] = array_values($videoEmbed['captions']);
    }

    return $videoEmbed;
}

// ─── Post-Record für Ziel anpassen ─────────────────────────────────
function remapRecord(array $record, string $sourcePds, string $sourceDid, string $sourceToken, string $targetPds, string $targetToken): ?array {
    if (!isset($record['embed']['$type'])) {
        return $record;
    }

    $embedType = $record['embed']['$type'];

    // Einzel-/Mehrfach-Bilder
    if ($embedType === 'app.bsky.embed.images') {
        $remapped = remapImagesEmbed($record['embed'], $sourcePds, $sourceDid, $sourceToken, $targetPds, $targetToken);
        if ($remapped === null) {
            return null;
        }
        $record['embed'] = $remapped;
    }

    // Video (inkl. Untertitel)
    if ($embedType === 'app.bsky.embed.video') {
        $remapped = remapVideoEmbed($record['embed'], $sourcePds, $sourceDid, $sourceToken, $targetPds, $targetToken);
        if ($remapped === null) {
            return null;
        }
        $record['embed'] = $remapped;
    }

    // Record-with-media (Zitat + Bilder ODER Zitat + Video)
    if ($embedType === 'app.bsky.embed.recordWithMedia' && isset($record['embed']['media'])) {
        $mediaType = $record['embed']['media']['$type'] ?? '';
        if ($mediaType === 'app.bsky.embed.images') {
            $remapped = remapImagesEmbed($record['embed']['media'], $sourcePds, $sourceDid, $sourceToken, $targetPds, $targetToken);
            if ($remapped === null) {
                return null;
            }
            $record['embed']['media'] = $remapped;
        } elseif ($mediaType === 'app.bsky.embed.video') {
            $remapped = remapVideoEmbed($record['embed']['media'], $sourcePds, $sourceDid, $sourceToken, $targetPds, $targetToken);
            if ($remapped === null) {
                return null;
            }
            $record['embed']['media'] = $remapped;
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

    // Überlappende Läufe verhindern
    $lock = acquireLock();
    if ($lock === false) {
        mirrorLog("Ein anderer Mirror-Lauf ist noch aktiv, überspringe diesen Lauf", 'WARN');
        return;
    }

    mirrorLog("=== Mirror-Lauf gestartet ===");

    // DB vorbereiten
    ensureTable();

    $maxAttempts = (int) ($config['max_attempts'] ?? 5);
    $pageSize    = (int) ($config['batch_size']   ?? 20);
    $maxPages    = (int) ($config['max_pages']    ?? 25);

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

    // Zu spiegelnde Posts einsammeln (neu oder erneut zu versuchen),
    // über mehrere Seiten, damit auch größere Bursts erfasst werden
    $posts = collectNewPosts($config['source']['pds'], $sourceDid, $sourceToken, $maxAttempts, $pageSize, $maxPages);
    mirrorLog(count($posts) . " zu spiegelnde Posts gefunden");

    $mirrored = 0;
    $failed   = 0;

    foreach ($posts as $post) {
        $uri = $post['uri'] ?? '';
        $cid = $post['cid'] ?? '';
        $val = $post['value'] ?? [];
        $createdAt = $val['createdAt'] ?? date('c');

        mirrorLog("Spiegele: $uri");

        // Record für Ziel-PDS anpassen (Blobs re-uploaden)
        $remapped = remapRecord(
            $val,
            $config['source']['pds'], $sourceDid, $sourceToken,
            $config['target']['pds'], $targetToken
        );

        if ($remapped === null) {
            // Blob-Fehler: als fehlgeschlagen vermerken (wird erneut versucht)
            recordAttempt($uri, $cid, $createdAt, null, null, 'failed', lastError() ?? 'Blob-Übertragung fehlgeschlagen');
            mirrorLog("Fehlgeschlagen (Blob): $uri", 'WARN');
            $failed++;
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
            recordAttempt($uri, $cid, $createdAt, $targetUri, $targetCid, 'mirrored', null);
            mirrorLog("Gespiegelt: $uri → $targetUri");
            $mirrored++;
        } else {
            // Fehlschlag wird vermerkt, aber NICHT endgültig – nächster Lauf
            // versucht es erneut, bis max_attempts erreicht ist
            recordAttempt($uri, $cid, $createdAt, null, null, 'failed', lastError() ?? 'createRecord fehlgeschlagen');
            mirrorLog("Erstellen fehlgeschlagen für: $uri", 'ERROR');
            $failed++;
        }
    }

    mirrorLog("Fertig: $mirrored gespiegelt, $failed fehlgeschlagen");

    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
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
