<?php
require_once __DIR__ . '/lib.php';

/**
 * CreditGod Payments API
 *
 * Base URL: https://autopay.builtbyjj.dev/creditgod/api.php
 * Authentication: Authorization: Bearer <api key>
 *
 * Records live in their own documents (creditgod_*) and never appear in the
 * Autopay terminal dashboard.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function cgFail(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$apiKey = strpos($header, 'Bearer ') === 0 ? substr($header, 7) : ($_GET['api_key'] ?? '');
$keys = cgApiKeys();
if ($apiKey === '' || empty($keys[$apiKey]) || empty($keys[$apiKey]['active'])) {
    cgFail('Invalid or missing API key. Include Authorization: Bearer YOUR_KEY header.', 401);
}
$isTestMode = ($keys[$apiKey]['mode'] ?? 'live') === 'test';

$method = $_SERVER['REQUEST_METHOD'];
$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$path = preg_replace('#^creditgod/?#', '', $path);
$path = preg_replace('#^(api\.php/?|api/?)#', '', $path);
$endpoint = $path ?: ($_GET['endpoint'] ?? '');

$input = json_decode(file_get_contents('php://input'), true) ?: [];

function cgInput(array $input, string ...$names): string {
    foreach ($names as $name) {
        if (isset($input[$name])) return trim((string)$input[$name]);
    }
    return '';
}

switch ($endpoint) {

    // POST /creditgod/api.php?endpoint=charge — one-time customer checkout payment
    case 'charge':
        if ($method !== 'POST') cgFail('POST required', 405);

        $amount = round(floatval($input['amount'] ?? 0), 2);
        $description = cgInput($input, 'description') ?: 'CreditGod Payment';
        $clientName = cgInput($input, 'client_name', 'clientName');
        $clientEmail = cgInput($input, 'client_email', 'clientEmail');
        $clientPhone = cgInput($input, 'client_phone', 'clientPhone');
        $clientAddress = cgInput($input, 'address', 'clientAddress');
        $clientCity = cgInput($input, 'city', 'clientCity');
        $clientState = cgInput($input, 'state', 'clientState');
        $clientZip = cgInput($input, 'zip', 'clientZip');
        $cardNumber = preg_replace('/\s+/', '', cgInput($input, 'card_number', 'cardNumber'));
        $expMonth = cgInput($input, 'exp_month', 'expMonth');
        $expYear = cgInput($input, 'exp_year', 'expYear');
        $cvc = cgInput($input, 'cvc', 'cvv');
        $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
        $saveCard = !empty($input['save_card'] ?? $input['saveCard'] ?? false);

        if ($amount <= 0) cgFail('Amount must be greater than 0');
        if (!$clientName) cgFail('client_name is required');
        if (!$cardNumber || !$expMonth || !$expYear || !$cvc) cgFail('Card details required: card_number, exp_month, exp_year, cvc');

        $customer = [
            'clientName' => $clientName, 'clientEmail' => $clientEmail, 'clientPhone' => $clientPhone,
            'clientAddress' => $clientAddress, 'clientCity' => $clientCity, 'clientState' => $clientState, 'clientZip' => $clientZip,
        ];

        if ($isTestMode) {
            $approved = substr($cardNumber, -4) !== '0002';
            $txn = array_merge($customer, [
                'id' => 'cg_test_' . bin2hex(random_bytes(8)),
                'amount' => $amount, 'description' => $description,
                'status' => $approved ? 'approved' : 'declined',
                'timestamp' => date('c'),
                'cardLast4' => substr($cardNumber, -4), 'cardBrand' => 'TestCard',
                'testMode' => true, 'app' => 'CreditGod',
            ]);
            if ($metadata) $txn['metadata'] = $metadata;
            cgSaveTransaction($txn);
            $resp = [
                'success' => $approved, 'transaction_id' => $txn['id'], 'amount' => $amount,
                'status' => $txn['status'], 'card_last4' => $txn['cardLast4'], 'card_brand' => 'TestCard',
                'timestamp' => $txn['timestamp'], 'test_mode' => true,
            ];
            if (!$approved) $resp['error'] = 'Test decline (simulated)';
            cgFireWebhooks('charge.' . $txn['status'], $resp);
            echo json_encode($resp);
            break;
        }

        $token = cgGatewayToken();
        if (!$token) cgFail('Payment gateway not connected', 503);

        $tokenResult = cgTokenizeCard($cardNumber, $expMonth, $expYear, $cvc, [
            'name' => $clientName, 'line1' => $clientAddress, 'city' => $clientCity,
            'state' => $clientState, 'zip' => $clientZip,
        ]);
        if (empty($tokenResult['id'])) {
            cgFail($tokenResult['error']['message'] ?? 'Card validation failed');
        }

        $cardLast4 = substr($cardNumber, -4);
        $cardBrand = $tokenResult['card']['brand'] ?? 'Card';
        $result = cgCharge($tokenResult['id'], $amount, $token);

        if (!empty($result['gatewayUnavailable'])) {
            cgFail($result['body']['error'] ?? 'Payment gateway unavailable', 503);
        }

        $approved = $result['code'] >= 200 && $result['code'] < 300;
        $errorMsg = $approved ? '' : ($result['body']['message'] ?? ($result['body']['error'] ?? 'Charge failed'));

        $txn = array_merge($customer, [
            'id' => $approved ? ($result['body']['id'] ?? uniqid('cg_txn_')) : uniqid('cg_txn_'),
            'amount' => $amount, 'description' => $description,
            'status' => $approved ? 'approved' : 'declined',
            'timestamp' => date('c'),
            'cardLast4' => $cardLast4, 'cardBrand' => $cardBrand,
            'app' => 'CreditGod',
        ]);
        if ($metadata) $txn['metadata'] = $metadata;
        if (!$approved) $txn['error'] = $errorMsg;
        cgSaveTransaction($txn);
        cgAudit('charge_' . $txn['status'], 'Transaction ' . $txn['id'], '$' . number_format($amount, 2) . ' - ' . $clientName);

        if ($approved && $saveCard) {
            cgSaveCardForCustomer($customer, [
                'number' => $cardNumber, 'exp_month' => $expMonth, 'exp_year' => $expYear, 'cvc' => $cvc,
                'last4' => $cardLast4, 'brand' => $cardBrand,
            ]);
        }

        $resp = [
            'success' => $approved, 'transaction_id' => $txn['id'], 'amount' => $amount,
            'status' => $txn['status'], 'card_last4' => $cardLast4, 'card_brand' => $cardBrand,
            'timestamp' => $txn['timestamp'],
        ];
        if ($metadata) $resp['metadata'] = $metadata;
        if (!$approved) $resp['error'] = $errorMsg;
        cgFireWebhooks('charge.' . $txn['status'], $resp);
        echo json_encode($resp);
        break;

    // POST /creditgod/api.php?endpoint=autopay — recurring subscription the customer agreed to
    case 'autopay':
        if ($method !== 'POST') cgFail('POST required', 405);

        $amount = round(floatval($input['amount'] ?? 0), 2);
        $clientName = cgInput($input, 'client_name', 'clientName');
        $clientEmail = cgInput($input, 'client_email', 'clientEmail');
        $clientPhone = cgInput($input, 'client_phone', 'clientPhone');
        $clientAddress = cgInput($input, 'address', 'clientAddress');
        $clientCity = cgInput($input, 'city', 'clientCity');
        $clientState = cgInput($input, 'state', 'clientState');
        $clientZip = cgInput($input, 'zip', 'clientZip');
        $description = cgInput($input, 'description') ?: 'CreditGod Recurring Payment';
        $frequency = cgInput($input, 'frequency') ?: 'monthly';
        $startDate = cgInput($input, 'start_date', 'startDate') ?: date('Y-m-d');
        $cardNumber = preg_replace('/\s+/', '', cgInput($input, 'card_number', 'cardNumber'));
        $expMonth = cgInput($input, 'exp_month', 'expMonth');
        $expYear = cgInput($input, 'exp_year', 'expYear');
        $cvc = cgInput($input, 'cvc', 'cvv');
        $chargeNow = !empty($input['charge_now'] ?? $input['chargeNow'] ?? false);
        $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];

        if (!$clientName) cgFail('client_name is required');
        if ($amount <= 0) cgFail('Amount must be greater than 0');
        if (!$cardNumber || !$expMonth || !$expYear || !$cvc) cgFail('Card details required');
        if (!in_array($frequency, ['weekly', 'biweekly', 'monthly', 'quarterly'], true)) {
            cgFail('Invalid frequency. Use: weekly, biweekly, monthly, quarterly');
        }

        $customer = [
            'clientName' => $clientName, 'clientEmail' => $clientEmail, 'clientPhone' => $clientPhone,
            'clientAddress' => $clientAddress, 'clientCity' => $clientCity, 'clientState' => $clientState, 'clientZip' => $clientZip,
        ];
        $cardLast4 = substr($cardNumber, -4);
        $cardBrand = 'Card';
        $firstCharge = null;

        if ($isTestMode) {
            $sub = array_merge($customer, [
                'id' => 'cg_test_sub_' . bin2hex(random_bytes(8)),
                'amount' => $amount, 'description' => $description, 'frequency' => $frequency,
                'startDate' => $startDate,
                'nextCharge' => $chargeNow ? cgNextCharge($startDate, $frequency) : $startDate,
                'cardLast4' => $cardLast4, 'cardBrand' => 'TestCard', 'status' => 'active',
                'createdAt' => date('c'), 'history' => [], 'failCount' => 0,
                'testMode' => true, 'app' => 'CreditGod',
            ]);
            if ($metadata) $sub['metadata'] = $metadata;
            $all = cgAutopays();
            array_unshift($all, $sub);
            cgSaveAutopays($all);
            $resp = [
                'success' => true, 'subscription_id' => $sub['id'], 'amount' => $amount,
                'frequency' => $frequency, 'next_charge' => $sub['nextCharge'], 'status' => 'active', 'test_mode' => true,
            ];
            cgFireWebhooks('autopay.created', $resp);
            echo json_encode($resp);
            break;
        }

        if ($chargeNow) {
            $token = cgGatewayToken();
            if (!$token) cgFail('Payment gateway not connected', 503);
            $tokenResult = cgTokenizeCard($cardNumber, $expMonth, $expYear, $cvc, [
                'name' => $clientName, 'line1' => $clientAddress, 'city' => $clientCity,
                'state' => $clientState, 'zip' => $clientZip,
            ]);
            if (empty($tokenResult['id'])) cgFail($tokenResult['error']['message'] ?? 'Card validation failed');
            $cardBrand = $tokenResult['card']['brand'] ?? 'Card';
            $result = cgCharge($tokenResult['id'], $amount, $token);
            if (!empty($result['gatewayUnavailable'])) cgFail($result['body']['error'] ?? 'Payment gateway unavailable', 503);
            if ($result['code'] < 200 || $result['code'] >= 300) {
                cgFail('First charge failed: ' . ($result['body']['message'] ?? ($result['body']['error'] ?? 'Charge failed')));
            }
            $txn = array_merge($customer, [
                'id' => $result['body']['id'] ?? uniqid('cg_txn_'),
                'amount' => $amount, 'description' => $description, 'status' => 'approved',
                'timestamp' => date('c'), 'cardLast4' => $cardLast4, 'cardBrand' => $cardBrand,
                'app' => 'CreditGod',
            ]);
            if ($metadata) $txn['metadata'] = $metadata;
            cgSaveTransaction($txn);
            cgAudit('charge_approved', 'Transaction ' . $txn['id'], '$' . number_format($amount, 2) . ' - ' . $clientName);
            $firstCharge = ['success' => true, 'transaction_id' => $txn['id'], 'amount' => $amount];
        }

        $sub = array_merge($customer, [
            'id' => 'cg_sub_' . bin2hex(random_bytes(8)),
            'amount' => $amount, 'description' => $description, 'frequency' => $frequency,
            'startDate' => $startDate,
            'nextCharge' => $chargeNow ? cgNextCharge($startDate, $frequency) : $startDate,
            'cardLast4' => $cardLast4, 'cardBrand' => $cardBrand,
            'encryptedCard' => cgEncryptCard(['number' => $cardNumber, 'exp_month' => $expMonth, 'exp_year' => $expYear, 'cvc' => $cvc]),
            'status' => 'active', 'createdAt' => date('c'), 'history' => [], 'failCount' => 0,
            'app' => 'CreditGod',
        ]);
        if ($metadata) $sub['metadata'] = $metadata;

        $all = cgAutopays();
        array_unshift($all, $sub);
        cgSaveAutopays($all);
        cgSaveCardForCustomer($customer, [
            'number' => $cardNumber, 'exp_month' => $expMonth, 'exp_year' => $expYear, 'cvc' => $cvc,
            'last4' => $cardLast4, 'brand' => $cardBrand,
        ]);
        cgAudit('autopay_created', 'Subscription ' . $sub['id'], '$' . number_format($amount, 2) . ' ' . $frequency . ' - ' . $clientName);

        $resp = [
            'success' => true, 'subscription_id' => $sub['id'], 'amount' => $amount,
            'frequency' => $frequency, 'next_charge' => $sub['nextCharge'], 'status' => 'active',
        ];
        if ($metadata) $resp['metadata'] = $metadata;
        if ($firstCharge) $resp['first_charge'] = $firstCharge;
        cgFireWebhooks('autopay.created', $resp);
        echo json_encode($resp);
        break;

    // POST /creditgod/api.php?endpoint=refund
    case 'refund':
        if ($method !== 'POST') cgFail('POST required', 405);

        $txnId = cgInput($input, 'transaction_id', 'id');
        $refundAmount = round(floatval($input['amount'] ?? 0), 2);
        if (!$txnId) cgFail('transaction_id is required');

        $all = cgTransactions();
        $index = null;
        foreach ($all as $i => $t) {
            if (($t['id'] ?? '') === $txnId) { $index = $i; break; }
        }
        if ($index === null) cgFail('Transaction not found', 404);
        if (($all[$index]['status'] ?? '') !== 'approved') cgFail('Can only refund approved transactions');
        if ($refundAmount <= 0) $refundAmount = floatval($all[$index]['amount']);

        $txnIsTest = !empty($all[$index]['testMode']);
        if ($txnIsTest !== $isTestMode) {
            cgFail($txnIsTest ? 'This is a test transaction; refund it with your test key' : 'This is a live transaction; refund it with your live key');
        }

        if (!$txnIsTest) {
            if (!cgIsGatewaySaleId($txnId)) cgFail('This transaction has no processor sale ID, so it cannot be refunded through the API', 409);
            $token = cgGatewayToken();
            if (!$token) cgFail('Payment gateway not connected', 503);

            $refundResult = cgGatewayRequest('POST', '/v2/shop/' . CG_SHOP_ID . '/sale/' . $txnId . '/refund', ['amount' => (int)round($refundAmount * 100)], $token);
            if ($refundResult['code'] < 200 || $refundResult['code'] >= 300) {
                cgFail($refundResult['body']['message'] ?? ($refundResult['body']['error'] ?? 'Refund failed'));
            }
        }

        storageMutateDocument(CG_TRANSACTIONS, [], function ($txns) use ($txnId, $refundAmount) {
            foreach ($txns as &$t) {
                if (($t['id'] ?? '') === $txnId) {
                    $t['status'] = 'refunded';
                    $t['refundedAt'] = date('c');
                    $t['refundAmount'] = $refundAmount;
                    break;
                }
            }
            return $txns;
        });
        cgAudit('refund', 'Transaction ' . $txnId, '$' . number_format($refundAmount, 2));
        echo json_encode(['success' => true, 'transaction_id' => $txnId, 'refund_amount' => $refundAmount, 'status' => 'refunded']);
        break;

    // POST /creditgod/api.php?endpoint=cancel-autopay
    case 'cancel-autopay':
        if ($method !== 'POST') cgFail('POST required', 405);
        $subId = cgInput($input, 'subscription_id', 'id');
        if (!$subId) cgFail('subscription_id is required');

        $all = cgAutopays();
        $found = false;
        foreach ($all as &$sub) {
            if (($sub['id'] ?? '') === $subId) {
                $sub['status'] = 'cancelled';
                $sub['cancelledAt'] = date('c');
                $found = true;
                break;
            }
        }
        unset($sub);
        if (!$found) cgFail('Subscription not found', 404);
        cgSaveAutopays($all);
        cgAudit('autopay_cancelled', 'Subscription ' . $subId, 'Cancelled via API');
        echo json_encode(['success' => true, 'subscription_id' => $subId, 'status' => 'cancelled']);
        break;

    // GET /creditgod/api.php?endpoint=transactions
    case 'transactions':
        if ($method !== 'GET') cgFail('GET required', 405);
        $limit = max(1, min(500, intval($_GET['limit'] ?? 50)));
        $status = $_GET['status'] ?? '';
        $all = cgTransactions();
        if ($status) {
            $all = array_values(array_filter($all, fn($t) => ($t['status'] ?? '') === $status));
        }
        echo json_encode([
            'success' => true,
            'total' => count($all),
            'transactions' => array_map(fn($t) => [
                'id' => $t['id'] ?? '', 'amount' => $t['amount'] ?? 0, 'status' => $t['status'] ?? '',
                'client_name' => $t['clientName'] ?? '', 'client_email' => $t['clientEmail'] ?? '',
                'description' => $t['description'] ?? '', 'date' => $t['timestamp'] ?? '',
                'card_last4' => $t['cardLast4'] ?? '', 'card_brand' => $t['cardBrand'] ?? '',
                'metadata' => $t['metadata'] ?? null,
            ], array_slice($all, 0, $limit)),
        ]);
        break;

    // GET /creditgod/api.php?endpoint=subscriptions
    case 'subscriptions':
        if ($method !== 'GET') cgFail('GET required', 405);
        echo json_encode([
            'success' => true,
            'subscriptions' => array_map(fn($s) => [
                'id' => $s['id'] ?? '', 'amount' => $s['amount'] ?? 0, 'frequency' => $s['frequency'] ?? '',
                'status' => $s['status'] ?? '', 'next_charge' => $s['nextCharge'] ?? '',
                'client_name' => $s['clientName'] ?? '', 'client_email' => $s['clientEmail'] ?? '',
            ], cgAutopays()),
        ]);
        break;

    // GET /creditgod/api.php?endpoint=customer&name=... | &email=...
    case 'customer':
        if ($method !== 'GET') cgFail('GET required', 405);
        $search = trim($_GET['name'] ?? $_GET['email'] ?? '');
        if (!$search) cgFail('Provide ?name= or ?email= parameter');

        $customer = null;
        foreach (storageReadDocument(CG_CARDS, []) as $c) {
            if (strcasecmp(trim((string)($c['clientName'] ?? '')), $search) === 0
                || strcasecmp(trim((string)($c['clientEmail'] ?? '')), $search) === 0) {
                $customer = $c;
                break;
            }
        }
        if (!$customer) cgFail('Customer not found', 404);

        $name = $customer['clientName'] ?? '';
        $txns = array_values(array_filter(cgTransactions(), fn($t) => strcasecmp(trim((string)($t['clientName'] ?? '')), $name) === 0));
        $subs = array_values(array_filter(cgAutopays(), fn($a) => strcasecmp(trim((string)($a['clientName'] ?? '')), $name) === 0));

        echo json_encode([
            'success' => true,
            'customer' => [
                'name' => $name,
                'email' => $customer['clientEmail'] ?? '',
                'phone' => $customer['clientPhone'] ?? '',
                'address' => $customer['clientAddress'] ?? '',
                'city' => $customer['clientCity'] ?? '',
                'state' => $customer['clientState'] ?? '',
                'zip' => $customer['clientZip'] ?? '',
                'card_last4' => $customer['cardLast4'] ?? '',
                'card_brand' => $customer['cardBrand'] ?? '',
            ],
            'autopay_subscriptions' => array_map(fn($a) => [
                'id' => $a['id'] ?? '', 'amount' => $a['amount'] ?? 0, 'frequency' => $a['frequency'] ?? '',
                'status' => $a['status'] ?? '', 'next_charge' => $a['nextCharge'] ?? '',
            ], $subs),
            'recent_transactions' => array_map(fn($t) => [
                'id' => $t['id'] ?? '', 'amount' => $t['amount'] ?? 0, 'status' => $t['status'] ?? '',
                'date' => $t['timestamp'] ?? '', 'description' => $t['description'] ?? '',
            ], array_slice($txns, 0, 10)),
        ]);
        break;

    // POST|GET|DELETE /creditgod/api.php?endpoint=webhooks
    case 'webhooks':
        if ($method === 'POST') {
            $url = cgInput($input, 'url');
            if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) cgFail('Valid url is required');
            $wh = [
                'id' => 'cg_wh_' . bin2hex(random_bytes(8)),
                'url' => $url,
                'events' => $input['events'] ?? ['*'],
                'secret' => bin2hex(random_bytes(16)),
                'active' => true,
                'createdAt' => date('c'),
            ];
            storageMutateDocument(CG_WEBHOOKS, [], function ($all) use ($wh) {
                $all[] = $wh;
                return $all;
            });
            echo json_encode(['success' => true, 'webhook' => $wh]);
        } elseif ($method === 'GET') {
            echo json_encode(['success' => true, 'webhooks' => storageReadDocument(CG_WEBHOOKS, [])]);
        } elseif ($method === 'DELETE') {
            $whId = cgInput($input, 'id') ?: ($_GET['id'] ?? '');
            storageMutateDocument(CG_WEBHOOKS, [], function ($all) use ($whId) {
                return array_values(array_filter($all, fn($w) => ($w['id'] ?? '') !== $whId));
            });
            echo json_encode(['success' => true, 'deleted' => $whId]);
        } else {
            cgFail('Unsupported method', 405);
        }
        break;

    default:
        echo json_encode([
            'success' => true,
            'message' => 'CreditGod Payments API',
            'version' => '1.0',
            'mode' => $isTestMode ? 'test' : 'live',
            'endpoints' => [
                'POST /creditgod/api.php?endpoint=charge' => 'Process a customer checkout payment',
                'POST /creditgod/api.php?endpoint=autopay' => 'Create a recurring subscription',
                'POST /creditgod/api.php?endpoint=refund' => 'Refund a transaction',
                'POST /creditgod/api.php?endpoint=cancel-autopay' => 'Cancel a subscription',
                'GET  /creditgod/api.php?endpoint=transactions' => 'List CreditGod transactions',
                'GET  /creditgod/api.php?endpoint=subscriptions' => 'List CreditGod subscriptions',
                'GET  /creditgod/api.php?endpoint=customer&name=John' => 'Look up a CreditGod customer',
                'POST /creditgod/api.php?endpoint=webhooks' => 'Register a webhook URL',
            ],
        ]);
        break;
}
