-- Migration: make youth address fields nullable
-- Apply this after deploying code that no longer requires youth address fields.

ALTER TABLE youth
  MODIFY street1 VARCHAR(255) NULL,
  MODIFY street2 VARCHAR(255) NULL,
  MODIFY city    VARCHAR(100) NULL,
  MODIFY state   VARCHAR(50)  NULL,
  MODIFY zip     VARCHAR(20)  NULL;
