-- Event photos stored in Cloudflare R2. Only object keys live here; the bytes
-- (original, 2048px display rendition, 400px thumbnail) never touch this server.
CREATE TABLE IF NOT EXISTS event_photos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  uploaded_by_user_id INT DEFAULT NULL,
  original_key VARCHAR(255) NOT NULL,
  display_key  VARCHAR(255) NOT NULL,
  thumb_key    VARCHAR(255) NOT NULL,
  content_type VARCHAR(100) NOT NULL COMMENT 'MIME type of the original',
  byte_length  INT UNSIGNED NOT NULL COMMENT 'bytes of the original',
  width  INT UNSIGNED NOT NULL COMMENT 'original px width after EXIF orientation',
  height INT UNSIGNED NOT NULL COMMENT 'original px height after EXIF orientation',
  sha256 CHAR(64) DEFAULT NULL COMMENT 'hex SHA-256 of the original, browser-computed, dedup hint',
  taken_at DATETIME DEFAULT NULL COMMENT 'naive wall-clock capture time',
  taken_at_source ENUM('exif','file','upload') NOT NULL DEFAULT 'upload',
  caption VARCHAR(500) DEFAULT NULL,
  exclude_from_slideshow TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT DEFAULT NULL COMMENT 'admin manual order; NULL = chronological',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_event_photos_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_photos_uploader FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_event_photos_event_order (event_id, sort_order, taken_at, id),
  INDEX idx_event_photos_uploader (uploaded_by_user_id),
  INDEX idx_event_photos_sha (event_id, sha256),
  UNIQUE INDEX uq_event_photos_original_key (original_key)
) ENGINE=InnoDB;
