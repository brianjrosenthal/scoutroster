-- Add medical_form_in_person_opt_in field to both users and youth tables
-- This allows people to opt into bringing medical forms to events in person

ALTER TABLE users 
ADD COLUMN medical_form_in_person_opt_in TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE youth 
ADD COLUMN medical_form_in_person_opt_in TINYINT(1) NOT NULL DEFAULT 0;
