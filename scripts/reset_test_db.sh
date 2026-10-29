#!/usr/bin/env bash
# ==============================================================================
# ShopWise Platform - Dedicated Test Database Reset Script
# Strictly recreates shopwise_test from schema.sql, seed.sql, and demo_orders.sql.
# PROTECTED: Must refuse to run unless target database name ends with '_test'.
# MUST NEVER accept 'shopwise_db' or any non-test database name.
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$ROOT_DIR"

DB_NAME="${1:-${DB_NAME:-shopwise_test}}"

# ------------------------------------------------------------------------------
# MANDATORY SAFETY GUARDS
# ------------------------------------------------------------------------------
if [ "$DB_NAME" = "shopwise_db" ]; then
    echo -e "\033[0;31mFATAL SECURITY ERROR: Refusing to reset protected production/working database '$DB_NAME'!\033[0m" >&2
    echo "This script ONLY operates on dedicated disposable test databases." >&2
    exit 1
fi

if [[ ! "$DB_NAME" =~ _test$ ]]; then
    echo -e "\033[0;31mFATAL SECURITY ERROR: Database name '$DB_NAME' is invalid. Name MUST end with '_test'!\033[0m" >&2
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
        "$MYSQL_CMD" -u "$DB_USER" -p"$DB_PASS" -e "$sql" 2>/dev/null
    else
        "$MYSQL_CMD" -u "$DB_USER" -e "$sql" 2>/dev/null
    fi
}

import_sql() {
    local file="$1"
    if [ -n "$DB_PASS" ]; then
        "$MYSQL_CMD" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$file"
    else
        "$MYSQL_CMD" -u "$DB_USER" "$DB_NAME" < "$file"
    fi
}

echo "=== Initializing Disposable Test Database: '$DB_NAME' ==="

# 1. Drop and recreate ONLY the test database
run_sql "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Import core schema
import_sql "database/schema.sql"

# 3. Import seed data
import_sql "database/seed.sql"

# 4. Import demo orders
import_sql "database/demo_orders.sql"

# 5. Apply any pending database migrations
for mig in database/migrations/*.sql; do
    if [ -f "$mig" ]; then
        import_sql "$mig" 2>/dev/null || true
    fi
done

# 6. Initialize dedicated test uploads directory
UPLOADS_TEST_DIR="$ROOT_DIR/public/uploads_test"
mkdir -p "$UPLOADS_TEST_DIR/products"
chmod 0755 "$UPLOADS_TEST_DIR" "$UPLOADS_TEST_DIR/products"

# Protect uploads_test with strict .htaccess (prevent script execution)
if [ -f "$ROOT_DIR/public/uploads/.htaccess" ]; then
    cp "$ROOT_DIR/public/uploads/.htaccess" "$UPLOADS_TEST_DIR/.htaccess"
    chmod 0644 "$UPLOADS_TEST_DIR/.htaccess"
fi

# 7. Initialize dedicated test rate limits directory
RATE_LIMITS_TEST_DIR="$ROOT_DIR/app/storage/rate_limits_test"
if [ ! -d "$RATE_LIMITS_TEST_DIR" ]; then
    mkdir -p "$RATE_LIMITS_TEST_DIR"
    chmod 0755 "$RATE_LIMITS_TEST_DIR"
fi
curl -s -X POST http://127.0.0.1/shopwise-platform/public/cart/test/clear-rate-limits > /dev/null 2>&1 || true
rm -f "$RATE_LIMITS_TEST_DIR"/*.json 2>/dev/null || true

echo "=== Test Database '$DB_NAME' successfully initialized and ready for test suites ==="
