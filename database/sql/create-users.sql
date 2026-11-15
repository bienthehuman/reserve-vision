-- Least-privilege database users (ADR 0008).
--
-- Plain SQL for the mysql or mariadb client, run as an administrative user.
-- No Docker assumptions: dev (docker/mysql/initdb.d), CI (1.2) and production
-- (1.3, compose.prod.yaml) all run this same file. Run it once per schema, after setting:
--
--   SET @db_name           = 'reservevision',
--       @app_password      = '…',
--       @migrator_password = '…';
--   SOURCE database/sql/create-users.sql;
--
-- Idempotent: running it again keeps existing data and resets the passwords
-- to the given values.
--
--   migrator  ALL PRIVILEGES on the schema. Used only by `php artisan migrate`
--             (connection `mysql_migrator`).
--   app       SELECT, INSERT, UPDATE, DELETE on the schema. No DDL, no TRIGGER.
--             Used by the web app, queue workers, scheduler and the test suite.

SET @db_ident = CONCAT('`', REPLACE(@db_name, '`', '``'), '`');

-- Schema
SET @stmt = CONCAT('CREATE DATABASE IF NOT EXISTS ', @db_ident,
                   ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- Users (global; shared by every schema this script is run for)
SET @stmt = CONCAT('CREATE USER IF NOT EXISTS ''migrator''@''%'' IDENTIFIED BY ', QUOTE(@migrator_password));
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt = CONCAT('ALTER USER ''migrator''@''%'' IDENTIFIED BY ', QUOTE(@migrator_password));
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SET @stmt = CONCAT('CREATE USER IF NOT EXISTS ''app''@''%'' IDENTIFIED BY ', QUOTE(@app_password));
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
SET @stmt = CONCAT('ALTER USER ''app''@''%'' IDENTIFIED BY ', QUOTE(@app_password));
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- migrator: everything on this schema, nothing outside it
SET @stmt = CONCAT('GRANT ALL PRIVILEGES ON ', @db_ident, '.* TO ''migrator''@''%''');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- app: data only, no DDL
SET @stmt = CONCAT('GRANT SELECT, INSERT, UPDATE, DELETE ON ', @db_ident, '.* TO ''app''@''%''');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- audit_logs is append-only for `app` (ADR 0008), but not through grants: MySQL
-- has no per-table DENY, so the schema-wide grant above includes UPDATE and
-- DELETE on it. The migration that creates audit_logs adds BEFORE UPDATE and
-- BEFORE DELETE triggers that signal an error. `app` has no TRIGGER or DROP
-- privilege, so it can neither remove them nor TRUNCATE the table. The server
-- needs log_bin_trust_function_creators=1 for the migrator to create them.

FLUSH PRIVILEGES;
