# Phase 3B — Dashboard statistics extraction TDD evidence

Status: complete

## Scope and characterization

The pre-change caller inventory found one production caller:

- `public/index.php:24` called `get_dashboard_stats($conn, $dashboard_staff_id)`.

No additional production, CLI, test, or script caller was found. The original
implementation was a five-query read model in `includes/functions.php` with
fixed defaults, global Product and stock totals, global Order/sale totals when
the scope was null, and prepared staff-scoped Order/sale totals otherwise.

## RED checkpoint

The source contract and disposable integration contract were added before the
module implementation.

| Commit | Command | Evidence |
|---|---|---|
| `f9ffaa574a2bd23ff027f3e570da7e18cae6ad5e` | `docker compose run --rm --no-deps app php -r "require 'tests/Unit/dashboard_test.php'; echo run_dashboard_unit_tests(), PHP_EOL;"` | Expected failure: `Dashboard source fixture could not be read.` because `includes/dashboard.php` did not yet exist. |

The RED checkpoint added only `tests/Unit/dashboard_test.php`,
`tests/Integration/dashboard_test.php`, and the test-runner wiring.

## GREEN checkpoint

The exact dashboard implementation moved to `dashboard_get_stats()` in
`includes/dashboard.php`. `get_dashboard_stats()` became a one-delegation
compatibility wrapper, and `public/index.php` now calls the focused function
directly. The module has no dependency on `functions.php`, session state, or
global state.

| Commit | Check | Result |
|---|---|---|
| `760f0e4d9394f90bdb58fd3aa8c4b57c36ba3932` | Focused source contract | PASS — 33 assertions |
| `760f0e4d9394f90bdb58fd3aa8c4b57c36ba3932` | Focused disposable integration contract | PASS — 18 assertions |
| `760f0e4d9394f90bdb58fd3aa8c4b57c36ba3932` | Full disposable regression after the extraction | PASS — 1,591 assertions (977 unit, 614 integration) |

The integration fixture verifies admin/global totals, cashier-scoped order and
sale totals, purchase exclusion, fixed key order, numeric types, compatibility
wrapper equivalence, and zero defaults for a closed database connection. It
uses a disposable database and leaves no fixture rows in the development
database.

## Verification evidence

| Check | Result |
|---|---|
| PHP syntax | PASS — all 67 tracked PHP files passed the Docker `php -l` sweep |
| JavaScript syntax | PASS — all 4 tracked JavaScript files passed `node --check` |
| Browser QA | PASS — 18/18 tests at 375px, 768px, and 1440px; responsive overflow, keyboard/accessibility reporting, console/network, authentication, authorization, and dashboard journeys remained green |
| Repository security scan | PASS — Git-backed scan inspected tracked files with zero findings; Git was installed only in a disposable verification container, not the application image |
| CI supply-chain policy | PASS — zero findings |
| Release-integrity check | PASS — safe metadata manifest generated with the current commit, schema migration version, migration list, immutable verification-only image metadata, and `verified` status |
| `git diff --check` | PASS |

No committed visual baselines were added. Visual regression therefore remains
INCONCLUSIVE under the repository’s existing Browser QA policy; this batch does
not claim a baseline comparison or full manual WCAG conformance.

## Compatibility contract

The four returned keys, their numeric types, null-versus-staff scope behavior,
sale-only revenue rule, zero defaults, error logging, and closed-connection
fallback remain unchanged. Other dashboard/report functions remain in the
facade and were not extracted in this batch.
