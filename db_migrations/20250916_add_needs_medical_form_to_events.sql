-- Add needs_medical_form column to events table
-- This field indicates whether an event requires medical forms from participants

ALTER TABLE events 
ADD COLUMN needs_medical_form TINYINT(1) NOT NULL DEFAULT 0 
AFTER allow_non_user_rsvp;
