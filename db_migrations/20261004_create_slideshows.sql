-- End-of-year slideshows: an ordered list of events (sections), each played
-- to a music track. Tracks live in R2 under audio/... and are reusable.
CREATE TABLE IF NOT EXISTS slideshow_tracks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  object_key VARCHAR(255) NOT NULL,
  content_type VARCHAR(100) NOT NULL,
  byte_length BIGINT UNSIGNED NOT NULL DEFAULT 0,
  duration_seconds DECIMAL(8,2) DEFAULT NULL COMMENT 'Reported by browser at upload; NULL if unknown',
  uploaded_by_user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sst_uploaded_by FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_sst_object_key (object_key)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS slideshows (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  created_by_user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ss_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS slideshow_sections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slideshow_id INT NOT NULL,
  event_id INT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  track_id INT DEFAULT NULL,
  title_override VARCHAR(255) DEFAULT NULL,
  seconds_per_photo DECIMAL(5,2) DEFAULT NULL COMMENT 'NULL = auto: clamp(track_duration / photo_count, 3, 8)',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sss_slideshow FOREIGN KEY (slideshow_id) REFERENCES slideshows(id) ON DELETE CASCADE,
  CONSTRAINT fk_sss_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_sss_track FOREIGN KEY (track_id) REFERENCES slideshow_tracks(id) ON DELETE SET NULL,
  UNIQUE KEY uq_sss_slideshow_event (slideshow_id, event_id),
  INDEX idx_sss_slideshow_sort (slideshow_id, sort_order)
) ENGINE=InnoDB;
