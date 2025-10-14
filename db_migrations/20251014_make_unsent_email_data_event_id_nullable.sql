-- Migration: Make event_id nullable for non-event-specific emails
-- Date: 2025-10-14
-- Purpose: Allow upcoming events digest emails (and other non-event-specific emails) 
--          to be queued and tracked without requiring a specific event_id

-- 1. Update unsent_email_data table
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

-- 2. Update event_invitations_sent table for tracking
-- Drop the existing foreign key constraint
ALTER TABLE event_invitations_sent
  DROP FOREIGN KEY event_invitations_sent_ibfk_1;

-- Drop the existing primary key
ALTER TABLE event_invitations_sent
  DROP PRIMARY KEY;

-- Make event_id nullable
ALTER TABLE event_invitations_sent
  MODIFY COLUMN event_id INT NULL;

-- Re-add primary key (handles NULL properly)
ALTER TABLE event_invitations_sent
  ADD PRIMARY KEY (event_id, user_id);

-- Re-add the foreign key constraint (now allows NULL)
ALTER TABLE event_invitations_sent
  ADD CONSTRAINT event_invitations_sent_ibfk_1
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE;
