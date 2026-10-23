<?php
require_once __DIR__ . '/lib.php';

session_start();

$isAdminAuth = !empty($_SESSION['admin_auth']);

$transactions = cgTransactions();
$subscriptions = cgAutopays();
$keys = cgApiKeys();

$approved = array_values(array_filter($transactions, fn($t) => ($t['status'] ?? '') === 'approved' && empty($t['testMode'])));
$processedTotal = array_sum(array_map(fn($t) => floatval($t['amount'] ?? 0), $approved));
$activeSubs = array_values(array_filter($subscriptions, fn($s) => ($s['status'] ?? '') === 'active'));

function cgEsc(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>CreditGod Payments</title>
<style>
    * { box-sizing: border-box; }
    body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f3f4f6; color: #111827; }
    .wrap { max-width: 1060px; margin: 0 auto; padding: 28px 24px; }
    h1 { font-size: 22px; margin: 0 0 4px; }
    .sub { color: #6b7280; font-size: 13px; margin-bottom: 22px; }
    .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px; margin-bottom: 18px; }
    .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 18px; }
    .stat { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; }
    .stat .label { font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
    .stat .value { font-size: 24px; font-weight: 600; margin-top: 6px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #f3f4f6; }
    th { color: #6b7280; font-weight: 600; font-size: 12px; text-transform: uppercase; }
    .pill { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 600; }
    .approved { background: #dcfce7; color: #166534; }
    .declined { background: #fee2e2; color: #991b1b; }
    .refunded { background: #e0e7ff; color: #3730a3; }
    .empty { padding: 28px; text-align: center; color: #6b7280; }
    code { background: #f3f4f6; padding: 2px 6px; border-radius: 5px; font-size: 12px; }
    a { color: #2563eb; }
</style>
</head>
<body>
<div class="wrap">
    <h1>CreditGod Payments</h1>
    <div class="sub">Separate ledger — these records never appear in the Autopay terminal.</div>

<?php if (!$isAdminAuth): ?>
    <div class="card">
        <strong>Admin login required.</strong>
        <p>Sign in on the <a href="/">terminal login</a>, then return to this page.</p>
    </div>
    <div class="card">
        <h2 style="font-size:16px; margin-top:0;">API</h2>
        <p>Base URL: <code>https://<?= cgEsc($_SERVER['HTTP_HOST'] ?? 'autopay.builtbyjj.dev') ?>/creditgod/api.php</code></p>
        <p>Send <code>Authorization: Bearer &lt;api key&gt;</code>. See <code>?endpoint=</code> listing for available endpoints.</p>
    </div>
<?php else: ?>
    <div class="stats">
        <div class="stat"><div class="label">Processed</div><div class="value">$<?= number_format($processedTotal, 2) ?></div></div>
        <div class="stat"><div class="label">Transactions</div><div class="value"><?= count($transactions) ?></div></div>
        <div class="stat"><div class="label">Approved</div><div class="value"><?= count($approved) ?></div></div>
        <div class="stat"><div class="label">Active Subscriptions</div><div class="value"><?= count($activeSubs) ?></div></div>
    </div>

    <div class="card" style="padding:0; overflow:hidden;">
        <div style="padding:16px 18px; border-bottom:1px solid #f3f4f6; display:flex; justify-content:space-between; align-items:center;">
            <h2 style="font-size:16px; margin:0;">Transactions</h2>
            <div style="font-size:12px; color:#6b7280;">Newest first</div>
        </div>
        <?php if (!$transactions): ?>
            <div class="empty">No CreditGod transactions yet.</div>
        <?php else: ?>
        <table>
            <tr><th>Date</th><th>Customer</th><th>Description</th><th>Card</th><th>Status</th><th style="text-align:right;">Amount</th></tr>
            <?php foreach (array_slice($transactions, 0, 100) as $t): ?>
            <tr>
                <td><?= cgEsc(date('M j, Y g:ia', strtotime($t['timestamp'] ?? 'now'))) ?></td>
                <td><?= cgEsc((string)($t['clientName'] ?? '')) ?><?= !empty($t['testMode']) ? ' <span class="pill refunded">test</span>' : '' ?></td>
                <td><?= cgEsc((string)($t['description'] ?? '')) ?></td>
                <td><?= cgEsc(trim(($t['cardBrand'] ?? '') . ' ****' . ($t['cardLast4'] ?? ''))) ?></td>
                <td><span class="pill <?= cgEsc((string)($t['status'] ?? '')) ?>"><?= cgEsc((string)($t['status'] ?? '')) ?></span></td>
                <td style="text-align:right;">$<?= number_format(floatval($t['amount'] ?? 0), 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>

    <div class="card" style="padding:0; overflow:hidden;">
        <div style="padding:16px 18px; border-bottom:1px solid #f3f4f6;">
            <h2 style="font-size:16px; margin:0;">Subscriptions</h2>
        </div>
        <?php if (!$subscriptions): ?>
            <div class="empty">No CreditGod subscriptions yet.</div>
        <?php else: ?>
        <table>
            <tr><th>Next Charge</th><th>Customer</th><th>Frequency</th><th>Status</th><th style="text-align:right;">Amount</th></tr>
            <?php foreach ($subscriptions as $s): ?>
            <tr>
                <td><?= cgEsc((string)($s['nextCharge'] ?? '')) ?></td>
                <td><?= cgEsc((string)($s['clientName'] ?? '')) ?></td>
                <td><?= cgEsc((string)($s['frequency'] ?? '')) ?></td>
                <td><?= cgEsc((string)($s['status'] ?? '')) ?></td>
                <td style="text-align:right;">$<?= number_format(floatval($s['amount'] ?? 0), 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2 style="font-size:16px; margin-top:0;">API keys</h2>
        <table>
            <tr><th>Name</th><th>Mode</th><th>Key</th></tr>
            <?php foreach ($keys as $key => $meta): ?>
            <tr>
                <td><?= cgEsc((string)($meta['name'] ?? '')) ?></td>
                <td><?= cgEsc((string)($meta['mode'] ?? '')) ?></td>
                <td><code><?= cgEsc(substr($key, 0, 14) . str_repeat('*', 10) . substr($key, -4)) ?></code></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <p style="font-size:12px; color:#6b7280;">Base URL: <code>https://<?= cgEsc($_SERVER['HTTP_HOST'] ?? 'autopay.builtbyjj.dev') ?>/creditgod/api.php</code></p>
    </div>
<?php endif; ?>
</div>
</body>
</html>
