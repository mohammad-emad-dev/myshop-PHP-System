# MyShop response and side-effect contract inventory

This inventory records current return and control-flow behavior. Future
modules must preserve these contracts until callers are migrated deliberately.

Batch 2 places the focused Catalog read implementations in
`includes/catalog.php`. The legacy names listed below remain available from
`includes/functions.php` as compatibility wrappers, so existing scripts and
unmigrated pages retain the same return and failure contracts.

## Array-returning functions

An empty array may mean “no rows” or “database failure” for legacy functions;
callers must not assume the distinction is currently available.

### Catalog and inventory

- `get_all_products()` — complete product array; legacy unbounded query.
- `get_pos_products()` — bounded product array.
- `get_stock_movements()` — complete movement array; legacy unbounded query.
- `get_stock_movements_page()` — bounded movement page.
- `get_low_stock_products()` — bounded low-stock array.
- `get_products_page()` — bounded product page.
- `get_categories()` and `get_categories_page()` — category arrays.
- `get_customers()` and `get_customers_page()` — customer arrays.
- `get_suppliers()` and `get_suppliers_page()` — supplier arrays.
- Selector functions — bounded arrays.

### Orders and reporting

- `get_orders()` — complete order array; legacy unbounded query.
- `get_orders_for_staff()` — scoped order array.
- `get_orders_page()` — bounded order page.
- `get_order_details()` — order-detail array; unauthorized or failure cases
  return an empty array.
- `get_order_summary()` — summary associative array with numeric defaults on
  failure.
- `get_dashboard_stats()` — default statistics array on query failure.
- `get_chart_data()` — padded chart array with zero values on failure.
- `get_top_selling_products()` and `get_category_sales_distribution()` —
  report arrays.

### Staff and audit

- `get_staff_members()` — bounded staff array.
- `get_audit_logs_page()` — bounded audit array.
- `get_backup_table_allowlist()` and `export_report_definitions()` — static
  configuration arrays.

## Null-returning functions

- `get_product_by_id()` — `null` for invalid IDs, missing records, or database
  failure.
- `get_pos_product_by_barcode()` — `null` for empty/missing barcode or failure.
- `get_category_by_id()` — `null` for missing or failed lookup.
- `get_customer_by_id()` — `null` for missing or failed lookup.
- `get_supplier_by_id()` — `null` for missing or failed lookup.
- `get_order_by_id()` — `null` for missing, unauthorized, or failed lookup.
- `get_authenticated_staff_id()` — `null` when no valid session identity is
  available.
- `quote_backup_table()` — `null` for a table outside the allow-list.
- `get_asset_integrity()` — `null` for local or unsupported assets.

## False-returning functions

False is used for validation failures, authorization failures, mutation
failures, and some infrastructure failures.

- `password_meets_policy()` and normalization/validation helpers where input
  is invalid.
- `get_login_source_ip()` when the peer address is unavailable or invalid.
- `login_rate_limit_*()` mutation/check helpers on infrastructure failure.
- `verify_login(false)` when the current session is not authenticated or no
  longer represents an active allowed staff record.
- `create_product()`, `update_product()`, and `delete_product()` on rejected or
  failed mutations.
- `log_stock_movement()` on rejected or failed history writes.
- `create_order()` on any validation, authorization, transaction, stock,
  audit, or database failure.
- Staff, category, customer, and supplier mutation functions on rejected or
  failed mutations.
- `handle_image_upload()` and `delete_newly_uploaded_image()` on invalid input,
  unsafe paths, failed validation, or filesystem failure.
- `audit_log()` and related helpers when an audit write cannot be completed.

## Zero-returning functions

Count and numeric functions use zero for empty results and often also for
database failure:

- `count_products()`
- `count_stock_movements()`
- `count_orders()`
- `count_categories()`
- `count_customers()`
- `count_suppliers()`
- `count_audit_logs()`

This is a known compatibility contract. It should not be changed while
extracting modules unless callers are migrated and tested together.

## Default-value returns

- `get_dashboard_stats()` returns zeroed KPI values when one or more queries
  fail.
- `get_chart_data()` returns a complete date range padded with zero values when
  the query fails.
- `get_order_summary()` returns default numeric summary fields on failure.
- Paginated readers return empty arrays when the query fails.

These defaults are user-facing behavior and must not silently change during a
refactor.

## Functions that throw

The focused streaming/operational modules use exceptions as internal failure
signals:

- `includes/export.php`: binding, result binding, and output-stream failures
  may throw `RuntimeException`; `export_csv_fail()` converts failures to a
  generic HTTP response where appropriate.
- `includes/backup.php`: invalid output/table configuration, query/stream
  failures, and transaction failures may throw `RuntimeException`; the caller
  logs safely and emits a generic failure response.
- `scripts/production-preflight.php`, supply-chain, release-integrity, and
  scanner helpers throw or return CLI failures according to their script
  contracts.

Most legacy functions in `includes/functions.php` catch database exceptions and
convert them to the return values listed above.

## Redirecting or terminating functions

- `redirect()` sends a `Location` header and terminates execution.
- `verify_login()` may redirect when called with its default argument.
- `require_admin()` sends the current authorization failure response and may
  terminate the request.
- `config/db.php` terminates on unavailable required configuration or database
  connection. Readiness mode returns HTTP 503 JSON with the exact generic body
  `{"status":"not_ready","check":"database"}`.
- `export_csv_fail()` terminates the export response with a generic error.
- Backup request helpers terminate before or after streaming depending on the
  point of failure.

## Audit-event writers

Audit writes occur through:

- `audit_log()`
- `audit_log_current_actor()`
- `audit_log_denied()`
- Page-level calls in login, product, stock, order, settings, backup, and
  authorization-denial paths.

Audit metadata is sanitized in `includes/audit.php` and must not gain access to
passwords, cookies, CSRF tokens, request bodies, or database credentials.

## Session-mutating functions

- `start_secure_session()` initializes/refreshes idle-session state.
- `generate_csrf_token()` creates the session CSRF token.
- `verify_csrf_token()` reads session CSRF state.
- `destroy_current_session()` clears session data and expires the cookie.
- `verify_login()` writes authenticated staff identity, role, last activity, and
  the current staff global record.
- `public/login.php` writes login session fields directly after successful
  authentication.
- `public/settings.php` updates `$_SESSION['full_name']` after a profile update.

## Side-effect inventory

| Side effect | Current owner |
|---|---|
| HTTP headers | Security helpers, redirect/auth helpers, export, backup, endpoints |
| Process termination | Database failure closure, redirect, admin/auth failures, export/backup failures |
| Session writes | `security.php`, `functions.php:verify_login`, login/settings pages |
| Database writes | Rate limiting, audit, catalog, inventory, orders, staff, categories, customers, suppliers |
| Filesystem writes | `handle_image_upload`, backup/export output streams, test/runner temporary files |
| Server logs | `log_application_error`, `error_log` calls, audit/operational logging |
| Browser output | All public page templates, JSON endpoints, CSV and SQL stream endpoints |
