-- Rename where_string to when_string in events table
-- This corrects the naming to properly reflect that it customizes the "When" display

ALTER TABLE events
  CHANGE where_string when_string VARCHAR(500) DEFAULT NULL;
