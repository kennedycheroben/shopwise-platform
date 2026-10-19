# Pre-Deployment Cleanup Checklist

This checklist documents sensitive files, test endpoints, demo seeds, and configurations that must be purged, restricted, or reconfigured before launching the ShopWise platform into production.

---

## 1. Test-Only Endpoints & Debug Scripts
- [ ] **Delete `public/admin/api/admin-only.php`**: Test-only endpoint used during Phase 2A authorization checks. Must never exist in production.
- [ ] **Remove test acceptance scripts**: Delete or restrict execution permissions on `/scripts/acceptance_*.sh` and any temporary cURL / database diagnostic scripts.

---

## 2. Database Seeds & Demo Data
- [ ] **Remove SQL seed scripts**: Delete `database/demo_orders*.sql` and any temporary migration or seed dumps from server storage so sensitive structure or dummy order data is not publicly accessible.
- [ ] **Purge throwaway users**: Ensure any test users created during automated testing (`*@test.local`) are deleted from the `users` table.

---

## 3. Environment & Configuration
- [ ] **Configure `config.local.php` for production**:
  - `APP_ENV` set to `'production'`.
  - `APP_DEBUG` set to `false` (suppress PHP stack traces and verbose error messages).
  - Secure database credentials with least-privilege DB user.
  - Set `SESSION_SECURE` to `true` (HTTPS only).
  - Configure production mailer / SMS gateway credentials.

---

## 4. File Permissions & Web Server Hardening
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

## 5. Security & Maintenance Verification
- [ ] Run full security scan on headers (Content-Security-Policy, X-Frame-Options, Strict-Transport-Security).
- [ ] Verify CSRF protection is active across all endpoints.
- [ ] Confirm log rotation is configured for `storage/logs/`.

---

## 6. Storefront Integration & Customer Links
- [ ] **Connect `MailerService::getTrackingUrl(string $orderNumber)`**: Once the customer-facing storefront order tracking page is launched (Phase 3+), update this helper method in `app/src/Services/MailerService.php` to return the real customer tracking URL (e.g. `https://shopwise.co.ke/track-order.html?order=...`). The current implementation returns `null` to strictly prevent broken links to unbuilt storefront pages.

