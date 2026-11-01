# ShopWise — Role-Based Access Control (RBAC) Implementation Plan

**Project:** ShopWise Kenya E-Commerce Platform  
**Repository:** `kennedycheroben/shopwise-platform`  
**Branch:** `maintenance/php-modernization-security-audit`  
**Document Status:** Phased Engineering Implementation Roadmap  
**Execution Condition:** STRICTLY AUDIT & ARCHITECTURE — DO NOT EXECUTE WITHOUT EXPLICIT APPROVAL  

---

## 1. Definitive Answers to the 10 Core Audit Questions

### Question 1: What roles and permissions currently exist?
* **Current Roles:** Only **two** administrative roles exist in the database and application code:
  1. `admin`: Endowed with global wildcard authorization (`['*']`).
  2. `staff`: Endowed with a flat, monolithic array of 9 capabilities:
     `products.view`, `products.edit`, `categories.manage`, `inventory.adjust`, `orders.view`, `orders.manage`, `customers.view`, `reviews.moderate`, `messages.manage`.
* **Database Representation:** Stored strictly as an `ENUM('customer', 'admin', 'staff')` column on the `users` table. There are no relational tables for roles, capabilities, or permissions.

### Question 2: Which routes and actions are protected?
* **Protected Routes:**
  - `admin/staff/index.php` (requires `staff.manage` — admin only).
  - `admin/settings/index.php` (requires `settings.manage` — admin only).
  - `admin/audit-log/index.php` (requires `audit.view` — admin only).
  - `admin/coupons/index.php` (requires `coupons.manage` — admin only).
  - `admin/delivery/zones.php` and `pricing.php` (require `delivery.manage` — admin only).
  - `admin/products/index.php` (GET requires `products.view`; POST soft_delete/restore/hard_delete requires `products.delete`).
  - `admin/products/create.php` (requires `products.edit`).
  - `admin/products/edit.php` (GET requires `products.view`; POST updates require `products.edit`).
  - `admin/categories/index.php` (GET & POST create/update/toggle require `categories.manage`; POST delete requires `categories.delete`).
  - `admin/inventory/index.php` and `movements.php` (require `inventory.adjust`).
  - `admin/orders/index.php`, `detail.php`, `invoice.php`, `packing-slip.php` (require `orders.view`; POST transitions and cancel require `orders.manage`; POST mark_as_paid and record_refund require `orders.refund`).
  - `admin/customers/index.php` and `detail.php` (require `customers.view`; POST block/unblock requires `customers.manage`).
  - `admin/messages/index.php` and `detail.php` (require `messages.manage`).
  - `admin/reviews/index.php` (requires `reviews.moderate`; POST delete checks `$isAdmin`).
* **Unprotected / Leaked Routes:**
  - `public/admin/index.php` (Dashboard view & `?format=json`).
  - `public/admin/api/dashboard.php` (Dashboard JSON API).
  - `public/admin/api/revenue-chart.php` (Revenue Chart JSON API).
  These endpoints check only `guard.php` (session authentication) and leak all company financial data to any staff member.

### Question 3: Are there authorization inconsistencies or bypasses?
* **YES. Six major inconsistencies exist:**
  1. **Dashboard & API Financial Leakage:** No permission check exists on `index.php`, `api/dashboard.php`, or `api/revenue-chart.php`.
  2. **Dead / Orphaned Authorization Code:** `AdminAuthService::can()` is defined with coarse module names but is never called anywhere. `AdminAuthService::requireAdmin()` is also never invoked.
  3. **Ad-Hoc Permission Repurposing:** `OrderService::markAsPaid()` checks `Permissions::require('orders.refund')` as a hacky proxy for "admin only".
  4. **Hardcoded Inline Role Bypasses:** In `reviews/index.php`, review deletion checks `if (!$isAdmin)` instead of a permission key.
  5. **Asset Deletion Escalation:** `products.edit` allows deleting product variants and product images, bypassing the delete restriction on the parent product.
  6. **CLI Blanket Bypass:** `Permissions::can()` returns `true` if `PHP_SAPI === 'cli' && $role === null`.

### Question 4: What is the minimum sensible set of departments?
* For ShopWise Kenya, the minimum sensible departmental division is **four functional departments** overseen by the Super Admin:
  1. **Executive & Governance:** Super Admin.
  2. **Finance & Accounting:** Accounts / Finance Manager.
  3. **Operations & Fulfillment:** Orders & Inventory.
  4. **Commercial & Customer Experience:** Catalog, Marketing & Customer Support.
* For enterprise scale, full separation into the **7 recommended roles** (Super Admin, Finance, Orders, Inventory, Catalog, Customer Support, Marketing) provides complete least-privilege security.

### Question 5: What should each role see on its dashboard?
* **Super Admin:** Complete platform revenue, AOV, order volume, security/audit feeds, system health, attention orders.
* **Accounts / Finance:** M-Pesa collections, pending reconciliations, failed/timeout STK transactions, refund volume, cash flow charts. **No product editing or inventory controls.**
* **Orders Manager:** Dispatch queues, orders awaiting processing, out-for-delivery counts, regional delivery heatmap. **Zero monetary revenue figures.**
* **Inventory Manager:** Out-of-stock SKUs, low-stock reorder warnings, physical warehouse counts, unit velocity. **No customer PII or financial revenue.**
* **Product / Catalog Manager:** Live vs draft products, missing images/categories, catalog data quality issues.
* **Customer Support:** Unread inquiry tickets, pending reviews queue, customer wait times, order status lookup tool.
* **Marketing Manager:** Active coupon campaigns, coupon redemption volume, total discount spend, campaign ROI.

### Question 6: What financial actions should Accounts control?
* **Accounts MUST control:**
  - Inbound payments ledger inspection (`payments` table).
  - M-Pesa transaction auditing (receipt numbers, checkout IDs, merchant IDs).
  - Safaricom STK Push status queries and reconciliation against merchant statements.
  - Verification of offline/manual payments (`mark_as_paid`).
  - Standard refund processing (≤ KES 10,000) for confirmed returns.
  - Financial exports (CSV ledgers of revenue, tax, and delivery collections).
* **Accounts MUST NOT control:**
  - Product creation, editing, or base price modifications.
  - Inventory quantity adjustments or write-offs.
  - Staff account provisioning or role assignments.
  - Store identity, pickup hours, or operational settings.
  - Tampering with or deleting payment records or audit logs.

### Question 7: Which operations require approval by another role?
* **Maker-Checker Operations:**
  1. **Refunds > KES 10,000:** Initiated by Finance or Support; requires **Super Admin** approval before M-Pesa reversal execution.
  2. **Standard Refunds (≤ KES 10,000):** Initiated by Support or Orders; requires **Finance Manager** verification and approval. (Initiator cannot approve).
  3. **Large Inventory Write-Downs (> 100 units):** Initiated by Inventory; requires **Super Admin** approval.
  4. **Store Policy Updates:** Drafted by Catalog/Legal; approved by **Super Admin**.
  5. **Staff Role Elevation:** Solely restricted to **Super Admin**.

### Question 8: Can staff access another department's data through direct URLs or APIs?
* **YES, in the current state:**
  - Any staff member who enters `http://localhost/.../admin/index.php` or requests `admin/?format=json` sees full platform financial revenue.
  - Any staff member can query `admin/api/dashboard.php` and `admin/api/revenue-chart.php` to obtain revenue data.
  - Any staff member can access `/admin/orders/detail.php?id=X` and view customer phone numbers, delivery addresses, and payment IDs.
  - Any staff member can alter stock quantities at `/admin/inventory/` or modify catalog prices at `/admin/products/edit.php`.
* **Under the Recommended RBAC Architecture:**
  - All page routes, GET requests, POST actions, and JSON APIs will enforce server-side `Permissions::require(...)`. Direct URL navigation or forged POSTs by unauthorized roles will immediately result in **HTTP 403 Forbidden**.

### Question 9: What database and application changes would be required to implement the recommended architecture?
* **Database Schema Changes:**
  1. Update `users.role` ENUM to include `'super_admin'`, `'finance_manager'`, `'orders_manager'`, `'inventory_manager'`, `'catalog_manager'`, `'support_agent'`, `'marketing_manager'`.
  2. Create `refund_requests` table to support the Maker-Checker workflow (storing order ID, applicant ID, approver ID, amount, reason, status, reversal reference).
* **Application Code Changes:**
  1. Refactor `App\Services\Permissions.php` to map the new roles to granular capabilities.
  2. Deprecate and remove dead methods in `AdminAuthService` (`can()`, `requireAdmin()`).
  3. Decompose `DashboardService` into role-specific data providers (`DashboardService::forRole($role)`).
  4. Create the dedicated Finance workspace under `public/admin/finance/`.
  5. Update `app/views/admin/sidebar.php` to render departmental navigation menus.
  6. Enforce server-side permissions on all endpoints, POST actions, and JSON APIs.

### Question 10: What is the safest implementation sequence?
* The safest sequence follows a **6-phase risk-managed approach**:
  1. **Phase 1:** Security Hardening (lock down leaking dashboard APIs and orphan methods on existing roles).
  2. **Phase 2:** Database Schema Evolution (expand `users.role` enum, migrate existing admin to `super_admin`).
  3. **Phase 3:** RBAC Engine Refactoring (granular permission mappings in `Permissions.php`).
  4. **Phase 4:** Dedicated Finance Workspace & Maker-Checker Refund Workflow.
  5. **Phase 5:** Departmental Dashboards & Navigation Restructuring.
  6. **Phase 6:** Automated Test Suite Expansion & End-to-End Verification.

---

## 2. Phased Implementation Roadmap

```
┌────────────────────────────────────────────────────────────────────────┐
│                   PHASED RBAC IMPLEMENTATION ROADMAP                   │
└────────────────────────────────────────────────────────────────────────┘

  Phase 1: Immediate Security Hardening & Defect Remediation
  ├── Add Permissions::require('dashboard.view_executive') to index.php & APIs
  ├── Deprecate orphaned AdminAuthService::can() and requireAdmin()
  └── Fix inline role check in reviews/index.php to use reviews.delete

  Phase 2: Database Schema & Role Hierarchy Migration
  ├── Migration 007: Expand users.role ENUM to support 7 departmental roles
  ├── Designate primary Super Admin account (prevent last admin demotion)
  └── Create refund_requests table for Maker-Checker two-person integrity

  Phase 3: Core Authorization Engine Refactoring
  ├── Update Permissions::MAP with granular capability keys
  ├── Implement Permissions::can() and Permissions::require() consolidation
  └── Tighten CLI execution guard to prevent accidental ambient bypasses

  Phase 4: Finance Workspace & Separation of Duties Implementation
  ├── Implement public/admin/finance/ (payments ledger, reconciliation)
  ├── Build Maker-Checker workflow in OrderService and RefundService
  └── Isolate M-Pesa STK push queries and mark-as-paid to Finance only

  Phase 5: Modular Department Dashboards & Navigation
  ├── Refactor DashboardService::data() into role-specific viewmodels
  ├── Build departmental dashboard views (Finance, Orders, Inventory, Support)
  └── Update sidebar.php and header.php to render role-tailored menus

  Phase 6: Automated Verification & Audit Trail Hardening
  ├── Expand acceptance test suites (acceptance_rbac.sh)
  ├── Test HTTP 403 enforcement across all roles for forbidden URLs and POSTs
  └── Verify before/after state diffs in AuditLogger
```

---

### Phase 1: Security Hardening & Defect Remediation
* **Goal:** Immediately plug data leakage vulnerabilities without disrupting existing functionality.
* **Actions:**
  1. In `public/admin/index.php`, `public/admin/api/dashboard.php`, and `public/admin/api/revenue-chart.php`, add authorization checks:
     ```php
     Permissions::require('dashboard.view');
     ```
  2. In `public/admin/reviews/index.php`, replace:
     ```php
     if (!$isAdmin) { http_response_code(403); ... }
     ```
     with:
     ```php
     Permissions::require('reviews.delete');
     ```
  3. In `app/src/Services/OrderService.php::markAsPaid()`, replace:
     ```php
     Permissions::require('orders.refund'); // Admin only hack
     ```
     with:
     ```php
     Permissions::require('payments.mark_as_paid');
     ```
  4. Ensure `AdminAuthService` defers all capability checks to `Permissions::can()`.

---

### Phase 2: Database Schema Evolution
* **Goal:** Safely update database structures without data loss.
* **Migration `database/migrations/007_implement_rbac_roles_and_refunds.sql`:**
  ```sql
  -- Step 1: Expand users role enum
  ALTER TABLE `users` MODIFY COLUMN `role` ENUM(
    'customer',
    'super_admin',
    'finance_manager',
    'orders_manager',
    'inventory_manager',
    'catalog_manager',
    'support_agent',
    'marketing_manager',
    'admin',
    'staff'
  ) NOT NULL DEFAULT 'customer';

  -- Step 2: Migrate existing admin accounts to super_admin
  UPDATE `users` SET `role` = 'super_admin' WHERE `role` = 'admin';

  -- Step 3: Create refund_requests table for two-person integrity
  CREATE TABLE `refund_requests` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id` BIGINT UNSIGNED NOT NULL,
    `initiator_user_id` BIGINT UNSIGNED NOT NULL,
    `approver_user_id` BIGINT UNSIGNED NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `reason` VARCHAR(255) NOT NULL,
    `status` ENUM('pending_approval', 'approved', 'rejected', 'executed') NOT NULL DEFAULT 'pending_approval',
    `reversal_reference` VARCHAR(64) NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_refund_order` (`order_id`),
    KEY `idx_refund_status` (`status`),
    CONSTRAINT `fk_refund_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_refund_initiator` FOREIGN KEY (`initiator_user_id`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_refund_approver` FOREIGN KEY (`approver_user_id`) REFERENCES `users` (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ```

---

### Phase 3: Core Authorization Engine Refactoring
* **Goal:** Reconfigure `App\Services\Permissions.php` with the full granular matrix defined in `docs/ADMIN_PERMISSION_MATRIX.md`.
* **Actions:**
  - Map each role (`super_admin`, `finance_manager`, `orders_manager`, `inventory_manager`, `catalog_manager`, `support_agent`, `marketing_manager`) to its explicit allow-list.
  - Maintain `'admin' => ['*']` for backward compatibility.
  - Enhance `Permissions::require()` to accept arrays of capabilities for OR-logic where appropriate:
    ```php
    public static function requireAny(array $abilities): void
    ```

---

### Phase 4: Financial Workspace & Separation of Duties
* **Goal:** Create an isolated financial module and enforce Maker-Checker integrity.
* **Actions:**
  1. Create `public/admin/finance/index.php` (Finance Overview).
  2. Create `public/admin/finance/payments.php` (Payments Ledger with status filters, M-Pesa receipt search).
  3. Create `public/admin/finance/reconciliation.php` (M-Pesa reconciliation workspace).
  4. Create `public/admin/finance/refunds.php` (Refund approval & execution interface).
  5. In `OrderService::cancel()`, ensure that paid orders require a refund ticket in `refund_requests`.
  6. Enforce that `$initiatorUserId !== $approverUserId` for all refund approvals.

---

### Phase 5: Modular Department Dashboards & Navigation
* **Goal:** Deliver role-tailored dashboards and navigation sidebars.
* **Actions:**
  1. Refactor `DashboardService` into modular methods (`getSuperAdminDashboard()`, `getFinanceDashboard()`, `getOrdersDashboard()`, etc.).
  2. Create role-specific dashboard views in `app/views/admin/dashboards/`:
     - `executive.php`
     - `finance.php`
     - `orders.php`
     - `inventory.php`
     - `catalog.php`
     - `support.php`
     - `marketing.php`
  3. In `app/views/admin/sidebar.php`, wrap navigation menu groups with role capabilities so staff only see their own department's links.

---

### Phase 6: Automated Verification & Audit Trail Hardening
* **Goal:** Ensure all security boundaries are validated with automated tests.
* **Actions:**
  1. Author `scripts/acceptance_rbac.sh` to test every role against every endpoint:
     - Verify Finance cannot access catalog edit or inventory adjust.
     - Verify Orders cannot access finance ledger or revenue charts.
     - Verify Inventory cannot access customer details or financial reports.
     - Verify Support cannot adjust prices or execute refunds.
     - Verify Super Admin has full oversight and cannot be demoted.
  2. Verify before/after diff logging in `AuditLogger` for sensitive operations (role changes, payment status updates, stock write-downs).

---

## 3. Risk Management & Rollback Strategy

| Identified Risk | Severity | Mitigation Strategy | Rollback Procedure |
|---|:---:|---|---|
| **Disruption to existing staff workflows** | Medium | Backward compatibility: existing `admin` accounts are mapped to `super_admin`; existing `staff` mapped to a composite role or retained during rollout. | Retain `'admin'` and `'staff'` keys in `Permissions::MAP` during transition. |
| **Accidental lockout of administrators** | High | Schema migration maintains existing credentials; `scripts/create_admin.php` updated to provision `super_admin`. | CLI rescue script (`scripts/create_admin.php`) can re-elevate any account directly via database. |
| **Database migration interruption** | Medium | Migration uses standard DDL `MODIFY COLUMN` without dropping columns or tables. | Revert enum to `('customer', 'admin', 'staff')` via simple reverse DDL. |
| **API breakage on mobile/third-party** | Low | Internal APIs (`api/dashboard.php`) are only used by the web admin interface; response formats retain existing keys where appropriate. | Fallback to legacy dashboard method if role is unrecognized. |

---

---

## 5. Milestone 2A Implementation & Verification Record (Completed)

* **Date:** 2026-11-01
* **Branch:** `maintenance/php-modernization-security-audit`
* **Status:** Verified & Fully Tested in `shopwise_test`

### 5.1 Architecture & Schema Implemented
1. **Database Schema (`database/migrations/007_create_roles_and_user_roles.sql`):**
   - Canonical `roles` table created and populated with 7 departmental roles (`super_admin`, `finance_manager`, `orders_manager`, `inventory_manager`, `catalog_manager`, `support_agent`, `marketing_manager`), legacy compatibility aliases (`admin`, `staff`), and storefront `customer`.
   - `users.role` widened from restrictive ENUM to `VARCHAR(50) NOT NULL DEFAULT 'customer'` to accommodate all roles without data truncation or locking.
   - Normalized `user_roles` junction table created with foreign key constraints to support multi-role assignments.
2. **Explicit System Execution Context (`App\Services\SecurityContext`):**
   - Completely replaced ambient CLI bypass (`$role === null && (PHP_SAPI === 'cli' ...)`).
   - Provides `SecurityContext::runAsSystem(callable $action, string $reason, array $metadata = [])` with automatic context reversion in `finally`.
   - Enforces web-impersonation guard: non-admin web sessions cannot enter system execution context.
   - Integrates with `AuditLogger::write()` to record `_system_execution: true` and `_system_reason`.
3. **Centralized Permissions Registry & Role Resolution (`App\Services\Permissions`):**
   - Strict capability matrix defining positive and negative boundaries for all 7 roles.
   - `Permissions::rolesForUser(array|int $user)` resolves normalized multi-role arrays.
   - `Permissions::require(string $ability, ?int $actorId = null)` validates actor roles for CLI/service invocations and guards web sessions.
4. **Automated Verification:**
   - Dedicated suite `scripts/test_milestone_2a.sh`: **57 passed, 0 failed**.
   - Regression suite `scripts/test_phase1_rbac_hardening.sh`: **102 passed, 0 failed**.
   - Acceptance suite `scripts/acceptance_2c1.sh`: **81 passed, 0 failed**.
   - Acceptance suite `scripts/acceptance_2c2.sh`: **115 passed, 0 failed**.
   - Protected baseline `shopwise_db` remained 100% untouched.


