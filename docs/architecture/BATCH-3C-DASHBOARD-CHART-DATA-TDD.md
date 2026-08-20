# Phase 3C — Dashboard chart-data extraction TDD evidence

Status: complete

## Scope and characterization

The pre-change caller inventory found one production caller:

- `public/index.php:25` called `get_chart_data($conn, 7, $dashboard_staff_id)`.

No additional production, CLI, test, or script caller was found. The original
implementation normalized days to 1–31, built a complete chronological date
series, aggregated sales and purchases with prepared bindings, optionally
scoped by staff, and returned zero-filled points when a query failed.

## RED checkpoint

The source and disposable integration contracts were added before the focused
chart service existed.

| Commit | Command | Evidence |
|---|---|---|
| `aa442528d86e46e9445cad232e3926153c40064b` | `docker compose run --rm --no-deps app php -r "require 'tests/Unit/dashboard_test.php'; echo run_dashboard_unit_tests(), PHP_EOL;"` | Expected failure: `Dashboard module must expose the explicit chart-data service.` |

The RED checkpoint added only the chart source assertions and disposable
database fixture assertions to the existing dashboard test files.

## GREEN checkpoint

The exact chart implementation moved to `dashboard_get_chart_data()` in
`includes/dashboard.php`. `get_chart_data()` became a one-delegation
compatibility wrapper, and `public/index.php` now calls the focused function
directly. The module has no dependency on `functions.php`, session state, or
global state.

| Commit | Check | Result |
|---|---|---|
| `00e36679857abdcb83ccd7f678004220e742a612` | Focused source contract | PASS — 57 assertions |
| `00e36679857abdcb83ccd7f678004220e742a612` | Focused disposable integration contract | PASS — 47 assertions |
| `00e36679857abdcb83ccd7f678004220e742a612` | Full disposable regression | PASS — 1,644 assertions (1,001 unit, 643 integration) |

The integration fixture verifies global and cashier-scoped chart data, separate
sales/purchase totals, chronological labels, zero-filled days, minimum and
maximum day normalization, compatibility-wrapper equivalence, and the
closed-connection fallback shape. It uses only a disposable database.

## Verification evidence

| Check | Result |
|---|---|
| PHP syntax | PASS — all 67 tracked PHP files passed the Docker `php -l` sweep |
| JavaScript syntax | PASS — all 4 tracked JavaScript files passed `node --check` |
| Browser QA | PASS — 18/18 tests at 375px, 768px, and 1440px; dashboard journeys, responsive overflow, keyboard/accessibility reporting, console/network, authentication, and authorization remained green |
| Repository security scan | PASS — tracked-file scan completed with zero findings in a disposable Git-enabled verification container |
| CI supply-chain policy | PASS — zero findings |
| `git diff --check` | PASS |

No visual baselines were added. Visual regression therefore remains
INCONCLUSIVE under the repository’s existing Browser QA policy, and automated
accessibility checks are not a substitute for a complete manual WCAG audit.

## Compatibility contract

The chart point shape, date labels and ordering, 1–31 day normalization,
sales/purchases separation, optional staff scope, prepared bindings, failure
logging, statement cleanup, zero-filled fallback, and closed-connection
behavior remain unchanged. Other dashboard/reporting functions remain in the
facade and were intentionally not extracted in this batch.
