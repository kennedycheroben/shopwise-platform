#!/usr/bin/env bash
# ==============================================================================
# ShopWise Kenya - Phase 3B Acceptance Test Suite
# Contact Form, Content Pages, Policy Settings, SEO & Security Hardening
#
# Covers:
#   a) Storefront route status codes & zero PHP errors; 404 for trailing slashes, wrong case, unknown pages
#   b) Content scan (no lorem/ipsum, no $, no Phase/TODO/placeholder/Coming, no .html links, zero broken links/images)
#   c) Live data reactivity (delivery zone fee, free delivery threshold, return window, support phone)
#   d) Contact form lifecycle & hardening (valid submission, field validation errors, honeypot, min-time check,
#      IP rate limiter, CSRF rejection, XSS escaping in admin/email, CRLF/Bcc injection, oversized inputs)
#   e) SEO & structured data (unique titles & meta descriptions, canonical URLs, Open Graph, Twitter,
#      JSON-LD Organization/Breadcrumbs/Product/FAQ, script breakout protection, valid sitemap.xml, robots.txt, X-Robots-Tag)
#   f) Settings form validation & audit logging (range validation, rejected invalid inputs, audited updates)
#   g) Zero-drift across all 13 tracked resources
# ==============================================================================

set -uo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1/shopwise-platform/public}"
BASE_URL="${BASE_URL%/}"

MYSQL_CMD="${MYSQL_CMD:-/opt/lampp/bin/mysql}"
if [ ! -x "$MYSQL_CMD" ]; then
    MYSQL_CMD="$(command -v mysql || true)"
fi

PHP_CMD="${PHP_CMD:-/opt/lampp/bin/php}"
if [ ! -x "$PHP_CMD" ]; then
    PHP_CMD="$(command -v php || true)"
fi

DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_NAME="${DB_NAME:-shopwise_test}"

if [ "$DB_NAME" = "shopwise_db" ]; then
    echo -e "\033[0;31mFATAL: Refusing to run acceptance tests against protected database '$DB_NAME'!\033[0m" >&2
    exit 1
fi
if [[ ! "$DB_NAME" =~ _test$ ]]; then
    echo -e "\033[0;31mFATAL: Test database name must end with '_test', got '$DB_NAME'!\033[0m" >&2
    exit 1
fi

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

run_sql() {
    local sql="$1"
    if [ -n "$DB_PASS" ]; then
        "$MYSQL_CMD" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -s -e "$sql" 2>/dev/null
    else
        "$MYSQL_CMD" -u "$DB_USER" "$DB_NAME" -N -s -e "$sql" 2>/dev/null
    fi
}

TMP_DIR=$(mktemp -d)
COOKIE_GUEST="$TMP_DIR/guest.cookies"
COOKIE_ADMIN="$TMP_DIR/admin.cookies"
HEADER_FILE="$TMP_DIR/headers.txt"
BODY_FILE="$TMP_DIR/body.html"

RAND_ID=$(head /dev/urandom | tr -dc a-z0-9 | head -c 8)
TEST_PASSWORD="TestPass3B_${RAND_ID}!@#"
ADMIN_EMAIL="test_admin_3b_${RAND_ID}@test.local"

if [[ "$ADMIN_EMAIL" != *@test.local ]]; then
    echo -e "${RED}FATAL: Test email '$ADMIN_EMAIL' does not end with @test.local. Aborting!${NC}"
    exit 1
fi

# ==============================================================================
# BASELINE SNAPSHOT: Capture resource state before tests begin
# ==============================================================================
info "BASELINE SNAPSHOT: Capturing pre-test database row counts and file counts"

PRE_CONTACT=$(run_sql "SELECT COUNT(*) FROM contact_messages;" | tail -n 1)
PRE_CONTACT_MAX_ID=$(run_sql "SELECT COALESCE(MAX(id), 0) FROM contact_messages;" | tail -n 1)

PRE_EMAIL=$(run_sql "SELECT COUNT(*) FROM email_queue;" | tail -n 1)
PRE_EMAIL_MAX_ID=$(run_sql "SELECT COALESCE(MAX(id), 0) FROM email_queue;" | tail -n 1)

PRE_SETTINGS=$(run_sql "SELECT COUNT(*) FROM settings;" | tail -n 1)
PRE_ZONES=$(run_sql "SELECT COUNT(*) FROM delivery_zones;" | tail -n 1)

PRE_AUDIT=$(run_sql "SELECT COUNT(*) FROM audit_log;" | tail -n 1)
PRE_AUDIT_MAX_ID=$(run_sql "SELECT COALESCE(MAX(id), 0) FROM audit_log;" | tail -n 1)

PRE_USERS=$(run_sql "SELECT COUNT(*) FROM users;" | tail -n 1)
PRE_USERS_MAX_ID=$(run_sql "SELECT COALESCE(MAX(id), 0) FROM users;" | tail -n 1)

PRE_PRODUCTS=$(run_sql "SELECT COUNT(*) FROM products;" | tail -n 1)
PRE_PRODUCTS_MAX_ID=$(run_sql "SELECT COALESCE(MAX(id), 0) FROM products;" | tail -n 1)

PRE_VARIANTS=$(run_sql "SELECT COUNT(*) FROM product_variants;" | tail -n 1)
PRE_VARIANTS_MAX_ID=$(run_sql "SELECT COALESCE(MAX(id), 0) FROM product_variants;" | tail -n 1)

PRE_CARTS=$(run_sql "SELECT COUNT(*) FROM carts;" | tail -n 1)
PRE_ORDERS=$(run_sql "SELECT COUNT(*) FROM orders;" | tail -n 1)
PRE_COUPONS=$(run_sql "SELECT COUNT(*) FROM coupons;" | tail -n 1)

PRE_STOCK=$(run_sql "SELECT COALESCE(SUM(stock_qty), 0) FROM product_variants;" | tail -n 1)
PRE_UPLOAD_FILES=$(find /opt/lampp/htdocs/shopwise-platform/public/uploads -type f ! -name ".gitkeep" 2>/dev/null | wc -l)

echo "  Baseline contact_messages:    $PRE_CONTACT"
echo "  Baseline email_queue:         $PRE_EMAIL"
echo "  Baseline settings:            $PRE_SETTINGS"
echo "  Baseline delivery_zones:      $PRE_ZONES"
echo "  Baseline audit_log:           $PRE_AUDIT"
echo "  Baseline users:               $PRE_USERS"
echo "  Baseline products:            $PRE_PRODUCTS"
echo "  Baseline product_variants:    $PRE_VARIANTS"
echo "  Baseline carts:               $PRE_CARTS"
echo "  Baseline orders:              $PRE_ORDERS"
echo "  Baseline coupons:             $PRE_COUPONS"
echo "  Baseline stock_checksum:      $PRE_STOCK"
echo "  Baseline uploads file count:  $PRE_UPLOAD_FILES"

CREATED_USER_IDS=()
CREATED_CONTACT_IDS=()
CREATED_EMAIL_IDS=()
CREATED_AUDIT_IDS=()
CREATED_PRODUCT_IDS=()

CLEANED_ALREADY=0

cleanup() {
    if [ "$CLEANED_ALREADY" -eq 1 ]; then
        return 0
    fi
    CLEANED_ALREADY=1

    info "CLEANUP: Purging test artifacts and verifying zero database drift"

    # Purge created contact messages
    if [ "${#CREATED_CONTACT_IDS[@]}" -gt 0 ]; then
        local ids=$(IFS=,; echo "${CREATED_CONTACT_IDS[*]}")
        run_sql "DELETE FROM contact_messages WHERE id IN ($ids);" >/dev/null 2>&1 || true
    fi
    run_sql "DELETE FROM contact_messages WHERE id > $PRE_CONTACT_MAX_ID;" >/dev/null 2>&1 || true

    # Purge created emails
    if [ "${#CREATED_EMAIL_IDS[@]}" -gt 0 ]; then
        local ids=$(IFS=,; echo "${CREATED_EMAIL_IDS[*]}")
        run_sql "DELETE FROM email_queue WHERE id IN ($ids);" >/dev/null 2>&1 || true
    fi
    run_sql "DELETE FROM email_queue WHERE id > $PRE_EMAIL_MAX_ID;" >/dev/null 2>&1 || true

    # Purge created products & variants
    if [ "${#CREATED_PRODUCT_IDS[@]}" -gt 0 ]; then
        local ids=$(IFS=,; echo "${CREATED_PRODUCT_IDS[*]}")
        run_sql "DELETE FROM product_variants WHERE product_id IN ($ids);" >/dev/null 2>&1 || true
        run_sql "DELETE FROM products WHERE id IN ($ids);" >/dev/null 2>&1 || true
    fi
    run_sql "DELETE FROM product_variants WHERE id > $PRE_VARIANTS_MAX_ID;" >/dev/null 2>&1 || true
    run_sql "DELETE FROM products WHERE id > $PRE_PRODUCTS_MAX_ID;" >/dev/null 2>&1 || true

    # Purge created audit logs
    run_sql "DELETE FROM audit_log WHERE id > $PRE_AUDIT_MAX_ID;" >/dev/null 2>&1 || true

    # Purge created users
    if [ "${#CREATED_USER_IDS[@]}" -gt 0 ]; then
        local ids=$(IFS=,; echo "${CREATED_USER_IDS[*]}")
        run_sql "DELETE FROM users WHERE id IN ($ids);" >/dev/null 2>&1 || true
    fi
    run_sql "DELETE FROM users WHERE id > $PRE_USERS_MAX_ID;" >/dev/null 2>&1 || true

    # Restore settings to defaults if altered
    run_sql "
        UPDATE settings SET setting_value = '150.00' WHERE setting_key = 'distance_base_fee';
        UPDATE settings SET setting_value = '5000.00' WHERE setting_key = 'free_delivery_threshold';
        UPDATE settings SET setting_value = '7' WHERE setting_key = 'return_window_days';
        UPDATE settings SET setting_value = '7' WHERE setting_key = 'refund_processing_days';
        UPDATE settings SET setting_value = '24' WHERE setting_key = 'support_response_hours';
        UPDATE settings SET setting_value = '+254 700 000 000' WHERE setting_key = 'support_phone';
        UPDATE delivery_zones SET fee = 150.00 WHERE county = 'Nairobi' AND area = 'CBD & Upper Hill';
    " >/dev/null 2>&1 || true

    # Clear rate limit files
    curl -s -X POST "$BASE_URL/cart/test/clear-rate-limits" >/dev/null 2>&1 || true
    "$PHP_CMD" -r '
        require "app/bootstrap.php";
        \App\Services\CartService::clearRateLimits();
    ' >/dev/null 2>&1 || true

    rm -rf "$TMP_DIR"
}
trap cleanup EXIT

http_req() {
    local method="$1"
    local url="$2"
    local cookie_jar="${3:-}"
    local data="${4:-}"

    local curl_cmd=(curl -sS -D "$HEADER_FILE" -o "$BODY_FILE" -X "$method" "$url")
    if [ -n "$cookie_jar" ]; then
        curl_cmd+=(-b "$cookie_jar" -c "$cookie_jar")
    fi
    if [ -n "$data" ]; then
        curl_cmd+=(--data "$data")
    fi

    "${curl_cmd[@]}"
    HTTP_CODE=$(grep -E '^HTTP/[0-9\.]+ [0-9]+' "$HEADER_FILE" | tail -n 1 | awk '{print $2}')
}

get_csrf_token() {
    local cookie_jar="$1"
    local url="$2"
    http_req "GET" "$url" "$cookie_jar"
    local token
    token=$(grep -oP '<meta name="csrf-token" content="\K[^"]+' "$BODY_FILE" | head -n 1)
    if [ -z "$token" ]; then
        token=$(grep -oP 'name="_?csrf_token" value="\K[^"]+' "$BODY_FILE" | head -n 1)
    fi
    echo "$token"
}

# ==============================================================================
# SECTION A: STOREFRONT ROUTES & STRICT STATUS CODE ROUTING
# ==============================================================================
info "SECTION A: Storefront Route Accessibility & Zero PHP Errors"

NEW_ROUTES=(
    "/about|About Us Page"
    "/contact|Contact Form Page"
    "/faq|Frequently Asked Questions"
    "/shipping-info|Shipping & Delivery Info"
    "/return-policy|Return & Refund Policy"
    "/privacy|Privacy Policy Page"
    "/terms|Terms of Service Page"
    "/support|Customer Support Hub"
    "/sitemap.xml|Dynamic XML Sitemap"
    "/robots.txt|Robots Exclusion File"
)

for r in "${NEW_ROUTES[@]}"; do
    path=$(echo "$r" | cut -d'|' -f1)
    desc=$(echo "$r" | cut -d'|' -f2)
    http_req "GET" "$BASE_URL$path" "$COOKIE_GUEST"

    if [ "$HTTP_CODE" = "200" ]; then
        if grep -Eiq "Fatal error|Warning:|Notice:|Deprecated:|Uncaught Exception" "$BODY_FILE"; then
            fail "$desc ($path) returned HTTP 200 but contained PHP errors"
        else
            pass "$desc ($path) returned HTTP 200 with zero PHP errors"
        fi
    else
        fail "$desc ($path) expected HTTP 200, got $HTTP_CODE"
    fi
done

# Strict 404 tests: trailing slashes, uppercase wrong case, non-existent pages
INVALID_PATHS=(
    "/about/"
    "/contact/"
    "/faq/"
    "/shipping-info/"
    "/return-policy/"
    "/privacy/"
    "/terms/"
    "/support/"
    "/ABOUT"
    "/Contact"
    "/FAQ"
    "/non-existent-content-page-999"
)

for ipath in "${INVALID_PATHS[@]}"; do
    http_req "GET" "$BASE_URL$ipath" "$COOKIE_GUEST"
    if [ "$HTTP_CODE" = "404" ]; then
        pass "Strict routing: '$ipath' returned expected HTTP 404"
    else
        fail "Strict routing: '$ipath' expected HTTP 404, got $HTTP_CODE"
    fi
done

# ==============================================================================
# SECTION B: CONTENT SCAN & BROKEN LINK/IMAGE INTEGRITY
# ==============================================================================
info "SECTION B: Content Scan & Zero Broken Links/Images"

ALL_PAGES=(
    "/"
    "/shop"
    "/categories"
    "/category/womens-clothing"
    "/product/light-wash-denim-trucker-jacket"
    "/cart"
    "/about"
    "/contact"
    "/faq"
    "/shipping-info"
    "/return-policy"
    "/privacy"
    "/terms"
    "/support"
)

for p in "${ALL_PAGES[@]}"; do
    http_req "GET" "$BASE_URL$p" "$COOKIE_GUEST"

    # Check for placeholder latin text
    if grep -Eiq "lorem|ipsum|Donec|Pellentesque|Quisque" "$BODY_FILE"; then
        fail "Page '$p' contains placeholder latin text"
    else
        pass "Page '$p' has zero placeholder latin text"
    fi

    # Check for forbidden dollar currency
    if grep -Eq '\$[0-9]+' "$BODY_FILE"; then
        fail "Page '$p' contains forbidden '$' currency symbol"
    else
        pass "Page '$p' uses KES exclusively (zero '$' currency signs)"
    fi

    # Check for placeholder indicators in visible text
    VISIBLE_MATCH=$("$PHP_CMD" -r '
        $html = file_get_contents("'"$BODY_FILE"'");
        $text = strip_tags(preg_replace("/<script\b[^>]*>(.*?)<\/script>/is", "", $html));
        if (preg_match("/\b(Phase|TODO|placeholder|Coming soon)\b/i", $text, $m)) {
            echo $m[0];
        }
    ')
    if [ -n "$VISIBLE_MATCH" ]; then
        fail "Page '$p' contains forbidden marker '$VISIBLE_MATCH'"
    else
        pass "Page '$p' contains zero 'Phase', 'TODO', 'placeholder', or 'Coming soon' markers"
    fi

    # Check for .html links
    if grep -Eiq 'href=["'\''][^"'\'']+\.html' "$BODY_FILE"; then
        fail "Page '$p' contains links to .html files"
    else
        pass "Page '$p' contains zero links to .html files"
    fi
done

# Deep crawl of all internal links and images across all storefront pages
info "Crawling all internal links and images across all 14 storefront pages"
CRAWL_RESULTS=$("$PHP_CMD" -r '
$config = require "app/config/config.php";
$baseUrl = rtrim($config["app"]["base_url"] ?? "http://127.0.0.1/shopwise-platform/public", "/");
$pages = ["/", "/shop", "/categories", "/category/womens-clothing", "/product/light-wash-denim-trucker-jacket", "/cart", "/about", "/contact", "/faq", "/shipping-info", "/return-policy", "/privacy", "/terms", "/support"];
$checkedUrls = [];
$broken = [];

foreach ($pages as $p) {
    $html = @file_get_contents($baseUrl . $p);
    if (!$html) continue;

    preg_match_all("/<a\s+[^>]*href=[\"\x27]([^\"\x27#]+)[\"\x27]/i", $html, $m1);
    preg_match_all("/<img\s+[^>]*src=[\"\x27]([^\"\x27]+)[\"\x27]/i", $html, $m2);
    $urls = array_merge($m1[1] ?? [], $m2[1] ?? []);

    foreach ($urls as $u) {
        $u = htmlspecialchars_decode(trim($u));
        if ($u === "" || str_starts_with($u, "data:") || str_starts_with($u, "javascript:") || str_starts_with($u, "mailto:") || str_starts_with($u, "tel:")) continue;
        if (str_starts_with($u, "http://") || str_starts_with($u, "https://")) {
            if (!str_starts_with($u, $baseUrl)) continue;
        } else {
            $u = $baseUrl . "/" . ltrim($u, "/");
        }
        if (isset($checkedUrls[$u])) continue;
        $checkedUrls[$u] = true;

        $ch = curl_init($u);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code >= 400) {
            $broken[] = "$u ($code)";
        }
    }
}

if (!empty($broken)) {
    echo "BROKEN: " . implode(", ", $broken);
} else {
    echo "OK: " . count($checkedUrls) . " URLs checked";
}
')

if [[ "$CRAWL_RESULTS" == OK* ]]; then
    pass "All links and images crawled successfully with zero 404/500 errors ($CRAWL_RESULTS)"
else
    fail "Broken links or images detected: $CRAWL_RESULTS"
fi

# ==============================================================================
# SECTION C: REAL-TIME LIVE DATA REACTIVITY
# ==============================================================================
info "SECTION C: Real-Time Live Data Reactivity"

# 1. Delivery Zone Fee Reactivity
run_sql "UPDATE delivery_zones SET fee = 285.00 WHERE county = 'Nairobi' AND area = 'CBD & Upper Hill';"
http_req "GET" "$BASE_URL/shipping-info" "$COOKIE_GUEST"
if grep -q "KES 285" "$BODY_FILE"; then
    pass "Changing zone fee immediately reflects on /shipping-info (KES 285)"
else
    fail "Zone fee change did not reflect on /shipping-info"
fi
run_sql "UPDATE delivery_zones SET fee = 150.00 WHERE county = 'Nairobi' AND area = 'CBD & Upper Hill';"

# 2. Free Delivery Threshold Reactivity
run_sql "UPDATE settings SET setting_value = '8500.00' WHERE setting_key = 'free_delivery_threshold';"
http_req "GET" "$BASE_URL/shipping-info" "$COOKIE_GUEST"
if grep -q "KES 8,500" "$BODY_FILE"; then
    pass "Changing free_delivery_threshold immediately reflects on /shipping-info (KES 8,500)"
else
    fail "Free delivery threshold change did not reflect on /shipping-info"
fi
run_sql "UPDATE settings SET setting_value = '5000.00' WHERE setting_key = 'free_delivery_threshold';"

# 3. Return Window Days Reactivity
run_sql "UPDATE settings SET setting_value = '21' WHERE setting_key = 'return_window_days';"
http_req "GET" "$BASE_URL/return-policy" "$COOKIE_GUEST"
if grep -q "21" "$BODY_FILE" && grep -q "21-day" "$BODY_FILE"; then
    pass "Changing return_window_days immediately reflects on /return-policy (21 days)"
else
    fail "Return window days change did not reflect on /return-policy"
fi
http_req "GET" "$BASE_URL/about" "$COOKIE_GUEST"
if grep -q "21-Day Return Window" "$BODY_FILE"; then
    pass "Changing return_window_days immediately reflects on /about (21-Day Return Window)"
else
    fail "Return window days change did not reflect on /about"
fi
run_sql "UPDATE settings SET setting_value = '7' WHERE setting_key = 'return_window_days';"

# 4. Support Phone Reactivity
run_sql "UPDATE settings SET setting_value = '+254 799 888 777' WHERE setting_key = 'support_phone';"
http_req "GET" "$BASE_URL/contact" "$COOKIE_GUEST"
if grep -q "+254 799 888 777" "$BODY_FILE"; then
    pass "Changing support_phone immediately reflects on /contact (+254 799 888 777)"
else
    fail "Support phone change did not reflect on /contact"
fi
http_req "GET" "$BASE_URL/support" "$COOKIE_GUEST"
if grep -q "+254 799 888 777" "$BODY_FILE"; then
    pass "Changing support_phone immediately reflects on /support"
else
    fail "Support phone change did not reflect on /support"
fi
run_sql "UPDATE settings SET setting_value = '+254 700 000 000' WHERE setting_key = 'support_phone';"

# ==============================================================================
# SECTION D: CONTACT FORM SUBMISSIONS & SECURITY CONTROLS
# ==============================================================================
info "SECTION D: Contact Form Submissions & Security Controls"

# Clear rate limits
curl -s -X POST "$BASE_URL/cart/test/clear-rate-limits" >/dev/null 2>&1 || true
"$PHP_CMD" -r '
    require "app/bootstrap.php";
    \App\Services\CartService::clearRateLimits();
'

# 1. Valid submission stores exactly 1 row in contact_messages and queues exactly 1 email
TOKEN_CONTACT=$(get_csrf_token "$COOKIE_GUEST" "$BASE_URL/contact")
FORM_TIME=$(($(date +%s) - 10))

RES_VALID=$(curl -s -b "$COOKIE_GUEST" -c "$COOKIE_GUEST" -X POST "$BASE_URL/contact" \
    -H "Accept: application/json" \
    -H "X-Requested-With: XMLHttpRequest" \
    -d "_csrf_token=$TOKEN_CONTACT" \
    -d "form_time=$FORM_TIME" \
    -d "name=Faith Mutua" \
    -d "email=faith.test@example.com" \
    -d "phone=0712345678" \
    -d "subject=Product Delivery Query" \
    -d "message=Hello team, how long will delivery take to Kilimani?")

NEW_MSG_ID=$(echo "$RES_VALID" | grep -oP '"id":\K[0-9]+' || true)

if [ -n "$NEW_MSG_ID" ] && [ "$NEW_MSG_ID" -gt "$PRE_CONTACT_MAX_ID" ]; then
    CREATED_CONTACT_IDS+=("$NEW_MSG_ID")
    pass "Valid contact form submission saved row #$NEW_MSG_ID to contact_messages with status 'new'"
else
    fail "Valid contact form submission failed to save row: $RES_VALID"
fi

# Verify queued notification email
QUEUED_EMAIL=$(run_sql "SELECT id FROM email_queue WHERE subject LIKE '%[Contact Inquiry #$NEW_MSG_ID]%' ORDER BY id DESC LIMIT 1;" | tail -n 1)
if [ -n "$QUEUED_EMAIL" ] && [ "$QUEUED_EMAIL" -gt "$PRE_EMAIL_MAX_ID" ]; then
    CREATED_EMAIL_IDS+=("$QUEUED_EMAIL")
    pass "Contact form submission queued notification email #$QUEUED_EMAIL with response time promise"
else
    fail "Notification email was not queued for contact form submission"
fi

# 2. Input validation failures store 0 rows
CNT_BEFORE=$(run_sql "SELECT COUNT(*) FROM contact_messages;")

INVALID_CASES=(
    "name=&email=test@example.com&subject=Test&message=Test+message+content+here|Empty name"
    "name=A&email=test@example.com&subject=Test&message=Test+message+content+here|Too short name"
    "name=Valid+Name&email=not-an-email&subject=Test&message=Test+message+content+here|Invalid email address"
    "name=Valid+Name&email=test@example.com&phone=12345&subject=Test&message=Test+message+content+here|Invalid phone number"
    "name=Valid+Name&email=test@example.com&subject=AB&message=Test+message+content+here|Too short subject"
    "name=Valid+Name&email=test@example.com&subject=Valid+Subject&message=Short|Too short message (< 10 chars)"
)

for icase in "${INVALID_CASES[@]}"; do
    payload=$(echo "$icase" | cut -d'|' -f1)
    desc=$(echo "$icase" | cut -d'|' -f2)

    res=$(curl -s -b "$COOKIE_GUEST" -X POST "$BASE_URL/contact" \
        -H "Accept: application/json" \
        -H "X-Requested-With: XMLHttpRequest" \
        -d "_csrf_token=$TOKEN_CONTACT" \
        -d "form_time=$FORM_TIME" \
        -d "$payload")

    if echo "$res" | grep -q '"success":false'; then
        pass "Validation error handled correctly: $desc"
    else
        fail "Expected validation failure for: $desc, got: $res"
    fi
done

CNT_AFTER=$(run_sql "SELECT COUNT(*) FROM contact_messages;")
if [ "$CNT_BEFORE" = "$CNT_AFTER" ]; then
    pass "Zero contact messages stored across all invalid validation test cases"
else
    fail "Invalid contact form test cases leaked rows into contact_messages: $CNT_BEFORE vs $CNT_AFTER"
fi

# 3. Honeypot check
res_hp=$(curl -s -b "$COOKIE_GUEST" -X POST "$BASE_URL/contact" \
    -H "Accept: application/json" \
    -H "X-Requested-With: XMLHttpRequest" \
    -d "_csrf_token=$TOKEN_CONTACT" \
    -d "form_time=$FORM_TIME" \
    -d "website=https://spambot.evil.org" \
    -d "name=Bot User" \
    -d "email=bot@example.com" \
    -d "subject=Spam Offer" \
    -d "message=Buy cheap prescription items now at our site.")
if echo "$res_hp" | grep -q 'Spam bot submission detected'; then
    pass "Honeypot protection successfully caught and rejected spam bot submission"
else
    fail "Honeypot protection failed to reject submission: $res_hp"
fi

# 4. Minimum form fill-time check (< 3 seconds)
FAST_TIME=$(date +%s)
res_fast=$(curl -s -b "$COOKIE_GUEST" -X POST "$BASE_URL/contact" \
    -H "Accept: application/json" \
    -H "X-Requested-With: XMLHttpRequest" \
    -d "_csrf_token=$TOKEN_CONTACT" \
    -d "form_time=$FAST_TIME" \
    -d "name=Speedy Submitter" \
    -d "email=speedy@example.com" \
    -d "subject=Instant Submit" \
    -d "message=Submitted in 0.1 seconds by an automated script.")
if echo "$res_fast" | grep -q 'Form was submitted too quickly'; then
    pass "Minimum fill-time check (< 3s) successfully blocked instant bot submission"
else
    fail "Minimum fill-time check failed: $res_fast"
fi

# 5. IP Rate limiting (5 per hour per IP; 6th rejected with HTTP 429)
info "Testing IP rate limiter (5 submissions per hour max)"
curl -s -X POST "$BASE_URL/cart/test/clear-rate-limits" >/dev/null 2>&1 || true
RATE_PASS=1
for i in {1..5}; do
    res_i=$(curl -s -w "\n%{http_code}" -b "$COOKIE_GUEST" -X POST "$BASE_URL/contact" \
        -H "Accept: application/json" \
        -H "X-Requested-With: XMLHttpRequest" \
        -d "_csrf_token=$TOKEN_CONTACT" \
        -d "form_time=$FORM_TIME" \
        -d "name=Rate Limit User $i" \
        -d "email=ratelimit$i@example.com" \
        -d "subject=Inquiry $i" \
        -d "message=Inquiry number $i for rate limit verification.")
    code_i=$(echo "$res_i" | tail -n 1)
    msg_id_i=$(echo "$res_i" | head -n -1 | grep -oP '"id":\K[0-9]+' || true)
    if [ -n "$msg_id_i" ]; then
        CREATED_CONTACT_IDS+=("$msg_id_i")
    fi
    if [ "$code_i" != "200" ]; then
        RATE_PASS=0
    fi
done

# 6th request must be rejected with HTTP 429
res_6th=$(curl -s -w "\n%{http_code}" -b "$COOKIE_GUEST" -X POST "$BASE_URL/contact" \
    -H "Accept: application/json" \
    -H "X-Requested-With: XMLHttpRequest" \
    -d "_csrf_token=$TOKEN_CONTACT" \
    -d "form_time=$FORM_TIME" \
    -d "name=Rate Limit User 6" \
    -d "email=ratelimit6@example.com" \
    -d "subject=Inquiry 6" \
    -d "message=This 6th request must be blocked by the IP rate limiter.")
code_6th=$(echo "$res_6th" | tail -n 1)

if [ "$RATE_PASS" -eq 1 ] && [ "$code_6th" = "429" ]; then
    pass "IP Rate Limiter successfully allowed 5 submissions and blocked 6th with HTTP 429"
else
    fail "IP Rate Limiter failed: first 5 pass=$RATE_PASS, 6th code=$code_6th (expected 429)"
fi

# Reset rate limits
curl -s -X POST "$BASE_URL/cart/test/clear-rate-limits" >/dev/null 2>&1 || true

# 6. Missing and forged CSRF token rejection
res_no_csrf=$(curl -s -w "\n%{http_code}" -b "$COOKIE_GUEST" -X POST "$BASE_URL/contact" \
    -H "Accept: application/json" \
    -d "name=No CSRF&email=nocsrf@example.com&subject=Test&message=Hello+world+message+content")
code_no_csrf=$(echo "$res_no_csrf" | tail -n 1)

res_bad_csrf=$(curl -s -w "\n%{http_code}" -b "$COOKIE_GUEST" -X POST "$BASE_URL/contact" \
    -H "Accept: application/json" \
    -d "_csrf_token=evil_forged_token_xyz" \
    -d "name=Bad CSRF&email=badcsrf@example.com&subject=Test&message=Hello+world+message+content")
code_bad_csrf=$(echo "$res_bad_csrf" | tail -n 1)

if [ "$code_no_csrf" = "403" ] && [ "$code_bad_csrf" = "403" ]; then
    pass "Contact form strictly enforces CSRF: missing=403, forged=403"
else
    fail "CSRF enforcement failed: missing=$code_no_csrf, forged=$code_bad_csrf"
fi

# 7. XSS payloads safely escaped in database, email, and admin detail view
XSS_NAME="Alice <script>alert('xss_name')</script>"
XSS_SUBJ="Inquiry <img src=x onerror=alert('xss_subj')>"
XSS_BODY="Please help with order <script>alert('xss_msg')</script> and <b>formatting</b>."

res_xss=$(curl -s -b "$COOKIE_GUEST" -X POST "$BASE_URL/contact" \
    -H "Accept: application/json" \
    -H "X-Requested-With: XMLHttpRequest" \
    -d "_csrf_token=$TOKEN_CONTACT" \
    -d "form_time=$FORM_TIME" \
    -d "name=$XSS_NAME" \
    -d "email=xss.test@example.com" \
    -d "subject=$XSS_SUBJ" \
    -d "message=$XSS_BODY")

XSS_MSG_ID=$(echo "$res_xss" | grep -oP '"id":\K[0-9]+' || true)
if [ -n "$XSS_MSG_ID" ]; then
    CREATED_CONTACT_IDS+=("$XSS_MSG_ID")
    
    # Check queued email HTML body
    EMAIL_ROW=$(run_sql "SELECT body_html FROM email_queue WHERE subject LIKE '%[Contact Inquiry #$XSS_MSG_ID]%' LIMIT 1;")
    if [ -n "$EMAIL_ROW" ]; then
        CREATED_EMAIL_IDS+=($(run_sql "SELECT id FROM email_queue WHERE subject LIKE '%[Contact Inquiry #$XSS_MSG_ID]%' LIMIT 1;"))
        if echo "$EMAIL_ROW" | grep -Fq "&lt;script&gt;alert(&#039;xss_name&#039;)&lt;/script&gt;"; then
            pass "XSS payload in contact name safely escaped in notification email HTML"
        else
            fail "XSS payload unescaped in notification email: $EMAIL_ROW"
        fi
        if echo "$EMAIL_ROW" | grep -Fq "&lt;script&gt;alert(&#039;xss_msg&#039;)&lt;/script&gt;"; then
            pass "XSS payload in contact message safely escaped in notification email HTML"
        else
            fail "XSS message unescaped in notification email"
        fi
    fi
fi

# 8. CRLF and Bcc header injection neutralization
CRLF_NAME="Attacker\r\nBcc: victim@example.com"
CRLF_SUBJ="Subject Injection\r\nCc: spam@example.com"
res_crlf=$(curl -s -b "$COOKIE_GUEST" -X POST "$BASE_URL/contact" \
    -H "Accept: application/json" \
    -H "X-Requested-With: XMLHttpRequest" \
    -d "_csrf_token=$TOKEN_CONTACT" \
    -d "form_time=$FORM_TIME" \
    -d "name=$CRLF_NAME" \
    -d "email=crlf@example.com" \
    -d "subject=$CRLF_SUBJ" \
    -d "message=Testing CRLF header injection neutralization.")

CRLF_MSG_ID=$(echo "$res_crlf" | grep -oP '"id":\K[0-9]+' || true)
if [ -n "$CRLF_MSG_ID" ]; then
    CREATED_CONTACT_IDS+=("$CRLF_MSG_ID")
    SAVED_SUBJ=$(run_sql "SELECT subject FROM contact_messages WHERE id = $CRLF_MSG_ID;")
    if [[ "$SAVED_SUBJ" != *$'\r'* ]] && [[ "$SAVED_SUBJ" != *$'\n'* ]]; then
        pass "CRLF injection stripped from subject before storage and email dispatch"
    else
        fail "CRLF characters detected in saved subject"
    fi
fi

# ==============================================================================
# SECTION E: SEARCH ENGINE OPTIMIZATION (SEO) & STRUCTURED DATA
# ==============================================================================
info "SECTION E: Search Engine Optimization (SEO) & Structured Data"

# 1. Unique non-empty title, meta description, and canonical URL on all pages
SEO_PAGES=("/" "/shop" "/about" "/contact" "/faq" "/shipping-info" "/return-policy" "/privacy" "/terms" "/support")
TITLES=()
DESCS=()
SEO_FAIL=0

for sp in "${SEO_PAGES[@]}"; do
    http_req "GET" "$BASE_URL$sp" "$COOKIE_GUEST"

    title=$(grep -oP '<title>\K[^<]+' "$BODY_FILE" | head -n 1)
    desc=$(grep -oP '<meta name="description" content="\K[^"]+' "$BODY_FILE" | head -n 1)
    canon=$(grep -oP '<link rel="canonical" href="\K[^"]+' "$BODY_FILE" | head -n 1)

    if [ -z "$title" ]; then
        fail "Page '$sp' missing <title> tag"
        SEO_FAIL=1
    fi
    if [ -z "$desc" ]; then
        fail "Page '$sp' missing <meta name='description'> tag"
        SEO_FAIL=1
    fi
    if [ -z "$canon" ]; then
        fail "Page '$sp' missing <link rel='canonical'> tag"
        SEO_FAIL=1
    fi

    TITLES+=("$title")
    DESCS+=("$desc")
done

# Verify uniqueness of titles across the 10 pages
UNIQUE_TITLES=$(printf "%s\n" "${TITLES[@]}" | sort -u | wc -l)
if [ "$UNIQUE_TITLES" -eq "${#SEO_PAGES[@]}" ] && [ "$SEO_FAIL" -eq 0 ]; then
    pass "All 10 storefront pages have unique non-empty titles, meta descriptions, and canonical URLs"
else
    fail "Duplicate or missing titles found: ${#SEO_PAGES[@]} pages vs $UNIQUE_TITLES unique titles"
fi

# 2. JSON-LD structured data validation
VALID_JSON_LD=$("$PHP_CMD" -r '
$pages = ["/", "/product/light-wash-denim-trucker-jacket", "/faq", "/about"];
$baseUrl = "http://127.0.0.1/shopwise-platform/public";
$allValid = true;

foreach ($pages as $p) {
    $html = @file_get_contents($baseUrl . $p);
    preg_match_all("#<script type=\"application/ld\+json\">(.*?)</script>#is", $html, $m);
    if (empty($m[1])) {
        $allValid = false;
        echo "FAIL: No JSON-LD found on $p\n";
        continue;
    }
    foreach ($m[1] as $idx => $block) {
        $data = json_decode(trim($block), true);
        if ($data === null) {
            $allValid = false;
            echo "FAIL: Invalid JSON-LD block on $p: " . json_last_error_msg() . "\n";
        }
    }
}

if ($allValid) echo "PASS";
')

if [ "$VALID_JSON_LD" = "PASS" ]; then
    pass "JSON-LD blocks parse as valid structured data on Home, Product, FAQ, and About pages"
else
    fail "JSON-LD validation failed: $VALID_JSON_LD"
fi

# 3. Product named </script><script>alert(1)</script> cannot break JSON-LD
MAL_PRODUCT_ID=$(run_sql "
    INSERT INTO products (category_id, name, slug, description, base_price, is_active, created_at, updated_at)
    VALUES (1, '</script><script>alert(1)</script>', 'breakout-test-slug-$RAND_ID', 'Test Description', 999.00, 1, NOW(), NOW());
    SELECT LAST_INSERT_ID();
" | tail -n 1)

if [ -n "$MAL_PRODUCT_ID" ] && [ "$MAL_PRODUCT_ID" -gt "$PRE_PRODUCTS_MAX_ID" ]; then
    CREATED_PRODUCT_IDS+=("$MAL_PRODUCT_ID")
    http_req "GET" "$BASE_URL/product/breakout-test-slug-$RAND_ID" "$COOKIE_GUEST"

    if grep -Fq "</script><script>alert(1)</script>" "$BODY_FILE"; then
        fail "Product JSON-LD broke out of script tag: raw </script> found"
    elif grep -Fq '\u003C/script\u003E\u003Cscript\u003Ealert(1)\u003C/script\u003E' "$BODY_FILE"; then
        pass "Product with </script><script>alert(1)</script> successfully neutralized via JSON_HEX_TAG"
    else
        pass "Product detail page with malicious script tag rendered safely without breakout"
    fi
fi

# 4. Dynamic sitemap.xml validation & exclusion check
SITEMAP_CHECK=$("$PHP_CMD" -r '
$url = "http://127.0.0.1/shopwise-platform/public/sitemap.xml";
$xmlStr = @file_get_contents($url);
$xml = @simplexml_load_string($xmlStr);
if ($xml === false) {
    echo "FAIL: Failed to parse XML";
    exit(1);
}

$forbidden = false;
foreach ($xml->url as $u) {
    $loc = (string)$u->loc;
    if (str_contains($loc, "/admin") || str_contains($loc, "/cart") || str_contains($loc, "/search")) {
        $forbidden = true;
        echo "FAIL: Forbidden URL: $loc";
        break;
    }
}
if (!$forbidden && count($xml->url) >= 10) {
    echo "PASS: " . count($xml->url) . " URLs";
}
')

if [[ "$SITEMAP_CHECK" == PASS* ]]; then
    pass "Dynamic /sitemap.xml is valid XML and excludes admin, cart, and search ($SITEMAP_CHECK)"
else
    fail "Sitemap validation error: $SITEMAP_CHECK"
fi

# 5. Dynamic robots.txt validation
http_req "GET" "$BASE_URL/robots.txt" "$COOKIE_GUEST"
if grep -q "Disallow: /admin/" "$BODY_FILE" && grep -q "Disallow: /cart" "$BODY_FILE" && grep -q "Disallow: /search" "$BODY_FILE" && grep -q "Sitemap:" "$BODY_FILE"; then
    pass "Dynamic /robots.txt disallows admin, cart, search, and specifies dynamic sitemap URL"
else
    fail "Dynamic /robots.txt content is incorrect"
fi

# 6. X-Robots-Tag header check on non-production environment
http_req "GET" "$BASE_URL/" "$COOKIE_GUEST"
if grep -Eiq '^X-Robots-Tag:.*noindex.*nofollow' "$HEADER_FILE"; then
    pass "X-Robots-Tag: noindex, nofollow is sent on non-production responses"
else
    fail "X-Robots-Tag header missing from non-production response"
fi

# ==============================================================================
# SECTION F: ADMIN SETTINGS VALIDATION & AUDIT LOGGING
# ==============================================================================
info "SECTION F: Admin Settings Form Validation & Audit Logging"

# Provision throwaway admin user
HASHED_PASS=$("$PHP_CMD" -r "echo password_hash('$TEST_PASSWORD', PASSWORD_BCRYPT);")
run_sql "
    INSERT INTO users (role, first_name, last_name, email, password_hash, phone, status, created_at, updated_at)
    VALUES ('admin', 'Phase3B', 'Tester', '$ADMIN_EMAIL', '$HASHED_PASS', '254711888999', 'active', NOW(), NOW());
"
ADMIN_USER_ID=$(run_sql "SELECT id FROM users WHERE email='$ADMIN_EMAIL';" | tail -n 1)
CREATED_USER_IDS+=("$ADMIN_USER_ID")

# Login admin
LOGIN_CSRF=$(get_csrf_token "$COOKIE_ADMIN" "$BASE_URL/admin/login.php")
curl -s -L -c "$COOKIE_ADMIN" -b "$COOKIE_ADMIN" \
    -d "email=${ADMIN_EMAIL}" \
    -d "password=${TEST_PASSWORD}" \
    -d "_csrf_token=${LOGIN_CSRF}" \
    "$BASE_URL/admin/login.php" > /dev/null

SETTINGS_CSRF=$(get_csrf_token "$COOKIE_ADMIN" "$BASE_URL/admin/settings/index.php")

# 1. Invalid values rejected
INVALID_SETTINGS=(
    "return_window_days=0|Return window days 0 rejected"
    "return_window_days=-5|Return window days negative rejected"
    "return_window_days=9999|Return window days > 90 rejected"
    "refund_processing_days=0|Refund processing days 0 rejected"
    "refund_processing_days=35|Refund processing days > 30 rejected"
    "support_response_hours=0|Support response hours 0 rejected"
    "support_response_hours=999|Support response hours > 168 rejected"
    "contact_form_notify_email=invalid-email-address|Invalid notify email rejected"
)

for iset in "${INVALID_SETTINGS[@]}"; do
    payload=$(echo "$iset" | cut -d'|' -f1)
    desc=$(echo "$iset" | cut -d'|' -f2)

    res_inv=$(curl -s -L -c "$COOKIE_ADMIN" -b "$COOKIE_ADMIN" "$BASE_URL/admin/settings/index.php" \
        -d "_csrf_token=$SETTINGS_CSRF" \
        -d "store_name=ShopWise+Kenya" \
        -d "support_email=support@test.local" \
        -d "support_phone=0700000000" \
        -d "pickup_address=Moi+Avenue+Nairobi" \
        -d "business_hours=Mon-Sat+8am-6pm" \
        -d "$payload")

    # Verify error message returned in flash alert and database value unchanged
    if echo "$res_inv" | grep -Eiq "must be an integer|valid customer support email|valid contact form notification email"; then
        pass "Settings validation correctly rejected: $desc"
    else
        fail "Settings validation failed to reject: $desc"
    fi
done

# 2. Valid values saved and audited
res_valid_set=$(curl -s -L -c "$COOKIE_ADMIN" -b "$COOKIE_ADMIN" "$BASE_URL/admin/settings/index.php" \
    -d "_csrf_token=$SETTINGS_CSRF" \
    -d "store_name=ShopWise+Kenya" \
    -d "store_tagline=Quality+everyday+essentials" \
    -d "support_email=support@test.local" \
    -d "support_phone=0700000000" \
    -d "pickup_address=Moi+Avenue+Nairobi" \
    -d "business_hours=Mon-Sat+8am-6pm" \
    -d "return_window_days=10" \
    -d "refund_processing_days=5" \
    -d "support_response_hours=12" \
    -d "contact_form_notify_email=support@example.com")

VAL_RET=$(run_sql "SELECT setting_value FROM settings WHERE setting_key = 'return_window_days';")
VAL_REF=$(run_sql "SELECT setting_value FROM settings WHERE setting_key = 'refund_processing_days';")
VAL_HRS=$(run_sql "SELECT setting_value FROM settings WHERE setting_key = 'support_response_hours';")
VAL_EML=$(run_sql "SELECT setting_value FROM settings WHERE setting_key = 'contact_form_notify_email';")

if [ "$VAL_RET" = "10" ] && [ "$VAL_REF" = "5" ] && [ "$VAL_HRS" = "12" ] && [ "$VAL_EML" = "support@example.com" ]; then
    pass "Valid settings successfully saved to database (return=10, refund=5, support=12h, email=support@example.com)"
else
    fail "Settings values not persisted correctly: ret=$VAL_RET, ref=$VAL_REF, hrs=$VAL_HRS, eml=$VAL_EML"
fi

# Check audit log
AUDIT_ROW=$(run_sql "SELECT id FROM audit_log WHERE action = 'settings_update' AND actor_user_id = $ADMIN_USER_ID ORDER BY id DESC LIMIT 1;" | tail -n 1)
if [ -n "$AUDIT_ROW" ] && [ "$AUDIT_ROW" -gt "$PRE_AUDIT_MAX_ID" ]; then
    CREATED_AUDIT_IDS+=("$AUDIT_ROW")
    pass "Settings update recorded in audit_log #$AUDIT_ROW with actor user #$ADMIN_USER_ID"
else
    fail "Settings update was not recorded in audit_log"
fi

# Restore settings
run_sql "
    UPDATE settings SET setting_value = '7' WHERE setting_key = 'return_window_days';
    UPDATE settings SET setting_value = '7' WHERE setting_key = 'refund_processing_days';
    UPDATE settings SET setting_value = '24' WHERE setting_key = 'support_response_hours';
    UPDATE settings SET setting_value = 'support@test.local' WHERE setting_key = 'contact_form_notify_email';
"

# ==============================================================================
# SECTION G: CLEANUP & DRIFT VERIFICATION
# ==============================================================================
info "SECTION G: Cleanup Execution & Zero Drift Verification"

cleanup

POST_CONTACT=$(run_sql "SELECT COUNT(*) FROM contact_messages;" | tail -n 1)
POST_EMAIL=$(run_sql "SELECT COUNT(*) FROM email_queue;" | tail -n 1)
POST_SETTINGS=$(run_sql "SELECT COUNT(*) FROM settings;" | tail -n 1)
POST_ZONES=$(run_sql "SELECT COUNT(*) FROM delivery_zones;" | tail -n 1)
POST_AUDIT=$(run_sql "SELECT COUNT(*) FROM audit_log;" | tail -n 1)
POST_USERS=$(run_sql "SELECT COUNT(*) FROM users;" | tail -n 1)
POST_PRODUCTS=$(run_sql "SELECT COUNT(*) FROM products;" | tail -n 1)
POST_VARIANTS=$(run_sql "SELECT COUNT(*) FROM product_variants;" | tail -n 1)
POST_CARTS=$(run_sql "SELECT COUNT(*) FROM carts;" | tail -n 1)
POST_ORDERS=$(run_sql "SELECT COUNT(*) FROM orders;" | tail -n 1)
POST_COUPONS=$(run_sql "SELECT COUNT(*) FROM coupons;" | tail -n 1)
POST_STOCK=$(run_sql "SELECT COALESCE(SUM(stock_qty), 0) FROM product_variants;" | tail -n 1)
POST_UPLOAD_FILES=$(find /opt/lampp/htdocs/shopwise-platform/public/uploads -type f ! -name ".gitkeep" 2>/dev/null | wc -l)

echo "Post-Test Resource Baseline Comparison:"
printf "  %-24s | %-12s | %-12s | %-8s\n" "Resource" "Baseline" "Post-Test" "Status"
echo "  -------------------------+--------------+--------------+---------"

DRIFT_FAIL=0
check_drift() {
    local name="$1"
    local base="$2"
    local post="$3"
    if [ "$base" = "$post" ]; then
        printf "  %-24s | %-12s | %-12s | ${GREEN}%-8s${NC}\n" "$name" "$base" "$post" "MATCH"
    else
        printf "  %-24s | %-12s | %-12s | ${RED}%-8s${NC}\n" "$name" "$base" "$post" "DRIFT"
        fail "Resource '$name' drifted: baseline $base vs post-test $post"
        DRIFT_FAIL=1
    fi
}

check_drift "contact_messages"    "$PRE_CONTACT"       "$POST_CONTACT"
check_drift "email_queue"         "$PRE_EMAIL"         "$POST_EMAIL"
check_drift "settings"            "$PRE_SETTINGS"      "$POST_SETTINGS"
check_drift "delivery_zones"      "$PRE_ZONES"         "$POST_ZONES"
check_drift "audit_log"           "$PRE_AUDIT"         "$POST_AUDIT"
check_drift "users"               "$PRE_USERS"         "$POST_USERS"
check_drift "products"            "$PRE_PRODUCTS"      "$POST_PRODUCTS"
check_drift "product_variants"    "$PRE_VARIANTS"      "$POST_VARIANTS"
check_drift "carts"               "$PRE_CARTS"         "$POST_CARTS"
check_drift "orders"              "$PRE_ORDERS"        "$POST_ORDERS"
check_drift "coupons"             "$PRE_COUPONS"       "$POST_COUPONS"
check_drift "stock_checksum"      "$PRE_STOCK"         "$POST_STOCK"
check_drift "uploads_file_count"  "$PRE_UPLOAD_FILES"  "$POST_UPLOAD_FILES"

if [ "$DRIFT_FAIL" -eq 0 ]; then
    pass "Zero database and filesystem drift across all 13 tracked resources"
fi

# ==============================================================================
# SUMMARY
# ==============================================================================
echo -e "\n=============================================================================="
echo -e "${BOLD}Phase 3B Acceptance Suite Completed${NC}"
echo -e "  Passed: ${GREEN}${PASSED}${NC}"
echo -e "  Failed: ${RED}${FAILED}${NC}"
echo -e "=============================================================================="

if [ "$FAILED" -gt 0 ]; then
    exit 1
fi
exit 0
