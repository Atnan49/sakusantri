-- Migration 007: add avatar column to users table
-- Adds optional avatar filename reference (stored in public/assets/uploads/)
ALTER TABLE `users`
  ADD COLUMN `avatar` VARCHAR(255) NULL AFTER `saldo`;

-- You can rollback by (WARNING: will drop data):
-- ALTER TABLE `users` DROP COLUMN `avatar`;