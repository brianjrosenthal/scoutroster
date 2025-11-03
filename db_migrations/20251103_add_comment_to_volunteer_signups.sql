-- Add comment field to volunteer_signups table
-- This allows volunteers to add context to their signups (e.g., "I'm bringing chips and salsa")

ALTER TABLE volunteer_signups
ADD COLUMN comment TEXT DEFAULT NULL AFTER user_id;
