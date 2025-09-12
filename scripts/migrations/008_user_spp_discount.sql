-- Add per-user SPP scholarship/discount settings
ALTER TABLE `users`
  ADD COLUMN `spp_discount_type` ENUM('percent','nominal') NULL DEFAULT NULL AFTER `saldo`,
  ADD COLUMN `spp_discount_value` DECIMAL(12,2) NULL DEFAULT NULL AFTER `spp_discount_type`,
  ADD COLUMN `spp_discount_until` CHAR(6) NULL DEFAULT NULL COMMENT 'YYYYMM (inclusive)' AFTER `spp_discount_value`;

-- To remove (rollback):
-- ALTER TABLE `users` DROP COLUMN `spp_discount_until`, DROP COLUMN `spp_discount_value`, DROP COLUMN `spp_discount_type`;
