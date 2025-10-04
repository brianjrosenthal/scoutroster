-- Add unsubscribed field to users table
-- This allows users to opt out of email communications

ALTER TABLE users
ADD COLUMN unsubscribed TINYINT(1) NOT NULL DEFAULT 0
AFTER password_reset_expires_at;

-- Add index for efficient filtering
CREATE INDEX idx_users_unsubscribed ON users(unsubscribed);
