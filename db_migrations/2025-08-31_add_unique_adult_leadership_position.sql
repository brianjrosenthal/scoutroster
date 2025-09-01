-- Enforce unique leadership positions per adult (prevents duplicates)
-- Note: This assumes the table adult_leadership_positions already exists.
-- This migration will add a unique composite index on (adult_id, position).

ALTER TABLE adult_leadership_positions
  ADD UNIQUE INDEX uniq_alp_adult_position (adult_id, position);
