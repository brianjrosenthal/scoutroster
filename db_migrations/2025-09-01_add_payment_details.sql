-- Add optional payment_details to reimbursement requests
ALTER TABLE reimbursement_requests
  ADD COLUMN payment_details VARCHAR(500) DEFAULT NULL AFTER description;
