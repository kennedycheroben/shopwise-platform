-- ==============================================================================
-- ShopWise Kenya - Migration 007 DOWN: Departmental Roles Schema Reversion
-- Milestone 2B: Emergency Schema Reversion & Archival Script
-- ==============================================================================
--
-- IMPORTANT SAFETY, LIMITATION & RECOVERY NOTICE:
-- 1. Loss of Active Departmental Semantics:
--    Downward schema migration CANNOT be described as lossless for active runtime semantics.
--    Mapping granular departmental roles ('finance_manager', 'orders_manager', etc.)
--    back to generic legacy roles ('admin', 'staff') permanently collapses departmental
--    boundaries in active application logic.
--
-- 2. Authoritative Full Database Restoration Procedure:
--    For an absolute, 100% lossless database rollback to pre-migration state, restore
--    from the verified pre-migration logical backup dump:
--      mysql -u root -p shopwise_db < backups/pre_migration_007_<timestamp>.sql
--    This is the ONLY authoritative production recovery mechanism.
--
-- 3. MariaDB Implicit DDL Commits:
--    In MariaDB/MySQL, DDL statements (ALTER TABLE, CREATE TABLE, DROP TABLE) execute
--    an immediate IMPLICIT COMMIT. They cannot be executed inside a rollbackable transaction.
--
-- 4. Archival of Relational Role Data:
--    To prevent complete loss of role assignments, this down script archives all active
--    user_roles records into `_archive_user_roles` and canonical roles into `_archive_roles`
--    before dropping active schema tables.
--
-- 5. MariaDB STRICT_TRANS_TABLES Compliance:
--    MariaDB enforces strict column truncation. To alter `users.role` back to
--    ENUM('customer','admin','staff'), departmental roles in `users.role` are
--    remapped to legacy compatibility values before the column is altered so the
--    DDL statement does not abort with Error 1265.
-- ==============================================================================

-- Step 1: Pre-Reversion Archival (Lossless Role Preservation)
CREATE TABLE IF NOT EXISTS `_archive_user_roles` (
    `user_id` BIGINT UNSIGNED NOT NULL,
    `role` VARCHAR(50) NOT NULL,
    `assigned_at` DATETIME NOT NULL,
    `assigned_by` BIGINT UNSIGNED NULL,
    `archived_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `_archive_user_roles` (`user_id`, `role`, `assigned_at`, `assigned_by`, `archived_at`)
SELECT `user_id`, `role`, `assigned_at`, `assigned_by`, NOW()
FROM `user_roles`
ON DUPLICATE KEY UPDATE `archived_at` = NOW();

CREATE TABLE IF NOT EXISTS `_archive_roles` (
    `name` VARCHAR(50) NOT NULL,
    `display_name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `department` VARCHAR(50) NOT NULL,
    `archived_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `_archive_roles` (`name`, `display_name`, `description`, `department`, `archived_at`)
SELECT `name`, `display_name`, `description`, `department`, NOW()
FROM `roles`
ON DUPLICATE KEY UPDATE `archived_at` = NOW();

-- Step 2: Legacy Compatibility Remapping on users.role
-- Necessary to satisfy MariaDB STRICT_TRANS_TABLES before ALTER TABLE
UPDATE `users` SET `role` = 'admin' WHERE `role` = 'super_admin';
UPDATE `users` SET `role` = 'staff' WHERE `role` IN (
    'finance_manager', 'orders_manager', 'inventory_manager',
    'catalog_manager', 'support_agent', 'marketing_manager'
);
UPDATE `users` SET `role` = 'customer' WHERE `role` NOT IN ('admin', 'staff', 'customer');

-- Step 3: Revert users.role Column to Legacy ENUM
ALTER TABLE `users` MODIFY COLUMN `role` ENUM('customer','admin','staff') NOT NULL DEFAULT 'customer';

-- Step 4: Safely Drop Junction and Canonical Roles Tables
-- Drop foreign keys first to prevent constraint violations
ALTER TABLE `user_roles` DROP FOREIGN KEY `fk_user_roles_user`;
ALTER TABLE `user_roles` DROP FOREIGN KEY `fk_user_roles_role`;

DROP TABLE IF EXISTS `user_roles`;
DROP TABLE IF EXISTS `roles`;

