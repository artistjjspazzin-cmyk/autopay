#!/usr/bin/env php
<?php

require_once __DIR__ . '/storage.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$documents = [
    'transactions.json',
    'autopay.json',
    'saved_cards.json',
    'deposits.json',
    'disputes.json',
    'sessions.json',
    'audit_log.json',
    'payment_links.json',
    'webhooks.json',
    'cf_cookies.json',
    'squire_creds.json',
    'squire_token.txt',
    '.cron_last_processed',
];

foreach ($documents as $name) {
    if (!storageHasDocument($name)) {
        fwrite(STDERR, "MISSING {$name}\n");
        exit(1);
    }
    $value = storageReadDocument($name);
    printf("%-24s items=%d\n", $name, storageItemCount($value));
}

$transactions = storageReadDocument('transactions.json', []);
$deposits = storageReadDocument('deposits.json', []);
$autopays = storageReadDocument('autopay.json', []);
$links = storageReadDocument('payment_links.json', []);

$approved = array_filter($transactions, fn($transaction) => ($transaction['status'] ?? '') === 'approved');
$declined = array_filter($transactions, fn($transaction) => ($transaction['status'] ?? '') === 'declined');
$activeAutopays = array_filter($autopays, fn($autopay) => ($autopay['status'] ?? '') === 'active');
$activeLinks = array_filter($links, fn($link) => ($link['status'] ?? '') === 'active');

$approvedTotal = array_sum(array_map(fn($transaction) => (float)($transaction['amount'] ?? 0), $approved));
$depositTotal = array_sum(array_map(fn($deposit) => (float)($deposit['amount'] ?? 0), $deposits));

printf("approved_transactions=%d\n", count($approved));
printf("declined_transactions=%d\n", count($declined));
printf("approved_total=%.2f\n", $approvedTotal);
printf("deposit_total=%.2f\n", $depositTotal);
printf("active_autopays=%d\n", count($activeAutopays));
printf("active_payment_links=%d\n", count($activeLinks));
