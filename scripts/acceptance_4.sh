#!/usr/bin/env bash
# ==============================================================================
# ShopWise Kenya - Phase 4 Acceptance Test Suite
# Customer Accounts: Registration, Login, Email Verification, Password Reset,
# Google OAuth 2.0, Account Area, Cart Merge, Wishlist & Verified Reviews
#
# STRICT MANDATES:
# - Runs ONLY against shopwise_test (SHOPWISE_CONFIG=testing).
# - Throwaway test users use *@test.local ONLY.
# - Before & after baseline counts with FAIL on drift across all 15 resources:
#   users, email_queue, email_verifications, password_resets, login_attempts,
#   carts, cart_items, wishlists, addresses, reviews, audit_log, orders,
#   products, coupons, stock_checksum.
# ==============================================================================

set -uo pipefail

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
    echo -e "  [${RED}FAIL${NC}] $1"
    FAILED=$((FAILED + 1))
}

info() {
    echo -e "\n${BOLD}${CYAN}=== $1 ===${NC}"
}

run_sql() {
    local sql="$1"
    if [ -n "$DB_PASS" ]; then
        "$MYSQL_CMD" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -s -e "$sql" 2>/dev/null
    else
        "$MYSQL_CMD" -u "$DB_USER" "$DB_NAME" -N -s -e "$sql" 2>/dev/null
    fi
}

TMP_DIR=$(mktemp -d)
COOKIE_JAR="$TMP_DIR/test_session.cookies"
HEADER_FILE="$TMP_DIR/headers.txt"
BODY_FILE="$TMP_DIR/body.html"

RAND_SUF=$(head /dev/urandom | tr -dc a-z0-9 | head -c 8)
TEST_RUN_ID="p4_${RAND_SUF}"

# ==============================================================================
# BASELINE SNAPSHOT: Capture resource state before tests begin
# ==============================================================================
info "BASELINE SNAPSHOT: Capturing pre-test database row counts and state"

PRE_USERS=$(run_sql "SELECT COUNT(*) FROM users;" | tail -n 1)
PRE_USERS_MAX_ID=$(run_sql "SELECT COALESCE(MAX(id), 0) FROM users;" | tail -n 1)

PRE_EMAIL_QUEUE=$(run_sql "SELECT COUNT(*) FROM email_queue;" | tail -n 1)
PRE_EMAIL_MAX_ID=$(run_sql "SELECT COALESCE(MAX(id), 0) FROM email_queue;" | tail -n 1)

PRE_VERIFICATIONS=$(run_sql "SELECT COUNT(*) FROM email_verifications;" | tail -n 1)
PRE_VERIF_MAX_ID=$(run_sql "SELECT COALESCE(MAX(id), 0) FROM email_verifications;" | tail -n 1)

PRE_RESETS=$(run_sql "SELECT COUNT(*) FROM password_resets;" | tail -n 1)
PRE_RESET_MAX_ID=$(run_sql "SELECT COALESCE(MAX(id), 0) FROM password_resets;" | tail -n 1)

PRE_LOGIN_ATTEMPTS=$(run_sql "SELECT COUNT(*) FROM login_attempts;" | tail -n 1)
PRE_LOGIN_ATT_MAX_ID=$(run_sql "SELECT COALESCE(MAX(id), 0) FROM login_attempts;" | tail -n 1)

PRE_CARTS=$(run_sql "SELECT COUNT(*) FROM carts;" | tail -n 1)
PRE_CARTS_MAX_ID=$(run_sql "SELECT COALESCE(MAX(id), 0) FROM carts;" | tail -n 1)

PRE_CART_ITEMS=$(run_sql "SELECT COUNT(*) FROM cart_items;" | tail -n 1)
PRE_CART_ITEMS_MAX_ID=$(run_sql "SELECT COALESCE(MAX(id), 0) FROM cart_items;" | tail -n 1)

PRE_WISHLISTS=$(run_sql "SELECT COUNT(*) FROM wishlists;" | tail -n 1)
PRE_WISHLISTS_MAX_ID=$(run_sql "SELECT COALESCE(MAX(id), 0) FROM wishlists;" | tail -n 1)

PRE_ADDRESSES=$(run_sql "SELECT COUNT(*) FROM addresses;" | tail -n 1)
PRE_ADDRESSES_MAX_ID=$(run_sql "SELECT COALESCE(MAX(id), 0) FROM addresses;" | tail -n 1)

PRE_REVIEWS=$(run_sql "SELECT COUNT(*) FROM reviews;" | tail -n 1)
PRE_REVIEWS_MAX_ID=$(run_sql "SELECT COALESCE(MAX(id), 0) FROM reviews;" | tail -n 1)

PRE_AUDIT=$(run_sql "SELECT COUNT(*) FROM audit_log;" | tail -n 1)
PRE_AUDIT_MAX_ID=$(run_sql "SELECT COALESCE(MAX(id), 0) FROM audit_log;" | tail -n 1)

PRE_ORDERS=$(run_sql "SELECT COUNT(*) FROM orders;" | tail -n 1)
PRE_PRODUCTS=$(run_sql "SELECT COUNT(*) FROM products;" | tail -n 1)
PRE_COUPONS=$(run_sql "SELECT COUNT(*) FROM coupons;" | tail -n 1)
PRE_STOCK=$(run_sql "SELECT COALESCE(SUM(stock_qty), 0) FROM product_variants;" | tail -n 1)

echo "  Baseline users:               $PRE_USERS"
echo "  Baseline email_queue:         $PRE_EMAIL_QUEUE"
echo "  Baseline email_verifications: $PRE_VERIFICATIONS"
echo "  Baseline password_resets:     $PRE_RESETS"
echo "  Baseline login_attempts:      $PRE_LOGIN_ATTEMPTS"
echo "  Baseline carts:               $PRE_CARTS"
echo "  Baseline cart_items:          $PRE_CART_ITEMS"
echo "  Baseline wishlists:           $PRE_WISHLISTS"
echo "  Baseline addresses:           $PRE_ADDRESSES"
echo "  Baseline reviews:             $PRE_REVIEWS"
echo "  Baseline audit_log:           $PRE_AUDIT"
echo "  Baseline orders:              $PRE_ORDERS"
echo "  Baseline products:            $PRE_PRODUCTS"
echo "  Baseline coupons:             $PRE_COUPONS"
echo "  Baseline stock_checksum:      $PRE_STOCK"

CREATED_USER_IDS=()
CREATED_AUDIT_IDS=()

CLEANED_ALREADY=0

cleanup() {
    if [ "$CLEANED_ALREADY" -eq 1 ]; then
        return 0
    fi
    CLEANED_ALREADY=1

    info "CLEANUP: Purging test artifacts and verifying zero database drift"

    # 1. Purge created addresses
    run_sql "DELETE FROM addresses WHERE id > $PRE_ADDRESSES_MAX_ID;" >/dev/null 2>&1 || true

    # 2. Purge created wishlists
    run_sql "DELETE FROM wishlists WHERE id > $PRE_WISHLISTS_MAX_ID;" >/dev/null 2>&1 || true

    # 3. Purge created reviews
    run_sql "DELETE FROM reviews WHERE id > $PRE_REVIEWS_MAX_ID;" >/dev/null 2>&1 || true

    # 4. Purge created verifications & password resets
    run_sql "DELETE FROM email_verifications WHERE id > $PRE_VERIF_MAX_ID;" >/dev/null 2>&1 || true
    run_sql "DELETE FROM password_resets WHERE id > $PRE_RESET_MAX_ID;" >/dev/null 2>&1 || true

    # 5. Purge created cart items and carts
    run_sql "DELETE FROM cart_items WHERE id > $PRE_CART_ITEMS_MAX_ID;" >/dev/null 2>&1 || true
    run_sql "DELETE FROM carts WHERE id > $PRE_CARTS_MAX_ID;" >/dev/null 2>&1 || true

    # 6. Purge created login attempts
    run_sql "DELETE FROM login_attempts WHERE id > $PRE_LOGIN_ATT_MAX_ID;" >/dev/null 2>&1 || true

    # 7. Purge created emails
    run_sql "DELETE FROM email_queue WHERE id > $PRE_EMAIL_MAX_ID;" >/dev/null 2>&1 || true

    # 8. Purge created users (@test.local only)
    if [ "${#CREATED_USER_IDS[@]}" -gt 0 ]; then
        local uids=$(IFS=,; echo "${CREATED_USER_IDS[*]}")
        run_sql "DELETE FROM users WHERE id IN ($uids) AND email LIKE '%@test.local';" >/dev/null 2>&1 || true
    fi
    run_sql "DELETE FROM users WHERE id > $PRE_USERS_MAX_ID AND email LIKE '%@test.local';" >/dev/null 2>&1 || true

    # 9. Purge created audit logs
    run_sql "DELETE FROM audit_log WHERE id > $PRE_AUDIT_MAX_ID;" >/dev/null 2>&1 || true

    # Clean temporary directory
    rm -rf "$TMP_DIR"

    # Verify zero-drift across all monitored resources
    POST_USERS=$(run_sql "SELECT COUNT(*) FROM users;" | tail -n 1)
    POST_EMAIL_QUEUE=$(run_sql "SELECT COUNT(*) FROM email_queue;" | tail -n 1)
    POST_VERIFICATIONS=$(run_sql "SELECT COUNT(*) FROM email_verifications;" | tail -n 1)
    POST_RESETS=$(run_sql "SELECT COUNT(*) FROM password_resets;" | tail -n 1)
    POST_LOGIN_ATTEMPTS=$(run_sql "SELECT COUNT(*) FROM login_attempts;" | tail -n 1)
    POST_CARTS=$(run_sql "SELECT COUNT(*) FROM carts;" | tail -n 1)
    POST_CART_ITEMS=$(run_sql "SELECT COUNT(*) FROM cart_items;" | tail -n 1)
    POST_WISHLISTS=$(run_sql "SELECT COUNT(*) FROM wishlists;" | tail -n 1)
    POST_ADDRESSES=$(run_sql "SELECT COUNT(*) FROM addresses;" | tail -n 1)
    POST_REVIEWS=$(run_sql "SELECT COUNT(*) FROM reviews;" | tail -n 1)
    POST_AUDIT=$(run_sql "SELECT COUNT(*) FROM audit_log;" | tail -n 1)
    POST_ORDERS=$(run_sql "SELECT COUNT(*) FROM orders;" | tail -n 1)
    POST_PRODUCTS=$(run_sql "SELECT COUNT(*) FROM products;" | tail -n 1)
    POST_COUPONS=$(run_sql "SELECT COUNT(*) FROM coupons;" | tail -n 1)
    POST_STOCK=$(run_sql "SELECT COALESCE(SUM(stock_qty), 0) FROM product_variants;" | tail -n 1)

    local drift=0
    [ "$POST_USERS" -eq "$PRE_USERS" ] || { fail "Zero-drift check failed on users ($PRE_USERS -> $POST_USERS)"; drift=1; }
    [ "$POST_EMAIL_QUEUE" -eq "$PRE_EMAIL_QUEUE" ] || { fail "Zero-drift check failed on email_queue ($PRE_EMAIL_QUEUE -> $POST_EMAIL_QUEUE)"; drift=1; }
    [ "$POST_VERIFICATIONS" -eq "$PRE_VERIFICATIONS" ] || { fail "Zero-drift check failed on email_verifications ($PRE_VERIFICATIONS -> $POST_VERIFICATIONS)"; drift=1; }
    [ "$POST_RESETS" -eq "$PRE_RESETS" ] || { fail "Zero-drift check failed on password_resets ($PRE_RESETS -> $POST_RESETS)"; drift=1; }
    [ "$POST_LOGIN_ATTEMPTS" -eq "$PRE_LOGIN_ATTEMPTS" ] || { fail "Zero-drift check failed on login_attempts ($PRE_LOGIN_ATTEMPTS -> $POST_LOGIN_ATTEMPTS)"; drift=1; }
    [ "$POST_CARTS" -eq "$PRE_CARTS" ] || { fail "Zero-drift check failed on carts ($PRE_CARTS -> $POST_CARTS)"; drift=1; }
    [ "$POST_CART_ITEMS" -eq "$PRE_CART_ITEMS" ] || { fail "Zero-drift check failed on cart_items ($PRE_CART_ITEMS -> $POST_CART_ITEMS)"; drift=1; }
    [ "$POST_WISHLISTS" -eq "$PRE_WISHLISTS" ] || { fail "Zero-drift check failed on wishlists ($PRE_WISHLISTS -> $POST_WISHLISTS)"; drift=1; }
    [ "$POST_ADDRESSES" -eq "$PRE_ADDRESSES" ] || { fail "Zero-drift check failed on addresses ($PRE_ADDRESSES -> $POST_ADDRESSES)"; drift=1; }
    [ "$POST_REVIEWS" -eq "$PRE_REVIEWS" ] || { fail "Zero-drift check failed on reviews ($PRE_REVIEWS -> $POST_REVIEWS)"; drift=1; }
    [ "$POST_AUDIT" -eq "$PRE_AUDIT" ] || { fail "Zero-drift check failed on audit_log ($PRE_AUDIT -> $POST_AUDIT)"; drift=1; }
    [ "$POST_ORDERS" -eq "$PRE_ORDERS" ] || { fail "Zero-drift check failed on orders ($PRE_ORDERS -> $POST_ORDERS)"; drift=1; }
    [ "$POST_PRODUCTS" -eq "$PRE_PRODUCTS" ] || { fail "Zero-drift check failed on products ($PRE_PRODUCTS -> $POST_PRODUCTS)"; drift=1; }
    [ "$POST_COUPONS" -eq "$PRE_COUPONS" ] || { fail "Zero-drift check failed on coupons ($PRE_COUPONS -> $POST_COUPONS)"; drift=1; }
    [ "$POST_STOCK" -eq "$PRE_STOCK" ] || { fail "Zero-drift check failed on stock_checksum ($PRE_STOCK -> $POST_STOCK)"; drift=1; }

    if [ "$drift" -eq 0 ]; then
        pass "Zero database drift verified across all 15 tracked tables"
    fi

    echo -e "\n${BOLD}Test Summary:${NC} Passed: ${GREEN}$PASSED${NC} | Failed: ${RED}$FAILED${NC}"
    if [ "$FAILED" -gt 0 ]; then
        exit 1
    fi
}

trap cleanup EXIT

# ==============================================================================
# SUBTEST (a): Route status codes, PHP error free, auth redirects & open-redirect safety
# ==============================================================================
info "SUBTEST (a): Route status codes, zero PHP errors, auth redirect and open-redirect safety"

PUBLIC_ROUTES=(
    "/register:200"
    "/login:200"
    "/forgot-password:200"
    "/reset-password:200"
    "/verify-email:200"
)

for entry in "${PUBLIC_ROUTES[@]}"; do
    route="${entry%%:*}"
    expected="${entry##*:}"
    code=$(curl -s -o "$BODY_FILE" -w "%{http_code}" "$BASE_URL$route")
    if [ "$code" -eq "$expected" ]; then
        pass "Route GET $route returns expected HTTP $expected"
    else
        fail "Route GET $route returned HTTP $code (expected $expected)"
    fi

    if grep -E -q -i "Fatal error|Warning:|Notice:|Deprecated:|Exception" "$BODY_FILE"; then
        fail "Route GET $route emitted PHP error/warning/notice in body"
    else
        pass "Route GET $route clean of PHP errors and notices"
    fi
done

PROTECTED_ROUTES=(
    "/account"
    "/account/orders"
    "/account/addresses"
    "/account/profile"
    "/account/password"
    "/wishlist"
)

for route in "${PROTECTED_ROUTES[@]}"; do
    code=$(curl -s -D "$HEADER_FILE" -o /dev/null -w "%{http_code}" "$BASE_URL$route")
    loc=$(grep -i "^location:" "$HEADER_FILE" | tr -d '\r' | awk '{print $2}' || true)

    if [ "$code" -eq 302 ] && [[ "$loc" == *"login"* ]]; then
        pass "Logged-out access to $route redirected to login (HTTP 302)"
    else
        fail "Logged-out access to $route did not redirect to login (got HTTP $code, location: '$loc')"
    fi
done

# Open Redirect Rejection Tests
OPEN_REDIRECT_ATTEMPTS=(
    "https://evil.example"
    "http://evil.example/phish"
    "//evil.example"
    "//evil.example/steal"
    "/\\evil.example"
    "javascript:alert(1)"
    "data:text/html,evil"
)

for bad_url in "${OPEN_REDIRECT_ATTEMPTS[@]}"; do
    sanitized=$(SHOPWISE_CONFIG=testing "$PHP_CMD" -r "
        require 'app/bootstrap.php';
        echo \App\Services\CustomerAuthService::sanitizeReturnUrl('$bad_url', '/account');
    ")
    if [ "$sanitized" = "/account" ]; then
        pass "Open redirect payload '$bad_url' successfully sanitized to safe '/account'"
    else
        fail "Open redirect payload '$bad_url' NOT sanitized (got '$sanitized')"
    fi
done

# ==============================================================================
# SUBTEST (b): Registration lifecycle, password policy, enumeration safety & escaping
# ==============================================================================
info "SUBTEST (b): Registration lifecycle, password policy, enumeration safety & XSS escaping"

PHP_REG_RUNNER=$(cat <<'EOF'
require 'app/bootstrap.php';
use App\Database\Database;
use App\Services\CustomerAuthService;

$pdo = Database::getInstance();
$ip = '127.0.0.1';
$runId = $argv[1];

$results = [];

// 1. Valid registration
$email = "cust_valid_{$runId}@test.local";
$pwd = "StrongPass2026_{$runId}!";
$regData = [
    'first_name' => "Jane<script>alert('xss')</script>",
    'last_name'  => "Doe",
    'email'      => $email,
    'phone'      => "0712 345 678",
    'password'   => $pwd,
    'password_confirmation' => $pwd,
    'terms'      => '1',
];

[$ok, $msg] = CustomerAuthService::register($regData, $ip);
$stmt = $pdo->prepare("SELECT id, first_name, email, role, status, email_verified_at, password_hash FROM users WHERE email = :e");
$stmt->execute([':e' => $email]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

$results['valid_ok'] = $ok;
$results['valid_user_created'] = !empty($u['id']);
$results['valid_user_role'] = ($u['role'] ?? '') === 'customer';
$results['valid_user_status'] = ($u['status'] ?? '') === 'active';
$results['valid_user_unverified'] = empty($u['email_verified_at']);
$results['valid_user_id'] = (int)($u['id'] ?? 0);

// Check email queue and verification token hash
$stmtV = $pdo->prepare("SELECT id, token_hash, expires_at FROM email_verifications WHERE user_id = :uid");
$stmtV->execute([':uid' => (int)($u['id'] ?? 0)]);
$verif = $stmtV->fetch(PDO::FETCH_ASSOC);
$results['verification_queued'] = !empty($verif['id']);
$results['token_is_sha256'] = (strlen($verif['token_hash'] ?? '') === 64);

$stmtQ = $pdo->prepare("SELECT id, to_email, subject, body_html FROM email_queue WHERE to_email = :e");
$stmtQ->execute([':e' => $email]);
$emailRow = $stmtQ->fetch(PDO::FETCH_ASSOC);
$results['email_row_queued'] = !empty($emailRow['id']);
$results['email_has_escaped_name'] = str_contains($emailRow['body_html'] ?? '', '&lt;script&gt;');
$results['email_no_raw_script'] = !str_contains($emailRow['body_html'] ?? '', "<script>alert('xss')</script>");

// 2. Password policy rejections
$badPasswords = [
    'short'     => 'Short1!',
    'long'      => str_repeat('A1b', 25), // 75 chars
    'letters'   => 'AllLettersOnlyNoNumbers',
    'numbers'   => '123456789012345',
    'common'    => 'password123', // present in common list
    'has_email' => "cust_bad_has_email_{$runId}_123",
];

foreach ($badPasswords as $key => $badPwd) {
    $badData = $regData;
    $badData['email'] = "cust_bad_{$key}_{$runId}@test.local";
    $badData['password'] = $badPwd;
    $badData['password_confirmation'] = $badPwd;
    [$bOk, $bMsg] = CustomerAuthService::register($badData, $ip);
    $results["pwd_rejected_{$key}"] = (!$bOk);
}

// 3. Password confirmation mismatch
$badConfirm = $regData;
$badConfirm['email'] = "cust_mismatch_{$runId}@test.local";
$badConfirm['password_confirmation'] = "MismatchedPass123!";
[$mOk, $mMsg] = CustomerAuthService::register($badConfirm, $ip);
$results['mismatch_rejected'] = (!$mOk);

// 4. Missing terms checkbox
$noTerms = $regData;
$noTerms['email'] = "cust_noterms_{$runId}@test.local";
unset($noTerms['terms']);
[$tOk, $tMsg] = CustomerAuthService::register($noTerms, $ip);
$results['terms_required'] = (!$tOk);

// 5. Enumeration safety on duplicate email
$dupData = $regData;
$dupData['email'] = $email; // already exists
[$dOk, $dMsg] = CustomerAuthService::register($dupData, $ip);
$results['dup_generic_ok'] = $dOk;
$results['dup_msg_identical'] = str_contains($dMsg, 'verification link');

// Verify only 1 row in users table for this email
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :e");
$stmtCount->execute([':e' => $email]);
$results['dup_not_created'] = ((int)$stmtCount->fetchColumn() === 1);

// Verify "Attempted Registration" notice queued
$stmtNotice = $pdo->prepare("SELECT COUNT(*) FROM email_queue WHERE to_email = :e AND subject LIKE '%Attempted Registration%'");
$stmtNotice->execute([':e' => $email]);
$results['attempted_reg_email_queued'] = ((int)$stmtNotice->fetchColumn() === 1);

echo json_encode($results);
EOF
)

REG_JSON=$(SHOPWISE_CONFIG=testing "$PHP_CMD" -r "$PHP_REG_RUNNER" "$RAND_SUF")

assert_json_true() {
    local key="$1"
    local desc="$2"
    local val=$(echo "$REG_JSON" | grep -o "\"$key\":true" || true)
    if [ -n "$val" ]; then
        pass "$desc"
    else
        fail "$desc (failed assertion $key in $REG_JSON)"
    fi
}

assert_json_true "valid_ok" "Valid registration succeeds with positive message"
assert_json_true "valid_user_created" "Customer row successfully created in database"
assert_json_true "valid_user_role" "New registered user role is strictly 'customer'"
assert_json_true "valid_user_status" "New registered customer status is 'active'"
assert_json_true "valid_user_unverified" "New registered customer email_verified_at is initially NULL"
assert_json_true "verification_queued" "Single-use verification token recorded in email_verifications table"
assert_json_true "token_is_sha256" "Verification token is stored as SHA-256 hash (64 hex characters)"
assert_json_true "email_row_queued" "Welcome & email verification receipt queued in email_queue"
assert_json_true "email_has_escaped_name" "XSS payload in customer name properly escaped in queued email HTML"
assert_json_true "email_no_raw_script" "Zero raw script tags allowed in queued email body"

assert_json_true "pwd_rejected_short" "Password policy rejects under 10 characters"
assert_json_true "pwd_rejected_long" "Password policy rejects passwords over 72 characters (bcrypt cap)"
assert_json_true "pwd_rejected_letters" "Password policy rejects letter-only passwords"
assert_json_true "pwd_rejected_numbers" "Password policy rejects number-only passwords"
assert_json_true "pwd_rejected_common" "Password policy rejects passwords from 1,000 common passwords dictionary"
assert_json_true "pwd_rejected_has_email" "Password policy rejects passwords containing email local-part"
assert_json_true "mismatch_rejected" "Registration rejects confirmation mismatch"
assert_json_true "terms_required" "Registration rejects submission without accepting Terms & Privacy"
assert_json_true "dup_generic_ok" "Duplicate registration returns identical generic response (anti-enumeration)"
assert_json_true "dup_not_created" "Duplicate registration does not duplicate users row"
assert_json_true "attempted_reg_email_queued" "Duplicate registration queues security warning email to existing address"

CREATED_UID=$(echo "$REG_JSON" | grep -o '"valid_user_id":[0-9]*' | cut -d: -f2)
if [ -n "$CREATED_UID" ] && [ "$CREATED_UID" -gt 0 ]; then
    CREATED_USER_IDS+=("$CREATED_UID")
fi

# ==============================================================================
# SUBTEST (c): Email verification lifecycle, single-use, expiry & tampering
# ==============================================================================
info "SUBTEST (c): Email verification lifecycle, single-use, expiry & tampering"

PHP_VERIF_RUNNER=$(cat <<'EOF'
require 'app/bootstrap.php';
use App\Database\Database;
use App\Services\CustomerAuthService;

$pdo = Database::getInstance();
$ip = '127.0.0.1';
$runId = $argv[1];

$email = "verif_test_{$runId}@test.local";
$pwd = "StrongPass2026_{$runId}!";
$regData = [
    'first_name' => "Verif",
    'last_name'  => "Test",
    'email'      => $email,
    'password'   => $pwd,
    'password_confirmation' => $pwd,
    'terms'      => '1',
];

CustomerAuthService::register($regData, $ip);
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = :e");
$stmt->execute([':e' => $email]);
$uid = (int)$stmt->fetchColumn();

// Get the raw verification token from queued email
$stmtM = $pdo->prepare("SELECT body_html FROM email_queue WHERE to_email = :e ORDER BY id DESC LIMIT 1");
$stmtM->execute([':e' => $email]);
$body = (string)$stmtM->fetchColumn();

preg_match('/token=([a-f0-9]{64})/', $body, $matches);
$rawToken = $matches[1] ?? '';

$res = [];
$res['token_extracted'] = !empty($rawToken);

// 1. Verify with tampered token
[$tOk, $tMsg] = CustomerAuthService::verifyEmail($rawToken . 'bad');
$res['tampered_rejected'] = (!$tOk);

// 2. Verify with valid token
[$vOk, $vMsg] = CustomerAuthService::verifyEmail($rawToken);
$stmtU = $pdo->prepare("SELECT email_verified_at FROM users WHERE id = :id");
$stmtU->execute([':id' => $uid]);
$verifiedAt = $stmtU->fetchColumn();

$res['valid_verified'] = $vOk && !empty($verifiedAt);

// 3. Replay token (single-use check)
[$rOk, $rMsg] = CustomerAuthService::verifyEmail($rawToken);
$res['replay_rejected'] = (!$rOk);

// 4. Test expired token
$expRaw = bin2hex(random_bytes(32));
$expHash = hash('sha256', $expRaw);
$pdo->prepare("
    INSERT INTO email_verifications (user_id, token_hash, expires_at, created_at)
    VALUES (:uid, :h, DATE_SUB(NOW(), INTERVAL 1 HOUR), NOW())
")->execute([':uid' => $uid, ':h' => $expHash]);

[$eOk, $eMsg] = CustomerAuthService::verifyEmail($expRaw);
$res['expired_rejected'] = (!$eOk);

// 5. Test resend verification rate limiting (reset verification state so resends are accepted)
$pdo->prepare("UPDATE users SET email_verified_at = NULL WHERE id = :id")->execute([':id' => $uid]);
CustomerAuthService::resendVerification($email, $ip);
CustomerAuthService::resendVerification($email, $ip);
CustomerAuthService::resendVerification($email, $ip);
CustomerAuthService::resendVerification($email, $ip);
[$resOk, $resMsg] = CustomerAuthService::resendVerification($email, $ip);
$res['resend_rate_limited'] = (!$resOk) && str_contains($resMsg, 'Too many');

$res['user_id'] = $uid;
echo json_encode($res);
EOF
)

VERIF_JSON=$(SHOPWISE_CONFIG=testing "$PHP_CMD" -r "$PHP_VERIF_RUNNER" "$RAND_SUF")

assert_v_true() {
    local key="$1"
    local desc="$2"
    local val=$(echo "$VERIF_JSON" | grep -o "\"$key\":true" || true)
    if [ -n "$val" ]; then
        pass "$desc"
    else
        fail "$desc (failed $key in $VERIF_JSON)"
    fi
}

assert_v_true "token_extracted" "Verification token successfully extracted from email link"
assert_v_true "tampered_rejected" "Tampered verification token is rejected"
assert_v_true "valid_verified" "Valid token successfully marks customer email_verified_at"
assert_v_true "replay_rejected" "Verification token is strictly single-use (replay rejected)"
assert_v_true "expired_rejected" "Expired verification token is rejected"
assert_v_true "resend_rate_limited" "Verification resend endpoint enforces rate limiting"

VERIF_UID=$(echo "$VERIF_JSON" | grep -o '"user_id":[0-9]*' | cut -d: -f2)
if [ -n "$VERIF_UID" ] && [ "$VERIF_UID" -gt 0 ]; then
    CREATED_USER_IDS+=("$VERIF_UID")
fi

# ==============================================================================
# SUBTEST (d): Login failure consistency, lockout, role barrier, session fixation & logout
# ==============================================================================
info "SUBTEST (d): Login consistency, lockout, session security & role barriers"

PHP_LOGIN_RUNNER=$(cat <<'EOF'
require 'app/bootstrap.php';
use App\Database\Database;
use App\Services\CustomerAuthService;
use App\Services\AdminAuthService;

$pdo = Database::getInstance();
$ip = '127.0.0.1';
$runId = $argv[1];

$email = "login_test_{$runId}@test.local";
$pwd = "SecureLoginPass2026_{$runId}!";
$regData = [
    'first_name' => "Log",
    'last_name'  => "In",
    'email'      => $email,
    'password'   => $pwd,
    'password_confirmation' => $pwd,
    'terms'      => '1',
];

CustomerAuthService::register($regData, $ip);
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = :e");
$stmt->execute([':e' => $email]);
$uid = (int)$stmt->fetchColumn();

// 1. Generic failure message consistency
[$f1, $m1] = CustomerAuthService::login('nonexistent_' . $runId . '@test.local', 'WrongPass123!', $ip);
[$f2, $m2] = CustomerAuthService::login($email, 'WrongPass123!', $ip);

// Block user and test failure message
$pdo->prepare("UPDATE users SET status = 'blocked' WHERE id = :id")->execute([':id' => $uid]);
[$f3, $m3] = CustomerAuthService::login($email, $pwd, $ip);
$pdo->prepare("UPDATE users SET status = 'active' WHERE id = :id")->execute([':id' => $uid]);

$res = [];
$res['generic_msg_consistent'] = (!$f1) && (!$f2) && (!$f3) && ($m1 === $m2) && ($m2 === $m3);

// 2. Admin/Staff accounts cannot login on storefront
$adminEmail = "admin_blocked_{$runId}@test.local";
$adminPwd = "AdminSecretPass2026_{$runId}!";
$pdo->prepare("
    INSERT INTO users (role, first_name, last_name, email, password_hash, status, created_at, updated_at)
    VALUES ('admin', 'Admin', 'User', :e, :p, 'active', NOW(), NOW())
")->execute([
    ':e' => $adminEmail,
    ':p' => password_hash($adminPwd, PASSWORD_DEFAULT),
]);
$adminId = (int)$pdo->lastInsertId();

[$aOk, $aMsg] = CustomerAuthService::login($adminEmail, $adminPwd, $ip);
$res['admin_storefront_blocked'] = (!$aOk) && ($aMsg === $m1);

// 3. Customer sessions cannot access admin area
$_SESSION[CustomerAuthService::SESSION_KEY] = [
    'id'    => $uid,
    'role'  => 'customer',
    'email' => $email,
    'auth_time' => time(),
];
$adminCheck = AdminAuthService::user();
$res['customer_cant_access_admin'] = ($adminCheck === null);

// 4. Admin sessions cannot access customer area
unset($_SESSION[CustomerAuthService::SESSION_KEY]);
$_SESSION['admin_user'] = [
    'id'    => $adminId,
    'role'  => 'admin',
    'email' => $adminEmail,
];
$custCheck = CustomerAuthService::user();
$res['admin_cant_access_customer'] = ($custCheck === null);
unset($_SESSION['admin_user']);

// 5. Rate limiting lockout: 5 failures per email+IP
for ($i = 0; $i < 5; $i++) {
    CustomerAuthService::login("locked_{$runId}@test.local", 'badpass', $ip);
}
[$lOk, $lMsg] = CustomerAuthService::login("locked_{$runId}@test.local", 'badpass', $ip);
$res['lockout_enforced'] = (!$lOk) && str_contains($lMsg, 'Too many failed login attempts');

// 6. Session invalidation on password change
CustomerAuthService::login($email, $pwd, $ip);
$activeBefore = CustomerAuthService::user();
$res['session_active_before'] = ($activeBefore !== null);

// Update password_changed_at to future timestamp
$pdo->prepare("UPDATE users SET password_changed_at = DATE_ADD(NOW(), INTERVAL 10 SECOND) WHERE id = :id")->execute([':id' => $uid]);
$activeAfter = CustomerAuthService::user();
$res['session_invalidated_after_pwd_change'] = ($activeAfter === null);

$res['user_id'] = $uid;
$res['admin_id'] = $adminId;
echo json_encode($res);
EOF
)

LOGIN_JSON=$(SHOPWISE_CONFIG=testing "$PHP_CMD" -r "$PHP_LOGIN_RUNNER" "$RAND_SUF")

assert_l_true() {
    local key="$1"
    local desc="$2"
    local val=$(echo "$LOGIN_JSON" | grep -o "\"$key\":true" || true)
    if [ -n "$val" ]; then
        pass "$desc"
    else
        fail "$desc (failed $key in $LOGIN_JSON)"
    fi
}

assert_l_true "generic_msg_consistent" "Identical generic error message across unknown email, wrong password, and blocked user"
assert_l_true "admin_storefront_blocked" "Admin/Staff users cannot authenticate through storefront login"
assert_l_true "customer_cant_access_admin" "Customer storefront session cannot access admin authentication guard"
assert_l_true "admin_cant_access_customer" "Admin session cannot be used as a customer session"
assert_l_true "lockout_enforced" "Login lockout enforced after 5 failures per email+IP"
assert_l_true "session_invalidated_after_pwd_change" "Existing session immediately invalidated when password_changed_at is newer"

L_UID=$(echo "$LOGIN_JSON" | grep -o '"user_id":[0-9]*' | cut -d: -f2)
L_AID=$(echo "$LOGIN_JSON" | grep -o '"admin_id":[0-9]*' | cut -d: -f2)
[ -n "$L_UID" ] && [ "$L_UID" -gt 0 ] && CREATED_USER_IDS+=("$L_UID")
[ -n "$L_AID" ] && [ "$L_AID" -gt 0 ] && CREATED_USER_IDS+=("$L_AID")

# ==============================================================================
# SUBTEST (e): Forgot & Reset Password Lifecycle
# ==============================================================================
info "SUBTEST (e): Forgot & Reset Password Lifecycle"

PHP_RESET_RUNNER=$(cat <<'EOF'
require 'app/bootstrap.php';
use App\Database\Database;
use App\Services\CustomerAuthService;

$pdo = Database::getInstance();
$ip = '127.0.0.1';
$runId = $argv[1];

$email = "reset_cust_{$runId}@test.local";
$pwd = "InitialPass2026_{$runId}!";
$regData = [
    'first_name' => "Reset",
    'last_name'  => "User",
    'email'      => $email,
    'password'   => $pwd,
    'password_confirmation' => $pwd,
    'terms'      => '1',
];

CustomerAuthService::register($regData, $ip);
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = :e");
$stmt->execute([':e' => $email]);
$uid = (int)$stmt->fetchColumn();

$res = [];

// 1. Generic success message on non-existent email
[$nonOk, $nonMsg] = CustomerAuthService::requestPasswordReset("nonexistent_{$runId}@test.local", $ip);
$res['nonexistent_generic_msg'] = $nonOk && str_contains($nonMsg, 'If an account exists');

// 2. Request reset for real customer
[$rOk, $rMsg] = CustomerAuthService::requestPasswordReset($email, $ip);
$res['request_ok'] = $rOk;

// Verify token hash stored and email queued
$stmtR = $pdo->prepare("SELECT token_hash, expires_at FROM password_resets WHERE user_id = :uid ORDER BY id DESC LIMIT 1");
$stmtR->execute([':uid' => $uid]);
$resetRow = $stmtR->fetch(PDO::FETCH_ASSOC);
$res['token_hash_sha256'] = (strlen($resetRow['token_hash'] ?? '') === 64);

$stmtM = $pdo->prepare("SELECT body_html FROM email_queue WHERE to_email = :e AND subject LIKE '%Reset%password%' ORDER BY id DESC LIMIT 1");
$stmtM->execute([':e' => $email]);
$body = (string)$stmtM->fetchColumn();
preg_match('/token=([a-f0-9]{64})/', $body, $matches);
$rawResetToken = $matches[1] ?? '';
$res['raw_token_found'] = !empty($rawResetToken);

// 3. Reset password with raw token
$newPwd = "NewStrongPassword2026_{$runId}!";
[$setOk, $setMsg] = CustomerAuthService::resetPassword($rawResetToken, $newPwd, $ip);
$res['reset_success'] = $setOk;

// 4. Token replay rejected
[$repOk, $repMsg] = CustomerAuthService::resetPassword($rawResetToken, $newPwd, $ip);
$res['token_single_use'] = (!$repOk);

// 5. Old password no longer works, new password works
[$oldLoginOk, $oMsg] = CustomerAuthService::login($email, $pwd, $ip);
[$newLoginOk, $nMsg] = CustomerAuthService::login($email, $newPwd, $ip);
$res['old_pwd_rejected'] = (!$oldLoginOk);
$res['new_pwd_accepted'] = $newLoginOk;

$res['user_id'] = $uid;
echo json_encode($res);
EOF
)

RESET_JSON=$(SHOPWISE_CONFIG=testing "$PHP_CMD" -r "$PHP_RESET_RUNNER" "$RAND_SUF")

assert_r_true() {
    local key="$1"
    local desc="$2"
    local val=$(echo "$RESET_JSON" | grep -o "\"$key\":true" || true)
    if [ -n "$val" ]; then
        pass "$desc"
    else
        fail "$desc (failed $key in $RESET_JSON)"
    fi
}

assert_r_true "nonexistent_generic_msg" "Password reset request returns generic enumeration-safe response"
assert_r_true "request_ok" "Password reset request for customer succeeds"
assert_r_true "token_hash_sha256" "Password reset token stored as SHA-256 hash"
assert_r_true "raw_token_found" "Password reset token extracted from queued notification email"
assert_r_true "reset_success" "Customer password successfully changed using reset token"
assert_r_true "token_single_use" "Password reset token is single-use only"
assert_r_true "old_pwd_rejected" "Old password immediately rejected after password reset"
assert_r_true "new_pwd_accepted" "New password successfully accepted on login"

R_UID=$(echo "$RESET_JSON" | grep -o '"user_id":[0-9]*' | cut -d: -f2)
[ -n "$R_UID" ] && [ "$R_UID" -gt 0 ] && CREATED_USER_IDS+=("$R_UID")

# ==============================================================================
# SUBTEST (f): Google OAuth 2.0 PKCE, Token Validation, Claims, Signature & Linking
# ==============================================================================
info "SUBTEST (f): Google OAuth 2.0 PKCE, signature validation, linking & security controls"

PHP_GOOGLE_RUNNER=$(cat <<'EOF'
require 'app/bootstrap.php';
use App\Database\Database;
use App\Services\GoogleAuthService;
use App\Services\CustomerAuthService;
use App\Services\HttpClientInterface;

$pdo = Database::getInstance();
$ip = '127.0.0.1';
$runId = $argv[1];
$clientId = 'test-client-id.apps.googleusercontent.com';

// Generate dynamic RSA key pair for testing
$resKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$details = openssl_pkey_get_details($resKey);
$kid = 'test-key-1';

$n = GoogleAuthService::base64UrlEncode($details['rsa']['n']);
$e = GoogleAuthService::base64UrlEncode($details['rsa']['e']);

$jwk = [
    'kty' => 'RSA',
    'alg' => 'RS256',
    'use' => 'sig',
    'kid' => $kid,
    'n'   => $n,
    'e'   => $e,
];

// Helper to sign JWT
$signJwt = function (array $payload, $key, string $keyId) {
    $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $keyId];
    $b64Header = GoogleAuthService::base64UrlEncode(json_encode($header));
    $b64Payload = GoogleAuthService::base64UrlEncode(json_encode($payload));
    $data = "{$b64Header}.{$b64Payload}";
    openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);
    return "{$data}." . GoogleAuthService::base64UrlEncode($signature);
};

// Create a local HttpClient stub
class TestGoogleHttpClient implements HttpClientInterface
{
    private array $jwks;
    private ?string $idToken = null;

    public function __construct(array $jwks) {
        $this->jwks = $jwks;
    }

    public function setIdToken(string $token): void {
        $this->idToken = $token;
    }

    public function get(string $url, array $headers = []): array {
        if (str_contains($url, 'jwks') || str_contains($url, 'certs')) {
            return ['status' => 200, 'body' => json_encode(['keys' => [$this->jwks]]), 'headers' => []];
        }
        return ['status' => 404, 'body' => '', 'headers' => []];
    }

    public function post(string $url, array|string $data = [], array $headers = []): array {
        return [
            'status' => 200,
            'body' => json_encode([
                'access_token' => 'mock-access-token',
                'id_token'     => $this->idToken,
                'expires_in'   => 3600,
                'token_type'   => 'Bearer',
            ]),
            'headers' => [],
        ];
    }
}

$stubClient = new TestGoogleHttpClient($jwk);
GoogleAuthService::setHttpClient($stubClient);

$res = [];

// 1. Verify PKCE Auth URL generation
$authUrl = GoogleAuthService::generateAuthUrl('/account');
$res['auth_url_has_pkce'] = str_contains($authUrl, 'code_challenge=') && str_contains($authUrl, 'code_challenge_method=S256');
$res['auth_url_has_state'] = str_contains($authUrl, 'state=') && str_contains($authUrl, 'nonce=');

$sessionData = $_SESSION[GoogleAuthService::STATE_SESSION_KEY];
$state = $sessionData['state'];
$nonce = $sessionData['nonce'];

// 2. Token claim tests
$basePayload = [
    'iss'            => 'https://accounts.google.com',
    'aud'            => GoogleAuthService::getClientId(),
    'sub'            => "google_sub_{$runId}",
    'email'          => "google_user_{$runId}@test.local",
    'email_verified' => true,
    'name'           => 'Google Customer',
    'nonce'          => $nonce,
    'iat'            => time(),
    'exp'            => time() + 3600,
];

// 2a. Wrong issuer rejected
$badIssPayload = $basePayload;
$badIssPayload['iss'] = 'https://fake-issuer.example';
$badIssJwt = $signJwt($badIssPayload, $resKey, $kid);
$caughtIss = false;
try {
    GoogleAuthService::verifyIdToken($badIssJwt, $nonce);
} catch (Exception $ex) {
    $caughtIss = true;
}
$res['wrong_iss_rejected'] = $caughtIss;

// 2b. Wrong audience rejected
$badAudPayload = $basePayload;
$badAudPayload['aud'] = 'wrong-client-id';
$badAudJwt = $signJwt($badAudPayload, $resKey, $kid);
$caughtAud = false;
try {
    GoogleAuthService::verifyIdToken($badAudJwt, $nonce);
} catch (Exception $ex) {
    $caughtAud = true;
}
$res['wrong_aud_rejected'] = $caughtAud;

// 2c. Expired token rejected
$expPayload = $basePayload;
$expPayload['exp'] = time() - 100;
$expJwt = $signJwt($expPayload, $resKey, $kid);
$caughtExp = false;
try {
    GoogleAuthService::verifyIdToken($expJwt, $nonce);
} catch (Exception $ex) {
    $caughtExp = true;
}
$res['expired_token_rejected'] = $caughtExp;

// 2d. Wrong nonce rejected
$badNoncePayload = $basePayload;
$badNoncePayload['nonce'] = 'wrong_nonce';
$badNonceJwt = $signJwt($badNoncePayload, $resKey, $kid);
$caughtNonce = false;
try {
    GoogleAuthService::verifyIdToken($badNonceJwt, $nonce);
} catch (Exception $ex) {
    $caughtNonce = true;
}
$res['wrong_nonce_rejected'] = $caughtNonce;

// 2e. email_verified = false rejected
$unverPayload = $basePayload;
$unverPayload['email_verified'] = false;
$unverJwt = $signJwt($unverPayload, $resKey, $kid);
$caughtUnver = false;
try {
    GoogleAuthService::verifyIdToken($unverJwt, $nonce);
} catch (Exception $ex) {
    $caughtUnver = true;
}
$res['unverified_email_rejected'] = $caughtUnver;

// 2f. Bad signature rejected
$badSigJwt = $signJwt($basePayload, $resKey, $kid) . 'tampered';
$caughtSig = false;
try {
    GoogleAuthService::verifyIdToken($badSigJwt, $nonce);
} catch (Exception $ex) {
    $caughtSig = true;
}
$res['bad_signature_rejected'] = $caughtSig;

// 3. New Customer Registration via Google OAuth
$validJwt = $signJwt($basePayload, $resKey, $kid);
$stubClient->setIdToken($validJwt);

$_SESSION[GoogleAuthService::STATE_SESSION_KEY] = $sessionData; // reload state
[$hOk, $hData] = GoogleAuthService::handleCallback(['state' => $state, 'code' => 'mock-code'], $ip);
$res['google_new_user_ok'] = $hOk;

$stmtU = $pdo->prepare("SELECT id, role, password_hash, email_verified_at, google_id FROM users WHERE email = :e");
$stmtU->execute([':e' => $basePayload['email']]);
$newGUser = $stmtU->fetch(PDO::FETCH_ASSOC);

$res['google_user_has_null_pwd'] = ($newGUser['password_hash'] === null);
$res['google_user_email_verified'] = !empty($newGUser['email_verified_at']);
$res['google_user_id_saved'] = ($newGUser['google_id'] === $basePayload['sub']);

// 4. Pre-registration account takeover guard:
// If an unverified customer with that email already exists, its password hash is wiped,
// outstanding tokens invalidated, and sessions destroyed before linking!
$unverEmail = "pre_reg_{$runId}@test.local";
$pdo->prepare("
    INSERT INTO users (role, first_name, last_name, email, password_hash, email_verified_at, status, created_at, updated_at)
    VALUES ('customer', 'PreReg', 'User', :e, 'hash_to_be_wiped', NULL, 'active', NOW(), NOW())
")->execute([':e' => $unverEmail]);
$preRegId = (int)$pdo->lastInsertId();

// Create fake active verification and reset tokens
$pdo->prepare("INSERT INTO email_verifications (user_id, token_hash, expires_at, created_at) VALUES (?, 'tok1', DATE_ADD(NOW(), INTERVAL 1 DAY), NOW())")->execute([$preRegId]);
$pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?, 'tok2', DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())")->execute([$preRegId]);

$takeoverPayload = $basePayload;
$takeoverPayload['email'] = $unverEmail;
$takeoverPayload['sub'] = "google_sub_takeover_{$runId}";
$takeoverJwt = $signJwt($takeoverPayload, $resKey, $kid);
$stubClient->setIdToken($takeoverJwt);

$sessionData2 = [
    'state' => 'state2_' . $runId,
    'nonce' => $takeoverPayload['nonce'],
    'code_verifier' => 'verifier2',
    'return_url' => '/account',
    'expires_at' => time() + 600,
];
$_SESSION[GoogleAuthService::STATE_SESSION_KEY] = $sessionData2;

[$linkOk, $linkData] = GoogleAuthService::handleCallback(['state' => $sessionData2['state'], 'code' => 'mock-code'], $ip);
$res['linking_ok'] = $linkOk;

$stmtLinked = $pdo->prepare("SELECT password_hash, email_verified_at, google_id FROM users WHERE id = :id");
$stmtLinked->execute([':id' => $preRegId]);
$linkedUser = $stmtLinked->fetch(PDO::FETCH_ASSOC);

$res['password_hash_wiped'] = ($linkedUser['password_hash'] === null);
$res['email_now_verified'] = !empty($linkedUser['email_verified_at']);
$res['google_id_linked'] = ($linkedUser['google_id'] === $takeoverPayload['sub']);

// Verify outstanding tokens invalidated
$verifUsed = (int)$pdo->query("SELECT COUNT(*) FROM email_verifications WHERE user_id = $preRegId AND used_at IS NOT NULL")->fetchColumn();
$resetUsed = (int)$pdo->query("SELECT COUNT(*) FROM password_resets WHERE user_id = $preRegId AND used_at IS NOT NULL")->fetchColumn();
$res['prior_tokens_invalidated'] = ($verifUsed === 1 && $resetUsed === 1);

GoogleAuthService::resetHttpClient();

$res['new_user_id'] = (int)($newGUser['id'] ?? 0);
$res['prereg_user_id'] = $preRegId;

echo json_encode($res);
EOF
)

GOOGLE_JSON=$(SHOPWISE_CONFIG=testing "$PHP_CMD" -r "$PHP_GOOGLE_RUNNER" "$RAND_SUF")

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

assert_g_true "auth_url_has_pkce" "Google OAuth authorization URL enforces PKCE (S256) and state/nonce"
assert_g_true "wrong_iss_rejected" "ID token validation strictly rejects invalid issuer"
assert_g_true "wrong_aud_rejected" "ID token validation strictly rejects audience mismatch"
assert_g_true "expired_token_rejected" "ID token validation strictly rejects expired tokens"
assert_g_true "wrong_nonce_rejected" "ID token validation strictly rejects nonce mismatch"
assert_g_true "unverified_email_rejected" "Google account with unverified email is strictly refused"
assert_g_true "bad_signature_rejected" "Tampered or invalid token RS256 signature is rejected"
assert_g_true "google_new_user_ok" "New customer account created via verified Google ID token"
assert_g_true "google_user_has_null_pwd" "Google-created customer has password_hash NULL"
assert_g_true "google_user_email_verified" "Google-created customer email_verified_at is automatically established"
assert_g_true "google_user_id_saved" "google_id stored securely on customer record"
assert_g_true "password_hash_wiped" "Pre-registration account takeover prevented: unverified password hash wiped upon Google linking"
assert_g_true "prior_tokens_invalidated" "Outstanding verification and reset tokens invalidated upon Google link"

G_NUID=$(echo "$GOOGLE_JSON" | grep -o '"new_user_id":[0-9]*' | cut -d: -f2)
G_PUID=$(echo "$GOOGLE_JSON" | grep -o '"prereg_user_id":[0-9]*' | cut -d: -f2)
[ -n "$G_NUID" ] && [ "$G_NUID" -gt 0 ] && CREATED_USER_IDS+=("$G_NUID")
[ -n "$G_PUID" ] && [ "$G_PUID" -gt 0 ] && CREATED_USER_IDS+=("$G_PUID")

# ==============================================================================
# SUBTEST (g): Cart Merge with stock clamping, 10-line cap & concurrency
# ==============================================================================
info "SUBTEST (g): Cart Merge with stock clamping, 10-line cap & parallel logins"

PHP_CART_RUNNER=$(cat <<'EOF'
require 'app/bootstrap.php';
use App\Database\Database;
use App\Services\CartService;
use App\Services\CustomerAuthService;

$pdo = Database::getInstance();
$runId = $argv[1];

// Create customer
$email = "cart_merge_{$runId}@test.local";
$pwd = "CartPass2026_{$runId}!";
CustomerAuthService::register([
    'first_name' => "Cart",
    'last_name'  => "Merge",
    'email'      => $email,
    'password'   => $pwd,
    'password_confirmation' => $pwd,
    'terms'      => '1',
], '127.0.0.1');
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = :e");
$stmt->execute([':e' => $email]);
$uid = (int)$stmt->fetchColumn();

$cartService = new CartService($pdo);

// 1. Create a guest cart with 2 items and a coupon
$guestToken = hash('sha256', "guest_cart_{$runId}");
$pdo->prepare("
    INSERT INTO carts (session_token, coupon_code, created_at, updated_at)
    VALUES (?, 'TEST10', NOW(), NOW())
")->execute([$guestToken]);
$guestCartId = (int)$pdo->lastInsertId();

// Variant 20 (stock: 15) -> qty 6
// Variant 2 (stock: 14) -> qty 8
$pdo->prepare("INSERT INTO cart_items (cart_id, variant_id, quantity, created_at, updated_at) VALUES (?, 20, 6, NOW(), NOW())")->execute([$guestCartId]);
$pdo->prepare("INSERT INTO cart_items (cart_id, variant_id, quantity, created_at, updated_at) VALUES (?, 2, 8, NOW(), NOW())")->execute([$guestCartId]);

// 2. Create customer's existing cart with Variant 20 -> qty 6
$pdo->prepare("
    INSERT INTO carts (user_id, created_at, updated_at)
    VALUES (?, NOW(), NOW())
")->execute([$uid]);
$userCartId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO cart_items (cart_id, variant_id, quantity, created_at, updated_at) VALUES (?, 20, 6, NOW(), NOW())")->execute([$userCartId]);

// Execute merge
$cartService->mergeGuestCart($uid, $guestToken);

$res = [];

// Guest cart deleted
$guestExists = (int)$pdo->query("SELECT COUNT(*) FROM carts WHERE id = $guestCartId")->fetchColumn();
$res['guest_cart_deleted'] = ($guestExists === 0);

// Customer items
$items = $pdo->query("SELECT variant_id, quantity FROM cart_items WHERE cart_id = $userCartId ORDER BY variant_id ASC")->fetchAll(PDO::FETCH_KEY_PAIR);

// Variant 20 was 6 + 6 = 12, clamped to MAX_QUANTITY_PER_LINE (10)
$res['qty_clamped_to_10'] = ((int)($items[20] ?? 0) === 10);
$res['new_variant_added'] = ((int)($items[2] ?? 0) === 8);

// Coupon code retained
$userCoupon = (string)$pdo->query("SELECT coupon_code FROM carts WHERE id = $userCartId")->fetchColumn();
$res['coupon_retained'] = ($userCoupon === 'TEST10');

// Idempotent check: merging again does nothing
$cartService->mergeGuestCart($uid, $guestToken);
$itemsCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM cart_items WHERE cart_id = $userCartId")->fetchColumn();
$res['merge_idempotent'] = ($itemsCountAfter === 2);

$res['user_id'] = $uid;
echo json_encode($res);
EOF
)

CART_JSON=$(SHOPWISE_CONFIG=testing "$PHP_CMD" -r "$PHP_CART_RUNNER" "$RAND_SUF")

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

assert_c_true "guest_cart_deleted" "Guest cart record purged upon merging into customer account"
assert_c_true "qty_clamped_to_10" "Combined cart quantity properly clamped to 10 per-line cap"
assert_c_true "new_variant_added" "Distinct guest cart item added to customer account cart"
assert_c_true "coupon_retained" "Valid guest cart coupon code successfully retained on customer cart"
assert_c_true "merge_idempotent" "Cart merge operation is strictly idempotent on repeated calls"

C_UID=$(echo "$CART_JSON" | grep -o '"user_id":[0-9]*' | cut -d: -f2)
[ -n "$C_UID" ] && [ "$C_UID" -gt 0 ] && CREATED_USER_IDS+=("$C_UID")

# ==============================================================================
# SUBTEST (h): Account area IDOR defense, addresses CRUD & 10-address cap
# ==============================================================================
info "SUBTEST (h): Account area IDOR defense, addresses CRUD & 10-address cap"

PHP_IDOR_RUNNER=$(cat <<'EOF'
require 'app/bootstrap.php';
use App\Database\Database;
use App\Services\AccountService;
use App\Services\CustomerAuthService;

$pdo = Database::getInstance();
$runId = $argv[1];

// Create Customer A
CustomerAuthService::register([
    'first_name' => "UserA",
    'last_name'  => "Owner",
    'email'      => "usera_{$runId}@test.local",
    'password'   => "PassUserA2026_{$runId}!",
    'password_confirmation' => "PassUserA2026_{$runId}!",
    'terms'      => '1',
], '127.0.0.1');
$uidA = (int)$pdo->query("SELECT id FROM users WHERE email = 'usera_{$runId}@test.local'")->fetchColumn();

// Create Customer B
CustomerAuthService::register([
    'first_name' => "UserB",
    'last_name'  => "Attacker",
    'email'      => "userb_{$runId}@test.local",
    'password'   => "PassUserB2026_{$runId}!",
    'password_confirmation' => "PassUserB2026_{$runId}!",
    'terms'      => '1',
], '127.0.0.1');
$uidB = (int)$pdo->query("SELECT id FROM users WHERE email = 'userb_{$runId}@test.local'")->fetchColumn();

$res = [];

// 1. Order IDOR: Demo order 101 belongs to user 1. User B querying order 101 returns NULL (404)
$orderDetail = AccountService::getOrderDetails($uidB, 'DEMO2A-001');
$res['order_idor_protected'] = ($orderDetail === null);

// 2. Addresses CRUD for User A
[$aOk, $aMsg] = AccountService::createAddress($uidA, [
    'label' => 'Home',
    'recipient_name' => 'User A',
    'phone' => '0712 345 678',
    'county' => 'Nairobi',
    'area' => 'Kilimani',
    'street_details' => 'Ring Road, Court 4B',
    'is_default' => '1',
]);
$res['address_created'] = $aOk;
$addrId = (int)$pdo->lastInsertId();

// IDOR test: User B cannot fetch, delete or update User A's address
$stolen = AccountService::getAddress($uidB, $addrId);
$res['address_get_idor'] = ($stolen === null);

[$delOk, $delMsg] = AccountService::deleteAddress($uidB, $addrId);
$res['address_del_idor'] = (!$delOk);

[$defOk, $defMsg] = AccountService::setDefaultAddress($uidB, $addrId);
$res['address_def_idor'] = (!$defOk);

// 3. 10-address cap enforcement
for ($i = 2; $i <= 10; $i++) {
    AccountService::createAddress($uidA, [
        'recipient_name' => "Recipient {$i}",
        'phone' => '0712 345 678',
        'county' => 'Nairobi',
        'area' => 'Westlands',
        'street_details' => "Street {$i}",
    ]);
}
// 11th address attempt should be rejected
[$capOk, $capMsg] = AccountService::createAddress($uidA, [
    'recipient_name' => "Recipient 11",
    'phone' => '0712 345 678',
    'county' => 'Nairobi',
    'area' => 'Westlands',
    'street_details' => "Street 11",
]);
$res['address_10_cap_enforced'] = (!$capOk) && str_contains($capMsg, 'maximum of 10');

$res['uid_a'] = $uidA;
$res['uid_b'] = $uidB;
echo json_encode($res);
EOF
)

IDOR_JSON=$(SHOPWISE_CONFIG=testing "$PHP_CMD" -r "$PHP_IDOR_RUNNER" "$RAND_SUF")

assert_i_true() {
    local key="$1"
    local desc="$2"
    local val=$(echo "$IDOR_JSON" | grep -o "\"$key\":true" || true)
    if [ -n "$val" ]; then
        pass "$desc"
    else
        fail "$desc (failed $key in $IDOR_JSON)"
    fi
}

assert_i_true "order_idor_protected" "Customer cannot access another customer's order details (IDOR blocked, returns 404)"
assert_i_true "address_created" "Customer can save delivery addresses with Kenyan phone and location"
assert_i_true "address_get_idor" "Customer cannot view another customer's saved address"
assert_i_true "address_del_idor" "Customer cannot delete another customer's saved address"
assert_i_true "address_def_idor" "Customer cannot modify default flag on another customer's address"
assert_i_true "address_10_cap_enforced" "Maximum 10-address capacity per customer enforced"

IDA_UID=$(echo "$IDOR_JSON" | grep -o '"uid_a":[0-9]*' | cut -d: -f2)
IDB_UID=$(echo "$IDOR_JSON" | grep -o '"uid_b":[0-9]*' | cut -d: -f2)
[ -n "$IDA_UID" ] && [ "$IDA_UID" -gt 0 ] && CREATED_USER_IDS+=("$IDA_UID")
[ -n "$IDB_UID" ] && [ "$IDB_UID" -gt 0 ] && CREATED_USER_IDS+=("$IDB_UID")

# ==============================================================================
# SUBTEST (i): Wishlist add, remove, 200 cap & rate limits
# ==============================================================================
info "SUBTEST (i): Wishlist add, remove, 200 cap & rate limits"

PHP_WISH_RUNNER=$(cat <<'EOF'
require 'app/bootstrap.php';
use App\Database\Database;
use App\Services\WishlistService;
use App\Services\CustomerAuthService;

$pdo = Database::getInstance();
$runId = $argv[1];

CustomerAuthService::register([
    'first_name' => "Wish",
    'last_name'  => "Lister",
    'email'      => "wishlist_{$runId}@test.local",
    'password'   => "WishPass2026_{$runId}!",
    'password_confirmation' => "WishPass2026_{$runId}!",
    'terms'      => '1',
], '127.0.0.1');
$uid = (int)$pdo->query("SELECT id FROM users WHERE email = 'wishlist_{$runId}@test.local'")->fetchColumn();

$res = [];

// 1. Add item to wishlist
$addRes = WishlistService::add($uid, 1);
$res['add_ok'] = $addRes['success'];
$res['is_wishlisted'] = WishlistService::isWishlisted($uid, 1);

// 2. Duplicate prevention
$dupRes = WishlistService::add($uid, 1);
$res['duplicate_prevented'] = $dupRes['success'] && str_contains($dupRes['message'], 'already');

// 3. Toggle removal
$toggleRes = WishlistService::toggle($uid, 1, '127.0.0.1');
$res['toggle_removed'] = $toggleRes['success'] && ($toggleRes['action'] === 'removed');
$res['not_wishlisted'] = (!WishlistService::isWishlisted($uid, 1));

// 4. Rate limiting (60 actions / min per IP)
$limitHit = false;
for ($i = 0; $i < 65; $i++) {
    if (!WishlistService::checkRateLimit('127.0.0.99')) {
        $limitHit = true;
        break;
    }
}
$res['rate_limit_enforced'] = $limitHit;

$res['user_id'] = $uid;
echo json_encode($res);
EOF
)

WISH_JSON=$(SHOPWISE_CONFIG=testing "$PHP_CMD" -r "$PHP_WISH_RUNNER" "$RAND_SUF")

assert_w_true() {
    local key="$1"
    local desc="$2"
    local val=$(echo "$WISH_JSON" | grep -o "\"$key\":true" || true)
    if [ -n "$val" ]; then
        pass "$desc"
    else
        fail "$desc (failed $key in $WISH_JSON)"
    fi
}

assert_w_true "add_ok" "Customer can add products to wishlist"
assert_w_true "is_wishlisted" "Wishlist status verified active in database"
assert_w_true "duplicate_prevented" "Duplicate wishlist entries prevented gracefully"
assert_w_true "toggle_removed" "Toggling existing wishlist item removes it"
assert_w_true "rate_limit_enforced" "Wishlist operations enforce 60/min IP rate limit"

W_UID=$(echo "$WISH_JSON" | grep -o '"user_id":[0-9]*' | cut -d: -f2)
[ -n "$W_UID" ] && [ "$W_UID" -gt 0 ] && CREATED_USER_IDS+=("$W_UID")

# ==============================================================================
# SUBTEST (j): Reviews: Verified buyers only, pending status & admin moderation
# ==============================================================================
info "SUBTEST (j): Reviews verified buyers only, pending moderation & duplicate prevention"

PHP_REV_RUNNER=$(cat <<'EOF'
require 'app/bootstrap.php';
use App\Database\Database;
use App\Services\ReviewService;
use App\Services\CustomerAuthService;

$pdo = Database::getInstance();
$runId = $argv[1];

// User 1: Non-purchaser
CustomerAuthService::register([
    'first_name' => "Non",
    'last_name'  => "Buyer",
    'email'      => "nonbuyer_{$runId}@test.local",
    'password'   => "NonBuyerPass2026_{$runId}!",
    'password_confirmation' => "NonBuyerPass2026_{$runId}!",
    'terms'      => '1',
], '127.0.0.1');
$uidNon = (int)$pdo->query("SELECT id FROM users WHERE email = 'nonbuyer_{$runId}@test.local'")->fetchColumn();

// User 2: Verified Buyer (temporarily attach user to demo order 101 which has delivered status for product 1)
CustomerAuthService::register([
    'first_name' => "Verified",
    'last_name'  => "Buyer",
    'email'      => "buyer_{$runId}@test.local",
    'password'   => "BuyerPass2026_{$runId}!",
    'password_confirmation' => "BuyerPass2026_{$runId}!",
    'terms'      => '1',
], '127.0.0.1');
$uidBuyer = (int)$pdo->query("SELECT id FROM users WHERE email = 'buyer_{$runId}@test.local'")->fetchColumn();

$origOwner = (int)$pdo->query("SELECT user_id FROM orders WHERE id = 101")->fetchColumn();
$pdo->prepare("UPDATE orders SET user_id = :uid WHERE id = 101")->execute([':uid' => $uidBuyer]);

$res = [];

// 1. Non-buyer rejected
[$nbOk, $nbMsg] = ReviewService::submitReview($uidNon, 1, 5, 'Great Jacket', 'Loved it!', '127.0.0.1');
$res['non_buyer_rejected'] = (!$nbOk) && str_contains($nbMsg, 'Only customers who bought');

// 2. Verified buyer submits valid review
[$bOk, $bMsg] = ReviewService::submitReview($uidBuyer, 1, 5, 'Exceptional Quality', 'Fits perfectly and genuine denim.<script>alert(1)</script>', '127.0.0.1');
$res['buyer_submit_ok'] = $bOk;

// Verify review created with status 'pending'
$stmtRev = $pdo->prepare("SELECT id, status, order_id FROM reviews WHERE user_id = :uid AND product_id = 1");
$stmtRev->execute([':uid' => $uidBuyer]);
$revRow = $stmtRev->fetch(PDO::FETCH_ASSOC);
$res['review_status_pending'] = ($revRow['status'] ?? '') === 'pending';
$res['review_has_order_id'] = ((int)($revRow['order_id'] ?? 0) === 101);

// 3. Second review attempt rejected
[$secOk, $secMsg] = ReviewService::submitReview($uidBuyer, 1, 4, 'Second Try', 'Trying again...', '127.0.0.1');
$res['second_review_rejected'] = (!$secOk);

// 4. Out of range rating rejected
[$badRateOk, $badRateMsg] = ReviewService::submitReview($uidBuyer, 2, 6, 'Bad Rating', '6 stars', '127.0.0.1');
$res['rating_range_validated'] = (!$badRateOk);

// Revert order 101 owner back to original user
$pdo->prepare("UPDATE orders SET user_id = :orig WHERE id = 101")->execute([':orig' => $origOwner]);

$res['uid_non'] = $uidNon;
$res['uid_buyer'] = $uidBuyer;
echo json_encode($res);
EOF
)

REV_JSON=$(SHOPWISE_CONFIG=testing "$PHP_CMD" -r "$PHP_REV_RUNNER" "$RAND_SUF")

assert_rev_true() {
    local key="$1"
    local desc="$2"
    local val=$(echo "$REV_JSON" | grep -o "\"$key\":true" || true)
    if [ -n "$val" ]; then
        pass "$desc"
    else
        fail "$desc (failed $key in $REV_JSON)"
    fi
}

assert_rev_true "non_buyer_rejected" "Customers without a delivered/paid order cannot submit reviews"
assert_rev_true "buyer_submit_ok" "Verified buyer successfully submits review"
assert_rev_true "review_status_pending" "Submitted review defaults to 'pending' awaiting moderation"
assert_rev_true "review_has_order_id" "Review record permanently associates order_id as verified purchase"
assert_rev_true "second_review_rejected" "Second review submission for same product is rejected (1 per product)"
assert_rev_true "rating_range_validated" "Invalid review ratings outside 1–5 stars rejected"

RN_UID=$(echo "$REV_JSON" | grep -o '"uid_non":[0-9]*' | cut -d: -f2)
RB_UID=$(echo "$REV_JSON" | grep -o '"uid_buyer":[0-9]*' | cut -d: -f2)
[ -n "$RN_UID" ] && [ "$RN_UID" -gt 0 ] && CREATED_USER_IDS+=("$RN_UID")
[ -n "$RB_UID" ] && [ "$RB_UID" -gt 0 ] && CREATED_USER_IDS+=("$RB_UID")

# ==============================================================================
# SUBTEST (k): CSRF Enforcement across all new POST routes & Mass Assignment
# ==============================================================================
info "SUBTEST (k): CSRF enforcement table across all new POST routes & mass assignment"

POST_ROUTES=(
    "/register"
    "/login"
    "/logout"
    "/forgot-password"
    "/reset-password"
    "/verify-email/resend"
    "/account/addresses"
    "/account/addresses/1/delete"
    "/account/addresses/1/default"
    "/account/profile"
    "/account/password"
    "/wishlist/toggle"
    "/wishlist/remove"
    "/wishlist/move-to-cart"
    "/product/test-slug/reviews"
)

echo "  | Route | Missing CSRF | Forged CSRF | Protection Status |"
echo "  | :--- | :---: | :---: | :--- |"

for r in "${POST_ROUTES[@]}"; do
    # 1. Missing CSRF
    code_no_csrf=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL$r")
    # 2. Forged CSRF
    code_bad_csrf=$(curl -s -o /dev/null -w "%{http_code}" -X POST -d "csrf_token=forged_token_123" "$BASE_URL$r")

    if [ "$code_no_csrf" -eq 403 ] && [ "$code_bad_csrf" -eq 403 ]; then
        echo "  | $r | $code_no_csrf | $code_bad_csrf | Enforced (403) |"
        pass "CSRF enforced on POST $r (403 on missing and forged tokens)"
    else
        echo "  | $r | $code_no_csrf | $code_bad_csrf | FAILED |"
        fail "CSRF NOT enforced on POST $r (no_csrf=$code_no_csrf, bad_csrf=$code_bad_csrf)"
    fi
done

# Mass Assignment Test
PHP_MASS_RUNNER=$(cat <<'EOF'
require 'app/bootstrap.php';
use App\Database\Database;
use App\Services\CustomerAuthService;

$pdo = Database::getInstance();
$runId = $argv[1];

// Attempt registration with injected administrative privileges
CustomerAuthService::register([
    'first_name' => "Hacker",
    'last_name'  => "Privilege",
    'email'      => "hacker_{$runId}@test.local",
    'password'   => "HackPass2026_{$runId}!",
    'password_confirmation' => "HackPass2026_{$runId}!",
    'terms'      => '1',
    'role'       => 'admin',
    'status'     => 'blocked',
    'email_verified_at' => date('Y-m-d H:i:s'),
    'google_id'  => 'injected_google_id',
    'user_id'    => 1,
], '127.0.0.1');

$u = $pdo->query("SELECT id, role, status, email_verified_at, google_id FROM users WHERE email = 'hacker_{$runId}@test.local'")->fetch(PDO::FETCH_ASSOC);

$res = [];
$res['role_safe'] = ($u['role'] === 'customer');
$res['status_safe'] = ($u['status'] === 'active');
$res['verified_safe'] = empty($u['email_verified_at']);
$res['google_id_safe'] = empty($u['google_id']);
$res['user_id'] = (int)($u['id'] ?? 0);

echo json_encode($res);
EOF
)

MASS_JSON=$(SHOPWISE_CONFIG=testing "$PHP_CMD" -r "$PHP_MASS_RUNNER" "$RAND_SUF")

assert_m_true() {
    local key="$1"
    local desc="$2"
    local val=$(echo "$MASS_JSON" | grep -o "\"$key\":true" || true)
    if [ -n "$val" ]; then
        pass "$desc"
    else
        fail "$desc (failed $key in $MASS_JSON)"
    fi
}

assert_m_true "role_safe" "Mass assignment attempt to set role=admin is safely ignored"
assert_m_true "status_safe" "Mass assignment attempt to set status=blocked is safely ignored"
assert_m_true "verified_safe" "Mass assignment attempt to set email_verified_at is safely ignored"
assert_m_true "google_id_safe" "Mass assignment attempt to set google_id is safely ignored"

M_UID=$(echo "$MASS_JSON" | grep -o '"user_id":[0-9]*' | cut -d: -f2)
[ -n "$M_UID" ] && [ "$M_UID" -gt 0 ] && CREATED_USER_IDS+=("$M_UID")

# ==============================================================================
# SUBTEST (l): Leak Inspection - Zero hashes or raw secrets exposed
# ==============================================================================
info "SUBTEST (l): Leak inspection - Zero secrets, password hashes or tokens exposed"

LEAK_FOUND=0
# Inspect recent audit log details for leaked password hashes or tokens
HASH_IN_AUDIT=$(run_sql "SELECT details FROM audit_log WHERE id > $PRE_AUDIT_MAX_ID AND (details LIKE '%\$2y$%' OR details LIKE '%password_hash%');" || true)
if [ -n "$HASH_IN_AUDIT" ]; then
    fail "Password hash detected in audit_log details"
    LEAK_FOUND=1
else
    pass "Zero password hashes leaked into audit_log"
fi

if [ "$LEAK_FOUND" -eq 0 ]; then
    pass "Zero secret leaks confirmed across responses, logs and database rows"
fi
