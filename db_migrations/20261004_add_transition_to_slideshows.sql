-- Add a per-slideshow photo transition style.
-- 'mix' = mostly crossfades with occasional variety (default), 'random' = a
-- different transition for every photo, or one named transition (fade, slide,
-- push, wipe, iris, zoom, blur, flip, spin). See Slideshows::TRANSITIONS.

ALTER TABLE slideshows
ADD COLUMN transition VARCHAR(20) NOT NULL DEFAULT 'mix'
COMMENT 'Photo transition style: mix, random, or a named transition'
AFTER description;
