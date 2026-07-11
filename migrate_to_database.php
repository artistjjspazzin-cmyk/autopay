#!/usr/bin/env php
<?php

require_once __DIR__ . '/storage.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['data-dir:', 'force', 'verify-only']);
$dataDir = rtrim($options['data-dir'] ?? (__DIR__ . '/data'), '/');
$force = array_key_exists('force', $options);
$verifyOnly = array_key_exists('verify-only', $options);

$documents = [
    'transactions.json' => 'json',
    'autopay.json' => 'json',
    'saved_cards.json' => 'json',
    'deposits.json' => 'json',
    'disputes.json' => 'json',
    'sessions.json' => 'json',
    'audit_log.json' => 'json',
    'payment_links.json' => 'json',
    'webhooks.json' => 'json',
    'cf_cookies.json' => 'json',
    'squire_creds.json' => 'json',
    'squire_token.txt' => 'text',
    '.cron_last_processed' => 'text',
    'stripe_sk.txt' => 'text',
];

storageEnsureSchema();
$errors = [];
$processed = 0;

foreach ($documents as $fileName => $type) {
    $path = $dataDir . '/' . $fileName;
    if (!is_file($path)) {
        echo "SKIP {$fileName} (not present)\n";
        continue;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        $errors[] = "Could not read {$fileName}";
        continue;
    }

    try {
        $value = $type === 'json'
            ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR)
            : trim($raw);
        $payload = storageEncodeDocument($value);
        $expectedHash = hash('sha256', $payload);

        if (!$verifyOnly) {
            if (storageHasDocument($fileName) && !$force) {
                $storedPayload = storageEncodeDocument(storageReadDocument($fileName));
                if (!hash_equals($expectedHash, hash('sha256', $storedPayload))) {
                    throw new RuntimeException("{$fileName} already exists in the database with different content; rerun with --force only after reviewing the difference");
                }
            } else {
                storageWriteDocument($fileName, $value, $type, $path);
            }
        }

        $storedPayload = storageEncodeDocument(storageReadDocument($fileName));
        $storedHash = hash('sha256', $storedPayload);
        if (!hash_equals($expectedHash, $storedHash)) {
            throw new RuntimeException("Verification failed for {$fileName}");
        }

        printf("OK %-24s items=%d sha256=%s\n", $fileName, storageItemCount($value), $storedHash);
        $processed++;
    } catch (Throwable $error) {
        $errors[] = $error->getMessage();
    }
}

if ($errors) {
    foreach ($errors as $error) fwrite(STDERR, "ERROR {$error}\n");
    exit(1);
}

echo "Verified {$processed} database documents; source files were not modified.\n";
