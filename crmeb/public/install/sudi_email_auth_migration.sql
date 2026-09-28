-- Safe additive upgrade for existing CRMEB installations. Does not touch user data.
SET @db_name = DATABASE();
SET @column_count = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'eb_user' AND COLUMN_NAME = 'email'
);
SET @sql = IF(@column_count = 0,
  'ALTER TABLE `eb_user` ADD COLUMN `email` varchar(100) DEFAULT NULL COMMENT ''verified login email'' AFTER `account`',
  'SELECT 1');
PREPARE sudi_email_column FROM @sql; EXECUTE sudi_email_column; DEALLOCATE PREPARE sudi_email_column;

SET @index_count = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'eb_user' AND INDEX_NAME = 'uniq_user_email'
);
SET @sql = IF(@index_count = 0,
  'CREATE UNIQUE INDEX `uniq_user_email` ON `eb_user` (`email`)',
  'SELECT 1');
PREPARE sudi_email_index FROM @sql; EXECUTE sudi_email_index; DEALLOCATE PREPARE sudi_email_index;

SET @sql = IF((SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'eb_user' AND COLUMN_NAME = 'pwd') < 255,
  'ALTER TABLE `eb_user` MODIFY COLUMN `pwd` varchar(255) NOT NULL DEFAULT '''' COMMENT ''user password hash''',
  'SELECT 1');
PREPARE sudi_user_pwd FROM @sql; EXECUTE sudi_user_pwd; DEALLOCATE PREPARE sudi_user_pwd;
