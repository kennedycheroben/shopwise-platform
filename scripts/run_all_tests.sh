#!/usr/bin/env bash
# ==============================================================================
# ShopWise Platform - Master Test Suite Runner
# Strictly tests disposable test database 'shopwise_test'.
# Enforces zero-touch on working database 'shopwise_db' via CHECKSUM TABLE
# and verifies upload isolation.
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$ROOT_DIR"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

PHP_CMD="${PHP_CMD:-/opt/lampp/bin/php}"
if [ ! -x "$PHP_CMD" ]; then
    PHP_CMD="php"
fi

MYSQL_CMD="/opt/lampp/bin/mysql"
if [ ! -x "$MYSQL_CMD" ]; then
    MYSQL_CMD="mysql"
fi

PROTECTED_DB="shopwise_db"
TEST_DB="shopwise_test"
DB_USER="root"
DB_PASS=""

# Guard: Ensure TEST_DB is strictly a test database
if [ "$TEST_DB" = "$PROTECTED_DB" ] || [[ ! "$TEST_DB" =~ _test$ ]]; then
    echo -e "${RED}FATAL CONFIG ERROR: TEST_DB must be disposable and end with '_test'. Got '$TEST_DB'.${NC}" >&2
    exit 1
fi

run_sql_protected() {
    local sql="$1"
    if [ -n "$DB_PASS" ]; then
        "$MYSQL_CMD" -u "$DB_USER" -p"$DB_PASS" "$PROTECTED_DB" -N -s -e "$sql" 2>/dev/null
    else
        "$MYSQL_CMD" -u "$DB_USER" "$PROTECTED_DB" -N -s -e "$sql" 2>/dev/null
    fi
}

echo -e "${BOLD}${CYAN}==============================================================================${NC}"
echo -e "${BOLD}${CYAN}            SHOPWISE PLATFORM - MASTER TEST SUITE RUNNER                      ${NC}"
echo -e "${BOLD}${CYAN}==============================================================================${NC}"
echo "PHP Binary:           $PHP_CMD"
echo "Protected DB:         $PROTECTED_DB (monitored with CHECKSUM TABLE)"
echo "Test DB:              $TEST_DB (re-seeded disposable target)"
echo "Root Directory:       $ROOT_DIR"
echo "Testing Base URL:     http://127.0.0.1/shopwise-platform/public"
echo ""

# ------------------------------------------------------------------------------
# STEP 1: RECORD PRE-TEST CHECKSUMS OF shopwise_db & UPLOADS
# ------------------------------------------------------------------------------
echo -e "${BOLD}--- Step 1: Recording pre-test CHECKSUM TABLE for all tables in $PROTECTED_DB ---${NC}"
PRE_CHECKSUMS_FILE=$(mktemp)
PRE_TABLES=$(run_sql_protected "SHOW TABLES;")
TABLE_LIST_CSV=$(echo "$PRE_TABLES" | tr '\n' ',' | sed 's/,$//')

# Schema integrity self-check: verify live tables match database/schema.sql exactly
SCHEMA_TABLES=$(grep -i 'CREATE TABLE' "$ROOT_DIR/database/schema.sql" | awk '{print $3}' | tr -d '`(' | LC_ALL=C sort)
LIVE_TABLES=$(echo "$PRE_TABLES" | LC_ALL=C sort)
if [ "$SCHEMA_TABLES" != "$LIVE_TABLES" ]; then
    echo -e "${RED}FATAL: Live database tables in $PROTECTED_DB do not match database/schema.sql!${NC}" >&2
    echo "Discrepancy (schema vs live):" >&2
    diff -u <(echo "$SCHEMA_TABLES") <(echo "$LIVE_TABLES") >&2
    exit 1
fi
echo "  [PASS] Live table list matches database/schema.sql ($(echo "$SCHEMA_TABLES" | wc -l) tables verified)."

if [ -n "$DB_PASS" ]; then
    "$MYSQL_CMD" -u "$DB_USER" -p"$DB_PASS" "$PROTECTED_DB" -N -s -e "CHECKSUM TABLE $TABLE_LIST_CSV;" > "$PRE_CHECKSUMS_FILE" 2>/dev/null
else
    "$MYSQL_CMD" -u "$DB_USER" "$PROTECTED_DB" -N -s -e "CHECKSUM TABLE $TABLE_LIST_CSV;" > "$PRE_CHECKSUMS_FILE" 2>/dev/null
fi

PRE_UPLOADS_COUNT=$(find "$ROOT_DIR/public/uploads" -type f 2>/dev/null | wc -l || echo 0)
echo "  Recorded $(wc -l < "$PRE_CHECKSUMS_FILE") table checksums for $PROTECTED_DB."
echo "  Recorded baseline uploads count: $PRE_UPLOADS_COUNT files in public/uploads."
echo ""

# ------------------------------------------------------------------------------
# STEP 2: RECREATE DISPOSABLE TEST DATABASE (shopwise_test)
# ------------------------------------------------------------------------------
echo -e "${BOLD}--- Step 2: Recreating disposable test database ($TEST_DB) ---${NC}"
bash scripts/reset_test_db.sh "$TEST_DB"
echo ""

# ------------------------------------------------------------------------------
# STEP 3: PHP LINTING (php -l)
# ------------------------------------------------------------------------------
echo -e "${BOLD}--- Step 3: Running PHP syntax linter (php -l) over app/ and public/ ---${NC}"
LINT_ERRORS=0
while IFS= read -r file; do
    if ! "$PHP_CMD" -l "$file" >/dev/null 2>&1; then
        echo -e "  [${RED}SYNTAX ERROR${NC}] $file"
        LINT_ERRORS=$((LINT_ERRORS + 1))
    fi
done < <(find app public -path "app/storage" -prune -o -type f -name "*.php" -print)

if [ "$LINT_ERRORS" -gt 0 ]; then
    echo -e "${RED}LINT FAILED: $LINT_ERRORS syntax error(s) detected.${NC}"
    exit 1
fi
echo -e "  [${GREEN}PASS${NC}] All PHP files in app/ and public/ passed syntax validation."
echo ""

# ------------------------------------------------------------------------------
# STEP 4: RUN ALL ACCEPTANCE TEST SUITES AGAINST shopwise_test
# ------------------------------------------------------------------------------
TMP_OUT_DIR=$(mktemp -d)
trap 'rm -rf "$TMP_OUT_DIR" "$PRE_CHECKSUMS_FILE"' EXIT

OVERALL_STATUS=0
declare -A SUITE_EXITS

export BASE_URL="http://127.0.0.1/shopwise-platform/public"
export DB_NAME="$TEST_DB"
export SHOPWISE_CONFIG="testing"

run_suite() {
    local name="$1"
    local script="$2"
    local runner="${3:-bash}"
    local log_file="$TMP_OUT_DIR/${name}.log"

    echo -e "${BOLD}--- Running Suite: $name ($script) ---${NC}"
    local start_time
    start_time=$(date +%s)

    set +e
    if [ "$runner" = "php" ]; then
        BASE_URL="http://127.0.0.1/shopwise-platform/public" DB_NAME="$TEST_DB" SHOPWISE_CONFIG="testing" "$PHP_CMD" "$script" > "$log_file" 2>&1
    elif [ "$runner" = "python3" ]; then
        BASE_URL="http://127.0.0.1/shopwise-platform/public" DB_NAME="$TEST_DB" SHOPWISE_CONFIG="testing" python3 "$script" > "$log_file" 2>&1
    else
        BASE_URL="http://127.0.0.1/shopwise-platform/public" DB_NAME="$TEST_DB" SHOPWISE_CONFIG="testing" bash "$script" > "$log_file" 2>&1
    fi
    local exit_code=$?
    set -e

    SUITE_EXITS["$name"]=$exit_code

    local duration=$(( $(date +%s) - start_time ))
    echo "  Completed in ${duration}s (Exit code: $exit_code)"

    if [ "$exit_code" -ne 0 ]; then
        OVERALL_STATUS=1
        echo -e "  [${RED}FAIL${NC}] Suite $name failed (Exit code: $exit_code). Last 15 lines of log:"
        tail -n 15 "$log_file" | sed 's/^/    /'
    fi
}

run_suite "acceptance_2afix" "scripts/acceptance_2afix.sh"
run_suite "acceptance_2b"    "scripts/acceptance_2b.sh"
run_suite "acceptance_2c1"   "scripts/acceptance_2c1.sh"
run_suite "acceptance_2c2"   "scripts/acceptance_2c2.sh"
run_suite "acceptance_3a"    "scripts/acceptance_3a.sh"
run_suite "acceptance_3b"    "scripts/acceptance_3b.sh"
run_suite "acceptance_4"     "scripts/acceptance_4.sh"
run_suite "acceptance_5"     "scripts/acceptance_5.sh"
run_suite "prove_csrf"       "scripts/prove_csrf.sh"
run_suite "category_slugs"   "scripts/test_category_slugs.sh"
run_suite "delivery_pricing" "scripts/test_delivery_pricing.sh"
run_suite "customer_reply"   "scripts/test_customer_support_reply.sh"
run_suite "rbac_revocation"  "scripts/test_rbac_revocation_regression.sh"
run_suite "upload_security"  "scripts/test_upload_security.php" "php"
run_suite "route_guard"      "scripts/test_test_route_guard.php" "php"
run_suite "env_centralization" "scripts/test_env_centralization.php" "php"
run_suite "browser_marketing" "scripts/test_browser_marketing.sh"
run_suite "uat_15_steps"     "scripts/uat_15_steps.sh"
run_suite "stock_parity"     "scripts/check_stock_parity.py" "python3"
run_suite "guest_cart_checkout" "scripts/test_public_guest_cart_checkout.sh"

echo ""

# ------------------------------------------------------------------------------
# STEP 5: PARSE RESULTS & PRINT SUMMARY TABLE
# ------------------------------------------------------------------------------
parse_2afix() {
    local log="$TMP_OUT_DIR/acceptance_2afix.log"
    local clean_text=$(sed -r "s/\x1B\[[0-9;]*[a-zA-Z]//g" "$log")
    local passed=$(echo "$clean_text" | grep -oP 'Passed:\s*\K[0-9]+' | tail -n 1 || echo 0)
    local failed=$(echo "$clean_text" | grep -oP 'Failed:\s*\K[0-9]+' | tail -n 1 || echo 0)
    echo "${passed:-0} ${failed:-0}"
}

parse_2b() {
    local log="$TMP_OUT_DIR/acceptance_2b.log"
    local clean_text=$(sed -r "s/\x1B\[[0-9;]*[a-zA-Z]//g" "$log")
    local passed=$(echo "$clean_text" | grep -oP 'Total Passed:\s*\K[0-9]+' | tail -n 1 || echo 0)
    local failed=$(echo "$clean_text" | grep -oP 'Total Failed:\s*\K[0-9]+' | tail -n 1 || echo 0)
    echo "${passed:-0} ${failed:-0}"
}

parse_2c1() {
    local log="$TMP_OUT_DIR/acceptance_2c1.log"
    local clean_text=$(sed -r "s/\x1B\[[0-9;]*[a-zA-Z]//g" "$log")
    local passed=$(echo "$clean_text" | grep -oP 'SUMMARY: Passed:\s*\K[0-9]+' | tail -n 1 || echo 0)
    local failed=$(echo "$clean_text" | grep -oP 'Failed:\s*\K[0-9]+' | tail -n 1 || echo 0)
    echo "${passed:-0} ${failed:-0}"
}

parse_2c2() {
    local log="$TMP_OUT_DIR/acceptance_2c2.log"
    local clean_text=$(sed -r "s/\x1B\[[0-9;]*[a-zA-Z]//g" "$log")
    local passed=$(echo "$clean_text" | grep -oP 'Passed:\s*\K[0-9]+' | tail -n 1 || echo 0)
    local failed=$(echo "$clean_text" | grep -oP 'Failed:\s*\K[0-9]+' | tail -n 1 || echo 0)
    echo "${passed:-0} ${failed:-0}"
}

parse_3a() {
    local log="$TMP_OUT_DIR/acceptance_3a.log"
    local clean_text=$(sed -r "s/\x1B\[[0-9;]*[a-zA-Z]//g" "$log")
    local passed=$(echo "$clean_text" | grep -oP 'Passed:\s*\K[0-9]+' | tail -n 1 || echo 0)
    local failed=$(echo "$clean_text" | grep -oP 'Failed:\s*\K[0-9]+' | tail -n 1 || echo 0)
    echo "${passed:-0} ${failed:-0}"
}

parse_3b() {
    local log="$TMP_OUT_DIR/acceptance_3b.log"
    local clean_text=$(sed -r "s/\x1B\[[0-9;]*[a-zA-Z]//g" "$log")
    local passed=$(echo "$clean_text" | grep -oP 'Passed:\s*\K[0-9]+' | tail -n 1 || echo 0)
    local failed=$(echo "$clean_text" | grep -oP 'Failed:\s*\K[0-9]+' | tail -n 1 || echo 0)
    echo "${passed:-0} ${failed:-0}"
}

parse_4() {
    local log="$TMP_OUT_DIR/acceptance_4.log"
    local clean_text=$(sed -r "s/\x1B\[[0-9;]*[a-zA-Z]//g" "$log")
    local passed=$(echo "$clean_text" | grep -oP 'Passed:\s*\K[0-9]+' | tail -n 1 || echo 0)
    local failed=$(echo "$clean_text" | grep -oP 'Failed:\s*\K[0-9]+' | tail -n 1 || echo 0)
    echo "${passed:-0} ${failed:-0}"
}

parse_5() {
    local log="$TMP_OUT_DIR/acceptance_5.log"
    local clean_text=$(sed -r "s/\x1B\[[0-9;]*[a-zA-Z]//g" "$log")
    local passed=$(echo "$clean_text" | grep -oP 'Phase 5 Test Summary:\s*Passed:\s*\K[0-9]+' | tail -n 1 || echo 0)
    local failed=$(echo "$clean_text" | grep -oP 'Failed:\s*\K[0-9]+' | tail -n 1 || echo 0)
    echo "${passed:-0} ${failed:-0}"
}

parse_csrf() {
    local log="$TMP_OUT_DIR/prove_csrf.log"
    local clean_text=$(sed -r "s/\x1B\[[0-9;]*[a-zA-Z]//g" "$log")
    local total=$(echo "$clean_text" | grep -oP 'SUCCESS: ALL \K[0-9]+' | tail -n 1 || echo 0)
    if [ "${SUITE_EXITS["prove_csrf"]:-1}" -eq 0 ] && echo "$clean_text" | grep -q "SUCCESS: ALL"; then
        echo "${total:-0} 0"
    else
        echo "0 1"
    fi
}

parse_slugs() {
    local log="$TMP_OUT_DIR/category_slugs.log"
    local clean_text=$(sed -r "s/\x1B\[[0-9;]*[a-zA-Z]//g" "$log")
    local passed=$(echo "$clean_text" | grep -oP 'CATEGORY SLUG TEST SUMMARY:\s*\K[0-9]+' | tail -n 1 || echo 0)
    local failed=$(echo "$clean_text" | grep -oP 'Passed,\s*\K[0-9]+(?=\s*Failed)' | tail -n 1 || echo 0)
    echo "${passed:-0} ${failed:-0}"
}

parse_delivery() {
    local log="$TMP_OUT_DIR/delivery_pricing.log"
    local clean_text=$(sed -r "s/\x1B\[[0-9;]*[a-zA-Z]//g" "$log")
    local passed=$(echo "$clean_text" | grep -oP 'Delivery Pricing Tests:\s*\K[0-9]+' | tail -n 1 || echo 0)
    local failed=$(echo "$clean_text" | grep -oP 'passed,\s*\K[0-9]+(?=\s*failed)' | tail -n 1 || echo 0)
    echo "${passed:-0} ${failed:-0}"
}

parse_reply() {
    local log="$TMP_OUT_DIR/customer_reply.log"
    local clean_text=$(sed -r "s/\x1B\[[0-9;]*[a-zA-Z]//g" "$log")
    local passed=$(echo "$clean_text" | grep -oP 'Customer Support Reply Tests:\s*\K[0-9]+' | tail -n 1 || echo 0)
    local failed=$(echo "$clean_text" | grep -oP 'passed,\s*\K[0-9]+(?=\s*failed)' | tail -n 1 || echo 0)
    echo "${passed:-0} ${failed:-0}"
}

parse_rbac() {
    local log="$TMP_OUT_DIR/rbac_revocation.log"
    local clean_text=$(sed -r "s/\x1B\[[0-9;]*[a-zA-Z]//g" "$log")
    local passed=$(echo "$clean_text" | grep -oP 'RBAC Revocation Invariant Tests:\s*\K[0-9]+' | tail -n 1 || echo 0)
    local failed=$(echo "$clean_text" | grep -oP 'passed,\s*\K[0-9]+(?=\s*failed)' | tail -n 1 || echo 0)
    echo "${passed:-0} ${failed:-0}"
}

parse_upload_sec() {
    local log="$TMP_OUT_DIR/upload_security.log"
    local clean_text=$(sed -r "s/\x1B\[[0-9;]*[a-zA-Z]//g" "$log")
    local passed=$(echo "$clean_text" | grep -oP 'Summary:\s*\K[0-9]+' | tail -n 1 || echo 0)
    local total=$(echo "$clean_text" | grep -oP 'Summary:\s*[0-9]+\s*/\s*\K[0-9]+' | tail -n 1 || echo 0)
    local failed=$((total - passed))
    echo "${passed:-0} ${failed:-0}"
}

parse_route_guard() {
    local log="$TMP_OUT_DIR/route_guard.log"
    local clean_text=$(sed -r "s/\x1B\[[0-9;]*[a-zA-Z]//g" "$log")
    local passed=$(echo "$clean_text" | grep -oP 'Summary:\s*\K[0-9]+' | tail -n 1 || echo 0)
    local total=$(echo "$clean_text" | grep -oP 'Summary:\s*[0-9]+\s*/\s*\K[0-9]+' | tail -n 1 || echo 0)
    local failed=$((total - passed))
    echo "${passed:-0} ${failed:-0}"
}

parse_env_centralization() {
    local log="$TMP_OUT_DIR/env_centralization.log"
    local clean_text=$(sed -r "s/\x1B\[[0-9;]*[a-zA-Z]//g" "$log")
    local passed=$(echo "$clean_text" | grep -oP 'SUMMARY:\s*\K[0-9]+' | tail -n 1 || echo 0)
    local failed=$(echo "$clean_text" | grep -oP 'Passed,\s*\K[0-9]+(?=\s*Failed)' | tail -n 1 || echo 0)
    echo "${passed:-0} ${failed:-0}"
}

parse_browser_marketing() {
    local log="$TMP_OUT_DIR/browser_marketing.log"
    if [ "${SUITE_EXITS["browser_marketing"]:-1}" -eq 0 ] && grep -q "ALL BROWSER VERIFICATION CHECKS PASSED" "$log"; then
        echo "7 0"
    else
        echo "0 1"
    fi
}

parse_uat() {
    local log="$TMP_OUT_DIR/uat_15_steps.log"
    local clean_text=$(sed -r "s/\x1B\[[0-9;]*[a-zA-Z]//g" "$log")
    local passed=$(grep -c "\[PASS\] Step" "$log" || echo 0)
    if [ "${SUITE_EXITS["uat_15_steps"]:-1}" -eq 0 ]; then
        echo "${passed:-15} 0"
    else
        echo "${passed:-0} 1"
    fi
}

parse_parity() {
    local log="$TMP_OUT_DIR/stock_parity.log"
    local clean_text=$(sed -r "s/\x1B\[[0-9;]*[a-zA-Z]//g" "$log")
    local variants=$(echo "$clean_text" | grep -oP 'Total variants evaluated:\s*\K[0-9]+' | tail -n 1 || echo 0)
    local disc=$(echo "$clean_text" | grep -oP 'Ledger Discrepancies:\s*\K[0-9]+' | tail -n 1 || echo 0)
    if [ "${SUITE_EXITS["stock_parity"]:-1}" -eq 0 ] && [ "${disc:-1}" -eq 0 ]; then
        echo "${variants:-0} 0"
    else
        echo "0 ${variants:-1}"
    fi
}

parse_guest_cart_checkout() {
    local log="$TMP_OUT_DIR/guest_cart_checkout.log"
    local clean_text=$(sed -r "s/\x1B\[[0-9;]*[a-zA-Z]//g" "$log")
    local passed=$(echo "$clean_text" | grep -oP 'Passed:\s*\K[0-9]+' | tail -n 1 || echo 0)
    local failed=$(echo "$clean_text" | grep -oP 'Failed:\s*\K[0-9]+' | tail -n 1 || echo 0)
    echo "${passed:-0} ${failed:-0}"
}

echo -e "${BOLD}${CYAN}==============================================================================${NC}"
echo -e "${BOLD}${CYAN}                       TEST SUITE EXECUTION SUMMARY                           ${NC}"
echo -e "${BOLD}${CYAN}==============================================================================${NC}"
printf "%-32s | %-8s | %-8s | %-10s | %-10s\n" "Test Suite / Script" "Passed" "Failed" "Exit Code" "Status"
echo "---------------------------------+----------+----------+------------+------------"

TOTAL_PASSED=0
TOTAL_FAILED=0

report_row() {
    local name="$1"
    local title="$2"
    local parse_fn="$3"

    local p f
    read -r p f < <("$parse_fn")
    local exit_code="${SUITE_EXITS[$name]:-0}"

    if [ "$exit_code" -ne 0 ]; then
        if [ "$f" -eq 0 ]; then
            f=1
        fi
        OVERALL_STATUS=1
        printf "%-32s | %-8s | %-8s | %-10s | %b\n" "$title" "$p" "$f" "$exit_code" "${RED}FAIL${NC}"
    elif [ "$f" -gt 0 ]; then
        OVERALL_STATUS=1
        printf "%-32s | %-8s | %-8s | %-10s | %b\n" "$title" "$p" "$f" "$exit_code" "${RED}FAIL${NC}"
    else
        printf "%-32s | %-8s | %-8s | %-10s | %b\n" "$title" "$p" "$f" "$exit_code" "${GREEN}PASS${NC}"
    fi

    TOTAL_PASSED=$((TOTAL_PASSED + p))
    TOTAL_FAILED=$((TOTAL_FAILED + f))
}

report_row "acceptance_2afix" "acceptance_2afix.sh" parse_2afix
report_row "acceptance_2b"    "acceptance_2b.sh"    parse_2b
report_row "acceptance_2c1"   "acceptance_2c1.sh"   parse_2c1
report_row "acceptance_2c2"   "acceptance_2c2.sh"   parse_2c2
report_row "acceptance_3a"    "acceptance_3a.sh"    parse_3a
report_row "acceptance_3b"    "acceptance_3b.sh"    parse_3b
report_row "acceptance_4"     "acceptance_4.sh"     parse_4
report_row "acceptance_5"     "acceptance_5.sh"     parse_5
report_row "prove_csrf"       "prove_csrf.sh"       parse_csrf
report_row "category_slugs"   "test_category_slugs" parse_slugs
report_row "delivery_pricing" "test_delivery_pricing" parse_delivery
report_row "customer_reply"   "test_customer_reply" parse_reply
report_row "rbac_revocation"  "test_rbac_revocation" parse_rbac
report_row "upload_security"  "test_upload_security" parse_upload_sec
report_row "route_guard"      "test_route_guard"    parse_route_guard
report_row "env_centralization" "test_env_centralization" parse_env_centralization
report_row "browser_marketing" "browser_marketing"   parse_browser_marketing
report_row "uat_15_steps"     "uat_15_steps.sh"     parse_uat
report_row "stock_parity"     "check_stock_parity"  parse_parity
report_row "guest_cart_checkout" "guest_cart_checkout.sh" parse_guest_cart_checkout

echo "---------------------------------+----------+----------+------------+------------"
printf "%-32s | %-8s | %-8s | %-10s | %b\n" "TOTAL" "$TOTAL_PASSED" "$TOTAL_FAILED" "-" "$([ "$TOTAL_FAILED" -eq 0 ] && [ "$OVERALL_STATUS" -eq 0 ] && echo -e "${GREEN}ALL PASS${NC}" || echo -e "${RED}FAILURES${NC}")"
echo ""

# ------------------------------------------------------------------------------
# STEP 6: POST-TEST CHECKSUM VERIFICATION OF shopwise_db & UPLOADS
# ------------------------------------------------------------------------------
echo -e "${BOLD}--- Step 6: Verifying shopwise_db Integrity via CHECKSUM TABLE ---${NC}"
POST_CHECKSUMS_FILE=$(mktemp)
if [ -n "$DB_PASS" ]; then
    "$MYSQL_CMD" -u "$DB_USER" -p"$DB_PASS" "$PROTECTED_DB" -N -s -e "CHECKSUM TABLE $TABLE_LIST_CSV;" > "$POST_CHECKSUMS_FILE" 2>/dev/null
else
    "$MYSQL_CMD" -u "$DB_USER" "$PROTECTED_DB" -N -s -e "CHECKSUM TABLE $TABLE_LIST_CSV;" > "$POST_CHECKSUMS_FILE" 2>/dev/null
fi

POST_UPLOADS_COUNT=$(find "$ROOT_DIR/public/uploads" -type f 2>/dev/null | wc -l || echo 0)

CHECKSUM_DRIFT=0
printf "%-36s | %-14s | %-14s | %-10s\n" "Table" "Pre Checksum" "Post Checksum" "Status"
echo "-------------------------------------+----------------+----------------+------------"

while IFS=$'\t' read -r tbl pre_ck; do
    post_ck=$(awk -v t="$tbl" '$1 == t {print $2}' "$POST_CHECKSUMS_FILE")
    if [ "$pre_ck" = "$post_ck" ]; then
        status="${GREEN}IDENTICAL${NC}"
    else
        status="${RED}DRIFT ($pre_ck -> $post_ck)${NC}"
        CHECKSUM_DRIFT=$((CHECKSUM_DRIFT + 1))
        OVERALL_STATUS=1
    fi
    printf "%-36s | %-14s | %-14s | %b\n" "$tbl" "$pre_ck" "$post_ck" "$status"
done < "$PRE_CHECKSUMS_FILE"

rm -f "$POST_CHECKSUMS_FILE"

echo ""
echo -e "${BOLD}--- Step 7: Verifying public/uploads file count isolation ---${NC}"
if [ "$PRE_UPLOADS_COUNT" -eq "$POST_UPLOADS_COUNT" ]; then
    echo -e "  [${GREEN}IDENTICAL${NC}] public/uploads files unchanged: $PRE_UPLOADS_COUNT -> $POST_UPLOADS_COUNT"
else
    echo -e "  [${RED}DRIFT${NC}] public/uploads files changed: $PRE_UPLOADS_COUNT -> $POST_UPLOADS_COUNT"
    CHECKSUM_DRIFT=$((CHECKSUM_DRIFT + 1))
    OVERALL_STATUS=1
fi
echo ""

# ------------------------------------------------------------------------------
# STEP 8: FINAL EXIT STATUS
# ------------------------------------------------------------------------------
if [ "$OVERALL_STATUS" -eq 0 ] && [ "$TOTAL_FAILED" -eq 0 ] && [ "$CHECKSUM_DRIFT" -eq 0 ]; then
    echo -e "${BOLD}${GREEN}==============================================================================${NC}"
    echo -e "${BOLD}${GREEN}  SUCCESS: ALL SUITES PASSED (0 FAILURES) AND ZERO DRIFT DETECTED IN $PROTECTED_DB!  ${NC}"
    echo -e "${BOLD}${GREEN}==============================================================================${NC}"
    exit 0
else
    echo -e "${BOLD}${RED}==============================================================================${NC}"
    echo -e "${BOLD}${RED}  FAILURE: TEST FAILURES ($TOTAL_FAILED) OR CHECKSUM DRIFT ($CHECKSUM_DRIFT) DETECTED!   ${NC}"
    echo -e "${BOLD}${RED}==============================================================================${NC}"
    exit 1
fi
