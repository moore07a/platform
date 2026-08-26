-- Tenant product entitlements and multi-role user access.

ALTER TABLE users MODIFY user_type ENUM(
    'platform_admin','owner','admin','poultry_manager','ruminant_manager','sales_manager','viewer'
) NOT NULL;

CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    is_platform_role TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO roles (code, name, is_platform_role) VALUES
    ('platform_admin', 'Platform administrator', 1),
    ('owner', 'Farm owner', 0),
    ('admin', 'Farm administrator', 0),
    ('poultry_manager', 'Poultry manager', 0),
    ('ruminant_manager', 'Ruminant manager', 0),
    ('sales_manager', 'Sales manager', 0),
    ('viewer', 'Viewer', 0);

CREATE TABLE IF NOT EXISTS user_roles (
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
);

INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT u.id, r.id FROM users u INNER JOIN roles r ON r.code = u.user_type;

CREATE TABLE IF NOT EXISTS farm_modules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    farm_id INT NOT NULL,
    module_code ENUM('poultry','ruminant','sales') NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    enabled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_farm_module (farm_id, module_code),
    INDEX idx_farm_modules_enabled (farm_id, is_enabled),
    CONSTRAINT fk_farm_modules_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE CASCADE
);

-- Preserve the current single-farm experience by enabling all product modules for Renee.
INSERT IGNORE INTO farm_modules (farm_id, module_code, is_enabled)
SELECT id, 'poultry', 1 FROM farms WHERE slug = 'renee-farms';
INSERT IGNORE INTO farm_modules (farm_id, module_code, is_enabled)
SELECT id, 'ruminant', 1 FROM farms WHERE slug = 'renee-farms';
INSERT IGNORE INTO farm_modules (farm_id, module_code, is_enabled)
SELECT id, 'sales', 1 FROM farms WHERE slug = 'renee-farms';
