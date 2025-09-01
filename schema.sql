-- Cub Scouts application schema
-- Create DB then use it
-- CREATE DATABASE cub_scouts_app DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE cub_scouts_app;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Adults/users
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  last_name  VARCHAR(100) NOT NULL,
  email      VARCHAR(255) DEFAULT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  is_admin   TINYINT(1) NOT NULL DEFAULT 0,

  -- Optional personal info
  preferred_name VARCHAR(100) DEFAULT NULL,
  street1 VARCHAR(255) DEFAULT NULL,
  street2 VARCHAR(255) DEFAULT NULL,
  city    VARCHAR(100) DEFAULT NULL,
  state   VARCHAR(50)  DEFAULT NULL,
  zip     VARCHAR(20)  DEFAULT NULL,
  email2  VARCHAR(255) DEFAULT NULL,
  phone_home VARCHAR(30) DEFAULT NULL,
  phone_cell VARCHAR(30) DEFAULT NULL,
  shirt_size VARCHAR(20) DEFAULT NULL,
  photo_path VARCHAR(512) DEFAULT NULL,

  -- Scouting info
  bsa_membership_number VARCHAR(50) DEFAULT NULL,
  bsa_registration_expires_on DATE DEFAULT NULL,
  safeguarding_training_completed_on DATE DEFAULT NULL,

  -- Medical/emergency contacts
  emergency_contact1_name  VARCHAR(100) DEFAULT NULL,
  emergency_contact1_phone VARCHAR(30)  DEFAULT NULL,
  emergency_contact2_name  VARCHAR(100) DEFAULT NULL,
  emergency_contact2_phone VARCHAR(30)  DEFAULT NULL,

  -- Email verification + password resets
  email_verify_token VARCHAR(64) DEFAULT NULL,
  email_verified_at DATETIME DEFAULT NULL,
  password_reset_token_hash CHAR(64) DEFAULT NULL,
  password_reset_expires_at DATETIME DEFAULT NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_users_email_verify_token ON users(email_verify_token);
CREATE INDEX idx_users_pwreset_expires ON users(password_reset_expires_at);

-- Youth (cub scouts)
CREATE TABLE youth (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  last_name  VARCHAR(100) NOT NULL,
  suffix VARCHAR(20) DEFAULT NULL,
  preferred_name VARCHAR(100) DEFAULT NULL,
  gender ENUM('male','female','non-binary','prefer not to say') DEFAULT NULL,
  birthdate DATE DEFAULT NULL,
  school VARCHAR(150) DEFAULT NULL,
  shirt_size VARCHAR(20) DEFAULT NULL,

  bsa_registration_number VARCHAR(50) DEFAULT NULL, -- presence indicates "registered"

  -- Address (optional)
  street1 VARCHAR(255) DEFAULT NULL,
  street2 VARCHAR(255) DEFAULT NULL,
  city    VARCHAR(100) DEFAULT NULL,
  state   VARCHAR(50)  DEFAULT NULL,
  zip     VARCHAR(20)  DEFAULT NULL,

  class_of INT NOT NULL,     -- grade computed from class_of
  sibling TINYINT(1) NOT NULL DEFAULT 0,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_youth_class_of ON youth(class_of);
CREATE INDEX idx_youth_last_first ON youth(last_name, first_name);

-- Parent relationships (adult is parent/guardian of youth)
CREATE TABLE parent_relationships (
  id INT AUTO_INCREMENT PRIMARY KEY,
  youth_id INT NOT NULL,
  adult_id INT NOT NULL,
  relationship ENUM('father','mother','guardian','parent') NOT NULL,
  CONSTRAINT fk_pr_youth FOREIGN KEY (youth_id) REFERENCES youth(id) ON DELETE CASCADE,
  CONSTRAINT fk_pr_adult FOREIGN KEY (adult_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_youth_adult (youth_id, adult_id)
) ENGINE=InnoDB;

CREATE INDEX idx_pr_adult ON parent_relationships(adult_id);
CREATE INDEX idx_pr_youth ON parent_relationships(youth_id);

-- Dens (by class_of / grade)
CREATE TABLE dens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  den_name VARCHAR(100) NOT NULL,
  class_of INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_dens_class_of ON dens(class_of);
CREATE UNIQUE INDEX uniq_dens_name_classof ON dens(den_name, class_of);

-- Den membership (each youth at most one den)
CREATE TABLE den_memberships (
  id INT AUTO_INCREMENT PRIMARY KEY,
  youth_id INT NOT NULL,
  den_id   INT NOT NULL,
  CONSTRAINT fk_dm_youth FOREIGN KEY (youth_id) REFERENCES youth(id) ON DELETE CASCADE,
  CONSTRAINT fk_dm_den   FOREIGN KEY (den_id)   REFERENCES dens(id)  ON DELETE CASCADE,
  UNIQUE KEY uniq_den_membership (youth_id)
) ENGINE=InnoDB;

CREATE INDEX idx_dm_den ON den_memberships(den_id);

-- Adult leadership positions (optionally for a specific den)
CREATE TABLE adult_leadership_positions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  adult_id INT NOT NULL,
  position VARCHAR(255) NOT NULL,
  den_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_alp_adult FOREIGN KEY (adult_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_alp_den   FOREIGN KEY (den_id)   REFERENCES dens(id)  ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_alp_adult ON adult_leadership_positions(adult_id);
CREATE INDEX idx_alp_den ON adult_leadership_positions(den_id);
CREATE UNIQUE INDEX uniq_alp_adult_position ON adult_leadership_positions(adult_id, position);

-- Medical forms (PDF uploads)
CREATE TABLE medical_forms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM('youth','adult') NOT NULL,
  youth_id INT DEFAULT NULL,
  adult_id INT DEFAULT NULL,
  file_path VARCHAR(512) NOT NULL,
  original_filename VARCHAR(255) DEFAULT NULL,
  mime_type VARCHAR(100) DEFAULT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mf_youth FOREIGN KEY (youth_id) REFERENCES youth(id) ON DELETE CASCADE,
  CONSTRAINT fk_mf_adult FOREIGN KEY (adult_id) REFERENCES users(id) ON DELETE CASCADE
  -- NOTE: App enforces that exactly one of (youth_id, adult_id) is non-NULL to match "must be for either a cub scout or an adult".
) ENGINE=InnoDB;

CREATE INDEX idx_mf_type ON medical_forms(type);
CREATE INDEX idx_mf_youth ON medical_forms(youth_id);
CREATE INDEX idx_mf_adult ON medical_forms(adult_id);

-- Events
CREATE TABLE events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at   DATETIME DEFAULT NULL,
  location VARCHAR(255) DEFAULT NULL,
  description TEXT DEFAULT NULL,
  photo_path VARCHAR(512) DEFAULT NULL,
  max_cub_scouts INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (starts_at)
) ENGINE=InnoDB;

-- RSVP group (creator + multiple members)
CREATE TABLE rsvps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  created_by_user_id INT NOT NULL,
  comments TEXT DEFAULT NULL,
  n_guests INT DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_rsvps_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_rsvps_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE INDEX idx_rsvps_event ON rsvps(event_id);

-- RSVP members (youth or adult per row)
CREATE TABLE rsvp_members (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rsvp_id INT NOT NULL,
  event_id INT NOT NULL,
  participant_type ENUM('youth','adult') NOT NULL,
  youth_id INT DEFAULT NULL,
  adult_id INT DEFAULT NULL,
  CONSTRAINT fk_rm_rsvp  FOREIGN KEY (rsvp_id)  REFERENCES rsvps(id)  ON DELETE CASCADE,
  CONSTRAINT fk_rm_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_rm_youth FOREIGN KEY (youth_id) REFERENCES youth(id)  ON DELETE CASCADE,
  CONSTRAINT fk_rm_adult FOREIGN KEY (adult_id) REFERENCES users(id)  ON DELETE RESTRICT,
  INDEX idx_rm_rsvp (rsvp_id),
  INDEX idx_rm_event (event_id),
  UNIQUE KEY uniq_event_adult (event_id, adult_id),
  UNIQUE KEY uniq_event_youth (event_id, youth_id)
  -- App ensures for each row exactly one of youth_id/adult_id is non-NULL to match participant_type.
) ENGINE=InnoDB;

-- Settings key-value
CREATE TABLE settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  key_name VARCHAR(191) NOT NULL UNIQUE,
  value LONGTEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Default settings
INSERT INTO settings (key_name, value) VALUES
  ('site_title', 'Cub Scouts Pack 440'),
  ('announcement', ''),
  ('timezone', '')
ON DUPLICATE KEY UPDATE value=VALUES(value);

-- Reimbursements
CREATE TABLE reimbursement_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_modified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  status ENUM('submitted','revoked','more_info_requested','resubmitted','approved','rejected') NOT NULL,
  comment_from_last_status_change TEXT DEFAULT NULL,
  last_status_set_by INT DEFAULT NULL,
  last_status_set_at DATETIME DEFAULT NULL,
  CONSTRAINT fk_rr_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_rr_last_set_by FOREIGN KEY (last_status_set_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE INDEX idx_rr_created_by ON reimbursement_requests(created_by);
CREATE INDEX idx_rr_status ON reimbursement_requests(status);

CREATE TABLE reimbursement_request_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reimbursement_request_id INT NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  stored_path VARCHAR(512) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rrf_req FOREIGN KEY (reimbursement_request_id) REFERENCES reimbursement_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_rrf_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE INDEX idx_rrf_req ON reimbursement_request_files(reimbursement_request_id);

CREATE TABLE reimbursement_request_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reimbursement_request_id INT NOT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status_changed_to ENUM('submitted','revoked','more_info_requested','resubmitted','approved','rejected') DEFAULT NULL,
  comment_text TEXT NOT NULL,
  CONSTRAINT fk_rrc_req FOREIGN KEY (reimbursement_request_id) REFERENCES reimbursement_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_rrc_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE INDEX idx_rrc_req ON reimbursement_request_comments(reimbursement_request_id);
CREATE INDEX idx_rrc_creator ON reimbursement_request_comments(created_by);

-- Optional: seed an admin user (update email and password hash, then remove)
-- The hash below corresponds to password: Admin123!
-- INSERT INTO users (first_name,last_name,email,password_hash,is_admin,email_verified_at)
-- VALUES ('Admin','User','admin@example.com','$2y$10$9xH7Jq4v3o6s9k3y8i4rVOyWb0yBYZ5rW.0f9pZ.gG9K6l7lS6b2S',1,NOW());
