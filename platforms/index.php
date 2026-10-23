<?php
require_once dirname(__DIR__) . '/storage.php';

const PF_ADMIN = 'platforms_admin.json';
const PF_REGISTRY = 'platforms.json';

session_name('PFSESS');
session_set_cookie_params(['path' => '/platforms']);
session_start();

function pfEsc($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pfRegistry(): array {
    $registry = storageReadDocument(PF_REGISTRY, []);
    if (!$registry) {
        $registry = [
            [
                'id' => 'autopay',
                'name' => 'Autopay',
                'url' => 'https://autopay.builtbyjj.dev',
                'transactionsDoc' => 'transactions.json',
                'subscriptionsDoc' => 'autopay.json',
                'notes' => 'Main payment terminal',
                'createdAt' => date('c'),
            ],
            [
                'id' => 'creditgod',
                'name' => 'CreditGod',
                'url' => 'https://autopay.builtbyjj.dev/creditgod',
                'transactionsDoc' => 'creditgod_transactions.json',
                'subscriptionsDoc' => 'creditgod_autopay.json',
                'notes' => 'Customer checkout API',
                'createdAt' => date('c'),
            ],
        ];
        storageWriteDocument(PF_REGISTRY, $registry);
    }
    return $registry;
}

function pfStats(array $platform): array {
    $txns = $platform['transactionsDoc'] ? storageReadDocument($platform['transactionsDoc'], []) : [];
    $subs = $platform['subscriptionsDoc'] ? storageReadDocument($platform['subscriptionsDoc'], []) : [];
    if (!is_array($txns)) $txns = [];
    if (!is_array($subs)) $subs = [];

    $approved = 0; $volume = 0.0; $declined = 0; $refunded = 0; $last = null; $customers = [];
    $monthStart = strtotime(date('Y-m-01'));
    $monthVolume = 0.0; $monthCount = 0;

    foreach ($txns as $t) {
        if (!is_array($t)) continue;
        $status = strtolower((string)($t['status'] ?? ''));
        $amount = floatval($t['amount'] ?? 0);
        $ts = strtotime((string)($t['timestamp'] ?? $t['date'] ?? '')) ?: null;
        if ($status === 'approved') { $approved++; $volume += $amount; }
        elseif ($status === 'declined') { $declined++; }
        elseif ($status === 'refunded') { $refunded++; }
        if ($ts && ($last === null || $ts > $last)) $last = $ts;
        if ($ts && $ts >= $monthStart && $status === 'approved') { $monthVolume += $amount; $monthCount++; }
        $name = trim((string)($t['clientName'] ?? $t['client_name'] ?? ''));
        if ($name !== '') $customers[strtolower($name)] = true;
    }

    $activeSubs = 0; $subVolume = 0.0;
    foreach ($subs as $s) {
        if (!is_array($s)) continue;
        if (strtolower((string)($s['status'] ?? 'active')) === 'active') {
            $activeSubs++;
            $subVolume += floatval($s['amount'] ?? 0);
        }
        $name = trim((string)($s['clientName'] ?? $s['client_name'] ?? ''));
        if ($name !== '') $customers[strtolower($name)] = true;
    }

    return [
        'transactions' => count($txns),
        'approved' => $approved,
        'declined' => $declined,
        'refunded' => $refunded,
        'volume' => $volume,
        'monthVolume' => $monthVolume,
        'monthCount' => $monthCount,
        'activeSubs' => $activeSubs,
        'recurringPerCycle' => $subVolume,
        'customers' => count($customers),
        'lastActivity' => $last,
    ];
}

$loginError = '';
$flash = '';

if (($_GET['action'] ?? '') === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: /platforms/');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'login') {
    $admin = storageReadDocument(PF_ADMIN, []);
    $user = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    if (!$admin || empty($admin['username']) || empty($admin['passwordHash'])) {
        $loginError = 'Admin account is not provisioned yet.';
    } elseif (hash_equals(strtolower($admin['username']), strtolower($user)) && password_verify($pass, $admin['passwordHash'])) {
        session_regenerate_id(true);
        $_SESSION['pf_auth'] = true;
        header('Location: /platforms/');
        exit;
    } else {
        $loginError = 'Invalid username or password.';
        usleep(400000);
    }
}

$isAuth = !empty($_SESSION['pf_auth']);

if ($isAuth && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'add') {
    $name = trim((string)($_POST['name'] ?? ''));
    $id = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
    if ($name === '') {
        $flash = 'Platform name is required.';
    } else {
        $new = [
            'id' => $id,
            'name' => $name,
            'url' => trim((string)($_POST['url'] ?? '')),
            'transactionsDoc' => trim((string)($_POST['transactionsDoc'] ?? '')),
            'subscriptionsDoc' => trim((string)($_POST['subscriptionsDoc'] ?? '')),
            'notes' => trim((string)($_POST['notes'] ?? '')),
            'createdAt' => date('c'),
        ];
        storageMutateDocument(PF_REGISTRY, [], function ($all) use ($new) {
            $all = array_values(array_filter($all, fn($p) => ($p['id'] ?? '') !== $new['id']));
            $all[] = $new;
            return $all;
        });
        header('Location: /platforms/?added=' . urlencode($new['name']));
        exit;
    }
}

if ($isAuth && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'remove') {
    $id = (string)($_POST['id'] ?? '');
    storageMutateDocument(PF_REGISTRY, [], function ($all) use ($id) {
        return array_values(array_filter($all, fn($p) => ($p['id'] ?? '') !== $id));
    });
    header('Location: /platforms/?removed=' . urlencode($id));
    exit;
}

if (isset($_GET['added'])) $flash = 'Added platform: ' . $_GET['added'];
if (isset($_GET['removed'])) $flash = 'Removed platform: ' . $_GET['removed'];

$platforms = $isAuth ? pfRegistry() : [];
$rows = [];
$totals = ['volume' => 0.0, 'transactions' => 0, 'activeSubs' => 0, 'customers' => 0, 'monthVolume' => 0.0];
foreach ($platforms as $p) {
    $stats = pfStats($p);
    $rows[] = ['platform' => $p, 'stats' => $stats];
    $totals['volume'] += $stats['volume'];
    $totals['transactions'] += $stats['transactions'];
    $totals['activeSubs'] += $stats['activeSubs'];
    $totals['customers'] += $stats['customers'];
    $totals['monthVolume'] += $stats['monthVolume'];
}
usort($rows, fn($a, $b) => $b['stats']['volume'] <=> $a['stats']['volume']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Platforms</title>
<style>
    * { box-sizing: border-box; }
    body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; background:#f3f4f6; color:#111827; }
    .wrap { max-width:1100px; margin:0 auto; padding:28px 22px 60px; }
    h1 { font-size:23px; margin:0 0 4px; }
    .sub { color:#6b7280; font-size:13px; margin-bottom:22px; }
    .card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 20px; margin-bottom:18px; }
    .stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:14px; margin-bottom:18px; }
    .stat { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:15px 16px; }
    .stat .label { font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; }
    .stat .value { font-size:23px; font-weight:600; margin-top:6px; }
    .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(330px,1fr)); gap:16px; }
    .pf { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 20px; }
    .pf h3 { margin:0 0 2px; font-size:17px; }
    .pf .url { font-size:12px; margin-bottom:12px; }
    .kv { display:grid; grid-template-columns:1fr auto; gap:6px 10px; font-size:13px; }
    .kv .k { color:#6b7280; }
    .kv .v { font-weight:600; text-align:right; }
    .meta { margin-top:12px; padding-top:12px; border-top:1px solid #f3f4f6; font-size:11.5px; color:#6b7280; word-break:break-all; }
    label { display:block; font-size:12px; color:#374151; margin:0 0 4px; font-weight:600; }
    input, textarea { width:100%; padding:10px 11px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; margin-bottom:12px; font-family:inherit; }
    button { padding:10px 16px; background:#111827; color:#fff; border:0; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; }
    button.link { background:none; color:#b91c1c; padding:0; font-size:12px; font-weight:500; }
    .flash { background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; border-radius:10px; padding:11px 14px; margin-bottom:16px; font-size:13px; }
    .two { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    a { color:#2563eb; }
    .idle { color:#b45309; }
</style>
</head>
<body>
<div class="wrap">
    <h1>Platforms</h1>
    <div class="sub">Who is using our services, across every site.</div>

<?php if ($flash): ?><div class="flash"><?= pfEsc($flash) ?></div><?php endif; ?>

<?php if (!$isAuth): ?>
    <div class="card" style="max-width:380px;">
        <h2 style="font-size:16px; margin:0 0 14px;">Sign in</h2>
        <form method="post" action="/platforms/?action=login">
            <input name="username" placeholder="Username" autocomplete="username" required>
            <input name="password" type="password" placeholder="Password" autocomplete="current-password" required>
            <button type="submit" style="width:100%;">Sign In</button>
            <?php if ($loginError): ?><div style="margin-top:12px; color:#b91c1c; font-size:13px;"><?= pfEsc($loginError) ?></div><?php endif; ?>
        </form>
    </div>
<?php else: ?>
    <div style="margin-bottom:14px;"><a href="/platforms/?action=logout" style="font-size:13px;">Sign out</a></div>

    <div class="stats">
        <div class="stat"><div class="label">Platforms</div><div class="value"><?= count($rows) ?></div></div>
        <div class="stat"><div class="label">Total Processed</div><div class="value">$<?= number_format($totals['volume'], 2) ?></div></div>
        <div class="stat"><div class="label">This Month</div><div class="value">$<?= number_format($totals['monthVolume'], 2) ?></div></div>
        <div class="stat"><div class="label">Transactions</div><div class="value"><?= number_format($totals['transactions']) ?></div></div>
        <div class="stat"><div class="label">Active Subscriptions</div><div class="value"><?= number_format($totals['activeSubs']) ?></div></div>
        <div class="stat"><div class="label">Customers</div><div class="value"><?= number_format($totals['customers']) ?></div></div>
    </div>

    <div class="grid">
    <?php foreach ($rows as $row): $p = $row['platform']; $s = $row['stats']; ?>
        <div class="pf">
            <h3><?= pfEsc($p['name'] ?? $p['id']) ?></h3>
            <div class="url"><?php if (!empty($p['url'])): ?><a href="<?= pfEsc($p['url']) ?>" target="_blank" rel="noopener"><?= pfEsc($p['url']) ?></a><?php else: ?>&nbsp;<?php endif; ?></div>
            <div class="kv">
                <div class="k">Processed</div><div class="v">$<?= number_format($s['volume'], 2) ?></div>
                <div class="k">This month</div><div class="v">$<?= number_format($s['monthVolume'], 2) ?> (<?= $s['monthCount'] ?>)</div>
                <div class="k">Transactions</div><div class="v"><?= number_format($s['transactions']) ?></div>
                <div class="k">Approved / declined / refunded</div><div class="v"><?= $s['approved'] ?> / <?= $s['declined'] ?> / <?= $s['refunded'] ?></div>
                <div class="k">Active subscriptions</div><div class="v"><?= number_format($s['activeSubs']) ?></div>
                <div class="k">Recurring per cycle</div><div class="v">$<?= number_format($s['recurringPerCycle'], 2) ?></div>
                <div class="k">Customers</div><div class="v"><?= number_format($s['customers']) ?></div>
                <div class="k">Last activity</div>
                <div class="v<?= $s['lastActivity'] && $s['lastActivity'] < strtotime('-14 days') ? ' idle' : '' ?>">
                    <?= $s['lastActivity'] ? pfEsc(date('M j, Y', $s['lastActivity'])) : '—' ?>
                </div>
            </div>
            <div class="meta">
                <?= pfEsc($p['notes'] ?? '') ?><br>
                records: <?= pfEsc($p['transactionsDoc'] ?? '—') ?><?= !empty($p['subscriptionsDoc']) ? ', ' . pfEsc($p['subscriptionsDoc']) : '' ?>
                <form method="post" action="/platforms/?action=remove" style="margin-top:8px;" onsubmit="return confirm('Remove <?= pfEsc($p['name'] ?? $p['id']) ?> from this list? Its payment records are not deleted.');">
                    <input type="hidden" name="id" value="<?= pfEsc($p['id'] ?? '') ?>">
                    <button type="submit" class="link">Remove from list</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <div class="card" style="margin-top:22px;">
        <h2 style="font-size:16px; margin:0 0 14px;">Add a platform</h2>
        <form method="post" action="/platforms/?action=add">
            <div class="two">
                <div><label>Name</label><input name="name" placeholder="NewSite" required></div>
                <div><label>URL</label><input name="url" placeholder="https://newsite.example"></div>
            </div>
            <div class="two">
                <div><label>Transactions record</label><input name="transactionsDoc" placeholder="newsite_transactions.json"></div>
                <div><label>Subscriptions record (optional)</label><input name="subscriptionsDoc" placeholder="newsite_autopay.json"></div>
            </div>
            <label>Notes</label><input name="notes" placeholder="What this platform does">
            <button type="submit">Add platform</button>
        </form>
        <p style="font-size:12px; color:#6b7280; margin-bottom:0;">
            Usage is read live from the record names you enter — each platform keeps its own documents, so nothing mixes between sites.
        </p>
    </div>
<?php endif; ?>
</div>
</body>
</html>
