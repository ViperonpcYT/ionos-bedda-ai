-- Database: User Profiles and rewards (IONOS: dbs15747049)
-- Points audit ledger — run in phpMyAdmin on the customers DB.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS points_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id INT UNSIGNED NOT NULL,
  delta INT NOT NULL,
  balance_after INT NOT NULL,
  type VARCHAR(32) NOT NULL,
  reference_type VARCHAR(32) NULL,
  reference_id VARCHAR(64) NULL,
  order_number VARCHAR(64) NULL,
  amount_cad DECIMAL(10,2) NULL,
  points_used INT NULL,
  points_earned INT NULL,
  ip_address VARCHAR(45) NULL,
  actor VARCHAR(32) NOT NULL DEFAULT 'system',
  note VARCHAR(255) NULL,
  meta_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pl_customer (customer_id),
  KEY idx_pl_type (type),
  KEY idx_pl_order (order_number),
  KEY idx_pl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional columns on orders DB (dbs15747042) — also added via ensureOrdersSchema()
-- ALTER TABLE orders ADD COLUMN customer_id INT UNSIGNED NULL;
-- ALTER TABLE orders ADD COLUMN points_used INT NOT NULL DEFAULT 0;
-- ALTER TABLE orders ADD COLUMN points_earned INT NOT NULL DEFAULT 0;
-- ALTER TABLE orders ADD COLUMN points_discount_cad DECIMAL(10,2) NOT NULL DEFAULT 0.00;
