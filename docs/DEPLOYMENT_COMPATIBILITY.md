# ShopWise Platform - Truehost cPanel Deployment & Compatibility Guide

## 1. Hosting Architecture Overview

The ShopWise Kenya platform is architected for deployment on **Truehost CloudLinux cPanel Shared Hosting** under a custom domain or subdomain (e.g. `shop.kennedycheroben.co.ke`).

- **Architecture:** Zero-dependency PHP application. Does NOT require Node.js production servers, Redis, Elasticsearch, Docker, or system daemon processes.
- **Web Server:** Apache 2.4+ with `mod_rewrite`, `mod_headers`, `mod_expires`, `mod_authz_core`.
- **PHP Handler:** MultiPHP FastCGI / PHP-FPM (`ea-php84` recommended, `ea-php82`+ supported).
- **Database Server:** MariaDB 10.4+ or MySQL 8.0+ via cPanel MySQL Databases.

---

## 2. Directory Layout & Document Root Configuration

### Option A: Subdomain Dedicated Document Root (Recommended)
When creating the subdomain in cPanel:
1. Set **Document Root** to: `public_html/shopwise-platform/public` (or `/home/<cpanel_user>/shopwise-platform/public`).
2. This ensures `app/`, `database/`, `cron/`, `scripts/`, and `vendor/` reside completely outside the public web root.

### Option B: Root Document Root with .htaccess Hardening
If the subdomain points to `public_html/shopwise-platform`:
1. The root `.htaccess` automatically rewrites public storefront and admin requests into `public/`.
2. Dedicated `.htaccess` files inside `app/`, `database/`, `cron/`, `scripts/` unconditionally enforce `Require all denied` (HTTP 403 Forbidden).

---

## 3. PHP Configuration Requirements

Configure via cPanel **MultiPHP INI Editor**:

| Directive | Recommended Value | Reason |
| :--- | :--- | :--- |
| `max_execution_time` | `60` | Prevents timeouts on multi-image uploads and batch email dispatches. |
| `memory_limit` | `256M` | Accommodates GD high-resolution image decoding (up to 6000x6000px). |
| `upload_max_filesize` | `10M` | Allows uploading up to 5MB original product images. |
| `post_max_size` | `12M` | Handles multi-file uploads (`images[]`). |
| `date.timezone` | `Africa/Nairobi` | Aligns with Kenyan business operations and Safaricom Daraja timestamps. |
| `session.cookie_httponly` | `On` | Mitigates session theft via cross-site scripting. |
| `session.cookie_secure` | `On` | Ensures cookies are sent exclusively over HTTPS. |
| `session.use_strict_mode` | `On` | Prevents session fixation. |

### Required PHP Extensions
Enable via cPanel **Select PHP Version / Extensions**:
- `pdo_mysql`
- `curl`
- `openssl`
- `fileinfo`
- `gd` (with WebP support)
- `mbstring`
- `json`
- `xml` / `dom`

---

## 4. File and Directory Permissions

Truehost cPanel executes scripts using suPHP or FastCGI/suexec.
> [!CAUTION]
> Never set permissions to `0777` on Truehost cPanel. Any file or folder with `0777` permissions triggers an immediate HTTP 500 Internal Server Error.

Standard permissions:
- **Directories:** `0755` (`find . -type d -exec chmod 0755 {} +`)
- **Files:** `0644` (`find . -type f -exec chmod 0644 {} +`)
- **Protected Storage (Tokens / Locks):** `0750` for directories, `0640` for files.

---

## 5. Scheduled Tasks (cPanel Cron Jobs)

Add the following cron entries via cPanel **Cron Jobs**:

```bash
# 1. Order Expiry Worker (Every 2 minutes)
# Scans pending orders past expires_at, verifies Daraja STK queries, and restores stock atomically
*/2 * * * * /usr/local/bin/php /home/<user>/shopwise-platform/cron/expire_orders.php >/dev/null 2>&1

# 2. Outbound Transactional Email Queue (Every 2 minutes)
# Dispatches queued confirmation and notification emails via authenticated SMTP
*/2 * * * * /usr/local/bin/php /home/<user>/shopwise-platform/cron/send_queued_emails.php >/dev/null 2>&1

# 3. Abandoned Cart & Rate Limit Cleanup (Daily at 3:00 AM EAT)
# Purges empty carts (>24h), abandoned carts (>30d), and expired rate limit files
0 3 * * * /usr/local/bin/php /home/<user>/shopwise-platform/cron/purge_carts.php >/dev/null 2>&1
```

---

## 6. Production Credentials & Configuration

Copy `app/config/config.example.php` to `app/config/config.local.php` on the server and populate:
- `app.environment` = `'production'`
- `app.debug` = `false`
- `app.base_url` = `'https://shop.kennedycheroben.co.ke'`
- `db.database` = `<cpanel_user>_shopwise`
- `db.username` = `<cpanel_user>_shopwise_user`
- `db.password` = `<strong_random_password>`
- `mpesa.env` = `'production'`
- `mpesa.shortcode` = `<Paybill_or_Till_Number>`
- `mpesa.passkey` = `<Live_Daraja_Passkey>`
- `mpesa.callback_base_url` = `'https://shop.kennedycheroben.co.ke'`
- `mpesa.callback_secret` = `<random_64_char_hex_secret>`
- `mail.driver` = `'smtp'`
- `mail.smtp.host` = `'mail.kennedycheroben.co.ke'`
- `mail.smtp.username` = `'support@kennedycheroben.co.ke'`
- `mail.smtp.password` = `<email_account_password>`
- `mail.smtp.port` = `465` (SSL) or `587` (TLS)
