#!/usr/bin/env bash
# ==============================================================================
# ShopWise Kenya - Phase 2B Comprehensive Acceptance Test Suite
#
# Validates Catalog Admin:
#   - Products, Variants, Images, Categories, Inventory
#   - Template fidelity & security
#   - Role permissions (Admin, Staff, Customer, Logged-out)
#   - Validation & Business rules (order_items protection, last variant, etc.)
#   - Concurrency safety on inventory adjustments
#   - Image upload security & GD re-encoding
#   - SQL injection, XSS escaping, CSRF protection, and IDOR prevention
#   - Audit log integration
# ==============================================================================

set -uo pipefail

BASE_URL="${BASE_URL:-http://localhost/shopwise-platform/public}"
BASE_URL="${BASE_URL%/}"
MYSQL_CMD="${MYSQL_CMD:-/opt/lampp/bin/mysql}"
if [ ! -x "$MYSQL_CMD" ]; then
    MYSQL_CMD="$(command -v mysql || true)"
fi

DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_NAME="${DB_NAME:-shopwise_db}"

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

PASSED=0
FAILED=0

pass() {
    echo -e "  [${GREEN}PASS${NC}] $1"
    PASSED=$((PASSED + 1))
}

fail() {
    echo -e "  [${RED}FAIL${NC}] $1"
    FAILED=$((FAILED + 1))
}

info() {
    echo -e "\n${BOLD}${CYAN}=== $1 ===${NC}"
}

# Run SQL query
run_sql() {
    local sql="$1"
    if [ -n "$DB_PASS" ]; then
        "$MYSQL_CMD" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "$sql" 2>/dev/null
    else
        "$MYSQL_CMD" -u "$DB_USER" "$DB_NAME" -e "$sql" 2>/dev/null
    fi
}

TMP_DIR=$(mktemp -d)
COOKIE_ADMIN="$TMP_DIR/admin.cookies"
COOKIE_STAFF="$TMP_DIR/staff.cookies"
COOKIE_CUST="$TMP_DIR/cust.cookies"
COOKIE_GUEST="$TMP_DIR/guest.cookies"

HEADER_FILE="$TMP_DIR/headers.txt"
BODY_FILE="$TMP_DIR/body.html"

# Generate unique run ID
RAND_ID=$(head /dev/urandom | tr -dc a-z0-9 | head -c 8)
TEST_PASSWORD="TestPass2B_${RAND_ID}!@#"

ADMIN_EMAIL="test_admin_${RAND_ID}@test.local"
STAFF_EMAIL="test_staff_${RAND_ID}@test.local"
CUST_EMAIL="test_cust_${RAND_ID}@test.local"

# ==============================================================================
# BASELINE SNAPSHOT: Capture resource state before tests begin
# ==============================================================================
info "BASELINE SNAPSHOT: Capturing pre-test database row counts and upload file count"
START_PRODUCTS=$(run_sql "SELECT COUNT(*) FROM products;" | tail -n 1)
START_VARIANTS=$(run_sql "SELECT COUNT(*) FROM product_variants;" | tail -n 1)
START_IMAGES=$(run_sql "SELECT COUNT(*) FROM product_images;" | tail -n 1)
START_CATEGORIES=$(run_sql "SELECT COUNT(*) FROM categories;" | tail -n 1)
START_MOVEMENTS=$(run_sql "SELECT COUNT(*) FROM inventory_movements;" | tail -n 1)
START_AUDIT=$(run_sql "SELECT COUNT(*) FROM audit_log;" | tail -n 1)
START_USERS=$(run_sql "SELECT COUNT(*) FROM users;" | tail -n 1)
START_UPLOAD_FILES=$(find /opt/lampp/htdocs/shopwise-platform/public/uploads/products/ -type f ! -name ".gitkeep" 2>/dev/null | wc -l)
START_MAX_AUDIT_ID=$(run_sql "SELECT COALESCE(MAX(id), 0) FROM audit_log;" | tail -n 1)

echo "Starting resource baseline counts:"
echo "  products:            $START_PRODUCTS"
echo "  product_variants:    $START_VARIANTS"
echo "  product_images:      $START_IMAGES"
echo "  categories:          $START_CATEGORIES"
echo "  inventory_movements: $START_MOVEMENTS"
echo "  audit_log:           $START_AUDIT"
echo "  users:               $START_USERS"
echo "  upload files:        $START_UPLOAD_FILES"

CLEANED_ALREADY=0

cleanup() {
    if [ "$CLEANED_ALREADY" -eq 1 ]; then
        return 0
    fi
    CLEANED_ALREADY=1

    info "CLEANUP & HYGIENE AUDIT: Purging all test artifacts and verifying parity"

    # 1. Delete physical image files created during the run for test products
    local test_img_paths
    test_img_paths=($(run_sql "SELECT path FROM product_images WHERE product_id IN (SELECT id FROM products WHERE name LIKE '%${RAND_ID}%' OR slug LIKE '%${RAND_ID}%');" | tr '\n' ' '))
    for p in "${test_img_paths[@]}"; do
        if [ -n "$p" ] && [[ "$p" == uploads/* ]]; then
            local full_path="/opt/lampp/htdocs/shopwise-platform/public/$p"
            local thumb_path=$(echo "$full_path" | sed -E 's/(\.[a-zA-Z0-9]+)$/_thumb\1/')
            rm -f "$full_path" "$thumb_path"
        fi
    done

    # 2. Delete test records from DB
    run_sql "
        DELETE im FROM inventory_movements im 
        JOIN product_variants pv ON im.variant_id = pv.id 
        JOIN products p ON pv.product_id = p.id 
        WHERE p.name LIKE '%${RAND_ID}%' OR p.slug LIKE '%${RAND_ID}%';

        DELETE pi FROM product_images pi
        JOIN products p ON pi.product_id = p.id
        WHERE p.name LIKE '%${RAND_ID}%' OR p.slug LIKE '%${RAND_ID}%';

        DELETE pv FROM product_variants pv
        JOIN products p ON pv.product_id = p.id
        WHERE p.name LIKE '%${RAND_ID}%' OR p.slug LIKE '%${RAND_ID}%';

        DELETE FROM products WHERE name LIKE '%${RAND_ID}%' OR slug LIKE '%${RAND_ID}%';
        DELETE FROM categories WHERE name LIKE '%${RAND_ID}%' OR slug LIKE '%${RAND_ID}%';
        DELETE FROM users WHERE email LIKE '%@test.local' OR email LIKE '%${RAND_ID}%';
        DELETE FROM login_attempts WHERE identifier LIKE '%@test.local' OR identifier LIKE '%${RAND_ID}%';
        DELETE FROM audit_log WHERE id > $START_MAX_AUDIT_ID OR details LIKE '%${RAND_ID}%';
    " >/dev/null 2>&1 || true

    # 3. Clean orphan files under public/uploads/products/ not referenced by product_images
    ORPHANS_REMOVED=$(/opt/lampp/bin/php -r '
        require "app/bootstrap.php";
        use App\Database\Database;
        $pdo = Database::getInstance();
        $stmt = $pdo->query("SELECT path FROM product_images");
        $validPaths = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $validPaths[$row["path"]] = true;
            $thumb = preg_replace("/(\\.[a-zA-Z0-9]+)$/", "_thumb$1", $row["path"]);
            $validPaths[$thumb] = true;
        }

        $dir = __DIR__ . "/public/uploads/products";
        $orphans = 0;
        if (is_dir($dir)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile() && $file->getFilename() !== ".gitkeep") {
                    $rel = str_replace(__DIR__ . "/public/", "", $file->getPathname());
                    if (!isset($validPaths[$rel])) {
                        @unlink($file->getPathname());
                        $orphans++;
                    }
                }
            }
        }
        echo $orphans;
    ')
    echo "  Cleaned orphan upload files: $ORPHANS_REMOVED"

    # Also clean test temp execution file if exists
    rm -f /opt/lampp/htdocs/shopwise-platform/public/uploads/exec_test_*.php
    rm -rf "$TMP_DIR"

    # 4. Verify and print row counts before and after
    END_PRODUCTS=$(run_sql "SELECT COUNT(*) FROM products;" | tail -n 1)
    END_VARIANTS=$(run_sql "SELECT COUNT(*) FROM product_variants;" | tail -n 1)
    END_IMAGES=$(run_sql "SELECT COUNT(*) FROM product_images;" | tail -n 1)
    END_CATEGORIES=$(run_sql "SELECT COUNT(*) FROM categories;" | tail -n 1)
    END_MOVEMENTS=$(run_sql "SELECT COUNT(*) FROM inventory_movements;" | tail -n 1)
    END_AUDIT=$(run_sql "SELECT COUNT(*) FROM audit_log;" | tail -n 1)
    END_USERS=$(run_sql "SELECT COUNT(*) FROM users;" | tail -n 1)
    END_UPLOAD_FILES=$(find /opt/lampp/htdocs/shopwise-platform/public/uploads/products/ -type f ! -name ".gitkeep" 2>/dev/null | wc -l)

    echo ""
    info "HYGIENE AUDIT: Table Rows & File Counts (Before vs After)"
    printf "  %-22s | %-10s | %-10s | %-8s\n" "Resource" "Starting" "Ending" "Result"
    printf "  %-22s | %-10s | %-10s | %-8s\n" "----------------------" "----------" "----------" "--------"
    
    local any_diff=0
    audit_check() {
        local res_name="$1"
        local b_cnt="$2"
        local a_cnt="$3"
        if [ "$b_cnt" = "$a_cnt" ]; then
            printf "  %-22s | %-10s | %-10s | ${GREEN}%-8s${NC}\n" "$res_name" "$b_cnt" "$a_cnt" "MATCH"
        else
            printf "  %-22s | %-10s | %-10s | ${RED}%-8s${NC}\n" "$res_name" "$b_cnt" "$a_cnt" "MISMATCH"
            any_diff=1
        fi
    }

    audit_check "products" "$START_PRODUCTS" "$END_PRODUCTS"
    audit_check "product_variants" "$START_VARIANTS" "$END_VARIANTS"
    audit_check "product_images" "$START_IMAGES" "$END_IMAGES"
    audit_check "categories" "$START_CATEGORIES" "$END_CATEGORIES"
    audit_check "inventory_movements" "$START_MOVEMENTS" "$END_MOVEMENTS"
    audit_check "audit_log" "$START_AUDIT" "$END_AUDIT"
    audit_check "users" "$START_USERS" "$END_USERS"
    audit_check "upload_files" "$START_UPLOAD_FILES" "$END_UPLOAD_FILES"

    if [ "$any_diff" -eq 0 ]; then
        pass "Hygiene verification: All starting and ending row and file counts match perfectly."
    else
        fail "Hygiene verification failed: Post-test resource count mismatch detected."
    fi
}
trap cleanup EXIT

# Check PHP errors in response
check_php_errors() {
    local file="$1"
    local errors=("Fatal error" "Warning:" "Notice:" "Deprecated:" "Parse error" "Exception")
    for err in "${errors[@]}"; do
        if grep -Fqi "$err" "$file"; then
            echo -e "    ${RED}-> Found PHP error pattern: '${err}'${NC}"
            return 1
        fi
    done
    return 0
}

# Fetch helper
http_req() {
    local method="$1"
    local url="$2"
    local cookie_jar="${3:-}"
    local data="${4:-}"
    local content_type="${5:-}"

    local curl_cmd=(curl -sS -D "$HEADER_FILE" -o "$BODY_FILE" -X "$method")
    if [ -n "$cookie_jar" ]; then
        curl_cmd+=(-b "$cookie_jar" -c "$cookie_jar")
    fi
    if [ -n "$content_type" ]; then
        curl_cmd+=(-H "Content-Type: $content_type")
    fi
    if [ -n "$data" ]; then
        curl_cmd+=(--data "$data")
    fi
    curl_cmd+=("$url")

    "${curl_cmd[@]}"
    HTTP_CODE=$(grep -E '^HTTP/[0-9\.]+ [0-9]+' "$HEADER_FILE" | tail -n 1 | awk '{print $2}')

    # Follow redirect on 302 to populate BODY_FILE with destination HTML & flash alerts
    if [ "$HTTP_CODE" = "302" ]; then
        local loc=$(grep -i '^location:' "$HEADER_FILE" | tail -n 1 | awk '{print $2}' | tr -d '\r')
        if [ -n "$loc" ]; then
            if [[ "$loc" != http* ]]; then
                loc="${BASE_URL}/$(echo "$loc" | sed 's|^/||')"
            fi
            if [ -n "$cookie_jar" ]; then
                curl -sS -b "$cookie_jar" -c "$cookie_jar" -o "$BODY_FILE" "$loc"
            else
                curl -sS -o "$BODY_FILE" "$loc"
            fi
        fi
    fi
}

# Upload helper for multipart form posts with redirect following
upload_post() {
    local url="$1"
    local cookie_jar="$2"
    shift 2
    curl -sS -b "$cookie_jar" -c "$cookie_jar" -D "$HEADER_FILE" -o "$BODY_FILE" -X POST "$url" "$@"
    HTTP_CODE=$(grep -E '^HTTP/[0-9\.]+ [0-9]+' "$HEADER_FILE" | tail -n 1 | awk '{print $2}')
    if [ "$HTTP_CODE" = "302" ]; then
        local loc=$(grep -i '^location:' "$HEADER_FILE" | tail -n 1 | awk '{print $2}' | tr -d '\r')
        if [ -n "$loc" ]; then
            if [[ "$loc" != http* ]]; then
                loc="${BASE_URL}/$(echo "$loc" | sed 's|^/||')"
            fi
            curl -sS -b "$cookie_jar" -c "$cookie_jar" -o "$BODY_FILE" "$loc"
        fi
    fi
}

# Extract CSRF token from page
get_csrf_token() {
    local cookie_jar="$1"
    local url="$2"
    http_req "GET" "$url" "$cookie_jar"
    grep -oP 'name="_csrf_token"\s+value="\K[^"]+' "$BODY_FILE" | head -n 1
}

# ==============================================================================
# STEP 1: CREATE THROWAWAY ACCOUNTS & LOGIN
# ==============================================================================
info "Step 1: Preparing Throwaway Accounts"

HASHED_PASS=$(/opt/lampp/bin/php -r "echo password_hash('$TEST_PASSWORD', PASSWORD_DEFAULT);")

run_sql "
INSERT INTO users (role, first_name, last_name, email, password_hash, status, email_verified_at)
VALUES 
('admin', 'TestAdmin', '2B', '$ADMIN_EMAIL', '$HASHED_PASS', 'active', NOW()),
('staff', 'TestStaff', '2B', '$STAFF_EMAIL', '$HASHED_PASS', 'active', NOW()),
('customer', 'TestCustomer', '2B', '$CUST_EMAIL', '$HASHED_PASS', 'active', NOW());
"

# Login function
login_user() {
    local email="$1"
    local pass="$2"
    local jar="$3"

    http_req "GET" "$BASE_URL/admin/login.php" "$jar"
    local csrf=$(grep -oP 'name="_csrf_token"\s+value="\K[^"]+' "$BODY_FILE" | head -n 1)

    http_req "POST" "$BASE_URL/admin/login.php" "$jar" \
        "_csrf_token=${csrf}&email=${email}&password=${pass}" \
        "application/x-www-form-urlencoded"
}

login_user "$ADMIN_EMAIL" "$TEST_PASSWORD" "$COOKIE_ADMIN"
if [ "$HTTP_CODE" = "302" ]; then
    pass "Admin user authenticated successfully (HTTP 302 redirect)."
else
    fail "Admin login failed (HTTP $HTTP_CODE)."
fi

login_user "$STAFF_EMAIL" "$TEST_PASSWORD" "$COOKIE_STAFF"
if [ "$HTTP_CODE" = "302" ]; then
    pass "Staff user authenticated successfully (HTTP 302 redirect)."
else
    fail "Staff login failed (HTTP $HTTP_CODE)."
fi

login_user "$CUST_EMAIL" "$TEST_PASSWORD" "$COOKIE_CUST"
# Note: customer login to admin should fail or be rejected when accessing admin pages
pass "Customer test credentials prepared."

# ==============================================================================
# CHECK A: PAGE ACCESSIBILITY & ACCESS CONTROL
# ==============================================================================
info "Check (a): Page Accessibility & Role Guarding"

PAGES=(
    "/admin/categories/"
    "/admin/products/"
    "/admin/products/create.php"
    "/admin/products/edit.php?id=1"
    "/admin/inventory/"
    "/admin/inventory/movements.php"
)

for p in "${PAGES[@]}"; do
    # 1. Admin access -> must be 200 without errors
    http_req "GET" "$BASE_URL$p" "$COOKIE_ADMIN"
    if [ "$HTTP_CODE" = "200" ] && check_php_errors "$BODY_FILE"; then
        pass "Admin access to $p returns 200 OK without PHP errors."
    else
        fail "Admin access to $p failed (HTTP $HTTP_CODE)."
    fi

    # 2. Logged out -> must redirect 302 to login
    http_req "GET" "$BASE_URL$p" "$COOKIE_GUEST"
    if [ "$HTTP_CODE" = "302" ]; then
        pass "Logged-out access to $p redirects (HTTP 302)."
    else
        fail "Logged-out access to $p returned HTTP $HTTP_CODE (expected 302)."
    fi

    # 3. Customer role -> must be denied (403)
    http_req "GET" "$BASE_URL$p" "$COOKIE_CUST"
    if [ "$HTTP_CODE" = "403" ] || [ "$HTTP_CODE" = "302" ]; then
        pass "Customer role to $p is denied (HTTP $HTTP_CODE)."
    else
        fail "Customer role to $p allowed unexpectedly (HTTP $HTTP_CODE)."
    fi
done

# ==============================================================================
# CHECK B: PERMISSIONS (STAFF VS ADMIN)
# ==============================================================================
info "Check (b): Permissions Enforcement (Staff vs Admin)"

# Staff can view products
http_req "GET" "$BASE_URL/admin/products/" "$COOKIE_STAFF"
if [ "$HTTP_CODE" = "200" ]; then
    pass "Staff can view products catalog (HTTP 200)."
else
    fail "Staff cannot view products catalog (HTTP $HTTP_CODE)."
fi

# Staff can view categories
http_req "GET" "$BASE_URL/admin/categories/" "$COOKIE_STAFF"
if [ "$HTTP_CODE" = "200" ]; then
    pass "Staff can access categories management (HTTP 200)."
else
    fail "Staff cannot access categories management (HTTP $HTTP_CODE)."
fi

# Staff can view inventory
http_req "GET" "$BASE_URL/admin/inventory/" "$COOKIE_STAFF"
if [ "$HTTP_CODE" = "200" ]; then
    pass "Staff can access inventory management (HTTP 200)."
else
    fail "Staff cannot access inventory management (HTTP $HTTP_CODE)."
fi

# Create a disposable product to test delete permissions
CSRF_ADMIN=$(get_csrf_token "$COOKIE_ADMIN" "$BASE_URL/admin/products/create.php")
PROD_NAME="PermTest_${RAND_ID}"
PROD_SLUG="permtest-${RAND_ID}"

http_req "POST" "$BASE_URL/admin/products/create.php" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&name=${PROD_NAME}&slug=${PROD_SLUG}&category_id=1&short_description=Perm+Test&description=%3Cp%3EDesc%3C%2Fp%3E&base_price=1000&is_active=1" \
    "application/x-www-form-urlencoded"

PERM_PROD_ID=$(run_sql "SELECT id FROM products WHERE slug = '$PROD_SLUG' LIMIT 1;" | tail -n 1)

# Staff attempts soft delete -> MUST return 403
CSRF_STAFF=$(get_csrf_token "$COOKIE_STAFF" "$BASE_URL/admin/products/")
http_req "POST" "$BASE_URL/admin/products/" "$COOKIE_STAFF" \
    "_csrf_token=${CSRF_STAFF}&action=soft_delete&id=${PERM_PROD_ID}" \
    "application/x-www-form-urlencoded"

if [ "$HTTP_CODE" = "403" ]; then
    pass "Staff soft-delete on product returns HTTP 403 Forbidden."
else
    fail "Staff soft-delete did not return 403 (got HTTP $HTTP_CODE)."
fi

# Staff attempts restore -> MUST return 403
http_req "POST" "$BASE_URL/admin/products/" "$COOKIE_STAFF" \
    "_csrf_token=${CSRF_STAFF}&action=restore&id=${PERM_PROD_ID}" \
    "application/x-www-form-urlencoded"

if [ "$HTTP_CODE" = "403" ]; then
    pass "Staff restore on product returns HTTP 403 Forbidden."
else
    fail "Staff restore did not return 403 (got HTTP $HTTP_CODE)."
fi

# Staff attempts category delete -> MUST return 403
# Create a dummy category
CAT_NAME="PermCat_${RAND_ID}"
CAT_SLUG="permcat-${RAND_ID}"
run_sql "INSERT INTO categories (name, slug, sort_order, is_active) VALUES ('$CAT_NAME', '$CAT_SLUG', 99, 1);"
PERM_CAT_ID=$(run_sql "SELECT id FROM categories WHERE slug = '$CAT_SLUG' LIMIT 1;" | tail -n 1)

http_req "POST" "$BASE_URL/admin/categories/" "$COOKIE_STAFF" \
    "_csrf_token=${CSRF_STAFF}&action=delete&id=${PERM_CAT_ID}" \
    "application/x-www-form-urlencoded"

if [ "$HTTP_CODE" = "403" ]; then
    pass "Staff category delete returns HTTP 403 Forbidden."
else
    fail "Staff category delete did not return 403 (got HTTP $HTTP_CODE)."
fi

# Admin deletes category -> MUST succeed
CSRF_ADMIN=$(get_csrf_token "$COOKIE_ADMIN" "$BASE_URL/admin/categories/")
http_req "POST" "$BASE_URL/admin/categories/" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&action=delete&id=${PERM_CAT_ID}" \
    "application/x-www-form-urlencoded"

CAT_EXISTS=$(run_sql "SELECT COUNT(*) FROM categories WHERE id = $PERM_CAT_ID;" | tail -n 1)
if [ "$CAT_EXISTS" = "0" ]; then
    pass "Admin successfully deleted category."
else
    fail "Admin failed to delete category."
fi

# Admin soft delete product -> MUST succeed
CSRF_ADMIN=$(get_csrf_token "$COOKIE_ADMIN" "$BASE_URL/admin/products/")
http_req "POST" "$BASE_URL/admin/products/" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&action=soft_delete&id=${PERM_PROD_ID}" \
    "application/x-www-form-urlencoded"

DEL_CHECK=$(run_sql "SELECT deleted_at IS NOT NULL FROM products WHERE id = $PERM_PROD_ID;" | tail -n 1)
if [ "$DEL_CHECK" = "1" ]; then
    pass "Admin successfully soft-deleted product."
else
    fail "Admin failed to soft-delete product."
fi

# Admin restore product -> MUST succeed
http_req "POST" "$BASE_URL/admin/products/" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&action=restore&id=${PERM_PROD_ID}" \
    "application/x-www-form-urlencoded"

RESTORE_CHECK=$(run_sql "SELECT deleted_at IS NULL FROM products WHERE id = $PERM_PROD_ID;" | tail -n 1)
if [ "$RESTORE_CHECK" = "1" ]; then
    pass "Admin successfully restored product."
else
    fail "Admin failed to restore product."
fi

# ==============================================================================
# CHECK C: PRODUCT LIFECYCLE, MATRIX GENERATION & IMAGES
# ==============================================================================
info "Check (c): Product Lifecycle, Variant Matrix & Image Management"

MAIN_PROD_NAME="Maasai Shuka Coat ${RAND_ID}"
MAIN_PROD_SLUG="shuka-coat-${RAND_ID}"

CSRF_ADMIN=$(get_csrf_token "$COOKIE_ADMIN" "$BASE_URL/admin/products/create.php")
http_req "POST" "$BASE_URL/admin/products/create.php" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&name=${MAIN_PROD_NAME}&slug=${MAIN_PROD_SLUG}&category_id=1&short_description=Authentic+Kenyan+warm+coat&description=%3Cp%3EHandcrafted+in+Nairobi+with+pure+cotton.%3C%2Fp%3E&base_price=4500.00&compare_at_price=5000.00&is_active=1&is_featured=1" \
    "application/x-www-form-urlencoded"

MAIN_PROD_ID=$(run_sql "SELECT id FROM products WHERE slug = '$MAIN_PROD_SLUG' LIMIT 1;" | tail -n 1)

if [ -n "$MAIN_PROD_ID" ] && [ "$MAIN_PROD_ID" -gt 0 ]; then
    pass "Created product '${MAIN_PROD_NAME}' (ID: $MAIN_PROD_ID)."
else
    fail "Failed to create product."
fi

# Verify automatic default variant created
DEF_VAR_COUNT=$(run_sql "SELECT COUNT(*) FROM product_variants WHERE product_id = $MAIN_PROD_ID AND size IS NULL AND color IS NULL;" | tail -n 1)
if [ "$DEF_VAR_COUNT" = "1" ]; then
    pass "Automatic default variant (size & color NULL) was generated upon product creation."
else
    fail "Default variant was not created (count: $DEF_VAR_COUNT)."
fi

# Generate variants: 2 sizes x 3 colors = 6 variants
CSRF_ADMIN=$(get_csrf_token "$COOKIE_ADMIN" "$BASE_URL/admin/products/edit.php?id=$MAIN_PROD_ID&tab=variants")
http_req "POST" "$BASE_URL/admin/products/edit.php?id=$MAIN_PROD_ID" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&action=variant_generate&product_id=${MAIN_PROD_ID}&sizes=M%2CL&colors=Red%3A%23FF0000%2CBlue%3A%230000FF%2CGreen%3A%2300FF00" \
    "application/x-www-form-urlencoded"

VAR_COUNT=$(run_sql "SELECT COUNT(*) FROM product_variants WHERE product_id = $MAIN_PROD_ID AND size IN ('M','L');" | tail -n 1)
if [ "$VAR_COUNT" = "6" ]; then
    pass "Matrix generator created exactly 6 variants (2 sizes x 3 colors)."
else
    fail "Matrix generator created $VAR_COUNT variants (expected 6)."
fi

# Verify SKUs are unique
SKU_DISTINCT=$(run_sql "SELECT COUNT(DISTINCT sku) FROM product_variants WHERE product_id = $MAIN_PROD_ID;" | tail -n 1)
SKU_TOTAL=$(run_sql "SELECT COUNT(*) FROM product_variants WHERE product_id = $MAIN_PROD_ID;" | tail -n 1)
if [ "$SKU_DISTINCT" = "$SKU_TOTAL" ]; then
    pass "All generated SKUs are strictly unique."
else
    fail "Duplicate SKUs generated ($SKU_DISTINCT distinct out of $SKU_TOTAL)."
fi

# Run generate AGAIN with same options -> must be idempotent (0 duplicates added)
http_req "POST" "$BASE_URL/admin/products/edit.php?id=$MAIN_PROD_ID" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&action=variant_generate&product_id=${MAIN_PROD_ID}&sizes=M%2CL&colors=Red%3A%23FF0000%2CBlue%3A%230000FF%2CGreen%3A%2300FF00" \
    "application/x-www-form-urlencoded"

VAR_COUNT_AFTER=$(run_sql "SELECT COUNT(*) FROM product_variants WHERE product_id = $MAIN_PROD_ID AND size IN ('M','L');" | tail -n 1)
if [ "$VAR_COUNT_AFTER" = "6" ]; then
    pass "Idempotency confirmed: Re-running matrix generator added 0 duplicate variants."
else
    fail "Matrix generator re-run added duplicates (total: $VAR_COUNT_AFTER)."
fi

# Generate 3 valid test images using PHP GD
IMG1="$TMP_DIR/test1_${RAND_ID}.jpg"
IMG2="$TMP_DIR/test2_${RAND_ID}.png"
if /opt/lampp/bin/php -r "exit(function_exists('imagewebp') ? 0 : 1);"; then
    IMG3="$TMP_DIR/test3_${RAND_ID}.webp"
else
    IMG3="$TMP_DIR/test3_${RAND_ID}.jpg"
fi

/opt/lampp/bin/php -r "
    \$im1 = imagecreatetruecolor(600, 600);
    imagefill(\$im1, 0, 0, imagecolorallocate(\$im1, 200, 50, 50));
    imagejpeg(\$im1, '$IMG1');

    \$im2 = imagecreatetruecolor(600, 600);
    imagefill(\$im2, 0, 0, imagecolorallocate(\$im2, 50, 200, 50));
    imagepng(\$im2, '$IMG2');

    \$im3 = imagecreatetruecolor(600, 600);
    imagefill(\$im3, 0, 0, imagecolorallocate(\$im3, 50, 50, 200));
    if (str_ends_with('$IMG3', '.webp')) {
        imagewebp(\$im3, '$IMG3');
    } else {
        imagejpeg(\$im3, '$IMG3');
    }
"

# Upload 3 images via multipart/form-data
CSRF_ADMIN=$(get_csrf_token "$COOKIE_ADMIN" "$BASE_URL/admin/products/edit.php?id=$MAIN_PROD_ID&tab=images")
upload_post "$BASE_URL/admin/products/edit.php?id=$MAIN_PROD_ID" "$COOKIE_ADMIN" \
    -F "_csrf_token=${CSRF_ADMIN}" \
    -F "action=upload_images" \
    -F "product_id=${MAIN_PROD_ID}" \
    -F "image_color=Red" \
    -F "image_alt=Test Image Front" \
    -F "images[]=@${IMG1};type=image/jpeg" \
    -F "images[]=@${IMG2};type=image/png" \
    -F "images[]=@${IMG3};type=image/jpeg"

IMG_COUNT=$(run_sql "SELECT COUNT(*) FROM product_images WHERE product_id = $MAIN_PROD_ID;" | tail -n 1)
if [ "$IMG_COUNT" = "3" ]; then
    pass "Uploaded 3 valid images successfully."
else
    fail "Image upload failed (count: $IMG_COUNT, expected 3)."
fi

# Verify exactly one primary image is set
PRIMARY_COUNT=$(run_sql "SELECT COUNT(*) FROM product_images WHERE product_id = $MAIN_PROD_ID AND is_primary = 1;" | tail -n 1)
if [ "$PRIMARY_COUNT" = "1" ]; then
    pass "Exactly one image is set as primary upon upload."
else
    fail "Primary image count is $PRIMARY_COUNT (expected 1)."
fi

# Fetch image IDs
IMG_IDS=($(run_sql "SELECT id FROM product_images WHERE product_id = $MAIN_PROD_ID ORDER BY sort_order ASC;" | tr '\n' ' '))
if [ "${#IMG_IDS[@]}" -ge 2 ]; then
    IMG_FIRST="${IMG_IDS[0]}"
    IMG_SECOND="${IMG_IDS[1]}"
else
    IMG_FIRST="0"
    IMG_SECOND="0"
fi

# Set Image 2 as primary
http_req "POST" "$BASE_URL/admin/products/edit.php?id=$MAIN_PROD_ID" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&action=set_primary_image&product_id=${MAIN_PROD_ID}&image_id=${IMG_SECOND}" \
    "application/x-www-form-urlencoded"

NEW_PRIMARY=$(run_sql "SELECT id FROM product_images WHERE product_id = $MAIN_PROD_ID AND is_primary = 1;" | tail -n 1)
if [ "$NEW_PRIMARY" = "$IMG_SECOND" ]; then
    pass "Set primary image successfully switched to Image #$IMG_SECOND."
else
    fail "Failed to switch primary image (current: $NEW_PRIMARY)."
fi

# Delete the primary image -> confirm next image is automatically promoted
http_req "POST" "$BASE_URL/admin/products/edit.php?id=$MAIN_PROD_ID" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&action=delete_image&product_id=${MAIN_PROD_ID}&image_id=${IMG_SECOND}" \
    "application/x-www-form-urlencoded"

PROMOTED_PRIMARY=$(run_sql "SELECT id FROM product_images WHERE product_id = $MAIN_PROD_ID AND is_primary = 1;" | tail -n 1)
if [ -n "$PROMOTED_PRIMARY" ] && [ "$PROMOTED_PRIMARY" != "$IMG_SECOND" ]; then
    pass "Deleting primary image automatically promoted next image (#$PROMOTED_PRIMARY) to primary."
else
    fail "Promotion on primary image deletion failed."
fi

# Print evidence summary
echo "Database state for product $MAIN_PROD_ID:"
run_sql "SELECT id, name, slug, base_price, is_active FROM products WHERE id = $MAIN_PROD_ID;"
run_sql "SELECT id, sku, size, color, stock_qty, is_active FROM product_variants WHERE product_id = $MAIN_PROD_ID;"
run_sql "SELECT id, path, is_primary, color FROM product_images WHERE product_id = $MAIN_PROD_ID;"
run_sql "SELECT id, variant_id, change_qty, reason FROM inventory_movements WHERE variant_id IN (SELECT id FROM product_variants WHERE product_id = $MAIN_PROD_ID);"
run_sql "SELECT id, action, entity_type, entity_id FROM audit_log WHERE entity_id = $MAIN_PROD_ID ORDER BY id DESC LIMIT 5;"

# ==============================================================================
# CHECK D: UPLOAD ATTACKS REJECTION & DIRECT EXECUTION BLOCK
# ==============================================================================
info "Check (d): Upload Attacks & Script Execution Protection"

CSRF_ADMIN=$(get_csrf_token "$COOKIE_ADMIN" "$BASE_URL/admin/products/edit.php?id=$MAIN_PROD_ID&tab=images")

# 1. PHP file named shell.php
ATTACK_PHP="$TMP_DIR/shell_${RAND_ID}.php"
echo "<?php echo 'HACKED'; ?>" > "$ATTACK_PHP"

upload_post "$BASE_URL/admin/products/edit.php?id=$MAIN_PROD_ID" "$COOKIE_ADMIN" \
    -F "_csrf_token=${CSRF_ADMIN}" \
    -F "action=upload_images" \
    -F "product_id=${MAIN_PROD_ID}" \
    -F "images[]=@${ATTACK_PHP};type=application/x-php"

if grep -qi "Invalid file extension" "$BODY_FILE" || grep -qi "Upload failed" "$BODY_FILE"; then
    pass "Upload attack: shell.php rejected via extension allowlist."
else
    fail "Upload attack: shell.php was not rejected."
fi

# 2. PHP file renamed shell.jpg
ATTACK_RENAMED="$TMP_DIR/shell_${RAND_ID}.jpg"
echo "<?php echo 'HACKED'; ?>" > "$ATTACK_RENAMED"

upload_post "$BASE_URL/admin/products/edit.php?id=$MAIN_PROD_ID" "$COOKIE_ADMIN" \
    -F "_csrf_token=${CSRF_ADMIN}" \
    -F "action=upload_images" \
    -F "product_id=${MAIN_PROD_ID}" \
    -F "images[]=@${ATTACK_RENAMED};type=image/jpeg"

if grep -qi "Invalid file type detected" "$BODY_FILE" || grep -qi "not a valid" "$BODY_FILE" || grep -qi "Upload failed" "$BODY_FILE"; then
    pass "Upload attack: PHP script renamed shell.jpg rejected via MIME & getimagesize check."
else
    fail "Upload attack: shell.jpg was not rejected."
fi

# 3. Plain text file with .jpg extension
ATTACK_TXT="$TMP_DIR/readme_${RAND_ID}.jpg"
echo "This is just plain text content." > "$ATTACK_TXT"

upload_post "$BASE_URL/admin/products/edit.php?id=$MAIN_PROD_ID" "$COOKIE_ADMIN" \
    -F "_csrf_token=${CSRF_ADMIN}" \
    -F "action=upload_images" \
    -F "product_id=${MAIN_PROD_ID}" \
    -F "images[]=@${ATTACK_TXT};type=image/jpeg"

if grep -qi "Invalid file type detected" "$BODY_FILE" || grep -qi "not a valid" "$BODY_FILE" || grep -qi "Upload failed" "$BODY_FILE"; then
    pass "Upload attack: Text file with .jpg extension rejected."
else
    fail "Upload attack: Text file with .jpg extension was not rejected."
fi

# 4. Oversized file (> 5MB)
ATTACK_OVERSIZED="$TMP_DIR/oversized_${RAND_ID}.jpg"
# Create 5.5MB file
dd if=/dev/zero of="$ATTACK_OVERSIZED" bs=1M count=6 >/dev/null 2>&1

upload_post "$BASE_URL/admin/products/edit.php?id=$MAIN_PROD_ID" "$COOKIE_ADMIN" \
    -F "_csrf_token=${CSRF_ADMIN}" \
    -F "action=upload_images" \
    -F "product_id=${MAIN_PROD_ID}" \
    -F "images[]=@${ATTACK_OVERSIZED};type=image/jpeg"

if grep -qi "exceeds maximum allowed size" "$BODY_FILE" || grep -qi "Upload failed" "$BODY_FILE"; then
    pass "Upload attack: Oversized file (>5MB) rejected."
else
    fail "Upload attack: Oversized file was not rejected."
fi

# 5. Image with embedded PHP in EXIF/comment
ATTACK_EMBEDDED="$TMP_DIR/embedded_${RAND_ID}.jpg"
/opt/lampp/bin/php -r "
    \$im = imagecreatetruecolor(400, 400);
    imagejpeg(\$im, '$ATTACK_EMBEDDED');
    file_put_contents('$ATTACK_EMBEDDED', '/* <?php phpinfo(); ?> */', FILE_APPEND);
"

upload_post "$BASE_URL/admin/products/edit.php?id=$MAIN_PROD_ID" "$COOKIE_ADMIN" \
    -F "_csrf_token=${CSRF_ADMIN}" \
    -F "action=upload_images" \
    -F "product_id=${MAIN_PROD_ID}" \
    -F "images[]=@${ATTACK_EMBEDDED};type=image/jpeg"

# Verify uploaded image stored on disk has NO PHP code
LAST_IMG_PATH=$(run_sql "SELECT path FROM product_images WHERE product_id = $MAIN_PROD_ID ORDER BY id DESC LIMIT 1;" | tail -n 1)
FULL_IMG_PATH="/opt/lampp/htdocs/shopwise-platform/public/$LAST_IMG_PATH"

if [ -f "$FULL_IMG_PATH" ]; then
    if grep -q "<?php" "$FULL_IMG_PATH"; then
        fail "Security failure: Stored image contains '<?php' script tag!"
    else
        pass "GD re-encoding neutralized embedded PHP comment; stored file contains no '<?php'."
    fi
else
    pass "Malicious embedded payload was safely stripped or rejected."
fi

# 6. Direct execution block test in public/uploads/
TEST_UPLOAD_EXEC="/opt/lampp/htdocs/shopwise-platform/public/uploads/exec_test_${RAND_ID}.php"
echo "<?php echo 'EXECUTED_PHP_IN_UPLOADS'; ?>" > "$TEST_UPLOAD_EXEC"

http_req "GET" "$BASE_URL/uploads/exec_test_${RAND_ID}.php"
rm -f "$TEST_UPLOAD_EXEC"

if [ "$HTTP_CODE" = "403" ] || [ "$HTTP_CODE" = "404" ] || ! grep -q "EXECUTED_PHP_IN_UPLOADS" "$BODY_FILE"; then
    pass "Direct PHP execution in public/uploads/ is blocked (HTTP $HTTP_CODE)."
else
    fail "Security risk: PHP script executed directly inside public/uploads/!"
fi

# ==============================================================================
# CHECK E: VALIDATION ERROR HANDLING
# ==============================================================================
info "Check (e): Server-Side Field Validation"

CSRF_ADMIN=$(get_csrf_token "$COOKIE_ADMIN" "$BASE_URL/admin/products/create.php")

# 1. Negative price
http_req "POST" "$BASE_URL/admin/products/create.php" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&name=ValTest1_${RAND_ID}&category_id=1&short_description=Test&description=%3Cp%3EDesc%3C%2Fp%3E&base_price=-500" \
    "application/x-www-form-urlencoded"

if grep -qi "Base price must be a positive number" "$BODY_FILE"; then
    pass "Validation: Negative price rejected with clear field error."
else
    fail "Negative price was not rejected."
fi

# 2. Compare-at price lower than base price
http_req "POST" "$BASE_URL/admin/products/create.php" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&name=ValTest2_${RAND_ID}&category_id=1&short_description=Test&description=%3Cp%3EDesc%3C%2Fp%3E&base_price=2000&compare_at_price=1500" \
    "application/x-www-form-urlencoded"

if grep -qi "Compare-at price must be strictly greater" "$BODY_FILE"; then
    pass "Validation: Compare-at lower than base price rejected."
else
    fail "Compare-at lower than base price was not rejected."
fi

# 3. Duplicate slug
http_req "POST" "$BASE_URL/admin/products/create.php" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&name=ValTest3_${RAND_ID}&slug=${MAIN_PROD_SLUG}&category_id=1&short_description=Test&description=%3Cp%3EDesc%3C%2Fp%3E&base_price=2000" \
    "application/x-www-form-urlencoded"

if grep -qi "already taken" "$BODY_FILE"; then
    pass "Validation: Duplicate product slug rejected."
else
    fail "Duplicate product slug was not rejected."
fi

# 4. Duplicate SKU in variant add
CSRF_ADMIN=$(get_csrf_token "$COOKIE_ADMIN" "$BASE_URL/admin/products/edit.php?id=$MAIN_PROD_ID&tab=variants")
EXISTING_SKU=$(run_sql "SELECT sku FROM product_variants WHERE product_id = $MAIN_PROD_ID LIMIT 1;" | tail -n 1)

http_req "POST" "$BASE_URL/admin/products/edit.php?id=$MAIN_PROD_ID" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&action=variant_add&product_id=${MAIN_PROD_ID}&sku=${EXISTING_SKU}&stock_qty=5&low_stock_threshold=2" \
    "application/x-www-form-urlencoded"

if grep -qi "already in use" "$BODY_FILE"; then
    pass "Validation: Duplicate SKU rejected."
else
    fail "Duplicate SKU was not rejected."
fi

# 5. Duplicate size + color combination
http_req "POST" "$BASE_URL/admin/products/edit.php?id=$MAIN_PROD_ID" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&action=variant_add&product_id=${MAIN_PROD_ID}&sku=NEW-SKU-${RAND_ID}&size=M&color=Red&stock_qty=5&low_stock_threshold=2" \
    "application/x-www-form-urlencoded"

if grep -qi "already exists" "$BODY_FILE"; then
    pass "Validation: Duplicate size/color combination on product rejected."
else
    fail "Duplicate size/color combination was not rejected."
fi

# 6. Negative stock quantity
http_req "POST" "$BASE_URL/admin/products/edit.php?id=$MAIN_PROD_ID" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&action=variant_add&product_id=${MAIN_PROD_ID}&sku=NEG-SKU-${RAND_ID}&size=XXL&color=Orange&stock_qty=-10&low_stock_threshold=2" \
    "application/x-www-form-urlencoded"

if grep -qi "non-negative" "$BODY_FILE"; then
    pass "Validation: Negative stock quantity rejected."
else
    fail "Negative stock quantity was not rejected."
fi

# 7. Invalid color HEX
http_req "POST" "$BASE_URL/admin/products/edit.php?id=$MAIN_PROD_ID" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&action=variant_add&product_id=${MAIN_PROD_ID}&sku=HEX-SKU-${RAND_ID}&size=XXL&color=Orange&color_hex=NOT_HEX&stock_qty=5&low_stock_threshold=2" \
    "application/x-www-form-urlencoded"

if grep -qi "valid 6-digit hex" "$BODY_FILE"; then
    pass "Validation: Invalid color hex rejected."
else
    fail "Invalid color hex was not rejected."
fi

# 8. Category parent set to itself
VAL_CAT_NAME="ValCat_${RAND_ID}"
VAL_CAT_SLUG="valcat-${RAND_ID}"
run_sql "INSERT INTO categories (name, slug, sort_order, is_active) VALUES ('$VAL_CAT_NAME', '$VAL_CAT_SLUG', 99, 1);"
VAL_CAT_ID=$(run_sql "SELECT id FROM categories WHERE slug = '$VAL_CAT_SLUG' LIMIT 1;" | tail -n 1)

CSRF_ADMIN=$(get_csrf_token "$COOKIE_ADMIN" "$BASE_URL/admin/categories/")

http_req "POST" "$BASE_URL/admin/categories/" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&action=update&id=${VAL_CAT_ID}&name=${VAL_CAT_NAME}&slug=${VAL_CAT_SLUG}&parent_id=${VAL_CAT_ID}" \
    "application/x-www-form-urlencoded"

if grep -qi "own parent" "$BODY_FILE"; then
    pass "Validation: Category set as its own parent rejected."
else
    fail "Category set as its own parent was not rejected."
fi

run_sql "DELETE FROM categories WHERE id = ${VAL_CAT_ID};"

# ==============================================================================
# CHECK F: BUSINESS RULES (ORDER ITEMS, LAST VARIANT, CATEGORY BLOCK)
# ==============================================================================
info "Check (f): Business Rules Enforcement"

# 1. Cannot delete or deactivate last active variant
# Create a single-variant product
PROD_SINGLE_NAME="SingleVar_${RAND_ID}"
PROD_SINGLE_SLUG="singlevar-${RAND_ID}"

CSRF_ADMIN=$(get_csrf_token "$COOKIE_ADMIN" "$BASE_URL/admin/products/create.php")
http_req "POST" "$BASE_URL/admin/products/create.php" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&name=${PROD_SINGLE_NAME}&slug=${PROD_SINGLE_SLUG}&category_id=1&short_description=Single&description=%3Cp%3EDesc%3C%2Fp%3E&base_price=1000" \
    "application/x-www-form-urlencoded"

SINGLE_PROD_ID=$(run_sql "SELECT id FROM products WHERE slug = '$PROD_SINGLE_SLUG' LIMIT 1;" | tail -n 1)
SINGLE_VAR_ID=$(run_sql "SELECT id FROM product_variants WHERE product_id = $SINGLE_PROD_ID LIMIT 1;" | tail -n 1)

# Try deleting the only variant
CSRF_ADMIN=$(get_csrf_token "$COOKIE_ADMIN" "$BASE_URL/admin/products/edit.php?id=$SINGLE_PROD_ID&tab=variants")
http_req "POST" "$BASE_URL/admin/products/edit.php?id=$SINGLE_PROD_ID" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&action=variant_delete&product_id=${SINGLE_PROD_ID}&variant_id=${SINGLE_VAR_ID}" \
    "application/x-www-form-urlencoded"

if grep -qi "must keep at least one active variant" "$BODY_FILE"; then
    pass "Rule: Deleting the last active variant is strictly blocked."
else
    fail "Deleting last active variant was not blocked."
fi

# Try deactivating the only variant
VAR_SKU=$(run_sql "SELECT sku FROM product_variants WHERE id = $SINGLE_VAR_ID;" | tail -n 1)
http_req "POST" "$BASE_URL/admin/products/edit.php?id=$SINGLE_PROD_ID" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&action=variant_update&product_id=${SINGLE_PROD_ID}&variant_id=${SINGLE_VAR_ID}&sku=${VAR_SKU}&stock_qty=5&low_stock_threshold=2" \
    "application/x-www-form-urlencoded"

if grep -qi "must keep at least one active variant" "$BODY_FILE"; then
    pass "Rule: Deactivating the last active variant is strictly blocked."
else
    fail "Deactivating last active variant was not blocked."
fi

# 2. Variant in order_items cannot be deleted, only deactivated
# Seeded demo order has variant ID 2
ORDER_VAR_ID="2"
ORDER_VAR_PROD_ID=$(run_sql "SELECT product_id FROM product_variants WHERE id = $ORDER_VAR_ID;" | tail -n 1)

CSRF_ADMIN=$(get_csrf_token "$COOKIE_ADMIN" "$BASE_URL/admin/products/edit.php?id=$ORDER_VAR_PROD_ID&tab=variants")
http_req "POST" "$BASE_URL/admin/products/edit.php?id=$ORDER_VAR_PROD_ID" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&action=variant_delete&product_id=${ORDER_VAR_PROD_ID}&variant_id=${ORDER_VAR_ID}" \
    "application/x-www-form-urlencoded"

if grep -qi "linked to past customer orders" "$BODY_FILE" || grep -qi "cannot be deleted" "$BODY_FILE"; then
    pass "Rule: Variant referenced in order_items cannot be deleted."
else
    fail "Deleting variant in order_items was not blocked."
fi

# Deactivating the order item variant should succeed
ORDER_VAR_SKU=$(run_sql "SELECT sku FROM product_variants WHERE id = $ORDER_VAR_ID;" | tail -n 1)
http_req "POST" "$BASE_URL/admin/products/edit.php?id=$ORDER_VAR_PROD_ID" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&action=variant_update&product_id=${ORDER_VAR_PROD_ID}&variant_id=${ORDER_VAR_ID}&sku=${ORDER_VAR_SKU}&stock_qty=10&low_stock_threshold=5" \
    "application/x-www-form-urlencoded"

ORDER_VAR_ACTIVE=$(run_sql "SELECT is_active FROM product_variants WHERE id = $ORDER_VAR_ID;" | tail -n 1)
if [ "$ORDER_VAR_ACTIVE" = "0" ]; then
    pass "Rule: Variant referenced in order_items can be deactivated."
    # Reactivate to maintain demo integrity
    run_sql "UPDATE product_variants SET is_active = 1 WHERE id = $ORDER_VAR_ID;"
else
    fail "Deactivating variant in order_items failed."
fi

# 3. Category with products cannot be deleted
CSRF_ADMIN=$(get_csrf_token "$COOKIE_ADMIN" "$BASE_URL/admin/categories/")
http_req "POST" "$BASE_URL/admin/categories/" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&action=delete&id=1" \
    "application/x-www-form-urlencoded"

if grep -qi "is assigned to" "$BODY_FILE" || grep -qi "Cannot delete category" "$BODY_FILE"; then
    pass "Rule: Category with active products cannot be deleted."
else
    fail "Deleting category with products was not blocked."
fi

# 4. Product with order items cannot be hard deleted
CSRF_ADMIN=$(get_csrf_token "$COOKIE_ADMIN" "$BASE_URL/admin/products/")
http_req "POST" "$BASE_URL/admin/products/" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&action=hard_delete&id=1" \
    "application/x-www-form-urlencoded"

if grep -qi "cannot be permanently deleted" "$BODY_FILE" || grep -qi "order item record" "$BODY_FILE"; then
    pass "Rule: Product with past order items cannot be hard deleted."
else
    fail "Hard-deleting product with order items was not blocked."
fi

# ==============================================================================
# CHECK G: INVENTORY CONCURRENCY & ADJUSTMENTS
# ==============================================================================
info "Check (g): Inventory Concurrency Safety & Adjustment Tracking"

# Create a dedicated variant for concurrency testing with stock 10
CONC_SKU="CONC-${RAND_ID}"
run_sql "INSERT INTO product_variants (product_id, sku, size, color, stock_qty, low_stock_threshold, is_active)
         VALUES ($MAIN_PROD_ID, '$CONC_SKU', 'CONC', 'Black', 10, 2, 1);"
CONC_VAR_ID=$(run_sql "SELECT id FROM product_variants WHERE sku = '$CONC_SKU';" | tail -n 1)

CSRF_ADMIN=$(get_csrf_token "$COOKIE_ADMIN" "$BASE_URL/admin/inventory/")

# Fire 20 parallel adjustments of -1 in background
CONC_RESP_DIR="$TMP_DIR/conc_resp"
mkdir -p "$CONC_RESP_DIR"

for i in {1..20}; do
    (
        curl -sS -H "Accept: application/json" -b "$COOKIE_ADMIN" -X POST "$BASE_URL/admin/inventory/" \
            -d "_csrf_token=${CSRF_ADMIN}&variant_id=${CONC_VAR_ID}&mode=delta&value=-1&reason=correction&note=Concurrent+Test" \
            -o "$CONC_RESP_DIR/res_$i.json"
    ) &
done
wait

# Verify final stock is EXACTLY 0 (never negative)
FINAL_STOCK=$(run_sql "SELECT stock_qty FROM product_variants WHERE id = $CONC_VAR_ID;" | tail -n 1)
if [ "$FINAL_STOCK" = "0" ]; then
    pass "Concurrent safety: Final stock is exactly 0 under 20 parallel decrements."
else
    fail "Concurrent safety failed: Final stock is $FINAL_STOCK (expected 0)."
fi

# Verify exactly 10 adjustments succeeded and recorded movements
MOV_COUNT=$(run_sql "SELECT COUNT(*) FROM inventory_movements WHERE variant_id = $CONC_VAR_ID AND change_qty = -1;" | tail -n 1)
if [ "$MOV_COUNT" = "10" ]; then
    pass "Concurrent safety: Exactly 10 inventory movements were recorded."
else
    fail "Inventory movements count is $MOV_COUNT (expected exactly 10)."
fi

echo "Concurrency Test Metrics:"
echo "  Initial Stock:           10"
echo "  Final Stock:             $FINAL_STOCK"
echo "  Recorded Movements (-1): $MOV_COUNT"

info "RAW RESPONSE BODIES OF 3 REFUSED REQUESTS (Clean messages, no SQL exceptions)"
REFUSED_FILES=($(grep -l '"success":false' "$CONC_RESP_DIR"/res_*.json 2>/dev/null | head -n 3))
if [ "${#REFUSED_FILES[@]}" -ge 3 ]; then
    for idx in 0 1 2; do
        num=$((idx + 1))
        echo -e "${YELLOW}--- Refused Request #$num RAW Response Body ---${NC}"
        cat "${REFUSED_FILES[$idx]}"
        echo ""
    done
    pass "Verified clean user-facing refused response messages on stock underflow."
else
    echo "Warning: Fewer than 3 refused response files found."
    fail "Failed to find 3 refused responses."
fi

# Test positive delta adjustment (+15)
http_req "POST" "$BASE_URL/admin/inventory/" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&variant_id=${CONC_VAR_ID}&mode=delta&value=15&reason=restock&note=Replenishment" \
    "application/x-www-form-urlencoded"

STOCK_AFTER_ADD=$(run_sql "SELECT stock_qty FROM product_variants WHERE id = $CONC_VAR_ID;" | tail -n 1)
LAST_MOV_QTY=$(run_sql "SELECT change_qty FROM inventory_movements WHERE variant_id = $CONC_VAR_ID ORDER BY id DESC LIMIT 1;" | tail -n 1)

if [ "$STOCK_AFTER_ADD" = "15" ] && [ "$LAST_MOV_QTY" = "15" ]; then
    pass "Positive delta adjustment correctly logged +15 and updated stock to 15."
else
    fail "Positive delta adjustment failed (stock: $STOCK_AFTER_ADD, delta: $LAST_MOV_QTY)."
fi

# Test set-to-value adjustment (set to 25 -> signed delta should be +10)
http_req "POST" "$BASE_URL/admin/inventory/" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&variant_id=${CONC_VAR_ID}&mode=set&value=25&reason=correction&note=Inventory+audit+count" \
    "application/x-www-form-urlencoded"

STOCK_AFTER_SET=$(run_sql "SELECT stock_qty FROM product_variants WHERE id = $CONC_VAR_ID;" | tail -n 1)
SET_MOV_QTY=$(run_sql "SELECT change_qty FROM inventory_movements WHERE variant_id = $CONC_VAR_ID ORDER BY id DESC LIMIT 1;" | tail -n 1)

if [ "$STOCK_AFTER_SET" = "25" ] && [ "$SET_MOV_QTY" = "10" ]; then
    pass "Set-to-value adjustment (from 15 to 25) logged signed delta of +10."
else
    fail "Set-to-value adjustment failed (stock: $STOCK_AFTER_SET, delta: $SET_MOV_QTY)."
fi

# ==============================================================================
# CHECK H: SECURITY (SQLi, XSS, CSRF, IDOR)
# ==============================================================================
info "Check (h): Security Hardening (SQLi, XSS, CSRF, IDOR)"

# 1. SQL Injection strings in search and filters
SQLI_TESTS=("' OR 1=1 --" "'; DROP TABLE products; --" "' UNION SELECT NULL--")
for sqli in "${SQLI_TESTS[@]}"; do
    ENCODED=$(/opt/lampp/bin/php -r 'echo urlencode($argv[1]);' -- "$sqli")
    http_req "GET" "$BASE_URL/admin/products/?search=$ENCODED" "$COOKIE_ADMIN"
    if [ "$HTTP_CODE" = "200" ] && check_php_errors "$BODY_FILE"; then
        pass "SQL injection probe safely handled: $sqli"
    else
        fail "SQL injection probe failed (HTTP $HTTP_CODE): $sqli"
    fi
done

# 2. XSS escaping
XSS_NAME="<script>alert(1)</script>${RAND_ID}"
XSS_SLUG="xss-test-${RAND_ID}"
CSRF_ADMIN=$(get_csrf_token "$COOKIE_ADMIN" "$BASE_URL/admin/products/create.php")

http_req "POST" "$BASE_URL/admin/products/create.php" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&name=${XSS_NAME}&slug=${XSS_SLUG}&category_id=1&short_description=XSS+test&description=%3Cp%3EDesc%3C%2Fp%3E&base_price=1200" \
    "application/x-www-form-urlencoded"

XSS_PROD_ID=$(run_sql "SELECT id FROM products WHERE slug = '$XSS_SLUG' LIMIT 1;" | tail -n 1)

# Check list page
http_req "GET" "$BASE_URL/admin/products/?search=${XSS_SLUG}" "$COOKIE_ADMIN"
if grep -q "&lt;script&gt;alert(1)&lt;/script&gt;" "$BODY_FILE" && ! grep -q "<script>alert(1)</script>" "$BODY_FILE"; then
    pass "XSS test: Product name correctly escaped in products list table."
else
    fail "XSS test failed in products list table."
fi

# Check edit page
http_req "GET" "$BASE_URL/admin/products/edit.php?id=$XSS_PROD_ID" "$COOKIE_ADMIN"
if grep -q "&lt;script&gt;alert(1)&lt;/script&gt;" "$BODY_FILE" || grep -q 'value="&lt;script&gt;alert(1)&lt;/script&gt;' "$BODY_FILE" || ! grep -q "<script>alert(1)</script>" "$BODY_FILE"; then
    pass "XSS test: Product name correctly escaped on product edit screen."
else
    fail "XSS test failed on product edit screen."
fi

# 3. CSRF token missing / invalid -> must return 403
http_req "POST" "$BASE_URL/admin/products/create.php" "$COOKIE_ADMIN" \
    "_csrf_token=INVALID_TOKEN&name=CsrfAttack&category_id=1&short_description=Attack&description=%3Cp%3EA%3C%2Fp%3E&base_price=500" \
    "application/x-www-form-urlencoded"

if [ "$HTTP_CODE" = "403" ]; then
    pass "CSRF test: Invalid CSRF token returns HTTP 403 Forbidden."
else
    fail "CSRF test failed: Got HTTP $HTTP_CODE (expected 403)."
fi

# 4. IDOR tampering: Attempt to update/delete variant belonging to another product
# Product A has variant X; caller posts to edit.php?id=Product_B with variant_id=X
VAR_BELONGING_TO_OTHER="1" # Belongs to Product 1
CSRF_ADMIN=$(get_csrf_token "$COOKIE_ADMIN" "$BASE_URL/admin/products/edit.php?id=$MAIN_PROD_ID&tab=variants")

http_req "POST" "$BASE_URL/admin/products/edit.php?id=$MAIN_PROD_ID" "$COOKIE_ADMIN" \
    "_csrf_token=${CSRF_ADMIN}&action=variant_update&product_id=${MAIN_PROD_ID}&variant_id=${VAR_BELONGING_TO_OTHER}&sku=HACKED-SKU&stock_qty=99&low_stock_threshold=5" \
    "application/x-www-form-urlencoded"

if grep -qi "does not belong" "$BODY_FILE"; then
    pass "IDOR test: Tampering with variant_id belonging to another product was rejected."
else
    fail "IDOR test failed: Cross-product variant modification was not blocked."
fi

# ==============================================================================
# CHECK I: AUDIT LOG VERIFICATION
# ==============================================================================
info "Check (i): Audit Logging Completeness"

AUDIT_WRITES=$(run_sql "SELECT COUNT(*) FROM audit_log WHERE details LIKE '%${RAND_ID}%';" | tail -n 1)
if [ "$AUDIT_WRITES" -gt 0 ]; then
    pass "Audit log integration verified: Recorded $AUDIT_WRITES write action(s) for test run."
else
    fail "No audit log rows found for test operations."
fi

# Execute cleanup and hygiene audit prior to summary
cleanup

# Summary
info "TEST EXECUTION SUMMARY"
echo -e "Total Passed: ${GREEN}${PASSED}${NC}"
echo -e "Total Failed: ${RED}${FAILED}${NC}"

if [ "$FAILED" -eq 0 ]; then
    echo -e "\n${BOLD}${GREEN}ALL PHASE 2B ACCEPTANCE TESTS PASSED!${NC}\n"
    exit 0
else
    echo -e "\n${BOLD}${RED}SOME TESTS FAILED.${NC}\n"
    exit 1
fi
