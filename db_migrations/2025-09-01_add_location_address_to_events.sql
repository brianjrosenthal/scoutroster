-- Add location_address field to events for full postal address (multiline)
ALTER TABLE events
  ADD COLUMN location_address TEXT DEFAULT NULL AFTER location;
