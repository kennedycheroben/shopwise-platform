-- ==============================================================================
-- ShopWise Kenya - Migration 007: Departmental Roles and User Roles Schema
-- Milestone 2A: Role-Based Access Control (RBAC) Foundation
-- ==============================================================================

-- 1. Create canonical roles registry table
CREATE TABLE IF NOT EXISTS `roles` (
    `name` VARCHAR(50) NOT NULL,
    `display_name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `department` VARCHAR(50) NOT NULL,
    `is_system` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Populate the 7 administrative departmental roles + legacy + customer
INSERT INTO `roles` (`name`, `display_name`, `description`, `department`, `is_system`) VALUES
('super_admin', 'Super Administrator', 'Complete platform oversight, full departmental authority, staff and security administration', 'Executive', 1),
('finance_manager', 'Finance Manager', 'Payments, M-Pesa reconciliation, refund processing, and financial reporting', 'Finance', 1),
('orders_manager', 'Orders Manager', 'Order processing, fulfillment, dispatch, and delivery coordination', 'Operations', 1),
('inventory_manager', 'Inventory Manager', 'Stock levels, inventory movements, restocking, and stock adjustments', 'Inventory', 1),
('catalog_manager', 'Catalog Manager', 'Product catalog, categories, variants, descriptions, and media publication', 'Catalog', 1),
('support_agent', 'Customer Support Agent', 'Customer inquiries, contact messages, review moderation, and customer assistance', 'Support', 1),
('marketing_manager', 'Marketing Manager', 'Coupons, promotions, campaigns, and merchandising performance', 'Marketing', 1),
('admin', 'Administrator (Legacy)', 'Legacy administrator with universal platform privileges', 'Executive', 1),
('staff', 'Staff Member (Legacy)', 'Legacy general staff account with baseline operational access', 'Operations', 1),
('customer', 'Storefront Customer', 'Retail storefront customer account for browsing and shopping', 'Storefront', 1)
ON DUPLICATE KEY UPDATE 
    `display_name` = VALUES(`display_name`),
    `description` = VALUES(`description`),
    `department` = VALUES(`department`);

-- 3. Modify users.role column from restrictive ENUM to VARCHAR(50)
-- Preserves existing user roles ('customer', 'admin', 'staff') while allowing new departmental roles
ALTER TABLE `users` MODIFY COLUMN `role` VARCHAR(50) NOT NULL DEFAULT 'customer';

-- 4. Create user_roles junction table to support normalized and multi-role assignments
CREATE TABLE IF NOT EXISTS `user_roles` (
    `user_id` BIGINT UNSIGNED NOT NULL,
    `role` VARCHAR(50) NOT NULL,
    `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `assigned_by` BIGINT UNSIGNED NULL,
    PRIMARY KEY (`user_id`, `role`),
    KEY `idx_user_roles_role` (`role`),
    KEY `idx_user_roles_assigned_by` (`assigned_by`),
    CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role`) REFERENCES `roles` (`name`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Seed user_roles from existing users.role to ensure 100% sync
INSERT IGNORE INTO `user_roles` (`user_id`, `role`, `assigned_at`)
SELECT `id`, `role`, NOW() FROM `users`;

