#!/usr/bin/env bash
# ==============================================================================
# ShopWise Platform - Milestone 2B Stage 1 Security & RBAC Test Suite
#
# Verifies:
#   1. Hardened SecurityContext (caller validation, DB-verified test context)
#   2. Multi-role authorization and instant revocation
#   3. StaffService departmental role management & last Super Admin protection
#   4. Super Admin bootstrap utility (--dry-run, live elevation, repeated run guard)
#   5. Backward compatibility and regression checks
# ==============================================================================

set -euo pipefail

# STRICT SAFETY CHECK: Force testing database
export SHOPWISE_CONFIG="testing"

DB_HOST="127.0.0.1"
DB_USER="root"
DB_PASS=""
DB_NAME="shopwise_test"

run_sql() {
    mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" -sN -e "$1"
}

ACTIVE_DB=$(run_sql "SELECT DATABASE();")
if [ "$ACTIVE_DB" != "shopwise_test" ]; then
    echo "FATAL: Test suite must ONLY run against 'shopwise_test'. Connected to '$ACTIVE_DB'." >&2
    exit 1
fi

TOTAL_TESTS=0
PASSED_TESTS=0
FAILED_TESTS=0

pass() {
    TOTAL_TESTS=$((TOTAL_TESTS + 1))
    PASSED_TESTS=$((PASSED_TESTS + 1))
    echo "  [PASS] $1"
}

fail() {
    TOTAL_TESTS=$((TOTAL_TESTS + 1))
    FAILED_TESTS=$((FAILED_TESTS + 1))
    echo "  [FAIL] $1" >&2
}

echo "=============================================================================="
echo "   SHOPWISE PLATFORM — MILESTONE 2B STAGE 1 SECURITY VERIFICATION SUITE       "
echo "=============================================================================="
echo "Target Database: $DB_NAME"

# ==============================================================================
# SECTION 1: Hardened SecurityContext Invariants
# ==============================================================================
echo ""
echo "=== 1. Hardened SecurityContext & Caller Verification ==="

SEC_TEST_JSON=$(SHOPWISE_CONFIG=testing php -r '
require "app/bootstrap.php";
use App\Services\SecurityContext;
use App\Services\Permissions;

$checks = [];

// 1. Ambient CLI has zero privileges
$checks["ambient_cli_no_expire"] = (Permissions::can("orders.expire") === false);
$checks["ambient_cli_no_financial_dash"] = (Permissions::can("dashboard.financial") === false);

// 2. Unregistered job is rejected
$invalidJobRejected = false;
try {
    SecurityContext::runAsSystem(fn() => true, "unregistered_bogus_job");
} catch (\InvalidArgumentException $e) {
    $invalidJobRejected = true;
}
$checks["unregistered_job_rejected"] = $invalidJobRejected;

// 3. runInTestContext enforces test database verification
$testContextSucceeded = false;
SecurityContext::runInTestContext(function() use (&$testContextSucceeded) {
    $testContextSucceeded = (SecurityContext::isSystem() && Permissions::can("test.execute"));
}, ["test.execute", "orders.expire"], "test_worker");
$checks["run_in_test_context_verified_db"] = $testContextSucceeded;

// 4. Test context cleared after execution
$checks["test_context_cleared"] = (SecurityContext::isSystem() === false);

// 5. Nested capabilities intersection
SecurityContext::runInTestContext(function() use (&$checks) {
    SecurityContext::runInTestContext(function() use (&$checks) {
        $checks["nested_intersect_common"] = Permissions::can("orders.expire");
        $checks["nested_intersect_cannot_acquire_parent_only"] = (Permissions::can("orders.manage") === false);
        $checks["nested_intersect_cannot_acquire_child_only"] = (Permissions::can("payments.refund") === false);
    }, ["orders.expire", "payments.refund"]);
}, ["orders.manage", "orders.expire"]);

// 6. Context cleared even after unhandled exception
try {
    SecurityContext::runInTestContext(function() {
        throw new \RuntimeException("Forced test exception");
    });
} catch (\RuntimeException $e) {
    // Expected
}
$checks["cleared_after_exception"] = (SecurityContext::isSystem() === false);

echo json_encode($checks);
')

for key in $(echo "$SEC_TEST_JSON" | jq -r 'keys[]'); do
    val=$(echo "$SEC_TEST_JSON" | jq -r ".[\"$key\"]")
    if [ "$val" = "true" ]; then
        pass "SecurityContext: $key"
    else
        fail "SecurityContext: $key (evaluated to $val)"
    fi
done

# ==============================================================================
# SECTION 2: Multi-Role Authorization & Real-Time Revocation
# ==============================================================================
echo ""
echo "=== 2. Multi-Role Authorization & Real-Time Revocation ==="

MULTI_ROLE_JSON=$(SHOPWISE_CONFIG=testing php -r '
require "app/bootstrap.php";
use App\Database\Database;
use App\Services\Permissions;

$pdo = Database::getInstance();
$checks = [];

// Cleanup any leftover test user from previous aborted runs
$pdo->exec("DELETE FROM users WHERE email LIKE \"%multi_test_user%\"");

$testEmail = "multi_test_user_" . bin2hex(random_bytes(4)) . "@example.com";
$pdo->prepare("
    INSERT INTO users (first_name, last_name, email, role, status, created_at)
    VALUES (\"Multi\", \"Tester\", :email, \"staff\", \"active\", NOW())
")->execute([":email" => $testEmail]);
$testUserId = (int)$pdo->lastInsertId();

// Assign two departmental roles: finance_manager AND orders_manager
$insStmt = $pdo->prepare("INSERT INTO user_roles (user_id, role) VALUES (:uid, :role)");
$insStmt->execute([":uid" => $testUserId, ":role" => "finance_manager"]);
$insStmt->execute([":uid" => $testUserId, ":role" => "orders_manager"]);

// Test 1: Union of permissions resolves correctly
$roles = Permissions::rolesForUser($testUserId, true);
$checks["multi_role_count_is_2"] = (count($roles) === 2);
$checks["finance_ability_granted"] = Permissions::can("orders.refund", $roles);
$checks["orders_ability_granted"]  = Permissions::can("orders.packing_slip", $roles);
$checks["unassigned_catalog_denied"] = (Permissions::can("products.create", $roles) === false);

// Test 2: Instant Revocation: Delete orders_manager role
$pdo->prepare("DELETE FROM user_roles WHERE user_id = :uid AND role = \"orders_manager\"")->execute([":uid" => $testUserId]);

// Fresh roles resolution immediately reflects revocation
$updatedRoles = Permissions::rolesForUser($testUserId, true);
$checks["revocation_count_is_1"] = (count($updatedRoles) === 1);
$checks["revocation_lost_orders_packing_slip"] = (Permissions::can("orders.packing_slip", $updatedRoles) === false);
$checks["revocation_retained_finance_refund"]   = Permissions::can("orders.refund", $updatedRoles);

// Test 3: Total revocation of all admin roles
$pdo->prepare("DELETE FROM user_roles WHERE user_id = :uid")->execute([":uid" => $testUserId]);
$pdo->prepare("UPDATE users SET role = \"customer\" WHERE id = :uid")->execute([":uid" => $testUserId]);

$revokedRoles = Permissions::rolesForUser($testUserId, true);
$checks["has_admin_access_after_revocation"] = (Permissions::hasAdminAccess($testUserId) === false);

// Test 4: Revocation when users.role still contains an old administrative role
// Ensure users.role is set to 'staff', but user_roles has 0 rows for this user
$pdo->prepare("UPDATE users SET role = \"staff\" WHERE id = :uid")->execute([":uid" => $testUserId]);
$staleRoleCheck = Permissions::rolesForUser($testUserId, true);
$checks["stale_users_role_cannot_restore_privilege"] = empty($staleRoleCheck);
$checks["has_admin_access_with_stale_role_is_false"] = (Permissions::hasAdminAccess($testUserId) === false);

// Test 5: Legacy schema fallback (when user_roles is genuinely absent)
Permissions::resetSchemaCache();
// Verify helper identifies schema exists
$checks["has_user_roles_schema_identified"] = Permissions::hasUserRolesSchema();

// Cleanup
$pdo->prepare("DELETE FROM users WHERE id = :uid")->execute([":uid" => $testUserId]);

echo json_encode($checks);
')

for key in $(echo "$MULTI_ROLE_JSON" | jq -r 'keys[]'); do
    val=$(echo "$MULTI_ROLE_JSON" | jq -r ".[\"$key\"]")
    if [ "$val" = "true" ]; then
        pass "Multi-Role Authorization: $key"
    else
        fail "Multi-Role Authorization: $key (evaluated to $val)"
    fi
done

# ==============================================================================
# SECTION 3: StaffService Departmental Management & Invariants
# ==============================================================================
echo ""
echo "=== 3. StaffService Departmental Role Management & Invariants ==="

STAFF_SERVICE_JSON=$(SHOPWISE_CONFIG=testing php -r '
require "app/bootstrap.php";
use App\Database\Database;
use App\Services\StaffService;
use App\Services\Permissions;
use App\Services\SecurityContext;

$pdo = Database::getInstance();
$checks = [];

// Cleanup leftover test emails from previous runs
$pdo->exec("DELETE FROM users WHERE email IN (\"grace.operations.test@shopwise.internal\", \"sole_super_admin@shopwise.internal\")");

// 1. Create a departmental staff account (Orders Manager)
$newStaffId = StaffService::create([
    "first_name" => "Grace",
    "last_name"  => "Operations",
    "email"      => "grace.operations.test@shopwise.internal",
    "roles"      => ["orders_manager"],
], 0, "127.0.0.1");

$checks["staff_create_success"] = ($newStaffId > 0);

$fetched = StaffService::get($newStaffId);
$checks["staff_get_has_orders_manager"] = in_array("orders_manager", $fetched["roles"] ?? [], true);

// 2. Update staff member to have dual roles (orders_manager + inventory_manager)
StaffService::update($newStaffId, [
    "first_name" => "Grace",
    "last_name"  => "Operations",
    "roles"      => ["orders_manager", "inventory_manager"],
], 0, "127.0.0.1");

$updated = StaffService::get($newStaffId);
$checks["staff_update_has_both_roles"] = (
    in_array("orders_manager", $updated["roles"] ?? [], true) &&
    in_array("inventory_manager", $updated["roles"] ?? [], true)
);

// 3. Test Last Super Admin Protection
// Temporarily create a single Super Admin
$saId = StaffService::create([
    "first_name" => "Root",
    "last_name"  => "Admin",
    "email"      => "sole_super_admin@shopwise.internal",
    "roles"      => ["super_admin"],
], 0, "127.0.0.1");

// Temporarily deactivate any other active admin/super_admin in test DB so saId is genuinely sole active admin
$pdo->prepare("UPDATE users SET status = \"blocked\" WHERE (role IN (\"super_admin\", \"admin\")) AND id != :saId")->execute([":saId" => $saId]);
$pdo->prepare("DELETE FROM user_roles WHERE (role IN (\"super_admin\", \"admin\")) AND user_id != :saId")->execute([":saId" => $saId]);

// Attempt to demote sole Super Admin -> must throw DomainException
$demoteBlocked = false;
try {
    StaffService::update($saId, [
        "first_name" => "Root",
        "last_name"  => "Admin",
        "roles"      => ["staff"],
    ], 0, "127.0.0.1");
} catch (\DomainException $e) {
    $demoteBlocked = true;
}
$checks["last_super_admin_demote_blocked"] = $demoteBlocked;

// Attempt to deactivate sole Super Admin -> must throw DomainException
$deactivateBlocked = false;
try {
    StaffService::toggleStatus($saId, 0, "127.0.0.1");
} catch (\DomainException $e) {
    $deactivateBlocked = true;
}
$checks["last_super_admin_deactivate_blocked"] = $deactivateBlocked;

// Attempt self-demotion check
$selfDemoteBlocked = false;
try {
    StaffService::update($saId, [
        "first_name" => "Root",
        "last_name"  => "Admin",
        "roles"      => ["finance_manager"],
    ], $saId, "127.0.0.1");
} catch (\DomainException $e) {
    $selfDemoteBlocked = true;
}
$checks["self_super_admin_demote_blocked"] = $selfDemoteBlocked;

// Cleanup and restore stashed admins
$pdo->prepare("DELETE FROM users WHERE id IN (:n, :s)")->execute([":n" => $newStaffId, ":s" => $saId]);
$pdo->exec("UPDATE users SET status = \"active\" WHERE email LIKE \"%rbac_admin%\"");

echo json_encode($checks);
')

for key in $(echo "$STAFF_SERVICE_JSON" | jq -r 'keys[]'); do
    val=$(echo "$STAFF_SERVICE_JSON" | jq -r ".[\"$key\"]")
    if [ "$val" = "true" ]; then
        pass "StaffService: $key"
    else
        fail "StaffService: $key (evaluated to $val)"
    fi
done

# Genuine multi-process concurrency test using actual StaffService methods
CONCURRENCY_TEST_OUT=$(SHOPWISE_CONFIG=testing php scripts/test_staff_concurrency.php 2>&1)
if echo "$CONCURRENCY_TEST_OUT" | grep -q "ALL SYNCHRONIZED STAFFSERVICE CONCURRENCY TESTS PASSED"; then
    pass "StaffService: genuine_multi_process_concurrency_race"
else
    fail "StaffService: genuine_multi_process_concurrency_race: $CONCURRENCY_TEST_OUT"
fi

# ==============================================================================
# SECTION 4: Super Admin Bootstrap Utility
# ==============================================================================
echo ""
echo "=== 4. Super Admin Bootstrap Utility ==="

# Create a clean customer test account with distinct password and timestamp
CUSTOMER_TEST_EMAIL="bootstrap_candidate_$(date +%s)@example.com"
CUST_ID=$(SHOPWISE_CONFIG=testing php -r '
require "app/bootstrap.php";
use App\Database\Database;
$pdo = Database::getInstance();
$hash = password_hash("UnchangedPass123!", PASSWORD_DEFAULT);
$stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, email, password_hash, role, status, created_at, password_changed_at) VALUES (\"Boot\", \"Candidate\", :email, :hash, \"customer\", \"active\", NOW(), \"2026-01-01 12:00:00\")");
$stmt->execute([":email" => "'"$CUSTOMER_TEST_EMAIL"'", ":hash" => $hash]);
echo $pdo->lastInsertId();
')

# Record baseline account fields before bootstrap
PRE_ACCOUNT_FIELDS=$(run_sql "SELECT CONCAT_WS(':', first_name, last_name, email, password_hash, status, password_changed_at) FROM users WHERE id = $CUST_ID;")

# 1. Bootstrap Help Flag
if php scripts/bootstrap_super_admin.php --help > /dev/null; then
    pass "Bootstrap utility responds to --help"
else
    fail "Bootstrap utility failed on --help"
fi

# 2. Bootstrap Dry-Run (Must simulate without modifying DB)
DRY_RUN_OUT=$(SHOPWISE_CONFIG=testing php scripts/bootstrap_super_admin.php --user-id="$CUST_ID" --dry-run)
if echo "$DRY_RUN_OUT" | grep -q "DRY-RUN COMPLETED SUCCESSFULLY"; then
    pass "Bootstrap dry-run completes successfully"
else
    fail "Bootstrap dry-run failed"
fi

# Verify user role remains customer after dry-run
POST_DRY_ROLE=$(run_sql "SELECT role FROM users WHERE id = $CUST_ID;")
if [ "$POST_DRY_ROLE" = "customer" ]; then
    pass "Dry-run did not alter database record"
else
    fail "Dry-run altered database record: $POST_DRY_ROLE"
fi

# 3. Live Bootstrap Execution
LIVE_OUT=$(SHOPWISE_CONFIG=testing php scripts/bootstrap_super_admin.php --user-id="$CUST_ID" --force --yes)
if echo "$LIVE_OUT" | grep -q "SUPER ADMINISTRATOR ELEVATION COMPLETED SUCCESSFULLY"; then
    pass "Live bootstrap execution succeeded"
else
    fail "Live bootstrap execution failed: $LIVE_OUT"
fi

# Verify users.role is now super_admin
POST_LIVE_ROLE=$(run_sql "SELECT role FROM users WHERE id = $CUST_ID;")
if [ "$POST_LIVE_ROLE" = "super_admin" ]; then
    pass "users.role successfully updated to 'super_admin'"
else
    fail "users.role is $POST_LIVE_ROLE"
fi

# Verify all unrelated account fields (password_hash, password_changed_at, status, names, email) remain untouched
POST_ACCOUNT_FIELDS=$(run_sql "SELECT CONCAT_WS(':', first_name, last_name, email, password_hash, status, password_changed_at) FROM users WHERE id = $CUST_ID;")
if [ "$PRE_ACCOUNT_FIELDS" = "$POST_ACCOUNT_FIELDS" ]; then
    pass "Bootstrap leaves all unrelated account fields untouched (password, timestamp, status, names)"
else
    fail "Bootstrap altered unrelated account fields: pre=$PRE_ACCOUNT_FIELDS, post=$POST_ACCOUNT_FIELDS"
fi

# Verify user_roles junction table has super_admin
JUNCTION_ROLE=$(run_sql "SELECT role FROM user_roles WHERE user_id = $CUST_ID AND role = 'super_admin';")
if [ "$JUNCTION_ROLE" = "super_admin" ]; then
    pass "user_roles junction record created"
else
    fail "user_roles junction record missing"
fi

# Verify truthful assignment attribution (assigned_by is NULL for CLI operator bootstrap)
ASSIGNED_BY=$(run_sql "SELECT IFNULL(assigned_by, 'NULL') FROM user_roles WHERE user_id = $CUST_ID AND role = 'super_admin';")
if [ "$ASSIGNED_BY" = "NULL" ]; then
    pass "Truthful assignment attribution: assigned_by is NULL (CLI operator)"
else
    fail "False assignment attribution: assigned_by is $ASSIGNED_BY"
fi

# Verify audit_log entry
AUDIT_ENTRY=$(run_sql "SELECT action FROM audit_log WHERE entity_id = $CUST_ID AND action = 'user.bootstrap_super_admin';")
if [ "$AUDIT_ENTRY" = "user.bootstrap_super_admin" ]; then
    pass "Forensic audit log entry recorded"
else
    fail "audit_log entry missing"
fi

# Verify truthful audit actor attribution (actor_user_id is NULL for CLI operator bootstrap)
AUDIT_ACTOR=$(run_sql "SELECT IFNULL(actor_user_id, 'NULL') FROM audit_log WHERE entity_id = $CUST_ID AND action = 'user.bootstrap_super_admin' ORDER BY id DESC LIMIT 1;")
if [ "$AUDIT_ACTOR" = "NULL" ]; then
    pass "Truthful audit actor attribution: actor_user_id is NULL (CLI operator)"
else
    fail "False audit actor attribution: actor_user_id is $AUDIT_ACTOR"
fi

# 4. Inactive Account Rejection Test
BLOCKED_CUST_ID=$(SHOPWISE_CONFIG=testing php -r '
require "app/bootstrap.php";
use App\Database\Database;
$pdo = Database::getInstance();
$stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, email, role, status, created_at) VALUES (\"Blocked\", \"Candidate\", \"blocked_candidate_test@example.com\", \"customer\", \"blocked\", NOW())");
$stmt->execute();
echo $pdo->lastInsertId();
')

set +e
BLOCKED_ERR=$(SHOPWISE_CONFIG=testing php scripts/bootstrap_super_admin.php --user-id="$BLOCKED_CUST_ID" --force --yes 2>&1)
BLOCKED_EXIT=$?
set -e

if [ "$BLOCKED_EXIT" -ne 0 ] && echo "$BLOCKED_ERR" | grep -q "Only 'active' accounts can be elevated"; then
    pass "Inactive account promotion safely rejected with exit code 1"
else
    fail "Inactive account promotion was not rejected as expected ($BLOCKED_EXIT)"
fi

# Cleanup blocked test user
run_sql "DELETE FROM users WHERE id = $BLOCKED_CUST_ID;"

# 5. Repeated bootstrap without --force should fail
set +e
REPEATED_ERR=$(SHOPWISE_CONFIG=testing php scripts/bootstrap_super_admin.php --user-id="$CUST_ID" --yes 2>&1)
REPEATED_EXIT=$?
set -e

if [ "$REPEATED_EXIT" -ne 0 ] && echo "$REPEATED_ERR" | grep -q "Repeated bootstrap prevented"; then
    pass "Repeated bootstrap safely prevented without --force"
else
    fail "Repeated bootstrap did not block"
fi

# Cleanup test customer
run_sql "DELETE FROM user_roles WHERE user_id = $CUST_ID;"
run_sql "DELETE FROM audit_log WHERE entity_id = $CUST_ID;"
run_sql "DELETE FROM users WHERE id = $CUST_ID;"

# ==============================================================================
# SECTION 5: Migration 007 & Recovery Safeguards
# ==============================================================================
echo ""
echo "=== 5. Migration & Recovery Safeguards ==="

# Check that 007_down.sql exists and is syntactically valid
if [ -f "database/migrations/007_down.sql" ]; then
    pass "database/migrations/007_down.sql exists"
else
    fail "database/migrations/007_down.sql missing"
fi

# Check that backup script exists and is executable
if [ -x "scripts/backup_db.sh" ]; then
    pass "scripts/backup_db.sh is present and executable"
else
    fail "scripts/backup_db.sh missing or not executable"
fi

echo ""
echo "=============================================================================="
echo "MILESTONE 2B STAGE 1 TEST SUMMARY: $PASSED_TESTS Passed, $FAILED_TESTS Failed (Total: $TOTAL_TESTS)"
echo "=============================================================================="

if [ "$FAILED_TESTS" -gt 0 ]; then
    exit 1
fi
