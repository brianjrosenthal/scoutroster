-- Migration: Create event_registration_field_data table
-- Date: 2026-01-08
-- Description: Store user-entered registration field data for events

CREATE TABLE IF NOT EXISTS event_registration_field_data (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_registration_field_definition_id INT NOT NULL,
  participant_type ENUM('youth','adult') NOT NULL,
  participant_id INT NOT NULL,
  value TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_erfd_field_def FOREIGN KEY (event_registration_field_definition_id) REFERENCES event_registration_field_definitions(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_erfd_field_participant (event_registration_field_definition_id, participant_type, participant_id),
  INDEX idx_erfd_field_def (event_registration_field_definition_id),
  INDEX idx_erfd_participant (participant_type, participant_id)
) ENGINE=InnoDB;
