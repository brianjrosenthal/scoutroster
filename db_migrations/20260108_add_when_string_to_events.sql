-- Add when_string field to events table for custom "When" display
-- This allows specifying custom timing text like "Check-in: 5pm, Event: 6pm, Dinner: 8pm"
-- instead of just showing the start and end times

ALTER TABLE events
  ADD COLUMN when_string VARCHAR(500) DEFAULT NULL
  AFTER rsvp_url_label;
