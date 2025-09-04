-- Add youth registration expiration and paid-until tracking
ALTER TABLE youth
  ADD COLUMN bsa_registration_expires_date DATE NULL AFTER bsa_registration_number,
  ADD COLUMN date_paid_until DATE NULL AFTER bsa_registration_expires_date;
