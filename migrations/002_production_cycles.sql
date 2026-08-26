-- Adds support for overlapping production cycles for poultry and ruminant operations.

CREATE TABLE IF NOT EXISTS production_cycles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cycle_code VARCHAR(100) NOT NULL UNIQUE,
    farm_type ENUM('poultry','ruminant') NOT NULL,
    production_type VARCHAR(100) NOT NULL,
    status ENUM('planned','active','closed','archived') NOT NULL DEFAULT 'planned',
    start_date DATE NOT NULL,
    expected_end_date DATE NULL,
    close_date DATE NULL,
    opening_headcount INT NOT NULL DEFAULT 0,
    closing_headcount INT NULL,
    notes TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cycle_status (farm_type, production_type, status),
    INDEX idx_cycle_dates (start_date, close_date),
    CONSTRAINT fk_cycles_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS stock_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cycle_id INT NOT NULL,
    batch_code VARCHAR(100) NULL,
    item_description VARCHAR(150) NOT NULL,
    quantity INT NOT NULL,
    unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
    supplier_name VARCHAR(150) NULL,
    received_date DATE NOT NULL,
    notes TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_batch_cycle_date (cycle_id, received_date),
    CONSTRAINT fk_batches_cycle FOREIGN KEY (cycle_id) REFERENCES production_cycles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_batches_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

ALTER TABLE broiler_daily_records
    ADD COLUMN cycle_id INT NULL AFTER id,
    DROP INDEX record_date,
    ADD UNIQUE KEY uniq_broiler_cycle_record (cycle_id, record_date),
    ADD INDEX idx_broiler_cycle_date (cycle_id, record_date),
    ADD CONSTRAINT fk_broiler_cycle FOREIGN KEY (cycle_id) REFERENCES production_cycles(id) ON DELETE SET NULL;

ALTER TABLE layer_daily_records
    ADD COLUMN cycle_id INT NULL AFTER id,
    DROP INDEX record_date,
    ADD UNIQUE KEY uniq_layer_cycle_record (cycle_id, record_date),
    ADD INDEX idx_layer_cycle_date (cycle_id, record_date),
    ADD CONSTRAINT fk_layer_cycle FOREIGN KEY (cycle_id) REFERENCES production_cycles(id) ON DELETE SET NULL;

ALTER TABLE ruminant_daily_records
    ADD COLUMN cycle_id INT NULL AFTER id,
    DROP INDEX uniq_record_animal,
    ADD UNIQUE KEY uniq_ruminant_cycle_animal (record_date, animal_type, cycle_id),
    ADD INDEX idx_ruminant_cycle_date (cycle_id, record_date),
    ADD CONSTRAINT fk_ruminant_cycle FOREIGN KEY (cycle_id) REFERENCES production_cycles(id) ON DELETE SET NULL;

ALTER TABLE sales_records
    ADD COLUMN cycle_id INT NULL AFTER farm_type,
    ADD INDEX idx_sales_cycle_date (cycle_id, sale_date),
    ADD CONSTRAINT fk_sales_cycle FOREIGN KEY (cycle_id) REFERENCES production_cycles(id) ON DELETE SET NULL;

ALTER TABLE farm_expenses
    ADD COLUMN cycle_id INT NULL AFTER farm_type,
    ADD INDEX idx_expense_cycle_date (cycle_id, expense_date),
    ADD CONSTRAINT fk_expense_cycle FOREIGN KEY (cycle_id) REFERENCES production_cycles(id) ON DELETE SET NULL;
