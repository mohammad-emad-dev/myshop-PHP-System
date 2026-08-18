-- Batch 2 migration for existing databases based on the pre-active-staff
-- Batch 1 baseline.
--
-- This migration is idempotent across both supported states:
--   1. an old Batch 1 database with none of the active-staff objects; and
--   2. a current canonical database where schema.sql already created them.
--
-- Run once with an explicit schema/deployment account. This file is never
-- executed by a web request. Missing objects are added conditionally; any
-- unrelated DDL error is allowed to abort the migration.

DROP PROCEDURE IF EXISTS myshop_batch2_staff_active;

DELIMITER //

CREATE PROCEDURE myshop_batch2_staff_active()
SQL SECURITY INVOKER
BEGIN
    DECLARE v_column_count INT DEFAULT 0;
    DECLARE v_constraint_count INT DEFAULT 0;
    DECLARE v_index_count INT DEFAULT 0;
    DECLARE v_index_parts INT DEFAULT 0;
    DECLARE v_active_index_parts INT DEFAULT 0;
    DECLARE v_sql TEXT;

    SELECT COUNT(*)
      INTO v_column_count
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'Staff'
       AND COLUMN_NAME = 'is_active';

    IF v_column_count = 0 THEN
        SET v_sql = 'ALTER TABLE `Staff` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `role`';
        SET @myshop_batch2_sql = v_sql;
        PREPARE batch2_stmt FROM @myshop_batch2_sql;
        EXECUTE batch2_stmt;
        DEALLOCATE PREPARE batch2_stmt;
    END IF;

    SELECT COUNT(*)
      INTO v_constraint_count
      FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'Staff'
       AND CONSTRAINT_NAME = 'chk_staff_is_active'
       AND CONSTRAINT_TYPE = 'CHECK';

    IF v_constraint_count = 0 THEN
        SET v_sql = 'ALTER TABLE `Staff` ADD CONSTRAINT `chk_staff_is_active` CHECK (`is_active` IN (0, 1))';
        SET @myshop_batch2_sql = v_sql;
        PREPARE batch2_stmt FROM @myshop_batch2_sql;
        EXECUTE batch2_stmt;
        DEALLOCATE PREPARE batch2_stmt;
    END IF;

    SELECT COUNT(*),
           COALESCE(SUM(CASE WHEN COLUMN_NAME = 'is_active' AND SEQ_IN_INDEX = 1 THEN 1 ELSE 0 END), 0)
      INTO v_index_parts, v_active_index_parts
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'Staff'
       AND INDEX_NAME = 'idx_staff_active';

    IF v_index_parts > 0 AND (v_index_parts <> 1 OR v_active_index_parts <> 1) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'idx_staff_active exists with an unexpected definition';
    END IF;

    IF v_index_parts = 0 THEN
        SET v_sql = 'ALTER TABLE `Staff` ADD INDEX `idx_staff_active` (`is_active`)';
        SET @myshop_batch2_sql = v_sql;
        PREPARE batch2_stmt FROM @myshop_batch2_sql;
        EXECUTE batch2_stmt;
        DEALLOCATE PREPARE batch2_stmt;
    END IF;
END//

DELIMITER ;

CALL myshop_batch2_staff_active();
DROP PROCEDURE myshop_batch2_staff_active;
