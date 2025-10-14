-- Migration: Make event_id nullable in unsent_email_data for non-event-specific emails
-- Date: 2025-10-14
-- Purpose: Allow upcoming events digest emails (and other non-event-specific emails) 
--          to be queued without requiring a specific event_id

-- Drop the existing foreign key constraint
ALTER TABLE unsent_email_data
  DROP FOREIGN KEY fk_ued_event;

-- Make event_id nullable
ALTER TABLE unsent_email_data
  MODIFY COLUMN event_id INT NULL;

-- Re-add the foreign key constraint (now allows NULL)
ALTER TABLE unsent_email_data
  ADD CONSTRAINT fk_ued_event 
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE;
