# Batch 6E orders authentication caller migration evidence

## Scope

This batch changes only the authentication and authorization calls in
`public/orders.php`. The page now passes its existing `$conn` directly to the
extracted authentication module. The existing `$is_admin_user` assignment,
call order, CSRF handling, order validation, pricing authority, transaction
boundaries, stock locking, audit events, forms, responses, and rendered markup
are unchanged.

The compatibility wrappers remain available for the still-unmigrated callers:
login, settings, backup handling, and shared layouts.

## RED and GREEN evidence

| Stage | Command | Result | Guarantee |
|---|---|---|---|
| RED | `docker compose run --rm --no-deps app php -r "require 'tests/Unit/auth_extraction_test.php'; echo run_auth_extraction_unit_tests(), PHP_EOL;"` after adding the Batch 6E contract | Failed because `public/orders.php` still exposed the legacy authentication sequence. | The exact orders caller contract executed before the production change. |
| GREEN | Same focused command after migration | Passed with `194` assertions. | The page has one explicit login call and two explicit administrator checks in the original source order; the legacy calls are absent; the admin-state assignment and CSRF-before-purchase-authorization order remain protected. |
| Regression | `docker compose run --rm --no-deps -e TEST_DB_HOST=db -e TEST_DB_PORT=3306 -e TEST_DB_ROOT_USER=root -e TEST_DB_ROOT_PASSWORD=<local disposable value> app php tests/run.php` | Passed with `1004 assertions (580 unit, 424 integration)` in `8.80s`. | Existing auth, role denial, CSRF, order, stock, pricing, audit, scoping, and transaction contracts remained green. |
| Browser QA | `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/run-browser-qa.ps1` | Passed with `18` tests in `47.0s`. | Disposable admin/cashier journeys, POS lookup, order/history surfaces, responsive checks, keyboard checks, accessibility checks, console/network checks, and overflow checks passed at 375px, 768px, and 1440px. |

## Page-level order boundary coverage

The disposable PHP integration test now submits the actual `public/orders.php`
POST boundary with temporary admin and cashier sessions. It verifies:

- admin sale and cashier sale success;
- admin purchase success and cashier purchase HTTP 403 denial;
- invalid order CSRF HTTP 403 denial before business processing;
- client-submitted prices do not override database prices;
- order staff/type/customer/supplier associations;
- stock quantity and stock movement records;
- insufficient-stock page rejection; and
- direct `create_order()` rollback with no partial order, stock, or movement.

No production or normal local database was used. The browser suite remains
non-destructive; the PHP integration suite covers the destructive POST boundary
against a disposable database.

## Additional verification

- All PHP sources passed the Docker `php -l` sweep.
- All `4` tracked JavaScript files passed `node --check`.
- `git diff --check` passed.

## Compatibility and rollback

Legacy authentication wrappers remain operational for all remaining callers.
The migration can be reverted locally with `git revert <batch-6e-commit>`;
that restores the two legacy orders-page calls without changing database data.
