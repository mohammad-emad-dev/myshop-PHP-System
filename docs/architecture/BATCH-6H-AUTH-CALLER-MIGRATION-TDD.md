# Batch 6H: Login Authentication Caller Migration

This batch moved only the authenticated-user redirect check in
`public/login.php` to the explicit authentication module. The login credential
query, rate limiting, CSRF handling, session lifecycle, audit behavior,
redirects, and rendered markup remain owned by their existing code.

## Original call-site inventory

Before the change, the page had exactly one authentication caller:

- `public/login.php:24` — `verify_login(false)` inside
  `if (!$is_logout_request && isset($_SESSION['staff_id']) && ...)`.

The logout POST branch runs first. The login POST/CSRF branch runs after the
authenticated-user redirect guard. The migration changed only the caller to:

`auth_verify_login($conn, false)`

The `redirect()` compatibility wrapper remains operational and was not moved.

## RED/GREEN evidence

| Stage | Command | Result | Guarantee |
|---|---|---|---|
| RED | `docker compose run --rm --no-deps app php -r "require 'tests/Unit/auth_extraction_test.php'; echo run_auth_extraction_unit_tests(), PHP_EOL;"` | Failed with `Login authentication call count or execution position changed.` | The new contract executed against the pre-migration page and rejected its legacy caller. |
| GREEN | Same focused command after migration | Passed with `235` assertions. | The page has exactly one explicit auth call in the original logout → authenticated redirect → login POST order; the legacy wrapper is absent; session, CSRF, rate-limit, redirect, logout, and audit landmarks remain present. |
| Regression | `docker compose --env-file .env exec -T -e TEST_DB_HOST=db -e TEST_DB_PORT=3306 -e TEST_DB_ROOT_USER=root -e TEST_DB_ROOT_PASSWORD=<local disposable value> app php tests/run.php` | Passed with `1099 assertions (621 unit, 478 integration)` in `10.15s`. | Real disposable-MySQL authentication, invalid/stale session invalidation, rate-limit behavior/reset, login CSRF rejection, audit behavior, database-failure contracts, and prior application behavior remained green. |
| Browser QA | `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/run-browser-qa.ps1` | Passed `18/18` in `46.1s`. | Anonymous login, invalid credentials, protected redirect, successful login, session-ID regeneration, logout invalidation, responsive, keyboard, accessibility, console/network, and overflow checks passed at 375px, 768px, and 1440px. |

The browser runner used disposable admin/cashier credentials and removed its
containers, volume, network, images, and temporary results. No credentials,
cookies, CSRF tokens, screenshots, or database contents were committed.

## Preserved contracts

- Anonymous users still receive the login page.
- Active authenticated users still redirect to `index.php`.
- Logout POST handling remains isolated from login processing.
- Invalid logout and login CSRF remain HTTP 403.
- Successful login still regenerates the session ID, refreshes CSRF, resets
  rate-limit state, writes the same audit event, and redirects to `index.php`.
- Invalid credentials, rate-limit blocks, and database failures retain their
  existing generic response behavior.
- No SQL, password verification, business logic, schema, UI, or deployment
  behavior changed.

## Additional verification

- PHP lint passed for all tracked PHP files.
- JavaScript syntax passed for 4 tracked JavaScript files.
- Development and production Compose configuration validation passed.
- Production preflight passed with generated temporary values.
- Disposable schema/migration validation passed.
- Git-backed repository security scan: zero findings.
- CI supply-chain policy scan: zero findings.
- Release-integrity metadata check: passed.
- `git diff --check`: passed.
