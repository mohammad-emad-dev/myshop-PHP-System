# Batch 6A authentication extraction evidence

## Scope

This batch moves active-session Staff revalidation, the administrator role
check, the administrator denial path, and terminating redirects into
`includes/auth.php` and `includes/http.php`. Existing pages continue to call
`verify_login()`, `is_admin()`, `require_admin()`, and `redirect()` through thin
compatibility wrappers. Login credential verification, rate limiting, CSRF,
successful-login session regeneration, logout request handling, and all
business mutations remain in their existing owners.

## RED and GREEN evidence

| Stage | Command | Result | Guarantee |
|---|---|---|---|
| RED | `docker compose run --rm --no-deps app php -r "require 'tests/Unit/auth_extraction_test.php'; echo run_auth_extraction_unit_tests(), PHP_EOL;"` | Failed with `Focused authentication module is missing.` | The new characterization test executed and failed for the intended missing module. |
| GREEN | Same focused command | Passed with `75` assertions. | Module independence, explicit dependencies, thin wrappers, role validation, failure invalidation, idle timeout, session/global fields, redirects, 403 denial, audit call, CSRF, regeneration, and logout source contracts are protected. |
| Regression | `docker compose exec -T -e TEST_DB_HOST=db -e TEST_DB_PORT=3306 -e TEST_DB_ROOT_USER=root -e TEST_DB_ROOT_PASSWORD=<local disposable-test value> app php tests/run.php` | Passed with `827 assertions (461 unit, 366 integration)`. | Real disposable-MySQL active admin/cashier lookup, disabled/missing Staff rejection, legacy wrapper/global compatibility, and all existing business/security tests passed. |
| Browser QA | `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/run-browser-qa.ps1` | Passed with `18` tests. | Disposable login/logout (including session-ID regeneration/invalidation), protected redirects, admin access, cashier server-side denial, POS lookup, responsive, keyboard, axe, console/network, and overflow checks passed at all three configured widths. |

The documented `pwsh` executable was not installed on this host. The existing
unchanged runner was executed with compatible Windows PowerShell 5.1 instead.
It created random disposable credentials and removed its containers, images,
network, volume, environment files, and browser output. Credential values were
not printed or committed.

## Coverage limitation

The existing PHP harness is assertion-based and has no configured coverage
reporter. No dependency was added solely to produce a coverage percentage. The
focused source/runtime tests, disposable database suite, and disposable browser
suite are the available behavior-preservation evidence.
