-- Run in the **Auto mail-in orders** database if shipping admin fails with:
-- Unknown column 'order_date' in 'ORDER BY'
-- Ignore "Duplicate column" if re-running.

SET NAMES utf8mb4;

ALTER TABLE orders ADD COLUMN order_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

UPDATE orders SET order_date = created_at
WHERE (order_date IS NULL OR order_date = '0000-00-00 00:00:00')
  AND created_at IS NOT NULL;
