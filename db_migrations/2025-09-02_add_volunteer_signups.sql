-- Add volunteer signups table
CREATE TABLE IF NOT EXISTS volunteer_signups (
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
