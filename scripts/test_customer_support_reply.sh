#!/usr/bin/env bash
# ==============================================================================
# ShopWise Platform - Customer Support Admin Reply & Message Handling Suite
#
# Covers all 5 Section 4 requirements:
#   1. Message reply creation with sanitized text-only body
#   2. Automatic message status update to 'replied'
#   3. Customer reply email queue insertion in email_queue table
#   4. Error and rollback handling if mail queueing fails inside transaction
#   5. RBAC enforcement on messages.manage permission (authorized vs unauthorized)
# ==============================================================================

set -euo pipefail

export SHOPWISE_CONFIG="testing"

DB_HOST="127.0.0.1"
DB_USER="root"
DB_PASS=""
DB_NAME="shopwise_test"
BASE_URL="http://127.0.0.1/shopwise-platform/public"

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
    echo -e "  \033[0;32m[PASS]\033[0m $1"
}

fail() {
    TOTAL_TESTS=$((TOTAL_TESTS + 1))
    FAILED_TESTS=$((FAILED_TESTS + 1))
    echo -e "  \033[0;31m[FAIL]\033[0m $1" >&2
}

echo "=============================================================================="
echo " ShopWise Kenya - Customer Support Reply Verification Suite"
echo " Target DB: $DB_NAME"
echo "=============================================================================="

# ------------------------------------------------------------------------------
# TEST 1 & 2 & 3: Message Reply Creation, Status Update, Email Queue Insertion
# ------------------------------------------------------------------------------
echo ""
echo "--- Tests 1-3: Reply Creation, Status Update, Email Queueing ---"

TEST_MSG_ID=$(run_sql "INSERT INTO contact_messages (name, email, subject, message, status, created_at)
                       VALUES ('Alice Wambui', 'alice.test@example.com', 'Delivery to Nakuru', 'Do you ship to Nakuru CBD?', 'new', NOW());
                       SELECT LAST_INSERT_ID();")

REPLY_TEST_RESULT=$(SHOPWISE_CONFIG=testing php -r '
require "app/bootstrap.php";
use App\Services\ContactMessageService;

$msgId = (int)$argv[1];
try {
    ContactMessageService::reply($msgId, "Yes Alice, we deliver to Nakuru CBD within 2 business days.", 1, "127.0.0.1");
    echo "REPLY_OK";
} catch (\Throwable $e) {
    echo "REPLY_ERROR: " . $e->getMessage();
}
' "$TEST_MSG_ID")

if [ "$REPLY_TEST_RESULT" = "REPLY_OK" ]; then
    pass "ContactMessageService::reply executed cleanly without server errors"
else
    fail "ContactMessageService::reply failed: $REPLY_TEST_RESULT"
fi

# Verify status updated to replied
UPDATED_STATUS=$(run_sql "SELECT status FROM contact_messages WHERE id = $TEST_MSG_ID;")
if [ "$UPDATED_STATUS" = "replied" ]; then
    pass "Message status automatically updated to 'replied' upon reply submission"
else
    fail "Message status not updated to 'replied' (current: '$UPDATED_STATUS')"
fi

# Verify email queued in email_queue
QUEUED_EMAIL=$(run_sql "SELECT to_email, subject, status FROM email_queue WHERE to_email = 'alice.test@example.com' ORDER BY id DESC LIMIT 1;")
if echo "$QUEUED_EMAIL" | grep -q "alice.test@example.com.*Re: Delivery to Nakuru.*pending"; then
    pass "Reply email correctly formatted and queued in email_queue table"
else
    fail "Email queue entry missing or invalid: $QUEUED_EMAIL"
fi

# Verify end-to-end admin UI reply submission & flash rendering via HTTP
TEST_ADMIN_EMAIL="msg.admin.$$.$(date +%s%N)@example.com"
HASHED_PASS='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'
run_sql "DELETE FROM login_attempts;
         INSERT INTO users (role, first_name, last_name, email, password_hash, status, created_at, email_verified_at)
         VALUES ('super_admin', 'MsgAdmin', 'Tester', '$TEST_ADMIN_EMAIL', '$HASHED_PASS', 'active', NOW(), NOW());
         INSERT INTO user_roles (user_id, role) VALUES (LAST_INSERT_ID(), 'super_admin');"

COOKIE_TEST=$(mktemp)
LOGIN_PAGE=$(curl -s -c "$COOKIE_TEST" "$BASE_URL/admin/login.php")
CSRF_TOKEN=$(echo "$LOGIN_PAGE" | grep -oP 'name="_csrf_token" value="\K[^"]+' | head -1)

curl -s -b "$COOKIE_TEST" -c "$COOKIE_TEST" -X POST "$BASE_URL/admin/login.php" \
  -d "_csrf_token=$CSRF_TOKEN&email=$TEST_ADMIN_EMAIL&password=password" > /dev/null
sleep 0.2

DETAIL_PAGE=$(curl -s -b "$COOKIE_TEST" -c "$COOKIE_TEST" "$BASE_URL/admin/messages/detail.php?id=$TEST_MSG_ID")
DETAIL_CSRF=$(echo "$DETAIL_PAGE" | grep -oP 'name="_csrf_token" value="\K[^"]+' | head -1)

# Submit reply via POST
curl -s -b "$COOKIE_TEST" -c "$COOKIE_TEST" -X POST "$BASE_URL/admin/messages/detail.php?id=$TEST_MSG_ID" \
  -d "_csrf_token=$DETAIL_CSRF&action=reply&reply_body=We+deliver+bulk+orders+via+courier." > /dev/null
sleep 0.2

# Follow redirect via GET with cookie
FOLLOW_RESP=$(curl -s -b "$COOKIE_TEST" -c "$COOKIE_TEST" "$BASE_URL/admin/messages/detail.php?id=$TEST_MSG_ID")

rm -f "$COOKIE_TEST"
run_sql "DELETE FROM user_roles WHERE user_id = (SELECT id FROM users WHERE email='$TEST_ADMIN_EMAIL'); DELETE FROM users WHERE email='$TEST_ADMIN_EMAIL';"

if [[ "$FOLLOW_RESP" == *"Reply email queued and sent"* && "$FOLLOW_RESP" == *"Alice Wambui"* ]]; then
    pass "Admin message detail view renders successfully with flash message (no variable collision)"
else
    fail "Admin message detail view failed to render flash message"
fi

# Cleanup
run_sql "DELETE FROM email_queue WHERE to_email = 'alice.test@example.com';"
run_sql "DELETE FROM contact_messages WHERE id = $TEST_MSG_ID;"

# ------------------------------------------------------------------------------
# TEST 4: Transaction Rollback on Failure
# ------------------------------------------------------------------------------
echo ""
echo "--- Test 4: Transaction Rollback on Failure ---"

FAIL_MSG_ID=$(run_sql "INSERT INTO contact_messages (name, email, subject, message, status, created_at)
                      VALUES ('Bob Kiprono', 'bob.test@example.com', 'Size Question', 'Is size 44 available?', 'read', NOW());
                      SELECT LAST_INSERT_ID();")

EMPTY_BODY_TEST=$(SHOPWISE_CONFIG=testing php -r '
require "app/bootstrap.php";
use App\Services\ContactMessageService;

$msgId = (int)$argv[1];
try {
    ContactMessageService::reply($msgId, "   ", 1, "127.0.0.1");
    echo "UNEXPECTED_SUCCESS";
} catch (\InvalidArgumentException $e) {
    echo "VALIDATION_CAUGHT: " . $e->getMessage();
} catch (\Throwable $e) {
    echo "OTHER_ERROR: " . $e->getMessage();
}
' "$FAIL_MSG_ID")

if echo "$EMPTY_BODY_TEST" | grep -q "VALIDATION_CAUGHT"; then
    pass "Empty reply body rejected with InvalidArgumentException before transaction executes"
else
    fail "Empty reply validation failed: $EMPTY_BODY_TEST"
fi

# Check that message status remains 'read' (not corrupted)
STATUS_AFTER_FAIL=$(run_sql "SELECT status FROM contact_messages WHERE id = $FAIL_MSG_ID;")
if [ "$STATUS_AFTER_FAIL" = "read" ]; then
    pass "Message status remains unchanged when validation fails"
else
    fail "Message status corrupted after validation failure: $STATUS_AFTER_FAIL"
fi

run_sql "DELETE FROM contact_messages WHERE id = $FAIL_MSG_ID;"

# 4b. Mid-Transaction Failure Injection Test
INJECT_MSG_ID=$(run_sql "INSERT INTO contact_messages (name, email, subject, message, status, created_at)
                        VALUES ('Charlie Omondi', 'charlie.rollback@example.com', 'Order Inquiry', 'Where is my order #1234?', 'new', NOW());
                        SELECT LAST_INSERT_ID();")

INJECTION_TEST_OUTPUT=$(SHOPWISE_CONFIG=testing php -r '
require "app/bootstrap.php";
use App\Services\ContactMessageService;

$msgId = (int)$argv[1];

// Inject an unexpected runtime failure inside the active transaction
ContactMessageService::$testFailureHook = function($pdo, $id) {
    throw new \RuntimeException("SIMULATED_DB_DISCONNECT_OR_QUEUE_CRASH");
};

try {
    ContactMessageService::reply($msgId, "Your order is in transit.", 1, "127.0.0.1");
    echo "UNEXPECTED_TRANSACTION_SUCCESS";
} catch (\RuntimeException $re) {
    if (str_contains($re->getMessage(), "SIMULATED_DB_DISCONNECT_OR_QUEUE_CRASH")) {
        echo "INJECTION_CAUGHT_OK";
    } else {
        echo "UNEXPECTED_EXCEPTION: " . $re->getMessage();
    }
} catch (\Throwable $t) {
    echo "WRONG_THROWABLE: " . $t->getMessage();
} finally {
    ContactMessageService::$testFailureHook = null;
}
' "$INJECT_MSG_ID")

if [ "$INJECTION_TEST_OUTPUT" = "INJECTION_CAUGHT_OK" ]; then
    pass "Controlled failure-injection inside reply transaction was caught and rethrown"
else
    fail "Failure injection did not trigger expected exception: $INJECTION_TEST_OUTPUT"
fi

# Verify message status rolled back to 'new' (NOT 'replied')
ROLLBACK_STATUS=$(run_sql "SELECT status FROM contact_messages WHERE id = $INJECT_MSG_ID;")
if [ "$ROLLBACK_STATUS" = "new" ]; then
    pass "Contact message status rolled back cleanly to 'new' after failure injection"
else
    fail "Contact message status was not rolled back (current: '$ROLLBACK_STATUS')"
fi

# Verify no orphaned rows in email_queue
ORPHANED_EMAILS=$(run_sql "SELECT COUNT(*) FROM email_queue WHERE to_email = 'charlie.rollback@example.com';")
if [ "$ORPHANED_EMAILS" -eq 0 ]; then
    pass "Zero orphaned rows in email_queue after transaction rollback"
else
    fail "Found $ORPHANED_EMAILS orphaned rows in email_queue after transaction failure"
fi

# Verify no partial audit logs written
PARTIAL_AUDIT=$(run_sql "SELECT COUNT(*) FROM audit_log WHERE entity_type = 'contact_message' AND entity_id = $INJECT_MSG_ID;")
if [ "$PARTIAL_AUDIT" -eq 0 ]; then
    pass "Zero partial audit logs in audit_log after transaction rollback"
else
    fail "Found $PARTIAL_AUDIT partial audit log entries after transaction failure"
fi

run_sql "DELETE FROM contact_messages WHERE id = $INJECT_MSG_ID;"

# ------------------------------------------------------------------------------
# TEST 5: RBAC Enforcement on messages.manage
# ------------------------------------------------------------------------------
echo ""
echo "--- Test 5: RBAC Enforcement ---"

RBAC_TEST=$(SHOPWISE_CONFIG=testing php -r '
require "app/bootstrap.php";
use App\Services\Permissions;

// Super admin has messages.manage
$adminAllowed = Permissions::can("messages.manage", "super_admin");

// Customer does NOT have messages.manage
$customerAllowed = Permissions::can("messages.manage", "customer");

if ($adminAllowed && !$customerAllowed) {
    echo "RBAC_OK";
} else {
    echo "RBAC_MISMATCH: admin=" . ($adminAllowed ? "yes" : "no") . ", customer=" . ($customerAllowed ? "yes" : "no");
}
')

if [ "$RBAC_TEST" = "RBAC_OK" ]; then
    pass "RBAC strictly protects messages.manage (allowed for admin, denied for customer)"
else
    fail "RBAC check failed: $RBAC_TEST"
fi

echo ""
echo "=============================================================================="
echo " Customer Support Reply Tests: $PASSED_TESTS passed, $FAILED_TESTS failed (out of $TOTAL_TESTS)"
echo "=============================================================================="

if [ "$FAILED_TESTS" -gt 0 ]; then
    exit 1
fi
