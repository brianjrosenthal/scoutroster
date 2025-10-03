-- Add safeguarding training expiration field to users table
ALTER TABLE users ADD COLUMN safeguarding_training_expires_on DATE DEFAULT NULL AFTER safeguarding_training_completed_on;

-- Create audit table for Scouting.org import changes
CREATE TABLE scouting_org_field_changes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  type ENUM('adult','youth') NOT NULL,
  adult_id INT DEFAULT NULL,
  youth_id INT DEFAULT NULL,
  field_name VARCHAR(100) NOT NULL,
  old_value TEXT DEFAULT NULL,
  new_value TEXT DEFAULT NULL,
  CONSTRAINT fk_sofc_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sofc_adult FOREIGN KEY (adult_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sofc_youth FOREIGN KEY (youth_id) REFERENCES youth(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_sofc_created_by ON scouting_org_field_changes(created_by);
CREATE INDEX idx_sofc_created_at ON scouting_org_field_changes(created_at);
CREATE INDEX idx_sofc_type ON scouting_org_field_changes(type);
CREATE INDEX idx_sofc_adult ON scouting_org_field_changes(adult_id);
CREATE INDEX idx_sofc_youth ON scouting_org_field_changes(youth_id);
