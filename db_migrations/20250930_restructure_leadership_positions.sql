-- Migration to restructure adult leadership positions system
-- This creates three new tables to replace the current single table approach

-- Create the new table structure

-- 1. Master list of available leadership positions
CREATE TABLE adult_leadership_positions_new (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  sort_priority INT NOT NULL DEFAULT 0,
  description TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT NOT NULL,
  CONSTRAINT fk_alp_new_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE INDEX idx_alp_new_sort ON adult_leadership_positions_new(sort_priority);

-- 2. Assignments of pack leadership positions to adults
CREATE TABLE adult_leadership_position_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  adult_leadership_position_id INT NOT NULL,
  adult_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT NOT NULL,
  CONSTRAINT fk_alpa_position FOREIGN KEY (adult_leadership_position_id) REFERENCES adult_leadership_positions_new(id) ON DELETE CASCADE,
  CONSTRAINT fk_alpa_adult FOREIGN KEY (adult_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_alpa_created_by FOREIGN KEY (created_by) REFERENCES users(id),
  UNIQUE KEY uniq_alpa_position_adult (adult_leadership_position_id, adult_id)
) ENGINE=InnoDB;

CREATE INDEX idx_alpa_adult ON adult_leadership_position_assignments(adult_id);
CREATE INDEX idx_alpa_position ON adult_leadership_position_assignments(adult_leadership_position_id);

-- 3. Den leader assignments (separate from pack positions)
CREATE TABLE adult_den_leader_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  class_of INT NOT NULL,
  adult_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT NOT NULL,
  CONSTRAINT fk_adla_adult FOREIGN KEY (adult_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_adla_created_by FOREIGN KEY (created_by) REFERENCES users(id),
  UNIQUE KEY uniq_adla_class_adult (class_of, adult_id)
) ENGINE=InnoDB;

CREATE INDEX idx_adla_adult ON adult_den_leader_assignments(adult_id);
CREATE INDEX idx_adla_class ON adult_den_leader_assignments(class_of);

-- Insert default pack leadership positions
INSERT INTO adult_leadership_positions_new (name, sort_priority, description, created_by) VALUES
('Cubmaster', 1, 'Pack leader responsible for overall program delivery', 1),
('Committee Chair', 2, 'Chair of the pack committee', 1),
('Treasurer', 3, 'Manages pack finances', 1),
('Assistant Cubmaster', 4, 'Assists the Cubmaster with program delivery', 1),
('Social Chair', 5, 'Organizes social activities and events', 1),
('Safety Chair', 6, 'Ensures safety protocols are followed', 1);

-- Note: Existing data migration will be handled separately as user mentioned they will recreate the data
-- The old table (adult_leadership_positions) will be renamed to preserve existing data during transition

-- Rename the old table to preserve existing data
RENAME TABLE adult_leadership_positions TO adult_leadership_positions_old;

-- Rename the new table to the expected name
RENAME TABLE adult_leadership_positions_new TO adult_leadership_positions;
