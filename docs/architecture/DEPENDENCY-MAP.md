# MyShop dependency map

This map records current call-site and dependency relationships after the
Batch 8A order-creation service extraction. It remains a
characterization artifact; the compatibility wrappers are still required.

## Public-page to shared-function map

The following map is derived from the current tracked PHP source. Function
names are grouped only for readability; the source remains the authority.

| Public page | Current shared calls |
|---|---|
| `audit_log.php` | `start_secure_session`, `verify_login`, `require_admin`, `normalize_page_number`, `normalize_page_size`, `truncate_list_search`, `count_audit_logs`, `get_audit_logs_page` |
| `backup_database.php` | `start_secure_session`, `verify_login`, `is_admin`, `verify_csrf_token`, `audit_log`, `get_backup_table_allowlist`, `quote_backup_table`, `stream_database_backup` |
| `categories.php` | `start_secure_session`, `verify_login`, `is_admin`, `require_admin`, `verify_csrf_token`, `generate_csrf_token`, `sanitize_input`, `normalize_page_number`, `normalize_page_size`, `truncate_list_search`, `create_category`, `update_category`, `delete_category`, `catalog_count_categories`, `catalog_get_categories_page`, `audit_log_current_actor`, `audit_log_denied` |
| `customers.php` | `start_secure_session`, `verify_login`, `is_admin`, `verify_csrf_token`, `generate_csrf_token`, `sanitize_input`, `normalize_page_number`, `normalize_page_size`, `truncate_list_search`, `create_customer`, `update_customer`, `delete_customer`, `people_count_customers`, `people_get_customers_page`, `audit_log_current_actor`, `audit_log_denied` |
| `export_report.php` | `start_secure_session`, `verify_login`, `require_admin`, `export_report_definitions`, `export_validate_entity`, `export_validate_order_filters`, `export_csv_text`, `export_csv_write_row`, `export_csv_fail`, `export_stream_entity` |
| `get_order_details.php` | `start_secure_session`, `verify_login`, `is_admin`, `orders_get_by_id`, `orders_get_details`, `audit_log_current_actor` |
| `health.php` | `initialize_request_context` |
| `index.php` | `start_secure_session`, `verify_login`, `is_admin`, `get_dashboard_stats`, `get_chart_data`, `get_inventory_valuation`, `get_top_selling_products`, `get_category_sales_distribution`, `get_low_stock_products` |
| `login.php` | `start_secure_session`, `send_security_headers`, `verify_csrf_token`, `get_login_source_ip`, `build_login_rate_limit_key`, `login_rate_limit_check`, `login_rate_limit_record_failure`, `login_rate_limit_reset`, `audit_log`, `audit_log_current_actor`, `destroy_current_session`, `generate_csrf_token`, `redirect`, `verify_login`, `get_asset_integrity` |
| `order_history.php` | `start_secure_session`, `verify_login`, `is_admin`, `sanitize_input`, `normalize_page_number`, `normalize_page_size`, `orders_count`, `orders_get_summary`, `orders_get_page` |
| `orders.php` | `start_secure_session`, `verify_login`, `is_admin`, `verify_csrf_token`, `generate_csrf_token`, `truncate_list_search`, `catalog_get_pos_products`, `catalog_get_categories_for_selector`, `people_get_customers_for_selector`, `people_get_suppliers_for_selector`, `catalog_get_product_by_id`, `orders_create`, `audit_log_current_actor`, `audit_log_denied` |
| `pos_product_lookup.php` | `start_secure_session`, `verify_login`, `truncate_list_search`, `catalog_get_pos_product_by_barcode` |
| `print_invoice.php` | `start_secure_session`, `send_security_headers`, `verify_login`, `is_admin`, `sanitize_id`, `orders_get_by_id`, `orders_get_details`, `audit_log_current_actor` |
| `products.php` | `start_secure_session`, `verify_login`, `is_admin`, `verify_csrf_token`, `generate_csrf_token`, `sanitize_input`, `normalize_page_number`, `normalize_page_size`, `truncate_list_search`, `catalog_get_categories_for_selector`, `catalog_get_products_page`, `catalog_count_products`, `products_create`, `products_update`, `products_delete`, `handle_image_upload`, `delete_newly_uploaded_image`, `audit_log_current_actor`, `audit_log_denied` |
| `ready.php` | `initialize_request_context`, `log_application_error`; `config/db.php` performs the connection and readiness failure contract |
| `settings.php` | `start_secure_session`, `verify_login`, `is_admin`, `require_admin`, `verify_csrf_token`, `generate_csrf_token`, `sanitize_input`, `password_meets_policy`, `create_staff_member`, `update_staff_member`, `delete_staff_member`, `set_staff_active`, `get_staff_members`, `audit_log_current_actor` |
| `stock_movements.php` | `start_secure_session`, `verify_login`, `is_admin`, `verify_csrf_token`, `sanitize_input`, `normalize_page_number`, `normalize_page_size`, `get_pos_products`, `get_product_by_id`, `inventory_adjust_stock`, `inventory_get_stock_movements_page`, `inventory_count_stock_movements`, `audit_log_current_actor`, `audit_log_denied` |
| `suppliers.php` | `start_secure_session`, `verify_login`, `is_admin`, `verify_csrf_token`, `generate_csrf_token`, `sanitize_input`, `normalize_page_number`, `normalize_page_size`, `truncate_list_search`, `create_supplier`, `update_supplier`, `delete_supplier`, `people_count_suppliers`, `people_get_suppliers_page`, `audit_log_current_actor`, `audit_log_denied` |

## Global and implicit dependencies

| Dependency | Current consumers and behavior |
|---|---|
| `$conn` | Most database functions accept it explicitly. Auth implementations accept it explicitly; legacy `verify_login()`/`is_admin()`/`require_admin()` wrappers, backup request helpers, and some page code retain the global or page-variable contract. |
| `$_SESSION` | `security.php` manages lifecycle; login, logout, authorization, page scoping, CSRF, and feedback state read/write session values. |
| `$GLOBALS['current_staff_record']` | `auth_verify_login()` writes it; `auth_is_admin()` reads it; `destroy_current_session()` clears it. Legacy wrappers preserve access to this contract. |
| `$GLOBALS['request_correlation_id']` | `initialize_request_context()` owns it and shutdown logging reads it. |
| `$GLOBALS['csp_nonce']` | `get_csp_nonce()` and layouts use it for inline CSP nonces. |
| `$_SERVER` | Request method, URI, peer address, HTTPS state, and forwarded protocol are used by security and page controllers. |
| Filesystem | `handle_image_upload()` and `delete_newly_uploaded_image()` use `public/uploads`; backup and export use output streams. |
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
Unmigrated callers, including `public/stock_movements.php` for product reads,
continue to use those wrappers. `get_categories()` remains an unbounded
legacy loader and `get_category_by_id()` remains an uncalled legacy lookup;
neither was moved without a verified caller.

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

The legacy names `create_product()`, `update_product()`, and
`delete_product()` remain in `functions.php` as delegation-only
compatibility wrappers for tests, CLI utilities, and other un-migrated callers.
`public/products.php` now calls the focused services directly while retaining
ownership of request validation, authorization, CSRF checks, upload handling,
generic messages, HTTP responses, and page rendering.

The legacy `create_order()` name remains in `functions.php` as a
delegation-only compatibility wrapper for remaining callers. `public/orders.php`
calls `orders_create()` directly and continues to own request parsing, CSRF,
page-level purchase authorization, POS validation, generic messages, and
rendering.
Staff administration, category/customer/supplier writes, and other
not-yet-extracted workflows remain in `includes/functions.php` or their existing
page controllers.

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

## Inventory module boundary

`includes/inventory.php` has no dependency on `includes/functions.php`. It
requires `pagination.php` for page-size normalization, `security.php` for the
audit source-IP dependency, and `audit.php` for explicit-actor audit writes. It
accepts `$conn` explicitly for each stock-movement operation.

| Focused function | Read behavior | Side effects and security notes |
|---|---|---|
| `inventory_count_stock_movements` | Count all movements or movements for one product | Prepared optional product filter; returns `0` on invalid input or failure |
| `inventory_get_stock_movements_page` | Bounded movement page with optional product filter and deterministic newest-first ordering | Prepared product/limit/offset values; returns `[]` on invalid input or failure |
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
`get_low_stock_products()` and `get_inventory_valuation()` remain in the facade
for the dashboard and navbar callers.

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
- `get_low_stock_products`
- `get_stock_movements` (legacy unbounded loader)
- `inventory_count_stock_movements` (`count_stock_movements` compatibility wrapper)
- `inventory_get_stock_movements_page` (`get_stock_movements_page` compatibility wrapper)

### Order and reporting reads

- `orders_count` (`count_orders` compatibility wrapper)
- `orders_get_page` (`get_orders_page` compatibility wrapper)
- `orders_get_summary` (`get_order_summary` compatibility wrapper)
- `orders_get_by_id` (`get_order_by_id` compatibility wrapper)
- `orders_get_details` (`get_order_details` compatibility wrapper)
- `get_orders` and `get_orders_for_staff` (legacy unbounded loaders)
- `get_dashboard_stats`
- `get_chart_data`
- `get_inventory_valuation`
- `get_top_selling_products`
- `get_category_sales_distribution`

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
| `create_category`, `update_category`, `delete_category` | Mutate category/reference data. |
| `create_customer`, `update_customer`, `delete_customer` | Mutate customer data. |
| `create_supplier`, `update_supplier`, `delete_supplier` | Mutate supplier data. |
| `handle_image_upload` | Writes a validated image to `public/uploads`. |
| `delete_newly_uploaded_image` | Deletes a validated current-request upload path. |
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
- Data protection: `handle_image_upload`, `delete_newly_uploaded_image`,
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

The safest remaining candidate is the dashboard read model after its current
query and default-value behavior is characterized.

Batch 6A moved only active-session verification, the administrator role check,
the administrator denial path, and redirect implementation behind wrappers. It
did not move login credential verification, rate limiting, CSRF, login/logout
request handling, staff writes, or any business mutation.

## Functions that must not move early

- Staff deletion/status functions because they protect the last active admin
  and self-deactivation invariants.
- `handle_image_upload()` because its path, MIME, dimension, and filesystem
  guarantees are security boundaries.
- `stream_database_backup()` because snapshot consistency, table allow-listing,
  sensitive data handling, and completion markers are operational guarantees.
- Login credential verification and rate-limit functions until their request,
  audit, timing, and persistence contracts are covered independently.
