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
  suppress_email_directory TINYINT(1) NOT NULL DEFAULT 0,
  suppress_phone_directory TINYINT(1) NOT NULL DEFAULT 0,
  phone_home VARCHAR(30) DEFAULT NULL,
  phone_cell VARCHAR(30) DEFAULT NULL,
  shirt_size VARCHAR(20) DEFAULT NULL,

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
  bsa_registration_expires_date DATE DEFAULT NULL,
  date_paid_until DATE DEFAULT NULL,

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
  CONSTRAINT fk_pr_youth FOREIGN KEY (youth_id) REFERENCES youth(id) ON DELETE CASCADE,
  CONSTRAINT fk_pr_adult FOREIGN KEY (adult_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_youth_adult (youth_id, adult_id)
) ENGINE=InnoDB;

CREATE INDEX idx_pr_adult ON parent_relationships(adult_id);
CREATE INDEX idx_pr_youth ON parent_relationships(youth_id);



-- Adult leadership positions
CREATE TABLE adult_leadership_positions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  adult_id INT NOT NULL,
  position VARCHAR(255) NOT NULL,
  class_of INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_alp_adult FOREIGN KEY (adult_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_alp_adult ON adult_leadership_positions(adult_id);
CREATE UNIQUE INDEX uniq_alp_adult_position_class ON adult_leadership_positions(adult_id, position, class_of);

-- Events
CREATE TABLE events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at   DATETIME DEFAULT NULL,
  location VARCHAR(255) DEFAULT NULL,
  location_address TEXT DEFAULT NULL,
  description TEXT DEFAULT NULL,
  max_cub_scouts INT DEFAULT NULL,
  allow_non_user_rsvp TINYINT(1) NOT NULL DEFAULT 1,
  evite_rsvp_url VARCHAR(512) DEFAULT NULL,
  google_maps_url VARCHAR(512) DEFAULT NULL,
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
  answer ENUM('yes','maybe','no') NOT NULL DEFAULT 'yes',
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

-- Public (logged-out) RSVPs for events
CREATE TABLE rsvps_logged_out (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(50) DEFAULT NULL,
  total_adults INT NOT NULL DEFAULT 0,
  total_kids INT NOT NULL DEFAULT 0,
  answer ENUM('yes','maybe','no') NOT NULL DEFAULT 'yes',
  comment TEXT DEFAULT NULL,
  token_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_rlo_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_rlo_event ON rsvps_logged_out(event_id);
CREATE INDEX idx_rlo_email ON rsvps_logged_out(email);

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
  ('timezone', ''),
  ('google_calendar_url', '')
ON DUPLICATE KEY UPDATE value=VALUES(value);

-- Recommendations
CREATE TABLE recommendations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  parent_name VARCHAR(255) NOT NULL,
  child_name VARCHAR(255) NOT NULL,
  email VARCHAR(255) DEFAULT NULL,
  phone VARCHAR(50) DEFAULT NULL,
  grade ENUM('K','1','2','3','4','5') DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  status ENUM('new','active','joined','unsubscribed') NOT NULL DEFAULT 'new',
  reached_out_at DATETIME DEFAULT NULL,
  reached_out_by_user_id INT DEFAULT NULL,
  created_by_user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_recommendations_created_at (created_at),
  INDEX idx_recommendations_status (status),
  CONSTRAINT fk_recommendations_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id),
  CONSTRAINT fk_recommendations_reached_by FOREIGN KEY (reached_out_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE recommendation_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  recommendation_id INT NOT NULL,
  created_by_user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  text TEXT NOT NULL,
  INDEX idx_rec_comments_rec (recommendation_id),
  INDEX idx_rec_comments_created_at (created_at),
  CONSTRAINT fk_rec_comments_rec FOREIGN KEY (recommendation_id) REFERENCES recommendations(id) ON DELETE CASCADE,
  CONSTRAINT fk_rec_comments_user FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Reimbursements
CREATE TABLE reimbursement_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  payment_details VARCHAR(500) DEFAULT NULL,
  amount DECIMAL(10,2) DEFAULT NULL,
  created_by INT NOT NULL,
  entered_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_modified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  status ENUM('submitted','revoked','more_info_requested','resubmitted','approved','rejected','paid') NOT NULL,
  comment_from_last_status_change TEXT DEFAULT NULL,
  last_status_set_by INT DEFAULT NULL,
  last_status_set_at DATETIME DEFAULT NULL,
  CONSTRAINT fk_rr_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_rr_entered_by FOREIGN KEY (entered_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_rr_last_set_by FOREIGN KEY (last_status_set_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE INDEX idx_rr_created_by ON reimbursement_requests(created_by);
CREATE INDEX idx_rr_status ON reimbursement_requests(status);
CREATE INDEX idx_rr_entered_by ON reimbursement_requests(entered_by);

CREATE TABLE reimbursement_request_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reimbursement_request_id INT NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
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
  status_changed_to ENUM('submitted','revoked','more_info_requested','resubmitted','approved','rejected','paid') DEFAULT NULL,
  comment_text TEXT NOT NULL,
  CONSTRAINT fk_rrc_req FOREIGN KEY (reimbursement_request_id) REFERENCES reimbursement_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_rrc_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE INDEX idx_rrc_req ON reimbursement_request_comments(reimbursement_request_id);
CREATE INDEX idx_rrc_creator ON reimbursement_request_comments(created_by);

-- Optional: seed an admin user (update email and password hash, then remove)
INSERT INTO users (first_name,last_name,email,password_hash,is_admin,email_verified_at)
VALUES ('Admin','User','admin@example.com','$2y$10$9xH7Jq4v3o6s9k3y8i4rVOyWb0yBYZ5rW.0f9pZ.gG9K6l7lS6b2S',1,NOW());

-- Volunteer Roles
CREATE TABLE volunteer_roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  title VARCHAR(150) NOT NULL,
  description TEXT DEFAULT NULL,
  slots_needed INT NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_vr_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_vr_event_title (event_id, title),
  INDEX idx_vr_event (event_id)
) ENGINE=InnoDB;

-- Volunteer Signups
CREATE TABLE volunteer_signups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  role_id INT NOT NULL,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_vs_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_vs_role  FOREIGN KEY (role_id)  REFERENCES volunteer_roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_vs_user  FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_vs_event (event_id),
  INDEX idx_vs_role (role_id),
  UNIQUE KEY uniq_vs_role_user (role_id, user_id)
) ENGINE=InnoDB;

-- ===== Files Storage (DB-backed uploads) =====

-- Public files (event photos, profile photos)
CREATE TABLE public_files (
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

-- Secure files (reimbursement attachments)
CREATE TABLE secure_files (
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

-- Link columns (added via ALTER to avoid circular FK creation order)
ALTER TABLE users
  ADD COLUMN photo_public_file_id INT NULL;

ALTER TABLE users
  ADD CONSTRAINT fk_users_photo_public_file
    FOREIGN KEY (photo_public_file_id) REFERENCES public_files(id) ON DELETE SET NULL;

-- Add new youth photo_public_file_id
ALTER TABLE youth
  ADD COLUMN photo_public_file_id INT NULL;

ALTER TABLE youth
  ADD CONSTRAINT fk_youth_photo_public_file
    FOREIGN KEY (photo_public_file_id) REFERENCES public_files(id) ON DELETE SET NULL;

ALTER TABLE events
  ADD COLUMN photo_public_file_id INT NULL;

ALTER TABLE events
  ADD CONSTRAINT fk_events_photo_public_file
    FOREIGN KEY (photo_public_file_id) REFERENCES public_files(id) ON DELETE SET NULL;

ALTER TABLE reimbursement_request_files
  ADD COLUMN secure_file_id INT NULL;

ALTER TABLE reimbursement_request_files
  ADD CONSTRAINT fk_rrf_secure_file
    FOREIGN KEY (secure_file_id) REFERENCES secure_files(id) ON DELETE SET NULL;

CREATE INDEX idx_rrf_secure_file ON reimbursement_request_files(secure_file_id);

-- ===== Payment notifications submitted by users =====
CREATE TABLE payment_notifications_from_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  youth_id INT NOT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  payment_method ENUM('Paypal','Zelle','Venmo','Check','Other') NOT NULL,
  comment TEXT DEFAULT NULL,
  status ENUM('new','verified','deleted') NOT NULL DEFAULT 'new',
  CONSTRAINT fk_pnfu_youth FOREIGN KEY (youth_id) REFERENCES youth(id) ON DELETE CASCADE,
  CONSTRAINT fk_pnfu_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE INDEX idx_pnfu_created_at ON payment_notifications_from_users(created_at);
CREATE INDEX idx_pnfu_status ON payment_notifications_from_users(status);
CREATE INDEX idx_pnfu_youth ON payment_notifications_from_users(youth_id);
CREATE INDEX idx_pnfu_created_by ON payment_notifications_from_users(created_by);

-- ===== Pending registrations =====
CREATE TABLE pending_registrations (
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
  CONSTRAINT fk_prg_secure_file FOREIGN KEY (secure_file_id) REFERENCES secure_files(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_prg_youth ON pending_registrations(youth_id);
CREATE INDEX idx_prg_status ON pending_registrations(status);
CREATE INDEX idx_prg_payment ON pending_registrations(payment_status);
CREATE INDEX idx_prg_created_by ON pending_registrations(created_by);

-- ===== Activity Log =====
CREATE TABLE activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id INT NULL,
  action_type VARCHAR(64) NOT NULL,
  json_metadata LONGTEXT NULL,
  CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_al_created_at ON activity_log(created_at);
CREATE INDEX idx_al_user_id ON activity_log(user_id);
CREATE INDEX idx_al_action_type ON activity_log(action_type);
