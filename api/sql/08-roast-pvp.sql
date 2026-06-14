-- Bike roast PvP matchmaking (Roast DB)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS roast_pvp_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  queue_token CHAR(64) NOT NULL,
  match_id CHAR(36) NULL,
  status ENUM('waiting','matched','cancelled') NOT NULL DEFAULT 'waiting',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roast_pvp_queue_token (queue_token),
  KEY idx_roast_pvp_queue_waiting (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roast_pvp_matches (
  match_id CHAR(36) NOT NULL,
  player_a_token CHAR(64) NOT NULL,
  player_b_token CHAR(64) NULL,
  player_a_job_id CHAR(36) NULL,
  player_b_job_id CHAR(36) NULL,
  status ENUM('matched','dueling','complete','expired','cancelled') NOT NULL DEFAULT 'matched',
  winner ENUM('a','b','t') NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (match_id),
  KEY idx_roast_pvp_match_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
