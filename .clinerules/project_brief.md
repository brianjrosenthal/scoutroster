### Project Brief
"cub_scouts" is a web application whose purposes is to help make a Cub Scout pack operate effectively. 

## User Types

There are "admins" and "users".

## Key user flows

1. Login.  There is a login page (/login.php) which allows a user to login.  Users are redirected there if they are not logged in.
2. Forgot my password.  /forgot_password.php.  Generates and emails a link to /reset_password.php with a token that allows a user to reset their password.
3. View the homepage.  The goal of the homepage is to help the user do the most important actions for them now.  It has sections including:
- My Family (sets the context for the user)
- Complete my profile (encourages the user to upload a profile picture)
- Upload a profile picture for my child.
- Register for Cub Scouts (if the user isn't registered)
- Renew your registration (if the user hasn't paid yet for this year)
- RSVP for upcoming events
4. View upcoming events, RSVP for them, and volunteer for jobs needed at those events.
5. Recommend a friend to Scouts.
6. Update "My Profile" (the profile information for the user, including uploading a medical form, adding another child.
7. Submit a reimbursement request
8. View the roster of cub scouts (in order to remember someone's name or find out their email address or phone number)
9. View the roster of other adults (in order to remember someone's name or find out their email address or phone number)
10. Logout

There are certain user flows that don't require a user to be logged in:
1. Responding to a personalized event invitation.  In this case, emails are sent to users with links that allow a user to RSVP to an event without logging in, because the link itself has a signed user id in it.
2. Clicking on a "public link" url (event_public.php).  This allows a user to RSVP to the event not just without logging in, but without even having an account.  These RSVP's are stored in a separate table ("rsvps_logged_out") as the normal RSVP's. 

Users cannot create their own account.  Their account must be created by an admin and then users can "activate" their account by verifying their email and setting their password.

Not built yet:
1. Viewing photos for an event (and uploading photos to an event)

## Key admin flows
1. All of the user flows (since admins are also users)
2. Adding new adults to the system and their kids (individually through /admin_adults.php, /admin_youth.php, or in bulk through /admin_import_upload.php)
3. Updating the bsa registration expiration date in buik by important from the export from scouting.org
4. Adding new events to the system
... editing them over time, configuring the volunteer roles for the event, viewing RSVP's for an event.
5. Downloading the mailing list to a CSV.
6. Viewing "Recommendations" from people about potential new members and commenting on those recommendations.
ISSUE - there should be a way to "archive" a recommendation.
7. Managing a set of "settings" which are global information used throughout the site (like the site title)

## Reimbuirsement "approver" flows
- Cubmaster Treasurer, Committee Chair) can approve (or reject) reimbursements, as well as send re-imbursements back to the submitter for more information.
- These approvers see pending reimbursement requests on the homepage in a homepage section.

## Architectural notes:
1. The application is a PHP / MYSQL application
2. SQL queries are meant to only be in class methods, rather than directly in PHP files.  Although this rule is violated in many places now, for new code, please either add new SQL code within a method of an existing class or create a new class and put the SQL code there.
3. Uplaods are currently stored in the file system, but I'd like to transition this so that instead they are stored in the database and cached in the file system.
4. The database schema is documented in a file schema.sql.
5. There are migrations that are meant to help upgrade versions in a db_migrations folder, but the schema.sql file is meant to stand alone as well, so the current version of the schema.sql file at any time should not need any migrations.

## Design Notes
1. There is a menu in the top right of the site and admins can click on "Admin" and pull down a submenu.
2. The profile photo pulls down a submenu as well.

## Security notes
1. Forms are protected with CSRF tokens.
2. Passwords have reasonable constraints to disallow weak passwords.
3. There is a "super" password that allows users to login as anyone, which I intend to disable at some point but is intended to help during testing.
4. There is a config.local.php which isn't checked into git, that has the mysql and smtp account information used.

## Data Model Notes
1. The data model is best understood by reading schema.sql

## Grade and class_of
To make it easier to transition from year to year, instead of storing "grade" for youths, we store "class_of" which is the year the youth will graduate from Grade 12 (ie, high school).  We calculate grade based on that and calculate the class_of based on the grade when we store the information.

## Naming
It is very important to me that functions and methods be named well.  The name of a method should express its intent.  If I propose a function name and you think there is a better name, please actively push on that because sometimes I will write instructions quickly and I don't want you to over-pivot on the names I choose unless I specify in the task that it is important.

## Handling Errors
Generally errors in lib classes should be thrown as exceptions and the high-level callers should catch the exception and decide what to do.  Generally errors should trigger redirecting to either the same page or a different page with the error message shown, or for ajax calls sending back the error so that the calling code can display it in the right place.
