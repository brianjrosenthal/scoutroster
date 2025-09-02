-- Public (logged-out) RSVPs for events
CREATE TABLE IF NOT EXISTS rsvps_logged_out (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(50) DEFAULT NULL,
  total_adults INT NOT NULL DEFAULT 0,
  total_kids INT NOT NULL DEFAULT 0,
  comment TEXT DEFAULT NULL,
  token_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_rlo_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_rlo_event ON rsvps_logged_out(event_id);
CREATE INDEX idx_rlo_email ON rsvps_logged_out(email);
