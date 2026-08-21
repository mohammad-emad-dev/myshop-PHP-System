# Phase 3G: low-stock read extraction evidence

## Scope

Phase 3G moved only the implementation of `get_low_stock_products()` from
`includes/functions.php` to
`inventory_get_low_stock_products($conn, $limit = 100)` in
`includes/inventory.php`. The legacy function remains a delegation-only
compatibility wrapper. `public/index.php` and `includes/layouts/navbar.php` now
call the focused Inventory service directly.

No stock mutation, stock-movement history, upload, authorization, session,
rendering, schema, CSS, or JavaScript behavior was changed.

Historical Phase 3A and Batch 7A documents remain unchanged.

## Pre-change characterization

The facade implementation was a bounded prepared query with:

- default limit 100 and normalization to 25, 50, or 100;
- `stock <= alert_threshold` filtering;
- `LEFT JOIN Category` and the `category_name` alias;
- deterministic `stock ASC, name ASC, id ASC` ordering;
- `[]` on prepare, bind, execute, result, query, or closed-connection failure;
- existing low-stock server-side error messages.

The verified production callers before extraction were:

- `public/index.php` — dashboard low-stock table;
- `includes/layouts/navbar.php` — notification count and preview list.

## RED checkpoint

Source contracts and disposable integration tests were added before the
implementation. The focused unit command failed as expected because the
explicit Inventory service was absent:

```text
docker compose run --rm --no-deps app php -r "require 'tests/Unit/inventory_read_test.php'; echo 'INVENTORY_READ_UNIT_ASSERTIONS=' . run_inventory_read_unit_tests() . PHP_EOL;"
Fatal error: ... Inventory function is missing: inventory_get_low_stock_products
```

The focused integration command also failed before implementation because the
new function was undefined.

RED commit:

- `e9a7b6e3351bfca62dfc7ff56b39e3c997fbcb37` —
  `test(inventory): characterize low-stock read extraction`

## GREEN implementation

GREEN commit:

- `ce93afd79d645b55f06333294ff95f46278dac9d` —
  `refactor(inventory): extract low-stock reads`

Focused GREEN evidence:

- Inventory source contracts: **91 assertions**.
- Disposable low-stock integration tests: **16 assertions**.
- Dashboard source contracts: **136 assertions**.
- Covered threshold equality, below-threshold inclusion, above-threshold
  exclusion, category alias/null behavior, deterministic ordering, allowed and
  invalid limits, closed-connection fallback, and wrapper equivalence.

## Final verification

- Full disposable regression: **1818 assertions** — 1111 unit and 707
  integration.
- PHP lint: **67 files**, all passed.
- JavaScript syntax: **4 tracked files**, all passed.
- Repository security scan: passed with zero findings.
- CI supply-chain scan: passed with immutable references.
- Browser QA: **18/18 passed** at 375px, 768px, and 1440px, including
  dashboard/navbar rendering, responsive overflow, console/network checks, and
  automated accessibility regression coverage.
- Disposable Browser QA containers, images, network, and MySQL volume were
  cleaned up by the runner.
- `git diff --check`: passed before documentation commit.

Automated accessibility checks are regression evidence only and are not a
complete manual WCAG audit. No visual regression baseline was added.
