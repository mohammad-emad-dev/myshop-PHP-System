# Phase 3E: Top-selling products extraction TDD evidence

This document records the focused extraction of the top-selling product read
from `includes/functions.php` into `includes/dashboard.php`. It is a current
state evidence record; historical Phase 3A, Batch 3B, Batch 3C, and Batch 7A
documents remain unchanged.

## Scope and preserved boundary

- `dashboard_get_top_selling_products($conn, $limit = 5, $staff_id = null)`
  owns the existing sale-only Product/OrderDetail aggregate.
- Limit normalization remains 1–50, and grouping/order remain quantity
  descending.
- `get_top_selling_products()` remains available as a delegation-only
  compatibility wrapper.
- `public/index.php` calls the focused service directly.
- Authentication, authorization, session, CSRF, rendering, UI, schema, and the
  category-sales and low-stock reads remain unchanged.

## Verified caller inventory and failure characterization

Before extraction, repository search found one verified production caller:
`public/index.php:27`, calling `get_top_selling_products($conn, 5,
$dashboard_staff_id)`. No dynamic production caller was identified in
`public/`, `includes/`, `tests/`, or `scripts/`; tests retain compatibility
wrapper coverage.

The existing implementation returned `[]` after explicit prepare, bind,
execute, or result failures, logging the existing server-side diagnostic. It
did not catch exceptions from `prepare()`. A disposable closed-connection
characterization run produced:

```text
CLOSED_BEHAVIOR=THROW:Error
```

The extracted service preserves this strict-mysqli closed-connection behavior;
it does not silently convert it to an empty result.

## RED checkpoint

Commit: `ea799cc8a32b8d619e50b427408884f6d49d6bed` —
`test(dashboard): characterize top-selling extraction`

Commands and observed failures:

```text
docker compose run --rm --no-deps app php -r "require 'tests/Unit/dashboard_test.php'; echo 'DASHBOARD_UNIT_ASSERTIONS=' . run_dashboard_unit_tests() . PHP_EOL;"
FAIL: Dashboard module must expose the explicit top-selling products service.

docker compose exec -T ... app php -r "require 'tests/Integration/dashboard_test.php'; ..."
FAIL: Call to undefined function dashboard_get_top_selling_products()
```

The failures were caused by the intentionally missing focused implementation,
not by test setup or an unrelated regression.

## GREEN checkpoint

Commit: `459c6b43090daeee9bb2c70c3f6ec879b7d219da` —
`refactor(dashboard): extract top-selling products service`

Follow-up formatting commit: `23ee3a70abe898d4e8247b794031ecafcc15fb52` —
`chore(dashboard): normalize top-selling query formatting`.

The implementation moved the existing SQL and statement/error branches into
`includes/dashboard.php`, changed the legacy function to a single delegation,
and changed only the dashboard page caller. Focused verification passed:

```text
DASHBOARD_UNIT_ASSERTIONS=101
DASHBOARD_INTEGRATION_ASSERTIONS=74
```

The disposable integration run verified global and cashier-scoped results,
sale-only aggregation, quantity-descending ordering, default and boundary
limits, empty staff scope, wrapper equivalence, and the closed-connection
failure contract. Disposable fixtures were cleaned up by the existing harness.

## Test specification

| Guarantee | Evidence | Result |
|---|---|---|
| Focused service exposes the explicit contract and existing SQL | `tests/Unit/dashboard_test.php` | PASS, included in 101 source assertions |
| Wrapper delegates once without duplicated SQL | `tests/Unit/dashboard_test.php` | PASS |
| Dashboard page calls the focused service and not the legacy name | `tests/Unit/dashboard_test.php` | PASS |
| Global and cashier-scoped top-selling results are correct | `tests/Integration/dashboard_test.php` | PASS |
| Purchases are excluded and ordering is quantity-descending | `tests/Integration/dashboard_test.php` | PASS |
| Limits normalize and bound results | `tests/Integration/dashboard_test.php` | PASS |
| Empty staff scope returns `[]` | `tests/Integration/dashboard_test.php` | PASS |
| Closed connections preserve the existing thrown failure | `tests/Integration/dashboard_test.php` | PASS |

## Known gaps and operational notes

The dashboard page changed only its PHP data-service call. Browser QA is run as
a final verification because `public/index.php` changed, but no visual baseline
is introduced by this batch. Visual regression remains INCONCLUSIVE without
committed baselines, and automated accessibility checks do not replace a
manual WCAG audit.

The module preserves the existing server-side error logging and does not expose
database diagnostics through a normal result. The legacy wrapper remains for
unmigrated callers and compatibility tests.

## Final evidence

Final verification from the Phase 3E working tree:

| Check | Command/result |
|---|---|
| Full disposable regression | `docker compose exec -T ... app php tests/run.php` — PASS: **1715 assertions** (1045 unit, 670 integration) |
| PHP syntax | `docker compose run --rm --no-deps app sh -lc "find config database includes public scripts tests -type f -name '*.php' -print0 \| xargs -0 -n1 php -l"` — PASS, 67 PHP files |
| JavaScript syntax | `node --check` for every tracked `*.js` — PASS, 4 files |
| Repository security and supply-chain policy | Git-enabled disposable app CLI — PASS, zero findings |
| Browser QA | `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/run-browser-qa.ps1` — **18/18 passed** at mobile 375px, tablet 768px, and desktop 1440px |
| Diff whitespace | `git diff --check` — PASS |

The Browser QA runner removed its disposable containers, images, volume, and
network. No visual baseline was added; visual regression remains INCONCLUSIVE
without committed baselines. Automated accessibility checks passed as reported
by the suite, but they are not a complete manual WCAG audit.
