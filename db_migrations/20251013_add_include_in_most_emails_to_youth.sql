-- Add include_in_most_emails field to youth table
-- This allows marking youth whose families should receive emails even if not registered

ALTER TABLE youth 
ADD COLUMN include_in_most_emails TINYINT(1) NOT NULL DEFAULT 0 
COMMENT 'Include this youth''s family in most email communications (active leads)';

CREATE INDEX idx_youth_include_in_most_emails ON youth(include_in_most_emails);
