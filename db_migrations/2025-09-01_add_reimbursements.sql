-- Reimbursements core tables

CREATE TABLE IF NOT EXISTS reimbursement_requests (
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

CREATE TABLE IF NOT EXISTS reimbursement_request_files (
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

CREATE TABLE IF NOT EXISTS reimbursement_request_comments (
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
