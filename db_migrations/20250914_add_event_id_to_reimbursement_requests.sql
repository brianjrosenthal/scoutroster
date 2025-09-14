-- Migration: Make reimbursement requests optionally associated with an event
-- Adds nullable event_id with FK to events(id), non-destructive to existing data

START TRANSACTION;

ALTER TABLE reimbursement_requests
  ADD COLUMN event_id INT NULL AFTER entered_by;

CREATE INDEX idx_rr_event ON reimbursement_requests(event_id);

ALTER TABLE reimbursement_requests
  ADD CONSTRAINT fk_rr_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL;

COMMIT;
