-- Add entered_by field to rsvps table to track which admin entered the RSVP
-- This is separate from created_by_user_id which indicates whose RSVP it is

ALTER TABLE rsvps 
ADD COLUMN entered_by INT NULL AFTER created_by_user_id,
ADD CONSTRAINT fk_rsvps_entered_by FOREIGN KEY (entered_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX idx_rsvps_entered_by ON rsvps(entered_by);
