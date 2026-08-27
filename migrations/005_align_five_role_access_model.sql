-- Align legacy SaaS roles with the agreed five-role access model:
-- platform_owner, farm_admin, ruminant_manager, poultry_manager, sales_rep.

ALTER TABLE users MODIFY user_type ENUM(
    'platform_admin','platform_owner','owner','admin','farm_admin',
    'poultry_manager','ruminant_manager','sales_manager','sales_rep','viewer'
) NOT NULL;

INSERT INTO roles (code, name, is_platform_role)
VALUES ('farm_admin', 'Admin / Farm Owner', 0)
ON DUPLICATE KEY UPDATE name = VALUES(name), is_platform_role = VALUES(is_platform_role);

INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT ur.user_id, farm_admin.id
FROM user_roles ur
INNER JOIN roles legacy ON legacy.id = ur.role_id AND legacy.code IN ('owner', 'admin')
INNER JOIN roles farm_admin ON farm_admin.code = 'farm_admin';

DELETE ur FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE r.code IN ('owner', 'admin');
DELETE FROM roles WHERE code IN ('owner', 'admin');

UPDATE roles SET code = 'platform_owner', name = 'Owner / Developer' WHERE code = 'platform_admin';
UPDATE roles SET code = 'sales_rep', name = 'Sales Representative' WHERE code = 'sales_manager';

UPDATE users SET user_type = 'platform_owner' WHERE user_type = 'platform_admin';
UPDATE users SET user_type = 'farm_admin' WHERE user_type IN ('owner', 'admin');
UPDATE users SET user_type = 'sales_rep' WHERE user_type = 'sales_manager';

DELETE FROM permissions WHERE role IN ('owner', 'admin', 'platform_admin', 'sales_manager');

ALTER TABLE users MODIFY user_type ENUM(
    'platform_owner','farm_admin','poultry_manager','ruminant_manager','sales_rep','viewer'
) NOT NULL;
