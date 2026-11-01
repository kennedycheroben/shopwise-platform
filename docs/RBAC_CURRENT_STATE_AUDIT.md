# ShopWise — Role-Based Access Control (RBAC) Current State Audit

**Project:** ShopWise Kenya E-Commerce Platform  
**Repository:** `kennedycheroben/shopwise-platform`  
**Branch:** `maintenance/php-modernization-security-audit`  
**Audit Date:** October 31, 2026  
**Auditor:** Software Architecture, Security & Product Engineering Audit Team  
**Audit Classification:** STRICTLY ARCHITECTURAL & DIAGNOSTIC — NO CODE MODIFIED  

---

## 1. Executive Summary

ShopWise Kenya is a production-grade e-commerce application built on PHP 8.2+, MySQL/MariaDB (InnoDB), and Apache, utilizing server-rendered components, Vanilla JavaScript, Bootstrap 5.3, and custom CSS. The storefront integrates direct M-Pesa Daraja STK Push payments, customer cart management, checkout with County/Sub-County delivery calculation, user accounts with password resets, Google OAuth, and email verification.

This audit evaluates the platform's administrative authorization model. Currently, ShopWise operates under a **binary two-tier administrative authorization model**:
1. **`admin`**: A super-user role endowed with global wildcard authorization (`*`).
2. **`staff`**: A monolithic employee role with a broad, flat set of permissions spanning catalog management, product creation, variant editing, inventory adjustments, order processing, cancellation, customer viewing, review moderation, and customer support communications.

### Key Audit Findings
1. **Absence of Departmental Boundaries:** There is currently no departmental segregation. A single staff member assigned the `staff` role has simultaneous access to catalog pricing, warehouse stock counts, customer personal identifiable information (PII), and order management.
2. **Unprotected Executive Dashboard & Data Leakage:** The main admin dashboard (`/admin/index.php`), the dashboard metrics API (`/admin/api/dashboard.php`), and the revenue chart API (`/admin/api/revenue-chart.php`) enforce **no permission checks whatsoever**. Any authenticated user passing `app/views/admin/guard.php` (which includes any `staff` member) can view full 30-day paid revenue, historical revenue charts, average order value (AOV), sales totals, and top products by revenue.
3. **Orphaned & Inconsistent Authorization Methods:** `App\Services\AdminAuthService::can()` defines a coarse-grained permission check (`['dashboard', 'products', 'inventory', 'orders', 'customers', 'reviews', 'messages']`), but **it is never called anywhere in the entire codebase**. Furthermore, `AdminAuthService::requireAdmin()` is never invoked. All page and service authorization relies entirely on `App\Services\Permissions::require()` and `Permissions::can()`.
4. **Absence of a Dedicated Finance/Accounts Module:** There is no dedicated payments, reconciliation, or financial ledger module. Payment transactions (`payments` table) and M-Pesa receipts are only inspectable inside individual order detail pages (`/admin/orders/detail.php`).
5. **Makeshift Permission Hacks:** To restrict manual payment receipt entry (`markAsPaid`), the service method `App\Services\OrderService::markAsPaid()` calls `Permissions::require('orders.refund')`. Because `orders.refund` was omitted from the `staff` permissions array, it was repurposed as an ad-hoc proxy for "admin only".
6. **Hardcoded Role Bypasses in Views:** In `public/admin/reviews/index.php`, deleting a customer review does not check a permission key such as `reviews.delete`; instead, it evaluates an inline `$isAdmin` boolean (`$currentUser['role'] === 'admin'`).
7. **Over-Permissive Permissions:** The capability `products.edit` grants permission not only to update existing products, but also to create new products (`/admin/products/create.php`), delete product variants (`variant_delete`), and delete product images (`delete_image`). There is no distinct `products.create` or `products.delete` for variant/image assets.
8. **Lack of a True Super Admin Entity:** The database `users.role` ENUM only recognizes `'customer'`, `'admin'`, and `'staff'`. Any administrator can create, edit, or demote another administrator, subject only to a runtime check preventing demotion or deactivation of the last active administrator.

---

## 2. Current Roles and Database Representation

### 2.1 Database Schema (`users` table)
In [database/schema.sql](file:///opt/lampp/htdocs/shopwise-platform/database/schema.sql#L17-L40), the role attribute is defined as an `ENUM`:

```sql
CREATE TABLE `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role` ENUM('customer', 'admin', 'staff') NOT NULL DEFAULT 'customer',
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `phone` VARCHAR(20) NULL,
  `password_hash` VARCHAR(255) NULL,
  `google_id` VARCHAR(191) NULL,
  `avatar_url` VARCHAR(255) NULL,
  `status` ENUM('active', 'blocked') NOT NULL DEFAULT 'active',
  `email_verified_at` DATETIME NULL,
  `last_login_at` DATETIME NULL,
  `remember_token_hash` VARCHAR(255) NULL,
  `password_changed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

*Evidence Status:* **CONFIRMED** (`database/schema.sql`, lines 17-40).  
There are **no tables** representing roles (e.g., `roles`), granular permissions (e.g., `permissions`), or role assignments (e.g., `role_permissions`, `user_roles`). The role is strictly a single 3-value column on `users`.

---

## 3. Detailed Inspection of Authorization Infrastructure

### 3.1 `App\Services\Permissions.php`
Located at [app/src/Services/Permissions.php](file:///opt/lampp/htdocs/shopwise-platform/app/src/Services/Permissions.php#L1-L38):

```php
final class Permissions
{
    private const MAP = [
        'admin' => ['*'],
        'staff' => [
            'products.view',
            'products.edit',
            'categories.manage',
            'inventory.adjust',
            'orders.view',
            'orders.manage',
            'customers.view',
            'reviews.moderate',
            'messages.manage',
        ],
    ];

    public static function can(string $ability, ?string $role = null): bool
    {
        if ($role === null && (PHP_SAPI === 'cli' || defined('STDIN'))) {
            return true;
        }
        $role ??= AdminAuthService::user()['role'] ?? '';
        $abilities = self::MAP[$role] ?? [];
        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    public static function require(string $ability): void
    {
        if (!self::can($ability)) {
            http_response_code(403);
            require dirname(__DIR__, 2) . '/views/admin/error-403.php';
            exit;
        }
    }

    public static function matrix(): array { return self::MAP; }
}
```

#### Observations & Vulnerabilities in `Permissions.php`:
1. **Fixed In-Memory Map:** Permissions are hardcoded in a static class constant (`self::MAP`). Any role customization requires code modifications.
2. **Staff Monolith:** `staff` has 9 broad permissions. There is no separation between catalog editors, order processors, customer care, and inventory warehouse workers.
3. **CLI Blanket Bypass:** `if ($role === null && (PHP_SAPI === 'cli' || defined('STDIN'))) return true;`. While practical for CLI test runners and cron jobs, if any web endpoint executes in a subshell or simulated environment where `STDIN` is defined without passing a role, permissions default to unrestricted `true`.
4. **Missing Negative Rules:** The model only supports positive allow-lists. There is no concept of explicit `deny` rules.

*Evidence Status:* **CONFIRMED & TESTED** (`scripts/acceptance_2c1.sh`, `scripts/acceptance_2c2.sh`).

---

### 3.2 `App\Services\AdminAuthService.php`
Located at [app/src/Services/AdminAuthService.php](file:///opt/lampp/htdocs/shopwise-platform/app/src/Services/AdminAuthService.php#L1-L140):

```php
public static function can(string $capability): bool
{
    $u = self::user();
    if (!$u) {
        return false;
    }
    if ($u['role'] === 'admin') {
        return true;
    }
    return in_array($capability, ['dashboard', 'products', 'inventory', 'orders', 'customers', 'reviews', 'messages'], true);
}

public static function requireAdmin(): array
{
    $user = self::requireLogin();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        require dirname(__DIR__, 2) . '/views/admin/error-403.php';
        exit;
    }
    return $user;
}
```

#### Observations & Critical Architectural Flaws:
1. **Dead Code / Orphaned Methods:** Exhaustive static grep across `app/`, `public/`, and `scripts/` reveals that **`AdminAuthService::can()` is never called anywhere**.
2. **Naming & Concept Divergence:** `AdminAuthService::can()` uses domain module nouns (`'dashboard'`, `'products'`, `'inventory'`), while `Permissions::can()` uses dot-notated actions (`'products.view'`, `'products.edit'`, `'orders.manage'`). If a developer attempts to call `AdminAuthService::can('products.view')`, it returns `false` for staff.
3. **Unused `requireAdmin()`:** `AdminAuthService::requireAdmin()` is never called in any route or controller. Routes instead call `Permissions::require('staff.manage')` or `Permissions::require('settings.manage')` to enforce admin-only access.

*Evidence Status:* **CONFIRMED** (`app/src/Services/AdminAuthService.php`).

---

### 3.3 `app/views/admin/guard.php`
Located at [app/views/admin/guard.php](file:///opt/lampp/htdocs/shopwise-platform/app/views/admin/guard.php#L1-L9):

```php
header('Cache-Control: no-store, private'); header('X-Frame-Options: DENY'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: strict-origin-when-cross-origin');
$return=$_SERVER['REQUEST_URI'] ?? '/admin/'; if (!str_starts_with($return,'/') || str_starts_with($return,'//')) $return='/admin/';
$sessionUser=AdminAuthService::user(); if (!$sessionUser) { header('Location: '.url('admin/login.php?return='.rawurlencode($return))); exit; }
$stmt=Database::getInstance()->prepare('SELECT id,role,first_name,last_name,email,status,password_changed_at FROM users WHERE id=:id LIMIT 1'); $stmt->execute([':id'=>$sessionUser['id']]); $current=$stmt->fetch();
if (!$current || !in_array($current['role'],['admin','staff'],true) || $current['status']!=='active' || ($sessionUser['role'] ?? '') !== $current['role'] || (($sessionUser['password_changed_at'] ?? null) !== $current['password_changed_at'])) { AdminAuthService::logout(); header('Location: '.url('admin/login.php')); exit; }
$_SESSION['admin_user']=$current; $adminUser=$current;
```

#### Observations & Critical Security Gap:
1. **Authentication Guard Only:** `guard.php` verifies that the session corresponds to an active user in the database whose role is in `['admin', 'staff']`, that their role hasn't changed mid-session, and that their password hasn't been changed since the session was issued.
2. **Zero Permission Enforcement:** `guard.php` does **not** check what the user is allowed to do.
3. **Vulnerability:** Any script that includes `guard.php` but neglects to call `Permissions::require(...)` is open to **all** staff members.

*Evidence Status:* **CONFIRMED & TESTED** (`scripts/acceptance_2c1.sh`).

---

### 3.4 `App\Services\StaffService.php`
Located at [app/src/Services/StaffService.php](file:///opt/lampp/htdocs/shopwise-platform/app/src/Services/StaffService.php#L1-L230):

`StaffService` manages backend user accounts. It enforces several safety rules:
- **Admin Actor Enforcement:** Line 73: `if ($actorRole !== 'admin') throw new DomainException("Only administrators can create staff or administrator accounts.");`
- **Self-Role Modification Protection:** Line 156: `if ($actorId === $id && $newRole !== $user['role']) throw new DomainException("You cannot change your own administrative role.");`
- **Last Active Admin Protection (Demotion):** Line 165: Prevents demoting an admin to staff if `activeAdmins <= 1`.
- **Self-Deactivation Protection:** Line 201: `if ($actorId === $id) throw new DomainException("You cannot deactivate your own account.");`
- **Last Active Admin Protection (Deactivation):** Line 212: Prevents deactivating an admin if `activeAdmins <= 1`.
- **Secure Passwordless Invitation:** Uses `PasswordResetService::request()` to email a setup link rather than allowing the admin to set or know the user's password.

#### Gaps in `StaffService`:
1. **No Hierarchy Among Admins:** All users with `role = 'admin'` are completely equal. Admin A can deactivate or demote Admin B (provided Admin C exists or Admin B isn't the last). There is no "Super Admin" who is immune from action by subordinate administrators.
2. **Binary Role Assignment:** `StaffService::create()` and `update()` only allow `['admin', 'staff']`.

*Evidence Status:* **CONFIRMED & TESTED** (`scripts/acceptance_2c2.sh`, lines 709–780).

---

### 3.5 `App\Services\DashboardService.php`
Located at [app/src/Services/DashboardService.php](file:///opt/lampp/htdocs/shopwise-platform/app/src/Services/DashboardService.php#L1-L310):

`DashboardService::data()` computes global, platform-wide metrics:
- 30-day paid revenue and trend comparison against previous 30 days.
- Monthly, weekly, and daily revenue and order charts (`chartData()`).
- Total category revenue breakdown.
- Top 10 products by units sold and revenue generated.
- Recent 10 orders with customer names, status, and total monetary amounts.
- Stock alerts (variants at or below low stock threshold).
- Total sales today, new customers this month, active products count.

#### Security Gap:
This service has **no role parameter and performs no permission checks**. It assumes the caller is entitled to full financial intelligence.

*Evidence Status:* **CONFIRMED** (`app/src/Services/DashboardService.php`).

---

## 4. Complete Audit of Admin Endpoints and Protection Status

The application has 33 PHP endpoints in `public/admin/`. Below is the exhaustive audit of each endpoint:

| Endpoint Path | Method(s) | Current Protection | Authorized Roles | Audit Status | Vulnerability / Data Leakage Identified |
|---|---|---|---|---|---|
| `public/admin/login.php` | GET, POST | Public (Rate-limited, timing-mitigated) | Guests | **TESTED** | None. Standard auth entrypoint. |
| `public/admin/logout.php` | GET, POST | Session termination | Authenticated | **CONFIRMED** | None. Terminates session. |
| `public/admin/forgot-password.php` | GET, POST | Public (Rate-limited) | Guests | **TESTED** | None. Emailed reset token. |
| `public/admin/reset-password.php` | GET, POST | Public (Token-verified) | Token holders | **TESTED** | None. Token-based single-use. |
| `public/admin/index.php` | GET | `guard.php` only | `admin`, `staff` | **CONFIRMED** | **CRITICAL LEAK:** Staff views full financial revenue, AOV, order totals. Supports `?format=json` which dumps all data. |
| `public/admin/api/dashboard.php` | GET (AJAX) | `guard.php` only | `admin`, `staff` | **CONFIRMED** | **CRITICAL LEAK:** Any staff member can fetch JSON containing total platform revenue, top products revenue, recent order values. |
| `public/admin/api/revenue-chart.php` | GET (AJAX) | `guard.php` only | `admin`, `staff` | **CONFIRMED** | **CRITICAL LEAK:** Any staff member can fetch monthly/weekly/daily revenue sums. |
| `public/admin/api/admin-only.php` | GET (AJAX) | `guard.php` + `Permissions::require('staff.manage')` | `admin` only | **TESTED** | Correctly denies staff with HTTP 403. |
| `public/admin/profile.php` | GET, POST | `guard.php` | `admin`, `staff` | **TESTED** | Safe self-service (updates own name/password only). |
| `public/admin/search.php` | GET | `guard.php` + `SearchService` permissions | `admin`, `staff` | **TESTED** | Sub-queries filter by `orders.view`, `products.view`, `customers.view`. Staff currently sees all 3 groups. |
| `public/admin/orders/index.php` | GET | `Permissions::require('orders.view')` | `admin`, `staff` | **TESTED** | Staff sees all order numbers, recipient names, delivery methods, payment statuses, and totals. |
| `public/admin/orders/detail.php` | GET | `Permissions::require('orders.view')` | `admin`, `staff` | **TESTED** | Staff sees customer phone, delivery address, order items, unit prices, internal notes, M-Pesa checkout IDs. |
| `public/admin/orders/detail.php` (POST transition) | POST | `OrderService::transition` (`orders.manage`) | `admin`, `staff` | **TESTED** | Staff can change order status (processing, out_for_delivery, delivered). If target is `refunded`, requires `orders.refund`. |
| `public/admin/orders/detail.php` (POST cancel) | POST | `OrderService::cancel` (`orders.manage`) | `admin`, `staff` | **TESTED** | Staff can cancel orders and trigger automated stock restoration. |
| `public/admin/orders/detail.php` (POST mark_as_paid) | POST | `OrderService::markAsPaid` (`orders.refund`) | `admin` only | **TESTED** | Admin only. Repurposes `orders.refund` as an admin check. |
| `public/admin/orders/detail.php` (POST record_refund) | POST | `OrderService::recordRefund` (`orders.refund`) | `admin` only | **TESTED** | Admin only. Allows recording refund with reference note. |
| `public/admin/orders/detail.php` (POST add_note) | POST | `OrderService::addInternalNote` (`orders.manage`) | `admin`, `staff` | **TESTED** | Staff can append internal timeline notes. |
| `public/admin/orders/detail.php` (POST check_mpesa_status) | POST | `Permissions::require('orders.manage')` | `admin`, `staff` | **TESTED** | **RISK:** Staff can query Safaricom STK status; on success, transitions order to Paid! |
| `public/admin/orders/invoice.php` | GET | `Permissions::require('orders.view')` | `admin`, `staff` | **TESTED** | Printable invoice with customer details, item prices, subtotal, and total. |
| `public/admin/orders/packing-slip.php` | GET | `Permissions::require('orders.view')` | `admin`, `staff` | **TESTED** | Printable packing slip (items, quantities, recipient address). |
| `public/admin/products/index.php` | GET | `Permissions::require('products.view')` | `admin`, `staff` | **TESTED** | Staff can list, filter, and inspect all products. |
| `public/admin/products/index.php` (POST toggle) | POST | `Permissions::require('products.edit')` | `admin`, `staff` | **TESTED** | Staff can toggle product visibility (active/inactive). |
| `public/admin/products/index.php` (POST soft_delete) | POST | `Permissions::require('products.delete')` | `admin` only | **TESTED** | Correctly restricted to admin. |
| `public/admin/products/index.php` (POST restore) | POST | `Permissions::require('products.delete')` | `admin` only | **TESTED** | Correctly restricted to admin. |
| `public/admin/products/index.php` (POST hard_delete) | POST | `Permissions::require('products.delete')` | `admin` only | **TESTED** | Correctly restricted to admin. |
| `public/admin/products/create.php` | GET, POST | `Permissions::require('products.edit')` | `admin`, `staff` | **TESTED** | **GAP:** Staff can create new products and set pricing. No `products.create` permission exists. |
| `public/admin/products/edit.php` | GET | `Permissions::require('products.view')` | `admin`, `staff` | **TESTED** | Staff can view edit screen. |
| `public/admin/products/edit.php` (POST update_product) | POST | `Permissions::require('products.edit')` | `admin`, `staff` | **TESTED** | Staff can modify base price, compare price, title, descriptions, categories. |
| `public/admin/products/edit.php` (POST variant_*) | POST | `Permissions::require('products.edit')` | `admin`, `staff` | **TESTED** | **GAP:** Staff can add, update, generate, AND **delete** product variants under `products.edit`. |
| `public/admin/products/edit.php` (POST image_*) | POST | `Permissions::require('products.edit')` | `admin`, `staff` | **TESTED** | **GAP:** Staff can upload, reorder, and **delete** product images under `products.edit`. |
| `public/admin/categories/index.php` | GET | `Permissions::require('categories.manage')` | `admin`, `staff` | **TESTED** | Staff can view all categories. |
| `public/admin/categories/index.php` (POST create/update/toggle) | POST | `categories.manage` (implicit page guard) | `admin`, `staff` | **TESTED** | Staff can create and edit category names, slugs, descriptions. |
| `public/admin/categories/index.php` (POST delete) | POST | `Permissions::require('categories.delete')` | `admin` only | **TESTED** | Correctly restricted to admin. |
| `public/admin/inventory/index.php` | GET, POST | `Permissions::require('inventory.adjust')` | `admin`, `staff` | **TESTED** | Staff can view stock and perform quick stock adjustments (`delta` or `set`). |
| `public/admin/inventory/movements.php` | GET | `Permissions::require('inventory.adjust')` | `admin`, `staff` | **TESTED** | Staff can view inventory movements log. |
| `public/admin/customers/index.php` | GET | `Permissions::require('customers.view')` | `admin`, `staff` | **TESTED** | Staff views all customer accounts, names, emails, phones, status. |
| `public/admin/customers/index.php` (POST set_status) | POST | `Permissions::require('customers.manage')` | `admin` only | **TESTED** | Correctly restricted to admin. |
| `public/admin/customers/detail.php` | GET | `Permissions::require('customers.view')` | `admin`, `staff` | **TESTED** | Staff views customer order history, lifetime spend, addresses. |
| `public/admin/customers/detail.php` (POST set_status) | POST | `Permissions::require('customers.manage')` | `admin` only | **TESTED** | Correctly restricted to admin. |
| `public/admin/coupons/index.php` | GET, POST | `Permissions::require('coupons.manage')` | `admin` only | **TESTED** | Denied to staff (HTTP 403). Admin manages coupons. |
| `public/admin/delivery/zones.php` | GET, POST | `Permissions::require('delivery.manage')` | `admin` only | **TESTED** | Denied to staff (HTTP 403). Admin manages zones. |
| `public/admin/delivery/pricing.php` | GET, POST | `Permissions::require('delivery.manage')` | `admin` only | **TESTED** | Denied to staff (HTTP 403). Admin manages delivery fees. |
| `public/admin/messages/index.php` | GET, POST | `Permissions::require('messages.manage')` | `admin`, `staff` | **TESTED** | Staff views contact inquiries, updates status. |
| `public/admin/messages/detail.php` | GET, POST | `Permissions::require('messages.manage')` | `admin`, `staff` | **TESTED** | Staff reads messages, submits email replies to customers. |
| `public/admin/reviews/index.php` | GET, POST (moderate) | `Permissions::require('reviews.moderate')` | `admin`, `staff` | **TESTED** | Staff views reviews, marks approved/rejected/pending. |
| `public/admin/reviews/index.php` (POST delete) | POST | Hardcoded `if (!$isAdmin)` | `admin` only | **CONFIRMED** | Admin only, but hardcoded check rather than permission. |
| `public/admin/settings/index.php` | GET, POST | `Permissions::require('settings.manage')` | `admin` only | **TESTED** | Denied to staff (HTTP 403). Admin updates store info, policies. |
| `public/admin/staff/index.php` | GET, POST | `Permissions::require('staff.manage')` | `admin` only | **TESTED** | Denied to staff (HTTP 403). Admin creates/edits staff accounts. |
| `public/admin/audit-log/index.php` | GET | `Permissions::require('audit.view')` | `admin` only | **TESTED** | Denied to staff (HTTP 403). Admin inspects system audit trail. |

---

## 5. Security Vulnerabilities and Authorization Bypasses Summary

### 5.1 Critical Security Vulnerabilities
1. **Unprotected Financial Intelligence (Direct URL & API Access):**
   - Direct browsing to `/admin/` exposes the revenue dashboard to any staff member.
   - Fetching `/admin/?format=json`, `/admin/api/dashboard.php`, or `/admin/api/revenue-chart.php` returns full business revenue figures to any staff cookie.
2. **Lack of Asset Deletion Separation:**
   - A staff member with `products.edit` cannot delete a product, but **can delete every variant and every image** belonging to that product, effectively vandalizing the catalog.
3. **M-Pesa STK Query Execution by Staff:**
   - Any staff member can trigger Safaricom STK Push query from `/admin/orders/detail.php` (action `check_mpesa_status`), which automatically marks the order as `paid` upon Safaricom confirmation.
4. **Unilateral Stock Adjustments:**
   - Staff can arbitrarily overwrite stock quantities (`mode = 'set'`) with zero approval or validation.

### 5.2 Architectural Inconsistencies
1. **`AdminAuthService` vs `Permissions` Disconnect:**
   - Two competing authorization classes exist. `AdminAuthService` has dead code; `Permissions` is the true authority.
2. **Ad-Hoc Permission Repurposing:**
   - `OrderService::markAsPaid()` checks `Permissions::require('orders.refund')` instead of a dedicated financial permission such as `payments.verify` or `orders.mark_paid`.
3. **Inline Role Comparisons:**
   - `reviews/index.php` inspects `$currentUser['role'] === 'admin'` rather than calling `Permissions::require('reviews.delete')`.

---

## 6. Audit Conclusion

The current ShopWise administration model provides good foundational security against unauthenticated external actors (CSRF tokens, rate limits, session fixation prevention, password change invalidation). However, **internal authorization between staff members is completely flat**.

The system must evolve from this binary `admin`/`staff` model to a structured **Departmental Role-Based Access Control (RBAC)** architecture with strict separation of duties, role-tailored dashboards, and an isolated financial workspace.

