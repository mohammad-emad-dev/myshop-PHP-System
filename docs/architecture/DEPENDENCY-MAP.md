# MyShop dependency map

This map records current call-site and dependency relationships after the
Phase 4G dead-code and legacy-retirement review after the Phase 4F final
compatibility facade closure. It remains a
characterization artifact; the compatibility wrappers are still required.

Phase 4G removed only the unreferenced generated `docs/preview.png` mockup.
All remaining facade functions are retained by the evidence-based manifest in
`PHASE-4G-DEAD-CODE-RETIREMENT-TDD.md`; no module dependency or runtime caller
boundary changed.

## Public-page to shared-function map

The following map is derived from the current tracked PHP source. Function
names are grouped only for readability; the source remains the authority.

| Public page | Current shared calls |
|---|---|
| `audit_log.php` | `start_secure_session`, `verify_login`, `require_admin`, `normalize_page_number`, `normalize_page_size`, `truncate_list_search`, `count_audit_logs`, `get_audit_logs_page` |
| `backup_database.php` | `start_secure_session`, `verify_login`, `is_admin`, `verify_csrf_token`, `audit_log`, `get_backup_table_allowlist`, `quote_backup_table`, `stream_database_backup` |
| `categories.php` | `start_secure_session`, `verify_login`, `is_admin`, `require_admin`, `verify_csrf_token`, `generate_csrf_token`, `sanitize_input`, `normalize_page_number`, `normalize_page_size`, `truncate_list_search`, `categories_create`, `categories_update`, `categories_delete`, `catalog_count_categories`, `catalog_get_categories_page`, `audit_log_current_actor`, `audit_log_denied` |
| `customers.php` | `start_secure_session`, `verify_login`, `is_admin`, `verify_csrf_token`, `generate_csrf_token`, `sanitize_input`, `normalize_page_number`, `normalize_page_size`, `truncate_list_search`, `customers_create`, `customers_update`, `customers_delete`, `people_count_customers`, `people_get_customers_page`, `audit_log_current_actor`, `audit_log_denied` |
| `export_report.php` | `start_secure_session`, `verify_login`, `require_admin`, `export_report_definitions`, `export_validate_entity`, `export_validate_order_filters`, `export_csv_text`, `export_csv_write_row`, `export_csv_fail`, `export_stream_entity` |
| `get_order_details.php` | `start_secure_session`, `verify_login`, `is_admin`, `orders_get_by_id`, `orders_get_details`, `audit_log_current_actor` |
| `health.php` | `initialize_request_context` |
| `index.php` | `start_secure_session`, `verify_login`, `is_admin`, `dashboard_get_stats`, `dashboard_get_chart_data`, `dashboard_get_inventory_valuation`, `dashboard_get_top_selling_products`, `dashboard_get_category_sales_distribution`, `inventory_get_low_stock_products` |
| `login.php` | `start_secure_session`, `send_security_headers`, `verify_csrf_token`, `get_login_source_ip`, `build_login_rate_limit_key`, `login_rate_limit_check`, `login_rate_limit_record_failure`, `login_rate_limit_reset`, `audit_log`, `audit_log_current_actor`, `destroy_current_session`, `generate_csrf_token`, `redirect`, `verify_login`, `get_asset_integrity` |
| `order_history.php` | `start_secure_session`, `verify_login`, `is_admin`, `sanitize_input`, `normalize_page_number`, `normalize_page_size`, `orders_count`, `orders_get_summary`, `orders_get_page` |
| `orders.php` | `start_secure_session`, `verify_login`, `is_admin`, `verify_csrf_token`, `generate_csrf_token`, `truncate_list_search`, `catalog_get_pos_products`, `catalog_get_categories_for_selector`, `people_get_customers_for_selector`, `people_get_suppliers_for_selector`, `catalog_get_product_by_id`, `orders_create`, `audit_log_current_actor`, `audit_log_denied` |
| `pos_product_lookup.php` | `start_secure_session`, `verify_login`, `truncate_list_search`, `catalog_get_pos_product_by_barcode` |
| `print_invoice.php` | `start_secure_session`, `send_security_headers`, `verify_login`, `is_admin`, `sanitize_id`, `orders_get_by_id`, `orders_get_details`, `audit_log_current_actor` |
| `products.php` | `start_secure_session`, `verify_login`, `is_admin`, `verify_csrf_token`, `generate_csrf_token`, `sanitize_input`, `normalize_page_number`, `normalize_page_size`, `truncate_list_search`, `catalog_get_categories_for_selector`, `catalog_get_products_page`, `catalog_count_products`, `products_create`, `products_update`, `products_delete`, `uploads_handle_image`, `uploads_delete_newly_uploaded_image`, `audit_log_current_actor`, `audit_log_denied` |
| `ready.php` | `initialize_request_context`, `log_application_error`; `config/db.php` performs the connection and readiness failure contract |
| `settings.php` | `start_secure_session`, `verify_login`, `is_admin`, `require_admin`, `verify_csrf_token`, `generate_csrf_token`, `sanitize_input`, `password_meets_policy`, `create_staff_member`, `update_staff_member`, `delete_staff_member`, `set_staff_active`, `get_staff_members`, `audit_log_current_actor` |
| `stock_movements.php` | `start_secure_session`, `verify_login`, `is_admin`, `verify_csrf_token`, `sanitize_input`, `normalize_page_number`, `normalize_page_size`, `catalog_get_pos_products`, `catalog_get_product_by_id`, `inventory_adjust_stock`, `inventory_get_stock_movements_page`, `inventory_count_stock_movements`, `audit_log_current_actor`, `audit_log_denied` |
| `suppliers.php` | `start_secure_session`, `verify_login`, `is_admin`, `verify_csrf_token`, `generate_csrf_token`, `sanitize_input`, `normalize_page_number`, `normalize_page_size`, `truncate_list_search`, `suppliers_create`, `suppliers_update`, `suppliers_delete`, `people_count_suppliers`, `people_get_suppliers_page`, `audit_log_current_actor`, `audit_log_denied` |

## Global and implicit dependencies

| Dependency | Current consumers and behavior |
|---|---|
| `$conn` | Most database functions accept it explicitly. Auth implementations accept it explicitly; legacy `verify_login()`/`is_admin()`/`require_admin()` wrappers, backup request helpers, and some page code retain the global or page-variable contract. |
| `$_SESSION` | `security.php` manages lifecycle; login, logout, authorization, page scoping, CSRF, and feedback state read/write session values. |
| `$GLOBALS['current_staff_record']` | `auth_verify_login()` writes it; `auth_is_admin()` reads it; `destroy_current_session()` clears it. Legacy wrappers preserve access to this contract. |
| `$GLOBALS['request_correlation_id']` | `initialize_request_context()` owns it and shutdown logging reads it. |
| `$GLOBALS['csp_nonce']` | `get_csp_nonce()` and layouts use it for inline CSP nonces. |
| `$_SERVER` | Request method, URI, peer address, HTTPS state, and forwarded protocol are used by security and page controllers. |
| Filesystem | `uploads_handle_image()` and `uploads_delete_newly_uploaded_image()` use only the canonical `public/uploads` boundary; backup and export use output streams. |
| Process environment | `config/db.php`, HSTS/proxy handling, production scripts, tests, and Compose provide configuration through environment variables. |
| Headers/termination | `http_redirect()`, `auth_require_admin()`, their legacy wrappers, database failure handlers, CSV failure handling, and backup failure handling can send headers or terminate execution. |

## Authentication and HTTP module boundaries

`includes/auth.php` requires only `security.php`, `audit.php`, and `http.php`;
it does not require `functions.php`. Database-backed operations accept `$conn`
explicitly. Existing pages still call the facade names in this batch.

| Focused function | Behavior | Side effects and security notes |
|---|---|---|
| `auth_verify_login` | Revalidates `staff_id` through the existing active Staff lookup | Invalidates failed sessions; may redirect; refreshes identity fields and `current_staff_record` on success |
| `auth_is_admin` | Uses the compatible current-staff global, revalidating if absent | Returns `false` for unauthenticated and cashier sessions |
| `auth_require_admin` | Enforces active administrator access | Audits denial, sends HTTP 403, and terminates with the existing generic body |
| `http_redirect` | Sends the existing `Location` response | Terminates the request immediately |

The facade functions `verify_login`, `is_admin`, `require_admin`, and `redirect`
remain delegation-only wrappers. Login credential verification, rate limiting,
successful-login session regeneration, logout, and CSRF handling remain in
their existing owners and were not moved.

## Catalog module boundary

`includes/catalog.php` has no dependency on `includes/functions.php`. It
requires only `pagination.php` for the existing search and page-size
normalization helpers and accepts `$conn` explicitly for every query.

| Focused function | Read behavior | Side effects and security notes |
|---|---|---|
| `catalog_get_products_page` | Bounded, ordered product page with optional search and low-stock filter | Prepared values; returns `[]` on failure |
| `catalog_count_products` | Product count using the same allow-listed filter fragment | Prepared values; returns `0` on failure |
| `catalog_get_pos_products` | Bounded POS product list or name/barcode search | Prepared values; returns `[]` on failure |
| `catalog_get_pos_product_by_barcode` | Exact barcode lookup | Prepared value; returns `null` on empty, missing, or failed lookup |
| `catalog_get_product_by_id` | Single product lookup for server-side revalidation/selectors | Prepared value; returns `null` for invalid or failed lookup |
| `catalog_get_categories_for_selector` | Bounded category selector list ordered by name and ID | Prepared limit; returns `[]` on failure |
| `catalog_count_categories` | Category count using optional name/description search | Prepared values; returns `0` on failure |
| `catalog_get_categories_page` | Bounded category page with `product_count`, search, and deterministic ordering | Prepared values; returns `[]` on failure |

The legacy names `get_products_page`, `count_products`, `get_pos_products`,
`get_pos_product_by_barcode`, `get_product_by_id`, and
`get_categories_for_selector`, `count_categories`, and `get_categories_page`
remain in `functions.php` as delegation-only compatibility wrappers.
`public/stock_movements.php` now calls the focused Catalog product lookup and
bounded POS selector directly. `get_categories()` remains an unbounded
legacy loader and `get_category_by_id()` remains an uncalled legacy lookup;
neither was moved without a verified caller.

## Category write module boundary

`includes/categories.php` has no dependency on `includes/functions.php`, session
state, or global state. It accepts the database connection and category inputs
explicitly and preserves the existing non-transactional prepared-statement
behavior and boolean return contracts.

| Focused function | Write behavior and return contract |
|---|---|
| `categories_create` | Trims name/description, rejects an empty name, performs the existing duplicate-name lookup, inserts one row with `ss` bindings, requires one affected row, and returns `true` only for that success; validation, duplicate, query, and connection failures return `false`. |
| `categories_update` | Normalizes the ID and text, rejects invalid IDs/names, rejects duplicate names, preserves General-category rename protection, updates with `ssi` bindings, and preserves the existing `true` return after an executed zero-row update for a missing category. |

`public/categories.php` calls the focused create/update/delete services after
its existing CSRF and administrator gates. The legacy
`create_category()`, `update_category()`, and `delete_category()` names remain
delegation-only wrappers in `functions.php`.

## Product module boundary

`includes/products.php` has no dependency on `includes/functions.php`. It
requires `inventory.php` for stock-history integration and safe rollback
diagnostics, and `audit.php` for explicit-actor audit writes. It accepts the
database connection and actor ID explicitly and does not read session or
global state.

| Focused function | Mutation and transaction behavior | Return contract |
|---|---|---|
| `products_create` | Creates a product, applies the General-category fallback and barcode normalization, records initial stock history when needed, writes the success audit, and commits atomically; failures roll back and attempt the failure audit | `bool`; `true` only after commit, otherwise `false` |
| `products_update` | Locks the product row, preserves both image/no-image update paths, applies stock-delta history only when needed, writes the success audit, and commits atomically; failures roll back and attempt the failure audit | `bool`; preserves the existing no-op update behavior and returns `false` on failure |
| `products_delete` | Locks the product row, rejects OrderDetail or StockMovement history, deletes only one eligible product, writes the success audit, and commits atomically; failures roll back and attempt the failure audit | `bool`; `true` only after commit, otherwise `false` |

The focused functions depend on `inventory_log_stock_movement()` and
`inventory_rollback_error()` from `includes/inventory.php`, plus
`audit_log()` from `includes/audit.php`. They preserve the existing
prepared statements, transaction ordering, rollback cleanup, audit metadata,
and generic failure behavior.

## Uploads module boundary

`includes/uploads.php` has no dependency on `includes/functions.php`, session
state, or global state. It accepts the upload value or relative path explicitly
and owns the existing filesystem security boundary under `public/uploads`.

| Focused function | Contract and security behavior |
|---|---|
| `uploads_handle_image` | Validates upload errors, `is_uploaded_file`, MIME/content consistency, image structure, size, dimensions, pixel count, canonical destination, and random filename; returns a relative `uploads/<random-name>.<extension>` path or `false`. |
| `uploads_delete_newly_uploaded_image` | Accepts only the generated relative upload-path shape, resolves the canonical uploads root, rejects traversal, absolute paths, symlinks, and outside-root files, and returns `true` for a missing or successfully deleted safe path and `false` for unsafe or failed cleanup. |

`public/products.php` calls both focused functions directly after its existing
authorization, CSRF, and request-validation gates. The legacy
`handle_image_upload()` and `delete_newly_uploaded_image()` names remain in
`functions.php` as delegation-only compatibility wrappers for remaining
callers and tests. The page remains responsible for deciding when to upload,
when to clean up a newly generated path, and which generic response to show.

The legacy names `create_product()`, `update_product()`, and
`delete_product()` remain in `functions.php` as delegation-only
compatibility wrappers for tests, CLI utilities, and other un-migrated callers.
`public/products.php` now calls the focused services directly while retaining
ownership of request validation, authorization, CSRF checks, upload request
handling and messages, generic HTTP responses, and page rendering. The focused
Uploads module owns filesystem validation, storage, and cleanup.

The legacy `create_order()` name remains in `functions.php` as a
delegation-only compatibility wrapper for remaining callers. `public/orders.php`
calls `orders_create()` directly and continues to own request parsing, CSRF,
page-level purchase authorization, POS validation, generic messages, and
rendering.
Staff administration and other not-yet-extracted workflows remain in
`includes/functions.php` or their existing page controllers. Customer writes
are owned by `includes/customers.php`, and supplier writes are now owned by
`includes/suppliers.php`; the legacy customer and supplier mutation names
remain in the facade only as delegation-only compatibility wrappers.

## Orders module boundary

`includes/orders.php` has no dependency on `includes/functions.php`. It requires
`inventory.php` for direct stock-movement writes and safe rollback diagnostics,
`audit.php` for explicit-actor order audit writes, and `pagination.php` for
bounded page-size normalization. It accepts `$conn` and explicit staff scope
or actor IDs; it does not read session or global state.

| Focused function | Mutation and transaction behavior | Return contract |
|---|---|---|
| `orders_create` | Normalizes duplicate items, validates staff/party/order type, locks products, obtains server-side prices, inserts order/details, updates stock, writes movements and audit, then commits atomically; failures clean up, roll back, log, and attempt the failure audit | `int|false`; returns the created order ID only after commit, otherwise `false` |
| `orders_count` | Applies optional staff scope and all/sale/purchase filtering with a bounded aggregate query | `int`; returns `0` for invalid scope or query failure |
| `orders_get_page` | Applies optional staff scope and type filtering with prepared limit/offset values and deterministic newest-first ordering | `array`; returns `[]` for invalid scope or query failure |
| `orders_get_summary` | Applies optional staff scope and type filtering to aggregate order counts and totals | `array`; returns numeric default fields on invalid scope or query failure |
| `orders_get_by_id` | Loads one order with party/staff aliases and optional staff ownership scope | `?array`; returns `null` for invalid, missing, unauthorized, or failed lookups |
| `orders_get_details` | Loads one order's product details with optional staff ownership scope | `array`; returns `[]` for invalid, missing/unauthorized, or failed lookups |

`orders_create()` calls `inventory_log_stock_movement()` directly and uses
`inventory_rollback_error()` for safe rollback diagnostics. The focused service
preserves sale/purchase movement reasons, totals, role rules, and failure
metadata. The legacy `create_order()` wrapper preserves the existing signature
and delegates without duplicating SQL.

The current `public/order_history.php`, `public/get_order_details.php`, and
`public/print_invoice.php` callers use the five focused read names directly.
The five legacy names remain delegation-only wrappers in `functions.php` for
remaining callers and compatibility tests. `get_orders()` and
`get_orders_for_staff()` remain unbounded legacy loaders and were not extracted.

## Dashboard module boundary

`includes/dashboard.php` has no dependency on `includes/functions.php`. It
accepts the database connection and optional staff scope explicitly, reads no
session or global state, and owns bounded dashboard KPI, chart,
inventory-valuation, top-selling, and category-sales reads.

| Focused function | Read behavior | Return contract |
|---|---|---|
| `dashboard_get_stats` | Global Product count and stock totals; global or optional staff-scoped Order count and sale-only totals | Fixed associative array with `total_products`, `total_orders`, `total_sales`, and `total_stock`; numeric zero defaults on query or connection failure |
| `dashboard_get_chart_data` | Bounded sales/purchase aggregation with complete chronological date labels, optional staff scope, and separate order-type totals | Array of `{label, sales, purchases}` points; days normalize to 1–31 and failed queries preserve the zero-filled requested shape |
| `dashboard_get_inventory_valuation` | `SUM(stock * price)` over current Product rows | Float valuation; returns `0.0` when no rows, the query fails, or the connection is closed |
| `dashboard_get_top_selling_products` | Sale-only Product/OrderDetail aggregation with optional staff scope, normalized limit, and quantity-descending ordering | Array of `name`, `total_qty`, and `total_sales` rows; returns `[]` for explicit statement failures and preserves the existing thrown mysqli failure for a closed connection |
| `dashboard_get_category_sales_distribution` | Sale-only category aggregation through OrderDetail, Order, Product, and optional Category joins; optional staff scope; limit normalized to 25, 50, or 100 | Array of `category_name` and `total_sales` rows ordered by `total_sales DESC`, with `Uncategorized` fallback; global failures and global closed-connection failures return `[]`, while scoped explicit statement failures return `[]` and scoped closed-connection behavior preserves the existing thrown mysqli failure |

`public/index.php` calls `dashboard_get_stats()`, `dashboard_get_chart_data()`,
`dashboard_get_inventory_valuation()`, `dashboard_get_top_selling_products()`,
and `dashboard_get_category_sales_distribution()` directly after its existing
authentication and authorization scope decision.
The legacy `get_dashboard_stats()`, `get_chart_data()`,
`get_inventory_valuation()`, `get_top_selling_products()`, and
`get_category_sales_distribution()` remain
delegation-only compatibility wrappers in `functions.php`; other
dashboard/report reads remain in the facade and were not moved in this batch.

## Inventory module boundary

`includes/inventory.php` has no dependency on `includes/functions.php`. It
requires `pagination.php` for page-size normalization, `security.php` for the
audit source-IP dependency, and `audit.php` for explicit-actor audit writes. It
accepts `$conn` explicitly for each stock-movement operation.

| Focused function | Read behavior | Side effects and security notes |
|---|---|---|
| `inventory_count_stock_movements` | Count all movements or movements for one product | Prepared optional product filter; returns `0` on invalid input or failure |
| `inventory_get_stock_movements_page` | Bounded movement page with optional product filter and deterministic newest-first ordering | Prepared product/limit/offset values; returns `[]` on invalid input or failure |
| `inventory_get_low_stock_products` | Bounded Product read for rows where stock is at or below alert threshold, with optional Category name | Normalizes limit to 25, 50, or 100; returns `p.*` plus `category_name`, ordered by stock/name/id; returns `[]` on query or closed-connection failure |
| `inventory_log_stock_movement` | Inserts one stock-movement history row | Preserves the prepared insert, binding, boolean return, and error-log contract; adds no transaction boundary |
| `inventory_adjust_stock` | Atomic validated manual stock adjustment | Owns row locking, guarded update, movement insert, explicit-actor success/failure audit, commit, rollback, and cleanup; returns `true` only after commit |

`public/stock_movements.php` calls the focused read functions directly for its
read path and delegates validated manual adjustments to
`inventory_adjust_stock()`. The legacy `count_stock_movements` and
`get_stock_movements_page` names remain delegation-only wrappers in
`functions.php` for un-migrated callers. The legacy `log_stock_movement()` name
also remains a delegation-only wrapper for remaining un-migrated callers.
`products_create()`, `products_update()`, and `orders_create()` call
`inventory_log_stock_movement()` directly, and `inventory_adjust_stock()` calls
it directly for manual adjustments.
`get_stock_movements()` remains an unbounded legacy loader because the
inventory-wide caller inventory found no verified runtime caller.
`public/index.php` and `includes/layouts/navbar.php` call
`inventory_get_low_stock_products()` directly. The legacy
`get_low_stock_products()` name remains a delegation-only compatibility wrapper
for any future or un-migrated callers. Inventory valuation now belongs to the
Dashboard module; its legacy name remains only as a compatibility wrapper.

## People module boundary

`includes/people.php` has no dependency on `includes/functions.php`. It
requires only `pagination.php` for the existing search and page-size
normalization helpers and accepts `$conn` explicitly for every query.

| Focused function | Read behavior | Side effects and security notes |
|---|---|---|
| `people_count_customers` | Customer count using optional name, phone, or email search | Prepared values; returns `0` on failure |
| `people_get_customers_page` | Bounded customer page with search and deterministic name/ID ordering | Prepared values; returns `[]` on failure |
| `people_get_customers_for_selector` | Bounded POS customer selector with ID, name, and phone | Prepared limit; returns `[]` on failure |
| `people_count_suppliers` | Supplier count using optional name, phone, or email search | Prepared values; returns `0` on failure |
| `people_get_suppliers_page` | Bounded supplier page with search and deterministic name/ID ordering | Prepared values; returns `[]` on failure |
| `people_get_suppliers_for_selector` | Bounded admin purchase selector with ID, name, and phone | Prepared limit; returns `[]` on failure |

The legacy names `count_customers`, `get_customers_page`,
`get_customers_for_selector`, `count_suppliers`, `get_suppliers_page`, and
`get_suppliers_for_selector` remain in `functions.php` as delegation-only
compatibility wrappers. `get_customers()` and `get_suppliers()` remain
unbounded legacy loaders; `get_customer_by_id()` and `get_supplier_by_id()`
remain uncalled legacy lookups. None was moved without a verified caller.

## Customer mutation module boundary

`includes/customers.php` has no dependency on `includes/functions.php`, session
state, or global state. It requires only `validation.php`, accepts the database
connection and customer inputs explicitly, and uses prepared statements with
statement cleanup on every success and failure path.

| Focused function | Mutation and return contract |
|---|---|
| `customers_create` | Sanitizes the supplied fields, rejects an empty name, inserts one customer, and returns `true` only when `affected_rows === 1`; prepared-statement, connection, and SQL failures return `false`. |
| `customers_update` | Sanitizes the supplied fields, rejects IDs `<= 1` and empty names, and preserves the legacy execute-success contract, including `true` for a missing ID when the update executes successfully. |
| `customers_delete` | Rejects IDs `<= 1`, deletes one customer, and returns `true` only when `affected_rows === 1`; the existing foreign key leaves historical orders intact and sets `Order.customer_id` to `NULL`. |

The protected ID `1` is the Walk-in Customer and cannot be updated or deleted.
The focused services preserve the legacy error logging, boolean failure
contract, prepared bindings, sanitization, and closed-connection behavior.
`public/customers.php` calls the focused services directly after its existing
CSRF and administrator gates. The legacy customer names remain
delegation-only wrappers in `functions.php`. `includes/people.php` remains
read-only.

## Supplier mutation module boundary

`includes/suppliers.php` has no dependency on `includes/functions.php`, session
state, or global state. It requires only `validation.php`, accepts the database
connection and supplier inputs explicitly, and uses prepared statements with
statement cleanup on every success and failure path.

| Focused function | Mutation and return contract |
|---|---|
| `suppliers_create` | Sanitizes the supplied fields, rejects an empty name, inserts one supplier, and returns `true` only when `affected_rows === 1`; prepared-statement, connection, and SQL failures return `false`. |
| `suppliers_update` | Sanitizes the supplied fields, rejects IDs `<= 1` and empty names, and preserves the legacy execute-success contract, including `true` for a missing ID when the update executes successfully. |
| `suppliers_delete` | Rejects IDs `<= 1`, deletes one supplier, and returns `true` only when `affected_rows === 1`; the existing foreign key leaves historical purchase orders intact and sets `Order.supplier_id` to `NULL`. |

The protected ID `1` is the General Supplier and cannot be updated or deleted.
The focused services preserve the legacy error logging, boolean failure
contract, prepared bindings, sanitization, and closed-connection behavior.
`public/suppliers.php` calls the focused services directly after its existing
CSRF and administrator gates. The legacy supplier names remain
delegation-only wrappers in `functions.php`. `includes/people.php` remains
read-only, and `get_supplier_by_id()` remains in the facade because no verified
focused caller requires moving it.

## Read-only functions

These functions are intended to read or normalize data. They still may log
errors and may depend on `$conn` or session scope.

### Shared normalization and policy reads

- `sanitize_input`, `sanitize_email`, `sanitize_phone`, `sanitize_id`
- `password_meets_policy`, `normalize_login_identifier`
- `build_login_rate_limit_key`, `build_product_filter_sql`
- `normalize_page_number`, `normalize_page_size`, `truncate_list_search`
- Export, production-preflight, release-integrity, and supply-chain validators

### Catalog and inventory reads

- `get_all_products` (legacy unbounded loader, still in `functions.php`)
- `catalog_get_pos_products` (`get_pos_products` compatibility wrapper)
- `catalog_get_pos_product_by_barcode` (`get_pos_product_by_barcode` compatibility wrapper)
- `catalog_count_products` (`count_products` compatibility wrapper)
- `catalog_get_products_page` (`get_products_page` compatibility wrapper)
- `catalog_get_product_by_id` (`get_product_by_id` compatibility wrapper)
- `get_stock_movements` (legacy unbounded loader)
- `inventory_count_stock_movements` (`count_stock_movements` compatibility wrapper)
- `inventory_get_stock_movements_page` (`get_stock_movements_page` compatibility wrapper)
- `inventory_get_low_stock_products` (`get_low_stock_products` compatibility wrapper)

### Order and reporting reads

- `orders_count` (`count_orders` compatibility wrapper)
- `orders_get_page` (`get_orders_page` compatibility wrapper)
- `orders_get_summary` (`get_order_summary` compatibility wrapper)
- `orders_get_by_id` (`get_order_by_id` compatibility wrapper)
- `orders_get_details` (`get_order_details` compatibility wrapper)
- `get_orders` and `get_orders_for_staff` (legacy unbounded loaders)
- `dashboard_get_stats` (`get_dashboard_stats` compatibility wrapper)
- `dashboard_get_chart_data` (`get_chart_data` compatibility wrapper)
- `dashboard_get_inventory_valuation` (`get_inventory_valuation` compatibility wrapper)
- `dashboard_get_top_selling_products` (`get_top_selling_products` compatibility wrapper)
- `dashboard_get_category_sales_distribution` (`get_category_sales_distribution` compatibility wrapper)

### Reference and staff reads

- `get_staff_members`
- `get_categories` (legacy unbounded loader),
  `catalog_count_categories` (`count_categories` compatibility wrapper),
  `catalog_get_categories_page` (`get_categories_page` compatibility wrapper),
  `catalog_get_categories_for_selector` (`get_categories_for_selector` compatibility wrapper),
  `get_category_by_id` (uncalled legacy lookup)
- `get_customers` (legacy unbounded loader),
  `people_count_customers` (`count_customers` compatibility wrapper),
  `people_get_customers_page` (`get_customers_page` compatibility wrapper),
  `people_get_customers_for_selector` (`get_customers_for_selector`
  compatibility wrapper), `get_customer_by_id` (uncalled legacy lookup)
- `get_suppliers` (legacy unbounded loader),
  `people_count_suppliers` (`count_suppliers` compatibility wrapper),
  `people_get_suppliers_page` (`get_suppliers_page` compatibility wrapper),
  `people_get_suppliers_for_selector` (`get_suppliers_for_selector`
  compatibility wrapper), `get_supplier_by_id` (uncalled legacy lookup)

## Mutating functions

| Function | Mutation and side effects |
|---|---|
| `login_rate_limit_cleanup_expired` | Deletes expired rate-limit rows. |
| `login_rate_limit_record_failure` | Inserts or updates rate-limit state. |
| `login_rate_limit_reset` | Deletes/reset rate-limit state after successful login. |
| `products_create` | Focused atomic product creation service; records initial stock history and success/failure audit events. |
| `products_update` | Focused atomic product update service; records non-zero stock deltas and success/failure audit events. |
| `products_delete` | Focused atomic product deletion service; rejects historical order/stock use and records success/failure audit events. |
| `create_product`, `update_product`, `delete_product` | Delegation-only compatibility wrappers for the focused Product module. |
| `inventory_log_stock_movement` | Focused stock-movement insert used directly by product, order, and manual-adjustment services. |
| `log_stock_movement` | Delegation-only compatibility wrapper for remaining callers. |
| `orders_create` | Creates orders/details, updates stock, logs movement/audit, and commits a transaction. |
| `create_order` | Delegation-only compatibility wrapper for `orders_create`. |
| `orders_count`, `orders_get_page`, `orders_get_summary`, `orders_get_by_id`, `orders_get_details` | Focused bounded and scoped order reads. |
| `count_orders`, `get_orders_page`, `get_order_summary`, `get_order_by_id`, `get_order_details` | Delegation-only compatibility wrappers for focused order reads. |
| `create_staff_member` | Inserts a staff account with a password hash. |
| `update_staff_member` | Updates staff identity, role, active/password state subject to policy. |
| `delete_staff_member` | Deactivates staff subject to account-integrity rules. |
| `set_staff_active` | Enables/disables staff subject to admin-integrity rules. |
| `categories_create` | Focused category creation service with trim, duplicate-name, prepared insert, and affected-row contracts. |
| `categories_update` | Focused category update service with duplicate-name and General-category protection. |
| `categories_delete` | Focused category deletion transaction with General verification, product reassignment, rollback, and boolean failure behavior. |
| `create_category`, `update_category`, `delete_category` | Delegation-only compatibility wrappers for the focused Category module. |
| `customers_create` | Focused customer creation service with sanitization, prepared insert, and exact affected-row success contract. |
| `customers_update` | Focused customer update service with protected-ID/name validation and preserved execute-success behavior. |
| `customers_delete` | Focused customer deletion service with protected-ID validation and exact affected-row success contract. |
| `create_customer`, `update_customer`, `delete_customer` | Delegation-only compatibility wrappers for the focused Customer module. |
| `suppliers_create` | Focused supplier creation service with sanitization, prepared insert, and exact affected-row success contract. |
| `suppliers_update` | Focused supplier update service with protected-ID/name validation and preserved execute-success behavior. |
| `suppliers_delete` | Focused supplier deletion service with protected-ID validation and exact affected-row success contract. |
| `create_supplier`, `update_supplier`, `delete_supplier` | Delegation-only compatibility wrappers for the focused Supplier module. |
| `uploads_handle_image` | Validates and writes a safe image to the canonical `public/uploads` directory. |
| `uploads_delete_newly_uploaded_image` | Deletes a safe current-request upload path and preserves the outside-root/traversal protections. |
| `handle_image_upload`, `delete_newly_uploaded_image` | Delegation-only compatibility wrappers for the focused Uploads module. |
| `audit_log`, `audit_log_current_actor`, `audit_log_denied` | Write audit events. |
| `stream_database_backup` | Reads a consistent snapshot and writes SQL to the caller's output stream; it does not mutate application data but has operational side effects. |

## Security-sensitive functions

- Session and request security: `initialize_request_context`,
  `start_secure_session`, `destroy_current_session`, `is_https_request`,
  `send_hsts_header`, `send_security_headers`.
- CSRF: `generate_csrf_token`, `verify_csrf_token`.
- Authentication: `auth_verify_login` (through the `verify_login` wrapper), all
  `login_rate_limit_*` functions, and `get_login_source_ip`.
- Authorization: `auth_is_admin`, `auth_require_admin` (through legacy
  wrappers), scoped order lookups, and
  staff-integrity mutation functions.
- Data protection: `uploads_handle_image`, `uploads_delete_newly_uploaded_image`,
  `export_csv_text`, `export_validate_entity`, `quote_backup_table`,
  `stream_database_backup`.
- Audit: all functions in `includes/audit.php`.

## Transaction participants

### Critical business transaction

`orders_create()` at `includes/orders.php`:

- Validates order type and staff role.
- Validates customer/supplier relationship.
- Starts a transaction.
- Locks product rows with `FOR UPDATE`.
- Recalculates prices and stock effects server-side.
- Inserts order/detail rows.
- Updates product stock.
- Writes stock movement and audit events.
- Commits or rolls back on failure.

The implementation was moved only after characterization tests covered all of
these invariants. `public/orders.php` now calls the focused service directly;
the compatibility wrapper remains available for un-migrated callers and tests.

### Other transaction participants

- `includes/inventory.php`: atomic manual stock adjustment; `public/stock_movements.php`
  retains request validation, CSRF, authorization, generic responses, and
  rendering around the service call.
- `public/settings.php:113-195`: profile update transaction.
- `includes/backup.php:88-180`: read-only consistent snapshot transaction.
- Login rate-limit helpers: transaction helpers around rate-limit state changes.

## Safe remaining extraction candidates

Dashboard KPI aggregation, chart data, inventory valuation, top-selling
products, and category sales distribution are extracted and characterized in
`includes/dashboard.php`; low-stock reads are extracted and characterized in
`includes/inventory.php`. Other dashboard/report functions remain in the facade
and require separate caller and contract audits before any move.

Batch 6A moved only active-session verification, the administrator role check,
the administrator denial path, and redirect implementation behind wrappers. It
did not move login credential verification, rate limiting, CSRF, login/logout
request handling, staff writes, or any business mutation.

## Functions that must not move early

- Staff deletion/status functions because they protect the last active admin
  and self-deactivation invariants.
- Upload helpers now live in `includes/uploads.php`; changes to
  `uploads_handle_image()` or `uploads_delete_newly_uploaded_image()` must
  preserve their path, MIME, dimension, canonical-root, and cleanup security
  contracts. The legacy names are wrappers only.
- `stream_database_backup()` because snapshot consistency, table allow-listing,
  sensitive data handling, and completion markers are operational guarantees.
- Login credential verification and rate-limit functions until their request,
  audit, timing, and persistence contracts are covered independently.
