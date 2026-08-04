-- Add include_in_most_emails field to users table
-- This allows marking adults who should always be included in "Registered + Active Leads"
-- emails regardless of their children's registration/grade status

ALTER TABLE users
ADD COLUMN include_in_most_emails TINYINT(1) NOT NULL DEFAULT 0
COMMENT 'Always include this adult in most email communications'
AFTER unsubscribed;

CREATE INDEX idx_users_include_in_most_emails ON users(include_in_most_emails);
