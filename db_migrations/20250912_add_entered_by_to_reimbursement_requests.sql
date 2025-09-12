-- Migration: Add entered_by to reimbursement_requests to support on-behalf submissions
-- Run this against the current database before deploying code that relies on r.entered_by

START TRANSACTION;

-- 1) Add column as NULLable to avoid failure on existing rows
ALTER TABLE reimbursement_requests
  ADD COLUMN entered_by INT NULL AFTER created_by;

-- 2) Backfill all existing rows: entered_by = created_by
UPDATE reimbursement_requests
  SET entered_by = created_by
  WHERE entered_by IS NULL;

-- 3) Enforce NOT NULL after backfill
ALTER TABLE reimbursement_requests
  MODIFY COLUMN entered_by INT NOT NULL;

-- 4) Add foreign key constraint and index
ALTER TABLE reimbursement_requests
  ADD CONSTRAINT fk_rr_entered_by FOREIGN KEY (entered_by) REFERENCES users(id) ON DELETE RESTRICT;

CREATE INDEX idx_rr_entered_by ON reimbursement_requests(entered_by);

COMMIT;
