<?php
require_once __DIR__ . '/lib.php';

/**
 * Hosted-site payments API.
 * URL: https://autopay.builtbyjj.dev/p/<site>/api?endpoint=...
 * Authentication: Authorization: Bearer <site api key>
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function siteFail(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function siteInput(array $input, string ...$names): string {
    foreach ($names as $name) {
        if (isset($input[$name])) return trim((string)$input[$name]);
    }
    return '';
}

$siteId = (string)($_GET['site'] ?? '');
$site = preg_match('/^[a-z0-9-]{1,40}$/', $siteId) ? pfFindSite($siteId) : null;
if (!$site || ($site['type'] ?? '') !== 'hosted') siteFail('Unknown site', 404);
$settings = siteSettings($site);
if (empty($settings['active'])) siteFail('This site is suspended', 403);

$header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$apiKey = strpos($header, 'Bearer ') === 0 ? substr($header, 7) : '';
$keys = siteKeys($site);
if ($apiKey === '' || empty($keys[$apiKey]) || empty($keys[$apiKey]['active'])) {
    siteFail('Invalid or missing API key. Include Authorization: Bearer YOUR_KEY header.', 401);
}
$isTestMode = ($keys[$apiKey]['mode'] ?? 'live') === 'test';
if (!$isTestMode && empty($settings['liveEnabled'])) {
    siteFail('Live payments are not enabled for this site yet. Use the test key.', 403);
}

$method = $_SERVER['REQUEST_METHOD'];
$endpoint = (string)($_GET['endpoint'] ?? '');
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$base = $site['apiUrl'];

function siteCustomerFromInput(array $input): array {
    return [
        'clientName' => siteInput($input, 'client_name', 'clientName'),
        'clientEmail' => siteInput($input, 'client_email', 'clientEmail'),
        'clientPhone' => siteInput($input, 'client_phone', 'clientPhone'),
        'clientAddress' => siteInput($input, 'address', 'clientAddress'),
        'clientCity' => siteInput($input, 'city', 'clientCity'),
        'clientState' => siteInput($input, 'state', 'clientState'),
        'clientZip' => siteInput($input, 'zip', 'clientZip'),
    ];
}

function siteCardFromInput(array $input): array {
    $card = [
        'number' => preg_replace('/\s+/', '', siteInput($input, 'card_number', 'cardNumber')),
        'exp_month' => siteInput($input, 'exp_month', 'expMonth'),
        'exp_year' => siteInput($input, 'exp_year', 'expYear'),
        'cvc' => siteInput($input, 'cvc', 'cvv'),
    ];
    if (!$card['number'] || !$card['exp_month'] || !$card['exp_year'] || !$card['cvc']) {
        siteFail('Card details required: card_number, exp_month, exp_year, cvc');
    }
    return $card;
}

function siteCheckAmount(float $amount, array $settings): void {
    if ($amount <= 0) siteFail('Amount must be greater than 0');
    $max = floatval($settings['maxCharge'] ?? 0);
    if ($max > 0 && $amount > $max) siteFail('Amount exceeds this site\'s limit of $' . number_format($max, 2));
}

/** Tokenizes and charges a card through the shared gateway. */
function siteLiveCharge(array $customer, array $card, float $amount): array {
    $token = cgGatewayToken();
    if (!$token) siteFail('Payment gateway not connected', 503);
    $tokenResult = cgTokenizeCard($card['number'], $card['exp_month'], $card['exp_year'], $card['cvc'], [
        'name' => $customer['clientName'], 'line1' => $customer['clientAddress'], 'city' => $customer['clientCity'],
        'state' => $customer['clientState'], 'zip' => $customer['clientZip'],
    ]);
    if (empty($tokenResult['id'])) siteFail($tokenResult['error']['message'] ?? 'Card validation failed');
    $result = cgCharge($tokenResult['id'], $amount, $token);
    if (!empty($result['gatewayUnavailable'])) siteFail($result['body']['error'] ?? 'Payment gateway unavailable', 503);
    $approved = $result['code'] >= 200 && $result['code'] < 300;
    return [
        'approved' => $approved,
        'id' => $approved ? ($result['body']['id'] ?? uniqid('txn_')) : uniqid('txn_'),
        'brand' => $tokenResult['card']['brand'] ?? 'Card',
        'error' => $approved ? '' : ($result['body']['message'] ?? ($result['body']['error'] ?? 'Charge failed')),
    ];
}

switch ($endpoint) {

    case 'charge':
        if ($method !== 'POST') siteFail('POST required', 405);
        $amount = round(floatval($input['amount'] ?? 0), 2);
        siteCheckAmount($amount, $settings);
        $customer = siteCustomerFromInput($input);
        if (!$customer['clientName']) siteFail('client_name is required');
        $card = siteCardFromInput($input);
        $description = siteInput($input, 'description') ?: ($settings['defaultDescription'] ?: $site['name'] . ' Payment');
        $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
        $last4 = substr($card['number'], -4);

        if ($isTestMode) {
            $approved = $last4 !== '0002';
            $res = ['approved' => $approved, 'id' => 'test_' . bin2hex(random_bytes(8)), 'brand' => 'TestCard', 'error' => $approved ? '' : 'Test decline (simulated)'];
        } else {
            $res = siteLiveCharge($customer, $card, $amount);
        }

        $txn = array_merge($customer, [
            'id' => $res['id'], 'amount' => $amount, 'description' => $description,
            'status' => $res['approved'] ? 'approved' : 'declined', 'timestamp' => date('c'),
            'cardLast4' => $last4, 'cardBrand' => $res['brand'], 'app' => $site['name'],
        ]);
        if ($isTestMode) $txn['testMode'] = true;
        if ($res['error']) $txn['error'] = $res['error'];
        if ($metadata) $txn['metadata'] = $metadata;
        siteSaveTransaction($site, $txn);
        siteAudit($site, 'charge_' . $txn['status'], 'Transaction ' . $txn['id'], '$' . number_format($amount, 2) . ' - ' . $customer['clientName'] . ($isTestMode ? ' (test)' : ''));
        if (!$isTestMode && $res['approved'] && !empty($input['save_card'])) {
            siteSaveCardForCustomer($site, $customer, $card + ['last4' => $last4, 'brand' => $res['brand']]);
        }

        $resp = [
            'success' => $res['approved'], 'transaction_id' => $txn['id'], 'amount' => $amount, 'status' => $txn['status'],
            'card_last4' => $last4, 'card_brand' => $res['brand'], 'timestamp' => $txn['timestamp'],
        ];
        if ($isTestMode) $resp['test_mode'] = true;
        if ($metadata) $resp['metadata'] = $metadata;
        if (!$res['approved']) { $resp['error'] = $res['error']; http_response_code(402); }
        siteFireWebhooks($site, 'charge.' . $txn['status'], $resp);
        echo json_encode($resp);
        break;

    case 'autopay':
        if ($method !== 'POST') siteFail('POST required', 405);
        if (empty($settings['allowRecurring'])) siteFail('Recurring payments are not enabled for this site', 403);
        $amount = round(floatval($input['amount'] ?? 0), 2);
        siteCheckAmount($amount, $settings);
        $customer = siteCustomerFromInput($input);
        if (!$customer['clientName']) siteFail('client_name is required');
        $card = siteCardFromInput($input);
        $frequency = siteInput($input, 'frequency') ?: 'monthly';
        if (!in_array($frequency, ['weekly', 'biweekly', 'monthly', 'quarterly'], true)) {
            siteFail('Invalid frequency. Use: weekly, biweekly, monthly, quarterly');
        }
        $startDate = siteInput($input, 'start_date', 'startDate') ?: date('Y-m-d');
        $chargeNow = !isset($input['charge_now']) || !empty($input['charge_now']);
        $description = siteInput($input, 'description') ?: ($settings['defaultDescription'] ?: $site['name'] . ' Subscription');
        $last4 = substr($card['number'], -4);
        $brand = $isTestMode ? 'TestCard' : 'Card';
        $firstCharge = null;

        if ($chargeNow && !$isTestMode) {
            $res = siteLiveCharge($customer, $card, $amount);
            if (!$res['approved']) siteFail('First charge failed: ' . $res['error'], 402);
            $brand = $res['brand'];
            $txn = array_merge($customer, [
                'id' => $res['id'], 'amount' => $amount, 'description' => $description, 'status' => 'approved',
                'timestamp' => date('c'), 'cardLast4' => $last4, 'cardBrand' => $brand, 'app' => $site['name'],
            ]);
            siteSaveTransaction($site, $txn);
            siteAudit($site, 'charge_approved', 'Transaction ' . $txn['id'], '$' . number_format($amount, 2) . ' - ' . $customer['clientName']);
            $firstCharge = ['success' => true, 'transaction_id' => $txn['id'], 'amount' => $amount];
        }

        $sub = array_merge($customer, [
            'id' => ($isTestMode ? 'test_sub_' : 'sub_') . bin2hex(random_bytes(8)),
            'amount' => $amount, 'description' => $description, 'frequency' => $frequency, 'startDate' => $startDate,
            'nextCharge' => $chargeNow ? cgNextCharge($startDate, $frequency) : $startDate,
            'cardLast4' => $last4, 'cardBrand' => $brand, 'status' => 'active',
            'createdAt' => date('c'), 'history' => [], 'failCount' => 0, 'app' => $site['name'],
        ]);
        if ($isTestMode) {
            $sub['testMode'] = true;
        } else {
            $sub['encryptedCard'] = cgEncryptCard($card);
        }
        $all = siteAutopays($site);
        array_unshift($all, $sub);
        siteSaveAutopays($site, $all);
        siteAudit($site, 'autopay_created', 'Subscription ' . $sub['id'], '$' . number_format($amount, 2) . ' ' . $frequency . ' - ' . $customer['clientName']);
        $resp = ['success' => true, 'subscription_id' => $sub['id'], 'amount' => $amount, 'frequency' => $frequency, 'next_charge' => $sub['nextCharge'], 'status' => 'active'];
        if ($isTestMode) $resp['test_mode'] = true;
        if ($firstCharge) $resp['first_charge'] = $firstCharge;
        siteFireWebhooks($site, 'autopay.created', $resp);
        echo json_encode($resp);
        break;

    case 'cancel-autopay':
        if ($method !== 'POST') siteFail('POST required', 405);
        $subId = siteInput($input, 'subscription_id', 'id');
        if (!$subId) siteFail('subscription_id is required');
        $found = false;
        storageMutateDocument(siteDoc($site, 'autopay'), [], function ($all) use ($subId, &$found) {
            foreach ($all as &$s) {
                if (($s['id'] ?? '') === $subId) { $s['status'] = 'cancelled'; $s['cancelledAt'] = date('c'); $found = true; break; }
            }
            return $all;
        });
        if (!$found) siteFail('Subscription not found', 404);
        siteAudit($site, 'autopay_cancelled', 'Subscription ' . $subId, 'Cancelled via API');
        echo json_encode(['success' => true, 'subscription_id' => $subId, 'status' => 'cancelled']);
        break;

    case 'refund':
        if ($method !== 'POST') siteFail('POST required', 405);
        if (empty($settings['allowRefunds'])) siteFail('Refunds through the API are disabled for this site', 403);
        $txnId = siteInput($input, 'transaction_id', 'id');
        if (!$txnId) siteFail('transaction_id is required');
        $r = siteRefund($site, $txnId, round(floatval($input['amount'] ?? 0), 2), 'api', $isTestMode);
        if (!$r['ok']) siteFail($r['error'], $r['status']);
        echo json_encode(['success' => true, 'transaction_id' => $txnId, 'refund_amount' => $r['amount'], 'status' => 'refunded']);
        break;

    case 'transactions':
        if ($method !== 'GET') siteFail('GET required', 405);
        $limit = max(1, min(500, intval($_GET['limit'] ?? 50)));
        $status = (string)($_GET['status'] ?? '');
        $all = siteTransactions($site);
        if ($status !== '') $all = array_values(array_filter($all, fn($t) => ($t['status'] ?? '') === $status));
        echo json_encode([
            'success' => true, 'total' => count($all),
            'transactions' => array_map(fn($t) => [
                'id' => $t['id'] ?? '', 'amount' => $t['amount'] ?? 0, 'status' => $t['status'] ?? '',
                'client_name' => $t['clientName'] ?? '', 'client_email' => $t['clientEmail'] ?? '',
                'description' => $t['description'] ?? '', 'date' => $t['timestamp'] ?? '',
                'card_last4' => $t['cardLast4'] ?? '', 'card_brand' => $t['cardBrand'] ?? '',
                'test_mode' => !empty($t['testMode']), 'metadata' => $t['metadata'] ?? null,
            ], array_slice($all, 0, $limit)),
        ]);
        break;

    case 'subscriptions':
        if ($method !== 'GET') siteFail('GET required', 405);
        echo json_encode([
            'success' => true,
            'subscriptions' => array_map(fn($s) => [
                'id' => $s['id'] ?? '', 'amount' => $s['amount'] ?? 0, 'frequency' => $s['frequency'] ?? '',
                'status' => $s['status'] ?? '', 'next_charge' => $s['nextCharge'] ?? '',
                'client_name' => $s['clientName'] ?? '', 'client_email' => $s['clientEmail'] ?? '',
                'test_mode' => !empty($s['testMode']),
            ], siteAutopays($site)),
        ]);
        break;

    case 'webhooks':
        $doc = siteDoc($site, 'webhooks');
        if ($method === 'POST') {
            $url = siteInput($input, 'url');
            if (!$url || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) siteFail('Valid http(s) url is required');
            $wh = [
                'id' => 'wh_' . bin2hex(random_bytes(8)), 'url' => $url,
                'events' => is_array($input['events'] ?? null) ? $input['events'] : ['*'],
                'secret' => bin2hex(random_bytes(16)), 'active' => true, 'createdAt' => date('c'),
            ];
            storageMutateDocument($doc, [], function ($all) use ($wh) { $all[] = $wh; return $all; });
            echo json_encode(['success' => true, 'webhook' => $wh]);
        } elseif ($method === 'GET') {
            echo json_encode(['success' => true, 'webhooks' => storageReadDocument($doc, [])]);
        } elseif ($method === 'DELETE') {
            $whId = siteInput($input, 'id') ?: (string)($_GET['id'] ?? '');
            storageMutateDocument($doc, [], fn($all) => array_values(array_filter($all, fn($w) => ($w['id'] ?? '') !== $whId)));
            echo json_encode(['success' => true, 'deleted' => $whId]);
        } else {
            siteFail('Unsupported method', 405);
        }
        break;

    default:
        echo json_encode([
            'success' => true,
            'message' => $site['name'] . ' Payments API',
            'version' => '1.0',
            'mode' => $isTestMode ? 'test' : 'live',
            'live_enabled' => !empty($settings['liveEnabled']),
            'recurring_enabled' => !empty($settings['allowRecurring']),
            'max_charge' => floatval($settings['maxCharge']) ?: null,
            'endpoints' => [
                "POST $base?endpoint=charge" => 'Process a customer checkout payment',
                "POST $base?endpoint=autopay" => 'Create a recurring subscription',
                "POST $base?endpoint=cancel-autopay" => 'Cancel a subscription',
                "POST $base?endpoint=refund" => 'Refund a transaction',
                "GET  $base?endpoint=transactions" => 'List transactions',
                "GET  $base?endpoint=subscriptions" => 'List subscriptions',
                "POST $base?endpoint=webhooks" => 'Register a webhook URL',
            ],
        ]);
        break;
}
