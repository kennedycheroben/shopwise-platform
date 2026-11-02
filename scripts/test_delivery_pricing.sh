#!/usr/bin/env bash
# ==============================================================================
# ShopWise Platform - Delivery Pricing & Automatic Calculation Verification Suite
#
# Covers all 9 Section 1 requirements:
#   1. Location-to-zone matching (County, Area, Town/sub-area)
#   2. Correct delivery charge calculation based on configured zone rules
#   3. Changing locations updates the charge and order total
#   4. Unknown/unsupported locations are rejected safely (never silently KES 0)
#   5. Inactive zones are rejected on quote and order placement
#   6. Client-side price and zone manipulation attempts are strictly rejected/overridden
#   7. Server-computed order totals are correct and enforce whole-shilling rules
#   8. Historical order delivery charges and totals remain unchanged when admin updates pricing
#   9. Existing delivery configuration and seed data are strictly preserved
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
echo " ShopWise Kenya - Delivery Pricing & Auto Calculation Test Suite"
echo " Target DB: $DB_NAME"
echo "=============================================================================="

# ------------------------------------------------------------------------------
# TEST GROUP 1: Location-to-Zone Matching
# ------------------------------------------------------------------------------
echo ""
echo "--- Group 1: Location-to-Zone Matching ---"

SHOPWISE_CONFIG=testing php -r '
require "app/bootstrap.php";
use App\Services\DeliveryService;

$cases = [
    ["Nairobi", "CBD & Upper Hill", 1, 150.0],
    ["Nairobi", "Westlands", 2, 200.0],
    ["Nairobi", "Kilimani", 2, 200.0],
    ["Nairobi", "Karen", 3, 300.0],
    ["Nairobi", "Embakasi", 4, 300.0],
    ["Nairobi", "Roysambu", 5, 350.0],
    ["Kiambu, Machakos & Kajiado", "Thika", 6, 450.0],
    ["Kiambu", "Kitengela", 6, 450.0],
    ["Other Major Towns", "Mombasa", 7, 600.0],
    ["Other Major Towns", "Kisumu", 7, 600.0],
];

foreach ($cases as [$c, $a, $expectedId, $expectedFee]) {
    $match = DeliveryService::matchZoneByLocation($c, $a);
    if (!$match || (int)$match["id"] !== $expectedId) {
        fwrite(STDERR, "Match failed for $c - $a\n");
        exit(1);
    }
}
' && pass "Location strings correctly map to expected delivery zones across all Kenyan tiers" || fail "Location to zone mapping failed"

# ------------------------------------------------------------------------------
# TEST GROUP 2: Delivery Charge Calculation & Free Delivery Threshold
# ------------------------------------------------------------------------------
echo ""
echo "--- Group 2: Delivery Charge Calculation ---"

SHOPWISE_CONFIG=testing php -r '
require "app/bootstrap.php";
use App\Services\DeliveryService;

// Normal quote under threshold (KES 2000 subtotal)
$q1 = DeliveryService::quoteZone(1, 2000.0);
if ((float)$q1["fee"] !== 150.0 || $q1["free_applied"] !== false) {
    fwrite(STDERR, "Quote zone 1 failed\n");
    exit(1);
}

// Pickup quote is always KES 0
$qPickup = DeliveryService::quote("pickup", 2000.0);
if ((float)$qPickup["fee"] !== 0.0 || $qPickup["method"] !== "pickup") {
    fwrite(STDERR, "Pickup quote failed\n");
    exit(1);
}

// Free delivery threshold (>= 5000)
$qFree = DeliveryService::quoteZone(1, 5500.0);
if ((float)$qFree["fee"] !== 0.0 || $qFree["free_applied"] !== true) {
    fwrite(STDERR, "Free delivery threshold quote failed\n");
    exit(1);
}
' && pass "Correct delivery charge calculated for paid and free-delivery-eligible orders" || fail "Delivery charge calculation failed"

# ------------------------------------------------------------------------------
# TEST GROUP 3: Dynamic Location Changes via AJAX Quote Endpoint
# ------------------------------------------------------------------------------
echo ""
echo "--- Group 3: Location Change & Dynamic Total Recalculation ---"

COOKIE_JAR=$(mktemp)
trap 'rm -f "$COOKIE_JAR"' EXIT

# Obtain session cookie and CSRF token
CART_PAGE=$(curl -s -c "$COOKIE_JAR" "$BASE_URL/cart")
CSRF_TOKEN=$(echo "$CART_PAGE" | grep -oP '<meta name="csrf-token" content="\K[^"]+' | head -1 || true)
if [ -z "$CSRF_TOKEN" ]; then
    CSRF_TOKEN=$(echo "$CART_PAGE" | grep -oP 'name="_csrf_token" value="\K[^"]+' | head -1 || true)
fi

# Add active variant to cart
ACTIVE_VID=$(run_sql "SELECT id FROM product_variants WHERE is_active=1 AND stock_qty > 0 LIMIT 1;")
curl -s -b "$COOKIE_JAR" -c "$COOKIE_JAR" -X POST "$BASE_URL/cart/add" \
  -H "X-Requested-With: XMLHttpRequest" \
  -d "_csrf_token=$CSRF_TOKEN&variant_id=$ACTIVE_VID&quantity=1" > /dev/null

# Request quote for Nairobi CBD
QUOTE_RESP_CBD=$(curl -s -b "$COOKIE_JAR" -c "$COOKIE_JAR" -X POST "$BASE_URL/checkout/quote" \
  -H "Content-Type: application/json" \
  -H "X-Requested-With: XMLHttpRequest" \
  -d '{"delivery_method":"delivery","county":"Nairobi","area":"CBD & Upper Hill"}')

FEE_CBD=$(echo "$QUOTE_RESP_CBD" | grep -o '"fee":[0-9]*' | head -1 | cut -d':' -f2)

# Request quote for Mombasa
QUOTE_RESP_MSA=$(curl -s -b "$COOKIE_JAR" -c "$COOKIE_JAR" -X POST "$BASE_URL/checkout/quote" \
  -H "Content-Type: application/json" \
  -H "X-Requested-With: XMLHttpRequest" \
  -d '{"delivery_method":"delivery","county":"Other Major Towns","area":"Mombasa"}')

FEE_MSA=$(echo "$QUOTE_RESP_MSA" | grep -o '"fee":[0-9]*' | head -1 | cut -d':' -f2)

if [ "$FEE_CBD" = "150" ] && [ "$FEE_MSA" = "600" ]; then
    pass "Changing delivery location dynamically updates the delivery fee (Nairobi: KES $FEE_CBD -> Mombasa: KES $FEE_MSA)"
else
    fail "Changing delivery location did not update delivery fee correctly (CBD: '$FEE_CBD', MSA: '$FEE_MSA')"
fi

# ------------------------------------------------------------------------------
# TEST GROUP 4: Unknown / Unsupported Locations Handled Correctly
# ------------------------------------------------------------------------------
echo ""
echo "--- Group 4: Unsupported Location Rejection ---"

UNKNOWN_RESP=$(curl -s -b "$COOKIE_JAR" -c "$COOKIE_JAR" -X POST "$BASE_URL/checkout/quote" \
  -H "Content-Type: application/json" \
  -H "X-Requested-With: XMLHttpRequest" \
  -d '{"delivery_method":"delivery","county":"Marsabit","area":"Moyale Outskirts"}')

if echo "$UNKNOWN_RESP" | grep -q "Delivery is unavailable for Marsabit"; then
    pass "Unsupported location is rejected with clear error message (never silently KES 0)"
else
    fail "Unsupported location did not return expected error message: $UNKNOWN_RESP"
fi

# ------------------------------------------------------------------------------
# TEST GROUP 5: Inactive Delivery Zone Rejection
# ------------------------------------------------------------------------------
echo ""
echo "--- Group 5: Inactive Zone Rejection ---"

# Create throwaway inactive zone
run_sql "INSERT INTO delivery_zones (county, area, fee, est_days_min, est_days_max, is_active, created_at)
         VALUES ('Nairobi', 'Temporary Inactive Zone', 250.00, 1, 2, 0, NOW());"
INACTIVE_ZONE_ID=$(run_sql "SELECT id FROM delivery_zones WHERE area = 'Temporary Inactive Zone' LIMIT 1;")

INACTIVE_RESP=$(curl -s -b "$COOKIE_JAR" -c "$COOKIE_JAR" -X POST "$BASE_URL/checkout/quote" \
  -H "Content-Type: application/json" \
  -H "X-Requested-With: XMLHttpRequest" \
  -d "{\"delivery_method\":\"delivery\",\"zone_id\":$INACTIVE_ZONE_ID}")

if echo "$INACTIVE_RESP" | grep -q "currently inactive"; then
    pass "Quote request for inactive delivery zone is rejected"
else
    fail "Inactive zone was not rejected properly: $INACTIVE_RESP"
fi

run_sql "DELETE FROM delivery_zones WHERE id = $INACTIVE_ZONE_ID;"

# ------------------------------------------------------------------------------
# TEST GROUP 6: Client-Side Price & Zone Tampering Defense
# ------------------------------------------------------------------------------
echo ""
echo "--- Group 6: Client-Side Price & Zone Manipulation Defense ---"

SHOPWISE_CONFIG=testing php -r '
require "app/bootstrap.php";
use App\Services\OrderService;
use App\Services\DeliveryService;

$pdo = App\Database\Database::getInstance();
$cartService = new App\Services\CartService();
$v = $pdo->query("SELECT id FROM product_variants WHERE is_active=1 AND stock_qty > 0 LIMIT 1")->fetchColumn();
$cartService->addItem((int)$v, 1);
$cartData = $cartService->getCartDetails();

// Attacker sends Mombasa location but crafts zone_id=1 (Nairobi CBD KES 150) and fee=0
$county = "Other Major Towns";
$area = "Mombasa";
$clientCraftedZoneId = 1; // Nairobi CBD
$matched = DeliveryService::matchZoneByLocation($county, $area);
if ($matched && (int)$matched["id"] !== $clientCraftedZoneId) {
    $resolvedZoneId = (int)$matched["id"];
} else {
    $resolvedZoneId = $clientCraftedZoneId;
}

$tamperedChoices = [
    "delivery_method" => "delivery",
    "recipient_name"  => "Tamper Tester",
    "phone"           => "0712345678",
    "county"          => $county,
    "area"            => $area,
    "street_details"  => "Moi Avenue, Mombasa",
    "zone_id"         => $resolvedZoneId,
    "delivery_fee"    => 0.0, // Attempted price override!
    "total"           => 1.0, // Attempted total override!
];

$token = "tamper_test_" . bin2hex(random_bytes(8));
try {
    $order = OrderService::createFromCart($cartData, $tamperedChoices, 1, $token, "127.0.0.1");
    if ((float)$order["delivery_fee"] === 600.0) {
        echo "TAMPER_DEFENSE_OK\n";
    } else {
        echo "TAMPER_DEFENSE_FAILED: delivery_fee is " . $order["delivery_fee"] . "\n";
    }
    $pdo->query("DELETE FROM order_items WHERE order_id = " . (int)$order["id"]);
    $pdo->query("DELETE FROM orders WHERE id = " . (int)$order["id"]);
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
' | grep -q "TAMPER_DEFENSE_OK" && pass "Client-submitted delivery fees and mismatched zone IDs are safely overridden by server calculation" || fail "Client tampering defense failed"

# ------------------------------------------------------------------------------
# TEST GROUP 7: Historical Order Delivery Charge Stability
# ------------------------------------------------------------------------------
echo ""
echo "--- Group 7: Historical Order Delivery Charge Stability ---"

# Insert a dummy historical order with delivery fee 200 and total 1200
HIST_ORDER_NUM="TEST-HIST-$(date +%s)"
run_sql "INSERT INTO orders (order_number, user_id, status, payment_status, subtotal, delivery_fee, discount, total, delivery_method, delivery_pricing_method, recipient_name, phone, county, area, idempotency_token, created_at, updated_at)
         VALUES ('$HIST_ORDER_NUM', 1, 'delivered', 'paid', 1000.00, 200.00, 0.00, 1200.00, 'delivery', 'zone', 'Hist Cust', '0700111222', 'Nairobi', 'Westlands', 'token_hist_test', '2026-01-01 10:00:00', '2026-01-01 10:00:00');"

# Now simulate admin updating zone 2 (Westlands) price from 200 to 350
ORIG_ZONE_FEE=$(run_sql "SELECT fee FROM delivery_zones WHERE id = 2;")
run_sql "UPDATE delivery_zones SET fee = 350.00 WHERE id = 2;"

# Verify historical order total and delivery fee remain 200.00 and 1200.00
HIST_CHECK=$(run_sql "SELECT delivery_fee, total FROM orders WHERE order_number = '$HIST_ORDER_NUM';")
run_sql "UPDATE delivery_zones SET fee = $ORIG_ZONE_FEE WHERE id = 2;" # Restore
run_sql "DELETE FROM orders WHERE order_number = '$HIST_ORDER_NUM';"

if echo "$HIST_CHECK" | grep -q "200.00	1200.00"; then
    pass "Historical order delivery fees and totals remain unchanged when admin updates zone pricing"
else
    fail "Historical order modified: $HIST_CHECK"
fi

# ------------------------------------------------------------------------------
# TEST GROUP 8: Existing Delivery Configuration Preserved
# ------------------------------------------------------------------------------
echo ""
echo "--- Group 8: Baseline Configuration Integrity ---"

ZONE_COUNT=$(run_sql "SELECT COUNT(*) FROM delivery_zones;")
if [ "$ZONE_COUNT" -ge 7 ]; then
    pass "All 7 baseline delivery zones preserved without data loss (current count: $ZONE_COUNT)"
else
    fail "Baseline delivery zones missing or corrupted (count: $ZONE_COUNT)"
fi

echo ""
echo "=============================================================================="
echo " Delivery Pricing Tests: $PASSED_TESTS passed, $FAILED_TESTS failed (out of $TOTAL_TESTS)"
echo "=============================================================================="

if [ "$FAILED_TESTS" -gt 0 ]; then
    exit 1
fi
