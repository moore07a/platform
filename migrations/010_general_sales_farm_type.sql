-- Give sales-only records a durable classification that does not change when
-- livestock module entitlements are updated.
ALTER TABLE sales_records
    MODIFY farm_type ENUM('poultry','ruminant','general') NOT NULL;
