-- Add optional year scope for per-user SPP scholarship/discount
ALTER TABLE `users`
  ADD COLUMN `spp_discount_year` INT NULL DEFAULT NULL AFTER `spp_discount_until`;

-- To rollback:
-- ALTER TABLE `users` DROP COLUMN `spp_discount_year`;
