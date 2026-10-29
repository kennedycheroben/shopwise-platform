# ShopWise Kenya - E-Commerce Platform

A production-grade, single-store e-commerce platform built for the Kenyan market.

- **Store Location:** Nairobi CBD, Kenya
- **Currency:** Kenyan Shillings (KES)
- **Primary Payments:** M-Pesa Daraja STK Push (Customer PayBill Online)
- **Authentication:** Customer Email/Password + Google OAuth (Storefront); Strict Session RBAC (Admin)
- **Hosting Target:** Truehost cPanel Shared Hosting (subdomain of `kennedycheroben.co.ke`)
- **Technology Stack:** PHP 8.1+, MariaDB/MySQL (InnoDB, `utf8mb4_unicode_ci`), PDO with prepared statements, Vanilla JavaScript, Bootstrap 5.

---

## Current Build Status

| Component | Status | Notes |
| :--- | :--- | :--- |
| **Database Architecture** | **Complete** | 25 tables, relational constraints, atomic inventory ledger, audit trail |
| **Admin Portal (Phase 2A)** | **Complete** | Dashboard metrics, revenue charts, session RBAC, brute-force protection |
| **Admin Catalog (Phase 2B)** | **Complete** | Products, variants matrix, image upload security, concurrency stock locks |
| **Admin Orders (Phase 2C-1)** | **Complete** | Order lifecycle, payments, refunds, packing slips, customer management |
| **Admin Modules (Phase 2C-2)**| **Complete** | Coupons, delivery zones & pricing, reviews, messages, staff, audit log |
| **Storefront (Phase 3 & 4)** | *Pending* | Catalog browsing, cart, checkout, customer account portal (Upcoming) |
| **Automated Crons (Phase 5)** | *Placeholder*| Background order expiry & M-Pesa auto-reconciliation (Upcoming) |

---

## Repository Layout

```
shopwise-platform/
├── public/                 # Web root (Subdomain document root points HERE)
│   ├── admin/              # Admin application controllers & views
│   │   ├── api/            # JSON endpoints (dashboard, revenue charts)
│   │   ├── audit-log/      # Audit log viewer & filtering
│   │   ├── coupons/        # Coupon promotion management
│   │   ├── customers/      # Customer directory & account control
│   │   ├── delivery/       # Delivery zones & distance pricing engine
│   │   ├── messages/       # Inbound customer contact messages & replies
│   │   ├── orders/         # Orders, invoices, packing slips, status transitions
│   │   ├── products/       # Products, variant matrix & image uploads
│   │   ├── reviews/        # Product review moderation
│   │   ├── settings/       # Store profile & delivery calculation settings
│   │   ├── staff/          # Staff RBAC management & password setup tokens
│   │   ├── search.php      # Global administrative search
│   │   ├── login.php       # Administrative sign-in with lockout protection
│   │   ├── forgot-password.php # Password reset request
│   │   └── reset-password.php  # Token-based password reset
│   ├── assets/             # Storefront & Admin shared assets (CSS, JS, fonts)
│   ├── uploads/            # Uploaded media (PHP execution blocked via .htaccess)
│   │   └── products/       # Product images & WebP variants
│   ├── .htaccess           # Web server routing & security headers
│   └── index.php           # Storefront router (Phase 3)
├── app/                    # Application Core (Protected from direct web access)
│   ├── config/
│   │   ├── config.example.php  # Environment configuration blueprint
│   │   ├── config.local.php    # Local/Production secret overrides (Git-ignored)
│   │   └── config.php          # Main configuration loader & URL detection
│   ├── src/
│   │   ├── Database/       # PDO Database singleton & connection pool
│   │   ├── Helpers/        # Security, string, CSRF, money & phone helpers
│   │   └── Services/       # Core business domain services:
│   │                       # AdminAuthService, AuditLogService, AuditLogger,
│   │                       # ContactMessageService, CouponService, CustomerService,
│   │                       # DeliveryService, ImageUploadService, MailerService,
│   │                       # NotificationService, OrderService, PasswordResetService,
│   │                       # Permissions, ProductService, ReviewService,
│   │                       # SearchService, SettingService, StaffService, VariantService
│   ├── storage/logs/       # Application error and activity logs
│   ├── views/admin/        # Shared admin layout partials:
│   │                       # head, header, sidebar, footer, guard, error-403, error-404
│   └── bootstrap.php       # Autoloader, security constants, session & error handling
├── database/
│   ├── schema.sql          # Full database DDL with 25 relational tables
│   ├── seed.sql            # Core seed data: 21 products, 66 variants, Nairobi delivery zones
│   ├── demo_orders.sql     # Development-only realistic demo orders & transactions
│   └── demo_orders_remove.sql # Cleanup script to remove demo orders without touching seed data
├── cron/
│   ├── expire_orders.php   # Scheduled order expiry (Placeholder until Phase 5)
│   └── send_queued_emails.php # Scheduled email dispatcher
├── docs/
│   ├── TESTING_RULES.md    # Testing constitution, data integrity rules & audit log policies
│   └── PRE_DEPLOY_CLEANUP.md # Pre-flight deployment audit checklist
├── scripts/
│   ├── run_all_tests.sh    # Master test suite runner (linting + all acceptance suites)
│   ├── acceptance_2afix.sh # Phase 2A acceptance test runner
│   ├── acceptance_2b.sh    # Phase 2B acceptance test runner
│   ├── acceptance_2c1.sh   # Phase 2C-1 acceptance test runner
│   ├── acceptance_2c2.sh   # Phase 2C-2 acceptance test runner
│   ├── check_stock_parity.py # Live stock ledger invariant parity verifier
│   └── create_admin.php    # Interactive CLI administrator account provisioning
├── vendor/                 # Vendored third-party dependencies:
│   └── PHPMailer/          # PHPMailer 6.9+ for authenticated SMTP email delivery
├── .gitignore              # Repository exclusions (credentials, logs, uploads, tmp)
├── .htaccess               # Root hardening (denies direct access to /app, /database, /cron, /scripts)
├── LICENSE-NOTICE.md       # Third-party licenses, BootstrapMade attributions & notices
└── README.md
```

---

## Local Development Setup (XAMPP / LAMP)

### 1. Prerequisites
- **PHP:** 8.1 or higher (PHP 8.2+ recommended) with `pdo_mysql`, `gd`, `fileinfo`, `mbstring`, `json` extensions enabled.
- **Database:** MariaDB 10.4+ or MySQL 8.0+.
- **Web Server:** Apache with `mod_rewrite` and `mod_headers` enabled.

### 2. Database Installation
Create the database and import schema followed by seed data:

#### Via Command Line (XAMPP / Linux):
```bash
/opt/lampp/bin/mysql -u root < database/local_create_db.sql
/opt/lampp/bin/mysql -u root shopwise_db < database/schema.sql
/opt/lampp/bin/mysql -u root shopwise_db < database/seed.sql
```

#### Via phpMyAdmin:
1. Open phpMyAdmin (`http://localhost/phpmyadmin/`).
2. Create database `shopwise_db` with collation `utf8mb4_unicode_ci`.
3. Select `shopwise_db`, click **Import**, choose `database/schema.sql` and run.
4. With `shopwise_db` still selected, click **Import**, choose `database/seed.sql` and run.

### 3. Local Configuration
1. Copy the example configuration to create your local config:
   ```bash
   cp app/config/config.example.php app/config/config.local.php
   ```
2. Open `app/config/config.local.php` and verify credentials:
   - Database name: `shopwise_db`
   - Database user: `root`
   - Database password: `""` (empty for default XAMPP)
   - Base URL: Leave empty `""` for automatic detection or set `http://localhost/shopwise-platform/public`.

### 4. Dependencies & PHPMailer
This project does not require Composer on the server. PHPMailer is vendored directly in `vendor/PHPMailer/` and is autoloaded by `app/bootstrap.php`.

### 5. Provision an Administrator Account
Run the interactive CLI provisioning script to create a superadmin:
```bash
/opt/lampp/bin/php scripts/create_admin.php
```

### 6. Google OAuth 2.0 Setup (Storefront Customer Sign-In)
To enable Google Sign-In for storefront customers, create a Web Application OAuth Client in the Google Cloud Console:

1. **Google Cloud Console Project**:
   - Navigate to [Google Cloud Console](https://console.cloud.google.com/) and create or select your project.
2. **OAuth Consent Screen**:
   - Go to **APIs & Services > OAuth consent screen**.
   - Select **External** user type and click **Create**.
   - App Name: `ShopWise Kenya`
   - User Support Email: your support email (e.g. `support@shopwise.co.ke`)
   - Developer Contact Information: your technical contact email.
   - Scopes: `openid`, `email`, `profile`.
3. **Create Credentials**:
   - Go to **APIs & Services > Credentials > Create Credentials > OAuth client ID**.
   - Application Type: **Web application**.
   - Name: `ShopWise Storefront Web Client`.
4. **Authorized JavaScript Origins**:
   - `http://localhost`
   - `http://127.0.0.1`
   - `https://shopwise.kennedycheroben.co.ke` (production subdomain)
5. **Authorized Redirect URIs** (Exact URLs):
   - Local development:
     `http://localhost/shopwise-platform/public/auth/google/callback`
   - Local fallback / 127.0.0.1:
     `http://127.0.0.1/shopwise-platform/public/auth/google/callback`
   - Production subdomain:
     `https://shopwise.kennedycheroben.co.ke/auth/google/callback`
6. **Save Credentials in `config.local.php`**:
   - Add your client ID and client secret ONLY to `app/config/config.local.php` (never commit real secrets to Git):
     ```php
     'google' => [
         'client_id'     => 'YOUR_CLIENT_ID.apps.googleusercontent.com',
         'client_secret' => 'GOCSPX-YOUR_CLIENT_SECRET',
         'redirect_uri'  => 'http://localhost/shopwise-platform/public/auth/google/callback',
     ],
     ```
   - *Note*: If Google credentials are not configured, the Google Sign-In button is automatically hidden from public storefront pages to prevent dead buttons.

---

## Development & Testing Data

### Demo Orders Data
For testing the administrative dashboard with historical charts, status queues, and customer orders:
```bash
# Load realistic demo orders:
/opt/lampp/bin/mysql -u root shopwise_db < database/demo_orders.sql

# Remove demo orders (restores catalog and removes demo rows cleanly):
/opt/lampp/bin/mysql -u root shopwise_db < database/demo_orders_remove.sql
```

### Back Up Database Before Every Phase
Always back up the working database before beginning any new development phase or executing maintenance:
```bash
bash scripts/backup_db.sh
```
This dumps `shopwise_db` to `~/backups/shopwise_db_<timestamp>.sql` with mysqldump, verifies path and size, and never overwrites previous snapshots.

### Local Storage & Permissions (No 0777)
Apache in XAMPP runs as user `daemon:daemon`. Rate limit tracking files and uploads must be writable by the web server without world-writable permissions:
```bash
sudo chown -R daemon:daemon /opt/lampp/htdocs/shopwise-platform/app/storage /opt/lampp/htdocs/shopwise-platform/public/uploads
chmod -R 0755 /opt/lampp/htdocs/shopwise-platform/app/storage /opt/lampp/htdocs/shopwise-platform/public/uploads
```
All directories created dynamically in PHP code use `0755` and files use `0644`. World-writable permissions (`0777`) are strictly prohibited in code, configuration, and documentation.

### Dedicated Test Database & Isolated Testing Architecture
All automated tests run strictly against a disposable test database (`shopwise_test`). The working database (`shopwise_db`) is protected and is **never** read or written by any test runner or test script.

- **Host-Based Configuration Isolation:**
  - Working site runs at `http://localhost/shopwise-platform/public` -> connects to `shopwise_db`.
  - Test suite runs at `http://127.0.0.1/shopwise-platform/public` with `SHOPWISE_CONFIG=testing` -> strictly loads `app/config/config.testing.php` (targeting `shopwise_test`, `public/uploads_test/`, and `app/storage/rate_limits_test/`).
  - In production (`app.environment != 'local'`), the testing switch is strictly disabled and impossible to activate.
- **Guard Rails:**
  - `scripts/reset_test_db.sh` refuses execution if the target database is `shopwise_db` or does not end with `_test`.
  - Every acceptance script (`acceptance_2afix.sh`, `acceptance_2b.sh`, `acceptance_2c1.sh`, `acceptance_2c2.sh`, `acceptance_3a.sh`) contains an active guard that aborts immediately if pointed at `shopwise_db`.
- **Master Test Runner:**
  ```bash
  bash scripts/run_all_tests.sh
  ```
  The master runner records `CHECKSUM TABLE` for all 25 tables in `shopwise_db` and counts files in `public/uploads`, recreates `shopwise_test`, runs PHP linting (`php -l`), runs all 5 acceptance test suites plus `check_stock_parity.py`, re-checks table checksums and upload count, and **fails immediately** if any table in `shopwise_db` or file in `public/uploads` was modified.

Individual test suites can also be executed independently against `shopwise_test`:
```bash
bash scripts/acceptance_2afix.sh
bash scripts/acceptance_2b.sh
bash scripts/acceptance_2c1.sh
bash scripts/acceptance_2c2.sh
bash scripts/acceptance_3a.sh
SHOPWISE_CONFIG=testing python3 scripts/check_stock_parity.py
```

---

## Production Deployment & cPanel Cron Configuration

### 1. Document Root Architecture
On cPanel shared hosting (e.g. Truehost), the application root sits **above the public web root** for security:
- **Repository Location:** `/home/CPANELUSER/shopwise-platform`
- **Subdomain Document Root:** Must point to `/home/CPANELUSER/shopwise-platform/public`

This prevents public HTTP access to `/app`, `/database`, `/cron`, `/scripts`, and sensitive configuration files.

### 2. Scheduled Cron Jobs
In cPanel under **Cron Jobs**, configure the following CLI commands:

1. **Email Queue Dispatcher** (Runs every 2 minutes):
   ```bash
   /usr/local/bin/php /home/CPANELUSER/shopwise-platform/cron/send_queued_emails.php >/dev/null 2>&1
   ```

2. **Order Expiration & Stock Restoration** (Placeholder until Phase 5):
   ```bash
   # Phase 5 automated order expiry & stock release (every 5 minutes):
   /usr/local/bin/php /home/CPANELUSER/shopwise-platform/cron/expire_orders.php >/dev/null 2>&1
   ```
   *Note: `cron/expire_orders.php` is a functional placeholder that will be finalized in Phase 5 alongside storefront order placement.*

3. **Abandoned Cart Purge** (Runs daily at midnight):
   ```bash
   0 0 * * * /usr/local/bin/php /home/CPANELUSER/shopwise-platform/cron/purge_carts.php >/dev/null 2>&1
   ```
   *Note: Automatically removes abandoned guest and customer carts and line items untouched for more than 30 days.*

---

## Go-Live Checklist

Before launching to production, complete each of the following mandatory steps:

1. **Set `app.environment = 'production'`**: In `config.local.php`, set `'environment' => 'production'`. When `app.environment` is not `'production'`, every response is sent with header `X-Robots-Tag: noindex, nofollow` and `<meta name="robots" content="noindex, nofollow">` to prevent staging and local indexation. Setting it to `'production'` enables search engine crawling and indexation.
2. **Replace Placeholder Settings**: Update store contact details in Admin > Settings or the `settings` database table:
   - **Support Phone**: Replace default placeholder with verified customer care phone number.
   - **Support Email**: Replace with verified domain email (e.g., `support@shopwise.co.ke`).
   - **Pickup Address**: Replace placeholder with exact physical store/depot location in Nairobi CBD.
3. **Remove Test Configuration & Test Routes**:
   - Delete `app/config/config.testing.php`.
   - Remove disposable test directories: `public/uploads_test/` and `app/storage/rate_limits_test/`.
   - Remove test routes and diagnostic endpoints (`/cart/test/clear-rate-limits` and `public/admin/api/admin-only.php`).
   - Exclude or delete the `scripts/` directory from the production deployment bundle.
4. **Enable HTTPS & Enforce SSL**:
   - Ensure an active SSL certificate (Let's Encrypt / cPanel AutoSSL) is installed on the domain/subdomain.
   - Enforce HTTPS redirection in web server configuration or `.htaccess`.
5. **Set Cookie Secure Flags**:
   - In `config.local.php`, ensure `'session' => ['secure' => true]` is active so all session (`PHPSESSID`) and guest cart cookies (`shopwise_cart`) enforce `Secure; HttpOnly; SameSite=Lax`.
6. **Configure Production SMTP Credentials**:
   - In `config.local.php`, supply live SMTP credentials (`mail.host`, `mail.port`, `mail.username`, `mail.password`, `mail.encryption`) so transactional emails (email verifications, password resets, order notifications) are delivered reliably.
7. **Set Up Scheduled Cron Jobs**:
   - Configure cPanel cron jobs for email sending (`cron/send_queued_emails.php` every 2 minutes), abandoned cart cleanup (`cron/purge_carts.php` daily at midnight), and order expiration (`cron/expire_orders.php` every 5 minutes in Phase 5).

---

## License & Attribution
- UI components and page templates are derived from **PowerAdmin** and **ShopWise** by **BootstrapMade**.
- Review [LICENSE-NOTICE.md](LICENSE-NOTICE.md) for full licensing terms and mandatory footer attribution requirements.
