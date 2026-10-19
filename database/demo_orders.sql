-- ==============================================================================
-- DEVELOPMENT ONLY: DEMO ORDERS SEEDING SCRIPT
-- WARNING: NEVER IMPORT INTO PRODUCTION AND NEVER CALL FROM seed.sql
-- This script inserts 12 realistic orders for testing the Phase 2A admin dashboard.
-- ==============================================================================

USE `shopwise_db`;

START TRANSACTION;

-- Clean up any existing demo orders first to ensure idempotency
DELETE im FROM `inventory_movements` im 
  JOIN `orders` o ON o.id = im.order_id 
  WHERE o.order_number LIKE 'DEMO2A-%';

DELETE p FROM `payments` p 
  JOIN `orders` o ON o.id = p.order_id 
  WHERE o.order_number LIKE 'DEMO2A-%';

DELETE osh FROM `order_status_history` osh 
  JOIN `orders` o ON o.id = osh.order_id 
  WHERE o.order_number LIKE 'DEMO2A-%';

DELETE oi FROM `order_items` oi 
  JOIN `orders` o ON o.id = oi.order_id 
  WHERE o.order_number LIKE 'DEMO2A-%';

DELETE FROM `orders` WHERE `order_number` LIKE 'DEMO2A-%';

-- ------------------------------------------------------------------------------
-- 1. Insert 12 Realistic Orders Across Various Statuses and Dates
-- ------------------------------------------------------------------------------
INSERT INTO `orders` (
  `id`, `order_number`, `user_id`, `status`, `payment_status`,
  `subtotal`, `delivery_fee`, `discount`, `total`,
  `delivery_method`, `delivery_pricing_method`, `delivery_distance_km`,
  `recipient_name`, `phone`, `county`, `area`, `street_details`,
  `idempotency_token`, `expires_at`, `created_at`, `updated_at`
) VALUES
-- Order 1: Delivered (Paid, Zone delivery, Kilimani)
(101, 'DEMO2A-001', 1, 'delivered', 'paid', 3200.00, 200.00, 0.00, 3400.00,
 'delivery', 'zone', NULL, 'Wanjiku Mwangi', '254712345671', 'Nairobi', 'Kilimani', 'Argwings Kodhek Road, Apt 4B',
 'idem-demo-001', NULL, DATE_SUB(NOW(), INTERVAL 28 DAY), DATE_SUB(NOW(), INTERVAL 27 DAY)),

-- Order 2: Delivered (Paid, CBD Pickup, Brian Kiprono)
(102, 'DEMO2A-002', 2, 'delivered', 'paid', 4800.00, 0.00, 0.00, 4800.00,
 'pickup', 'pickup', NULL, 'Brian Kiprono', '254722345672', 'Nairobi', 'CBD', 'Moi Avenue Collection Counter',
 'idem-demo-002', NULL, DATE_SUB(NOW(), INTERVAL 24 DAY), DATE_SUB(NOW(), INTERVAL 23 DAY)),

-- Order 3: Cancelled (Customer cancelled, refunded, Westlands)
(103, 'DEMO2A-003', 3, 'cancelled', 'refunded', 2400.00, 200.00, 0.00, 2600.00,
 'delivery', 'zone', NULL, 'Kevin Otieno', '254733345673', 'Nairobi', 'Westlands', 'Rhapta Road, Court 12',
 'idem-demo-003', NULL, DATE_SUB(NOW(), INTERVAL 20 DAY), DATE_SUB(NOW(), INTERVAL 19 DAY)),

-- Order 4: Delivered (Paid, Distance-based courier delivery to Ruiru)
(104, 'DEMO2A-004', 4, 'delivered', 'paid', 4500.00, 350.00, 0.00, 4850.00,
 'delivery', 'distance', 8.50, 'Faith Muthoni', '254744345674', 'Kiambu', 'Ruiru', 'Eastern Bypass, Near Quickmart',
 'idem-demo-004', NULL, DATE_SUB(NOW(), INTERVAL 16 DAY), DATE_SUB(NOW(), INTERVAL 15 DAY)),

-- Order 5: Delivered (Paid, Zone delivery, Karen)
(105, 'DEMO2A-005', 5, 'delivered', 'paid', 3800.00, 300.00, 0.00, 4100.00,
 'delivery', 'zone', NULL, 'Emmanuel Kipchumba', '254755345675', 'Nairobi', 'Karen', 'Marula Lane, Gate 7',
 'idem-demo-005', NULL, DATE_SUB(NOW(), INTERVAL 12 DAY), DATE_SUB(NOW(), INTERVAL 11 DAY)),

-- Order 6: Expired (Unpaid timeout)
(106, 'DEMO2A-006', 6, 'expired', 'unpaid', 1650.00, 150.00, 0.00, 1800.00,
 'delivery', 'zone', NULL, 'Mercy Achieng', '254766345676', 'Nairobi', 'CBD & Upper Hill', 'Hospital Road',
 'idem-demo-006', DATE_SUB(NOW(), INTERVAL 9 DAY), DATE_SUB(NOW(), INTERVAL 9 DAY), DATE_SUB(NOW(), INTERVAL 9 DAY)),

-- Order 7: Delivered (Paid, Major town courier to Nakuru)
(107, 'DEMO2A-007', 2, 'delivered', 'paid', 5200.00, 600.00, 0.00, 5800.00,
 'delivery', 'zone', NULL, 'Brian Kiprono', '254722345672', 'Other Major Towns', 'Nakuru (via Courier)', 'Kenyatta Avenue, Nakuru CBD',
 'idem-demo-007', NULL, DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY)),

-- Order 8: Processing (Paid, Upper Hill)
(108, 'DEMO2A-008', 1, 'processing', 'paid', 3600.00, 150.00, 0.00, 3750.00,
 'delivery', 'zone', NULL, 'Wanjiku Mwangi', '254712345671', 'Nairobi', 'CBD & Upper Hill', 'Kenya Re Towers, 4th Floor',
 'idem-demo-008', NULL, DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY)),

-- Order 9: Out for Delivery (Paid, Parklands)
(109, 'DEMO2A-009', 3, 'out_for_delivery', 'paid', 2800.00, 200.00, 0.00, 3000.00,
 'delivery', 'zone', NULL, 'Kevin Otieno', '254733345673', 'Nairobi', 'Westlands', '3rd Parklands Avenue',
 'idem-demo-009', NULL, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),

-- Order 10: Paid (Paid today, awaiting fulfillment)
(110, 'DEMO2A-010', 4, 'paid', 'paid', 3950.00, 200.00, 0.00, 4150.00,
 'delivery', 'zone', NULL, 'Faith Muthoni', '254744345674', 'Nairobi', 'Westlands', 'Kileleshwa, Kandara Road',
 'idem-demo-010', NULL, NOW(), NOW()),

-- Order 11: Pending Payment (Recent checkout within expiry window)
(111, 'DEMO2A-011', 5, 'pending_payment', 'pending', 2600.00, 150.00, 0.00, 2750.00,
 'delivery', 'zone', NULL, 'Emmanuel Kipchumba', '254755345675', 'Nairobi', 'CBD & Upper Hill', 'Haile Selassie Avenue',
 'idem-demo-011', DATE_ADD(NOW(), INTERVAL 25 MINUTE), NOW(), NOW()),

-- Order 12: Paid (Today, CBD pickup)
(112, 'DEMO2A-012', 6, 'paid', 'paid', 1750.00, 0.00, 0.00, 1750.00,
 'pickup', 'pickup', NULL, 'Mercy Achieng', '254766345676', 'Nairobi', 'CBD', 'Store Pickup Counter',
 'idem-demo-012', NULL, NOW(), NOW());

-- ------------------------------------------------------------------------------
-- 2. Matching Order Items (Ensuring 5+ distinct products for Top Products widget)
-- ------------------------------------------------------------------------------
INSERT INTO `order_items` (`order_id`, `product_id`, `variant_id`, `product_name`, `size`, `color`, `sku`, `unit_price`, `quantity`, `line_total`, `created_at`) VALUES
-- Order 101: Denim Jacket (Prod 1, Var 2)
(101, 1, 2, 'Light Wash Denim Trucker Jacket', 'M', 'Light Indigo', 'WCL-DNM-LB-M', 3200.00, 1, 3200.00, DATE_SUB(NOW(), INTERVAL 28 DAY)),

-- Order 102: Leather High-Tops (Prod 11, Var 40)
(102, 11, 40, 'Classic Black Leather High-Top Sneakers', '41', 'Black / White', 'SHO-HTOP-BK-41', 4800.00, 1, 4800.00, DATE_SUB(NOW(), INTERVAL 24 DAY)),

-- Order 103: White Blouse (Prod 2, Var 6) - Cancelled
(103, 2, 6, 'Crisp White Tie-Front Cotton Blouse', 'M', 'Pure White', 'WCL-BLS-WH-M', 2400.00, 1, 2400.00, DATE_SUB(NOW(), INTERVAL 20 DAY)),

-- Order 104: Smart Watch Ultra (Prod 17, Var 57)
(104, 17, 57, 'Smart Watch Ultra with Fitness Tracking & GPS', '49mm', 'Alpine Orange', 'ELC-WCH-OR', 4500.00, 1, 4500.00, DATE_SUB(NOW(), INTERVAL 16 DAY)),

-- Order 105: Tan Leather Tote (Prod 13, Var 49)
(105, 13, 49, 'Handcrafted Tan Leather Shoulder Tote Bag', 'Standard', 'Tan Cognac', 'BAG-TOTE-TN', 3800.00, 1, 3800.00, DATE_SUB(NOW(), INTERVAL 12 DAY)),

-- Order 106: Polo Shirt (Prod 5, Var 15) - Expired
(106, 5, 15, 'Men''s Classic Pique Cotton Polo Shirt', 'M', 'Navy Blue', 'MCL-POLO-NV-M', 1650.00, 1, 1650.00, DATE_SUB(NOW(), INTERVAL 9 DAY)),

-- Order 107: Running Shoes (Prod 12, Var 46)
(107, 12, 46, 'Air Cushion Mesh Breathable Running Shoes', '42', 'White / Silver', 'SHO-AIR-WH-42', 5200.00, 1, 5200.00, DATE_SUB(NOW(), INTERVAL 7 DAY)),

-- Order 108: Skincare Set (Prod 21, Var 65)
(108, 21, 65, '3-Step Custom Daily Skincare Routine Set', 'Full Set', 'Indigo Blue', 'BTY-SET-3PC', 3600.00, 1, 3600.00, DATE_SUB(NOW(), INTERVAL 3 DAY)),

-- Order 109: Coffee Kettle (Prod 20, Var 63)
(109, 20, 63, 'Matte Cream Gooseneck Pour-Over Coffee Kettle', '850ml', 'Matte Cream', 'HOM-KTL-CR', 2800.00, 1, 2800.00, DATE_SUB(NOW(), INTERVAL 2 DAY)),

-- Order 110: Laptop Backpack (Prod 14, Var 51)
(110, 14, 51, 'Urban Waterproof Laptop Travel Backpack', '28L', 'Slate Charcoal', 'BAG-BPK-GR', 3950.00, 1, 3950.00, NOW()),

-- Order 111: Hoodie (Prod 6, Var 20) - Pending
(111, 6, 20, 'Heavyweight Cotton Fleece Pullover Hoodie', 'M', 'Heather Grey', 'MCL-HOD-GR-M', 2600.00, 1, 2600.00, NOW()),

-- Order 112: Sunglasses (Prod 15, Var 53)
(112, 15, 53, 'Tortoiseshell Round Frame UV400 Sunglasses', 'Standard', 'Amber Tortoise', 'ACC-SUN-TR', 1750.00, 1, 1750.00, NOW());

-- ------------------------------------------------------------------------------
-- 3. M-Pesa Payments (Sandbox Style Receipts)
-- ------------------------------------------------------------------------------
INSERT INTO `payments` (
  `order_id`, `method`, `status`, `amount`, `phone`,
  `merchant_request_id`, `checkout_request_id`, `mpesa_receipt`,
  `result_code`, `result_desc`, `created_at`
) VALUES
(101, 'mpesa', 'success', 3400.00, '254712345671', 'MR-DEMO-001', 'ws_demo_req_001', 'QGH7K2M9XP', 0, 'The service request is processed successfully.', DATE_SUB(NOW(), INTERVAL 28 DAY)),
(102, 'mpesa', 'success', 4800.00, '254722345672', 'MR-DEMO-002', 'ws_demo_req_002', 'QGH8L3N1YR', 0, 'The service request is processed successfully.', DATE_SUB(NOW(), INTERVAL 24 DAY)),
(103, 'mpesa', 'failed',  2600.00, '254733345673', 'MR-DEMO-003', 'ws_demo_req_003', NULL, 1032, 'Request cancelled by user.', DATE_SUB(NOW(), INTERVAL 20 DAY)),
(104, 'mpesa', 'success', 4850.00, '254744345674', 'MR-DEMO-004', 'ws_demo_req_004', 'QGH9M4P2ZS', 0, 'The service request is processed successfully.', DATE_SUB(NOW(), INTERVAL 16 DAY)),
(105, 'mpesa', 'success', 4100.00, '254755345675', 'MR-DEMO-005', 'ws_demo_req_005', 'QGJ1N5Q3AT', 0, 'The service request is processed successfully.', DATE_SUB(NOW(), INTERVAL 12 DAY)),
(107, 'mpesa', 'success', 5800.00, '254722345672', 'MR-DEMO-007', 'ws_demo_req_007', 'QGJ2P6R4BU', 0, 'The service request is processed successfully.', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(108, 'mpesa', 'success', 3750.00, '254712345671', 'MR-DEMO-008', 'ws_demo_req_008', 'QGJ3Q7S5CV', 0, 'The service request is processed successfully.', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(109, 'mpesa', 'success', 3000.00, '254733345673', 'MR-DEMO-009', 'ws_demo_req_009', 'QGJ4R8T6DW', 0, 'The service request is processed successfully.', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(110, 'mpesa', 'success', 4150.00, '254744345674', 'MR-DEMO-010', 'ws_demo_req_010', 'QGJ5S9U7EX', 0, 'The service request is processed successfully.', NOW()),
(112, 'mpesa', 'success', 1750.00, '254766345676', 'MR-DEMO-012', 'ws_demo_req_012', 'QGJ6T0V8FY', 0, 'The service request is processed successfully.', NOW());

-- ------------------------------------------------------------------------------
-- 4. Order Status History
-- ------------------------------------------------------------------------------
INSERT INTO `order_status_history` (`order_id`, `from_status`, `to_status`, `note`, `created_at`) VALUES
(101, NULL, 'pending_payment', 'Order placed by customer', DATE_SUB(NOW(), INTERVAL 28 DAY)),
(101, 'pending_payment', 'paid', 'M-Pesa payment confirmed (Receipt: QGH7K2M9XP)', DATE_SUB(NOW(), INTERVAL 28 DAY)),
(101, 'paid', 'delivered', 'Order delivered to customer in Kilimani', DATE_SUB(NOW(), INTERVAL 27 DAY)),

(102, NULL, 'pending_payment', 'Order placed for CBD collection', DATE_SUB(NOW(), INTERVAL 24 DAY)),
(102, 'pending_payment', 'paid', 'Payment received via Daraja STK Push', DATE_SUB(NOW(), INTERVAL 24 DAY)),
(102, 'paid', 'delivered', 'Collected at Moi Avenue counter', DATE_SUB(NOW(), INTERVAL 23 DAY)),

(103, NULL, 'pending_payment', 'Order submitted', DATE_SUB(NOW(), INTERVAL 20 DAY)),
(103, 'pending_payment', 'cancelled', 'Customer requested cancellation', DATE_SUB(NOW(), INTERVAL 19 DAY)),

(104, NULL, 'pending_payment', 'Order placed with distance courier delivery', DATE_SUB(NOW(), INTERVAL 16 DAY)),
(104, 'pending_payment', 'paid', 'Payment confirmed via M-Pesa', DATE_SUB(NOW(), INTERVAL 16 DAY)),
(104, 'paid', 'delivered', 'Delivered to Ruiru', DATE_SUB(NOW(), INTERVAL 15 DAY)),

(105, NULL, 'pending_payment', 'Order created', DATE_SUB(NOW(), INTERVAL 12 DAY)),
(105, 'pending_payment', 'paid', 'M-Pesa confirmed', DATE_SUB(NOW(), INTERVAL 12 DAY)),
(105, 'paid', 'delivered', 'Delivered to Karen address', DATE_SUB(NOW(), INTERVAL 11 DAY)),

(106, NULL, 'pending_payment', 'Checkout initiated', DATE_SUB(NOW(), INTERVAL 9 DAY)),
(106, 'pending_payment', 'expired', 'Order automatically expired after 30-minute unpaid timeout', DATE_SUB(NOW(), INTERVAL 9 DAY)),

(107, NULL, 'pending_payment', 'Order placed for Nakuru dispatch', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(107, 'pending_payment', 'paid', 'Payment confirmed', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(107, 'paid', 'delivered', 'Courier delivery completed in Nakuru', DATE_SUB(NOW(), INTERVAL 5 DAY)),

(108, NULL, 'pending_payment', 'Order placed', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(108, 'pending_payment', 'paid', 'Payment received', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(108, 'paid', 'processing', 'Order packed and assigned to rider', DATE_SUB(NOW(), INTERVAL 3 DAY)),

(109, NULL, 'pending_payment', 'Order placed', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(109, 'pending_payment', 'paid', 'Payment confirmed', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(109, 'paid', 'out_for_delivery', 'Dispatched with Nairobi delivery courier', DATE_SUB(NOW(), INTERVAL 1 DAY)),

(110, NULL, 'pending_payment', 'Order placed today', NOW()),
(110, 'pending_payment', 'paid', 'M-Pesa payment received', NOW()),

(111, NULL, 'pending_payment', 'Checkout initiated, awaiting customer STK PIN entry', NOW()),

(112, NULL, 'pending_payment', 'Pickup order placed today', NOW()),
(112, 'pending_payment', 'paid', 'M-Pesa STK payment confirmed', NOW());

-- ------------------------------------------------------------------------------
-- 5. Inventory Movements Consistent with Seed Stock
-- ------------------------------------------------------------------------------
INSERT INTO `inventory_movements` (`variant_id`, `change_qty`, `reason`, `order_id`, `note`, `created_at`) VALUES
(2,  -1, 'sale', 101, 'Deducted for demo order #DEMO2A-001', DATE_SUB(NOW(), INTERVAL 28 DAY)),
(40, -1, 'sale', 102, 'Deducted for demo order #DEMO2A-002', DATE_SUB(NOW(), INTERVAL 24 DAY)),
(6,  -1, 'sale', 103, 'Deducted for demo order #DEMO2A-003', DATE_SUB(NOW(), INTERVAL 20 DAY)),
(6,   1, 'cancel_restore', 103, 'Restored from cancelled demo order #DEMO2A-003', DATE_SUB(NOW(), INTERVAL 19 DAY)),
(57, -1, 'sale', 104, 'Deducted for demo order #DEMO2A-004', DATE_SUB(NOW(), INTERVAL 16 DAY)),
(49, -1, 'sale', 105, 'Deducted for demo order #DEMO2A-005', DATE_SUB(NOW(), INTERVAL 12 DAY)),
(15, -1, 'sale', 106, 'Deducted for demo order #DEMO2A-006', DATE_SUB(NOW(), INTERVAL 9 DAY)),
(15,  1, 'expiry_restore', 106, 'Restored from expired demo order #DEMO2A-006', DATE_SUB(NOW(), INTERVAL 9 DAY)),
(46, -1, 'sale', 107, 'Deducted for demo order #DEMO2A-007', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(65, -1, 'sale', 108, 'Deducted for demo order #DEMO2A-008', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(63, -1, 'sale', 109, 'Deducted for demo order #DEMO2A-009', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(51, -1, 'sale', 110, 'Deducted for demo order #DEMO2A-010', NOW()),
(20, -1, 'sale', 111, 'Reserved for demo order #DEMO2A-011', NOW()),
(53, -1, 'sale', 112, 'Deducted for demo order #DEMO2A-012', NOW());

-- ------------------------------------------------------------------------------
-- 6. Update Product Variants Stock to Reflect Demo Orders
-- ------------------------------------------------------------------------------
UPDATE `product_variants` pv
JOIN (
  SELECT `variant_id`, SUM(`change_qty`) AS `net_change`
  FROM `inventory_movements`
  WHERE `order_id` BETWEEN 101 AND 112
  GROUP BY `variant_id`
) im ON pv.id = im.variant_id
SET pv.stock_qty = pv.stock_qty + im.net_change;

-- 7. Mark Cancelled/Expired Orders with Stock Released
UPDATE `orders` SET `stock_released` = 1 WHERE `id` IN (103, 106);

COMMIT;
