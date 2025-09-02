-- Add answer to rsvps to support yes/maybe/no
ALTER TABLE rsvps
  ADD COLUMN answer ENUM('yes','maybe','no') NOT NULL DEFAULT 'yes' AFTER n_guests;
