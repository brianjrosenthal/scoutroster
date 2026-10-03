-- Add not_staying_overnight to rsvps
-- Set from the camping roster page to leave a party off the printed roster (e.g. day visitors).

ALTER TABLE rsvps
ADD COLUMN not_staying_overnight TINYINT(1) NOT NULL DEFAULT 0
COMMENT 'Party is not staying overnight; omitted from the printed camping roster'
AFTER n_guests;
