-- Add description column to event_registration_field_definitions
ALTER TABLE event_registration_field_definitions 
ADD COLUMN description TEXT DEFAULT NULL AFTER name;
