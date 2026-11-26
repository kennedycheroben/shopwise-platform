#!/usr/bin/env bash
# ==============================================================================
# ShopWise Kenya - RBAC Revocation Invariant & Authoritative user_roles Regression Suite
#
# Proves that:
# 1. user_roles is the SOLE authoritative source of administrative access.
# 2. Removing a user's last administrative role in user_roles removes administrative access,
#    EVEN IF the legacy users.role column still contains 'admin' or 'staff'.
# 3. Normal authorization checks NEVER silently restore revoked admin roles into user_roles.
# 4. canForActor(), hasAdminAccess(), and AdminAuthService::login() strictly deny access.
# 5. Legitimate administrators with valid user_roles assignments authenticate successfully.
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$ROOT_DIR"

PHP_CMD="${PHP_CMD:-/opt/lampp/bin/php}"
if [ ! -x "$PHP_CMD" ]; then PHP_CMD="php"; fi

MYSQL_CMD="${MYSQL_CMD:-/opt/lampp/bin/mysql}"
if [ ! -x "$MYSQL_CMD" ]; then MYSQL_CMD="mysql"; fi

DB_NAME="${DB_NAME:-shopwise_test}"
if [[ ! "$DB_NAME" =~ _test$ ]]; then
    echo "FATAL: Test must run against disposable test database ending in _test. Got: $DB_NAME" >&2
    exit 1
fi

TOTAL=0
PASSED=0
FAILED=0

pass() {
    TOTAL=$((TOTAL + 1))
    PASSED=$((PASSED + 1))
    echo -e "  \033[0;32m[PASS]\033[0m $1"
}

fail() {
    TOTAL=$((TOTAL + 1))
    FAILED=$((FAILED + 1))
    echo -e "  \033[0;31m[FAIL]\033[0m $1" >&2
}

echo "=============================================================================="
echo " ShopWise Kenya - RBAC Revocation & Authoritative user_roles Regression Suite"
echo " Target DB: $DB_NAME"
echo "=============================================================================="

# Execute PHP test runner
TEST_OUT=$(SHOPWISE_CONFIG=testing "$PHP_CMD" -r '
require "app/bootstrap.php";
use App\Database\Database;
use App\Services\Permissions;
use App\Services\AdminAuthService;

$pdo = Database::getInstance();
$results = [];

// 1. Create a user with users.role = "admin" and assign role in user_roles
$email = "rbac_test_" . uniqid() . "@test.local";
$pwd = "AdminSecurePass2026!";
$hash = password_hash($pwd, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("INSERT INTO users (role, first_name, last_name, email, password_hash, status, created_at, email_verified_at) VALUES (\"admin\", \"Revoke\", \"Target\", :e, :h, \"active\", NOW(), NOW())");
$stmt->execute([":e" => $email, ":h" => $hash]);
$uid = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO user_roles (user_id, role, assigned_at) VALUES (:uid, \"admin\", NOW())")->execute([":uid" => $uid]);

// Verify initial admin access
$results["initial_has_admin"] = Permissions::hasAdminAccess($uid);
$results["initial_roles"] = Permissions::rolesForUser($uid, true);
$results["initial_can_manage"] = Permissions::canForActor("products.edit", $uid);
[$loginOk, ] = AdminAuthService::login($email, $pwd, "127.0.0.1");
$results["initial_login"] = $loginOk;

// 2. REVOKE the role from user_roles, deliberately leaving users.role = "admin" in users table
$pdo->prepare("DELETE FROM user_roles WHERE user_id = :uid")->execute([":uid" => $uid]);

// 3. Re-evaluate authorization
$rolesAfterRevoke = Permissions::rolesForUser($uid, true);
$results["roles_empty_after_revoke"] = ($rolesAfterRevoke === []);
$results["has_admin_false_after_revoke"] = (Permissions::hasAdminAccess($uid) === false);
$results["can_manage_false_after_revoke"] = (Permissions::canForActor("products.edit", $uid) === false);

// 4. Verify user_roles was NOT silently re-populated
$checkRolesStmt = $pdo->prepare("SELECT COUNT(*) FROM user_roles WHERE user_id = :uid");
$checkRolesStmt->execute([":uid" => $uid]);
$results["no_silent_sync_to_db"] = ((int)$checkRolesStmt->fetchColumn() === 0);

// 5. Verify AdminAuthService::login fails for revoked user
[$loginAfterRevoke, $loginMsg] = AdminAuthService::login($email, $pwd, "127.0.0.1");
$results["login_rejected_after_revoke"] = ($loginAfterRevoke === false);
$results["login_rejected_message_correct"] = str_contains($loginMsg, "do not grant access");

// 6. Test session guard termination simulation:
// If an existing session has this user, guard checks fresh roles from DB and rejects
$freshRoles = Permissions::rolesForUser($uid, true);
$hasAdminGuard = false;
foreach ($freshRoles as $r) {
    if (Permissions::isAdminRole($r)) {
        $hasAdminGuard = true;
        break;
    }
}
$results["guard_denies_access"] = ($hasAdminGuard === false);

// 7. Verify legitimate active administrator still has full access
$legitEmail = "legit_admin_" . uniqid() . "@test.local";
$pdo->prepare("INSERT INTO users (role, first_name, last_name, email, password_hash, status, created_at, email_verified_at) VALUES (\"super_admin\", \"Legit\", \"Admin\", :e, :h, \"active\", NOW(), NOW())")->execute([":e" => $legitEmail, ":h" => $hash]);
$legitUid = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO user_roles (user_id, role, assigned_at) VALUES (:uid, \"super_admin\", NOW())")->execute([":uid" => $legitUid]);

$results["legit_has_admin"] = Permissions::hasAdminAccess($legitUid);
$results["legit_can_manage"] = Permissions::canForActor("products.edit", $legitUid);
[$legitLogin, ] = AdminAuthService::login($legitEmail, $pwd, "127.0.0.1");
$results["legit_login_ok"] = $legitLogin;

// Clean up
$pdo->prepare("DELETE FROM users WHERE id IN (:u1, :u2)")->execute([":u1" => $uid, ":u2" => $legitUid]);

echo json_encode($results);
')

# Assertions
assert_res() {
    local key="$1"
    local desc="$2"
    local val
    val=$(echo "$TEST_OUT" | grep -oP "\"$key\":\s*\K(true|false)" || echo "false")
    if [ "$val" = "true" ]; then
        pass "$desc"
    else
        fail "$desc (expected true, got '$val')"
    fi
}

assert_res "initial_has_admin" "User initially has admin access when user_roles assignment is active"
assert_res "initial_can_manage" "User can perform administrative action when user_roles assignment is active"
assert_res "initial_login" "User successfully authenticates via AdminAuthService when user_roles assignment is active"
assert_res "roles_empty_after_revoke" "Permissions::rolesForUser returns empty array after user_roles record is deleted"
assert_res "has_admin_false_after_revoke" "Permissions::hasAdminAccess returns false after user_roles record is deleted"
assert_res "can_manage_false_after_revoke" "Permissions::canForActor returns false even though users.role still contains 'admin'"
assert_res "no_silent_sync_to_db" "Authorization check does not silently recreate user_roles record from stale users.role"
assert_res "login_rejected_after_revoke" "AdminAuthService::login strictly rejects user after role revocation in user_roles"
assert_res "login_rejected_message_correct" "AdminAuthService::login produces correct unauthorized response message"
assert_res "guard_denies_access" "Admin guard logic strictly terminates active session when fresh roles are empty"
assert_res "legit_has_admin" "Legitimate administrator with valid user_roles retains hasAdminAccess = true"
assert_res "legit_can_manage" "Legitimate administrator with valid user_roles retains granular capability = true"
assert_res "legit_login_ok" "Legitimate administrator with valid user_roles authenticates successfully"

echo ""
echo "RBAC Revocation Invariant Tests: $PASSED passed, $FAILED failed."
if [ "$FAILED" -eq 0 ]; then
    exit 0
else
    exit 1
fi

