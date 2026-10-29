# ShopWise Kenya - Business Policy & Storefront Content Review

This document catalogues every business policy statement, legal commitment, operational timeframe, customer service promise, and storefront claim presented across public storefront pages. Each statement is mapped to the database setting, database table, or system behavior that controls it so the store owner can inspect, confirm, or adjust values before production launch.

---

## 1. Public Content Claims Audit & Verification (Task d2)

In accordance with user directive **d2**, every public storefront page was audited for factual claims not backed by the database, settings, or system behavior.

### Audited Keywords & Phrases
Searched across all files in `app/views/store/` and subdirectories for:
`"licensed"`, `"certified"`, `"guarantee"`, `"all 47"`, `"countrywide"`, `"nationwide"`, `"same-day"`, `"next-day"`, `"24/7"`, `"free"`, `"trusted"`, `"award"`, `"secure"`, `"ISO"`, `"official"`, courier brand names (`"Fargo"`, `"Wells Fargo"`, `"G4S"`, `"DHL"`), payment brands (`"Visa"`, `"Mastercard"`, `"debit"`), percentages (`"100%"`), and partnership claims (`"partnered"`).

### Audit Findings & Removals / Replacements
| File & Line | Original Unsupported Text | Problem Identified | Fix / Replacement Applied | Backing Data Source |
| :--- | :--- | :--- | :--- | :--- |
| `app/views/store/faq.php:34` | "We deliver to all 47 counties across Kenya... licensed courier partners such as Fargo Courier and Wells Fargo" | False claim: Delivery zones do not cover all 47 counties; no named courier partners. | Derived dynamically: lists active counties from `DeliveryService::getCounties()` (`Nairobi, Kiambu, Machakos & Kajiado, Other Major Towns`). | `delivery_zones` table (`is_active = 1`) |
| `app/views/store/about.php:32` | "serving customers across all 47 counties through certified courier networks" | False: store does not cover all 47 counties, no certified couriers named. | Replaced with "operating from Nairobi... essentials backed by fast dispatch, fair transparent pricing in Kenyan Shillings, and secure M-Pesa payment." | `settings` & `delivery_zones` |
| `app/views/store/about.php:57` | "Countrywide Delivery" | Unbacked claim of nationwide delivery. | Replaced with "Regional Delivery Zones" reflecting active delivery zones. | `delivery_zones` table |
| `app/views/store/partials/footer.php:80` | "Delivery across Kenya within 24-48 hours via licensed couriers" | Unbacked: no licensed couriers named, coverage is not universal. | Replaced with "Fulfillment and dispatch from our Nairobi CBD store across active delivery zones in 1 to 4 business days." | `delivery_zones` table |
| `app/views/store/home.php:235` | "Countrywide Delivery" | Universal coverage claim unsupported. | Replaced with "Regional Delivery Zones" (Same-day to 1 day in Nairobi; 1-4 business days across regional zones). | `delivery_zones` table |
| `app/views/store/home.php:243` | "Instant checkout via Safaricom M-Pesa or debit cards." | False: Card payments not implemented/supported; only M-Pesa. | Replaced with "Instant checkout via Safaricom M-Pesa STK Push." | M-Pesa system configuration |
| `app/views/store/home.php:249-250` | "<h5>Genuine Products</h5><p>100% authentic stock verified before stocking.</p>" | Unbacked percentage claim ("100% authentic"). | Replaced with "<h5>Quality Checked</h5><p>Carefully inspected stock with clear descriptions.</p>" | Catalog quality guidelines |
| `app/views/store/product.php:181` | "Countrywide Delivery" | Universal coverage claim unsupported. | Replaced with "Regional Delivery" (Nairobi & select regional towns). | `delivery_zones` table |
| `app/views/store/product.php:185` | "<span>100% Genuine</span>" | Unbacked percentage claim. | Replaced with "<span>Quality Checked</span>". | Catalog inspection |
| `app/views/store/product.php:189` | "M-Pesa / Cards" | False: Card payment not supported. | Replaced with "M-Pesa STK". | M-Pesa Daraja integration |
| `app/views/store/contact.php:143` | "Guaranteed response within 24 business hours" | Unbacked guarantee claim. | Replaced with "Target response within <?= $supportHours ?> business hours". | `support_response_hours` setting |
| `app/views/store/return-policy.php:59` | "coordinate parcel return via our partnered couriers" | Unbacked: no courier partnership established. | Replaced with "coordinate parcel return via courier services." | Customer courier dispatch |
| `app/views/store/partials/footer.php:85` | "100% Genuine Kenyan Stock" | Unbacked percentage claim. | Replaced with "Quality Checked Inventory". | Catalog inspection |
| `app/views/store/terms.php:122` | "issue a 100% refund via M-Pesa within 24 hours" | Colloquial percentage claim. | Replaced with "issue a full refund via M-Pesa within 24 hours". | System refund workflow |
| `app/views/store/terms.php:133` | "using our verified STK Push prompt or official Paybill number" | Unbacked "verified/official" adjectives. | Replaced with "using our automated STK Push prompt or store Paybill shortcode". | M-Pesa integration |
| `app/views/store/terms.php:139` | "Official transactions are conducted only through our automated storefront M-Pesa integration." | Unbacked "official" adjective. | Replaced with "Authorized store transactions are conducted only through our automated storefront M-Pesa integration." | System architecture |

---

## 2. Active Delivery Zones & Ground Truth

The delivery coverage of ShopWise Kenya is strictly derived from the `delivery_zones` database table. Currently, active zones consist of:

| Zone ID | County Name | Coverage Area | Fee (KES) | Est. Days (Min - Max) | Status |
| :--- | :--- | :--- | :--- | :--- | :--- |
| 1 | Nairobi | CBD & Upper Hill | 150.00 | 0 - 1 business days | Active (`is_active = 1`) |
| 2 | Nairobi | Westlands, Parklands, Kilimani, Kileleshwa, Lavington, Kangemi | 200.00 | 0 - 1 business days | Active (`is_active = 1`) |
| 3 | Nairobi | South B, South C, Langata, Karen | 300.00 | 1 - 1 business days | Active (`is_active = 1`) |
| 4 | Nairobi | Eastlands (Embakasi, Donholm, Umoja, Kayole) | 300.00 | 1 - 1 business days | Active (`is_active = 1`) |
| 5 | Nairobi | Kasarani, Roysambu, Ruaka, Ruiru, Rongai, Ngong | 350.00 | 1 - 2 business days | Active (`is_active = 1`) |
| 6 | Kiambu, Machakos & Kajiado | Kiambu Town, Thika, Machakos Town, Kitengela, Athi River | 450.00 | 1 - 2 business days | Active (`is_active = 1`) |
| 7 | Other Major Towns | Nakuru, Mombasa, Kisumu, Eldoret, Nyeri, Kisii (via Courier) | 600.00 | 2 - 4 business days | Active (`is_active = 1`) |

**Summary of County Coverage:**
1. **Nairobi County**: Complete coverage across 5 area tiers (KES 150 to KES 350, 0-2 business days).
2. **Neighboring Counties**: Kiambu, Machakos, Kajiado (KES 450, 1-2 business days).
3. **Regional Major Hubs**: Nakuru, Mombasa, Kisumu, Uasin Gishu (Eldoret), Nyeri, Kisii (KES 600, 2-4 business days).

Any reference across the storefront to "all 47 counties" or "nationwide" has been eliminated. All delivery statements are dynamically populated from `delivery_zones` or generalized as regional coverage.

---

## 3. All Remaining Business Statements & Their Data Sources

| Domain | Business Statement / Promise | Public Page(s) | Backing Data Source / Setting | Value in DB / Settings |
| :--- | :--- | :--- | :--- | :--- |
| **Returns** | Return window of 7 calendar days from delivery date | `/return-policy`, `/faq` | `settings.return_window_days` | `7` |
| **Refunds** | Refund inspection and disbursement timeline | `/return-policy`, `/faq` | `settings.refund_processing_days` | `7` |
| **Refunds** | Refunds disbursed directly via Safaricom M-Pesa | `/return-policy`, `/terms` | System Rule | M-Pesa B2C / STK Reversal |
| **Pickup** | Free physical order pickup at Moi Avenue, Nairobi CBD | `/shipping-info`, Header, Footer | `settings.pickup_address` | `Moi Avenue, Nairobi CBD` |
| **Shipping** | Free shipping on qualifying orders | `/shipping-info`, `/faq`, Header | `settings.free_delivery_threshold` | `5000.00` (KES) |
| **Shipping** | Distance-based motorcycle delivery base fee | `/shipping-info` | `settings.distance_base_fee` | `150.00` (KES) |
| **Shipping** | Distance-based rate per kilometer | `/shipping-info` | `settings.distance_per_km` | `30.00` (KES/km) |
| **Shipping** | Distance-based included base kilometers | `/shipping-info` | `settings.distance_included_km` | `3.00` (km) |
| **Shipping** | Distance-based road curvature factor | `/shipping-info` | `settings.distance_road_factor` | `1.30` |
| **Shipping** | Delivery fee minimum and maximum limits | `/shipping-info` | `settings.distance_min_fee`, `settings.distance_max_fee` | Min: 150.00, Max: 1500.00 |
| **Support** | Customer inquiry response window | `/contact`, `/support` | `settings.support_response_hours` | `24` (hours) |
| **Support** | Customer care phone & WhatsApp line | Header, Footer, `/contact` | `settings.support_phone` | `+254 700 000 000` |
| **Support** | Official customer care email | Header, Footer, `/contact` | `settings.support_email` | `support@kennedycheroben.co.ke` |
| **Support** | Business operating hours | Footer, `/contact`, `/support` | `settings.business_hours` | `Mon-Sat, 8:00 am - 6:00 pm EAT` |
| **Payment** | Single checkout payment settlement via M-Pesa | Storefront, Checkout | System Architecture | Safaricom M-Pesa STK Push |
| **Orders** | Unpaid pending order stock hold duration | `/terms`, Checkout | `settings.order_expiry_minutes` | `30` (minutes) |
| **Data** | Statutory tax and accounting record retention | `/privacy` | Tax Procedures Act, 2015 (Kenya) | 7 years |
| **Data** | Customer support messages retention | `/privacy` | Data retention policy | 2 years |
| **Cookies** | Cart cookie name and lifetime | `/privacy` | Cookie implementation | `shopwise_cart` (30 days) |
| **Cookies** | Session cookie name and lifetime | `/privacy` | PHP Session architecture | `PHPSESSID` (session) |
| **Legal** | Governing law and jurisdiction | `/terms` | Terms of service | Laws of the Republic of Kenya |

---

## 4. Phase 4: Customer Accounts, Authentication & Reviews Ground Truth

| Domain | Business Statement / Policy | Public Page(s) | Backing System Rule / Policy | Implementation Details |
| :--- | :--- | :--- | :--- | :--- |
| **Accounts** | Email verification token validity | `/verify-email`, `/register` | System Rule (`CustomerAuthService::VERIFICATION_EXPIRY_HOURS`) | 24 hours from issuance, single-use, SHA-256 hashed in database |
| **Accounts** | Password reset token validity | `/forgot-password`, `/reset-password` | System Rule (`CustomerAuthService::RESET_EXPIRY_MINUTES`) | 60 minutes from issuance, single-use, SHA-256 hashed |
| **Accounts** | Customer account retention & deletion | `/privacy`, `/account/profile` | Kenya Data Protection Act, 2019 | Retained during active account usage; deletion requests executed by contacting customer support |
| **Accounts** | Password complexity policy | `/register`, `/reset-password`, `/account/password` | System Rule (`CustomerAuthService::validatePasswordPolicy`) | 10 to 72 characters, at least 1 letter, 1 number, common passwords rejected |
| **Accounts** | Account rate limits | `/login`, `/forgot-password`, `/verify-email/resend` | System Rule (`login_attempts` table) | 5 failures / 15 min per email+IP, 20 failures / 15 min per IP |
| **Google Auth** | Google OAuth authentication & data use | `/login`, `/register`, `/privacy` | Google Identity Platform | Uses authorization code flow with PKCE, validates RS256 signature against Google JWKS |
| **Google Auth** | Pre-registration account takeover guard | Google Auth Callback | System Security Rule | Unverified accounts linking Google have password hash wiped and active sessions invalidated |
| **Wishlist** | Customer wishlist capacity | `/wishlist`, Product cards | System Rule (`WishlistService::MAX_ITEMS`) | Up to 200 items per customer; rate limit 60 actions/min |
| **Reviews** | Verified buyer review requirement | `/product/{slug}#tab-feedback` | System Rule (`ReviewService::canCustomerReview`) | Reviews allowed strictly for delivered or paid orders containing the item |
| **Reviews** | Review moderation & submission limit | `/product/{slug}#tab-feedback`, Admin Reviews | System Rule (`ReviewService::submitReview`) | 1 review per product per customer, status pending until approved, 5 submissions/hour |

