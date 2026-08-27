-- Repair installations where the role-alignment migration was interrupted.
-- Farm provisioning also performs this small repair defensively, but keeping it
-- in the migration history fixes existing users before their next interaction.

INSERT INTO roles (code, name, is_platform_role) VALUES
    ('platform_owner', 'Owner / Developer', 1),
    ('farm_admin', 'Admin / Farm Owner', 0),
    ('poultry_manager', 'Poultry Manager', 0),
    ('ruminant_manager', 'Ruminant Manager', 0),
    ('sales_rep', 'Sales Representative', 0),
    ('viewer', 'Viewer', 0)
ON DUPLICATE KEY UPDATE name = VALUES(name), is_platform_role = VALUES(is_platform_role);

INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT u.id, r.id
FROM users u
INNER JOIN roles r ON r.code = CASE
    WHEN u.user_type IN ('platform_owner', 'platform_admin') THEN 'platform_owner'
    WHEN u.user_type IN ('farm_admin', 'owner', 'admin') THEN 'farm_admin'
    WHEN u.user_type = 'poultry_manager' THEN 'poultry_manager'
    WHEN u.user_type = 'ruminant_manager' THEN 'ruminant_manager'
    WHEN u.user_type IN ('sales_rep', 'sales_manager') THEN 'sales_rep'
    WHEN u.user_type = 'viewer' THEN 'viewer'
END;
