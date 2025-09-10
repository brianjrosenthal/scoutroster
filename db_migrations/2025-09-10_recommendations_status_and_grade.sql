-- Recommendations: add status + grade, backfill, and remove legacy reached_out flag

-- 1) Add status with default 'new'
ALTER TABLE recommendations
  ADD COLUMN status ENUM('new','active','joined','unsubscribed') NOT NULL DEFAULT 'new' AFTER notes;

-- 2) Backfill status from legacy reached_out flag
UPDATE recommendations
SET status = CASE WHEN reached_out = 1 THEN 'active' ELSE 'new' END;

-- 3) Add optional grade (NULL = Unknown)
ALTER TABLE recommendations
  ADD COLUMN grade ENUM('K','1','2','3','4','5') NULL AFTER phone;

-- 4) Index for status filtering
CREATE INDEX idx_recommendations_status ON recommendations(status);

-- 5) Drop legacy reached_out boolean + its index
ALTER TABLE recommendations
  DROP INDEX idx_recommendations_reached_out;

ALTER TABLE recommendations
  DROP COLUMN reached_out;
