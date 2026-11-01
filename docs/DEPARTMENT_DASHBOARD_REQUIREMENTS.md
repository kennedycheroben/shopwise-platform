# ShopWise — Department-Specific Dashboard Architecture & Data Governance

**Project:** ShopWise Kenya E-Commerce Platform  
**Repository:** `kennedycheroben/shopwise-platform`  
**Branch:** `maintenance/php-modernization-security-audit`  
**Document Status:** Architecture & Specification Document  
**Audience:** Frontend Engineers, Backend Architects, and Security Reviewers  

---

## 1. Deconstruction of the Existing Monolithic Dashboard

In the current ShopWise implementation, [app/src/Services/DashboardService.php](file:///opt/lampp/htdocs/shopwise-platform/app/src/Services/DashboardService.php) operates as a single, monolithic data provider. When any authenticated user accesses `/admin/index.php`, the controller unconditionally executes:

```php
$dashboard = DashboardService::data();
```

### 1.1 Data Exposed Unconditionally to All Staff
Every caller receiving the payload receives:
1. **Gross Revenue Metrics:**
   - `revenue_30`: Exact 30-day paid revenue sum in KES.
   - `revenue_30_trend`: Growth or contraction percentage vs previous 30 days.
   - `sales_today`: Real-time daily paid revenue.
   - `aov_30`: Average Order Value in KES.
2. **Historical Revenue Charts (`chartData()`):**
   - Monthly revenue series (last 12 months) down to two decimal places.
   - Weekly revenue series (last 12 weeks).
   - Daily revenue series (last 30 days).
3. **Category Revenue Breakdown:**
   - Categorical revenue distribution ranked from highest grossing to lowest.
4. **Top Products by Revenue:**
   - Top 10 products sorted by sales units and exact revenue generated in KES.
5. **Recent Orders Ledger:**
   - Last 10 customer orders including recipient names and order totals.
6. **Customer Growth Stats:**
   - `new_customers`: Total new customer accounts registered this month.

### 1.2 Vulnerability in JSON & AJAX Endpoints
The exposure is not limited to HTML rendering. Three distinct access paths expose this data:
- **`GET /admin/?format=json`:** In `public/admin/index.php` lines 10-14, passing `?format=json` immediately outputs the entire `$dashboard` array as JSON and exits.
- **`GET /admin/api/dashboard.php`:** Outputs `json_encode(DashboardService::data())` to any user authenticated via `guard.php`.
- **`GET /admin/api/revenue-chart.php`:** Outputs `json_encode(DashboardService::chartData($period))` to any user authenticated via `guard.php`.

**Security Consequence:** An employee hired solely for warehouse inventory counting or customer support ticket handling currently has instant, unmonitored access to the company's private financial revenues, sales trajectories, and top-selling revenue margins.

---

## 2. Role-Based Dashboard Architecture

To enforce strict data governance and eliminate information leakage, the monolithic `DashboardService` must be decomposed into **role-tailored dashboard viewmodels**. 

A user logging into `/admin/` will be routed to a dashboard designed exclusively for their departmental responsibilities. Server-side authorization must ensure that non-financial roles receive payloads completely devoid of revenue fields.

```
                      User Enters /admin/
                              │
                              ▼
                [app/views/admin/guard.php]
                 (Auth & Session Verified)
                              │
                              ▼
            [Determine Departmental Role from Session]
                              │
      ┌──────────────┬────────┼──────────────┬──────────────┐
      ▼              ▼        ▼              ▼              ▼
[Super Admin]    [Finance] [Orders]     [Inventory]     [Support / Marketing / Catalog]
Full Executive   Financial Fulfillment  Stock / Whse    Department-Specific
Oversight        Ledger    Throughput   Velocity        Action Workspaces
```

---

## 3. Detailed Departmental Dashboard Specifications

---

### 3.1 Super Admin Dashboard (Executive & Platform Governance)

* **Primary Users:** Business Owner, Managing Director, CTO, Lead Systems Administrator.
* **Core Objective:** Monitor overall platform health, gross business performance, cross-department throughput, system exceptions, and security alerts.
* **Top Metric Cards:**
  1. **Total Paid Revenue (30 Days):** KES sum with percentage trend vs previous 30 days.
  2. **Total Orders (30 Days):** Fulfillment volume and order completion rate.
  3. **Average Order Value (AOV):** Basket size trend.
  4. **Active Customer Accounts:** New registrations this month and active customer ratio.
* **Charts & Visualizations:**
  - **Revenue & Orders Overview (Dual Axis):** 12-month area chart for KES revenue alongside line chart for order volume.
  - **Department Throughput Radar:** Real-time task distribution (Pending Orders vs Unanswered Tickets vs Out-of-Stock SKUs).
* **Data Tables & Action Feeds:**
  - **Attention Orders Queue:** Orders flagged with payment discrepancies, stock allocation issues, or delayed shipping.
  - **Security & Audit Feed:** Recent administrative actions (staff role updates, failed logins, settings changes).
  - **Top Performing Categories & Products:** Gross revenue rankings.

---

### 3.2 Accounts / Finance Dashboard (Financial Health & Reconciliation)

* **Primary Users:** Chief Accountant, Finance Manager, Billing Officer.
* **Core Objective:** Reconcile inbound M-Pesa payments, track gateway success rates, monitor failed transactions, audit settlement balances, and process refunds.
* **Top Metric Cards:**
  1. **M-Pesa Collections (Today / 30 Days):** Total cleared M-Pesa receipts.
  2. **Pending Reconciliation Queue:** Transactions in `pending_verification` or `initiated` awaiting resolution.
  3. **STK Push Success Rate:** Percentage of initiated STK pushes that resulted in verified payment callbacks.
  4. **Refunds Issued (Month):** Total value and count of processed refunds.
* **Charts & Visualizations:**
  - **Daily Inbound Cash Flow (Last 30 Days):** Bar chart showing daily collections vs refunded amounts.
  - **M-Pesa Callback Status Distribution:** Pie/Donut chart (Success vs Timeout vs User Cancelled vs Insufficient Funds).
* **Data Tables & Action Feeds:**
  - **Unreconciled / Failed Payment Alerts:** List of orders where checkout request IDs failed or timed out, with a 1-click "Query Safaricom STK Status" action.
  - **Pending Refund Requests:** Queue of refund tickets initiated by Support/Orders awaiting verification and payout approval.
  - **Recent Verified Payments Ledger:** Latest M-Pesa receipts, phone numbers, merchant IDs, and timestamps.
* **Strict Exclusions:** No inventory stock manipulation controls, no catalog editing forms, no staff role controls.

---

### 3.3 Orders Manager Dashboard (Fulfillment Throughput & Dispatch)

* **Primary Users:** Fulfillment Supervisor, Logistics Coordinator, Dispatch Officers.
* **Core Objective:** Maximize order processing speed, eliminate dispatch bottlenecks, monitor delivery progress, and ensure on-time delivery.
* **Top Metric Cards:**
  1. **Orders Awaiting Processing (`paid`):** Number of paid orders ready for packing.
  2. **Orders Out for Delivery (`out_for_delivery`):** Active shipments currently in transit.
  3. **Delayed Shipments (> 48h):** Orders pending fulfillment beyond SLA threshold.
  4. **Orders Flagged for Attention:** Orders with address clarification or packaging questions.
* **Charts & Visualizations:**
  - **Order Pipeline Funnel:** Real-time breakdown: `paid` → `processing` → `out_for_delivery` → `delivered`.
  - **Delivery Logistics Split:** Bar chart comparing Doorstep Delivery vs In-Store Customer Pickup.
* **Data Tables & Action Feeds:**
  - **Ready-to-Pack Dispatch Queue:** Priority list sorted by order date, with quick actions to "Print Packing Slip", "Print Invoice", and "Mark Out for Delivery".
  - **County / Sub-County Regional Heatmap:** Active deliveries grouped by delivery zone (Nairobi Central, Kiambu, Machakos, Upcountry).
  - **Recent Operational Timeline:** Status history of recently dispatched orders.
* **Strict Exclusions:** **ZERO monetary revenue metrics.** No total platform revenues, no AOV, no financial profit figures.

---

### 3.4 Inventory Manager Dashboard (Stock Health & Warehouse Velocity)

* **Primary Users:** Warehouse Manager, Stock Controller, Inventory Auditor.
* **Core Objective:** Prevent stockouts, track physical inventory movements, manage reorder thresholds, and optimize warehouse replenishment.
* **Top Metric Cards:**
  1. **Out-of-Stock Variants (0 Units):** Immediate replenishment alerts.
  2. **Low-Stock Warnings (≤ Threshold):** SKUs approaching reorder point.
  3. **Total Physical Units in Warehouse:** Sum of all variant inventory.
  4. **Active Restock Movements (This Week):** Total intake quantity.
* **Charts & Visualizations:**
  - **Inventory Health Ratio:** Donut chart: Healthy Stock vs Low Stock vs Critical Out-of-Stock.
  - **Top Fast-Moving SKUs (By Unit Velocity):** Top 10 items leaving warehouse (units, not KES).
* **Data Tables & Action Feeds:**
  - **Reorder Priority Table:** List of variants below threshold, displaying SKU, product name, size/color, current count, and reorder threshold, with inline "Quick Restock" button.
  - **Recent Inventory Audit Feed:** Latest entries from `inventory_movements` (restocks, adjustments, cancellations).
* **Strict Exclusions:** No customer personal data (PII), no order billing totals, no company revenue figures, no catalog pricing controls.

---

### 3.5 Product / Catalog Manager Dashboard (Assortment & Data Quality)

* **Primary Users:** Catalog Specialist, Merchandiser, E-Commerce Content Editor.
* **Core Objective:** Maintain catalog completeness, high-quality product assets, accurate category hierarchies, and optimize merchandising.
* **Top Metric Cards:**
  1. **Total Published Products (`is_active = 1`):** Live catalog count.
  2. **Draft / Inactive Products:** Products awaiting publication.
  3. **Catalog Data Quality Issues:** Products missing primary images, descriptions, or categories.
  4. **Variants Awaiting Barcode / SKU Setup:** Incomplete variant records.
* **Charts & Visualizations:**
  - **Catalog Category Distribution:** Breakdown of products across active categories.
  - **Image Asset Health:** Ratio of products with rich galleries (3+ images) vs single-image products.
* **Data Tables & Action Feeds:**
  - **Catalog Action Required List:** Products with zero images, missing compare prices, or unassigned categories.
  - **Recently Updated Products:** Audit log of recent title, price, or description edits.
  - **New Product Reviews Feed:** Customer ratings and comments awaiting catalog quality review.
* **Strict Exclusions:** No order dispatch controls, no stock write-offs, no payment verification controls, no staff account management.

---

### 3.6 Customer Support Dashboard (Inquiries & Customer Sentiment)

* **Primary Users:** Customer Support Agents, Helpdesk Team, Review Moderators.
* **Core Objective:** Respond rapidly to customer inquiries, resolve pre-purchase and post-purchase questions, moderate reviews, and maintain high customer satisfaction.
* **Top Metric Cards:**
  1. **Unread Customer Inquiries (`new`):** Inbox backlog requiring immediate triage.
  2. **Inquiries in Progress:** Active tickets being handled.
  3. **Reviews Pending Moderation:** Customer reviews awaiting approval.
  4. **Average Response Time:** Speed of email resolution.
* **Charts & Visualizations:**
  - **Inbound Inquiry Trend (Last 14 Days):** Daily message volume by contact reason.
  - **Customer Review Rating Breakdown:** 5-star vs 4-star vs low-rating breakdown.
* **Data Tables & Action Feeds:**
  - **Urgent Customer Inbox Queue:** Unread messages with subject, sender email, and elapsed wait time, with 1-click "Reply" button.
  - **Pending Reviews Moderation Queue:** Customer review submissions with inline "Approve" and "Reject" buttons.
  - **Recent Order Status Quick-Search:** Search widget allowing instant order lookup by customer phone number or order number to provide delivery updates.
* **Strict Exclusions:** No ability to modify prices, no ability to execute refunds, no stock adjustment controls, no gross revenue visibility.

---

### 3.7 Marketing Manager Dashboard (Promotions & Campaign ROI)

* **Primary Users:** Marketing Lead, Growth Manager, Promotions Coordinator.
* **Core Objective:** Monitor promotional coupon performance, assess discount spending, optimize campaign timing, and drive conversion.
* **Top Metric Cards:**
  1. **Active Promotional Coupons:** Number of live campaigns.
  2. **Coupon Redemptions (This Month):** Total times coupons were applied at checkout.
  3. **Total Discount Spend (Month):** Aggregate KES value of granted discounts.
  4. **Cart Conversion Lift:** Percentage of orders utilizing promotional codes.
* **Charts & Visualizations:**
  - **Coupon Redemptions Over Time:** Line chart of daily coupon usage.
  - **Campaign Share Breakdown:** Donut chart of redemptions per coupon code.
* **Data Tables & Action Feeds:**
  - **Active Campaigns Performance Table:** Coupon code, discount type, times used, max usage limit, expiration countdown, and total discount granted.
  - **Top Converting Categories:** Categories with highest promotional velocity.
* **Strict Exclusions:** No customer PII access, no payment gateway or refund execution controls, no warehouse inventory adjustment access.

---

## 4. Technical Architecture & Secure API Design

### 4.1 Backend Service Decomposition
The existing monolithic `DashboardService` must be refactored into modular, role-authorized service methods:

```php
namespace App\Services;

final class DashboardService
{
    /**
     * Entry point: returns role-specific payload based on authenticated role.
     */
    public static function forRole(string $role): array
    {
        return match ($role) {
            'super_admin', 'admin' => self::getSuperAdminDashboard(),
            'finance_manager'      => self::getFinanceDashboard(),
            'orders_manager'       => self::getOrdersDashboard(),
            'inventory_manager'    => self::getInventoryDashboard(),
            'catalog_manager'      => self::getCatalogDashboard(),
            'support_agent'        => self::getSupportDashboard(),
            'marketing_manager'    => self::getMarketingDashboard(),
            default                => self::getRestrictedDashboard(),
        };
    }

    public static function getSuperAdminDashboard(): array { /* Executive metrics + full revenue */ }
    public static function getFinanceDashboard(): array { /* Payments, M-Pesa health, reconciliations */ }
    public static function getOrdersDashboard(): array { /* Fulfillment queues, dispatch, NO revenue */ }
    public static function getInventoryDashboard(): array { /* Stockouts, low stock, movements */ }
    public static function getCatalogDashboard(): array { /* Catalog health, draft products */ }
    public static function getSupportDashboard(): array { /* Inquiries, pending reviews */ }
    public static function getMarketingDashboard(): array { /* Coupons, campaigns, discount spend */ }
    private static function getRestrictedDashboard(): array { /* Minimal read-only fallback */ }
}
```

### 4.2 Secure AJAX & JSON Endpoints
1. **`public/admin/index.php`:**
   - Evaluates `$role = $currentUser['role']`.
   - Calls `DashboardService::forRole($role)`.
   - Loads the appropriate department view template (e.g. `views/admin/dashboards/finance.php`).
   - If `?format=json` is requested, it outputs **only** the sanitized, role-specific dataset.
2. **`public/admin/api/dashboard.php`:**
   - Enforces `Permissions::require('dashboard.view')`.
   - Returns JSON strictly filtered to the user's role.
3. **`public/admin/api/revenue-chart.php`:**
   - Enforces `Permissions::require('finance.view')` or `Permissions::require('dashboard.view_executive')`.
   - If a non-financial user (e.g. Orders Manager, Support Agent, Inventory Manager) requests this endpoint, the server responds with **HTTP 403 Forbidden**.

