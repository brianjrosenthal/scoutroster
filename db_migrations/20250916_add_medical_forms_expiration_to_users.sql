-- Add medical_forms_expiration_date field to users table
-- This field tracks when medical forms expire for each adult
-- Only approvers (Cubmaster, Committee Chair, Treasurer) can edit this field

ALTER TABLE users ADD COLUMN medical_forms_expiration_date DATE DEFAULT NULL;
