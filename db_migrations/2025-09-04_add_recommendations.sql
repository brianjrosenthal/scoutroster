-- Recommendations feature: recommendations and recommendation_comments tables
-- Run via: php cub_scouts/bin/migrate.php

CREATE TABLE IF NOT EXISTS recommendations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  parent_name VARCHAR(255) NOT NULL,
  child_name VARCHAR(255) NOT NULL,
  email VARCHAR(255) DEFAULT NULL,
  phone VARCHAR(50) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  reached_out TINYINT(1) NOT NULL DEFAULT 0,
  reached_out_at DATETIME DEFAULT NULL,
  reached_out_by_user_id INT DEFAULT NULL,
  created_by_user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_recommendations_created_at (created_at),
  INDEX idx_recommendations_reached_out (reached_out),
  CONSTRAINT fk_recommendations_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id),
  CONSTRAINT fk_recommendations_reached_by FOREIGN KEY (reached_out_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recommendation_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  recommendation_id INT NOT NULL,
  created_by_user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  text TEXT NOT NULL,
  INDEX idx_rec_comments_rec (recommendation_id),
  INDEX idx_rec_comments_created_at (created_at),
  CONSTRAINT fk_rec_comments_rec FOREIGN KEY (recommendation_id) REFERENCES recommendations(id) ON DELETE CASCADE,
  CONSTRAINT fk_rec_comments_user FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
