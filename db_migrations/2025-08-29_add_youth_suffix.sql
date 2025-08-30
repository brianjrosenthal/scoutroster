-- Add suffix column to youth for name suffixes like "Jr", "III"
ALTER TABLE youth
  ADD COLUMN suffix VARCHAR(20) DEFAULT NULL AFTER last_name;
