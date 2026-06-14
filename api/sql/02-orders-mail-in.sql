-- Database: Auto mail-in orders (IONOS name example: dbs15097373)
-- Used by: getOrderDatabase() — submit-order, stripe-webhook, shipping, reconcile-payments
-- Run this SQL in phpMyAdmin while that database is selected.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_number VARCHAR(64) NOT NULL,
  stripe_payment_intent_id VARCHAR(255) NULL,
  stripe_payment_status VARCHAR(32) NULL,
  stripe_subscription_id VARCHAR(255) NULL,
  chitchats_shipment_id VARCHAR(64) NULL,
  customer_name VARCHAR(255) NOT NULL DEFAULT '',
  customer_email VARCHAR(255) NOT NULL,
  phone_number VARCHAR(64) NULL,
  items LONGTEXT NOT NULL,
  subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  shipping_address TEXT NULL,
  shipping_street VARCHAR(255) NULL,
  shipping_address2 VARCHAR(255) NULL,
  shipping_city VARCHAR(128) NULL,
  province VARCHAR(16) NULL,
  postal_code VARCHAR(32) NULL,
  order_date DATETIME NOT NULL,
  ip_address VARCHAR(45) NULL,
  spam_score INT NOT NULL DEFAULT 0,
  fulfillment_method VARCHAR(32) NOT NULL DEFAULT 'shipping',
  pickup_location VARCHAR(255) NULL,
  pickup_date DATE NULL,
  shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  shipping_carrier VARCHAR(64) NULL,
  chitchats_postage_type VARCHAR(64) NULL,
  grand_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  is_subscription TINYINT(1) NOT NULL DEFAULT 0,
  subscription_interval VARCHAR(32) NULL,
  payment_status VARCHAR(32) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'queued',
  tracking_number VARCHAR(128) NULL,
  label_pdf_url TEXT NULL,
  label_created_at DATETIME NULL,
  shipped_at DATETIME NULL,
  inventory_synced TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_number (order_number),
  UNIQUE KEY uq_stripe_pi (stripe_payment_intent_id),
  KEY idx_customer_email (customer_email),
  KEY idx_order_date (order_date),
  KEY idx_status (status),
  KEY idx_stripe_sub (stripe_subscription_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id INT UNSIGNED NOT NULL,
  product_id VARCHAR(64) NOT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_order_id (order_id),
  KEY idx_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id VARCHAR(64) NOT NULL,
  name VARCHAR(255) NOT NULL DEFAULT '',
  stock_in_stock INT NOT NULL DEFAULT 0,
  stock_sold INT NOT NULL DEFAULT 0,
  low_stock_threshold INT NOT NULL DEFAULT 10,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  stripe_event_id VARCHAR(255) NOT NULL,
  event_type VARCHAR(128) NOT NULL,
  processed_at DATETIME NOT NULL,
  metadata JSON NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_stripe_event (stripe_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
