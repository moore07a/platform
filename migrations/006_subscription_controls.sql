-- Adds SaaS subscription controls and fields required when a platform owner creates a farm.

ALTER TABLE users ADD COLUMN email VARCHAR(255) NULL AFTER password;
ALTER TABLE farms ADD COLUMN subscription_starts_at DATETIME NULL AFTER subscription_status;

-- Backfill the new explicit start date from existing trial start/end intent where possible.
UPDATE farms SET subscription_starts_at = created_at WHERE subscription_starts_at IS NULL;
