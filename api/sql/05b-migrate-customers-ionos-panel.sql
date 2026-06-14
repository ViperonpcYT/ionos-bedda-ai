-- Run on dbs15747049 if customers table was created from IONOS panel
-- (reward_points, phone, total_spent) without login columns.

ALTER TABLE customers ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) NULL;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS reset_token VARCHAR(255) NULL;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS reset_expires DATETIME NULL;

-- MariaDB 10.11 may not support IF NOT EXISTS on ADD COLUMN — if errors, run lines one at a time and skip duplicates.
