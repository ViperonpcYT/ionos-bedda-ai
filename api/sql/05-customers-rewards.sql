-- Database: User Profiles and rewards (IONOS: dbs15747049)
-- Used by: getCustomersDatabase() — customer-auth, points, create-account on checkout
-- Run this SQL in phpMyAdmin while that database is selected.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS customers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  first_name VARCHAR(100) NOT NULL DEFAULT '',
  last_name VARCHAR(100) NOT NULL DEFAULT '',
  points INT NOT NULL DEFAULT 0,
  reset_token VARCHAR(255) NULL,
  reset_expires DATETIME NULL,
  reset_code VARCHAR(10) NULL,
  reset_expires_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
