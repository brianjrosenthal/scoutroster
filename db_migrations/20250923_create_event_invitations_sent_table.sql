-- Create event_invitations_sent table to track invitation counts and prevent duplicates
CREATE TABLE event_invitations_sent (
  event_id INT NOT NULL,
  user_id INT NOT NULL,
  n INT NOT NULL DEFAULT 1,
  last_sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id, user_id),
  CONSTRAINT fk_eis_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_eis_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_eis_event ON event_invitations_sent(event_id);
CREATE INDEX idx_eis_user ON event_invitations_sent(user_id);
CREATE INDEX idx_eis_last_sent ON event_invitations_sent(last_sent_at);
