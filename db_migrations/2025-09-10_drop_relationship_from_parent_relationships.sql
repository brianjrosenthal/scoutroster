-- Migration: Drop relationship column from parent_relationships
-- Reason: Application no longer uses relationship enum; simplify to (youth_id, adult_id) link.

ALTER TABLE parent_relationships
  DROP COLUMN relationship;
