-- Add optional Evite RSVP URL to events
ALTER TABLE events
  ADD COLUMN evite_rsvp_url VARCHAR(512) DEFAULT NULL AFTER allow_non_user_rsvp;
