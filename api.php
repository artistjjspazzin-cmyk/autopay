<?php
require_once __DIR__ . '/storage.php';

/**
 * Authorize.net Terminal - External API
 * Allows external websites to process charges and set up autopay
 * through the existing payment gateway.
 * 
 * Base URL: https://autopay.builtbyjj.dev/api.php
 * Authentication: Bearer token in Authorization header
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-cache');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ─── Configuration ───────────────────────────────────────────
$SQUIRE_API_BASE = getenv('SQUIRE_API_BASE') ?: 'https://api.getsquire.com';
$SHOP_ID = getenv('SQUIRE_SHOP_ID') ?: '';
$STRIPE_PK = getenv('STRIPE_PUBLISHABLE_KEY') ?: '';
$US_PROXY = getenv('US_PROXY_URL') ?: '';

// API Keys — provide a JSON object keyed by token through AUTOPAY_API_KEYS_JSON.
$API_KEYS = json_decode(getenv('AUTOPAY_API_KEYS_JSON') ?: '{}', true);
if (!is_array($API_KEYS)) $API_KEYS = [];

// ─── Authentication ──────────────────────────────────────────
function getApiKey() {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (strpos($header, 'Bearer ') === 0) return substr($header, 7);
    return $_GET['api_key'] ?? '';
}

$apiKey = getApiKey();
if (empty($apiKey) || !isset($API_KEYS[$apiKey]) || !$API_KEYS[$apiKey]['active']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid or missing API key. Include Authorization: Bearer YOUR_KEY header.']);
    exit;
}
$isTestMode = ($API_KEYS[$apiKey]['mode'] ?? 'live') === 'test';
$appName = $API_KEYS[$apiKey]['app'] ?? 'api';

// ─── Helper Functions ────────────────────────────────────────
function getToken() {
    return storageReadText('squire_token.txt');
}

function getTransactions() {
    return storageReadDocument('transactions.json', []);
}

function saveTransaction($txn) {
    storageMutateDocument('transactions.json', [], function($all) use ($txn) {
        array_unshift($all, $txn);
        return $all;
    });
}

function saveTransactions($all) {
    storageWriteDocument('transactions.json', $all);
}

function getSavedCards() {
    return storageReadDocument('saved_cards.json', []);
}

function saveSavedCards($all) {
    storageWriteDocument('saved_cards.json', $all);
}

function getAutopays() {
    return storageReadDocument('autopay.json', []);
}

function saveAutopays($all) {
    storageWriteDocument('autopay.json', $all);
}

function encryptCard($cardData) {
    $key = hash('sha256', __DIR__ . '/data/.enc_key', true);
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt(json_encode($cardData), 'aes-256-cbc', $key, 0, $iv);
    return base64_encode($iv . '::' . $encrypted);
}

function saveCardForCustomer($clientName, $clientEmail, $clientPhone, $clientAddress, $clientCity, $clientState, $clientZip, $cardNumber, $expMonth, $expYear, $cvc, $cardLast4, $cardBrand) {
    if (empty($clientName)) return;
    $all = getSavedCards();
    $encCard = encryptCard(['number' => $cardNumber, 'exp_month' => $expMonth, 'exp_year' => $expYear, 'cvc' => $cvc]);
    $found = false;
    foreach ($all as &$c) {
        if (strtolower($c['clientName']) === strtolower($clientName)) {
            $c['encryptedCard'] = $encCard;
            $c['cardLast4'] = $cardLast4;
            $c['cardBrand'] = $cardBrand;
            if ($clientEmail) $c['clientEmail'] = $clientEmail;
            if ($clientPhone) $c['clientPhone'] = $clientPhone;
            if ($clientAddress) $c['clientAddress'] = $clientAddress;
            if ($clientCity) $c['clientCity'] = $clientCity;
            if ($clientState) $c['clientState'] = $clientState;
            if ($clientZip) $c['clientZip'] = $clientZip;
            $c['updatedAt'] = date('c');
            $found = true;
            break;
        }
    }
    unset($c);
    if (!$found) {
        $all[] = [
            'clientName' => $clientName, 'clientEmail' => $clientEmail, 'clientPhone' => $clientPhone,
            'clientAddress' => $clientAddress, 'clientCity' => $clientCity, 'clientState' => $clientState, 'clientZip' => $clientZip,
            'encryptedCard' => $encCard, 'cardLast4' => $cardLast4, 'cardBrand' => $cardBrand,
            'createdAt' => date('c'), 'updatedAt' => date('c'),
        ];
    }
    saveSavedCards($all);
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
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_USERPWD => $STRIPE_PK . ':',
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_PROXY => $US_PROXY,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true);
}

function squireAPI($method, $endpoint, $data = null, $token = null) {
    $proxyUrl = getenv('SQUIRE_PROXY_URL') ?: 'http://127.0.0.1:9876';
    $payload = ['method' => $method, 'endpoint' => $endpoint];
    if ($data !== null) $payload['data'] = $data;
    if ($token) $payload['token'] = $token;
    $ch = curl_init($proxyUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError) return ['code' => 0, 'body' => ['error' => 'Payment gateway unavailable'], 'raw' => '', 'error' => $curlError];
    $result = json_decode($response, true);
    if (!$result) return ['code' => 0, 'body' => ['error' => 'Invalid gateway response'], 'raw' => $response, 'error' => ''];
    return ['code' => $result['code'] ?? 0, 'body' => $result['body'] ?? ['error' => 'Empty response'], 'raw' => $result['raw'] ?? '', 'error' => ''];
}

function processSquireCharge($stripeToken, $amount, $token) {
    global $SHOP_ID;
    $amountCents = round($amount * 100);
    $itemId = sprintf('%08x-%04x-%04x-%04x-%012x', mt_rand(), mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand());
    $saleData = [
        'items' => [['id' => $itemId, 'quantity' => 1, 'type' => 'charge', 'amount' => $amountCents, 'customerId' => '']],
        'discounts' => [], 'promoCode' => '',
        'payments' => [['type' => 'card', 'paymentToken' => $stripeToken, 'amount' => $amountCents]],
        'tips' => [],
    ];
    return squireAPI('POST', '/v2/shop/' . $SHOP_ID . '/sale', $saleData, $token);
}

function getWebhooks() {
    return storageReadDocument('webhooks.json', []);
}

function saveWebhooks($all) {
    storageWriteDocument('webhooks.json', $all);
}

function fireWebhooks($event, $payload, $source = '') {
    $webhooks = getWebhooks();
    foreach ($webhooks as $wh) {
        if ($wh['active'] !== true) continue;
        // Source filtering: if webhook has a source set, only fire for matching transactions
        if (!empty($wh['source']) && $source && $wh['source'] !== $source) continue;
        $events = $wh['events'] ?? ['*'];
        if (!in_array('*', $events) && !in_array($event, $events)) continue;
        // Build full payload with required fields at top level
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
        curl_exec($ch);
        curl_close($ch);
    }
}

function simulateCharge($amount, $clientName) {
    $statuses = ['approved', 'approved', 'approved', 'approved', 'declined'];
    $status = $statuses[array_rand($statuses)];
    return [
        'id' => 'test_' . bin2hex(random_bytes(8)),
        'amount' => $amount,
        'status' => $status,
        'clientName' => $clientName,
        'timestamp' => date('c'),
        'test_mode' => true,
    ];
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

// ─── Routing ─────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
// Remove base path (api.php or api/)
$path = preg_replace('#^(api\.php/?|api/?)#', '', $path);
$endpoint = $path ?: ($_GET['endpoint'] ?? '');

// Also support ?endpoint= parameter for simpler routing
if (empty($endpoint) && isset($_GET['endpoint'])) {
    $endpoint = $_GET['endpoint'];
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

switch ($endpoint) {

    // ═══════════════════════════════════════════════════════════
    // POST /charge — Process a one-time payment
    // ═══════════════════════════════════════════════════════════
    case 'charge':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'error' => 'POST required']); exit; }

        $amount = floatval($input['amount'] ?? 0);
        $description = str_replace('ViewProPlus', 'JJ', $input['description'] ?? 'Payment');
        $clientName = trim($input['client_name'] ?? $input['clientName'] ?? '');
        $clientEmail = trim($input['client_email'] ?? $input['clientEmail'] ?? '');
        $clientPhone = trim($input['client_phone'] ?? $input['clientPhone'] ?? '');
        $clientAddress = trim($input['address'] ?? $input['clientAddress'] ?? '');
        $clientCity = trim($input['city'] ?? $input['clientCity'] ?? '');
        $clientState = trim($input['state'] ?? $input['clientState'] ?? '');
        $clientZip = trim($input['zip'] ?? $input['clientZip'] ?? '');
        $cardNumber = preg_replace('/\s+/', '', $input['card_number'] ?? $input['cardNumber'] ?? '');
        $expMonth = $input['exp_month'] ?? $input['expMonth'] ?? '';
        $expYear = $input['exp_year'] ?? $input['expYear'] ?? '';
        $cvc = $input['cvc'] ?? $input['cvv'] ?? '';

        // Metadata
        $metadata = $input['metadata'] ?? [];
        $username = $metadata['username'] ?? $input['username'] ?? '';
        $plan = $metadata['plan'] ?? $input['plan'] ?? '';
        $orderType = $metadata['order_type'] ?? $input['order_type'] ?? '';

        // Validation
        if ($amount <= 0) { echo json_encode(['success' => false, 'error' => 'Amount must be greater than 0']); exit; }
        if (!$cardNumber || !$expMonth || !$expYear || !$cvc) { echo json_encode(['success' => false, 'error' => 'Card details required: card_number, exp_month, exp_year, cvc']); exit; }
        if (!$clientName) { echo json_encode(['success' => false, 'error' => 'client_name is required']); exit; }

        // ── TEST MODE: simulate charge ──
        if ($isTestMode) {
            $sim = simulateCharge($amount, $clientName);
            $resp = [
                'success' => $sim['status'] === 'approved',
                'transaction_id' => $sim['id'],
                'amount' => $amount,
                'status' => $sim['status'],
                'card_last4' => substr($cardNumber, -4),
                'card_brand' => 'TestCard',
                'timestamp' => $sim['timestamp'],
                'test_mode' => true,
                'metadata' => ['username' => $username, 'plan' => $plan, 'order_type' => $orderType],
            ];
            if ($sim['status'] !== 'approved') $resp['error'] = 'Test decline (simulated)';
            fireWebhooks('charge.' . $sim['status'], $resp, $appName);
            echo json_encode($resp);
            break;
        }

        // ── LIVE MODE ──
        $token = getToken();
        if (!$token) { http_response_code(503); echo json_encode(['success' => false, 'error' => 'Payment gateway not connected']); exit; }

        $tokenResult = tokenizeCard($cardNumber, $expMonth, $expYear, $cvc, $clientAddress, $clientCity, $clientState, $clientZip, $clientName);
        if (empty($tokenResult['id'])) {
            $err = $tokenResult['error']['message'] ?? 'Card validation failed';
            echo json_encode(['success' => false, 'error' => $err]);
            exit;
        }
        $payToken = $tokenResult['id'];
        $cardLast4 = substr($cardNumber, -4);
        $cardBrand = $tokenResult['card']['brand'] ?? 'Card';

        $result = processSquireCharge($payToken, $amount, $token);

        $metaBlock = array_filter(['username' => $username, 'plan' => $plan, 'order_type' => $orderType]);

        if ($result['code'] >= 200 && $result['code'] < 300) {
            $txn = [
                'id' => $result['body']['id'] ?? uniqid('txn_'),
                'amount' => $amount, 'description' => $description,
                'clientName' => $clientName, 'clientEmail' => $clientEmail, 'clientPhone' => $clientPhone,
                'clientAddress' => $clientAddress, 'clientCity' => $clientCity, 'clientState' => $clientState, 'clientZip' => $clientZip,
                'status' => 'approved', 'timestamp' => date('c'),
                'cardLast4' => $cardLast4, 'cardBrand' => $cardBrand, 'source' => $appName,
            ];
            if ($metaBlock) $txn['metadata'] = $metaBlock;
            saveTransaction($txn);
            saveCardForCustomer($clientName, $clientEmail, $clientPhone, $clientAddress, $clientCity, $clientState, $clientZip, $cardNumber, $expMonth, $expYear, $cvc, $cardLast4, $cardBrand);
            $resp = [
                'success' => true, 'transaction_id' => $txn['id'], 'amount' => $amount,
                'status' => 'approved', 'card_last4' => $cardLast4, 'card_brand' => $cardBrand,
                'timestamp' => $txn['timestamp'],
            ];
            if ($metaBlock) $resp['metadata'] = $metaBlock;
            fireWebhooks('charge.approved', $resp, $appName);
            echo json_encode($resp);
        } else {
            $errorMsg = $result['body']['message'] ?? ($result['body']['error'] ?? 'Charge failed');
            $txn = [
                'id' => uniqid('txn_'), 'amount' => $amount, 'description' => $description,
                'clientName' => $clientName, 'clientEmail' => $clientEmail, 'clientPhone' => $clientPhone,
                'clientAddress' => $clientAddress, 'clientCity' => $clientCity, 'clientState' => $clientState, 'clientZip' => $clientZip,
                'status' => 'declined', 'timestamp' => date('c'),
                'cardLast4' => $cardLast4, 'cardBrand' => $cardBrand, 'error' => $errorMsg, 'source' => $appName,
            ];
            if ($metaBlock) $txn['metadata'] = $metaBlock;
            saveTransaction($txn);
            $resp = ['success' => false, 'error' => $errorMsg, 'transaction_id' => $txn['id']];
            if ($metaBlock) $resp['metadata'] = $metaBlock;
            fireWebhooks('charge.declined', $resp, $appName);
            echo json_encode($resp);
        }
        break;

    // ═══════════════════════════════════════════════════════════
    // POST /autopay — Set up recurring autopay subscription
    // ═══════════════════════════════════════════════════════════
    case 'autopay':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'error' => 'POST required']); exit; }

        $clientName = trim($input['client_name'] ?? $input['clientName'] ?? '');
        $clientEmail = trim($input['client_email'] ?? $input['clientEmail'] ?? '');
        $clientPhone = trim($input['client_phone'] ?? $input['clientPhone'] ?? '');
        $clientAddress = trim($input['address'] ?? $input['clientAddress'] ?? '');
        $clientCity = trim($input['city'] ?? $input['clientCity'] ?? '');
        $clientState = trim($input['state'] ?? $input['clientState'] ?? '');
        $clientZip = trim($input['zip'] ?? $input['clientZip'] ?? '');
        $amount = floatval($input['amount'] ?? 0);
        $description = str_replace('ViewProPlus', 'JJ', trim($input['description'] ?? 'Recurring Payment'));
        $frequency = $input['frequency'] ?? 'monthly'; // weekly, biweekly, monthly, quarterly
        $startDate = $input['start_date'] ?? $input['startDate'] ?? date('Y-m-d');
        $cardNumber = preg_replace('/\s+/', '', $input['card_number'] ?? $input['cardNumber'] ?? '');
        $expMonth = $input['exp_month'] ?? $input['expMonth'] ?? '';
        $expYear = $input['exp_year'] ?? $input['expYear'] ?? '';
        $cvc = $input['cvc'] ?? $input['cvv'] ?? '';
        $chargeNow = !empty($input['charge_now'] ?? $input['chargeNow'] ?? false);

        // Metadata
        $metadata = $input['metadata'] ?? [];
        $username = $metadata['username'] ?? $input['username'] ?? '';
        $plan = $metadata['plan'] ?? $input['plan'] ?? '';
        $orderType = $metadata['order_type'] ?? $input['order_type'] ?? '';

        // Validation
        if (!$clientName) { echo json_encode(['success' => false, 'error' => 'client_name is required']); exit; }
        if ($amount <= 0) { echo json_encode(['success' => false, 'error' => 'Amount must be greater than 0']); exit; }
        if (!$cardNumber || !$expMonth || !$expYear || !$cvc) { echo json_encode(['success' => false, 'error' => 'Card details required']); exit; }
        if (!in_array($frequency, ['weekly', 'biweekly', 'monthly', 'quarterly'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid frequency. Use: weekly, biweekly, monthly, quarterly']);
            exit;
        }

        $metaBlock = array_filter(['username' => $username, 'plan' => $plan, 'order_type' => $orderType]);

        // ── TEST MODE ──
        if ($isTestMode) {
            $subId = 'test_sub_' . bin2hex(random_bytes(8));
            $nextCharge = $chargeNow ? calcNextCharge($startDate ?: date('Y-m-d'), $frequency) : ($startDate ?: date('Y-m-d'));
            $resp = [
                'success' => true, 'subscription_id' => $subId, 'amount' => $amount,
                'frequency' => $frequency, 'next_charge' => $nextCharge, 'status' => 'active', 'test_mode' => true,
            ];
            if ($metaBlock) $resp['metadata'] = $metaBlock;
            if ($chargeNow) {
                $sim = simulateCharge($amount, $clientName);
                $resp['first_charge'] = ['success' => $sim['status'] === 'approved', 'transaction_id' => $sim['id'], 'amount' => $amount, 'test_mode' => true];
            }
            fireWebhooks('autopay.created', $resp, $appName);
            echo json_encode($resp);
            break;
        }

        // ── LIVE MODE ──
        $firstChargeResult = null;
        if ($chargeNow) {
            $token = getToken();
            if (!$token) { http_response_code(503); echo json_encode(['success' => false, 'error' => 'Payment gateway not connected']); exit; }
            $tokenResult = tokenizeCard($cardNumber, $expMonth, $expYear, $cvc, $clientAddress, $clientCity, $clientState, $clientZip, $clientName);
            if (empty($tokenResult['id'])) {
                $err = $tokenResult['error']['message'] ?? 'Card validation failed';
                echo json_encode(['success' => false, 'error' => $err]);
                exit;
            }
            $payToken = $tokenResult['id'];
            $cardLast4 = substr($cardNumber, -4);
            $cardBrand = $tokenResult['card']['brand'] ?? 'Card';
            $result = processSquireCharge($payToken, $amount, $token);
            if ($result['code'] >= 200 && $result['code'] < 300) {
                $txn = [
                    'id' => $result['body']['id'] ?? uniqid('txn_'),
                    'amount' => $amount, 'description' => $description,
                    'clientName' => $clientName, 'clientEmail' => $clientEmail, 'clientPhone' => $clientPhone,
                    'clientAddress' => $clientAddress, 'clientCity' => $clientCity, 'clientState' => $clientState, 'clientZip' => $clientZip,
                    'status' => 'approved', 'timestamp' => date('c'),
                    'cardLast4' => $cardLast4, 'cardBrand' => $cardBrand, 'source' => $appName,
                ];
                if ($metaBlock) $txn['metadata'] = $metaBlock;
                saveTransaction($txn);
                $firstChargeResult = ['success' => true, 'transaction_id' => $txn['id'], 'amount' => $amount];
            } else {
                $errorMsg = $result['body']['message'] ?? ($result['body']['error'] ?? 'Charge failed');
                echo json_encode(['success' => false, 'error' => 'First charge failed: ' . $errorMsg]);
                exit;
            }
        }

        $cardLast4 = substr($cardNumber, -4);
        $cardBrand = $cardBrand ?? 'Card';
        $encryptedCard = encryptCard(['number' => $cardNumber, 'exp_month' => $expMonth, 'exp_year' => $expYear, 'cvc' => $cvc]);

        if ($chargeNow) {
            $nextCharge = calcNextCharge($startDate ?: date('Y-m-d'), $frequency);
        } else {
            $nextCharge = $startDate ?: date('Y-m-d');
        }

        $sub = [
            'id' => 'sub_' . bin2hex(random_bytes(8)),
            'clientName' => $clientName, 'clientEmail' => $clientEmail, 'clientPhone' => $clientPhone,
            'clientAddress' => $clientAddress, 'clientCity' => $clientCity, 'clientState' => $clientState, 'clientZip' => $clientZip,
            'amount' => $amount, 'description' => $description, 'frequency' => $frequency,
            'startDate' => $startDate, 'nextCharge' => $nextCharge,
            'cardLast4' => $cardLast4, 'cardBrand' => $cardBrand,
            'encryptedCard' => $encryptedCard, 'status' => 'active',
            'createdAt' => date('c'), 'history' => [], 'failCount' => 0,
            'source' => $appName,
        ];
        if ($metaBlock) $sub['metadata'] = $metaBlock;

        $all = getAutopays();
        array_unshift($all, $sub);
        saveAutopays($all);
        saveCardForCustomer($clientName, $clientEmail, $clientPhone, $clientAddress, $clientCity, $clientState, $clientZip, $cardNumber, $expMonth, $expYear, $cvc, $cardLast4, $cardBrand);

        $response = [
            'success' => true, 'subscription_id' => $sub['id'], 'amount' => $amount,
            'frequency' => $frequency, 'next_charge' => $nextCharge, 'status' => 'active',
        ];
        if ($metaBlock) $response['metadata'] = $metaBlock;
        if ($firstChargeResult) $response['first_charge'] = $firstChargeResult;
        fireWebhooks('autopay.created', $response, $appName);
        echo json_encode($response);
        break;

    // ═══════════════════════════════════════════════════════════
    // GET /customer — Look up a customer by name or email
    // ═══════════════════════════════════════════════════════════
    case 'customer':
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['success' => false, 'error' => 'GET required']); exit; }

        $search = trim($_GET['name'] ?? $_GET['email'] ?? '');
        if (!$search) { echo json_encode(['success' => false, 'error' => 'Provide ?name= or ?email= parameter']); exit; }

        $cards = getSavedCards();
        $autopays = getAutopays();
        $transactions = getTransactions();

        $customer = null;
        foreach ($cards as $c) {
            if (strcasecmp(trim($c['clientName'] ?? ''), $search) === 0 || strcasecmp(trim($c['clientEmail'] ?? ''), $search) === 0) {
                $customer = $c;
                break;
            }
        }

        if (!$customer) { echo json_encode(['success' => false, 'error' => 'Customer not found']); exit; }

        $custName = $customer['clientName'] ?? '';
        $custAutopays = array_values(array_filter($autopays, function($a) use ($custName, $appName) {
            return strcasecmp(trim($a['clientName'] ?? ''), $custName) === 0 && ($a['source'] ?? '') === $appName;
        }));
        $custTxns = array_values(array_filter($transactions, function($t) use ($custName, $appName) {
            return strcasecmp(trim($t['clientName'] ?? ''), $custName) === 0 && ($t['source'] ?? '') === $appName;
        }));

        echo json_encode([
            'success' => true,
            'customer' => [
                'name' => $customer['clientName'] ?? '',
                'email' => $customer['clientEmail'] ?? '',
                'phone' => $customer['clientPhone'] ?? '',
                'address' => $customer['clientAddress'] ?? '',
                'city' => $customer['clientCity'] ?? '',
                'state' => $customer['clientState'] ?? '',
                'zip' => $customer['clientZip'] ?? '',
                'card_last4' => $customer['cardLast4'] ?? '',
                'card_brand' => $customer['cardBrand'] ?? '',
            ],
            'autopay_subscriptions' => array_map(function($a) {
                return [
                    'id' => $a['id'], 'amount' => $a['amount'], 'frequency' => $a['frequency'],
                    'status' => $a['status'], 'next_charge' => $a['nextCharge'] ?? '',
                ];
            }, $custAutopays),
            'recent_transactions' => array_map(function($t) {
                return [
                    'id' => $t['id'], 'amount' => $t['amount'], 'status' => $t['status'],
                    'date' => $t['timestamp'] ?? '', 'description' => $t['description'] ?? '',
                ];
            }, array_slice($custTxns, 0, 10)),
        ]);
        break;

    // ═══════════════════════════════════════════════════════════
    // POST /refund — Refund a transaction
    // ═══════════════════════════════════════════════════════════
    case 'refund':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'error' => 'POST required']); exit; }

        $txnId = $input['transaction_id'] ?? $input['id'] ?? '';
        $refundAmount = floatval($input['amount'] ?? 0);

        if (!$txnId) { echo json_encode(['success' => false, 'error' => 'transaction_id is required']); exit; }

        // Find the transaction
        $allTxns = getTransactions();
        $txn = null;
        foreach ($allTxns as &$t) {
            if ($t['id'] === $txnId) { $txn = &$t; break; }
        }
        unset($t);
        if (!$txn) { echo json_encode(['success' => false, 'error' => 'Transaction not found']); exit; }
        if (($txn['source'] ?? '') !== $appName) { echo json_encode(['success' => false, 'error' => 'Transaction not found']); exit; }
        if ($txn['status'] !== 'approved') { echo json_encode(['success' => false, 'error' => 'Can only refund approved transactions']); exit; }
        if ($refundAmount <= 0) $refundAmount = floatval($txn['amount']);

        // Process refund through gateway
        $token = getToken();
        if (!$token) { http_response_code(503); echo json_encode(['success' => false, 'error' => 'Payment gateway not connected']); exit; }

        $saleId = $txn['id'];
        $refundResult = squireAPI('POST', '/v2/shop/' . $SHOP_ID . '/sale/' . $saleId . '/refund', ['amount' => round($refundAmount * 100)], $token);

        if ($refundResult['code'] >= 200 && $refundResult['code'] < 300) {
            $txn['status'] = 'refunded';
            $txn['refundedAt'] = date('c');
            $txn['refundAmount'] = $refundAmount;
            saveTransactions($allTxns);
            echo json_encode(['success' => true, 'transaction_id' => $txnId, 'refund_amount' => $refundAmount, 'status' => 'refunded']);
        } else {
            $errorMsg = $refundResult['body']['message'] ?? ($refundResult['body']['error'] ?? 'Refund failed');
            echo json_encode(['success' => false, 'error' => $errorMsg]);
        }
        break;

    // ═══════════════════════════════════════════════════════════
    // POST /cancel-autopay — Cancel an autopay subscription
    // ═══════════════════════════════════════════════════════════
    case 'cancel-autopay':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'error' => 'POST required']); exit; }

        $subId = $input['subscription_id'] ?? $input['id'] ?? '';
        if (!$subId) { echo json_encode(['success' => false, 'error' => 'subscription_id is required']); exit; }

        $all = getAutopays();
        $found = false;
        foreach ($all as &$sub) {
            if ($sub['id'] === $subId && ($sub['source'] ?? '') === $appName) {
                $sub['status'] = 'cancelled';
                $sub['cancelledAt'] = date('c');
                $found = true;
                break;
            }
        }
        unset($sub);

        if (!$found) { echo json_encode(['success' => false, 'error' => 'Subscription not found']); exit; }
        saveAutopays($all);
        echo json_encode(['success' => true, 'subscription_id' => $subId, 'status' => 'cancelled']);
        break;

    // ═══════════════════════════════════════════════════════════
    // GET /transactions — List recent transactions
    // ═══════════════════════════════════════════════════════════
    case 'transactions':
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['success' => false, 'error' => 'GET required']); exit; }

        $limit = intval($_GET['limit'] ?? 50);
        $status = $_GET['status'] ?? '';
        $allTxns = getTransactions();

        // Only show transactions from this app's source
        $allTxns = array_values(array_filter($allTxns, function($t) use ($appName) { return ($t['source'] ?? '') === $appName; }));

        if ($status) {
            $allTxns = array_values(array_filter($allTxns, function($t) use ($status) { return $t['status'] === $status; }));
        }

        echo json_encode([
            'success' => true,
            'total' => count($allTxns),
            'transactions' => array_map(function($t) {
                return [
                    'id' => $t['id'], 'amount' => $t['amount'], 'status' => $t['status'],
                    'client_name' => $t['clientName'] ?? '', 'client_email' => $t['clientEmail'] ?? '',
                    'description' => $t['description'] ?? '', 'date' => $t['timestamp'] ?? '',
                    'card_last4' => $t['cardLast4'] ?? '', 'card_brand' => $t['cardBrand'] ?? '',
                    'source' => $t['source'] ?? 'manual',
                    'metadata' => $t['metadata'] ?? null,
                ];
            }, array_slice($allTxns, 0, $limit)),
        ]);
        break;

    // ═══════════════════════════════════════════════════════════
    // POST /webhooks — Register a webhook URL
    // ═══════════════════════════════════════════════════════════
    case 'webhooks':
        if ($method === 'POST') {
            $url = trim($input['url'] ?? '');
            if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
                echo json_encode(['success' => false, 'error' => 'Valid url is required']);
                exit;
            }
            $events = $input['events'] ?? ['*'];
            $secret = bin2hex(random_bytes(16));
            $wh = [
                'id' => 'wh_' . bin2hex(random_bytes(8)),
                'url' => $url,
                'events' => $events,
                'secret' => $secret,
                'active' => true,
                'createdAt' => date('c'),
                'source' => $appName,
            ];
            $all = getWebhooks();
            $all[] = $wh;
            saveWebhooks($all);
            echo json_encode(['success' => true, 'webhook' => $wh]);
        } elseif ($method === 'GET') {
            echo json_encode(['success' => true, 'webhooks' => getWebhooks()]);
        } elseif ($method === 'DELETE') {
            $whId = $input['id'] ?? $_GET['id'] ?? '';
            $all = getWebhooks();
            $all = array_values(array_filter($all, function($w) use ($whId) { return $w['id'] !== $whId; }));
            saveWebhooks($all);
            echo json_encode(['success' => true, 'deleted' => $whId]);
        }
        break;

    // ═══════════════════════════════════════════════════════════
    // Default — Show available endpoints
    // ═══════════════════════════════════════════════════════════
    default:
        echo json_encode([
            'success' => true,
            'message' => 'Authorize.net Terminal API',
            'version' => '2.0',
            'mode' => $isTestMode ? 'test' : 'live',
            'endpoints' => [
                'POST /api.php?endpoint=charge' => 'Process a one-time payment',
                'POST /api.php?endpoint=autopay' => 'Set up recurring autopay',
                'GET  /api.php?endpoint=customer&name=John' => 'Look up customer info',
                'POST /api.php?endpoint=refund' => 'Refund a transaction',
                'POST /api.php?endpoint=cancel-autopay' => 'Cancel autopay subscription',
                'GET  /api.php?endpoint=transactions' => 'List recent transactions',
                'POST /api.php?endpoint=webhooks' => 'Register a webhook URL',
                'GET  /api.php?endpoint=webhooks' => 'List registered webhooks',
            ],
        ]);
        break;
}
