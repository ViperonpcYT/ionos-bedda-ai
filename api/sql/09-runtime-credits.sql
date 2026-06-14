-- Runtime Credits — Roast Account Details (6th IONOS MariaDB)
-- Run in phpMyAdmin on the Roast Account Details database.

CREATE TABLE IF NOT EXISTS runtime_balances (
  customer_id INT UNSIGNED NOT NULL,
  pvp_credits INT NOT NULL DEFAULT 0,
  solo_credits INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS runtime_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id INT UNSIGNED NOT NULL,
  delta_pvp INT NOT NULL DEFAULT 0,
  delta_solo INT NOT NULL DEFAULT 0,
  balance_pvp_after INT NOT NULL DEFAULT 0,
  balance_solo_after INT NOT NULL DEFAULT 0,
  type VARCHAR(32) NOT NULL,
  reference_type VARCHAR(32) NULL,
  reference_id VARCHAR(64) NULL,
  actor VARCHAR(32) NOT NULL DEFAULT 'system',
  note VARCHAR(255) NULL,
  meta_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rl_customer (customer_id),
  KEY idx_rl_type (type),
  KEY idx_rl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ad_unlock_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token CHAR(64) NOT NULL,
  scope ENUM('solo','pvp') NOT NULL,
  guest_id CHAR(36) NULL,
  customer_id INT UNSIGNED NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ad_token (token),
  KEY idx_ad_token_scope (scope, expires_at),
  KEY idx_ad_token_guest (guest_id),
  KEY idx_ad_token_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ad_claim_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope ENUM('solo','pvp','bonus') NOT NULL,
  claim_type ENUM('unlock','bonus') NOT NULL DEFAULT 'unlock',
  ip_hash CHAR(64) NOT NULL,
  session_id CHAR(36) NOT NULL,
  guest_id CHAR(36) NULL,
  customer_id INT UNSIGNED NULL,
  claimed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_acl_ip_day (ip_hash, claimed_at),
  KEY idx_acl_session (session_id, claimed_at),
  KEY idx_acl_guest_day (guest_id, claimed_at),
  KEY idx_acl_customer_day (customer_id, claimed_at),
  KEY idx_acl_scope_ip (scope, ip_hash, claimed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cloud_usage_daily (
  usage_date DATE NOT NULL,
  groq_calls INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (usage_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
