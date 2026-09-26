-- Apply after sudi_email_auth_migration.sql. Incremental, no data is deleted or merged.
-- A duplicate phone/openid causes the unique index step to fail: resolve ownership first.
SET @db_name = DATABASE();
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='eb_user' AND COLUMN_NAME='sudi_login_phone')=0,
  'ALTER TABLE eb_user ADD COLUMN sudi_login_phone varchar(30) GENERATED ALWAYS AS (CASE WHEN is_del=0 AND phone<>'''' THEN phone ELSE NULL END) STORED', 'SELECT 1');
PREPARE sudi_identity FROM @sql; EXECUTE sudi_identity; DEALLOCATE PREPARE sudi_identity;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='eb_user' AND INDEX_NAME='uniq_sudi_phone')=0,
  'CREATE UNIQUE INDEX uniq_sudi_phone ON eb_user (sudi_login_phone)', 'SELECT 1');
PREPARE sudi_identity FROM @sql; EXECUTE sudi_identity; DEALLOCATE PREPARE sudi_identity;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='eb_wechat_user' AND COLUMN_NAME='sudi_openid')=0,
  'ALTER TABLE eb_wechat_user ADD COLUMN sudi_openid varchar(255) GENERATED ALWAYS AS (CASE WHEN is_del=0 AND openid<>'''' THEN openid ELSE NULL END) STORED', 'SELECT 1');
PREPARE sudi_identity FROM @sql; EXECUTE sudi_identity; DEALLOCATE PREPARE sudi_identity;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='eb_wechat_user' AND INDEX_NAME='uniq_sudi_openid')=0,
  'CREATE UNIQUE INDEX uniq_sudi_openid ON eb_wechat_user (sudi_openid, user_type)', 'SELECT 1');
PREPARE sudi_identity FROM @sql; EXECUTE sudi_identity; DEALLOCATE PREPARE sudi_identity;
