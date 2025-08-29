# DB Migrations

This folder is reserved for future database migrations for the Cub Scouts application.

- Use timestamped filenames (e.g., `2025-08-29_add_events_table.sql`).
- Each migration should be idempotent or include guards (e.g., `IF NOT EXISTS`) where appropriate.
- Apply migrations in chronological order after seeding the initial `schema.sql`.

Initial bootstrap:
1) Create database and user in MySQL.
2) Import `schema.sql`.
3) Configure `config.local.php` with DB and SMTP settings.
4) Run the app and sign in with the seeded admin (or create an admin manually via SQL if you didn't seed).
