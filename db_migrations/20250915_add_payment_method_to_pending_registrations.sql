-- Add payment_method column to pending_registrations table
ALTER TABLE pending_registrations 
ADD COLUMN payment_method ENUM('Paypal','Check','I will pay later') DEFAULT NULL 
AFTER comment;
