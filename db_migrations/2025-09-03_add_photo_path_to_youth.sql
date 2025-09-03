-- Add profile photo path to youth
ALTER TABLE youth
  ADD COLUMN photo_path VARCHAR(512) DEFAULT NULL AFTER shirt_size;
