-- Add answer to rsvps_logged_out to support yes/maybe/no for public RSVPs
ALTER TABLE rsvps_logged_out
  ADD COLUMN answer ENUM('yes','maybe','no') NOT NULL DEFAULT 'yes' AFTER total_kids;
