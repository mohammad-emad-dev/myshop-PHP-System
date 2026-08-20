# Batch 7G product caller migration TDD evidence

This document records the direct caller migration for `public/products.php`.
The product services and compatibility wrappers were already characterized by
Batches 7D through 7F; this batch changes only the page's mutation dispatch.

## Call-site inventory before migration

The complete tracked-source inventory found these product-page callers in
`public/products.php`:

| Page branch | Legacy call | Preserved focused-service call and argument order |
|---|---|---|
| `action=create` | `create_product($conn, $_SESSION['staff_id'], $name, $description, $price, $stock, $image_path, $alert_threshold, $category_id, $barcode)` | `products_create($conn, $_SESSION['staff_id'], $name, $description, $price, $stock, $image_path, $alert_threshold, $category_id, $barcode)` |
| `action=update` | `update_product($conn, $_SESSION['staff_id'], $id, $name, $description, $price, $stock, $image_path, $alert_threshold, $category_id, $barcode)` | `products_update($conn, $_SESSION['staff_id'], $id, $name, $description, $price, $stock, $image_path, $alert_threshold, $category_id, $barcode)` |
| `action=delete` | `delete_product($conn, $id, $_SESSION['staff_id'])` | `products_delete($conn, $id, $_SESSION['staff_id'])` |

The page still requires `includes/functions.php` for authentication, catalog,
upload, audit, and compatibility helpers. The legacy mutation functions remain
available for integration tests, CLI utilities, and other future callers.

## TDD evidence

### RED checkpoint

- Commit: `a15f01f` (`test(products): characterize direct page service callers`)
- Command:

  `docker compose run --rm --no-deps app php -r "require 'tests/Unit/product_write_test.php'; echo run_product_write_unit_tests(), PHP_EOL;"`

- Result: expected failure, exit code 1, because the page still called
  `create_product()` instead of `products_create()`.

The source contract added coverage for the three exact service signatures and
actor IDs, absence of legacy mutation calls, and the ordering of CSRF and
administrator authorization before mutation dispatch.

### GREEN checkpoint

- Implementation commit: `7b4adf1` (`refactor(products): migrate page to focused services`)
- Contract synchronization commit: `f86f074` (`test(products): update direct caller contract`)
- Focused command: same command as the RED checkpoint.
- Result: `108` assertions, exit code 0.

The second test commit updates the existing architecture baseline contract from
the legacy page call to all three focused product services. The compatibility
wrapper contracts remain covered by the focused product tests and disposable
integration suite.

## Verification evidence

| Guarantee | Command | Result |
|---|---|---|
| Direct product service callers and source ordering | focused product unit command above | PASS: 108 assertions |
| Full application and disposable integration behavior | `$testRootPassword = ((Get-Content .env | Where-Object { $_ -match '^MYSQL_ROOT_PASSWORD=' }) -replace '^MYSQL_ROOT_PASSWORD=', ''); docker compose --env-file .env exec -T -e TEST_DB_HOST=db -e TEST_DB_PORT=3306 -e TEST_DB_ROOT_USER=root -e TEST_DB_ROOT_PASSWORD=$testRootPassword app php tests/run.php` | PASS: 1413 assertions (830 unit, 583 integration) |
| Browser behavior and responsive/accessibility regression | `powershell -NoProfile -File scripts/run-browser-qa.ps1` | PASS: 18 tests across 375px, 768px, and 1440px |

The browser runner used its disposable environment and cleaned its containers,
volume, network, and temporary runtime data. It reported no unexpected
application failures and retained the repository's existing automated
accessibility limitations; automated checks are not a complete manual WCAG
audit. No committed visual baselines exist, so visual-baseline comparison is
not claimed.

## Preserved contract

`public/products.php` still owns request method/action handling, input
sanitization and validation, CSRF rejection and HTTP 403 behavior,
administrator authorization, upload validation and cleanup, generic feedback,
redirect/rendering state, and all existing HTML/CSS/JavaScript. Only the
database-service function names at the three dispatch points changed.
