# Batch 7C: Atomic Manual Stock-Adjustment Extraction

This batch moved the manual stock-adjustment transaction from
`public/stock_movements.php` into `inventory_adjust_stock()` in
`includes/inventory.php`. The page retains request/action detection, CSRF
validation, administrator authorization, input parsing, generic messages,
HTTP responses, filtering, pagination, and rendering.

## Preserved transaction contract

The service receives `$staff_id` explicitly and does not read session state.
It owns the transaction boundary and preserves:

- `SELECT stock FROM Product WHERE id = ? FOR UPDATE`;
- nonnegative current-stock validation and signed 32-bit underflow/overflow
  protection;
- guarded `UPDATE Product SET stock = ? WHERE id = ? AND stock = ?`;
- `inventory_log_stock_movement()` with `manual_adjustment`;
- the `stock_adjustment` success audit metadata (`quantity`, `new_stock`);
- commit only after movement and success audit completion; and
- statement cleanup, rollback, failure logging, and explicit-actor failure audit
  after database-operation rollback.

The page continues to record the existing CSRF failure and authorization-denied
events before it delegates a validated adjustment.

## TDD evidence

| Stage | Command | Result |
|---|---|---|
| RED | `docker compose run --rm --no-deps app php -r "require 'tests/Unit/inventory_adjustment_test.php'; echo run_inventory_adjustment_unit_tests(), PHP_EOL;"` before implementation | Failed because `inventory_adjust_stock()` was missing. |
| GREEN | Same focused command after implementation | Passed with `34` assertions. |
| Full regression | `docker compose --env-file .env exec -T -e TEST_DB_HOST=db -e TEST_DB_PORT=3306 -e TEST_DB_ROOT_USER=root -e TEST_DB_ROOT_PASSWORD=<local disposable value> app php tests/run.php` | Passed with `1224 assertions (714 unit, 510 integration)`. |
| Browser QA | `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/run-browser-qa.ps1` | Passed `18/18` at 375px, 768px, and 1440px. |

The disposable integration suite verified admin success, cashier denial,
invalid CSRF, missing products, invalid stored stock, underflow, overflow,
forced movement insertion failure, audit failure, rollback, stock and
movement consistency, success/failure audit records, and clean page output.

## Scope boundaries

- `create_product()`, `update_product()`, `create_order()`, sales, purchases,
  schema, UI, and compatibility wrappers were not otherwise changed.
- Product and order callers continue using the legacy movement wrapper.
- No session or hidden global dependency was added to `includes/inventory.php`.
- All test databases and temporary database objects are disposable and cleaned
  by the integration harness.
