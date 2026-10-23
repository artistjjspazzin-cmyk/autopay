<?php
require_once dirname(__DIR__) . '/creditgod/lib.php';

const PF_REGISTRY = 'platforms.json';
const PF_USERS = 'platforms_users.json';
const PF_LEGACY_ADMIN = 'platforms_admin.json';
const PF_BASE_URL = 'https://autopay.builtbyjj.dev';
const PF_PERMISSIONS = [
    'view' => 'View transactions & customers',
    'refund' => 'Issue refunds',
    'keys' => 'See & rotate API keys',
    'settings' => 'Change site settings',
];

function pfEsc($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pfSlug(string $name): string {
    return trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)), '-');
}

function pfDefaultSettings(): array {
    return [
        'active' => true,
        'liveEnabled' => false,
        'maxCharge' => 0,
        'allowRecurring' => false,
        'allowRefunds' => true,
        'defaultDescription' => '',
        'statementNote' => '',
    ];
}

function pfRegistry(): array {
    $registry = storageReadDocument(PF_REGISTRY, []);
    if (!$registry) {
        $registry = [
            [
                'id' => 'autopay', 'name' => 'Autopay', 'type' => 'linked',
                'url' => PF_BASE_URL,
                'transactionsDoc' => 'transactions.json', 'subscriptionsDoc' => 'autopay.json',
                'notes' => 'Main payment terminal', 'createdAt' => date('c'),
            ],
            [
                'id' => 'creditgod', 'name' => 'CreditGod', 'type' => 'linked',
                'url' => PF_BASE_URL . '/creditgod',
                'transactionsDoc' => 'creditgod_transactions.json', 'subscriptionsDoc' => 'creditgod_autopay.json',
                'notes' => 'Customer checkout API', 'createdAt' => date('c'),
            ],
        ];
        storageWriteDocument(PF_REGISTRY, $registry);
    }
    return $registry;
}

function pfFindSite(string $id): ?array {
    foreach (pfRegistry() as $p) {
        if (($p['id'] ?? '') === $id) return $p;
    }
    return null;
}

function pfSaveSite(array $site): void {
    storageMutateDocument(PF_REGISTRY, [], function ($all) use ($site) {
        foreach ($all as &$p) {
            if (($p['id'] ?? '') === $site['id']) { $p = $site; return $all; }
        }
        unset($p);
        $all[] = $site;
        return $all;
    });
}

function siteDoc(array $site, string $kind): string {
    return 'site_' . $site['id'] . '_' . $kind . '.json';
}

function siteSettings(array $site): array {
    return array_merge(pfDefaultSettings(), $site['settings'] ?? []);
}

function siteNewKeys(array $site): array {
    $tag = substr(preg_replace('/[^a-z0-9]/', '', $site['id']), 0, 8);
    return [
        'sk_live_' . $tag . '_' . bin2hex(random_bytes(16)) => ['name' => $site['name'] . ' Live', 'active' => true, 'mode' => 'live', 'createdAt' => date('c')],
        'sk_test_' . $tag . '_' . bin2hex(random_bytes(16)) => ['name' => $site['name'] . ' Test', 'active' => true, 'mode' => 'test', 'createdAt' => date('c')],
    ];
}

function siteKeys(array $site): array {
    return storageReadDocument(siteDoc($site, 'api_keys'), []);
}

function siteRotateKeys(array $site): array {
    $keys = siteNewKeys($site);
    storageWriteDocument(siteDoc($site, 'api_keys'), $keys);
    return $keys;
}

function siteCreate(string $name, string $notes): array {
    $id = pfSlug($name);
    if ($id === '' || strlen($id) > 40) throw new InvalidArgumentException('Use a name with letters or numbers (max 40).');
    if (pfFindSite($id)) throw new InvalidArgumentException('A site named "' . $id . '" already exists.');
    $site = [
        'id' => $id,
        'name' => $name,
        'type' => 'hosted',
        'url' => PF_BASE_URL . '/p/' . $id . '/',
        'apiUrl' => PF_BASE_URL . '/p/' . $id . '/api',
        'notes' => $notes,
        'settings' => pfDefaultSettings(),
        'createdAt' => date('c'),
    ];
    $site['transactionsDoc'] = siteDoc($site, 'transactions');
    $site['subscriptionsDoc'] = siteDoc($site, 'autopay');
    pfSaveSite($site);
    siteRotateKeys($site);
    siteAudit($site, 'site_created', 'Site ' . $id, 'Provisioned ' . $site['url']);
    return $site;
}

function siteTransactions(array $site): array {
    return storageReadDocument(siteDoc($site, 'transactions'), []);
}

function siteSaveTransaction(array $site, array $txn): void {
    storageMutateDocument(siteDoc($site, 'transactions'), [], function ($all) use ($txn) {
        array_unshift($all, $txn);
        return $all;
    });
}

function siteAutopays(array $site): array {
    return storageReadDocument(siteDoc($site, 'autopay'), []);
}

function siteSaveAutopays(array $site, array $all): void {
    storageWriteDocument(siteDoc($site, 'autopay'), $all);
}

function siteAudit(array $site, string $action, string $target, string $details, string $actor = ''): void {
    storageMutateDocument(siteDoc($site, 'audit_log'), [], function ($all) use ($action, $target, $details, $actor) {
        array_unshift($all, [
            'timestamp' => date('c'), 'action' => $action, 'target' => $target, 'details' => $details,
            'actor' => $actor, 'ip' => $_SERVER['REMOTE_ADDR'] ?? 'system',
        ]);
        return array_slice($all, 0, 5000);
    });
}

function siteSaveCardForCustomer(array $site, array $customer, array $card): void {
    if (empty($customer['clientName'])) return;
    storageMutateDocument(siteDoc($site, 'saved_cards'), [], function ($all) use ($customer, $card) {
        $encCard = cgEncryptCard($card);
        foreach ($all as &$existing) {
            if (strcasecmp((string)($existing['clientName'] ?? ''), $customer['clientName']) === 0) {
                $existing = array_merge($existing, array_filter($customer, fn($v) => $v !== ''), [
                    'encryptedCard' => $encCard, 'cardLast4' => $card['last4'] ?? '', 'cardBrand' => $card['brand'] ?? '', 'updatedAt' => date('c'),
                ]);
                return $all;
            }
        }
        unset($existing);
        $all[] = array_merge($customer, [
            'encryptedCard' => $encCard, 'cardLast4' => $card['last4'] ?? '', 'cardBrand' => $card['brand'] ?? '',
            'createdAt' => date('c'), 'updatedAt' => date('c'),
        ]);
        return $all;
    });
}

function siteFireWebhooks(array $site, string $event, array $payload): void {
    foreach (storageReadDocument(siteDoc($site, 'webhooks'), []) as $wh) {
        if (($wh['active'] ?? false) !== true) continue;
        $events = $wh['events'] ?? ['*'];
        if (!in_array('*', $events, true) && !in_array($event, $events, true)) continue;
        $ch = curl_init($wh['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Webhook-Secret: ' . ($wh['secret'] ?? '')],
            CURLOPT_POSTFIELDS => json_encode(['event' => $event, 'app' => $site['name'], 'data' => $payload, 'timestamp' => date('c')]),
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

/**
 * Refunds an approved transaction. Test-mode transactions are refunded locally;
 * live transactions go through the gateway first.
 */
function siteRefund(array $site, string $txnId, float $refundAmount, string $actor, ?bool $testKey = null): array {
    $txn = null;
    foreach (siteTransactions($site) as $t) {
        if (($t['id'] ?? '') === $txnId) { $txn = $t; break; }
    }
    if (!$txn) return ['ok' => false, 'error' => 'Transaction not found', 'status' => 404];
    if (($txn['status'] ?? '') !== 'approved') return ['ok' => false, 'error' => 'Can only refund approved transactions', 'status' => 400];
    if ($refundAmount <= 0) $refundAmount = floatval($txn['amount'] ?? 0);

    $txnIsTest = !empty($txn['testMode']);
    if ($testKey !== null && $testKey !== $txnIsTest) {
        return ['ok' => false, 'error' => $txnIsTest ? 'This is a test transaction; refund it with your test key' : 'This is a live transaction; refund it with your live key', 'status' => 400];
    }

    if (!$txnIsTest) {
        if (!cgIsGatewaySaleId($txnId)) return ['ok' => false, 'error' => 'This transaction has no processor sale ID, so it cannot be refunded automatically', 'status' => 409];
        $token = cgGatewayToken();
        if (!$token) return ['ok' => false, 'error' => 'Payment gateway not connected', 'status' => 503];
        $res = cgGatewayRequest('POST', '/v2/shop/' . CG_SHOP_ID . '/sale/' . $txnId . '/refund', ['amount' => (int)round($refundAmount * 100)], $token);
        if ($res['code'] < 200 || $res['code'] >= 300) {
            return ['ok' => false, 'error' => $res['body']['message'] ?? ($res['body']['error'] ?? 'Refund failed'), 'status' => 400];
        }
    }

    storageMutateDocument(siteDoc($site, 'transactions'), [], function ($txns) use ($txnId, $refundAmount, $actor) {
        foreach ($txns as &$t) {
            if (($t['id'] ?? '') === $txnId) {
                $t['status'] = 'refunded';
                $t['refundedAt'] = date('c');
                $t['refundAmount'] = $refundAmount;
                if ($actor !== '') $t['refundedBy'] = $actor;
                break;
            }
        }
        return $txns;
    });
    siteAudit($site, 'refund', 'Transaction ' . $txnId, '$' . number_format($refundAmount, 2), $actor);
    siteFireWebhooks($site, 'charge.refunded', ['transaction_id' => $txnId, 'refund_amount' => $refundAmount]);
    return ['ok' => true, 'amount' => $refundAmount];
}

/* ---------- users & permissions ---------- */

function pfUsers(): array {
    $users = storageReadDocument(PF_USERS, []);
    if (!$users) {
        $legacy = storageReadDocument(PF_LEGACY_ADMIN, []);
        if (!empty($legacy['username']) && !empty($legacy['passwordHash'])) {
            $users = [[
                'username' => $legacy['username'], 'passwordHash' => $legacy['passwordHash'],
                'role' => 'owner', 'sites' => ['*'], 'perms' => array_keys(PF_PERMISSIONS),
                'active' => true, 'createdAt' => $legacy['createdAt'] ?? date('c'),
            ]];
            storageWriteDocument(PF_USERS, $users);
        }
    }
    return $users;
}

function pfFindUser(string $username): ?array {
    foreach (pfUsers() as $u) {
        if (strcasecmp((string)($u['username'] ?? ''), $username) === 0) return $u;
    }
    return null;
}

function pfAuthenticate(string $username, string $password): ?array {
    $u = pfFindUser(trim($username));
    if (!$u || empty($u['active']) || !password_verify($password, (string)($u['passwordHash'] ?? ''))) {
        usleep(400000);
        return null;
    }
    return $u;
}

function pfIsOwner(?array $user): bool {
    return $user !== null && ($user['role'] ?? '') === 'owner';
}

function pfCanSee(?array $user, string $siteId): bool {
    if (!$user || empty($user['active'])) return false;
    if (pfIsOwner($user)) return true;
    $sites = $user['sites'] ?? [];
    return in_array('*', $sites, true) || in_array($siteId, $sites, true);
}

function pfCan(?array $user, string $siteId, string $perm): bool {
    if (!pfCanSee($user, $siteId)) return false;
    if (pfIsOwner($user)) return true;
    return in_array($perm, $user['perms'] ?? [], true);
}

function pfSaveUser(array $user): void {
    storageMutateDocument(PF_USERS, [], function ($all) use ($user) {
        foreach ($all as &$u) {
            if (strcasecmp((string)($u['username'] ?? ''), $user['username']) === 0) { $u = $user; return $all; }
        }
        unset($u);
        $all[] = $user;
        return $all;
    });
}

function pfDeleteUser(string $username): void {
    storageMutateDocument(PF_USERS, [], function ($all) use ($username) {
        return array_values(array_filter($all, fn($u) => strcasecmp((string)($u['username'] ?? ''), $username) !== 0));
    });
}

function pfMaskKey(string $key): string {
    return substr($key, 0, 16) . str_repeat('*', 8) . substr($key, -4);
}

/* ---------- usage stats ---------- */

function pfStats(array $platform): array {
    $txns = !empty($platform['transactionsDoc']) ? storageReadDocument($platform['transactionsDoc'], []) : [];
    $subs = !empty($platform['subscriptionsDoc']) ? storageReadDocument($platform['subscriptionsDoc'], []) : [];
    if (!is_array($txns)) $txns = [];
    if (!is_array($subs)) $subs = [];

    $approved = 0; $volume = 0.0; $declined = 0; $refunded = 0; $last = null; $customers = [];
    $monthStart = strtotime(date('Y-m-01'));
    $monthVolume = 0.0; $monthCount = 0; $testCount = 0;

    foreach ($txns as $t) {
        if (!is_array($t)) continue;
        if (!empty($t['testMode'])) { $testCount++; continue; }
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
        if (!is_array($s) || !empty($s['testMode'])) continue;
        if (strtolower((string)($s['status'] ?? 'active')) === 'active') {
            $activeSubs++;
            $subVolume += floatval($s['amount'] ?? 0);
        }
        $name = trim((string)($s['clientName'] ?? $s['client_name'] ?? ''));
        if ($name !== '') $customers[strtolower($name)] = true;
    }

    return [
        'transactions' => $approved + $declined + $refunded,
        'approved' => $approved, 'declined' => $declined, 'refunded' => $refunded,
        'volume' => $volume, 'monthVolume' => $monthVolume, 'monthCount' => $monthCount,
        'activeSubs' => $activeSubs, 'recurringPerCycle' => $subVolume,
        'customers' => count($customers), 'lastActivity' => $last, 'testCount' => $testCount,
    ];
}

/* ---------- session (shared by /platforms and /p/<site>) ---------- */

function pfSessionStart(): void {
    session_name('PFSID');
    session_set_cookie_params(['path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function pfCurrentUser(): ?array {
    $name = (string)($_SESSION['pf_user'] ?? '');
    if ($name === '') return null;
    $u = pfFindUser($name);
    return ($u && !empty($u['active'])) ? $u : null;
}

function pfLogin(array $user): void {
    session_regenerate_id(true);
    $_SESSION['pf_user'] = $user['username'];
}

function pfLogout(): void {
    $_SESSION = [];
    session_destroy();
}

function pfCsrfField(): string {
    return '<input type="hidden" name="csrf" value="' . pfEsc($_SESSION['csrf'] ?? '') . '">';
}

function pfCsrfOk(): bool {
    return hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''));
}

function pfStyles(): string {
    return <<<CSS
    * { box-sizing: border-box; }
    body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; background:#f3f4f6; color:#111827; }
    .wrap { max-width:1100px; margin:0 auto; padding:26px 22px 60px; }
    .top { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:18px; }
    h1 { font-size:23px; margin:0; }
    h2 { font-size:16px; margin:0 0 14px; }
    .sub { color:#6b7280; font-size:13px; }
    .card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 20px; margin-bottom:18px; }
    .stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(165px,1fr)); gap:14px; margin-bottom:18px; }
    .stat { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:15px 16px; }
    .stat .label { font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; }
    .stat .value { font-size:22px; font-weight:600; margin-top:6px; }
    .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:16px; margin-bottom:18px; }
    .pf { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 20px; }
    .pf h3 { margin:0 0 2px; font-size:17px; display:flex; gap:8px; align-items:center; }
    .kv { display:grid; grid-template-columns:1fr auto; gap:6px 10px; font-size:13px; }
    .kv .k { color:#6b7280; } .kv .v { font-weight:600; text-align:right; }
    .meta { margin-top:12px; padding-top:12px; border-top:1px solid #f3f4f6; font-size:12px; color:#6b7280; word-break:break-all; }
    label { display:block; font-size:12px; color:#374151; margin:0 0 4px; font-weight:600; }
    input[type=text], input[type=password], input[type=number], input:not([type]), select { width:100%; padding:10px 11px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; margin-bottom:12px; font-family:inherit; background:#fff; }
    .check { display:flex; align-items:center; gap:8px; font-weight:500; font-size:13px; margin-bottom:8px; }
    .check input { margin:0; }
    button, .btn { display:inline-block; padding:9px 15px; background:#111827; color:#fff; border:0; border-radius:8px; font-size:13.5px; font-weight:600; cursor:pointer; text-decoration:none; }
    .btn.light, button.light { background:#fff; color:#111827; border:1px solid #d1d5db; }
    button.danger { background:#b91c1c; }
    button.link { background:none; color:#b91c1c; padding:0; font-size:12px; font-weight:500; }
    .flash { background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; border-radius:10px; padding:12px 14px; margin-bottom:16px; font-size:13px; }
    .error { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; border-radius:10px; padding:12px 14px; margin-bottom:16px; font-size:13px; }
    .two { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    @media (max-width:640px) { .two { grid-template-columns:1fr; } }
    table { width:100%; border-collapse:collapse; font-size:13px; }
    th, td { text-align:left; padding:9px 10px; border-bottom:1px solid #f3f4f6; vertical-align:top; }
    th { color:#6b7280; font-weight:600; font-size:11.5px; text-transform:uppercase; }
    .pill { display:inline-block; padding:2px 9px; border-radius:999px; font-size:11px; font-weight:600; background:#f3f4f6; color:#374151; }
    .pill.approved, .pill.on { background:#dcfce7; color:#166534; }
    .pill.declined, .pill.off { background:#fee2e2; color:#991b1b; }
    .pill.refunded, .pill.test { background:#e0e7ff; color:#3730a3; }
    code { background:#f3f4f6; padding:2px 6px; border-radius:5px; font-size:12px; word-break:break-all; }
    pre { background:#0f172a; color:#e2e8f0; padding:14px; border-radius:10px; font-size:12px; overflow-x:auto; }
    a { color:#2563eb; }
    .idle { color:#b45309; }
    .scroll { overflow-x:auto; }
CSS;
}
