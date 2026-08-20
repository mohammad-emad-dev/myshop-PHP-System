# Batch 6C CRUD authentication caller migration evidence

## Scope

This batch changes only authentication and authorization calls in
`products.php`, `categories.php`, `customers.php`, and `suppliers.php`. Each
page continues to load the compatibility facade but now passes its page-local
`$conn` directly to the extracted authentication module.

The compatibility wrappers remain unchanged for login, stock movements,
orders, settings, backup handling, and shared layouts. CRUD dispatch, CSRF
ordering, denied-mutation audit events, product upload handling, SQL,
transactions, forms, responses, and rendered markup are unchanged.

## RED and GREEN evidence

| Stage | Command | Result | Guarantee |
|---|---|---|---|
| Baseline | `docker compose run --rm --no-deps app php -r "require 'tests/Unit/auth_extraction_test.php'; echo run_auth_extraction_unit_tests(), PHP_EOL;"` | Passed with `149` assertions. | The approved Batch 6B authentication boundary was green before this migration. |
| RED | Same focused command after adding the Batch 6C caller contract | Failed because `public/products.php` retained its legacy auth sequence. | The new sequence contract executed and rejected the intended pre-migration state. |
| GREEN | Same focused command after migrating the four callers | Passed with `174` assertions. | All four pages use the exact explicit auth call count and source order; excluded callers remain on wrappers; CSRF, denial-audit, and upload landmarks remain present. |
| Regression | `docker compose exec -T -e TEST_DB_HOST=db -e TEST_DB_PORT=3306 -e TEST_DB_ROOT_USER=root -e TEST_DB_ROOT_PASSWORD=<local disposable-test value> app php tests/run.php` | Passed with `926 assertions (560 unit, 366 integration)` in `7.85s`. | Product/category/customer/supplier CRUD, CSRF token rejection, audit behavior, product history and audit rollback, uploads boundary, authentication, authorization, and all existing business/security contracts remained green. |
| Browser QA | `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/run-browser-qa.ps1` | Passed with `18` tests in `49.6s`. | Disposable product search/pagination and products/categories/customers/suppliers page loading passed with admin/cashier authorization, responsive, keyboard, axe, console/network, and overflow checks at 375px, 768px, and 1440px. |
| PHP lint | `docker compose run --rm --no-deps app sh -lc "find config database includes public scripts tests -type f -name '*.php' -print0 \| xargs -0 -n1 php -l"` | Passed. | All PHP sources parsed after migration. |
| JavaScript syntax | The Quality Gate `node --check` loop over tracked `*.js` files | Passed for `4` files. | No JavaScript syntax regression was introduced. |

## Coverage limitations

The assertion-based PHP harness has no configured line-coverage reporter. The
Browser QA suite exercises these CRUD surfaces read-only in a disposable
environment; cashier mutation rejection is protected by source-order contracts
and the existing authorization tests rather than destructive browser actions.

The Batch 6B limitation remains unchanged: Browser QA does not create a valid
invoice/order-detail fixture, so those valid rendering paths are not claimed as
browser-covered.
