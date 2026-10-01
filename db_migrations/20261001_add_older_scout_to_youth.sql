-- Add older_scout flag to youth table
-- Marks a youth in grade 6+ who is a scout in their own right (not just a sibling of a member).
-- Allows adding grade 6-12 youth without the "sibling" flag.

ALTER TABLE youth
ADD COLUMN older_scout TINYINT(1) NOT NULL DEFAULT 0
COMMENT 'Older scout (grade 6+) who is a member in their own right, not a sibling'
AFTER sibling;
