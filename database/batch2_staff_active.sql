-- Batch 2 migration for databases created from the Batch 1 schema.
-- Run once with an explicit schema/deployment account.
-- This file is never executed by a web request.

ALTER TABLE Staff
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role,
    ADD CONSTRAINT chk_staff_is_active CHECK (is_active IN (0, 1)),
    ADD INDEX idx_staff_active (is_active);
