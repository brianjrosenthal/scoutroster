-- Add registration_field_data_instructions field to events table
-- This allows admins to provide custom instructions for the registration data form

ALTER TABLE events
  ADD COLUMN registration_field_data_instructions TEXT DEFAULT NULL
  AFTER where_string;
