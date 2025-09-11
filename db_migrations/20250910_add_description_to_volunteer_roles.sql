-- Migration: Add description column to volunteer_roles
-- Run this against the current database before deploying code that relies on vr.description

ALTER TABLE volunteer_roles
  ADD COLUMN description TEXT NULL AFTER title;
