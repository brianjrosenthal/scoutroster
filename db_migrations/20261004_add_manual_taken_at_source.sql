-- Let the uploader or an admin set a photo's capture date by hand (for photos
-- that arrived without EXIF, e.g. via WhatsApp, which strips metadata).

ALTER TABLE event_photos
MODIFY COLUMN taken_at_source ENUM('exif','file','upload','manual') NOT NULL DEFAULT 'upload';
