-- Phase 2A: invalidate pre-reset administrator sessions.
ALTER TABLE `users` ADD COLUMN `password_changed_at` DATETIME NULL AFTER `remember_token_hash`;
