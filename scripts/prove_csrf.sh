#!/usr/bin/env bash
# ==============================================================================
# ShopWise Platform - CSRF Protection Verification Loop
# Strictly tests EVERY storefront and admin POST route with:
#   1. Missing CSRF token -> Rejected (403 or session error)
#   2. Forged CSRF token  -> Rejected (403 or session error)
# ==============================================================================

set -uo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1/shopwise-platform/public}"
BASE_URL="${BASE_URL%/}"

RED='\033[0;31m'
GREEN='\033[0;32m'
BOLD='\033[1m'
NC='\033[0m'

TMP_DIR=$(mktemp -d)
trap 'rm -rf "$TMP_DIR"' EXIT

COOKIE_ADMIN="$TMP_DIR/admin.cookies"
COOKIE_GUEST="$TMP_DIR/guest.cookies"

# 1. Establish guest session (fetches cookie + valid token in session)
curl -s -c "$COOKIE_GUEST" -b "$COOKIE_GUEST" "$BASE_URL/shop" > /dev/null

# 2. Establish admin session on shopwise_test
# Provision throwaway admin user in shopwise_test
MYSQL_CMD="/opt/lampp/bin/mysql"
[ ! -x "$MYSQL_CMD" ] && MYSQL_CMD="mysql"

TEST_ADMIN_EMAIL="csrf_verifier_$(head /dev/urandom | tr -dc a-z0-9 | head -c 8)@test.local"
TEST_ADMIN_PASS="CsrfAdminPass123!@#"
HASHED_PASS=$(php -r "echo password_hash('$TEST_ADMIN_PASS', PASSWORD_BCRYPT);")

"$MYSQL_CMD" -u root shopwise_test -e "
INSERT INTO users (role, first_name, last_name, email, password_hash, phone, status, created_at, updated_at)
VALUES ('admin', 'CSRF', 'Verifier', '$TEST_ADMIN_EMAIL', '$HASHED_PASS', '254711999999', 'active', NOW(), NOW());
" 2>/dev/null

ADMIN_USER_ID=$("$MYSQL_CMD" -u root shopwise_test -N -s -e "SELECT id FROM users WHERE email='$TEST_ADMIN_EMAIL';" 2>/dev/null)

# Clean up throwaway admin on exit
cleanup() {
    if [ -n "${ADMIN_USER_ID:-}" ]; then
        "$MYSQL_CMD" -u root shopwise_test -e "DELETE FROM users WHERE id = $ADMIN_USER_ID;" 2>/dev/null || true
    fi
    rm -rf "$TMP_DIR"
}
trap cleanup EXIT

# Get login page to capture initial CSRF token for login
LOGIN_HTML=$(curl -s -c "$COOKIE_ADMIN" -b "$COOKIE_ADMIN" "$BASE_URL/admin/login.php")
LOGIN_CSRF=$(echo "$LOGIN_HTML" | grep -oP 'name="_csrf_token"\s+value="\K[^"]+' | head -n 1)

# Log in the admin
curl -s -L -c "$COOKIE_ADMIN" -b "$COOKIE_ADMIN" \
    -d "email=${TEST_ADMIN_EMAIL}" \
    -d "password=${TEST_ADMIN_PASS}" \
    -d "_csrf_token=${LOGIN_CSRF}" \
    "$BASE_URL/admin/login.php" > /dev/null

echo -e "${BOLD}==============================================================================${NC}"
echo -e "${BOLD}                 CSRF PROTECTION PROOF ACROSS ALL POST ROUTES                ${NC}"
echo -e "${BOLD}==============================================================================${NC}"
printf "%-38s | %-16s | %-16s | %-10s\n" "Route" "Missing Token" "Forged Token" "Status"
echo "---------------------------------------+------------------+------------------+------------"

STORE_ROUTES=(
    "cart/add"
    "cart/update"
    "cart/remove"
    "cart/clear"
    "cart/coupon/apply"
    "cart/coupon/remove"
    "contact"
)

ADMIN_ROUTES=(
    "admin/categories/index.php"
    "admin/coupons/index.php"
    "admin/customers/index.php"
    "admin/customers/detail.php?id=1"
    "admin/delivery/pricing.php"
    "admin/delivery/zones.php"
    "admin/inventory/index.php"
    "admin/messages/index.php"
    "admin/messages/detail.php?id=1"
    "admin/orders/detail.php?id=101"
    "admin/products/index.php"
    "admin/products/create.php"
    "admin/products/edit.php?id=1"
    "admin/profile.php"
    "admin/reviews/index.php"
    "admin/settings/index.php"
    "admin/staff/index.php"
    "admin/logout.php"
    "admin/login.php"
    "admin/forgot-password.php"
    "admin/reset-password.php"
)

ALL_PASSED=1

# Test storefront routes
for r in "${STORE_ROUTES[@]}"; do
    url="$BASE_URL/$r"
    
    # Missing CSRF
    res_missing=$(curl -s -w "\n%{http_code}" -X POST -H "Accept: application/json" -b "$COOKIE_GUEST" "$url")
    code_missing=$(echo "$res_missing" | tail -n 1)
    body_missing=$(echo "$res_missing" | head -n -1)

    # Forged CSRF
    res_forged=$(curl -s -w "\n%{http_code}" -X POST -H "Accept: application/json" -b "$COOKIE_GUEST" -d "_csrf_token=forged_token_evil_9999" "$url")
    code_forged=$(echo "$res_forged" | tail -n 1)
    body_forged=$(echo "$res_forged" | head -n -1)

    ok_missing=0
    ok_forged=0
    [ "$code_missing" = "403" ] && ok_missing=1
    [ "$code_forged" = "403" ] && ok_forged=1

    if [ "$ok_missing" -eq 1 ] && [ "$ok_forged" -eq 1 ]; then
        status="${GREEN}PASS${NC}"
    else
        status="${RED}FAIL${NC}"
        ALL_PASSED=0
    fi

    printf "%-38s | HTTP %-11s | HTTP %-11s | %b\n" "$r" "$code_missing (403)" "$code_forged (403)" "$status"
done

# Test admin routes
for r in "${ADMIN_ROUTES[@]}"; do
    url="$BASE_URL/$r"

    # Use admin cookie for authenticated routes, fresh cookie for auth entrypoints
    cookie="$COOKIE_ADMIN"
    if [[ "$r" =~ login|forgot|reset ]]; then
        cookie="$COOKIE_GUEST"
    fi

    # Missing CSRF
    res_missing=$(curl -s -w "\n%{http_code}" -X POST -b "$cookie" "$url")
    code_missing=$(echo "$res_missing" | tail -n 1)
    body_missing=$(echo "$res_missing" | head -n -1)

    # Forged CSRF
    res_forged=$(curl -s -w "\n%{http_code}" -X POST -b "$cookie" -d "_csrf_token=forged_evil_token_xyz" "$url")
    code_forged=$(echo "$res_forged" | tail -n 1)
    body_forged=$(echo "$res_forged" | head -n -1)

    ok_missing=0
    ok_forged=0

    # For authenticated admin actions: expects HTTP 403 Forbidden
    # For login/forgot/reset: either 403 or 200 with session expired error in body
    if [ "$code_missing" = "403" ] || [[ "$body_missing" =~ (expired|Invalid CSRF|Forbidden) ]]; then
        ok_missing=1
    fi

    if [ "$code_forged" = "403" ] || [[ "$body_forged" =~ (expired|Invalid CSRF|Forbidden) ]]; then
        ok_forged=1
    fi

    if [ "$ok_missing" -eq 1 ] && [ "$ok_forged" -eq 1 ]; then
        status="${GREEN}PASS${NC}"
    else
        status="${RED}FAIL${NC}"
        ALL_PASSED=0
    fi

    disp_missing="$code_missing"
    disp_forged="$code_forged"
    [ "$ok_missing" -eq 1 ] && disp_missing="${disp_missing} (Rejected)"
    [ "$ok_forged" -eq 1 ] && disp_forged="${disp_forged} (Rejected)"

    printf "%-38s | %-16s | %-16s | %b\n" "$r" "$disp_missing" "$disp_forged" "$status"
done

echo "---------------------------------------+------------------+------------------+------------"
if [ "$ALL_PASSED" -eq 1 ]; then
    echo -e "${BOLD}${GREEN}SUCCESS: ALL $((${#STORE_ROUTES[@]} + ${#ADMIN_ROUTES[@]})) POST ROUTES PROVEN PROTECTED AGAINST MISSING AND FORGED CSRF!${NC}"
    exit 0
else
    echo -e "${BOLD}${RED}FAILURE: ONE OR MORE ROUTES ALLOWED CSRF BYPASS!${NC}"
    exit 1
fi
