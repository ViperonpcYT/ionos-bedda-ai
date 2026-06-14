-- Run in the **News Letter** database if queue/logs fail with:
-- Unknown column 'send_after' (or 'status', 'retry_count', etc.)
-- Ignore "Duplicate column" errors if re-running.

SET NAMES utf8mb4;

ALTER TABLE email_queue ADD COLUMN recipient_name VARCHAR(255) NULL;
ALTER TABLE email_queue ADD COLUMN text_body MEDIUMTEXT NULL;
ALTER TABLE email_queue ADD COLUMN unsubscribe_token VARCHAR(64) NULL;
ALTER TABLE email_queue ADD COLUMN send_after DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE email_queue ADD COLUMN status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending';
ALTER TABLE email_queue ADD COLUMN retry_count INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE email_queue ADD COLUMN last_error VARCHAR(255) NULL;
ALTER TABLE email_queue ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE email_queue ADD COLUMN sent_at DATETIME NULL;

UPDATE email_queue SET send_after = COALESCE(created_at, NOW())
WHERE send_after IS NULL OR send_after = '0000-00-00 00:00:00';

UPDATE email_queue SET status = 'pending' WHERE status IS NULL OR status = '';
