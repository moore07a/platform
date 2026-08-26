-- Schema upgrades moved out of request lifecycle.
-- Execute with: php scripts/run_migrations.php

ALTER TABLE stock_items ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER farm_type;
ALTER TABLE stock_items ADD COLUMN unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER current_stock;
ALTER TABLE farm_expenses ADD COLUMN unit DECIMAL(12,2) NOT NULL DEFAULT 1 AFTER amount;
ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL DEFAULT NULL AFTER full_name;
ALTER TABLE farm_expenses ADD COLUMN poultry_category ENUM('broiler','layer') NULL DEFAULT NULL AFTER farm_type;

CREATE TABLE permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role VARCHAR(100) NOT NULL,
    module VARCHAR(100) NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY unique_role_module (role, module)
);

CREATE TABLE IF NOT EXISTS customer_ledger_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(150) NOT NULL,
    entry_date DATE NOT NULL,
    entry_type ENUM('sale','payment','adjustment') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    sale_id INT NULL,
    notes TEXT NULL,
    user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer_date (customer_name, entry_date),
    INDEX idx_entry_type (entry_type),
    CONSTRAINT fk_ledger_sale FOREIGN KEY (sale_id) REFERENCES sales_records(id) ON DELETE SET NULL,
    CONSTRAINT fk_ledger_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
