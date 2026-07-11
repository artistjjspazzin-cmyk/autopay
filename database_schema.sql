CREATE TABLE IF NOT EXISTS app_documents (
    document_name VARCHAR(100) NOT NULL PRIMARY KEY,
    document_type ENUM('json', 'text') NOT NULL DEFAULT 'json',
    payload LONGTEXT NOT NULL,
    content_sha256 CHAR(64) NOT NULL,
    item_count INT UNSIGNED NOT NULL DEFAULT 0,
    source_file VARCHAR(255) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT app_documents_payload_json CHECK (JSON_VALID(payload))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
