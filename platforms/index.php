<?php
require_once dirname(__DIR__) . '/sites/lib.php';

pfSessionStart();

$action = (string)($_GET['action'] ?? '');
$loginError = '';
$error = '';
$flash = (string)($_SESSION['flash'] ?? '');
$created = $_SESSION['created'] ?? null;
unset($_SESSION['flash'], $_SESSION['created']);

function pfRedirect(string $flash = '', string $anchor = ''): void {
    if ($flash !== '') $_SESSION['flash'] = $flash;
    header('Location: /platforms/' . $anchor);
    exit;
}

if ($action === 'logout') {
    pfLogout();
    pfRedirect();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    $u = pfAuthenticate((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
    if ($u) { pfLogin($u); pfRedirect(); }
    $loginError = 'Invalid username or password.';
}

$user = pfCurrentUser();
$isOwner = pfIsOwner($user);

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'login') {
    if (!pfCsrfOk()) {
        $error = 'Your session expired. Reload the page and try again.';
    } elseif (!$isOwner) {
        $error = 'Only the owner can make changes here.';
    } elseif ($action === 'create-site') {
        try {
            $site = siteCreate(trim((string)($_POST['name'] ?? '')), trim((string)($_POST['notes'] ?? '')));
            $_SESSION['created'] = ['id' => $site['id'], 'name' => $site['name'], 'url' => $site['url'], 'apiUrl' => $site['apiUrl'], 'keys' => siteKeys($site)];
            pfRedirect('', '#new-site');
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'track') {
        $name = trim((string)($_POST['name'] ?? ''));
        $id = pfSlug($name);
        if ($id === '') {
            $error = 'Platform name is required.';
        } elseif (pfFindSite($id)) {
            $error = 'A platform with that name already exists.';
        } else {
            pfSaveSite([
                'id' => $id, 'name' => $name, 'type' => 'linked',
                'url' => trim((string)($_POST['url'] ?? '')),
                'transactionsDoc' => trim((string)($_POST['transactionsDoc'] ?? '')),
                'subscriptionsDoc' => trim((string)($_POST['subscriptionsDoc'] ?? '')),
                'notes' => trim((string)($_POST['notes'] ?? '')), 'createdAt' => date('c'),
            ]);
            pfRedirect('Now tracking ' . $name . '.');
        }
    } elseif ($action === 'remove') {
        $id = (string)($_POST['id'] ?? '');
        storageMutateDocument(PF_REGISTRY, [], fn($all) => array_values(array_filter($all, fn($p) => ($p['id'] ?? '') !== $id)));
        pfRedirect('Removed ' . $id . ' from the list. Its payment records were kept.');
    } elseif ($action === 'save-user') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $existing = pfFindUser($username);
        if (!preg_match('/^[A-Za-z0-9._@-]{3,40}$/', $username)) {
            $error = 'Username must be 3-40 letters, numbers, or . _ @ -';
        } elseif (!$existing && strlen($password) < 8) {
            $error = 'New users need a password of at least 8 characters.';
        } elseif ($password !== '' && strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($existing && pfIsOwner($existing) && strcasecmp($existing['username'], $user['username']) !== 0) {
            $error = 'Another owner account cannot be edited here.';
        } else {
            $role = ($existing && pfIsOwner($existing)) ? 'owner' : 'member';
            $validSites = array_column(pfRegistry(), 'id');
            $sites = array_values(array_intersect((array)($_POST['sites'] ?? []), $validSites));
            $perms = array_values(array_intersect((array)($_POST['perms'] ?? []), array_keys(PF_PERMISSIONS)));
            if (!in_array('view', $perms, true)) array_unshift($perms, 'view');
            $record = array_merge($existing ?? ['createdAt' => date('c')], [
                'username' => $existing['username'] ?? $username,
                'role' => $role,
                'sites' => $role === 'owner' ? ['*'] : $sites,
                'perms' => $role === 'owner' ? array_keys(PF_PERMISSIONS) : $perms,
                'active' => $role === 'owner' ? true : !empty($_POST['active']),
                'updatedAt' => date('c'),
            ]);
            if ($password !== '') $record['passwordHash'] = password_hash($password, PASSWORD_BCRYPT);
            pfSaveUser($record);
            pfRedirect(($existing ? 'Updated ' : 'Added ') . $record['username'] . '.', '#users');
        }
    } elseif ($action === 'delete-user') {
        $target = pfFindUser((string)($_POST['username'] ?? ''));
        if (!$target || pfIsOwner($target)) {
            $error = 'That user cannot be removed.';
        } else {
            pfDeleteUser($target['username']);
            pfRedirect('Removed user ' . $target['username'] . '.', '#users');
        }
    }
}

$rows = [];
$totals = ['volume' => 0.0, 'transactions' => 0, 'activeSubs' => 0, 'customers' => 0, 'monthVolume' => 0.0];
$allSites = $user ? pfRegistry() : [];
foreach ($allSites as $p) {
    if (!pfCanSee($user, $p['id'] ?? '')) continue;
    $s = pfStats($p);
    $rows[] = ['platform' => $p, 'stats' => $s];
    foreach ($totals as $k => $_) $totals[$k] += $s[$k];
}
usort($rows, fn($a, $b) => $b['stats']['volume'] <=> $a['stats']['volume']);
$users = $isOwner ? pfUsers() : [];
$editUser = $isOwner && isset($_GET['user']) ? pfFindUser((string)$_GET['user']) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Platforms</title>
<style><?= pfStyles() ?></style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <h1>Platforms</h1>
            <div class="sub">Who is using our services, across every site.</div>
        </div>
        <?php if ($user): ?><div class="sub">Signed in as <b><?= pfEsc($user['username']) ?></b> (<?= pfEsc($user['role'] ?? 'member') ?>) · <a href="/platforms/?action=logout">Sign out</a></div><?php endif; ?>
    </div>

<?php if ($flash): ?><div class="flash"><?= pfEsc($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="error"><?= pfEsc($error) ?></div><?php endif; ?>

<?php if (!$user): ?>
    <div class="card" style="max-width:380px;">
        <h2>Sign in</h2>
        <form method="post" action="/platforms/?action=login">
            <input name="username" placeholder="Username" autocomplete="username" required>
            <input name="password" type="password" placeholder="Password" autocomplete="current-password" required>
            <button type="submit" style="width:100%;">Sign In</button>
            <?php if ($loginError): ?><div style="margin-top:12px; color:#b91c1c; font-size:13px;"><?= pfEsc($loginError) ?></div><?php endif; ?>
        </form>
    </div>
<?php else: ?>

    <?php if ($created): ?>
    <div class="card" id="new-site" style="border-color:#86efac;">
        <h2><?= pfEsc($created['name']) ?> is ready</h2>
        <div class="kv" style="grid-template-columns:auto 1fr;">
            <div class="k">Dashboard</div><div><a href="<?= pfEsc($created['url']) ?>"><?= pfEsc($created['url']) ?></a></div>
            <div class="k">API URL</div><div><code><?= pfEsc($created['apiUrl']) ?></code></div>
            <?php foreach ($created['keys'] as $k => $meta): ?>
            <div class="k"><?= pfEsc(ucfirst($meta['mode'])) ?> key</div><div><code><?= pfEsc($k) ?></code></div>
            <?php endforeach; ?>
        </div>
        <p class="sub" style="margin-bottom:0;">Starts in test mode. Turn on live payments, limits, and recurring in that site's Settings. Keys are always available on the site dashboard to users with the "API keys" permission.</p>
    </div>
    <?php endif; ?>

    <div class="stats">
        <div class="stat"><div class="label">Platforms</div><div class="value"><?= count($rows) ?></div></div>
        <div class="stat"><div class="label">Total Processed</div><div class="value">$<?= number_format($totals['volume'], 2) ?></div></div>
        <div class="stat"><div class="label">This Month</div><div class="value">$<?= number_format($totals['monthVolume'], 2) ?></div></div>
        <div class="stat"><div class="label">Transactions</div><div class="value"><?= number_format($totals['transactions']) ?></div></div>
        <div class="stat"><div class="label">Active Subscriptions</div><div class="value"><?= number_format($totals['activeSubs']) ?></div></div>
        <div class="stat"><div class="label">Customers</div><div class="value"><?= number_format($totals['customers']) ?></div></div>
    </div>

    <div class="grid">
    <?php foreach ($rows as $row): $p = $row['platform']; $s = $row['stats']; $hosted = ($p['type'] ?? '') === 'hosted'; $set = $hosted ? siteSettings($p) : []; ?>
        <div class="pf">
            <h3><?= pfEsc($p['name'] ?? $p['id']) ?>
                <?php if ($hosted): ?>
                    <?= $set['active'] ? ($set['liveEnabled'] ? '<span class="pill on">live</span>' : '<span class="pill test">test mode</span>') : '<span class="pill off">suspended</span>' ?>
                <?php endif; ?>
            </h3>
            <div style="font-size:12px; margin-bottom:12px;"><?php if (!empty($p['url'])): ?><a href="<?= pfEsc($p['url']) ?>"><?= pfEsc($p['url']) ?></a><?php endif; ?></div>
            <div class="kv">
                <div class="k">Processed</div><div class="v">$<?= number_format($s['volume'], 2) ?></div>
                <div class="k">This month</div><div class="v">$<?= number_format($s['monthVolume'], 2) ?> (<?= $s['monthCount'] ?>)</div>
                <div class="k">Approved / declined / refunded</div><div class="v"><?= $s['approved'] ?> / <?= $s['declined'] ?> / <?= $s['refunded'] ?></div>
                <div class="k">Active subscriptions</div><div class="v"><?= number_format($s['activeSubs']) ?> · $<?= number_format($s['recurringPerCycle'], 2) ?>/cycle</div>
                <div class="k">Customers</div><div class="v"><?= number_format($s['customers']) ?></div>
                <?php if ($s['testCount']): ?><div class="k">Test transactions</div><div class="v"><?= number_format($s['testCount']) ?></div><?php endif; ?>
                <div class="k">Last activity</div>
                <div class="v<?= $s['lastActivity'] && $s['lastActivity'] < strtotime('-14 days') ? ' idle' : '' ?>"><?= $s['lastActivity'] ? pfEsc(date('M j, Y', $s['lastActivity'])) : '—' ?></div>
            </div>
            <div class="meta">
                <?= pfEsc($p['notes'] ?? '') ?>
                <?php if ($hosted): ?><div style="margin-top:6px;">API: <code><?= pfEsc($p['apiUrl']) ?></code></div><?php endif; ?>
                <div style="margin-top:10px; display:flex; gap:10px; align-items:center;">
                    <?php if ($hosted): ?><a class="btn light" href="<?= pfEsc($p['url']) ?>">Open dashboard &amp; settings</a><?php endif; ?>
                    <?php if ($isOwner): ?>
                    <form method="post" action="/platforms/?action=remove" onsubmit="return confirm('Remove <?= pfEsc($p['name'] ?? $p['id']) ?> from this list? Its payment records are kept.');">
                        <?= pfCsrfField() ?><input type="hidden" name="id" value="<?= pfEsc($p['id'] ?? '') ?>">
                        <button type="submit" class="link">Remove from list</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <?php if ($isOwner): ?>
    <div class="card">
        <h2>Add new site</h2>
        <p class="sub" style="margin-top:0;">Creates a new URL, its own payments API, API keys (test + live), its own records, and a dashboard with settings.</p>
        <form method="post" action="/platforms/?action=create-site">
            <?= pfCsrfField() ?>
            <div class="two">
                <div><label>Site name</label><input name="name" placeholder="My New Site" required maxlength="40"></div>
                <div><label>Notes</label><input name="notes" placeholder="What this site sells"></div>
            </div>
            <p class="sub" style="margin-top:-4px;">URL will be <code><?= pfEsc(PF_BASE_URL) ?>/p/<i>site-name</i>/</code></p>
            <button type="submit">Create site</button>
        </form>
    </div>

    <div class="card" id="users">
        <h2>Users &amp; permissions</h2>
        <div class="scroll"><table>
            <tr><th>User</th><th>Role</th><th>Sites</th><th>Permissions</th><th>Status</th><th></th></tr>
            <?php foreach ($users as $u): $owner = pfIsOwner($u); ?>
            <tr>
                <td><b><?= pfEsc($u['username']) ?></b></td>
                <td><?= pfEsc($u['role'] ?? 'member') ?></td>
                <td><?= $owner || in_array('*', $u['sites'] ?? [], true) ? 'All sites' : pfEsc(implode(', ', $u['sites'] ?? []) ?: 'None') ?></td>
                <td><?= $owner ? 'Everything' : pfEsc(implode(', ', array_map(fn($p) => PF_PERMISSIONS[$p] ?? $p, $u['perms'] ?? []))) ?></td>
                <td><?= !empty($u['active']) ? '<span class="pill on">active</span>' : '<span class="pill off">disabled</span>' ?></td>
                <td style="white-space:nowrap;">
                    <a href="/platforms/?user=<?= urlencode($u['username']) ?>#user-form" style="font-size:12px;">Edit</a>
                    <?php if (!$owner): ?>
                    <form method="post" action="/platforms/?action=delete-user" style="display:inline; margin-left:8px;" onsubmit="return confirm('Remove user <?= pfEsc($u['username']) ?>?');">
                        <?= pfCsrfField() ?><input type="hidden" name="username" value="<?= pfEsc($u['username']) ?>">
                        <button type="submit" class="link">Remove</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table></div>

        <?php $eu = $editUser; $euOwner = $eu && pfIsOwner($eu); ?>
        <h2 id="user-form" style="margin-top:22px;"><?= $eu ? 'Edit ' . pfEsc($eu['username']) : 'Add a user' ?></h2>
        <form method="post" action="/platforms/?action=save-user">
            <?= pfCsrfField() ?>
            <div class="two">
                <div><label>Username</label><input name="username" value="<?= pfEsc($eu['username'] ?? '') ?>" <?= $eu ? 'readonly' : '' ?> required></div>
                <div><label><?= $eu ? 'New password (leave blank to keep)' : 'Password (min 8 characters)' ?></label><input type="password" name="password" autocomplete="new-password" <?= $eu ? '' : 'required' ?>></div>
            </div>
            <?php if (!$euOwner): ?>
            <div class="two">
                <div>
                    <label>Sites they can access</label>
                    <?php foreach ($allSites as $p): ?>
                    <label class="check"><input type="checkbox" name="sites[]" value="<?= pfEsc($p['id']) ?>" <?= $eu && in_array($p['id'], $eu['sites'] ?? [], true) ? 'checked' : '' ?>> <?= pfEsc($p['name'] ?? $p['id']) ?></label>
                    <?php endforeach; ?>
                </div>
                <div>
                    <label>What they can do</label>
                    <?php foreach (PF_PERMISSIONS as $perm => $labelText): ?>
                    <label class="check"><input type="checkbox" name="perms[]" value="<?= pfEsc($perm) ?>" <?= $perm === 'view' || ($eu && in_array($perm, $eu['perms'] ?? [], true)) ? 'checked' : '' ?> <?= $perm === 'view' ? 'disabled' : '' ?>> <?= pfEsc($labelText) ?></label>
                    <?php endforeach; ?>
                    <label class="check" style="margin-top:12px;"><input type="checkbox" name="active" value="1" <?= !$eu || !empty($eu['active']) ? 'checked' : '' ?>> Account active</label>
                </div>
            </div>
            <?php else: ?>
            <p class="sub">Owner account — always has access to every site and permission.</p>
            <?php endif; ?>
            <button type="submit"><?= $eu ? 'Save changes' : 'Add user' ?></button>
            <?php if ($eu): ?> <a href="/platforms/#users" style="font-size:13px; margin-left:8px;">Cancel</a><?php endif; ?>
        </form>
        <p class="sub" style="margin-bottom:0;">Users sign in with the same username and password here and on each site dashboard they have access to. Permissions only apply to sites built here; for Autopay and CreditGod they only control who sees the usage numbers.</p>
    </div>

    <div class="card">
        <h2>Track an existing platform</h2>
        <p class="sub" style="margin-top:0;">For a site that already keeps its own records on this server (like Autopay and CreditGod).</p>
        <form method="post" action="/platforms/?action=track">
            <?= pfCsrfField() ?>
            <div class="two">
                <div><label>Name</label><input name="name" required></div>
                <div><label>URL</label><input name="url" placeholder="https://..."></div>
            </div>
            <div class="two">
                <div><label>Transactions record</label><input name="transactionsDoc" placeholder="newsite_transactions.json"></div>
                <div><label>Subscriptions record (optional)</label><input name="subscriptionsDoc" placeholder="newsite_autopay.json"></div>
            </div>
            <label>Notes</label><input name="notes">
            <button type="submit" class="light">Track platform</button>
        </form>
    </div>
    <?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
