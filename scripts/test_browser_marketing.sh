#!/usr/bin/env bash
# ==============================================================================
# ShopWise Platform - Real Browser Verification (Headless Chrome CDP)
# Priority 4: Marketing Navigation & Coupons Actions Dropdown Verification
# Target DB: shopwise_test strictly
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$ROOT_DIR"

DB_HOST="127.0.0.1"
DB_USER="root"
DB_PASS=""
DB_NAME="shopwise_test"
BASE_URL="http://127.0.0.1/shopwise-platform/public"
CHROME_PORT=9222

run_sql() {
    mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" -sN -e "$1"
}

ACTIVE_DB=$(run_sql "SELECT DATABASE();")
if [ "$ACTIVE_DB" != "shopwise_test" ]; then
    echo "FATAL: Test suite must ONLY run against 'shopwise_test'. Connected to '$ACTIVE_DB'." >&2
    exit 1
fi

echo "=============================================================================="
echo " ShopWise Kenya - Browser Verification Suite (Headless Chrome CDP)"
echo " Target DB: $DB_NAME"
echo "=============================================================================="

# 1. Ensure test admin account exists in shopwise_test
ADMIN_EMAIL="browser_test_admin@shopwise.test"
ADMIN_PASS="AdminPass123!"

# Generate bcrypt hash
HASHED_PASS=$(php -r "echo password_hash('$ADMIN_PASS', PASSWORD_BCRYPT);")

run_sql "DELETE FROM users WHERE email = '$ADMIN_EMAIL';"
ADMIN_UID=$(run_sql "
    INSERT INTO users (role, first_name, last_name, email, password_hash, status, created_at, email_verified_at)
    VALUES ('admin', 'Browser', 'Tester', '$ADMIN_EMAIL', '$HASHED_PASS', 'active', NOW(), NOW());
    SELECT LAST_INSERT_ID();
")
ADMIN_UID=$(echo "$ADMIN_UID" | tr -d '[:space:]')
run_sql "INSERT INTO user_roles (user_id, role, assigned_at) VALUES ($ADMIN_UID, 'admin', NOW());"

# 2. Ensure at least one active coupon exists
COUPON_COUNT=$(run_sql "SELECT COUNT(*) FROM coupons;")
if [ "$COUPON_COUNT" -eq 0 ]; then
    run_sql "INSERT INTO coupons (code, type, value, min_spend, is_active, created_at)
             VALUES ('BROWSERTEST10', 'percent', 10.00, 500.00, 1, NOW());"
fi

# 3. Launch Headless Chrome
CHROME_PROFILE=$(mktemp -d /tmp/chrome_p4_XXXXXX)
CHROME_BIN="/usr/bin/google-chrome"
if [ ! -x "$CHROME_BIN" ]; then
    CHROME_BIN="$(which google-chrome || which chromium-browser || echo "")"
fi

if [ -z "$CHROME_BIN" ] || [ ! -x "$CHROME_BIN" ]; then
    echo "ERROR: Google Chrome binary not found. Cannot run browser automation." >&2
    exit 1
fi

echo "Launching Headless Chrome on port $CHROME_PORT..."
"$CHROME_BIN" \
    --headless=new \
    --remote-debugging-port="$CHROME_PORT" \
    --user-data-dir="$CHROME_PROFILE" \
    --no-sandbox \
    --disable-gpu \
    --disable-dev-shm-usage \
    --window-size=1280,800 \
    about:blank >/tmp/chrome_browser_test.log 2>&1 &
CHROME_PID=$!

cleanup() {
    echo "Cleaning up Chrome (PID ${CHROME_PID:-})..."
    if [ -n "${CHROME_PID:-}" ]; then
        pkill -P "$CHROME_PID" 2>/dev/null || true
        kill -9 "$CHROME_PID" 2>/dev/null || true
        wait "$CHROME_PID" 2>/dev/null || true
    fi
    if [ -n "${CHROME_PROFILE:-}" ] && [ -d "$CHROME_PROFILE" ]; then
        sleep 0.3
        rm -rf "$CHROME_PROFILE" 2>/dev/null || rm -rf "$CHROME_PROFILE" 2>/dev/null || true
    fi
    if [ -n "${ADMIN_UID:-}" ]; then
        run_sql "DELETE FROM user_roles WHERE user_id = $ADMIN_UID; DELETE FROM users WHERE id = $ADMIN_UID;" 2>/dev/null || true
    fi
}
trap cleanup EXIT

# 4. Wait for Chrome CDP to be available
READY=0
for i in {1..15}; do
    if curl -s "http://127.0.0.1:$CHROME_PORT/json/version" >/dev/null 2>&1; then
        READY=1
        break
    fi
    sleep 0.5
done

if [ "$READY" -ne 1 ]; then
    echo "FATAL: Chrome did not become ready within 7.5 seconds." >&2
    cat /tmp/chrome_browser_test.log >&2
    exit 1
fi

# 5. Run Node.js CDP Verification Script
export CHROME_PORT="$CHROME_PORT"
export BASE_URL="$BASE_URL"
export ADMIN_EMAIL="$ADMIN_EMAIL"
export ADMIN_PASSWORD="$ADMIN_PASS"

node scripts/verify_marketing_browser.js

echo ""
echo "  [PASS] Real browser layout, sidebar navigation, table display, dropdown visibility & toggle execution verified!"
exit 0
