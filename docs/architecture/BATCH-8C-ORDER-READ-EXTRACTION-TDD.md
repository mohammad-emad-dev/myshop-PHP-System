# Batch 8C — Order Read Extraction TDD Evidence

This batch moves only bounded and single-record order reads from
`includes/functions.php` into the existing `includes/orders.php` module. The
order-history, order-detail, and invoice page callers remain unchanged and
continue to use compatibility wrappers.

## Caller inventory

The pre-change inventory used:

```text
rg -n "\b(count_orders|get_orders_page|get_order_summary|get_order_by_id|get_order_details)\s*\(" public includes tests scripts --glob '*.php'
```

Production callers found:

| Page | Calls |
|---|---|
| `public/order_history.php:20,26,27` | `count_orders()`, `get_orders_page()`, `get_order_summary()` |
| `public/get_order_details.php:19,46` | `get_order_by_id()`, `get_order_details()` |
| `public/print_invoice.php:16,23` | `get_order_by_id()`, `get_order_details()` |

Integration callers in `tests/Integration/database_test.php` cover count,
pagination, summary, scoped lookup, unauthorized lookup/detail behavior, and
closed-connection failure behavior through the compatibility names. The same
inventory found no dynamic order-read calls. `get_orders()` and
`get_orders_for_staff()` remain legacy unbounded loaders used by the database
integration suite and were intentionally not extracted.

## TDD checkpoints

| Stage | Commit | Command/result | Evidence |
|---|---|---|---|
| RED | `00cfe2a` | `docker compose run --rm --no-deps app php -r "require 'tests/Unit/order_read_test.php'; echo run_order_read_unit_tests(), PHP_EOL;"` failed with `Orders module must explicitly require pagination for bounded reads.` | The new source contract executed against the pre-extraction module and failed for the intended missing implementation boundary. |
| GREEN | `be2dc9f` | Same focused command passed with `68` assertions. | All five focused functions are present in `includes/orders.php`; the facade names delegate without SQL; pages and unbounded loaders remain intentionally unchanged. |

## Focused module boundary

`includes/orders.php` now owns:

- `orders_count()` for scoped/type-filtered counts;
- `orders_get_page()` for bounded, deterministic pages;
- `orders_get_summary()` for scoped/type-filtered numeric summaries;
- `orders_get_by_id()` for scoped single-order lookup; and
- `orders_get_details()` for scoped order-detail lookup.

The module explicitly requires `pagination.php` for page-size normalization and
does not require `functions.php`, read session state, or use globals. The SQL,
aliases, prepared bindings, staff scope, filter defaults, ordering, and failure
return values were moved without redesign.

`count_orders()`, `get_orders_page()`, `get_order_summary()`,
`get_order_by_id()`, and `get_order_details()` remain thin compatibility
wrappers. The legacy `get_orders()` and `get_orders_for_staff()` loaders remain
in the facade because no bounded extraction was required for their callers.

## Verification evidence

| Check | Result |
|---|---|
| Focused order-read source tests | PASS — `68` assertions |
| Focused order-write source tests | PASS — `43` assertions |
| Focused product tests | PASS — `108` assertions |
| Focused inventory read tests | PASS — `60` assertions |
| Focused inventory adjustment tests | PASS — `40` assertions |
| Full disposable regression | PASS — `1537` assertions (`941` unit, `596` integration) |
| Browser QA | Not run; public pages were unchanged in this batch and no Browser QA result is claimed. |

The disposable integration suite verifies admin/global visibility, cashier
scope isolation, unauthorized order/detail behavior, all/sale/purchase counts,
bounded pages and offsets, deterministic ordering, summary defaults, invalid
IDs, and closed-connection failure contracts. No schema, page, UI, CSS,
JavaScript, authorization, or CSRF behavior changed.

## Rollback

Revert the Batch 8C commits in reverse order. The production rollback boundary
is limited to restoring the five original implementations in
`includes/functions.php` and removing the focused read functions; no database
or deployment state changed.
