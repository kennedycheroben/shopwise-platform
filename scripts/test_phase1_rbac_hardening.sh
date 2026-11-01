#!/usr/bin/env bash
# ==============================================================================
# ShopWise Kenya - Phase 1 Admin Authorization Security Hardening Test Suite
# Strictly executes against isolated disposable test database 'shopwise_test'.
# NEVER touches 'shopwise_db' or production.
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$ROOT_DIR"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

# ------------------------------------------------------------------------------
# SAFETY CHECK: Must be shopwise_test
# ------------------------------------------------------------------------------
DB_NAME="${DB_NAME:-shopwise_test}"
if [ "$DB_NAME" = "shopwise_db" ] || [[ ! "$DB_NAME" =~ _test$ ]]; then
    echo -e "${RED}FATAL SECURITY ERROR: This test script only runs against a database ending with '_test'. Got '$DB_NAME'.${NC}" >&2
    exit 1
fi

MYSQL_CMD="/opt/lampp/bin/mysql"
if [ ! -x "$MYSQL_CMD" ]; then
    MYSQL_CMD="mysql"
fi

DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"

run_sql() {
    local sql="$1"
    if [ -n "$DB_PASS" ]; then
        "$MYSQL_CMD" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -s -e "$sql" 2>/dev/null
    else
        "$MYSQL_CMD" -u "$DB_USER" "$DB_NAME" -N -s -e "$sql" 2>/dev/null
    fi
}

BASE_URL="${BASE_URL:-http://127.0.0.1/shopwise-platform/public}"
TMP_DIR=$(mktemp -d)
trap 'rm -rf "$TMP_DIR"' EXIT

COOKIE_ADMIN="$TMP_DIR/admin.cookies"
COOKIE_STAFF="$TMP_DIR/staff.cookies"
COOKIE_CUST="$TMP_DIR/customer.cookies"
HEADER_FILE="$TMP_DIR/headers.txt"
BODY_FILE="$TMP_DIR/body.txt"

TOTAL_PASSED=0
TOTAL_FAILED=0

pass() {
    echo -e "  [${GREEN}PASS${NC}] $1"
    TOTAL_PASSED=$((TOTAL_PASSED + 1))
}

fail() {
    echo -e "  [${RED}FAIL${NC}] $1"
    TOTAL_FAILED=$((TOTAL_FAILED + 1))
}

info() {
    echo -e "\n${BOLD}${CYAN}=== $1 ===${NC}"
}

echo -e "${BOLD}${CYAN}==============================================================================${NC}"
echo -e "${BOLD}${CYAN}   SHOPWISE PLATFORM — PHASE 1 RBAC AUTHORIZATION SECURITY HARDENING TEST     ${NC}"
echo -e "${BOLD}${CYAN}==============================================================================${NC}"
echo "Target Base URL: $BASE_URL"
echo "Target Database: $DB_NAME"
echo ""

# ------------------------------------------------------------------------------
# STEP 0: CREATE PROVISIONED TEST ACCOUNTS
# ------------------------------------------------------------------------------
info "Setup: Provisioning Isolated Test Users in $DB_NAME"

RAND_ID=$(date +%s)_$RANDOM
ADMIN_EMAIL="rbac_admin_${RAND_ID}@test.local"
STAFF_EMAIL="rbac_staff_${RAND_ID}@test.local"
CUST_EMAIL="rbac_cust_${RAND_ID}@test.local"
TEST_PASSWORD="TestPassword123!"
PASSWORD_HASH=$(php -r "echo password_hash('$TEST_PASSWORD', PASSWORD_DEFAULT);")

run_sql "INSERT INTO users (role, first_name, last_name, email, password_hash, status, created_at, email_verified_at)
VALUES
('admin', 'Admin', 'Auditor', '$ADMIN_EMAIL', '$PASSWORD_HASH', 'active', NOW(), NOW()),
('staff', 'Staff', 'Operator', '$STAFF_EMAIL', '$PASSWORD_HASH', 'active', NOW(), NOW()),
('customer', 'Customer', 'Shopper', '$CUST_EMAIL', '$PASSWORD_HASH', 'active', NOW(), NOW());"

# Authenticate users and store session cookies
login_user() {
    local email="$1"
    local password="$2"
    local cookie_file="$3"

    curl -s -c "$cookie_file" "$BASE_URL/admin/login.php" > "$BODY_FILE"
    local csrf_token
    csrf_token=$(grep -oP 'name="_csrf_token"\s+value="\K[^"]+' "$BODY_FILE" | head -n 1 || echo "")

    curl -s -b "$cookie_file" -c "$cookie_file" -D "$HEADER_FILE" \
        -d "_csrf_token=$csrf_token" \
        -d "email=$email" \
        -d "password=$password" \
        -X POST "$BASE_URL/admin/login.php" > /dev/null

    local http_code
    http_code=$(grep -i "^HTTP/" "$HEADER_FILE" | head -n 1 | awk '{print $2}')
    if [ "$http_code" -ne 302 ]; then
        echo -e "${RED}FATAL: Failed to authenticate user $email (HTTP $http_code)${NC}" >&2
        exit 1
    fi
}

login_user "$ADMIN_EMAIL" "$TEST_PASSWORD" "$COOKIE_ADMIN"
login_user "$STAFF_EMAIL" "$TEST_PASSWORD" "$COOKIE_STAFF"

# Customer login on storefront
curl -s -c "$COOKIE_CUST" "$BASE_URL/login" > "$BODY_FILE"
CUST_CSRF=$(grep -oP 'name="_csrf_token"\s+value="\K[^"]+' "$BODY_FILE" | head -n 1 || echo "")
curl -s -b "$COOKIE_CUST" -c "$COOKIE_CUST" -D "$HEADER_FILE" \
    -d "_csrf_token=$CUST_CSRF" \
    -d "email=$CUST_EMAIL" \
    -d "password=$TEST_PASSWORD" \
    -X POST "$BASE_URL/login" > /dev/null

# Extract CSRF tokens for subsequent authenticated POST operations
curl -s -b "$COOKIE_ADMIN" "$BASE_URL/admin/orders/index.php" > "$BODY_FILE"
ADMIN_CSRF=$(grep -oP 'name="_csrf_token"\s+value="\K[^"]+' "$BODY_FILE" | head -n 1 || echo "")

curl -s -b "$COOKIE_STAFF" "$BASE_URL/admin/orders/index.php" > "$BODY_FILE"
STAFF_CSRF=$(grep -oP 'name="_csrf_token"\s+value="\K[^"]+' "$BODY_FILE" | head -n 1 || echo "")

FIRST_ORDER_ID=$(run_sql "SELECT id FROM orders ORDER BY id ASC LIMIT 1;" | tail -n 1)
FIRST_PRODUCT_ID=$(run_sql "SELECT id FROM products WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1;" | tail -n 1)
FIRST_VARIANT_ID=$(run_sql "SELECT id FROM product_variants WHERE product_id = $FIRST_PRODUCT_ID ORDER BY id ASC LIMIT 1;" | tail -n 1)
FIRST_REVIEW_ID=$(run_sql "SELECT id FROM reviews ORDER BY id ASC LIMIT 1;" | tail -n 1)

pass "Test users authenticated (Admin: $ADMIN_EMAIL, Staff: $STAFF_EMAIL)"

# ------------------------------------------------------------------------------
# TEST 1: UNAUTHENTICATED REQUESTS (GUESTS) ARE REDIRECTED (HTTP 302)
# ------------------------------------------------------------------------------
info "Test 1: Unauthenticated Requests Denied (HTTP 302 to login.php)"

GUEST_ROUTES=(
    "admin/"
    "admin/index.php"
    "admin/api/dashboard.php"
    "admin/api/revenue-chart.php"
    "admin/orders/index.php"
    "admin/products/index.php"
    "admin/staff/index.php"
)

for route in "${GUEST_ROUTES[@]}"; do
    code=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/$route")
    if [ "$code" -eq 302 ]; then
        pass "Guest accessing $route receives HTTP 302 redirect"
    else
        fail "Guest accessing $route expected HTTP 302, got $code"
    fi
done

# ------------------------------------------------------------------------------
# TEST 2: EXECUTIVE DASHBOARD ACCESS RESTRICTION (STAFF DENIED HTTP 403)
# ------------------------------------------------------------------------------
info "Test 2: Executive Dashboard Access Denied to Ordinary Staff (HTTP 403)"

DASHBOARD_ROUTES=(
    "admin/"
    "admin/index.php"
    "admin/?format=json"
    "admin/api/dashboard.php"
    "admin/api/revenue-chart.php"
    "admin/api/revenue-chart.php?period=weekly"
    "admin/api/revenue-chart.php?period=daily"
)

for route in "${DASHBOARD_ROUTES[@]}"; do
    curl -s -D "$HEADER_FILE" -b "$COOKIE_STAFF" "$BASE_URL/$route" > "$BODY_FILE"
    code=$(grep -i "^HTTP/" "$HEADER_FILE" | tail -n 1 | awk '{print $2}')
    
    if [ "$code" -eq 403 ]; then
        pass "Staff denied executive endpoint $route (HTTP 403)"
    else
        fail "Staff expected HTTP 403 on $route, got $code"
    fi

    # Verify no financial payload was leaked
    if grep -q "revenue_30" "$BODY_FILE" || grep -q "chart_monthly" "$BODY_FILE"; then
        fail "CRITICAL LEAK: Financial revenue keys found in staff response for $route!"
    else
        pass "No financial data leaked in response for $route"
    fi
done

# ------------------------------------------------------------------------------
# TEST 3: AUTHORIZED ADMINISTRATOR RETAINS FULL EXECUTIVE ACCESS (HTTP 200)
# ------------------------------------------------------------------------------
info "Test 3: Authorized Administrator Retains Executive Dashboard Access (HTTP 200)"

for route in "${DASHBOARD_ROUTES[@]}"; do
    curl -s -D "$HEADER_FILE" -b "$COOKIE_ADMIN" "$BASE_URL/$route" > "$BODY_FILE"
    code=$(grep -i "^HTTP/" "$HEADER_FILE" | tail -n 1 | awk '{print $2}')

    if [ "$code" -eq 200 ]; then
        pass "Admin successfully accesses executive endpoint $route (HTTP 200)"
    else
        fail "Admin expected HTTP 200 on $route, got $code"
    fi
done

# Verify Admin JSON payload contains valid financial data
curl -s -b "$COOKIE_ADMIN" "$BASE_URL/admin/api/dashboard.php" > "$BODY_FILE"
if grep -q "revenue_30" "$BODY_FILE" && grep -q "orders_30" "$BODY_FILE"; then
    pass "Admin JSON API returns complete executive dataset"
else
    fail "Admin JSON API missing expected executive keys"
fi

# ------------------------------------------------------------------------------
# TEST 4: UNAUTHORIZED DIRECT GET REQUESTS (STAFF DENIED WITH HTTP 403)
# ------------------------------------------------------------------------------
info "Test 4: Protected Modules Denied to Staff via Direct GET (HTTP 403)"

ADMIN_ONLY_GET_ROUTES=(
    "admin/staff/index.php"
    "admin/settings/index.php"
    "admin/audit-log/index.php"
    "admin/coupons/index.php"
    "admin/delivery/zones.php"
    "admin/delivery/pricing.php"
    "admin/api/admin-only.php"
)

for route in "${ADMIN_ONLY_GET_ROUTES[@]}"; do
    code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_STAFF" "$BASE_URL/$route")
    if [ "$code" -eq 403 ]; then
        pass "Staff denied direct GET on $route (HTTP 403)"
    else
        fail "Staff expected HTTP 403 on $route, got $code"
    fi
done

# ------------------------------------------------------------------------------
# TEST 5: UNAUTHORIZED POST REQUESTS DENIED (HTTP 403)
# ------------------------------------------------------------------------------
info "Test 5: Sensitive POST Operations Denied to Staff (HTTP 403)"

# 1. Staff cannot manually mark order as paid
code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_STAFF" \
    -d "_csrf_token=$STAFF_CSRF" \
    -d "action=mark_as_paid" \
    -d "id=$FIRST_ORDER_ID" \
    -d "mpesa_receipt=QAA1234567" \
    -d "note=Unauthorized" \
    -X POST "$BASE_URL/admin/orders/detail.php")
if [ "$code" -eq 403 ]; then
    pass "Staff forbidden from mark_as_paid (HTTP 403)"
else
    fail "Staff expected HTTP 403 for mark_as_paid, got $code"
fi

# 2. Staff cannot record refund
code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_STAFF" \
    -d "_csrf_token=$STAFF_CSRF" \
    -d "action=record_refund" \
    -d "id=$FIRST_ORDER_ID" \
    -d "reference_note=Unauthorized" \
    -X POST "$BASE_URL/admin/orders/detail.php")
if [ "$code" -eq 403 ]; then
    pass "Staff forbidden from record_refund (HTTP 403)"
else
    fail "Staff expected HTTP 403 for record_refund, got $code"
fi

# 3. Staff cannot delete product review
code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_STAFF" \
    -d "_csrf_token=$STAFF_CSRF" \
    -d "action=delete" \
    -d "id=$FIRST_REVIEW_ID" \
    -X POST "$BASE_URL/admin/reviews/index.php")
if [ "$code" -eq 403 ]; then
    pass "Staff forbidden from review deletion (HTTP 403)"
else
    fail "Staff expected HTTP 403 for review deletion, got $code"
fi

# 4. Staff cannot delete product variant
code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_STAFF" \
    -d "_csrf_token=$STAFF_CSRF" \
    -d "action=variant_delete" \
    -d "product_id=$FIRST_PRODUCT_ID" \
    -d "variant_id=$FIRST_VARIANT_ID" \
    -X POST "$BASE_URL/admin/products/edit.php?id=$FIRST_PRODUCT_ID")
if [ "$code" -eq 403 ]; then
    pass "Staff forbidden from variant deletion (HTTP 403)"
else
    fail "Staff expected HTTP 403 for variant deletion, got $code"
fi

# 5. Staff cannot delete product image
code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_STAFF" \
    -d "_csrf_token=$STAFF_CSRF" \
    -d "action=delete_image" \
    -d "product_id=$FIRST_PRODUCT_ID" \
    -d "image_id=9999" \
    -X POST "$BASE_URL/admin/products/edit.php?id=$FIRST_PRODUCT_ID")
if [ "$code" -eq 403 ]; then
    pass "Staff forbidden from image deletion (HTTP 403)"
else
    fail "Staff expected HTTP 403 for image deletion, got $code"
fi

# 6. Staff cannot delete product (soft_delete)
code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_STAFF" \
    -d "_csrf_token=$STAFF_CSRF" \
    -d "action=soft_delete" \
    -d "id=$FIRST_PRODUCT_ID" \
    -X POST "$BASE_URL/admin/products/index.php")
if [ "$code" -eq 403 ]; then
    pass "Staff forbidden from product soft_delete (HTTP 403)"
else
    fail "Staff expected HTTP 403 for product soft_delete, got $code"
fi

# 7. Staff cannot delete category
code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_STAFF" \
    -d "_csrf_token=$STAFF_CSRF" \
    -d "action=delete" \
    -d "id=1" \
    -X POST "$BASE_URL/admin/categories/index.php")
if [ "$code" -eq 403 ]; then
    pass "Staff forbidden from category deletion (HTTP 403)"
else
    fail "Staff expected HTTP 403 for category deletion, got $code"
fi

# 8. Staff cannot block customer
code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_STAFF" \
    -d "_csrf_token=$STAFF_CSRF" \
    -d "action=set_status" \
    -d "id=1" \
    -d "status=blocked" \
    -X POST "$BASE_URL/admin/customers/index.php")
if [ "$code" -eq 403 ]; then
    pass "Staff forbidden from blocking customer (HTTP 403)"
else
    fail "Staff expected HTTP 403 for customer set_status, got $code"
fi

# ------------------------------------------------------------------------------
# TEST 6: LEGITIMATE STAFF OPERATIONS REMAIN FUNCTIONAL (HTTP 200 / 302)
# ------------------------------------------------------------------------------
info "Test 6: Legitimate Staff Operations Remain Functional"

STAFF_PERMITTED_GET_ROUTES=(
    "admin/orders/index.php"
    "admin/orders/detail.php?id=$FIRST_ORDER_ID"
    "admin/orders/invoice.php?id=$FIRST_ORDER_ID"
    "admin/orders/packing-slip.php?id=$FIRST_ORDER_ID"
    "admin/products/index.php"
    "admin/products/create.php"
    "admin/products/edit.php?id=$FIRST_PRODUCT_ID"
    "admin/categories/index.php"
    "admin/inventory/index.php"
    "admin/inventory/movements.php"
    "admin/customers/index.php"
    "admin/customers/detail.php?id=1"
    "admin/messages/index.php"
    "admin/reviews/index.php"
    "admin/search.php?q=denim"
    "admin/profile.php"
)

for route in "${STAFF_PERMITTED_GET_ROUTES[@]}"; do
    curl -s -D "$HEADER_FILE" -b "$COOKIE_STAFF" "$BASE_URL/$route" > "$BODY_FILE"
    code=$(grep -i "^HTTP/" "$HEADER_FILE" | tail -n 1 | awk '{print $2}')
    errors=$( (grep -Ei "Fatal error|Warning:|Notice:|Deprecated:|Exception" "$BODY_FILE" || true) | wc -l )

    if [ "$code" -eq 200 ] && [ "$errors" -eq 0 ]; then
        pass "Staff successfully accesses legitimate workspace $route (HTTP 200, 0 errors)"
    else
        fail "Staff expected HTTP 200 on $route, got $code (errors: $errors)"
    fi
done

# Staff can append internal note to an order
code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_STAFF" \
    -d "_csrf_token=$STAFF_CSRF" \
    -d "action=add_note" \
    -d "id=$FIRST_ORDER_ID" \
    -d "internal_note=Verified package dimensions by staff" \
    -X POST "$BASE_URL/admin/orders/detail.php")
if [ "$code" -eq 302 ]; then
    pass "Staff successfully adds internal order note (HTTP 302 redirect on success)"
else
    fail "Staff expected HTTP 302 on add_note, got $code"
fi

# Staff can perform inventory quick stock adjustment
code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_STAFF" \
    -d "_csrf_token=$STAFF_CSRF" \
    -d "variant_id=$FIRST_VARIANT_ID" \
    -d "mode=delta" \
    -d "value=1" \
    -d "reason=restock" \
    -d "note=Standard replenishment" \
    -X POST "$BASE_URL/admin/inventory/index.php")
if [ "$code" -eq 302 ]; then
    pass "Staff successfully adjusts inventory stock (HTTP 302 redirect on success)"
else
    fail "Staff expected HTTP 302 on stock adjustment, got $code"
fi

# Staff can moderate a review
code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_STAFF" \
    -d "_csrf_token=$STAFF_CSRF" \
    -d "action=moderate" \
    -d "id=$FIRST_REVIEW_ID" \
    -d "status=approved" \
    -X POST "$BASE_URL/admin/reviews/index.php")
if [ "$code" -eq 302 ]; then
    pass "Staff successfully moderates customer review (HTTP 302 redirect on success)"
else
    fail "Staff expected HTTP 302 on review moderation, got $code"
fi

# ------------------------------------------------------------------------------
# TEST 7: CSRF PROTECTION REMAINS STRICTLY ENFORCED
# ------------------------------------------------------------------------------
info "Test 7: CSRF Protection Verification"

# Admin with invalid CSRF token
code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_ADMIN" \
    -d "_csrf_token=INVALID_FORGED_TOKEN" \
    -d "action=add_note" \
    -d "id=$FIRST_ORDER_ID" \
    -d "internal_note=Forged" \
    -X POST "$BASE_URL/admin/orders/detail.php")
if [ "$code" -eq 403 ]; then
    pass "Forged CSRF token rejected with HTTP 403 Forbidden"
else
    fail "Forged CSRF token expected HTTP 403, got $code"
fi

# ------------------------------------------------------------------------------
# TEST 8: SERVICE LAYER PERMISSION HARMONIZATION
# ------------------------------------------------------------------------------
info "Test 8: PHP Service Layer Permission & Delegation Verification"

SERVICE_TEST_OUTPUT=$(SHOPWISE_CONFIG="testing" php -r "
require 'app/bootstrap.php';
use App\Services\Permissions;
use App\Services\AdminAuthService;

\$checks = [];

# AdminAuthService::can delegation when no session:
\$checks[] = (AdminAuthService::can('dashboard') === false) ? 'P1' : 'F1';
\$checks[] = (AdminAuthService::can('orders') === false) ? 'P2' : 'F2';

// AdminAuthService::can with simulated staff session:
\$_SESSION['admin_user'] = ['id' => 1, 'role' => 'staff'];
\$checks[] = (AdminAuthService::can('dashboard') === false) ? 'P1b' : 'F1b';
\$checks[] = (AdminAuthService::can('orders') === true) ? 'P2b' : 'F2b';

// AdminAuthService::can with simulated admin session:
\$_SESSION['admin_user'] = ['id' => 2, 'role' => 'admin'];
\$checks[] = (AdminAuthService::can('dashboard') === true) ? 'P1c' : 'F1c';
\$checks[] = (AdminAuthService::can('orders') === true) ? 'P2c' : 'F2c';
unset(\$_SESSION['admin_user']);
\$checks[] = (Permissions::can('orders.mark_as_paid', 'staff') === false) ? 'P3' : 'F3';
\$checks[] = (Permissions::can('orders.mark_as_paid', 'admin') === true) ? 'P4' : 'F4';
\$checks[] = (Permissions::can('reviews.delete', 'staff') === false) ? 'P5' : 'F5';
\$checks[] = (Permissions::can('reviews.delete', 'admin') === true) ? 'P6' : 'F6';
\$checks[] = (Permissions::can('products.delete', 'staff') === false) ? 'P7' : 'F7';
\$checks[] = (Permissions::can('products.delete', 'admin') === true) ? 'P8' : 'F8';
\$checks[] = (Permissions::can('dashboard.view', 'staff') === false) ? 'P9' : 'F9';
\$checks[] = (Permissions::can('dashboard.view', 'admin') === true) ? 'P10' : 'F10';

echo implode(' ', \$checks);
")

for res in $SERVICE_TEST_OUTPUT; do
    if [[ "$res" =~ ^P ]]; then
        pass "Service authorization check $res passed"
    else
        fail "Service authorization check $res failed"
    fi
done

# ------------------------------------------------------------------------------
# TEST 9: CUSTOMER ROLE COMPLETELY DENIED ADMIN ACCESS
# ------------------------------------------------------------------------------
info "Test 9: Storefront Customer Role Completely Denied Admin Access"

for route in "${DASHBOARD_ROUTES[@]}" "${STAFF_PERMITTED_GET_ROUTES[@]}"; do
    code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_CUST" "$BASE_URL/$route")
    if [ "$code" -eq 302 ] || [ "$code" -eq 403 ]; then
        pass "Customer denied access to $route (HTTP $code)"
    else
        fail "Customer expected 302/403 on $route, got $code"
    fi
done

# ------------------------------------------------------------------------------
# FINAL REPORT
# ------------------------------------------------------------------------------
echo ""
echo -e "${BOLD}${CYAN}==============================================================================${NC}"
echo -e "${BOLD}PHASE 1 RBAC HARDENING TEST SUMMARY: ${GREEN}$TOTAL_PASSED Passed${NC}, ${RED}$TOTAL_FAILED Failed${NC}"
echo -e "${BOLD}${CYAN}==============================================================================${NC}"

if [ "$TOTAL_FAILED" -gt 0 ]; then
    exit 1
fi
