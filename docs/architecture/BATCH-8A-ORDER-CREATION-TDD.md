# Batch 8A order-creation service TDD evidence

This document records the extraction of the transactional order-creation
implementation from `includes/functions.php` into `includes/orders.php`.
`public/orders.php` remains on the compatibility wrapper in this batch.

## Caller inventory before extraction

Repository-wide search found these executable callers before the extraction:

| Caller | Location | Contract exercised |
|---|---|---|
| POS page | `public/orders.php:98` | Page request validation delegates to `create_order()` and consumes the created order ID |
| Validation unit tests | `tests/Unit/validation_test.php:37,41,45,49` | Invalid/empty order inputs and duplicate quantity overflow |
| Database integration tests | `tests/Integration/database_test.php` | Sale, purchase, invalid type, authorization, price authority, rollback, and page-boundary behavior |
| Backup/restore integration | `tests/Integration/backup_restore_test.php:159` | Disposable sale fixture creation |

No executable CLI caller was found under `scripts/`; other repository matches
were documentation or source-contract references.

## Compatibility decision: return value

The current repository contract returns the created order ID on success and
`false` on failure. `public/orders.php` uses that ID in its success message and
completed-order state, and existing integration tests assert an integer ID.
Therefore `orders_create()` intentionally uses `int|false`, and the legacy
`create_order()` wrapper preserves the same order-ID-or-false behavior. A
literal `bool` return would be behavior-breaking and was not introduced.

## RED checkpoint

- Commit: `dbcb12f` (`test(orders): characterize order creation service boundary`)
- Command:

  `docker compose run --rm --no-deps app php -r "require 'tests/Unit/order_write_test.php'; echo run_order_write_unit_tests(), PHP_EOL;"`

- Result: expected exit code 1 because `includes/orders.php` did not yet exist.

The source contract covered explicit dependencies, no session/global access,
transaction and lock SQL, server-side pricing, sale/purchase writes, direct
inventory movement calls, audit/rollback behavior, wrapper delegation, and the
unchanged `public/orders.php` caller.

## GREEN checkpoint

- Commit: `4b062b7` (`refactor(orders): extract order creation service`)
- Additional test commit: `098357d` (`test(orders): cover duplicate item aggregation`)
- Additional test commit: `29a331d` (`test(orders): cover transactional failure rollback`)
- Focused order contract: PASS, 42 assertions.
- Focused product contract: PASS, 108 assertions.
- Focused inventory read contract: PASS, 60 assertions.
- Focused inventory adjustment contract: PASS, 40 assertions.
- Full regression after extraction: PASS, 1468 assertions (872 unit, 596 integration).

The integration suite directly exercises sale and purchase service paths while
the existing orders-page tests continue to exercise the compatibility wrapper.
It also verifies duplicate-item aggregation, client-price tampering resistance,
cashier sale/purchase policy, customer/supplier validation, exact totals and
stock changes, movement reasons, audit behavior, insufficient-stock rollback,
closed-connection failure, movement-insertion rollback, audit-insertion
rollback, and the absence of partial Order, OrderDetail, Product,
StockMovement, or AuditLog records after those failures.

## Preserved boundaries

`includes/orders.php` owns the database transaction and accepts the staff ID
explicitly. It requires only `inventory.php` and `audit.php`, calls
`inventory_log_stock_movement()` directly, and uses `inventory_rollback_error()`
for safe rollback diagnostics. `public/orders.php` still owns request parsing,
CSRF validation, page-level purchase authorization, generic messages, session
flood-protection state, POS markup, and JavaScript.

No schema, migration, authorization policy, CSRF behavior, or UI behavior was
changed.
