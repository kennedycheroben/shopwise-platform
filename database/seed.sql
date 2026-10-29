-- ==============================================================================
-- ShopWise Kenya - Seed Data Script
-- Idempotent where reasonable, strictly realistic Kenyan commercial content
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- 1. Store Settings
-- ------------------------------------------------------------------------------
DELETE FROM `settings`;
INSERT INTO `settings` (`setting_key`, `setting_value`, `type`, `description`) VALUES
('store_name', 'ShopWise Kenya', 'string', 'Official trading name of the store'),
('store_tagline', 'Quality everyday essentials, delivered across Kenya', 'string', 'Marketing brand tagline'),
('support_email', 'support@kennedycheroben.co.ke', 'string', 'Primary customer care and support email address'),
('support_phone', '+254 700 000 000', 'string', 'Direct telephone and WhatsApp customer care line (placeholder to customize)'),
('pickup_address', 'Moi Avenue, Nairobi CBD (exact pickup point shared after order confirmation)', 'string', 'Store collection address for customer pick-up orders'),
('business_hours', 'Mon-Sat, 8:00 am - 6:00 pm EAT', 'string', 'Customer service and fulfillment operating hours'),
('store_latitude', '-1.286389', 'float', 'Nairobi Central Business District dispatch hub latitude'),
('store_longitude', '36.817223', 'float', 'Nairobi Central Business District dispatch hub longitude'),
('currency', 'KES', 'string', 'National currency code (Kenyan Shilling)'),
('free_delivery_threshold', '5000.00', 'float', 'Minimum order amount in KES for complimentary delivery'),
('order_expiry_minutes', '30', 'integer', 'Duration before unpaid pending orders are automatically cancelled and stock restored'),
('distance_base_fee', '150.00', 'float', 'Base rate in KES for distance-based courier delivery'),
('distance_per_km', '30.00', 'float', 'Incremental cost per kilometer beyond the included radius'),
('distance_included_km', '3.00', 'float', 'Kilometers included within the initial base fee'),
('distance_road_factor', '1.30', 'float', 'Multiplier applied to straight-line distance to estimate actual road travel'),
('distance_min_fee', '150.00', 'float', 'Minimum allowable delivery charge'),
('distance_max_fee', '1500.00', 'float', 'Maximum delivery cap for motorcycle courier deliveries'),
('distance_rounding', '10', 'integer', 'Rounding increment in KES for computed delivery charges'),
('low_stock_default', '5', 'integer', 'Default threshold for inventory low-stock alerts'),
('return_window_days', '7', 'integer', 'Standard window in days from delivery for customer return requests'),
('refund_processing_days', '7', 'integer', 'Business days required to inspect and disburse approved refunds'),
('support_response_hours', '24', 'integer', 'Target customer service response time in hours'),
('contact_form_notify_email', 'support@kennedycheroben.co.ke', 'string', 'Recipient email address for incoming storefront contact inquiries');

-- ------------------------------------------------------------------------------
-- 2. Delivery Zones
-- ------------------------------------------------------------------------------
DELETE FROM `delivery_zones`;
INSERT INTO `delivery_zones` (`county`, `area`, `fee`, `est_days_min`, `est_days_max`, `is_active`) VALUES
('Nairobi', 'CBD & Upper Hill', 150.00, 0, 1, 1),
('Nairobi', 'Westlands, Parklands, Kilimani, Kileleshwa, Lavington, Kangemi', 200.00, 0, 1, 1),
('Nairobi', 'South B, South C, Langata, Karen', 300.00, 1, 1, 1),
('Nairobi', 'Eastlands (Embakasi, Donholm, Umoja, Kayole)', 300.00, 1, 1, 1),
('Nairobi', 'Kasarani, Roysambu, Ruaka, Ruiru, Rongai, Ngong', 350.00, 1, 2, 1),
('Kiambu, Machakos & Kajiado', 'Kiambu Town, Thika, Machakos Town, Kitengela, Athi River', 450.00, 1, 2, 1),
('Other Major Towns', 'Nakuru, Mombasa, Kisumu, Eldoret, Nyeri, Kisii (via Courier)', 600.00, 2, 4, 1);

-- ------------------------------------------------------------------------------
-- 3. Categories
-- ------------------------------------------------------------------------------
DELETE FROM `categories`;
INSERT INTO `categories` (`id`, `parent_id`, `name`, `slug`, `description`, `image`, `sort_order`, `is_active`) VALUES
(1, NULL, 'Women''s Clothing', 'womens-clothing', 'Chic dresses, tailored jackets, crisp cotton blouses, and everyday casual tops designed for modern Kenyan women.', 'assets/img/product/product-7.webp', 1, 1),
(2, NULL, 'Men''s Clothing', 'mens-clothing', 'Sharp tailored blazers, classic polo shirts, breathable linens, and warm heavyweight hoodies for work and leisure.', 'assets/img/product/product-10.webp', 2, 1),
(3, NULL, 'Shoes', 'shoes', 'Premium leather high-tops, cushioned athletic running sneakers, and patent stiletto heels crafted for lasting comfort.', 'assets/img/product/product-8.webp', 3, 1),
(4, NULL, 'Bags & Accessories', 'bags-accessories', 'Handcrafted leather totes, waterproof commuter backpacks, polarized sunglasses, and structured fedora hats.', 'assets/img/product/product-1.webp', 4, 1),
(5, NULL, 'Electronics', 'electronics', 'High-performance smartwatches, studio headphones, and reliable mobile tech accessories built for seamless connectivity.', 'assets/img/product/product-elec-smartwatch.webp', 5, 1),
(6, NULL, 'Home & Decor', 'home-decor', 'Mid-century sculptural accent chairs, specialty pour-over coffee kettles, and modern interior decorative essentials.', 'assets/img/product/product-2.webp', 6, 1),
(7, NULL, 'Beauty & Personal Care', 'beauty-personal-care', 'Dermatologist-formulated daily skincare sets, gentle cleansers, and barrier-repairing moisturizers for radiant skin.', 'assets/img/product/product-beauty-skincare.webp', 7, 1);

-- ------------------------------------------------------------------------------
-- 4. Sample Customers (Passwords unusable; real Kenyan names & contacts)
-- ------------------------------------------------------------------------------
DELETE FROM `users` WHERE `role` = 'customer';
INSERT INTO `users` (`id`, `role`, `first_name`, `last_name`, `email`, `phone`, `password_hash`, `status`, `email_verified_at`, `created_at`) VALUES
(1, 'customer', 'Wanjiku', 'Mwangi', 'wanjiku.mwangi@example.co.ke', '254712345671', '!UNUSABLE_ACCOUNT_HASH_WANJIKU!', 'active', NOW(), NOW()),
(2, 'customer', 'Brian', 'Kiprono', 'brian.kiprono@example.co.ke', '254722345672', '!UNUSABLE_ACCOUNT_HASH_BRIAN!', 'active', NOW(), NOW()),
(3, 'customer', 'Kevin', 'Otieno', 'kevin.otieno@example.co.ke', '254733345673', '!UNUSABLE_ACCOUNT_HASH_KEVIN!', 'active', NOW(), NOW()),
(4, 'customer', 'Faith', 'Muthoni', 'faith.muthoni@example.co.ke', '254744345674', '!UNUSABLE_ACCOUNT_HASH_FAITH!', 'active', NOW(), NOW()),
(5, 'customer', 'Emmanuel', 'Kipchumba', 'emmanuel.kipchumba@example.co.ke', '254755345675', '!UNUSABLE_ACCOUNT_HASH_EMMANUEL!', 'active', NOW(), NOW()),
(6, 'customer', 'Mercy', 'Achieng', 'mercy.achieng@example.co.ke', '254766345676', '!UNUSABLE_ACCOUNT_HASH_MERCY!', 'active', NOW(), NOW());

-- ------------------------------------------------------------------------------
-- 5. Products, Images & Variants (21 Realistic Products across all categories)
-- ------------------------------------------------------------------------------
DELETE FROM `inventory_movements`;
DELETE FROM `product_variants`;
DELETE FROM `product_images`;
DELETE FROM `products`;

-- Product 1: Light Wash Denim Trucker Jacket
INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `short_description`, `description`, `base_price`, `compare_at_price`, `is_active`, `is_featured`, `meta_title`, `meta_description`) VALUES
(1, 1, 'Light Wash Denim Trucker Jacket', 'light-wash-denim-trucker-jacket',
'A versatile relaxed-fit trucker jacket crafted from 100% durable cotton denim with authentic washed fading. Features classic button flap chest pockets and adjustable waist tabs for effortless layering.',
'Cut from durable 12oz washed cotton denim, this casual trucker jacket brings timeless casual style to any wardrobe. It features a point collar, metal shank button closure, twin flap pockets, and deep welt side pockets. Ideal for chilly Nairobi evenings and weekend road trips, it pairs seamlessly over a floral dress or casual tee. Machine wash cold inside out with similar colors; line dry in the shade to preserve the light indigo hue.',
3200.00, 3800.00, 1, 1, 'Light Wash Denim Trucker Jacket | ShopWise Kenya', 'Shop women''s light wash denim trucker jacket in Nairobi. 100% cotton, relaxed fit, fast delivery across Kenya.');

INSERT INTO `product_images` (`product_id`, `color`, `path`, `alt_text`, `sort_order`, `is_primary`) VALUES
(1, 'Light Indigo', 'assets/img/product/product-7.webp', 'Light Wash Denim Trucker Jacket Front View', 1, 1);

INSERT INTO `product_variants` (`id`, `product_id`, `sku`, `size`, `color`, `color_hex`, `price_override`, `stock_qty`, `low_stock_threshold`, `is_active`) VALUES
(1, 1, 'WCL-DNM-LB-S', 'S', 'Light Indigo', '#93C5FD', NULL, 8, 4, 1),
(2, 1, 'WCL-DNM-LB-M', 'M', 'Light Indigo', '#93C5FD', NULL, 15, 5, 1),
(3, 1, 'WCL-DNM-LB-L', 'L', 'Light Indigo', '#93C5FD', NULL, 6, 3, 1),
(4, 1, 'WCL-DNM-LB-XL', 'XL', 'Light Indigo', '#93C5FD', NULL, 2, 2, 1);

-- Product 2: Crisp White Tie-Front Cotton Blouse
INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `short_description`, `description`, `base_price`, `compare_at_price`, `is_active`, `is_featured`, `meta_title`, `meta_description`) VALUES
(2, 1, 'Crisp White Tie-Front Cotton Blouse', 'crisp-white-tie-front-cotton-blouse',
'An elegant long-sleeve cotton shirt featuring an adjustable waist tie and relaxed spread collar. Perfect for transitioning effortlessly from corporate meetings in Upper Hill to dinner in Kilimani.',
'Tailored from breathable 100% poplin cotton, this contemporary blouse adds a fresh silhouette to smart-casual dressing. The front ties allow you to cinch the waist or wear it loosely over tailored trousers or pencil skirts. Built with reinforced mother-of-pearl buttons and clean French seams for enduring durability. Machine wash gentle cycle at 30°C and warm iron while damp for a crisp, smooth finish.',
2400.00, 2900.00, 1, 1, 'Crisp White Tie-Front Blouse | ShopWise Kenya', 'Buy women''s tie-front white cotton blouse online in Kenya. Breathable cotton poplin, stylish and comfortable.');

INSERT INTO `product_images` (`product_id`, `color`, `path`, `alt_text`, `sort_order`, `is_primary`) VALUES
(2, 'Pure White', 'assets/img/product/product-f-7.webp', 'Crisp White Tie-Front Cotton Blouse Model View', 1, 1);

INSERT INTO `product_variants` (`id`, `product_id`, `sku`, `size`, `color`, `color_hex`, `price_override`, `stock_qty`, `low_stock_threshold`, `is_active`) VALUES
(5, 2, 'WCL-BLS-WH-S', 'S', 'Pure White', '#FFFFFF', NULL, 12, 4, 1),
(6, 2, 'WCL-BLS-WH-M', 'M', 'Pure White', '#FFFFFF', NULL, 14, 5, 1),
(7, 2, 'WCL-BLS-WH-L', 'L', 'Pure White', '#FFFFFF', NULL, 4, 3, 1),
(8, 2, 'WCL-BLS-WH-XL', 'XL', 'Pure White', '#FFFFFF', NULL, 0, 2, 1);

-- Product 3: Vibrant Geometric Print Tie-Front Blouse
INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `short_description`, `description`, `base_price`, `compare_at_price`, `is_active`, `is_featured`, `meta_title`, `meta_description`) VALUES
(3, 1, 'Vibrant Geometric Print Tie-Front Blouse', 'vibrant-geometric-print-tie-front-blouse',
'A lightweight short-sleeve resort-style blouse featuring bold multi-color geometric patterns. Designed with a deep V-neckline and waist tie for a lively, sun-ready aesthetic.',
'Made from featherlight rayon fabric that drapes smoothly against the skin, this blouse keeps you cool under the Kenyan sun. Features short batwing sleeves and a tie-knot hem that accentuates high-rise trousers or denim shorts. The expressive graphic print stays vibrant wash after wash without fading. Hand wash or gentle machine wash cold, drip dry, and iron on low heat.',
1850.00, 2200.00, 1, 0, 'Vibrant Geometric Blouse | ShopWise Kenya', 'Shop colorful geometric short sleeve blouse in Nairobi with fast doorstep delivery.');

INSERT INTO `product_images` (`product_id`, `color`, `path`, `alt_text`, `sort_order`, `is_primary`) VALUES
(3, 'Multi-Color', 'assets/img/product/product-f-3.webp', 'Vibrant Geometric Print Blouse Model View', 1, 1);

INSERT INTO `product_variants` (`id`, `product_id`, `sku`, `size`, `color`, `color_hex`, `price_override`, `stock_qty`, `low_stock_threshold`, `is_active`) VALUES
(9, 3, 'WCL-GEO-ML-S', 'S', 'Multi-Color', '#E06A3B', NULL, 9, 3, 1),
(10, 3, 'WCL-GEO-ML-M', 'M', 'Multi-Color', '#E06A3B', NULL, 11, 4, 1),
(11, 3, 'WCL-GEO-ML-L', 'L', 'Multi-Color', '#E06A3B', NULL, 5, 2, 1);

-- Product 4: Knit Open-Front Cardigan & Tank Twinset
INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `short_description`, `description`, `base_price`, `compare_at_price`, `is_active`, `is_featured`, `meta_title`, `meta_description`) VALUES
(4, 1, 'Knit Open-Front Cardigan & Tank Twinset', 'knit-open-front-cardigan-tank-twinset',
'A sophisticated two-piece knit ensemble pairing an airy long-sleeve cardigan with a coordinating scoop-neck tank. Crafted from ultra-soft fine gauge cotton blend for lightweight warmth.',
'Designed for versatile trans-seasonal layering, this twinset combines a relaxed open-front cardigan with a patterned scoop-neck vest. The fine-gauge viscose-cotton knit provides gentle stretch while holding its shape after multiple wears. Wear the pieces together for a cohesive look or style the cardigan separately over dresses and camisoles. Hand wash cold with mild detergent, reshape and lay flat to dry to prevent stretching.',
2950.00, 3500.00, 1, 0, 'Knit Open-Front Cardigan Set | ShopWise Kenya', 'Comfortable 2-piece knit cardigan and tank set. Soft, breathable fabric available in Nairobi.');

INSERT INTO `product_images` (`product_id`, `color`, `path`, `alt_text`, `sort_order`, `is_primary`) VALUES
(4, 'Ivory Cream', 'assets/img/product/product-f-5.webp', 'Knit Open-Front Cardigan Twinset View', 1, 1);

INSERT INTO `product_variants` (`id`, `product_id`, `sku`, `size`, `color`, `color_hex`, `price_override`, `stock_qty`, `low_stock_threshold`, `is_active`) VALUES
(12, 4, 'WCL-CRD-IV-S', 'S', 'Ivory Cream', '#FDFBF7', NULL, 7, 3, 1),
(13, 4, 'WCL-CRD-IV-M', 'M', 'Ivory Cream', '#FDFBF7', NULL, 12, 4, 1),
(14, 4, 'WCL-CRD-IV-L', 'L', 'Ivory Cream', '#FDFBF7', NULL, 3, 2, 1);

-- Product 5: Men''s Classic Pique Cotton Polo Shirt
INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `short_description`, `description`, `base_price`, `compare_at_price`, `is_active`, `is_featured`, `meta_title`, `meta_description`) VALUES
(5, 2, 'Men''s Classic Pique Cotton Polo Shirt', 'mens-classic-pique-cotton-polo-shirt',
'A smart-casual wardrobe essential woven from 100% combed cotton pique with a three-button placket. Delivers structured comfort and breathability for workdays and weekend social events alike.',
'Woven from long-staple combed cotton, this short-sleeve polo features a ribbed collar that resists curling and twin-needle stitched hems for lasting resilience. The breathable pique knit promotes natural airflow, keeping you comfortable during warm afternoons in Nairobi and coastal travel. Features an embroidered minimalist bird crest on the left chest and contrast inner placket tape. Machine wash cold with like colors, tumble dry low or line dry in shade.',
1650.00, 2100.00, 1, 1, 'Men''s Classic Cotton Polo Shirt | ShopWise Kenya', 'Premium 100% combed cotton pique polo shirt in navy blue. Fast delivery across Kenya.');

INSERT INTO `product_images` (`product_id`, `color`, `path`, `alt_text`, `sort_order`, `is_primary`) VALUES
(5, 'Navy Blue', 'assets/img/product/product-10.webp', 'Men''s Classic Navy Polo Shirt Front View', 1, 1);

INSERT INTO `product_variants` (`id`, `product_id`, `sku`, `size`, `color`, `color_hex`, `price_override`, `stock_qty`, `low_stock_threshold`, `is_active`) VALUES
(15, 5, 'MCL-POLO-NV-M', 'M', 'Navy Blue', '#1E3A8A', NULL, 20, 5, 1),
(16, 5, 'MCL-POLO-NV-L', 'L', 'Navy Blue', '#1E3A8A', NULL, 18, 5, 1),
(17, 5, 'MCL-POLO-NV-XL', 'XL', 'Navy Blue', '#1E3A8A', NULL, 5, 3, 1),
(18, 5, 'MCL-POLO-NV-XXL', 'XXL', 'Navy Blue', '#1E3A8A', NULL, 2, 2, 1);

-- Product 6: Heavyweight Cotton Fleece Pullover Hoodie
INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `short_description`, `description`, `base_price`, `compare_at_price`, `is_active`, `is_featured`, `meta_title`, `meta_description`) VALUES
(6, 2, 'Heavyweight Cotton Fleece Pullover Hoodie', 'heavyweight-cotton-fleece-pullover-hoodie',
'An exceptionally cozy pullover hoodie crafted from 380gsm brushed-back cotton fleece. Equipped with a roomy double-layered hood, kangaroo pocket, and thick ribbed cuffs.',
'Engineered for cool mornings and relaxed weekends, this hoodie is cut from premium 380gsm cotton-rich fleece that delivers substantive warmth without unnecessary bulk. The kangaroo pouch pocket includes reinforced bar-tack stitching, while the hood features matching woven drawcords with dipped silicone tips. Pre-shrunk fabric ensures the garment preserves its proportions across repeated laundry cycles. Machine wash inside out with cold water and dry flat.',
2600.00, 3200.00, 1, 1, 'Heavyweight Cotton Pullover Hoodie | ShopWise Kenya', 'Warm 380gsm fleece hoodie in heather grey. High quality, comfortable fit.');

INSERT INTO `product_images` (`product_id`, `color`, `path`, `alt_text`, `sort_order`, `is_primary`) VALUES
(6, 'Heather Grey', 'assets/img/product/product-4.webp', 'Heavyweight Cotton Fleece Hoodie Front View', 1, 1);

INSERT INTO `product_variants` (`id`, `product_id`, `sku`, `size`, `color`, `color_hex`, `price_override`, `stock_qty`, `low_stock_threshold`, `is_active`) VALUES
(19, 6, 'MCL-HOD-GR-S', 'S', 'Heather Grey', '#9CA3AF', NULL, 8, 3, 1),
(20, 6, 'MCL-HOD-GR-M', 'M', 'Heather Grey', '#9CA3AF', NULL, 16, 5, 1),
(21, 6, 'MCL-HOD-GR-L', 'L', 'Heather Grey', '#9CA3AF', NULL, 14, 5, 1),
(22, 6, 'MCL-HOD-GR-XL', 'XL', 'Heather Grey', '#9CA3AF', NULL, 4, 2, 1);

-- Product 7: Tailored Slim-Fit Charcoal Wool-Blend Blazer
INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `short_description`, `description`, `base_price`, `compare_at_price`, `is_active`, `is_featured`, `meta_title`, `meta_description`) VALUES
(7, 2, 'Tailored Slim-Fit Charcoal Wool-Blend Blazer', 'tailored-slim-fit-charcoal-wool-blend-blazer',
'A sharp, modern single-breasted blazer constructed from a structured wool-blend fabric. Delivers clean lapel lines and a tapered cut for executive presentations and formal banquets.',
'Crafted with a modern slim profile, this blazer features notch lapels, dual rear vents, lightly padded shoulders, and four-button kissing cuff details. Full silky interior lining ensures frictionless movement over dress shirts and fine knitwear. Two interior jet pockets provide secure storage for a wallet, cardholder, or mobile phone. Dry clean only; steam gently to eliminate creases between wears.',
6800.00, 8500.00, 1, 1, 'Tailored Slim-Fit Charcoal Blazer | ShopWise Kenya', 'Men''s formal slim-fit charcoal blazer. Modern cut, wool-blend fabric.');

INSERT INTO `product_images` (`product_id`, `color`, `path`, `alt_text`, `sort_order`, `is_primary`) VALUES
(7, 'Charcoal Grey', 'assets/img/product/product-m-3.webp', 'Tailored Slim-Fit Charcoal Blazer Model View', 1, 1);

INSERT INTO `product_variants` (`id`, `product_id`, `sku`, `size`, `color`, `color_hex`, `price_override`, `stock_qty`, `low_stock_threshold`, `is_active`) VALUES
(23, 7, 'MCL-BLZ-CH-38', '38R', 'Charcoal Grey', '#374151', NULL, 6, 2, 1),
(24, 7, 'MCL-BLZ-CH-40', '40R', 'Charcoal Grey', '#374151', NULL, 9, 3, 1),
(25, 7, 'MCL-BLZ-CH-42', '42R', 'Charcoal Grey', '#374151', NULL, 7, 3, 1),
(26, 7, 'MCL-BLZ-CH-44', '44R', 'Charcoal Grey', '#374151', NULL, 1, 2, 1);

-- Product 8: Men''s Classic Camel Wool-Blend Overcoat
INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `short_description`, `description`, `base_price`, `compare_at_price`, `is_active`, `is_featured`, `meta_title`, `meta_description`) VALUES
(8, 2, 'Men''s Classic Camel Wool-Blend Overcoat', 'mens-classic-camel-wool-blend-overcoat',
'An iconic tailored mid-length coat woven from warm camel-hued wool blend with a notch collar. An enduring statement piece designed to elevate business and evening attire.',
'This timeless overcoat is constructed with a 60% wool blend for superior thermal insulation against cold weather and evening breezes. Designed with a structured single-breasted front placket, horn-effect buttons, and deep fleece-lined diagonal pockets. The satin interior lining allows the coat to glide smoothly over tailored jackets and suits. Dry clean recommended to preserve the fabric''s rich texture and tailored drape.',
9200.00, 11500.00, 1, 0, 'Men''s Camel Wool Overcoat | ShopWise Kenya', 'Classic tailored camel wool overcoat for men. Superior warmth and executive elegance.');

INSERT INTO `product_images` (`product_id`, `color`, `path`, `alt_text`, `sort_order`, `is_primary`) VALUES
(8, 'Camel Tan', 'assets/img/product/product-m-4.webp', 'Men''s Classic Camel Overcoat Model View', 1, 1);

INSERT INTO `product_variants` (`id`, `product_id`, `sku`, `size`, `color`, `color_hex`, `price_override`, `stock_qty`, `low_stock_threshold`, `is_active`) VALUES
(27, 8, 'MCL-COAT-CM-40', '40R', 'Camel Tan', '#C19A6B', NULL, 5, 2, 1),
(28, 8, 'MCL-COAT-CM-42', '42R', 'Camel Tan', '#C19A6B', NULL, 6, 2, 1),
(29, 8, 'MCL-COAT-CM-44', '44R', 'Camel Tan', '#C19A6B', NULL, 3, 1, 1);

-- Product 9: Windowpane Check Taupe Two-Piece Suit
INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `short_description`, `description`, `base_price`, `compare_at_price`, `is_active`, `is_featured`, `meta_title`, `meta_description`) VALUES
(9, 2, 'Windowpane Check Taupe Two-Piece Suit', 'windowpane-check-taupe-two-piece-suit',
'A refined two-piece formal suit featuring a subtle windowpane check pattern on rich taupe fabric. Includes a tailored two-button jacket and matching flat-front trousers.',
'Precision-cut for the discerning gentleman, this suit combines a subtle geometric check weave with soft shoulder construction for all-day comfort during corporate functions and weddings. The jacket offers functional flap pockets, a ticket pocket, and inner pen sleeve, while the trousers feature a zip fly with hook-and-bar closure. Tailored from crease-resistant poly-viscose that maintains a sharp crease throughout busy schedules. Dry clean only.',
12500.00, 14800.00, 1, 1, 'Windowpane Check Taupe Suit | ShopWise Kenya', 'Gentlemen''s windowpane check tailored suit in taupe. Includes jacket and matching trousers.');

INSERT INTO `product_images` (`product_id`, `color`, `path`, `alt_text`, `sort_order`, `is_primary`) VALUES
(9, 'Taupe Check', 'assets/img/product/product-m-17.webp', 'Windowpane Check Taupe Suit Model View', 1, 1);

INSERT INTO `product_variants` (`id`, `product_id`, `sku`, `size`, `color`, `color_hex`, `price_override`, `stock_qty`, `low_stock_threshold`, `is_active`) VALUES
(30, 9, 'MCL-SUIT-TP-38', '38R', 'Taupe Check', '#B5A895', NULL, 4, 2, 1),
(31, 9, 'MCL-SUIT-TP-40', '40R', 'Taupe Check', '#B5A895', NULL, 6, 2, 1),
(32, 9, 'MCL-SUIT-TP-42', '42R', 'Taupe Check', '#B5A895', NULL, 3, 2, 1),
(33, 9, 'MCL-SUIT-TP-44', '44R', 'Taupe Check', '#B5A895', NULL, 0, 1, 1);

-- Product 10: Nude Patent Pointed-Toe Stiletto Pumps
INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `short_description`, `description`, `base_price`, `compare_at_price`, `is_active`, `is_featured`, `meta_title`, `meta_description`) VALUES
(10, 3, 'Nude Patent Pointed-Toe Stiletto Pumps', 'nude-patent-pointed-toe-stiletto-pumps',
'High-shine patent leather stiletto pumps featuring a classic pointed toe and an 85mm slender heel. Engineered with dual-layer memory foam insoles for elevated comfort.',
'Step out in confidence with these quintessential pointed-toe pumps, finished in an ultra-glossy nude beige patent finish that elongates the leg. Designed with cushioned footbeds, non-slip rubber soles, and balanced heel placement to minimize foot fatigue during extended wear. Suitable for weddings, corporate conferences, and high-profile evening dinners. Wipe clean with a soft damp microfiber cloth and store in the included dust bag.',
4200.00, 4900.00, 1, 1, 'Nude Patent Stiletto Pumps | ShopWise Kenya', 'Shop women''s glossy nude stiletto heels in Kenya. Memory foam insole, 85mm heel.');

INSERT INTO `product_images` (`product_id`, `color`, `path`, `alt_text`, `sort_order`, `is_primary`) VALUES
(10, 'Nude Beige', 'assets/img/product/product-5.webp', 'Nude Patent Pointed-Toe Pumps Top View', 1, 1);

INSERT INTO `product_variants` (`id`, `product_id`, `sku`, `size`, `color`, `color_hex`, `price_override`, `stock_qty`, `low_stock_threshold`, `is_active`) VALUES
(34, 10, 'SHO-PUMP-ND-37', '37', 'Nude Beige', '#E8D3C3', NULL, 5, 2, 1),
(35, 10, 'SHO-PUMP-ND-38', '38', 'Nude Beige', '#E8D3C3', NULL, 8, 3, 1),
(36, 10, 'SHO-PUMP-ND-39', '39', 'Nude Beige', '#E8D3C3', NULL, 7, 3, 1),
(37, 10, 'SHO-PUMP-ND-40', '40', 'Nude Beige', '#E8D3C3', NULL, 3, 2, 1),
(38, 10, 'SHO-PUMP-ND-41', '41', 'Nude Beige', '#E8D3C3', NULL, 2, 1, 1);

-- Product 11: Classic Black Leather High-Top Sneakers
INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `short_description`, `description`, `base_price`, `compare_at_price`, `is_active`, `is_featured`, `meta_title`, `meta_description`) VALUES
(11, 3, 'Classic Black Leather High-Top Sneakers', 'classic-black-leather-high-top-sneakers',
'Timeless high-top street sneakers featuring full-grain black leather uppers and a vulcanized white rubber cupsole. Accented with contrast red racing stripe detailing and metal eyelets.',
'Blending retro basketball silhouette with premium everyday durability, these high-top sneakers feature supple leather uppers that age gracefully with wear. Built with reinforced double stitching, a padded ankle collar, and a grippy waffle tread for dependable traction on pavement. A cushioned EVA sockliner absorbs impact during daily urban commutes around Nairobi. Clean with a leather cleaner and soft brush; do not machine wash.',
4800.00, 5600.00, 1, 1, 'Black Leather High-Top Sneakers | ShopWise Kenya', 'Iconic black leather high-top sneakers in Nairobi. Durable vulcanized rubber sole.');

INSERT INTO `product_images` (`product_id`, `color`, `path`, `alt_text`, `sort_order`, `is_primary`) VALUES
(11, 'Black / White', 'assets/img/product/product-8.webp', 'Classic Black Leather High-Top Sneaker Profile View', 1, 1);

INSERT INTO `product_variants` (`id`, `product_id`, `sku`, `size`, `color`, `color_hex`, `price_override`, `stock_qty`, `low_stock_threshold`, `is_active`) VALUES
(39, 11, 'SHO-HTOP-BK-40', '40', 'Black / White', '#1C1C1C', NULL, 6, 2, 1),
(40, 11, 'SHO-HTOP-BK-41', '41', 'Black / White', '#1C1C1C', NULL, 10, 3, 1),
(41, 11, 'SHO-HTOP-BK-42', '42', 'Black / White', '#1C1C1C', NULL, 12, 4, 1),
(42, 11, 'SHO-HTOP-BK-43', '43', 'Black / White', '#1C1C1C', NULL, 5, 2, 1),
(43, 11, 'SHO-HTOP-BK-44', '44', 'Black / White', '#1C1C1C', NULL, 2, 1, 1);

-- Product 12: Air Cushion Mesh Breathable Running Shoes
INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `short_description`, `description`, `base_price`, `compare_at_price`, `is_active`, `is_featured`, `meta_title`, `meta_description`) VALUES
(12, 3, 'Air Cushion Mesh Breathable Running Shoes', 'air-cushion-mesh-breathable-running-shoes',
'High-performance featherlight running shoes with engineered honeycomb mesh and responsive heel air unit. Offers optimum shock absorption and ventilation for morning jogs and gym sessions.',
'Designed for active runners and fitness enthusiasts, these sneakers boast a multi-zone engineered knit upper that maximizes airflow to keep feet cool and dry. The full-length phylon midsole is paired with an exposed heel air chamber that returns energy with every stride. Molded TPU side cages lock the midfoot securely in place during rapid direction changes. Hand wash with mild soap and air dry away from direct sunlight.',
5200.00, 6400.00, 1, 0, 'Air Cushion Mesh Running Shoes | ShopWise Kenya', 'Lightweight breathable mesh athletic running sneakers. Heel air cushioning for impact protection.');

INSERT INTO `product_images` (`product_id`, `color`, `path`, `alt_text`, `sort_order`, `is_primary`) VALUES
(12, 'White / Silver', 'assets/img/product/product-12.webp', 'Air Cushion Running Shoes Side View', 1, 1);

INSERT INTO `product_variants` (`id`, `product_id`, `sku`, `size`, `color`, `color_hex`, `price_override`, `stock_qty`, `low_stock_threshold`, `is_active`) VALUES
(44, 12, 'SHO-AIR-WH-40', '40', 'White / Silver', '#F3F4F6', NULL, 4, 2, 1),
(45, 12, 'SHO-AIR-WH-41', '41', 'White / Silver', '#F3F4F6', NULL, 8, 3, 1),
(46, 12, 'SHO-AIR-WH-42', '42', 'White / Silver', '#F3F4F6', NULL, 10, 3, 1),
(47, 12, 'SHO-AIR-WH-43', '43', 'White / Silver', '#F3F4F6', NULL, 6, 2, 1),
(48, 12, 'SHO-AIR-WH-44', '44', 'White / Silver', '#F3F4F6', NULL, 3, 1, 1);

-- Product 13: Handcrafted Tan Leather Shoulder Tote Bag
INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `short_description`, `description`, `base_price`, `compare_at_price`, `is_active`, `is_featured`, `meta_title`, `meta_description`) VALUES
(13, 4, 'Handcrafted Tan Leather Shoulder Tote Bag', 'handcrafted-tan-leather-shoulder-tote-bag',
'A spacious everyday carryall crafted from genuine pebble-grain cowhide leather with sturdy shoulder straps. Features a secure top snap closure and interior zip compartment for valuables.',
'Beautifully crafted by skilled leatherworkers, this generous shoulder tote combines understated luxury with functional day-to-day utility. The roomy interior easily accommodates a 14-inch laptop, planner, water bottle, and makeup pouch, supported by reinforced flat base corners. Solid antique brass hardware and thick contrast stitching ensure years of reliable service. Treat periodically with leather conditioner to maintain moisture and patina.',
3800.00, 4500.00, 1, 1, 'Handcrafted Tan Leather Shoulder Tote | ShopWise Kenya', 'Genuine pebble leather shoulder tote bag for women. Handcrafted in Kenya.');

INSERT INTO `product_images` (`product_id`, `color`, `path`, `alt_text`, `sort_order`, `is_primary`) VALUES
(13, 'Tan Cognac', 'assets/img/product/product-1.webp', 'Handcrafted Tan Leather Shoulder Tote Front View', 1, 1),
(13, 'Tan Cognac', 'assets/img/product/product-details-4.webp', 'Tan Leather Tote Interior View', 2, 0),
(13, 'Tan Cognac', 'assets/img/product/product-details-6.webp', 'Tan Leather Tote Carried on Shoulder', 3, 0);

INSERT INTO `product_variants` (`id`, `product_id`, `sku`, `size`, `color`, `color_hex`, `price_override`, `stock_qty`, `low_stock_threshold`, `is_active`) VALUES
(49, 13, 'BAG-TOTE-TN', 'Standard', 'Tan Cognac', '#B87333', NULL, 14, 4, 1),
(50, 13, 'BAG-TOTE-BK', 'Standard', 'Onyx Black', '#1A1A1A', NULL, 8, 3, 1);

-- Product 14: Urban Waterproof Laptop Travel Backpack
INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `short_description`, `description`, `base_price`, `compare_at_price`, `is_active`, `is_featured`, `meta_title`, `meta_description`) VALUES
(14, 4, 'Urban Waterproof Laptop Travel Backpack', 'urban-waterproof-laptop-travel-backpack',
'A rugged yet streamlined 28L tech commuter backpack made from water-resistant 900D Oxford nylon. Includes a dedicated padded compartment fitting laptops up to 15.6 inches.',
'Engineered for daily commuters and travelers navigating bustling streets, this backpack protects electronics with thick water-repellent ballistic nylon and sealed weather-resistant zippers. Features an ergonomic air-mesh back panel, adjustable sternum strap, dual quick-access side pockets, and an integrated luggage strap for carry-on rolling bags. Multiple internal organizer slots keep cables, pens, keys, and battery packs orderly. Spot clean with warm water and mild detergent.',
3950.00, 4800.00, 1, 1, 'Urban Waterproof Laptop Backpack | ShopWise Kenya', 'Water-resistant 28L commuter laptop backpack with padded 15.6 inch compartment.');

INSERT INTO `product_images` (`product_id`, `color`, `path`, `alt_text`, `sort_order`, `is_primary`) VALUES
(14, 'Slate Charcoal', 'assets/img/product/product-6.webp', 'Urban Waterproof Laptop Backpack Front View', 1, 1);

INSERT INTO `product_variants` (`id`, `product_id`, `sku`, `size`, `color`, `color_hex`, `price_override`, `stock_qty`, `low_stock_threshold`, `is_active`) VALUES
(51, 14, 'BAG-BPK-GR', '28L', 'Slate Charcoal', '#4B5563', NULL, 18, 5, 1),
(52, 14, 'BAG-BPK-BK', '28L', 'Midnight Black', '#111827', NULL, 11, 4, 1);

-- Product 15: Tortoiseshell Round Frame UV400 Sunglasses
INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `short_description`, `description`, `base_price`, `compare_at_price`, `is_active`, `is_featured`, `meta_title`, `meta_description`) VALUES
(15, 4, 'Tortoiseshell Round Frame UV400 Sunglasses', 'tortoiseshell-round-frame-uv400-sunglasses',
'Vintage-inspired round sunglasses featuring handcrafted acetate frames in a warm tortoiseshell motif. Polarized category 3 lenses deliver full UV400 protection against harsh equatorial glare.',
'Protect your vision in style with these timeless round-frame shades, featuring optical-grade polarized polycarbonate lenses that eliminate glare and enhance clarity on bright Kenyan roads. The lightweight cellulose acetate frame includes reinforced five-barrel metal hinges and smooth molded nose bridges for pinch-free all-day wear. Comes complete with a hard protective storage case, microfiber lens cleaning cloth, and polarization test card. Rinse with fresh water and wipe with the microfiber cloth after dusty outings.',
1750.00, 2400.00, 1, 0, 'Tortoiseshell Round UV400 Sunglasses | ShopWise Kenya', 'Polarized round frame sunglasses in amber tortoise. 100% UV400 protection.');

INSERT INTO `product_images` (`product_id`, `color`, `path`, `alt_text`, `sort_order`, `is_primary`) VALUES
(15, 'Amber Tortoise', 'assets/img/product/product-3.webp', 'Tortoiseshell Round Sunglasses Front View', 1, 1);

INSERT INTO `product_variants` (`id`, `product_id`, `sku`, `size`, `color`, `color_hex`, `price_override`, `stock_qty`, `low_stock_threshold`, `is_active`) VALUES
(53, 15, 'ACC-SUN-TR', 'Standard', 'Amber Tortoise', '#78350F', NULL, 22, 5, 1),
(54, 15, 'ACC-SUN-BK', 'Standard', 'Gloss Black', '#000000', NULL, 15, 4, 1);

-- Product 16: Wide-Brim Wool Felt Fedora Hat
INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `short_description`, `description`, `base_price`, `compare_at_price`, `is_active`, `is_featured`, `meta_title`, `meta_description`) VALUES
(16, 4, 'Wide-Brim Wool Felt Fedora Hat', 'wide-brim-wool-felt-fedora-hat',
'An iconic wide-brim fedora blocked from 100% Australian wool felt and accented with an elegant metallic gold trim band. Adds instant polish to dresses, trench coats, or tailored blazers.',
'Expertly shaped for structure and longevity, this statement fedora features a 7.5cm flat stiffened brim and a teardrop pinched crown. An interior adjustable sweatband drawstring ensures a snug, custom fit across varying head circumferences. Ideal for outdoor garden events, races, weekend brunches, and sunny safari getaways. Gently brush with a horsehair clothes brush in the direction of the felt nap to remove dust.',
2400.00, 2950.00, 1, 0, 'Wide-Brim Wool Felt Fedora | ShopWise Kenya', 'Classic wide brim felt fedora hat with gold trim band.');

INSERT INTO `product_images` (`product_id`, `color`, `path`, `alt_text`, `sort_order`, `is_primary`) VALUES
(16, 'Matte Black', 'assets/img/product/product-f-1.webp', 'Wide-Brim Wool Felt Fedora Model View', 1, 1);

INSERT INTO `product_variants` (`id`, `product_id`, `sku`, `size`, `color`, `color_hex`, `price_override`, `stock_qty`, `low_stock_threshold`, `is_active`) VALUES
(55, 16, 'ACC-FED-BK', 'M/L', 'Matte Black', '#0F172A', NULL, 10, 3, 1),
(56, 16, 'ACC-FED-CM', 'M/L', 'Rich Camel', '#B45309', NULL, 7, 2, 1);

-- Product 17: Smart Watch Ultra with Fitness Tracking & GPS
INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `short_description`, `description`, `base_price`, `compare_at_price`, `is_active`, `is_featured`, `meta_title`, `meta_description`) VALUES
(17, 5, 'Smart Watch Ultra with Fitness Tracking & GPS', 'smart-watch-ultra-with-fitness-tracking-gps',
'A rugged 49mm titanium-look smartwatch featuring an ultra-bright AMOLED touch display and heart rate monitoring. Comes with an elastic sport loop strap and IP68 water resistance.',
'Designed for athletics and connected living, this multi-sport smartwatch provides real-time GPS tracking, heart rate, blood oxygen (SpO2), and sleep stage monitoring. Receive SMS, WhatsApp, and call alerts directly on your wrist with smooth Bluetooth 5.3 phone connectivity compatible with Android and iOS. Built with a battery that lasts up to 7 days on standard usage and recharges rapidly via magnetic wireless cradle. What is in the box: Smart Watch Ultra, Orange Alpine Loop Band, Black Silicone Band, Magnetic USB-C Charger, User Manual.',
4500.00, 5500.00, 1, 1, 'Smart Watch Ultra 49mm | ShopWise Kenya', 'Rugged 49mm fitness smartwatch with AMOLED screen, GPS tracking, and heart rate monitor.');

INSERT INTO `product_images` (`product_id`, `color`, `path`, `alt_text`, `sort_order`, `is_primary`) VALUES
(17, 'Alpine Orange', 'assets/img/product/product-elec-smartwatch.webp', 'Smart Watch Ultra with Orange Strap Wrist View', 1, 1);

INSERT INTO `product_variants` (`id`, `product_id`, `sku`, `size`, `color`, `color_hex`, `price_override`, `stock_qty`, `low_stock_threshold`, `is_active`) VALUES
(57, 17, 'ELC-WCH-OR', '49mm', 'Alpine Orange', '#EA580C', NULL, 12, 3, 1),
(58, 17, 'ELC-WCH-BK', '49mm', 'Midnight Black', '#18181B', NULL, 9, 3, 1);

-- Product 18: Sony Foldable On-Ear Stereo Wired Headphones
INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `short_description`, `description`, `base_price`, `compare_at_price`, `is_active`, `is_featured`, `meta_title`, `meta_description`) VALUES
(18, 5, 'Sony Foldable On-Ear Stereo Wired Headphones', 'sony-foldable-on-ear-stereo-wired-headphones',
'Lightweight on-ear stereo headphones with 30mm dynamic dome drivers for balanced acoustics and punchy bass. Features swivel folding earcups and a tangle-free 1.2m serrated cable.',
'Enjoy rich, detailed audio fidelity for music, podcasts, and video streaming with these comfortable on-ear headphones. Soft pressure-relieving earpads deliver acoustic isolation and extended wearing comfort during long study or work sessions. The swivel folding design allows the earcups to fold flat for effortless packing into laptop bags and backpacks. What is in the box: Sony Stereo Headphones, 3.5mm Gold-Plated Audio Jack, Quick Start Guide, Warranty Sheet.',
2800.00, 3400.00, 1, 1, 'Sony Foldable On-Ear Stereo Headphones | ShopWise Kenya', 'Original Sony on-ear stereo wired headphones with 30mm drivers and swivel folding earcups.');

INSERT INTO `product_images` (`product_id`, `color`, `path`, `alt_text`, `sort_order`, `is_primary`) VALUES
(18, 'Pearl White', 'assets/img/product/product-elec-headphones.webp', 'Sony Foldable On-Ear Stereo Headphones Top View', 1, 1);

INSERT INTO `product_variants` (`id`, `product_id`, `sku`, `size`, `color`, `color_hex`, `price_override`, `stock_qty`, `low_stock_threshold`, `is_active`) VALUES
(59, 18, 'ELC-HDPH-WH', 'Standard', 'Pearl White', '#FFFFFF', NULL, 15, 4, 1),
(60, 18, 'ELC-HDPH-BK', 'Standard', 'Midnight Black', '#18181B', NULL, 8, 3, 1);

-- Product 19: Mid-Century Modern Wooden Accent Armchair
INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `short_description`, `description`, `base_price`, `compare_at_price`, `is_active`, `is_featured`, `meta_title`, `meta_description`) VALUES
(19, 6, 'Mid-Century Modern Wooden Accent Armchair', 'mid-century-modern-wooden-accent-armchair',
'A sculptural mid-century lounge chair featuring a curved natural walnut wood frame and thick ivory upholstered seat. Brings warm Scandinavian elegance to living rooms and study corners.',
'Handcrafted from solid kiln-dried hardwood and finished with warm walnut veneer, this ergonomic accent chair makes a striking focal point in any interior space. The contoured barrel back cradles the body comfortably, while the high-density resilient foam seat cushion is wrapped in stain-resistant textured linen fabric. Solid tapered wood legs are fitted with protective non-marking floor glides to safeguard wood and tile floors. Spot clean upholstery with a clean damp cloth; wipe wood frame with lemon oil polish.',
14500.00, 17500.00, 1, 1, 'Mid-Century Wooden Accent Armchair | ShopWise Kenya', 'Modern curved walnut accent lounge armchair with ivory cushion. Delivered across Kenya.');

INSERT INTO `product_images` (`product_id`, `color`, `path`, `alt_text`, `sort_order`, `is_primary`) VALUES
(19, 'Walnut & Ivory', 'assets/img/product/product-2.webp', 'Mid-Century Modern Wooden Armchair Studio View', 1, 1);

INSERT INTO `product_variants` (`id`, `product_id`, `sku`, `size`, `color`, `color_hex`, `price_override`, `stock_qty`, `low_stock_threshold`, `is_active`) VALUES
(61, 19, 'HOM-CHR-WNT', 'Standard', 'Walnut & Ivory', '#F5F5F0', NULL, 5, 2, 1),
(62, 19, 'HOM-CHR-OAK', 'Standard', 'Oak & Charcoal', '#374151', NULL, 2, 1, 1);

-- Product 20: Matte Cream Gooseneck Pour-Over Coffee Kettle
INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `short_description`, `description`, `base_price`, `compare_at_price`, `is_active`, `is_featured`, `meta_title`, `meta_description`) VALUES
(20, 6, 'Matte Cream Gooseneck Pour-Over Coffee Kettle', 'matte-cream-gooseneck-pour-over-coffee-kettle',
'A precision 850ml pour-over kettle engineered with a slender gooseneck spout and heat-resistant natural beechwood handle. Ideal for brewing pour-over coffee, Chemex, and artisan Kenyan teas.',
'Achieve the perfect extraction with this stovetop kettle, engineered from food-grade 304 stainless steel with an electrostatically applied matte cream powder-coat exterior. The elongated slender spout delivers a consistent 90-degree laminar water flow for maximum bloom control over ground coffee beans. Features a built-in lid thermometer port and an ergonomically counterbalanced wooden handle to protect your fingers from steam. Compatible with gas, electric, and ceramic stovetops (hand wash only; do not place in microwave or dishwasher).',
2800.00, 3500.00, 1, 0, 'Matte Cream Gooseneck Coffee Kettle | ShopWise Kenya', '850ml stainless steel gooseneck pour-over kettle with wooden handle for artisan coffee lovers.');

INSERT INTO `product_images` (`product_id`, `color`, `path`, `alt_text`, `sort_order`, `is_primary`) VALUES
(20, 'Matte Cream', 'assets/img/product/product-home-kettle.webp', 'Matte Cream Gooseneck Kettle Studio Shot', 1, 1);

INSERT INTO `product_variants` (`id`, `product_id`, `sku`, `size`, `color`, `color_hex`, `price_override`, `stock_qty`, `low_stock_threshold`, `is_active`) VALUES
(63, 20, 'HOM-KTL-CR', '850ml', 'Matte Cream', '#FFFDD0', NULL, 14, 4, 1),
(64, 20, 'HOM-KTL-BK', '850ml', 'Matte Black', '#1C1917', NULL, 9, 3, 1);

-- Product 21: 3-Step Custom Daily Skincare Routine Set
INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `short_description`, `description`, `base_price`, `compare_at_price`, `is_active`, `is_featured`, `meta_title`, `meta_description`) VALUES
(21, 7, '3-Step Custom Daily Skincare Routine Set', '3-step-custom-daily-skincare-routine-set',
'A dermatologist-tested three-piece regimen containing a gentle hydrating cleanser (80ml), specialized treatment serum (30ml), and rich barrier moisturizer (50ml). Formulated to balance and nourish all skin types.',
'Transform your daily skincare routine with this clean, fragrance-free 3-step system designed to cleanse, repair, and lock in lasting hydration. The sulfate-free cleanser clears dirt and oil without stripping moisture; the active serum targets blemishes and uneven skin texture; while the nourishing ceramides in the moisturizer reinforce the skin barrier against dry conditions. Formulated without parabens, artificial fragrances, or pore-clogging mineral oils. What is in the box: Hydrating Gel Cleanser 80ml, Targeted Treatment Formula 30ml, Barrier Moisturizer 50ml, Instructional Step Guide.',
3600.00, 4200.00, 1, 1, '3-Step Custom Daily Skincare Set | ShopWise Kenya', 'Dermatologist-tested 3-piece skincare set: hydrating cleanser, serum, and barrier moisturizer.');

INSERT INTO `product_images` (`product_id`, `color`, `path`, `alt_text`, `sort_order`, `is_primary`) VALUES
(21, 'Indigo Blue', 'assets/img/product/product-beauty-skincare.webp', '3-Step Skincare Routine Box Set View', 1, 1);

INSERT INTO `product_variants` (`id`, `product_id`, `sku`, `size`, `color`, `color_hex`, `price_override`, `stock_qty`, `low_stock_threshold`, `is_active`) VALUES
(65, 21, 'BTY-SET-3PC', 'Full Set', 'Indigo Blue', '#312E81', NULL, 16, 5, 1),
(66, 21, 'BTY-SET-TRL', 'Trial Kit', 'Indigo Blue', '#312E81', 1950.00, 10, 3, 1);

-- Initial Inventory Movements for all variants
INSERT INTO `inventory_movements` (`variant_id`, `change_qty`, `reason`, `note`, `created_at`)
SELECT `id`, `stock_qty`, 'initial', 'Initial inventory stock intake', NOW()
FROM `product_variants` WHERE `stock_qty` > 0;

-- ------------------------------------------------------------------------------
-- 6. Coupons
-- ------------------------------------------------------------------------------
DELETE FROM `coupons`;
INSERT INTO `coupons` (`id`, `code`, `type`, `value`, `min_spend`, `usage_limit`, `used_count`, `starts_at`, `expires_at`, `is_active`) VALUES
(1, 'WELCOME10', 'percent', 10.00, 2000.00, 500, 14, NOW(), '2028-12-31 23:59:59', 1),
(2, 'NAIROBIFREEDEL', 'fixed', 200.00, 2500.00, 200, 29, NOW(), '2028-12-31 23:59:59', 1);

-- ------------------------------------------------------------------------------
-- 7. Customer Reviews (Authentic Kenyan Customer Feedback)
-- ------------------------------------------------------------------------------
DELETE FROM `reviews`;
INSERT INTO `reviews` (`product_id`, `user_id`, `order_id`, `rating`, `title`, `body`, `status`, `created_at`) VALUES
(1, 1, NULL, 5, 'Perfect fit and authentic denim quality!', 'Received my denim jacket in Kilimani within 4 hours of ordering via M-Pesa. The wash looks even better in person and it has substantial weight. Highly recommended!', 'approved', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(5, 2, NULL, 5, 'Excellent quality cotton polo', 'The collar stays firm after washing and the fabric is truly breathable during hot days. Delivered promptly to my office in Upper Hill.', 'approved', DATE_SUB(NOW(), INTERVAL 12 DAY)),
(10, 4, NULL, 5, 'Super comfortable stilettos', 'Wore these for a full wedding in Karen without foot pain thanks to the soft cushioned insole. The nude patent finish is immaculate.', 'approved', DATE_SUB(NOW(), INTERVAL 8 DAY)),
(11, 3, NULL, 5, 'Solid leather high-tops', 'The black leather is sturdy and looks sharp with jeans. Delivered safely to Kisumu in 2 days. Great service from the ShopWise team!', 'approved', DATE_SUB(NOW(), INTERVAL 15 DAY)),
(13, 6, NULL, 5, 'My daily work tote', 'Fits my 14-inch HP laptop, charger, and notebook with room to spare. The pebble leather feels very premium and the stitching is top tier.', 'approved', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(17, 5, NULL, 4, 'Great battery and display', 'The AMOLED screen is crystal clear even in direct sunlight. Heart rate tracking syncs smoothly with my Android phone.', 'approved', DATE_SUB(NOW(), INTERVAL 9 DAY));

-- ------------------------------------------------------------------------------
-- 8. Contact Inquiries
-- ------------------------------------------------------------------------------
DELETE FROM `contact_messages`;
INSERT INTO `contact_messages` (`name`, `email`, `phone`, `subject`, `message`, `status`, `created_at`) VALUES
('Dennis Kiprotich', 'dennis.k@example.com', '254721998877', 'Inquiry about bulk order delivery to Eldoret', 'Hello ShopWise team, I am looking to purchase 6 pairs of the Classic Black Leather High-Top Sneakers for our youth dance club in Eldoret. Do you offer courier parcel tracking or bulk discount rates? Kindly advise on payment and delivery timeline. Regards, Dennis.', 'new', DATE_SUB(NOW(), INTERVAL 2 DAY)),
('Amina Hassan', 'amina.hassan@example.com', '254734112233', 'Sizing guidance for tailored charcoal blazer', 'Hi there, I want to surprise my husband with the Tailored Slim-Fit Charcoal Blazer for his birthday. His chest measurement is approximately 41 inches. Would size 40R or 42R be the best fit for an athletic build? Thank you!', 'in_progress', DATE_SUB(NOW(), INTERVAL 1 DAY));

SET FOREIGN_KEY_CHECKS = 1;
