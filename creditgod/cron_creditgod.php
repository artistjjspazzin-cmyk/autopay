#!/usr/bin/env php
<?php
require_once __DIR__ . '/lib.php';

/**
 * CreditGod recurring charge processor.
* Cron (every 5 min): php /var/www/html/autopay/creditgod/cron_creditgod.php >> /var/log/creditgod.log 2>&1
 */

$today = date('Y-m-d');
echo '[' . date('c') . "] CreditGod cron started. Today: $today\n";

$subs = cgAutopays();
$token = null;
$changed = false;

foreach ($subs as &$sub) {
    if (($sub['status'] ?? '') !== 'active') continue;
    if (!empty($sub['testMode'])) continue;
    if (($sub['nextCharge'] ?? '9999-12-31') > $today) continue;
    if (empty($sub['encryptedCard'])) {
        echo "  Skipping {$sub['id']}: no stored card\n";
        continue;
    }

    $token = $token ?: cgGatewayToken();
    if (!$token) {
        echo "  Payment gateway not connected; aborting\n";
        break;
    }

    $card = cgDecryptCard($sub['encryptedCard']);
    if (!$card) {
        echo "  Skipping {$sub['id']}: card could not be read\n";
        continue;
    }

    $amount = round(floatval($sub['amount'] ?? 0), 2);
    $tokenResult = cgTokenizeCard($card['number'], $card['exp_month'], $card['exp_year'], $card['cvc'], [
        'name' => $sub['clientName'] ?? '', 'line1' => $sub['clientAddress'] ?? '',
        'city' => $sub['clientCity'] ?? '', 'state' => $sub['clientState'] ?? '', 'zip' => $sub['clientZip'] ?? '',
    ]);

    $approved = false;
    $errorMsg = '';
    if (empty($tokenResult['id'])) {
        $errorMsg = $tokenResult['error']['message'] ?? 'Card validation failed';
    } else {
        $result = cgCharge($tokenResult['id'], $amount, $token);
        if (!empty($result['gatewayUnavailable'])) {
            echo "  Gateway unavailable; leaving {$sub['id']} due for the next run\n";
            continue;
        }
        $approved = $result['code'] >= 200 && $result['code'] < 300;
        $errorMsg = $approved ? '' : ($result['body']['message'] ?? ($result['body']['error'] ?? 'Charge failed'));
    }

    $txn = [
        'id' => $approved ? ($result['body']['id'] ?? uniqid('cg_txn_')) : uniqid('cg_txn_'),
        'amount' => $amount,
        'description' => $sub['description'] ?? 'CreditGod Recurring Payment',
        'clientName' => $sub['clientName'] ?? '', 'clientEmail' => $sub['clientEmail'] ?? '', 'clientPhone' => $sub['clientPhone'] ?? '',
        'status' => $approved ? 'approved' : 'declined',
        'timestamp' => date('c'),
        'cardLast4' => $sub['cardLast4'] ?? '', 'cardBrand' => $sub['cardBrand'] ?? 'Card',
        'subscriptionId' => $sub['id'], 'recurring' => true, 'app' => 'CreditGod',
    ];
    if (!$approved) $txn['error'] = $errorMsg;
    cgSaveTransaction($txn);
    cgAudit('recurring_charge_' . $txn['status'], 'Subscription ' . $sub['id'], '$' . number_format($amount, 2) . ' - ' . ($sub['clientName'] ?? ''));
    cgFireWebhooks('charge.' . $txn['status'], [
        'transaction_id' => $txn['id'], 'subscription_id' => $sub['id'], 'amount' => $amount,
        'status' => $txn['status'], 'client_name' => $txn['clientName'], 'error' => $errorMsg ?: null,
    ]);

    $sub['history'][] = ['date' => $today, 'amount' => $amount, 'status' => $txn['status'], 'transactionId' => $txn['id']];
    if ($approved) {
        $sub['failCount'] = 0;
        $sub['nextCharge'] = cgNextCharge($today, $sub['frequency'] ?? 'monthly');
        echo "  Charged {$sub['id']} \${$amount} -> next {$sub['nextCharge']}\n";
    } else {
        $sub['failCount'] = (int)($sub['failCount'] ?? 0) + 1;
        if ($sub['failCount'] >= 3) {
            $sub['status'] = 'failed';
            echo "  {$sub['id']} failed 3 times, marked failed: $errorMsg\n";
        } else {
            $sub['nextCharge'] = date('Y-m-d', strtotime($today . ' +1 day'));
            echo "  {$sub['id']} declined ($errorMsg), retry {$sub['nextCharge']}\n";
        }
    }
    $changed = true;
}
unset($sub);

if ($changed) cgSaveAutopays($subs);
echo '[' . date('c') . "] CreditGod cron finished\n";
