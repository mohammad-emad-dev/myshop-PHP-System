# Batch 6B read-only authentication caller migration evidence

## Scope

This batch changes only the authentication call sites in the dashboard and
read-only/reporting endpoints listed in the Batch 6B contract. Each migrated
page continues to load `includes/functions.php`, which loads the extracted
authentication module, but now passes its page-local `$conn` directly to
`auth_verify_login()`, `auth_is_admin()`, or `auth_require_admin()`.

The compatibility wrappers remain unchanged and available to `login.php`, the
mutation pages, `backup_database.php`, and shared layouts. SQL, staff scoping,
response handling, redirects, session fields, audit behavior, and rendered
markup are unchanged.

## RED and GREEN evidence

| Stage | Command | Result | Guarantee |
|---|---|---|---|
| Baseline | `docker compose run --rm --no-deps app php -r "require 'tests/Unit/auth_extraction_test.php'; echo run_auth_extraction_unit_tests(), PHP_EOL;"` | Passed with `88` assertions. | The approved Batch 6A authentication boundary was green before this migration. |
| RED | Same focused command after adding the Batch 6B caller contract | Failed because `public/index.php` contained zero `auth_verify_login($conn)` calls. | The new contract executed and rejected the legacy caller state for the intended reason. |
| GREEN | Same focused command after migrating the seven callers | Passed with `149` assertions. | Every approved caller uses the explicit module with the expected argument and call count; no approved caller retains a legacy auth wrapper; excluded callers still use the compatibility facade. |
| Regression | `docker compose exec -T -e TEST_DB_HOST=db -e TEST_DB_PORT=3306 -e TEST_DB_ROOT_USER=root -e TEST_DB_ROOT_PASSWORD=<local disposable-test value> app php tests/run.php` | Passed with `901 assertions (535 unit, 366 integration)` in `8.22s`. | Active admin/cashier authentication, audit role checks, cashier order/history scope, cross-staff order/detail denial, owner detail visibility, export streaming, login/logout contracts, and existing business/security behavior remained green. |
| Browser QA | `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/run-browser-qa.ps1` | Passed with `18` tests in `46.0s`. | Disposable admin/cashier login, logout, dashboard, audit denial/access, order-history restrictions, export access controls, POS lookup, responsive, keyboard, axe, console/network, and overflow checks passed at 375px, 768px, and 1440px. |
| PHP lint | `docker compose run --rm --no-deps app sh -lc "find config database includes public scripts tests -type f -name '*.php' -print0 \| xargs -0 -n1 php -l"` | Passed. | All PHP sources parsed after the caller migration. |
| JavaScript syntax | The Quality Gate `node --check` loop over tracked `*.js` files | Passed for `4` files. | No JavaScript syntax regression was introduced. |

## Coverage limitation

The existing Browser QA fixture does not create an order, so it does not
directly navigate the invoice endpoint or open an order-detail modal. Those
endpoint authorization paths retain their existing staff-scope code unchanged;
the disposable MySQL suite covers cross-staff order/detail denial and owner
visibility, while the focused source contract proves that only the auth call
changed. The PHP harness has no configured line-coverage reporter, and no
dependency was added solely to produce a percentage.
