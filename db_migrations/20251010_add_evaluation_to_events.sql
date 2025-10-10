-- Add evaluation column to events table
-- This field allows capturing lessons learned and reflections after an event is completed

ALTER TABLE events 
ADD COLUMN evaluation TEXT DEFAULT NULL 
AFTER description;
