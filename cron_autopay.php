#!/usr/bin/env php
<?php
require_once __DIR__ . '/storage.php';

/**
 * Autopay Cron Processor
 * Run daily via cron: * /5 * * * * php /var/www/html/autopay/cron_autopay.php >> /var/log/autopay.log 2>&1
 * Processes all due autopay subscriptions
 */

$SQUIRE_API_BASE = getenv('SQUIRE_API_BASE') ?: 'https://api.getsquire.com';
$SHOP_ID = getenv('SQUIRE_SHOP_ID') ?: '';
$STRIPE_PK = getenv('STRIPE_PUBLISHABLE_KEY') ?: '';
$US_PROXY = getenv('US_PROXY_URL') ?: '';
$SQUIRE_PROXY_URL = getenv('SQUIRE_PROXY_URL') ?: 'http://127.0.0.1:9876';

$today = date('Y-m-d');
echo "[" . date('c') . "] Autopay cron started. Today: $today\n";

// ─── Helpers ─────────────────────────────────────────────────
function loadJson($documentName) {
    return storageReadDocument(storageDocumentNameForPath($documentName), []);
}

function saveJson($documentName, $data) {
    storageWriteDocument(storageDocumentNameForPath($documentName), $data);
}

function squireProxyAPI($method, $endpoint, $data = null, $token = null) {
    global $SQUIRE_PROXY_URL;
    $payload = ['method' => $method, 'endpoint' => $endpoint];
    if ($data) $payload['data'] = $data;
    if ($token) $payload['token'] = $token;
    $ch = curl_init($SQUIRE_PROXY_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = json_decode($resp, true);
    if ($body && isset($body['code'])) return [
        'code' => $body['code'],
        'body' => $body['body'] ?? $body,
        'raw' => $body['raw'] ?? '',
        'gatewayUnavailable' => !empty($body['gatewayUnavailable']),
    ];
    return ['code' => $httpCode, 'body' => $body, 'raw' => $resp];
}

function getSquireToken() {
    $creds = storageReadDocument('squire_creds.json', null);
    if ($creds && !empty($creds['username']) && !empty($creds['password'])) {
        $result = squireProxyAPI('POST', '/v1/login', ['username' => $creds['username'], 'password' => $creds['password']]);
        if ($result['code'] >= 200 && $result['code'] < 300 && !empty($result['body']['token'])) {
            storageWriteText('squire_token.txt', $result['body']['token']);
            echo "  Token refreshed via proxy\n";
            return $result['body']['token'];
        }
    }

    $token = storageReadText('squire_token.txt');
    return $token ?: null;
}

function chargeViaSquire($stripeToken, $amountCents, $token) {
    global $SHOP_ID;
    $itemId = sprintf('%08x-%04x-%04x-%04x-%012x', mt_rand(), mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand());
    $saleData = [
        'items' => [['id' => $itemId, 'quantity' => 1, 'type' => 'charge', 'amount' => $amountCents, 'customerId' => '']],
        'discounts' => [], 'promoCode' => '',
        'payments' => [['type' => 'card', 'paymentToken' => $stripeToken, 'amount' => $amountCents]],
        'tips' => [],
    ];
    return squireProxyAPI('POST', '/v2/shop/' . $SHOP_ID . '/sale', $saleData, $token);
}

function decryptCard($encrypted) {
    $key = hash('sha256', __DIR__ . '/data/.enc_key', true);
    $parts = explode('::', base64_decode($encrypted), 2);
    if (count($parts) !== 2) return null;
    $decrypted = openssl_decrypt($parts[1], 'aes-256-cbc', $key, 0, $parts[0]);
    return json_decode($decrypted, true);
}

function tokenizeCard($cardNumber, $expMonth, $expYear, $cvc, $address = '', $city = '', $state = '', $zip = '', $name = '') {
    global $STRIPE_PK, $US_PROXY;
    $fields = [
        'card[number]' => $cardNumber,
        'card[exp_month]' => $expMonth,
        'card[exp_year]' => $expYear,
        'card[cvc]' => $cvc,
    ];
    if ($name) $fields['card[name]'] = $name;
    if ($address) $fields['card[address_line1]'] = $address;
    if ($city) $fields['card[address_city]'] = $city;
    if ($state) $fields['card[address_state]'] = $state;
    if ($zip) $fields['card[address_zip]'] = $zip;
    $fields['card[address_country]'] = 'US';
    $ch = curl_init('https://api.stripe.com/v1/tokens');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_USERPWD => $STRIPE_PK . ':',
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_PROXY => $US_PROXY,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true);
}

function calcNextCharge($currentDate, $frequency) {
    $dt = new DateTime($currentDate);
    switch ($frequency) {
        case 'weekly': $dt->modify('+1 week'); break;
        case 'biweekly': $dt->modify('+2 weeks'); break;
        case 'monthly': $dt->modify('+1 month'); break;
        case 'quarterly': $dt->modify('+3 months'); break;
        default: $dt->modify('+1 month');
    }
    return $dt->format('Y-m-d');
}

function getWebhooks() {
    return storageReadDocument('webhooks.json', []);
}

function fireWebhooks($event, $payload, $source = '') {
    $webhooks = getWebhooks();
    foreach ($webhooks as $wh) {
        if ($wh['active'] !== true) continue;
        // Source filtering: only fire to webhooks registered for this source
        if (!empty($wh['source']) && $source && $wh['source'] !== $source) continue;
        // If no source provided but webhook has a source requirement, skip
        if (!empty($wh['source']) && !$source) continue;
        $events = $wh['events'] ?? ['*'];
        if (!in_array('*', $events) && !in_array($event, $events)) continue;
        // Build payload with required fields at top level
        $body = json_encode([
            'event' => $event,
            'amount' => $payload['amount'] ?? null,
            'transaction_id' => $payload['transaction_id'] ?? null,
            'subscription_id' => $payload['subscription_id'] ?? null,
            'client_name' => $payload['client_name'] ?? null,
            'client_email' => $payload['client_email'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
            'data' => $payload,
            'timestamp' => date('c'),
        ]);
        $ch = curl_init($wh['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Webhook-Secret: ' . ($wh['secret'] ?? ''), 'X-Autopay-Secret: ' . ($wh['secret'] ?? '')],
            CURLOPT_POSTFIELDS => $body,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        echo "    Webhook -> {$wh['url']} (HTTP $httpCode)\n";
    }
}

// ─── Main Processing ────────────────────────────────────────
$autopays = loadJson('autopay.json');
$transactions = loadJson('transactions.json');
$token = getSquireToken();

if (!$token) {
    echo "ERROR: Could not get Squire token. Aborting.\n";
    exit(1);
}

$processed = 0;
$succeeded = 0;
$failed = 0;

// Collect all due subscriptions first, then process only ONE per run
// Cron runs every 20 minutes so each customer is charged ~20 mins apart
$dueIndexes = [];
foreach ($autopays as $idx => $sub) {
    if ($sub['status'] !== 'active') continue;
    if (($sub['nextCharge'] ?? '9999-99-99') > $today) continue;
    $dueIndexes[] = $idx;
}

echo "  Due subscriptions: " . count($dueIndexes) . "\n";

if (!empty($dueIndexes)) {
    // Pick the next one to process using a round-robin tracker
    $lastProcessedId = storageReadText('.cron_last_processed');

    // Find the next due sub after the last processed one
    $targetIdx = $dueIndexes[0]; // default to first
    if ($lastProcessedId) {
        $foundLast = false;
        foreach ($dueIndexes as $idx) {
            if ($foundLast) { $targetIdx = $idx; break; }
            if ($autopays[$idx]['id'] === $lastProcessedId) $foundLast = true;
        }
        // If last processed was the final one, wrap around to first
        if (!$foundLast || $targetIdx === $dueIndexes[0]) $targetIdx = $dueIndexes[0];
    }

    $sub = &$autopays[$targetIdx];
    $amount = floatval($sub['amount']);
    $amountCents = round($amount * 100);

    // SKIP: Don't charge if amount is 0 or negative (data issue)
    if ($amount <= 0) {
        echo "  SKIPPED: {$sub['clientName']} - amount is \$0 (needs amount set in dashboard)\n";
        storageWriteText('.cron_last_processed', $sub['id']);
        saveJson('autopay.json', $autopays);
        $remaining = max(0, count($dueIndexes) - 1);
        echo "[" . date('c') . "] Done. Processed: 0 | Skipped: 1 (no amount) | Remaining: $remaining (next in ~20 min)\n\n";
        exit(0);
    }

    // SKIP: Don't charge if no card AND no saved card available
    if (empty($sub['encryptedCard']) && empty($sub['stripeToken'])) {
        $savedCards = loadJson('saved_cards.json');
        $foundCard = false;
        foreach ($savedCards as $sc) {
            if (strtolower(trim($sc['clientEmail'] ?? '')) === strtolower(trim($sub['clientEmail'] ?? '')) && !empty($sc['encryptedCard'])) {
                $sub['encryptedCard'] = $sc['encryptedCard'];
                $sub['cardLast4'] = $sc['cardLast4'] ?? '';
                $sub['cardBrand'] = $sc['cardBrand'] ?? 'Card';
                $foundCard = true;
                break;
            }
        }
        if (!$foundCard) {
            echo "  SKIPPED: {$sub['clientName']} - no card on file\n";
            storageWriteText('.cron_last_processed', $sub['id']);
            saveJson('autopay.json', $autopays);
            $remaining = max(0, count($dueIndexes) - 1);
            echo "[" . date('c') . "] Done. Processed: 0 | Skipped: 1 (no card) | Remaining: $remaining (next in ~20 min)\n\n";
            exit(0);
        }
    }

    // COOLDOWN: Don't retry the same card within 10 minutes of a decline
    $history = $sub['history'] ?? [];
    if (!empty($history)) {
        $lastAttempt = end($history);
        if (isset($lastAttempt['timestamp'])) {
            $lastTime = strtotime($lastAttempt['timestamp']);
            $elapsed = time() - $lastTime;
            if ($elapsed < 600 && ($lastAttempt['status'] ?? '') === 'declined') {
                echo "  COOLDOWN: {$sub['clientName']} - last decline {$elapsed}s ago (need 600s)\n";
                storageWriteText('.cron_last_processed', $sub['id']);
                saveJson('autopay.json', $autopays);
                $remaining = max(0, count($dueIndexes) - 1);
                echo "[" . date('c') . "] Done. Processed: 0 | Cooldown: 1 | Remaining: $remaining (next in ~20 min)\n\n";
                exit(0);
            }
        }
    }

    $processed = 1;

    // If subscription is missing card info, try to fill from saved_cards
    if (empty($sub['cardLast4'])) {
        $savedCards = loadJson('saved_cards.json');
        foreach ($savedCards as $sc) {
            if (strtolower(trim($sc['clientEmail'] ?? '')) === strtolower(trim($sub['clientEmail'] ?? '')) && !empty($sc['cardLast4'])) {
                $sub['cardLast4'] = $sc['cardLast4'];
                $sub['cardBrand'] = $sc['cardBrand'] ?? 'Card';
                if (empty($sub['encryptedCard']) && !empty($sc['encryptedCard'])) {
                    $sub['encryptedCard'] = $sc['encryptedCard'];
                }
                break;
            }
        }
    }

    // If subscription is missing card info, try to fill from saved_cards
    if (empty($sub['cardLast4'])) {
        $savedCards = loadJson('saved_cards.json');
        foreach ($savedCards as $sc) {
            if (strtolower(trim($sc['clientEmail'] ?? '')) === strtolower(trim($sub['clientEmail'] ?? '')) && !empty($sc['cardLast4'])) {
                $sub['cardLast4'] = $sc['cardLast4'];
                $sub['cardBrand'] = $sc['cardBrand'] ?? 'Card';
                if (empty($sub['encryptedCard']) && !empty($sc['encryptedCard'])) {
                    $sub['encryptedCard'] = $sc['encryptedCard'];
                }
                break;
            }
        }
    }

    echo "  Processing (1 of " . count($dueIndexes) . "): {$sub['clientName']} - \${$amount} ({$sub['frequency']}) [Sub: {$sub['id']}]\n";

    // Save tracker so next run picks the next customer
    storageWriteText('.cron_last_processed', $sub['id']);

    // Decrypt stored card and create a fresh token
    $result = null;
    if (!empty($sub['encryptedCard'])) {
        $card = decryptCard($sub['encryptedCard']);
        if ($card && !empty($card['number'])) {
            echo "    Tokenizing stored card...\n";
            $tokenResult = tokenizeCard($card['number'], $card['exp_month'], $card['exp_year'], $card['cvc'], $sub['clientAddress'] ?? '', $sub['clientCity'] ?? '', $sub['clientState'] ?? '', $sub['clientZip'] ?? '', $sub['clientName'] ?? '');
            if (!empty($tokenResult['id'])) {
                echo "    Token created, charging via gateway...\n";
                $result = chargeViaSquire($tokenResult['id'], $amountCents, $token);
            } else {
                $tokenErr = $tokenResult['error']['message'] ?? 'Token creation failed';
                echo "    Token creation failed: $tokenErr\n";
            }
        } else {
            echo "    Could not decrypt card data\n";
        }
    }

    // Fallback to stored token (legacy, may be expired)
    if ((!$result || (($result['code'] < 200 || $result['code'] >= 300) && empty($result['gatewayUnavailable']))) && !empty($sub['stripeToken'])) {
        echo "    Trying stored token fallback...\n";
        $result = chargeViaSquire($sub['stripeToken'], $amountCents, $token);
    }

    // No method worked at all
    if (!$result) {
        echo "    FAILED: No valid payment method\n";
        $sub['failCount'] = ($sub['failCount'] ?? 0) + 1;
        $sub['history'][] = [
            'timestamp' => date('c'), 'amount' => $amount,
            'status' => 'declined', 'error' => 'No stored card found',
        ];
        if ($sub['failCount'] >= 3) { $sub['status'] = 'failed'; echo "    Subscription marked as FAILED after {$sub['failCount']} attempts\n"; }
        else { $sub['nextCharge'] = date('Y-m-d', strtotime('+1 day')); echo "    Will retry tomorrow (attempt {$sub['failCount']}/3)\n"; }
        $failed = 1;
        $transactions[] = ['id' => uniqid('txn_'), 'amount' => $amount, 'description' => $sub['description'] ?? 'Autopay', 'clientName' => $sub['clientName'], 'clientEmail' => $sub['clientEmail'] ?? '', 'clientPhone' => $sub['clientPhone'] ?? '', 'status' => 'declined', 'timestamp' => date('c'), 'cardLast4' => $sub['cardLast4'] ?? '****', 'cardBrand' => $sub['cardBrand'] ?? 'Card', 'source' => 'autopay', 'subscriptionId' => $sub['id'], 'error' => 'No payment method available'];

    } elseif (!empty($result['gatewayUnavailable'])) {
        echo "    GATEWAY UNAVAILABLE: No charge was submitted; subscription remains active\n";
        $processed = 0;

    } elseif ($result['code'] >= 200 && $result['code'] < 300) {
        echo "    SUCCESS: Charged \${$amount}\n";
        $succeeded = 1;

        $txn = [
            'id' => $result['body']['id'] ?? uniqid('txn_'),
            'amount' => $amount,
            'description' => $sub['description'] ?? 'Autopay',
            'clientName' => $sub['clientName'],
            'clientEmail' => $sub['clientEmail'] ?? '',
            'clientPhone' => $sub['clientPhone'] ?? '',
            'status' => 'approved',
            'timestamp' => date('c'),
            'cardLast4' => $sub['cardLast4'] ?? '****',
            'cardBrand' => $sub['cardBrand'] ?? 'Card',
            'source' => 'autopay',
            'subscriptionId' => $sub['id'],
            'metadata' => $sub['metadata'] ?? null,
        ];
        array_unshift($transactions, $txn);

        $sub['history'][] = [
            'timestamp' => date('c'), 'amount' => $amount, 'status' => 'approved',
            'txnId' => $txn['id'],
        ];
        $sub['nextCharge'] = calcNextCharge($today, $sub['frequency']);
        $sub['failCount'] = 0;
        $sub['lastPaid'] = date('c');

        // Fire webhook for successful autopay renewal (routed by source)
        fireWebhooks('autopay.renewed', [
            'subscription_id' => $sub['id'],
            'transaction_id' => $txn['id'],
            'client_name' => $sub['clientName'],
            'client_email' => $sub['clientEmail'] ?? '',
            'amount' => $amount,
            'status' => 'approved',
            'frequency' => $sub['frequency'],
            'next_charge' => $sub['nextCharge'],
            'metadata' => $sub['metadata'] ?? null,
        ], $sub['source'] ?? '');

    } else {
        $errorMsg = $result['body']['message'] ?? ($result['body']['error'] ?? 'Charge failed');
        echo "    FAILED: $errorMsg\n";
        $failed = 1;

        $sub['failCount'] = ($sub['failCount'] ?? 0) + 1;
        $sub['history'][] = [
            'timestamp' => date('c'), 'amount' => $amount,
            'status' => 'declined', 'error' => $errorMsg,
        ];

        if ($sub['failCount'] >= 3) {
            $sub['status'] = 'failed';
            echo "    Subscription marked as FAILED after {$sub['failCount']} attempts\n";
        } else {
            $sub['nextCharge'] = date('Y-m-d', strtotime('+1 day'));
            echo "    Will retry tomorrow (attempt {$sub['failCount']}/3)\n";
        }

        $txn = [
            'id' => uniqid('txn_'),
            'amount' => $amount,
            'description' => $sub['description'] ?? 'Autopay',
            'clientName' => $sub['clientName'],
            'clientEmail' => $sub['clientEmail'] ?? '',
            'clientPhone' => $sub['clientPhone'] ?? '',
            'status' => 'declined',
            'timestamp' => date('c'),
            'cardLast4' => $sub['cardLast4'] ?? '****',
            'cardBrand' => $sub['cardBrand'] ?? 'Card',
            'source' => 'autopay',
            'subscriptionId' => $sub['id'],
            'error' => $errorMsg,
            'metadata' => $sub['metadata'] ?? null,
        ];
        array_unshift($transactions, $txn);

        // Fire webhook for failed autopay charge (routed by source)
        fireWebhooks('autopay.failed', [
            'subscription_id' => $sub['id'],
            'transaction_id' => $txn['id'],
            'client_name' => $sub['clientName'],
            'client_email' => $sub['clientEmail'] ?? '',
            'amount' => $amount,
            'status' => 'declined',
            'error' => $errorMsg,
            'fail_count' => $sub['failCount'],
            'metadata' => $sub['metadata'] ?? null,
        ], $sub['source'] ?? '');
    }
    unset($sub);
}

// Save everything
saveJson('autopay.json', $autopays);
saveJson('transactions.json', $transactions);

$remaining = max(0, count($dueIndexes) - 1);
echo "[" . date('c') . "] Done. Processed: $processed | Succeeded: $succeeded | Failed: $failed | Remaining: $remaining (next in ~20 min)\n\n";
