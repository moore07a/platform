-- Farm Management System Database Schema
-- Derived from application code (PHP) in repository

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    farm_id INT NOT NULL,
    username VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    user_type ENUM('owner','poultry_manager','ruminant_manager','sales_manager') NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    last_login_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_farm_username (farm_id, username),
    INDEX idx_users_farm (farm_id)
);

CREATE TABLE permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role VARCHAR(100) NOT NULL,
    module VARCHAR(100) NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY unique_role_module (role, module)
);

CREATE TABLE inventory_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(150) NOT NULL,
    farm_type ENUM('poultry','ruminant','both') NOT NULL DEFAULT 'both',
    unit VARCHAR(50) NOT NULL DEFAULT 'unit',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE stock_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(150) NOT NULL,
    category_id INT NOT NULL,
    current_stock DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
    min_stock_level DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit VARCHAR(50) NOT NULL,
    farm_type ENUM('poultry','ruminant','both') NOT NULL DEFAULT 'both',
    feed_category ENUM('general','layer','broiler','ruminant') NOT NULL DEFAULT 'general',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES inventory_categories(id)
);

CREATE TABLE stock_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    stock_item_id INT NOT NULL,
    transaction_type ENUM('received','used') NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    previous_stock DECIMAL(12,2) NOT NULL,
    new_stock DECIMAL(12,2) NOT NULL,
    transaction_date DATE NOT NULL,
    remarks TEXT NULL,
    user_id INT NULL,
    farm_type ENUM('poultry','ruminant','both') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (stock_item_id) REFERENCES stock_items(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE production_cycles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cycle_code VARCHAR(100) NOT NULL UNIQUE,
    farm_type ENUM('poultry','ruminant','general') NOT NULL,
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
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE stock_batches (
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
    FOREIGN KEY (cycle_id) REFERENCES production_cycles(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE sales_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_date DATE NOT NULL,
    farm_type ENUM('poultry','ruminant') NOT NULL,
    cycle_id INT NULL,
    product_type VARCHAR(150) NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    total_amount DECIMAL(14,2) AS (quantity * unit_price) STORED,
    customer_name VARCHAR(150) NULL,
    remarks TEXT NULL,
    user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sale_month (sale_date),
    INDEX idx_sales_cycle_date (cycle_id, sale_date),
    FOREIGN KEY (cycle_id) REFERENCES production_cycles(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE customer_ledger_entries (
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
    FOREIGN KEY (sale_id) REFERENCES sales_records(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE farm_expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    expense_date DATE NOT NULL,
    farm_type ENUM('poultry','ruminant','both') NOT NULL DEFAULT 'both',
    cycle_id INT NULL,
    category ENUM('feeds','medication','salary','logistic','fuel','misc') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    unit DECIMAL(12,2) NOT NULL DEFAULT 1,
    description TEXT NULL,
    user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_expense_month (expense_date),
    INDEX idx_expense_cycle_date (cycle_id, expense_date),
    FOREIGN KEY (cycle_id) REFERENCES production_cycles(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE layer_daily_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cycle_id INT NULL,
    record_date DATE NOT NULL,
    opening_stock INT NOT NULL,
    mortality INT NOT NULL DEFAULT 0,
    feed_consumption_bags DECIMAL(12,2) NOT NULL DEFAULT 0,
    water_consumption_liters DECIMAL(12,2) NOT NULL DEFAULT 0,
    medications TEXT NULL,
    egg_production INT NOT NULL DEFAULT 0,
    crates_count INT NOT NULL DEFAULT 0,
    laying_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    birds_age INT NOT NULL,
    remarks TEXT NULL,
    user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_layer_cycle_record (cycle_id, record_date),
    INDEX idx_layer_cycle_date (cycle_id, record_date),
    FOREIGN KEY (cycle_id) REFERENCES production_cycles(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE broiler_daily_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cycle_id INT NULL,
    record_date DATE NOT NULL,
    opening_stock INT NOT NULL,
    mortality INT NOT NULL DEFAULT 0,
    feed_consumption_bags DECIMAL(12,2) NOT NULL DEFAULT 0,
    water_consumption_liters DECIMAL(12,2) NOT NULL DEFAULT 0,
    medications TEXT NULL,
    birds_age INT NOT NULL,
    remarks TEXT NULL,
    user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_broiler_cycle_record (cycle_id, record_date),
    INDEX idx_broiler_cycle_date (cycle_id, record_date),
    FOREIGN KEY (cycle_id) REFERENCES production_cycles(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE ruminant_daily_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cycle_id INT NULL,
    record_date DATE NOT NULL,
    animal_type VARCHAR(100) NOT NULL,
    opening_stock INT NOT NULL,
    mortality INT NOT NULL DEFAULT 0,
    feed_consumption_kg DECIMAL(12,2) NOT NULL DEFAULT 0,
    water_consumption_liters DECIMAL(12,2) NOT NULL DEFAULT 0,
    other_details TEXT NULL,
    tag_no VARCHAR(100) NULL,
    medications TEXT NULL,
    reproduction_details TEXT NULL,
    remarks TEXT NULL,
    user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ruminant_cycle_animal (record_date, animal_type, cycle_id),
    INDEX idx_ruminant_cycle_date (cycle_id, record_date),
    FOREIGN KEY (cycle_id) REFERENCES production_cycles(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE profit_loss_summary (
    id INT AUTO_INCREMENT PRIMARY KEY,
    month CHAR(7) NOT NULL,
    farm_type ENUM('poultry','ruminant','both') NOT NULL DEFAULT 'both',
    total_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_expenses DECIMAL(14,2) NOT NULL DEFAULT 0,
    profit DECIMAL(14,2) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_month_farm (month, farm_type)
);
