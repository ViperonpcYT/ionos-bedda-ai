-- Run in the **Coupon Codes** database (dbs15747045) if coupon admin or
-- validate-coupon fails with unknown column type, value, min_total, used_count, or deleted.
-- Safe to re-run: ignore "Duplicate column" errors.
-- Legacy IONOS tables may only have: discount_type, discount_value, usage_count.

SET NAMES utf8mb4;

ALTER TABLE coupon_codes ADD COLUMN type ENUM('percent','fixed') NOT NULL DEFAULT 'percent';
ALTER TABLE coupon_codes ADD COLUMN value DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE coupon_codes ADD COLUMN min_total DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE coupon_codes ADD COLUMN used_count INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE coupon_codes ADD COLUMN deleted TINYINT(1) NOT NULL DEFAULT 0;

UPDATE coupon_codes SET type = CASE
    WHEN LOWER(TRIM(discount_type)) IN ('percent','percentage','%') THEN 'percent'
    ELSE 'fixed'
END
WHERE discount_type IS NOT NULL AND discount_type != ''
  AND (value IS NULL OR value = 0);

UPDATE coupon_codes SET value = discount_value
WHERE discount_value IS NOT NULL AND (value IS NULL OR value = 0);

UPDATE coupon_codes SET used_count = usage_count
WHERE usage_count IS NOT NULL AND used_count = 0;

UPDATE coupon_codes SET deleted = 0 WHERE deleted IS NULL;
UPDATE coupon_codes SET min_total = 0.00 WHERE min_total IS NULL;
