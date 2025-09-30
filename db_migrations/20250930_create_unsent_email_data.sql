-- Create unsent_email_data table for JavaScript-coordinated email sending
-- Migration: 20250930_create_unsent_email_data.sql

ALTER TABLE `unsent_email_data` DROP FOREIGN KEY IF EXISTS `fk_ued_user`;
ALTER TABLE `unsent_email_data` DROP FOREIGN KEY IF EXISTS `fk_ued_sent_by`;
DROP TABLE IF EXISTS `unsent_email_data`;

CREATE TABLE unsent_email_data (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  user_id INT NOT NULL,
  subject TEXT NOT NULL,
  body LONGTEXT NOT NULL,
  ics_content LONGTEXT DEFAULT NULL,
  sent_status ENUM('', 'sent', 'failed') NOT NULL DEFAULT '',
  error TEXT DEFAULT NULL,
  sent_by INT NOT NULL,
  sent_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ued_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_ued_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ued_sent_by FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE INDEX idx_ued_event ON unsent_email_data(event_id);
CREATE INDEX idx_ued_sent_status ON unsent_email_data(sent_status);
CREATE INDEX idx_ued_sent_by ON unsent_email_data(sent_by);
CREATE INDEX idx_ued_created_at ON unsent_email_data(created_at);
