-- Database: UserClicks (IONOS example: dbs15747041)
-- Used by: api/config.php, get-analytics.php, get-summary.php, log-event.php
-- Run in phpMyAdmin while that database is selected.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(64) NOT NULL,
  user_id VARCHAR(128) NULL,
  session_id VARCHAR(128) NULL,
  page_url VARCHAR(512) NULL,
  referrer_url VARCHAR(512) NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(512) NULL,
  event_data JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_event_type (event_type),
  KEY idx_created_at (created_at),
  KEY idx_session (session_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
