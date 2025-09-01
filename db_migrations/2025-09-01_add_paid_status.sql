-- Add 'paid' status to reimbursement requests and comments
-- Allows approvers to mark requests as paid (terminal), and jump directly to paid from in-flight states.

ALTER TABLE reimbursement_requests
  MODIFY status ENUM('submitted','revoked','more_info_requested','resubmitted','approved','rejected','paid') NOT NULL;

ALTER TABLE reimbursement_request_comments
  MODIFY status_changed_to ENUM('submitted','revoked','more_info_requested','resubmitted','approved','rejected','paid') DEFAULT NULL;
