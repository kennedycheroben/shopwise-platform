#!/usr/bin/env bash
# ==============================================================================
# ShopWise Platform - Milestone 2A: RBAC Security Hardening & Regression Suite
#
# Covers:
#   1. Database schema verification (roles, user_roles, users.role varchar)
#   2. Canonical 7-role permission matrix (positive & negative assertions)
#   3. Multi-role union resolution
#   4. Web Request Prohibition:
#      - Unauthenticated web request cannot enter system context (HTTP 403)
#      - Authenticated staff cannot enter system context via web (HTTP 403)
#      - Authenticated administrator cannot enter system context via web (HTTP 403)
#   5. CLI SecurityContext & Narrow Job Capabilities:
#      - Ordinary CLI code cannot acquire system permissions automatically
#      - Missing or invalid job identifier is rejected with InvalidArgumentException
#      - Order-expiration job restricted strictly to declared capabilities
#      - Email-dispatch job restricted strictly to declared capabilities (cannot refund or delete)
#      - Cart cleanup job restricted strictly to declared capabilities
#      - Context state cleared after successful execution
#      - Context state cleared after exceptions
#      - Nested execution strictly intersects capabilities (cannot broaden privileges)
#   6. Service layer defense with actor validation
#   7. Audit logging integration with system context
#
# STRICTLY TARGETS: shopwise_test
# NEVER touches shopwise_db
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

PASSED_COUNT=0
FAILED_COUNT=0

info() { echo -e "\n${CYAN}${BOLD}=== $1 ===${NC}"; }
pass() { echo -e "  ${GREEN}[PASS]${NC} $1"; ((PASSED_COUNT++)) || true; }
fail() { echo -e "  ${RED}[FAIL]${NC} $1"; ((FAILED_COUNT++)) || true; }

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
BASE_URL="${BASE_URL:-http://127.0.0.1/shopwise-platform/public}"

run_sql() {
    local sql="$1"
    if [ -n "$DB_PASS" ]; then
        "$MYSQL_CMD" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -s -e "$sql" 2>/dev/null
    else
        "$MYSQL_CMD" -u "$DB_USER" "$DB_NAME" -N -s -e "$sql" 2>/dev/null
    fi
}

echo "=============================================================================="
echo "   SHOPWISE PLATFORM — MILESTONE 2A SECURITY REGRESSION SUITE                "
echo "=============================================================================="
echo "Target Database: $DB_NAME"
echo "Target Base URL: $BASE_URL"

# ------------------------------------------------------------------------------
# 1. Database Schema Verification in shopwise_test
# ------------------------------------------------------------------------------
info "1. Database Schema Verification"

ROLES_TABLE=$(run_sql "SHOW TABLES LIKE 'roles';")
if [ "$ROLES_TABLE" = "roles" ]; then
    pass "Table 'roles' exists in $DB_NAME"
else
    fail "Table 'roles' missing in $DB_NAME"
fi

USER_ROLES_TABLE=$(run_sql "SHOW TABLES LIKE 'user_roles';")
if [ "$USER_ROLES_TABLE" = "user_roles" ]; then
    pass "Table 'user_roles' exists in $DB_NAME"
else
    fail "Table 'user_roles' missing in $DB_NAME"
fi

USER_ROLE_COL_TYPE=$(run_sql "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '$DB_NAME' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role';")
if [ "$USER_ROLE_COL_TYPE" = "varchar" ]; then
    pass "Column 'users.role' is VARCHAR (supports new departmental roles)"
else
    fail "Column 'users.role' expected varchar, got '$USER_ROLE_COL_TYPE'"
fi

ROLES_COUNT=$(run_sql "SELECT COUNT(*) FROM roles WHERE name IN ('super_admin', 'finance_manager', 'orders_manager', 'inventory_manager', 'catalog_manager', 'support_agent', 'marketing_manager');")
if [ "$ROLES_COUNT" -eq 7 ]; then
    pass "All 7 canonical departmental roles registered in 'roles' table"
else
    fail "Expected 7 canonical roles, found $ROLES_COUNT"
fi

# ------------------------------------------------------------------------------
# 2. Canonical 7-Role Permission Matrix Assertions (via PHP)
# ------------------------------------------------------------------------------
info "2. Canonical 7-Role Permission Matrix Assertions"

TEST_OUTPUT=$(SHOPWISE_CONFIG="testing" php -r "
require 'app/bootstrap.php';
use App\Services\Permissions;

\$results = [];

// super_admin: Universal authority
\$results['super_admin_all'] = Permissions::can('anything.possible', 'super_admin');
\$results['super_admin_financial'] = Permissions::can('dashboard.financial', 'super_admin');
\$results['super_admin_delete'] = Permissions::can('products.delete', 'super_admin');

// finance_manager: Payments & reconciliation allowed; Catalog & executive dashboard denied
\$results['finance_mark_paid'] = Permissions::can('orders.mark_as_paid', 'finance_manager');
\$results['finance_refund'] = Permissions::can('orders.refund', 'finance_manager');
\$results['finance_reconcile'] = Permissions::can('payments.reconcile', 'finance_manager');
\$results['finance_financial_dash'] = Permissions::can('dashboard.financial', 'finance_manager');
\$results['finance_no_exec_dash'] = !Permissions::can('dashboard.view', 'finance_manager');
\$results['finance_no_prod_create'] = !Permissions::can('products.create', 'finance_manager');
\$results['finance_no_prod_delete'] = !Permissions::can('products.delete', 'finance_manager');
\$results['finance_no_stock_adjust'] = !Permissions::can('inventory.adjust', 'finance_manager');

// orders_manager: Status transitions allowed; Refund & executive financial data denied
\$results['orders_status_trans'] = Permissions::can('orders.status_transition', 'orders_manager');
\$results['orders_packing_slip'] = Permissions::can('orders.packing_slip', 'orders_manager');
\$results['orders_delivery'] = Permissions::can('delivery.manage', 'orders_manager');
\$results['orders_no_refund'] = !Permissions::can('orders.refund', 'orders_manager');
\$results['orders_no_mark_paid'] = !Permissions::can('orders.mark_as_paid', 'orders_manager');
\$results['orders_no_financial_dash'] = !Permissions::can('dashboard.financial', 'orders_manager');

// inventory_manager: Stock adjust allowed; Price & refund denied
\$results['inventory_adjust'] = Permissions::can('inventory.adjust', 'inventory_manager');
\$results['inventory_movements'] = Permissions::can('inventory.movements', 'inventory_manager');
\$results['inventory_no_refund'] = !Permissions::can('orders.refund', 'inventory_manager');
\$results['inventory_no_prod_del'] = !Permissions::can('products.delete', 'inventory_manager');

// catalog_manager: Products & Variants CRUD allowed; Financials & stock adjust denied
\$results['catalog_prod_create'] = Permissions::can('products.create', 'catalog_manager');
\$results['catalog_prod_delete'] = Permissions::can('products.delete', 'catalog_manager');
\$results['catalog_cat_manage'] = Permissions::can('categories.manage', 'catalog_manager');
\$results['catalog_no_refund'] = !Permissions::can('orders.refund', 'catalog_manager');
\$results['catalog_no_stock_adjust'] = !Permissions::can('inventory.adjust', 'catalog_manager');

// support_agent: Inquiries & reviews moderate allowed; Review deletion & customer block denied
\$results['support_reviews_mod'] = Permissions::can('reviews.moderate', 'support_agent');
\$results['support_msg_reply'] = Permissions::can('messages.reply', 'support_agent');
\$results['support_no_rev_delete'] = !Permissions::can('reviews.delete', 'support_agent');
\$results['support_no_cust_block'] = !Permissions::can('customers.block', 'support_agent');
\$results['support_no_refund'] = !Permissions::can('orders.refund', 'support_agent');

// marketing_manager: Coupons allowed; Full ledger & refund denied
\$results['marketing_coupons_manage'] = Permissions::can('coupons.manage', 'marketing_manager');
\$results['marketing_coupons_delete'] = Permissions::can('coupons.delete', 'marketing_manager');
\$results['marketing_no_refund'] = !Permissions::can('orders.refund', 'marketing_manager');
\$results['marketing_no_mark_paid'] = !Permissions::can('orders.mark_as_paid', 'marketing_manager');

// customer: Storefront role has zero admin permissions
\$results['customer_no_orders_view'] = !Permissions::can('orders.view', 'customer');
\$results['customer_no_products_edit'] = !Permissions::can('products.edit', 'customer');

echo json_encode(\$results);
")

for key in $(echo "$TEST_OUTPUT" | jq -r 'keys[]'); do
    val=$(echo "$TEST_OUTPUT" | jq -r ".$key")
    if [ "$val" = "true" ]; then
        pass "Permission rule verified: $key"
    else
        fail "Permission rule violation: $key"
    fi
done

# ------------------------------------------------------------------------------
# 3. Multi-Role Union Resolution
# ------------------------------------------------------------------------------
info "3. Multi-Role Union Resolution"

MULTI_ROLE_OUTPUT=$(SHOPWISE_CONFIG="testing" php -r "
require 'app/bootstrap.php';
use App\Services\Permissions;

// User with dual roles: orders_manager + inventory_manager
\$roles = ['orders_manager', 'inventory_manager'];

\$canTransition = Permissions::can('orders.status_transition', \$roles);
\$canAdjustStock = Permissions::can('inventory.adjust', \$roles);
\$canRefund = Permissions::can('orders.refund', \$roles); // Neither has this

echo json_encode([
    'orders_and_inventory_transition' => \$canTransition,
    'orders_and_inventory_adjust'     => \$canAdjustStock,
    'orders_and_inventory_no_refund'  => !\$canRefund,
]);
")

for key in $(echo "$MULTI_ROLE_OUTPUT" | jq -r 'keys[]'); do
    val=$(echo "$MULTI_ROLE_OUTPUT" | jq -r ".$key")
    if [ "$val" = "true" ]; then
        pass "Multi-role union check verified: $key"
    else
        fail "Multi-role union check violation: $key"
    fi
done

# ------------------------------------------------------------------------------
# 4. Web Request Prohibition (Real HTTP Calls via curl)
# ------------------------------------------------------------------------------
info "4. Web Request System-Context Prohibition (Real HTTP Calls)"

# 4A. Unauthenticated web request
UNAUTH_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/test/security-context-entry")
if [ "$UNAUTH_CODE" = "403" ]; then
    pass "Unauthenticated web request blocked from system context (HTTP 403 Forbidden)"
else
    fail "Unauthenticated web request expected HTTP 403, got $UNAUTH_CODE"
fi

# Provision temporary staff and admin in shopwise_test to acquire real session cookies
TEST_PWD="SecContextPass123!"
COOKIE_DIR=$(mktemp -d)
COOKIE_STAFF="$COOKIE_DIR/staff.jar"
COOKIE_ADMIN="$COOKIE_DIR/admin.jar"
RUN_ID="$(date +%s)_$$"
STAFF_EMAIL="sec_staff_${RUN_ID}@test.local"
ADMIN_EMAIL="sec_admin_${RUN_ID}@test.local"

PWD_HASH=$(php -r "echo password_hash('$TEST_PWD', PASSWORD_DEFAULT);")
run_sql "INSERT INTO users (role, first_name, last_name, email, password_hash, status) VALUES ('staff', 'SecStaff', 'Test', '$STAFF_EMAIL', '$PWD_HASH', 'active');"
STAFF_USER_ID=$(run_sql "SELECT id FROM users WHERE email = '$STAFF_EMAIL';")
run_sql "INSERT INTO user_roles (user_id, role) VALUES ($STAFF_USER_ID, 'staff');"

run_sql "INSERT INTO users (role, first_name, last_name, email, password_hash, status) VALUES ('admin', 'SecAdmin', 'Test', '$ADMIN_EMAIL', '$PWD_HASH', 'active');"
ADMIN_USER_ID=$(run_sql "SELECT id FROM users WHERE email = '$ADMIN_EMAIL';")
run_sql "INSERT INTO user_roles (user_id, role) VALUES ($ADMIN_USER_ID, 'admin');"

# Log in staff member
curl -s -c "$COOKIE_STAFF" "$BASE_URL/admin/login.php" > /dev/null
CSRF_STAFF=$(curl -s -b "$COOKIE_STAFF" "$BASE_URL/admin/login.php" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | cut -d'"' -f4 || true)
curl -s -b "$COOKIE_STAFF" -c "$COOKIE_STAFF" -X POST "$BASE_URL/admin/login.php" \
    -d "csrf_token=$CSRF_STAFF&email=$STAFF_EMAIL&password=$TEST_PWD" > /dev/null

# Log in admin member
curl -s -c "$COOKIE_ADMIN" "$BASE_URL/admin/login.php" > /dev/null
CSRF_ADMIN=$(curl -s -b "$COOKIE_ADMIN" "$BASE_URL/admin/login.php" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | cut -d'"' -f4 || true)
curl -s -b "$COOKIE_ADMIN" -c "$COOKIE_ADMIN" -X POST "$BASE_URL/admin/login.php" \
    -d "csrf_token=$CSRF_ADMIN&email=$ADMIN_EMAIL&password=$TEST_PWD" > /dev/null

# 4B. Authenticated staff web request
STAFF_WEB_CODE=$(curl -s -b "$COOKIE_STAFF" -o /dev/null -w "%{http_code}" "$BASE_URL/test/security-context-entry")
if [ "$STAFF_WEB_CODE" = "403" ]; then
    pass "Authenticated staff web request blocked from system context (HTTP 403 Forbidden)"
else
    fail "Authenticated staff web request expected HTTP 403, got $STAFF_WEB_CODE"
fi

# 4C. Authenticated administrator web request
ADMIN_WEB_CODE=$(curl -s -b "$COOKIE_ADMIN" -o /dev/null -w "%{http_code}" "$BASE_URL/test/security-context-entry")
if [ "$ADMIN_WEB_CODE" = "403" ]; then
    pass "Authenticated administrator web request blocked from system context (HTTP 403 Forbidden)"
else
    fail "Authenticated administrator web request expected HTTP 403, got $ADMIN_WEB_CODE"
fi

# Cleanup web cookies and temporary accounts
rm -rf "$COOKIE_DIR"
run_sql "DELETE FROM user_roles WHERE user_id IN ($STAFF_USER_ID, $ADMIN_USER_ID);"
run_sql "DELETE FROM users WHERE id IN ($STAFF_USER_ID, $ADMIN_USER_ID);"

# ------------------------------------------------------------------------------
# 5. CLI SecurityContext & Narrow Job Capability Hardening
# ------------------------------------------------------------------------------
info "5. CLI SecurityContext & Narrow Job Capability Hardening"

SEC_CONTEXT_OUTPUT=$(SHOPWISE_CONFIG="testing" php -r "
require 'app/bootstrap.php';
use App\Services\Permissions;
use App\Services\SecurityContext;

\$checks = [];

// A. Ordinary CLI code cannot acquire system permissions automatically
\$checks['cli_ambient_no_dashboard'] = (Permissions::can('dashboard.view') === false);
\$checks['cli_ambient_no_expire']    = (Permissions::can('orders.expire') === false);
\$checks['cli_ambient_no_refund']    = (Permissions::can('orders.refund') === false);

// B. Missing or invalid job identifier is rejected
\$invalidJobRejected = false;
try {
    SecurityContext::runAsSystem(function() { return true; }, 'invalid_arbitrary_job_123');
} catch (\InvalidArgumentException \$e) {
    \$invalidJobRejected = true;
}
\$checks['invalid_job_rejected'] = \$invalidJobRejected;

// C. Order expiration job restricted strictly to declared capabilities
SecurityContext::runAsSystem(function() use (&\$checks) {
    \$checks['job_expire_can_expire']          = Permissions::can('orders.expire');
    \$checks['job_expire_can_transition']      = Permissions::can('orders.status_transition');
    \$checks['job_expire_can_inventory_adjust'] = Permissions::can('inventory.adjust');
    \$checks['job_expire_CANNOT_refund']        = (Permissions::can('orders.refund') === false);
    \$checks['job_expire_CANNOT_mark_paid']     = (Permissions::can('orders.mark_as_paid') === false);
    \$checks['job_expire_CANNOT_delete_prod']   = (Permissions::can('products.delete') === false);
    \$checks['job_expire_CANNOT_staff_manage']  = (Permissions::can('staff.manage') === false);
    \$checks['job_expire_CANNOT_dash_view']     = (Permissions::can('dashboard.view') === false);
}, SecurityContext::JOB_ORDER_EXPIRATION);

// D. Email dispatch job restricted strictly to declared capabilities
SecurityContext::runAsSystem(function() use (&\$checks) {
    \$checks['job_email_can_dispatch']   = Permissions::can('email.dispatch');
    \$checks['job_email_can_queue']      = Permissions::can('email.queue');
    \$checks['job_email_CANNOT_refund']   = (Permissions::can('orders.refund') === false);
    \$checks['job_email_CANNOT_prod_del'] = (Permissions::can('products.delete') === false);
}, SecurityContext::JOB_EMAIL_DISPATCHER);

// E. Cart cleanup job restricted strictly to declared capabilities
SecurityContext::runAsSystem(function() use (&\$checks) {
    \$checks['job_cart_can_purge']        = Permissions::can('carts.purge');
    \$checks['job_cart_can_rate_purge']   = Permissions::can('rate_limits.purge');
    \$checks['job_cart_CANNOT_refund']    = (Permissions::can('orders.refund') === false);
    \$checks['job_cart_CANNOT_dash_view'] = (Permissions::can('dashboard.view') === false);
}, SecurityContext::JOB_PURGE_CARTS);

// F. Context cleared after successful execution
\$checks['cleared_after_success'] = (SecurityContext::isSystem() === false);

// G. Context cleared after unhandled exception
try {
    SecurityContext::runAsSystem(function() {
        throw new \RuntimeException('Intentional worker error');
    }, SecurityContext::JOB_ORDER_EXPIRATION);
} catch (\RuntimeException \$e) {
    // Expected
}
\$checks['cleared_after_exception'] = (SecurityContext::isSystem() === false);

// H. Nested execution strictly intersects capabilities (cannot broaden privileges)
SecurityContext::runAsSystem(function() use (&\$checks) {
    // Parent: JOB_ORDER_EXPIRATION has ['orders.expire', 'orders.status_transition', 'inventory.adjust', 'payments.verify', 'email.queue', 'audit.log']
    SecurityContext::runAsSystem(function() use (&\$checks) {
        // Child: JOB_EMAIL_DISPATCHER has ['email.dispatch', 'email.queue', 'audit.log']
        // Effective intersection: ONLY ['email.queue', 'audit.log']
        \$checks['nested_intersection_retains_common'] = Permissions::can('email.queue');
        \$checks['nested_cannot_acquire_child_only']   = (Permissions::can('email.dispatch') === false);
        \$checks['nested_cannot_retain_parent_only']   = (Permissions::can('orders.expire') === false);
    }, SecurityContext::JOB_EMAIL_DISPATCHER);
}, SecurityContext::JOB_ORDER_EXPIRATION);

// I. Permissions::require verifies narrow job capability and blocks unauthorized action
\$requireBlockedRefund = false;
try {
    SecurityContext::runAsSystem(function() {
        Permissions::require('orders.refund');
    }, SecurityContext::JOB_ORDER_EXPIRATION);
} catch (\DomainException \$e) {
    \$requireBlockedRefund = true;
}
\$checks['job_require_blocks_unauthorized_capability'] = \$requireBlockedRefund;

echo json_encode(\$checks);
")

for key in $(echo "$SEC_CONTEXT_OUTPUT" | jq -r 'keys[]'); do
    val=$(echo "$SEC_CONTEXT_OUTPUT" | jq -r ".$key")
    if [ "$val" = "true" ]; then
        pass "SecurityContext check verified: $key"
    else
        fail "SecurityContext check violation: $key"
    fi
done

# ------------------------------------------------------------------------------
# 6. Service-Layer Enforcement with Actor Roles
# ------------------------------------------------------------------------------
info "6. Service-Layer Enforcement with Actor Roles"

SERVICE_TEST_OUTPUT=$(SHOPWISE_CONFIG="testing" php -r "
require 'app/bootstrap.php';
use App\Services\Permissions;
use App\Database\Database;

\$pdo = Database::getInstance();
\$checks = [];

// Provision temporary test accounts in shopwise_test
\$hash = password_hash('Pass123!', PASSWORD_DEFAULT);
\$pdo->prepare(\"INSERT INTO users (role, first_name, last_name, email, password_hash, status) VALUES ('finance_manager', 'Fin', 'User', 'test_fin_m2a@test.local', :h, 'active')\")->execute([':h' => \$hash]);
\$finId = (int)\$pdo->lastInsertId();
\$pdo->prepare(\"INSERT INTO user_roles (user_id, role) VALUES (:uid, 'finance_manager')\")->execute([':uid' => \$finId]);

\$pdo->prepare(\"INSERT INTO users (role, first_name, last_name, email, password_hash, status) VALUES ('orders_manager', 'Ord', 'User', 'test_ord_m2a@test.local', :h, 'active')\")->execute([':h' => \$hash]);
\$ordId = (int)\$pdo->lastInsertId();
\$pdo->prepare(\"INSERT INTO user_roles (user_id, role) VALUES (:uid, 'orders_manager')\")->execute([':uid' => \$ordId]);

// Finance manager can mark as paid
\$checks['fin_actor_can_mark_paid'] = Permissions::canForActor('orders.mark_as_paid', \$finId);
// Orders manager CANNOT mark as paid
\$checks['ord_actor_cannot_mark_paid'] = !Permissions::canForActor('orders.mark_as_paid', \$ordId);

// Verify Permissions::require throws DomainException in CLI for unauthorized actor
\$ordBlocked = false;
try {
    Permissions::require('orders.mark_as_paid', \$ordId);
} catch (\DomainException \$e) {
    \$ordBlocked = true;
}
\$checks['unauthorized_actor_throws_domain_exception'] = \$ordBlocked;

// Verify Permissions::require succeeds for authorized actor
\$finAllowed = false;
try {
    Permissions::require('orders.mark_as_paid', \$finId);
    \$finAllowed = true;
} catch (\DomainException \$e) {
    \$finAllowed = false;
}
\$checks['authorized_actor_passes_require'] = \$finAllowed;

// Cleanup test accounts
\$pdo->prepare(\"DELETE FROM user_roles WHERE user_id IN (:f, :o)\")->execute([':f' => \$finId, ':o' => \$ordId]);
\$pdo->prepare(\"DELETE FROM users WHERE id IN (:f, :o)\")->execute([':f' => \$finId, ':o' => \$ordId]);

echo json_encode(\$checks);
")

for key in $(echo "$SERVICE_TEST_OUTPUT" | jq -r 'keys[]'); do
    val=$(echo "$SERVICE_TEST_OUTPUT" | jq -r ".$key")
    if [ "$val" = "true" ]; then
        pass "Service actor enforcement verified: $key"
    else
        fail "Service actor enforcement violation: $key"
    fi
done

# ------------------------------------------------------------------------------
# 7. Audit Logging Integration with System Context
# ------------------------------------------------------------------------------
info "7. Audit Logging Integration with System Context"

AUDIT_TEST_OUTPUT=$(SHOPWISE_CONFIG="testing" php -r "
require 'app/bootstrap.php';
use App\Services\AuditLogger;
use App\Services\SecurityContext;
use App\Database\Database;

\$pdo = Database::getInstance();

SecurityContext::runAsSystem(function() {
    AuditLogger::log(0, 'cron_batch_test', 'order', 999, ['test_key' => 'test_val'], '127.0.0.1');
}, SecurityContext::JOB_ORDER_EXPIRATION);

\$stmt = \$pdo->query(\"SELECT details FROM audit_log WHERE action = 'cron_batch_test' ORDER BY id DESC LIMIT 1\");
\$rawDetails = \$stmt->fetchColumn();
\$details = json_decode(\$rawDetails ?: '{}', true);

\$hasSysExec = (\$details['_system_execution'] ?? false) === true;
\$hasJob = (\$details['_system_job'] ?? '') === SecurityContext::JOB_ORDER_EXPIRATION;

// Clean up audit test row
\$pdo->exec(\"DELETE FROM audit_log WHERE action = 'cron_batch_test'\");

echo json_encode([
    'audit_system_execution_flag' => \$hasSysExec,
    'audit_system_job_logged'     => \$hasJob,
]);
")

for key in $(echo "$AUDIT_TEST_OUTPUT" | jq -r 'keys[]'); do
    val=$(echo "$AUDIT_TEST_OUTPUT" | jq -r ".$key")
    if [ "$val" = "true" ]; then
        pass "Audit logger system context verified: $key"
    else
        fail "Audit logger system context violation: $key"
    fi
done

echo ""
echo "=============================================================================="
echo -e "MILESTONE 2A TEST SUMMARY: ${GREEN}$PASSED_COUNT Passed${NC}, ${RED}$FAILED_COUNT Failed${NC}"
echo "=============================================================================="

if [ "$FAILED_COUNT" -gt 0 ]; then
    exit 1
fi
exit 0
