# ShopWise Platform - Acceptance Testing & Quality Assurance Verification Report

## 1. Test Architecture & Strict Database Isolation

In compliance with `docs/TESTING_RULES.md`:
- **Working Database Protected:** `shopwise_db` was protected with pre- and post-test `CHECKSUM TABLE` across all 25 tables.
- **Dedicated Disposable Target:** All test suites executed exclusively against `shopwise_test`, recreated fresh from `schema.sql`, `seed.sql`, and `demo_orders.sql` before runs.
- **Filesystem Isolation:** Upload tests executed in git-ignored `public/uploads_test/`, leaving `public/uploads/` completely untouched (verified 2 -> 2 files).

---

## 2. Test Execution Summary

### 2.1 Final Test Run (PHP 8.5.4 CLI)

```
==============================================================================
                       TEST SUITE EXECUTION SUMMARY                           
==============================================================================
Test Suite / Script            | Passed       | Failed       | Status    
-------------------------------+--------------+--------------+------------
acceptance_2afix.sh            | 38           | 0            | PASS
acceptance_2b.sh               | 76           | 0            | PASS
acceptance_2c1.sh              | 81           | 0            | PASS
acceptance_2c2.sh              | 115          | 0            | PASS
acceptance_3a.sh               | 100          | 0            | PASS
acceptance_3b.sh               | 117          | 0            | PASS
acceptance_4.sh                | 122          | 0            | PASS
acceptance_5.sh                | 87           | 0            | PASS
check_stock_parity.py          | 66           | 0            | PASS
-------------------------------+--------------+--------------+------------
TOTAL                          | 802          | 0            | ALL PASS
==============================================================================
```

### 2.2 Suite Breakdown & Capabilities Verified

1. **`acceptance_2afix.sh` (Phase 2A - 38 tests):**
   - Admin authentication, logout, session regeneration.
   - Brute-force lockout (5 failed attempts cooldown).
   - Role restrictions: customer barred from admin login; staff barred from superadmin endpoints.
   - Protected folder access blocking (403/404).

2. **`acceptance_2b.sh` (Phase 2B - 76 tests):**
   - Catalog management (products, variants, categories).
   - Variant SKU generation, matrix uniqueness, and idempotency.
   - Image upload validation, GD re-encoding, EXIF sanitization, and thumbnail creation.
   - Concurrency safety under 20 parallel stock decrements.
   - SQLi probes, XSS escaping, CSRF verification, and audit trail logging.

3. **`acceptance_2c1.sh` (Phase 2C-1 - 81 tests):**
   - Order management and status transition state machine.
   - Optimistic concurrency control (stale-state detection).
   - Atomic cancellation and stock restoration.
   - Invoices, packing slips, customer address management, and password secrecy.

4. **`acceptance_2c2.sh` (Phase 2C-2 - 115 tests):**
   - Coupon lifecycle (creation, validation, limits, expiry, redemption).
   - Delivery zones and distance-based pricing calculations.
   - Product reviews moderation and customer messaging inbox.
   - Staff onboarding with passwordless token invitations.
   - Store settings validation and audit logging.

5. **`acceptance_3a.sh` (Phase 3A - 100 tests):**
   - Storefront catalog browsing, search filters, and sorting.
   - CartService session and cookie management.
   - Variant picker stock display and dynamic price calculation.

6. **`acceptance_3b.sh` (Phase 3B - 117 tests):**
   - Static informational pages (About, FAQ, Shipping, Returns, Terms, Privacy, Support).
   - Real-time reactivity to administrative settings changes.
   - Contact form validation, honeypot, fill-time checks, and IP rate limiting.
   - XML Sitemap generation and robots exclusion rules.

7. **`acceptance_4.sh` (Phase 4 - 122 tests):**
   - Customer account registration and email verification tokens.
   - Password reset workflow with token invalidation.
   - Google OAuth client simulation and account linking.
   - Wishlist toggle and move-to-cart operations.
   - Verified purchaser reviews and rating aggregates.

8. **`acceptance_5.sh` (Phase 5 - 87 tests):**
   - Live checkout validation and delivery quote calculations.
   - Kenyan whole-shilling rule enforcement (KES currency rounding).
   - Safaricom Daraja STK Push request construction and phone normalization.
   - STK Push query verification and callback processing idempotency.
   - Order expiration cron worker (`expire_orders.php`) with non-overlapping `flock`.
   - Late payment recovery after order expiry.

9. **`check_stock_parity.py` (Inventory Ledger Parity - 66 tests):**
   - Verifies the fundamental inventory invariant across all 66 product variants:
     $$\text{Live Stock} = \text{Seed Stock} + \sum (\text{inventory\_movements.change\_qty})$$
   - Result: 0 ledger discrepancies across all variants.

---

## 3. Database Checksum Verification Evidence

Both pre-test and post-test `CHECKSUM TABLE` commands were executed against `shopwise_db`:

```
Table                                | Pre Checksum   | Post Checksum  | Status    
-------------------------------------+----------------+----------------+------------
shopwise_db.addresses                | 0              | 0              | IDENTICAL
shopwise_db.audit_log                | 1487071137     | 1487071137     | IDENTICAL
shopwise_db.cart_items               | 71062615       | 71062615       | IDENTICAL
shopwise_db.carts                    | 3827388848     | 3827388848     | IDENTICAL
shopwise_db.categories               | 4109438359     | 4109438359     | IDENTICAL
shopwise_db.contact_messages         | 803014972      | 803014972      | IDENTICAL
shopwise_db.coupon_redemptions       | 0              | 0              | IDENTICAL
shopwise_db.coupons                  | 4023400530     | 4023400530     | IDENTICAL
shopwise_db.delivery_zones           | 1491918239     | 1491918239     | IDENTICAL
shopwise_db.email_queue              | 1539281789     | 1539281789     | IDENTICAL
shopwise_db.email_verifications      | 728705204      | 728705204      | IDENTICAL
shopwise_db.inventory_movements      | 4211027428     | 4211027428     | IDENTICAL
shopwise_db.login_attempts           | 2539568630     | 2539568630     | IDENTICAL
shopwise_db.order_items              | 2372213853     | 2372213853     | IDENTICAL
shopwise_db.order_status_history     | 2471667997     | 2471667997     | IDENTICAL
shopwise_db.orders                   | 3204982788     | 3204982788     | IDENTICAL
shopwise_db.password_resets          | 0              | 0              | IDENTICAL
shopwise_db.payments                 | 2481302938     | 2481302938     | IDENTICAL
shopwise_db.product_images           | 2491248259     | 2491248259     | IDENTICAL
shopwise_db.product_variants         | 2180645590     | 2180645590     | IDENTICAL
shopwise_db.products                 | 2515703477     | 2515703477     | IDENTICAL
shopwise_db.reviews                  | 2696613543     | 2696613543     | IDENTICAL
shopwise_db.settings                 | 1924009806     | 1924009806     | IDENTICAL
shopwise_db.users                    | 3957058604     | 3957058604     | IDENTICAL
shopwise_db.wishlists                | 0              | 0              | IDENTICAL
```

**Uploads Isolation:** `public/uploads` file count: Pre: 2, Post: 2 (**IDENTICAL**).
**Final Checksum Drift:** **0 tables modified, 0 files modified**.
