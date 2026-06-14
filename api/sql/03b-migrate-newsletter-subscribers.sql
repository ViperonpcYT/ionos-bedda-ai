-- Run in the **News Letter** database (phpMyAdmin) if email-admin fails with:
-- Unknown column 'status' in 'WHERE'
-- Safe to run once; ignore "Duplicate column" if you re-run.

SET NAMES utf8mb4;

ALTER TABLE newsletter_subscribers
  ADD COLUMN status ENUM('pending','confirmed','unsubscribed') NOT NULL DEFAULT 'pending';

-- If you still have a legacy boolean column:
-- UPDATE newsletter_subscribers SET status = 'unsubscribed' WHERE unsubscribed = 1;
-- UPDATE newsletter_subscribers SET status = 'confirmed' WHERE unsubscribed = 0 OR unsubscribed IS NULL;

-- If neither status nor unsubscribed existed before (plain email list):
-- UPDATE newsletter_subscribers SET status = 'confirmed';

ALTER TABLE newsletter_subscribers
  ADD COLUMN confirmed_at DATETIME NULL;
