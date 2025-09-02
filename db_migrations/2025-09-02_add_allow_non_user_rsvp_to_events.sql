-- Add allow_non_user_rsvp flag to events to control public RSVP availability
ALTER TABLE events
  ADD COLUMN allow_non_user_rsvp TINYINT(1) NOT NULL DEFAULT 1 AFTER max_cub_scouts;
