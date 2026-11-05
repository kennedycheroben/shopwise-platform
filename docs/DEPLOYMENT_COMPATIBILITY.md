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

## 6. Production Credentials & Environment Configuration (`.env`)

All sensitive production credentials and environment variables are centralized in a single `.env` file.
`.env` is the single source of truth for secrets, passwords, tokens, API keys, and environment settings.

### Recommended Production `.env` Location
To guarantee complete isolation from public web traffic, store the production `.env` file outside of `public_html`:

1. **Option 1 (Recommended - Dedicated Private Directory):**
   `/home/<cpanel_user>/shopwise-config/.env`
   Set environment variable in `.htaccess` or cPanel:
   `SetEnv SHOPWISE_ENV_PATH /home/<cpanel_user>/shopwise-config/.env`

2. **Option 2 (User Home Directory):**
   `/home/<cpanel_user>/.env`
   Automatically discovered by `App\Config\Env` if project root is a subfolder.

3. **Option 3 (Project Root Outside public_html):**
   `/home/<cpanel_user>/shopwise-platform/.env`
   When Apache document root points to `/home/<cpanel_user>/shopwise-platform/public`.

### Required File Permissions
- Set `.env` permissions strictly to `0600` (Owner Read/Write only):
  ```bash
  chmod 600 /home/<cpanel_user>/shopwise-config/.env
  ```
- Ensure ownership belongs to your cPanel user (`chown <cpanel_user>:<cpanel_user> .env`).

### Discovery Precedence
`App\Config\Env` resolves configuration using the following order:
1. `SHOPWISE_ENV_PATH` environment variable (if defined via server/cPanel `SetEnv`).
2. Project root `.env` (`/path/to/shopwise-platform/.env`).
3. Parent dedicated config folder: `../shopwise-config/.env`.
4. Parent directory dotfiles: `../.env` or `../.env.shopwise`.

### Production Deployment Procedure
1. Deploy release code using Git or archive (excluding `.env`).
2. In the private cPanel directory, copy `.env.example` to create `.env`.
3. Set file permissions to `chmod 600 .env`.
4. Populate production credentials:
   - `APP_ENV=production`
   - `APP_DEBUG=false`
   - `APP_URL=https://shop.kennedycheroben.co.ke`
   - `APP_KEY=<strong_random_64_character_hex_key>`
   - `DB_HOST=127.0.0.1`
   - `DB_PORT=3306`
   - `DB_DATABASE=<cpanel_user>_shopwise`
   - `DB_USERNAME=<cpanel_user>_shopwise_user`
   - `DB_PASSWORD=<strong_random_db_password>`
   - `GOOGLE_CLIENT_ID=<production_client_id>.apps.googleusercontent.com`
   - `GOOGLE_CLIENT_SECRET=<production_client_secret>`
   - `GOOGLE_REDIRECT_URI=https://shop.kennedycheroben.co.ke/auth/google/callback`
   - `MAIL_DRIVER=smtp`
   - `MAIL_HOST=mail.kennedycheroben.co.ke`
   - `MAIL_PORT=465`
   - `MAIL_USERNAME=support@kennedycheroben.co.ke`
   - `MAIL_PASSWORD=<email_mailbox_password>`
   - `MAIL_ENCRYPTION=ssl`
   - `MPESA_ENV=production`
   - `MPESA_CONSUMER_KEY=<production_daraja_consumer_key>`
   - `MPESA_CONSUMER_SECRET=<production_daraja_consumer_secret>`
   - `MPESA_SHORTCODE=<production_business_shortcode>`
   - `MPESA_PASSKEY=<production_live_passkey>`
   - `MPESA_TRANSACTION_TYPE=CustomerPayBillOnline`
   - `MPESA_CALLBACK_BASE_URL=https://shop.kennedycheroben.co.ke`
   - `MPESA_CALLBACK_SECRET=<secure_random_64_character_hex_secret>`
5. Test HTTP blocking: Attempt to fetch `https://shop.kennedycheroben.co.ke/.env`. Verify an immediate `403 Forbidden` response.
