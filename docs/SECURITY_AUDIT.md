# ShopWise Platform - Application Security Assessment & Hardening Report

## 1. Executive Summary

A comprehensive security review was performed on the ShopWise Kenya e-commerce platform covering authentication, authorization, session management, injection vulnerabilities, CSRF protection, file upload security, and financial transaction safeguards.

- **Target Systems:** Customer storefront, administrative portal, REST endpoints, and Safaricom Daraja M-Pesa payment integration.
- **Vulnerabilities Identified & Hardened:** 4 primary vectors remediated (admin IP brute-force rate limiting, password reset weakness screening, proxy SSL scheme handling, and upload script execution extension coverage).
- **Residual Risk:** Low (Production configuration requires genuine Truehost cPanel HTTPS certificates, isolated SMTP credentials, and production M-Pesa Daraja credentials).

---

## 2. Authentication & Authorization Assessment

### 2.1 Customer Authentication
- **Password Storage:** Standard `PASSWORD_DEFAULT` (`bcrypt` with cost factor 10).
- **Password Strength:** Enforces minimum 8 characters and rejects passwords found in `app/data/common_passwords.txt`.
- **Brute-Force Protection:** Dual rate limit (5 failed attempts per 15 minutes per email+IP; 20 failed attempts per IP).
- **Timing Attack Mitigation:** Constant-time password hashing simulation on non-existent accounts.
- **Session Security:** Cookies configured with `HttpOnly`, `SameSite=Lax`, strict mode enabled, and dynamic `Secure` flag. Session regeneration on login and logout.

### 2.2 Administrative Authentication & RBAC
- **Strict Role Separation:** Admin vs Staff role permissions enforced via `Permissions::require(...)`.
- **Session Integrity:** `app/views/admin/guard.php` inspects the database on every protected request to assert that:
  - Account status is `active`.
  - Role has not been demoted/tampered with.
  - `password_changed_at` matches the established session (immediate invalidation upon password reset).
- **Hardening Applied:** Added IP-level rate limiting (20 attempts / 15 mins) and dummy hash timing mitigation to `AdminAuthService::login()`.

---

## 3. Web Application Vulnerability Analysis

### 3.1 SQL Injection (SQLi)
- **Methodology:** AST token scanning and pattern matching across all database statements.
- **Result:** 100% of SQL statements across all services, cron scripts, and admin controllers use parameterized PDO prepared statements (`:param` or `?`).
- **Dynamic Sort/Table Guards:** Dynamic table names and sort columns use strict allowlists (`in_array($val, $allowed, true)`).
- **Verification:** SQL injection test suite in `acceptance_2b.sh` probed with `' OR 1=1 --`, `'; DROP TABLE products; --`, `' UNION SELECT NULL--` with zero error leaks and zero data modification.

### 3.2 Cross-Site Scripting (XSS)
- **Storefront Views:** All dynamic database values are escaped via `e(?string $value): string` (`htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`).
- **Rich Text Descriptions:** Product descriptions pass through `HtmlSanitizer::clean()` using a strict tag allowlist and attribute stripping.
- **Verification:** Acceptance suites verified that `<script>` tags, event handlers (`onerror`), and malicious payloads are properly escaped in storefront pages, invoices, packing slips, emails, and admin views.

### 3.3 Cross-Site Request Forgery (CSRF)
- **Mechanism:** Cryptographic 32-byte session tokens (`random_bytes(32)`), verified via constant-time `hash_equals()`.
- **Enforcement:** All browser POST endpoints (`/cart/*`, `/checkout/*`, `/register`, `/login`, `/contact`, `/account/*`, `/admin/*`) require valid CSRF tokens.
- **Verification:** Missing and forged tokens return HTTP 403 Forbidden or HTTP 400 Bad Request across storefront and admin suites.

### 3.4 File Upload Security
- **Strict Allowlist:** Only `jpg`, `jpeg`, `png`, `webp` extensions permitted.
- **Deep Inspection:** Validates genuine MIME type via `finfo_file()` and checks image dimensions via `getimagesize()`.
- **Active Neutralization:** Images are decoded and re-encoded using PHP GD (`imagecreatefrom*` -> `imagewebp`/`imagejpeg`), completely stripping metadata, EXIF comments, and embedded PHP tags.
- **Filename Randomization:** Saved as `prod_{hex32}.ext` preventing path traversal and name guessing.
- **Server Execution Prevention:** `public/uploads/.htaccess` blocks script execution and sets `Options -ExecCGI -Indexes`. Extended to cover `.pht`, `.phtm`, `.php84`, `.php85`, `.shtml`.

---

## 4. M-Pesa Daraja Payment Security

1. **Callback Secret URL Masking:** Callbacks are routed to `/payments/callback/{secret}` where `{secret}` is a 64-character unguessable secret verified via `hash_equals()`. Invalid secrets return HTTP 404 without acknowledging endpoint presence.
2. **Payload Size Guard:** Enforces 64 KB maximum payload limit to prevent resource exhaustion attacks.
3. **STK Push Query Verification:** Callbacks are independently verified against Safaricom's STK Query endpoint before marking payments successful.
4. **Receipt Uniqueness & Format:** Validates `^[A-Z0-9]{10}$` and rejects duplicate receipt numbers across the database.
5. **Exact Amount Assertion:** Order total is compared against callback metadata amount. Discrepancies flag the order with `attention_reason` and alert administrators without marking the order as paid.
6. **Zero Credential Leaks:** Consumer secrets, passkeys, and tokens are scrubbed from logs, exceptions, and database records.

---

## 5. Defense-in-Depth Directory Protections

Dedicated `.htaccess` files were placed in all non-public directories:
- `app/.htaccess` -> `Require all denied`
- `database/.htaccess` -> `Require all denied`
- `cron/.htaccess` -> `Require all denied`
- `scripts/.htaccess` -> `Require all denied`

Regardless of virtual host document root configuration, direct HTTP requests to source files and databases are barred.
