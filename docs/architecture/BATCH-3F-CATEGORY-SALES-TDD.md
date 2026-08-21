# Phase 3F: category sales distribution extraction evidence

## Scope

Phase 3F moved only the implementation of `get_category_sales_distribution()`
from `includes/functions.php` to
`dashboard_get_category_sales_distribution($conn, $staff_id = null, $limit = 100)`
in `includes/dashboard.php`. The legacy function remains a delegation-only
compatibility wrapper, and `public/index.php` now calls the focused service
directly. No UI, authorization, CSRF, schema, or unrelated report behavior was
changed.

Historical architecture records, including Phase 3A and Batch 7A evidence,
remain unchanged. This document records current Phase 3F evidence only.

## Pre-change characterization

The only verified production caller before extraction was:

- `public/index.php:28` — `get_category_sales_distribution($conn,
  $dashboard_staff_id)`.

The implementation had two intentionally different closed-connection paths:

- global scope (`$staff_id === null`): the existing `try/catch/finally` returned
  `[]` and logged `Category sales distribution query failed`;
- scoped scope: the existing uncaught `mysqli` failure escaped as `Error` with
  `mysqli object is already closed`.

The extraction preserves this distinction, as well as the existing prepared
bindings, `Uncategorized` fallback, sale-only filter, grouping, descending
total-sales ordering, and limit normalization to 25, 50, or 100.

## RED checkpoint

Source and integration contracts were added before implementation. The focused
unit command failed as expected because the explicit service did not yet exist:

```text
docker compose run --rm --no-deps app php -r "require 'tests/Unit/dashboard_test.php'; echo 'DASHBOARD_UNIT_ASSERTIONS=' . run_dashboard_unit_tests() . PHP_EOL;"
Dashboard module must expose the explicit category-sales service.
```

The focused integration command likewise failed before implementation because
`dashboard_get_category_sales_distribution()` was undefined.

RED test commit:

- `9ab485fa1ccdbcc14429b74a2ea412a3bd054cf7` —
  `test(dashboard): characterize category sales extraction`

## GREEN implementation

Implementation commit:

- `e6d8bcc6b37592bcd33d2cd5816a8ba367a3fc4a` —
  `refactor(dashboard): extract category sales service`

A fixture-only correction made the integration data explicitly assign products
to their test categories while preserving the production schema and behavior:

- `526f6489da361126d3fc2aa5d9528441dc5515ed` —
  `test(dashboard): stabilize category distribution fixtures`

Focused GREEN results:

- Dashboard source/unit contracts: **135 assertions**.
- Dashboard integration contracts: **95 assertions**.
- Covered global and cashier-scoped distributions, sale-only behavior,
  Uncategorized rows, valid and invalid limits, deterministic ordering,
  empty/unknown-staff behavior, wrapper equivalence, and separate global versus
  scoped closed-connection behavior.

## Verification evidence

The final disposable checks completed successfully:

- Full regression: **1770 assertions** — 1079 unit and 691 integration.
- PHP lint: **67 PHP files**, all passed.
- JavaScript syntax: **4 tracked JavaScript files**, all passed.
- Repository security scan: passed with zero findings.
- CI supply-chain policy scan: passed with immutable references.
- Browser QA: **18/18 passed** at 375px, 768px, and 1440px; disposable
  containers, images, network, and MySQL volume were cleaned up by the runner.
- `git diff --check`: passed before the documentation-only update.

The automated accessibility checks remain regression evidence only and are not
a complete manual WCAG audit. No visual regression baseline was introduced in
this batch.
