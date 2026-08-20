# Batch 7B: Inventory Stock-Movement Write Extraction

This batch moved the implementation of `log_stock_movement()` into the
independent `includes/inventory.php` module as
`inventory_log_stock_movement()`. Existing callers remain on the legacy
compatibility wrapper. No caller migration, transaction boundary, stock
mutation, authorization, CSRF, audit, or schema behavior was changed.

## Call-site inventory before extraction

The repository-wide search was:

```text
rg -n --glob '*.php' '\blog_stock_movement\s*\(' .
```

Verified production callers before extraction:

| Location | Caller context | Decision |
|---|---|---|
| `public/stock_movements.php:96` | Manual stock adjustment | Retained on `log_stock_movement()` wrapper |
| `includes/functions.php:761` | `create_product()` initial stock history | Retained on wrapper |
| `includes/functions.php:904` | `update_product()` stock-delta history | Retained on wrapper |
| `includes/functions.php:1388` | `create_order()` sale/purchase history | Retained on wrapper |

The same search found source-contract mentions in
`tests/Unit/inventory_read_test.php` and
`tests/Unit/auth_extraction_test.php`. A repository search for dynamic calls
(`call_user_func`, `call_user_func_array`, and variable-function syntax) found
no dynamic `log_stock_movement()` caller.

## Implementation and compatibility seam

Added to `includes/inventory.php`:

- `inventory_log_stock_movement($conn, $product_id, $staff_id, $quantity, $movement_type, $reason = null)`

The function preserves the original prepared `StockMovement` insert, column
order, `iiiss` binding, affected-row check, boolean return values, error-log
messages, exception handling, and statement cleanup.

`includes/functions.php` retains `log_stock_movement()` as a delegation-only
wrapper with the original arguments. These callers intentionally remain on
that wrapper in this batch:

- `public/stock_movements.php`
- `create_product()`
- `update_product()`
- `create_order()`

The Inventory module does not require `includes/functions.php`.

## TDD and verification evidence

| Stage | Command | Result |
|---|---|---|
| RED | `docker compose run --rm --no-deps app php -r "require 'tests/Unit/inventory_read_test.php'; echo run_inventory_read_unit_tests(), PHP_EOL;"` before implementation | Failed as intended because `inventory_log_stock_movement()` was missing. |
| GREEN | Same focused command after implementation | Passed with `58` assertions. |
| Full regression | `docker compose --env-file .env exec -T -e TEST_DB_HOST=db -e TEST_DB_PORT=3306 -e TEST_DB_ROOT_USER=root -e TEST_DB_ROOT_PASSWORD=<local disposable value> app php tests/run.php` | Passed with `1175 assertions (682 unit, 493 integration)`. |
| Browser QA | `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/run-browser-qa.ps1` | Passed `18/18` at mobile 375px, tablet 768px, and desktop 1440px. |

The integration suite additionally verified a successful compatibility-wrapper
insert, safe failure for an invalid foreign key and closed connection, order
sale/purchase movement records, manual adjustment behavior, insufficient-stock
rollback, stock quantities, audit records, and authorization/CSRF behavior.

## Preserved boundaries

- No production caller was migrated.
- No transaction was added, removed, or moved.
- Product, order, and manual stock-adjustment SQL remains in its existing owner.
- `SELECT ... FOR UPDATE`, stock quantities, movement records, audit events,
  authorization, CSRF checks, response behavior, and schema are unchanged.
- No secrets, database dumps, `.env` files, or generated artifacts were added.
