-- Migration: 004_add_carts_coupon_code
-- Description: Add coupon_code column to carts table for guest and user cart coupon preview
ALTER TABLE `carts` ADD COLUMN `coupon_code` VARCHAR(50) NULL AFTER `session_token`;
