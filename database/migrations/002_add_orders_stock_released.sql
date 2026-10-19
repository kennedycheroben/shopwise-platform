-- Migration 002: Add stock_released flag to orders table for cancel/expire idempotency
ALTER TABLE `orders` ADD COLUMN `stock_released` TINYINT(1) NOT NULL DEFAULT 0 AFTER `customer_notes`;
