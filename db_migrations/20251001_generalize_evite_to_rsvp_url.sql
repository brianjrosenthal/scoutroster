-- Migration: Generalize evite_rsvp_url to rsvp_url and add rsvp_url_label
-- Date: 2025-10-01

-- Add the new rsvp_url_label column
ALTER TABLE events ADD COLUMN rsvp_url_label VARCHAR(100) DEFAULT NULL;

-- Rename evite_rsvp_url to rsvp_url
ALTER TABLE events CHANGE COLUMN evite_rsvp_url rsvp_url VARCHAR(512) DEFAULT NULL;

-- For existing events with rsvp_url, set a default label to maintain current behavior
UPDATE events SET rsvp_url_label = 'RSVP TO EVITE' WHERE rsvp_url IS NOT NULL AND rsvp_url != '';
