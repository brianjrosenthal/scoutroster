-- Create blob storage tables and add linking columns for public and secure files

-- 1) Public files (event photos, profile photos)
CREATE TABLE IF NOT EXISTS public_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  data LONGBLOB NOT NULL,
  content_type VARCHAR(100) DEFAULT NULL,
  original_filename VARCHAR(255) DEFAULT NULL,
  byte_length INT UNSIGNED DEFAULT NULL,
  sha256 CHAR(64) DEFAULT NULL,
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pf_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_pf_sha256 ON public_files(sha256);
CREATE INDEX idx_pf_created_by ON public_files(created_by_user_id);
CREATE INDEX idx_pf_created_at ON public_files(created_at);

-- 2) Secure files (reimbursement attachments)
CREATE TABLE IF NOT EXISTS secure_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  data LONGBLOB NOT NULL,
  content_type VARCHAR(100) DEFAULT NULL,
  original_filename VARCHAR(255) DEFAULT NULL,
  byte_length INT UNSIGNED DEFAULT NULL,
  sha256 CHAR(64) DEFAULT NULL,
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sf_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_sf_sha256 ON secure_files(sha256);
CREATE INDEX idx_sf_created_by ON secure_files(created_by_user_id);
CREATE INDEX idx_sf_created_at ON secure_files(created_at);

-- 3) Link columns for users/youth/events to public_files
ALTER TABLE users
  ADD COLUMN photo_public_file_id INT NULL,
  ADD CONSTRAINT fk_users_photo_public_file
    FOREIGN KEY (photo_public_file_id) REFERENCES public_files(id) ON DELETE SET NULL;

-- Add column to youth (may already exist via migrations; IF NOT EXISTS keeps idempotent)
ALTER TABLE youth
  ADD COLUMN photo_public_file_id INT NULL;

-- Add the FK for youth if supported; some MySQL versions don't allow IF NOT EXISTS here
-- Attempt to add; if it already exists, it will fail but migration overall can continue in environments running migrations once.
ALTER TABLE youth
  ADD CONSTRAINT fk_youth_photo_public_file
    FOREIGN KEY (photo_public_file_id) REFERENCES public_files(id) ON DELETE SET NULL;

ALTER TABLE events
  ADD COLUMN photo_public_file_id INT NULL,
  ADD CONSTRAINT fk_events_photo_public_file
    FOREIGN KEY (photo_public_file_id) REFERENCES public_files(id) ON DELETE SET NULL;

-- 4) Link reimbursement_request_files to secure_files (keep stored_path for legacy fallback)
ALTER TABLE reimbursement_request_files
  ADD COLUMN secure_file_id INT NULL,
  ADD CONSTRAINT fk_rrf_secure_file
    FOREIGN KEY (secure_file_id) REFERENCES secure_files(id) ON DELETE SET NULL;

CREATE INDEX idx_rrf_secure_file ON reimbursement_request_files(secure_file_id);
