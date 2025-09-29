-- Add 'left' field to youth table to track youth who have left the troop
ALTER TABLE youth
  ADD COLUMN left_troop TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Indicates if the youth has left the troop (decided not to continue with scouts)';

CREATE INDEX idx_youth_left_troop ON youth(left_troop);
