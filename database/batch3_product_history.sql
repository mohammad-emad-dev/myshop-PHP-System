-- Batch 3 migration for existing databases.
--
-- Preconditions:
-- 1. The database was created from the canonical Batch 1 database/schema.sql.
-- 2. database/batch2_staff_active.sql completed successfully first.
-- 3. All affected tables use InnoDB.
-- 4. The canonical foreign-key names exist exactly as follows:
--      OrderDetail.fk_order_detail_order
--      StockMovement.fk_stock_movement_product
--      StockMovement.fk_stock_movement_staff
--
-- Optional preflight check, run separately with the target database selected:
-- SELECT TABLE_NAME, CONSTRAINT_NAME, DELETE_RULE
-- FROM information_schema.REFERENTIAL_CONSTRAINTS
-- WHERE CONSTRAINT_SCHEMA = DATABASE()
--   AND CONSTRAINT_NAME IN (
--       'fk_order_detail_order',
--       'fk_stock_movement_product',
--       'fk_stock_movement_staff'
--   )
-- ORDER BY TABLE_NAME, CONSTRAINT_NAME;
--
-- Run once from a controlled CLI/deployment process with a schema account.
-- Never execute this file from PHP, config/db.php, or a web request.
-- Do not use an SQL-client option that ignores errors. These ALTER statements
-- intentionally do not use IF EXISTS: a missing or renamed constraint must
-- fail visibly instead of being silently skipped.
-- If either statement fails, stop, retain the error output, inspect the
-- current schema and migration state, and resolve it before retrying. MySQL
-- DDL can commit each ALTER independently; do not blindly rerun the file.
-- Databases predating Batch 1 require manual schema/data inspection first.

ALTER TABLE StockMovement
    DROP FOREIGN KEY fk_stock_movement_product,
    DROP FOREIGN KEY fk_stock_movement_staff,
    ADD CONSTRAINT fk_stock_movement_product
        FOREIGN KEY (product_id) REFERENCES Product(id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_stock_movement_staff
        FOREIGN KEY (staff_id) REFERENCES Staff(id) ON DELETE RESTRICT;

ALTER TABLE OrderDetail
    DROP FOREIGN KEY fk_order_detail_order,
    ADD CONSTRAINT fk_order_detail_order
        FOREIGN KEY (order_id) REFERENCES `Order`(id) ON DELETE RESTRICT;
