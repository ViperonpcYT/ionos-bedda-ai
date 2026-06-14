-- Bike roast pipeline checkpoints (Analytics DB)
-- Run in phpMyAdmin while analytics database is selected.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS roast_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id CHAR(36) NOT NULL,
  status ENUM('processing','partial','complete','failed') NOT NULL DEFAULT 'processing',
  phase VARCHAR(32) NOT NULL DEFAULT 'pending',
  identity_json JSON NULL,
  inspect_json JSON NULL,
  roast_text MEDIUMTEXT NULL,
  score SMALLINT UNSIGNED NULL,
  steps_json JSON NULL,
  error_json JSON NULL,
  image_hash CHAR(64) NULL,
  ip_hash CHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roast_job_id (job_id),
  KEY idx_roast_status (status, updated_at),
  KEY idx_roast_ip_day (ip_hash, created_at),
  KEY idx_roast_image_hash (image_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
