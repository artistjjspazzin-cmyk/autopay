<?php
require_once dirname(__DIR__) . '/storage.php';

const CG_SQUIRE_API_BASE = 'https://api.getsquire.com';
const CG_PROXY_URL = 'http://127.0.0.1:9876';

define('CG_SHOP_ID', getenv('SQUIRE_SHOP_ID') ?: '');
define('CG_STRIPE_PK', getenv('STRIPE_PUBLISHABLE_KEY') ?: '');

const CG_TRANSACTIONS = 'creditgod_transactions.json';
const CG_AUTOPAY = 'creditgod_autopay.json';
const CG_CARDS = 'creditgod_saved_cards.json';
const CG_WEBHOOKS = 'creditgod_webhooks.json';
const CG_AUDIT = 'creditgod_audit_log.json';
const CG_KEYS = 'creditgod_api_keys.json';

function cgDefaultKeys(): array {
    return [
        'sk_live_cg_' . bin2hex(random_bytes(16)) => ['name' => 'CreditGod Live', 'active' => true, 'mode' => 'live'],
        'sk_test_cg_' . bin2hex(random_bytes(16)) => ['name' => 'CreditGod Test', 'active' => true, 'mode' => 'test'],
    ];
}

function cgApiKeys(): array {
    $keys = storageReadDocument(CG_KEYS, []);
    if (!$keys) {
        $keys = cgDefaultKeys();
        storageWriteDocument(CG_KEYS, $keys);
    }
    return $keys;
}

function cgTransactions(): array {
    return storageReadDocument(CG_TRANSACTIONS, []);
}

function cgSaveTransaction(array $txn): void {
    storageMutateDocument(CG_TRANSACTIONS, [], function ($all) use ($txn) {
        array_unshift($all, $txn);
        return $all;
    });
}

function cgAutopays(): array {
    return storageReadDocument(CG_AUTOPAY, []);
}

function cgSaveAutopays(array $all): void {
    storageWriteDocument(CG_AUTOPAY, $all);
}

function cgAudit(string $action, string $target, string $details): void {
    storageMutateDocument(CG_AUDIT, [], function ($all) use ($action, $target, $details) {
        array_unshift($all, [
            'timestamp' => date('c'),
            'action' => $action,
            'target' => $target,
            'details' => $details,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'system',
        ]);
        return array_slice($all, 0, 5000);
    });
}

function cgEncryptCard(array $cardData): string {
    $key = hash('sha256', dirname(__DIR__) . '/data/.enc_key', true);
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt(json_encode($cardData), 'aes-256-cbc', $key, 0, $iv);
    return base64_encode($iv . '::' . $encrypted);
}

function cgDecryptCard(string $encrypted): ?array {
    $key = hash('sha256', dirname(__DIR__) . '/data/.enc_key', true);
    $parts = explode('::', base64_decode($encrypted), 2);
    if (count($parts) !== 2) return null;
    $decrypted = openssl_decrypt($parts[1], 'aes-256-cbc', $key, 0, $parts[0]);
    return json_decode($decrypted, true);
}

function cgSaveCardForCustomer(array $customer, array $card): void {
    if (empty($customer['clientName'])) return;
    storageMutateDocument(CG_CARDS, [], function ($all) use ($customer, $card) {
        $encCard = cgEncryptCard($card);
        foreach ($all as &$existing) {
            if (strcasecmp((string)($existing['clientName'] ?? ''), $customer['clientName']) === 0) {
                $existing = array_merge($existing, array_filter($customer, fn($v) => $v !== ''), [
                    'encryptedCard' => $encCard,
                    'cardLast4' => $card['last4'] ?? ($existing['cardLast4'] ?? ''),
                    'cardBrand' => $card['brand'] ?? ($existing['cardBrand'] ?? ''),
                    'updatedAt' => date('c'),
                ]);
                return $all;
            }
        }
        unset($existing);
        $all[] = array_merge($customer, [
            'encryptedCard' => $encCard,
            'cardLast4' => $card['last4'] ?? '',
            'cardBrand' => $card['brand'] ?? '',
            'createdAt' => date('c'),
            'updatedAt' => date('c'),
        ]);
        return $all;
    });
}

function cgTokenizeCard(string $cardNumber, $expMonth, $expYear, string $cvc, array $address = []): ?array {
    $fields = [
        'card[number]' => $cardNumber,
        'card[exp_month]' => $expMonth,
        'card[exp_year]' => $expYear,
        'card[cvc]' => $cvc,
        'card[address_country]' => 'US',
    ];
    foreach (['name' => 'card[name]', 'line1' => 'card[address_line1]', 'city' => 'card[address_city]', 'state' => 'card[address_state]', 'zip' => 'card[address_zip]'] as $key => $field) {
        if (!empty($address[$key])) $fields[$field] = $address[$key];
    }
    $proxy = getenv('US_PROXY_URL') ?: '';
    $ch = curl_init('https://api.stripe.com/v1/tokens');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERPWD => CG_STRIPE_PK . ':',
        CURLOPT_POSTFIELDS => http_build_query($fields),
    ]);
    if ($proxy) curl_setopt($ch, CURLOPT_PROXY, $proxy);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true);
}

function cgGatewayToken(): ?string {
    $token = storageReadText('squire_token.txt');
    return $token ?: null;
}

function cgGatewayRequest(string $method, string $endpoint, ?array $data = null, ?string $token = null): array {
    $payload = ['method' => $method, 'endpoint' => $endpoint];
    if ($data !== null) $payload['data'] = $data;
    if ($token) $payload['token'] = $token;
    $ch = curl_init(CG_PROXY_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError) return ['code' => 0, 'body' => ['error' => 'Payment gateway unavailable'], 'gatewayUnavailable' => true];
    $result = json_decode($response, true);
    if (!$result) return ['code' => 0, 'body' => ['error' => 'Invalid gateway response'], 'gatewayUnavailable' => true];
    return [
        'code' => $result['code'] ?? 0,
        'body' => $result['body'] ?? ['error' => 'Empty response'],
        'gatewayUnavailable' => !empty($result['gatewayUnavailable']),
    ];
}

function cgCharge(string $payToken, float $amount, string $token): array {
    $amountCents = (int)round($amount * 100);
    $itemId = sprintf('%08x-%04x-%04x-%04x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand());
    $saleData = [
        'items' => [['id' => $itemId, 'quantity' => 1, 'type' => 'charge', 'amount' => $amountCents, 'customerId' => '']],
        'discounts' => [],
        'promoCode' => '',
        'payments' => [['type' => 'card', 'paymentToken' => $payToken, 'amount' => $amountCents]],
        'tips' => [],
    ];
    return cgGatewayRequest('POST', '/v2/shop/' . CG_SHOP_ID . '/sale', $saleData, $token);
}

function cgFireWebhooks(string $event, array $payload): void {
    foreach (storageReadDocument(CG_WEBHOOKS, []) as $wh) {
        if (($wh['active'] ?? false) !== true) continue;
        $events = $wh['events'] ?? ['*'];
        if (!in_array('*', $events, true) && !in_array($event, $events, true)) continue;
        $ch = curl_init($wh['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Webhook-Secret: ' . ($wh['secret'] ?? '')],
            CURLOPT_POSTFIELDS => json_encode([
                'event' => $event,
                'app' => 'CreditGod',
                'data' => $payload,
                'timestamp' => date('c'),
            ]),
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

function cgNextCharge(string $currentDate, string $frequency): string {
    $dt = new DateTime($currentDate);
    switch ($frequency) {
        case 'weekly': $dt->modify('+1 week'); break;
        case 'biweekly': $dt->modify('+2 weeks'); break;
        case 'quarterly': $dt->modify('+3 months'); break;
        default: $dt->modify('+1 month');
    }
    return $dt->format('Y-m-d');
}
