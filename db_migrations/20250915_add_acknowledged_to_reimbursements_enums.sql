-- 2025-09-15: Add 'acknowledged' reimbursement status and backfill any blank enum values
-- This migration:
-- 1) Extends reimbursement_requests.status enum with 'acknowledged'
-- 2) Extends reimbursement_request_comments.status_changed_to enum with 'acknowledged'
-- 3) Backfills data where previous writes resulted in '' (empty enum) due to missing enum value

START TRANSACTION;

-- 1) Extend reimbursement_requests.status enum
ALTER TABLE reimbursement_requests
  MODIFY COLUMN status ENUM('submitted','revoked','more_info_requested','resubmitted','approved','rejected','paid','acknowledged') NOT NULL;

-- 2) Extend reimbursement_request_comments.status_changed_to enum
ALTER TABLE reimbursement_request_comments
  MODIFY COLUMN status_changed_to ENUM('submitted','revoked','more_info_requested','resubmitted','approved','rejected','paid','acknowledged') DEFAULT NULL;

-- 3a) Backfill: if status is currently empty '' for Donation Letter Only, set to 'acknowledged'
UPDATE reimbursement_requests
SET status = 'acknowledged'
WHERE status = '' AND payment_method = 'Donation Letter Only';

-- 3b) Backfill: if a comment tried to set 'acknowledged' but became '' (empty enum), fix it
-- Prefer cases where the comment text indicates a donation letter was sent, or the parent request is Donation Letter Only.
UPDATE reimbursement_request_comments c
JOIN reimbursement_requests r ON r.id = c.reimbursement_request_id
SET c.status_changed_to = 'acknowledged'
WHERE c.status_changed_to = ''
  AND (
    c.comment_text LIKE 'Donation letter sent.%'
    OR r.payment_method = 'Donation Letter Only'
  );

COMMIT;
