-- Make users.email nullable (keeps existing UNIQUE index; MySQL allows multiple NULLs)
ALTER TABLE users
  MODIFY email VARCHAR(255) NULL;
