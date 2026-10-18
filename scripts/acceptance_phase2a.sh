#!/usr/bin/env bash
# Run locally from the project root after Apache and MariaDB are running.
set -u
base='http://localhost/shopwise-platform/public'; mysql='/opt/lampp/bin/mysql'; db='shopwise_db'; failures=0
pass(){ echo "PASS: $1"; }; fail(){ echo "FAIL: $1"; failures=$((failures+1)); }
code(){ curl -sS -o /dev/null -w '%{http_code}' "$1"; }
expect_redirect(){ local got; got=$(code "$base$1"); [[ "$got" =~ ^30[12]$ ]] && pass "$1 => $got" || fail "$1 => $got (expected redirect)"; }
expect_block(){ local got; got=$(code "$base$1"); [[ "$got" == 403 || "$got" == 404 ]] && pass "$1 => $got" || fail "$1 => $got (expected 403/404)"; }
$mysql -u root "$db" -e 'SELECT 1' >/dev/null && pass 'MariaDB connection' || { fail 'MariaDB connection'; exit 1; }
expect_redirect '/admin/'; expect_redirect '/admin/index.php'; expect_redirect '/admin/profile.php'
for path in '/app/config/config.php' '/database/seed.sql' '/scripts/create_admin.php' '/cron/' '/vendor/autoload.php' '/_template_reference/'; do expect_block "$path"; done
css=$(code "$base/admin/assets/css/main.css"); js=$(code "$base/admin/assets/js/main.js"); [[ "$css" == 200 && "$js" == 200 ]] && pass "Admin assets CSS=$css JS=$js" || fail "Admin assets CSS=$css JS=$js"
echo 'Dashboard SQL: paid sales today/7/30, grouped statuses, grouped paid revenue by date, paid order-item top five, stock threshold variants, recent orders, monthly customers, active products are in app/src/Services/DashboardService.php.'
echo 'Manual auth acceptance: create accounts with scripts/create_user.php, then use browser DevTools/curl cookie jar to submit CSRF-protected forms. The script does not brute-force passwords or extract reset tokens.'
echo 'Demo data test:'; $mysql -u root "$db" < database/demo_orders_remove.sql; before=$($mysql -N -u root "$db" -e "SELECT COUNT(*) FROM orders WHERE order_number LIKE 'DEMO2A-%'"); $mysql -u root "$db" < database/demo_orders.sql; after=$($mysql -N -u root "$db" -e "SELECT COUNT(*) FROM orders WHERE order_number LIKE 'DEMO2A-%'"); [[ "$before" == 0 && "$after" == 12 ]] && pass "Demo load created $after orders" || fail "Demo order counts before=$before after=$after"; $mysql -u root "$db" < database/demo_orders_remove.sql; removed=$($mysql -N -u root "$db" -e "SELECT COUNT(*) FROM orders WHERE order_number LIKE 'DEMO2A-%'"); [[ "$removed" == 0 ]] && pass 'Demo removal' || fail "Demo removal left $removed rows"
exit "$failures"
