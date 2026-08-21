# Phase 4F — Final Compatibility Facade and Architecture Review

Status: complete

This document records the evidence-driven closure review performed after the
Catalog, Inventory, Orders, Dashboard, Categories, Customer, and Supplier
extractions. It is a current-state architecture record, not permission to
remove legacy code. Phase 4G must characterize each candidate again before any
deletion.

## Review method

The review inspected:

- `includes/functions.php`
- every PHP file under `includes/` and `public/`
- `scripts/`
- `tests/`
- current architecture documentation

The source inventory was generated from the function declarations in
`includes/functions.php`. Production callers were searched separately under
`includes/` and `public/`, test callers under `tests/`, and script callers under
`scripts/`. The dependency review searched all `require_once` declarations and
checked extracted modules for facade, session, and global-state access.

The review used repository-local `rg` searches and the existing source-contract
tests. A function is not marked dead merely because no page currently calls it:
compatibility callers, export paths, tests, scripts, and external deployments
remain part of the removal risk assessment.

## Findings and safe corrections

The review found no circular include dependency, no extracted module requiring
`functions.php`, no unexpected `$_SESSION` or `$GLOBALS` access in a completed
module, no duplicate SQL in delegation wrappers, and no duplicated sanitization
implementation. `validation.php` remains strict, dependency-free, and pure.

Two narrow, evidence-backed corrections were made:

1. `includes/people.php` had a stale comment saying Customer and Supplier
   writes still lived in the facade. The comment now records the completed
   Customer and Supplier mutation boundaries.
2. `public/stock_movements.php` was the last verified production page using
   Catalog reads through the facade. Its product lookup and bounded POS
   selector now call `catalog_get_product_by_id()` and
   `catalog_get_pos_products()` directly. Its authentication, CSRF order,
   authorization, inventory mutation, pagination, rendering, and messages were
   not changed.

No function or file was deleted. No database schema or business behavior was
changed. No push was performed.

## Complete facade classification

The facade contains 73 functions. The following inventory is exhaustive. The
caller columns report the production search (`includes/` and `public/`), test
search (`tests/`), and script search (`scripts/`). “None found” means no call
was found outside `includes/functions.php` itself.

### Delegation-only compatibility wrappers

| Function | Owning module | Production callers | Test callers | Script callers | Phase 4G status |
|---|---|---|---|---|---|
| `redirect` | `http.php` | `public/login.php` | `auth_extraction_test.php` | none found | retain; auth boundary and external compatibility risk |
| `build_product_filter_sql` | `catalog.php` | none found | none found | none found | candidate; verify external/CLI callers first |
| `get_pos_products` | `catalog.php` | none found after stock-page migration | `database_test.php` | none found | candidate; retain until compatibility inventory is complete |
| `get_pos_product_by_barcode` | `catalog.php` | none found | `database_test.php` | none found | candidate; retain until compatibility inventory is complete |
| `count_products` | `catalog.php` | none found | `database_test.php` | none found | candidate; retain until compatibility inventory is complete |
| `get_products_page` | `catalog.php` | none found | `database_test.php` | none found | candidate; retain until compatibility inventory is complete |
| `get_product_by_id` | `catalog.php` | none found after stock-page migration | `database_test.php` | none found | candidate; verify external callers first |
| `get_low_stock_products` | `inventory.php` | none found | `inventory_read_test.php`, `inventory_read_test.php` | none found | candidate; retain for compatibility |
| `log_stock_movement` | `inventory.php` | none found | `inventory_adjustment_test.php`, `database_test.php`, `inventory_read_test.php` | none found | candidate; retain for compatibility |
| `count_stock_movements` | `inventory.php` | none found | `database_test.php` | none found | candidate; retain for compatibility |
| `get_stock_movements_page` | `inventory.php` | none found | `database_test.php` | none found | candidate; retain for compatibility |
| `create_product` | `products.php` | none found | `product_write_test.php`, `database_test.php`, `backup_restore_test.php` | none found | candidate; preserve wrapper until external callers are cleared |
| `update_product` | `products.php` | none found | `product_write_test.php`, `database_test.php` | none found | candidate; preserve wrapper until external callers are cleared |
| `delete_product` | `products.php` | none found | `product_write_test.php`, `database_test.php` | none found | candidate; preserve wrapper until external callers are cleared |
| `create_order` | `orders.php` | none found | `order_write_test.php`, `validation_test.php`, `database_test.php`, `backup_restore_test.php` | none found | candidate; preserve order-ID/false compatibility contract |
| `count_orders` | `orders.php` | none found | `database_test.php` | none found | candidate; preserve bounded-read wrapper |
| `get_order_summary` | `orders.php` | none found | `database_test.php` | none found | candidate; preserve bounded-read wrapper |
| `get_orders_page` | `orders.php` | none found | `database_test.php` | none found | candidate; preserve bounded-read wrapper |
| `get_order_by_id` | `orders.php` | none found | `database_test.php` | none found | candidate; preserve scoped-read wrapper |
| `get_order_details` | `orders.php` | none found | `database_test.php` | none found | candidate; preserve scoped-read wrapper |
| `get_dashboard_stats` | `dashboard.php` | none found | `dashboard_test.php` | none found | candidate; preserve fixed-key response wrapper |
| `handle_image_upload` | `uploads.php` | none found | none found | none found | candidate; verify deployment/plugin callers before removal |
| `delete_newly_uploaded_image` | `uploads.php` | none found | none found | none found | candidate; verify deployment/plugin callers before removal |
| `get_chart_data` | `dashboard.php` | none found | `dashboard_test.php` | none found | candidate; preserve chart-shape wrapper |
| `count_categories` | `catalog.php` | none found | `database_test.php` | none found | candidate; preserve compatibility wrapper |
| `get_categories_page` | `catalog.php` | none found | `database_test.php` | none found | candidate; preserve compatibility wrapper |
| `get_categories_for_selector` | `catalog.php` | none found | `database_test.php` | none found | candidate; preserve compatibility wrapper |
| `create_category` | `categories.php` | none found | `category_write_test.php`, `database_test.php` | none found | candidate; preserve wrapper until external callers are cleared |
| `update_category` | `categories.php` | none found | `category_write_test.php`, `database_test.php` | none found | candidate; preserve wrapper until external callers are cleared |
| `delete_category` | `categories.php` | none found | `category_delete_test.php`, `database_test.php` | none found | candidate; preserve wrapper until external callers are cleared |
| `count_customers` | `people.php` | none found | `database_test.php` | none found | candidate; preserve compatibility wrapper |
| `get_customers_page` | `people.php` | none found | `database_test.php` | none found | candidate; preserve compatibility wrapper |
| `get_customers_for_selector` | `people.php` | none found | `database_test.php` | none found | candidate; preserve compatibility wrapper |
| `create_customer` | `customers.php` | none found | `customer_mutation_test.php`, `database_test.php`, `backup_restore_test.php` | none found | candidate; preserve wrapper until external callers are cleared |
| `update_customer` | `customers.php` | none found | `customer_mutation_test.php`, `database_test.php` | none found | candidate; preserve wrapper until external callers are cleared |
| `delete_customer` | `customers.php` | none found | `customer_mutation_test.php`, `database_test.php` | none found | candidate; preserve wrapper until external callers are cleared |
| `count_suppliers` | `people.php` | none found | `database_test.php` | none found | candidate; preserve compatibility wrapper |
| `get_suppliers_page` | `people.php` | none found | `database_test.php` | none found | candidate; preserve compatibility wrapper |
| `get_suppliers_for_selector` | `people.php` | none found | `database_test.php` | none found | candidate; preserve compatibility wrapper |
| `create_supplier` | `suppliers.php` | none found | `database_test.php` | none found | candidate; preserve wrapper until external callers are cleared |
| `update_supplier` | `suppliers.php` | none found | `database_test.php` | none found | candidate; preserve wrapper until external callers are cleared |
| `delete_supplier` | `suppliers.php` | none found | `supplier_mutation_test.php`, `database_test.php` | none found | candidate; preserve wrapper until external callers are cleared |
| `get_inventory_valuation` | `dashboard.php` | none found | none found | none found | candidate; verify external report callers first |
| `get_top_selling_products` | `dashboard.php` | none found | none found | none found | candidate; verify external report callers first |
| `get_category_sales_distribution` | `dashboard.php` | none found | none found | none found | candidate; verify external report callers first |

Every wrapper above contains only its focused call (or the preserved HTTP
compatibility call). There is no SQL, sanitization, or error logging in these
bodies.

### Still-owned legacy services

| Function | Owner in current state | Production callers | Test callers | Script callers | Phase 4G decision |
|---|---|---|---|---|---|
| `login_rate_limit_log_failure` | `functions.php` login rate-limit service | none found | none found | none found | do not retire without a separate auth characterization |
| `login_rate_limit_begin_transaction` | `functions.php` login rate-limit service | none found | none found | none found | retain; transaction helper is part of the rate-limit contract |
| `login_rate_limit_rollback` | `functions.php` login rate-limit service | none found | none found | none found | retain with rate-limit service |
| `login_rate_limit_cleanup_expired` | `functions.php` login rate-limit service | none found | none found | none found | retain with rate-limit service |
| `login_rate_limit_check` | `functions.php` login rate-limit service | `public/login.php` | `auth_extraction_test.php`, `database_test.php` | none found | do not retire; production security boundary |
| `login_rate_limit_record_failure` | `functions.php` login rate-limit service | `public/login.php` | `auth_extraction_test.php`, `database_test.php`, `backup_restore_test.php` | none found | do not retire; production security boundary |
| `login_rate_limit_reset` | `functions.php` login rate-limit service | `public/login.php` | `auth_extraction_test.php`, `database_test.php` | none found | do not retire; production security boundary |
| `get_staff_members` | `functions.php` staff service | `public/settings.php` | `database_test.php` | none found | do not retire; production caller |
| `create_staff_member` | `functions.php` staff service | `public/settings.php` | `auth_extraction_test.php`, `database_test.php` | `browser-qa-seed.php` | do not retire; production and seed callers |
| `update_staff_member` | `functions.php` staff service | `public/settings.php` | `auth_extraction_test.php`, `database_test.php` | none found | do not retire; production caller |
| `delete_staff_member` | `functions.php` staff service | `public/settings.php` | `auth_extraction_test.php` | none found | do not retire; production caller |
| `set_staff_active` | `functions.php` staff service | `public/settings.php` | `auth_extraction_test.php` | none found | do not retire; production caller |

### Shared helpers

| Function | Current owner | Production callers | Test callers | Script callers | Phase 4G decision |
|---|---|---|---|---|---|
| `password_meets_policy` | `functions.php` shared validation/auth helper | `public/settings.php` | `validation_test.php`, `auth_extraction_test.php` | `browser-qa-seed.php` | retain; active policy and seed contract |
| `normalize_login_identifier` | `functions.php` pure login helper | none found | `validation_test.php` | none found | characterize before any move; no removal yet |
| `build_login_rate_limit_key` | `functions.php` pure login helper | `public/login.php` | `validation_test.php`, `database_test.php`, `backup_restore_test.php` | none found | retain; active security helper |

### Unbounded legacy loaders

| Function | Current owner | Production callers | Test callers | Script callers | Phase 4G decision |
|---|---|---|---|---|---|
| `get_all_products` | `functions.php` legacy product loader | none found | `export_streaming_test.php` | none found | candidate; migrate/verify export and external consumers first |
| `get_stock_movements` | `functions.php` legacy movement loader | none found | `inventory_read_test.php`, `export_streaming_test.php` | none found | candidate; replace unbounded export/read contract first |
| `get_orders` | `functions.php` legacy order loader | none found | `order_read_test.php`, `database_test.php` | none found | candidate; replace any external full-list consumer first |
| `get_orders_for_staff` | `functions.php` legacy scoped loader | none found | `order_read_test.php`, `database_test.php` | none found | candidate; verify staff-scoped external consumers first |
| `get_categories` | `functions.php` legacy category loader | none found | `catalog_read_test.php` | none found | candidate; migrate any full-selector/export consumer first |
| `get_customers` | `functions.php` legacy customer loader | none found | `people_read_test.php`, `export_streaming_test.php` | none found | candidate; replace export contract first |
| `get_suppliers` | `functions.php` legacy supplier loader | none found | `people_read_test.php`, `export_streaming_test.php` | none found | candidate; replace export contract first |

### Uncalled legacy lookups

| Function | Current owner | Production callers | Test callers | Script callers | Phase 4G decision |
|---|---|---|---|---|---|
| `get_category_by_id` | `functions.php` legacy lookup | none found | `catalog_read_test.php` | none found | candidate; confirm external/API callers before removal |
| `get_customer_by_id` | `functions.php` legacy lookup | none found | `people_read_test.php` | none found | candidate; confirm external/API callers before removal |
| `get_supplier_by_id` | `functions.php` legacy lookup | none found | `people_read_test.php` | none found | candidate; expressly retain until verified caller inventory is complete |

### Request/session/auth boundaries

| Function | Focused owner / boundary | Production callers | Test callers | Script callers | Phase 4G decision |
|---|---|---|---|---|---|
| `verify_login` | `auth.php` through the global-compatible facade boundary | none found outside focused callers | `auth_extraction_test.php`, `database_test.php` | none found | retain until all auth page callers are migrated and characterized |
| `is_admin` | `auth.php` through the global-compatible facade boundary | `includes/layouts/sidebar.php` | `auth_extraction_test.php`, `database_test.php` | none found | do not retire; active production caller |
| `require_admin` | `auth.php` through the global-compatible facade boundary | none found | `auth_extraction_test.php` | none found | retain; terminating authorization contract |

## Architecture boundary results

| Boundary | Result | Evidence |
|---|---|---|
| Dedicated modules require no facade | pass | source contracts scan all completed modules for `require_once ... functions.php` |
| Dedicated modules avoid session/global state | pass | source contracts scan completed modules for `$_SESSION` and `$GLOBALS` |
| Include graph direction | pass | extracted modules point to low-level helpers only; no cycle found |
| Validation purity | pass | `validation.php` is strict, has no `require_once`, `mysqli`, session, or global access |
| People read-only | pass | no Customer/Supplier write definitions in `people.php`; writes are in `customers.php`/`suppliers.php` |
| Customer/Supplier ownership | pass | public pages call focused mutation services; wrappers contain delegation only |
| Direct migrated public callers | pass | products, categories, customers, suppliers, orders, dashboards, order reads, POS lookup, and stock movements use focused owners |
| Wrapper SQL duplication | pass | wrapper-body contract rejects SQL verbs, `prepare`, query calls, sanitization, and logging |
| Sanitization duplication | pass | reusable sanitizers are centralized in `validation.php`; focused modules require it where needed |

## Phase 4G candidate inventory

The candidates are review targets, not deletion approvals. The evidence below
shows no current production caller in the tracked `includes/` and `public/`
search. The test/script callers and compatibility risk remain material.

| Function/file | Evidence of no production callers | Remaining test/script callers | Risk | Required migration before removal | Recommended action |
|---|---|---|---|---|---|
| Catalog wrapper group in `includes/functions.php` (`build_product_filter_sql`, `get_pos_products`, `get_pos_product_by_barcode`, `count_products`, `get_products_page`, `get_product_by_id`, `count_categories`, `get_categories_page`, `get_categories_for_selector`) | none found after direct page audit | database and catalog unit/integration contracts | low/medium compatibility risk | search deployment scripts, plugins, scheduled jobs, and external CLI consumers; replace tests with focused calls | retain until Phase 4G compatibility inventory closes |
| Inventory wrapper group in `includes/functions.php` (`get_low_stock_products`, `log_stock_movement`, `count_stock_movements`, `get_stock_movements_page`) | none found in production pages | inventory unit/integration tests | medium because write and read response contracts are public legacy names | migrate remaining scripts/exports and prove no external caller; preserve failure semantics | retain; consider one bounded migration at a time |
| Product mutation wrappers in `includes/functions.php` (`create_product`, `update_product`, `delete_product`) | none found in production pages | product-write, database, backup integration tests | high because writes affect stock history and audit behavior | characterize wrapper equivalence, migrate all operational scripts, and define removal/versioning policy | retain |
| Order mutation/read wrappers in `includes/functions.php` (`create_order`, `count_orders`, `get_order_summary`, `get_orders_page`, `get_order_by_id`, `get_order_details`) | none found in migrated pages | order-write, order-read, validation, database, backup tests | high because order scope and transaction contracts are security-sensitive | prove all exports/scripts/external callers use focused services | retain |
| Dashboard wrappers in `includes/functions.php` (`get_dashboard_stats`, `get_chart_data`, `get_inventory_valuation`, `get_top_selling_products`, `get_category_sales_distribution`) | none found in dashboard page | dashboard integration tests for the first two; source compatibility checks for the rest | medium because report shape and failure defaults are externally observable | verify exports/CLI/report consumers and keep response-contract tests | retain until report caller audit |
| Upload wrappers in `includes/functions.php` (`handle_image_upload`, `delete_newly_uploaded_image`) | none found in tracked production/tests/scripts | none found | medium filesystem/security risk | search deployment hooks and extensions; retain path-boundary tests independent of facade | retain; remove only with an explicit compatibility decision |
| Category mutation wrappers in `includes/functions.php` (`create_category`, `update_category`, `delete_category`) | none found in `public/` after Phase 4C | category write/delete and database tests | high because deletion is transactional and reassigns products | prove external admin tooling is migrated and wrapper equivalence remains covered | retain |
| Customer mutation wrappers in `includes/functions.php` (`create_customer`, `update_customer`, `delete_customer`) | none found in `public/` after Phase 4D | customer mutation, database, backup tests | high because protected Walk-in behavior and legacy missing-ID update semantics are contractual | prove external caller inventory and keep database FK characterization | retain |
| Supplier mutation wrappers in `includes/functions.php` (`create_supplier`, `update_supplier`, `delete_supplier`) | none found in `public/` after Phase 4E | supplier mutation and database tests | high because historical purchase-order `ON DELETE SET NULL` behavior is contractual | prove external caller inventory and keep FK characterization | retain |
| Unbounded loaders in `includes/functions.php` (`get_all_products`, `get_stock_movements`, `get_orders`, `get_orders_for_staff`, `get_categories`, `get_customers`, `get_suppliers`) | none found in tracked production | export, read, catalog, and people tests | high performance/data-volume risk; medium compatibility risk | replace any full-list export/read contract with bounded or dedicated export services; characterize memory and ordering | candidate for staged Phase 4G migration, not deletion now |
| Legacy lookups in `includes/functions.php` (`get_category_by_id`, `get_customer_by_id`, `get_supplier_by_id`) | none found in tracked production | catalog/people read tests | medium because names may be used by external tools; supplier lookup is explicitly retained by Phase 4E scope | perform repository, deployment, CLI, and integration-caller inventory; add focused owner only if a verified caller needs it | candidate for later review, no deletion |
| `normalize_login_identifier` in `includes/functions.php` | no production caller found | validation unit test | medium auth correctness risk | characterize login and script callers; move only with auth-specific contract coverage | retain until auth phase |

## Intentional remaining legacy boundaries

The following are intentionally outside Phase 4F: login rate limiting and
authentication compatibility, staff/settings administration, unbounded legacy
loaders, legacy reference lookups, export/backup caller migration, and any
schema or UI redesign. Their lack of a tracked page caller is not sufficient
evidence for deletion. Phase 4G must use a separate characterization and
release decision for each candidate.

## Test and verification record

The RED source-contract checkpoint is the commit that introduced
`tests/Unit/facade_closure_test.php` and the runner integration. The GREEN
checkpoint must prove the complete 73-function inventory, wrapper-only bodies,
module dependency direction, direct public callers, and corrected current
documentation. A separate documentation checkpoint records this file and the
current architecture-document updates.

The final report for the implementation records the exact focused assertion
count, full disposable regression count, lint result, security scan, supply-
chain scan, browser QA result at 375px/768px/1440px, worktree status, and
commit hashes.
