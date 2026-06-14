-- Database: Coupon Codes (IONOS name example: dbs15627937)
-- Used by: getCouponDatabase() — validate-coupon, manage-coupons, reward coupons
-- Run this SQL in phpMyAdmin while that database is selected.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS coupon_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(64) NOT NULL,
  type ENUM('percent','fixed') NOT NULL,
  value DECIMAL(10,2) NOT NULL,
  min_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  expires_at DATETIME NULL,
  usage_limit INT UNSIGNED NULL,
  used_count INT UNSIGNED NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  deleted TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_coupon_code (code),
  KEY idx_coupon_active (active, deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
