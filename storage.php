<?php

function storageLoadConfig(): void {
    if (getenv('AUTOPAY_DB_HOST') !== false) return;

    $configPath = getenv('AUTOPAY_DB_CONFIG') ?: '/etc/autopay/database.env';
    if (!is_readable($configPath)) {
        throw new RuntimeException('Database configuration is unavailable');
    }

    $values = parse_ini_file($configPath, false, INI_SCANNER_RAW);
    if (!is_array($values)) {
        throw new RuntimeException('Database configuration is invalid');
    }

    foreach ($values as $name => $value) {
        if (getenv($name) === false) putenv($name . '=' . $value);
    }
}

function storagePdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    storageLoadConfig();
    $host = getenv('AUTOPAY_DB_HOST') ?: '127.0.0.1';
    $port = getenv('AUTOPAY_DB_PORT') ?: '3306';
    $database = getenv('AUTOPAY_DB_NAME') ?: 'autopay';
    $user = getenv('AUTOPAY_DB_USER') ?: 'autopay';
    $password = getenv('AUTOPAY_DB_PASSWORD') ?: '';

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    return $pdo;
}

function storageEnsureSchema(): void {
    storagePdo()->exec(
        "CREATE TABLE IF NOT EXISTS app_documents (
            document_name VARCHAR(100) NOT NULL PRIMARY KEY,
            document_type ENUM('json', 'text') NOT NULL DEFAULT 'json',
            payload LONGTEXT NOT NULL,
            content_sha256 CHAR(64) NOT NULL,
            item_count INT UNSIGNED NOT NULL DEFAULT 0,
            source_file VARCHAR(255) NULL,
            created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            CONSTRAINT app_documents_payload_json CHECK (JSON_VALID(payload))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin"
    );
}

function storageDocumentNameForPath(string $path): string {
    return basename($path);
}

function storageEncodeDocument(mixed $value): string {
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function storageItemCount(mixed $value): int {
    if (is_array($value)) return count($value);
    if (is_string($value)) return $value === '' ? 0 : 1;
    return $value === null ? 0 : 1;
}

function storageHasDocument(string $name): bool {
    $stmt = storagePdo()->prepare('SELECT 1 FROM app_documents WHERE document_name = ?');
    $stmt->execute([$name]);
    return (bool)$stmt->fetchColumn();
}

function storageReadDocument(string $name, mixed $default = []): mixed {
    $stmt = storagePdo()->prepare('SELECT payload FROM app_documents WHERE document_name = ?');
    $stmt->execute([$name]);
    $payload = $stmt->fetchColumn();
    if ($payload === false) return $default;
    return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
}

function storageReadText(string $name, string $default = ''): string {
    $value = storageReadDocument($name, $default);
    return is_string($value) ? $value : $default;
}

function storageWriteDocument(string $name, mixed $value, string $type = 'json', ?string $sourceFile = null): void {
    $payload = storageEncodeDocument($value);
    $stmt = storagePdo()->prepare(
        'INSERT INTO app_documents (document_name, document_type, payload, content_sha256, item_count, source_file)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE document_type = VALUES(document_type), payload = VALUES(payload),
             content_sha256 = VALUES(content_sha256), item_count = VALUES(item_count),
             source_file = COALESCE(VALUES(source_file), source_file)'
    );
    $stmt->execute([$name, $type, $payload, hash('sha256', $payload), storageItemCount($value), $sourceFile]);
}

function storageWriteText(string $name, string $value, ?string $sourceFile = null): void {
    storageWriteDocument($name, $value, 'text', $sourceFile);
}

function storageMutateDocument(string $name, mixed $default, callable $mutator, string $type = 'json'): mixed {
    $pdo = storagePdo();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT payload FROM app_documents WHERE document_name = ? FOR UPDATE');
        $stmt->execute([$name]);
        $payload = $stmt->fetchColumn();
        $value = $payload === false ? $default : json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $value = $mutator($value);
        storageWriteDocument($name, $value, $type);
        $pdo->commit();
        return $value;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
