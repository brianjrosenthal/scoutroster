-- Create event registration field definitions table
CREATE TABLE IF NOT EXISTS event_registration_field_definitions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  scope ENUM('per_person','per_youth','per_family') NOT NULL,
  name VARCHAR(255) NOT NULL,
  field_type ENUM('text','select','boolean') NOT NULL,
  required TINYINT(1) NOT NULL DEFAULT 0,
  option_list TEXT DEFAULT NULL COMMENT 'JSON array of options for select fields',
  sequence_number INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT NOT NULL,
  CONSTRAINT fk_erfd_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_erfd_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_erfd_event (event_id),
  INDEX idx_erfd_sequence (event_id, sequence_number)
) ENGINE=InnoDB;
