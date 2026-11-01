# ShopWise — Recommended Administrative Roles Architecture

**Project:** ShopWise Kenya E-Commerce Platform  
**Repository:** `kennedycheroben/shopwise-platform`  
**Branch:** `maintenance/php-modernization-security-audit`  
**Document Status:** Architecture Recommendation  
**Audience:** System Architects, Product Engineering, Security Team, and Business Leadership  

---

## 1. Executive Overview

To transform ShopWise from its current binary, monolithic `admin` / `staff` access model into a robust, enterprise-grade e-commerce administration system, we define a structured **Role-Based Access Control (RBAC)** architecture. 

The primary business objective is clear:
> **One Super Admin oversees the entire platform, while other administrators and department employees have distinct, least-privilege roles, isolated dashboards, and granular server-enforced permissions.**

An Accounts/Finance employee must have full oversight of financial transactions and reconciliation, but must **never** possess catalog pricing, stock adjustment, staff management, or store settings privileges. Similarly, warehouse staff must not see customer PII or company revenues, and customer support representatives must not be able to alter inventory or change catalog prices.

---

## 2. Departmental & Role Composition Analysis

### 2.1 Evaluation of the Proposed 7 Roles
The proposed 7 administrative roles map cleanly to the actual modules and operational lifecycles of ShopWise Kenya:

1. **Super Admin:** Executive oversight, governance, staff account administration, audit inspection, system settings, policy control, and high-value override authority.
2. **Accounts / Finance Manager:** Financial workspace, payment records, M-Pesa reconciliation, failed/pending payment verification, refund auditing and execution, revenue reports.
3. **Orders Manager (Fulfillment & Operations):** Order lifecycle processing, packing slips, dispatch, delivery coordination, shipping verification, internal fulfillment notes.
4. **Inventory Manager (Warehouse & Logistics):** Physical stock count, restocking, stock adjustments, low-stock threshold management, inventory movement audit.
5. **Product / Catalog Manager (Merchandising):** Product catalog, categories, variants, descriptions, imagery, SEO metadata, publishing/visibility.
6. **Customer Support (Customer Care):** Inbound inquiries (`contact_messages`), customer order inquiry lookup (read-only), review moderation, customer support notes.
7. **Marketing Manager (Growth & Promotions):** Coupons and discount codes (`coupons`), campaign performance tracking, category merchandising.

### 2.2 Operational Sizing & Role Combinations
For lean retail operations or initial deployment, maintaining 7 separate human administrators may exceed team capacity. We recommend the following **operational consolidation guidelines**:

- **Small Enterprise / Startup Configuration (3 Roles):**
  - **Super Admin:** Executive, governance, settings, staff, marketing, and emergency overrides.
  - **Finance & Operations Manager:** Combines Accounts (`finance`) and Orders (`fulfillment`).
  - **Catalog & Customer Experience:** Combines Catalog, Inventory, and Customer Support.
- **Mid-Tier Growth Configuration (5 Roles):**
  - **Super Admin**
  - **Accounts / Finance Manager**
  - **Operations & Warehouse Manager** (Orders + Inventory)
  - **Catalog & Marketing Manager** (Products + Categories + Coupons)
  - **Customer Support Specialist** (Inquiries + Reviews + Customer assistance)
- **Target Enterprise Configuration (7 Standalone Roles):**
  - Full departmental separation as detailed below.

Because the permission engine will be built on **granular capabilities mapped to roles**, a user can easily be assigned multiple functional roles or a composite role without modifying application code.

---

## 3. Comprehensive Specifications for Recommended Roles

---

### Role 1: Super Admin (`super_admin`)

* **Department:** Executive & System Governance
* **Hierarchy Level:** Root Authority (Level 0)
* **Purpose:** Complete platform oversight, security governance, team administration, and system-level configuration.
* **Responsibilities:**
  - Create, manage, activate, and deactivate all staff and administrative accounts.
  - Assign roles and departmental boundaries to personnel.
  - Configure platform settings (store identity, pickup hours, policies, delivery pricing rules).
  - Inspect system audit logs, security events, and failed authentication attempts.
  - Act as final approval authority for high-value refunds and sensitive exceptions.
* **Allowed Modules:** All modules without restriction (`*`).
* **Read Permissions:** Global read on all tables, logs, reports, and configurations.
* **Create Permissions:** Create staff accounts, products, categories, coupons, delivery zones, order notes.
* **Update Permissions:** Update all entities, store settings, staff roles, pricing, status overrides.
* **Delete Permissions:** Hard/soft product deletion, category deletion, coupon deletion, delivery zone deletion, review deletion.
* **Approval Permissions:** High-value refunds (> KES 10,000), manual order reversals, policy updates, staff role elevation.
* **Explicitly Forbidden Operations:**
  - Cannot alter immutable audit logs (`audit_log` is append-only).
  - Cannot delete or deactivate the last active Super Admin account.
  - Cannot demote own Super Admin role.
* **Dashboard Visible Metrics:**
  - Full platform revenue (KES), AOV, order volume, net profit margins.
  - System health, failed M-Pesa callbacks, active staff count, security alerts.
  - Cross-department performance KPIs (fulfillment speed, low stock, unread inquiries).

---

### Role 2: Accounts / Finance Manager (`finance_manager`)

* **Department:** Finance & Accounting
* **Hierarchy Level:** Department Manager (Level 1)
* **Purpose:** Maintain complete control and auditability over monetary flows, payment reconciliations, failed transactions, refunds, and financial reporting.
* **Responsibilities:**
  - Monitor all inbound payment transactions (`payments` table).
  - Reconcile M-Pesa Daraja STK Push callbacks against Safaricom statements and merchant accounts.
  - Investigate pending, failed, or timed-out payment transactions.
  - Verify manual payments where automated STK callbacks experienced network timeouts.
  - Review refund requests submitted by Customer Support/Orders and execute approved refunds with M-Pesa reversal references.
  - Generate daily, weekly, and monthly gross/net revenue reports and export financial ledgers.
* **Allowed Modules:**
  - Finance & Payments Workspace (`/admin/finance/`).
  - Orders (Read-only financial view: totals, payment status, customer name, transaction IDs).
  - Audit Log (Read-only financial actions).
* **Read Permissions:**
  - `finance.view`, `payments.view`, `payments.reconcile`, `payments.export`.
  - `orders.view` (financial and payment metadata).
  - `audit.finance_view` (financial event logs).
* **Create Permissions:**
  - `payments.record_manual`: Record verified offline or direct paybill transactions.
  - `refunds.initiate`: Initiate a refund transaction.
* **Update Permissions:**
  - `payments.verify`: Verify and mark an unconfirmed payment as settled.
  - `orders.mark_paid`: Transition an order from `pending_payment` to `paid` upon verified bank/M-Pesa receipt.
* **Delete Permissions:** **NONE.** Financial records must **never** be deleted under any circumstance.
* **Approval Permissions:**
  - Approve standard refunds (≤ KES 10,000) with verified payment history.
* **Explicitly Forbidden Operations (Strict Enforcements):**
  - **FORBIDDEN:** Creating or editing products (`products.*`).
  - **FORBIDDEN:** Modifying product base prices or variant prices.
  - **FORBIDDEN:** Adjusting physical warehouse stock or inventory movements (`inventory.*`).
  - **FORBIDDEN:** Managing product categories (`categories.*`).
  - **FORBIDDEN:** Managing staff accounts or altering user roles (`staff.*`).
  - **FORBIDDEN:** Modifying store policies or system settings (`settings.*`).
  - **FORBIDDEN:** Deleting or mutating existing payment callback records (`payments` table).
* **Dashboard Visible Metrics:**
  - Total M-Pesa collections today and over the last 30 days.
  - Pending payment verification count and failed transaction rate.
  - Total refunds issued this month (count and KES amount).
  - STK Push success rate percentage.
  - Daily settlement summary and reconciliation status.

---

### Role 3: Orders Manager (`orders_manager`)

* **Department:** Fulfillment & Operations
* **Hierarchy Level:** Department Manager (Level 1)
* **Purpose:** Oversee order fulfillment, packing, dispatch, delivery coordination, and customer delivery satisfaction.
* **Responsibilities:**
  - Process paid orders through fulfillment stages: `paid` → `processing` → `out_for_delivery` → `delivered`.
  - Print packing slips and delivery invoices for dispatch personnel.
  - Coordinate with delivery drivers and third-party logistics using County/Sub-County delivery details.
  - Record internal operational notes regarding packaging, tracking numbers, and driver assignments.
  - Cancel unfulfilled orders where customer requested cancellation prior to dispatch (automatically restoring stock).
  - Flag suspicious or problematic orders with `attention_reason`.
* **Allowed Modules:**
  - Orders Management (`/admin/orders/`).
  - Delivery Zones & Pricing (Read-only lookup to verify delivery rates).
  - Customer Directory (Read-only contact lookup for delivery coordination).
* **Read Permissions:**
  - `orders.view`, `orders.export`.
  - `delivery.view` (zones and pricing rules).
  - `customers.view` (recipient name, address, delivery notes, phone).
* **Create Permissions:**
  - `orders.notes_create`: Append internal operational timeline notes.
* **Update Permissions:**
  - `orders.status_transition`: Advance orders from `paid` through to `delivered`.
  - `orders.cancel`: Cancel unshipped orders (idempotently restoring inventory).
* **Delete Permissions:** **NONE.** Orders and line items cannot be deleted.
* **Approval Permissions:**
  - Approve order cancellation prior to dispatch.
* **Explicitly Forbidden Operations:**
  - **FORBIDDEN:** Issuing or recording refunds (`orders.refund`, `refunds.*`).
  - **FORBIDDEN:** Manually marking unpaid orders as paid without financial verification (`orders.mark_paid`).
  - **FORBIDDEN:** Editing product base prices or catalog information.
  - **FORBIDDEN:** Arbitrary warehouse inventory adjustments (cannot adjust stock outside order cancellation/fulfillment).
  - **FORBIDDEN:** Managing staff accounts, coupons, or store settings.
  - **FORBIDDEN:** Viewing gross revenue charts or profit metrics.
* **Dashboard Visible Metrics:**
  - Orders awaiting fulfillment (count).
  - Orders currently in processing and out for delivery (count).
  - Delayed or stalled shipments (> 48 hours in fulfillment).
  - Orders flagged with attention reasons.
  - Delivery method breakdown (Pickup vs Doorstep Delivery).

---

### Role 4: Inventory Manager (`inventory_manager`)

* **Department:** Warehouse & Logistics
* **Hierarchy Level:** Operational Staff (Level 2)
* **Purpose:** Maintain stock accuracy, record stock intake, manage reorder thresholds, and prevent stockouts.
* **Responsibilities:**
  - Monitor stock levels across all product variants (SKUs, sizes, colors).
  - Execute stock adjustments for restocks, damages, returns, or supplier discrepancies with mandatory audit notes.
  - Configure low-stock thresholds per variant to ensure timely reordering.
  - Audit inventory movement history (`inventory_movements` table) to investigate discrepancies.
* **Allowed Modules:**
  - Inventory & Stock Management (`/admin/inventory/`).
  - Inventory Movements Log (`/admin/inventory/movements.php`).
  - Product Catalog (Read-only view of SKUs, categories, and variant specifications).
* **Read Permissions:**
  - `inventory.view`, `inventory.movements_view`.
  - `products.view` (SKUs, variants, categories, specifications).
* **Create Permissions:**
  - `inventory.adjust`: Create signed stock adjustments (`reason`: `restock`, `admin_adjustment`).
* **Update Permissions:**
  - `inventory.threshold_update`: Update `low_stock_threshold`.
* **Delete Permissions:** **NONE.** Inventory movement logs are immutable.
* **Approval Permissions:** Restock entries up to 500 units per SKU.
* **Explicitly Forbidden Operations:**
  - **FORBIDDEN:** Creating or deleting products or categories.
  - **FORBIDDEN:** Altering product selling prices, compare-at prices, or descriptions.
  - **FORBIDDEN:** Viewing order financial totals or customer billing records.
  - **FORBIDDEN:** Changing order statuses or issuing refunds.
  - **FORBIDDEN:** Accessing staff management, coupons, or store settings.
* **Dashboard Visible Metrics:**
  - Out-of-stock variants count.
  - Low-stock alerts count (variants ≤ threshold).
  - Total units in warehouse.
  - Recent stock intake and deductions log.
  - Fastest-moving SKUs (by unit volume, not monetary revenue).

---

### Role 5: Product / Catalog Manager (`catalog_manager`)

* **Department:** Merchandising & E-Commerce
* **Hierarchy Level:** Department Manager (Level 1)
* **Purpose:** Curate and optimize product presentation, manage categories, maintain product attributes, and manage catalog pricing.
* **Responsibilities:**
  - Create, update, and organize products, descriptions, features, and SEO metadata.
  - Manage product variants (sizes, colors, SKUs, barcode mappings).
  - Upload, optimize, reorder, and assign primary product images.
  - Create and organize category hierarchies.
  - Set product selling prices (`base_price`) and promotional prices (`compare_at_price`).
  - Toggle product visibility (`is_active`, `is_featured`).
* **Allowed Modules:**
  - Products Management (`/admin/products/`).
  - Categories Management (`/admin/categories/`).
  - Reviews (Read-only product feedback view).
* **Read Permissions:**
  - `products.view`, `categories.view`, `reviews.view`.
* **Create Permissions:**
  - `products.create`: Create new products.
  - `categories.create`: Create new categories.
  - `variants.create`: Add variants to products.
  - `images.upload`: Upload gallery images.
* **Update Permissions:**
  - `products.edit`: Edit descriptions, SEO, prices, active status.
  - `categories.edit`: Edit category names, slugs, display orders.
  - `variants.edit`: Modify variant attributes and SKUs.
  - `images.reorder`: Change image display order and primary badge.
* **Delete Permissions:**
  - `products.soft_delete`: Move products to trash (`deleted_at = NOW()`).
  - `variants.delete`: Delete inactive variants without historical sales.
  - `images.delete`: Remove product images.
* **Approval Permissions:**
  - Publish new products to the live storefront.
* **Explicitly Forbidden Operations:**
  - **FORBIDDEN:** Permanent hard deletion of products (`products.hard_delete` is Super Admin only).
  - **FORBIDDEN:** Category deletion (`categories.delete` is Super Admin only to prevent orphaned products).
  - **FORBIDDEN:** Arbitrary warehouse stock overrides (cannot inject inventory outside initial setup).
  - **FORBIDDEN:** Access to orders, payments, refunds, customer PII, staff management, or store settings.
* **Dashboard Visible Metrics:**
  - Total active products and draft products.
  - Products missing primary images or category assignments.
  - Top viewed and top added-to-cart products.
  - New customer reviews awaiting catalog team attention.

---

### Role 6: Customer Support Specialist (`support_agent`)

* **Department:** Customer Experience & Support
* **Hierarchy Level:** Operational Staff (Level 2)
* **Purpose:** Address customer inquiries, assist customers with order status inquiries, moderate product reviews, and maintain positive customer relations.
* **Responsibilities:**
  - Read, triage, and reply to inbound contact inquiries (`contact_messages`).
  - Look up customer orders to answer delivery, tracking, and fulfillment questions.
  - Moderate customer product reviews (`reviews`) for spam, inappropriate language, or policy violations.
  - Assist customers with account access status inquiries.
  - Submit refund requests on behalf of dissatisfied customers for Finance/Super Admin review.
* **Allowed Modules:**
  - Customer Inquiries Inbox (`/admin/messages/`).
  - Reviews Moderation (`/admin/reviews/`).
  - Customers Directory (Read-only view of customer contact details and order history).
  - Orders (Read-only lookup of fulfillment status and delivery tracking).
* **Read Permissions:**
  - `messages.view`, `reviews.view`, `customers.view`, `orders.view`.
* **Create Permissions:**
  - `messages.reply`: Send email replies through the official customer service mailer.
  - `refunds.request`: Submit a refund request ticket for Finance review.
  - `orders.notes_create`: Add customer service interaction notes to an order.
* **Update Permissions:**
  - `messages.status_update`: Mark inquiries as `in_progress`, `resolved`, or `closed`.
  - `reviews.moderate`: Mark customer reviews as `approved` or `rejected`.
* **Delete Permissions:** **NONE.** Customer inquiries and reviews cannot be deleted by support agents.
* **Approval Permissions:** None.
* **Explicitly Forbidden Operations:**
  - **FORBIDDEN:** Review deletion (`reviews.delete` is Super Admin only).
  - **FORBIDDEN:** Customer blocking or account modification (`customers.manage` is Super Admin only).
  - **FORBIDDEN:** Modifying product details, prices, or inventory.
  - **FORBIDDEN:** Changing order statuses (cannot mark orders as paid or delivered).
  - **FORBIDDEN:** Executing or approving refunds (`refunds.approve`, `orders.refund`).
  - **FORBIDDEN:** Accessing staff management, coupons, delivery pricing, or store settings.
  - **FORBIDDEN:** Viewing company-wide revenue metrics.
* **Dashboard Visible Metrics:**
  - Unread / new customer inquiries count.
  - Pending customer reviews awaiting moderation count.
  - Average inquiry resolution time.
  - Recent customer service tickets.

---

### Role 7: Marketing Manager (`marketing_manager`)

* **Department:** Growth, Marketing & Merchandising
* **Hierarchy Level:** Department Manager (Level 1)
* **Purpose:** Drive customer acquisition, manage promotional coupon campaigns, and analyze conversion and promotional performance.
* **Responsibilities:**
  - Create and manage promotional discount coupons (`coupons` table: percentage, fixed, free delivery).
  - Configure coupon constraints (minimum spend, maximum discount, start/end dates, usage limits).
  - Monitor coupon redemption rates and campaign effectiveness.
  - Inspect product catalog categories and featured product selections for marketing campaigns.
* **Allowed Modules:**
  - Coupons & Promotions (`/admin/coupons/`).
  - Catalog (Read-only view of products and categories to align promotions).
  - Orders (Aggregated promotional view: coupon redemptions, discount volume).
* **Read Permissions:**
  - `coupons.view`, `products.view`, `categories.view`, `orders.promotions_view`.
* **Create Permissions:**
  - `coupons.create`: Create new discount coupon campaigns.
* **Update Permissions:**
  - `coupons.edit`: Modify coupon dates, thresholds, and toggle active/inactive status.
* **Delete Permissions:**
  - `coupons.delete`: Soft-delete expired or cancelled coupon codes.
* **Approval Permissions:**
  - Launch standard marketing campaigns up to 20% discount.
* **Explicitly Forbidden Operations:**
  - **FORBIDDEN:** Altering product base prices directly in the catalog.
  - **FORBIDDEN:** Changing warehouse stock or order statuses.
  - **FORBIDDEN:** Viewing customer personal data (PII) beyond anonymized order coupon redemptions.
  - **FORBIDDEN:** Processing refunds or altering payment statuses.
  - **FORBIDDEN:** Accessing staff management or system operational settings.
* **Dashboard Visible Metrics:**
  - Active coupon codes count.
  - Total coupon redemptions this month.
  - Total discounts granted (KES).
  - Top-performing coupon campaigns by revenue generated.
  - Average discount percentage per converted cart.

---

## 4. Super Admin Safeguards & Account Protection

The Super Admin account represents the highest privilege level in the enterprise. The system must enforce code-level and schema-level immutability safeguards:

### 4.1 Separate `super_admin` Role
The current `users.role` ENUM must be updated from `('customer', 'admin', 'staff')` to include `'super_admin'`:
```sql
ALTER TABLE `users` MODIFY COLUMN `role` ENUM(
  'customer',
  'super_admin',
  'finance_manager',
  'orders_manager',
  'inventory_manager',
  'catalog_manager',
  'support_agent',
  'marketing_manager',
  'admin',  -- Kept temporarily for backward compatibility
  'staff'   -- Kept temporarily for backward compatibility
) NOT NULL DEFAULT 'customer';
```

### 4.2 Mandatory Immutability Rules
1. **Protected Super Admin Entity:**
   - Any attempt by any administrator (including another Super Admin) to delete, block, or deactivate a Super Admin account must throw an immediate `DomainException("Super Administrator accounts cannot be deleted or deactivated.")`.
2. **Anti-Demotion Guard:**
   - A Super Admin account cannot have its role modified to any lesser role through the web interface. Role changes require direct, documented database operations by authorized system engineers.
3. **Sole Authority Over Staff Management:**
   - Only accounts with role `'super_admin'` can access `public/admin/staff/index.php`. No department manager (Finance, Orders, Catalog, etc.) can create or edit staff accounts or assign roles.
4. **Privilege Escalation Prevention:**
   - Under no circumstances may an account create or promote another account to a role equal to or higher than its own.
5. **Last Super Admin Safeguard:**
   - In all account update logic, the system must enforce:
     ```php
     $superAdminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND status = 'active'")->fetchColumn();
     if ($superAdminCount <= 1) {
         throw new DomainException("Operation blocked: The platform must retain at least one active Super Administrator.");
     }
     ```

---

## 5. Accounts / Finance Separation & Segregation of Duties

The separation of financial operations is a critical compliance and security requirement.

### 5.1 The Financial Workspace
Currently, ShopWise has no finance module. A dedicated financial workspace must be introduced under `public/admin/finance/`:
1. `public/admin/finance/index.php`: Financial Overview Dashboard (collections, settlement trends, M-Pesa health).
2. `public/admin/finance/payments.php`: Complete searchable payments ledger (`payments` table: IDs, M-Pesa receipts, checkout request IDs, status, phone, amounts, timestamps).
3. `public/admin/finance/reconciliation.php`: M-Pesa reconciliation workspace (matching payments against Safaricom statements and flagging discrepancies).
4. `public/admin/finance/refunds.php`: Dedicated refund management workspace (tracking initiated, pending approval, and executed refunds).
5. `public/admin/finance/reports.php`: Revenue, daily cash flow, and tax reporting with secure CSV export.

### 5.2 Segregation of Duties (Maker-Checker Workflow)
To prevent internal fraud, embezzlement, or accidental payouts, financial operations must enforce **two-person integrity (Maker-Checker)**:

```
[Customer Request / Stalled Order]
               │
               ▼
   [Step 1: Initiation (Maker)]
   Customer Support OR Orders Manager initiates Refund Request
   (Records order ID, affected items, reason, customer contact)
   Status: 'pending_approval'
               │
               ▼
   [Step 2: Financial Audit & Verification]
   Finance Manager audits payment record, M-Pesa receipt, and goods return
               │
               ▼
   [Step 3: Dual Approval & Execution (Checker)]
   - Small Refunds (≤ KES 10,000): Finance Manager approves & records reversal code.
   - Large Refunds (> KES 10,000): Requires Super Admin secondary approval.
   - STRICT RULE: The user who initiated the refund CANNOT approve it.
               │
               ▼
   [Step 4: Execution & Settlement]
   Order transitioned to 'refunded', M-Pesa reversal code logged, customer notified.
```

### 5.3 Prohibitions on the Finance Role
To maintain operational integrity, the Finance Manager is strictly locked out of operational modules:
- Cannot modify product selling prices (prevents price manipulation).
- Cannot adjust inventory quantities (prevents covering phantom losses).
- Cannot change order shipping status to `delivered` (prevents false fulfillment marking).
- Cannot create or modify staff accounts.
- Cannot delete or modify raw payment callback records in the database.

