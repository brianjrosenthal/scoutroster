# Cub Scouts

PHP/MySQL web app for managing a Cub Scout Pack. Mirrors the architecture and clean UI patterns from the Tournaments app:
- PHP + MySQL
- Three-tier style (lib classes, management logic, UI pages)
- Cache-busted static resources
- Login flows incl. email verification, password reset, change password
- Settings page with Site Title, Announcement, Time Zone
- SUPER_PASSWORD in config.local.php (not in source)

## Setup

1) Create a database and import schema:
- Create DB and user in MySQL
- Import `schema.sql` into your database
- Optionally seed an admin (see commented section at the end of `schema.sql`)

2) Create config.local.php:
- Copy `config.local.php.example` to `config.local.php`
- Fill in DB creds, SMTP values, optionally set a SUPER_PASSWORD for temporary testing (leave blank to disable)

3) Run locally with PHP's built-in server (example):
- From the `web_applications` folder:
  php -S localhost:3001 -t cub_scouts
- Then visit http://localhost:3001/login.php

4) Email (SMTP):
- Configure Gmail SMTP using an App Password or your provider’s SMTP
- Email is used for account verification and password resets

## Notes

- Site title is read from Settings (admin can change it). Defaults to "Cub Scouts Pack 440".
- Announcement shows on Home when set.
- Time zone from Settings is used for date formatting.
- SUPER_PASSWORD (in `config.local.php`) allows a universal bypass for login verification (still requires email verified). Clear it when done testing.

## Next Features

- Youth roster (search/filter by grade), admin add/edit youth
- Adult roster with privacy rules (only see contact info for parents in same den unless admin)
- Dens management & membership
- Events list + event page + RSVP group edit
- My Profile (adult + children info) with medical form upload/download (PDF, authorized access)
- Admin mailing list export (Evite CSV)
- ICS calendar feed endpoint (subscribe from Google/Apple Calendar)

## Project Structure

- config.php, config.local.php.example
- auth.php, partials.php, settings.php, mailer.php
- styles.css, main.js
- index.php (home)
- login.php, logout.php, verify_email.php, verify_resend.php
- forgot_password.php, reset_password.php, change_password.php
- admin_settings.php
- schema.sql, db_migrations/
- lib/
  - UserManagement.php
  - GradeCalculator.php
