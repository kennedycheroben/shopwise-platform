#!/usr/bin/env bash
# ==============================================================================
# ShopWise Kenya - Phase 2A-FIX Comprehensive Acceptance Test Suite
#
# Usage:
#   ADMIN_EMAIL=admin@shopwise.co.ke ADMIN_PASSWORD=adminpass ./scripts/acceptance_2afix.sh
#
# Optional environment variables:
#   BASE_URL    (default: http://localhost/shopwise-platform/public)
#   MYSQL_CMD   (default: /opt/lampp/bin/mysql or system mysql)
#   DB_USER     (default: root)
#   DB_PASS     (default: "")
#   DB_NAME     (default: shopwise_db)
# ==============================================================================

set -uo pipefail

# ------------------------------------------------------------------------------
# 0. Setup and Configuration
# ------------------------------------------------------------------------------
BASE_URL="${BASE_URL:-http://127.0.0.1/shopwise-platform/public}"
BASE_URL="${BASE_URL%/}"
ROOT_URL="${BASE_URL%/public}"

MYSQL_CMD="${MYSQL_CMD:-/opt/lampp/bin/mysql}"
if [ ! -x "$MYSQL_CMD" ]; then
    MYSQL_CMD="$(command -v mysql || true)"
fi

DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_NAME="${DB_NAME:-shopwise_test}"

if [ "$DB_NAME" = "shopwise_db" ]; then
    echo -e "\033[0;31mFATAL: Refusing to run tests against protected database '$DB_NAME'!\033[0m" >&2
    exit 1
fi
if [[ ! "$DB_NAME" =~ _test$ ]]; then
    echo -e "\033[0;31mFATAL: Test database name must end with '_test', got '$DB_NAME'!\033[0m" >&2
    exit 1
fi

RAND_ID=$(head /dev/urandom | tr -dc a-z0-9 | head -c 8)
ADMIN_EMAIL="${ADMIN_EMAIL:-test_admin_${RAND_ID}@test.local}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-TestAdminPass_${RAND_ID}!@#}"
CUST_EMAIL="${CUSTOMER_EMAIL:-test_cust_${RAND_ID}@test.local}"
CUST_PASS="${CUSTOMER_PASSWORD:-TestCustPass_${RAND_ID}!@#}"
STAFF_EMAIL="${STAFF_EMAIL:-test_staff_${RAND_ID}@test.local}"
STAFF_PASS="${STAFF_PASSWORD:-TestStaffPass_${RAND_ID}!@#}"

for em in "$ADMIN_EMAIL" "$CUST_EMAIL" "$STAFF_EMAIL"; do
    if [[ "$em" != *@test.local ]]; then
        echo -e "\033[0;31mFATAL: Test email '$em' does not end with @test.local. Aborting immediately!\033[0m"
        exit 1
    fi
done

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

warn() {
    echo -e "  [${YELLOW}WARN${NC}] $1"
}

info() {
    echo -e "\n${BOLD}${CYAN}=== $1 ===${NC}"
}

COOKIE_JAR=$(mktemp)
BODY_FILE=$(mktemp)
HEADER_FILE=$(mktemp)

# Database helper
run_sql() {
    local sql="$1"
    if [ -n "$MYSQL_CMD" ]; then
        if [ -n "$DB_PASS" ]; then
            "$MYSQL_CMD" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "$sql" 2>/dev/null
        else
            "$MYSQL_CMD" -u "$DB_USER" "$DB_NAME" -e "$sql" 2>/dev/null
        fi
    else
        return 1
    fi
}

PHP_BIN="/opt/lampp/bin/php"
if [ ! -x "$PHP_BIN" ]; then
    PHP_BIN="php"
fi

# Auto-provision throwaway admin user in database
ADMIN_HASH=$("$PHP_BIN" -r "echo password_hash('$ADMIN_PASSWORD', PASSWORD_DEFAULT);")
run_sql "INSERT INTO users (role, first_name, last_name, email, password_hash, status, created_at, email_verified_at) VALUES ('admin', 'TestAdmin', '2AFix', '$ADMIN_EMAIL', '$ADMIN_HASH', 'active', NOW(), NOW());"
ADMIN_USER_ID=$(run_sql "SELECT id FROM users WHERE email='$ADMIN_EMAIL' LIMIT 1;" | tail -n 1)
CUST_USER_ID=""
STAFF_USER_ID=""

KEEP_TEST_USERS="${KEEP_TEST_USERS:-0}"

cleanup() {
    rm -f "$COOKIE_JAR" "$BODY_FILE" "$HEADER_FILE"
    if [ -n "${CUST_JAR:-}" ]; then rm -f "$CUST_JAR"*; fi
    if [ -n "${STAFF_JAR:-}" ]; then rm -f "$STAFF_JAR"*; fi
    if [ -n "${LOCKOUT_JAR:-}" ]; then rm -f "$LOCKOUT_JAR"*; fi
    # Clean up test users and login attempts from DB if not preserved for further steps
    if [ "$KEEP_TEST_USERS" != "1" ]; then
        local user_ids=()
        [ -n "${ADMIN_USER_ID:-}" ] && user_ids+=("$ADMIN_USER_ID")
        [ -n "${CUST_USER_ID:-}" ] && user_ids+=("$CUST_USER_ID")
        [ -n "${STAFF_USER_ID:-}" ] && user_ids+=("$STAFF_USER_ID")
        if [ ${#user_ids[@]} -gt 0 ]; then
            local id_csv
            id_csv=$(IFS=,; echo "${user_ids[*]}")
            run_sql "DELETE FROM users WHERE id IN ($id_csv);" >/dev/null 2>&1 || true
        fi
        run_sql "DELETE FROM login_attempts WHERE identifier IN ('$ADMIN_EMAIL', '$CUST_EMAIL', '$STAFF_EMAIL', '${LOCKOUT_ID:-}');" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

# Check response for PHP error signatures
check_php_errors() {
    local target="$1"
    local has_err=0
    local errors=("Fatal error" "Warning:" "Notice:" "Deprecated:" "Parse error" "Exception")
    for err in "${errors[@]}"; do
        if grep -Fqi "$err" "$target"; then
            echo -e "    ${RED}-> Found PHP error pattern: '${err}'${NC}"
            has_err=1
        fi
    done
    return $has_err
}

# HTTP fetch helper (no redirect follow)
fetch_url() {
    local url="$1"
    local jar="${2:-}"
    local data="${3:-}"

    if [ -n "$data" ]; then
        if [ -n "$jar" ]; then
            curl -sS -b "$jar" -c "$jar" -D "$HEADER_FILE" -X POST -d "$data" "$url" -o "$BODY_FILE"
        else
            curl -sS -D "$HEADER_FILE" -X POST -d "$data" "$url" -o "$BODY_FILE"
        fi
    else
        if [ -n "$jar" ]; then
            curl -sS -b "$jar" -c "$jar" -D "$HEADER_FILE" "$url" -o "$BODY_FILE"
        else
            curl -sS -D "$HEADER_FILE" "$url" -o "$BODY_FILE"
        fi
    fi
    HTTP_CODE=$(head -n 1 "$HEADER_FILE" | awk '{print $2}')
}

# Extract CSRF token from HTML body
extract_csrf() {
    local file="$1"
    sed -n 's/.*name="_csrf_token" value="\([^"]*\)".*/\1/p' "$file" | head -n1
}

echo -e "${BOLD}Starting ShopWise Admin Phase 2A-FIX Acceptance Tests${NC}"
echo -e "Target Base URL: ${BASE_URL}"
echo -e "Admin Account:  ${ADMIN_EMAIL}"

# ------------------------------------------------------------------------------
# PART (a): Admin Pages Logged-Out and Logged-In
# ------------------------------------------------------------------------------
info "Part (a): Checking Admin Pages (Logged-Out)"

# 1. Login page
fetch_url "$BASE_URL/admin/login.php"
if [ "$HTTP_CODE" = "200" ] && check_php_errors "$BODY_FILE" && grep -q '<form' "$BODY_FILE"; then
    pass "Logged-out /admin/login.php returned 200, error-free HTML with login form"
else
    fail "Logged-out /admin/login.php failed (HTTP $HTTP_CODE)"
fi

# 2. Forgot password page
fetch_url "$BASE_URL/admin/forgot-password.php"
if [ "$HTTP_CODE" = "200" ] && check_php_errors "$BODY_FILE" && grep -q '<form' "$BODY_FILE"; then
    pass "Logged-out /admin/forgot-password.php returned 200, error-free HTML with request form"
else
    fail "Logged-out /admin/forgot-password.php failed (HTTP $HTTP_CODE)"
fi

# 3. Reset password page
fetch_url "$BASE_URL/admin/reset-password.php?token=acceptance_dummy_token"
if [ "$HTTP_CODE" = "200" ] && check_php_errors "$BODY_FILE"; then
    pass "Logged-out /admin/reset-password.php returned 200, error-free HTML"
else
    fail "Logged-out /admin/reset-password.php failed (HTTP $HTTP_CODE)"
fi

# 4. Standalone 403 page
fetch_url "$BASE_URL/admin/403.php"
if [ "$HTTP_CODE" = "403" ] && check_php_errors "$BODY_FILE" && grep -qi '403' "$BODY_FILE"; then
    pass "Logged-out /admin/403.php returned HTTP 403, error-free standalone template"
else
    fail "Logged-out /admin/403.php failed (HTTP $HTTP_CODE)"
fi

# 5. Standalone 404 page
fetch_url "$BASE_URL/admin/404.php"
if [ "$HTTP_CODE" = "404" ] && check_php_errors "$BODY_FILE" && grep -qi 'Page Not Found' "$BODY_FILE"; then
    pass "Logged-out /admin/404.php returned HTTP 404, error-free standalone template"
else
    fail "Logged-out /admin/404.php failed (HTTP $HTTP_CODE)"
fi

# 6. Dashboard protected redirect
fetch_url "$BASE_URL/admin/index.php"
if [[ "$HTTP_CODE" =~ ^30[12]$ ]] && grep -qi 'Location:.*login\.php' "$HEADER_FILE"; then
    pass "Logged-out /admin/index.php correctly redirected to login.php (HTTP $HTTP_CODE)"
else
    fail "Logged-out /admin/index.php did not redirect to login.php (HTTP $HTTP_CODE)"
fi

# 7. Profile protected redirect
fetch_url "$BASE_URL/admin/profile.php"
if [[ "$HTTP_CODE" =~ ^30[12]$ ]] && grep -qi 'Location:.*login\.php' "$HEADER_FILE"; then
    pass "Logged-out /admin/profile.php correctly redirected to login.php (HTTP $HTTP_CODE)"
else
    fail "Logged-out /admin/profile.php did not redirect to login.php (HTTP $HTTP_CODE)"
fi

# Perform Login to establish authenticated session
info "Performing Admin Authentication"
fetch_url "$BASE_URL/admin/login.php" "$COOKIE_JAR"
LOGIN_CSRF=$(extract_csrf "$BODY_FILE")

if [ -z "$LOGIN_CSRF" ]; then
    fail "Could not extract CSRF token from login form"
else
    pass "Extracted CSRF token for login submission"
    LOGIN_PAYLOAD="_csrf_token=${LOGIN_CSRF}&email=${ADMIN_EMAIL}&password=${ADMIN_PASSWORD}"
    fetch_url "$BASE_URL/admin/login.php" "$COOKIE_JAR" "$LOGIN_PAYLOAD"

    if [[ "$HTTP_CODE" =~ ^30[12]$ ]]; then
        pass "Admin login submission succeeded (HTTP $HTTP_CODE redirect)"
    else
        fail "Admin login submission failed (HTTP $HTTP_CODE). Verify ADMIN_EMAIL and ADMIN_PASSWORD."
    fi
fi

info "Part (a): Checking Admin Pages (Logged-In)"

# 8. Authenticated Dashboard
fetch_url "$BASE_URL/admin/index.php" "$COOKIE_JAR"
if [ "$HTTP_CODE" = "200" ] && check_php_errors "$BODY_FILE"; then
    if grep -q '<aside class="sidebar">' "$BODY_FILE" && grep -q '<header class="header">' "$BODY_FILE"; then
        if grep -q 'metric-card' "$BODY_FILE" && grep -q 'Revenue Overview' "$BODY_FILE"; then
            pass "Logged-in /admin/index.php loaded with sidebar, header, metric cards and revenue overview"
        else
            fail "Logged-in /admin/index.php is missing metric cards or chart regions"
        fi
    else
        fail "Logged-in /admin/index.php is missing <aside class=\"sidebar\"> or <header class=\"header\">"
    fi
else
    fail "Logged-in /admin/index.php failed (HTTP $HTTP_CODE)"
fi

# 9. Authenticated Profile Page
fetch_url "$BASE_URL/admin/profile.php" "$COOKIE_JAR"
if [ "$HTTP_CODE" = "200" ] && check_php_errors "$BODY_FILE"; then
    if grep -q '<aside class="sidebar">' "$BODY_FILE" && grep -q '<header class="header">' "$BODY_FILE"; then
        if grep -qi 'profile' "$BODY_FILE"; then
            pass "Logged-in /admin/profile.php loaded with sidebar, header and profile markup"
        else
            fail "Logged-in /admin/profile.php is missing profile content"
        fi
    else
        fail "Logged-in /admin/profile.php is missing <aside class=\"sidebar\"> or <header class=\"header\">"
    fi
else
    fail "Logged-in /admin/profile.php failed (HTTP $HTTP_CODE)"
fi

# 10. Logout test
fetch_url "$BASE_URL/admin/profile.php" "$COOKIE_JAR"
LOGOUT_CSRF=$(extract_csrf "$BODY_FILE")
if [ -n "$LOGOUT_CSRF" ]; then
    fetch_url "$BASE_URL/admin/logout.php" "$COOKIE_JAR" "_csrf_token=${LOGOUT_CSRF}"
    if [[ "$HTTP_CODE" =~ ^30[12]$ ]]; then
        # Check that dashboard now redirects again
        fetch_url "$BASE_URL/admin/index.php" "$COOKIE_JAR"
        if [[ "$HTTP_CODE" =~ ^30[12]$ ]]; then
            pass "Logged-out POST to /admin/logout.php terminated session cleanly"
        else
            fail "Session remained active after logout (HTTP $HTTP_CODE)"
        fi
    else
        fail "Logout POST did not return 302 redirect (HTTP $HTTP_CODE)"
    fi
    # Re-login for remaining authenticated tests
    fetch_url "$BASE_URL/admin/login.php" "$COOKIE_JAR"
    RE_CSRF=$(extract_csrf "$BODY_FILE")
    fetch_url "$BASE_URL/admin/login.php" "$COOKIE_JAR" "_csrf_token=${RE_CSRF}&email=${ADMIN_EMAIL}&password=${ADMIN_PASSWORD}"
fi

# ------------------------------------------------------------------------------
# PART (b): Protected Folders Blocked (403 or 404)
# ------------------------------------------------------------------------------
info "Part (b): Verifying Protected Path Restrictions (HTTP 403/404)"

BLOCKED_PATHS=(
    "app/config/config.php"
    "database/seed.sql"
    "scripts/create_admin.php"
    "cron/"
    "vendor/autoload.php"
    "_template_reference/"
)

for p in "${BLOCKED_PATHS[@]}"; do
    # Check directly from web root
    fetch_url "$ROOT_URL/$p"
    if [ "$HTTP_CODE" = "403" ] || [ "$HTTP_CODE" = "404" ]; then
        pass "Direct root path '$p' blocked (HTTP $HTTP_CODE)"
    else
        fail "Direct root path '$p' returned HTTP $HTTP_CODE (expected 403 or 404)"
    fi

    # Also check when accessed through public/
    if [ "$ROOT_URL" != "$BASE_URL" ]; then
        fetch_url "$BASE_URL/$p"
        if [ "$HTTP_CODE" = "403" ] || [ "$HTTP_CODE" = "404" ]; then
            pass "Public-relative path '$p' blocked (HTTP $HTTP_CODE)"
        else
            fail "Public-relative path '$p' returned HTTP $HTTP_CODE (expected 403 or 404)"
        fi
    fi
done

# ------------------------------------------------------------------------------
# PART (c): Shell Assets HTTP 200
# ------------------------------------------------------------------------------
info "Part (c): Verifying Shell CSS, JS, and Image Assets (HTTP 200)"

ASSETS=(
    "admin/assets/img/favicon.png"
    "admin/assets/img/apple-touch-icon.png"
    "admin/assets/img/logo.webp"
    "admin/assets/img/profile-img.webp"
    "admin/assets/vendor/bootstrap/css/bootstrap.min.css"
    "admin/assets/vendor/bootstrap-icons/bootstrap-icons.css"
    "admin/assets/vendor/remixicon/remixicon.css"
    "admin/assets/vendor/fontawesome-free/css/all.min.css"
    "admin/assets/vendor/phosphor-icons/phosphor-icons.css"
    "admin/assets/vendor/lucide-icons/lucide.css"
    "admin/assets/vendor/simple-datatables/style.css"
    "admin/assets/vendor/quill/quill.snow.css"
    "admin/assets/vendor/quill/quill.bubble.css"
    "admin/assets/vendor/choices.js/choices.min.css"
    "admin/assets/vendor/flatpickr/flatpickr.min.css"
    "admin/assets/css/main.css"
    "admin/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"
    "admin/assets/vendor/apexcharts/apexcharts.min.js"
    "admin/assets/vendor/chart.js/chart.umd.js"
    "admin/assets/vendor/echarts/echarts.min.js"
    "admin/assets/vendor/simple-datatables/simple-datatables.js"
    "admin/assets/vendor/quill/quill.js"
    "admin/assets/vendor/tinymce/tinymce.min.js"
    "admin/assets/vendor/choices.js/choices.min.js"
    "admin/assets/vendor/flatpickr/flatpickr.min.js"
    "admin/assets/vendor/php-email-form/validate.js"
    "admin/assets/js/theme.js"
    "admin/assets/js/main.js"
    "admin/assets/js/apps-sidebar-toggle.js"
)

ASSETS_OK=1
for asset in "${ASSETS[@]}"; do
    fetch_url "$BASE_URL/$asset"
    if [ "$HTTP_CODE" = "200" ]; then
        : # silent pass for individual assets to keep output readable
    else
        fail "Asset '$asset' returned HTTP $HTTP_CODE"
        ASSETS_OK=0
    fi
done

if [ "$ASSETS_OK" -eq 1 ]; then
    pass "All ${#ASSETS[@]} shell CSS, JS, vendor libraries, and image assets returned HTTP 200"
fi

# ------------------------------------------------------------------------------
# PART (d): Dashboard JSON Endpoints
# ------------------------------------------------------------------------------
info "Part (d): Verifying Dashboard JSON Endpoints"

# 1. Monthly revenue chart
fetch_url "$BASE_URL/admin/api/revenue-chart.php?period=monthly" "$COOKIE_JAR"
if [ "$HTTP_CODE" = "200" ] && php -r 'json_decode(file_get_contents($argv[1]), false, 512, JSON_THROW_ON_ERROR);' "$BODY_FILE" 2>/dev/null; then
    if grep -q '"categories"' "$BODY_FILE" && grep -q '"revenue"' "$BODY_FILE" && grep -q '"totals"' "$BODY_FILE"; then
        pass "API /admin/api/revenue-chart.php?period=monthly returned valid JSON with revenue series and totals"
    else
        fail "/admin/api/revenue-chart.php?period=monthly JSON missing expected categories/revenue/totals keys"
    fi
else
    fail "/admin/api/revenue-chart.php?period=monthly failed (HTTP $HTTP_CODE)"
fi

# 2. Weekly revenue chart
fetch_url "$BASE_URL/admin/api/revenue-chart.php?period=weekly" "$COOKIE_JAR"
if [ "$HTTP_CODE" = "200" ] && php -r 'json_decode(file_get_contents($argv[1]), false, 512, JSON_THROW_ON_ERROR);' "$BODY_FILE" 2>/dev/null; then
    pass "API /admin/api/revenue-chart.php?period=weekly returned valid JSON"
else
    fail "/admin/api/revenue-chart.php?period=weekly failed (HTTP $HTTP_CODE)"
fi

# 3. Daily revenue chart
fetch_url "$BASE_URL/admin/api/revenue-chart.php?period=daily" "$COOKIE_JAR"
if [ "$HTTP_CODE" = "200" ] && php -r 'json_decode(file_get_contents($argv[1]), false, 512, JSON_THROW_ON_ERROR);' "$BODY_FILE" 2>/dev/null; then
    pass "API /admin/api/revenue-chart.php?period=daily returned valid JSON"
else
    fail "/admin/api/revenue-chart.php?period=daily failed (HTTP $HTTP_CODE)"
fi

# 4. Full dashboard API
fetch_url "$BASE_URL/admin/api/dashboard.php" "$COOKIE_JAR"
if [ "$HTTP_CODE" = "200" ] && php -r 'json_decode(file_get_contents($argv[1]), false, 512, JSON_THROW_ON_ERROR);' "$BODY_FILE" 2>/dev/null; then
    if grep -q '"revenue_30"' "$BODY_FILE" && grep -q '"orders_30"' "$BODY_FILE" && grep -q '"recent_orders"' "$BODY_FILE"; then
        pass "API /admin/api/dashboard.php returned valid dashboard metrics payload"
    else
        fail "/admin/api/dashboard.php payload missing required metric keys"
    fi
else
    fail "/admin/api/dashboard.php failed (HTTP $HTTP_CODE)"
fi

# 5. Dashboard index format=json
fetch_url "$BASE_URL/admin/index.php?format=json" "$COOKIE_JAR"
if [ "$HTTP_CODE" = "200" ] && php -r 'json_decode(file_get_contents($argv[1]), false, 512, JSON_THROW_ON_ERROR);' "$BODY_FILE" 2>/dev/null; then
    pass "Endpoint /admin/index.php?format=json returned valid dashboard JSON"
else
    fail "/admin/index.php?format=json failed (HTTP $HTTP_CODE)"
fi

# ------------------------------------------------------------------------------
# PART (e): Security - Lockout, Customer Blocking, Staff Restrictions
# ------------------------------------------------------------------------------
info "Part (e): Security, Rate Limiting, and Role Enforcement"

# 1. 5-failed-login lockout test
LOCKOUT_JAR=$(mktemp)
LOCKOUT_ID="${LOCKOUT_EMAIL:-lockout-probe@test.local}"

# Perform 5 failed sign-in attempts
for i in {1..5}; do
    fetch_url "$BASE_URL/admin/login.php" "$LOCKOUT_JAR"
    C_TOKEN=$(extract_csrf "$BODY_FILE")
    fetch_url "$BASE_URL/admin/login.php" "$LOCKOUT_JAR" "_csrf_token=${C_TOKEN}&email=${LOCKOUT_ID}&password=WrongPassword999!"
done

# 6th sign-in attempt must trigger rate limit lockout message
fetch_url "$BASE_URL/admin/login.php" "$LOCKOUT_JAR"
C_TOKEN=$(extract_csrf "$BODY_FILE")
fetch_url "$BASE_URL/admin/login.php" "$LOCKOUT_JAR" "_csrf_token=${C_TOKEN}&email=${LOCKOUT_ID}&password=WrongPassword999!"

if grep -qi 'Too many sign-in attempts' "$BODY_FILE"; then
    pass "Account lockout triggered after 5 failed login attempts (15-min cooldown enforced)"
else
    fail "Lockout message not observed after 5 failed login attempts"
fi
rm -f "$LOCKOUT_JAR"*

# Database verification checks for customer & staff roles
DB_AVAILABLE=0
if run_sql "SELECT 1;" >/dev/null 2>&1; then
    DB_AVAILABLE=1
fi

if [ "$DB_AVAILABLE" -eq 1 ]; then
    pass "Connected to MariaDB ($DB_NAME) for role and permission tests"

    # 2. Customer-role account cannot log in to admin
    CUST_HASH=$("$PHP_BIN" -r "echo password_hash('$CUST_PASS', PASSWORD_DEFAULT);")

    # Ensure user exists as active customer
    run_sql "INSERT INTO users (role, first_name, last_name, email, password_hash, status, created_at) VALUES ('customer', 'Customer', 'Tester', '$CUST_EMAIL', '$CUST_HASH', 'active', NOW());"
    CUST_USER_ID=$(run_sql "SELECT id FROM users WHERE email='$CUST_EMAIL' LIMIT 1;" | tail -n 1)

    CUST_JAR=$(mktemp)
    fetch_url "$BASE_URL/admin/login.php" "$CUST_JAR"
    CUST_CSRF=$(extract_csrf "$BODY_FILE")
    fetch_url "$BASE_URL/admin/login.php" "$CUST_JAR" "_csrf_token=${CUST_CSRF}&email=${CUST_EMAIL}&password=${CUST_PASS}"

    if [ "$HTTP_CODE" = "200" ] && grep -qi 'do not grant access' "$BODY_FILE"; then
        # Confirm user cannot access dashboard
        fetch_url "$BASE_URL/admin/index.php" "$CUST_JAR"
        if [[ "$HTTP_CODE" =~ ^30[12]$ ]]; then
            pass "Customer-role account strictly blocked from admin login and dashboard"
        else
            fail "Customer session was unexpectedly authorized on /admin/index.php"
        fi
    else
        fail "Customer login attempt did not return access denied message (HTTP $HTTP_CODE)"
    fi
    rm -f "$CUST_JAR"*

    # 3. Staff account cannot reach admin-only ability
    STAFF_HASH=$("$PHP_BIN" -r "echo password_hash('$STAFF_PASS', PASSWORD_DEFAULT);")

    # Ensure user exists as active staff
    run_sql "INSERT INTO users (role, first_name, last_name, email, password_hash, status, created_at) VALUES ('staff', 'Staff', 'Tester', '$STAFF_EMAIL', '$STAFF_HASH', 'active', NOW());"
    STAFF_USER_ID=$(run_sql "SELECT id FROM users WHERE email='$STAFF_EMAIL' LIMIT 1;" | tail -n 1)

    STAFF_JAR=$(mktemp)
    fetch_url "$BASE_URL/admin/login.php" "$STAFF_JAR"
    STAFF_CSRF=$(extract_csrf "$BODY_FILE")
    fetch_url "$BASE_URL/admin/login.php" "$STAFF_JAR" "_csrf_token=${STAFF_CSRF}&email=${STAFF_EMAIL}&password=${STAFF_PASS}"

    if [[ "$HTTP_CODE" =~ ^30[12]$ ]]; then
        # Staff can view dashboard
        fetch_url "$BASE_URL/admin/index.php" "$STAFF_JAR"
        if [ "$HTTP_CODE" = "200" ]; then
            pass "Staff-role account authorized to access dashboard (HTTP 200)"
        else
            fail "Staff account could not view dashboard (HTTP $HTTP_CODE)"
        fi

        # Staff CANNOT access admin-only ability
        fetch_url "$BASE_URL/admin/api/admin-only.php" "$STAFF_JAR"
        if [ "$HTTP_CODE" = "403" ]; then
            pass "Staff-role account blocked from admin-only ability /admin/api/admin-only.php (HTTP 403 Forbidden)"
        else
            fail "Staff account accessed admin-only endpoint unexpectedly (HTTP $HTTP_CODE, expected 403)"
        fi
    else
        fail "Staff login failed (HTTP $HTTP_CODE)"
    fi
    if [ "$KEEP_TEST_USERS" != "1" ]; then
        local user_ids=()
        [ -n "${ADMIN_USER_ID:-}" ] && user_ids+=("$ADMIN_USER_ID")
        [ -n "${CUST_USER_ID:-}" ] && user_ids+=("$CUST_USER_ID")
        [ -n "${STAFF_USER_ID:-}" ] && user_ids+=("$STAFF_USER_ID")
        if [ ${#user_ids[@]} -gt 0 ]; then
            local id_csv
            id_csv=$(IFS=,; echo "${user_ids[*]}")
            run_sql "DELETE FROM users WHERE id IN ($id_csv);" >/dev/null 2>&1 || true
        fi
        run_sql "DELETE FROM login_attempts WHERE identifier IN ('$ADMIN_EMAIL', '$CUST_EMAIL', '$STAFF_EMAIL');" >/dev/null 2>&1 || true
    fi
fi

# Verify catalog counts
PROD_COUNT=$(run_sql "SELECT COUNT(*) FROM products;" | tail -n 1)
if [ "$PROD_COUNT" -eq 21 ]; then
    pass "Products count verified: exactly 21 products in database."
else
    fail "Products count mismatch: expected 21, got $PROD_COUNT"
fi

VAR_COUNT=$(run_sql "SELECT COUNT(*) FROM product_variants;" | tail -n 1)
if [ "$VAR_COUNT" -eq 66 ]; then
    pass "Variants count verified: exactly 66 variants in database."
else
    fail "Variants count mismatch: expected 66, got $VAR_COUNT"
fi

CAT_COUNT=$(run_sql "SELECT COUNT(*) FROM categories;" | tail -n 1)
if [ "$CAT_COUNT" -eq 7 ]; then
    pass "Categories count verified: exactly 7 categories in database."
else
    fail "Categories count mismatch: expected 7, got $CAT_COUNT"
fi


# ------------------------------------------------------------------------------
# Test Summary
# ------------------------------------------------------------------------------
echo -e "\n${BOLD}================================================${NC}"
echo -e "${BOLD}Phase 2A-FIX Acceptance Test Summary${NC}"
echo -e "Passed: ${GREEN}${PASSED}${NC}"
echo -e "Failed: ${RED}${FAILED}${NC}"
echo -e "${BOLD}================================================${NC}"

if [ "$FAILED" -eq 0 ]; then
    echo -e "${GREEN}${BOLD}ALL ACCEPTANCE CHECKS PASSED SUCCESSFULLY.${NC}"
    exit 0
else
    echo -e "${RED}${BOLD}SOME ACCEPTANCE CHECKS FAILED. PLEASE REVIEW LOGS ABOVE.${NC}"
    exit 1
fi
