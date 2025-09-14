-- Migration: Add payment_method to reimbursement_requests
-- Options: 'Zelle', 'Check', 'Donation Letter Only'

START TRANSACTION;

ALTER TABLE reimbursement_requests
  ADD COLUMN payment_method ENUM('Zelle','Check','Donation Letter Only') NULL AFTER amount;

COMMIT;
