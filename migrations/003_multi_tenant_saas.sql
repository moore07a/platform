-- Converts the application from a single farm installation to a shared, tenant-isolated SaaS.
-- The first tenant preserves the existing Renee Farms data during upgrade.

CREATE TABLE IF NOT EXISTS farms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    logo_path VARCHAR(255) NULL,
    primary_color VARCHAR(20) NOT NULL DEFAULT '#198754',
    contact_name VARCHAR(150) NULL,
    contact_email VARCHAR(255) NULL,
    subscription_plan VARCHAR(50) NOT NULL DEFAULT 'starter',
    subscription_status ENUM('trial','active','past_due','suspended','cancelled') NOT NULL DEFAULT 'trial',
    trial_ends_at DATETIME NULL,
    subscription_ends_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO farms (name, slug, subscription_plan, subscription_status)
SELECT 'Renee Farms Ltd', 'renee-farms', 'pro', 'active'
WHERE NOT EXISTS (SELECT 1 FROM farms WHERE slug = 'renee-farms');

CREATE TABLE IF NOT EXISTS subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    farm_id INT NOT NULL,
    plan_code VARCHAR(50) NOT NULL,
    status ENUM('trial','active','past_due','suspended','cancelled') NOT NULL,
    billing_interval ENUM('monthly','annual') NOT NULL DEFAULT 'monthly',
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    provider VARCHAR(50) NULL,
    provider_subscription_id VARCHAR(150) NULL,
    current_period_ends_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_subscription_farm_status (farm_id, status),
    CONSTRAINT fk_subscription_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE CASCADE
);

ALTER TABLE users ADD COLUMN farm_id INT NULL AFTER id;
ALTER TABLE inventory_categories ADD COLUMN farm_id INT NULL AFTER id;
ALTER TABLE stock_items ADD COLUMN farm_id INT NULL AFTER id;
ALTER TABLE stock_transactions ADD COLUMN farm_id INT NULL AFTER id;
ALTER TABLE production_cycles ADD COLUMN farm_id INT NULL AFTER id;
ALTER TABLE stock_batches ADD COLUMN farm_id INT NULL AFTER id;
ALTER TABLE sales_records ADD COLUMN farm_id INT NULL AFTER id;
ALTER TABLE customer_ledger_entries ADD COLUMN farm_id INT NULL AFTER id;
ALTER TABLE farm_expenses ADD COLUMN farm_id INT NULL AFTER id;
ALTER TABLE layer_daily_records ADD COLUMN farm_id INT NULL AFTER id;
ALTER TABLE broiler_daily_records ADD COLUMN farm_id INT NULL AFTER id;
ALTER TABLE ruminant_daily_records ADD COLUMN farm_id INT NULL AFTER id;
ALTER TABLE profit_loss_summary ADD COLUMN farm_id INT NULL AFTER id;

UPDATE users SET farm_id = (SELECT id FROM farms WHERE slug = 'renee-farms') WHERE farm_id IS NULL;
UPDATE inventory_categories SET farm_id = (SELECT id FROM farms WHERE slug = 'renee-farms') WHERE farm_id IS NULL;
UPDATE stock_items SET farm_id = (SELECT id FROM farms WHERE slug = 'renee-farms') WHERE farm_id IS NULL;
UPDATE stock_transactions SET farm_id = (SELECT id FROM farms WHERE slug = 'renee-farms') WHERE farm_id IS NULL;
UPDATE production_cycles SET farm_id = (SELECT id FROM farms WHERE slug = 'renee-farms') WHERE farm_id IS NULL;
UPDATE stock_batches SET farm_id = (SELECT id FROM farms WHERE slug = 'renee-farms') WHERE farm_id IS NULL;
UPDATE sales_records SET farm_id = (SELECT id FROM farms WHERE slug = 'renee-farms') WHERE farm_id IS NULL;
UPDATE customer_ledger_entries SET farm_id = (SELECT id FROM farms WHERE slug = 'renee-farms') WHERE farm_id IS NULL;
UPDATE farm_expenses SET farm_id = (SELECT id FROM farms WHERE slug = 'renee-farms') WHERE farm_id IS NULL;
UPDATE layer_daily_records SET farm_id = (SELECT id FROM farms WHERE slug = 'renee-farms') WHERE farm_id IS NULL;
UPDATE broiler_daily_records SET farm_id = (SELECT id FROM farms WHERE slug = 'renee-farms') WHERE farm_id IS NULL;
UPDATE ruminant_daily_records SET farm_id = (SELECT id FROM farms WHERE slug = 'renee-farms') WHERE farm_id IS NULL;
UPDATE profit_loss_summary SET farm_id = (SELECT id FROM farms WHERE slug = 'renee-farms') WHERE farm_id IS NULL;

ALTER TABLE users MODIFY farm_id INT NOT NULL;
ALTER TABLE inventory_categories MODIFY farm_id INT NOT NULL;
ALTER TABLE stock_items MODIFY farm_id INT NOT NULL;
ALTER TABLE stock_transactions MODIFY farm_id INT NOT NULL;
ALTER TABLE production_cycles MODIFY farm_id INT NOT NULL;
ALTER TABLE stock_batches MODIFY farm_id INT NOT NULL;
ALTER TABLE sales_records MODIFY farm_id INT NOT NULL;
ALTER TABLE customer_ledger_entries MODIFY farm_id INT NOT NULL;
ALTER TABLE farm_expenses MODIFY farm_id INT NOT NULL;
ALTER TABLE layer_daily_records MODIFY farm_id INT NOT NULL;
ALTER TABLE broiler_daily_records MODIFY farm_id INT NOT NULL;
ALTER TABLE ruminant_daily_records MODIFY farm_id INT NOT NULL;
ALTER TABLE profit_loss_summary MODIFY farm_id INT NOT NULL;

ALTER TABLE users DROP INDEX username, ADD UNIQUE KEY uniq_farm_username (farm_id, username), ADD INDEX idx_users_farm (farm_id);
ALTER TABLE inventory_categories ADD INDEX idx_categories_farm (farm_id);
ALTER TABLE stock_items ADD INDEX idx_stock_items_farm (farm_id);
ALTER TABLE stock_transactions ADD INDEX idx_transactions_farm (farm_id);
ALTER TABLE production_cycles DROP INDEX cycle_code, ADD UNIQUE KEY uniq_farm_cycle_code (farm_id, cycle_code), ADD INDEX idx_cycles_farm (farm_id);
ALTER TABLE stock_batches ADD INDEX idx_batches_farm (farm_id);
ALTER TABLE sales_records ADD INDEX idx_sales_farm (farm_id);
ALTER TABLE customer_ledger_entries ADD INDEX idx_ledger_farm (farm_id);
ALTER TABLE farm_expenses ADD INDEX idx_expenses_farm (farm_id);
ALTER TABLE layer_daily_records ADD INDEX idx_layer_farm (farm_id);
ALTER TABLE broiler_daily_records ADD INDEX idx_broiler_farm (farm_id);
ALTER TABLE ruminant_daily_records ADD INDEX idx_ruminant_farm (farm_id);
ALTER TABLE profit_loss_summary DROP INDEX uniq_month_farm, ADD UNIQUE KEY uniq_tenant_month_farm (farm_id, month, farm_type);

ALTER TABLE users ADD CONSTRAINT fk_users_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE RESTRICT;
ALTER TABLE inventory_categories ADD CONSTRAINT fk_categories_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE RESTRICT;
ALTER TABLE stock_items ADD CONSTRAINT fk_stock_items_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE RESTRICT;
ALTER TABLE stock_transactions ADD CONSTRAINT fk_transactions_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE RESTRICT;
ALTER TABLE production_cycles ADD CONSTRAINT fk_cycles_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE RESTRICT;
ALTER TABLE stock_batches ADD CONSTRAINT fk_batches_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE RESTRICT;
ALTER TABLE sales_records ADD CONSTRAINT fk_sales_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE RESTRICT;
ALTER TABLE customer_ledger_entries ADD CONSTRAINT fk_ledger_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE RESTRICT;
ALTER TABLE farm_expenses ADD CONSTRAINT fk_expenses_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE RESTRICT;
ALTER TABLE layer_daily_records ADD CONSTRAINT fk_layer_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE RESTRICT;
ALTER TABLE broiler_daily_records ADD CONSTRAINT fk_broiler_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE RESTRICT;
ALTER TABLE ruminant_daily_records ADD CONSTRAINT fk_ruminant_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE RESTRICT;
ALTER TABLE profit_loss_summary ADD CONSTRAINT fk_profit_loss_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE RESTRICT;
