# Phase 5C: Local Performance and Data-Volume Readiness

Status: complete. This is a current architecture record; historical Phase 3
and Phase 4 documents are intentionally unchanged.

## Scope and decision

MyShop remains a localhost-first application. This phase audited the complete
array readers named in the phase request, their production and compatibility
callers, interactive selectors and history pages, dashboard reports, and CSV
exports. The evidence did not justify a production SQL or schema change:
interactive list reads already use focused bounded services, exports already
use cursor-batched streaming, and the canonical schema already contains the
indexes needed by the verified scoped access paths.

The phase therefore makes the smallest safe change: it adds executable
data-volume contracts and a disposable fixture, records the caller/evidence
inventory, and preserves the existing application behavior.

## Evidence method

The audit used repository-wide `rg` searches over `includes/`, `public/`,
`scripts/`, tests, configuration, and current architecture documentation.
Function definitions in `includes/functions.php` were distinguished from
callers. No tracked production caller was found for the seven complete-array
legacy loaders below. Existing compatibility tests and integration tests still
exercise several of those names, so they remain public facade behavior.

`tests/Integration/data_volume_test.php` creates a unique disposable MySQL
database through `DisposableDatabase`, loads the canonical schema and current
migrations, and inserts:

- 2 staff records;
- 600 categories, products, customers, and suppliers;
- 600 stock movements and 600 orders split across two staff scopes.

All fixture writes use prepared statements and every fixture statement closes
in a `finally` block. The disposable database and temporary runtime account
are removed during cleanup.

The fixture proves that the interactive services clip result sets at their
requested limits, keep their existing staff/product scopes, and preserve their
documented ordering. Representative `EXPLAIN` statements for product,
scoped stock, scoped order, customer selector, and supplier selector paths
also execute successfully against the disposable database.

## Audited read inventory

| Read | Production callers | Compatibility callers | Decision |
|---|---|---|---|
| `get_all_products()` | None found under `includes/`, `public/`, or `scripts/` | Facade definition and legacy/source tests | Retain as an unbounded compatibility loader. Interactive callers use `catalog_get_products_page()` or `catalog_get_pos_products()`. |
| `get_stock_movements()` | None found | Facade definition and legacy/source tests | Retain. History uses `inventory_get_stock_movements_page()`; CSV uses the streaming export service. |
| `get_orders()` | None found | Facade definition and database compatibility tests | Retain. Order history uses `orders_get_page()` and `orders_get_summary()`. |
| `get_orders_for_staff()` | None found | Facade definition and staff-scope compatibility tests | Retain to preserve the scoped legacy array contract. Interactive history uses `orders_get_page()` with the existing staff scope. |
| `get_categories()` | None found | Facade definition and category compatibility tests | Retain. Category management uses `catalog_get_categories_page()` and selectors use `catalog_get_categories_for_selector()`. |
| `get_customers()` | None found | Facade definition and people compatibility tests | Retain. Customer management and selectors use the bounded `people_*` services. |
| `get_suppliers()` | None found | Facade definition and people compatibility tests | Retain. Supplier management and selectors use the bounded `people_*` services. |

The compatibility facade was not removed or redesigned. These complete-array
functions are not classified as dead solely because current pages migrated;
external callers cannot be disproved from repository evidence.

## Focused caller map

- `public/products.php` uses `catalog_get_products_page()` and a bounded
  category selector.
- `public/categories.php` uses `catalog_get_categories_page()`.
- `public/stock_movements.php` uses `inventory_get_stock_movements_page()`,
  `inventory_count_stock_movements()`, and a bounded product selector.
- `public/customers.php` uses `people_get_customers_page()` and the bounded
  customer count/selector services.
- `public/suppliers.php` uses the corresponding bounded supplier services.
- `public/orders.php` uses bounded POS products, category, customer, and
  supplier selectors.
- `public/order_history.php` uses `orders_get_page()` and `orders_get_summary()`
  with the existing administrator/cashier scope.
- `public/index.php` uses dashboard aggregates, a 31-day chart window,
  bounded top-selling/category reports, and a bounded low-stock list.

Search values remain prepared. Page and selector queries use explicit `LIMIT`
bindings and deterministic tie-breakers:

- products: `created_at DESC, id DESC`;
- stock movements: `created_at DESC, id DESC`;
- orders: `order_date DESC, id DESC`;
- categories, customers, and suppliers: `name ASC, id ASC`.

The audit did not alter authorization, staff scoping, pagination, return
contracts, or rendering.

## Exports and reports

`includes/export.php` intentionally supports complete CSV exports. It does not
load a complete result set into PHP memory: each entity uses prepared cursor
queries with `EXPORT_BATCH_SIZE` (250), deterministic ordering, and one-row
fetching. The total number of exported rows is intentionally unbounded because
that is the export contract; the in-memory working set is bounded. The phase
did not paginate or change export output.

Dashboard counts, sums, and chart aggregation return scalar or fixed-window
results. Top-selling products are capped at 50 and category distribution at
100. These operations may scan source rows to calculate an aggregate, but do
not return an unbounded detail array.

## Index and migration decision

No index or migration was added. The disposable `EXPLAIN` checks were paired
with the canonical schema review. Relevant existing indexes include:

- `idx_product_category` and `idx_product_name`;
- `idx_order_date`, `idx_order_staff`, and `idx_order_date_type`;
- `idx_stock_movement_product_created` and `idx_stock_movement_created`;
- `idx_customer_name` and `idx_supplier_name`.

Contains-search predicates such as `LIKE '%term%'` are not represented as a
new index opportunity by this phase. Adding a speculative index would change
the schema without evidence of a local workload benefit.

## Intentionally unbounded operations

The following remain intentionally unbounded in row count and are documented
legacy or operational contracts:

1. The seven complete-array compatibility loaders in the inventory above.
2. CSV export totals, implemented as bounded-memory cursor streaming.
3. Aggregate database scans used for counts, sums, and fixed-window dashboard
   reports; their returned shape is bounded even when source tables grow.

Any future change to a legacy loader requires a separate characterization and
compatibility decision. Any future index requires disposable `EXPLAIN` and
representative local data evidence plus a forward migration/recovery plan.

## Verification

The Phase 5C focused unit/source contracts verify the module limits, ordering,
caller ownership, export streaming, dashboard limits, compatibility retention,
and test registration. The focused disposable integration test verifies the
600-row fixture, clipping, deterministic ordering, authorization scope, and
five representative `EXPLAIN` plans. The full regression, PHP lint,
JavaScript syntax checks, repository security/supply-chain scans, and
`git diff --check` remain release-gate checks. Browser QA is conditional for
this phase because no production page, UI, or behavior was changed.
