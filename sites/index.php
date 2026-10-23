<?php
require_once __DIR__ . '/lib.php';

pfSessionStart();

$siteId = (string)($_GET['site'] ?? '');
$site = preg_match('/^[a-z0-9-]{1,40}$/', $siteId) ? pfFindSite($siteId) : null;
if (!$site || ($site['type'] ?? '') !== 'hosted') {
    http_response_code(404);
    echo 'Site not found';
    exit;
}
$self = '/p/' . $site['id'] . '/';
$action = (string)($_GET['action'] ?? '');
$loginError = '';
$flash = (string)($_SESSION['flash'] ?? '');
$error = '';
unset($_SESSION['flash']);

function siteRedirect(string $to, string $flash = ''): void {
    if ($flash !== '') $_SESSION['flash'] = $flash;
    header('Location: ' . $to);
    exit;
}

if ($action === 'logout') {
    pfLogout();
    siteRedirect($self);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    $u = pfAuthenticate((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
    if (!$u) {
        $loginError = 'Invalid username or password.';
    } elseif (!pfCanSee($u, $site['id'])) {
        $loginError = 'Your account does not have access to this site.';
    } else {
        pfLogin($u);
        siteAudit($site, 'login', 'Dashboard', 'Signed in', $u['username']);
        siteRedirect($self);
    }
}

$user = pfCurrentUser();
$canView = pfCanSee($user, $site['id']);
$who = $user['username'] ?? '';

if ($canView && $_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'login') {
    if (!pfCsrfOk()) {
        $error = 'Your session expired. Reload the page and try again.';
    } elseif ($action === 'refund') {
        if (!pfCan($user, $site['id'], 'refund')) {
            $error = 'You do not have permission to issue refunds.';
        } else {
            $txnId = (string)($_POST['id'] ?? '');
            $r = siteRefund($site, $txnId, 0, $who);
            if ($r['ok']) siteRedirect($self, 'Refunded $' . number_format($r['amount'], 2) . ' on ' . $txnId);
            $error = $r['error'];
        }
    } elseif ($action === 'rotate-keys') {
        if (!pfCan($user, $site['id'], 'keys')) {
            $error = 'You do not have permission to manage API keys.';
        } else {
            siteRotateKeys($site);
            siteAudit($site, 'keys_rotated', 'API keys', 'Old keys revoked', $who);
            siteRedirect($self . '#keys', 'New API keys created. The old keys stop working immediately.');
        }
    } elseif ($action === 'settings') {
        if (!pfCan($user, $site['id'], 'settings')) {
            $error = 'You do not have permission to change settings.';
        } else {
            $site['name'] = trim((string)($_POST['name'] ?? '')) ?: $site['name'];
            $site['notes'] = trim((string)($_POST['notes'] ?? ''));
            $site['settings'] = array_merge(siteSettings($site), [
                'active' => !empty($_POST['active']),
                'liveEnabled' => !empty($_POST['liveEnabled']),
                'allowRecurring' => !empty($_POST['allowRecurring']),
                'allowRefunds' => !empty($_POST['allowRefunds']),
                'maxCharge' => max(0, round(floatval($_POST['maxCharge'] ?? 0), 2)),
                'defaultDescription' => trim((string)($_POST['defaultDescription'] ?? '')),
                'statementNote' => trim((string)($_POST['statementNote'] ?? '')),
            ]);
            pfSaveSite($site);
            siteAudit($site, 'settings_changed', 'Settings', json_encode($site['settings']), $who);
            siteRedirect($self . '#settings', 'Settings saved.');
        }
    }
}

$settings = siteSettings($site);
$transactions = $canView ? siteTransactions($site) : [];
$subscriptions = $canView ? siteAutopays($site) : [];
$stats = $canView ? pfStats($site) : [];
$keys = $canView ? siteKeys($site) : [];
$audit = $canView ? array_slice(storageReadDocument(siteDoc($site, 'audit_log'), []), 0, 25) : [];
$canRefund = pfCan($user, $site['id'], 'refund');
$canKeys = pfCan($user, $site['id'], 'keys');
$canSettings = pfCan($user, $site['id'], 'settings');
$testKey = '';
foreach ($keys as $k => $meta) if (($meta['mode'] ?? '') === 'test' && !empty($meta['active'])) $testKey = $k;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= pfEsc($site['name']) ?> Payments</title>
<style><?= pfStyles() ?></style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <h1><?= pfEsc($site['name']) ?> Payments</h1>
            <div class="sub">
                <?= $settings['active'] ? '<span class="pill on">active</span>' : '<span class="pill off">suspended</span>' ?>
                <?= $settings['liveEnabled'] ? '<span class="pill on">live payments on</span>' : '<span class="pill test">test mode only</span>' ?>
                &nbsp;Own ledger — never mixed with other sites.
            </div>
        </div>
        <?php if ($user): ?>
        <div class="sub">Signed in as <b><?= pfEsc($who) ?></b> · <a href="/platforms/">All platforms</a> · <a href="<?= pfEsc($self) ?>?action=logout">Sign out</a></div>
        <?php endif; ?>
    </div>

<?php if ($flash): ?><div class="flash"><?= pfEsc($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="error"><?= pfEsc($error) ?></div><?php endif; ?>

<?php if (!$canView): ?>
    <div class="card" style="max-width:380px;">
        <h2>Sign in</h2>
        <?php if ($user): ?><p class="sub">Signed in as <?= pfEsc($who) ?>, but that account has no access to this site. <a href="<?= pfEsc($self) ?>?action=logout">Switch account</a></p><?php endif; ?>
        <form method="post" action="<?= pfEsc($self) ?>?action=login">
            <input name="username" placeholder="Username" autocomplete="username" required>
            <input name="password" type="password" placeholder="Password" autocomplete="current-password" required>
            <button type="submit" style="width:100%;">Sign In</button>
            <?php if ($loginError): ?><div style="margin-top:12px; color:#b91c1c; font-size:13px;"><?= pfEsc($loginError) ?></div><?php endif; ?>
        </form>
    </div>
<?php else: ?>

    <div class="stats">
        <div class="stat"><div class="label">Processed</div><div class="value">$<?= number_format($stats['volume'], 2) ?></div></div>
        <div class="stat"><div class="label">This Month</div><div class="value">$<?= number_format($stats['monthVolume'], 2) ?></div></div>
        <div class="stat"><div class="label">Transactions</div><div class="value"><?= number_format($stats['transactions']) ?></div></div>
        <div class="stat"><div class="label">Customers</div><div class="value"><?= number_format($stats['customers']) ?></div></div>
        <div class="stat"><div class="label">Active Subscriptions</div><div class="value"><?= number_format($stats['activeSubs']) ?></div></div>
        <div class="stat"><div class="label">Test Transactions</div><div class="value"><?= number_format($stats['testCount']) ?></div></div>
    </div>

    <div class="card">
        <h2>Connect your website</h2>
        <p style="font-size:13px; margin-top:0;">API URL: <code><?= pfEsc($site['apiUrl']) ?></code></p>
<pre>curl -X POST "<?= pfEsc($site['apiUrl']) ?>?endpoint=charge" \
  -H "Authorization: Bearer <?= pfEsc($canKeys && $testKey ? $testKey : 'YOUR_TEST_KEY') ?>" \
  -H "Content-Type: application/json" \
  -d '{"amount":25.00,"client_name":"Jane Doe","client_email":"jane@example.com",
       "card_number":"4242424242424242","exp_month":"12","exp_year":"2030","cvc":"123"}'</pre>
        <p class="sub" style="margin-bottom:0;">Test key: card 4242… approves, any card ending 0002 declines, nothing reaches the gateway. Live key works only after "Live payments" is turned on in settings.</p>
    </div>

    <div class="card" style="padding:0;">
        <div style="padding:16px 20px 0;"><h2>Transactions</h2></div>
        <?php if (!$transactions): ?>
            <p class="sub" style="padding:0 20px 18px;">No transactions yet.</p>
        <?php else: ?>
        <div class="scroll"><table>
            <tr><th>Date</th><th>Customer</th><th>Description</th><th>Card</th><th>Status</th><th style="text-align:right;">Amount</th><?php if ($canRefund): ?><th></th><?php endif; ?></tr>
            <?php foreach (array_slice($transactions, 0, 200) as $t): $st = (string)($t['status'] ?? ''); ?>
            <tr>
                <td><?= pfEsc(date('M j, Y g:ia', strtotime($t['timestamp'] ?? 'now'))) ?></td>
                <td><?= pfEsc($t['clientName'] ?? '') ?><?= !empty($t['testMode']) ? ' <span class="pill test">test</span>' : '' ?><br><span class="sub"><?= pfEsc($t['clientEmail'] ?? '') ?></span></td>
                <td><?= pfEsc($t['description'] ?? '') ?></td>
                <td><?= pfEsc(trim(($t['cardBrand'] ?? '') . ' ****' . ($t['cardLast4'] ?? ''))) ?></td>
                <td><span class="pill <?= pfEsc($st) ?>"><?= pfEsc($st) ?></span></td>
                <td style="text-align:right;">$<?= number_format(floatval($t['amount'] ?? 0), 2) ?></td>
                <?php if ($canRefund): ?><td>
                    <?php if ($st === 'approved'): ?>
                    <form method="post" action="<?= pfEsc($self) ?>?action=refund" onsubmit="return confirm('Refund $<?= number_format(floatval($t['amount'] ?? 0), 2) ?> to <?= pfEsc($t['clientName'] ?? '') ?>?');">
                        <?= pfCsrfField() ?><input type="hidden" name="id" value="<?= pfEsc($t['id'] ?? '') ?>">
                        <button type="submit" class="link">Refund</button>
                    </form>
                    <?php endif; ?>
                </td><?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
    </div>

    <div class="card" style="padding:0;">
        <div style="padding:16px 20px 0;"><h2>Subscriptions</h2></div>
        <?php if (!$subscriptions): ?>
            <p class="sub" style="padding:0 20px 18px;">No subscriptions<?= $settings['allowRecurring'] ? ' yet' : ' — recurring payments are turned off for this site' ?>.</p>
        <?php else: ?>
        <div class="scroll"><table>
            <tr><th>Next charge</th><th>Customer</th><th>Frequency</th><th>Status</th><th style="text-align:right;">Amount</th></tr>
            <?php foreach ($subscriptions as $s): ?>
            <tr>
                <td><?= pfEsc($s['nextCharge'] ?? '') ?></td>
                <td><?= pfEsc($s['clientName'] ?? '') ?><?= !empty($s['testMode']) ? ' <span class="pill test">test</span>' : '' ?></td>
                <td><?= pfEsc($s['frequency'] ?? '') ?></td>
                <td><?= pfEsc($s['status'] ?? '') ?></td>
                <td style="text-align:right;">$<?= number_format(floatval($s['amount'] ?? 0), 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
    </div>

    <div class="card" id="keys">
        <h2>API keys</h2>
        <div class="scroll"><table>
            <tr><th>Name</th><th>Mode</th><th>Key</th></tr>
            <?php foreach ($keys as $k => $meta): ?>
            <tr>
                <td><?= pfEsc($meta['name'] ?? '') ?></td>
                <td><span class="pill <?= ($meta['mode'] ?? '') === 'test' ? 'test' : 'on' ?>"><?= pfEsc($meta['mode'] ?? '') ?></span></td>
                <td><code><?= pfEsc($canKeys ? $k : pfMaskKey($k)) ?></code></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
        <?php if ($canKeys): ?>
        <form method="post" action="<?= pfEsc($self) ?>?action=rotate-keys" style="margin-top:12px;" onsubmit="return confirm('Create new keys? The current keys stop working right away.');">
            <?= pfCsrfField() ?><button type="submit" class="light">Rotate keys</button>
        </form>
        <?php else: ?>
        <p class="sub">You need the "API keys" permission to see full keys.</p>
        <?php endif; ?>
    </div>

    <?php if ($canSettings): ?>
    <div class="card" id="settings">
        <h2>Settings</h2>
        <form method="post" action="<?= pfEsc($self) ?>?action=settings">
            <?= pfCsrfField() ?>
            <div class="two">
                <div><label>Site name</label><input name="name" value="<?= pfEsc($site['name']) ?>"></div>
                <div><label>Notes</label><input name="notes" value="<?= pfEsc($site['notes'] ?? '') ?>"></div>
            </div>
            <div class="two">
                <div><label>Max charge per payment ($, 0 = no limit)</label><input type="number" step="0.01" min="0" name="maxCharge" value="<?= pfEsc($settings['maxCharge']) ?>"></div>
                <div><label>Default description</label><input name="defaultDescription" value="<?= pfEsc($settings['defaultDescription']) ?>" placeholder="<?= pfEsc($site['name']) ?> Payment"></div>
            </div>
            <label>Receipt / statement note</label><input name="statementNote" value="<?= pfEsc($settings['statementNote']) ?>">
            <label class="check"><input type="checkbox" name="active" value="1" <?= $settings['active'] ? 'checked' : '' ?>> Site active (off = API refuses all requests)</label>
            <label class="check"><input type="checkbox" name="liveEnabled" value="1" <?= $settings['liveEnabled'] ? 'checked' : '' ?>> Live payments (real cards charged with the live key)</label>
            <label class="check"><input type="checkbox" name="allowRecurring" value="1" <?= $settings['allowRecurring'] ? 'checked' : '' ?>> Allow recurring subscriptions</label>
            <label class="check"><input type="checkbox" name="allowRefunds" value="1" <?= $settings['allowRefunds'] ? 'checked' : '' ?>> Allow refunds through the API</label>
            <button type="submit" style="margin-top:8px;">Save settings</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>Activity</h2>
        <?php if (!$audit): ?><p class="sub">No activity yet.</p><?php else: ?>
        <div class="scroll"><table>
            <tr><th>When</th><th>Who</th><th>Action</th><th>Details</th></tr>
            <?php foreach ($audit as $a): ?>
            <tr>
                <td><?= pfEsc(date('M j, g:ia', strtotime($a['timestamp'] ?? 'now'))) ?></td>
                <td><?= pfEsc(($a['actor'] ?? '') ?: 'api') ?></td>
                <td><?= pfEsc($a['action'] ?? '') ?></td>
                <td><?= pfEsc(($a['target'] ?? '') . ' ' . (($a['action'] ?? '') === 'settings_changed' ? '' : ($a['details'] ?? ''))) ?></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>
</body>
</html>
