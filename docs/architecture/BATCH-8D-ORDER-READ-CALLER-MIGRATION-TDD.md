# Batch 8D — Order Read Caller Migration TDD Evidence

## Scope

Batch 8D migrates only the bounded and single-record order-read callers in:

- `public/order_history.php`
- `public/get_order_details.php`
- `public/print_invoice.php`

The focused implementations remain in `includes/orders.php`. The legacy
`functions.php` names remain delegation-only compatibility wrappers. The
unbounded `get_orders()` and `get_orders_for_staff()` loaders were not changed.

## Pre-change caller inventory

The pre-migration source at the Batch 8D starting point contained these calls:

| Page | Legacy call | Preserved arguments and scope |
| --- | --- | --- |
| `public/order_history.php` | `count_orders($conn, $order_scope_staff_id, $filter_type)` | Admins pass `null`; cashiers pass their session staff ID and the selected filter. |
| `public/order_history.php` | `get_orders_page($conn, $order_scope_staff_id, $filter_type, $page_size, $offset)` | Existing bounded page size and calculated offset are preserved. |
| `public/order_history.php` | `get_order_summary($conn, $order_scope_staff_id, $filter_type)` | Existing admin/cashier scope and sale/purchase/all filter are preserved. |
| `public/get_order_details.php` | `get_order_by_id($conn, $order_id, $staff_scope)` | Existing admin-global or cashier-owned lookup scope is preserved. |
| `public/get_order_details.php` | `get_order_details($conn, $order_id, $staff_scope)` | The same authorized order scope is preserved. |
| `public/print_invoice.php` | `get_order_by_id($conn, $order_id, $staff_scope)` | The same admin/cashier scope is preserved before invoice rendering. |
| `public/print_invoice.php` | `get_order_details($conn, $order_id, $staff_scope)` | The same scope and order ID are preserved. |

Repository-wide search found no dynamic calls for these five bounded read
operations. The compatibility wrappers remain available for other callers and
tests.

## TDD checkpoints

### RED

Commit `62fed90` (`test(orders): characterize direct read callers`) changed the
order-read source contracts to require the focused service calls and to reject
legacy order-read calls in the three pages.

Command:

```text
docker compose run --rm --no-deps app php -r "require 'tests/Unit/order_read_test.php'; echo run_order_read_unit_tests(), PHP_EOL;"
```

Result: **RED** — the test failed with:

```text
TestFailure: Order history must call orders_count with its existing scope and filter.
```

### GREEN

Commit `545a143` (`refactor(orders): migrate public read callers`) changed only
the seven page call sites to the focused service names. The page request,
authorization, staff scope, filtering, response, and rendering code was not
otherwise changed.

Focused source-contract result after the migration: **71 assertions passed**.

The five legacy wrappers remain delegation-only and contain no SQL or result
processing. `get_orders()` and `get_orders_for_staff()` remain intentionally
unbounded legacy loaders.

## Preserved contracts

- `order_history.php` still calculates the same page, page size, offset, staff
  scope, and order-type filter before reading data.
- `get_order_details.php` and `print_invoice.php` still authorize the order
  lookup before rendering or returning data, with the same 404/JSON behavior.
- Admins retain global visibility; cashiers remain limited to their own orders.
- The focused services preserve the existing null, empty-array, count, summary,
  ordering, and error-log contracts.
- `includes/functions.php` remains a compatibility boundary for unmigrated
  callers and tests.

## Verification evidence

The final local verification was run against the Batch 8D implementation and
completed with these results:

| Check | Result |
| --- | --- |
| Focused order-read source tests | PASS — `71` assertions |
| Focused order-write tests | PASS — `43` assertions |
| Focused product tests | PASS — `108` assertions |
| Focused inventory read tests | PASS — `60` assertions |
| Focused inventory adjustment tests | PASS — `40` assertions |
| Full disposable regression | PASS — `1540` assertions (`944` unit, `596` integration) |
| PHP lint | PASS — all PHP files under `config`, `database`, `includes`, `public`, `scripts`, and `tests` reported no syntax errors |
| Repository security scan | PASS — no findings in tracked files |
| CI supply-chain policy scan | PASS — workflow and production image references comply with the immutable-reference policy |
| Release-integrity check | PASS — safe release metadata validated |
| `git diff --check` | PASS |
| Disposable cleanup | PASS — zero matching test/browser schemas and users, containers, volumes, networks, or temporary artifacts |

The existing disposable Browser QA suite was also run because public read pages
changed: **18/18 tests passed** across the `375px`, `768px`, and `1440px`
projects, including authentication/authorization, order-history access,
responsive overflow, accessibility, and keyboard smoke coverage. Automated axe
results remain a regression signal and do not constitute a complete manual WCAG
audit. No visual baseline claim is made here.

## Rollback

To roll back the caller migration while retaining the extracted module, revert
the Batch 8D caller commit and its documentation commit. The unchanged legacy
wrappers provide the previous page call boundary. Do not remove the focused
module or wrappers without first rechecking all callers.
