-- Batch 14 runtime-account privilege migration.
--
-- This file must be executed by a deployment/schema account with permission
-- to manage the runtime account. It is never executed by PHP or a web request.
--
-- Required client variables, supplied before sourcing this file:
--   @myshop_runtime_user = the application DB_USER
--   @myshop_runtime_host = the MySQL account host, normally '%'
--
-- The database is taken from the selected connection database. No password
-- or credential is stored in this repository. The operation deliberately
-- revokes the existing account grants before granting only application CRUD.

DROP PROCEDURE IF EXISTS myshop_batch14_runtime_privileges;

DELIMITER //

CREATE PROCEDURE myshop_batch14_runtime_privileges()
SQL SECURITY INVOKER
BEGIN
    DECLARE v_database_name VARCHAR(255);
    DECLARE v_runtime_user VARCHAR(255);
    DECLARE v_runtime_host VARCHAR(255);
    DECLARE v_account_name TEXT;
    DECLARE v_sql TEXT;

    SET v_database_name = DATABASE();
    SET v_runtime_user = @myshop_runtime_user;
    SET v_runtime_host = @myshop_runtime_host;

    IF v_database_name IS NULL OR v_database_name = '' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A selected application database is required';
    END IF;

    IF v_runtime_user IS NULL OR v_runtime_user = '' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = '@myshop_runtime_user must be supplied';
    END IF;

    IF v_runtime_host IS NULL OR v_runtime_host = '' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = '@myshop_runtime_host must be supplied';
    END IF;

    IF LOWER(v_runtime_user) = 'root' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'The runtime account must not be root';
    END IF;

    SET v_account_name = CONCAT(QUOTE(v_runtime_user), '@', QUOTE(v_runtime_host));

    SET v_sql = CONCAT('REVOKE ALL PRIVILEGES, GRANT OPTION FROM ', v_account_name);
    SET @myshop_batch14_sql = v_sql;
    PREPARE batch14_stmt FROM @myshop_batch14_sql;
    EXECUTE batch14_stmt;
    DEALLOCATE PREPARE batch14_stmt;

    SET v_sql = CONCAT(
        'GRANT SELECT, INSERT, UPDATE, DELETE ON `',
        REPLACE(v_database_name, '`', '``'),
        '`.* TO ',
        v_account_name
    );
    SET @myshop_batch14_sql = v_sql;
    PREPARE batch14_stmt FROM @myshop_batch14_sql;
    EXECUTE batch14_stmt;
    DEALLOCATE PREPARE batch14_stmt;
END//

DELIMITER ;

CALL myshop_batch14_runtime_privileges();
DROP PROCEDURE myshop_batch14_runtime_privileges;
