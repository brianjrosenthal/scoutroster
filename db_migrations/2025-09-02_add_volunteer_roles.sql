-- Add volunteer roles table
CREATE TABLE IF NOT EXISTS volunteer_roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  title VARCHAR(150) NOT NULL,
  slots_needed INT NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_vr_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_vr_event_title (event_id, title),
  INDEX idx_vr_event (event_id)
) ENGINE=InnoDB;
