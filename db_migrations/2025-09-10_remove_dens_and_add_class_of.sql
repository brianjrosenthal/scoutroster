-- Migration: Remove dens and den_memberships; add class_of to adult_leadership_positions; drop den_id
-- Date: 2025-09-10

START TRANSACTION;

-- 1) adult_leadership_positions: add class_of (nullable)
ALTER TABLE adult_leadership_positions
  ADD COLUMN class_of INT NULL AFTER position;

-- 2) Remove FK/index/column den_id from adult_leadership_positions
-- Drop FK to dens
ALTER TABLE adult_leadership_positions
  DROP FOREIGN KEY fk_alp_den;

-- Drop index on den_id (if present)
ALTER TABLE adult_leadership_positions
  DROP INDEX idx_alp_den;

-- Drop the column
ALTER TABLE adult_leadership_positions
  DROP COLUMN den_id;

-- 3) Replace unique index with (adult_id, position, class_of)
ALTER TABLE adult_leadership_positions
  DROP INDEX uniq_alp_adult_position,
  ADD UNIQUE KEY uniq_alp_adult_position_class (adult_id, position, class_of);

-- 4) Drop tables: den_memberships and dens
DROP TABLE IF EXISTS den_memberships;
DROP TABLE IF EXISTS dens;

COMMIT;
