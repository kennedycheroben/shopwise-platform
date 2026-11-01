# ShopWise — Administrative Permission Matrix & Capability Specifications

**Project:** ShopWise Kenya E-Commerce Platform  
**Repository:** `kennedycheroben/shopwise-platform`  
**Branch:** `maintenance/php-modernization-security-audit`  
**Document Status:** Enterprise Architecture Specification  
**Classification Standard:** Strict Codebase Verification Audit  

---

## 1. Classification Methodology & Legend

Every administrative capability in this matrix has been audited against the actual ShopWise codebase, schemas, and test suites. Entries are classified using the following standards:

* **CONFIRMED:** The capability, check, or route is explicitly present, inspected, and verified in the source code.
* **TESTED:** The capability and its authorization restrictions have been verified via automated bash/curl integration test suites (`acceptance_2c1.sh`, `acceptance_2c2.sh`, `acceptance_5.sh`).
* **PARTIAL:** The capability exists in part (e.g. view exists, but delete is combined with edit, or permission is checked in code but not in UI).
* **INFERRED:** The capability is logically required by business workflows but currently missing or handled by an ad-hoc workaround.
* **NOT FOUND:** The capability or module is absent from the current codebase (e.g., dedicated payments ledger, refund tables).
* **NOT VERIFIED:** Untested edge cases or third-party external dependencies requiring live sandbox credentials.

### Matrix Role Abbreviations:
* **CUR-ADM:** Current Administrator (`admin`)
* **CUR-STF:** Current Monolithic Staff (`staff`)
* **SUP-ADM:** Recommended Super Admin (`super_admin`)
* **FIN-MGR:** Recommended Accounts / Finance Manager (`finance_manager`)
* **ORD-MGR:** Recommended Orders / Fulfillment Manager (`orders_manager`)
* **INV-MGR:** Recommended Inventory / Warehouse Manager (`inventory_manager`)
* **CAT-MGR:** Recommended Product / Catalog Manager (`catalog_manager`)
* **SUP-AGT:** Recommended Customer Support Specialist (`support_agent`)
* **MKT-MGR:** Recommended Marketing Manager (`marketing_manager`)

*Permission Indicators:*
* **ALLOW (`Y`):** Full authorized access.
* **DENY (`N`):** Explicitly denied (HTTP 403 or blocked at service/route level).
* **VIEW (`RO`):** Read-only inspection (no mutating actions).
* **APPR (`AP`):** Secondary approval / Maker-Checker required.
* **OWN (`O`):** Limited to own records (e.g. own profile).

---

## 2. Comprehensive Master Administrative Permission Matrix

### 2.1 System Administration, Team Governance & Audit

| Capability Key | Description & Scope | CUR-ADM | CUR-STF | SUP-ADM | FIN-MGR | ORD-MGR | INV-MGR | CAT-MGR | SUP-AGT | MKT-MGR | Audit Status | Implementing File / Method / Table |
|---|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|---|---|
| `dashboard.view_executive` | Platform-wide financial KPIs, revenue, AOV, sales | **Y** | **Y** | **Y** | **N** | **N** | **N** | **N** | **N** | **N** | **TESTED** | `public/admin/index.php`, `DashboardService::data()` *(Currently leaked to staff!)* |
| `dashboard.view_finance` | Financial dashboard: collections, M-Pesa health | **Y** | **Y** | **Y** | **Y** | **N** | **N** | **N** | **N** | **N** | **INFERRED** | Proposed `public/admin/finance/index.php` |
| `dashboard.view_orders` | Fulfillment dashboard: dispatch queues, delays | **Y** | **Y** | **Y** | **N** | **Y** | **N** | **N** | **N** | **N** | **INFERRED** | Proposed `OrdersDashboardService::data()` |
| `dashboard.view_inventory` | Warehouse dashboard: stockouts, low-stock, velocity | **Y** | **Y** | **Y** | **N** | **N** | **Y** | **N** | **N** | **N** | **INFERRED** | Proposed `InventoryDashboardService::data()` |
| `dashboard.view_catalog` | Catalog dashboard: missing images, category gaps | **Y** | **Y** | **Y** | **N** | **N** | **N** | **Y** | **N** | **N** | **INFERRED** | Proposed `CatalogDashboardService::data()` |
| `dashboard.view_support` | Support dashboard: unread messages, pending reviews | **Y** | **Y** | **Y** | **N** | **N** | **N** | **N** | **Y** | **N** | **INFERRED** | Proposed `SupportDashboardService::data()` |
| `dashboard.view_marketing` | Marketing dashboard: coupon redemptions, discounts | **Y** | **Y** | **Y** | **N** | **N** | **N** | **N** | **N** | **Y** | **INFERRED** | Proposed `MarketingDashboardService::data()` |
| `staff.view` | View staff members list and profile summaries | **Y** | **N** | **Y** | **N** | **N** | **N** | **N** | **N** | **N** | **TESTED** | `public/admin/staff/index.php`, `StaffService::list()` |
| `staff.create` | Provision new staff accounts (secure token email) | **Y** | **N** | **Y** | **N** | **N** | **N** | **N** | **N** | **N** | **TESTED** | `StaffService::create()`, `users` table |
| `staff.edit` | Update staff details, names, phone numbers | **Y** | **N** | **Y** | **N** | **N** | **N** | **N** | **N** | **N** | **TESTED** | `StaffService::update()`, `users` table |
| `staff.assign_role` | Assign or alter departmental roles of staff members | **Y** | **N** | **Y** | **N** | **N** | **N** | **N** | **N** | **N** | **TESTED** | `StaffService::update()`, `users.role` |
| `staff.toggle_status` | Activate, suspend, or block staff accounts | **Y** | **N** | **Y** | **N** | **N** | **N** | **N** | **N** | **N** | **TESTED** | `StaffService::toggleStatus()`, `users.status` |
| `settings.view` | View store operational settings, identity, policies | **Y** | **N** | **Y** | **RO** | **RO** | **N** | **RO** | **RO** | **RO** | **TESTED** | `public/admin/settings/index.php`, `SettingService::getAll()` |
| `settings.manage` | Modify store profile, hours, contacts, policies | **Y** | **N** | **Y** | **N** | **N** | **N** | **N** | **N** | **N** | **TESTED** | `SettingService::updateStoreSettings()`, `settings` table |
| `audit.view_all` | View complete platform audit log and system events | **Y** | **N** | **Y** | **N** | **N** | **N** | **N** | **N** | **N** | **TESTED** | `public/admin/audit-log/index.php`, `audit_log` table |
| `audit.view_finance` | View financial transaction audit logs only | **Y** | **N** | **Y** | **Y** | **N** | **N** | **N** | **N** | **N** | **INFERRED** | Filtered `AuditLogService::list(['entity_type' => 'payment'])` |
| `profile.manage_own` | Edit own name and change own password | **Y** | **Y** | **O** | **O** | **O** | **O** | **O** | **O** | **O** | **TESTED** | `public/admin/profile.php`, `users` table |

---

### 2.2 Finance, Payments & Accounting

| Capability Key | Description & Scope | CUR-ADM | CUR-STF | SUP-ADM | FIN-MGR | ORD-MGR | INV-MGR | CAT-MGR | SUP-AGT | MKT-MGR | Audit Status | Implementing File / Method / Table |
|---|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|---|---|
| `finance.workspace` | Access to financial management workspace | **N** | **N** | **Y** | **Y** | **N** | **N** | **N** | **N** | **N** | **NOT FOUND** | Proposed `public/admin/finance/index.php` |
| `payments.view_all` | View all M-Pesa transactions, callbacks, amounts | **Y** | **N** | **Y** | **Y** | **N** | **N** | **N** | **N** | **N** | **CONFIRMED** | `payments` table (Currently only in order detail) |
| `payments.reconcile` | Reconcile Safaricom STK transactions vs bank | **N** | **N** | **Y** | **Y** | **N** | **N** | **N** | **N** | **N** | **NOT FOUND** | Proposed `FinanceService::reconcile()` |
| `payments.verify_stk` | Trigger Safaricom STK Push query to check status | **Y** | **Y** | **Y** | **Y** | **RO** | **N** | **N** | **N** | **N** | **TESTED** | `MpesaService::queryStkPush()` *(Leaked to staff!)* |
| `payments.mark_as_paid` | Manually mark order as paid with M-Pesa receipt | **Y** | **N** | **Y** | **Y** | **N** | **N** | **N** | **N** | **N** | **TESTED** | `OrderService::markAsPaid()` *(Hacked via orders.refund)* |
| `payments.export` | Export financial ledgers, cash flows, and payments | **N** | **N** | **Y** | **Y** | **N** | **N** | **N** | **N** | **N** | **NOT FOUND** | Proposed `FinanceService::exportCsv()` |
| `refunds.initiate` | Initiate a refund request for an order | **Y** | **N** | **Y** | **Y** | **Y** | **N** | **N** | **Y** | **N** | **INFERRED** | Proposed `RefundService::request()` |
| `refunds.approve_standard` | Approve refunds ≤ KES 10,000 | **Y** | **N** | **Y** | **Y** | **N** | **N** | **N** | **N** | **N** | **INFERRED** | `OrderService::recordRefund()` *(Requires separation)* |
| `refunds.approve_high_value` | Approve refunds > KES 10,000 | **Y** | **N** | **Y** | **AP** | **N** | **N** | **N** | **N** | **N** | **INFERRED** | Requires Super Admin Dual Authorization |
| `refunds.execute` | Record M-Pesa reversal code & update order status | **Y** | **N** | **Y** | **Y** | **N** | **N** | **N** | **N** | **N** | **TESTED** | `OrderService::recordRefund()`, `orders.status` |

---

### 2.3 Sales, Orders & Fulfillment

| Capability Key | Description & Scope | CUR-ADM | CUR-STF | SUP-ADM | FIN-MGR | ORD-MGR | INV-MGR | CAT-MGR | SUP-AGT | MKT-MGR | Audit Status | Implementing File / Method / Table |
|---|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|---|---|
| `orders.view` | View orders list, search by order number/phone | **Y** | **Y** | **Y** | **RO** | **Y** | **N** | **N** | **RO** | **RO** | **TESTED** | `public/admin/orders/index.php`, `OrderService::paginate()` |
| `orders.view_detail` | View order items, recipient details, tracking notes | **Y** | **Y** | **Y** | **RO** | **Y** | **N** | **N** | **RO** | **N** | **TESTED** | `public/admin/orders/detail.php`, `OrderService::find()` |
| `orders.view_financials` | View order unit prices, subtotals, discounts, totals | **Y** | **Y** | **Y** | **Y** | **Y** | **N** | **N** | **N** | **N** | **CONFIRMED** | `orders.subtotal`, `orders.total`, `orders.discount` |
| `orders.status_transition` | Advance status (`paid` → `processing` → `delivered`) | **Y** | **Y** | **Y** | **N** | **Y** | **N** | **N** | **N** | **N** | **TESTED** | `OrderService::transition()`, `order_status_history` |
| `orders.cancel` | Cancel order prior to dispatch & restore stock | **Y** | **Y** | **Y** | **N** | **Y** | **N** | **N** | **N** | **N** | **TESTED** | `OrderService::cancel()`, `orders.stock_released` |
| `orders.add_note` | Append internal timeline notes to an order | **Y** | **Y** | **Y** | **Y** | **Y** | **N** | **N** | **Y** | **N** | **TESTED** | `OrderService::addInternalNote()` |
| `orders.print_invoice` | Generate printable PDF/HTML order invoice | **Y** | **Y** | **Y** | **Y** | **Y** | **N** | **N** | **RO** | **N** | **TESTED** | `public/admin/orders/invoice.php` |
| `orders.print_packing_slip` | Generate printable packing slip for dispatch | **Y** | **Y** | **Y** | **N** | **Y** | **RO** | **N** | **N** | **N** | **TESTED** | `public/admin/orders/packing-slip.php` |
| `orders.flag_attention` | Set `attention_reason` on problematic orders | **Y** | **Y** | **Y** | **Y** | **Y** | **N** | **N** | **Y** | **N** | **CONFIRMED** | `orders.attention_reason` |

---

### 2.4 Catalog, Products & Merchandising

| Capability Key | Description & Scope | CUR-ADM | CUR-STF | SUP-ADM | FIN-MGR | ORD-MGR | INV-MGR | CAT-MGR | SUP-AGT | MKT-MGR | Audit Status | Implementing File / Method / Table |
|---|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|---|---|
| `products.view` | View product catalog, search by SKU/title | **Y** | **Y** | **Y** | **N** | **N** | **RO** | **Y** | **RO** | **RO** | **TESTED** | `public/admin/products/index.php`, `ProductService` |
| `products.create` | Create new products, title, slug, descriptions | **Y** | **Y** | **Y** | **N** | **N** | **N** | **Y** | **N** | **N** | **TESTED** | `public/admin/products/create.php` *(Gapped under edit)* |
| `products.edit` | Modify product title, descriptions, SEO metadata | **Y** | **Y** | **Y** | **N** | **N** | **N** | **Y** | **N** | **N** | **TESTED** | `ProductService::update()`, `products` table |
| `products.set_price` | Modify base selling price and compare-at price | **Y** | **Y** | **Y** | **N** | **N** | **N** | **Y** | **N** | **N** | **CONFIRMED** | `products.base_price`, `products.compare_at_price` |
| `products.toggle_active` | Toggle product storefront visibility (`is_active`) | **Y** | **Y** | **Y** | **N** | **N** | **N** | **Y** | **N** | **N** | **TESTED** | `public/admin/products/index.php?action=toggle` |
| `products.soft_delete` | Move product to trash (`deleted_at = NOW()`) | **Y** | **N** | **Y** | **N** | **N** | **N** | **Y** | **N** | **N** | **TESTED** | `ProductService::softDelete()`, `products` table |
| `products.restore` | Restore soft-deleted product from trash | **Y** | **N** | **Y** | **N** | **N** | **N** | **Y** | **N** | **N** | **TESTED** | `ProductService::restore()`, `products` table |
| `products.hard_delete` | Permanently purge product and variants from DB | **Y** | **N** | **Y** | **N** | **N** | **N** | **N** | **N** | **N** | **TESTED** | `ProductService::hardDelete()`, `products` table |
| `variants.manage` | Add, edit, or generate product variants (SKUs) | **Y** | **Y** | **Y** | **N** | **N** | **RO** | **Y** | **N** | **N** | **TESTED** | `VariantService::create()`, `VariantService::update()` |
| `variants.delete` | Delete variants without order transaction history | **Y** | **Y** | **Y** | **N** | **N** | **N** | **Y** | **N** | **N** | **TESTED** | `VariantService::delete()` *(Leaked under products.edit!)* |
| `images.manage` | Upload, optimize, reorder gallery images | **Y** | **Y** | **Y** | **N** | **N** | **N** | **Y** | **N** | **N** | **TESTED** | `ProductImageService::upload()`, `reorder()` |
| `images.delete` | Delete product images from disk and database | **Y** | **Y** | **Y** | **N** | **N** | **N** | **Y** | **N** | **N** | **TESTED** | `ProductImageService::delete()` *(Leaked under edit!)* |
| `categories.view` | List product categories | **Y** | **Y** | **Y** | **N** | **N** | **RO** | **Y** | **RO** | **RO** | **TESTED** | `public/admin/categories/index.php` |
| `categories.create` | Create new category name, slug, description | **Y** | **Y** | **Y** | **N** | **N** | **N** | **Y** | **N** | **N** | **TESTED** | `CategoryService::create()`, `categories` table |
| `categories.edit` | Modify category title, slug, active status | **Y** | **Y** | **Y** | **N** | **N** | **N** | **Y** | **N** | **N** | **TESTED** | `CategoryService::update()`, `categories` table |
| `categories.delete` | Delete category (checks for child products) | **Y** | **N** | **Y** | **N** | **N** | **N** | **N** | **N** | **N** | **TESTED** | `CategoryService::delete()`, `categories` table |

---

### 2.5 Inventory, Stock & Warehouse

| Capability Key | Description & Scope | CUR-ADM | CUR-STF | SUP-ADM | FIN-MGR | ORD-MGR | INV-MGR | CAT-MGR | SUP-AGT | MKT-MGR | Audit Status | Implementing File / Method / Table |
|---|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|---|---|
| `inventory.view` | View warehouse stock counts per variant/SKU | **Y** | **Y** | **Y** | **N** | **RO** | **Y** | **RO** | **N** | **N** | **TESTED** | `public/admin/inventory/index.php`, `InventoryService` |
| `inventory.adjust_restock` | Record stock intake/restock with supplier note | **Y** | **Y** | **Y** | **N** | **N** | **Y** | **N** | **N** | **N** | **TESTED** | `InventoryService::adjustStock('delta')` |
| `inventory.adjust_correction`| Correct stock discrepancies (`mode = 'set'`) | **Y** | **Y** | **Y** | **N** | **N** | **Y** | **N** | **N** | **N** | **TESTED** | `InventoryService::adjustStock('set')` |
| `inventory.manage_threshold` | Set low-stock reorder thresholds per variant | **Y** | **Y** | **Y** | **N** | **N** | **Y** | **RO** | **N** | **N** | **CONFIRMED** | `product_variants.low_stock_threshold` |
| `inventory.view_movements` | View immutable audit trail of inventory movements | **Y** | **Y** | **Y** | **N** | **RO** | **Y** | **N** | **N** | **N** | **TESTED** | `public/admin/inventory/movements.php` |

---

### 2.6 Customer Service, CRM & Reviews

| Capability Key | Description & Scope | CUR-ADM | CUR-STF | SUP-ADM | FIN-MGR | ORD-MGR | INV-MGR | CAT-MGR | SUP-AGT | MKT-MGR | Audit Status | Implementing File / Method / Table |
|---|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|---|---|
| `customers.view` | View registered customer directory and contacts | **Y** | **Y** | **Y** | **RO** | **RO** | **N** | **N** | **Y** | **N** | **TESTED** | `public/admin/customers/index.php`, `CustomerService` |
| `customers.view_detail` | View customer profile, saved addresses, orders | **Y** | **Y** | **Y** | **RO** | **RO** | **N** | **N** | **Y** | **N** | **TESTED** | `public/admin/customers/detail.php` |
| `customers.manage_status` | Block or unblock customer storefront accounts | **Y** | **N** | **Y** | **N** | **N** | **N** | **N** | **N** | **N** | **TESTED** | `CustomerService::setStatus()`, `users.status` |
| `messages.view` | View inbound contact inquiries and triage status | **Y** | **Y** | **Y** | **N** | **N** | **N** | **N** | **Y** | **N** | **TESTED** | `public/admin/messages/index.php`, `ContactMessageService`|
| `messages.reply` | Send email reply to customer inquiry via mailer | **Y** | **Y** | **Y** | **N** | **N** | **N** | **N** | **Y** | **N** | **TESTED** | `ContactMessageService::sendReply()`, `email_queue` |
| `messages.update_status` | Mark inquiry as `new`, `in_progress`, or `resolved` | **Y** | **Y** | **Y** | **N** | **N** | **N** | **N** | **Y** | **N** | **TESTED** | `ContactMessageService::updateStatus()` |
| `reviews.view` | View customer reviews and star ratings | **Y** | **Y** | **Y** | **N** | **N** | **N** | **RO** | **Y** | **N** | **TESTED** | `public/admin/reviews/index.php`, `ReviewService` |
| `reviews.moderate` | Approve or reject pending customer reviews | **Y** | **Y** | **Y** | **N** | **N** | **N** | **N** | **Y** | **N** | **TESTED** | `ReviewService::moderate()`, `reviews.status` |
| `reviews.delete` | Permanently delete spam or abusive reviews | **Y** | **N** | **Y** | **N** | **N** | **N** | **N** | **N** | **N** | **CONFIRMED** | `ReviewService::delete()` *(Hardcoded inline check)* |

---

### 2.7 Marketing, Coupons & Logistics

| Capability Key | Description & Scope | CUR-ADM | CUR-STF | SUP-ADM | FIN-MGR | ORD-MGR | INV-MGR | CAT-MGR | SUP-AGT | MKT-MGR | Audit Status | Implementing File / Method / Table |
|---|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|---|---|
| `coupons.view` | View discount coupons, usage counts, thresholds | **Y** | **N** | **Y** | **RO** | **N** | **N** | **RO** | **N** | **Y** | **TESTED** | `public/admin/coupons/index.php`, `CouponService` |
| `coupons.create` | Create percentage, fixed, or free delivery coupons | **Y** | **N** | **Y** | **N** | **N** | **N** | **N** | **N** | **Y** | **TESTED** | `CouponService::create()`, `coupons` table |
| `coupons.edit` | Update coupon validity dates, minimum spend, caps | **Y** | **N** | **Y** | **N** | **N** | **N** | **N** | **N** | **Y** | **TESTED** | `CouponService::update()`, `coupons` table |
| `coupons.toggle_active` | Activate or deactivate coupon campaigns | **Y** | **N** | **Y** | **N** | **N** | **N** | **N** | **N** | **Y** | **TESTED** | `CouponService::toggleActive()`, `coupons` table |
| `coupons.delete` | Delete expired coupon codes | **Y** | **N** | **Y** | **N** | **N** | **N** | **N** | **N** | **Y** | **TESTED** | `CouponService::delete()`, `coupons` table |
| `delivery.view` | View delivery zones, county mappings, pricing tiers | **Y** | **N** | **Y** | **RO** | **RO** | **N** | **N** | **RO** | **N** | **TESTED** | `public/admin/delivery/zones.php`, `pricing.php` |
| `delivery.manage_zones` | Create, edit, toggle, or delete delivery zones | **Y** | **N** | **Y** | **N** | **N** | **N** | **N** | **N** | **N** | **TESTED** | `DeliveryService::createZone()`, `delivery_zones` table|
| `delivery.manage_pricing`| Configure base fees, per-km rates, tier tables | **Y** | **N** | **Y** | **N** | **N** | **N** | **N** | **N** | **N** | **TESTED** | `SettingService::updateDeliveryPricing()` |

---

## 3. High-Risk Operations & Maker-Checker Dual-Authorization Table

Certain operations present severe financial, legal, or operational risks to the business and must **never** be executed by a single individual without an immutable audit trail or secondary authorization:

| Sensitive Operation | Risk Level | Primary Risk | Allowed Initiator | Required Approver | Enforced Safeguard |
|---|:---:|---|---|---|---|
| **High-Value Refund (> KES 10,000)** | **CRITICAL** | Direct monetary loss, collusion, unauthorized payout | `finance_manager` or `support_agent` | **`super_admin`** | Secondary approval required before reversal execution; immutable audit record with actor IDs. |
| **Standard Refund (≤ KES 10,000)** | **HIGH** | Inaccurate payout or unwarranted goods return | `support_agent` or `orders_manager` | **`finance_manager`** | Maker-checker rule: The initiator **cannot** approve/execute the refund. |
| **Manual Payment Verification (`mark_as_paid`)** | **HIGH** | False order clearance without funds receipt | `orders_manager` | **`finance_manager`** | Valid 10-char M-Pesa receipt verification check against Safaricom records. |
| **Large Stock Write-Down (> 100 units)** | **HIGH** | Masking theft, inventory sabotage, artificial stockout | `inventory_manager` | **`super_admin`** | Delta adjustment > 100 requires justification note and Super Admin sign-off. |
| **Staff Role Elevation / Account Creation** | **CRITICAL** | Privilege escalation, backdoor access creation | N/A (Direct) | **`super_admin`** Only | Passwordless token invitation; last active Super Admin cannot be deleted/demoted. |
| **Store Policy & Legal Content Edit** | **HIGH** | Regulatory non-compliance (Data Protection Act, 2019) | `catalog_manager` | **`super_admin`** Only | Versioned audit log diff recording previous and new policy texts. |
| **Permanent Product Hard Deletion** | **HIGH** | Accidental data loss, breaking historical order FKs | N/A (Direct) | **`super_admin`** Only | Soft deletion (`deleted_at`) is preferred default; hard delete strictly restricted. |
| **Permanent Category Deletion** | **MEDIUM** | Orphaned catalog products, broken navigation links | N/A (Direct) | **`super_admin`** Only | Pre-check ensuring zero products are assigned to the category before deletion. |

