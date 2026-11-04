#!/usr/bin/env bash
# ==============================================================================
# ShopWise Platform - Category URL Slug & Immutability Verification Suite
#
# Covers all 13 Phase 6 requirements:
#   1. Automatic slug generation on category creation
#   2. Normalization of spaces, special characters, ampersands, and apostrophes
#   3. Duplicate category names receive unique incremental suffixes (-2, -3)
#   4. Multi-process concurrent slug collision safety
#   5. Category renaming preserves existing immutable slug
#   6. Updating description preserves existing slug
#   7. Changing parent category preserves existing slug
#   8. Submitted custom slugs are discarded server-side
#   9. Staff cannot manipulate slugs through direct HTTP POST requests
#  10. Baseline existing categories retain their current slugs
#  11. Public storefront category routes resolve properly (including 301 redirects)
#  12. Product-category associations are preserved
#  13. RBAC authorization rules remain strictly enforced
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
    echo "  [PASS] $1"
}

fail() {
    TOTAL_TESTS=$((TOTAL_TESTS + 1))
    FAILED_TESTS=$((FAILED_TESTS + 1))
    echo "  [FAIL] $1" >&2
}

echo "=============================================================================="
echo "   SHOPWISE PLATFORM — CATEGORY SLUG & IMMUTABILITY VERIFICATION SUITE       "
echo "=============================================================================="
echo "Target Database: $DB_NAME"
echo "Base URL:        $BASE_URL"

# ==============================================================================
# SECTION 1: Automatic Slug Generation & Normalization
# ==============================================================================
echo ""
echo "=== 1. Automatic Slug Generation & Character Normalization ==="

SECTION1_JSON=$(SHOPWISE_CONFIG=testing php -r '
require_once "app/bootstrap.php";
use App\Services\CategoryService;

$tests = [
    "Women\x27s Clothing"       => "womens-clothing",
    "Men\x27s Clothing"         => "mens-clothing",
    "Bags & Accessories"        => "bags-accessories",
    "Home & Decor"              => "home-decor",
    "Beauty & Personal Care"    => "beauty-personal-care",
    "Phones & Tablets -- 2026"  => "phones-tablets-2026",
    "   Special   Offer   "     => "special-offer",
    "St. John\x27s & Sons\x27"  => "st-johns-sons",
];

$results = [];
foreach ($tests as $name => $expectedSlug) {
    $slug = slugify($name);
    $results[$name] = [
        "actual"   => $slug,
        "expected" => $expectedSlug,
        "matches"  => ($slug === $expectedSlug),
    ];
}

// Test create generates slug without providing slug field
$catId = CategoryService::create([
    "name" => "Automated Unit Test Cat " . uniqid(),
    "description" => "Test description",
], 1, "127.0.0.1");

$cat = CategoryService::find($catId);
$results["auto_generated"] = [
    "id"   => $catId,
    "slug" => $cat["slug"],
    "valid" => (bool)preg_match("/^automated-unit-test-cat-[a-z0-9]+$/", $cat["slug"]),
];

// Clean up
$pdo = \App\Database\Database::getInstance();
$pdo->query("DELETE FROM categories WHERE id = {$catId}");

echo json_encode($results);
')

for key in "Women's Clothing" "Men's Clothing" "Bags & Accessories" "Home & Decor" "Beauty & Personal Care" "Phones & Tablets -- 2026" "   Special   Offer   " "St. John's & Sons'"; do
    MATCHES=$(echo "$SECTION1_JSON" | python3 -c "import sys, json; data=json.load(sys.stdin); print(data[sys.argv[1]]['matches'])" "$key")
    ACTUAL=$(echo "$SECTION1_JSON" | python3 -c "import sys, json; data=json.load(sys.stdin); print(data[sys.argv[1]]['actual'])" "$key")
    if [ "$MATCHES" = "True" ]; then
        pass "Normalization: '$key' -> '$ACTUAL'"
    else
        fail "Normalization: '$key' produced '$ACTUAL'"
    fi
done

AUTO_GEN_OK=$(echo "$SECTION1_JSON" | python3 -c "import sys, json; data=json.load(sys.stdin); print(data['auto_generated']['valid'])")
if [ "$AUTO_GEN_OK" = "True" ]; then
    pass "CategoryService::create automatically generates lowercase hyphenated slug"
else
    fail "CategoryService::create failed to generate valid slug"
fi

# ==============================================================================
# SECTION 2: Duplicate Slug Generation & Collision Suffixes
# ==============================================================================
echo ""
echo "=== 2. Duplicate Slug Handling & Incremental Suffixes ==="

SECTION2_JSON=$(SHOPWISE_CONFIG=testing php -r '
require_once "app/bootstrap.php";
use App\Services\CategoryService;

$baseName = "Duplicate Test " . uniqid();
$expectedBase = slugify($baseName);

$id1 = CategoryService::create(["name" => $baseName], 1, "127.0.0.1");
$id2 = CategoryService::create(["name" => $baseName], 1, "127.0.0.1");
$id3 = CategoryService::create(["name" => $baseName], 1, "127.0.0.1");

$cat1 = CategoryService::find($id1);
$cat2 = CategoryService::find($id2);
$cat3 = CategoryService::find($id3);

$results = [
    "slug1" => $cat1["slug"],
    "slug2" => $cat2["slug"],
    "slug3" => $cat3["slug"],
    "expected1" => $expectedBase,
    "expected2" => $expectedBase . "-2",
    "expected3" => $expectedBase . "-3",
];

// Clean up
$pdo = \App\Database\Database::getInstance();
$pdo->query("DELETE FROM categories WHERE id IN ({$id1}, {$id2}, {$id3})");

echo json_encode($results);
')

SLUG1_OK=$(echo "$SECTION2_JSON" | python3 -c "import sys, json; d=json.load(sys.stdin); print(d['slug1'] == d['expected1'])")
SLUG2_OK=$(echo "$SECTION2_JSON" | python3 -c "import sys, json; d=json.load(sys.stdin); print(d['slug2'] == d['expected2'])")
SLUG3_OK=$(echo "$SECTION2_JSON" | python3 -c "import sys, json; d=json.load(sys.stdin); print(d['slug3'] == d['expected3'])")

if [ "$SLUG1_OK" = "True" ]; then
    pass "First category receives base slug without suffix"
else
    fail "First category did not receive base slug"
fi

if [ "$SLUG2_OK" = "True" ]; then
    pass "Second duplicate category receives -2 suffix"
else
    fail "Second duplicate category did not receive -2 suffix"
fi

if [ "$SLUG3_OK" = "True" ]; then
    pass "Third duplicate category receives -3 suffix"
else
    fail "Third duplicate category did not receive -3 suffix"
fi

# ==============================================================================
# SECTION 3: Concurrency Safety Under Multi-Process Race
# ==============================================================================
echo ""
echo "=== 3. Concurrency Safety Under Multi-Process Race ==="

CONCURRENCY_JSON=$(SHOPWISE_CONFIG=testing php scripts/test_category_concurrency.php)
ALL_UNIQUE=$(echo "$CONCURRENCY_JSON" | python3 -c "import sys, json; d=json.load(sys.stdin); print(d.get('all_unique', False))")
SUCCESS_COUNT=$(echo "$CONCURRENCY_JSON" | python3 -c "import sys, json; d=json.load(sys.stdin); print(d.get('success_count', 0))")

if [ "$ALL_UNIQUE" = "True" ] && [ "$SUCCESS_COUNT" -eq 6 ]; then
    pass "Multi-process concurrent category creation safely serialized all 6 workers with distinct suffixes"
else
    fail "Multi-process concurrency test failed: $CONCURRENCY_JSON"
fi

# ==============================================================================
# SECTION 4: Slug Immutability Across Updates
# ==============================================================================
echo ""
echo "=== 4. Slug Immutability Across Edits, Renames, and Status Toggles ==="

SECTION4_JSON=$(SHOPWISE_CONFIG=testing php -r '
require_once "app/bootstrap.php";
use App\Services\CategoryService;

$id = CategoryService::create(["name" => "Initial Immutable Name " . uniqid()], 1, "127.0.0.1");
$initialCat = CategoryService::find($id);
$originalSlug = $initialCat["slug"];

// 1. Rename category
CategoryService::update($id, ["name" => "Completely New Category Name"], 1, "127.0.0.1");
$afterRename = CategoryService::find($id);

// 2. Update description
CategoryService::update($id, ["description" => "Brand new updated description"], 1, "127.0.0.1");
$afterDesc = CategoryService::find($id);

// 3. Change sort order
CategoryService::update($id, ["sort_order" => 42], 1, "127.0.0.1");
$afterSort = CategoryService::find($id);

// 4. Change status to inactive
CategoryService::update($id, ["is_active" => 0], 1, "127.0.0.1");
$afterStatus = CategoryService::find($id);

// 5. Change parent category (set to category 1)
CategoryService::update($id, ["parent_id" => 1], 1, "127.0.0.1");
$afterParent = CategoryService::find($id);

// 6. Attempt to pass custom slug in update payload
CategoryService::update($id, ["slug" => "malicious-tampered-slug", "name" => "Renamed Again"], 1, "127.0.0.1");
$afterTamper = CategoryService::find($id);

// Clean up
$pdo = \App\Database\Database::getInstance();
$pdo->query("DELETE FROM categories WHERE id = {$id}");

echo json_encode([
    "original"      => $originalSlug,
    "after_rename"  => $afterRename["slug"],
    "after_desc"    => $afterDesc["slug"],
    "after_sort"    => $afterSort["slug"],
    "after_status"  => $afterStatus["slug"],
    "after_parent"  => $afterParent["slug"],
    "after_tamper"  => $afterTamper["slug"],
]);
')

RENAME_OK=$(echo "$SECTION4_JSON" | python3 -c "import sys, json; d=json.load(sys.stdin); print(d['original'] == d['after_rename'])")
DESC_OK=$(echo "$SECTION4_JSON" | python3 -c "import sys, json; d=json.load(sys.stdin); print(d['original'] == d['after_desc'])")
SORT_OK=$(echo "$SECTION4_JSON" | python3 -c "import sys, json; d=json.load(sys.stdin); print(d['original'] == d['after_sort'])")
STATUS_OK=$(echo "$SECTION4_JSON" | python3 -c "import sys, json; d=json.load(sys.stdin); print(d['original'] == d['after_status'])")
PARENT_OK=$(echo "$SECTION4_JSON" | python3 -c "import sys, json; d=json.load(sys.stdin); print(d['original'] == d['after_parent'])")
TAMPER_OK=$(echo "$SECTION4_JSON" | python3 -c "import sys, json; d=json.load(sys.stdin); print(d['original'] == d['after_tamper'])")

if [ "$RENAME_OK" = "True" ]; then
    pass "Slug unchanged when category name is edited"
else
    fail "Slug changed after category rename"
fi

if [ "$DESC_OK" = "True" ]; then
    pass "Slug unchanged when description is updated"
else
    fail "Slug changed after description update"
fi

if [ "$SORT_OK" = "True" ]; then
    pass "Slug unchanged when sort order is modified"
else
    fail "Slug changed after sort order change"
fi

if [ "$STATUS_OK" = "True" ]; then
    pass "Slug unchanged when status is changed"
else
    fail "Slug changed after status change"
fi

if [ "$PARENT_OK" = "True" ]; then
    pass "Slug unchanged when parent category is changed"
else
    fail "Slug changed after parent change"
fi

if [ "$TAMPER_OK" = "True" ]; then
    pass "Submitted custom slug in update payload is strictly ignored and preserved"
else
    fail "Custom slug was accepted during update"
fi

# ==============================================================================
# SECTION 5: Protection Against Client Slug Injection in Creation
# ==============================================================================
echo ""
echo "=== 5. Submitted Custom Slugs Ignored on Creation ==="

SECTION5_JSON=$(SHOPWISE_CONFIG=testing php -r '
require_once "app/bootstrap.php";
use App\Services\CategoryService;

$id = CategoryService::create([
    "name" => "Legitimate Cat Name " . uniqid(),
    "slug" => "injected-custom-slug",
], 1, "127.0.0.1");

$cat = CategoryService::find($id);

// Clean up
$pdo = \App\Database\Database::getInstance();
$pdo->query("DELETE FROM categories WHERE id = {$id}");

echo json_encode([
    "slug" => $cat["slug"],
    "was_injected" => ($cat["slug"] === "injected-custom-slug"),
]);
')

INJECTED=$(echo "$SECTION5_JSON" | python3 -c "import sys, json; d=json.load(sys.stdin); print(d['was_injected'])")
if [ "$INJECTED" = "False" ]; then
    pass "Custom slug submitted during category creation is ignored and overwritten by system generator"
else
    fail "CategoryService::create accepted an injected custom slug"
fi

# ==============================================================================
# SECTION 6: Baseline Categories & Existing Slugs Preservation
# ==============================================================================
echo ""
echo "=== 6. Preservation of Existing ShopWise Categories ==="

EXPECTED_CATEGORIES=(
    "1|womens-clothing|Women's Clothing"
    "2|mens-clothing|Men's Clothing"
    "3|shoes|Shoes"
    "4|bags-accessories|Bags & Accessories"
    "5|electronics|Electronics"
    "6|home-decor|Home & Decor"
    "7|beauty-personal-care|Beauty & Personal Care"
)

for item in "${EXPECTED_CATEGORIES[@]}"; do
    IFS='|' read -r exp_id exp_slug exp_name <<< "$item"
    ACTUAL_ROW=$(run_sql "SELECT CONCAT(id, '|', slug, '|', name) FROM categories WHERE id = $exp_id;")
    if [ "$ACTUAL_ROW" = "$item" ]; then
        pass "Existing category #$exp_id ($exp_name) preserves slug '$exp_slug'"
    else
        fail "Existing category #$exp_id mismatch: expected '$item', got '$ACTUAL_ROW'"
    fi
done

# Check product-category associations are intact
PROD_COUNT=$(run_sql "SELECT COUNT(*) FROM products WHERE id <= 21 AND category_id BETWEEN 1 AND 7 AND deleted_at IS NULL;")
if [ "$PROD_COUNT" -eq 21 ]; then
    pass "All 21 catalog products remain associated with their original baseline categories"
else
    fail "Product category associations count mismatch: got $PROD_COUNT, expected 21"
fi

# ==============================================================================
# SECTION 7: Public Storefront Category Routes & 301 Historical Redirects
# ==============================================================================
echo ""
echo "=== 7. Public Storefront Category Routes & 301 Redirects ==="

# Test active category route
HTTP_WOMENS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/category/womens-clothing")
if [ "$HTTP_WOMENS" = "200" ]; then
    pass "GET /category/womens-clothing returns HTTP 200"
else
    fail "GET /category/womens-clothing returned HTTP $HTTP_WOMENS (expected 200)"
fi

HTTP_SHOES=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/category/shoes")
if [ "$HTTP_SHOES" = "200" ]; then
    pass "GET /category/shoes returns HTTP 200"
else
    fail "GET /category/shoes returned HTTP $HTTP_SHOES (expected 200)"
fi

HTTP_NONEXIST=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/category/non-existent-category-slug-xyz")
if [ "$HTTP_NONEXIST" = "404" ]; then
    pass "GET /category/non-existent-slug returns HTTP 404"
else
    fail "GET /category/non-existent-slug returned HTTP $HTTP_NONEXIST (expected 404)"
fi

# Test authorized slug migration and 301 permanent redirect
REDIRECT_TEST_JSON=$(SHOPWISE_CONFIG=testing php -r '
require_once "app/bootstrap.php";
use App\Services\CategoryService;

$id = CategoryService::create(["name" => "Migratable Test Category " . uniqid()], 1, "127.0.0.1");
$initial = CategoryService::find($id);
$initialSlug = $initial["slug"];

// Migrate slug to new slug
$newSlug = "migrated-category-" . uniqid();
CategoryService::migrateSlug($id, $newSlug, 1, "127.0.0.1");

echo json_encode([
    "id"          => $id,
    "initialSlug" => $initialSlug,
    "newSlug"     => $newSlug,
]);
')

INIT_SLUG=$(echo "$REDIRECT_TEST_JSON" | python3 -c "import sys, json; d=json.load(sys.stdin); print(d['initialSlug'])")
NEW_SLUG=$(echo "$REDIRECT_TEST_JSON" | python3 -c "import sys, json; d=json.load(sys.stdin); print(d['newSlug'])")
TEST_CAT_ID=$(echo "$REDIRECT_TEST_JSON" | python3 -c "import sys, json; d=json.load(sys.stdin); print(d['id'])")

# Request the historical URL and verify HTTP 301
CURL_RESP=$(curl -s -i "$BASE_URL/category/$INIT_SLUG")
HTTP_CODE=$(echo "$CURL_RESP" | head -n 1 | awk '{print $2}')
LOCATION_HEADER=$(echo "$CURL_RESP" | grep -i "^location:" | tr -d '\r' | awk '{print $2}')

if [ "$HTTP_CODE" = "301" ] && [[ "$LOCATION_HEADER" =~ /category/$NEW_SLUG ]]; then
    pass "Historical category slug permanently redirects (HTTP 301) to new slug URL"
else
    fail "Historical slug redirect failed: HTTP $HTTP_CODE, Location: $LOCATION_HEADER (expected 301 to /category/$NEW_SLUG)"
fi

# Request the new migrated slug directly and verify HTTP 200
NEW_HTTP=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/category/$NEW_SLUG")
if [ "$NEW_HTTP" = "200" ]; then
    pass "New migrated slug resolves successfully with HTTP 200"
else
    fail "New migrated slug returned HTTP $NEW_HTTP"
fi

# Clean up test category
run_sql "DELETE FROM categories WHERE id = $TEST_CAT_ID;"

# ==============================================================================
# SECTION 8: HTTP Controller Direct POST Tampering Protection & RBAC
# ==============================================================================
echo ""
echo "=== 8. HTTP Controller Direct POST Tampering & RBAC Enforcement ==="

TEST_ADMIN_EMAIL="test.category.admin.$(date +%s)@example.com"
TEST_ADMIN_PASS="SuperSecretPass123!"
HASHED_PASS=$(php -r "echo password_hash('$TEST_ADMIN_PASS', PASSWORD_DEFAULT);")

run_sql "INSERT INTO users (role, first_name, last_name, email, password_hash, status, email_verified_at)
         VALUES ('super_admin', 'CatAdmin', 'Tester', '$TEST_ADMIN_EMAIL', '$HASHED_PASS', 'active', NOW());"
TEST_ADMIN_ID=$(run_sql "SELECT id FROM users WHERE email = '$TEST_ADMIN_EMAIL' LIMIT 1;")
run_sql "INSERT INTO user_roles (user_id, role) VALUES ($TEST_ADMIN_ID, 'super_admin');"

COOKIE_JAR=$(mktemp)

# 1. Fetch login page to get CSRF token
LOGIN_PAGE=$(curl -s -c "$COOKIE_JAR" "$BASE_URL/admin/login.php")
LOGIN_CSRF=$(echo "$LOGIN_PAGE" | grep -oP 'name="_csrf_token"\s+value="\K[^"]+' | head -n 1 || true)

# 2. Login as admin
curl -s -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -d "_csrf_token=$LOGIN_CSRF&email=$TEST_ADMIN_EMAIL&password=$TEST_ADMIN_PASS" \
    -X POST "$BASE_URL/admin/login.php" > /dev/null

# 3. Fetch categories admin page with session cookie to obtain fresh CSRF token
ADMIN_PAGE=$(curl -s -b "$COOKIE_JAR" "$BASE_URL/admin/categories/")
CSRF_TOKEN=$(echo "$ADMIN_PAGE" | grep -oP 'name="_csrf_token"\s+value="\K[^"]+' | head -n 1 || true)

if [ -n "$CSRF_TOKEN" ]; then
    pass "Obtained authenticated admin session and CSRF token for category management"

    # Attempt to forge category creation with a custom slug
    INJECT_NAME="HTTP Injected Cat $(date +%s)"
    INJECT_SLUG="http-injected-slug-should-not-exist"

    curl -s -b "$COOKIE_JAR" \
        -d "_csrf_token=$CSRF_TOKEN&action=create&name=$INJECT_NAME&slug=$INJECT_SLUG" \
        -X POST "$BASE_URL/admin/categories/" > /dev/null

    # Check whether the injected slug was created
    WAS_INJECTED=$(run_sql "SELECT COUNT(*) FROM categories WHERE slug = '$INJECT_SLUG';")
    CREATED_CAT_SLUG=$(run_sql "SELECT slug FROM categories WHERE name = '$INJECT_NAME' LIMIT 1;")

    if [ "$WAS_INJECTED" -eq 0 ] && [ -n "$CREATED_CAT_SLUG" ] && [ "$CREATED_CAT_SLUG" != "$INJECT_SLUG" ]; then
        pass "Controller safely ignored custom 'slug' parameter from HTTP POST creation request"
    else
        fail "Controller allowed custom slug injection via HTTP POST: was_injected=$WAS_INJECTED, slug=$CREATED_CAT_SLUG"
    fi

    # Attempt to tamper with existing category slug via HTTP POST update
    CREATED_CAT_ID=$(run_sql "SELECT id FROM categories WHERE name = '$INJECT_NAME' LIMIT 1;")
    TAMPER_SLUG="tampered-via-http-update"

    curl -s -b "$COOKIE_JAR" \
        -d "_csrf_token=$CSRF_TOKEN&action=update&id=$CREATED_CAT_ID&name=HTTP Renamed Cat&slug=$TAMPER_SLUG" \
        -X POST "$BASE_URL/admin/categories/" > /dev/null

    AFTER_UPDATE_SLUG=$(run_sql "SELECT slug FROM categories WHERE id = $CREATED_CAT_ID;")
    if [ "$AFTER_UPDATE_SLUG" = "$CREATED_CAT_SLUG" ] && [ "$AFTER_UPDATE_SLUG" != "$TAMPER_SLUG" ]; then
        pass "Controller safely preserved immutable slug against HTTP POST update tampering"
    else
        fail "Controller allowed slug tampering via HTTP POST update: $AFTER_UPDATE_SLUG"
    fi

    # Clean up test category
    run_sql "DELETE FROM categories WHERE id = $CREATED_CAT_ID;"
else
    fail "Could not retrieve CSRF token from admin categories page"
fi

# Cleanup test admin user
run_sql "DELETE FROM user_roles WHERE user_id = $TEST_ADMIN_ID;"
run_sql "DELETE FROM audit_log WHERE actor_user_id = $TEST_ADMIN_ID OR entity_id = $TEST_ADMIN_ID;"
run_sql "DELETE FROM users WHERE id = $TEST_ADMIN_ID;"
rm -f "$COOKIE_JAR"

# Test unauthenticated access to admin categories is blocked (HTTP 302 to login)
ANON_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/admin/categories/")
if [ "$ANON_CODE" = "302" ]; then
    pass "Unauthenticated request to /admin/categories/ is blocked (HTTP 302 redirect to login)"
else
    fail "Unauthenticated request to /admin/categories/ returned HTTP $ANON_CODE (expected 302)"
fi

# ==============================================================================
# SUMMARY
# ==============================================================================
echo ""
echo "=============================================================================="
echo "CATEGORY SLUG TEST SUMMARY: $PASSED_TESTS Passed, $FAILED_TESTS Failed (Total: $TOTAL_TESTS)"
echo "=============================================================================="

if [ "$FAILED_TESTS" -eq 0 ]; then
    exit 0
else
    exit 1
fi
