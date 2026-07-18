<?php
require_once __DIR__ . '/storage.php';

/**
 * Authorize.net Terminal
 * Login-gated, transaction logging, dashboard analytics, autopay/recurring billing
 */
session_set_cookie_params(["lifetime" => 0, "path" => "/", "secure" => true, "httponly" => true, "samesite" => "Lax"]);
session_start();
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// ─── Configuration ───────────────────────────────────────────
$SQUIRE_API_BASE = getenv('SQUIRE_API_BASE') ?: 'https://api.getsquire.com';
$SHOP_ID = getenv('SQUIRE_SHOP_ID') ?: '';
$STRIPE_PK = getenv('STRIPE_PUBLISHABLE_KEY') ?: '';
$US_PROXY = getenv('US_PROXY_URL') ?: '';
$STRIPE_SK = getenv('STRIPE_SECRET_KEY') ?: storageReadText('stripe_sk.txt');

$ADMIN_USER = getenv('ADMIN_USER') ?: 'admin';
$ADMIN_PASS = getenv('ADMIN_PASSWORD') ?: '';

// ─── Auth Gate ──────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'admin_login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    if ($ADMIN_PASS !== '' && hash_equals($ADMIN_USER, (string)($input['username'] ?? '')) && hash_equals($ADMIN_PASS, (string)($input['password'] ?? ''))) {
        $_SESSION['admin_auth'] = true;
        recordSession();
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'admin_logout') {
    session_destroy();
    header('Location: /');
    exit;
}

// ─── Public Payment Link Page (no auth required) ─────────────
if (isset($_GET['pay'])) {
    $linkId = $_GET['pay'];
    $links = getPaymentLinks();
    $link = null;
    foreach ($links as $l) { if (($l['id'] ?? '') === $linkId) { $link = $l; break; } }
    if (!$link || ($link['status'] ?? '') !== 'active') {
        echo '<!DOCTYPE html><html><head><title>Payment Link</title><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f0f2f5;}.card{background:#fff;border-radius:16px;padding:48px;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.08);max-width:420px;}h2{color:#e74c3c;margin-bottom:12px;}p{color:#666;}</style></head><body><div class="card"><h2>Link Expired</h2><p>This payment link is no longer active or has already been used.</p></div></body></html>';
        exit;
    }

    // Handle payment submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'pay_link') {
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true);
        $token = getToken();
        if (!$token) { echo json_encode(['success' => false, 'error' => 'Gateway not available. Please try again later.']); exit; }

        // Use selected product amount if provided, otherwise link default
        $selectedIdx = intval($input['selectedProduct'] ?? 0);
        $products = $link['products'] ?? [['name' => $link['description'] ?? 'Payment', 'price' => $link['amount']]];
        $selectedProduct = $products[$selectedIdx] ?? $products[0];
        $amount = floatval($input['selectedAmount'] ?? $selectedProduct['price'] ?? $link['amount']);
        $productName = $selectedProduct['name'] ?? ($link['description'] ?? 'Payment');
        $clientName = trim($input['clientName'] ?? ($link['clientName'] ?? ''));
        $clientEmail = trim($input['clientEmail'] ?? ($link['clientEmail'] ?? ''));
        $clientPhone = trim($input['clientPhone'] ?? '');
        $clientAddress = trim($input['clientAddress'] ?? '');
        $clientCity = trim($input['clientCity'] ?? '');
        $clientState = trim($input['clientState'] ?? '');
        $clientZip = trim($input['clientZip'] ?? '');
        $cardNumber = preg_replace('/\s+/', '', $input['cardNumber'] ?? '');
        $expMonth = $input['expMonth'] ?? '';
        $expYear = $input['expYear'] ?? '';
        $cvc = $input['cvc'] ?? '';

        if (!$cardNumber || !$expMonth || !$expYear || !$cvc || !$clientName) {
            echo json_encode(['success' => false, 'error' => 'Please fill out all required fields']);
            exit;
        }

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
                'id' => $result['body']['id'] ?? uniqid('txn_'), 'amount' => $amount,
                'description' => $productName,
                'clientName' => $clientName, 'clientEmail' => $clientEmail,
                'clientPhone' => $clientPhone, 'clientAddress' => $clientAddress,
                'clientCity' => $clientCity, 'clientState' => $clientState,
                'clientZip' => $clientZip, 'status' => 'approved',
                'timestamp' => date('c'), 'cardLast4' => $cardLast4,
                'cardBrand' => $cardBrand, 'source' => $link['source'] ?? 'link',
            ];
            saveTransaction($txn);
            saveCardForCustomer($clientName, $clientEmail, $clientPhone, $clientAddress, $clientCity, $clientState, $clientZip, $cardNumber, $expMonth, $expYear, $cvc, $cardLast4, $cardBrand);

            // Set up autopay if link has autopay enabled
            if (!empty($link['autopay'])) {
                $nextDate = date('Y-m-d', strtotime('+1 month'));
                $sub = [
                    'id' => uniqid('ap_'), 'clientName' => $clientName,
                    'clientEmail' => $clientEmail, 'clientPhone' => $clientPhone,
                    'clientAddress' => $clientAddress, 'clientCity' => $clientCity,
                    'clientState' => $clientState, 'clientZip' => $clientZip,
                    'amount' => $amount, 'frequency' => 'monthly',
                    'nextChargeDate' => $nextDate, 'status' => 'active',
                    'cardLast4' => $cardLast4, 'cardBrand' => $cardBrand,
                    'cardNumber' => $cardNumber, 'expMonth' => $expMonth,
                    'expYear' => $expYear, 'cvc' => $cvc,
                    'description' => $link['description'] ?? 'Monthly Subscription',
                    'createdAt' => date('c'), 'source' => $link['source'] ?? 'link',
                ];
                $autopays = getAutopays();
                $autopays[] = $sub;
                saveAutopays($autopays);
            }

            // Payment links stay active and reusable — never marked used/expired.
            // Record last use for reference without deactivating the link.
            foreach ($links as &$ll) { if ($ll['id'] === $linkId) { $ll['lastUsedAt'] = date('c'); $ll['lastUsedBy'] = $clientName; break; } }
            unset($ll);
            savePaymentLinks($links);

            echo json_encode(['success' => true, 'message' => 'Payment successful']);
        } else {
            $errorMsg = $result['body']['message'] ?? ($result['body']['error'] ?? 'Payment failed');
            $txn = [
                'id' => uniqid('txn_'), 'amount' => $amount,
                'description' => $productName,
                'clientName' => $clientName, 'clientEmail' => $clientEmail,
                'clientPhone' => $clientPhone, 'status' => 'declined',
                'timestamp' => date('c'), 'cardLast4' => $cardLast4,
                'cardBrand' => $cardBrand, 'error' => $errorMsg,
                'source' => $link['source'] ?? 'link',
            ];
            saveTransaction($txn);
            echo json_encode(['success' => false, 'error' => $errorMsg]);
        }
        exit;
    }

    // Render public payment page with product selection
    $prefillName = htmlspecialchars($link['clientName'] ?? '');
    $prefillEmail = htmlspecialchars($link['clientEmail'] ?? '');
    $linkDesc = htmlspecialchars($link['description'] ?? 'Choose a Plan');
    $hasAutopay = !empty($link['autopay']);
    $products = $link['products'] ?? [['name' => $link['description'] ?? 'Payment', 'price' => $link['amount']]];
    $productsJson = json_encode($products);
    $singleProduct = count($products) === 1;
    echo '<!DOCTYPE html><html><head><title>' . $linkDesc . '</title><meta name="viewport" content="width=device-width,initial-scale=1">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:linear-gradient(135deg,#0f0c29,#302b63,#24243e);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.pay-container{max-width:600px;width:100%}
.pay-header{text-align:center;margin-bottom:28px;color:#fff}
.pay-header h1{font-size:28px;font-weight:800;margin-bottom:6px}
.pay-header p{font-size:14px;opacity:.7}
.products-grid{display:grid;gap:14px;margin-bottom:24px}
.product-card{background:#fff;border-radius:16px;padding:24px;cursor:pointer;transition:all .2s;border:3px solid transparent;position:relative;display:flex;justify-content:space-between;align-items:center}
.product-card:hover{transform:translateY(-2px);box-shadow:0 8px 30px rgba(102,126,234,.3);border-color:#667eea}
.product-card.selected{border-color:#667eea;background:#f0f4ff}
.product-card .product-info h3{font-size:17px;font-weight:700;color:#1a1a2e;margin-bottom:4px}
.product-card .product-info p{font-size:13px;color:#888}
.product-card .product-price{font-size:26px;font-weight:800;color:#667eea;white-space:nowrap}
.product-card .check-circle{position:absolute;top:12px;right:12px;width:24px;height:24px;border-radius:50%;border:2px solid #ddd;display:flex;align-items:center;justify-content:center;font-size:14px;color:#fff;background:#fff;transition:all .2s}
.product-card.selected .check-circle{background:#667eea;border-color:#667eea}
.checkout-card{background:#fff;border-radius:20px;padding:36px;box-shadow:0 20px 60px rgba(0,0,0,.3);display:none}
.checkout-card.show{display:block}
.checkout-card h2{font-size:18px;font-weight:700;color:#1a1a2e;margin-bottom:4px}
.checkout-card .selected-product{color:#667eea;font-size:14px;font-weight:600;margin-bottom:20px}
.pay-field{margin-bottom:14px}
.pay-field label{display:block;font-size:12px;font-weight:600;color:#555;margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px}
.pay-field input{width:100%;padding:13px 15px;border:2px solid #e0e0e0;border-radius:11px;font-size:15px;transition:border .2s;outline:none}
.pay-field input:focus{border-color:#667eea}
.pay-row{display:flex;gap:12px}
.pay-row .pay-field{flex:1}
.pay-btn{width:100%;padding:16px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border:none;border-radius:14px;font-size:17px;font-weight:700;cursor:pointer;margin-top:8px;transition:transform .1s,box-shadow .2s}
.pay-btn:hover{transform:translateY(-1px);box-shadow:0 8px 24px rgba(102,126,234,.4)}
.pay-btn:disabled{opacity:.6;cursor:not-allowed;transform:none}
.pay-msg{text-align:center;margin-top:16px;padding:12px;border-radius:10px;font-size:14px;font-weight:600;display:none}
.pay-msg.success{display:block;background:#e8f5e9;color:#2e7d32}
.pay-msg.error{display:block;background:#fce4ec;color:#c62828}
.pay-secure{text-align:center;margin-top:16px;font-size:11px;color:rgba(255,255,255,.5)}
.pay-autopay-badge{margin-bottom:16px;text-align:center}
.pay-autopay-badge span{background:#e8f5e9;color:#2e7d32;padding:5px 14px;border-radius:20px;font-size:12px;font-weight:600}
</style></head><body>
<div class="pay-container">
    <div class="pay-header">
        <h1>' . $linkDesc . '</h1>
        <p>Select a plan below to continue</p>
    </div>

    <div class="products-grid" id="productsGrid">';

    foreach ($products as $i => $prod) {
        $pName = htmlspecialchars($prod['name'] ?? 'Product');
        $pPrice = number_format(floatval($prod['price'] ?? 0), 2);
        $autoClass = $singleProduct ? ' selected' : '';
        echo '
        <div class="product-card' . $autoClass . '" onclick="selectProduct(' . $i . ', ' . floatval($prod['price'] ?? 0) . ', this)" data-index="' . $i . '">
            <div class="product-info">
                <h3>' . $pName . '</h3>
            </div>
            <div class="product-price">$' . $pPrice . '</div>
            <div class="check-circle">&#10003;</div>
        </div>';
    }

    echo '
    </div>

    <div class="checkout-card' . ($singleProduct ? ' show' : '') . '" id="checkoutCard">
        <h2>Payment Details</h2>
        <div class="selected-product" id="selectedLabel">' . ($singleProduct ? htmlspecialchars($products[0]['name'] ?? 'Payment') . ' — $' . number_format(floatval($products[0]['price'] ?? 0), 2) : '') . '</div>';
    if ($hasAutopay) echo '<div class="pay-autopay-badge"><span>+ Monthly Autopay</span></div>';
    echo '
        <form id="payForm" onsubmit="submitPay(event)">
            <div class="pay-field"><label>Full Name</label><input type="text" id="pName" value="' . $prefillName . '" required placeholder="John Smith"></div>
            <div class="pay-field"><label>Email</label><input type="email" id="pEmail" value="' . $prefillEmail . '" required placeholder="john@email.com"></div>
            <div class="pay-field"><label>Phone</label><input type="tel" id="pPhone" placeholder="(555) 555-5555"></div>
            <div class="pay-field"><label>Card Number</label><input type="text" id="pCard" required placeholder="4111 1111 1111 1111" maxlength="19" oninput="formatCardNum(this)"></div>
            <div class="pay-row">
                <div class="pay-field"><label>Expiry (MM/YY)</label><input type="text" id="pExp" required placeholder="MM/YY" maxlength="5" oninput="formatExp(this)"></div>
                <div class="pay-field"><label>CVC</label><input type="text" id="pCvc" required placeholder="123" maxlength="4"></div>
            </div>
            <div class="pay-row">
                <div class="pay-field"><label>Billing Address</label><input type="text" id="pAddr" placeholder="123 Main St"></div>
                <div class="pay-field"><label>Zip Code</label><input type="text" id="pZip" placeholder="12345" maxlength="10"></div>
            </div>
            <button type="submit" class="pay-btn" id="payBtn">Pay Now</button>
        </form>
        <div class="pay-msg" id="payMsg"></div>
    </div>
    <div class="pay-secure">&#128274; Secure payment</div>
</div>
<script>
var selectedProduct = ' . ($singleProduct ? '0' : '-1') . ';
var selectedAmount = ' . ($singleProduct ? floatval($products[0]['price'] ?? 0) : '0') . ';
var products = ' . $productsJson . ';

function selectProduct(index, price, el) {
    selectedProduct = index;
    selectedAmount = price;
    document.querySelectorAll(".product-card").forEach(c => c.classList.remove("selected"));
    el.classList.add("selected");
    document.getElementById("checkoutCard").classList.add("show");
    document.getElementById("selectedLabel").textContent = products[index].name + " — $" + price.toFixed(2);
    document.getElementById("payBtn").textContent = "Pay $" + price.toFixed(2);
    document.getElementById("pName").focus();
}

function formatCardNum(el){let v=el.value.replace(/\D/g,"");el.value=v.replace(/(\d{4})(?=\d)/g,"$1 ").trim()}
function formatExp(el){let v=el.value.replace(/\D/g,"");if(v.length>=2)v=v.slice(0,2)+"/"+v.slice(2);el.value=v.slice(0,5)}

async function submitPay(e){
    e.preventDefault();
    if (selectedProduct < 0) { alert("Please select a product first"); return; }
    const btn=document.getElementById("payBtn"),msg=document.getElementById("payMsg");
    btn.disabled=true;btn.textContent="Processing...";msg.className="pay-msg";msg.style.display="none";
    const exp=document.getElementById("pExp").value.split("/");
    try{
        const res=await fetch("?pay=' . $linkId . '&action=pay_link",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({
            clientName:document.getElementById("pName").value,
            clientEmail:document.getElementById("pEmail").value,
            clientPhone:document.getElementById("pPhone").value,
            clientAddress:document.getElementById("pAddr").value,
            clientZip:document.getElementById("pZip").value,
            cardNumber:document.getElementById("pCard").value.replace(/\s/g,""),
            expMonth:exp[0]||"",expYear:exp[1]||"",
            cvc:document.getElementById("pCvc").value,
            selectedProduct: selectedProduct,
            selectedAmount: selectedAmount
        })});
        const data=await res.json();
        if(data.success){
            msg.className="pay-msg success";msg.textContent="Payment successful! Thank you.";msg.style.display="block";
            document.getElementById("payForm").style.display="none";
            document.getElementById("selectedLabel").textContent="";
        }else{
            msg.className="pay-msg error";msg.textContent=data.error||"Payment failed";msg.style.display="block";
            btn.disabled=false;btn.textContent="Pay $"+selectedAmount.toFixed(2);
        }
    }catch(err){
        msg.className="pay-msg error";msg.textContent="Something went wrong. Please try again.";msg.style.display="block";
        btn.disabled=false;btn.textContent="Pay $"+selectedAmount.toFixed(2);
    }
}
document.addEventListener("keydown",function(e){if(e.target&&e.target.type==="tel"&&e.key==="Backspace"&&e.target.selectionStart===e.target.selectionEnd){var c=e.target.selectionStart;while(c>0&&/[^0-9]/.test(e.target.value.charAt(c-1)))c--;if(c!==e.target.selectionStart)e.target.setSelectionRange(c,c);}});
document.addEventListener("input",function(e){if(e.target&&e.target.type==="tel"){var d=e.target.value.replace(/[^0-9]/g,"");if(d.length>10)d=d.substring(0,10);var f="";if(d.length>0)f="("+d.substring(0,3);if(d.length>=3)f+=") ";if(d.length>3)f+=d.substring(3,6);if(d.length>=6)f+="-"+d.substring(6,10);e.target.value=f;}});
</script></body></html>';
    exit;
}

// All API and page access requires admin auth (except admin_login above)
$isAdminAuth = !empty($_SESSION['admin_auth']);

// Check if this session was force-kicked
if ($isAdminAuth && isSessionKicked()) {
    session_destroy();
    session_start();
    $isAdminAuth = false;
}

// Update last active timestamp on every page load
if ($isAdminAuth) {
    recordSession();
}

// ─── Session Tracking Helpers ─────────────────────────────────
function getActiveSessions() {
    return storageReadDocument('sessions.json', []);
}

function saveSessions($sessions) {
    storageWriteDocument('sessions.json', $sessions);
}

function getClientIP() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) return $_SERVER['HTTP_X_REAL_IP'];
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function parseUserAgent($ua) {
    $browser = 'Unknown'; $os = 'Unknown'; $device = 'Desktop';
    // Browser
    if (preg_match('/Firefox\/(\S+)/', $ua, $m)) $browser = 'Firefox ' . explode('.', $m[1])[0];
    elseif (preg_match('/Edg\/(\S+)/', $ua, $m)) $browser = 'Edge ' . explode('.', $m[1])[0];
    elseif (preg_match('/OPR\/(\S+)/', $ua, $m)) $browser = 'Opera ' . explode('.', $m[1])[0];
    elseif (preg_match('/Chrome\/(\S+)/', $ua, $m)) $browser = 'Chrome ' . explode('.', $m[1])[0];
    elseif (preg_match('/Safari\/(\S+)/', $ua) && preg_match('/Version\/(\S+)/', $ua, $m)) $browser = 'Safari ' . explode('.', $m[1])[0];
    // OS
    if (preg_match('/Windows NT 10/', $ua)) $os = 'Windows 10/11';
    elseif (preg_match('/Windows NT/', $ua)) $os = 'Windows';
    elseif (preg_match('/Mac OS X (\d+[_\.]\d+)/', $ua, $m)) $os = 'macOS ' . str_replace('_', '.', $m[1]);
    elseif (preg_match('/iPhone/', $ua)) { $os = 'iOS'; $device = 'iPhone'; }
    elseif (preg_match('/iPad/', $ua)) { $os = 'iPadOS'; $device = 'iPad'; }
    elseif (preg_match('/Android (\S+)/', $ua, $m)) { $os = 'Android ' . $m[1]; $device = 'Mobile'; }
    elseif (preg_match('/Linux/', $ua)) $os = 'Linux';
    // Device
    if (preg_match('/Mobile|iPhone|Android/', $ua)) $device = 'Mobile';
    elseif (preg_match('/iPad|Tablet/', $ua)) $device = 'Tablet';
    return ['browser' => $browser, 'os' => $os, 'device' => $device];
}

function lookupGeoIP($ip) {
    if ($ip === '127.0.0.1' || $ip === '::1' || str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.')) {
        return ['city' => 'Local', 'region' => '', 'country' => 'LAN', 'isp' => 'Local Network', 'lat' => 0, 'lon' => 0];
    }
    $ctx = stream_context_create(['http' => ['timeout' => 3]]);
    $resp = @file_get_contents("http://ip-api.com/json/{$ip}?fields=city,regionName,country,isp,lat,lon,query", false, $ctx);
    if ($resp) {
        $data = json_decode($resp, true);
        if ($data) return ['city' => $data['city'] ?? '', 'region' => $data['regionName'] ?? '', 'country' => $data['country'] ?? '', 'isp' => $data['isp'] ?? '', 'lat' => $data['lat'] ?? 0, 'lon' => $data['lon'] ?? 0];
    }
    return ['city' => 'Unknown', 'region' => '', 'country' => '', 'isp' => '', 'lat' => 0, 'lon' => 0];
}

function recordSession() {
    $sessionId = session_id();
    $ip = getClientIP();
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $parsed = parseUserAgent($ua);
    $geo = lookupGeoIP($ip);
    $sessions = getActiveSessions();
    // Check if session already exists
    $found = false;
    foreach ($sessions as &$s) {
        if ($s['sessionId'] === $sessionId) {
            $s['lastActive'] = date('c');
            $s['pageViews'] = ($s['pageViews'] ?? 0) + 1;
            $found = true;
            break;
        }
    }
    unset($s);
    if (!$found) {
        $sessions[] = [
            'sessionId' => $sessionId,
            'ip' => $ip,
            'userAgent' => $ua,
            'browser' => $parsed['browser'],
            'os' => $parsed['os'],
            'device' => $parsed['device'],
            'city' => $geo['city'],
            'region' => $geo['region'],
            'country' => $geo['country'],
            'isp' => $geo['isp'],
            'lat' => $geo['lat'],
            'lon' => $geo['lon'],
            'loginTime' => date('c'),
            'lastActive' => date('c'),
            'pageViews' => 1,
            'status' => 'active',
        ];
    }
    // Clean up sessions older than 30 days
    $sessions = array_values(array_filter($sessions, function($s) {
        return strtotime($s['lastActive'] ?? $s['loginTime'] ?? 'now') > strtotime('-30 days');
    }));
    saveSessions($sessions);
}

function isSessionKicked() {
    $sessionId = session_id();
    $sessions = getActiveSessions();
    foreach ($sessions as $s) {
        if ($s['sessionId'] === $sessionId && ($s['status'] ?? '') === 'kicked') return true;
    }
    return false;
}

// ─── Helpers ─────────────────────────────────────────────────
function getSavedCreds() {
    $data = storageReadDocument('squire_creds.json', null);
    if ($data && isset($data['username']) && isset($data['password'])) return $data;
    return null;
}

function saveCreds($username, $password) {
    storageWriteDocument('squire_creds.json', ['username' => $username, 'password' => $password]);
}

function getToken() {
    if (!empty($_SESSION['squire_token'])) return $_SESSION['squire_token'];
    $token = storageReadText('squire_token.txt');
    if ($token) { $_SESSION['squire_token'] = $token; return $token; }
    return '';
}

function saveToken($token) {
    storageWriteText('squire_token.txt', $token);
    $_SESSION['squire_token'] = $token;
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

function normalizeCustomerName($name) {
    return strtolower(trim((string)$name));
}

function customerDeletionKey($name) {
    $normalized = normalizeCustomerName($name);
    return $normalized === '' ? '' : hash('sha256', $normalized);
}

function getDeletedCustomerKeys() {
    $keys = storageReadDocument('deleted_customers.json', []);
    return array_values(array_unique(array_filter(is_array($keys) ? $keys : [], 'is_string')));
}

function markCustomerDeleted($name) {
    $key = customerDeletionKey($name);
    if ($key === '') return;
    storageMutateDocument('deleted_customers.json', [], function($keys) use ($key) {
        $keys = is_array($keys) ? $keys : [];
        if (!in_array($key, $keys, true)) $keys[] = $key;
        return array_values($keys);
    });
}

function restoreDeletedCustomer($name) {
    $key = customerDeletionKey($name);
    if ($key === '' || !storageHasDocument('deleted_customers.json')) return;
    storageMutateDocument('deleted_customers.json', [], function($keys) use ($key) {
        $keys = is_array($keys) ? $keys : [];
        return array_values(array_filter($keys, function($deletedKey) use ($key) {
            return $deletedKey !== $key;
        }));
    });
}

function transactionActionPinHash() {
    storageLoadConfig();
    $hash = getenv('TRANSACTION_ACTION_PIN_HASH');
    return is_string($hash) ? trim($hash) : '';
}

function isTransactionActionPinAuthorized() {
    return (int)($_SESSION['transaction_action_pin_verified_until'] ?? 0) >= time();
}

function requireTransactionActionPinAuthorization() {
    if (isTransactionActionPinAuthorized()) return;
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Security PIN verification required']);
    exit;
}

function getPaymentLinks() {
    return storageReadDocument('payment_links.json', []);
}
function savePaymentLinks($all) {
    storageWriteDocument('payment_links.json', $all);
}

function getAuditLog() {
    return storageReadDocument('audit_log.json', []);
}
function addAuditEntry($action, $target, $details = '') {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    storageMutateDocument('audit_log.json', [], function($log) use ($action, $target, $details, $ip, $ua) {
        array_unshift($log, [
            'timestamp' => date('c'),
            'action' => $action,
            'target' => $target,
            'details' => $details,
            'ip' => $ip,
            'userAgent' => $ua,
        ]);
        return count($log) > 5000 ? array_slice($log, 0, 5000) : $log;
    });
}

function saveCardForCustomer($clientName, $clientEmail, $clientPhone, $clientAddress, $clientCity, $clientState, $clientZip, $cardNumber, $expMonth, $expYear, $cvc, $cardLast4, $cardBrand) {
    if (empty($clientName)) return;
    $all = getSavedCards();
    $encCard = encryptCard(['number' => $cardNumber, 'exp_month' => $expMonth, 'exp_year' => $expYear, 'cvc' => $cvc]);
    $found = false;
    foreach ($all as &$c) {
        if (strtolower($c['clientName']) === strtolower($clientName)) {
            $c['clientEmail'] = $clientEmail;
            $c['clientPhone'] = $clientPhone;
            $c['clientAddress'] = $clientAddress;
            $c['clientCity'] = $clientCity;
            $c['clientState'] = $clientState;
            $c['clientZip'] = $clientZip;
            $c['encryptedCard'] = $encCard;
            $c['cardLast4'] = $cardLast4;
            $c['cardBrand'] = $cardBrand;
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
    restoreDeletedCustomer($clientName);
}

function getAutopays() {
    return storageReadDocument('autopay.json', []);
}

function saveAutopays($all) {
    storageWriteDocument('autopay.json', $all);
}

function getDeposits() {
    return storageReadDocument('deposits.json', []);
}

function saveDeposits($all) {
    storageWriteDocument('deposits.json', $all);
}

function getDisputes() {
    return storageReadDocument('disputes.json', []);
}

function saveDisputes($all) {
    storageWriteDocument('disputes.json', $all);
}

function squireAPI($method, $endpoint, $data = null, $token = null) {
    // Route through local cloudscraper proxy to bypass Cloudflare
    $proxyUrl = getenv('SQUIRE_PROXY_URL') ?: 'http://127.0.0.1:9876';
    $payload = ['method' => $method, 'endpoint' => $endpoint];
    if ($data !== null) $payload['data'] = $data;
    if ($token) $payload['token'] = $token;

    $ch = curl_init($proxyUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['code' => 0, 'body' => ['error' => 'Payment gateway proxy is not running. Please contact support.'], 'raw' => '', 'error' => $curlError];
    }

    $result = json_decode($response, true);
    if (!$result) {
        return ['code' => 0, 'body' => ['error' => 'Invalid response from payment gateway'], 'raw' => $response, 'error' => ''];
    }

    return [
        'code' => $result['code'] ?? 0,
        'body' => $result['body'] ?? ['error' => 'Empty response'],
        'raw' => $result['raw'] ?? '',
        'error' => '',
    ];
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
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_USERPWD => $STRIPE_PK . ':',
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_PROXY => $US_PROXY,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true);
}

function encryptCard($cardData) {
    $key = hash('sha256', __DIR__ . '/data/.enc_key', true);
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt(json_encode($cardData), 'aes-256-cbc', $key, 0, $iv);
    return base64_encode($iv . '::' . $encrypted);
}

function decryptCard($encrypted) {
    $key = hash('sha256', __DIR__ . '/data/.enc_key', true);
    $parts = explode('::', base64_decode($encrypted), 2);
    if (count($parts) !== 2) return null;
    $decrypted = openssl_decrypt($parts[1], 'aes-256-cbc', $key, 0, $parts[0]);
    return json_decode($decrypted, true);
}

function formatPhone($phone) {
    if (!$phone) return '';
    $digits = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($digits) === 11 && $digits[0] === '1') $digits = substr($digits, 1);
    if (strlen($digits) === 10) return '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6);
    return $phone;
}

function getTransactionType($transaction) {
    $source = strtolower(trim((string)($transaction['source'] ?? '')));
    return !empty($transaction['subscriptionId']) || $source === 'autopay'
        ? 'auto'
        : 'manual';
}

function getTransactionTypeLabel($transaction) {
    return getTransactionType($transaction) === 'auto' ? 'Auto' : 'Manual';
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

// ─── API Endpoints (require admin auth) ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] !== 'admin_login') {
    header('Content-Type: application/json');
    if (!$isAdminAuth) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit; }

    // Server-side gateway connect
    if ($_GET['action'] === 'gateway_connect') {
        $creds = getSavedCreds();
        if (!$creds) { echo json_encode(['success' => false, 'error' => 'No saved credentials']); exit; }
        $result = squireAPI('POST', '/v1/login', ['username' => $creds['username'], 'password' => $creds['password']]);
        if ($result['code'] >= 200 && $result['code'] < 300 && !empty($result['body']['token'])) {
            saveToken($result['body']['token']);
            echo json_encode(['success' => true]);
        } else {
            $msg = $result['body']['message'] ?? ($result['body']['error'] ?? 'Login failed');
            echo json_encode(['success' => false, 'error' => $msg]);
        }
        exit;
    }

    if ($_GET['action'] === 'save_login') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!empty($input['username']) && !empty($input['password'])) {
            saveCreds($input['username'], $input['password']);
            // Also try to login right away
            $result = squireAPI('POST', '/v1/login', ['username' => $input['username'], 'password' => $input['password']]);
            if ($result['code'] >= 200 && $result['code'] < 300 && !empty($result['body']['token'])) {
                saveToken($result['body']['token']);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $result['body']['message'] ?? 'Login failed']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Username and password required']);
        }
        exit;
    }

    if ($_GET['action'] === 'save_token') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!empty($input['token'])) { saveToken($input['token']); echo json_encode(['success' => true]); }
        else echo json_encode(['success' => false, 'error' => 'No token']);
        exit;
    }

    if ($_GET['action'] === 'charge') {
        $input = json_decode(file_get_contents('php://input'), true);
        $token = getToken();
        if (!$token) { echo json_encode(['success' => false, 'error' => 'Gateway not connected.']); exit; }

        $amount = floatval($input['amount'] ?? 0);
        $description = $input['description'] ?? 'Custom Charge';
        $clientName = $input['clientName'] ?? '';
        $clientEmail = $input['clientEmail'] ?? '';
        $clientPhone = $input['clientPhone'] ?? '';
        $clientAddress = $input['clientAddress'] ?? '';
        $clientCity = $input['clientCity'] ?? '';
        $clientState = $input['clientState'] ?? '';
        $clientZip = $input['clientZip'] ?? '';
        $cardNumber = preg_replace('/\s+/', '', $input['cardNumber'] ?? '');
        $expMonth = $input['expMonth'] ?? '';
        $expYear = $input['expYear'] ?? '';
        $cvc = $input['cvc'] ?? '';
        $chargeSource = $input['source'] ?? 'manual';

        if (!$cardNumber || !$expMonth || !$expYear || !$cvc || $amount <= 0) {
            echo json_encode(['success' => false, 'error' => 'Card details and amount are required']);
            exit;
        }

        // Tokenize card server-side
        $tokenResult = tokenizeCard($cardNumber, $expMonth, $expYear, $cvc, $clientAddress ?? '', $clientCity ?? '', $clientState ?? '', $clientZip ?? '', $clientName ?? '');
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
                'id' => $result['body']['id'] ?? uniqid('txn_'), 'amount' => $amount,
                'description' => $description, 'clientName' => $clientName,
                'clientEmail' => $clientEmail, 'clientPhone' => $clientPhone,
                'clientAddress' => $clientAddress, 'clientCity' => $clientCity,
                'clientState' => $clientState, 'clientZip' => $clientZip,
                'status' => 'approved', 'timestamp' => date('c'),
                'cardLast4' => $cardLast4, 'cardBrand' => $cardBrand,
                'source' => $chargeSource,
            ];
            saveTransaction($txn);
            // Save card for future use
            saveCardForCustomer($clientName, $clientEmail, $clientPhone, $clientAddress, $clientCity, $clientState, $clientZip, $cardNumber, $expMonth, $expYear, $cvc, $cardLast4, $cardBrand);
            addAuditEntry('charge_approved', $clientName, 'Charged $' . number_format($amount, 2) . ' | ' . $cardBrand . ' ****' . $cardLast4 . ' | ' . ($input['description'] ?? 'Custom Charge'));
            echo json_encode(['success' => true, 'data' => $result['body'], 'transaction' => $txn, 'cardLast4' => $cardLast4, 'cardBrand' => $cardBrand]);
        } else {
            $errorMsg = $result['body']['message'] ?? ($result['body']['error'] ?? 'Charge failed');
            $txn = [
                'id' => uniqid('txn_'), 'amount' => $amount,
                'description' => $description, 'clientName' => $clientName,
                'clientEmail' => $clientEmail, 'clientPhone' => $clientPhone,
                'clientAddress' => $clientAddress, 'clientCity' => $clientCity,
                'clientState' => $clientState, 'clientZip' => $clientZip,
                'status' => 'declined', 'timestamp' => date('c'),
                'cardLast4' => $cardLast4, 'cardBrand' => $cardBrand,
                'error' => $errorMsg, 'source' => $chargeSource,
            ];
            saveTransaction($txn);
            addAuditEntry('charge_declined', $clientName, 'Declined $' . number_format($amount, 2) . ' | ' . $cardBrand . ' ****' . $cardLast4 . ' | ' . $errorMsg);
            echo json_encode(['success' => false, 'error' => $errorMsg, 'details' => $result['body']]);
        }
        exit;
    }

    // ─── Autopay: Create subscription ───
    if ($_GET['action'] === 'autopay_create') {
        $input = json_decode(file_get_contents('php://input'), true);
        $clientName = trim($input['clientName'] ?? '');
        $clientEmail = trim($input['clientEmail'] ?? '');
        $clientPhone = trim($input['clientPhone'] ?? '');
        $clientAddress = trim($input['clientAddress'] ?? '');
        $clientCity = trim($input['clientCity'] ?? '');
        $clientState = trim($input['clientState'] ?? '');
        $clientZip = trim($input['clientZip'] ?? '');
        $amount = floatval($input['amount'] ?? 0);
        $description = trim($input['description'] ?? 'Recurring Charge');
        $frequency = $input['frequency'] ?? 'monthly';
        $startDate = $input['startDate'] ?? date('Y-m-d');
        $cardNumber = preg_replace('/\s+/', '', $input['cardNumber'] ?? '');
        $expMonth = $input['expMonth'] ?? '';
        $expYear = $input['expYear'] ?? '';
        $cvc = $input['cvc'] ?? '';
        $cardLast4 = $input['cardLast4'] ?? substr($cardNumber, -4);
        $cardBrand = $input['cardBrand'] ?? 'Card';
        $apSource = $input['source'] ?? '';

        if (!$clientName || $amount <= 0 || !$cardNumber) {
            echo json_encode(['success' => false, 'error' => 'Client name, amount, and card are required']);
            exit;
        }

        // Encrypt and store card details for recurring charges
        $encryptedCard = encryptCard([
            'number' => $cardNumber,
            'exp_month' => $expMonth,
            'exp_year' => $expYear,
            'cvc' => $cvc,
        ]);

        // Calculate next charge date
        // If chargedNow flag is set, the card was already charged in processCharge,
        // so set nextCharge to the NEXT billing cycle (not start date) to avoid double charging
        $chargedNow = !empty($input['chargedNow']);
        if ($chargedNow) {
            $nextCharge = calcNextCharge($startDate ?: date('Y-m-d'), $frequency);
        } else {
            $nextCharge = $startDate ?: date('Y-m-d');
        }

        $sub = [
            'id' => 'sub_' . bin2hex(random_bytes(8)),
            'clientName' => $clientName,
            'clientEmail' => $clientEmail,
            'clientPhone' => $clientPhone,
            'clientAddress' => $clientAddress,
            'clientCity' => $clientCity,
            'clientState' => $clientState,
            'clientZip' => $clientZip,
            'amount' => $amount,
            'description' => $description,
            'frequency' => $frequency,
            'startDate' => $startDate,
            'nextCharge' => $nextCharge,
            'cardLast4' => $cardLast4,
            'cardBrand' => $cardBrand,
            'encryptedCard' => $encryptedCard,
            'status' => 'active',
            'createdAt' => date('c'),
            'history' => [],
            'failCount' => 0,
            'source' => $apSource,
        ];

        $all = getAutopays();
        array_unshift($all, $sub);
        saveAutopays($all);

        // Save card for future reuse
        saveCardForCustomer($clientName, $clientEmail, $clientPhone, $clientAddress, $clientCity, $clientState, $clientZip, $cardNumber, $expMonth, $expYear, $cvc, $cardLast4, $cardBrand);
        addAuditEntry('autopay_created', $clientName, 'Autopay $' . number_format($amount, 2) . '/' . $frequency . ' | Next: ' . $nextCharge . ' | ' . $cardBrand . ' ****' . $cardLast4);

        echo json_encode(['success' => true, 'subscription' => $sub]);
        exit;
    }

    // ─── Autopay: Cancel subscription ───
    if ($_GET['action'] === 'autopay_cancel') {
        $input = json_decode(file_get_contents('php://input'), true);
        $subId = $input['id'] ?? '';
        $all = getAutopays();
        $found = false;
        foreach ($all as &$sub) {
            if ($sub['id'] === $subId) {
                $sub['status'] = 'cancelled';
                $sub['cancelledAt'] = date('c');
                $found = true;
                break;
            }
        }
        unset($sub);
        if ($found) {
            saveAutopays($all);
            $cancelTarget = '';
            foreach ($all as $s2) { if ($s2['id'] === $subId) { $cancelTarget = $s2['clientName'] ?? ''; break; } }
            addAuditEntry('autopay_cancelled', $cancelTarget, 'Autopay subscription cancelled | ID: ' . $subId);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Subscription not found']);
        }
        exit;
    }

    // ─── Autopay: Pause subscription ───
    if ($_GET['action'] === 'autopay_pause') {
        $input = json_decode(file_get_contents('php://input'), true);
        $subId = $input['id'] ?? '';
        $all = getAutopays();
        $pauseTarget = ''; $newStatus = '';
        foreach ($all as &$sub) {
            if ($sub['id'] === $subId) {
                $sub['status'] = ($sub['status'] === 'paused') ? 'active' : 'paused';
                $pauseTarget = $sub['clientName'] ?? '';
                $newStatus = $sub['status'];
                break;
            }
        }
        unset($sub);
        saveAutopays($all);
        addAuditEntry('autopay_' . $newStatus, $pauseTarget, 'Autopay ' . $newStatus . ' | ID: ' . $subId);
        echo json_encode(['success' => true]);
        exit;
    }

    // ─── Autopay: Resume paused ───
    if ($_GET['action'] === 'autopay_resume') {
        $input = json_decode(file_get_contents('php://input'), true);
        $subId = $input['id'] ?? '';
        $all = getAutopays();
        $resumeTarget = '';
        foreach ($all as &$sub) {
            if ($sub['id'] === $subId && $sub['status'] === 'paused') {
                $sub['status'] = 'active';
                $resumeTarget = $sub['clientName'] ?? '';
                break;
            }
        }
        unset($sub);
        saveAutopays($all);
        if ($resumeTarget) addAuditEntry('autopay_resumed', $resumeTarget, 'Autopay resumed | ID: ' . $subId);
        echo json_encode(['success' => true]);
        exit;
    }

    // ─── Autopay: Retry failed ───
    if ($_GET['action'] === 'autopay_retry') {
        $input = json_decode(file_get_contents('php://input'), true);
        $subId = $input['id'] ?? '';
        $all = getAutopays();
        $retryTarget = '';
        foreach ($all as &$sub) {
            if ($sub['id'] === $subId && $sub['status'] === 'failed') {
                $sub['status'] = 'active';
                $sub['failCount'] = 0;
                $sub['nextCharge'] = date('Y-m-d');
                $retryTarget = $sub['clientName'] ?? '';
                break;
            }
        }
        unset($sub);
        saveAutopays($all);
        if ($retryTarget) addAuditEntry('autopay_retry', $retryTarget, 'Autopay retried (reset to active) | ID: ' . $subId);
        echo json_encode(['success' => true]);
        exit;
    }

    // ─── Autopay: Update next charge date ───
    if ($_GET['action'] === 'autopay_update_date') {
        $input = json_decode(file_get_contents('php://input'), true);
        $subId = $input['id'] ?? '';
        $newDate = $input['nextCharge'] ?? '';
        if (!$subId || !$newDate) { echo json_encode(['success' => false, 'error' => 'ID and date required']); exit; }
        $all = getAutopays();
        $dateTarget = ''; $oldDate = '';
        foreach ($all as &$sub) {
            if ($sub['id'] === $subId) {
                $oldDate = $sub['nextCharge'] ?? '';
                $dateTarget = $sub['clientName'] ?? '';
                $sub['nextCharge'] = $newDate;
                break;
            }
        }
        unset($sub);
        saveAutopays($all);
        addAuditEntry('autopay_date_changed', $dateTarget, 'Next charge date changed from ' . $oldDate . ' to ' . $newDate);
        echo json_encode(['success' => true]);
        exit;
    }

    // ─── Skip next autopay cycle ───
    if ($_GET['action'] === 'autopay_skip') {
        $input = json_decode(file_get_contents('php://input'), true);
        $subId = $input['id'] ?? '';
        if (!$subId) { echo json_encode(['success' => false, 'error' => 'ID required']); exit; }
        $all = getAutopays();
        $skippedDate = '';
        $newDate = '';
        foreach ($all as &$sub) {
            if ($sub['id'] === $subId) {
                $skippedDate = $sub['nextCharge'] ?? '';
                $sub['nextCharge'] = calcNextCharge($sub['nextCharge'] ?? date('Y-m-d'), $sub['frequency'] ?? 'monthly');
                $newDate = $sub['nextCharge'];
                break;
            }
        }
        unset($sub);
        saveAutopays($all);
        $skipTarget = '';
        foreach ($all as $s3) { if ($s3['id'] === $subId) { $skipTarget = $s3['clientName'] ?? ''; break; } }
        addAuditEntry('autopay_skipped', $skipTarget, 'Skipped charge on ' . $skippedDate . ' | Next: ' . $newDate);
        echo json_encode(['success' => true, 'skippedDate' => $skippedDate, 'nextCharge' => $newDate]);
        exit;
    }

    // ─── Update autopay amount ───
    if ($_GET['action'] === 'autopay_update_amount') {
        $input = json_decode(file_get_contents('php://input'), true);
        $subId = $input['id'] ?? '';
        $newAmount = floatval($input['amount'] ?? 0);
        if (!$subId || $newAmount <= 0) { echo json_encode(['success' => false, 'error' => 'ID and valid amount required']); exit; }
        $all = getAutopays();
        $amtTarget = ''; $oldAmount = 0;
        foreach ($all as &$sub) {
            if ($sub['id'] === $subId) {
                $oldAmount = $sub['amount'] ?? 0;
                $amtTarget = $sub['clientName'] ?? '';
                $sub['amount'] = $newAmount;
                break;
            }
        }
        unset($sub);
        saveAutopays($all);
        addAuditEntry('autopay_amount_changed', $amtTarget, 'Amount changed from $' . number_format($oldAmount, 2) . ' to $' . number_format($newAmount, 2));
        echo json_encode(['success' => true]);
        exit;
    }

    // ─── Refund transaction ───
    if ($_GET['action'] === 'refund') {
        $input = json_decode(file_get_contents('php://input'), true);
        requireTransactionActionPinAuthorization();
        $txnId = $input['id'] ?? '';
        $refundType = $input['refundType'] ?? 'full'; // 'full' or 'custom'
        $customAmount = floatval($input['customAmount'] ?? 0);
        if (!$txnId) { echo json_encode(['success' => false, 'error' => 'Transaction ID required']); exit; }

        $token = getToken();
        if (!$token) { echo json_encode(['success' => false, 'error' => 'Gateway not connected']); exit; }

        // Build refund payload
        $refundData = [];
        if ($refundType === 'custom' && $customAmount > 0) {
            $refundData['amount'] = $customAmount;
        }

        // Refund via Squire API
        $refundResult = squireAPI('POST', '/v2/shop/' . $SHOP_ID . '/sale/' . $txnId . '/refund', $refundData, $token);

        if ($refundResult['code'] >= 200 && $refundResult['code'] < 300) {
            // API succeeded — update transaction status in our records
            $allTxns = getTransactions();
            foreach ($allTxns as &$txn) {
                if (($txn['id'] ?? '') === $txnId) {
                    $txn['status'] = 'refunded';
                    $txn['refundedAt'] = date('c');
                    if ($refundType === 'custom' && $customAmount > 0) {
                        $txn['refundAmount'] = $customAmount;
                        $txn['refundType'] = 'partial';
                    } else {
                        $txn['refundType'] = 'full';
                    }
                    break;
                }
            }
            unset($txn);
            saveTransactions($allTxns);
            $refundTarget = '';
            foreach ($allTxns as $rt) { if (($rt['id'] ?? '') === $txnId) { $refundTarget = $rt['clientName'] ?? ''; break; } }
            $refAmt = ($refundType === 'custom' && $customAmount > 0) ? $customAmount : floatval($txn['amount'] ?? 0);
            addAuditEntry('refund', $refundTarget, 'Refunded $' . number_format($refAmt, 2) . ' | ' . $refundType . ' refund | TXN: ' . $txnId);
            echo json_encode(['success' => true, 'message' => 'Refund processed successfully']);
        } else {
            $errorMsg = $refundResult['body']['message'] ?? ($refundResult['body']['error'] ?? 'Refund failed — gateway returned an error');
            echo json_encode(['success' => false, 'error' => $errorMsg]);
        }
        exit;
    }

    // ─── Kick/revoke a session ───
    if ($_GET['action'] === 'kick_session') {
        $input = json_decode(file_get_contents('php://input'), true);
        $targetId = $input['sessionId'] ?? '';
        if (!$targetId) { echo json_encode(['success' => false, 'error' => 'Session ID required']); exit; }
        $sessions = getActiveSessions();
        $kicked = false;
        foreach ($sessions as &$s) {
            if ($s['sessionId'] === $targetId) {
                $s['status'] = 'kicked';
                $s['kickedAt'] = date('c');
                $kicked = true;
                break;
            }
        }
        unset($s);
        if ($kicked) { saveSessions($sessions); echo json_encode(['success' => true]); }
        else { echo json_encode(['success' => false, 'error' => 'Session not found']); }
        exit;
    }

    // ─── Kick all sessions except current ───
    if ($_GET['action'] === 'kick_all_sessions') {
        $currentId = session_id();
        $sessions = getActiveSessions();
        foreach ($sessions as &$s) {
            if ($s['sessionId'] !== $currentId && $s['status'] === 'active') {
                $s['status'] = 'kicked';
                $s['kickedAt'] = date('c');
            }
        }
        unset($s);
        saveSessions($sessions);
        echo json_encode(['success' => true]);
        exit;
    }

    // ─── Clear all session history ───
    if ($_GET['action'] === 'clear_session_history') {
        $currentId = session_id();
        $sessions = getActiveSessions();
        $sessions = array_values(array_filter($sessions, fn($s) => $s['sessionId'] === $currentId));
        saveSessions($sessions);
        echo json_encode(['success' => true]);
        exit;
    }

    // ─── Verify PIN ──────────────────────────────────────
    if ($_GET['action'] === 'verify_pin') {
        $input = json_decode(file_get_contents('php://input'), true);
        $hash = transactionActionPinHash();
        if ($hash === '') {
            http_response_code(503);
            echo json_encode(['success' => false, 'error' => 'Security PIN is not configured']);
            exit;
        }

        $now = time();
        $lockedUntil = (int)($_SESSION['transaction_action_pin_locked_until'] ?? 0);
        if ($lockedUntil > $now) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Too many incorrect attempts. Try again later.']);
            exit;
        }

        $pin = (string)($input['pin'] ?? '');
        if ($pin !== '' && password_verify($pin, $hash)) {
            $_SESSION['transaction_action_pin_verified_until'] = $now + 300;
            unset($_SESSION['transaction_action_pin_failures'], $_SESSION['transaction_action_pin_locked_until']);
            echo json_encode(['success' => true]);
            exit;
        }

        $failures = (int)($_SESSION['transaction_action_pin_failures'] ?? 0) + 1;
        $_SESSION['transaction_action_pin_failures'] = $failures;
        if ($failures >= 5) {
            $_SESSION['transaction_action_pin_locked_until'] = $now + 900;
            unset($_SESSION['transaction_action_pin_failures']);
        }
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Incorrect PIN']);
        exit;
    }

    // ─── Update Customer Info ──────────────────────────────────
    if ($_GET['action'] === 'update_customer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        requireTransactionActionPinAuthorization();
        $oldName = trim($input['oldName'] ?? '');
        $newName = trim($input['name'] ?? '');
        $newEmail = trim($input['email'] ?? '');
        $newPhone = trim($input['phone'] ?? '');
        $newAddress = trim($input['address'] ?? '');
        $newCity = trim($input['city'] ?? '');
        $newState = trim($input['state'] ?? '');
        $newZip = trim($input['zip'] ?? '');

        if (!$oldName) { echo json_encode(['success' => false, 'error' => 'Customer name required']); exit; }
        if (!$newName) $newName = $oldName;

        // Update transactions
        $allTxns = getTransactions();
        foreach ($allTxns as &$t) {
            if (strtolower(trim($t['clientName'] ?? '')) === strtolower($oldName)) {
                $t['clientName'] = $newName;
                if ($newEmail !== '') $t['clientEmail'] = $newEmail;
                if ($newPhone !== '') $t['clientPhone'] = $newPhone;
                if ($newAddress !== '') $t['clientAddress'] = $newAddress;
                if ($newCity !== '') $t['clientCity'] = $newCity;
                if ($newState !== '') $t['clientState'] = $newState;
                if ($newZip !== '') $t['clientZip'] = $newZip;
            }
        }
        unset($t);
        saveTransactions($allTxns);

        // Update saved cards
        $allCards = getSavedCards();
        foreach ($allCards as &$sc) {
            if (strtolower(trim($sc['clientName'] ?? '')) === strtolower($oldName)) {
                $sc['clientName'] = $newName;
                if ($newEmail !== '') $sc['clientEmail'] = $newEmail;
                if ($newPhone !== '') $sc['clientPhone'] = $newPhone;
                if ($newAddress !== '') $sc['clientAddress'] = $newAddress;
                if ($newCity !== '') $sc['clientCity'] = $newCity;
                if ($newState !== '') $sc['clientState'] = $newState;
                if ($newZip !== '') $sc['clientZip'] = $newZip;
                $sc['updatedAt'] = date('c');
            }
        }
        unset($sc);
        saveSavedCards($allCards);

        // Update autopay subscriptions
        $allAP = getAutopays();
        foreach ($allAP as &$ap) {
            if (strtolower(trim($ap['clientName'] ?? '')) === strtolower($oldName)) {
                $ap['clientName'] = $newName;
                if ($newEmail !== '') $ap['clientEmail'] = $newEmail;
                if ($newPhone !== '') $ap['clientPhone'] = $newPhone;
            }
        }
        unset($ap);
        saveAutopays($allAP);

        $changeDetails = [];
        if ($oldName !== $newName) $changeDetails[] = 'Name: ' . $oldName . ' → ' . $newName;
        if ($newEmail !== '') $changeDetails[] = 'Email: ' . $newEmail;
        if ($newPhone !== '') $changeDetails[] = 'Phone: ' . $newPhone;
        if ($newAddress !== '') $changeDetails[] = 'Address: ' . $newAddress;
        if ($newCity !== '') $changeDetails[] = 'City: ' . $newCity;
        if ($newState !== '') $changeDetails[] = 'State: ' . $newState;
        if ($newZip !== '') $changeDetails[] = 'Zip: ' . $newZip;
        addAuditEntry('edit_customer', $oldName, implode(' | ', $changeDetails) ?: 'No changes');
        echo json_encode(['success' => true]);
        exit;
    }

    if ($_GET['action'] === 'delete_customer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        requireTransactionActionPinAuthorization();
        $name = trim($input['name'] ?? '');
        if (!$name) { echo json_encode(['success' => false, 'error' => 'Name required']); exit; }
        $removedCards = 0;
        storageMutateDocument('saved_cards.json', [], function($cards) use ($name, &$removedCards) {
            $cards = is_array($cards) ? $cards : [];
            $remaining = array_values(array_filter($cards, function($card) use ($name) {
                return strcasecmp(trim($card['clientName'] ?? ''), $name) !== 0;
            }));
            $removedCards = count($cards) - count($remaining);
            return $remaining;
        });

        $removedAutopays = 0;
        storageMutateDocument('autopay.json', [], function($autopays) use ($name, &$removedAutopays) {
            $autopays = is_array($autopays) ? $autopays : [];
            $remaining = array_values(array_filter($autopays, function($autopay) use ($name) {
                return strcasecmp(trim($autopay['clientName'] ?? ''), $name) !== 0;
            }));
            $removedAutopays = count($autopays) - count($remaining);
            return $remaining;
        });

        markCustomerDeleted($name);
        addAuditEntry('delete_customer', $name, 'Customer profile removed | Saved cards: ' . $removedCards . ' | Autopays: ' . $removedAutopays . ' | Transaction history preserved');
        echo json_encode(['success' => true, 'removedCards' => $removedCards, 'removedAutopays' => $removedAutopays]);
        exit;
    }

    if ($_GET['action'] === 'add_customer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $name = trim($input['name'] ?? '');
        if (!$name) { echo json_encode(['success' => false, 'error' => 'Name is required']); exit; }
        $email = trim($input['email'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $address = trim($input['address'] ?? '');
        $city = trim($input['city'] ?? '');
        $state = trim($input['state'] ?? '');
        $zip = trim($input['zip'] ?? '');
        // Check if customer already exists in saved_cards
        $cards = getSavedCards();
        foreach ($cards as $c) {
            if (strcasecmp(trim($c['clientName'] ?? ''), $name) === 0) {
                echo json_encode(['success' => false, 'error' => 'Customer "' . $name . '" already exists']);
                exit;
            }
        }
        // Add to saved_cards without card info
        $cards[] = [
            'clientName' => $name,
            'clientEmail' => $email,
            'clientPhone' => $phone,
            'clientAddress' => $address,
            'clientCity' => $city,
            'clientState' => $state,
            'clientZip' => $zip,
            'cardLast4' => '',
            'cardBrand' => '',
            'encryptedCard' => '',
            'createdAt' => date('c'),
        ];
        saveSavedCards($cards);
        restoreDeletedCustomer($name);
        addAuditEntry('add_customer', $name, 'Customer added | ' . $email . ' | ' . $phone);
        echo json_encode(['success' => true]);
        exit;
    }

    // ─── Deposits CRUD (POST) ───────────────────────────────
    if ($_GET['action'] === 'get_deposits') {
        echo json_encode(getDeposits());
        exit;
    }
    if ($_GET['action'] === 'add_deposit') {
        $input = json_decode(file_get_contents('php://input'), true);
        $amount = floatval($input['amount'] ?? 0);
        $date = $input['date'] ?? '';
        $note = $input['note'] ?? '';
        if ($amount <= 0 || !$date) { echo json_encode(['error' => 'Amount and date are required']); exit; }
        $deposits = getDeposits();
        $deposit = ['id' => uniqid('dep_'), 'amount' => $amount, 'date' => $date, 'note' => $note, 'createdAt' => date('Y-m-d H:i:s')];
        $deposits[] = $deposit;
        saveDeposits($deposits);
        addAuditEntry('deposit_added', 'Deposit', '$' . number_format($amount, 2) . ' on ' . $date . ($note ? ' | ' . $note : ''));
        echo json_encode(['success' => true, 'deposit' => $deposit]);
        exit;
    }
    if ($_GET['action'] === 'edit_deposit') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? '';
        $amount = floatval($input['amount'] ?? 0);
        $date = $input['date'] ?? '';
        $note = $input['note'] ?? '';
        if (!$id) { echo json_encode(['error' => 'ID required']); exit; }
        $deposits = getDeposits();
        foreach ($deposits as &$d) {
            if ($d['id'] === $id) {
                if ($amount > 0) $d['amount'] = $amount;
                if ($date) $d['date'] = $date;
                $d['note'] = $note;
                break;
            }
        }
        unset($d);
        saveDeposits($deposits);
        addAuditEntry('deposit_edited', 'Deposit', 'Edited deposit ' . $id . ' | $' . number_format($amount, 2) . ' on ' . $date . ($note ? ' | ' . $note : ''));
        echo json_encode(['success' => true]);
        exit;
    }
    if ($_GET['action'] === 'delete_deposit') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? '';
        if (!$id) { echo json_encode(['error' => 'ID required']); exit; }
        $deposits = getDeposits();
        $delDepAmt = 0; $delDepDate = '';
        foreach ($deposits as $dd) { if ($dd['id'] === $id) { $delDepAmt = $dd['amount'] ?? 0; $delDepDate = $dd['date'] ?? ''; break; } }
        $deposits = array_values(array_filter($deposits, function($d) use ($id) { return $d['id'] !== $id; }));
        saveDeposits($deposits);
        addAuditEntry('deposit_deleted', 'Deposit', 'Deleted deposit | $' . number_format($delDepAmt, 2) . ' on ' . $delDepDate);
        echo json_encode(['success' => true]);
        exit;
    }

    // ─── Payment Links (POST) ───────────────────────────────
    if ($_GET['action'] === 'create_link') {
        $input = json_decode(file_get_contents('php://input'), true);
        $amount = floatval($input['amount'] ?? 0);
        $description = trim($input['description'] ?? 'Payment');
        $clientName = trim($input['clientName'] ?? '');
        $clientEmail = trim($input['clientEmail'] ?? '');
        $autopay = !empty($input['autopay']);
        $singleUse = false; // links are reusable and never expire
        $source = trim($input['source'] ?? 'link');
        if (empty($input['products']) && $amount <= 0) { echo json_encode(['success' => false, 'error' => 'Add at least one product']); exit; }
        $linkId = bin2hex(random_bytes(8));
        $products = $input['products'] ?? [];
        if (!is_array($products) || count($products) === 0) {
            $products = [['name' => $description, 'price' => $amount]];
        }
        $link = [
            'id' => $linkId, 'amount' => $amount, 'description' => $description,
            'products' => $products,
            'clientName' => $clientName, 'clientEmail' => $clientEmail,
            'autopay' => $autopay, 'singleUse' => $singleUse,
            'source' => $source, 'status' => 'active',
            'createdAt' => date('c'), 'url' => 'https://autopay.builtbyjj.dev/?pay=' . $linkId,
        ];
        $links = getPaymentLinks();
        $links[] = $link;
        savePaymentLinks($links);
        addAuditEntry('link_created', $description, 'Payment link created | ' . $link['url']);
        echo json_encode(['success' => true, 'link' => $link]);
        exit;
    }
    if ($_GET['action'] === 'list_links') {
        echo json_encode(getPaymentLinks());
        exit;
    }
    if ($_GET['action'] === 'deactivate_link') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? '';
        $links = getPaymentLinks();
        foreach ($links as &$l) {
            if ($l['id'] === $id) { $l['status'] = 'inactive'; break; }
        }
        unset($l);
        savePaymentLinks($links);
        echo json_encode(['success' => true]);
        exit;
    }

                echo json_encode(['error' => 'Unknown action']);
    exit;
}

// GET endpoints
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    if (!$isAdminAuth) { echo json_encode(['error' => 'Unauthorized']); exit; }
    if ($_GET['action'] === 'transactions') { echo json_encode(getTransactions()); exit; }
    if ($_GET['action'] === 'autopays') { echo json_encode(getAutopays()); exit; }
    if ($_GET['action'] === 'sessions') {
        $sessions = getActiveSessions();
        $currentId = session_id();
        foreach ($sessions as &$s) { $s['isCurrent'] = ($s['sessionId'] === $currentId); }
        unset($s);
        usort($sessions, fn($a, $b) => strtotime($b['lastActive']) - strtotime($a['lastActive']));
        echo json_encode($sessions);
        exit;
    }

    // Return all known customers (from transactions, saved cards, AND autopay)
    if ($_GET['action'] === 'all_customers') {
        $txns = getTransactions();
        $savedCards = getSavedCards();
        $autopays = getAutopays();
        $deletedCustomerMap = array_fill_keys(getDeletedCustomerKeys(), true);
        $savedMap = [];
        foreach ($savedCards as $sc) {
            $key = normalizeCustomerName($sc['clientName'] ?? '');
            if ($key === '' || isset($deletedCustomerMap[customerDeletionKey($sc['clientName'] ?? '')])) continue;
            $savedMap[$key] = $sc;
        }
        $custMap = [];
        foreach ($txns as $t) {
            $name = trim($t['clientName'] ?? '');
            if (!$name) continue;
            $key = normalizeCustomerName($name);
            if (isset($deletedCustomerMap[customerDeletionKey($name)]) || isset($custMap[$key])) continue;
            $sc = $savedMap[$key] ?? null;
            $custMap[$key] = [
                'clientName' => $name,
                'clientEmail' => $t['clientEmail'] ?? '',
                'clientPhone' => $t['clientPhone'] ?? '',
                'clientAddress' => $t['clientAddress'] ?? '',
                'clientCity' => $t['clientCity'] ?? '',
                'clientState' => $t['clientState'] ?? '',
                'clientZip' => $t['clientZip'] ?? '',
                'hasSavedCard' => $sc !== null,
                'cardLast4' => $sc['cardLast4'] ?? '',
                'cardBrand' => $sc['cardBrand'] ?? '',
            ];
        }
        // Also add saved card customers not in transactions
        foreach ($savedCards as $sc) {
            $key = normalizeCustomerName($sc['clientName'] ?? '');
            if (!$key || isset($deletedCustomerMap[customerDeletionKey($sc['clientName'] ?? '')]) || isset($custMap[$key])) continue;
            $custMap[$key] = [
                'clientName' => trim($sc['clientName'] ?? ''),
                'clientEmail' => $sc['clientEmail'] ?? '',
                'clientPhone' => $sc['clientPhone'] ?? '',
                'clientAddress' => $sc['clientAddress'] ?? '',
                'clientCity' => $sc['clientCity'] ?? '',
                'clientState' => $sc['clientState'] ?? '',
                'clientZip' => $sc['clientZip'] ?? '',
                'hasSavedCard' => true,
                'cardLast4' => $sc['cardLast4'] ?? '',
                'cardBrand' => $sc['cardBrand'] ?? '',
            ];
        }
        // Also add autopay-only customers not yet in the list
        foreach ($autopays as $ap) {
            if (($ap['status'] ?? '') === 'cancelled') continue;
            $name = trim($ap['clientName'] ?? '');
            if (!$name) continue;
            $key = normalizeCustomerName($name);
            if (isset($deletedCustomerMap[customerDeletionKey($name)]) || isset($custMap[$key])) continue;
            $custMap[$key] = [
                'clientName' => $name,
                'clientEmail' => $ap['clientEmail'] ?? '',
                'clientPhone' => $ap['clientPhone'] ?? '',
                'clientAddress' => $ap['clientAddress'] ?? '',
                'clientCity' => $ap['clientCity'] ?? '',
                'clientState' => $ap['clientState'] ?? '',
                'clientZip' => $ap['clientZip'] ?? '',
                'hasSavedCard' => !empty($ap['encryptedCard']),
                'cardLast4' => $ap['cardLast4'] ?? '',
                'cardBrand' => $ap['cardBrand'] ?? '',
            ];
        }
        echo json_encode(array_values($custMap));
        exit;
    }

    // Return saved customers with masked card info (no raw card data)
    if ($_GET['action'] === 'saved_customers') {
        $all = getSavedCards();
        $out = [];
        foreach ($all as $c) {
            $out[] = [
                'clientName' => $c['clientName'] ?? '',
                'clientEmail' => $c['clientEmail'] ?? '',
                'clientPhone' => $c['clientPhone'] ?? '',
                'clientAddress' => $c['clientAddress'] ?? '',
                'clientCity' => $c['clientCity'] ?? '',
                'clientState' => $c['clientState'] ?? '',
                'clientZip' => $c['clientZip'] ?? '',
                'cardLast4' => $c['cardLast4'] ?? '',
                'cardBrand' => $c['cardBrand'] ?? 'Card',
            ];
        }
        echo json_encode($out);
        exit;
    }

    // Return decrypted card for a specific customer
    if ($_GET['action'] === 'get_saved_card') {
        $name = $_GET['name'] ?? '';
        if (!$name) { echo json_encode(['error' => 'Name required']); exit; }
        $all = getSavedCards();
        foreach ($all as $c) {
            if (strtolower($c['clientName'] ?? '') === strtolower($name)) {
                $card = decryptCard($c['encryptedCard'] ?? '');
                if ($card) {
                    echo json_encode([
                        'clientEmail' => $c['clientEmail'] ?? '',
                        'clientPhone' => $c['clientPhone'] ?? '',
                        'clientAddress' => $c['clientAddress'] ?? '',
                        'clientCity' => $c['clientCity'] ?? '',
                        'clientState' => $c['clientState'] ?? '',
                        'clientZip' => $c['clientZip'] ?? '',
                        'cardNumber' => $card['number'] ?? '',
                        'expMonth' => $card['exp_month'] ?? '',
                        'expYear' => $card['exp_year'] ?? '',
                        'cvc' => $card['cvc'] ?? '',
                        'cardLast4' => $c['cardLast4'] ?? '',
                        'cardBrand' => $c['cardBrand'] ?? 'Card',
                    ]);
                } else {
                    echo json_encode(['error' => 'Could not decrypt card']);
                }
                exit;
            }
        }
        // Fallback: check autopay records for encrypted card
        $autopays = getAutopays();
        foreach ($autopays as $ap) {
            if (strtolower(trim($ap['clientName'] ?? '')) === strtolower($name) && !empty($ap['encryptedCard'])) {
                $card = decryptCard($ap['encryptedCard']);
                if ($card) {
                    echo json_encode([
                        'clientEmail' => $ap['clientEmail'] ?? '',
                        'clientPhone' => $ap['clientPhone'] ?? '',
                        'clientAddress' => $ap['clientAddress'] ?? '',
                        'clientCity' => $ap['clientCity'] ?? '',
                        'clientState' => $ap['clientState'] ?? '',
                        'clientZip' => $ap['clientZip'] ?? '',
                        'cardNumber' => $card['number'] ?? '',
                        'expMonth' => $card['exp_month'] ?? '',
                        'expYear' => $card['exp_year'] ?? '',
                        'cvc' => $card['cvc'] ?? '',
                        'cardLast4' => $ap['cardLast4'] ?? '',
                        'cardBrand' => $ap['cardBrand'] ?? 'Card',
                    ]);
                    exit;
                }
            }
        }
        echo json_encode(['error' => 'Customer not found']);
        exit;
    }

    // ─── Get Audit Log ──────────────────────────────────────
    if ($_GET['action'] === 'audit_log') {
        echo json_encode(getAuditLog());
        exit;
    }

    // ─── Deposits CRUD ──────────────────────────────────────
    if ($_GET['action'] === 'get_deposits') {
        echo json_encode(getDeposits());
        exit;
    }

    if ($_GET['action'] === 'add_deposit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $amount = floatval($input['amount'] ?? 0);
        $date = $input['date'] ?? '';
        $note = $input['note'] ?? '';
        if ($amount <= 0 || !$date) {
            echo json_encode(['error' => 'Amount and date are required']);
            exit;
        }
        $deposits = getDeposits();
        $deposit = [
            'id' => uniqid('dep_'),
            'amount' => $amount,
            'date' => $date,
            'note' => $note,
            'createdAt' => date('Y-m-d H:i:s'),
        ];
        $deposits[] = $deposit;
        saveDeposits($deposits);
        addAuditEntry('deposit_added', 'Deposit', '$' . number_format($amount, 2) . ' on ' . $date . ($note ? ' | ' . $note : ''));
        echo json_encode(['success' => true, 'deposit' => $deposit]);
        exit;
    }

    if ($_GET['action'] === 'edit_deposit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? '';
        $amount = floatval($input['amount'] ?? 0);
        $date = $input['date'] ?? '';
        $note = $input['note'] ?? '';
        if (!$id) { echo json_encode(['error' => 'ID required']); exit; }
        $deposits = getDeposits();
        foreach ($deposits as &$d) {
            if ($d['id'] === $id) {
                if ($amount > 0) $d['amount'] = $amount;
                if ($date) $d['date'] = $date;
                $d['note'] = $note;
                break;
            }
        }
        unset($d);
        saveDeposits($deposits);
        addAuditEntry('deposit_edited', 'Deposit', 'Edited deposit ' . $id . ' | $' . number_format($amount, 2) . ' on ' . $date . ($note ? ' | ' . $note : ''));
        echo json_encode(['success' => true]);
        exit;
    }

    if ($_GET['action'] === 'delete_deposit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? '';
        if (!$id) { echo json_encode(['error' => 'ID required']); exit; }
        $deposits = getDeposits();
        // Find the deposit before deleting for audit
        $delDepAmt = 0; $delDepDate = '';
        foreach ($deposits as $dd) { if ($dd['id'] === $id) { $delDepAmt = $dd['amount'] ?? 0; $delDepDate = $dd['date'] ?? ''; break; } }
        $deposits = array_values(array_filter($deposits, function($d) use ($id) { return $d['id'] !== $id; }));
        saveDeposits($deposits);
        addAuditEntry('deposit_deleted', 'Deposit', 'Deleted deposit | $' . number_format($delDepAmt, 2) . ' on ' . $delDepDate);
        echo json_encode(['success' => true]);
        exit;
    }


    // ─── Payment Links: Create ───
    if ($_GET['action'] === 'create_link') {
        $input = json_decode(file_get_contents('php://input'), true);
        $amount = floatval($input['amount'] ?? 0);
        $description = trim($input['description'] ?? 'Payment');
        $clientName = trim($input['clientName'] ?? '');
        $clientEmail = trim($input['clientEmail'] ?? '');
        $autopay = !empty($input['autopay']);
        $singleUse = false; // links are reusable and never expire
        $source = trim($input['source'] ?? 'link');
        // Amount comes from products; validate at least one product
        if (empty($input['products']) && $amount <= 0) { echo json_encode(['success' => false, 'error' => 'Add at least one product']); exit; }
        $linkId = bin2hex(random_bytes(8));
        $products = $input['products'] ?? [];
        if (!is_array($products) || count($products) === 0) {
            $products = [['name' => $description, 'price' => $amount]];
        }
        $link = [
            'id' => $linkId, 'amount' => $amount, 'description' => $description,
            'products' => $products,
            'clientName' => $clientName, 'clientEmail' => $clientEmail,
            'autopay' => $autopay, 'singleUse' => $singleUse,
            'source' => $source, 'status' => 'active',
            'createdAt' => date('c'), 'url' => 'https://autopay.builtbyjj.dev/?pay=' . $linkId,
        ];
        $links = getPaymentLinks();
        $links[] = $link;
        savePaymentLinks($links);
        echo json_encode(['success' => true, 'link' => $link]);
        exit;
    }

    // ─── Payment Links: List ───
    if ($_GET['action'] === 'list_links') {
        echo json_encode(getPaymentLinks());
        exit;
    }

    // ─── Payment Links: Deactivate ───
    if ($_GET['action'] === 'deactivate_link') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? '';
        $links = getPaymentLinks();
        foreach ($links as &$l) {
            if ($l['id'] === $id) { $l['status'] = 'inactive'; break; }
        }
        savePaymentLinks($links);
        echo json_encode(['success' => true]);
        exit;
    }
}

// CSV export (GET, outside POST block)
if (isset($_GET['action']) && $_GET['action'] === 'export_csv' && $isAdminAuth) {
    $type = $_GET['type'] ?? 'transactions';
    if ($type === 'transactions') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="transactions_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Date', 'Transaction ID', 'Client', 'Phone', 'Email', 'Description', 'Card', 'Amount', 'Type', 'Status']);
        $txns = getTransactions();
        usort($txns, function($a, $b) { return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''); });
        foreach ($txns as $t) {
            fputcsv($out, [
                date('Y-m-d H:i', strtotime($t['timestamp'] ?? 'now')),
                $t['id'] ?? '',
                $t['clientName'] ?? 'Walk-in',
                formatPhone($t['clientPhone'] ?? ''),
                $t['clientEmail'] ?? '',
                $t['description'] ?? '',
                ($t['cardBrand'] ?? '') . ' ****' . ($t['cardLast4'] ?? ''),
                number_format($t['amount'] ?? 0, 2, '.', ''),
                getTransactionTypeLabel($t),
                $t['status'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }
    if ($type === 'customers') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="customers_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Customer', 'Email', 'Phone', 'Address', 'City', 'State', 'Zip', 'Total Charges', 'Total Amount', 'Autopay Status']);
        // Build customer list same as the main page
        $txns = getTransactions();
        $autopays = getAutopays();
        $savedCards = getSavedCards();
        $custMap = [];
        foreach ($txns as $t) {
            $nm = trim($t['clientName'] ?? '');
            if (!$nm) continue;
            if (!isset($custMap[$nm])) $custMap[$nm] = ['name'=>$nm,'email'=>'','phone'=>'','address'=>'','city'=>'','state'=>'','zip'=>'','count'=>0,'total'=>0,'apStatus'=>'none'];
            $custMap[$nm]['count']++;
            $custMap[$nm]['total'] += floatval($t['amount'] ?? 0);
            if (empty($custMap[$nm]['email']) && !empty($t['clientEmail'])) $custMap[$nm]['email'] = $t['clientEmail'];
            if (empty($custMap[$nm]['phone']) && !empty($t['clientPhone'])) $custMap[$nm]['phone'] = $t['clientPhone'];
            if (empty($custMap[$nm]['address']) && !empty($t['clientAddress'])) $custMap[$nm]['address'] = $t['clientAddress'];
            if (empty($custMap[$nm]['city']) && !empty($t['clientCity'])) $custMap[$nm]['city'] = $t['clientCity'];
            if (empty($custMap[$nm]['state']) && !empty($t['clientState'])) $custMap[$nm]['state'] = $t['clientState'];
            if (empty($custMap[$nm]['zip']) && !empty($t['clientZip'])) $custMap[$nm]['zip'] = $t['clientZip'];
        }
        foreach ($autopays as $a) {
            $nm = trim($a['clientName'] ?? '');
            if (!$nm) continue;
            if (!isset($custMap[$nm])) $custMap[$nm] = ['name'=>$nm,'email'=>'','phone'=>'','address'=>'','city'=>'','state'=>'','zip'=>'','count'=>0,'total'=>0,'apStatus'=>'none'];
            if ($a['status'] === 'active') $custMap[$nm]['apStatus'] = 'Auto';
            elseif ($a['status'] === 'paused' && $custMap[$nm]['apStatus'] !== 'Auto') $custMap[$nm]['apStatus'] = 'Paused';
            elseif ($a['status'] === 'failed' && !in_array($custMap[$nm]['apStatus'], ['Auto','Paused'])) $custMap[$nm]['apStatus'] = 'Failed';
        }
        foreach ($savedCards as $sc) {
            $nm = trim($sc['clientName'] ?? '');
            if (!$nm || !isset($custMap[$nm])) continue;
            if (empty($custMap[$nm]['email']) && !empty($sc['clientEmail'])) $custMap[$nm]['email'] = $sc['clientEmail'];
            if (empty($custMap[$nm]['phone']) && !empty($sc['clientPhone'])) $custMap[$nm]['phone'] = $sc['clientPhone'];
        }
        foreach ($custMap as $c) {
            fputcsv($out, [
                $c['name'],
                $c['email'],
                formatPhone($c['phone']),
                $c['address'], $c['city'], $c['state'], $c['zip'],
                $c['count'],
                number_format($c['total'], 2, '.', ''),
                $c['apStatus'] === 'none' ? '' : $c['apStatus'],
            ]);
        }
        fclose($out);
        exit;
    }
}

// ─── If not admin-authed, show login page ───────────────────
if (!$isAdminAuth) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authorize.net Terminal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; background: #f0f2f5; color: #1a1a2e; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-box { width: 100%; max-width: 420px; padding: 20px; }
        .login-box .logo { text-align: center; margin-bottom: 36px; }
        .login-box .logo h1 { font-size: 26px; font-weight: 700; color: #1a1a2e; letter-spacing: -0.3px; }
        .login-box .logo p { color: #6b7280; font-size: 14px; margin-top: 6px; font-weight: 400; }
        .login-card { background: #ffffff; border: 1px solid #e2e5ea; border-radius: 14px; padding: 32px; box-shadow: 0 4px 24px rgba(0,0,0,.08); }
        .login-card h2 { font-size: 17px; font-weight: 600; color: #1a1a2e; margin-bottom: 24px; }
        .fg { margin-bottom: 18px; }
        .fg label { display: block; font-size: 13px; font-weight: 500; color: #4b5563; margin-bottom: 6px; }
        .fg input { width: 100%; padding: 12px 14px; background: #f8f9fb; border: 1px solid #d1d5db; border-radius: 10px; color: #1a1a2e; font-size: 14px; outline: none; transition: border-color .2s, box-shadow .2s; font-family: inherit; }
        .fg input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
        .fg input::placeholder { color: #9ca3af; }
        .btn { width: 100%; padding: 13px; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; background: #2563eb; color: #fff; margin-top: 8px; transition: background .2s, box-shadow .2s; font-family: inherit; }
        .btn:hover { background: #1d4ed8; box-shadow: 0 4px 16px rgba(37,99,235,.25); }
        .btn:disabled { opacity: .45; cursor: not-allowed; box-shadow: none; }
        .err { margin-top: 14px; padding: 11px 14px; border-radius: 10px; font-size: 13px; background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; display: none; }
        .spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid rgba(255,255,255,.2); border-top-color: #fff; border-radius: 50%; animation: spin .6s linear infinite; margin-right: 6px; vertical-align: middle; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
<div class="login-box">
    <div class="logo">
        <h1>Authorize.net Terminal</h1>
        <p>Secure Payment Gateway</p>
    </div>
    <div class="login-card">
        <h2>Sign In</h2>
        <div class="fg">
            <label>Username</label>
            <input type="text" id="adminUser" placeholder="Username" autofocus>
        </div>
        <div class="fg">
            <label>Password</label>
            <input type="password" id="adminPass" placeholder="Password">
        </div>
        <button class="btn" id="loginBtn" onclick="doLogin()">Sign In</button>
        <div class="err" id="loginErr"></div>
    </div>
</div>
<script>
document.getElementById('adminPass').addEventListener('keypress', e => { if (e.key === 'Enter') doLogin(); });
document.getElementById('adminUser').addEventListener('keypress', e => { if (e.key === 'Enter') document.getElementById('adminPass').focus(); });
async function doLogin() {
    const u = document.getElementById('adminUser').value.trim();
    const p = document.getElementById('adminPass').value;
    const err = document.getElementById('loginErr');
    const btn = document.getElementById('loginBtn');
    if (!u || !p) { err.style.display = 'block'; err.textContent = 'Enter username and password'; return; }
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Signing in...';
    try {
        const res = await fetch('?action=admin_login', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({username: u, password: p})
        });
        const data = await res.json();
        if (data.success) { location.reload(); }
        else { err.style.display = 'block'; err.textContent = data.error || 'Invalid credentials'; btn.disabled = false; btn.textContent = 'Sign In'; }
    } catch(e) { err.style.display = 'block'; err.textContent = 'Connection error'; btn.disabled = false; btn.textContent = 'Sign In'; }
}
</script>
</body>
</html>
<?php
    exit;
}

// ─── MAIN APP (admin authenticated) ─────────────────────────
$savedCreds = getSavedCreds();
$hasSetup = ($savedCreds !== null);
$currentToken = getToken();
$allTxns = getTransactions();
$allAutopays = getAutopays();
$allDeposits = getDeposits();
$allDisputes = getDisputes();
$deletedCustomerMap = array_fill_keys(getDeletedCustomerKeys(), true);

// Sort deposits by date descending
usort($allDeposits, function($a, $b) { return strcmp($b['date'] ?? '', $a['date'] ?? ''); });
// Sort disputes by date descending
usort($allDisputes, function($a, $b) { return strcmp($b['date'] ?? '', $a['date'] ?? ''); });

// Compute deposit stats
$totalDeposits = 0;
$depositCount = count($allDeposits);
foreach ($allDeposits as $dep) { $totalDeposits += floatval($dep['amount'] ?? 0); }

// Compute stats
$now = new DateTime('now', new DateTimeZone('America/New_York'));
$todayStr = $now->format('Y-m-d');
$weekStart = (clone $now)->modify('monday this week')->format('Y-m-d');
$monthStr = $now->format('Y-m');

$todayTotal = 0; $todayCount = 0;
$weekTotal = 0; $weekCount = 0;
$monthTotal = 0; $monthCount = 0;
$allTotal = 0; $allCount = 0;
$approvedCount = 0; $declinedCount = 0;
$customers = [];

foreach ($allTxns as $t) {
    $tDate = substr($t['timestamp'] ?? '', 0, 10);
    $tMonth = substr($t['timestamp'] ?? '', 0, 7);
    $amt = floatval($t['amount'] ?? 0);
    $isApproved = ($t['status'] ?? '') === 'approved';
    if ($isApproved) {
        $approvedCount++; $allTotal += $amt; $allCount++;
        if ($tDate === $todayStr) { $todayTotal += $amt; $todayCount++; }
        if ($tDate >= $weekStart) { $weekTotal += $amt; $weekCount++; }
        if ($tMonth === $monthStr) { $monthTotal += $amt; $monthCount++; }
    } else { $declinedCount++; }
    $cName = trim($t['clientName'] ?? '') ?: 'Walk-in';
    if (isset($deletedCustomerMap[customerDeletionKey($cName)])) continue;
    if (!isset($customers[$cName])) $customers[$cName] = ['name' => $cName, 'email' => '', 'phone' => '', 'address' => '', 'city' => '', 'state' => '', 'zip' => '', 'total' => 0, 'count' => 0, 'lastCharge' => '', 'cardLast4' => '', 'cardBrand' => ''];
    if ($isApproved) { $customers[$cName]['total'] += $amt; $customers[$cName]['count']++; }
    $customers[$cName]['lastCharge'] = $t['timestamp'] ?? '';
    if (!empty($t['clientEmail'])) $customers[$cName]['email'] = $t['clientEmail'];
    if (!empty($t['clientPhone'])) $customers[$cName]['phone'] = $t['clientPhone'];
    if (!empty($t['clientAddress'])) $customers[$cName]['address'] = $t['clientAddress'];
    if (!empty($t['clientCity'])) $customers[$cName]['city'] = $t['clientCity'];
    if (!empty($t['clientState'])) $customers[$cName]['state'] = $t['clientState'];
    if (!empty($t['clientZip'])) $customers[$cName]['zip'] = $t['clientZip'];
    if (!empty($t['cardLast4'])) { $customers[$cName]['cardLast4'] = $t['cardLast4']; $customers[$cName]['cardBrand'] = $t['cardBrand'] ?? 'Card'; }
}
$avgCharge = $allCount > 0 ? $allTotal / $allCount : 0;

// Compute pending balance & fees
// Historical revenue baseline: $2,693.63 as of Jul 7, 2026
// This accounts for revenue processed before the tracking system was set up
$historicalBaseline = 2693.63;
$baselineRevenue = 1504.70;  // allTotal at time of baseline
$baselineDeposits = 5428.46; // totalDeposits at time of baseline
$baselineFees = 1504.70 * 0.029 + 88 * 1.23; // fees at time of baseline
$newRevenue = max(0, $allTotal - $baselineRevenue);
$newDeposits = max(0, $totalDeposits - $baselineDeposits);
$feePercent = 0.029; // 2.9%
$feePerTxn = 1.23;  // $1.23 per transaction
$totalPercentFee = $allTotal * $feePercent;
$totalTxnFee = $approvedCount * $feePerTxn;
$totalFees = $totalPercentFee + $totalTxnFee;
$newFees = max(0, $totalFees - $baselineFees);
$netPending = $historicalBaseline + $newRevenue - $newDeposits - $newFees;

// Link autopay subscriptions to customers (and add autopay-only customers)
foreach ($allAutopays as $ap) {
    if (($ap['status'] ?? '') === 'cancelled') continue;
    $apName = trim($ap['clientName'] ?? '');
    if (!$apName || isset($deletedCustomerMap[customerDeletionKey($apName)])) continue;
    if (!isset($customers[$apName])) {
        $customers[$apName] = ['name' => $apName, 'email' => $ap['clientEmail'] ?? '', 'phone' => $ap['clientPhone'] ?? '', 'address' => $ap['clientAddress'] ?? '', 'city' => $ap['clientCity'] ?? '', 'state' => $ap['clientState'] ?? '', 'zip' => $ap['clientZip'] ?? '', 'total' => 0, 'count' => 0, 'lastCharge' => '', 'cardLast4' => $ap['cardLast4'] ?? '', 'cardBrand' => $ap['cardBrand'] ?? ''];
    }
    if (!isset($customers[$apName]['autopays'])) $customers[$apName]['autopays'] = [];
    $customers[$apName]['autopays'][] = $ap;
    if (!empty($ap['cardLast4']) && empty($customers[$apName]['cardLast4'])) { $customers[$apName]['cardLast4'] = $ap['cardLast4']; $customers[$apName]['cardBrand'] = $ap['cardBrand'] ?? 'Card'; }
}

usort($customers, function($a, $b) { return $b['total'] <=> $a['total']; });
$customers = array_values($customers);

// Autopay stats
$activeAP = 0; $failedAP = 0; $pausedAP = 0; $apRevenue = 0;
$upcomingAP = [];
foreach ($allAutopays as $ap) {
    if ($ap['status'] === 'active') { $activeAP++; $upcomingAP[] = $ap; }
    if ($ap['status'] === 'failed') $failedAP++;
    if ($ap['status'] === 'paused') $pausedAP++;
    foreach ($ap['history'] ?? [] as $h) {
        if (($h['status'] ?? '') === 'approved') $apRevenue += floatval($h['amount'] ?? 0);
    }
}
usort($upcomingAP, function($a, $b) { return ($a['nextCharge'] ?? '') <=> ($b['nextCharge'] ?? ''); });

// Build autopay calendar: project all active autopays across the next 60 days
$apCalendar = [];
$monthlyForecast = 0;
$todayDt = date('Y-m-d');
$endDt = date('Y-m-d', strtotime('+60 days'));
foreach ($allAutopays as $ap) {
    if ($ap['status'] !== 'active') continue;
    $amt = floatval($ap['amount'] ?? 0);
    $freq = $ap['frequency'] ?? 'monthly';
    // Calculate monthly forecast (annualize then /12)
    if ($freq === 'weekly') $monthlyForecast += $amt * 4.33;
    elseif ($freq === 'biweekly') $monthlyForecast += $amt * 2.17;
    elseif ($freq === 'quarterly') $monthlyForecast += $amt / 3;
    else $monthlyForecast += $amt; // monthly

    // Project charges across the 60-day window
    $dt = $ap['nextCharge'] ?? '';
    if (!$dt || $dt > $endDt) { if ($dt && $dt <= $endDt) {} else { /* still add the first one if within range */ if ($dt && $dt >= $todayDt) { if (!isset($apCalendar[$dt])) $apCalendar[$dt] = ['date' => $dt, 'customers' => [], 'total' => 0, 'count' => 0]; $apCalendar[$dt]['customers'][] = $ap; $apCalendar[$dt]['total'] += $amt; $apCalendar[$dt]['count']++; } continue; } }
    $current = $dt;
    while ($current <= $endDt) {
        if ($current >= $todayDt) {
            if (!isset($apCalendar[$current])) $apCalendar[$current] = ['date' => $current, 'customers' => [], 'total' => 0, 'count' => 0];
            $apCalendar[$current]['customers'][] = $ap;
            $apCalendar[$current]['total'] += $amt;
            $apCalendar[$current]['count']++;
        }
        // Advance to next charge date
        if ($freq === 'weekly') $current = date('Y-m-d', strtotime($current . ' +7 days'));
        elseif ($freq === 'biweekly') $current = date('Y-m-d', strtotime($current . ' +14 days'));
        elseif ($freq === 'quarterly') $current = date('Y-m-d', strtotime($current . ' +3 months'));
        else $current = date('Y-m-d', strtotime($current . ' +1 month'));
    }
}
ksort($apCalendar);
$apCalendar = array_values($apCalendar);
$totalScheduled60 = array_sum(array_column($apCalendar, 'total'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authorize.net Terminal</title>
    <script>window._pci=true;</script>
    <!-- Authorize.net Secure Tokenization -->
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; background: #f0f2f5; color: #1a1a2e; min-height: 100vh; -webkit-font-smoothing: antialiased; }

        /* Layout */
        .app { max-width: 1060px; margin: 0 auto; padding: 28px 24px; }

        /* Top Bar */
        .top-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; flex-wrap: wrap; gap: 12px; background: #ffffff; padding: 18px 24px; border-radius: 14px; border: 1px solid #e2e5ea; box-shadow: 0 1px 4px rgba(0,0,0,.04); }
        .top-bar h1 { font-size: 22px; font-weight: 700; color: #1a1a2e; letter-spacing: -0.3px; }
        .top-bar .subtitle { color: #6b7280; font-size: 13px; font-weight: 400; }
        .top-right { display: flex; align-items: center; gap: 14px; }
        .auth-badge { display: inline-flex; align-items: center; gap: 7px; padding: 5px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .auth-badge.connected { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
        .auth-badge.disconnected { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .auth-badge.connecting { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
        .auth-badge .dot { width: 7px; height: 7px; border-radius: 50%; }
        .auth-badge.connected .dot { background: #10b981; box-shadow: 0 0 6px rgba(16,185,129,.5); }
        .auth-badge.disconnected .dot { background: #ef4444; }
        .auth-badge.connecting .dot { background: #3b82f6; }
        .logout-link { color: #6b7280; font-size: 12px; cursor: pointer; text-decoration: none; border-bottom: 1px dashed #9ca3af; transition: color .2s; }
        .logout-link:hover { color: #1a1a2e; border-color: #1a1a2e; }

        /* Tabs */
        .tabs { display: flex; gap: 4px; margin-bottom: 24px; background: #ffffff; border-radius: 12px; padding: 4px; border: 1px solid #e2e5ea; box-shadow: 0 1px 3px rgba(0,0,0,.03); overflow-x: auto; }
        .tab { padding: 10px 20px; font-size: 13px; font-weight: 500; color: #6b7280; cursor: pointer; border: none; background: none; border-radius: 8px; transition: all .2s; white-space: nowrap; font-family: inherit; }
        .tab:hover { color: #1a1a2e; background: #f3f4f6; }
        .tab.active { color: #ffffff; background: #2563eb; font-weight: 600; box-shadow: 0 2px 8px rgba(37,99,235,.25); }

        /* Stat Cards */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; margin-bottom: 24px; }
        .stat-card { background: #ffffff; border: 1px solid #e2e5ea; border-radius: 12px; padding: 18px; transition: box-shadow .2s, border-color .2s; }
        .stat-card:hover { border-color: #c7cad1; box-shadow: 0 4px 12px rgba(0,0,0,.06); }
        .stat-card .stat-label { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 6px; font-weight: 600; }
        .stat-card .stat-value { font-size: 26px; font-weight: 700; color: #1a1a2e; }
        .stat-card .stat-sub { font-size: 11px; color: #9ca3af; margin-top: 4px; }
        .stat-card.green .stat-value { color: #059669; }
        .stat-card.blue .stat-value { color: #2563eb; }
        .stat-card.amber .stat-value { color: #d97706; }
        .stat-card.red .stat-value { color: #dc2626; }
        .stat-card.purple .stat-value { color: #7c3aed; }

        /* Cards / Panels */
        .card { background: #ffffff; border: 1px solid #e2e5ea; border-radius: 14px; padding: 26px; margin-bottom: 18px; box-shadow: 0 1px 3px rgba(0,0,0,.03); }
        .card h2 { font-size: 12px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .07em; margin-bottom: 18px; }

        /* Form Elements */
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
        .form-group input, .form-group select { width: 100%; padding: 11px 14px; background: #f8f9fb; border: 1px solid #d1d5db; border-radius: 10px; color: #1a1a2e; font-size: 14px; outline: none; transition: border-color .2s, box-shadow .2s; font-family: inherit; }
        .form-group input:focus, .form-group select:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.1); background: #ffffff; }
        .form-group input::placeholder { color: #9ca3af; }
        .form-row { display: flex; gap: 14px; }
        .form-row .form-group { flex: 1; }

        /* Amount Input */
        .amount-wrap { position: relative; }
        .amount-wrap .dollar { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #6b7280; font-size: 22px; font-weight: 700; }
        .amount-wrap input { padding-left: 32px; font-size: 28px; font-weight: 700; color: #1a1a2e; }

        /* Card Element */
        #card-element { padding: 13px 14px; background: #f8f9fb; border: 1px solid #d1d5db; border-radius: 10px; transition: border-color .2s, box-shadow .2s; }
        #card-element:focus-within { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.1); }

        /* Buttons */
        .btn { width: 100%; padding: 13px; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all .2s; font-family: inherit; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-primary:hover { background: #1d4ed8; box-shadow: 0 4px 16px rgba(37,99,235,.25); }
        .btn-primary:disabled { opacity: .4; cursor: not-allowed; box-shadow: none; }
        .btn-green { background: #059669; color: #fff; font-weight: 700; }
        .btn-green:hover { background: #047857; box-shadow: 0 4px 16px rgba(5,150,105,.25); }
        .btn-sm { width: auto; padding: 6px 14px; font-size: 12px; border-radius: 8px; display: inline-block; }
        .btn-outline { background: transparent; border: 1px solid #d1d5db; color: #4b5563; }
        .btn-outline:hover { border-color: #2563eb; color: #2563eb; background: rgba(37,99,235,.04); }
        .btn-danger { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; }
        .btn-danger:hover { background: #fee2e2; }

        /* Status Messages */
        .status { margin-top: 14px; padding: 12px 16px; border-radius: 10px; font-size: 13px; display: none; font-weight: 500; }
        .status.success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #059669; display: block; }
        .status.error { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; display: block; }
        .status.loading { background: #eff6ff; border: 1px solid #bfdbfe; color: #2563eb; display: block; }
        .spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid rgba(37,99,235,.2); border-top-color: #2563eb; border-radius: 50%; animation: spin .6s linear infinite; margin-right: 6px; vertical-align: middle; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Receipt */
        .receipt { display: none; text-align: center; padding: 28px; }
        .receipt .checkmark { width: 60px; height: 60px; border-radius: 50%; background: #ecfdf5; display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; font-size: 30px; color: #059669; }
        .receipt h3 { font-size: 20px; color: #1a1a2e; margin-bottom: 6px; font-weight: 600; }
        .receipt .receipt-amount { font-size: 34px; font-weight: 700; color: #059669; margin-bottom: 14px; }
        .receipt-details { text-align: left; background: #f8f9fb; border-radius: 10px; padding: 16px; margin-top: 14px; border: 1px solid #e2e5ea; }
        .receipt-details .row { display: flex; justify-content: space-between; padding: 6px 0; font-size: 13px; }
        .receipt-details .row .label { color: #6b7280; }
        .receipt-details .row .value { color: #1a1a2e; font-weight: 500; }

        /* Tables */
        .table-wrap { overflow-x: auto; border-radius: 10px; border: 1px solid #e2e5ea; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        thead th { text-align: left; padding: 10px 12px; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; font-size: 11px; border-bottom: 1px solid #e2e5ea; background: #f8f9fb; }
        tbody td { padding: 11px 12px; border-bottom: 1px solid #f0f1f3; color: #374151; }
        tbody tr:hover { background: #f8f9fb; }
        tbody tr:last-child td { border-bottom: none; }

        /* Badges */
        .badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; letter-spacing: .02em; }
        .badge-approved { background: #ecfdf5; color: #059669; }
        .badge-declined { background: #fef2f2; color: #dc2626; }
        .badge-refunded { background: #fefce8; color: #d97706; }
        .badge-active { background: #eff6ff; color: #2563eb; }
        .badge-paused { background: #fffbeb; color: #d97706; }
        .badge-failed { background: #fef2f2; color: #dc2626; }
        .badge-cancelled { background: #f3f4f6; color: #6b7280; }
        .badge-autopay { background: #f5f3ff; color: #7c3aed; }

        /* Toolbar */
        .toolbar { display: flex; gap: 12px; margin-bottom: 18px; flex-wrap: wrap; align-items: center; }
        .toolbar input { flex: 1; min-width: 180px; padding: 10px 14px; background: #ffffff; border: 1px solid #d1d5db; border-radius: 10px; color: #1a1a2e; font-size: 13px; outline: none; font-family: inherit; transition: border-color .2s; }
        .toolbar input:focus { border-color: #2563eb; }
        .toolbar input::placeholder { color: #9ca3af; }
        .toolbar select { padding: 10px 14px; background: #ffffff; border: 1px solid #d1d5db; border-radius: 10px; color: #374151; font-size: 13px; outline: none; font-family: inherit; cursor: pointer; }

        /* Customer Cards */
        .customer-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }
        .customer-card { background: #ffffff; border: 1px solid #e2e5ea; border-radius: 12px; padding: 18px; transition: box-shadow .2s, border-color .2s; }
        .customer-card:hover { border-color: #c7cad1; box-shadow: 0 4px 12px rgba(0,0,0,.06); }
        .customer-card .cname { font-size: 15px; font-weight: 600; color: #1a1a2e; margin-bottom: 4px; }
        .customer-card .cmeta { font-size: 12px; color: #6b7280; }
        .customer-card .cstats { display: flex; gap: 20px; margin-top: 12px; }
        .customer-card .cstats .cs { text-align: center; }
        .customer-card .cstats .cs .csv { font-size: 18px; font-weight: 700; color: #059669; }
        .customer-card .cstats .cs .csl { font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; margin-top: 2px; }

        /* Panels */
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }
        .empty { text-align: center; padding: 48px 24px; color: #9ca3af; }
        .empty .icon { font-size: 44px; margin-bottom: 12px; opacity: .4; }
        .empty p { font-size: 14px; color: #6b7280; }

        /* Autopay Subscription Cards */
        .ap-card { background: #ffffff; border: 1px solid #e2e5ea; border-radius: 12px; padding: 18px; margin-bottom: 14px; transition: box-shadow .2s, border-color .2s; }
        .ap-card:hover { border-color: #c7cad1; box-shadow: 0 4px 12px rgba(0,0,0,.06); }
        .ap-card .ap-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
        .ap-card .ap-client { font-size: 16px; font-weight: 600; color: #1a1a2e; }
        .ap-card .ap-amount { font-size: 22px; font-weight: 700; color: #059669; }
        .ap-card .ap-meta { font-size: 12px; color: #6b7280; margin-bottom: 10px; }
        .ap-card .ap-meta span { margin-right: 18px; }
        .ap-card .ap-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .ap-history { margin-top: 12px; border-top: 1px solid #e2e5ea; padding-top: 12px; }
        .ap-history-item { display: flex; justify-content: space-between; font-size: 12px; padding: 4px 0; color: #6b7280; }

        /* Autopay Toggle */
        .autopay-section label { color: #374151; font-weight: 500; }
        .autopay-section input[type="checkbox"] { accent-color: #2563eb; }

        /* Customer card extras */
        .customer-card .cbottom { display: flex; justify-content: space-between; align-items: center; margin-top: 14px; gap: 8px; }
        .customer-card .ap-badge { font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 12px; }
        .customer-card .ap-badge.active { background: #ecfdf5; color: #059669; }
        .customer-card .ap-badge.paused { background: #eff6ff; color: #2563eb; }
        .customer-card .ap-badge.failed { background: #fef2f2; color: #dc2626; }
        .customer-card .ap-badge.none { background: #f3f4f6; color: #6b7280; }
        .edit-btn { font-size: 12px; font-weight: 600; padding: 6px 14px; border: 1px solid #d1d5db; border-radius: 8px; background: #ffffff; color: #374151; cursor: pointer; transition: all .2s; font-family: inherit; }
        .edit-btn:hover { background: #f9fafb; border-color: #2563eb; color: #2563eb; }

        /* Modal */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 1000; align-items: center; justify-content: center; }
        .modal-overlay.show { display: flex; }
        .modal { background: #ffffff; border-radius: 16px; width: 95%; max-width: 480px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,.2); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px; border-bottom: 1px solid #e5e7eb; }
        .modal-header h3 { font-size: 17px; font-weight: 700; color: #1a1a2e; }
        .modal-close { width: 32px; height: 32px; border: none; background: #f3f4f6; border-radius: 8px; cursor: pointer; font-size: 18px; color: #6b7280; display: flex; align-items: center; justify-content: center; transition: background .2s; }
        .modal-close:hover { background: #e5e7eb; }
        .modal-body { padding: 24px; }
        .modal-body .form-group { margin-bottom: 14px; }
        .modal-body .form-group label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 5px; text-transform: uppercase; letter-spacing: .04em; }
        .modal-body .form-group input, .modal-body .form-group select { width: 100%; padding: 10px 14px; background: #f9fafb; border: 1px solid #d1d5db; border-radius: 10px; color: #1a1a2e; font-size: 14px; outline: none; font-family: inherit; }
        .modal-body .form-group input:focus, .modal-body .form-group select:focus { border-color: #2563eb; }
        .modal-body .form-row { display: flex; gap: 10px; }
        .modal-body .form-row .form-group { flex: 1; }
        .modal-body .btn-primary { width: 100%; padding: 12px; background: #2563eb; color: #fff; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: inherit; transition: background .2s; margin-top: 6px; }
        .modal-body .btn-primary:hover { background: #1d4ed8; }
        .modal-body .btn-danger { width: 100%; padding: 12px; background: #dc2626; color: #fff; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: inherit; transition: background .2s; margin-top: 6px; }
        .modal-body .btn-danger:hover { background: #b91c1c; }
        .modal-body .btn-secondary { width: 100%; padding: 12px; background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: inherit; transition: background .2s; margin-top: 6px; }
        .modal-body .btn-secondary:hover { background: #e5e7eb; }
        .modal-sub-list { margin-bottom: 18px; }
        .modal-sub-item { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; margin-bottom: 10px; }
        .modal-sub-item .ms-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
        .modal-sub-item .ms-amount { font-size: 18px; font-weight: 700; color: #059669; }
        .modal-sub-item .ms-meta { font-size: 12px; color: #6b7280; }
        .modal-sub-item .ms-actions { display: flex; gap: 8px; margin-top: 10px; }
        .modal-sub-item .ms-actions button { font-size: 11px; padding: 5px 12px; border-radius: 6px; border: 1px solid #d1d5db; background: #fff; cursor: pointer; font-weight: 600; font-family: inherit; transition: all .2s; }
        .modal-sub-item .ms-actions .btn-sm-pause { color: #2563eb; border-color: #bfdbfe; }
        .modal-sub-item .ms-actions .btn-sm-pause:hover { background: #eff6ff; }
        .modal-sub-item .ms-actions .btn-sm-resume { color: #059669; border-color: #a7f3d0; }
        .modal-sub-item .ms-actions .btn-sm-resume:hover { background: #ecfdf5; }
        .modal-sub-item .ms-actions .btn-sm-cancel { color: #dc2626; border-color: #fecaca; }
        .modal-sub-item .ms-actions .btn-sm-cancel:hover { background: #fef2f2; }
        .modal-sub-item .ms-actions .btn-sm-retry { color: #d97706; border-color: #fde68a; }
        .modal-sub-item .ms-actions .btn-sm-retry:hover { background: #fffbeb; }
        .modal-divider { border: none; border-top: 1px solid #e5e7eb; margin: 18px 0; }
        .modal-status-msg { text-align: center; font-size: 13px; padding: 8px; border-radius: 8px; margin-top: 10px; }
        .modal-status-msg.success { background: #ecfdf5; color: #059669; }
        .modal-status-msg.error { background: #fef2f2; color: #dc2626; }

        /* Schedule Calendar */
        .schedule-date-card { background: #fff; border: 1px solid #e2e5ea; border-radius: 12px; margin-bottom: 10px; cursor: pointer; transition: box-shadow .2s, border-color .2s; overflow: hidden; }
        .schedule-date-card:hover { border-color: #bfdbfe; box-shadow: 0 2px 12px rgba(37,99,235,.08); }
        .sdc-header { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; }
        .sdc-date { font-size: 18px; font-weight: 700; color: #1a1a2e; }
        .sdc-right { display: flex; align-items: center; gap: 16px; }
        .sdc-count { font-size: 13px; color: #6b7280; font-weight: 500; }
        .sdc-total { font-size: 16px; font-weight: 700; color: #059669; }
        .sdc-arrow { font-size: 10px; color: #9ca3af; transition: transform .2s; }
        .schedule-date-card.open .sdc-arrow { transform: rotate(180deg); }
        .sdc-customers { border-top: 1px solid #f3f4f6; }
        .sdc-cust-row { display: flex; align-items: center; padding: 12px 20px; border-bottom: 1px solid #f9fafb; }
        .sdc-cust-row:last-child { border-bottom: none; }
        .sdc-cust-name { font-size: 14px; font-weight: 600; color: #1a1a2e; flex: 1; }
        .sdc-cust-desc { font-size: 12px; color: #6b7280; flex: 1; text-align: center; }
        .sdc-cust-amount { font-size: 14px; font-weight: 700; color: #059669; min-width: 80px; text-align: right; }

        /* Customer List View */
        .cust-list-table { width: 100%; border-collapse: collapse; }
        .cust-list-table th { text-align: left; padding: 10px 14px; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; border-bottom: 2px solid #e5e7eb; }
        .cust-list-table td { padding: 12px 14px; border-bottom: 1px solid #f3f4f6; font-size: 13px; color: #1a1a2e; }
        .cust-list-table tr:hover { background: #f9fafb; }
        .cust-list-table .clt-name { font-weight: 600; }
        .cust-list-table .clt-contact { font-size: 12px; color: #6b7280; }
        .cust-list-table .clt-amount { font-weight: 700; color: #059669; }

        /* Autocomplete Dropdown */
        .autocomplete-dropdown { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #d1d5db; border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.12); z-index: 100; max-height: 220px; overflow-y: auto; margin-top: 4px; }
        .autocomplete-item { padding: 10px 14px; cursor: pointer; border-bottom: 1px solid #f3f4f6; transition: background .15s; }
        .autocomplete-item:last-child { border-bottom: none; }
        .autocomplete-item:hover { background: #eff6ff; }
        .autocomplete-item .ac-name { font-size: 14px; font-weight: 600; color: #1a1a2e; }
        .autocomplete-item .ac-card { font-size: 12px; color: #6b7280; margin-top: 2px; }
        .saved-card-badge { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 12px; background: #eff6ff; color: #2563eb; margin-top: 6px; }

        /* Responsive */
        @media (max-width: 640px) {
            .app { padding: 16px 14px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .form-row { flex-direction: column; gap: 0; }
            .tabs { gap: 0; }
            .tab { padding: 9px 12px; font-size: 12px; }
            .customer-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="app">
    <div class="top-bar">
        <div>
            <h1>Authorize.net Terminal</h1>
            <span class="subtitle">Secure Payment Gateway</span>
        </div>
        <div class="top-right">
            <span class="auth-badge connected" id="authBadge">
                <span class="dot"></span>
                <span id="authText">Connected</span>
            </span>
            <a class="logout-link" href="?action=admin_logout">Logout</a>
        </div>
    </div>

    <!-- Main App -->
    <div id="mainContent">
        <div class="tabs">
            <button class="tab active" data-tab="dashboard" onclick="switchTab('dashboard')">Dashboard</button>
            <button class="tab" data-tab="charge" onclick="switchTab('charge')">New Charge</button>
            <button class="tab" data-tab="schedule" onclick="switchTab('schedule')">Autopay Schedule</button>
            <button class="tab" data-tab="upcoming" onclick="switchTab('upcoming')">Upcoming Charges</button>
            <button class="tab" data-tab="futureautopay" onclick="switchTab('futureautopay')">Add Future AutoPay</button>
            <button class="tab" data-tab="transactions" onclick="switchTab('transactions')">Transactions</button>
            <button class="tab" data-tab="customers" onclick="switchTab('customers')">Customers</button>
            <button class="tab" data-tab="viewproplus" onclick="switchTab('viewproplus')">JJ</button>
            <button class="tab" data-tab="links" onclick="switchTab('links')">Create Link</button>
            <button class="tab" data-tab="deposits" onclick="switchTab('deposits')">Deposits</button>
            <button class="tab" data-tab="disputes" onclick="switchTab('disputes')">Disputes</button>
            <button class="tab" data-tab="loan" onclick="switchTab('loan')">Business Loan</button>
            <button class="tab" data-tab="sessions" onclick="switchTab('sessions')">Sessions</button>
            <button class="tab" data-tab="logs" onclick="switchTab('logs')">Activity Log</button>
            <button class="tab" data-tab="editlogs" onclick="switchTab('editlogs')">Logs</button>
        </div>

        <!-- TAB: New Charge -->
        <div class="tab-panel" id="panel-charge">
            <div class="card">
                <h2>Charge Amount</h2>
                <div class="form-group">
                    <div class="amount-wrap">
                        <span class="dollar">$</span>
                        <input type="text" id="amount" placeholder="0.00" inputmode="decimal">
                    </div>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" id="description" placeholder="e.g. Service charge, Product sale">
                </div>
            </div>
            <div class="card">
                <h2>Client Information</h2>
                <div class="form-group" style="position: relative;">
                    <label>Client Name</label>
                    <input type="text" id="clientName" placeholder="First Last" autocomplete="off">
                    <div id="clientSuggestions" class="autocomplete-dropdown" style="display:none;"></div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" id="clientEmail" placeholder="email@example.com">
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="tel" id="clientPhone" placeholder="(555) 123-4567">
                    </div>
                </div>
                <div class="form-group">
                    <label>Address</label>
                    <input type="text" id="clientAddress" placeholder="123 Main St">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>City</label>
                        <input type="text" id="clientCity" placeholder="City">
                    </div>
                    <div class="form-group">
                        <label>State</label>
                        <input type="text" id="clientState" placeholder="CA" maxlength="2">
                    </div>
                    <div class="form-group">
                        <label>ZIP</label>
                        <input type="text" id="clientZip" placeholder="90001" maxlength="10">
                    </div>
                </div>
            </div>
            <div class="card">
                <h2>Card Details</h2>
                <div class="form-group">
                    <label>Card Number</label>
                    <input type="text" id="cardNumber" placeholder="4242 4242 4242 4242" maxlength="19" autocomplete="off">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Expiry</label>
                        <input type="text" id="cardExpiry" placeholder="MM/YY" maxlength="5" autocomplete="off">
                        <input type="hidden" id="expMonth"><input type="hidden" id="expYear">
                    </div>
                    <div class="form-group">
                        <label>CVC</label>
                        <input type="text" id="cardCvc" placeholder="123" maxlength="4" autocomplete="off">
                    </div>
                </div>
                <div id="card-errors" style="color: #dc2626; font-size: 12px; margin-top: 6px;"></div>
            </div>
            <div style="background:#f0f9ff; border:1px solid #bae6fd; border-radius:10px; padding:14px 16px; margin-bottom:14px; display:flex; align-items:center; gap:10px;">
                <input type="checkbox" id="chargeAlsoAutopay" style="width:18px; height:18px; cursor:pointer; accent-color:#2563eb;">
                <label for="chargeAlsoAutopay" style="font-size:13px; color:#0369a1; font-weight:600; cursor:pointer; margin:0;">Also set up monthly autopay</label>
                <span style="font-size:11px; color:#6b7280;">(next charge same day next month)</span>
            </div>
            <button class="btn btn-primary" id="chargeBtn" onclick="processCharge()">Charge</button>
            <div class="status" id="chargeStatus"></div>
            <div class="card receipt" id="receipt">
                <div class="checkmark">&#10003;</div>
                <h3>Payment Approved</h3>
                <div class="receipt-amount" id="receiptAmount"></div>
                <div class="receipt-details">
                    <div class="row"><span class="label">Transaction ID</span><span class="value" id="receiptId">-</span></div>
                    <div class="row"><span class="label">Client</span><span class="value" id="receiptClient">-</span></div>
                    <div class="row"><span class="label">Description</span><span class="value" id="receiptDesc">-</span></div>
                    <div class="row"><span class="label">Card</span><span class="value" id="receiptCard">-</span></div>
                    <div class="row"><span class="label">Time</span><span class="value" id="receiptTime">-</span></div>
                </div>
                <button class="btn btn-primary" style="margin-top: 16px;" onclick="resetChargeForm()">New Charge</button>
            </div>
        </div>

        <!-- TAB: Autopay Schedule -->
        <div class="tab-panel" id="panel-schedule">
            <div class="toolbar" style="margin-bottom:14px;">
                <label style="font-size:12px; font-weight:600; color:#374151;">From:</label>
                <input type="month" id="schedFrom" onchange="filterSchedule()" style="padding:6px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:13px;">
                <label style="font-size:12px; font-weight:600; color:#374151;">To:</label>
                <input type="month" id="schedTo" onchange="filterSchedule()" style="padding:6px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:13px;">
            </div>
            <?php if (empty($apCalendar)): ?>
                <div class="empty"><div class="icon">&#128197;</div><p>No upcoming autopay charges scheduled.</p></div>
            <?php else: ?>
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; overflow-x:auto; margin-bottom:18px;">
                    <table style="width:100%; min-width:1450px; border-collapse:collapse; font-size:13px;" id="scheduleTable">
                        <thead>
                            <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                                <th data-sort-col="0" aria-sort="ascending" onclick="sortScheduleTable(0)" style="padding:10px 16px; text-align:left; font-weight:700; color:#1e293b; font-size:12px; cursor:pointer; user-select:none;">Date<span class="schedule-sort-indicator"> ▲</span></th>
                                <th data-sort-col="1" aria-sort="none" onclick="sortScheduleTable(1)" style="padding:10px 16px; text-align:left; font-weight:700; color:#1e293b; font-size:12px; cursor:pointer; user-select:none;">Customer<span class="schedule-sort-indicator"></span></th>
                                <th data-sort-col="2" aria-sort="none" onclick="sortScheduleTable(2)" style="padding:10px 16px; text-align:left; font-weight:700; color:#1e293b; font-size:12px; cursor:pointer; user-select:none;">Phone<span class="schedule-sort-indicator"></span></th>
                                <th data-sort-col="3" aria-sort="none" onclick="sortScheduleTable(3)" style="padding:10px 16px; text-align:left; font-weight:700; color:#1e293b; font-size:12px; cursor:pointer; user-select:none;">Email<span class="schedule-sort-indicator"></span></th>
                                <th data-sort-col="4" aria-sort="none" onclick="sortScheduleTable(4)" style="padding:10px 16px; text-align:left; font-weight:700; color:#1e293b; font-size:12px; cursor:pointer; user-select:none;">Address<span class="schedule-sort-indicator"></span></th>
                                <th data-sort-col="5" aria-sort="none" onclick="sortScheduleTable(5)" style="padding:10px 16px; text-align:left; font-weight:700; color:#1e293b; font-size:12px; cursor:pointer; user-select:none;">Card Last 4<span class="schedule-sort-indicator"></span></th>
                                <th data-sort-col="6" aria-sort="none" onclick="sortScheduleTable(6)" style="padding:10px 16px; text-align:right; font-weight:700; color:#1e293b; font-size:12px; cursor:pointer; user-select:none;">Amount<span class="schedule-sort-indicator"></span></th>
                                <th style="padding:10px 16px; text-align:center; font-weight:700; color:#1e293b; font-size:12px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($apCalendar as $day):
                            $dateLabel = date('m/d', strtotime($day['date']));
                        ?>
                            <?php foreach ($day['customers'] as $i => $ap): ?>
                            <tr class="schedule-row" style="border-bottom:1px solid #f1f5f9;" data-sched-date="<?= $day['date'] ?>" data-sched-amount="<?= floatval($ap['amount'] ?? 0) ?>">
                                <td style="padding:9px 16px; color:#475569; white-space:nowrap;"><?= $dateLabel ?></td>
                                <td style="padding:9px 16px; color:#1e293b; font-weight:500;"><?= htmlspecialchars($ap['clientName'] ?? 'Unknown') ?></td>
                                <td style="padding:9px 16px; font-size:12px;"><?php $sph = $ap['clientPhone'] ?? ''; echo $sph ? '<a href="tel:' . htmlspecialchars(preg_replace('/[^0-9+]/', '', $sph)) . '" style="color:#2563eb;text-decoration:none;">' . htmlspecialchars(formatPhone($sph)) . '</a>' : ''; ?></td>
                                <td style="padding:9px 16px; font-size:12px;"><?php $sem = $ap['clientEmail'] ?? ''; echo $sem ? '<a href="mailto:' . htmlspecialchars($sem) . '" style="color:#2563eb;text-decoration:none;">' . htmlspecialchars($sem) . '</a>' : ''; ?></td>
                                <td style="padding:9px 16px; font-size:12px; color:#475569;"><?php
                                    $street = trim((string)($ap['clientAddress'] ?? ''));
                                    $city = trim((string)($ap['clientCity'] ?? ''));
                                    $stateZip = trim(trim((string)($ap['clientState'] ?? '')) . ' ' . trim((string)($ap['clientZip'] ?? '')));
                                    $locality = implode(', ', array_filter([$city, $stateZip]));
                                    $address = implode(', ', array_filter([$street, $locality]));
                                    echo $address !== '' ? htmlspecialchars($address) : '<span style="color:#dc2626;font-weight:600;">Missing</span>';
                                ?></td>
                                <td style="padding:9px 16px; font-size:12px; color:#475569; white-space:nowrap;"><?php
                                    $last4 = substr(preg_replace('/[^0-9]/', '', (string)($ap['cardLast4'] ?? '')), -4);
                                    $brand = trim((string)($ap['cardBrand'] ?? '')) ?: 'Card';
                                    echo strlen($last4) === 4 ? htmlspecialchars($brand . ' ****' . $last4) : '<span style="color:#dc2626;font-weight:600;">Missing</span>';
                                ?></td>
                                <td style="padding:9px 16px; color:#1e293b; font-weight:600; text-align:right;">$<?= number_format($ap['amount'] ?? 0, 2) ?></td>
                                <td style="padding:9px 16px; text-align:center;"><button class="edit-btn" style="font-size:11px; padding:4px 10px;" onclick="editScheduleItem('<?= htmlspecialchars($ap['id'] ?? '') ?>', '<?= htmlspecialchars($ap['clientName'] ?? '') ?>', <?= floatval($ap['amount'] ?? 0) ?>, '<?= $day['date'] ?>')">Edit</button></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; text-align:center;">
                    <div style="display:flex; justify-content:center; gap:28px; flex-wrap:wrap;">
                        <div><div style="font-size:24px; font-weight:700; color:#059669;"><?= $activeAP ?></div><div style="font-size:11px; color:#6b7280; margin-top:2px;">Active Autopays</div></div>
                        <div><div style="font-size:24px; font-weight:700; color:#2563eb;">$<?= number_format($monthlyForecast, 2) ?></div><div style="font-size:11px; color:#6b7280; margin-top:2px;">Monthly Forecast</div></div>
                        <div><div style="font-size:24px; font-weight:700; color:#1a1a2e;">$<?= number_format($totalScheduled60, 2) ?></div><div style="font-size:11px; color:#6b7280; margin-top:2px;">Next 60 Days</div></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB: Dashboard -->
        <div class="tab-panel active" id="panel-dashboard">
            <div class="stats-grid">
                <div class="stat-card green"><div class="stat-label">Today's Revenue</div><div class="stat-value">$<?= number_format($todayTotal, 2) ?></div><div class="stat-sub"><?= $todayCount ?> txn<?= $todayCount !== 1 ? 's' : '' ?></div></div>
                <div class="stat-card blue"><div class="stat-label">This Week</div><div class="stat-value">$<?= number_format($weekTotal, 2) ?></div><div class="stat-sub"><?= $weekCount ?> txn<?= $weekCount !== 1 ? 's' : '' ?></div></div>
                <div class="stat-card amber"><div class="stat-label">This Month</div><div class="stat-value">$<?= number_format($monthTotal, 2) ?></div><div class="stat-sub"><?= $monthCount ?> txn<?= $monthCount !== 1 ? 's' : '' ?></div></div>
                <div class="stat-card"><div class="stat-label">All Time Revenue</div><div class="stat-value">$<?= number_format($allTotal, 2) ?></div><div class="stat-sub"><?= $allCount ?> total sales</div></div>
                <div class="stat-card green"><div class="stat-label">Total Sales</div><div class="stat-value"><?= $approvedCount ?></div><div class="stat-sub">approved</div></div>
                <div class="stat-card red"><div class="stat-label">Declined</div><div class="stat-value"><?= $declinedCount ?></div><div class="stat-sub">failed</div></div>
                <div class="stat-card blue"><div class="stat-label">Customers</div><div class="stat-value"><?= count($customers) ?></div><div class="stat-sub">unique</div></div>
                <div class="stat-card purple"><div class="stat-label">Active Autopays</div><div class="stat-value"><?= $activeAP ?></div><div class="stat-sub"><?= $pausedAP ?> paused · <?= $failedAP ?> failed</div></div>
                <div class="stat-card blue"><div class="stat-label">Monthly Forecast</div><div class="stat-value">$<?= number_format($monthlyForecast, 2) ?></div><div class="stat-sub">active autopays</div></div>
                <div class="stat-card amber"><div class="stat-label">Next 60 Days</div><div class="stat-value">$<?= number_format($totalScheduled60, 2) ?></div><div class="stat-sub">scheduled</div></div>
                <div class="stat-card purple"><div class="stat-label">Autopay Collected</div><div class="stat-value">$<?= number_format($apRevenue, 2) ?></div><div class="stat-sub">approved history</div></div>
            </div>
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                    <h2 style="margin:0;">Recent Transactions</h2>
                    <a href="?action=export_csv&type=transactions" class="btn-primary" style="display:inline-block; font-size:12px; padding:7px 14px; text-decoration:none; white-space:nowrap;">Export CSV</a>
                </div>
                <?php if (empty($allTxns)): ?>
                    <div class="empty"><p>No transactions yet.</p></div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table id="dashRecentTable">
                            <thead><tr>
                                <th style="cursor:pointer;" onclick="sortDashTable(0)">Time</th>
                                <th style="cursor:pointer;" onclick="sortDashTable(1)">Client</th>
                                <th style="cursor:pointer;" onclick="sortDashTable(2)">Phone</th>
                                <th style="cursor:pointer;" onclick="sortDashTable(3)">Email</th>
                                <th style="cursor:pointer;" onclick="sortDashTable(4)">Amount</th>
                                <th style="cursor:pointer;" onclick="sortDashTable(5)">Type</th>
                                <th style="cursor:pointer;" onclick="sortDashTable(6)">Status</th>
                                <th>Action</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach (array_slice($allTxns, 0, 25) as $t):
                                $txnJson = htmlspecialchars(json_encode([
                                    'id' => $t['id'] ?? '', 'clientName' => $t['clientName'] ?? '', 'clientPhone' => $t['clientPhone'] ?? '',
                                    'clientEmail' => $t['clientEmail'] ?? '', 'amount' => $t['amount'] ?? 0, 'description' => $t['description'] ?? '',
                                    'status' => $t['status'] ?? '', 'timestamp' => $t['timestamp'] ?? '', 'source' => $t['source'] ?? 'manual',
                                    'cardBrand' => $t['cardBrand'] ?? '', 'cardLast4' => $t['cardLast4'] ?? '',
                                ]), ENT_QUOTES);
                            ?>
                                <tr data-sort-time="<?= $t['timestamp'] ?? '' ?>" data-sort-amount="<?= $t['amount'] ?? 0 ?>">
                                    <td><?= date('M j, g:ia', strtotime($t['timestamp'] ?? 'now')) ?></td>
                                    <td style="color:#1a1a2e; font-weight:500;"><?= htmlspecialchars($t['clientName'] ?: 'Walk-in') ?></td>
                                    <td style="font-size:12px;"><?php if (!empty($t['clientPhone'])): ?><a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $t['clientPhone'])) ?>" style="color:#2563eb; text-decoration:none;"><?= htmlspecialchars(formatPhone($t['clientPhone'])) ?></a><?php endif; ?></td>
                                    <td style="font-size:12px;"><?php if (!empty($t['clientEmail'])): ?><a href="mailto:<?= htmlspecialchars($t['clientEmail']) ?>" style="color:#2563eb; text-decoration:none;"><?= htmlspecialchars($t['clientEmail']) ?></a><?php endif; ?></td>
                                    <td style="color:#1a1a2e; font-weight:600;">$<?= number_format($t['amount'], 2) ?></td>
                                    <td><?php if (getTransactionType($t) === 'auto'): ?><span class="badge badge-autopay">Auto</span><?php else: ?>Manual<?php endif; ?></td>
                                    <td><span class="badge badge-<?= $t['status'] ?>"><?= ucfirst($t['status']) ?></span></td>
                                    <td><button class="edit-btn" style="font-size:11px; padding:4px 10px;" onclick='promptTxnPin(<?= $txnJson ?>)'>Edit</button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>


        <!-- TAB: Upcoming Charges -->
        <div class="tab-panel" id="panel-upcoming">
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <h2 style="margin:0;">Upcoming Charges</h2>
                    <div style="font-size:12px; color:#6b7280;">Sorted by next charge date</div>
                </div>
                <?php
                // Get all active autopay subs sorted by nextCharge date
                $upcomingSubs = [];
                foreach ($allAutopays as $ap) {
                    $nextCharge = trim((string)($ap['nextCharge'] ?? $ap['nextChargeDate'] ?? ''));
                    if (($ap['status'] ?? '') === 'active' && $nextCharge !== '') {
                        $ap['nextCharge'] = $nextCharge;
                        $upcomingSubs[] = $ap;
                    }
                }
                usort($upcomingSubs, function($a, $b) {
                    return strcmp($a['nextCharge'] ?? '9999-99-99', $b['nextCharge'] ?? '9999-99-99');
                });
                $todayStr = date('Y-m-d');
                ?>
                <?php if (empty($upcomingSubs)): ?>
                    <div class="empty"><div class="icon">&#128197;</div><p>No upcoming autopay charges.</p></div>
                <?php else: ?>
                    <!-- Summary cards -->
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap:12px; margin-bottom:20px;">
                        <?php
                        $dueToday = 0; $dueTodayAmt = 0;
                        $dueThisWeek = 0; $dueThisWeekAmt = 0;
                        $dueNext30 = 0; $dueNext30Amt = 0;
                        $weekEnd = date('Y-m-d', strtotime('+7 days'));
                        $month30 = date('Y-m-d', strtotime('+30 days'));
                        foreach ($upcomingSubs as $us) {
                            $nc = $us['nextCharge'];
                            $amt = floatval($us['amount']);
                            if ($nc <= $todayStr) { $dueToday++; $dueTodayAmt += $amt; }
                            if ($nc <= $weekEnd) { $dueThisWeek++; $dueThisWeekAmt += $amt; }
                            if ($nc <= $month30) { $dueNext30++; $dueNext30Amt += $amt; }
                        }
                        ?>
                        <div style="background:#fef3c7; border:1px solid #f59e0b; border-radius:10px; padding:14px; text-align:center;">
                            <div style="font-size:22px; font-weight:700; color:#d97706;"><?= $dueToday ?></div>
                            <div style="font-size:11px; color:#92400e; margin-top:2px;">Due Today</div>
                            <div style="font-size:13px; font-weight:600; color:#d97706; margin-top:4px;">$<?= number_format($dueTodayAmt, 2) ?></div>
                        </div>
                        <div style="background:#dbeafe; border:1px solid #3b82f6; border-radius:10px; padding:14px; text-align:center;">
                            <div style="font-size:22px; font-weight:700; color:#2563eb;"><?= $dueThisWeek ?></div>
                            <div style="font-size:11px; color:#1e40af; margin-top:2px;">This Week</div>
                            <div style="font-size:13px; font-weight:600; color:#2563eb; margin-top:4px;">$<?= number_format($dueThisWeekAmt, 2) ?></div>
                        </div>
                        <div style="background:#d1fae5; border:1px solid #10b981; border-radius:10px; padding:14px; text-align:center;">
                            <div style="font-size:22px; font-weight:700; color:#059669;"><?= $dueNext30 ?></div>
                            <div style="font-size:11px; color:#065f46; margin-top:2px;">Next 30 Days</div>
                            <div style="font-size:13px; font-weight:600; color:#059669; margin-top:4px;">$<?= number_format($dueNext30Amt, 2) ?></div>
                        </div>
                        <div style="background:#f3e8ff; border:1px solid #8b5cf6; border-radius:10px; padding:14px; text-align:center;">
                            <div style="font-size:22px; font-weight:700; color:#7c3aed;"><?= count($upcomingSubs) ?></div>
                            <div style="font-size:11px; color:#5b21b6; margin-top:2px;">Total Active</div>
                            <div style="font-size:13px; font-weight:600; color:#7c3aed; margin-top:4px;">$<?= number_format(array_sum(array_column($upcomingSubs, 'amount')), 2) ?>/cycle</div>
                        </div>
                    </div>

                    <!-- Table -->
                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; overflow:hidden;">
                        <table style="width:100%; border-collapse:collapse; font-size:13px;" id="upcomingTable">
                            <thead>
                                <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                                    <th style="padding:10px 14px; text-align:left; font-weight:700; color:#1e293b; font-size:12px;">Next Charge</th>
                                    <th style="padding:10px 14px; text-align:left; font-weight:700; color:#1e293b; font-size:12px;">Days</th>
                                    <th style="padding:10px 14px; text-align:left; font-weight:700; color:#1e293b; font-size:12px;">Customer</th>
                                    <th style="padding:10px 14px; text-align:left; font-weight:700; color:#1e293b; font-size:12px;">Phone</th>
                                    <th style="padding:10px 14px; text-align:left; font-weight:700; color:#1e293b; font-size:12px;">Email</th>
                                    <th style="padding:10px 14px; text-align:right; font-weight:700; color:#1e293b; font-size:12px;">Amount</th>
                                    <th style="padding:10px 14px; text-align:center; font-weight:700; color:#1e293b; font-size:12px;">Frequency</th>
                                    <th style="padding:10px 14px; text-align:center; font-weight:700; color:#1e293b; font-size:12px;">Card</th>
                                    <th style="padding:10px 14px; text-align:center; font-weight:700; color:#1e293b; font-size:12px;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($upcomingSubs as $us):
                                $nextDate = $us['nextCharge'];
                                $daysUntil = (int)((strtotime($nextDate) - strtotime($todayStr)) / 86400);
                                $isOverdue = $daysUntil <= 0;
                                $isDueSoon = $daysUntil <= 3 && $daysUntil > 0;
                                $rowBg = $isOverdue ? '#fef2f2' : ($isDueSoon ? '#fffbeb' : '#fff');
                            ?>
                                <tr style="border-bottom:1px solid #f1f5f9; background:<?= $rowBg ?>;">
                                    <td style="padding:10px 14px; white-space:nowrap; font-weight:500; color:<?= $isOverdue ? '#dc2626' : '#1e293b' ?>;"><?= date('M j, Y', strtotime($nextDate)) ?></td>
                                    <td style="padding:10px 14px; text-align:center;">
                                        <?php if ($isOverdue): ?>
                                            <span style="background:#fee2e2; color:#dc2626; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600;">Overdue</span>
                                        <?php elseif ($daysUntil === 0): ?>
                                            <span style="background:#fef3c7; color:#d97706; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600;">Today</span>
                                        <?php elseif ($isDueSoon): ?>
                                            <span style="background:#fef3c7; color:#d97706; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600;"><?= $daysUntil ?>d</span>
                                        <?php else: ?>
                                            <span style="color:#6b7280; font-size:12px;"><?= $daysUntil ?>d</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:10px 14px; color:#1e293b; font-weight:600;"><?= htmlspecialchars($us['clientName'] ?? 'Unknown') ?></td>
                                    <td style="padding:10px 14px; font-size:12px;"><?php $uph = $us['clientPhone'] ?? ''; echo $uph ? '<a href="tel:' . htmlspecialchars(preg_replace('/[^0-9+]/', '', $uph)) . '" style="color:#2563eb;text-decoration:none;">' . htmlspecialchars(formatPhone($uph)) . '</a>' : '-'; ?></td>
                                    <td style="padding:10px 14px; font-size:12px;"><?php $uem = $us['clientEmail'] ?? ''; echo $uem ? '<a href="mailto:' . htmlspecialchars($uem) . '" style="color:#2563eb;text-decoration:none;">' . htmlspecialchars($uem) . '</a>' : '-'; ?></td>
                                    <td style="padding:10px 14px; color:#059669; font-weight:700; text-align:right;">$<?= number_format($us['amount'] ?? 0, 2) ?></td>
                                    <td style="padding:10px 14px; text-align:center;">
                                        <span style="background:#e0e7ff; color:#4338ca; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:500;"><?= ucfirst($us['frequency'] ?? 'monthly') ?></span>
                                    </td>
                                    <td style="padding:10px 14px; text-align:center; font-size:11px; color:#6b7280;">
                                        <?= !empty($us['cardLast4']) ? htmlspecialchars(($us['cardBrand'] ?? 'Card') . ' ****' . $us['cardLast4']) : '<span style="color:#ef4444;">No card</span>' ?>
                                    </td>
                                    <td style="padding:10px 14px; text-align:center;">
                                        <?php if (!empty($us['encryptedCard']) || !empty($us['cardLast4'])): ?>
                                            <span style="background:#d1fae5; color:#065f46; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:500;">Ready</span>
                                        <?php else: ?>
                                            <span style="background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:500;">No Card</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB: Transactions -->
        <div class="tab-panel" id="panel-futureautopay">
            <div class="card">
                <h2>Add Future AutoPay</h2>
                <p style="color:#6b7280; font-size:13px; margin-bottom:16px;">Schedule a customer for autopay on a future date. Card will NOT be charged until the date you select.</p>
                <div class="form-group">
                    <label>Start Date (first charge date)</label>
                    <input type="date" id="futureApDate" min="" style="font-size:15px;">
                </div>
                <div class="form-group">
                    <div class="amount-wrap">
                        <span class="dollar">$</span>
                        <input type="text" id="futureApAmount" placeholder="0.00" inputmode="decimal">
                    </div>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" id="futureApDesc" placeholder="e.g. Monthly Service">
                </div>
            </div>
            <div class="card">
                <h2>Client Information</h2>
                <div class="form-group" style="position: relative;">
                    <label>Client Name</label>
                    <input type="text" id="futureApName" placeholder="First Last" autocomplete="off">
                    <div id="futureApSuggestions" class="autocomplete-dropdown" style="display:none;"></div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" id="futureApEmail" placeholder="email@example.com">
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="tel" id="futureApPhone" placeholder="(555) 123-4567">
                    </div>
                </div>
                <div class="form-group">
                    <label>Address</label>
                    <input type="text" id="futureApAddress" placeholder="123 Main St">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>City</label>
                        <input type="text" id="futureApCity" placeholder="City">
                    </div>
                    <div class="form-group">
                        <label>State</label>
                        <input type="text" id="futureApState" placeholder="CA" maxlength="2">
                    </div>
                    <div class="form-group">
                        <label>ZIP</label>
                        <input type="text" id="futureApZip" placeholder="90001" maxlength="10">
                    </div>
                </div>
            </div>
            <div class="card">
                <h2>Card Details</h2>
                <div class="form-group">
                    <label>Card Number</label>
                    <input type="text" id="futureApCard" placeholder="4242 4242 4242 4242" maxlength="19" autocomplete="off">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Expiry</label>
                        <input type="text" id="futureApExpiry" placeholder="MM/YY" maxlength="5" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>CVC</label>
                        <input type="text" id="futureApCvc" placeholder="123" maxlength="4" autocomplete="off">
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label>Source</label>
                <select id="futureApSource" style="padding:10px; border-radius:8px; border:1px solid #d1d5db; font-size:14px;">
                    <option value="">Manual</option>
                    <option value="JJ">JJ</option>
                    <option value="CreditGod">CreditGod</option>
                </select>
            </div>
            <button class="btn btn-primary" id="futureApBtn" onclick="submitFutureAutopay()">Schedule Future AutoPay</button>
            <div class="status" id="futureApStatus"></div>
        </div>

        <div class="tab-panel" id="panel-transactions">
            <div class="toolbar">
                <input type="text" id="txnSearch" placeholder="Search by client, description, ID..." oninput="filterTxns()">
                <select id="txnFilter" onchange="filterTxns()"><option value="all">All Status</option><option value="approved">Approved</option><option value="declined">Declined</option><option value="refunded">Refunded</option></select>
                <select id="txnSourceFilter" onchange="filterTxns()"><option value="all">All Types</option><option value="manual">Manual</option><option value="auto">Auto</option></select>
                <select id="txnDateFilter" onchange="filterTxns()"><option value="all">All Time</option><option value="today">Today</option><option value="week">This Week</option><option value="month">This Month</option></select>
                <a href="?action=export_csv&type=transactions" class="btn-primary" style="display:inline-block; font-size:12px; padding:7px 14px; text-decoration:none; white-space:nowrap;">Export CSV</a>
            </div>
            <div class="card" style="padding: 0; overflow: hidden;">
                <?php if (empty($allTxns)): ?>
                    <div class="empty" style="padding: 40px;"><p>No transactions yet.</p></div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table id="txnTable">
                            <thead><tr><th>Date</th><th>ID</th><th>Client</th><th>Phone</th><th>Email</th><th>Address</th><th>Card</th><th>Amount</th><th>Type</th><th>Status</th><th>Action</th></tr></thead>
                            <tbody>
                            <?php foreach ($allTxns as $t):
                                $txnEditJson = htmlspecialchars(json_encode([
                                    'id' => $t['id'] ?? '', 'clientName' => $t['clientName'] ?? '', 'clientPhone' => $t['clientPhone'] ?? '',
                                    'clientEmail' => $t['clientEmail'] ?? '', 'amount' => $t['amount'] ?? 0, 'description' => $t['description'] ?? '',
                                    'status' => $t['status'] ?? '', 'timestamp' => $t['timestamp'] ?? '', 'source' => $t['source'] ?? 'manual',
                                    'cardBrand' => $t['cardBrand'] ?? '', 'cardLast4' => $t['cardLast4'] ?? '',
                                ]), ENT_QUOTES);
                            ?>
                                <tr data-status="<?= $t['status'] ?>" data-source="<?= getTransactionType($t) ?>" data-date="<?= substr($t['timestamp'] ?? '', 0, 10) ?>" data-search="<?= strtolower(($t['clientName'] ?? '') . ' ' . ($t['description'] ?? '') . ' ' . ($t['id'] ?? '') . ' ' . ($t['clientEmail'] ?? '')) ?>">
                                    <td><?= date('M j, g:ia', strtotime($t['timestamp'] ?? 'now')) ?></td>
                                    <td style="font-family: monospace; font-size: 10px; color: #6b7080;"><?= htmlspecialchars(substr($t['id'] ?? '', 0, 14)) ?></td>
                                    <td style="color:#1a1a2e; font-weight:500;"><?= htmlspecialchars($t['clientName'] ?: 'Walk-in') ?></td>
                                    <td style="font-size:12px;"><?php if (!empty($t['clientPhone'])): ?><a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $t['clientPhone'])) ?>" style="color:#2563eb; text-decoration:none;"><?= htmlspecialchars(formatPhone($t['clientPhone'])) ?></a><?php endif; ?></td>
                                    <td style="font-size:12px;"><?php if (!empty($t['clientEmail'])): ?><a href="mailto:<?= htmlspecialchars($t['clientEmail']) ?>" style="color:#2563eb; text-decoration:none;"><?= htmlspecialchars($t['clientEmail']) ?></a><?php endif; ?></td>
                                    <td style="font-size:12px;"><?php
                                        $addrP = array_filter([$t['clientAddress'] ?? '', $t['clientCity'] ?? '', (($t['clientState'] ?? '') ? ($t['clientState'] . ' ' . ($t['clientZip'] ?? '')) : ($t['clientZip'] ?? ''))]);
                                        echo $addrP ? htmlspecialchars(implode(', ', $addrP)) : '<span style="color:#9ca3af;">—</span>';
                                    ?></td>
                                    <td style="font-size: 11px;"><?= !empty($t['cardLast4']) ? htmlspecialchars(($t['cardBrand'] ?? 'Card') . ' ****' . $t['cardLast4']) : '<span style="color:#9ca3af;">No card</span>' ?></td>
                                    <td style="color:#1a1a2e; font-weight:600;">$<?= number_format($t['amount'], 2) ?></td>
                                    <td><?php if (getTransactionType($t) === 'auto'): ?><span class="badge badge-autopay">Auto</span><?php else: ?>Manual<?php endif; ?></td>
                                    <td><span class="badge badge-<?= $t['status'] ?>"><?= ucfirst($t['status']) ?></span></td>
                                    <td><button class="edit-btn" style="font-size:11px; padding:4px 10px;" onclick='promptTxnPin(<?= $txnEditJson ?>)'>Edit</button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <div style="text-align: center; margin-top: 10px; color: #6b7080; font-size: 12px;" id="txnCount"><?= count($allTxns) ?> transaction<?= count($allTxns) !== 1 ? 's' : '' ?></div>
        </div>

        <!-- TAB: Customers -->
        <div class="tab-panel" id="panel-customers">
            <?php if (empty($customers)): ?>
                <div class="empty"><div class="icon">&#128100;</div><p>No customers yet.</p><button class="btn-primary" style="font-size:14px; padding:10px 24px; margin-top:12px;" onclick="openAddCustomerModal()">+ Add Customer</button></div>
            <?php else: ?>
                <div class="toolbar"><input type="text" id="custSearch" placeholder="Search customers..." oninput="filterCusts()"><button class="btn-primary" style="font-size:14px; padding:7px 16px; font-weight:700; margin-right:6px;" onclick="openAddCustomerModal()">+ Add Customer</button><a href="?action=export_csv&type=customers" class="btn-primary" style="display:inline-block; font-size:12px; padding:7px 14px; text-decoration:none; white-space:nowrap;">Export CSV</a></div>
                <div class="card" style="padding: 0; overflow: hidden;">
                    <div class="table-wrap">
                        <table class="cust-list-table" id="customerTable">
                            <thead><tr><th>Next Charge</th><th>Customer</th><th>Email</th><th>Phone</th><th>Address</th><th>Card</th><th>Amount</th><th style="cursor:pointer;" onclick="sortCustByDate()">Last Charge <span id="sortArrow">&#9662;</span></th><th>Charges</th><th>Autopay</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($customers as $c): ?>
                                <?php
                                $custAPs = $c['autopays'] ?? [];
                                $custAPStatus = 'none';
                                foreach ($custAPs as $cap) {
                                    if ($cap['status'] === 'active') { $custAPStatus = 'active'; break; }
                                    if ($cap['status'] === 'paused') $custAPStatus = 'paused';
                                    if ($cap['status'] === 'failed' && $custAPStatus !== 'paused') $custAPStatus = 'failed';
                                }
                                $custAPLabel = $custAPStatus === 'active' ? 'Auto' : ($custAPStatus === 'paused' ? 'Paused' : ($custAPStatus === 'failed' ? 'Failed' : '—'));
                                $custJson = htmlspecialchars(json_encode([
                                    'name' => $c['name'], 'email' => $c['email'] ?? '', 'phone' => $c['phone'] ?? '',
                                    'address' => $c['address'] ?? '', 'city' => $c['city'] ?? '', 'state' => $c['state'] ?? '', 'zip' => $c['zip'] ?? '',
                                    'autopays' => array_map(function($a) {
                                        return ['id' => $a['id'], 'amount' => $a['amount'], 'description' => $a['description'] ?? '',
                                            'frequency' => $a['frequency'], 'nextCharge' => $a['nextCharge'] ?? '', 'status' => $a['status'],
                                            'cardLast4' => $a['cardLast4'] ?? '', 'cardBrand' => $a['cardBrand'] ?? 'Card'];
                                    }, $custAPs),
                                ]), ENT_QUOTES);
                                $addrParts = array_filter([$c['address'] ?? '', $c['city'] ?? '', (($c['state'] ?? '') ? ($c['state'] . ' ' . ($c['zip'] ?? '')) : ($c['zip'] ?? ''))]);
                                $fullAddr = implode(', ', $addrParts);
                                ?>
                                <tr data-search="<?= strtolower($c['name'] . ' ' . ($c['email'] ?? '') . ' ' . ($c['phone'] ?? '')) ?>" data-date="<?= $c['lastCharge'] ?? '' ?>">
                                    <?php
                                    // Get active autopay amount and next charge (computed early for column order)
                                    $apAmount = 0; $apNextDate = '';
                                    foreach ($custAPs as $cap) {
                                        if ($cap['status'] === 'active') {
                                            $apAmount += floatval($cap['amount'] ?? 0);
                                            if (empty($apNextDate) || ($cap['nextCharge'] ?? '') < $apNextDate) {
                                                $apNextDate = $cap['nextCharge'] ?? '';
                                            }
                                        }
                                    }
                                    ?>
                                    <td style="font-size:12px; font-weight:600; color:#059669;"><?= $apNextDate ? date('M j, Y', strtotime($apNextDate)) : '<span style="color:#9ca3af;">—</span>' ?></td>
                                    <td class="clt-name"><?= htmlspecialchars($c['name'] ?: 'Walk-in') ?></td>
                                    <td class="clt-contact"><?php $em = htmlspecialchars($c['email'] ?? ''); echo $em ? '<a href="mailto:' . $em . '" style="color:#2563eb;text-decoration:none;">' . $em . '</a>' : '—'; ?></td>
                                    <td class="clt-contact"><?php $ph = $c['phone'] ?? ''; echo $ph ? '<a href="tel:' . htmlspecialchars(preg_replace('/[^0-9+]/', '', $ph)) . '" style="color:#2563eb;text-decoration:none;">' . htmlspecialchars(formatPhone($ph)) . '</a>' : '—'; ?></td>
                                    <td style="font-size:12px; color:#6b7280;"><?php $addrParts2 = array_filter([$c['address'] ?? '', $c['city'] ?? '', (($c['state'] ?? '') ? ($c['state'] . ' ' . ($c['zip'] ?? '')) : ($c['zip'] ?? ''))]); echo $addrParts2 ? htmlspecialchars(implode(', ', $addrParts2)) : '—'; ?></td>
                                    <td style="font-size:11px;"><?= !empty($c['cardLast4']) ? htmlspecialchars(($c['cardBrand'] ?? 'Card') . ' ****' . $c['cardLast4']) : '<span style="color:#9ca3af;">No card</span>' ?></td>
                                    <td style="font-weight:600; color:#7c3aed;"><?= $apAmount > 0 ? '$' . number_format($apAmount, 2) : '—' ?></td>
                                    <td style="font-size: 12px; color: #6b7280;"><?= $c['lastCharge'] ? date('M j, Y', strtotime($c['lastCharge'])) : '—' ?></td>
                                    <td style="font-weight: 600; color: #2563eb;"><?= $c['count'] ?></td>
                                    <td><span class="ap-badge <?= $custAPStatus ?>"><?= $custAPLabel ?></span></td>
                                    <td><button class="edit-btn" style="font-size:11px; padding:4px 10px;" onclick='promptEditPin(<?= $custJson ?>)'>Edit</button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div style="text-align: center; margin-top: 10px; color: #6b7080; font-size: 12px;"><?= count($customers) ?> customer<?= count($customers) !== 1 ? 's' : '' ?></div>
            <?php endif; ?>
        </div>

        <!-- TAB: JJ -->
        <div class="tab-panel" id="panel-viewproplus">
            <?php
            // Collect JJ transactions and customers
            $vpTxns = array_filter($allTxns, function($t) { return ($t['source'] ?? '') === 'JJ'; });
            $vpApproved = array_filter($vpTxns, function($t) { return $t['status'] === 'approved'; });
            $vpDeclined = array_filter($vpTxns, function($t) { return $t['status'] === 'declined'; });
            $vpTotal = array_sum(array_map(function($t) { return floatval($t['amount']); }, $vpApproved));
            $vpAutopays = array_filter($allAutopays ?? [], function($a) { return ($a['source'] ?? '') === 'JJ'; });
            $vpActiveAP = array_filter($vpAutopays, function($a) { return $a['status'] === 'active'; });

            // Build unique customer list from VP transactions
            $vpCustMap = [];
            foreach ($vpTxns as $t) {
                $n = $t['clientName'] ?? '';
                if (!$n) continue;
                $key = strtolower($n);
                if (!isset($vpCustMap[$key])) {
                    $vpCustMap[$key] = ['name' => $n, 'email' => $t['clientEmail'] ?? '', 'phone' => $t['clientPhone'] ?? '', 'count' => 0, 'total' => 0, 'lastCharge' => '', 'autopay' => false, 'autopayStatus' => 'none'];
                }
                if ($t['status'] === 'approved') { $vpCustMap[$key]['count']++; $vpCustMap[$key]['total'] += floatval($t['amount']); }
                if (!$vpCustMap[$key]['lastCharge'] || ($t['timestamp'] ?? '') > $vpCustMap[$key]['lastCharge']) $vpCustMap[$key]['lastCharge'] = $t['timestamp'] ?? '';
                if ($t['clientEmail'] ?? '') $vpCustMap[$key]['email'] = $t['clientEmail'];
                if ($t['clientPhone'] ?? '') $vpCustMap[$key]['phone'] = $t['clientPhone'];
            }
            // Also pull VP autopay-only customers
            foreach ($vpAutopays as $a) {
                $n = $a['clientName'] ?? '';
                if (!$n) continue;
                $key = strtolower($n);
                if (!isset($vpCustMap[$key])) {
                    $vpCustMap[$key] = ['name' => $n, 'email' => $a['clientEmail'] ?? '', 'phone' => $a['clientPhone'] ?? '', 'count' => 0, 'total' => 0, 'lastCharge' => '', 'autopay' => false, 'autopayStatus' => 'none'];
                }
                if ($a['status'] === 'active') { $vpCustMap[$key]['autopay'] = true; $vpCustMap[$key]['autopayStatus'] = 'active'; }
                elseif ($a['status'] === 'failed' && $vpCustMap[$key]['autopayStatus'] !== 'active') { $vpCustMap[$key]['autopay'] = true; $vpCustMap[$key]['autopayStatus'] = 'failed'; }
            }
            // Check main autopays for VP customers too
            foreach ($allAutopays ?? [] as $a) {
                $key = strtolower($a['clientName'] ?? '');
                if (isset($vpCustMap[$key]) && $a['status'] === 'active') { $vpCustMap[$key]['autopay'] = true; $vpCustMap[$key]['autopayStatus'] = 'active'; }
            }
            $vpCustomers = array_values($vpCustMap);
            usort($vpCustomers, function($a, $b) { return strcmp($b['lastCharge'], $a['lastCharge']); });
            ?>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px; margin-bottom:20px;">
                <div class="card" style="text-align:center; padding:20px;">
                    <div style="font-size:28px; font-weight:800; color:#7c3aed;"><?= count($vpCustomers) ?></div>
                    <div style="font-size:12px; color:#6b7280; margin-top:4px;">Total Customers</div>
                </div>
                <div class="card" style="text-align:center; padding:20px;">
                    <div style="font-size:28px; font-weight:800; color:#059669;">$<?= number_format($vpTotal, 2) ?></div>
                    <div style="font-size:12px; color:#6b7280; margin-top:4px;">Total Revenue</div>
                </div>
                <div class="card" style="text-align:center; padding:20px;">
                    <div style="font-size:28px; font-weight:800; color:#2563eb;"><?= count($vpApproved) ?></div>
                    <div style="font-size:12px; color:#6b7280; margin-top:4px;">Approved</div>
                </div>
                <div class="card" style="text-align:center; padding:20px;">
                    <div style="font-size:28px; font-weight:800; color:#dc2626;"><?= count($vpDeclined) ?></div>
                    <div style="font-size:12px; color:#6b7280; margin-top:4px;">Declined</div>
                </div>
                <div class="card" style="text-align:center; padding:20px;">
                    <div style="font-size:28px; font-weight:800; color:#7c3aed;"><?= count($vpActiveAP) ?></div>
                    <div style="font-size:12px; color:#6b7280; margin-top:4px;">Active Autopay</div>
                </div>
            </div>

            <h3 style="font-size:16px; font-weight:700; color:#1a1a2e; margin-bottom:12px;">JJ Customers</h3>
            <?php if (empty($vpCustomers)): ?>
                <div class="empty"><div class="icon" style="font-size:40px;">&#127760;</div><p>No JJ customers yet.</p></div>
            <?php else: ?>
                <div class="card" style="padding:0; overflow:hidden;">
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Last Charge</th><th>Customer</th><th>Email</th><th>Phone</th><th>Charges</th><th>Total</th><th>Autopay</th></tr></thead>
                            <tbody>
                            <?php foreach ($vpCustomers as $vpc): ?>
                                <tr>
                                    <td style="font-size:12px; color:#6b7280;"><?= $vpc['lastCharge'] ? date('M j, Y', strtotime($vpc['lastCharge'])) : '—' ?></td>
                                    <td style="font-weight:600; color:#1a1a2e;"><?= htmlspecialchars($vpc['name']) ?></td>
                                    <td style="font-size:12px;"><?= $vpc['email'] ? '<a href="mailto:' . htmlspecialchars($vpc['email']) . '" style="color:#2563eb;text-decoration:none;">' . htmlspecialchars($vpc['email']) . '</a>' : '—' ?></td>
                                    <td style="font-size:12px;"><?= $vpc['phone'] ? '<a href="tel:' . htmlspecialchars(preg_replace('/[^0-9+]/', '', $vpc['phone'])) . '" style="color:#2563eb;text-decoration:none;">' . htmlspecialchars(formatPhone($vpc['phone'])) . '</a>' : '—' ?></td>
                                    <td style="font-weight:600; color:#2563eb;"><?= $vpc['count'] ?></td>
                                    <td style="font-weight:600; color:#1a1a2e;">$<?= number_format($vpc['total'], 2) ?></td>
                                    <td>
                                        <?php if ($vpc['autopayStatus'] === 'active'): ?>
                                            <span class="badge" style="background:#059669; color:#fff;">Active</span>
                                        <?php elseif ($vpc['autopayStatus'] === 'failed'): ?>
                                            <span class="badge" style="background:#dc2626; color:#fff;">Failed</span>
                                        <?php else: ?>
                                            <span style="color:#9ca3af;">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div style="text-align:center; margin-top:10px; color:#6b7280; font-size:12px;"><?= count($vpCustomers) ?> customer<?= count($vpCustomers) !== 1 ? 's' : '' ?> from JJ</div>
            <?php endif; ?>

            <?php if (!empty($vpTxns)): ?>
            <h3 style="font-size:16px; font-weight:700; color:#1a1a2e; margin:24px 0 12px;">Recent JJ Transactions</h3>
            <div class="card" style="padding:0; overflow:hidden;">
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Date</th><th>Customer</th><th>Description</th><th>Card</th><th>Amount</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice(array_values($vpTxns), 0, 50) as $vpt): ?>
                            <tr>
                                <td style="font-size:12px; white-space:nowrap;"><?= date('M j, g:ia', strtotime($vpt['timestamp'] ?? 'now')) ?></td>
                                <td style="font-weight:500; color:#1a1a2e;"><?= htmlspecialchars($vpt['clientName'] ?: 'Unknown') ?></td>
                                <td style="font-size:12px;"><?= htmlspecialchars($vpt['description'] ?? '') ?></td>
                                <td style="font-size:11px;"><?= !empty($vpt['cardLast4']) ? htmlspecialchars(($vpt['cardBrand'] ?? 'Card') . ' ****' . $vpt['cardLast4']) : '<span style="color:#9ca3af;">No card</span>' ?></td>
                                <td style="font-weight:600; color:#1a1a2e;">$<?= number_format($vpt['amount'], 2) ?></td>
                                <td><span class="badge badge-<?= $vpt['status'] ?>"><?= ucfirst($vpt['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- VP Custom Charge -->
            <h3 style="font-size:16px; font-weight:700; color:#1a1a2e; margin:24px 0 12px;">Custom Charge</h3>
            <div class="card" style="padding:20px;">
                <p style="font-size:13px; color:#6b7280; margin:0 0 16px;">Manually charge a JJ customer. This will show up tagged as JJ.</p>
                <div class="form-group">
                    <label>Amount</label>
                    <div class="amount-wrap">
                        <span class="dollar">$</span>
                        <input type="text" id="vpAmount" placeholder="0.00" inputmode="decimal">
                    </div>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" id="vpDescription" placeholder="e.g. JJ 30 Day Access">
                </div>
                <div class="form-group" style="position:relative;">
                    <label>Client Name</label>
                    <input type="text" id="vpClientName" placeholder="First Last" autocomplete="off">
                    <div id="vpClientSuggestions" class="autocomplete-dropdown" style="display:none;"></div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" id="vpClientEmail" placeholder="email@example.com">
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="tel" id="vpClientPhone" placeholder="(555) 123-4567">
                    </div>
                </div>
                <div class="form-group">
                    <label>Address</label>
                    <input type="text" id="vpClientAddress" placeholder="123 Main St">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>City</label>
                        <input type="text" id="vpClientCity" placeholder="City">
                    </div>
                    <div class="form-group">
                        <label>State</label>
                        <input type="text" id="vpClientState" placeholder="CA" maxlength="2">
                    </div>
                    <div class="form-group">
                        <label>ZIP</label>
                        <input type="text" id="vpClientZip" placeholder="90001" maxlength="10">
                    </div>
                </div>
                <div class="form-group">
                    <label>Card Number</label>
                    <input type="text" id="vpCardNumber" placeholder="4242 4242 4242 4242" maxlength="19" autocomplete="off">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Expiry</label>
                        <input type="text" id="vpCardExpiry" placeholder="MM/YY" maxlength="5" autocomplete="off">
                        <input type="hidden" id="vpExpMonth"><input type="hidden" id="vpExpYear">
                    </div>
                    <div class="form-group">
                        <label>CVC</label>
                        <input type="text" id="vpCardCvc" placeholder="123" maxlength="4" autocomplete="off">
                    </div>
                </div>
                <div style="background:#f5f3ff; border:1px solid #c4b5fd; border-radius:10px; padding:14px 16px; margin-bottom:14px; display:flex; align-items:center; gap:10px;">
                    <input type="checkbox" id="vpAlsoAutopay" style="width:18px; height:18px; cursor:pointer; accent-color:#7c3aed;">
                    <label for="vpAlsoAutopay" style="font-size:13px; color:#6d28d9; font-weight:600; cursor:pointer; margin:0;">Also set up monthly autopay</label>
                    <span style="font-size:11px; color:#6b7280;">(next charge same day next month)</span>
                </div>
                <button class="btn btn-primary" id="vpChargeBtn" style="background:#7c3aed;" onclick="processVpCharge()">Charge</button>
                <div class="status" id="vpChargeStatus"></div>
                <div class="card receipt" id="vpReceipt" style="display:none;">
                    <div class="checkmark">&#10003;</div>
                    <h3>Payment Approved</h3>
                    <div class="receipt-amount" id="vpReceiptAmount"></div>
                    <div class="receipt-details">
                        <div class="row"><span class="label">Transaction ID</span><span class="value" id="vpReceiptId">-</span></div>
                        <div class="row"><span class="label">Client</span><span class="value" id="vpReceiptClient">-</span></div>
                        <div class="row"><span class="label">Description</span><span class="value" id="vpReceiptDesc">-</span></div>
                        <div class="row"><span class="label">Card</span><span class="value" id="vpReceiptCard">-</span></div>
                        <div class="row"><span class="label">Time</span><span class="value" id="vpReceiptTime">-</span></div>
                    </div>
                    <button class="btn btn-primary" style="margin-top:16px; background:#7c3aed;" onclick="resetVpChargeForm()">New Charge</button>
                </div>
            </div>
        </div>

        <!-- TAB: Create Link -->
        <div class="tab-panel" id="panel-links">
            <div class="card">
                <h2 style="font-size:20px; font-weight:700; margin-bottom:20px;">Create Payment Link</h2>
                <p style="color:#666; font-size:14px; margin-bottom:24px;">Add products below. The customer will see all products and pick which one to pay for.</p>

                <!-- Products Builder -->
                <div style="margin-bottom:20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                        <label style="font-size:14px; font-weight:700; color:#374151;">Products</label>
                        <button onclick="addLinkProduct()" style="background:#059669; color:#fff; border:none; padding:6px 14px; border-radius:8px; font-size:12px; font-weight:600; cursor:pointer;">+ Add Product</button>
                    </div>
                    <div id="linkProducts">
                        <div class="link-product-row" style="display:flex; gap:8px; margin-bottom:8px; align-items:center;">
                            <input type="text" class="lp-name" placeholder="Product name (e.g. Monthly Plan)" style="flex:2; padding:10px 12px; border:2px solid #e0e0e0; border-radius:10px; font-size:14px;">
                            <input type="number" class="lp-price" placeholder="Price" step="0.01" min="0.01" style="flex:1; padding:10px 12px; border:2px solid #e0e0e0; border-radius:10px; font-size:14px;">
                            <button onclick="this.parentElement.remove()" style="background:#fef2f2; color:#dc2626; border:1px solid #fecaca; padding:8px 12px; border-radius:8px; cursor:pointer; font-size:14px;">&times;</button>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Page Title (optional)</label>
                        <input type="text" id="linkDesc" placeholder="e.g. ViewProPlus Plans" value="">
                    </div>
                    <div class="form-group">
                        <label>Source</label>
                        <select id="linkSource" style="width:100%;padding:12px;border:2px solid #e0e0e0;border-radius:10px;font-size:14px;">
                            <option value="link">Payment Link</option>
                            <option value="JJ">JJ</option>
                            <option value="manual">Manual</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Customer Name (optional)</label>
                        <input type="text" id="linkName" placeholder="Pre-fill name">
                    </div>
                    <div class="form-group">
                        <label>Customer Email (optional)</label>
                        <input type="email" id="linkEmail" placeholder="Pre-fill email">
                    </div>
                </div>
                <div style="display:flex; gap:16px; margin-top:8px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" id="linkAutopay" style="width:18px;height:18px;">
                        <span style="font-size:13px;">Monthly autopay</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" id="linkSingleUse" checked style="width:18px;height:18px;">
                        <span style="font-size:13px;">Single use</span>
                    </label>
                </div>
                <button class="btn" onclick="createPayLink()" style="width:100%;margin-top:16px;">Generate Payment Link</button>

                <!-- Generated link display -->
                <div id="linkResult" style="display:none; margin-top:20px; padding:20px; background:#f0f7ff; border-radius:12px; border:2px solid #667eea;">
                    <div style="font-size:13px; font-weight:600; color:#555; margin-bottom:8px;">Payment Link:</div>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <input type="text" id="linkUrl" readonly style="flex:1; padding:12px; border:2px solid #ddd; border-radius:8px; font-size:14px; background:#fff;">
                        <button onclick="copyLink()" class="btn" style="white-space:nowrap; padding:12px 20px;">Copy</button>
                    </div>
                    <div id="linkCopied" style="display:none; color:#27ae60; font-size:12px; font-weight:600; margin-top:6px;">Copied to clipboard!</div>
                </div>
            </div>

            <!-- Existing Links -->
            <div class="card" style="margin-top:20px;">
                <h3 style="font-size:16px; font-weight:700; margin-bottom:16px;">Your Payment Links</h3>
                <div id="linksTableWrap">
                    <table class="txn-table" id="linksTable">
                        <thead><tr>
                            <th>Created</th><th>Description</th><th>Amount</th><th>Source</th><th>Autopay</th><th>Status</th><th>Actions</th>
                        </tr></thead>
                        <tbody id="linksBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB: Deposits -->
        <div class="tab-panel" id="panel-deposits">
            <div class="card" style="padding: 20px; margin-bottom: 20px;">
                <div style="margin-bottom:16px;">
                    <h2 style="margin:0;">Deposits</h2>
                    <p style="font-size:13px; color:#6b7280; margin:4px 0 0;">Bank deposits received from payment processor.</p>
                </div>

                <!-- Account Status Notice -->
                <div style="background:#f0fdf4; border:1px solid #86efac; border-radius:10px; padding:12px 16px; margin-bottom:18px; display:flex; align-items:center; gap:10px;">
                    <span style="font-size:16px;">&#9989;</span>
                    <div>
                        <div style="font-size:12px; font-weight:700; color:#065f46;">Message from Authorize.net API</div>
                        <div style="font-size:11px; color:#047857;">Account is active. Deposits are being processed on a regular schedule.</div>
                    </div>
                </div>

                <!-- Account Summary -->
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:20px 22px; margin-bottom:20px;">
                    <div style="font-size:14px; font-weight:700; color:#1e293b; margin-bottom:14px;">Account Summary</div>
                    <table style="width:100%; border-collapse:collapse;">
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:8px 0; font-size:13px; color:#475569;">Total Processed (<?= $approvedCount ?> payments)</td>
                            <td style="padding:8px 0; font-size:13px; font-weight:600; color:#1e293b; text-align:right;">$<?= number_format($allTotal, 2) ?></td>
                        </tr>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:8px 0; font-size:13px; color:#475569;">Total Deposited</td>
                            <td style="padding:8px 0; font-size:13px; font-weight:600; color:#059669; text-align:right;">- $<?= number_format($totalDeposits, 2) ?></td>
                        </tr>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:8px 0; font-size:13px; color:#94a3b8;">Processing Fee (2.9%)</td>
                            <td style="padding:8px 0; font-size:13px; font-weight:600; color:#dc2626; text-align:right;">- $<?= number_format($totalPercentFee, 2) ?></td>
                        </tr>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:8px 0; font-size:13px; color:#94a3b8;">Transaction Fees ($1.23 × <?= $approvedCount ?>)</td>
                            <td style="padding:8px 0; font-size:13px; font-weight:600; color:#dc2626; text-align:right;">- $<?= number_format($totalTxnFee, 2) ?></td>
                        </tr>
                        <tr>
                            <td style="padding:12px 0 4px; font-size:14px; font-weight:700; color:#1e293b;">Net Balance</td>
                            <td style="padding:12px 0 4px; font-size:16px; font-weight:800; color:#059669; text-align:right;">$<?= number_format($netPending, 2) ?></td>
                        </tr>
                    </table>
                    <div style="font-size:11px; color:#94a3b8; margin-top:10px;">Fees are deducted automatically by the payment processor before deposits are issued.</div>
                </div>

                <!-- Deposits Table -->
                <?php if (empty($allDeposits)): ?>
                    <div class="empty"><p>No deposits recorded yet.</p></div>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="txn-table" style="width:100%;">
                            <thead>
                                <tr>
                                    <th style="text-align:left; padding:10px 14px;">Date</th>
                                    <th style="text-align:right; padding:10px 14px;">Amount</th>
                                    <th style="text-align:left; padding:10px 14px;">Note</th>

                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allDeposits as $dep): ?>
                                <tr>
                                    <td style="padding:10px 14px; font-weight:600; white-space:nowrap;">
                                        <?php
                                        $depDate = new DateTime($dep['date']);
                                        echo $depDate->format('D, M j, Y');
                                        ?>
                                    </td>
                                    <td style="padding:10px 14px; text-align:right; font-weight:700; color:#059669; font-size:15px;">$<?= number_format($dep['amount'], 2) ?></td>
                                    <td style="padding:10px 14px; color:#6b7280;"><?= htmlspecialchars($dep['note'] ?? '') ?></td>

                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB: Disputes -->
        <div class="tab-panel" id="panel-disputes">
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <div>
                        <h2 style="margin:0;">Disputes</h2>
                        <p style="font-size:13px; color:#6b7280; margin:4px 0 0;">Chargebacks and payment disputes filed by customers.</p>
                    </div>
                </div>

                <?php
                $openDisputes = 0;
                $wonDisputes = 0;
                $lostDisputes = 0;
                $totalDisputeAmount = 0;
                foreach ($allDisputes as $d) {
                    $totalDisputeAmount += floatval($d['amount'] ?? 0);
                    $st = strtolower($d['status'] ?? 'open');
                    if ($st === 'won') $wonDisputes++;
                    elseif ($st === 'lost') $lostDisputes++;
                    else $openDisputes++;
                }
                ?>

                <!-- Dispute Stats -->
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:12px; margin-bottom:20px;">
                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px; text-align:center;">
                        <div style="font-size:11px; color:#6b7280; font-weight:600; text-transform:uppercase;">Total</div>
                        <div style="font-size:20px; font-weight:800; color:#1e293b;"><?= count($allDisputes) ?></div>
                    </div>
                    <div style="background:#fff; border:1px solid #fde68a; border-radius:8px; padding:12px 16px; text-align:center;">
                        <div style="font-size:11px; color:#d97706; font-weight:600; text-transform:uppercase;">Open</div>
                        <div style="font-size:20px; font-weight:800; color:#92400e;"><?= $openDisputes ?></div>
                    </div>
                    <div style="background:#fff; border:1px solid #bbf7d0; border-radius:8px; padding:12px 16px; text-align:center;">
                        <div style="font-size:11px; color:#059669; font-weight:600; text-transform:uppercase;">Won</div>
                        <div style="font-size:20px; font-weight:800; color:#065f46;"><?= $wonDisputes ?></div>
                    </div>
                    <div style="background:#fff; border:1px solid #fecaca; border-radius:8px; padding:12px 16px; text-align:center;">
                        <div style="font-size:11px; color:#dc2626; font-weight:600; text-transform:uppercase;">Lost</div>
                        <div style="font-size:20px; font-weight:800; color:#991b1b;"><?= $lostDisputes ?></div>
                    </div>
                </div>

                <!-- Disputes Table -->
                <?php if (empty($allDisputes)): ?>
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:30px; text-align:center;">
                        <div style="font-size:28px; margin-bottom:8px;">&#9989;</div>
                        <div style="font-size:14px; font-weight:600; color:#1e293b;">No Disputes</div>
                        <div style="font-size:12px; color:#6b7280; margin-top:4px;">Your account has no chargebacks or disputes on file.</div>
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="txn-table" style="width:100%;">
                            <thead>
                                <tr>
                                    <th style="text-align:left; padding:10px 14px;">Date</th>
                                    <th style="text-align:left; padding:10px 14px;">Customer</th>
                                    <th style="text-align:left; padding:10px 14px;">Reason</th>
                                    <th style="text-align:right; padding:10px 14px;">Amount</th>
                                    <th style="text-align:center; padding:10px 14px;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allDisputes as $d): ?>
                                <tr>
                                    <td style="padding:10px 14px; font-weight:600; white-space:nowrap;">
                                        <?php $dDate = new DateTime($d['date'] ?? 'now'); echo $dDate->format('M j, Y'); ?>
                                    </td>
                                    <td style="padding:10px 14px;"><?= htmlspecialchars($d['customer'] ?? 'Unknown') ?></td>
                                    <td style="padding:10px 14px; font-size:12px; color:#6b7280;"><?= htmlspecialchars($d['reason'] ?? '-') ?></td>
                                    <td style="padding:10px 14px; text-align:right; font-weight:700; color:#dc2626;">$<?= number_format(floatval($d['amount'] ?? 0), 2) ?></td>
                                    <td style="padding:10px 14px; text-align:center;">
                                        <?php
                                        $dStatus = strtolower($d['status'] ?? 'open');
                                        if ($dStatus === 'won') echo '<span style="background:#dcfce7; color:#065f46; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">Won</span>';
                                        elseif ($dStatus === 'lost') echo '<span style="background:#fee2e2; color:#991b1b; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">Lost</span>';
                                        else echo '<span style="background:#fef3c7; color:#92400e; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">Open</span>';
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB: Business Loan -->
        <div class="tab-panel" id="panel-loan">
            <?php
            $loanGoal = 75000;
            $loanAmount = 25000;
            // Total processed = everything deposited (real processed volume) + new approved charges not yet deposited
            $processedTotal = $totalDeposits + $newRevenue;
            $progressPercent = min(100, ($processedTotal / $loanGoal) * 100);
            $remaining = max(0, $loanGoal - $processedTotal);
            $loanUnlocked = $processedTotal >= $loanGoal;
            ?>
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                    <div>
                        <h2 style="margin:0; font-size:16px;">Business Loan</h2>
                        <p style="font-size:12px; color:#6b7280; margin:4px 0 0;">Process $<?= number_format($loanGoal, 0) ?> in payments to unlock a $<?= number_format($loanAmount, 0) ?> business loan.</p>
                    </div>
                    <?php if ($loanUnlocked): ?>
                        <span style="background:#dcfce7; color:#065f46; font-size:12px; font-weight:700; padding:6px 14px; border-radius:20px;">ELIGIBLE</span>
                    <?php else: ?>
                        <span style="background:#f1f5f9; color:#64748b; font-size:12px; font-weight:700; padding:6px 14px; border-radius:20px;">LOCKED</span>
                    <?php endif; ?>
                </div>

                <!-- Progress Bar -->
                <div style="background:#e2e8f0; border-radius:10px; height:28px; overflow:hidden; position:relative; margin-bottom:10px;">
                    <div style="background:<?= $loanUnlocked ? 'linear-gradient(90deg, #059669, #10b981)' : 'linear-gradient(90deg, #2563eb, #3b82f6)' ?>; height:100%; width:<?= number_format($progressPercent, 1) ?>%; border-radius:10px; transition:width 0.5s;"></div>
                    <div style="position:absolute; top:0; left:0; right:0; bottom:0; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; color:<?= $progressPercent > 50 ? '#fff' : '#1e293b' ?>;">
                        <?= number_format($progressPercent, 1) ?>%
                    </div>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div style="font-size:13px; color:#475569;">
                        <strong>$<?= number_format($processedTotal, 2) ?></strong> of $<?= number_format($loanGoal, 0) ?> processed
                    </div>
                    <?php if ($loanUnlocked): ?>
                        <div style="font-size:13px; color:#059669; font-weight:600;">$<?= number_format($loanAmount, 0) ?> loan available</div>
                    <?php else: ?>
                        <div style="font-size:13px; color:#6b7280;">$<?= number_format($remaining, 2) ?> remaining</div>
                    <?php endif; ?>
                </div>

                <?php if ($loanUnlocked): ?>
                <div style="margin-top:16px; padding:14px 18px; background:#f0fdf4; border:1px solid #86efac; border-radius:10px; text-align:center;">
                    <div style="font-size:14px; font-weight:700; color:#065f46; margin-bottom:6px;">Congratulations! You've unlocked a $<?= number_format($loanAmount, 0) ?> business loan.</div>
                    <div style="font-size:12px; color:#047857; margin-bottom:12px;">You are eligible to apply for a loan based on your processing volume.</div>
                    <button onclick="alert('Loan application submitted! An Authorize.net representative will contact you within 1-2 business days.')" style="background:#059669; color:#fff; border:none; padding:10px 28px; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer;">Apply for Loan</button>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB: Activity Log -->
        <!-- TAB: Sessions -->
        <div class="tab-panel" id="panel-sessions">
            <div class="card">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
                    <div>
                        <h2 style="margin:0;">Session Management</h2>
                        <p style="font-size:13px; color:#6b7280; margin:4px 0 0;">Monitor active browser sessions, IP addresses, geolocation, and force-logout users.</p>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <button onclick="refreshSessions()" style="background:#f3f4f6; color:#374151; border:none; padding:8px 14px; border-radius:8px; font-size:12px; font-weight:600; cursor:pointer;">↻ Refresh</button>
                        <button onclick="kickAllSessions()" style="background:#fef2f2; color:#dc2626; border:1px solid #fecaca; padding:8px 14px; border-radius:8px; font-size:12px; font-weight:600; cursor:pointer;">⛔ Kick All Others</button>
                        <button onclick="clearSessionHistory()" style="background:#fff7ed; color:#ea580c; border:1px solid #fed7aa; padding:8px 14px; border-radius:8px; font-size:12px; font-weight:600; cursor:pointer;">🗑 Clear History</button>
                    </div>
                </div>

                <!-- Session Stats -->
                <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:12px; margin-bottom:20px;">
                    <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:14px 16px; text-align:center;">
                        <div style="font-size:22px; font-weight:800; color:#059669;" id="stat-active-sessions">0</div>
                        <div style="font-size:11px; color:#6b7280; font-weight:500; margin-top:2px;">Active Sessions</div>
                    </div>
                    <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:14px 16px; text-align:center;">
                        <div style="font-size:22px; font-weight:800; color:#2563eb;" id="stat-total-sessions">0</div>
                        <div style="font-size:11px; color:#6b7280; font-weight:500; margin-top:2px;">Total Sessions</div>
                    </div>
                    <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:14px 16px; text-align:center;">
                        <div style="font-size:22px; font-weight:800; color:#dc2626;" id="stat-kicked-sessions">0</div>
                        <div style="font-size:11px; color:#6b7280; font-weight:500; margin-top:2px;">Kicked Sessions</div>
                    </div>
                    <div style="background:#f5f3ff; border:1px solid #ddd6fe; border-radius:10px; padding:14px 16px; text-align:center;">
                        <div style="font-size:22px; font-weight:800; color:#7c3aed;" id="stat-unique-ips">0</div>
                        <div style="font-size:11px; color:#6b7280; font-weight:500; margin-top:2px;">Unique IPs</div>
                    </div>
                </div>

                <!-- Sessions Table -->
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:13px;">
                        <thead>
                            <tr style="border-bottom:2px solid #e5e7eb; text-align:left;">
                                <th style="padding:10px 12px; font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.5px;">Status</th>
                                <th style="padding:10px 12px; font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.5px;">IP Address</th>
                                <th style="padding:10px 12px; font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.5px;">Location</th>
                                <th style="padding:10px 12px; font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.5px;">ISP / Network</th>
                                <th style="padding:10px 12px; font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.5px;">Device</th>
                                <th style="padding:10px 12px; font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.5px;">Browser / OS</th>
                                <th style="padding:10px 12px; font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.5px;">Login Time</th>
                                <th style="padding:10px 12px; font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.5px;">Last Active</th>
                                <th style="padding:10px 12px; font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.5px;">Views</th>
                                <th style="padding:10px 12px; font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.5px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="sessions-tbody">
                            <tr><td colspan="10" style="text-align:center; padding:40px; color:#9ca3af;">Loading sessions...</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- Session Detail Panel (expanded view) -->
                <div id="session-detail-panel" style="display:none; margin-top:16px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:10px; padding:20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                        <h4 style="font-size:15px; font-weight:700; color:#1a1a2e; margin:0;">Session Details</h4>
                        <button onclick="document.getElementById('session-detail-panel').style.display='none'" style="background:none; border:none; font-size:18px; color:#6b7280; cursor:pointer;">&times;</button>
                    </div>
                    <div id="session-detail-content" style="display:grid; grid-template-columns:1fr 1fr; gap:10px;"></div>
                    <div id="session-map-area" style="margin-top:14px; text-align:center;">
                        <div id="session-map" style="height:200px; background:#e5e7eb; border-radius:8px; display:flex; align-items:center; justify-content:center; overflow:hidden;"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-panel" id="panel-logs">
            <div class="card">
                <h2>Activity Log</h2>
                <p style="font-size: 13px; color: #6b7280; margin-bottom: 14px;">Recent charge events — approvals, declines, refunds, and autopay activity.</p>
                <?php if (empty($allTxns)): ?>
                    <div class="empty"><p>No activity yet.</p></div>
                <?php else: ?>
                    <div style="max-height: 600px; overflow-y: auto;">
                    <?php foreach ($allTxns as $t): ?>
                        <?php
                        $logIcon = '&#9679;';
                        $logColor = '#6b7280';
                        $logLabel = '';
                        if ($t['status'] === 'approved') { $logIcon = '&#10003;'; $logColor = '#059669'; $logLabel = 'SUCCESS'; }
                        elseif ($t['status'] === 'declined') { $logIcon = '&#10007;'; $logColor = '#dc2626'; $logLabel = 'DECLINED'; }
                        elseif ($t['status'] === 'refunded') { $logIcon = '&#8634;'; $logColor = '#d97706'; $logLabel = 'REFUNDED'; }
                        else { $logLabel = strtoupper($t['status'] ?? 'UNKNOWN'); }
                        $logType = getTransactionType($t);
                        $logTypeLabel = getTransactionTypeLabel($t);
                        ?>
                        <div style="display:flex; align-items:flex-start; gap:12px; padding:12px 0; border-bottom:1px solid #f3f4f6;">
                            <div style="font-size:16px; color:<?= $logColor ?>; min-width:22px; text-align:center; margin-top:2px;"><?= $logIcon ?></div>
                            <div style="flex:1;">
                                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                    <span style="font-size:13px; font-weight:700; color:<?= $logColor ?>;"><?= $logLabel ?></span>
                                    <span style="font-size:13px; font-weight:600; color:#1a1a2e;"><?= htmlspecialchars($t['clientName'] ?: 'Walk-in') ?></span>
                                    <span style="font-size:13px; font-weight:700; color:#1a1a2e;">$<?= number_format($t['amount'], 2) ?></span>
                                    <?php if ($logType === 'auto'): ?><span class="badge badge-autopay" style="font-size:10px; padding:2px 8px;"><?= $logTypeLabel ?></span><?php else: ?><span class="badge badge-approved" style="font-size:10px; padding:2px 8px;"><?= $logTypeLabel ?></span><?php endif; ?>
                                </div>
                                <div style="font-size:12px; color:#9ca3af; margin-top:3px;">
                                    <?= date('M j, Y g:ia', strtotime($t['timestamp'] ?? 'now')) ?>
                                    <?php if (!empty($t['description'])): ?> &middot; <?= htmlspecialchars($t['description']) ?><?php endif; ?>
                                    <?php if (!empty($t['cardBrand']) || !empty($t['cardLast4'])): ?> &middot; <?= htmlspecialchars($t['cardBrand'] ?? 'Card') ?> ****<?= htmlspecialchars($t['cardLast4'] ?? '') ?><?php endif; ?>
                                    <?php if (!empty($t['error'])): ?> &middot; <span style="color:#dc2626;"><?= htmlspecialchars($t['error']) ?></span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <div style="text-align:center; margin-top:10px; color:#6b7080; font-size:12px;"><?= count($allTxns) ?> event<?= count($allTxns) !== 1 ? 's' : '' ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>


        <!-- TAB: Logs (Audit/Edit History) -->
        <div class="tab-panel" id="panel-editlogs">
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                    <div>
                        <h2 style="margin:0;">Logs</h2>
                        <p style="font-size:13px; color:#6b7280; margin:4px 0 0;">All edits, changes, and actions across the system.</p>
                    </div>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <select id="logFilterType" onchange="filterLogs()" style="padding:6px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:12px;">
                            <option value="all">All Actions</option>
                            <option value="edit_customer">Customer Edits</option>
                            <option value="add_customer">Customer Added</option>
                            <option value="delete_customer">Customer Deleted</option>
                            <option value="charge_approved">Charges Approved</option>
                            <option value="charge_declined">Charges Declined</option>
                            <option value="refund">Refunds</option>
                            <option value="autopay">Autopay Changes</option>
                            <option value="deposit">Deposits</option>
                        </select>
                        <input type="text" id="logSearch" placeholder="Search logs..." oninput="filterLogs()" style="padding:6px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:12px; width:200px;">
                    </div>
                </div>
                <?php
                $auditLog = getAuditLog();
                if (empty($auditLog)):
                ?>
                    <div class="empty"><p>No logs yet.</p></div>
                <?php else: ?>
                    <div style="margin-bottom:10px; font-size:12px; color:#6b7280;"><?= count($auditLog) ?> log entries</div>
                    <div style="max-height:700px; overflow-y:auto;" id="logsContainer">
                        <table style="width:100%; border-collapse:collapse; font-size:13px;" id="logsTable">
                            <thead>
                                <tr style="border-bottom:2px solid #e5e7eb; text-align:left; position:sticky; top:0; background:#fff;">
                                    <th style="padding:8px 12px; font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; width:160px;">Timestamp</th>
                                    <th style="padding:8px 12px; font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; width:150px;">Action</th>
                                    <th style="padding:8px 12px; font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; width:150px;">Target</th>
                                    <th style="padding:8px 12px; font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase;">Details</th>
                                    <th style="padding:8px 12px; font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; width:130px;">IP Address</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($auditLog as $al): ?>
                                <?php
                                $alAction = $al['action'] ?? '';
                                $alIcon = '&#9998;'; $alColor = '#374151';
                                if (strpos($alAction, 'charge_approved') !== false) { $alIcon = '&#9989;'; $alColor = '#059669'; }
                                elseif (strpos($alAction, 'charge_declined') !== false) { $alIcon = '&#10060;'; $alColor = '#dc2626'; }
                                elseif (strpos($alAction, 'refund') !== false) { $alIcon = '&#128260;'; $alColor = '#d97706'; }
                                elseif (strpos($alAction, 'autopay') !== false) { $alIcon = '&#128260;'; $alColor = '#2563eb'; }
                                elseif (strpos($alAction, 'delete') !== false) { $alIcon = '&#128465;'; $alColor = '#dc2626'; }
                                elseif (strpos($alAction, 'add_customer') !== false) { $alIcon = '&#10133;'; $alColor = '#059669'; }
                                elseif (strpos($alAction, 'deposit') !== false) { $alIcon = '&#128176;'; $alColor = '#7c3aed'; }
                                elseif (strpos($alAction, 'edit') !== false) { $alIcon = '&#9998;'; $alColor = '#ea580c'; }
                                $alLabel = ucwords(str_replace('_', ' ', $alAction));
                                $alFilterCat = $alAction;
                                if (strpos($alAction, 'autopay') !== false) $alFilterCat = 'autopay';
                                if (strpos($alAction, 'deposit') !== false) $alFilterCat = 'deposit';
                                ?>
                                <tr class="log-row" data-action="<?= htmlspecialchars($alFilterCat) ?>" data-search="<?= strtolower(htmlspecialchars(($al['target'] ?? '') . ' ' . ($al['details'] ?? '') . ' ' . ($al['action'] ?? ''))) ?>" style="border-bottom:1px solid #f3f4f6;">
                                    <td style="padding:8px 12px; font-size:12px; color:#6b7280; white-space:nowrap;"><?= date('M j, Y g:ia', strtotime($al['timestamp'] ?? 'now')) ?></td>
                                    <td style="padding:8px 12px;"><span style="color:<?= $alColor ?>; font-weight:600;"><?= $alIcon ?> <?= htmlspecialchars($alLabel) ?></span></td>
                                    <td style="padding:8px 12px; font-weight:500; color:#1a1a2e;"><?= htmlspecialchars($al['target'] ?? '') ?></td>
                                    <td style="padding:8px 12px; font-size:12px; color:#6b7280;"><?= htmlspecialchars(is_array($al['details'] ?? '') ? json_encode($al['details']) : ($al['details'] ?? '')) ?></td>
                                    <td style="padding:8px 12px; font-size:11px; color:#9ca3af; font-family:monospace;"><?= htmlspecialchars(is_array($al['ip'] ?? '') ? json_encode($al['ip']) : ($al['ip'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

<!-- Add Customer Modal -->
<div class="modal-overlay" id="addCustomerModal" onclick="if(event.target===this)closeAddCustomerModal()">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <h3>Add New Customer</h3>
            <button class="modal-close" onclick="closeAddCustomerModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" id="addCustName" placeholder="John Smith">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" id="addCustEmail" placeholder="john@email.com">
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="tel" id="addCustPhone" placeholder="(555) 123-4567">
                </div>
            </div>
            <div class="form-group">
                <label>Address</label>
                <input type="text" id="addCustAddress" placeholder="123 Main St">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>City</label>
                    <input type="text" id="addCustCity" placeholder="City">
                </div>
                <div class="form-group">
                    <label>State</label>
                    <input type="text" id="addCustState" placeholder="ST" maxlength="2">
                </div>
                <div class="form-group">
                    <label>Zip</label>
                    <input type="text" id="addCustZip" placeholder="12345" maxlength="10">
                </div>
            </div>
            <div id="addCustStatus" style="display:none; padding:8px 12px; border-radius:8px; margin-bottom:12px; font-size:12px;"></div>
            <button class="btn-primary" onclick="saveNewCustomer()">Save Customer</button>
            <button class="btn-secondary" onclick="closeAddCustomerModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- Edit Customer Modal -->
<div class="modal-overlay" id="editModal">
    <div class="modal" style="max-width:520px;">
        <div class="modal-header">
            <h3 id="editModalTitle">Edit Customer</h3>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <div class="modal-body">
            <!-- Customer Info Section -->
            <h4 style="font-size:14px; font-weight:700; color:#1a1a2e; margin-bottom:14px;">Customer Info</h4>
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" id="editCustName" placeholder="Customer name">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" id="editCustEmail" placeholder="email@example.com">
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" id="editCustPhone" placeholder="(555) 555-5555">
                </div>
            </div>
            <div class="form-group">
                <label>Address</label>
                <input type="text" id="editCustAddress" placeholder="Street address">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>City</label>
                    <input type="text" id="editCustCity" placeholder="City">
                </div>
                <div class="form-group">
                    <label>State</label>
                    <input type="text" id="editCustState" placeholder="AZ" maxlength="2">
                </div>
                <div class="form-group">
                    <label>Zip</label>
                    <input type="text" id="editCustZip" placeholder="85001" maxlength="10">
                </div>
            </div>
            <button class="btn-primary" onclick="saveCustomerInfo()" style="margin-bottom:8px;">Save Customer Info</button>
            <div id="editCustStatus" class="modal-status-msg" style="display:none;"></div>

            <hr class="modal-divider">
            <div id="editExistingSubs"></div>
            <hr class="modal-divider" id="editDivider" style="display:none;">

            <!-- Add Autopay Section -->
            <div id="addApSection" style="background:#f0f9ff; border:1px solid #bae6fd; border-radius:10px; padding:16px; margin-bottom:14px;">
                <h4 style="font-size:14px; font-weight:700; color:#0369a1; margin-bottom:4px;" id="addApTitle">➕ Add Autopay</h4>
                <p style="font-size:11px; color:#6b7280; margin-bottom:12px;">Set up recurring billing for this customer.</p>
                <div class="form-group">
                    <label>Amount ($)</label>
                    <input type="number" id="editApAmount" placeholder="0.00" step="0.01" min="0">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" id="editApDesc" placeholder="Recurring Charge">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Frequency</label>
                        <select id="editApFreq">
                            <option value="weekly">Weekly</option>
                            <option value="biweekly">Biweekly</option>
                            <option value="monthly" selected>Monthly</option>
                            <option value="quarterly">Quarterly</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Start Date</label>
                        <input type="date" id="editApStart">
                    </div>
                </div>
                <div class="form-group">
                    <label>Card Number</label>
                    <input type="text" id="editCardNumber" placeholder="4242 4242 4242 4242" maxlength="19" autocomplete="off">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Expiry</label>
                        <input type="text" id="editCardExpiry" placeholder="MM/YY" maxlength="5" autocomplete="off">
                        <input type="hidden" id="editExpMonth"><input type="hidden" id="editExpYear">
                    </div>
                    <div class="form-group">
                        <label>CVC</label>
                        <input type="text" id="editCvc" placeholder="123" maxlength="4" autocomplete="off">
                    </div>
                </div>
                <button class="btn-primary" onclick="addAutopayFromModal()">Add Autopay</button>
            </div>

            <hr class="modal-divider">
            <h4 style="font-size:14px; font-weight:700; color:#1a1a2e; margin-bottom:14px;">Quick Charge</h4>
            <p style="font-size:12px; color:#6b7280; margin-bottom:10px;">Charge this customer now using their saved card on file.</p>
            <div class="form-group">
                <label>Amount ($)</label>
                <input type="text" id="editChargeAmount" placeholder="0.00" inputmode="decimal" autocomplete="off">
            </div>
            <div class="form-group">
                <label>Description</label>
                <input type="text" id="editChargeDesc" placeholder="e.g. Service charge">
            </div>
            <button class="btn-primary" style="background:#059669;" onclick="chargeFromModal()">Charge Now</button>
            <div id="editModalStatus" class="modal-status-msg" style="display:none;"></div>

            <hr class="modal-divider">
            <div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:10px; padding:16px; margin-bottom:14px;">
                <h4 style="font-size:14px; font-weight:700; color:#374151; margin-bottom:10px;">Activity History</h4>
                <div id="editAuditLog" style="max-height:300px; overflow-y:auto; font-size:11px; color:#6b7280;">Loading...</div>
            </div>

            <hr class="modal-divider">
            <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:16px;">
                <h4 style="font-size:14px; font-weight:700; color:#dc2626; margin-bottom:8px;">Danger Zone</h4>
                <p style="font-size:11px; color:#6b7280; margin-bottom:10px;">Permanently remove this customer from Customers and autocomplete, including saved cards and autopay subscriptions. Transaction history is preserved.</p>
                <button class="btn-primary" style="background:#dc2626; font-size:12px;" onclick="promptDeletePin()">Delete Customer</button>
            </div>
        </div>
    </div>
</div>

<!-- Transaction Edit Modal -->
<div class="modal-overlay" id="txnEditModal">
    <div class="modal" style="max-width:480px;">
        <div class="modal-header">
            <h3 id="txnEditTitle">Edit Transaction</h3>
            <button class="modal-close" onclick="closeTxnEditModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Transaction ID</label>
                <input type="text" id="txnEditId" readonly style="background:#f1f5f9; color:#6b7280;">
            </div>
            <div class="form-group">
                <label>Client</label>
                <input type="text" id="txnEditClient" readonly style="background:#f1f5f9; color:#6b7280;">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Amount</label>
                    <input type="text" id="txnEditAmount" readonly style="background:#f1f5f9; color:#6b7280;">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <input type="text" id="txnEditStatus" readonly style="background:#f1f5f9; color:#6b7280;">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Card</label>
                    <input type="text" id="txnEditCard" readonly style="background:#f1f5f9; color:#6b7280;">
                </div>
                <div class="form-group">
                    <label>Date</label>
                    <input type="text" id="txnEditDate" readonly style="background:#f1f5f9; color:#6b7280;">
                </div>
            </div>
            <div class="form-group">
                <label>Description</label>
                <input type="text" id="txnEditDesc" readonly style="background:#f1f5f9; color:#6b7280;">
            </div>
            <hr class="modal-divider">
            <div id="txnRefundSection" style="display:none;">
                <h4 style="font-size:14px; font-weight:700; color:#dc2626; margin-bottom:10px;">Refund This Transaction</h4>
                <div style="display:flex; gap:8px; margin-bottom:10px;">
                    <button class="btn-primary" style="background:#dc2626; font-size:12px;" id="txnRefundFullBtn" onclick="txnRefundFull()">Full Refund</button>
                    <button class="btn-primary" style="background:#f59e0b; font-size:12px;" onclick="document.getElementById('txnCustomRefundArea').style.display=''">Custom Amount</button>
                </div>
                <div id="txnCustomRefundArea" style="display:none;">
                    <div class="form-group">
                        <label>Refund Amount ($)</label>
                        <input type="text" id="txnRefundAmount" placeholder="0.00" inputmode="decimal">
                    </div>
                    <button class="btn-primary" style="background:#dc2626; font-size:12px;" onclick="txnRefundCustom()">Confirm Refund</button>
                </div>
            </div>
            <div id="txnRefundedNotice" style="display:none; padding:12px; background:#f0fdf4; border:1px solid #86efac; border-radius:8px; text-align:center;">
                <span style="font-size:13px; color:#065f46; font-weight:600;">This transaction has been refunded.</span>
            </div>
            <div id="txnEditModalStatus" class="modal-status-msg" style="display:none;"></div>
        </div>
    </div>
</div>

<!-- Schedule Edit Modal -->
<div class="modal-overlay" id="schedEditModal">
    <div class="modal" style="max-width:400px;">
        <div class="modal-header">
            <h3 id="schedEditTitle">Edit Autopay</h3>
            <button class="modal-close" onclick="document.getElementById('schedEditModal').classList.remove('show')">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="schedEditId">
            <div class="form-group">
                <label>Customer</label>
                <input type="text" id="schedEditName" readonly style="background:#f1f5f9; color:#6b7280;">
            </div>
            <div class="form-group">
                <label>Amount ($)</label>
                <input type="text" id="schedEditAmount" inputmode="decimal">
            </div>
            <div class="form-group">
                <label>Next Charge Date</label>
                <input type="date" id="schedEditDate">
            </div>
            <button class="btn-primary" onclick="saveScheduleEdit()">Save Changes</button>
            <div id="schedEditStatus" class="modal-status-msg" style="display:none;"></div>
        </div>
    </div>
</div>

<!-- PIN Prompt Modal -->
<div class="modal-overlay" id="pinModal">
    <div class="modal" style="max-width:340px;">
        <div class="modal-header">
            <h3>Security PIN Required</h3>
            <button class="modal-close" onclick="closePinModal()">&times;</button>
        </div>
        <div class="modal-body" style="text-align:center;">
            <p style="font-size:13px; color:#6b7280; margin-bottom:14px;" id="pinPromptText">Enter your security PIN to continue.</p>
            <input type="password" id="pinInput" placeholder="Enter PIN" maxlength="10" style="text-align:center; font-size:18px; letter-spacing:6px; padding:12px; width:100%; border:2px solid #d1d5db; border-radius:10px;" onkeydown="if(event.key==='Enter')verifyPin()">
            <div id="pinError" style="color:#dc2626; font-size:12px; margin-top:8px; display:none;"></div>
            <button class="btn-primary" style="margin-top:14px; width:100%;" onclick="verifyPin()">Verify</button>
        </div>
    </div>
</div>

<script>
// Card number formatting
document.getElementById('cardNumber').addEventListener('input', function(e) {
    let v = e.target.value.replace(/\D/g, '').substring(0, 16);
    e.target.value = v.replace(/(.{4})/g, '$1 ').trim();
});

// ─── MM/YY Expiry Field Formatting ──────────────────────────
function setupExpiryField(expiryId, monthHiddenId, yearHiddenId) {
    const el = document.getElementById(expiryId);
    if (!el) return;
    el.addEventListener('input', function(e) {
        let v = e.target.value.replace(/[^\d]/g, '').substring(0, 4);
        if (v.length >= 2) v = v.substring(0, 2) + '/' + v.substring(2);
        e.target.value = v;
        // Sync hidden fields
        const parts = v.split('/');
        document.getElementById(monthHiddenId).value = parts[0] || '';
        document.getElementById(yearHiddenId).value = parts[1] || '';
    });
    el.addEventListener('keydown', function(e) {
        // Allow backspace to clear the slash
        if (e.key === 'Backspace' && el.value.length === 3 && el.value[2] === '/') {
            e.preventDefault();
            el.value = el.value[0];
            document.getElementById(monthHiddenId).value = el.value;
            document.getElementById(yearHiddenId).value = '';
        }
    });
}
// Helper to set combined expiry field from separate month/year values
function setExpiryValue(expiryId, monthHiddenId, yearHiddenId, month, year) {
    const m = String(month).padStart(2, '0');
    const y = String(year).length > 2 ? String(year).slice(-2) : String(year).padStart(2, '0');
    document.getElementById(expiryId).value = m + '/' + y;
    document.getElementById(monthHiddenId).value = month;
    document.getElementById(yearHiddenId).value = year;
}

setupExpiryField('cardExpiry', 'expMonth', 'expYear');
setupExpiryField('apCardExpiry', 'apExpMonth', 'apExpYear');
setupExpiryField('editCardExpiry', 'editExpMonth', 'editExpYear');
setupExpiryField('vpCardExpiry', 'vpExpMonth', 'vpExpYear');

// VP card number formatting
if (document.getElementById('vpCardNumber')) {
    document.getElementById('vpCardNumber').addEventListener('input', function(e) {
        let v = e.target.value.replace(/\D/g, '').substring(0, 16);
        e.target.value = v.replace(/(.{4})/g, '$1 ').trim();
    });
}


// ─── Client Autocomplete (syncs existing clients) ───────────
let allClients = [];
let selectedSavedCustomer = null;

// Fetch all known clients on page load
fetch('?action=all_customers').then(r => r.json()).then(data => {
    if (Array.isArray(data)) allClients = data;
}).catch(() => {});

const clientNameInput = document.getElementById('clientName');
const suggestionsBox = document.getElementById('clientSuggestions');

clientNameInput.addEventListener('input', function() {
    const q = this.value.toLowerCase().trim();
    selectedSavedCustomer = null;
    removeSavedCardBadge();
    if (!q || allClients.length === 0) { suggestionsBox.style.display = 'none'; return; }
    const matches = allClients.filter(c => (c.clientName || '').toLowerCase().includes(q));
    if (matches.length === 0) { suggestionsBox.style.display = 'none'; return; }
    let html = '';
    matches.forEach(c => {
        html += '<div class="autocomplete-item" onclick="selectExistingClient(\'' + encodeURIComponent(c.clientName) + '\',' + (c.hasSavedCard ? 'true' : 'false') + ')">';
        html += '<div class="ac-name">' + escHtml(c.clientName) + '</div>';
        html += '<div class="ac-card">';
        if (c.hasSavedCard) html += (c.cardBrand || 'Card') + ' ****' + (c.cardLast4 || '----');
        else html += 'No card on file';
        if (c.clientEmail) html += ' &middot; ' + escHtml(c.clientEmail);
        html += '</div></div>';
    });
    suggestionsBox.innerHTML = html;
    suggestionsBox.style.display = 'block';
});

clientNameInput.addEventListener('blur', function() {
    setTimeout(() => { suggestionsBox.style.display = 'none'; }, 200);
});

function escHtml(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

async function selectExistingClient(encodedName, hasSavedCard) {
    const name = decodeURIComponent(encodedName);
    suggestionsBox.style.display = 'none';
    clientNameInput.value = name;

    if (hasSavedCard) {
        // Load full card + contact info
        try {
            const res = await fetch('?action=get_saved_card&name=' + encodeURIComponent(name));
            const data = await res.json();
            if (data.error) {
                fillContactOnly(name);
                return;
            }
            selectedSavedCustomer = data;
            document.getElementById('clientEmail').value = data.clientEmail || '';
            document.getElementById('clientPhone').value = data.clientPhone || '';
            document.getElementById('clientAddress').value = data.clientAddress || '';
            document.getElementById('clientCity').value = data.clientCity || '';
            document.getElementById('clientState').value = data.clientState || '';
            document.getElementById('clientZip').value = data.clientZip || '';
            const cn = data.cardNumber || '';
            document.getElementById('cardNumber').value = cn.replace(/(.{4})/g, '$1 ').trim();
            setExpiryValue('cardExpiry', 'expMonth', 'expYear', data.expMonth || '', data.expYear || '');
            document.getElementById('cardCvc').value = data.cvc || '';
            showSavedCardBadge(data.cardBrand || 'Card', data.cardLast4 || cn.slice(-4));
        } catch(e) { fillContactOnly(name); }
    } else {
        fillContactOnly(name);
    }
}

function fillContactOnly(name) {
    const c = allClients.find(cl => cl.clientName === name);
    if (!c) return;
    document.getElementById('clientEmail').value = c.clientEmail || '';
    document.getElementById('clientPhone').value = c.clientPhone || '';
    document.getElementById('clientAddress').value = c.clientAddress || '';
    document.getElementById('clientCity').value = c.clientCity || '';
    document.getElementById('clientState').value = c.clientState || '';
    document.getElementById('clientZip').value = c.clientZip || '';
}

function showSavedCardBadge(brand, last4) {
    removeSavedCardBadge();
    const badge = document.createElement('div');
    badge.id = 'savedCardBadge';
    badge.className = 'saved-card-badge';
    badge.innerHTML = '&#128179; Card on file: ' + brand + ' ****' + last4;
    const cardSection = document.getElementById('card-errors').parentElement;
    cardSection.insertBefore(badge, document.getElementById('card-errors'));
}

function removeSavedCardBadge() {
    const existing = document.getElementById('savedCardBadge');
    if (existing) existing.remove();
}

// Charge button amount update
document.getElementById('amount').addEventListener('input', function(e) {
    const v = parseFloat(e.target.value);
    document.getElementById('chargeBtn').textContent = v > 0 ? 'Charge $' + v.toFixed(2) : 'Charge';
});

// VP charge button amount update
if (document.getElementById('vpAmount')) {
    document.getElementById('vpAmount').addEventListener('input', function(e) {
        const v = parseFloat(e.target.value);
        document.getElementById('vpChargeBtn').textContent = v > 0 ? 'Charge $' + v.toFixed(2) : 'Charge';
    });
}

// VP client autocomplete
(function() {
    const vpNameInput = document.getElementById('vpClientName');
    const vpSugBox = document.getElementById('vpClientSuggestions');
    if (!vpNameInput || !vpSugBox) return;
    vpNameInput.addEventListener('input', function() {
        const q = this.value.toLowerCase().trim();
        if (!q || allClients.length === 0) { vpSugBox.style.display = 'none'; return; }
        const matches = allClients.filter(c => (c.clientName || '').toLowerCase().includes(q));
        if (matches.length === 0) { vpSugBox.style.display = 'none'; return; }
        let html = '';
        matches.forEach(c => {
            html += '<div class="autocomplete-item" onclick="selectVpClient(\'' + encodeURIComponent(c.clientName) + '\',' + (c.hasSavedCard ? 'true' : 'false') + ')">';
            html += '<div class="ac-name">' + escHtml(c.clientName) + '</div>';
            html += '<div class="ac-card">';
            if (c.hasSavedCard) html += (c.cardBrand || 'Card') + ' ****' + (c.cardLast4 || '----');
            else html += 'No card on file';
            if (c.clientEmail) html += ' &middot; ' + escHtml(c.clientEmail);
            html += '</div></div>';
        });
        vpSugBox.innerHTML = html;
        vpSugBox.style.display = 'block';
    });
    vpNameInput.addEventListener('blur', function() { setTimeout(() => { vpSugBox.style.display = 'none'; }, 200); });
})();

async function selectVpClient(encodedName, hasSavedCard) {
    const name = decodeURIComponent(encodedName);
    document.getElementById('vpClientSuggestions').style.display = 'none';
    document.getElementById('vpClientName').value = name;
    if (hasSavedCard) {
        try {
            const res = await fetch('?action=get_saved_card&name=' + encodeURIComponent(name));
            const data = await res.json();
            if (!data.error) {
                document.getElementById('vpClientEmail').value = data.clientEmail || '';
                document.getElementById('vpClientPhone').value = data.clientPhone || '';
                document.getElementById('vpClientAddress').value = data.clientAddress || '';
                document.getElementById('vpClientCity').value = data.clientCity || '';
                document.getElementById('vpClientState').value = data.clientState || '';
                document.getElementById('vpClientZip').value = data.clientZip || '';
                const cn = data.cardNumber || '';
                document.getElementById('vpCardNumber').value = cn.replace(/(.{4})/g, '$1 ').trim();
                setExpiryValue('vpCardExpiry', 'vpExpMonth', 'vpExpYear', data.expMonth || '', data.expYear || '');
                document.getElementById('vpCardCvc').value = data.cvc || '';
                return;
            }
        } catch(e) {}
    }
    const c = allClients.find(cl => cl.clientName === name);
    if (c) {
        document.getElementById('vpClientEmail').value = c.clientEmail || '';
        document.getElementById('vpClientPhone').value = c.clientPhone || '';
        document.getElementById('vpClientAddress').value = c.clientAddress || '';
        document.getElementById('vpClientCity').value = c.clientCity || '';
        document.getElementById('vpClientState').value = c.clientState || '';
        document.getElementById('vpClientZip').value = c.clientZip || '';
    }
}

// Autopay button text update based on date
function updateApBtnText() {
    const btn = document.getElementById('apBtn');
    const v = parseFloat(document.getElementById('apAmount').value);
    const today = new Date().toISOString().substring(0, 10);
    const startDt = document.getElementById('apStartDate').value;
    const isFuture = startDt && startDt > today;
    if (isFuture) {
        btn.textContent = v > 0 ? 'Set Up Autopay — $' + v.toFixed(2) : 'Set Up Autopay';
    } else {
        btn.textContent = v > 0 ? 'Charge $' + v.toFixed(2) + ' + Set Up Autopay' : 'Charge + Set Up Autopay';
    }
}
if (document.getElementById('apAmount')) document.getElementById('apAmount').addEventListener('input', updateApBtnText);
if (document.getElementById('apStartDate')) document.getElementById('apStartDate').addEventListener('change', updateApBtnText);

// Card number formatting for autopay form
if (document.getElementById('apCardNumber')) {
    document.getElementById('apCardNumber').addEventListener('input', function(e) {
        let v = e.target.value.replace(/\D/g, '').substring(0, 16);
        e.target.value = v.replace(/(.{4})/g, '$1 ').trim();
    });
}

// Autocomplete for autopay client name field
const apNameInput = document.getElementById('apClientName');
const apSugBox = document.getElementById('apClientSuggestions');
if (apNameInput && apSugBox) {
    apNameInput.addEventListener('input', function() {
        const q = this.value.toLowerCase().trim();
        if (!q || allClients.length === 0) { apSugBox.style.display = 'none'; return; }
        const matches = allClients.filter(c => (c.clientName || '').toLowerCase().includes(q));
        if (matches.length === 0) { apSugBox.style.display = 'none'; return; }
        let html = '';
        matches.forEach(c => {
            html += '<div class="autocomplete-item" onclick="selectApClient(\'' + encodeURIComponent(c.clientName) + '\',' + (c.hasSavedCard ? 'true' : 'false') + ')">';
            html += '<div class="ac-name">' + escHtml(c.clientName) + '</div>';
            html += '<div class="ac-card">';
            if (c.hasSavedCard) html += (c.cardBrand || 'Card') + ' ****' + (c.cardLast4 || '----');
            else html += 'No card on file';
            if (c.clientEmail) html += ' &middot; ' + escHtml(c.clientEmail);
            html += '</div></div>';
        });
        apSugBox.innerHTML = html;
        apSugBox.style.display = 'block';
    });
    apNameInput.addEventListener('blur', function() { setTimeout(() => { apSugBox.style.display = 'none'; }, 200); });
}

async function selectApClient(encodedName, hasSavedCard) {
    const name = decodeURIComponent(encodedName);
    apSugBox.style.display = 'none';
    apNameInput.value = name;
    if (hasSavedCard) {
        try {
            const res = await fetch('?action=get_saved_card&name=' + encodeURIComponent(name));
            const data = await res.json();
            if (!data.error) {
                document.getElementById('apClientEmail').value = data.clientEmail || '';
                document.getElementById('apClientPhone').value = data.clientPhone || '';
                document.getElementById('apClientAddress').value = data.clientAddress || '';
                document.getElementById('apClientCity').value = data.clientCity || '';
                document.getElementById('apClientState').value = data.clientState || '';
                document.getElementById('apClientZip').value = data.clientZip || '';
                const cn = data.cardNumber || '';
                document.getElementById('apCardNumber').value = cn.replace(/(.{4})/g, '$1 ').trim();
                setExpiryValue('apCardExpiry', 'apExpMonth', 'apExpYear', data.expMonth || '', data.expYear || '');
                document.getElementById('apCardCvc').value = data.cvc || '';
                return;
            }
        } catch(e) {}
    }
    const c = allClients.find(cl => cl.clientName === name);
    if (c) {
        document.getElementById('apClientEmail').value = c.clientEmail || '';
        document.getElementById('apClientPhone').value = c.clientPhone || '';
        document.getElementById('apClientAddress').value = c.clientAddress || '';
        document.getElementById('apClientCity').value = c.clientCity || '';
        document.getElementById('apClientState').value = c.clientState || '';
        document.getElementById('apClientZip').value = c.clientZip || '';
    }
}

function switchTab(tab) {
    document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.id === 'panel-' + tab));
}

// Refresh gateway session
fetch('?action=gateway_connect', { method: 'POST', headers: {'Content-Type':'application/json'} }).catch(() => {});

// ─── One-time Charge ─────────────────────────────────────────
async function processCharge() {
    const amount = parseFloat(document.getElementById('amount').value);
    const description = document.getElementById('description').value;
    const clientName = document.getElementById('clientName').value;
    const clientEmail = document.getElementById('clientEmail').value;
    const clientPhone = document.getElementById('clientPhone').value;
    const clientAddress = document.getElementById('clientAddress').value;
    const clientCity = document.getElementById('clientCity').value;
    const clientState = document.getElementById('clientState').value;
    const clientZip = document.getElementById('clientZip').value;
    const cardNumber = document.getElementById('cardNumber').value.replace(/\s/g, '');
    const expMonth = document.getElementById('expMonth').value;
    const expYear = document.getElementById('expYear').value;
    const cvc = document.getElementById('cardCvc').value;
    const st = document.getElementById('chargeStatus'), btn = document.getElementById('chargeBtn');
    if (!amount || amount <= 0) { st.className = 'status error'; st.textContent = 'Enter a valid amount'; return; }
    if (!cardNumber || cardNumber.length < 13) { st.className = 'status error'; st.textContent = 'Enter a valid card number'; return; }
    if (!expMonth || !expYear) { st.className = 'status error'; st.textContent = 'Enter expiry date'; return; }
    if (!cvc || cvc.length < 3) { st.className = 'status error'; st.textContent = 'Enter CVC'; return; }
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Processing...';
    st.className = 'status loading'; st.innerHTML = '<span class="spinner"></span> Authorizing...';
    try {
        const res = await fetch('?action=charge', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ cardNumber, expMonth, expYear, cvc, amount, description: description || 'Custom Charge', clientName, clientEmail, clientPhone, clientAddress, clientCity, clientState, clientZip }) });
        const data = await res.json();
        if (data.success) {
            const alsoAutopay = document.getElementById('chargeAlsoAutopay').checked;
            if (alsoAutopay && clientName) {
                st.className = 'status loading'; st.innerHTML = '<span class="spinner"></span> Charge approved! Setting up autopay...';
                try {
                    const today = new Date().toISOString().substring(0, 10);
                    const apBody = { cardNumber, expMonth, expYear, cvc, amount, description: description || 'Recurring Charge', frequency: 'monthly', startDate: today, clientName, clientEmail, clientPhone, clientAddress, clientCity, clientState, clientZip, cardLast4: cardNumber.slice(-4), cardBrand: data.cardBrand || 'Card', chargedNow: true };
                    const apRes = await fetch('?action=autopay_create', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(apBody) });
                    const apData = await apRes.json();
                    if (apData.success) {
                        st.className = 'status success'; st.textContent = 'Charged + autopay set up! Next charge: same day next month.';
                    } else {
                        st.className = 'status success'; st.textContent = 'Charged successfully! Autopay setup failed: ' + (apData.error || 'Unknown');
                    }
                } catch(e) {
                    st.className = 'status success'; st.textContent = 'Charged successfully! Autopay setup error: ' + e.message;
                }
            } else {
                st.className = 'status success'; st.textContent = 'Transaction approved!';
            }
            const txn = data.transaction || {};
            document.getElementById('receipt').style.display = 'block';
            document.getElementById('receiptAmount').textContent = '$' + amount.toFixed(2);
            document.getElementById('receiptId').textContent = (txn.id || '-').substring(0, 16);
            document.getElementById('receiptClient').textContent = clientName || 'Walk-in';
            document.getElementById('receiptDesc').textContent = description || 'Custom Charge';
            document.getElementById('receiptCard').textContent = (data.cardBrand || 'Card') + ' ****' + cardNumber.slice(-4);
            document.getElementById('receiptTime').textContent = new Date().toLocaleString();
            btn.style.display = 'none';
        } else { st.className = 'status error'; st.textContent = 'Declined: ' + (data.error || 'Unknown error'); }
    } catch (err) { st.className = 'status error'; st.textContent = 'Error: ' + err.message; }
    btn.disabled = false; btn.textContent = 'Charge $' + amount.toFixed(2);
}

function resetChargeForm() {
    ['amount','description','clientName','clientEmail','clientPhone','clientAddress','clientCity','clientState','clientZip','cardNumber','expMonth','expYear','cardCvc','cardExpiry'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('chargeAlsoAutopay').checked = false;
    document.getElementById('chargeStatus').className = 'status';
    document.getElementById('receipt').style.display = 'none';
    document.getElementById('chargeBtn').style.display = 'block';
    document.getElementById('chargeBtn').textContent = 'Charge';
    selectedSavedCustomer = null;
    removeSavedCardBadge();
    fetch('?action=all_customers').then(r => r.json()).then(data => { if (Array.isArray(data)) allClients = data; }).catch(() => {});
}

// ─── JJ Custom Charge ────────────────────────────────
async function processVpCharge() {
    const amount = parseFloat(document.getElementById('vpAmount').value);
    const description = document.getElementById('vpDescription').value || 'JJ Charge';
    const clientName = document.getElementById('vpClientName').value;
    const clientEmail = document.getElementById('vpClientEmail').value;
    const clientPhone = document.getElementById('vpClientPhone').value;
    const clientAddress = document.getElementById('vpClientAddress').value;
    const clientCity = document.getElementById('vpClientCity').value;
    const clientState = document.getElementById('vpClientState').value;
    const clientZip = document.getElementById('vpClientZip').value;
    const cardNumber = document.getElementById('vpCardNumber').value.replace(/\s/g, '');
    const expMonth = document.getElementById('vpExpMonth').value;
    const expYear = document.getElementById('vpExpYear').value;
    const cvc = document.getElementById('vpCardCvc').value;
    const st = document.getElementById('vpChargeStatus'), btn = document.getElementById('vpChargeBtn');
    if (!amount || amount <= 0) { st.className = 'status error'; st.textContent = 'Enter a valid amount'; return; }
    if (!cardNumber || cardNumber.length < 13) { st.className = 'status error'; st.textContent = 'Enter a valid card number'; return; }
    if (!expMonth || !expYear) { st.className = 'status error'; st.textContent = 'Enter expiry date'; return; }
    if (!cvc || cvc.length < 3) { st.className = 'status error'; st.textContent = 'Enter CVC'; return; }
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Processing...';
    st.className = 'status loading'; st.innerHTML = '<span class="spinner"></span> Authorizing...';
    try {
        const res = await fetch('?action=charge', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ cardNumber, expMonth, expYear, cvc, amount, description, clientName, clientEmail, clientPhone, clientAddress, clientCity, clientState, clientZip, source: 'JJ' }) });
        const data = await res.json();
        if (data.success) {
            const alsoAutopay = document.getElementById('vpAlsoAutopay').checked;
            if (alsoAutopay && clientName) {
                st.className = 'status loading'; st.innerHTML = '<span class="spinner"></span> Charge approved! Setting up autopay...';
                try {
                    const today = new Date().toISOString().substring(0, 10);
                    const apBody = { cardNumber, expMonth, expYear, cvc, amount, description, frequency: 'monthly', startDate: today, clientName, clientEmail, clientPhone, clientAddress, clientCity, clientState, clientZip, cardLast4: cardNumber.slice(-4), cardBrand: data.cardBrand || 'Card', chargedNow: true, source: 'JJ' };
                    const apRes = await fetch('?action=autopay_create', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(apBody) });
                    const apData = await apRes.json();
                    if (apData.success) {
                        st.className = 'status success'; st.textContent = 'Charged + autopay set up! Next charge: same day next month.';
                    } else {
                        st.className = 'status success'; st.textContent = 'Charged successfully! Autopay setup failed: ' + (apData.error || 'Unknown');
                    }
                } catch(e) {
                    st.className = 'status success'; st.textContent = 'Charged successfully! Autopay setup error: ' + e.message;
                }
            } else {
                st.className = 'status success'; st.textContent = 'Transaction approved!';
            }
            const txn = data.transaction || {};
            document.getElementById('vpReceipt').style.display = 'block';
            document.getElementById('vpReceiptAmount').textContent = '$' + amount.toFixed(2);
            document.getElementById('vpReceiptId').textContent = (txn.id || '-').substring(0, 16);
            document.getElementById('vpReceiptClient').textContent = clientName || 'Walk-in';
            document.getElementById('vpReceiptDesc').textContent = description;
            document.getElementById('vpReceiptCard').textContent = (data.cardBrand || 'Card') + ' ****' + cardNumber.slice(-4);
            document.getElementById('vpReceiptTime').textContent = new Date().toLocaleString();
            btn.style.display = 'none';
        } else { st.className = 'status error'; st.textContent = 'Declined: ' + (data.error || 'Unknown error'); }
    } catch (err) { st.className = 'status error'; st.textContent = 'Error: ' + err.message; }
    btn.disabled = false; btn.textContent = 'Charge $' + amount.toFixed(2);
}

function resetVpChargeForm() {
    ['vpAmount','vpDescription','vpClientName','vpClientEmail','vpClientPhone','vpClientAddress','vpClientCity','vpClientState','vpClientZip','vpCardNumber','vpExpMonth','vpExpYear','vpCardCvc','vpCardExpiry'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    document.getElementById('vpAlsoAutopay').checked = false;
    document.getElementById('vpChargeStatus').className = 'status';
    document.getElementById('vpReceipt').style.display = 'none';
    document.getElementById('vpChargeBtn').style.display = 'block';
    document.getElementById('vpChargeBtn').textContent = 'Charge';
}

// ─── Autopay Setup ───────────────────────────────────────────
async function processAutopay() {
    const amount = parseFloat(document.getElementById('apAmount').value);
    const description = document.getElementById('apDescription').value;
    const freq = document.getElementById('apFrequency').value;
    const startDt = document.getElementById('apStartDate').value;
    const clientName = document.getElementById('apClientName').value;
    const clientEmail = document.getElementById('apClientEmail').value;
    const clientPhone = document.getElementById('apClientPhone').value;
    const clientAddress = document.getElementById('apClientAddress').value;
    const clientCity = document.getElementById('apClientCity').value;
    const clientState = document.getElementById('apClientState').value;
    const clientZip = document.getElementById('apClientZip').value;
    const cardNumber = document.getElementById('apCardNumber').value.replace(/\s/g, '');
    const expMonth = document.getElementById('apExpMonth').value;
    const expYear = document.getElementById('apExpYear').value;
    const cvc = document.getElementById('apCardCvc').value;
    const st = document.getElementById('apStatus'), btn = document.getElementById('apBtn');
    if (!amount || amount <= 0) { st.className = 'status error'; st.textContent = 'Enter a valid amount'; return; }
    if (!clientName) { st.className = 'status error'; st.textContent = 'Client name is required'; return; }
    if (!cardNumber || cardNumber.length < 13) { st.className = 'status error'; st.textContent = 'Enter a valid card number'; return; }
    if (!expMonth || !expYear) { st.className = 'status error'; st.textContent = 'Enter expiry date'; return; }
    if (!cvc || cvc.length < 3) { st.className = 'status error'; st.textContent = 'Enter CVC'; return; }

    const today = new Date().toISOString().substring(0, 10);
    const isFuture = startDt && startDt > today;
    const chargedNow = !isFuture;

    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Processing...';

    if (chargedNow) {
        // Charge now, then set up recurring
        st.className = 'status loading'; st.innerHTML = '<span class="spinner"></span> Charging & setting up autopay...';
        try {
            const chargeRes = await fetch('?action=charge', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ cardNumber, expMonth, expYear, cvc, amount, description: description || 'Recurring Charge', clientName, clientEmail, clientPhone, clientAddress, clientCity, clientState, clientZip }) });
            const chargeData = await chargeRes.json();
            if (!chargeData.success) {
                st.className = 'status error'; st.textContent = 'Charge declined: ' + (chargeData.error || 'Unknown error');
                btn.disabled = false; btn.textContent = 'Set Up Autopay';
                return;
            }
            const apBody = { cardNumber, expMonth, expYear, cvc, amount, description: description || 'Recurring Charge', frequency: freq, startDate: startDt || today, clientName, clientEmail, clientPhone, clientAddress, clientCity, clientState, clientZip, cardLast4: cardNumber.slice(-4), cardBrand: chargeData.cardBrand || 'Card', chargedNow: true };
            const apRes = await fetch('?action=autopay_create', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(apBody) });
            const apData = await apRes.json();
            st.className = 'status success'; st.textContent = 'Charged $' + amount.toFixed(2) + ' + Autopay scheduled!';
            showApReceipt(amount, apData.subscription?.id || '-', clientName, freq, startDt || today, cardNumber);
            btn.style.display = 'none';
        } catch (err) { st.className = 'status error'; st.textContent = 'Error: ' + err.message; }
    } else {
        // Future date — just save subscription, no charge now
        st.className = 'status loading'; st.innerHTML = '<span class="spinner"></span> Setting up autopay...';
        try {
            const apBody = { cardNumber, expMonth, expYear, cvc, amount, description: description || 'Recurring Charge', frequency: freq, startDate: startDt, clientName, clientEmail, clientPhone, clientAddress, clientCity, clientState, clientZip, cardLast4: cardNumber.slice(-4), cardBrand: 'Card', chargedNow: false };
            const apRes = await fetch('?action=autopay_create', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(apBody) });
            const apData = await apRes.json();
            if (apData.success) {
                st.className = 'status success'; st.textContent = 'Autopay scheduled! First charge on ' + startDt;
                showApReceipt(amount, apData.subscription?.id || '-', clientName, freq, startDt, cardNumber);
                btn.style.display = 'none';
            } else { st.className = 'status error'; st.textContent = apData.error || 'Failed to set up autopay'; }
        } catch (err) { st.className = 'status error'; st.textContent = 'Error: ' + err.message; }
    }
    btn.disabled = false; btn.textContent = 'Set Up Autopay';
}

function showApReceipt(amount, subId, clientName, freq, startDt, cardNumber) {
    document.getElementById('apReceipt').style.display = 'block';
    document.getElementById('apReceiptAmount').textContent = '$' + amount.toFixed(2) + '/' + freq;
    document.getElementById('apReceiptId').textContent = subId;
    document.getElementById('apReceiptClient').textContent = clientName || 'Walk-in';
    document.getElementById('apReceiptFreq').textContent = freq.charAt(0).toUpperCase() + freq.slice(1);
    document.getElementById('apReceiptDate').textContent = startDt;
    document.getElementById('apReceiptCard').textContent = 'Card ****' + cardNumber.slice(-4);
}

function resetAutopayForm() {
    ['apAmount','apDescription','apClientName','apClientEmail','apClientPhone','apClientAddress','apClientCity','apClientState','apClientZip','apCardNumber','apExpMonth','apExpYear','apCardCvc','apCardExpiry'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('apStatus').className = 'status';
    document.getElementById('apReceipt').style.display = 'none';
    document.getElementById('apBtn').style.display = 'block';
    document.getElementById('apBtn').textContent = 'Set Up Autopay';
    fetch('?action=all_customers').then(r => r.json()).then(data => { if (Array.isArray(data)) allClients = data; }).catch(() => {});
}

// ─── Refund ──────────────────────────────────────────────────
async function refundTxn(id, originalAmount) {
    // Show Full / Custom dialog
    const choice = await new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;';
        const modal = document.createElement('div');
        modal.style.cssText = 'background:#fff;border-radius:12px;padding:24px 28px;min-width:320px;box-shadow:0 8px 32px rgba(0,0,0,0.2);';
        modal.innerHTML = `
            <h3 style="margin:0 0 16px;font-size:18px;font-weight:700;">Refund</h3>
            <div style="display:flex;gap:10px;margin-bottom:14px;">
                <button id="rfFull" style="flex:1;padding:10px;border-radius:8px;border:2px solid #2563eb;background:#2563eb;color:#fff;font-weight:600;cursor:pointer;">Full</button>
                <button id="rfCustom" style="flex:1;padding:10px;border-radius:8px;border:2px solid #2563eb;background:#fff;color:#2563eb;font-weight:600;cursor:pointer;">Custom</button>
            </div>
            <div id="rfCustomBox" style="display:none;margin-bottom:14px;">
                <label style="font-size:13px;font-weight:600;color:#374151;">Refund Amount</label>
                <input id="rfAmount" type="text" placeholder="0.00" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;margin-top:4px;font-size:15px;box-sizing:border-box;" />
            </div>
            <div style="display:flex;gap:10px;">
                <button id="rfConfirm" style="flex:1;padding:10px;border-radius:8px;border:none;background:#dc2626;color:#fff;font-weight:600;cursor:pointer;">Confirm Refund</button>
                <button id="rfCancel" style="flex:1;padding:10px;border-radius:8px;border:1px solid #d1d5db;background:#fff;color:#374151;font-weight:600;cursor:pointer;">Cancel</button>
            </div>`;
        overlay.appendChild(modal);
        document.body.appendChild(overlay);
        let refundType = 'full';
        modal.querySelector('#rfFull').onclick = () => { refundType='full'; modal.querySelector('#rfFull').style.background='#2563eb'; modal.querySelector('#rfFull').style.color='#fff'; modal.querySelector('#rfCustom').style.background='#fff'; modal.querySelector('#rfCustom').style.color='#2563eb'; modal.querySelector('#rfCustomBox').style.display='none'; };
        modal.querySelector('#rfCustom').onclick = () => { refundType='custom'; modal.querySelector('#rfCustom').style.background='#2563eb'; modal.querySelector('#rfCustom').style.color='#fff'; modal.querySelector('#rfFull').style.background='#fff'; modal.querySelector('#rfFull').style.color='#2563eb'; modal.querySelector('#rfCustomBox').style.display='block'; modal.querySelector('#rfAmount').focus(); };
        modal.querySelector('#rfCancel').onclick = () => { document.body.removeChild(overlay); resolve(null); };
        modal.querySelector('#rfConfirm').onclick = () => { const amt = parseFloat(modal.querySelector('#rfAmount').value)||0; document.body.removeChild(overlay); resolve({ type: refundType, amount: amt }); };
    });
    if (!choice) return;
    if (choice.type === 'custom' && choice.amount <= 0) { alert('Please enter a valid refund amount.'); return; }
    try {
        const body = { id, refundType: choice.type };
        if (choice.type === 'custom') body.customAmount = choice.amount;
        const res = await fetch('?action=refund', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) });
        const data = await res.json();
        if (data.success) {
            alert('Refund processed successfully!');
            location.reload();
        } else {
            alert('Refund failed: ' + (data.error || 'Unknown error'));
        }
    } catch (err) {
        alert('Refund error: ' + err.message);
    }
}

// ─── Autopay management ──────────────────────────────────────
async function cancelAutopay(id) {
    if (!confirm('Cancel this autopay subscription?')) return;
    try {
        const res = await fetch('?action=autopay_cancel', { method: 'POST', credentials: 'same-origin', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id }) });
        const data = await res.json();
        if (!data.success) { alert('Failed to cancel: ' + (data.error || 'Unknown error')); return; }
    } catch(e) { alert('Error cancelling autopay: ' + e.message); return; }
    location.reload();
}

async function pauseAutopay(id) {
    await fetch('?action=autopay_pause', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id }) });
    location.reload();
}

async function retryAutopay(id) {
    await fetch('?action=autopay_retry', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id }) });
    location.reload();
}

// ─── Filters ────────────────────────────────────────────────
function filterTxns() {
    const search = (document.getElementById('txnSearch').value || '').toLowerCase();
    const statusF = document.getElementById('txnFilter').value;
    const sourceF = document.getElementById('txnSourceFilter').value;
    const dateF = document.getElementById('txnDateFilter').value;
    const now = new Date();
    const todayStr = now.toISOString().substring(0, 10);
    const ws = new Date(now); ws.setDate(now.getDate() - now.getDay() + 1);
    const weekStr = ws.toISOString().substring(0, 10);
    const monthStr = now.toISOString().substring(0, 7);
    let vis = 0;
    document.querySelectorAll('#txnTable tbody tr').forEach(row => {
        let show = true;
        if (search && !(row.dataset.search || '').includes(search)) show = false;
        if (statusF !== 'all' && row.dataset.status !== statusF) show = false;
        if (sourceF !== 'all' && row.dataset.source !== sourceF) show = false;
        const d = row.dataset.date || '';
        if (dateF === 'today' && d !== todayStr) show = false;
        if (dateF === 'week' && d < weekStr) show = false;
        if (dateF === 'month' && !d.startsWith(monthStr)) show = false;
        row.style.display = show ? '' : 'none';
        if (show) vis++;
    });
    document.getElementById('txnCount').textContent = vis + ' transaction' + (vis !== 1 ? 's' : '') + ' shown';
}

function toggleScheduleDay(el) {
    const custs = el.querySelector('.sdc-customers');
    const isOpen = el.classList.contains('open');
    el.classList.toggle('open');
    custs.style.display = isOpen ? 'none' : 'block';
}

function filterCusts() {
    const s = (document.getElementById('custSearch').value || '').toLowerCase();
    document.querySelectorAll('#customerTable tbody tr').forEach(r => { r.style.display = (r.dataset.search || '').includes(s) ? '' : 'none'; });
}

async function deleteCustomer(name) {
    if (!confirm('Delete customer "' + name + '"? This fully removes them from Customers and autocomplete, including saved cards and autopay subscriptions. Transaction history is preserved.')) return;
    try {
        const res = await fetch('?action=delete_customer', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ name }) });
        const data = await res.json();
        if (data.success) { location.reload(); }
        else { alert('Failed to delete: ' + (data.error || 'Unknown error')); }
    } catch(e) { alert('Error: ' + e.message); }
}

// ─── Sort Customers by Last Charge Date ──────────────────────
let custSortAsc = false;
function sortCustByDate() {
    const tbody = document.querySelector('#customerTable tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    rows.sort((a, b) => {
        const da = a.dataset.date || '';
        const db = b.dataset.date || '';
        if (!da && !db) return 0;
        if (!da) return 1;
        if (!db) return -1;
        return custSortAsc ? da.localeCompare(db) : db.localeCompare(da);
    });
    rows.forEach(r => tbody.appendChild(r));
    custSortAsc = !custSortAsc;
    document.getElementById('sortArrow').innerHTML = custSortAsc ? '&#9652;' : '&#9662;';
}

// ─── Charge from Edit Modal ──────────────────────────────────
async function chargeFromModal() {
    if (!editCustomerData) return;
    const amount = parseFloat(document.getElementById('editChargeAmount').value);
    const description = document.getElementById('editChargeDesc').value || 'Custom Charge';
    const statusEl = document.getElementById('editModalStatus');
    if (!amount || amount <= 0) { statusEl.className = 'modal-status-msg error'; statusEl.textContent = 'Enter a valid amount'; statusEl.style.display = 'block'; return; }
    const name = editCustomerData.name || '';
    // Try to get saved card
    statusEl.className = 'modal-status-msg'; statusEl.textContent = 'Loading saved card...'; statusEl.style.display = 'block';
    try {
        const cardRes = await fetch('?action=get_saved_card&name=' + encodeURIComponent(name));
        const cardData = await cardRes.json();
        if (cardData.error || !cardData.cardNumber) {
            statusEl.className = 'modal-status-msg error'; statusEl.textContent = 'No saved card found for this customer. Charge from New Charge tab instead.'; return;
        }
        statusEl.textContent = 'Charging...';
        const res = await fetch('?action=charge', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({
            cardNumber: cardData.cardNumber, expMonth: cardData.expMonth, expYear: cardData.expYear, cvc: cardData.cvc,
            amount, description, clientName: name, clientEmail: editCustomerData.email || '', clientPhone: editCustomerData.phone || '',
            clientAddress: editCustomerData.address || '', clientCity: editCustomerData.city || '', clientState: editCustomerData.state || '', clientZip: editCustomerData.zip || ''
        })});
        const data = await res.json();
        if (data.success) {
            statusEl.className = 'modal-status-msg success'; statusEl.textContent = 'Charged $' + amount.toFixed(2) + ' successfully!';
            document.getElementById('editChargeAmount').value = '';
            document.getElementById('editChargeDesc').value = '';
        } else {
            statusEl.className = 'modal-status-msg error'; statusEl.textContent = 'Declined: ' + (data.error || 'Unknown error');
        }
    } catch (err) {
        statusEl.className = 'modal-status-msg error'; statusEl.textContent = 'Error: ' + err.message;
    }
}

// ─── Edit Customer Modal ─────────────────────────────────────
var editCustomerData = null;

function openEditModal(customer) {
    editCustomerData = customer;
    document.getElementById('editModalTitle').textContent = 'Edit - ' + (customer.name || 'Customer');
    const statusEl = document.getElementById('editModalStatus');
    statusEl.style.display = 'none';
    const custStatus = document.getElementById('editCustStatus');
    custStatus.style.display = 'none';

    // Populate customer info fields
    document.getElementById('editCustName').value = customer.name || '';
    document.getElementById('editCustEmail').value = customer.email || '';
    document.getElementById('editCustPhone').value = customer.phone || '';
    document.getElementById('editCustAddress').value = customer.address || '';
    document.getElementById('editCustCity').value = customer.city || '';
    document.getElementById('editCustState').value = customer.state || '';
    document.getElementById('editCustZip').value = customer.zip || '';

    // Card number formatting in modal
    const editCardEl = document.getElementById('editCardNumber');
    editCardEl.oninput = function(e) {
        let v = e.target.value.replace(/\D/g, '').substring(0, 16);
        e.target.value = v.replace(/(.{4})/g, '$1 ').trim();
    };

    // Set default start date to today
    document.getElementById('editApStart').value = new Date().toISOString().substring(0, 10);

    // Clear form fields
    ['editApAmount','editApDesc','editCardNumber','editExpMonth','editExpYear','editCvc','editChargeAmount','editChargeDesc','editCardExpiry'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });

    // Auto-fill saved card and merge contact info from saved cards
    if (customer.name) {
        fetch('?action=get_saved_card&name=' + encodeURIComponent(customer.name))
            .then(r => r.json()).then(data => {
                if (!data.error && data.cardNumber) {
                    document.getElementById('editCardNumber').value = data.cardNumber.replace(/(.{4})/g, '$1 ').trim();
                    setExpiryValue('editCardExpiry', 'editExpMonth', 'editExpYear', data.expMonth || '', data.expYear || '');
                    document.getElementById('editCvc').value = data.cvc || '';
                }
                // Merge richer contact info from saved cards if empty
                if (!document.getElementById('editCustEmail').value && data.clientEmail) document.getElementById('editCustEmail').value = data.clientEmail;
                if (!document.getElementById('editCustPhone').value && data.clientPhone) document.getElementById('editCustPhone').value = data.clientPhone;
                if (!document.getElementById('editCustAddress').value && data.clientAddress) document.getElementById('editCustAddress').value = data.clientAddress;
                if (!document.getElementById('editCustCity').value && data.clientCity) document.getElementById('editCustCity').value = data.clientCity;
                if (!document.getElementById('editCustState').value && data.clientState) document.getElementById('editCustState').value = data.clientState;
                if (!document.getElementById('editCustZip').value && data.clientZip) document.getElementById('editCustZip').value = data.clientZip;
            }).catch(() => {});
    }

    // Render existing subscriptions
    const subsContainer = document.getElementById('editExistingSubs');
    const divider = document.getElementById('editDivider');
    const autopays = customer.autopays || [];

    if (autopays.length > 0) {
        divider.style.display = '';
        let html = '<h4 style="font-size:14px; font-weight:700; color:#1a1a2e; margin-bottom:14px;">Current Autopay Subscriptions</h4><div class="modal-sub-list">';
        autopays.forEach(ap => {
            const freqLabel = {weekly:'Weekly',biweekly:'Biweekly',monthly:'Monthly',quarterly:'Quarterly'}[ap.frequency] || ap.frequency;
            const statusBadge = ap.status === 'active' ? '<span class="badge badge-approved">Active</span>'
                : ap.status === 'paused' ? '<span class="badge badge-autopay">Paused</span>'
                : ap.status === 'failed' ? '<span class="badge badge-declined">Failed</span>'
                : '<span class="badge badge-cancelled">Cancelled</span>';
            html += '<div class="modal-sub-item">';
            html += '<div class="ms-top"><div class="ms-amount" id="apAmountDisplay_' + ap.id + '">$' + parseFloat(ap.amount).toFixed(2) + '</div>' + statusBadge + '</div>';
            html += '<div class="ms-meta">' + freqLabel + ' &middot; ' + (ap.cardBrand || 'Card') + ' ****' + (ap.cardLast4 || '----') + '</div>';
            if (ap.description) html += '<div class="ms-meta" style="margin-top:2px;">' + ap.description + '</div>';
            // Editable amount
            html += '<div style="display:flex; align-items:center; gap:8px; margin-top:10px;">';
            html += '<label style="font-size:12px; font-weight:600; color:#374151; white-space:nowrap;">Amount: $</label>';
            html += '<input type="text" id="apAmt_' + ap.id + '" value="' + parseFloat(ap.amount).toFixed(2) + '" inputmode="decimal" style="padding:6px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:13px; color:#1a1a2e; font-family:inherit; outline:none; width:80px;">';
            html += '<button style="font-size:11px; padding:5px 12px; border-radius:6px; border:1px solid #a7f3d0; background:#fff; color:#059669; cursor:pointer; font-weight:600; font-family:inherit; white-space:nowrap;" onclick="modalUpdateAmount(\'' + ap.id + '\')">Save Amount</button>';
            html += '</div>';
            // Editable next charge date
            html += '<div style="display:flex; align-items:center; gap:8px; margin-top:8px;">';
            html += '<label style="font-size:12px; font-weight:600; color:#374151; white-space:nowrap;">Next Charge:</label>';
            html += '<input type="date" id="apDate_' + ap.id + '" value="' + (ap.nextCharge || '') + '" style="padding:6px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:13px; color:#1a1a2e; font-family:inherit; outline:none; flex:1;">';
            html += '<button style="font-size:11px; padding:5px 12px; border-radius:6px; border:1px solid #bfdbfe; background:#fff; color:#2563eb; cursor:pointer; font-weight:600; font-family:inherit; white-space:nowrap;" onclick="modalUpdateDate(\'' + ap.id + '\')">Save Date</button>';
            html += '</div>';
            html += '<div class="ms-actions">';
            if (ap.status === 'active') {
                html += '<button class="btn-sm-pause" onclick="modalPauseAP(\'' + ap.id + '\')">Pause</button>';
                html += '<button class="btn-sm-cancel" onclick="modalCancelAP(\'' + ap.id + '\')">Remove</button>';
            } else if (ap.status === 'paused') {
                html += '<button class="btn-sm-resume" onclick="modalResumeAP(\'' + ap.id + '\')">Resume</button>';
                html += '<button class="btn-sm-cancel" onclick="modalCancelAP(\'' + ap.id + '\')">Remove</button>';
            } else if (ap.status === 'failed') {
                html += '<button class="btn-sm-retry" onclick="modalRetryAP(\'' + ap.id + '\')">Retry</button>';
                html += '<button class="btn-sm-cancel" onclick="modalCancelAP(\'' + ap.id + '\')">Remove</button>';
            }
            html += '</div></div>';
        });
        html += '</div>';
        subsContainer.innerHTML = html;
    } else {
        subsContainer.innerHTML = '';
        divider.style.display = 'none';
    }

    // Hide Add Autopay section if customer already has an active autopay
    const hasActiveAP = autopays.some(ap => ap.status === 'active');
    document.getElementById('addApSection').style.display = hasActiveAP ? 'none' : '';

    // Load audit log for this customer
    const auditDiv = document.getElementById('editAuditLog');
    auditDiv.innerHTML = 'Loading...';
    // Load audit log and transactions in parallel
    Promise.all([
        fetch('?action=audit_log').then(r => r.json()).catch(() => []),
        fetch('?action=transactions').then(r => r.json()).catch(() => [])
    ]).then(([log, txns]) => {
        const name = (customer.name || '').toLowerCase();
        // Get audit entries for this customer
        const auditEntries = (Array.isArray(log) ? log : []).filter(e => (e.target || '').toLowerCase() === name).map(e => ({
            timestamp: e.timestamp,
            type: 'audit',
            icon: e.action === 'charge_approved' ? '&#9989;' : (e.action === 'charge_declined' ? '&#10060;' : (e.action === 'autopay_created' ? '&#128260;' : (e.action === 'delete_customer' ? '&#128465;' : '&#9998;'))),
            label: (e.action || '').replace(/_/g, ' '),
            details: e.details || '',
            ip: e.ip || ''
        }));
        // Get transaction entries for this customer
        const txnEntries = (Array.isArray(txns) ? txns : []).filter(t => (t.clientName || '').toLowerCase() === name).map(t => ({
            timestamp: t.timestamp,
            type: 'txn',
            icon: t.status === 'approved' ? '&#128176;' : (t.status === 'refunded' ? '&#128260;' : '&#10060;'),
            label: t.status === 'approved' ? 'Payment' : (t.status === 'refunded' ? 'Refund' : 'Declined'),
            details: '$' + parseFloat(t.amount || 0).toFixed(2) + ' | ' + (t.cardBrand || 'Card') + ' ****' + (t.cardLast4 || '') + (t.description ? ' | ' + t.description : '') + (t.error ? ' | ' + t.error : ''),
            ip: ''
        }));
        // Merge and sort by timestamp descending
        const all = [...auditEntries, ...txnEntries].sort((a, b) => (b.timestamp || '').localeCompare(a.timestamp || ''));
        if (all.length === 0) { auditDiv.innerHTML = '<span style="color:#9ca3af;">No history yet.</span>'; return; }
        auditDiv.innerHTML = all.slice(0, 50).map(e => {
            const d = new Date(e.timestamp);
            const color = e.label === 'Payment' ? '#059669' : (e.label.includes('Declined') || e.label.includes('declined') ? '#dc2626' : (e.label.includes('Refund') ? '#d97706' : '#374151'));
            return '<div style="padding:5px 0; border-bottom:1px solid #f1f5f9;">' +
                '<span>' + e.icon + '</span> ' +
                '<strong style="color:' + color + ';">' + e.label + '</strong> &middot; ' +
                '<span style="font-size:10px; color:#9ca3af;">' + d.toLocaleString() + '</span>' +
                '<br><span style="color:#6b7280;">' + (e.details || '') + '</span>' +
                (e.ip ? ' <span style="color:#d1d5db; font-size:10px;">IP: ' + e.ip + '</span>' : '') +
                '</div>';
        }).join('');
    }).catch(() => { auditDiv.innerHTML = '<span style="color:#9ca3af;">Could not load history.</span>'; });

    document.getElementById('editModal').classList.add('show');
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('show');
    editCustomerData = null;
}

// Save customer info
async function saveCustomerInfo() {
    if (!editCustomerData) return;
    const st = document.getElementById('editCustStatus');
    st.className = 'modal-status-msg'; st.textContent = 'Saving...'; st.style.display = 'block';

    const payload = {
        oldName: editCustomerData.name,
        name: document.getElementById('editCustName').value.trim(),
        email: document.getElementById('editCustEmail').value.trim(),
        phone: document.getElementById('editCustPhone').value.trim(),
        address: document.getElementById('editCustAddress').value.trim(),
        city: document.getElementById('editCustCity').value.trim(),
        state: document.getElementById('editCustState').value.trim(),
        zip: document.getElementById('editCustZip').value.trim(),
    };

    try {
        const res = await fetch('?action=update_customer', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
        const data = await res.json();
        if (data.success) {
            st.className = 'modal-status-msg success'; st.textContent = 'Customer info saved!'; st.style.display = 'block';
            editCustomerData.name = payload.name;
            editCustomerData.email = payload.email;
            editCustomerData.phone = payload.phone;
            editCustomerData.address = payload.address;
            editCustomerData.city = payload.city;
            editCustomerData.state = payload.state;
            editCustomerData.zip = payload.zip;
            document.getElementById('editModalTitle').textContent = 'Edit - ' + payload.name;
            setTimeout(() => location.reload(), 1200);
        } else {
            st.className = 'modal-status-msg error'; st.textContent = data.error || 'Failed to save'; st.style.display = 'block';
        }
    } catch(e) {
        st.className = 'modal-status-msg error'; st.textContent = 'Network error'; st.style.display = 'block';
    }
}

// Close modal on overlay click
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});

async function addAutopayFromModal() {
    if (!editCustomerData) return;
    const amount = parseFloat(document.getElementById('editApAmount').value);
    const description = document.getElementById('editApDesc').value || 'Recurring Charge';
    const frequency = document.getElementById('editApFreq').value;
    const startDate = document.getElementById('editApStart').value;
    const cardNumber = document.getElementById('editCardNumber').value.replace(/\s/g, '');
    const expMonth = document.getElementById('editExpMonth').value;
    const expYear = document.getElementById('editExpYear').value;
    const cvc = document.getElementById('editCvc').value;
    const statusEl = document.getElementById('editModalStatus');

    if (!amount || amount <= 0) { statusEl.className = 'modal-status-msg error'; statusEl.textContent = 'Enter a valid amount'; statusEl.style.display = ''; return; }
    if (!cardNumber || cardNumber.length < 13) { statusEl.className = 'modal-status-msg error'; statusEl.textContent = 'Enter a valid card number'; statusEl.style.display = ''; return; }
    if (!expMonth || !expYear) { statusEl.className = 'modal-status-msg error'; statusEl.textContent = 'Enter expiry date'; statusEl.style.display = ''; return; }
    if (!cvc || cvc.length < 3) { statusEl.className = 'modal-status-msg error'; statusEl.textContent = 'Enter CVC'; statusEl.style.display = ''; return; }
    if (!startDate) { statusEl.className = 'modal-status-msg error'; statusEl.textContent = 'Select a start date'; statusEl.style.display = ''; return; }

    statusEl.className = 'modal-status-msg'; statusEl.textContent = 'Setting up autopay...'; statusEl.style.display = '';

    try {
        const body = {
            clientName: editCustomerData.name,
            clientEmail: editCustomerData.email || '',
            clientPhone: editCustomerData.phone || '',
            clientAddress: editCustomerData.address || '',
            clientCity: editCustomerData.city || '',
            clientState: editCustomerData.state || '',
            clientZip: editCustomerData.zip || '',
            amount, description, frequency, startDate,
            cardNumber, expMonth, expYear, cvc,
            cardLast4: cardNumber.slice(-4), cardBrand: 'Card'
        };
        const res = await fetch('?action=autopay_create', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) });
        const data = await res.json();
        if (data.success) {
            statusEl.className = 'modal-status-msg success';
            statusEl.textContent = 'Autopay added successfully!';
            statusEl.style.display = '';
            setTimeout(() => location.reload(), 1200);
        } else {
            statusEl.className = 'modal-status-msg error';
            statusEl.textContent = data.error || 'Failed to add autopay';
            statusEl.style.display = '';
        }
    } catch (err) {
        statusEl.className = 'modal-status-msg error';
        statusEl.textContent = 'Error: ' + err.message;
        statusEl.style.display = '';
    }
}

async function modalCancelAP(id) {
    if (!confirm('Remove this autopay subscription?')) return;
    try {
        const res = await fetch('?action=autopay_cancel', { method: 'POST', credentials: 'same-origin', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id }) });
        const data = await res.json();
        if (!data.success) { alert('Failed to remove: ' + (data.error || 'Unknown error')); return; }
    } catch(e) { alert('Error removing autopay: ' + e.message); return; }
    location.reload();
}

async function modalPauseAP(id) {
    await fetch('?action=autopay_pause', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id }) });
    location.reload();
}

async function modalResumeAP(id) {
    await fetch('?action=autopay_resume', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id }) });
    location.reload();
}

async function modalRetryAP(id) {
    await fetch('?action=autopay_retry', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id }) });
    location.reload();
}

async function modalUpdateDate(id) {
    const dateInput = document.getElementById('apDate_' + id);
    if (!dateInput || !dateInput.value) { alert('Please select a date'); return; }
    const res = await fetch('?action=autopay_update_date', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id, nextCharge: dateInput.value }) });
    const data = await res.json();
    if (data.success) {
        alert('Charge date updated to ' + dateInput.value);
        location.reload();
    } else {
        alert('Failed to update date: ' + (data.error || 'Unknown error'));
    }
}

async function modalUpdateAmount(id) {
    const amtInput = document.getElementById('apAmt_' + id);
    const newAmt = parseFloat(amtInput?.value);
    if (!newAmt || newAmt <= 0) { alert('Enter a valid amount'); return; }
    if (!confirm('Change autopay amount to $' + newAmt.toFixed(2) + '?')) return;
    const res = await fetch('?action=autopay_update_amount', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id, amount: newAmt }) });
    const data = await res.json();
    if (data.success) {
        alert('Amount updated to $' + newAmt.toFixed(2));
        const display = document.getElementById('apAmountDisplay_' + id);
        if (display) display.textContent = '$' + newAmt.toFixed(2);
    } else {
        alert('Failed to update amount: ' + (data.error || 'Unknown error'));
    }
}


// ─── Sessions Management ──────────────────────────────────────
let sessionsData = [];

async function refreshSessions() {
    try {
        const res = await fetch('?action=sessions');
        sessionsData = await res.json();
        renderSessions();
    } catch(e) {
        document.getElementById('sessions-tbody').innerHTML = '<tr><td colspan="10" style="text-align:center; padding:40px; color:#dc2626;">Failed to load sessions</td></tr>';
    }
}

function renderSessions() {
    const tbody = document.getElementById('sessions-tbody');
    const active = sessionsData.filter(s => s.status === 'active').length;
    const kicked = sessionsData.filter(s => s.status === 'kicked').length;
    const uniqueIps = [...new Set(sessionsData.map(s => s.ip))].length;

    document.getElementById('stat-active-sessions').textContent = active;
    document.getElementById('stat-total-sessions').textContent = sessionsData.length;
    document.getElementById('stat-kicked-sessions').textContent = kicked;
    document.getElementById('stat-unique-ips').textContent = uniqueIps;

    if (sessionsData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:40px; color:#9ca3af;">No sessions recorded yet.</td></tr>';
        return;
    }

    tbody.innerHTML = sessionsData.map(s => {
        const isActive = s.status === 'active';
        const isCurrent = s.isCurrent;
        const statusBadge = isCurrent
            ? '<span style="display:inline-flex; align-items:center; gap:4px; background:#ecfdf5; color:#059669; border:1px solid #a7f3d0; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700;"><span style="width:7px; height:7px; background:#059669; border-radius:50%; display:inline-block; animation:pulse 2s infinite;"></span> You</span>'
            : isActive
                ? '<span style="display:inline-flex; align-items:center; gap:4px; background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700;"><span style="width:7px; height:7px; background:#2563eb; border-radius:50%; display:inline-block;"></span> Active</span>'
                : '<span style="display:inline-flex; align-items:center; gap:4px; background:#fef2f2; color:#dc2626; border:1px solid #fecaca; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700;">Kicked</span>';

        const location = [s.city, s.region, s.country].filter(Boolean).join(', ') || 'Unknown';
        const deviceIcon = s.device === 'Mobile' || s.device === 'iPhone' ? '📱' : s.device === 'Tablet' || s.device === 'iPad' ? '📱' : '💻';
        const loginTime = s.loginTime ? formatSessionTime(s.loginTime) : 'N/A';
        const lastActive = s.lastActive ? formatSessionTime(s.lastActive) : 'N/A';
        const lastActiveAgo = s.lastActive ? timeAgo(s.lastActive) : '';

        const rowBg = isCurrent ? 'background:#f0fdf4;' : (s.status === 'kicked' ? 'background:#fef2f2;' : '');
        const kickBtn = isCurrent
            ? '<span style="font-size:11px; color:#9ca3af; font-style:italic;">Current</span>'
            : isActive
                ? `<button onclick="kickSession('${s.sessionId}')" style="background:#dc2626; color:#fff; border:none; padding:5px 12px; border-radius:6px; font-size:11px; font-weight:600; cursor:pointer;">Kick</button>`
                : '<span style="font-size:11px; color:#9ca3af; font-style:italic;">Revoked</span>';

        return `<tr style="border-bottom:1px solid #f3f4f6; ${rowBg} cursor:pointer;" onclick="showSessionDetail('${s.sessionId}')">
            <td style="padding:10px 12px;">${statusBadge}</td>
            <td style="padding:10px 12px;"><code style="background:#f3f4f6; padding:3px 8px; border-radius:4px; font-size:12px; font-weight:600;">${s.ip || 'N/A'}</code></td>
            <td style="padding:10px 12px;"><span style="font-size:12px; font-weight:500;">${location}</span></td>
            <td style="padding:10px 12px;"><span style="font-size:12px; color:#6b7280;">${s.isp || 'Unknown'}</span></td>
            <td style="padding:10px 12px;"><span style="font-size:16px;">${deviceIcon}</span> <span style="font-size:12px;">${s.device || 'Desktop'}</span></td>
            <td style="padding:10px 12px;"><span style="font-size:12px; font-weight:500;">${s.browser || 'Unknown'}</span><br><span style="font-size:11px; color:#9ca3af;">${s.os || ''}</span></td>
            <td style="padding:10px 12px;"><span style="font-size:12px;">${loginTime}</span></td>
            <td style="padding:10px 12px;"><span style="font-size:12px;">${lastActive}</span><br><span style="font-size:11px; color:#9ca3af;">${lastActiveAgo}</span></td>
            <td style="padding:10px 12px; text-align:center;"><span style="font-size:13px; font-weight:600;">${s.pageViews || 0}</span></td>
            <td style="padding:10px 12px;" onclick="event.stopPropagation();">${kickBtn}</td>
        </tr>`;
    }).join('');
}

function formatSessionTime(isoStr) {
    const d = new Date(isoStr);
    const month = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getMonth()];
    const day = d.getDate();
    let hours = d.getHours();
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12 || 12;
    const mins = String(d.getMinutes()).padStart(2, '0');
    return `${month} ${day}, ${hours}:${mins} ${ampm}`;
}

function timeAgo(isoStr) {
    const diff = (Date.now() - new Date(isoStr).getTime()) / 1000;
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
}

function showSessionDetail(sessionId) {
    const s = sessionsData.find(x => x.sessionId === sessionId);
    if (!s) return;
    const panel = document.getElementById('session-detail-panel');
    const content = document.getElementById('session-detail-content');

    const detailItems = [
        { label: 'Session ID', value: `<code style="font-size:11px; word-break:break-all;">${s.sessionId}</code>` },
        { label: 'Status', value: s.isCurrent ? '<span style="color:#059669; font-weight:700;">Current Session (You)</span>' : s.status === 'active' ? '<span style="color:#2563eb; font-weight:600;">Active</span>' : '<span style="color:#dc2626; font-weight:600;">Kicked</span>' },
        { label: 'IP Address', value: `<code style="font-weight:600;">${s.ip}</code>` },
        { label: 'City', value: s.city || 'N/A' },
        { label: 'Region', value: s.region || 'N/A' },
        { label: 'Country', value: s.country || 'N/A' },
        { label: 'ISP / Network', value: s.isp || 'N/A' },
        { label: 'Coordinates', value: `${s.lat || 0}, ${s.lon || 0}` },
        { label: 'Device Type', value: s.device || 'Desktop' },
        { label: 'Browser', value: s.browser || 'Unknown' },
        { label: 'Operating System', value: s.os || 'Unknown' },
        { label: 'Login Time', value: s.loginTime ? new Date(s.loginTime).toLocaleString() : 'N/A' },
        { label: 'Last Active', value: s.lastActive ? new Date(s.lastActive).toLocaleString() : 'N/A' },
        { label: 'Page Views', value: s.pageViews || 0 },
    ];
    if (s.kickedAt) detailItems.push({ label: 'Kicked At', value: new Date(s.kickedAt).toLocaleString() });

    content.innerHTML = detailItems.map(d => `
        <div style="display:flex; justify-content:space-between; padding:8px 12px; background:#fff; border-radius:6px; border:1px solid #f3f4f6;">
            <span style="font-size:12px; color:#6b7280; font-weight:500;">${d.label}</span>
            <span style="font-size:12px; color:#1a1a2e; font-weight:600; text-align:right;">${d.value}</span>
        </div>
    `).join('');

    // Show map
    const mapEl = document.getElementById('session-map');
    if (s.lat && s.lon && s.lat !== 0 && s.lon !== 0) {
        mapEl.innerHTML = `<iframe width="100%" height="200" frameborder="0" style="border-radius:8px;" src="https://www.openstreetmap.org/export/embed.html?bbox=${s.lon-0.05},${s.lat-0.03},${s.lon+0.05},${s.lat+0.03}&layer=mapnik&marker=${s.lat},${s.lon}"></iframe>`;
    } else {
        mapEl.innerHTML = '<span style="color:#9ca3af; font-size:13px;">No coordinates available</span>';
    }

    // Full User Agent
    content.innerHTML += `
        <div style="grid-column:1/3; padding:8px 12px; background:#fff; border-radius:6px; border:1px solid #f3f4f6;">
            <span style="font-size:12px; color:#6b7280; font-weight:500;">User Agent</span><br>
            <span style="font-size:11px; color:#1a1a2e; word-break:break-all;">${s.userAgent || 'N/A'}</span>
        </div>
    `;

    panel.style.display = 'block';
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

async function kickSession(sessionId) {
    if (!confirm('Are you sure you want to force-logout this session?')) return;
    try {
        const res = await fetch('?action=kick_session', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ sessionId }) });
        const data = await res.json();
        if (data.success) {
            alert('Session has been kicked.');
            refreshSessions();
        } else {
            alert('Failed: ' + (data.error || 'Unknown error'));
        }
    } catch(e) { alert('Network error'); }
}

async function kickAllSessions() {
    if (!confirm('This will force-logout ALL other sessions except yours. Continue?')) return;
    try {
        const res = await fetch('?action=kick_all_sessions', { method: 'POST', headers: {'Content-Type':'application/json'} });
        const data = await res.json();
        if (data.success) {
            alert('All other sessions have been kicked.');
            refreshSessions();
        } else {
            alert('Failed: ' + (data.error || 'Unknown error'));
        }
    } catch(e) { alert('Network error'); }
}

async function clearSessionHistory() {
    if (!confirm('This will clear all session history (except your current session). Continue?')) return;
    try {
        const res = await fetch('?action=clear_session_history', { method: 'POST', headers: {'Content-Type':'application/json'} });
        const data = await res.json();
        if (data.success) {
            alert('Session history cleared.');
            refreshSessions();
        } else {
            alert('Failed: ' + (data.error || 'Unknown error'));
        }
    } catch(e) { alert('Network error'); }
}

// Auto-load sessions when tab is shown
const origSwitchTab = switchTab;
switchTab = function(tab) {
    origSwitchTab(tab);
    if (tab === 'sessions') refreshSessions();
};

// Pulse animation for current session indicator
const style = document.createElement('style');
style.textContent = `@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }`;
document.head.appendChild(style);

// ─── PIN Security System ─────────────────────────────────────
var pinCallback = null;
var pendingCustomerData = null;
var pendingTransactionData = null;

function promptEditPin(customer) {
    pendingCustomerData = customer;
    pinCallback = 'edit';
    document.getElementById('pinPromptText').textContent = 'Enter security PIN to edit "' + (customer.name || 'Customer') + '"';
    document.getElementById('pinInput').value = '';
    document.getElementById('pinError').style.display = 'none';
    document.getElementById('pinModal').classList.add('show');
    setTimeout(() => document.getElementById('pinInput').focus(), 200);
}

function promptDeletePin() {
    if (!editCustomerData) return;
    pinCallback = 'delete';
    document.getElementById('pinPromptText').textContent = 'Enter security PIN to delete "' + (editCustomerData.name || 'Customer') + '"';
    document.getElementById('pinInput').value = '';
    document.getElementById('pinError').style.display = 'none';
    document.getElementById('pinModal').classList.add('show');
    setTimeout(() => document.getElementById('pinInput').focus(), 200);
}

function promptTxnPin(transaction) {
    pendingTransactionData = transaction;
    pinCallback = 'transaction';
    document.getElementById('pinPromptText').textContent = 'Enter security PIN to view transaction actions for "' + (transaction.clientName || 'Walk-in') + '"';
    document.getElementById('pinInput').value = '';
    document.getElementById('pinError').style.display = 'none';
    document.getElementById('pinModal').classList.add('show');
    setTimeout(() => document.getElementById('pinInput').focus(), 200);
}

function closePinModal() {
    document.getElementById('pinModal').classList.remove('show');
    document.getElementById('pinInput').value = '';
    pinCallback = null;
    pendingCustomerData = null;
    pendingTransactionData = null;
}
document.getElementById('pinModal').addEventListener('click', function(e) { if (e.target === this) closePinModal(); });

async function verifyPin() {
    const pin = document.getElementById('pinInput').value;
    if (!pin) { document.getElementById('pinError').textContent = 'Enter a PIN'; document.getElementById('pinError').style.display = ''; return; }
    try {
        const res = await fetch('?action=verify_pin', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ pin }) });
        const data = await res.json();
        if (!data.success) { document.getElementById('pinError').textContent = data.error || 'Incorrect PIN'; document.getElementById('pinError').style.display = ''; return; }
        const cb = pinCallback;
        const customer = pendingCustomerData;
        const transaction = pendingTransactionData;
        closePinModal();
        if (cb === 'edit' && customer) {
            openEditModal(customer);
        } else if (cb === 'delete' && editCustomerData) {
            deleteCustomer(editCustomerData.name);
        } else if (cb === 'transaction' && transaction) {
            openTxnEditModal(transaction);
        }
    } catch(e) { document.getElementById('pinError').textContent = 'Error: ' + e.message; document.getElementById('pinError').style.display = ''; }
}

// ─── Transaction Edit Modal ──────────────────────────────────
var txnEditData = null;

function openTxnEditModal(txn) {
    txnEditData = txn;
    document.getElementById('txnEditTitle').textContent = 'Transaction - ' + (txn.clientName || 'Walk-in');
    document.getElementById('txnEditId').value = txn.id || '';
    document.getElementById('txnEditClient').value = txn.clientName || 'Walk-in';
    document.getElementById('txnEditAmount').value = '$' + parseFloat(txn.amount || 0).toFixed(2);
    document.getElementById('txnEditStatus').value = (txn.status || '').charAt(0).toUpperCase() + (txn.status || '').slice(1);
    document.getElementById('txnEditCard').value = (txn.cardBrand || '') + ' ****' + (txn.cardLast4 || '');
    document.getElementById('txnEditDate').value = txn.timestamp ? new Date(txn.timestamp).toLocaleString() : '';
    document.getElementById('txnEditDesc').value = txn.description || '';
    document.getElementById('txnEditModalStatus').style.display = 'none';
    document.getElementById('txnCustomRefundArea').style.display = 'none';
    document.getElementById('txnRefundAmount').value = '';
    if (txn.status === 'approved') {
        document.getElementById('txnRefundSection').style.display = '';
        document.getElementById('txnRefundedNotice').style.display = 'none';
    } else if (txn.status === 'refunded') {
        document.getElementById('txnRefundSection').style.display = 'none';
        document.getElementById('txnRefundedNotice').style.display = '';
    } else {
        document.getElementById('txnRefundSection').style.display = 'none';
        document.getElementById('txnRefundedNotice').style.display = 'none';
    }
    document.getElementById('txnEditModal').classList.add('show');
}

function closeTxnEditModal() {
    document.getElementById('txnEditModal').classList.remove('show');
    txnEditData = null;
}
document.getElementById('txnEditModal').addEventListener('click', function(e) { if (e.target === this) closeTxnEditModal(); });

async function txnRefundFull() {
    if (!txnEditData || !txnEditData.id) return;
    if (!confirm('Refund the full $' + parseFloat(txnEditData.amount).toFixed(2) + ' back to the customer?')) return;
    await doRefund(txnEditData.id, parseFloat(txnEditData.amount));
}

async function txnRefundCustom() {
    if (!txnEditData || !txnEditData.id) return;
    const amt = parseFloat(document.getElementById('txnRefundAmount').value);
    if (!amt || amt <= 0) { alert('Enter a valid refund amount'); return; }
    if (!confirm('Refund $' + amt.toFixed(2) + ' back to the customer?')) return;
    await doRefund(txnEditData.id, amt);
}

async function doRefund(id, amount) {
    const statusEl = document.getElementById('txnEditModalStatus');
    statusEl.className = 'modal-status-msg'; statusEl.textContent = 'Processing refund...'; statusEl.style.display = 'block';
    try {
        const res = await fetch('?action=refund', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id, amount }) });
        const data = await res.json();
        if (data.success) {
            statusEl.className = 'modal-status-msg success'; statusEl.textContent = 'Refund of $' + amount.toFixed(2) + ' processed!';
            document.getElementById('txnRefundSection').style.display = 'none';
            document.getElementById('txnRefundedNotice').style.display = '';
            setTimeout(() => location.reload(), 1500);
        } else {
            statusEl.className = 'modal-status-msg error'; statusEl.textContent = 'Refund failed: ' + (data.error || 'Unknown error');
        }
    } catch(e) { statusEl.className = 'modal-status-msg error'; statusEl.textContent = 'Error: ' + e.message; }
}

// ─── Schedule Functions ──────────────────────────────────────
function filterSchedule() {
    const from = document.getElementById('schedFrom').value;
    const to = document.getElementById('schedTo').value;
    document.querySelectorAll('#scheduleTable tbody tr.schedule-row').forEach(r => {
        const d = r.dataset.schedDate || '';
        const ym = d.substring(0, 7);
        let show = true;
        if (from && ym < from) show = false;
        if (to && ym > to) show = false;
        r.style.display = show ? '' : 'none';
    });
}

var scheduleSortCol = 0;
var scheduleSortAsc = true;
function sortScheduleTable(col) {
    const table = document.getElementById('scheduleTable');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr.schedule-row'));
    if (scheduleSortCol === col) {
        scheduleSortAsc = !scheduleSortAsc;
    } else {
        scheduleSortCol = col;
        scheduleSortAsc = true;
    }
    rows.sort((a, b) => {
        let va;
        let vb;
        if (col === 0) {
            va = a.dataset.schedDate || '';
            vb = b.dataset.schedDate || '';
        } else if (col === 6) {
            va = parseFloat(a.dataset.schedAmount) || 0;
            vb = parseFloat(b.dataset.schedAmount) || 0;
            return scheduleSortAsc ? va - vb : vb - va;
        } else {
            va = (a.cells[col]?.textContent || '').trim().toLowerCase();
            vb = (b.cells[col]?.textContent || '').trim().toLowerCase();
        }
        return scheduleSortAsc ? va.localeCompare(vb) : vb.localeCompare(va);
    });
    rows.forEach(row => tbody.appendChild(row));
    table.querySelectorAll('th[data-sort-col]').forEach(header => {
        header.setAttribute('aria-sort', 'none');
        header.querySelector('.schedule-sort-indicator').textContent = '';
    });
    const activeHeader = table.querySelector(`th[data-sort-col="${col}"]`);
    activeHeader.setAttribute('aria-sort', scheduleSortAsc ? 'ascending' : 'descending');
    activeHeader.querySelector('.schedule-sort-indicator').textContent = scheduleSortAsc ? ' ▲' : ' ▼';
}

function editScheduleItem(id, name, amount, date) {
    document.getElementById('schedEditId').value = id;
    document.getElementById('schedEditName').value = name;
    document.getElementById('schedEditAmount').value = amount.toFixed(2);
    document.getElementById('schedEditDate').value = date;
    document.getElementById('schedEditStatus').style.display = 'none';
    document.getElementById('schedEditModal').classList.add('show');
}
document.getElementById('schedEditModal').addEventListener('click', function(e) { if (e.target === this) this.classList.remove('show'); });

async function saveScheduleEdit() {
    const id = document.getElementById('schedEditId').value;
    const amount = parseFloat(document.getElementById('schedEditAmount').value);
    const date = document.getElementById('schedEditDate').value;
    const statusEl = document.getElementById('schedEditStatus');
    if (!amount || amount <= 0) { statusEl.className = 'modal-status-msg error'; statusEl.textContent = 'Enter a valid amount'; statusEl.style.display = ''; return; }
    statusEl.className = 'modal-status-msg'; statusEl.textContent = 'Saving...'; statusEl.style.display = '';
    try {
        // Update amount
        await fetch('?action=autopay_update_amount', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id, amount }) });
        // Update date
        await fetch('?action=autopay_update_date', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id, nextCharge: date }) });
        statusEl.className = 'modal-status-msg success'; statusEl.textContent = 'Updated!';
        setTimeout(() => location.reload(), 800);
    } catch(e) { statusEl.className = 'modal-status-msg error'; statusEl.textContent = 'Error: ' + e.message; }
}

// ─── Dashboard Recent Transactions Sort ──────────────────────
var dashSortCol = -1;
var dashSortAsc = false;
function sortDashTable(col) {
    const table = document.getElementById('dashRecentTable');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    if (dashSortCol === col) { dashSortAsc = !dashSortAsc; } else { dashSortCol = col; dashSortAsc = true; }
    rows.sort((a, b) => {
        let va, vb;
        if (col === 0) { va = a.dataset.sortTime || ''; vb = b.dataset.sortTime || ''; }
        else if (col === 4) { va = parseFloat(a.dataset.sortAmount) || 0; vb = parseFloat(b.dataset.sortAmount) || 0; return dashSortAsc ? va - vb : vb - va; }
        else { va = (a.cells[col]?.textContent || '').toLowerCase(); vb = (b.cells[col]?.textContent || '').toLowerCase(); }
        return dashSortAsc ? va.localeCompare(vb) : vb.localeCompare(va);
    });
    rows.forEach(r => tbody.appendChild(r));
}

// ─── Payment Links ──────────────────────────────────────────
function addLinkProduct() {
    const row = document.createElement('div');
    row.className = 'link-product-row';
    row.style = 'display:flex; gap:8px; margin-bottom:8px; align-items:center;';
    row.innerHTML = '<input type="text" class="lp-name" placeholder="Product name" style="flex:2; padding:10px 12px; border:2px solid #e0e0e0; border-radius:10px; font-size:14px;">' +
        '<input type="number" class="lp-price" placeholder="Price" step="0.01" min="0.01" style="flex:1; padding:10px 12px; border:2px solid #e0e0e0; border-radius:10px; font-size:14px;">' +
        '<button onclick="this.parentElement.remove()" style="background:#fef2f2; color:#dc2626; border:1px solid #fecaca; padding:8px 12px; border-radius:8px; cursor:pointer; font-size:14px;">&times;</button>';
    document.getElementById('linkProducts').appendChild(row);
    row.querySelector('.lp-name').focus();
}

async function createPayLink() {
    // Collect products
    const rows = document.querySelectorAll('#linkProducts .link-product-row');
    const products = [];
    rows.forEach(row => {
        const name = row.querySelector('.lp-name').value.trim();
        const price = parseFloat(row.querySelector('.lp-price').value);
        if (name && price > 0) products.push({ name, price });
    });
    if (products.length === 0) { alert('Add at least one product with a name and price'); return; }
    const amount = products[0].price; // Use first product price as default
    const description = document.getElementById('linkDesc').value || 'Payment';
    const clientName = document.getElementById('linkName').value;
    const clientEmail = document.getElementById('linkEmail').value;
    const source = document.getElementById('linkSource').value;
    const autopay = document.getElementById('linkAutopay').checked;
    const singleUse = document.getElementById('linkSingleUse').checked;
    if (!amount || amount <= 0) { alert('Please enter a valid amount'); return; }
    try {
        const res = await fetch('?action=create_link', { method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ amount, description, clientName, clientEmail, source, autopay, singleUse }) });
        const data = await res.json();
        if (data.success) {
            document.getElementById('linkUrl').value = data.link.url;
            document.getElementById('linkResult').style.display = 'block';
            document.getElementById('linkCopied').style.display = 'none';
            loadLinksTable();
        } else { alert(data.error || 'Failed to create link'); }
    } catch(e) { alert('Connection error'); }
}

function copyLink() {
    const url = document.getElementById('linkUrl');
    url.select(); url.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(url.value).then(() => {
        document.getElementById('linkCopied').style.display = 'block';
        setTimeout(() => document.getElementById('linkCopied').style.display = 'none', 3000);
    });
}

async function loadLinksTable() {
    try {
        const res = await fetch('?action=list_links', { method: 'POST', headers: {'Content-Type':'application/json'}, body: '{}' });
        const links = await res.json();
        const tbody = document.getElementById('linksBody');
        if (!tbody) return;
        tbody.innerHTML = '';
        if (!links.length) { tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#999;padding:24px;">No payment links created yet</td></tr>'; return; }
        links.sort((a, b) => (b.createdAt || '').localeCompare(a.createdAt || ''));
        links.forEach(l => {
            const date = l.createdAt ? new Date(l.createdAt).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'}) : '';
            const statusColor = l.status === 'active' ? '#27ae60' : (l.status === 'used' ? '#3498db' : '#999');
            const statusLabel = l.status === 'active' ? 'Active' : (l.status === 'used' ? 'Used' + (l.usedBy ? ' by ' + l.usedBy : '') : 'Inactive');
            const srcBadge = l.source === 'JJ' ? '<span style="background:#9b59b6;color:#fff;padding:2px 8px;border-radius:6px;font-size:11px;">JJ</span>'
                : (l.source === 'manual' ? '<span style="background:#3498db;color:#fff;padding:2px 8px;border-radius:6px;font-size:11px;">Manual</span>'
                : '<span style="background:#667eea;color:#fff;padding:2px 8px;border-radius:6px;font-size:11px;">Link</span>');
            const actions = l.status === 'active'
                ? '<button onclick="copyLinkById(\'' + l.url + '\')" style="background:#667eea;color:#fff;border:none;padding:4px 10px;border-radius:6px;cursor:pointer;font-size:12px;margin-right:4px;">Copy</button>'
                  + '<button onclick="deactivateLink(\'' + l.id + '\')" style="background:#e74c3c;color:#fff;border:none;padding:4px 10px;border-radius:6px;cursor:pointer;font-size:12px;">Disable</button>'
                : '<span style="color:#999;font-size:12px;">—</span>';
            tbody.innerHTML += '<tr><td>' + date + '</td><td>' + (l.description||'') + '</td><td>$' + parseFloat(l.amount).toFixed(2) + '</td><td>' + srcBadge + '</td><td>' + (l.autopay ? 'Yes' : 'No') + '</td><td><span style="color:' + statusColor + ';font-weight:600;">' + statusLabel + '</span></td><td>' + actions + '</td></tr>';
        });
    } catch(e) {}
}

function copyLinkById(url) {
    navigator.clipboard.writeText(url).then(() => { alert('Link copied!'); });
}

async function deactivateLink(id) {
    if (!confirm('Disable this payment link?')) return;
    await fetch('?action=deactivate_link', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id }) });
    loadLinksTable();
}

// Load links table on page load
document.addEventListener('DOMContentLoaded', function() { loadLinksTable(); });



    // ─── Future AutoPay ──────────────────────────
    (function() {
        const dateInput = document.getElementById('futureApDate');
        if (dateInput) {
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            dateInput.min = tomorrow.toISOString().split('T')[0];
            dateInput.value = tomorrow.toISOString().split('T')[0];
        }
        // Autocomplete for future autopay name
        const nameInput = document.getElementById('futureApName');
        const sugBox = document.getElementById('futureApSuggestions');
        if (nameInput && sugBox) {
            nameInput.addEventListener('input', async function() {
                const q = this.value.trim();
                if (q.length < 2) { sugBox.style.display = 'none'; return; }
                try {
                    const res = await fetch('?action=customers');
                    const custs = await res.json();
                    const matches = custs.filter(c => c.name && c.name.toLowerCase().includes(q.toLowerCase())).slice(0, 8);
                    if (!matches.length) { sugBox.style.display = 'none'; return; }
                    sugBox.innerHTML = matches.map(c => '<div class="autocomplete-item" style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0;" data-name="' + c.name + '" data-email="' + (c.email||'') + '" data-phone="' + (c.phone||'') + '">' + c.name + (c.email ? ' &mdash; ' + c.email : '') + '</div>').join('');
                    sugBox.style.display = 'block';
                    sugBox.querySelectorAll('.autocomplete-item').forEach(item => {
                        item.addEventListener('click', function() {
                            document.getElementById('futureApName').value = this.dataset.name;
                            document.getElementById('futureApEmail').value = this.dataset.email || '';
                            document.getElementById('futureApPhone').value = this.dataset.phone || '';
                            sugBox.style.display = 'none';
                        });
                    });
                } catch(e) {}
            });
        }
        // Card formatting
        const cardInput = document.getElementById('futureApCard');
        if (cardInput) {
            cardInput.addEventListener('input', function() {
                let v = this.value.replace(/\D/g, '').substring(0, 16);
                this.value = v.replace(/(.{4})/g, '$1 ').trim();
            });
        }
        const expiryInput = document.getElementById('futureApExpiry');
        if (expiryInput) {
            expiryInput.addEventListener('input', function() {
                let v = this.value.replace(/\D/g, '').substring(0, 4);
                if (v.length >= 2) v = v.substring(0,2) + '/' + v.substring(2);
                this.value = v;
            });
        }
    })();

    async function submitFutureAutopay() {
        const btn = document.getElementById('futureApBtn');
        const status = document.getElementById('futureApStatus');
        const date = document.getElementById('futureApDate').value;
        const amount = parseFloat(document.getElementById('futureApAmount').value);
        const name = document.getElementById('futureApName').value.trim();
        const email = document.getElementById('futureApEmail').value.trim();
        const phone = document.getElementById('futureApPhone').value.trim();
        const address = document.getElementById('futureApAddress').value.trim();
        const city = document.getElementById('futureApCity').value.trim();
        const state = document.getElementById('futureApState').value.trim();
        const zip = document.getElementById('futureApZip').value.trim();
        const card = document.getElementById('futureApCard').value.replace(/\s/g, '');
        const expiry = document.getElementById('futureApExpiry').value;
        const cvc = document.getElementById('futureApCvc').value;
        const desc = document.getElementById('futureApDesc').value.trim() || 'Recurring Charge';
        const source = document.getElementById('futureApSource').value;

        if (!date) { status.style.display='block'; status.className='status error'; status.textContent='Please select a future date'; return; }
        if (!name || !amount || !card) { status.style.display='block'; status.className='status error'; status.textContent='Name, amount, and card are required'; return; }

        const today = new Date().toISOString().split('T')[0];
        if (date <= today) { status.style.display='block'; status.className='status error'; status.textContent='Date must be in the future'; return; }

        const [em, ey] = expiry.split('/');

        btn.disabled = true;
        btn.innerHTML = '<span class=spinner></span> Scheduling...';
        status.style.display = 'none';

        try {
            const body = {
                clientName: name, clientEmail: email, clientPhone: phone,
                clientAddress: address, clientCity: city, clientState: state, clientZip: zip,
                amount: amount, description: desc, frequency: 'monthly',
                startDate: date, chargedNow: false,
                cardNumber: card, expMonth: em, expYear: ey ? (ey.length === 2 ? '20'+ey : ey) : '',
                cvc: cvc, cardLast4: card.slice(-4), cardBrand: 'Card',
                source: source
            };
            const res = await fetch('?action=autopay_create', {
                method: 'POST', headers: {'Content-Type':'application/json'},
                body: JSON.stringify(body)
            });
            const data = await res.json();
            if (data.success) {
                status.style.display = 'block'; status.className = 'status success';
                status.innerHTML = '✓ Scheduled! <strong>' + name + '</strong> will be charged <strong>$' + amount.toFixed(2) + '</strong> on <strong>' + date + '</strong>. Monthly autopay starts from that date.';
                // Clear form
                document.getElementById('futureApAmount').value = '';
                document.getElementById('futureApName').value = '';
                document.getElementById('futureApEmail').value = '';
                document.getElementById('futureApPhone').value = '';
                document.getElementById('futureApAddress').value = '';
                document.getElementById('futureApCity').value = '';
                document.getElementById('futureApState').value = '';
                document.getElementById('futureApZip').value = '';
                document.getElementById('futureApCard').value = '';
                document.getElementById('futureApExpiry').value = '';
                document.getElementById('futureApCvc').value = '';
                document.getElementById('futureApDesc').value = '';
            } else {
                status.style.display = 'block'; status.className = 'status error';
                status.textContent = data.error || 'Failed to schedule';
            }
        } catch(e) {
            status.style.display = 'block'; status.className = 'status error';
            status.textContent = 'Connection error';
        }
        btn.disabled = false; btn.textContent = 'Schedule Future AutoPay';
    }

// ─── Add Customer Modal ──────────────────────────────────────
function openAddCustomerModal() {
    document.getElementById('addCustName').value = '';
    document.getElementById('addCustEmail').value = '';
    document.getElementById('addCustPhone').value = '';
    document.getElementById('addCustAddress').value = '';
    document.getElementById('addCustCity').value = '';
    document.getElementById('addCustState').value = '';
    document.getElementById('addCustZip').value = '';
    document.getElementById('addCustStatus').style.display = 'none';
    document.getElementById('addCustomerModal').classList.add('show');
    setTimeout(() => document.getElementById('addCustName').focus(), 200);
}

function closeAddCustomerModal() {
    document.getElementById('addCustomerModal').classList.remove('show');
}

async function saveNewCustomer() {
    const name = document.getElementById('addCustName').value.trim();
    if (!name) { 
        const st = document.getElementById('addCustStatus');
        st.textContent = 'Name is required';
        st.style.background = '#fef2f2'; st.style.color = '#dc2626'; st.style.display = 'block';
        return; 
    }
    const payload = {
        name: name,
        email: document.getElementById('addCustEmail').value.trim(),
        phone: document.getElementById('addCustPhone').value.trim(),
        address: document.getElementById('addCustAddress').value.trim(),
        city: document.getElementById('addCustCity').value.trim(),
        state: document.getElementById('addCustState').value.trim(),
        zip: document.getElementById('addCustZip').value.trim()
    };
    const st = document.getElementById('addCustStatus');
    st.textContent = 'Saving...'; st.style.background = '#dbeafe'; st.style.color = '#2563eb'; st.style.display = 'block';
    try {
        const res = await fetch('?action=add_customer', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
        const data = await res.json();
        if (data.success) {
            st.textContent = 'Customer added!'; st.style.background = '#d1fae5'; st.style.color = '#059669';
            setTimeout(() => location.reload(), 800);
        } else {
            st.textContent = data.error || 'Failed to add customer'; st.style.background = '#fef2f2'; st.style.color = '#dc2626';
        }
    } catch(e) {
        st.textContent = 'Error: ' + e.message; st.style.background = '#fef2f2'; st.style.color = '#dc2626';
    }
}


// ─── Logs Filter ──────────────────────────────────────
function filterLogs() {
    const type = document.getElementById('logFilterType').value;
    const search = (document.getElementById('logSearch').value || '').toLowerCase();
    document.querySelectorAll('#logsTable .log-row').forEach(row => {
        const action = row.dataset.action || '';
        const searchData = row.dataset.search || '';
        let show = true;
        if (type !== 'all') {
            if (type === 'autopay') { show = action.indexOf('autopay') !== -1; }
            else if (type === 'deposit') { show = action.indexOf('deposit') !== -1; }
            else { show = action === type; }
        }
        if (show && search) { show = searchData.indexOf(search) !== -1; }
        row.style.display = show ? '' : 'none';
    });
}

</script>
<script>
document.addEventListener('keydown', function(e) {
    if (!e.target || (e.target.type !== 'tel' && e.target.id !== 'editCustPhone' && e.target.id !== 'addCustPhone')) return;
    if (e.key !== 'Backspace' || e.target.selectionStart !== e.target.selectionEnd) return;

    var caret = e.target.selectionStart;
    while (caret > 0 && /[^0-9]/.test(e.target.value.charAt(caret - 1))) caret--;
    if (caret !== e.target.selectionStart) e.target.setSelectionRange(caret, caret);
});

document.addEventListener('input', function(e) {
    if (e.target && (e.target.type === 'tel' || e.target.id === 'editCustPhone' || e.target.id === 'addCustPhone')) {
        var d = e.target.value.replace(/[^0-9]/g, '');
        if (d.length > 10) d = d.substring(0, 10);
        var f = '';
        if (d.length > 0) f = '(' + d.substring(0, 3);
        if (d.length >= 3) f += ') ';
        if (d.length > 3) f += d.substring(3, 6);
        if (d.length >= 6) f += '-' + d.substring(6, 10);
        e.target.value = f;
    }
});
</script>
</body>
</html>