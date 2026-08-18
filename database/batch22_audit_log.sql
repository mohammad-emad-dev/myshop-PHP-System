-- Batch 22 audit-log migration.
--
-- Execute this file with a controlled deployment/schema account against the
-- selected application database, in the documented migration order. It is
-- never executed by PHP, config/db.php, a web request, or application startup.
--
-- The migration is idempotent when AuditLog is absent or already matches the
-- canonical Batch 22 schema. A partial or incompatible existing table fails
-- explicitly and must be inspected before any retry; this migration does not
-- silently alter an unknown audit table.

DROP PROCEDURE IF EXISTS myshop_batch22_audit_log;

DELIMITER //

CREATE PROCEDURE myshop_batch22_audit_log()
SQL SECURITY INVOKER
BEGIN
    DECLARE v_database_name VARCHAR(255);
    DECLARE v_table_count INT DEFAULT 0;
    DECLARE v_column_count INT DEFAULT 0;
    DECLARE v_total_column_count INT DEFAULT 0;
    DECLARE v_index_count INT DEFAULT 0;
    DECLARE v_expected_index_count INT DEFAULT 0;
    DECLARE v_constraint_count INT DEFAULT 0;

    SET v_database_name = DATABASE();

    IF v_database_name IS NULL OR v_database_name = '' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A selected application database is required';
    END IF;

    SELECT COUNT(*) INTO v_table_count
    FROM information_schema.tables
    WHERE table_schema = v_database_name
      AND table_name = 'AuditLog'
      AND table_type = 'BASE TABLE';

    IF v_table_count = 0 THEN
        CREATE TABLE AuditLog (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actor_staff_id INT UNSIGNED NULL,
            action VARCHAR(80) NOT NULL,
            entity_type VARCHAR(50) NOT NULL,
            entity_id INT UNSIGNED NULL,
            outcome ENUM('success', 'failure') NOT NULL,
            source_ip VARCHAR(45) CHARACTER SET ascii COLLATE ascii_bin NULL,
            metadata JSON NULL,
            CONSTRAINT fk_audit_actor
                FOREIGN KEY (actor_staff_id) REFERENCES Staff(id) ON DELETE SET NULL,
            INDEX idx_audit_created_at (created_at, id),
            INDEX idx_audit_actor_created (actor_staff_id, created_at, id),
            INDEX idx_audit_action_created (action, created_at, id),
            INDEX idx_audit_entity_created (entity_type, entity_id, created_at, id),
            INDEX idx_audit_outcome_created (outcome, created_at, id)
        ) ENGINE = InnoDB
          DEFAULT CHARACTER SET utf8mb4
          COLLATE = utf8mb4_unicode_ci;
    ELSE
        SELECT COUNT(*) INTO v_total_column_count
        FROM information_schema.columns
        WHERE table_schema = v_database_name
          AND table_name = 'AuditLog';

        SELECT COUNT(*) INTO v_column_count
        FROM information_schema.columns
        WHERE table_schema = v_database_name
          AND table_name = 'AuditLog'
          AND column_name IN (
              'id', 'created_at', 'actor_staff_id', 'action', 'entity_type',
              'entity_id', 'outcome', 'source_ip', 'metadata'
          );

        IF v_total_column_count <> 9 OR v_column_count <> 9 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'AuditLog exists with an incompatible column set';
        END IF;

        SELECT COUNT(DISTINCT index_name) INTO v_index_count
        FROM information_schema.statistics
        WHERE table_schema = v_database_name
          AND table_name = 'AuditLog';

        SELECT COUNT(DISTINCT index_name) INTO v_expected_index_count
        FROM information_schema.statistics
        WHERE table_schema = v_database_name
          AND table_name = 'AuditLog'
          AND index_name IN (
              'idx_audit_created_at',
              'idx_audit_actor_created',
              'idx_audit_action_created',
              'idx_audit_entity_created',
              'idx_audit_outcome_created'
          );

        IF v_index_count <> 6 OR v_expected_index_count <> 5 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'AuditLog exists with an incompatible index set';
        END IF;

        SELECT COUNT(DISTINCT constraint_name) INTO v_constraint_count
        FROM information_schema.table_constraints
        WHERE table_schema = v_database_name
          AND table_name = 'AuditLog'
          AND constraint_name IN ('PRIMARY', 'fk_audit_actor');

        IF v_constraint_count <> 2 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'AuditLog exists with an incompatible constraint set';
        END IF;
    END IF;
END//

DELIMITER ;

CALL myshop_batch22_audit_log();
DROP PROCEDURE myshop_batch22_audit_log;
