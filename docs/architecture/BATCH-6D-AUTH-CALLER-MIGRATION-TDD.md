# Batch 6D stock-movements authentication caller migration evidence

## Scope

This batch changes only authentication and authorization calls in
`public/stock_movements.php`. The page now passes its existing `$conn`
directly to the extracted authentication module. Stock SQL, transaction
boundaries, row locking, CSRF ordering, audit events, movement logging,
validation, forms, responses, and rendered markup are unchanged.

The compatibility wrappers remain available for the still-unmigrated callers:
login, orders, settings, backup handling, and shared layouts.

## RED and GREEN evidence

| Stage | Command | Result | Guarantee |
|---|---|---|---|
| RED | `docker compose run --rm --no-deps app php -r "require 'tests/Unit/auth_extraction_test.php'; echo run_auth_extraction_unit_tests(), PHP_EOL;"` after adding the Batch 6D contract | Failed because the page still exposed the legacy stock authentication sequence. | The exact stock caller contract executed before production changes. |
| GREEN | Same focused command after migration | Passed with `184` assertions. | The page has exactly one explicit login call and three explicit admin checks in the original source order; legacy auth calls are absent; CSRF precedes authorization; stock locking, transactions, movement logging, and audit landmarks remain present. |
| Regression | `docker compose exec -T -e TEST_DB_HOST=db -e TEST_DB_PORT=3306 -e TEST_DB_ROOT_USER=root -e TEST_DB_ROOT_PASSWORD=<local disposable value> app php tests/run.php` | Passed with `953 assertions (570 unit, 383 integration)` in `8.78s`. | Existing authentication, role denial, CSRF, stock/order integrity, transaction rollback, row-locking paths, movement history, audit behavior, the disposable stock page POST boundary, and all prior business/security contracts remained green. |
| Browser QA | `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/run-browser-qa.ps1` | Passed with `18` tests in `52.0s`. | Disposable admin/cashier journeys, stock-ledger surface loading, responsive checks, keyboard checks, accessibility checks, console/network checks, and overflow checks passed at 375px, 768px, and 1440px. |

## Additional verification

- All PHP sources passed the Docker `php -l` sweep.
- All `4` tracked JavaScript files passed `node --check`.
- Development and production Compose configuration passed `config --quiet`.
- Production preflight passed with temporary immutable image references.
- Canonical schema and migration validation passed against a disposable database.
- The tracked-file repository security scan inspected `105` files with zero findings.
- `git diff --check` passed.

## Coverage limitations

The existing Browser QA suite does not submit a destructive stock adjustment.
The disposable PHP integration probe covers that page boundary with temporary
admin and cashier sessions, while the focused source contract protects the
CSRF-before-authorization order, row lock, transaction, movement, and audit
landmarks. No production or normal local database was used.
