# Cub Scouts

PHP/MySQL web app for managing a Cub Scout Pack. Mirrors the architecture and clean UI patterns from the Tournaments app:
- PHP + MySQL
- Three-tier style (lib classes, management logic, UI pages)
- Cache-busted static resources
- Login flows incl. email verification, password reset, change password
- Settings page with Site Title, Announcement, Time Zone

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

## Key Flows by Component

1. User Flows

A. Users are "Adults" (as opposed to youth members) and email addresses are unique.
B. The only way to create a user account is to be added by an administrator (ie, there is no public way to create a user account)
C. A user account can be "activated" if there is an email specified in the account through two ways:
(C1) An admin can "invite" the user to activate their account
(C2) A user can go through the "forgot password" flow

Non-activated users
A. Can be invited to events and can view events through event invitation links
(cryptographic fingerprint to verify event-id / uid combination)
B. Can RSVP to events (which RSVP's as their user)
C. Can volunteer at events.
D. Can activate their account (by going through the forgot-password flow)
E. Will in the future be able to see a calendar view of upcoming events through links generated for the user.

Public users
A. Can view public event links (cryptographic fingerprint to verify event-id)
B. Can RSVP for events through public event links
C. Cannot volunteer for roles at events

Logged in Users
A. Will see a dashboard with clear items "to do" reflecting the operations and needs of the organization
- Register for Scouting America (if they aren't registered)
- Upcoming Events
- Volunteer at upcoming events

Admins
A. Can do things other users cannot do
- Can add adults, can edit any adult
- Can add youth, can edit any youth
- Can create events, can change anyone's RSVP
- Can add volunteer roles to events
- Can change application settings
- Can change a member's role to Cubmaster, Treasurer, Committee Chair, Den Leader
B. Sees other homepage sections
- Reimbursements (if cubmaster, treasurer)
- Summary of registration todo's

2. Application-specific user-flows
A. Linking users to youth

3. Reimbursement Flows
A. All users:
- Submit a new reimbursement request (with optional file attachment)
- View reimbursement requests I have submitted, add comments, change status, attach more files
B. Approvers
- View and comment on all reimbursement requests
- Approve or reject or send back for more information
C. Admins
- View all reimbursement requests

4. Events - All Users
A. Upcoming Events Page: See upcoming events
B. RSVP to an event (for myself, my children, and any adult related to my children)
C. Edit RSVP's to an event (for myself, my children, and any adult related to my children)
D. Volunteer for a role for an event

5. Events - Admins
A. Create events, edit events, delete events
B. Create volunteer roles at events
C. Edit anyone's RSVP's at an event.

6. Mailing List
** A. Viewable by admins

7. Scouting specific featuers
- YPT Training Date (expiring)

= Data Model = 

See schema.sql
