# MyShop architecture baseline

Status: Phase 4F final compatibility facade and architecture closure baseline

Captured from the `security-hardening-baseline` branch at starting revision
`70d1ad64a5c639b93897c1c1abd2ab28063cef90`.

This document describes the current implementation. It is intentionally not a
target architecture and does not authorize production-code refactoring.

## Evidence and scope

The baseline covers the tracked PHP application, database files, Docker and
Apache configuration, GitHub Actions workflow, JavaScript, CSS, tests, browser
QA, scripts, and deployment documentation.

The repository is a server-rendered PHP/MySQL application. It has no PHP
framework, Composer dependency graph, PHP autoloader, or JavaScript build step.
Browser QA is intentionally separate from the dependency-free PHP test harness.

## Current request lifecycle

1. Apache serves `/var/www/html/public` as the document root. The repository
   root, `config/`, `database/`, and environment files are outside the public
   document root.
2. `public/.htaccess` disables directory indexes, adds baseline response
   headers, and keeps uploaded files from executing server-side scripts.
3. A page normally requires `includes/functions.php`, starts a secure session,
   loads `config/db.php`, and then performs request-specific work.
4. `includes/functions.php` loads `security.php`, `pagination.php`, `audit.php`,
   `http.php`, `auth.php`, `validation.php`, `catalog.php`, `people.php`,
   `inventory.php`, `products.php`, `orders.php`, `uploads.php`,
   `categories.php`, `customers.php`, and `suppliers.php` as a compatibility facade. Catalog, People, and stock-movement page
   callers may use their focused read functions directly; product mutations are
   owned by `includes/products.php`, category creation/update by
   `includes/categories.php`, including category deletion, and order creation plus bounded/single-record
   order reads by `includes/orders.php`; customer mutations are owned by
   `includes/customers.php` and supplier mutations are owned by
   `includes/suppliers.php`; legacy names remain available as
   compatibility wrappers.
5. `config/db.php` reads `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and
   `DB_PASSWORD` from the process environment and creates the mysqli
   connection. It does not run schema creation or migrations.
6. Authenticated pages call `verify_login()`. Admin-only operations call
   `is_admin()` or `require_admin()` in addition to route-specific policy.
7. Pages perform request validation and call shared functions. Many pages then
   render their own HTML and inline JavaScript in the same file.
8. Shared layouts render the navigation and page shell. The header sends CSP
   and other security headers; the footer loads JavaScript assets.
9. Failures are converted to generic user-facing messages while technical
   details are logged server-side.

Special endpoints:

- `public/health.php` is liveness-only and does not load the database.
- `public/ready.php` loads the database and performs `SELECT 1`.
- `public/get_order_details.php` returns scoped JSON order data.
- `public/pos_product_lookup.php` returns a barcode lookup response.
- `public/export_report.php` streams CSV data.
- `public/backup_database.php` streams a protected SQL backup.

## Authentication and session lifecycle

`includes/security.php:start_secure_session()` establishes an HTTP-only,
same-site session, disables URL session IDs, enables strict session mode, and
applies an idle timeout. The `Secure` cookie flag depends on direct HTTPS or a
trusted reverse proxy.

`public/login.php` owns the login request flow:

- CSRF is checked before login or logout processing.
- The source IP is derived from the direct peer unless a trusted proxy policy
  is configured.
- `login_rate_limit_*()` functions enforce account/IP rate limiting.
- The active staff record is read from MySQL and verified with
  `password_verify()`.
- A successful login regenerates the session ID, stores the staff identity and
  role, refreshes the CSRF token, and writes an audit event.
- Logout is a POST action that verifies CSRF and destroys the session.

`includes/auth.php:auth_verify_login()` re-checks the current active staff
record from the database and stores it in `$GLOBALS['current_staff_record']`.
`auth_is_admin()` depends on that global record and may call
`auth_verify_login(..., false)`. The public names `verify_login()`, `is_admin()`,
and `require_admin()` remain thin wrappers in `includes/functions.php`, so page
callers and the current global database/session contracts remain unchanged.

## Authorization boundaries

Authorization is server-side. Navigation visibility is only a presentation
convenience and is not the security boundary.

| Boundary | Current implementation |
|---|---|
| Authentication | `auth_verify_login()` in `includes/auth.php`; `verify_login()` compatibility wrapper |
| Admin role check | `auth_is_admin()` and `auth_require_admin()` in `includes/auth.php`; legacy wrappers retained |
| Admin audit log | `public/audit_log.php` requires authenticated admin access |
| CSV export | `public/export_report.php` requires admin access |
| Categories | `public/categories.php` protects category administration with admin checks |
| Staff management | `public/settings.php` protects staff mutations with `require_admin()` |
| Database backup | `public/backup_database.php` requires active admin, CSRF, and current-password reauthentication |
| Purchase orders | `public/orders.php:27-35` rejects purchase creation by cashiers |
| Order history | `orders_count()`, `orders_get_page()`, and `orders_get_summary()` apply staff scope for non-admin users |
| Order details/invoices | `orders_get_by_id()` and `orders_get_details()` apply optional staff scope |
| Product and stock mutations | Page-level role checks plus shared audit and transaction logic |

The exact status code and redirect behavior of each route is part of the
refactoring contract in `REFACTORING-CONTRACT.md`.

## Domain responsibilities in `includes/functions.php`

`includes/functions.php` is currently both a compatibility facade and the main
application service module.

| Lines/functions | Responsibility |
|---|---|
| `:12-70` | Pure identifiers, password policy, login normalization, and login-rate-limit helpers; reusable sanitization now lives in `includes/validation.php` |
| `:72-480` | Login rate-limit state, transaction helpers, and cleanup |
| `verify_login()`, `redirect()` | Delegation-only Auth and HTTP compatibility wrappers |
| `build_product_filter_sql()` | Catalog filter compatibility wrapper |
| `get_all_products()` and Catalog compatibility wrappers | Legacy full product read plus delegation to `includes/catalog.php` |
| `get_low_stock_products()`, stock movement reads, `log_stock_movement()` | Delegation-only low-stock and bounded stock-movement compatibility wrappers; the legacy unbounded movement loader remains in the facade |
| `create_product()`, `update_product()`, `delete_product()` | Delegation-only compatibility wrappers to `includes/products.php`; upload request dispatch remains page-owned while filesystem validation/storage/cleanup is owned by `includes/uploads.php` |
| `create_category()`, `update_category()`, `delete_category()` | Delegation-only compatibility wrappers to `includes/categories.php` |
| `create_customer()`, `update_customer()`, `delete_customer()` | Delegation-only compatibility wrappers to `includes/customers.php` |
| `create_supplier()`, `update_supplier()`, `delete_supplier()` | Delegation-only compatibility wrappers to `includes/suppliers.php` |
| `create_order()` | Delegation-only compatibility wrapper to `includes/orders.php`; the wrapper preserves the existing order-ID-or-false return contract |
| `orders_count()`, `orders_get_page()`, `orders_get_summary()`, `orders_get_by_id()`, `orders_get_details()` | Focused bounded and single-record order reads; the legacy names remain delegation-only wrappers for remaining callers |
| `get_dashboard_stats()`, `get_chart_data()`, `get_inventory_valuation()`, `get_top_selling_products()`, `get_category_sales_distribution()` | Delegation-only compatibility wrappers to `includes/dashboard.php`; remaining report functions remain in the facade |
| Role checks and staff administration functions | Authorization and staff administration |
| Category read/mutation functions | Category reads remain in Catalog compatibility wrappers; category create/update/delete are delegated to `includes/categories.php` |
| Customer read/mutation functions | Customer reads remain in People compatibility wrappers; customer create/update/delete are delegated to `includes/customers.php` |
| Supplier read/mutation functions | Supplier reads remain in People compatibility wrappers; supplier create/update/delete are delegated to `includes/suppliers.php` |
| Remaining dashboard report functions | Other dashboard report functions not yet extracted |

Focused shared modules already extracted from the facade:

- `includes/security.php`: sessions, CSRF, CSP, HSTS, request IDs, proxy
  handling, source-IP handling, and asset integrity metadata.
- `includes/pagination.php`: page, page-size, and search normalization.
- `includes/audit.php`: audit metadata, writes, filters, and pagination.
- `includes/export.php`: allow-listed, bounded CSV streaming.
- `includes/backup.php`: allow-listed, snapshot-based SQL backup streaming.
- `includes/catalog.php`: read-only product, POS, barcode, product-page, and
  category-selector, category-count, and category-page queries. The legacy
  public function names remain thin wrappers in `functions.php`.
- `includes/people.php`: read-only bounded customer and supplier count, page,
  and selector queries. The legacy public function names remain thin wrappers
  in `functions.php`.
- `includes/validation.php`: pure reusable input sanitization helpers shared by
  focused services and the compatibility facade; it has no database, session,
  or global-state dependency.
- `includes/inventory.php`: bounded low-stock and stock-movement reads, movement
  writing, and the atomic manual stock-adjustment service. The low-stock and
  bounded movement names remain available as compatibility wrappers; the
  bounded movement wrappers continue to support callers not yet migrated.
- `includes/products.php`: explicit product creation, update, and deletion
  transactions, product stock-history integration, and product audit writes.
  The legacy `create_product()`, `update_product()`, and `delete_product()`
  names remain delegation-only wrappers in `functions.php`.
- `includes/orders.php`: explicit atomic sale/purchase order creation, including
  staff and party validation, product row locks, server-side price authority,
  stock and movement mutations, order audit writes, commit, rollback, and safe
  rollback diagnostics. The legacy `create_order()` name remains a
  delegation-only wrapper in `functions.php`.
- The same module owns `orders_count()`, `orders_get_page()`,
  `orders_get_summary()`, `orders_get_by_id()`, and `orders_get_details()` for
  bounded and scoped order reads. Their legacy names remain delegation-only
  wrappers; the order-history, order-detail, and invoice pages now call the
  focused services directly. `get_orders()` and `get_orders_for_staff()` remain
  legacy unbounded loaders.
- `includes/dashboard.php`: explicit dashboard KPI aggregation, bounded
  sales/purchase chart data, Product inventory valuation, bounded top-selling
  product reads, and sale-only category sales distribution with explicit
  optional staff scope where applicable, complete chart date series, allowed
  category report page sizes, and preserved zero/empty failure contracts. The
  `get_dashboard_stats()`, `get_chart_data()`, `get_inventory_valuation()`,
  `get_top_selling_products()`, and `get_category_sales_distribution()` names
  remain delegation-only compatibility wrappers in `functions.php`.
- `includes/uploads.php`: explicit secure image validation, storage, and
  current-request cleanup under the canonical `public/uploads` boundary. The
  legacy upload-helper names remain delegation-only wrappers in `functions.php`.
- `includes/categories.php`: explicit category creation, update, and deletion
  services with trim/duplicate validation, General-category protection,
  prepared statements, product reassignment, transaction rollback, and
  preserved boolean failure contracts. The legacy create/update/delete names
  remain delegation-only wrappers.
- `includes/customers.php`: explicit customer creation, update, and deletion
  services with preserved sanitization, Walk-in Customer protection, prepared
  statements, affected-row contracts, foreign-key behavior, statement cleanup,
  and safe boolean failure results. The legacy customer names remain
  delegation-only wrappers.
- `includes/suppliers.php`: explicit supplier creation, update, and deletion
  services with preserved sanitization, General Supplier protection, prepared
  statements, affected-row contracts, foreign-key behavior, statement cleanup,
  and safe boolean failure results. The legacy supplier names remain
  delegation-only wrappers.

## Public pages and responsibilities

| Page | Current responsibility |
|---|---|
| `public/login.php` | Login, logout, rate-limit interaction, session changes, authentication audit, login view |
| `public/index.php` | Dashboard authorization, direct `dashboard_get_stats()`, `dashboard_get_chart_data()`, `dashboard_get_inventory_valuation()`, `dashboard_get_top_selling_products()`, `dashboard_get_category_sales_distribution()`, and `inventory_get_low_stock_products()` calls, remaining report data preparation, dashboard view |
| `public/products.php` | Product CRUD request dispatch, request validation, authorization, CSRF, upload request handling and generic messages, Catalog search/pagination, product table, forms, and rendering; delegates filesystem validation/storage/cleanup to `uploads_handle_image()` and `uploads_delete_newly_uploaded_image()` and product database mutations to `products_create()`, `products_update()`, and `products_delete()` |
| `public/categories.php` | Category CRUD request dispatch, admin checks, CSRF and request validation, direct `categories_create()`/`categories_update()`/`categories_delete()` calls, Catalog search/pagination, category view, and rendering |
| `public/stock_movements.php` | Manual stock adjustment request validation, CSRF and authorization boundary, delegation to the Inventory service, movement history filtering/pagination, and stock ledger view |
| `public/orders.php` | POS request parsing, CSRF and page-level purchase authorization, Catalog product/category and People customer/supplier-selector reads, product revalidation, delegation to `orders_create()`, POS view and JavaScript; the legacy `create_order()` wrapper remains for other callers |
| `public/order_history.php` | Scoped order history filters, pagination, summaries, order-history view and interactions |
| `public/get_order_details.php` | Scoped JSON order-detail endpoint |
| `public/pos_product_lookup.php` | Authenticated barcode lookup endpoint backed by the Catalog module |
| `public/customers.php` | Customer CRUD request dispatch, direct `customers_create()`/`customers_update()`/`customers_delete()` calls, People search/pagination, forms and table |
| `public/suppliers.php` | Supplier CRUD request dispatch, direct `suppliers_create()`/`suppliers_update()`/`suppliers_delete()` calls, People search/pagination, forms and table |
| `public/audit_log.php` | Admin audit filtering, pagination, and audit table |
| `public/export_report.php` | Admin CSV validation, headers, and delegation to streaming exporter |
| `public/print_invoice.php` | Scoped order lookup, invoice rendering, print behavior |
| `public/settings.php` | Profile read/update, password change, staff CRUD/status management, settings view |
| `public/backup_database.php` | Admin reauthentication, backup authorization, response streaming, backup audit |
| `public/health.php` | Generic liveness response |
| `public/ready.php` | Database readiness response |

The largest mixed-responsibility pages are `orders.php` (841 lines),
`settings.php` (615), `order_history.php` (621), `products.php` (573), and
`index.php` (485).

## Direct SQL ownership

Application pages with direct SQL ownership:

- `config/db.php:66-83`: connection and charset initialization.
- `public/login.php:61-92`: active staff lookup during login.
- `public/stock_movements.php`: no direct stock-adjustment SQL; delegates the
  validated manual adjustment to `includes/inventory.php`.
- `public/settings.php:15,78,131,139`: profile lookup, duplicate username
  check, and profile updates.
- `public/backup_database.php:113-115`: current admin password lookup for
  reauthentication.
- `public/ready.php:18`: readiness probe.

Shared modules own most other application SQL:

- `includes/catalog.php`: product/POS/barcode/product-page and bounded category
  count/page/selector reads; `includes/functions.php` retains compatibility
  wrappers.
- `includes/people.php`: bounded customer and supplier count/page/selector reads;
  `includes/functions.php` retains compatibility wrappers. Customer writes are
  owned by `includes/customers.php`; supplier writes are owned by
  `includes/suppliers.php`.
- `includes/products.php`: product creation, update, and deletion transactions,
  product stock-history integration, and product audit writes; the legacy names
  remain compatibility wrappers in `includes/functions.php`.
- `includes/auth.php`: active Staff session revalidation, admin role checks, and
  admin denial auditing; it accepts the database connection explicitly while
  preserving the current session/global side effects.
- `includes/http.php`: terminating redirect implementation; `redirect()` remains
  available through the compatibility facade.
- `includes/functions.php`: legacy full product reads, remaining inventory,
  order, staff, reference-data, remaining report queries, and protected
  mutations that have not yet been extracted; customer mutation names are
  compatibility wrappers for `includes/customers.php`, supplier mutation names
  are compatibility wrappers for `includes/suppliers.php`; `get_dashboard_stats()`,
  `get_chart_data()`, `get_inventory_valuation()`, `get_top_selling_products()`,
  and `get_category_sales_distribution()` are compatibility wrappers for
  `includes/dashboard.php`; `get_low_stock_products()` is a compatibility
  wrapper for `includes/inventory.php`.
- `includes/audit.php:62-300`: audit writes and reads.
- `includes/export.php:115-392`: bounded export queries.
- `includes/backup.php:114-168`: table definition and streamed data queries.

The direct page queries are currently prepared or static. Their primary
architectural risk is persistence coupling, not a confirmed SQL injection.

## Shared layouts and bootstrap behavior

- `includes/layouts/header.php:1-50` requires the facade, starts the session,
  sends security headers, loads external CSS, and opens the HTML document.
- `includes/layouts/sidebar.php:31-87` renders primary navigation and a CSRF-
  protected logout form. Admin-only navigation links are conditionally shown.
- `includes/layouts/navbar.php:24-216` calls the focused Inventory low-stock
  read for notifications, then renders top navigation, account controls, and
  the main content opening tag.
- `includes/layouts/footer.php` closes the page, loads the CSP compatibility
  shim, Bootstrap, optional page assets, and the shared script.

This is a useful presentation seam, but including the header also performs
session and security side effects.

## JavaScript ownership

- `public/assets/js/script.js:1-26` owns sidebar toggling, logout confirmation,
  and progress-bar width normalization.
- `public/assets/js/sweetalert-csp.js:27-50` adds the current CSP nonce to
  SweetAlert-created style elements.
- Page-specific behavior remains inline in PHP pages, including products,
  categories, customers, suppliers, stock movements, orders, order history,
  settings, invoice printing, and dashboard chart initialization.

The current JavaScript model is progressive enhancement around server-rendered
pages, not a client-side application.

## CSS and design ownership

`public/assets/css/style.css` is the global design system and override layer.
It owns:

- Color and typography tokens at `:1-63`.
- Global layout, sidebar, navbar, cards, forms, tables, and modals.
- POS layout and product-grid behavior around `:689-703`.
- Empty states around `:1103-1118`.
- Responsive rules around `:1142-1161`.
- Focus-visible treatment at `:93-96` and `:696-699`.

Bootstrap utilities, custom components, page-specific classes, and responsive
behavior are all coordinated through this one stylesheet.

## Existing test and CI coverage

The PHP harness is `tests/run.php`. It currently loads:

- `tests/Unit/validation_test.php`
- `tests/Unit/architecture_baseline_test.php`
- `tests/Unit/catalog_read_test.php`
- `tests/Unit/people_read_test.php`
- `tests/Unit/deployment_test.php`
- `tests/Unit/http_harness_test.php`
- `tests/Unit/repository_security_scan_test.php`
- `tests/Unit/ci_supply_chain_test.php`
- `tests/Unit/release_integrity_test.php`
- `tests/Unit/auth_extraction_test.php`
- `tests/Integration/database_test.php`
- `tests/Integration/backup_restore_test.php`
- `tests/Integration/operational_test.php`
- `tests/Integration/export_streaming_test.php`

Coverage includes validation, deployment contracts, HTTP responses, scanner
behavior, supply-chain policy, release metadata, disposable MySQL CRUD and
authorization behavior, backup/restore, operational endpoints, and streaming
exports.

The Playwright suite in `e2e/tests/critical-journeys.spec.js` covers login,
logout, protected routes, admin/cashier journeys, product search/pagination,
export access, keyboard smoke checks, axe checks, console/network failures, and
responsive overflow at 375px, 768px, and 1440px.

`.github/workflows/quality.yml` runs PHP and JavaScript syntax checks, Compose
validation, production image/preflight validation, security and supply-chain
policy checks, browser QA, production runtime smoke, schema validation, and
the disposable MySQL regression suite.

This document records coverage that exists in the repository. A test is not
considered passing for this batch unless it is listed in the implementation
report with its actual command result.
