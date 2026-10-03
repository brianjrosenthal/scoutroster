-- Add membership_info_note to users and youth
-- Free-text note printed in the "Membership Info" column of the camping roster,
-- e.g. "Parent of Charlie Rosenthal" or a BSA # when the import has not populated it.

ALTER TABLE users
ADD COLUMN membership_info_note VARCHAR(255) DEFAULT NULL
COMMENT 'Free-text note shown in roster Membership Info (e.g. Parent of ...)'
AFTER bsa_membership_number;

ALTER TABLE youth
ADD COLUMN membership_info_note VARCHAR(255) DEFAULT NULL
COMMENT 'Free-text note shown in roster Membership Info'
AFTER bsa_registration_number;
