-- Add training_note to users and youth
-- Free-text note printed in the "Training" column of the camping roster (e.g. "BALOO", "YPT 2026").

ALTER TABLE users
ADD COLUMN training_note VARCHAR(255) DEFAULT NULL
COMMENT 'Free-text note shown in roster Training column (e.g. BALOO)'
AFTER membership_info_note;

ALTER TABLE youth
ADD COLUMN training_note VARCHAR(255) DEFAULT NULL
COMMENT 'Free-text note shown in roster Training column'
AFTER membership_info_note;
