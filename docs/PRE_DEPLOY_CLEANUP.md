# Pre-Deployment Cleanup Checklist

This checklist documents sensitive files, test endpoints, demo seeds, and configurations that must be purged, restricted, or reconfigured before launching the ShopWise platform into production.

---

## 1. Never Deploy to Production (Strict Release Blockers)

The following files, directories, and endpoints are strictly for local testing and development. They must NEVER be present, accessible, or active on any production server:

- **`app/config/config.testing.php`**: Test-only configuration overriding database to `shopwise_test`.
- **`public/uploads_test/`**: Disposable test uploads directory.
- **`app/storage/rate_limits_test/`**: Disposable rate limiting state directory for test runs.
- **`database/demo_orders*.sql`**: Demo and dummy order generation scripts (`demo_orders.sql`, `demo_orders_remove.sql`).
- **`scripts/` folder**: All shell scripts and CLI test runners (`run_all_tests.sh`, `reset_test_db.sh`, `acceptance_*.sh`, `prove_csrf.sh`, `check_stock_parity.py`, etc.).
- **`/cart/test/clear-rate-limits`**: Test-only endpoint for resetting rate limit counters. Blocked in production via `SHOPWISE_TESTING_MODE` guard.
- **`public/admin/api/admin-only.php`**: Test-only diagnostic endpoint.

---

## 2. Test-Only Endpoints & Debug Scripts
- [ ] **Delete `public/admin/api/admin-only.php`**: Test-only endpoint used during Phase 2A authorization checks.
- [ ] **Remove test acceptance scripts**: Delete or exclude `/scripts/` folder from deployment bundles.

---

## 3. Database Seeds & Demo Data
- [ ] **Remove SQL seed scripts**: Delete `database/demo_orders*.sql` and any temporary migration or seed dumps from server storage so sensitive structure or dummy order data is not publicly accessible.
- [ ] **Purge throwaway users**: Ensure any test users created during automated testing (`*@test.local`) are deleted from the `users` table.

---

## 4. Environment & Configuration
- [ ] **Configure `config.local.php` for production**:
  - `APP_ENV` set to `'production'`.
  - `APP_DEBUG` set to `false` (suppress PHP stack traces and verbose error messages).
  - Secure database credentials with least-privilege DB user.
  - Set `SESSION_SECURE` to `true` (HTTPS only).
  - Configure production mailer / SMS gateway credentials.

---

## 5. File Permissions & Web Server Hardening
- [ ] **Local XAMPP Environment (One-time Setup)**:
  - Apache runs under user/group `daemon:daemon`. If upload or log directories were created by a desktop user, grant ownership:
    ```bash
    sudo chown -R daemon:daemon /opt/lampp/htdocs/shopwise-platform/public/uploads /opt/lampp/htdocs/shopwise-platform/app/storage
    ```
- [ ] **cPanel Shared Hosting (Production)**:
  - On cPanel with suPHP/suExec, directory permissions **must strictly be `0755`** and files **`0644`**.
  - **CRITICAL**: Never set folders to `0777` on cPanel. The suPHP security module immediately blocks execution and throws `500 Internal Server Error` if a folder has world-write permissions (`0777`).
  - Directories created by `ImageUploadService` use `0755` and saved files use `0644`.
- [ ] **Upload directory security**:
  - Verify `public/uploads/.htaccess` prevents direct PHP execution (`php_flag engine off` / handler overrides).
  - Ensure `app/`, `database/`, and `storage/` directories are outside the public document root or strictly forbidden via Apache / Nginx directives.
- [ ] **File permissions on configuration**:
  - Set `config/config.local.php` permissions to `0600` or `0640` readable only by the web server user.

---

## 6. Security & Maintenance Verification
- [ ] Run full security scan on headers (Content-Security-Policy, X-Frame-Options, Strict-Transport-Security).
- [ ] Verify CSRF protection is active across all endpoints.
- [ ] Confirm log rotation is configured for `storage/logs/`.

---

## 7. Storefront Integration & Customer Links
- [ ] **Connect `MailerService::getTrackingUrl(string $orderNumber)`**: Once the customer-facing storefront order tracking page is launched (Phase 3+), update this helper method in `app/src/Services/MailerService.php` to return the real customer tracking URL (e.g. `https://shopwise.co.ke/track-order.html?order=...`). The current implementation returns `null` to strictly prevent broken links to unbuilt storefront pages.

---

## 8. Storefront Hidden Controls & Deferred Links

The following template elements and navigation links have been intentionally hidden or disabled during storefront development to maintain 100% functional integrity (no dead links, no placeholder behaviors, and no client-side spoofing). They must be restored in their respective phases:

- [x] **Phase 3B - Contact Form, Content Pages & Footer Links** (COMPLETED):
  - `About Us` (`about.html` -> `/about`): Active in header navigation, footer company column, and home hero/about links.
  - `Contact Us` (`contact.html` -> `/contact`): Active in header utility bar, navigation strip, and footer customer service links with live contact form.
  - `FAQ` (`faq.html` -> `/faq`): Active in footer customer care links.
  - `Shipping Info` (`shiping-info.html` -> `/shipping-info`): Active in footer links and product detail shipping accordion.
  - `Returns & Exchanges / Return Policy` (`return-policy.html` -> `/return-policy`): Active in footer links and product guarantee bar.
  - `Privacy Policy` (`privacy.html` -> `/privacy`): Active in footer legal links.
  - `Terms of Service` (`tos.html` -> `/terms`): Active in footer legal links.
  - `Support Center` (`support.html` -> `/support`): Active in header utility bar and error pages.
  - `Footer Links & Store Details`: Active store details from settings and 200-returning content links.

- [x] **Phase 4 - Customer Accounts, Wishlist & Review Submission** (COMPLETED):
  - `Customer Login / Register Flyout`: Account, login, and register controls active in user profile icon flyout (`login.html`, `register.html`, `account.html`) in header action group.
  - `Order History / Order Status`: Active links in user flyout, account area, and footer.
  - `Wishlist Controls`: Wishlist hearts active on product cards, category tiles, product detail page, and header wishlist counter.
  - `Review Submission Form`: Active "Write a Customer Review" form on product details feedback tab for verified buyers.

- [ ] **Phase 5 - Checkout Activation & Order Processing**:
  - `Proceed to Checkout Button`: Checkout activation returns. Currently rendered disabled with real label `"Checkout opens soon"` in header mini-cart and `/cart` page; enable and link to `/checkout` once Phase 5 M-Pesa STK push and order placement flow are implemented.
  - `Instant Purchase Button ("Purchase Instantly")`: Re-enable on product detail page once one-click checkout is available.
  - `Order Confirmation & Tracking`: Connect order lookup once checkout creates completed orders.


- [ ] **Phase 6 - Polish & Enhancements**:
  - `Newsletter Subscription Form`: Re-enable in footer once newsletter integration with double opt-in verification is implemented.
  - `Product Comparison ("Compare")`: Product tile action button.
  - `Quick View Modal`: Product tile quick view modal popup.
  - `Social Media Icons`: Footer links to Facebook, Instagram, Twitter/X, TikTok, Pinterest, YouTube (omitted until verified store social profiles exist).
  - `Mobile App Store Buttons`: App Store and Google Play download buttons (omitted until mobile apps are released).

---

## 9. Go-Live Checklist

Before public launch, verify and check off all items:
- [ ] **Set `app.environment = 'production'`**: Set `'environment' => 'production'` in `config.local.php`. In all non-production modes, the application automatically sends `X-Robots-Tag: noindex, nofollow` and `<meta name="robots" content="noindex, nofollow">` on every page to prevent staging indexation. Production mode enables search engines to index the store.
- [ ] **Replace Placeholder Settings**: Navigate to Admin > Settings and enter real business contact details:
  - Support phone number
  - Support email address (`support@shopwise.co.ke`)
  - Physical pickup / store address in Nairobi CBD
- [ ] **Remove Test Configuration & Test Routes**:
  - Delete `app/config/config.testing.php`
  - Remove test-only routes (such as `/cart/test/clear-rate-limits` and `public/admin/api/admin-only.php`)
  - Remove disposable test folders: `public/uploads_test/` and `app/storage/rate_limits_test/`
  - Exclude or delete the `scripts/` directory
- [ ] **Enable HTTPS & Enforce SSL**: Ensure SSL/TLS certificate is active and HTTP-to-HTTPS redirection is enforced.
- [ ] **Set Cookie Secure Flags**: Ensure `'session' => ['secure' => true]` is configured in `config.local.php` so all cookies have the `Secure` flag.
- [ ] **Configure SMTP Mailer**: Set valid SMTP server host, port, username, password, and encryption in `config.local.php` to ensure delivery of verification, password reset, and order emails.
- [ ] **Configure Scheduled Cron Jobs**: Set cPanel cron jobs for `send_queued_emails.php` (every 2 min), `purge_carts.php` (daily at midnight), and `expire_orders.php` (every 5 min in Phase 5).


