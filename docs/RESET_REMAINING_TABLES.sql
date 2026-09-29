-- Run in phpMyAdmin only after a full database backup.
-- The selected Nexora database must contain only these two leftover tables.
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `mc_brand`, `mc_media_asset`;
SET FOREIGN_KEY_CHECKS = 1;
