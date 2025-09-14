-- Migration: Add new_application and application_processed to payment_notifications_from_users
-- Run this before deploying code that relies on these columns.

START TRANSACTION;

ALTER TABLE payment_notifications_from_users
  ADD COLUMN new_application TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN application_processed TINYINT(1) NOT NULL DEFAULT 0 AFTER new_application;

COMMIT;
