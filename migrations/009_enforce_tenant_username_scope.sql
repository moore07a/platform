-- Ensures identical usernames can exist in different farm workspaces while staying unique inside each farm.
-- This is intentionally idempotent for installations that already ran the multi-tenant index migration.

SET @legacy_username_index := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND index_name = 'username'
);
SET @drop_legacy_username_sql := IF(@legacy_username_index > 0, 'ALTER TABLE users DROP INDEX username', 'SELECT 1');
PREPARE drop_legacy_username_stmt FROM @drop_legacy_username_sql;
EXECUTE drop_legacy_username_stmt;
DEALLOCATE PREPARE drop_legacy_username_stmt;

SET @tenant_username_index := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND index_name = 'uniq_farm_username'
);
SET @add_tenant_username_sql := IF(@tenant_username_index = 0, 'ALTER TABLE users ADD UNIQUE KEY uniq_farm_username (farm_id, username)', 'SELECT 1');
PREPARE add_tenant_username_stmt FROM @add_tenant_username_sql;
EXECUTE add_tenant_username_stmt;
DEALLOCATE PREPARE add_tenant_username_stmt;
