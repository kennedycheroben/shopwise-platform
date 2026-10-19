# ShopWise Kenya - E-Commerce Platform

A production-grade, single-store e-commerce platform built for the Kenyan market.

- **Store Location:** Nairobi CBD, Kenya
- **Currency:** Kenyan Shillings (KES)
- **Primary Payments:** M-Pesa Daraja STK Push
- **Authentication:** Customer Email/Password + Google OAuth
- **Hosting Target:** Truehost cPanel Shared Hosting (subdomain of `kennedycheroben.co.ke`)
- **Technology Stack:** PHP 8.1+, MySQL/MariaDB (InnoDB, `utf8mb4_unicode_ci`), PDO with prepared statements only, Vanilla JS, Bootstrap 5.

---

## Repository Layout

```
shopwise-platform/
├── public/                 # Web root (accessible via browser)
│   ├── admin/              # PowerAdmin dashboard pages & assets
│   ├── assets/             # ShopWise storefront CSS, JS, fonts & images
│   ├── uploads/            # Customer media & product uploads
│   │   ├── products/       # Optimized WebP product photos & thumbnails
│   │   └── .htaccess       # Hardened protection (PHP script execution disabled)
│   ├── .htaccess           # Public directory rules & security headers
│   └── *.html              # Storefront template views (to be converted in Phase 2)
├── app/                    # Application Core (Protected from direct web access)
│   ├── config/
│   │   ├── config.example.php  # Template config with all environment options
│   │   ├── config.local.php    # Local dev overrides (Git-ignored)
│   │   └── config.php          # Main config loader with dynamic URL detection
│   ├── src/
│   │   ├── Database/       # PDO Singleton & atomic transaction manager
│   │   ├── Helpers/        # Security, money format, phone normalization, CSRF
│   │   └── Services/       # Upload & Mailer services
│   ├── storage/logs/       # Application error and activity logs
│   ├── views/partials/     # Shared PHP template partials (Phase 2)
│   └── bootstrap.php       # PSR-4 autoloader, session bootstrap & error handler
├── database/
│   ├── schema.sql          # Full database DDL with 25 relational tables
│   ├── seed.sql            # Seed data: 21 products, 66 variants, Nairobi zones
│   └── migrations/         # Future incremental database migrations
├── cron/
│   ├── expire_orders.php       # CLI script: cancels expired pending orders & restores stock
│   └── send_queued_emails.php  # CLI script: dispatches pending queued emails
├── scripts/
│   └── create_admin.php    # Interactive CLI script to provision administrator accounts
├── .gitignore
├── .htaccess               # Platform root rules (denies /app, /database, /cron, /scripts)
└── README.md
```

---

## Local Development Setup (XAMPP / LAMP)

### 1. Prerequisites
- **PHP:** 8.1 or higher (PHP 8.2+ recommended) with `pdo_mysql`, `gd`, `fileinfo`, `mbstring` extensions enabled.
- **Database:** MySQL 8.0+ or MariaDB 10.4+.
- **Web Server:** Apache with `mod_rewrite` and `mod_headers` enabled.

### 2. Database Installation
You can import the schema and seed data via **phpMyAdmin** or the command line:

#### Option A: Via phpMyAdmin
1. Open phpMyAdmin (`http://localhost/phpmyadmin/`).
2. Go to the **Import** tab.
3. Create/select a database named `shopwise_db`, then choose `database/schema.sql` and click **Import**. This creates all 25 tables in the selected database.
4. Keep that database selected in the left sidebar.
5. Go to the **Import** tab again.
6. Choose file: `database/seed.sql` and click **Import**.

#### Option B: Via Command Line (XAMPP Terminal)
```bash
/opt/lampp/bin/mysql -u root < database/local_create_db.sql
/opt/lampp/bin/mysql -u root shopwise_db < database/schema.sql
/opt/lampp/bin/mysql -u root shopwise_db < database/seed.sql
```

#### cPanel import
Create the database and database user in cPanel first (cPanel may prefix the database name). Assign the user **ALL PRIVILEGES**, select that database in phpMyAdmin, then import `schema.sql` followed by `seed.sql`. Do not import `local_create_db.sql` on cPanel.

### 3. Local Configuration
1. Copy the example configuration to create your local config:
   ```bash
   cp app/config/config.example.php app/config/config.local.php
   ```
2. Open `app/config/config.local.php` and verify your local database credentials:
   - Database name: `shopwise_db`
   - Username: `root`
   - Password: `""` (empty for standard XAMPP)
   - Base URL: Leave empty `""` for automatic dynamic detection, or specify `http://localhost/shopwise-platform/public`.

### 4. File & Directory Permissions
- **Local XAMPP Development**:
  Apache runs under user/group `daemon:daemon`. Ensure `public/uploads` and `app/storage` are writable by the web server:
  ```bash
  sudo chown -R daemon:daemon /opt/lampp/htdocs/shopwise-platform/public/uploads /opt/lampp/htdocs/shopwise-platform/app/storage
  ```
- **cPanel Shared Hosting (Production)**:
  On cPanel with suPHP/suExec, folders **must strictly be set to `0755`** and files to **`0644`**. Setting directories to `0777` on cPanel triggers `500 Internal Server Error`. The application's `ImageUploadService` automatically enforces `0755` directory creation and `0644` file permissions.

### 5. Dependencies
This phase has no Composer dependencies. When PHPMailer is added for production email delivery, install it locally and commit the resulting `vendor/` directory because the target host has no Composer:
```bash
composer install
```
Do not run Composer on the production server.

### 6. Provision the Administrator Account
Run the CLI provisioning script to create your superadmin account:
```bash
/opt/lampp/bin/php scripts/create_admin.php
```
You will be prompted to enter:
- First Name
- Last Name
- Email Address
- Telephone Number (optional)
- Admin Password (min 8 characters; hidden input)

---

## Background Scheduled Tasks (cPanel Cron Jobs)

## Development dashboard data

`database/demo_orders.sql` is development-only and is never part of production setup. Load it with `/opt/lampp/bin/mysql -u root shopwise_db < database/demo_orders.sql`; remove only its sample rows with `database/demo_orders_remove.sql`.

In cPanel under **Cron Jobs**, configure the following CLI commands:

1. **Order Expiration & Stock Restoration** (Runs every 5 minutes):
   ```bash
   /usr/local/bin/php /home/username/public_html/cron/expire_orders.php >/dev/null 2>&1
   ```

2. **Email Queue Dispatcher** (Runs every 2 minutes):
   ```bash
   /usr/local/bin/php /home/username/public_html/cron/send_queued_emails.php >/dev/null 2>&1
   ```
