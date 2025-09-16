-- Add medical_forms_expiration_date field to youth table
-- This field tracks when medical forms expire for each youth
-- Only approvers (Cubmaster, Committee Chair, Treasurer) can edit this field

ALTER TABLE youth ADD COLUMN medical_forms_expiration_date DATE DEFAULT NULL;
