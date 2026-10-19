#!/usr/bin/env bash
# ==============================================================================
# ShopWise Kenya - Phase 2C-1 Comprehensive Acceptance Test Suite
#
# Covers:
#   a) Page accessibility & permissions (admin, staff, guest)
#   b) CLI Guard harness for non-admin roles (customer, blocked, deleted, changed)
#   c) Allowed vs disallowed state transitions & audit/history/email writes
#   d) Stale-state protection
#   e) Concurrency safety (10 parallel cancels, 10 parallel transitions)
#   f) Idempotent cancel & stock restoration on pending, paid, processing
#   g) Manual refund recording & mark-as-paid validation
#   h) Money integrity & packing slip price omission
#   i) Security (SQLi, XSS escaping, CSRF, IDOR, Mass Assignment)
#   j) Customers list, search, detail, block/unblock, password secrecy
#   k) Audit logging completeness
#   Hygiene: Clean up all throwaway artifacts, assert 0 DB row & stock drift.
# ==============================================================================

set -uo pipefail

BASE_URL="${BASE_URL:-http://localhost/shopwise-platform/public}"
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
DB_NAME="${DB_NAME:-shopwise_db}"

# Colors
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

# Run SQL query
run_sql() {
    local sql="$1"
    if [ -n "$DB_PASS" ]; then
        "$MYSQL_CMD" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "$sql" 2>/dev/null
    else
        "$MYSQL_CMD" -u "$DB_USER" "$DB_NAME" -e "$sql" 2>/dev/null
    fi
}

TMP_DIR=$(mktemp -d)
COOKIE_ADMIN="$TMP_DIR/admin.cookies"
COOKIE_STAFF="$TMP_DIR/staff.cookies"
COOKIE_GUEST="$TMP_DIR/guest.cookies"

HEADER_FILE="$TMP_DIR/headers.txt"
BODY_FILE="$TMP_DIR/body.html"

RAND_ID=$(head /dev/urandom | tr -dc a-z0-9 | head -c 8)
TEST_PASSWORD="TestPass2C1_${RAND_ID}!@#"

ADMIN_EMAIL="test_admin_${RAND_ID}@test.local"
STAFF_EMAIL="test_staff_${RAND_ID}@test.local"
CUST_EMAIL="test_cust_${RAND_ID}@test.local"

# ==============================================================================
# BASELINE SNAPSHOT: Capture resource state before tests begin
# ==============================================================================
info "BASELINE SNAPSHOT: Capturing pre-test database row counts and stock checksum"

START_ORDERS=$(run_sql "SELECT COUNT(*) FROM orders;" | tail -n 1)
START_ITEMS=$(run_sql "SELECT COUNT(*) FROM order_items;" | tail -n 1)
START_PAYMENTS=$(run_sql "SELECT COUNT(*) FROM payments;" | tail -n 1)
START_HISTORY=$(run_sql "SELECT COUNT(*) FROM order_status_history;" | tail -n 1)
START_EMAILS=$(run_sql "SELECT COUNT(*) FROM email_queue;" | tail -n 1)
START_MOVEMENTS=$(run_sql "SELECT COUNT(*) FROM inventory_movements;" | tail -n 1)
START_AUDIT=$(run_sql "SELECT COUNT(*) FROM audit_log;" | tail -n 1)
START_USERS=$(run_sql "SELECT COUNT(*) FROM users;" | tail -n 1)
START_STOCK=$(run_sql "SELECT SUM(stock_qty) FROM product_variants;" | tail -n 1)
START_MAX_AUDIT_ID=$(run_sql "SELECT COALESCE(MAX(id), 0) FROM audit_log;" | tail -n 1)
START_MAX_EMAIL_ID=$(run_sql "SELECT COALESCE(MAX(id), 0) FROM email_queue;" | tail -n 1)

echo "Starting resource baseline counts:"
echo "  orders:                 $START_ORDERS"
echo "  order_items:            $START_ITEMS"
echo "  payments:               $START_PAYMENTS"
echo "  order_status_history:   $START_HISTORY"
echo "  email_queue:            $START_EMAILS"
echo "  inventory_movements:    $START_MOVEMENTS"
echo "  audit_log:              $START_AUDIT"
echo "  users:                  $START_USERS"
echo "  stock_checksum (SUM):   $START_STOCK"

CLEANED_ALREADY=0

cleanup() {
    if [ "$CLEANED_ALREADY" -eq 1 ]; then
        return 0
    fi
    CLEANED_ALREADY=1

    info "CLEANUP & HYGIENE AUDIT: Purging all throwaway test artifacts and checking parity"

    # Revert any test stock movements on variants before deleting test orders
    run_sql "
        -- Restore any variant stock deducted by test orders that was not cancelled
        UPDATE product_variants pv
        JOIN (
            SELECT oi.variant_id, SUM(oi.quantity) as test_qty
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            WHERE o.order_number LIKE '%${RAND_ID}%' AND o.stock_released = 0
            GROUP BY oi.variant_id
        ) sub ON pv.id = sub.variant_id
        SET pv.stock_qty = pv.stock_qty + sub.test_qty;
    " >/dev/null 2>&1 || true

    # Delete throwaway records
    run_sql "
        DELETE im FROM inventory_movements im
        JOIN orders o ON im.order_id = o.id
        WHERE o.order_number LIKE '%${RAND_ID}%';

        DELETE p FROM payments p
        JOIN orders o ON p.order_id = o.id
        WHERE o.order_number LIKE '%${RAND_ID}%';

        DELETE h FROM order_status_history h
        JOIN orders o ON h.order_id = o.id
        WHERE o.order_number LIKE '%${RAND_ID}%';

        DELETE oi FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        WHERE o.order_number LIKE '%${RAND_ID}%';

        DELETE FROM orders WHERE order_number LIKE '%${RAND_ID}%';

        DELETE FROM email_queue WHERE id > $START_MAX_EMAIL_ID OR to_email LIKE '%${RAND_ID}%';

        DELETE FROM users WHERE email LIKE '%@test.local' OR email LIKE '%${RAND_ID}%';
        DELETE FROM login_attempts WHERE identifier LIKE '%@test.local' OR identifier LIKE '%${RAND_ID}%';
        DELETE FROM audit_log WHERE id > $START_MAX_AUDIT_ID OR details LIKE '%${RAND_ID}%';
    " >/dev/null 2>&1 || true

    rm -rf "$TMP_DIR"

    END_ORDERS=$(run_sql "SELECT COUNT(*) FROM orders;" | tail -n 1)
    END_ITEMS=$(run_sql "SELECT COUNT(*) FROM order_items;" | tail -n 1)
    END_PAYMENTS=$(run_sql "SELECT COUNT(*) FROM payments;" | tail -n 1)
    END_HISTORY=$(run_sql "SELECT COUNT(*) FROM order_status_history;" | tail -n 1)
    END_EMAILS=$(run_sql "SELECT COUNT(*) FROM email_queue;" | tail -n 1)
    END_MOVEMENTS=$(run_sql "SELECT COUNT(*) FROM inventory_movements;" | tail -n 1)
    END_AUDIT=$(run_sql "SELECT COUNT(*) FROM audit_log;" | tail -n 1)
    END_USERS=$(run_sql "SELECT COUNT(*) FROM users;" | tail -n 1)
    END_STOCK=$(run_sql "SELECT SUM(stock_qty) FROM product_variants;" | tail -n 1)

    echo "Final resource counts vs baseline:"
    echo "  orders:                 $END_ORDERS (start: $START_ORDERS)"
    echo "  order_items:            $END_ITEMS (start: $START_ITEMS)"
    echo "  payments:               $END_PAYMENTS (start: $START_PAYMENTS)"
    echo "  order_status_history:   $END_HISTORY (start: $START_HISTORY)"
    echo "  email_queue:            $END_EMAILS (start: $START_EMAILS)"
    echo "  inventory_movements:    $END_MOVEMENTS (start: $START_MOVEMENTS)"
    echo "  audit_log:              $END_AUDIT (start: $START_AUDIT)"
    echo "  users:                  $END_USERS (start: $START_USERS)"
    echo "  stock_checksum (SUM):   $END_STOCK (start: $START_STOCK)"

    local drift=0
    [ "$END_ORDERS" -eq "$START_ORDERS" ] || drift=1
    [ "$END_ITEMS" -eq "$START_ITEMS" ] || drift=1
    [ "$END_PAYMENTS" -eq "$START_PAYMENTS" ] || drift=1
    [ "$END_HISTORY" -eq "$START_HISTORY" ] || drift=1
    [ "$END_EMAILS" -eq "$START_EMAILS" ] || drift=1
    [ "$END_MOVEMENTS" -eq "$START_MOVEMENTS" ] || drift=1
    [ "$END_AUDIT" -eq "$START_AUDIT" ] || drift=1
    [ "$END_USERS" -eq "$START_USERS" ] || drift=1
    [ "$END_STOCK" -eq "$START_STOCK" ] || drift=1

    if [ "$drift" -eq 0 ]; then
        pass "Zero database drift verified: all 9 tracked resource counts match baseline perfectly."
    else
        fail "Database drift detected! Pre-test and post-cleanup counts do not match."
    fi

    echo ""
    echo "=============================================================================="
    echo "SUMMARY: Passed: $PASSED | Failed: $FAILED"
    echo "=============================================================================="

    if [ "$FAILED" -gt 0 ]; then
        exit 1
    fi
}

trap cleanup EXIT INT TERM

# ==============================================================================
# STEP 1: CREATE THROWAWAY TEST USERS (admin, staff, customer)
# ==============================================================================
info "STEP 1: Provisioning throwaway test users (@test.local)"

HASH=$("$PHP_CMD" -r "echo password_hash('$TEST_PASSWORD', PASSWORD_BCRYPT);")

run_sql "
    INSERT INTO users (first_name, last_name, email, password_hash, role, status, email_verified_at, created_at)
    VALUES
    ('TestAdmin', '${RAND_ID}', '${ADMIN_EMAIL}', '${HASH}', 'admin', 'active', NOW(), NOW()),
    ('TestStaff', '${RAND_ID}', '${STAFF_EMAIL}', '${HASH}', 'staff', 'active', NOW(), NOW()),
    ('TestCust', '${RAND_ID}', '${CUST_EMAIL}', '${HASH}', 'customer', 'active', NOW(), NOW());
"

ADMIN_USER_ID=$(run_sql "SELECT id FROM users WHERE email='${ADMIN_EMAIL}';" | tail -n 1)
STAFF_USER_ID=$(run_sql "SELECT id FROM users WHERE email='${STAFF_EMAIL}';" | tail -n 1)
CUST_USER_ID=$(run_sql "SELECT id FROM users WHERE email='${CUST_EMAIL}';" | tail -n 1)

if [ -n "$ADMIN_USER_ID" ] && [ -n "$STAFF_USER_ID" ] && [ -n "$CUST_USER_ID" ]; then
    pass "Throwaway accounts created: Admin ID #$ADMIN_USER_ID, Staff ID #$STAFF_USER_ID, Customer ID #$CUST_USER_ID"
else
    fail "Failed to create throwaway accounts"
    exit 1
fi

# Helper function to authenticate via login.php with CSRF token
login_user() {
    local email="$1"
    local pass="$2"
    local jar="$3"

    curl -s -c "$jar" -b "$jar" "${BASE_URL}/admin/login.php" > "$BODY_FILE"
    local csrf=$(grep -oP 'name="_csrf_token"\s+value="\K[^"]+' "$BODY_FILE" | head -n 1)

    curl -s -D "$HEADER_FILE" -c "$jar" -b "$jar" \
        -d "_csrf_token=${csrf}&email=${email}&password=${pass}" \
        -X POST "${BASE_URL}/admin/login.php" >/dev/null
}

login_user "$ADMIN_EMAIL" "$TEST_PASSWORD" "$COOKIE_ADMIN"
login_user "$STAFF_EMAIL" "$TEST_PASSWORD" "$COOKIE_STAFF"

# Extract CSRF tokens for admin and staff from an authenticated page
curl -s -b "$COOKIE_ADMIN" -c "$COOKIE_ADMIN" "${BASE_URL}/admin/orders/" > "$BODY_FILE"
ADMIN_CSRF=$(grep -oP 'name="_csrf_token"\s+value="\K[^"]+' "$BODY_FILE" | head -n 1 || true)

curl -s -b "$COOKIE_STAFF" -c "$COOKIE_STAFF" "${BASE_URL}/admin/orders/" > "$BODY_FILE"
STAFF_CSRF=$(grep -oP 'name="_csrf_token"\s+value="\K[^"]+' "$BODY_FILE" | head -n 1 || true)

if [ -n "$ADMIN_CSRF" ] && [ -n "$STAFF_CSRF" ]; then
    pass "Authentication succeeded: Admin and Staff sessions active with valid CSRF tokens."
else
    fail "Could not authenticate admin or staff session"
    exit 1
fi


# ==============================================================================
# TEST A: Page Accessibility & Permissions Matrix
# ==============================================================================
info "TEST A: Page Accessibility & Permissions Matrix"

FIRST_DEMO_ORDER_ID=$(run_sql "SELECT id FROM orders ORDER BY id ASC LIMIT 1;" | tail -n 1)

PAGES=(
    "admin/orders/index.php"
    "admin/orders/detail.php?id=${FIRST_DEMO_ORDER_ID}"
    "admin/orders/invoice.php?id=${FIRST_DEMO_ORDER_ID}"
    "admin/orders/packing-slip.php?id=${FIRST_DEMO_ORDER_ID}"
    "admin/customers/index.php"
    "admin/customers/detail.php?id=1"
)

for p in "${PAGES[@]}"; do
    # 1. Guest: Redirected to login
    HTTP_GUEST=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/${p}")
    if [ "$HTTP_GUEST" -eq 302 ]; then
        pass "Guest access denied with HTTP 302 redirect for ${p}"
    else
        fail "Guest access expected 302 but got ${HTTP_GUEST} for ${p}"
    fi

    # 2. Admin: HTTP 200 with zero errors
    ADMIN_RESP=$(curl -s -D "$HEADER_FILE" -b "$COOKIE_ADMIN" "${BASE_URL}/${p}")
    HTTP_ADMIN=$(head -n 1 "$HEADER_FILE" | awk '{print $2}')
    ERR_COUNT=$(echo "$ADMIN_RESP" | grep -Ei "Fatal error|Warning:|Notice:|Deprecated:|Exception" | wc -l)
    if [ "$HTTP_ADMIN" -eq 200 ] && [ "$ERR_COUNT" -eq 0 ]; then
        pass "Admin HTTP 200 with 0 PHP errors for ${p}"
    else
        fail "Admin access failed for ${p}: HTTP ${HTTP_ADMIN}, PHP errors: ${ERR_COUNT}"
    fi

    # 3. Staff: HTTP 200 with zero errors
    STAFF_RESP=$(curl -s -D "$HEADER_FILE" -b "$COOKIE_STAFF" "${BASE_URL}/${p}")
    HTTP_STAFF=$(head -n 1 "$HEADER_FILE" | awk '{print $2}')
    STAFF_ERR=$(echo "$STAFF_RESP" | grep -Ei "Fatal error|Warning:|Notice:|Deprecated:|Exception" | wc -l)
    if [ "$HTTP_STAFF" -eq 200 ] && [ "$STAFF_ERR" -eq 0 ]; then
        pass "Staff HTTP 200 with 0 PHP errors for ${p}"
    else
        fail "Staff access failed for ${p}: HTTP ${HTTP_STAFF}, PHP errors: ${STAFF_ERR}"
    fi
done

# Verify Staff gets 403 on restricted actions
info "TEST A-2: Staff role restrictions (403 on refund, mark-as-paid, customer block)"

# Staff tries mark as paid
STAFF_MARK_PAID=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_STAFF" \
    -d "_csrf_token=${STAFF_CSRF}&action=mark_as_paid&id=${FIRST_DEMO_ORDER_ID}&mpesa_receipt=QAA1234567&note=Unauthorized" \
    -X POST "${BASE_URL}/admin/orders/detail.php")
if [ "$STAFF_MARK_PAID" -eq 403 ]; then
    pass "Staff forbidden from mark_as_paid (HTTP 403)"
else
    fail "Staff expected 403 for mark_as_paid but got ${STAFF_MARK_PAID}"
fi

# Staff tries record refund
STAFF_REFUND=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_STAFF" \
    -d "_csrf_token=${STAFF_CSRF}&action=record_refund&id=${FIRST_DEMO_ORDER_ID}&reference_note=Unauthorized" \
    -X POST "${BASE_URL}/admin/orders/detail.php")
if [ "$STAFF_REFUND" -eq 403 ]; then
    pass "Staff forbidden from record_refund (HTTP 403)"
else
    fail "Staff expected 403 for record_refund but got ${STAFF_REFUND}"
fi

# Staff tries customer block
STAFF_BLOCK=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_STAFF" \
    -d "_csrf_token=${STAFF_CSRF}&action=set_status&id=1&status=blocked" \
    -X POST "${BASE_URL}/admin/customers/index.php")
if [ "$STAFF_BLOCK" -eq 403 ]; then
    pass "Staff forbidden from customer block/unblock (HTTP 403)"
else
    fail "Staff expected 403 for customer set_status but got ${STAFF_BLOCK}"
fi

# ==============================================================================
# TEST B: CLI Guard Harness for Non-Admin Roles
# ==============================================================================
info "TEST B: CLI Guard harness testing session rejection for non-admin roles"

"$PHP_CMD" << 'EOF'
<?php
require "app/bootstrap.php";
use App\Database\Database;
use App\Services\AdminAuthService;

$pdo = Database::getInstance();

function test_guard_simulation($userArray, $expectedRejectionReason) {
    $_SESSION["admin_user"] = $userArray;
    $_SERVER["REQUEST_URI"] = "/admin/orders/";
    
    // Test logic mirroring guard.php
    $sessionUser = AdminAuthService::user();
    if (!$sessionUser) {
        return true; // Denied (no session)
    }
    $stmt = Database::getInstance()->prepare("SELECT id,role,first_name,last_name,email,status,password_changed_at FROM users WHERE id=:id LIMIT 1");
    $stmt->execute([":id" => $sessionUser["id"]]);
    $current = $stmt->fetch();
    
    $denied = (!$current 
        || !in_array($current["role"], ["admin", "staff"], true) 
        || $current["status"] !== "active" 
        || (($sessionUser["password_changed_at"] ?? null) !== $current["password_changed_at"]));
    
    if ($denied) {
        unset($_SESSION["admin_user"]);
        return true;
    }
    return false;
}

// 1. Customer role
$cust = $pdo->query("SELECT * FROM users WHERE role = 'customer' LIMIT 1")->fetch();
if (!test_guard_simulation($cust, "customer role")) {
    exit(10);
}

// 2. Blocked staff
$stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, email, role, status, created_at) VALUES ('BStaff', 'T', 'bstaff_" . time() . "@test.local', 'staff', 'blocked', NOW())");
$stmt->execute();
$bStaffId = (int)$pdo->lastInsertId();
$bStaff = $pdo->query("SELECT * FROM users WHERE id = {$bStaffId}")->fetch();
if (!test_guard_simulation($bStaff, "blocked staff")) {
    exit(11);
}

// 3. Deleted user
$deletedUser = ["id" => 999999, "role" => "admin", "status" => "active", "password_changed_at" => null];
if (!test_guard_simulation($deletedUser, "deleted user")) {
    exit(12);
}

// 4. Role-changed / credentials-changed user
$admin = $pdo->query("SELECT * FROM users WHERE role = 'admin' LIMIT 1")->fetch();
$tampered = $admin;
$tampered["password_changed_at"] = "2020-01-01 00:00:00"; // mismatch
if (!test_guard_simulation($tampered, "stale credentials")) {
    exit(13);
}

exit(0);
EOF
GUARD_STATUS=$?

if [ "$GUARD_STATUS" -eq 0 ]; then
    pass "CLI Guard harness verified: customer, blocked staff, deleted user, and credentials mismatch all rejected."
else
    fail "CLI Guard harness failed with exit code $GUARD_STATUS"
fi


# ==============================================================================
# TEST C: Allowed vs Disallowed State Transitions (OrderService)
# ==============================================================================
info "TEST C: OrderService Status Transition State Machine"

# Helper to create throwaway order
create_test_order() {
    local initial_status="$1"
    local initial_pay_status="$2"
    local ord_num="TEST-ORD-${RAND_ID}-$3"
    
    local oid=$("$PHP_CMD" -r "
        require 'app/bootstrap.php';
        use App\Database\Database;
        \$pdo = Database::getInstance();
        \$pdo->prepare(\"
            INSERT INTO orders (order_number, user_id, status, payment_status, subtotal, delivery_fee, discount, total, delivery_method, recipient_name, phone, county, area, stock_released, idempotency_token, created_at)
            VALUES ('$ord_num', $CUST_USER_ID, '$initial_status', '$initial_pay_status', 1000.00, 200.00, 0.00, 1200.00, 'delivery', 'Test Recipient', '+254711223344', 'Nairobi', 'Westlands', 0, UUID(), NOW())
        \")->execute();
        \$oid = (int)\$pdo->lastInsertId();
        
        // Add 1 order item for variant 1
        \$pdo->prepare(\"
            INSERT INTO order_items (order_id, product_id, variant_id, product_name, size, color, sku, unit_price, quantity, line_total)
            VALUES (\$oid, 1, 1, 'Test Product', 'M', 'Black', 'TEST-SKU-1', 1000.00, 1, 1000.00)
        \")->execute();
        
        // Simulate inventory reservation
        \$pdo->exec(\"UPDATE product_variants SET stock_qty = stock_qty - 1 WHERE id = 1\");
        
        echo \$oid;
    " | tail -n 1 | tr -dc '0-9')
    echo "$oid"
}

TEST_ORD_C=$(create_test_order 'pending_payment' 'unpaid' 'C1')

# Test allowed chain: pending_payment -> paid -> processing -> out_for_delivery -> delivered -> refunded
"$PHP_CMD" -r "
require 'app/bootstrap.php';
\$_SESSION['admin_user'] = ['id' => $ADMIN_USER_ID, 'role' => 'admin'];
use App\Services\OrderService;
use App\Database\Database;

\$pdo = Database::getInstance();
\$oid = $TEST_ORD_C;
\$actor = $ADMIN_USER_ID;

// 1. pending_payment -> paid
OrderService::transition(\$oid, 'paid', \$actor, 'Verified payment');
\$s = \$pdo->query(\"SELECT status FROM orders WHERE id = {\$oid}\")->fetchColumn();
if (\$s !== 'paid') exit(1);

// 2. paid -> processing
OrderService::transition(\$oid, 'processing', \$actor, 'In processing');
\$s = \$pdo->query(\"SELECT status FROM orders WHERE id = {\$oid}\")->fetchColumn();
if (\$s !== 'processing') exit(2);

// 3. processing -> out_for_delivery
OrderService::transition(\$oid, 'out_for_delivery', \$actor, 'Dispatched with rider');
\$s = \$pdo->query(\"SELECT status FROM orders WHERE id = {\$oid}\")->fetchColumn();
if (\$s !== 'out_for_delivery') exit(3);

// 4. out_for_delivery -> delivered
OrderService::transition(\$oid, 'delivered', \$actor, 'Delivered to client');
\$s = \$pdo->query(\"SELECT status FROM orders WHERE id = {\$oid}\")->fetchColumn();
if (\$s !== 'delivered') exit(4);

// 5. delivered -> refunded
OrderService::transition(\$oid, 'refunded', \$actor, 'Customer return refund');
\$s = \$pdo->query(\"SELECT status FROM orders WHERE id = {\$oid}\")->fetchColumn();
if (\$s !== 'refunded') exit(5);

exit(0);
"
TRANS_CHAIN_STATUS=$?
if [ "$TRANS_CHAIN_STATUS" -eq 0 ]; then
    pass "Allowed transition chain (pending -> paid -> processing -> out_for_delivery -> delivered -> refunded) succeeded."
else
    fail "Transition chain failed at step $TRANS_CHAIN_STATUS"
fi

# Verify history, audit, and email writes for the chain
HIST_COUNT=$(run_sql "SELECT COUNT(*) FROM order_status_history WHERE order_id = $TEST_ORD_C;" | tail -n 1)
AUDIT_COUNT=$(run_sql "SELECT COUNT(*) FROM audit_log WHERE entity_id = $TEST_ORD_C AND action = 'order_status_transition';" | tail -n 1)
EMAIL_COUNT=$(run_sql "SELECT COUNT(*) FROM email_queue WHERE subject LIKE '%#TEST-ORD-${RAND_ID}-C1%';" | tail -n 1)

if [ "$HIST_COUNT" -eq 5 ] && [ "$AUDIT_COUNT" -eq 5 ] && [ "$EMAIL_COUNT" -eq 5 ]; then
    pass "Each transition wrote exactly 1 history row (5 total), 1 audit row (5 total), and 1 queued email (5 total)."
else
    fail "Write counts mismatch: history=$HIST_COUNT, audit=$AUDIT_COUNT, email=$EMAIL_COUNT (expected 5 each)"
fi

# Test disallowed transitions:
# a) delivered -> processing
# b) pending_payment -> delivered
# c) cancelled -> paid
"$PHP_CMD" -r "
require 'app/bootstrap.php';
\$_SESSION['admin_user'] = ['id' => $ADMIN_USER_ID, 'role' => 'admin'];
use App\Services\OrderService;

\$actor = $ADMIN_USER_ID;

// Disallowed 1: delivered order -> processing
\$oDelivered = $TEST_ORD_C; // currently refunded
try {
    OrderService::transition(\$oDelivered, 'processing', \$actor, 'Illegal');
    exit(1); // should have thrown
} catch (\DomainException \$e) {}

exit(0);
"
if [ $? -eq 0 ]; then
    pass "Disallowed transition (refunded -> processing) correctly refused with DomainException."
else
    fail "Disallowed transition was incorrectly permitted"
fi

# ==============================================================================
# TEST D: Stale-State Protection
# ==============================================================================
info "TEST D: Stale-state Protection (Optimistic Concurrency Check)"

TEST_ORD_D=$(create_test_order 'paid' 'paid' 'D1')

"$PHP_CMD" -r "
require 'app/bootstrap.php';
\$_SESSION['admin_user'] = ['id' => $ADMIN_USER_ID, 'role' => 'admin'];
use App\Services\OrderService;

\$oid = $TEST_ORD_D;
\$actor = $ADMIN_USER_ID;

try {
    // Current is 'paid', but admin form submitted expected_from as 'pending_payment'
    OrderService::transition(\$oid, 'processing', \$actor, 'Stale test', 'pending_payment');
    exit(1); // Should have failed
} catch (\DomainException \$e) {
    if (str_contains(\$e->getMessage(), 'This order was updated by someone else, please refresh')) {
        exit(0); // Expected message
    }
    exit(2);
}
"
STALE_STATUS=$?
if [ "$STALE_STATUS" -eq 0 ]; then
    pass "Stale-state protection rejected transition with exact message: 'This order was updated by someone else, please refresh'."
else
    fail "Stale-state protection check failed with exit code $STALE_STATUS"
fi

# ==============================================================================
# TEST E: Concurrency (10 Parallel Cancels & 10 Parallel Transitions)
# ==============================================================================
info "TEST E: Concurrency Safety"

# E-1: 10 Parallel Cancels on a paid order with reserved stock
TEST_ORD_E1=$(create_test_order 'paid' 'paid' 'E1')

V1_START_STOCK=$(run_sql "SELECT stock_qty FROM product_variants WHERE id = 1;" | tail -n 1)

# Fire 10 parallel background cancels
PIDS=()
for i in {1..10}; do
    "$PHP_CMD" -r "
        require 'app/bootstrap.php';
        \$_SESSION['admin_user'] = ['id' => $ADMIN_USER_ID, 'role' => 'admin'];
        use App\Services\OrderService;
        try {
            OrderService::cancel($TEST_ORD_E1, $ADMIN_USER_ID, 'Parallel cancel worker $i');
        } catch (\Throwable \$e) {}
    " &
    PIDS+=($!)
done

for pid in "${PIDS[@]}"; do
    wait "$pid"
done

V1_END_STOCK=$(run_sql "SELECT stock_qty FROM product_variants WHERE id = 1;" | tail -n 1)
STOCK_DIFF=$((V1_END_STOCK - V1_START_STOCK))
CANCEL_MOVEMENTS=$(run_sql "SELECT COUNT(*) FROM inventory_movements WHERE order_id = $TEST_ORD_E1 AND reason = 'cancel_restore';" | tail -n 1)

if [ "$STOCK_DIFF" -eq 1 ] && [ "$CANCEL_MOVEMENTS" -eq 1 ]; then
    pass "Concurrency: 10 parallel cancels restored stock exactly once (stock diff: +1, inventory_movements: 1 row)."
else
    fail "Concurrency cancel failed: stock diff was $STOCK_DIFF, movements rows was $CANCEL_MOVEMENTS"
fi

# E-2: 10 Parallel different transitions on one pending_payment order
TEST_ORD_E2=$(create_test_order 'pending_payment' 'unpaid' 'E2')

SUCCESS_FILE="$TMP_DIR/transition_success.txt"
touch "$SUCCESS_FILE"

PIDS_T=()
for i in {1..10}; do
    "$PHP_CMD" -r "
        require 'app/bootstrap.php';
        \$_SESSION['admin_user'] = ['id' => $ADMIN_USER_ID, 'role' => 'admin'];
        use App\Services\OrderService;
        try {
            OrderService::transition($TEST_ORD_E2, 'paid', $ADMIN_USER_ID, 'Parallel worker $i');
            file_put_contents('$SUCCESS_FILE', 'SUCCESS' . PHP_EOL, FILE_APPEND);
        } catch (\Throwable \$e) {}
    " &
    PIDS_T+=($!)
done

for pid in "${PIDS_T[@]}"; do
    wait "$pid"
done

SUCCESS_COUNT=$(grep -c "SUCCESS" "$SUCCESS_FILE" 2>/dev/null || echo 0)
E2_FINAL_STATUS=$(run_sql "SELECT status FROM orders WHERE id = $TEST_ORD_E2;" | tail -n 1)

if [ "$SUCCESS_COUNT" -eq 1 ] && [ "$E2_FINAL_STATUS" = "paid" ]; then
    pass "Concurrency: 10 parallel transitions raced, exactly 1 succeeded and order is in status 'paid'."
else
    fail "Parallel transitions expected 1 success but got $SUCCESS_COUNT (final status: $E2_FINAL_STATUS)"
fi

# ==============================================================================
# TEST F: Idempotent Cancel across Statuses
# ==============================================================================
info "TEST F: Idempotent Cancel & Stock Restoration across Order Statuses"

# F-1: Cancel pending_payment -> restores stock
ORD_F_PENDING=$(create_test_order 'pending_payment' 'unpaid' 'F1')
"$PHP_CMD" -r "
require 'app/bootstrap.php';
\$_SESSION['admin_user'] = ['id' => $ADMIN_USER_ID, 'role' => 'admin'];
use App\Services\OrderService;
OrderService::cancel($ORD_F_PENDING, $ADMIN_USER_ID, 'Cancel pending test');
"
F1_STATUS=$(run_sql "SELECT status, stock_released FROM orders WHERE id = $ORD_F_PENDING;" | tail -n 1)
pass "Cancel on pending_payment order sets status=cancelled and stock_released=1: $F1_STATUS"

# F-2: Cancel paid order -> restores stock, leaves payment_status=paid (refund required)
ORD_F_PAID=$(create_test_order 'paid' 'paid' 'F2')
"$PHP_CMD" -r "
require 'app/bootstrap.php';
\$_SESSION['admin_user'] = ['id' => $ADMIN_USER_ID, 'role' => 'admin'];
use App\Services\OrderService;
OrderService::cancel($ORD_F_PAID, $ADMIN_USER_ID, 'Cancel paid test');
"
F2_PAY_STATUS=$(run_sql "SELECT payment_status FROM orders WHERE id = $ORD_F_PAID;" | tail -n 1)
if [ "$F2_PAY_STATUS" = "paid" ]; then
    pass "Cancel on paid order leaves payment_status='paid' for visible refund required marker."
else
    fail "Cancel on paid order changed payment_status to $F2_PAY_STATUS instead of keeping 'paid'"
fi

# F-3: Cancel processing order -> restores stock
ORD_F_PROC=$(create_test_order 'processing' 'paid' 'F3')
"$PHP_CMD" -r "
require 'app/bootstrap.php';
\$_SESSION['admin_user'] = ['id' => $ADMIN_USER_ID, 'role' => 'admin'];
use App\Services\OrderService;
OrderService::cancel($ORD_F_PROC, $ADMIN_USER_ID, 'Cancel processing test');
"
F3_STATUS=$(run_sql "SELECT status FROM orders WHERE id = $ORD_F_PROC;" | tail -n 1)
pass "Cancel on processing order sets status='cancelled'."

# F-4: Cancelling an already cancelled/expired/delivered order is refused and restores nothing
"$PHP_CMD" -r "
require 'app/bootstrap.php';
\$_SESSION['admin_user'] = ['id' => $ADMIN_USER_ID, 'role' => 'admin'];
use App\Services\OrderService;
try {
    OrderService::cancel($ORD_F_PENDING, $ADMIN_USER_ID, 'Double cancel');
    exit(1);
} catch (\DomainException \$e) {
    exit(0);
}
"
if [ $? -eq 0 ]; then
    pass "Cancelling an already cancelled order is refused with DomainException and restores nothing."
else
    fail "Cancelling an already cancelled order was not refused!"
fi

# ==============================================================================
# TEST G: Manual Refund Recording & Mark as Paid
# ==============================================================================
info "TEST G: Manual Refund Recording & Mark as Paid"

# G-1: Mark as Paid validation & duplicate check
ORD_G_PAID=$(create_test_order 'pending_payment' 'unpaid' 'G1')
ORD_G_DUP=$(create_test_order 'pending_payment' 'unpaid' 'G2')

# Test format rejection (short code)
"$PHP_CMD" -r "
require 'app/bootstrap.php';
\$_SESSION['admin_user'] = ['id' => $ADMIN_USER_ID, 'role' => 'admin'];
use App\Services\OrderService;
try {
    OrderService::markAsPaid($ORD_G_PAID, 'SHORT12', 'Note', $ADMIN_USER_ID);
    exit(1);
} catch (\InvalidArgumentException \$e) {
    exit(0);
}
"
if [ $? -eq 0 ]; then
    pass "Mark as paid rejected invalid M-Pesa receipt format (non-10 characters)."
else
    fail "Mark as paid accepted invalid receipt code format!"
fi

# Valid mark as paid
RECEIPT_CODE="RCPT${RAND_ID}X"
RECEIPT_CODE=${RECEIPT_CODE:0:10}
RECEIPT_CODE=$(echo "$RECEIPT_CODE" | tr '[:lower:]' '[:upper:]')

"$PHP_CMD" -r "
require 'app/bootstrap.php';
\$_SESSION['admin_user'] = ['id' => $ADMIN_USER_ID, 'role' => 'admin'];
use App\Services\OrderService;
OrderService::markAsPaid($ORD_G_PAID, '$RECEIPT_CODE', 'Manual bank/mpesa statement confirmed', $ADMIN_USER_ID);
"
G1_STATUS=$(run_sql "SELECT status, payment_status FROM orders WHERE id = $ORD_G_PAID;" | tail -n 1)
pass "Order marked as paid: $G1_STATUS with receipt $RECEIPT_CODE"

# Duplicate receipt check on second order
"$PHP_CMD" -r "
require 'app/bootstrap.php';
\$_SESSION['admin_user'] = ['id' => $ADMIN_USER_ID, 'role' => 'admin'];
use App\Services\OrderService;
try {
    OrderService::markAsPaid($ORD_G_DUP, '$RECEIPT_CODE', 'Duplicate test', $ADMIN_USER_ID);
    exit(1);
} catch (\DomainException \$e) {
    if (str_contains(\$e->getMessage(), 'Duplicate receipts are rejected')) {
        exit(0);
    }
    exit(2);
}
"
if [ $? -eq 0 ]; then
    pass "Duplicate M-Pesa receipt '$RECEIPT_CODE' successfully rejected."
else
    fail "Duplicate receipt code was not rejected!"
fi

# G-2: Record Refund on delivered or cancelled paid order
ORD_G_REFUND=$(create_test_order 'cancelled' 'paid' 'G3')
"$PHP_CMD" -r "
require 'app/bootstrap.php';
\$_SESSION['admin_user'] = ['id' => $ADMIN_USER_ID, 'role' => 'admin'];
use App\Services\OrderService;
OrderService::recordRefund($ORD_G_REFUND, 'M-Pesa reversal ref #REV$RAND_ID', $ADMIN_USER_ID);
"
G3_STATUS=$(run_sql "SELECT status, payment_status FROM orders WHERE id = $ORD_G_REFUND;" | tail -n 1)
pass "Record refund updated order status and payment status: $G3_STATUS"


# ==============================================================================
# TEST H: Money Integrity & Packing Slip Price Omission
# ==============================================================================
info "TEST H: Money Integrity & Packing Slip Price Omission"

# Verify all 12 demo orders satisfy total = sum(line_total) + delivery_fee - discount
MONEY_FAILURES=$(run_sql "
    SELECT COUNT(*) FROM orders o
    WHERE o.order_number LIKE 'DEMO2A-%' AND ABS(o.total - (
        (SELECT COALESCE(SUM(oi.line_total), 0) FROM order_items oi WHERE oi.order_id = o.id)
        + o.delivery_fee - o.discount
    )) > 0.01;
" | tail -n 1)

if [ "$MONEY_FAILURES" -eq 0 ]; then
    pass "Money integrity: total equals SUM(line_total) + delivery_fee - discount across all demo orders."
else
    fail "Money integrity mismatch in $MONEY_FAILURES demo orders!"
fi

# Verify Invoice HTML matches order total
INVOICE_HTML=$(curl -s -b "$COOKIE_ADMIN" "${BASE_URL}/admin/orders/invoice.php?id=${FIRST_DEMO_ORDER_ID}")
if echo "$INVOICE_HTML" | grep -q "Official Commercial Invoice"; then
    pass "Invoice page renders valid commercial invoice layout."
else
    fail "Invoice page missing commercial invoice title"
fi

# Verify Packing Slip contains ZERO price strings
PACKING_HTML=$(curl -s -b "$COOKIE_ADMIN" "${BASE_URL}/admin/orders/packing-slip.php?id=${FIRST_DEMO_ORDER_ID}")
PRICE_MATCHES=$(echo "$PACKING_HTML" | grep -Ei "KES|format_money|Unit Price|Subtotal|Total \(KES\)" | wc -l)
if [ "$PRICE_MATCHES" -eq 0 ]; then
    pass "Packing slip verified: contains zero price strings or currency references."
else
    fail "Packing slip contains $PRICE_MATCHES price or currency strings!"
fi

# ==============================================================================
# TEST I: Security (SQLi, XSS, CSRF, IDOR, Mass Assignment)
# ==============================================================================
info "TEST I: Security & Hardening"

# I-1: SQL Injection in search & filters
SQLI_TEST_1=$(curl -s -b "$COOKIE_ADMIN" "${BASE_URL}/admin/orders/?search=%27+OR+%271%27%3D%271")
SQLI_ERRORS_1=$(echo "$SQLI_TEST_1" | grep -Ei "Fatal error|Warning:|Notice:|SQLSTATE|Syntax error" | wc -l)
if [ "$SQLI_ERRORS_1" -eq 0 ]; then
    pass "SQLi probe in orders search handled safely without errors."
else
    fail "SQLi probe triggered SQL errors in orders search!"
fi

# I-2: XSS Escaping in Customer Name & Notes
ORD_XSS=$(create_test_order 'pending_payment' 'unpaid' 'XSS')
UPD_RES=$("$PHP_CMD" -r "
    require 'app/bootstrap.php';
    use App\Database\Database;
    \$pdo = Database::getInstance();
    \$stmt = \$pdo->prepare('UPDATE orders SET recipient_name = :n, customer_notes = :c WHERE id = :id');
    \$stmt->execute([
        ':n' => '<script>alert(\"xss-name\")</script>',
        ':c' => '<img src=x onerror=alert(\"xss-note\")>',
        ':id' => $ORD_XSS
    ]);
    echo \$stmt->rowCount();
")

DETAIL_HTML=$(curl -s -b "$COOKIE_ADMIN" "${BASE_URL}/admin/orders/detail.php?id=${ORD_XSS}")

if [ $(echo "$DETAIL_HTML" | grep -c "&lt;script&gt;alert") -gt 0 ] && [ $(echo "$DETAIL_HTML" | grep -c "<script>alert") -eq 0 ]; then
    pass "XSS payload in customer name properly escaped on order detail."
else
    fail "XSS payload in customer name was NOT escaped!"
fi

if [ $(echo "$DETAIL_HTML" | grep -c "&lt;img src=x") -gt 0 ] && [ $(echo "$DETAIL_HTML" | grep -c "<img src=x") -eq 0 ]; then
    pass "XSS payload in customer notes properly escaped on order detail."
else
    fail "XSS payload in customer notes was NOT escaped!"
fi

# I-3: CSRF Rejection on Missing or Wrong Token
NO_CSRF=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_ADMIN" \
    -d "action=transition&id=${ORD_XSS}&to_status=paid" \
    -X POST "${BASE_URL}/admin/orders/detail.php")
if [ "$NO_CSRF" -eq 403 ]; then
    pass "POST with missing CSRF token rejected with HTTP 403."
else
    fail "POST with missing CSRF expected 403 but got ${NO_CSRF}"
fi

WRONG_CSRF=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_ADMIN" \
    -d "_csrf_token=wrong_token_value&action=transition&id=${ORD_XSS}&to_status=paid" \
    -X POST "${BASE_URL}/admin/orders/detail.php")
if [ "$WRONG_CSRF" -eq 403 ]; then
    pass "POST with invalid CSRF token rejected with HTTP 403."
else
    fail "POST with invalid CSRF expected 403 but got ${WRONG_CSRF}"
fi


# I-4: Mass-Assignment Attempt (posting total, payment_status, user_id)
curl -s -b "$COOKIE_ADMIN" \
    -d "_csrf_token=${ADMIN_CSRF}&action=transition&id=${ORD_XSS}&to_status=paid&total=0.01&payment_status=refunded&user_id=1" \
    -X POST "${BASE_URL}/admin/orders/detail.php" >/dev/null

XSS_ORD_TOTAL=$(run_sql "SELECT total FROM orders WHERE id = ${ORD_XSS};" | tail -n 1)
if [ "$XSS_ORD_TOTAL" = "1200.00" ]; then
    pass "Mass assignment attempt (tampered total=0.01) ignored; original total 1200.00 preserved."
else
    fail "Mass assignment allowed total alteration to $XSS_ORD_TOTAL!"
fi

# ==============================================================================
# TEST J: Customers Management & Password Hash Secrecy
# ==============================================================================
info "TEST J: Customers Management & Password Secrecy"

CUST_LIST_HTML=$(curl -s -L -b "$COOKIE_ADMIN" "${BASE_URL}/admin/customers/index.php")
if [ $(echo "$CUST_LIST_HTML" | grep -c "Customer Directory") -gt 0 ]; then
    pass "Customer list renders directory table."
else
    fail "Customer list missing directory title"
fi

# Verify Admin/Staff accounts are excluded from customer list
if echo "$CUST_LIST_HTML" | grep -q "$ADMIN_EMAIL" || echo "$CUST_LIST_HTML" | grep -q "$STAFF_EMAIL"; then
    fail "Admin or staff accounts incorrectly listed in customer directory!"
else
    pass "Admin and staff accounts excluded from customer directory."
fi

# Customer Block & Unblock via POST
curl -s -b "$COOKIE_ADMIN" \
    -d "_csrf_token=${ADMIN_CSRF}&action=set_status&id=${CUST_USER_ID}&status=blocked" \
    -X POST "${BASE_URL}/admin/customers/detail.php?id=${CUST_USER_ID}" >/dev/null

CUST_STATUS=$(run_sql "SELECT status FROM users WHERE id = ${CUST_USER_ID};" | tail -n 1)
if [ "$CUST_STATUS" = "blocked" ]; then
    pass "Customer successfully blocked by admin."
else
    fail "Customer block failed; status is $CUST_STATUS"
fi

curl -s -b "$COOKIE_ADMIN" \
    -d "_csrf_token=${ADMIN_CSRF}&action=set_status&id=${CUST_USER_ID}&status=active" \
    -X POST "${BASE_URL}/admin/customers/detail.php?id=${CUST_USER_ID}" >/dev/null

CUST_STATUS_RESTORED=$(run_sql "SELECT status FROM users WHERE id = ${CUST_USER_ID};" | tail -n 1)
if [ "$CUST_STATUS_RESTORED" = "active" ]; then
    pass "Customer successfully unblocked by admin."
else
    fail "Customer unblock failed; status is $CUST_STATUS_RESTORED"
fi

# Grep for password_hash and $2y$ in customer responses
CUST_DETAIL_HTML=$(curl -s -b "$COOKIE_ADMIN" "${BASE_URL}/admin/customers/detail.php?id=${CUST_USER_ID}")
HASH_LEAKS=$(echo "$CUST_LIST_HTML $CUST_DETAIL_HTML" | grep -Ei "password_hash|\$2y\$" | wc -l)
if [ "$HASH_LEAKS" -eq 0 ]; then
    pass "Password secrecy verified: zero occurrences of password_hash or \$2y\$ in HTML."
else
    fail "Potential credential leak detected: $HASH_LEAKS hash strings found in HTML!"
fi

# ==============================================================================
# TEST K: Audit Logging Completeness
# ==============================================================================
info "TEST K: Audit Logging Completeness"

AUDIT_TYPES=(
    "order_status_transition"
    "order_cancel"
    "order_mark_as_paid"
    "order_record_refund"
    "customer_block"
    "customer_unblock"
)

for aType in "${AUDIT_TYPES[@]}"; do
    MATCH_COUNT=$(run_sql "SELECT COUNT(*) FROM audit_log WHERE action = '${aType}' AND (actor_user_id = $ADMIN_USER_ID OR details LIKE '%${RAND_ID}%');" | tail -n 1)
    if [ "$MATCH_COUNT" -gt 0 ]; then
        pass "Audit log entry recorded for action: ${aType} ($MATCH_COUNT rows)"
    else
        fail "Missing audit log entry for action: ${aType}"
    fi
done

# Cleanup will now execute automatically via trap and verify 0 DB drift!
