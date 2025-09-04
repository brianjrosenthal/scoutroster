-- Add amount to reimbursement requests
ALTER TABLE reimbursement_requests
  ADD COLUMN amount DECIMAL(10,2) DEFAULT NULL AFTER payment_details;
