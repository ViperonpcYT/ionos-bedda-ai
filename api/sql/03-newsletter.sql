-- Database: News Letter Participates (IONOS name example: dbs15100023)
-- Used by: getNewsletterDatabase(), secure-config getDatabase() (email queue / SMTP cron)
-- Run this SQL in phpMyAdmin while that database is selected.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS newsletter_subscribers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL,
  name VARCHAR(255) NULL,
  token VARCHAR(64) NOT NULL,
  unsubscribe_token VARCHAR(64) NOT NULL,
  status ENUM('pending','confirmed','unsubscribed') NOT NULL DEFAULT 'pending',
  confirmed_at DATETIME NULL,
  source VARCHAR(64) NOT NULL DEFAULT 'website',
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_newsletter_email (email),
  KEY idx_newsletter_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_queue (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipient_email VARCHAR(255) NOT NULL,
  recipient_name VARCHAR(255) NULL,
  subject VARCHAR(255) NOT NULL,
  html_body MEDIUMTEXT NOT NULL,
  text_body MEDIUMTEXT NULL,
  unsubscribe_token VARCHAR(64) NULL,
  send_after DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  retry_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_email_queue_pending (status, send_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_delivery_tracking (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  queue_id INT UNSIGNED NOT NULL,
  smtp_transaction_id VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_delivery_queue (queue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
