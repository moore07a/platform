-- Creates a dedicated platform workspace so platform owners can sign in without a customer farm workspace.

INSERT INTO farms (name, slug, subscription_plan, subscription_status)
SELECT 'Renee Farms Platform', 'owner', 'platform', 'active'
WHERE NOT EXISTS (SELECT 1 FROM farms WHERE slug = 'owner');

-- Backfill the platform_owner role for legacy user_type-only owner accounts.
INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT u.id, r.id
FROM users u
INNER JOIN roles r ON r.code = 'platform_owner'
WHERE u.user_type = 'platform_owner';

-- Move existing platform-owner accounts onto the dedicated owner workspace.
UPDATE users u
INNER JOIN roles r ON r.code = 'platform_owner'
INNER JOIN user_roles ur ON ur.user_id = u.id AND ur.role_id = r.id
SET u.farm_id = (SELECT id FROM farms WHERE slug = 'owner')
WHERE u.farm_id <> (SELECT id FROM farms WHERE slug = 'owner');
