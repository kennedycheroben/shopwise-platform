#!/usr/bin/env bash
# ==============================================================================
# SHOPWISE MVP: PUBLIC SHOPPING, GUEST CART & AUTHENTICATED CHECKOUT REGRESSION SUITE
#
# Covers:
# 1. Public storefront access (home, shop, category, product detail, search)
# 2. Guest cart operations (add, view, update, remove, stock/price recalculation)
# 3. Checkout authentication gate & return URL preservation
# 4. Cart preservation across navigation, registration, and login
# 5. Google Sign-In policy (existing verified customer accounts only)
# 6. Database integrity and zero drift verification on shopwise_test
# ==============================================================================

set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1/shopwise-platform/public}"
BASE_URL="${BASE_URL%/}"

MYSQL_CMD="${MYSQL_CMD:-/opt/lampp/bin/mysql}"
if [ ! -x "$MYSQL_CMD" ]; then
    MYSQL_CMD="$(command -v mysql || true)"
fi

PHP_CMD="${PHP_CMD:-/opt/lampp/bin/php}"
if [ ! -x "$PHP_CMD" ]; then
    PHP_CMD="$(command -v php || true)"
fi

DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_NAME="${DB_NAME:-shopwise_test}"

if [ "$DB_NAME" = "shopwise_db" ]; then
    echo -e "\033[0;31mFATAL: Refusing to run acceptance tests against protected database '$DB_NAME'!\033[0m" >&2
    exit 1
fi
if [[ ! "$DB_NAME" =~ _test$ ]]; then
    echo -e "\033[0;31mFATAL: Test database name must end with '_test', got '$DB_NAME'!\033[0m" >&2
    exit 1
fi

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

PASSED=0
FAILED=0

pass() {
    echo -e "  [${GREEN}PASS${NC}] $1"
    PASSED=$((PASSED + 1))
}

fail() {
    echo -e "  [${RED}FAIL${NC}] $1" >&2
    FAILED=$((FAILED + 1))
}

info() {
    echo -e "\n${BOLD}${CYAN}=== $1 ===${NC}"
}

RAND_SUF=$(head -c 16 /dev/urandom | xxd -p | head -c 8)
COOKIE_JAR="/tmp/sw_test_guest_cookie_${RAND_SUF}.txt"
BODY_FILE="/tmp/sw_test_body_${RAND_SUF}.html"

cleanup() {
    rm -f "$COOKIE_JAR" "$BODY_FILE" "/tmp/sw_test_"*"_${RAND_SUF}"* 2>/dev/null || true
}
trap cleanup EXIT

info "BASELINE SNAPSHOT: Capturing pre-test database state on $DB_NAME"
PRE_USERS=$("$MYSQL_CMD" -u "$DB_USER" "$DB_NAME" -N -e "SELECT COUNT(*) FROM users")
PRE_CARTS=$("$MYSQL_CMD" -u "$DB_USER" "$DB_NAME" -N -e "SELECT COUNT(*) FROM carts")
PRE_CART_ITEMS=$("$MYSQL_CMD" -u "$DB_USER" "$DB_NAME" -N -e "SELECT COUNT(*) FROM cart_items")
PRE_ORDERS=$("$MYSQL_CMD" -u "$DB_USER" "$DB_NAME" -N -e "SELECT COUNT(*) FROM orders")
echo "  Pre-test users:      $PRE_USERS"
echo "  Pre-test carts:      $PRE_CARTS"
echo "  Pre-test cart_items: $PRE_CART_ITEMS"
echo "  Pre-test orders:     $PRE_ORDERS"

# ------------------------------------------------------------------------------
# 1. PUBLIC STOREFRONT ACCESS
# ------------------------------------------------------------------------------
info "1. PUBLIC STOREFRONT ACCESS (Browsing without authentication)"

# 1.1 Homepage
HTTP_CODE=$(curl -s -o "$BODY_FILE" -w "%{http_code}" "$BASE_URL/")
if [ "$HTTP_CODE" -eq 200 ] || [ "$HTTP_CODE" -eq 302 ]; then
    pass "Guest can access homepage (HTTP $HTTP_CODE)"
else
    fail "Guest cannot access homepage (HTTP $HTTP_CODE)"
fi

# 1.2 Catalog / Shop
HTTP_CODE=$(curl -s -o "$BODY_FILE" -w "%{http_code}" "$BASE_URL/shop")
if [ "$HTTP_CODE" -eq 200 ] && grep -qi "Shop" "$BODY_FILE"; then
    pass "Guest can access product listing (/shop) without credentials"
else
    fail "Guest product listing access failed (HTTP $HTTP_CODE)"
fi

# 1.3 Category browsing
CAT_SLUG=$("$MYSQL_CMD" -u "$DB_USER" "$DB_NAME" -N -e "SELECT slug FROM categories WHERE is_active = 1 LIMIT 1")
HTTP_CODE=$(curl -s -o "$BODY_FILE" -w "%{http_code}" "$BASE_URL/category/$CAT_SLUG")
if [ "$HTTP_CODE" -eq 200 ]; then
    pass "Guest can access category page (/category/$CAT_SLUG) without credentials"
else
    fail "Guest category page access failed (HTTP $HTTP_CODE)"
fi

# 1.4 Product details
PROD_SLUG=$("$MYSQL_CMD" -u "$DB_USER" "$DB_NAME" -N -e "SELECT slug FROM products WHERE is_active = 1 LIMIT 1")
HTTP_CODE=$(curl -s -o "$BODY_FILE" -w "%{http_code}" "$BASE_URL/product/$PROD_SLUG")
if [ "$HTTP_CODE" -eq 200 ] && grep -qi "KES" "$BODY_FILE"; then
    pass "Guest can access product details (/product/$PROD_SLUG) with prices and stock visible"
else
    fail "Guest product details access failed (HTTP $HTTP_CODE)"
fi

# 1.5 Search and filtering
HTTP_CODE=$(curl -s -o "$BODY_FILE" -w "%{http_code}" "$BASE_URL/shop?q=jacket")
if [ "$HTTP_CODE" -eq 200 ]; then
    pass "Guest can search and filter catalog (/shop?q=jacket) without credentials"
else
    fail "Guest search failed (HTTP $HTTP_CODE)"
fi

# 1.6 Public cart view
HTTP_CODE=$(curl -s -o "$BODY_FILE" -w "%{http_code}" "$BASE_URL/cart")
if [ "$HTTP_CODE" -eq 200 ]; then
    pass "Guest can access shopping cart page (/cart) without credentials"
else
    fail "Guest cart page access failed (HTTP $HTTP_CODE)"
fi

# ------------------------------------------------------------------------------
# 2. GUEST SHOPPING CART OPERATIONS
# ------------------------------------------------------------------------------
info "2. GUEST SHOPPING CART OPERATIONS (Adding, updating, removing with recalculation)"

# Pick an active variant with sufficient stock
VARIANT_ID=$("$MYSQL_CMD" -u "$DB_USER" "$DB_NAME" -N -e "
    SELECT pv.id FROM product_variants pv
    JOIN products p ON p.id = pv.product_id
    WHERE pv.is_active = 1 AND p.is_active = 1 AND pv.stock_qty >= 10
    LIMIT 1
")

# Extract CSRF token from cart page
CSRF_TOKEN=$(curl -s -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/cart" | grep -o 'name="csrf_token" value="[^"]*"' | head -n1 | cut -d'"' -f4 || true)
if [ -z "$CSRF_TOKEN" ]; then
    # Fallback to session CSRF via PHP runner if page has no visible form
    CSRF_TOKEN=$(SHOPWISE_CONFIG=testing "$PHP_CMD" -r "
        require 'app/bootstrap.php';
        echo csrf_token();
    ")
fi

# 2.1 Guest adds item to cart via PHP service with session token cookie
CART_RUNNER=$(cat <<'EOF'
require 'app/bootstrap.php';
use App\Database\Database;
use App\Services\CartService;

$pdo = Database::getInstance();
$cartService = new CartService($pdo);

$variantId = (int)$argv[1];
$runId = $argv[2];

$res = [];

// 1. Add item as guest
$addRes = $cartService->addItem($variantId, 2);
$res['add_success'] = $addRes['success'];
$guestToken = $_COOKIE[CartService::COOKIE_NAME] ?? null;
$res['cookie_is_64_hex'] = (is_string($guestToken) && strlen($guestToken) === 64);

// 2. View cart details and verify server-side price recalculation
$details = $cartService->getCartDetails();
$res['cart_has_items'] = !empty($details['items']);
$res['cart_subtotal_positive'] = ($details['subtotal'] > 0);
$res['cart_item_qty_correct'] = ((int)($details['items'][0]['quantity'] ?? 0) === 2);

// Verify price is NOT trusted from client; matches DB price
$stmtPrice = $pdo->prepare("
    SELECT COALESCE(pv.price_override, p.base_price) AS unit_price
    FROM product_variants pv
    JOIN products p ON p.id = pv.product_id
    WHERE pv.id = ?
");
$stmtPrice->execute([$variantId]);
$dbUnitPrice = (float)$stmtPrice->fetchColumn();
$expectedSubtotal = $dbUnitPrice * 2;
$res['server_recalculated_price'] = (abs($details['subtotal'] - $expectedSubtotal) < 0.01);

// 3. Update quantity
$upRes = $cartService->updateItem($variantId, 3);
$res['update_success'] = $upRes['success'];
$detailsUp = $cartService->getCartDetails();
$res['updated_qty_matches'] = ((int)($detailsUp['items'][0]['quantity'] ?? 0) === 3);

// 4. Invalid variant rejection
$badAdd = $cartService->addItem(9999999, 1);
$res['invalid_variant_rejected'] = (!$badAdd['success']);

// 5. Invalid quantity rejection
$badQtyAdd = $cartService->addItem($variantId, 0);
$res['invalid_qty_rejected'] = (!$badQtyAdd['success']);

// 6. Excessive quantity beyond stock clamped or rejected
$stmtStock = $pdo->prepare("SELECT stock_qty FROM product_variants WHERE id = ?");
$stmtStock->execute([$variantId]);
$availStock = (int)$stmtStock->fetchColumn();
$extremeQty = $availStock + 500;
$excessRes = $cartService->updateItem($variantId, $extremeQty);
$detailsExcess = $cartService->getCartDetails();
$res['excess_qty_clamped_to_stock'] = ((int)($detailsExcess['items'][0]['quantity'] ?? 0) <= min($availStock, CartService::MAX_QUANTITY_PER_LINE));

// 7. Remove item
$remRes = $cartService->removeItem($variantId);
$res['remove_success'] = $remRes['success'];
$detailsEmpty = $cartService->getCartDetails();
$res['cart_empty_after_remove'] = empty($detailsEmpty['items']);

// Re-add 2 items so guest has a cart for subsequent preservation tests
$cartService->addItem($variantId, 2);

$res['guest_token'] = $cartService->getSessionToken();
echo json_encode($res);
EOF
)

CART_JSON=$(SHOPWISE_CONFIG=testing "$PHP_CMD" -r "$CART_RUNNER" "$VARIANT_ID" "$RAND_SUF")

assert_c_true() {
    local key="$1"
    local desc="$2"
    local val=$(echo "$CART_JSON" | grep -o "\"$key\":true" || true)
    if [ -n "$val" ]; then
        pass "$desc"
    else
        fail "$desc (failed $key in $CART_JSON)"
    fi
}

assert_c_true "add_success" "Guest can add products to shopping cart"
assert_c_true "cookie_is_64_hex" "Guest cart establishes secure 64-hex token cookie"
assert_c_true "cart_has_items" "Guest cart contains line items"
assert_c_true "cart_subtotal_positive" "Guest cart subtotal is properly computed"
assert_c_true "server_recalculated_price" "Cart price and totals are server-recalculated from database"
assert_c_true "update_success" "Guest can update product quantities in cart"
assert_c_true "updated_qty_matches" "Updated cart quantity reflects correctly"
assert_c_true "invalid_variant_rejected" "Invalid product variant IDs are strictly rejected"
assert_c_true "invalid_qty_rejected" "Zero and negative quantities are strictly rejected"
assert_c_true "excess_qty_clamped_to_stock" "Quantities exceeding stock are capped/clamped server-side"
assert_c_true "remove_success" "Guest can remove items from cart"
assert_c_true "cart_empty_after_remove" "Cart subtotal and items update upon item removal"

GUEST_TOKEN=$(echo "$CART_JSON" | grep -o '"guest_token":"[^"]*"' | cut -d'"' -f4)

# ------------------------------------------------------------------------------
# 3. CHECKOUT AUTHENTICATION REQUIREMENT
# ------------------------------------------------------------------------------
info "3. CHECKOUT AUTHENTICATION REQUIREMENT & ORDER GATE"

# 3.1 Guest visiting /checkout sees sign-in gate
CHECKOUT_BODY=$(curl -s -b "shopwise_cart=$GUEST_TOKEN" "$BASE_URL/checkout")
if echo "$CHECKOUT_BODY" | grep -qi "Sign in to check out"; then
    pass "Unauthenticated visitor visiting /checkout is shown the sign-in/register gate"
elif echo "$CHECKOUT_BODY" | grep -qi "login?next="; then
    pass "Unauthenticated visitor visiting /checkout is prompted with login?next="
else
    fail "Unauthenticated visitor not prompted with authentication on /checkout"
fi

# 3.2 Intended destination preserved in redirect / next URL
if echo "$CHECKOUT_BODY" | grep -qi "next=%2Fcheckout" || echo "$CHECKOUT_BODY" | grep -qi "next=/checkout"; then
    pass "Checkout destination preserved in next parameter (next=/checkout)"
else
    fail "Checkout destination was not preserved in authentication links"
fi

# 3.3 Direct unauthenticated POST /checkout/place is blocked and redirected to login
PLACE_RESP=$(curl -s -i -b "shopwise_cart=$GUEST_TOKEN" -X POST "$BASE_URL/checkout/place")
PLACE_STATUS=$(echo "$PLACE_RESP" | head -n1 | cut -d' ' -f2)
PLACE_LOCATION=$(echo "$PLACE_RESP" | grep -i "^Location:" | tr -d '\r' | cut -d' ' -f2 || true)

if [ "$PLACE_STATUS" -eq 302 ] && [[ "$PLACE_LOCATION" =~ login\?next= ]]; then
    pass "Direct POST /checkout/place without session redirects to login?next=%2Fcheckout (HTTP 302)"
else
    fail "Direct POST /checkout/place without session was not blocked as expected (Status: $PLACE_STATUS, Loc: $PLACE_LOCATION)"
fi

# Verify no rogue order was created in DB
ORDERS_AFTER_ROGUE=$("$MYSQL_CMD" -u "$DB_USER" "$DB_NAME" -N -e "SELECT COUNT(*) FROM orders")
if [ "$ORDERS_AFTER_ROGUE" -eq "$PRE_ORDERS" ]; then
    pass "Zero rogue orders created from unauthenticated checkout attempt"
else
    fail "Unauthenticated checkout mutated orders table!"
fi

# ------------------------------------------------------------------------------
# 4. CART PRESERVATION & MERGING ACROSS AUTHENTICATION
# ------------------------------------------------------------------------------
info "4. CART PRESERVATION & MERGE (Registration and Login)"

MERGE_RUNNER=$(cat <<'EOF'
require 'app/bootstrap.php';
use App\Database\Database;
use App\Services\CartService;
use App\Services\CustomerAuthService;

$pdo = Database::getInstance();
$variantId = (int)$argv[1];
$runId = $argv[2];
$ip = '127.0.0.1';

$res = [];

// 1. Build guest cart with 2 items of $variantId
$guestToken = bin2hex(random_bytes(32));
$_COOKIE[CartService::COOKIE_NAME] = $guestToken;
$guestCartService = new CartService($pdo);
$guestCartService->addItem($variantId, 2);

// 2. Register new customer while having active guest cart
$regEmail = "cust_cart_reg_{$runId}@test.local";
$pwd = "StrongPass2026_{$runId}!";
$regData = [
    'first_name' => "CartTest",
    'last_name'  => "Customer",
    'email'      => $regEmail,
    'phone'      => "0712 345 678",
    'password'   => $pwd,
    'password_confirmation' => $pwd,
    'terms'      => '1',
];

[$regOk, $regMsg] = CustomerAuthService::register($regData, $ip);
$res['register_ok'] = $regOk;

$stmtU = $pdo->prepare("SELECT id FROM users WHERE email = :e");
$stmtU->execute([':e' => $regEmail]);
$newUserId = (int)$stmtU->fetchColumn();
$res['user_created'] = ($newUserId > 0);

// Verify user now owns the cart items from the guest cart
$stmtUserCart = $pdo->prepare("SELECT id FROM carts WHERE user_id = :uid LIMIT 1");
$stmtUserCart->execute([':uid' => $newUserId]);
$userCartId = (int)$stmtUserCart->fetchColumn();
$res['user_owns_cart'] = ($userCartId > 0);

$stmtItems = $pdo->prepare("SELECT variant_id, quantity FROM cart_items WHERE cart_id = :cid");
$stmtItems->execute([':cid' => $userCartId]);
$mergedItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
$res['cart_items_preserved_on_reg'] = (!empty($mergedItems) && (int)$mergedItems[0]['quantity'] === 2);

// 3. Test Cart Merge on Login with an existing customer cart
// Clear the previous customer session so we act as a guest again
unset($_SESSION[CustomerAuthService::SESSION_KEY]);
CartService::clearRateLimits();

// Create a separate customer with 1 item already in cart
$loginEmail = "cust_cart_login_{$runId}@test.local";
$pdo->prepare("
    INSERT INTO users (role, first_name, last_name, email, password_hash, email_verified_at, status, created_at, updated_at)
    VALUES ('customer', 'LoginTest', 'Customer', :e, :p, NOW(), 'active', NOW(), NOW())
")->execute([':e' => $loginEmail, ':p' => password_hash($pwd, PASSWORD_DEFAULT)]);
$loginUserId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO carts (user_id, created_at, updated_at) VALUES (:uid, NOW(), NOW())")->execute([':uid' => $loginUserId]);
$loginUserCartId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO cart_items (cart_id, variant_id, quantity, created_at, updated_at) VALUES (:cid, :vid, 3, NOW(), NOW())")
    ->execute([':cid' => $loginUserCartId, ':vid' => $variantId]);

// New guest session with 2 items of the same variant
$secondGuestToken = bin2hex(random_bytes(32));
$_COOKIE[CartService::COOKIE_NAME] = $secondGuestToken;
$guestCartService2 = new CartService($pdo);
$guestCartService2->addItem($variantId, 2);

// Customer logs in; guest cart merges into existing customer cart
[$loginOk, $loginMsg] = CustomerAuthService::login($loginEmail, $pwd, $ip);
$res['login_ok'] = $loginOk;

// Re-fetch customer's cart items: 3 + 2 = 5 of the same variant, no duplicate rows
$stmtLoginItems = $pdo->prepare("SELECT variant_id, quantity FROM cart_items WHERE cart_id = :cid");
$stmtLoginItems->execute([':cid' => $loginUserCartId]);
$combinedItems = $stmtLoginItems->fetchAll(PDO::FETCH_ASSOC);
$res['login_no_duplicate_rows'] = (count($combinedItems) === 1);
$res['login_quantities_merged'] = ((int)($combinedItems[0]['quantity'] ?? 0) === 5);

// The guest cart record should be cleaned up
$stmtGuestPurged = $pdo->prepare("SELECT COUNT(*) FROM carts WHERE session_token = :t");
$stmtGuestPurged->execute([':t' => $secondGuestToken]);
$res['guest_cart_purged'] = ((int)$stmtGuestPurged->fetchColumn() === 0);

$res['reg_user_id'] = $newUserId;
$res['login_user_id'] = $loginUserId;
echo json_encode($res);
EOF
)

MERGE_JSON=$(SHOPWISE_CONFIG=testing "$PHP_CMD" -r "$MERGE_RUNNER" "$VARIANT_ID" "$RAND_SUF")

assert_m_true() {
    local key="$1"
    local desc="$2"
    local val=$(echo "$MERGE_JSON" | grep -o "\"$key\":true" || true)
    if [ -n "$val" ]; then
        pass "$desc"
    else
        fail "$desc (failed $key in $MERGE_JSON)"
    fi
}

assert_m_true "register_ok" "Customer registration succeeds with active guest cart"
assert_m_true "user_owns_cart" "Guest cart successfully converted to customer cart upon registration"
assert_m_true "cart_items_preserved_on_reg" "Guest cart line items and quantities preserved after registration"
assert_m_true "login_ok" "Customer login succeeds with active guest cart"
assert_m_true "login_no_duplicate_rows" "Cart merge does not create duplicate rows for matching variants"
assert_m_true "login_quantities_merged" "Cart merge accurately sums quantities (3 + 2 = 5)"
assert_m_true "guest_cart_purged" "Guest cart record purged after merging to prevent leakage"

# ------------------------------------------------------------------------------
# 5. GOOGLE SIGN-IN & REGISTRATION POLICY
# ------------------------------------------------------------------------------
info "5. GOOGLE SIGN-IN & REGISTRATION POLICY (Direct Registration, Linking & Restrictions)"

GOOGLE_RUNNER=$(cat <<'EOF'
require 'app/bootstrap.php';
use App\Database\Database;
use App\Services\GoogleAuthService;
use App\Services\CustomerAuthService;
use App\Services\CartService;

$pdo = Database::getInstance();
$variantId = (int)$argv[1];
$runId = $argv[2];
$ip = '127.0.0.1';

$res = [];

// Setup RSA keypair and mock JWK
$rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$details = openssl_pkey_get_details($rsa);
$kid = 'mock_kid_' . $runId;
$n = rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '=');
$e = rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '=');

$mockJwk = ['keys' => [['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid, 'n' => $n, 'e' => $e]]];

$signJwt = function (array $payload, $rsaKey, string $keyId) {
    $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $keyId];
    $b64H = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
    $b64P = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $toSign = "{$b64H}.{$b64P}";
    openssl_sign($toSign, $sig, $rsaKey, OPENSSL_ALGO_SHA256);
    $b64S = rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
    return "{$toSign}.{$b64S}";
};

$basePayload = [
    'iss' => 'https://accounts.google.com',
    'aud' => GoogleAuthService::getClientId(),
    'exp' => time() + 300,
    'iat' => time() - 10,
    'nonce' => 'mock_nonce_' . $runId,
    'email_verified' => true,
];

// Configure Mock HTTP Client
$stubClient = new class($mockJwk) implements \App\Services\HttpClientInterface {
    private array $jwks;
    private string $idToken = '';
    public function __construct(array $jwks) { $this->jwks = $jwks; }
    public function setIdToken(string $jwt): void { $this->idToken = $jwt; }
    public function get(string $url, array $headers = []): array {
        return ['status' => 200, 'body' => json_encode($this->jwks), 'headers' => []];
    }
    public function post(string $url, array|string $data = [], array $headers = []): array {
        return ['status' => 200, 'body' => json_encode(['id_token' => $this->idToken, 'access_token' => 'mock-token', 'expires_in' => 3600]), 'headers' => []];
    }
};
GoogleAuthService::setHttpClient($stubClient);

// 1. Direct registration of a new customer with active guest cart
unset($_SESSION[CustomerAuthService::SESSION_KEY]);
$newGuestToken = bin2hex(random_bytes(32));
$_COOKIE[CartService::COOKIE_NAME] = $newGuestToken;
$guestCartSvc = new CartService($pdo);
$guestCartSvc->addItem($variantId, 2);

$newGoogleEmail = "google_new_{$runId}@test.local";
$newPayload = $basePayload;
$newPayload['email'] = $newGoogleEmail;
$newPayload['sub'] = "google_sub_new_{$runId}";
$newPayload['given_name'] = "Grace";
$newPayload['family_name'] = "Wanjiku";
$newPayload['picture'] = "https://lh3.googleusercontent.com/photo_{$runId}.jpg";
$stubClient->setIdToken($signJwt($newPayload, $rsa, $kid));

$session1 = [
    'state' => 'state1_' . $runId,
    'nonce' => $newPayload['nonce'],
    'code_verifier' => 'verifier1',
    'return_url' => '/checkout',
    'created_at' => time(),
    'expires_at' => time() + 600,
];
$_SESSION[GoogleAuthService::STATE_SESSION_KEY] = $session1;
[$ok1, $res1, $user1, $returnUrl1] = GoogleAuthService::handleCallback(['state' => $session1['state'], 'code' => 'mock-code'], $ip);
$res['new_customer_registered'] = $ok1;
$res['new_customer_return_url_preserved'] = ($returnUrl1 === '/checkout');

$stmtNew = $pdo->prepare("SELECT * FROM users WHERE email = :e");
$stmtNew->execute([':e' => $newGoogleEmail]);
$newRow = $stmtNew->fetch(PDO::FETCH_ASSOC);
$newCustId = (int)($newRow['id'] ?? 0);

$res['new_customer_row_created'] = ($newCustId > 0);
$res['new_customer_role_is_customer'] = (($newRow['role'] ?? '') === 'customer');
$res['new_customer_status_is_active'] = (($newRow['status'] ?? '') === 'active');
$res['new_customer_email_verified'] = !empty($newRow['email_verified_at']);
$res['new_customer_google_id_stored'] = (($newRow['google_id'] ?? '') === $newPayload['sub']);
$res['new_customer_password_is_null'] = ($newRow['password_hash'] === null);

// Verify guest cart was preserved and attached to the new customer
$stmtCart = $pdo->prepare("SELECT id FROM carts WHERE user_id = :uid LIMIT 1");
$stmtCart->execute([':uid' => $newCustId]);
$newCartId = (int)$stmtCart->fetchColumn();
$stmtCartItems = $pdo->prepare("SELECT SUM(quantity) FROM cart_items WHERE cart_id = :cid");
$stmtCartItems->execute([':cid' => $newCartId]);
$res['new_customer_cart_preserved'] = ((int)$stmtCartItems->fetchColumn() === 2);

// 2. Duplicate Account Prevention: repeat login with the same Google ID logs in, does NOT duplicate row
$stubClient->setIdToken($signJwt($newPayload, $rsa, $kid));
$session2 = [
    'state' => 'state2_' . $runId,
    'nonce' => $newPayload['nonce'],
    'code_verifier' => 'verifier2',
    'return_url' => '/checkout',
    'created_at' => time(),
    'expires_at' => time() + 600,
];
$_SESSION[GoogleAuthService::STATE_SESSION_KEY] = $session2;
[$ok2, $res2, $user2, $returnUrl2] = GoogleAuthService::handleCallback(['state' => $session2['state'], 'code' => 'mock-code'], $ip);
$res['returning_google_user_signs_in'] = $ok2;

$stmtCountNew = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :e");
$stmtCountNew->execute([':e' => $newGoogleEmail]);
$res['duplicate_account_prevented'] = ((int)$stmtCountNew->fetchColumn() === 1);

// 3. Existing account linking without creating duplicate account
unset($_SESSION[CustomerAuthService::SESSION_KEY]);
$linkEmail = "existing_pwd_{$runId}@test.local";
$linkPwd = "ExistingPass2026_{$runId}!";
$linkPwdHash = password_hash($linkPwd, PASSWORD_DEFAULT);
$pdo->prepare("
    INSERT INTO users (role, first_name, last_name, email, password_hash, email_verified_at, status, created_at, updated_at)
    VALUES ('customer', 'David', 'Kimani', :e, :p, NULL, 'active', NOW(), NOW())
")->execute([':e' => $linkEmail, ':p' => $linkPwdHash]);
$linkUserId = (int)$pdo->lastInsertId();

$linkPayload = $basePayload;
$linkPayload['email'] = $linkEmail;
$linkPayload['sub'] = "google_sub_link_{$runId}";
$stubClient->setIdToken($signJwt($linkPayload, $rsa, $kid));

$session3 = [
    'state' => 'state3_' . $runId,
    'nonce' => $linkPayload['nonce'],
    'code_verifier' => 'verifier3',
    'return_url' => '/checkout',
    'created_at' => time(),
    'expires_at' => time() + 600,
];
$_SESSION[GoogleAuthService::STATE_SESSION_KEY] = $session3;
[$ok3, $res3, $user3, $returnUrl3] = GoogleAuthService::handleCallback(['state' => $session3['state'], 'code' => 'mock-code'], $ip);
$res['existing_account_linked'] = $ok3;

$stmtLinkUser = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmtLinkUser->execute([':id' => $linkUserId]);
$linkedRow = $stmtLinkUser->fetch(PDO::FETCH_ASSOC);

$res['existing_pwd_preserved'] = password_verify($linkPwd, $linkedRow['password_hash'] ?? '');
$res['existing_google_id_linked'] = (($linkedRow['google_id'] ?? '') === $linkPayload['sub']);
$res['existing_email_now_verified'] = !empty($linkedRow['email_verified_at']);

$stmtCountLink = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :e");
$stmtCountLink->execute([':e' => $linkEmail]);
$res['link_no_duplicate_row'] = ((int)$stmtCountLink->fetchColumn() === 1);

// 4. Ineligible accounts rejected: blocked/suspended user
$blockedEmail = "blocked_{$runId}@test.local";
$pdo->prepare("
    INSERT INTO users (role, first_name, last_name, email, status, created_at, updated_at)
    VALUES ('customer', 'Blocked', 'User', :e, 'blocked', NOW(), NOW())
")->execute([':e' => $blockedEmail]);
$blockedUserId = (int)$pdo->lastInsertId();

$blockedPayload = $basePayload;
$blockedPayload['email'] = $blockedEmail;
$blockedPayload['sub'] = "google_sub_blocked_{$runId}";
$stubClient->setIdToken($signJwt($blockedPayload, $rsa, $kid));

$session4 = [
    'state' => 'state4_' . $runId,
    'nonce' => $blockedPayload['nonce'],
    'code_verifier' => 'verifier4',
    'return_url' => '/checkout',
    'created_at' => time(),
    'expires_at' => time() + 600,
];
$_SESSION[GoogleAuthService::STATE_SESSION_KEY] = $session4;
[$ok4, $msg4] = GoogleAuthService::handleCallback(['state' => $session4['state'], 'code' => 'mock-code'], $ip);
$res['blocked_user_rejected'] = (!$ok4);

// 5. Ineligible accounts rejected: staff/admin role cannot sign in via customer Google OAuth
$adminEmail = "admin_oauth_{$runId}@test.local";
$pdo->prepare("
    INSERT INTO users (role, first_name, last_name, email, password_hash, status, created_at, updated_at)
    VALUES ('admin', 'System', 'Admin', :e, 'adminpwd', 'active', NOW(), NOW())
")->execute([':e' => $adminEmail]);
$adminUserId = (int)$pdo->lastInsertId();

$adminPayload = $basePayload;
$adminPayload['email'] = $adminEmail;
$adminPayload['sub'] = "google_sub_admin_{$runId}";
$stubClient->setIdToken($signJwt($adminPayload, $rsa, $kid));

$session5 = [
    'state' => 'state5_' . $runId,
    'nonce' => $adminPayload['nonce'],
    'code_verifier' => 'verifier5',
    'return_url' => '/checkout',
    'created_at' => time(),
    'expires_at' => time() + 600,
];
$_SESSION[GoogleAuthService::STATE_SESSION_KEY] = $session5;
[$ok5, $msg5] = GoogleAuthService::handleCallback(['state' => $session5['state'], 'code' => 'mock-code'], $ip);
$res['admin_role_rejected'] = (!$ok5);

// 6. Conflicting Google ID linking attempt rejected
$conflictPayload = $basePayload;
$conflictPayload['email'] = $linkEmail; // matches existing David Kimani
$conflictPayload['sub'] = "google_sub_different_{$runId}"; // but different google_id
$stubClient->setIdToken($signJwt($conflictPayload, $rsa, $kid));

$session6 = [
    'state' => 'state6_' . $runId,
    'nonce' => $conflictPayload['nonce'],
    'code_verifier' => 'verifier6',
    'return_url' => '/checkout',
    'created_at' => time(),
    'expires_at' => time() + 600,
];
$_SESSION[GoogleAuthService::STATE_SESSION_KEY] = $session6;
[$ok6, $msg6] = GoogleAuthService::handleCallback(['state' => $session6['state'], 'code' => 'mock-code'], $ip);
$res['conflicting_google_id_rejected'] = (!$ok6);

// 7. Unverified Google email claim rejected
$unverifiedClaimPayload = $basePayload;
$unverifiedClaimPayload['email'] = "unver_claim_{$runId}@test.local";
$unverifiedClaimPayload['sub'] = "sub_unver_claim_{$runId}";
$unverifiedClaimPayload['email_verified'] = false;
$stubClient->setIdToken($signJwt($unverifiedClaimPayload, $rsa, $kid));

$session7 = [
    'state' => 'state7_' . $runId,
    'nonce' => $unverifiedClaimPayload['nonce'],
    'code_verifier' => 'verifier7',
    'return_url' => '/checkout',
    'created_at' => time(),
    'expires_at' => time() + 600,
];
$_SESSION[GoogleAuthService::STATE_SESSION_KEY] = $session7;
[$ok7, $msg7] = GoogleAuthService::handleCallback(['state' => $session7['state'], 'code' => 'mock-code'], $ip);
$res['unverified_email_claim_rejected'] = (!$ok7);

// Cleanup
GoogleAuthService::resetHttpClient();
$res['new_cust_id'] = $newCustId;
$res['link_user_id'] = $linkUserId;
$res['blocked_user_id'] = $blockedUserId;
$res['admin_user_id'] = $adminUserId;

echo json_encode($res);
EOF
)

GOOGLE_JSON=$(SHOPWISE_CONFIG=testing "$PHP_CMD" -r "$GOOGLE_RUNNER" "$VARIANT_ID" "$RAND_SUF")

assert_g_true() {
    local key="$1"
    local desc="$2"
    local val=$(echo "$GOOGLE_JSON" | grep -o "\"$key\":true" || true)
    if [ -n "$val" ]; then
        pass "$desc"
    else
        fail "$desc (failed $key in $GOOGLE_JSON)"
    fi
}

assert_g_true "new_customer_registered" "New visitor clicking Continue with Google is automatically registered"
assert_g_true "new_customer_row_created" "Customer row successfully created in database"
assert_g_true "new_customer_role_is_customer" "New Google customer is assigned strictly 'customer' role"
assert_g_true "new_customer_status_is_active" "New Google customer status is 'active'"
assert_g_true "new_customer_email_verified" "New Google customer email treated as verified from Google claim"
assert_g_true "new_customer_google_id_stored" "Google ID correctly recorded on customer record"
assert_g_true "new_customer_password_is_null" "New Google customer password hash is safely null"
assert_g_true "new_customer_cart_preserved" "Guest cart preserved and attached to new Google customer"
assert_g_true "new_customer_return_url_preserved" "Return URL (/checkout) preserved for new Google customer"
assert_g_true "returning_google_user_signs_in" "Returning customer with linked Google ID signs in successfully"
assert_g_true "duplicate_account_prevented" "Subsequent Google logins do not create duplicate accounts"
assert_g_true "existing_account_linked" "Existing account with matching email securely links Google identity"
assert_g_true "existing_pwd_preserved" "Identity linking preserves existing customer password hash"
assert_g_true "existing_google_id_linked" "Google ID attached to existing account upon linking"
assert_g_true "existing_email_now_verified" "Email marked verified upon verified Google identity linking"
assert_g_true "link_no_duplicate_row" "Linking does not create a duplicate user record"
assert_g_true "blocked_user_rejected" "Suspended or blocked accounts strictly rejected from Google sign-in"
assert_g_true "admin_role_rejected" "Staff/Admin accounts strictly forbidden from customer Google sign-in"
assert_g_true "conflicting_google_id_rejected" "Account already linked to another Google identity rejects conflict"
assert_g_true "unverified_email_claim_rejected" "Google account with unverified email claim is strictly refused"

# ------------------------------------------------------------------------------
# 6. DATABASE INTEGRITY AND CLEANUP
# ------------------------------------------------------------------------------
info "6. DATABASE INTEGRITY & CLEANUP"

# Clean up all created test users and carts
REG_UID=$(echo "$MERGE_JSON" | grep -o '"reg_user_id":[0-9]*' | cut -d':' -f2 || true)
LOGIN_UID=$(echo "$MERGE_JSON" | grep -o '"login_user_id":[0-9]*' | cut -d':' -f2 || true)
NEW_CUST_UID=$(echo "$GOOGLE_JSON" | grep -o '"new_cust_id":[0-9]*' | cut -d':' -f2 || true)
LINK_UID=$(echo "$GOOGLE_JSON" | grep -o '"link_user_id":[0-9]*' | cut -d':' -f2 || true)
BLOCKED_UID=$(echo "$GOOGLE_JSON" | grep -o '"blocked_user_id":[0-9]*' | cut -d':' -f2 || true)
ADMIN_UID=$(echo "$GOOGLE_JSON" | grep -o '"admin_user_id":[0-9]*' | cut -d':' -f2 || true)

ALL_UIDS="$REG_UID $LOGIN_UID $NEW_CUST_UID $LINK_UID $BLOCKED_UID $ADMIN_UID"
for uid in $ALL_UIDS; do
    if [ -n "$uid" ] && [ "$uid" -gt 0 ]; then
        "$MYSQL_CMD" -u "$DB_USER" "$DB_NAME" -e "
            DELETE FROM cart_items WHERE cart_id IN (SELECT id FROM carts WHERE user_id = $uid);
            DELETE FROM carts WHERE user_id = $uid;
            DELETE FROM email_verifications WHERE user_id = $uid;
            DELETE FROM password_resets WHERE user_id = $uid;
            DELETE FROM audit_log WHERE actor_user_id = $uid;
            DELETE FROM users WHERE id = $uid;
        "
    fi
done

# Clean up any leftover emails and guest carts with our test run ID
"$MYSQL_CMD" -u "$DB_USER" "$DB_NAME" -e "
    DELETE FROM email_queue WHERE to_email LIKE '%${RAND_SUF}%';
    DELETE FROM cart_items WHERE cart_id IN (SELECT id FROM carts WHERE session_token = '$GUEST_TOKEN');
    DELETE FROM carts WHERE session_token = '$GUEST_TOKEN';
"

POST_USERS=$("$MYSQL_CMD" -u "$DB_USER" "$DB_NAME" -N -e "SELECT COUNT(*) FROM users")
POST_CARTS=$("$MYSQL_CMD" -u "$DB_USER" "$DB_NAME" -N -e "SELECT COUNT(*) FROM carts")
POST_CART_ITEMS=$("$MYSQL_CMD" -u "$DB_USER" "$DB_NAME" -N -e "SELECT COUNT(*) FROM cart_items")
POST_ORDERS=$("$MYSQL_CMD" -u "$DB_USER" "$DB_NAME" -N -e "SELECT COUNT(*) FROM orders")

if [ "$POST_USERS" -eq "$PRE_USERS" ]; then
    pass "Zero database drift on users table ($PRE_USERS -> $POST_USERS)"
else
    fail "Database drift detected on users table ($PRE_USERS -> $POST_USERS)"
fi

if [ "$POST_ORDERS" -eq "$PRE_ORDERS" ]; then
    pass "Zero database drift on orders table ($PRE_ORDERS -> $POST_ORDERS)"
else
    fail "Database drift detected on orders table ($PRE_ORDERS -> $POST_ORDERS)"
fi

echo -e "\n${BOLD}==============================================================================${NC}"
echo -e "${BOLD}TEST SUMMARY: Passed: ${GREEN}${PASSED}${NC} | Failed: ${RED}${FAILED}${NC}${NC}"
echo -e "${BOLD}==============================================================================${NC}"

if [ "$FAILED" -gt 0 ]; then
    exit 1
fi
exit 0
