# Phase 3D: Inventory valuation extraction TDD evidence

This document records the focused extraction of the inventory valuation read
from `includes/functions.php` into `includes/dashboard.php`. It is a current
state evidence record; historical Phase 3A, Batch 3B, and Batch 3C documents
remain unchanged.

## Scope and preserved boundary

- `dashboard_get_inventory_valuation($conn)` owns the existing
  `SUM(stock * price)` Product query and returns a float.
- `get_inventory_valuation($conn)` remains available as a delegation-only
  compatibility wrapper.
- `public/index.php` calls the focused service directly.
- Authentication, authorization, session, CSRF, rendering, UI, schema, and all
  other dashboard/report functions remain unchanged.

## Verified caller inventory

Before the extraction, repository search found one verified production caller:
`public/index.php:26`, calling `get_inventory_valuation($conn)`. No dynamic
production caller was identified in `public/`, `includes/`, `tests/`, or
`scripts/`; tests intentionally retain the compatibility-wrapper coverage.

## RED checkpoint

Commit: `8b13221` — `test(dashboard): characterize inventory valuation extraction`

Tests were added before production implementation and the current-state source
contracts were updated to require the focused service and direct page caller.

Commands and observed failures:

```text
docker compose run --rm --no-deps app php -r "require 'tests/Unit/dashboard_test.php'; echo 'DASHBOARD_UNIT_ASSERTIONS=' . run_dashboard_unit_tests() . PHP_EOL;"
FAIL: Dashboard module must expose the explicit inventory valuation service.

docker compose exec -T ... app php -r "require 'tests/Integration/dashboard_test.php'; ..."
FAIL: Call to undefined function dashboard_get_inventory_valuation()
```

The failures were caused by the intentionally missing focused implementation,
not by test setup or an unrelated regression.

## GREEN checkpoint

Commit: `4eb7b20` — `refactor(dashboard): extract inventory valuation service`

The implementation moved the unchanged SQL and failure contract into
`includes/dashboard.php`, changed the legacy function to a single delegation,
and changed only the dashboard page caller.

Focused verification:

```text
docker compose run --rm --no-deps app php -r "require 'tests/Unit/dashboard_test.php'; ..."
DASHBOARD_UNIT_ASSERTIONS=72

docker compose exec -T ... app php -r "require 'tests/Integration/dashboard_test.php'; ..."
DASHBOARD_INTEGRATION_ASSERTIONS=54
```

The integration run verified a `1000.0` valuation, float typing, zero valuation
after setting the disposable product stock to zero, compatibility-wrapper
equivalence, and `0.0` fallback for a closed connection. The disposable
database was cleaned up by the existing integration harness.

## Test specification

| Guarantee | Evidence | Result |
|---|---|---|
| Focused service exposes the explicit connection-only contract and existing SQL | `tests/Unit/dashboard_test.php` | PASS, included in 72 source assertions |
| Legacy wrapper delegates once and contains no SQL | `tests/Unit/dashboard_test.php` | PASS, included in 72 source assertions |
| Dashboard page calls the focused service and not the legacy name | `tests/Unit/dashboard_test.php`, `tests/Unit/inventory_read_test.php` | PASS |
| Product stock multiplied by price returns the expected float | `tests/Integration/dashboard_test.php` | PASS |
| Empty/zero valuation and closed-connection fallback remain `0.0` | `tests/Integration/dashboard_test.php` | PASS |
| Existing dashboard stats/chart behavior remains covered | `tests/Integration/dashboard_test.php` | PASS |

## Known gaps and operational notes

The dashboard page changed only its PHP data-service call. Browser QA is still
run as a final verification because `public/index.php` changed, but no visual
baseline is introduced by this batch. Visual regression remains INCONCLUSIVE
without committed baselines, and automated accessibility checks do not replace
a manual WCAG audit.

The module preserves the existing `error_log()` diagnostics. Database errors are
not returned to callers or rendered by the dashboard.

## Final evidence

Final verification from the Phase 3D working tree:

| Check | Command/result |
|---|---|
| Full disposable regression | `docker compose exec -T ... app php tests/run.php` — PASS: **1666 assertions** (1016 unit, 650 integration) |
| PHP syntax | `docker compose run --rm --no-deps app sh -lc "find config database includes public scripts tests -type f -name '*.php' -print0 \| xargs -0 -n1 php -l"` — PASS, 67 PHP files |
| JavaScript syntax | `node --check` for every tracked `*.js` — PASS, 4 files |
| Repository security and supply-chain policy | Git-enabled disposable app CLI — PASS, zero findings |
| Browser QA | `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/run-browser-qa.ps1` — **18/18 passed** at mobile 375px, tablet 768px, and desktop 1440px |
| Diff whitespace | `git diff --check` — PASS |

The Browser QA runner removed its disposable containers, images, volume, and
network. No visual baseline was added; visual regression remains INCONCLUSIVE
without committed baselines. Automated accessibility checks passed as reported
by the suite, but they are not a complete manual WCAG audit.
