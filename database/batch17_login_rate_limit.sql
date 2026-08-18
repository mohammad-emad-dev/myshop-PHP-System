-- Batch 17 login rate-limit migration.
--
-- Execute this file once, in the documented order, with a deployment/schema
-- account against the selected application database. It is never executed by
-- PHP, config/db.php, a web request, or application startup.
--
-- The migration is idempotent for a database where LoginRateLimit is absent or
-- already matches the canonical Batch 17 schema. If a partial or incompatible
-- table exists, it fails explicitly and requires manual inspection; it does
-- not silently alter an unknown table.

DROP PROCEDURE IF EXISTS myshop_batch17_login_rate_limit;

DELIMITER //

CREATE PROCEDURE myshop_batch17_login_rate_limit()
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
      AND table_name = 'LoginRateLimit'
      AND table_type = 'BASE TABLE';

    IF v_table_count = 0 THEN
        CREATE TABLE LoginRateLimit (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            ip_address VARCHAR(45) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            failure_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            first_attempt_at DATETIME NOT NULL,
            last_attempt_at DATETIME NOT NULL,
            blocked_until DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT chk_login_rate_failure_count CHECK (failure_count <= 5),
            CONSTRAINT uq_login_rate_account_ip UNIQUE (username_hash, ip_address),
            INDEX idx_login_rate_blocked_until (blocked_until),
            INDEX idx_login_rate_last_attempt (last_attempt_at)
        ) ENGINE = InnoDB
          DEFAULT CHARACTER SET utf8mb4
          COLLATE = utf8mb4_unicode_ci;
    ELSE
        SELECT COUNT(*) INTO v_total_column_count
        FROM information_schema.columns
        WHERE table_schema = v_database_name
          AND table_name = 'LoginRateLimit';

        SELECT COUNT(*) INTO v_column_count
        FROM information_schema.columns
        WHERE table_schema = v_database_name
          AND table_name = 'LoginRateLimit'
          AND column_name IN (
              'id', 'username_hash', 'ip_address', 'failure_count',
              'first_attempt_at', 'last_attempt_at', 'blocked_until',
              'created_at', 'updated_at'
          );

        IF v_total_column_count <> 9 OR v_column_count <> 9 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'LoginRateLimit exists with an incompatible column set';
        END IF;

        SELECT COUNT(DISTINCT index_name) INTO v_index_count
        FROM information_schema.statistics
        WHERE table_schema = v_database_name
          AND table_name = 'LoginRateLimit';

        SELECT COUNT(DISTINCT index_name) INTO v_expected_index_count
        FROM information_schema.statistics
        WHERE table_schema = v_database_name
          AND table_name = 'LoginRateLimit'
          AND index_name IN (
              'uq_login_rate_account_ip',
              'idx_login_rate_blocked_until',
              'idx_login_rate_last_attempt'
          );

        IF v_index_count <> 4 OR v_expected_index_count <> 3 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'LoginRateLimit exists with an incompatible index set';
        END IF;

        SELECT COUNT(DISTINCT constraint_name) INTO v_constraint_count
        FROM information_schema.table_constraints
        WHERE table_schema = v_database_name
          AND table_name = 'LoginRateLimit'
          AND constraint_name IN (
              'PRIMARY',
              'uq_login_rate_account_ip',
              'chk_login_rate_failure_count'
          );

        IF v_constraint_count <> 3 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'LoginRateLimit exists with an incompatible constraint set';
        END IF;
    END IF;
END//

DELIMITER ;

CALL myshop_batch17_login_rate_limit();
DROP PROCEDURE myshop_batch17_login_rate_limit;
