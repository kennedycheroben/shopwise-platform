-- Migration 003: Update contact_messages status enum to include read and replied
ALTER TABLE `contact_messages`
  MODIFY COLUMN `status` ENUM('new', 'read', 'replied', 'in_progress', 'resolved', 'archived') NOT NULL DEFAULT 'new';
