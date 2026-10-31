-- Migration 006: Add Phase 5 checkout attention_reason, pending_verification payment status, and order_notify_email setting

ALTER TABLE `orders`
  ADD COLUMN `attention_reason` VARCHAR(255) NULL AFTER `customer_notes`;

ALTER TABLE `payments`
  MODIFY COLUMN `status` ENUM('initiated', 'pending', 'pending_verification', 'success', 'failed', 'cancelled', 'timeout') NOT NULL DEFAULT 'initiated';

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `type`, `description`)
VALUES ('order_notify_email', 'support@kennedycheroben.co.ke', 'string', 'Recipient email address for new paid order alerts');
