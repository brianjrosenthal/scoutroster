-- Migration: Create pending_registrations table and remove application columns from payment_notifications_from_users

SET NAMES utf8mb4;

-- 1) Create pending_registrations
CREATE TABLE IF NOT EXISTS pending_registrations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  youth_id INT NOT NULL,
  created_by INT NOT NULL,
  secure_file_id INT DEFAULT NULL,
  comment TEXT DEFAULT NULL,
  status ENUM('new','processed','deleted') NOT NULL DEFAULT 'new',
  payment_status ENUM('not_paid','paid') NOT NULL DEFAULT 'not_paid',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_prg_youth FOREIGN KEY (youth_id) REFERENCES youth(id) ON DELETE CASCADE,
  CONSTRAINT fk_prg_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_prg_secure_file FOREIGN KEY (secure_file_id) REFERENCES secure_files(id) ON DELETE SET NULL,
  INDEX idx_prg_youth (youth_id),
  INDEX idx_prg_status (status),
  INDEX idx_prg_payment (payment_status),
  INDEX idx_prg_created_by (created_by)
) ENGINE=InnoDB;

-- 2) Remove application-related columns from payment_notifications_from_users
-- Note: If this migration is re-run and columns are already dropped, this section may error on older MySQL versions lacking IF EXISTS.
-- Run once in sequence with your migration tooling.
ALTER TABLE payment_notifications_from_users
  DROP COLUMN new_application,
  DROP COLUMN application_processed;
