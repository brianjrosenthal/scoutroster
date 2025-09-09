-- Drop legacy filesystem-backed columns now that the app is DB-only for uploads
-- WARNING: This is destructive. Ensure any needed backfill has already been run.

ALTER TABLE users
  DROP COLUMN photo_path;

ALTER TABLE youth
  DROP COLUMN photo_path;

ALTER TABLE events
  DROP COLUMN photo_path;

ALTER TABLE reimbursement_request_files
  DROP COLUMN stored_path;
