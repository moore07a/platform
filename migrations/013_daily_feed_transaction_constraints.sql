-- Prevent automatic feed movements from being removed while a daily record links to them.
ALTER TABLE layer_daily_records ADD CONSTRAINT fk_layer_feed_transaction FOREIGN KEY (feed_stock_transaction_id) REFERENCES stock_transactions(id) ON DELETE RESTRICT;
ALTER TABLE broiler_daily_records ADD CONSTRAINT fk_broiler_feed_transaction FOREIGN KEY (feed_stock_transaction_id) REFERENCES stock_transactions(id) ON DELETE RESTRICT;
ALTER TABLE ruminant_daily_records ADD CONSTRAINT fk_ruminant_feed_transaction FOREIGN KEY (feed_stock_transaction_id) REFERENCES stock_transactions(id) ON DELETE RESTRICT;
