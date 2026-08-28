-- Link daily feed-consumption entries to the inventory movement they create.
ALTER TABLE layer_daily_records ADD COLUMN feed_item_id INT NULL AFTER feed_consumption_bags,
    ADD COLUMN feed_stock_transaction_id INT NULL AFTER feed_item_id,
    ADD INDEX idx_layer_feed_item (feed_item_id),
    ADD INDEX idx_layer_feed_transaction (feed_stock_transaction_id),
    ADD CONSTRAINT fk_layer_feed_item FOREIGN KEY (feed_item_id) REFERENCES stock_items(id) ON DELETE RESTRICT;
ALTER TABLE broiler_daily_records ADD COLUMN feed_item_id INT NULL AFTER feed_consumption_bags,
    ADD COLUMN feed_stock_transaction_id INT NULL AFTER feed_item_id,
    ADD INDEX idx_broiler_feed_item (feed_item_id),
    ADD INDEX idx_broiler_feed_transaction (feed_stock_transaction_id),
    ADD CONSTRAINT fk_broiler_feed_item FOREIGN KEY (feed_item_id) REFERENCES stock_items(id) ON DELETE RESTRICT;
ALTER TABLE ruminant_daily_records ADD COLUMN feed_item_id INT NULL AFTER feed_consumption_kg,
    ADD COLUMN feed_stock_transaction_id INT NULL AFTER feed_item_id,
    ADD INDEX idx_ruminant_feed_item (feed_item_id),
    ADD INDEX idx_ruminant_feed_transaction (feed_stock_transaction_id),
    ADD CONSTRAINT fk_ruminant_feed_item FOREIGN KEY (feed_item_id) REFERENCES stock_items(id) ON DELETE RESTRICT;
