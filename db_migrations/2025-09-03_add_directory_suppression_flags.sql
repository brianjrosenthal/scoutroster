-- Add directory suppression flags to users table
ALTER TABLE users
  ADD COLUMN suppress_email_directory TINYINT(1) NOT NULL DEFAULT 0 AFTER email2,
  ADD COLUMN suppress_phone_directory TINYINT(1) NOT NULL DEFAULT 0 AFTER suppress_email_directory;
