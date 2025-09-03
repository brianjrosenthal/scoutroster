-- Add explicit Google Maps URL override to events
ALTER TABLE events
  ADD COLUMN google_maps_url VARCHAR(512) DEFAULT NULL AFTER evite_rsvp_url;
