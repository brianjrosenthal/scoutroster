-- Payment notifications submitted by logged-in users (parents)
-- Tracks how a user reports dues payment for a specific youth
-- Visible to Cubmaster and Treasurer for verification/deletion workflows

CREATE TABLE IF NOT EXISTS payment_notifications_from_users (
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
