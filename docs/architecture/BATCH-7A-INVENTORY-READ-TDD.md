# Batch 7A: Inventory Stock-Movement Read Extraction

This batch moved only bounded stock-movement count and page reads into
`includes/inventory.php`. Stock mutation, authorization, CSRF, locking,
movement logging, and audit behavior remain in their existing owners.

## Call-site inventory

The repository-wide inventory before extraction found:

| Function | Verified callers before extraction | Decision |
|---|---|---|
| `get_stock_movements()` | Definition in `includes/functions.php`; the export-streaming test mentions it only as an unbounded-loader fixture. No verified runtime caller. | Retained unchanged in the compatibility facade. |
| `count_stock_movements()` | `public/stock_movements.php:152`; bounded integration coverage in `tests/Integration/database_test.php:1039`. | Extracted and migrated. |
| `get_stock_movements_page()` | `public/stock_movements.php:158`; bounded integration coverage in `tests/Integration/database_test.php:1040-1042`. | Extracted and migrated. |
| `get_low_stock_products()` | `public/index.php:29` and `includes/layouts/navbar.php:27`. | Retained unchanged. |
| `get_inventory_valuation()` | `public/index.php:26`. | Retained unchanged. |

## Functions and compatibility seams

New independent module functions:

- `inventory_count_stock_movements()`
- `inventory_get_stock_movements_page()`

Legacy wrappers retained in `includes/functions.php`:

- `count_stock_movements()` delegates to `inventory_count_stock_movements()`.
- `get_stock_movements_page()` delegates to `inventory_get_stock_movements_page()`.

The module uses prepared statements, preserves the existing optional product
filter, newest-first `created_at`/`id` ordering, normalized page size and
offset, empty-array/zero failure returns, and existing error-log messages. It
does not require `functions.php`.

## TDD evidence

| Stage | Command | Result |
|---|---|---|
| RED | `docker compose run --rm --no-deps app php -r "require 'tests/Unit/inventory_read_test.php'; echo run_inventory_read_unit_tests(), PHP_EOL;"` before implementation | Failed because the Inventory extraction source fixture was missing. |
| GREEN | Same command after implementation | Passed with `37` assertions. |
| Full regression | `docker compose --env-file .env exec -T -e TEST_DB_HOST=db -e TEST_DB_PORT=3306 -e TEST_DB_ROOT_USER=root -e TEST_DB_ROOT_PASSWORD=<local disposable value> app php tests/run.php` | Passed with `1139 assertions (661 unit, 478 integration)`. |
| Browser QA | `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/run-browser-qa.ps1` | Passed `18/18` at 375px, 768px, and 1440px. |

The existing disposable integration suite also continued to verify movement
count, bounded pages, product filtering, empty pages, ordering, stock-adjustment
success and rollback, cashier denial, invalid CSRF, row locking, movement
records, audit records, and authorization behavior.

## Preserved boundaries

- `public/stock_movements.php` still authenticates before processing.
- CSRF validation remains before administrator authorization for mutations.
- `SELECT stock ... FOR UPDATE` is unchanged.
- Transaction begin, commit, rollback, stock quantities, movement records, and
  audit records are unchanged.
- No product, order, dashboard, navbar, database, UI, or deployment behavior
  was changed.
