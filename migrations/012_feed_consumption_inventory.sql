CREATE TABLE IF NOT EXISTS feed_consumption_inventory_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    farm_id INT NOT NULL,
    record_type ENUM('layer','broiler','ruminant') NOT NULL,
    record_id INT NOT NULL,
    stock_item_id INT NOT NULL,
    stock_transaction_id INT NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    unit VARCHAR(50) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_feed_consumption_record (farm_id, record_type, record_id),
    INDEX idx_feed_consumption_item (farm_id, stock_item_id),
    CONSTRAINT fk_feed_consumption_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id),
    CONSTRAINT fk_feed_consumption_transaction FOREIGN KEY (stock_transaction_id) REFERENCES stock_transactions(id)
);
