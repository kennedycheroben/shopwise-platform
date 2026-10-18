-- ==============================================================================
-- ShopWise Kenya - Production E-Commerce Platform Database Schema
-- Import this file after selecting the database in phpMyAdmin or cPanel.
-- Target Engine: MySQL 8.0+ / MariaDB 10.4+ (InnoDB, utf8mb4_unicode_ci)
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- 1. Users & Authentication
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `email_verifications`;
DROP TABLE IF EXISTS `password_resets`;
DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role` ENUM('customer', 'admin', 'staff') NOT NULL DEFAULT 'customer',
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `phone` VARCHAR(20) NULL,
  `password_hash` VARCHAR(255) NULL COMMENT 'Null for Google OAuth-only accounts',
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
  UNIQUE KEY `uq_users_google_id` (`google_id`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_status` (`status`),
  KEY `idx_users_phone` (`phone`),
  KEY `idx_users_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_resets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `token_hash` VARCHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `ip` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pwd_resets_user_id` (`user_id`),
  KEY `idx_pwd_resets_token_hash` (`token_hash`),
  KEY `idx_pwd_resets_expires_at` (`expires_at`),
  CONSTRAINT `fk_pwd_resets_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_verifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `token_hash` VARCHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email_verif_user_id` (`user_id`),
  KEY `idx_email_verif_token_hash` (`token_hash`),
  CONSTRAINT `fk_email_verif_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier` VARCHAR(191) NOT NULL COMMENT 'Email or normalized phone',
  `ip` VARCHAR(45) NOT NULL,
  `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_lookup` (`identifier`, `ip`, `attempted_at`),
  KEY `idx_login_attempts_ip` (`ip`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 2. Catalog: Categories, Products, Images, Variants
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `product_variants`;
DROP TABLE IF EXISTS `product_images`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `categories`;

CREATE TABLE `categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id` BIGINT UNSIGNED NULL,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(120) NOT NULL,
  `description` TEXT NULL,
  `image` VARCHAR(255) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_slug` (`slug`),
  KEY `idx_categories_parent` (`parent_id`),
  KEY `idx_categories_active_sort` (`is_active`, `sort_order`),
  CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`)
    REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `products` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL,
  `short_description` VARCHAR(500) NOT NULL,
  `description` MEDIUMTEXT NOT NULL,
  `base_price` DECIMAL(12,2) NOT NULL,
  `compare_at_price` DECIMAL(12,2) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
  `meta_title` VARCHAR(255) NULL,
  `meta_description` VARCHAR(500) NULL,
  `deleted_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_products_slug` (`slug`),
  KEY `idx_products_category` (`category_id`),
  KEY `idx_products_is_active` (`is_active`),
  KEY `idx_products_is_featured` (`is_featured`),
  KEY `idx_products_deleted_at` (`deleted_at`),
  KEY `idx_products_created_at` (`created_at`),
  CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`)
    REFERENCES `categories` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_images` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `color` VARCHAR(50) NULL COMMENT 'Optional association with variant color',
  `path` VARCHAR(255) NOT NULL,
  `alt_text` VARCHAR(255) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product_images_prod` (`product_id`),
  KEY `idx_product_images_primary` (`product_id`, `is_primary`),
  KEY `idx_product_images_color` (`color`),
  CONSTRAINT `fk_product_images_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_variants` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `sku` VARCHAR(100) NOT NULL,
  `size` VARCHAR(50) NULL,
  `color` VARCHAR(50) NULL,
  `color_hex` VARCHAR(7) NULL,
  `price_override` DECIMAL(12,2) NULL,
  `stock_qty` INT UNSIGNED NOT NULL DEFAULT 0,
  `low_stock_threshold` INT UNSIGNED NOT NULL DEFAULT 5,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_variants_sku` (`sku`),
  UNIQUE KEY `uq_variants_product_size_color` (`product_id`, `size`, `color`),
  KEY `idx_variants_product` (`product_id`),
  KEY `idx_variants_active` (`is_active`),
  KEY `idx_variants_stock` (`stock_qty`),
  CONSTRAINT `chk_variants_stock_qty` CHECK (`stock_qty` >= 0),
  CONSTRAINT `fk_product_variants_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 3. Shopping Cart
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `cart_items`;
DROP TABLE IF EXISTS `carts`;

CREATE TABLE `carts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL,
  `session_token` VARCHAR(64) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_carts_session_token` (`session_token`),
  KEY `idx_carts_user` (`user_id`),
  KEY `idx_carts_updated_at` (`updated_at`),
  CONSTRAINT `fk_carts_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cart_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cart_id` BIGINT UNSIGNED NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cart_items_cart_variant` (`cart_id`, `variant_id`),
  KEY `idx_cart_items_cart` (`cart_id`),
  KEY `idx_cart_items_variant` (`variant_id`),
  CONSTRAINT `chk_cart_items_quantity` CHECK (`quantity` > 0),
  CONSTRAINT `fk_cart_items_cart` FOREIGN KEY (`cart_id`)
    REFERENCES `carts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cart_items_variant` FOREIGN KEY (`variant_id`)
    REFERENCES `product_variants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 4. Customer Addresses & Wishlists
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `wishlists`;
DROP TABLE IF EXISTS `addresses`;

CREATE TABLE `addresses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `label` VARCHAR(50) NOT NULL DEFAULT 'Home',
  `recipient_name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `county` VARCHAR(100) NOT NULL,
  `area` VARCHAR(100) NOT NULL,
  `street_details` TEXT NOT NULL,
  `latitude` DECIMAL(10,8) NULL,
  `longitude` DECIMAL(11,8) NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_addresses_user` (`user_id`),
  KEY `idx_addresses_default` (`user_id`, `is_default`),
  CONSTRAINT `fk_addresses_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `wishlists` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wishlists_user_product` (`user_id`, `product_id`),
  KEY `idx_wishlists_user` (`user_id`),
  KEY `idx_wishlists_product` (`product_id`),
  CONSTRAINT `fk_wishlists_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wishlists_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 5. Delivery Zones, Settings & Coupons
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `coupons`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `delivery_zones`;

CREATE TABLE `delivery_zones` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `county` VARCHAR(100) NOT NULL,
  `area` VARCHAR(150) NOT NULL,
  `fee` DECIMAL(12,2) NOT NULL,
  `est_days_min` INT UNSIGNED NOT NULL DEFAULT 1,
  `est_days_max` INT UNSIGNED NOT NULL DEFAULT 2,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_delivery_zones_lookup` (`county`, `area`),
  KEY `idx_delivery_zones_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NULL,
  `type` ENUM('string', 'integer', 'float', 'boolean', 'json') NOT NULL DEFAULT 'string',
  `description` VARCHAR(255) NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `coupons` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50) NOT NULL,
  `type` ENUM('percent', 'fixed') NOT NULL,
  `value` DECIMAL(12,2) NOT NULL,
  `min_spend` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `usage_limit` INT UNSIGNED NULL,
  `used_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `starts_at` DATETIME NULL,
  `expires_at` DATETIME NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coupons_code` (`code`),
  KEY `idx_coupons_active_exp` (`is_active`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 6. Orders, Items, Status History & Coupon Redemptions
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `coupon_redemptions`;
DROP TABLE IF EXISTS `order_status_history`;
DROP TABLE IF EXISTS `order_items`;
DROP TABLE IF EXISTS `orders`;

CREATE TABLE `orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_number` VARCHAR(32) NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `status` ENUM(
    'pending_payment',
    'paid',
    'processing',
    'out_for_delivery',
    'delivered',
    'cancelled',
    'expired',
    'refunded'
  ) NOT NULL DEFAULT 'pending_payment',
  `payment_status` ENUM(
    'unpaid',
    'pending',
    'paid',
    'failed',
    'refunded'
  ) NOT NULL DEFAULT 'unpaid',
  `subtotal` DECIMAL(12,2) NOT NULL,
  `delivery_fee` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(12,2) NOT NULL,
  `delivery_method` ENUM('pickup', 'delivery') NOT NULL DEFAULT 'delivery',
  `delivery_pricing_method` ENUM('pickup', 'zone', 'distance') NOT NULL DEFAULT 'zone',
  `delivery_distance_km` DECIMAL(8,2) NULL,
  `recipient_name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `county` VARCHAR(100) NULL,
  `area` VARCHAR(100) NULL,
  `street_details` TEXT NULL,
  `latitude` DECIMAL(10,8) NULL,
  `longitude` DECIMAL(11,8) NULL,
  `customer_notes` TEXT NULL,
  `idempotency_token` VARCHAR(64) NOT NULL,
  `expires_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_orders_number` (`order_number`),
  UNIQUE KEY `uq_orders_idempotency` (`idempotency_token`),
  KEY `idx_orders_user` (`user_id`),
  KEY `idx_orders_status` (`status`),
  KEY `idx_orders_payment_status` (`payment_status`),
  KEY `idx_orders_expires_at` (`expires_at`),
  KEY `idx_orders_created_at` (`created_at`),
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `coupon_redemptions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `coupon_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `discount_amount` DECIMAL(12,2) NOT NULL,
  `redeemed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_redemptions_coupon` (`coupon_id`),
  KEY `idx_redemptions_order` (`order_id`),
  KEY `idx_redemptions_user` (`user_id`),
  CONSTRAINT `fk_redemptions_coupon` FOREIGN KEY (`coupon_id`)
    REFERENCES `coupons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_redemptions_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_redemptions_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `product_name` VARCHAR(255) NOT NULL,
  `size` VARCHAR(50) NULL,
  `color` VARCHAR(50) NULL,
  `sku` VARCHAR(100) NOT NULL,
  `unit_price` DECIMAL(12,2) NOT NULL COMMENT 'Snapshot of price at purchase time',
  `quantity` INT UNSIGNED NOT NULL,
  `line_total` DECIMAL(12,2) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_items_order` (`order_id`),
  KEY `idx_order_items_product` (`product_id`),
  KEY `idx_order_items_variant` (`variant_id`),
  KEY `idx_order_items_sku` (`sku`),
  CONSTRAINT `chk_order_items_qty` CHECK (`quantity` > 0),
  CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_items_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_order_items_variant` FOREIGN KEY (`variant_id`)
    REFERENCES `product_variants` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_status_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `from_status` VARCHAR(50) NULL,
  `to_status` VARCHAR(50) NOT NULL,
  `note` TEXT NULL,
  `actor_user_id` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_history_order` (`order_id`),
  KEY `idx_order_history_created_at` (`created_at`),
  CONSTRAINT `fk_order_history_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_history_actor` FOREIGN KEY (`actor_user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 7. Payments (M-Pesa Daraja STK Push & Callbacks)
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `payments`;

CREATE TABLE `payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `method` VARCHAR(30) NOT NULL DEFAULT 'mpesa',
  `status` ENUM('initiated', 'pending', 'success', 'failed', 'cancelled', 'timeout') NOT NULL DEFAULT 'initiated',
  `amount` DECIMAL(12,2) NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `merchant_request_id` VARCHAR(64) NULL,
  `checkout_request_id` VARCHAR(64) NULL,
  `mpesa_receipt` VARCHAR(64) NULL,
  `result_code` INT NULL,
  `result_desc` VARCHAR(255) NULL,
  `raw_callback` MEDIUMTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payments_checkout_req` (`checkout_request_id`),
  UNIQUE KEY `uq_payments_receipt` (`mpesa_receipt`),
  KEY `idx_payments_order` (`order_id`),
  KEY `idx_payments_status` (`status`),
  KEY `idx_payments_merchant_req` (`merchant_request_id`),
  KEY `idx_payments_created_at` (`created_at`),
  CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 8. Inventory Movements (Audited Stock Tracking)
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `inventory_movements`;

CREATE TABLE `inventory_movements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `change_qty` INT NOT NULL COMMENT 'Signed integer: positive for addition/restore, negative for deduction',
  `reason` ENUM(
    'sale',
    'cancel_restore',
    'expiry_restore',
    'admin_adjustment',
    'restock',
    'initial'
  ) NOT NULL,
  `order_id` BIGINT UNSIGNED NULL,
  `actor_user_id` BIGINT UNSIGNED NULL,
  `note` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inv_movements_variant` (`variant_id`),
  KEY `idx_inv_movements_reason` (`reason`),
  KEY `idx_inv_movements_order` (`order_id`),
  KEY `idx_inv_movements_created_at` (`created_at`),
  CONSTRAINT `fk_inv_movements_variant` FOREIGN KEY (`variant_id`)
    REFERENCES `product_variants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inv_movements_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inv_movements_actor` FOREIGN KEY (`actor_user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 9. Reviews & Contact Messages
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `reviews`;
DROP TABLE IF EXISTS `contact_messages`;

CREATE TABLE `reviews` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NULL,
  `rating` TINYINT UNSIGNED NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `body` TEXT NOT NULL,
  `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reviews_prod_status` (`product_id`, `status`),
  KEY `idx_reviews_user` (`user_id`),
  KEY `idx_reviews_status_created` (`status`, `created_at`),
  CONSTRAINT `chk_reviews_rating` CHECK (`rating` >= 1 AND `rating` <= 5),
  CONSTRAINT `fk_reviews_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reviews_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reviews_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `contact_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `phone` VARCHAR(20) NULL,
  `subject` VARCHAR(200) NOT NULL,
  `message` TEXT NOT NULL,
  `status` ENUM('new', 'in_progress', 'resolved', 'archived') NOT NULL DEFAULT 'new',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_contact_status` (`status`),
  KEY `idx_contact_email` (`email`),
  KEY `idx_contact_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 10. Email Queue & Audit Logging
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `email_queue`;
DROP TABLE IF EXISTS `audit_log`;

CREATE TABLE `email_queue` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `to_email` VARCHAR(191) NOT NULL,
  `to_name` VARCHAR(100) NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body_html` MEDIUMTEXT NOT NULL,
  `body_text` MEDIUMTEXT NOT NULL,
  `status` ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email_queue_status_created` (`status`, `created_at`),
  KEY `idx_email_queue_to_email` (`to_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_user_id` BIGINT UNSIGNED NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(100) NOT NULL,
  `entity_id` BIGINT UNSIGNED NULL,
  `details` JSON NULL,
  `ip` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_actor` (`actor_user_id`),
  KEY `idx_audit_entity` (`entity_type`, `entity_id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_created_at` (`created_at`),
  CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
