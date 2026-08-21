# Phase 4G — Dead Code and Legacy Retirement

Status: complete

This is the Phase 4G removal manifest derived from
`PHASE-4F-FACADE-CLOSURE-TDD.md`. It is intentionally conservative. A legacy
function remains unless tracked production, script, CI, dynamic-reference,
security-boundary, and compatibility evidence all support removal.

## Review rules and evidence sources

The review searched:

- `includes/`, `public/`, `scripts/`, `.github/`, `config/`, `database/`,
  `docker/`, `docker-compose*.yml`, `Dockerfile`, and `e2e/` for production,
  CI, configuration, and runtime references;
- `tests/` for characterization and compatibility callers;
- all tracked text for dynamic invocation, reflection, callback, and include
  references;
- all PHP, JavaScript, CSS, templates, layouts, README assets, and browser
  imports for frontend/file retirement evidence.

The candidate function search found no dynamic invocation or reflection of the
candidate names. The only safe file deletion is the unreferenced generated
image described below. No facade function meets the removal gate because the
remaining names are either supported compatibility surface, active security
or operational behavior, or carry unresolved external-caller risk.

## Removal decision categories

- **Safe to remove now** — no tracked/runtime/dynamic/CI reference, no public
  compatibility contract, and confirmed non-source artifact.
- **Requires internal caller migration first** — a verified repository caller
  has a focused replacement but has not migrated.
- **Must remain as a compatibility wrapper** — the focused implementation is
  owned elsewhere, but the legacy name remains an observable compatibility API.
- **Security/auth boundary and must remain** — removal would alter a security,
  session, authorization, or rate-limit contract.
- **Uncertain external compatibility risk** — no tracked production caller was
  found, but tests, exports, deployment conventions, or public legacy names
  prevent safe deletion.
- **Not actually dead** — an internal, test, script, or runtime reference is
  present even when no public page calls the name directly.

## Complete removal manifest

### Safe to remove now

| File | Production callers | Test callers | Script/CI callers | Dynamic/include references | Replacement | Risk | Final decision and reason |
|---|---|---|---|---|---|---|---|
| `docs/preview.png` | none found in templates, README, CI, or runtime source | none found | none found | none found | none; generated dashboard mockup only | low | **Remove now.** Git history identifies it as a release-preparation artifact; it is not referenced by README, CI, runtime code, or documentation. Curated README screenshots remain retained. |

### Must remain as compatibility wrappers

All rows in this section are delegation-only wrappers in
`includes/functions.php`. The focused replacement is active, but the legacy
name remains an observable facade surface. No production caller was found for
most rows after Phase 4F page migration; that is not proof that external
deployments, plugins, or CLI consumers do not call them.

| Function/file | Production callers | Test callers | Script/CI callers | Dynamic/include references | Replacement service | Risk | Final decision and reason |
|---|---|---|---|---|---|---|---|
| `build_product_filter_sql` / `includes/functions.php` | none found | facade classification only | none found | none found | `catalog_build_product_filter_sql` | medium compatibility risk | **Keep wrapper.** Historical facade API; no verified external-caller proof for removal. |
| `get_pos_products` / `includes/functions.php` | none after stock-page migration | database integration | none found | none found | `catalog_get_pos_products` | medium | **Keep wrapper.** Selector contract remains tested and externally callable. |
| `get_pos_product_by_barcode` / `includes/functions.php` | none found | database integration | none found | none found | `catalog_get_pos_product_by_barcode` | medium | **Keep wrapper.** Barcode lookup is an operational compatibility surface. |
| `count_products` / `includes/functions.php` | none found | database integration | none found | none found | `catalog_count_products` | low/medium | **Keep wrapper.** Preserve legacy pagination API until external inventory closes. |
| `get_products_page` / `includes/functions.php` | none found | database integration | none found | none found | `catalog_get_products_page` | low/medium | **Keep wrapper.** Preserve legacy pagination API until external inventory closes. |
| `get_product_by_id` / `includes/functions.php` | none after stock-page migration | database integration | none found | none found | `catalog_get_product_by_id` | medium | **Keep wrapper.** Product revalidation contract remains externally plausible. |
| `get_low_stock_products` / `includes/functions.php` | none found | Unit/Integration inventory reads | none found | none found | `inventory_get_low_stock_products` | medium | **Keep wrapper.** Inventory notification behavior is security/operationally sensitive. |
| `log_stock_movement` / `includes/functions.php` | none found | inventory unit/integration tests | none found | none found | `inventory_log_stock_movement` | high | **Keep wrapper.** History writes affect audit and inventory integrity. |
| `count_stock_movements` / `includes/functions.php` | none found | database integration | none found | none found | `inventory_count_stock_movements` | medium | **Keep wrapper.** Preserve legacy ledger read contract. |
| `get_stock_movements_page` / `includes/functions.php` | none found | database integration | none found | none found | `inventory_get_stock_movements_page` | medium | **Keep wrapper.** Preserve legacy ledger pagination contract. |
| `create_product` / `includes/functions.php` | none found | product-write, database, backup integration | none found | none found | `products_create` | high | **Keep wrapper.** Product mutation, stock history, and audit behavior are contractual. |
| `update_product` / `includes/functions.php` | none found | product-write and database integration | none found | none found | `products_update` | high | **Keep wrapper.** Product mutation and audit contracts remain protected. |
| `delete_product` / `includes/functions.php` | none found | product-write and database integration | none found | none found | `products_delete` | high | **Keep wrapper.** Historical-row protections and audit behavior remain contractual. |
| `create_order` / `includes/functions.php` | none found | order-write, validation, database, backup integration | none found | none found | `orders_create` | high | **Keep wrapper.** Order ID/false and transaction contracts are public legacy behavior. |
| `count_orders` / `includes/functions.php` | none found | database integration | none found | none found | `orders_count` | high | **Keep wrapper.** Order visibility and staff scope are security-sensitive. |
| `get_order_summary` / `includes/functions.php` | none found | database integration | none found | none found | `orders_get_summary` | high | **Keep wrapper.** Preserve scoped reporting behavior. |
| `get_orders_page` / `includes/functions.php` | none found | database integration | none found | none found | `orders_get_page` | high | **Keep wrapper.** Preserve scoped pagination behavior. |
| `get_order_by_id` / `includes/functions.php` | none found | database integration | none found | none found | `orders_get_by_id` | high | **Keep wrapper.** Preserve staff-scope authorization behavior. |
| `get_order_details` / `includes/functions.php` | none found | database integration | none found | none found | `orders_get_details` | high | **Keep wrapper.** Preserve order-detail authorization behavior. |
| `get_dashboard_stats` / `includes/functions.php` | none found in dashboard page | dashboard integration | none found | none found | `dashboard_get_stats` | medium | **Keep wrapper.** Fixed response shape remains compatibility behavior. |
| `handle_image_upload` / `includes/functions.php` | none after product-page migration | uploads source contract | none found | none found | `uploads_handle_image` | high | **Keep wrapper.** Filesystem validation and path-boundary behavior are security-sensitive. |
| `delete_newly_uploaded_image` / `includes/functions.php` | none after product-page migration | uploads source contract | none found | none found | `uploads_delete_newly_uploaded_image` | high | **Keep wrapper.** Upload cleanup has a security-sensitive path contract. |
| `get_chart_data` / `includes/functions.php` | none in dashboard page | dashboard integration | none found | none found | `dashboard_get_chart_data` | medium | **Keep wrapper.** Chart shape and zero-filled dates remain observable. |
| `count_categories` / `includes/functions.php` | none found | database integration | none found | none found | `catalog_count_categories` | low/medium | **Keep wrapper.** Preserve legacy category pagination API. |
| `get_categories_page` / `includes/functions.php` | none found | database integration | none found | none found | `catalog_get_categories_page` | low/medium | **Keep wrapper.** Preserve legacy category pagination API. |
| `get_categories_for_selector` / `includes/functions.php` | none found | database integration | none found | none found | `catalog_get_categories_for_selector` | medium | **Keep wrapper.** Selector contracts can be consumed by external tooling. |
| `create_category` / `includes/functions.php` | none after categories-page migration | category-write and database integration | none found | none found | `categories_create` | high | **Keep wrapper.** Category writes and exact boolean behavior remain contractual. |
| `update_category` / `includes/functions.php` | none after categories-page migration | category-write and database integration | none found | none found | `categories_update` | high | **Keep wrapper.** General-category protection remains contractual. |
| `delete_category` / `includes/functions.php` | none after categories-page migration | category-delete and database integration | none found | none found | `categories_delete` | high | **Keep wrapper.** Transactional reassignment and rollback behavior remain contractual. |
| `count_customers` / `includes/functions.php` | none found | database integration | none found | none found | `people_count_customers` | low/medium | **Keep wrapper.** Preserve legacy People read API. |
| `get_customers_page` / `includes/functions.php` | none found | database integration | none found | none found | `people_get_customers_page` | low/medium | **Keep wrapper.** Preserve legacy People pagination API. |
| `get_customers_for_selector` / `includes/functions.php` | none found | database integration | none found | none found | `people_get_customers_for_selector` | medium | **Keep wrapper.** Selector behavior remains externally plausible. |
| `create_customer` / `includes/functions.php` | none after customers-page migration | customer mutation, database, backup integration | none found | none found | `customers_create` | high | **Keep wrapper.** Walk-in protection and boolean contracts remain contractual. |
| `update_customer` / `includes/functions.php` | none after customers-page migration | customer mutation and database integration | none found | none found | `customers_update` | high | **Keep wrapper.** Legacy missing-ID execute-success behavior is preserved. |
| `delete_customer` / `includes/functions.php` | none after customers-page migration | customer mutation and database integration | none found | none found | `customers_delete` | high | **Keep wrapper.** Historical-order foreign-key behavior remains contractual. |
| `count_suppliers` / `includes/functions.php` | none found | database integration | none found | none found | `people_count_suppliers` | low/medium | **Keep wrapper.** Preserve legacy People read API. |
| `get_suppliers_page` / `includes/functions.php` | none found | database integration | none found | none found | `people_get_suppliers_page` | low/medium | **Keep wrapper.** Preserve legacy People pagination API. |
| `get_suppliers_for_selector` / `includes/functions.php` | none found | database integration | none found | none found | `people_get_suppliers_for_selector` | medium | **Keep wrapper.** Preserve purchase-selector behavior. |
| `create_supplier` / `includes/functions.php` | none after suppliers-page migration | supplier mutation and database integration | none found | none found | `suppliers_create` | high | **Keep wrapper.** Supplier validation and exact affected-row behavior remain contractual. |
| `update_supplier` / `includes/functions.php` | none after suppliers-page migration | supplier mutation and database integration | none found | none found | `suppliers_update` | high | **Keep wrapper.** Legacy missing-ID execute-success behavior remains contractual. |
| `delete_supplier` / `includes/functions.php` | none after suppliers-page migration | supplier mutation and database integration | none found | none found | `suppliers_delete` | high | **Keep wrapper.** Historical purchase-order `ON DELETE SET NULL` behavior remains contractual. |
| `get_inventory_valuation` / `includes/functions.php` | none in dashboard page | dashboard integration | none found | none found | `dashboard_get_inventory_valuation` | medium | **Keep wrapper.** Numeric failure/default contract remains observable. |
| `get_top_selling_products` / `includes/functions.php` | none in dashboard page | dashboard integration | none found | none found | `dashboard_get_top_selling_products` | medium | **Keep wrapper.** Sales-only scope and result shape remain observable. |
| `get_category_sales_distribution` / `includes/functions.php` | none in dashboard page | dashboard integration | none found | none found | `dashboard_get_category_sales_distribution` | medium | **Keep wrapper.** Report scope and result shape remain observable. |

### Uncertain external compatibility risk

These implementations are not called by tracked production pages, but they
are not safe to delete. Several remain exercised by export/read tests, and all
are legacy names documented by the facade or response-contract inventory.

| Function/file | Production callers | Test callers | Script/CI callers | Dynamic/include references | Replacement service | Risk | Final decision and reason |
|---|---|---|---|---|---|---|---|
| `get_all_products` / `includes/functions.php` | none found | export integration | none found | none found | bounded catalog/export path | high data-volume and compatibility risk | **Keep.** Export and external full-list consumers require a separate migration. |
| `get_stock_movements` / `includes/functions.php` | none found | inventory read and export integration | none found | none found | bounded inventory page/export path | high data-volume and compatibility risk | **Keep.** Unbounded export/read contract is not retired in this phase. |
| `get_orders` / `includes/functions.php` | none found | order-read and database integration | none found | none found | bounded `orders_get_page`/export path | high scope/data-volume risk | **Keep.** Full-list callers require separate characterization. |
| `get_orders_for_staff` / `includes/functions.php` | none found | order-read and database integration | none found | none found | scoped `orders_get_page`/export path | high authorization risk | **Keep.** Staff scope must be migrated and re-proven separately. |
| `get_categories` / `includes/functions.php` | none found | catalog source contract | none found | none found | bounded Catalog selector/page services | medium | **Keep.** Full-list compatibility remains unresolved. |
| `get_customers` / `includes/functions.php` | none found | People and export integration | none found | none found | bounded People page/export service | medium/high | **Keep.** Export callers and external consumers are unresolved. |
| `get_suppliers` / `includes/functions.php` | none found | People and export integration | none found | none found | bounded People page/export service | medium/high | **Keep.** Supplier export compatibility is unresolved. |
| `get_category_by_id` / `includes/functions.php` | none found | catalog source contract | none found | none found | none verified | medium | **Keep.** No focused replacement exists and external lookup callers are uncertain. |
| `get_customer_by_id` / `includes/functions.php` | none found | People source contract | none found | none found | none verified | medium | **Keep.** No focused replacement exists and external lookup callers are uncertain. |
| `get_supplier_by_id` / `includes/functions.php` | none found | People and supplier mutation source contracts | none found | none found | none verified | medium/high | **Keep.** Phase 4E explicitly retained it pending verified caller inventory. |

### Security/auth boundary and must remain

| Function/file | Production callers | Test callers | Script/CI callers | Dynamic/include references | Replacement service | Risk | Final decision and reason |
|---|---|---|---|---|---|---|---|
| `normalize_login_identifier` / `includes/functions.php` | internal `build_login_rate_limit_key` path reached by `public/login.php` | validation unit | none found | none found | none until auth/rate-limit extraction | high key-format/auth risk | **Keep.** It contributes to a persisted rate-limit key contract. |
| `redirect`, `verify_login`, `is_admin`, `require_admin` / `includes/functions.php` | login/layout/auth boundaries | auth and database tests | none found | none found | `http.php`/`auth.php` | critical | **Keep.** These are compatibility-preserving auth/session boundaries. |
| login-rate-limit functions / `includes/functions.php` | `public/login.php` directly and internal helper calls | auth/database/backup tests | none found | none found | none verified | critical | **Keep.** Rate limiting is explicitly out of retirement scope. |
| staff administration functions / `includes/functions.php` | `public/settings.php` | auth/database tests | `browser-qa-seed.php` for creation | none found | none verified | critical | **Keep.** Staff authorization and lifecycle behavior are explicitly protected. |

### Requires internal caller migration first

No candidate currently meets this category. The only verified facade/page
coupling found in Phase 4F (`public/stock_movements.php` Catalog reads) was
migrated before this phase. No additional internal caller migration is
authorized without a separate contract and RED test.

### Not actually dead

The following are not removal candidates despite limited direct page usage:

- `includes/functions.php` itself, because it remains the compatibility facade.
- focused upload, dashboard, catalog, inventory, order, category, customer,
  and supplier modules, because their public services and/or compatibility
  wrappers are covered by current behavior contracts;
- `public/assets/js/script.js`, `public/assets/js/sweetalert-csp.js`, and
  `public/assets/css/style.css`, because layouts/pages import them and Browser
  QA exercises the dependent surfaces;
- curated `screenshots/*.png`, because README references them;
- backup, export, health, readiness, authentication, and operational files,
  because they are explicit security or deployment boundaries.

## JavaScript and CSS retirement inventory

| Asset | References | Decision |
|---|---|---|
| `public/assets/js/script.js` | login, products, stock movements, shared footer; sidebar/logout handlers | retain |
| `public/assets/js/sweetalert-csp.js` | shared footer; nonce-safe SweetAlert bootstrap | retain |
| `public/assets/css/style.css` | shared header and login; page comments/documentation | retain |
| Inline handlers in products, orders, categories, customers, suppliers, settings, stock movements, order history, print invoice, dashboard | page-local forms, modals, barcode/POS, pagination, chart, invoice, and responsive behavior | retain; no unreachable block proven |

No JavaScript or CSS deletion is authorized in Phase 4G. The asset inventory
found no orphaned tracked frontend file, and Browser QA coverage depends on the
active assets and inline handlers.

## Deletion evidence and implementation plan

1. Add the RED architecture/removal test asserting the manifest decision,
   exact absence of all `docs/preview.png` references, and retention of all
   protected facade/security assets.
2. Run the focused test and commit the valid RED result.
3. Delete only `docs/preview.png`, run the same focused test GREEN, review the
   deletion diff, and commit the minimal removal.
4. Update current architecture documentation with the final manifest and
   verification evidence.

No wildcard deletion, schema change, UI redesign, compatibility-wrapper
removal, or Phase 5 work is included.

## TDD and verification record

| Checkpoint | Result |
|---|---|
| Manifest checkpoint | `86a02d6` — complete removal manifest committed before code/test changes |
| RED retirement contract | `b6ff383` — focused test failed only because `docs/preview.png` still existed |
| GREEN retirement change | `79048f2` — exact binary artifact deletion; focused retirement test passed with 39 assertions |
| Focused removal tests | PASS — 39 assertions |
| Full disposable PHP regression | PASS — 2,645 assertions (1,787 unit, 858 integration) |
| PHP lint | PASS — all tracked PHP files under application, test, script, database, and config paths |
| JavaScript syntax | PASS — all tracked JavaScript files with `node --check` |
| Repository security scan | PASS — zero findings |
| Supply-chain scan | PASS — zero findings |
| `git diff --check` | PASS |
| Historical Phase 3/4 documents | PASS — unchanged |
| Browser QA | PASS — 18/18 at 375px, 768px, and 1440px |
| Final worktree | clean; no new secrets, dumps, generated artifacts, or disposable resources |

No internal production callers were migrated in Phase 4G. No facade function,
compatibility wrapper, JavaScript asset, CSS asset, screenshot, security
boundary, backup/export boundary, or operational file was removed.

## Focused CSS dead-code cleanup (post-Phase 4G)

This narrowly scoped follow-up removed only the seven CSS targets confirmed
unused by the Phase 4G frontend inventory: four legacy root custom-property
declarations, two obsolete movement-badge rule blocks, and one hidden cart-row
rule. The adjacent empty compatibility comment was removed with its block.
No other CSS was reformatted, recolored, reorganized, or redesigned.

The pre-change fixed-string audit found those targets only in
`public/assets/css/style.css`. It found no PHP, HTML, JavaScript, test,
documentation, or dynamic class-construction references, and the explicitly
excluded modal-lock selector was not present. The post-change audit found no
occurrences anywhere in the repository. Shared root, modal, table, cart, and
semantic color contracts remain present.

The source-contract test constructs the audited names from fragments so the
repository-wide absence check remains meaningful while still asserting the
exact runtime targets. The application JavaScript, PHP source, HTML, UI
behavior, business logic, and schema were not changed.

### CSS cleanup TDD and verification record

| Checkpoint | Result |
|---|---|
| RED CSS source contract | `c5cd08d` — focused contract failed while the legacy declarations remained |
| GREEN CSS removal | `8f3a479` — minimal stylesheet deletion; focused contract passed |
| Reference-audit hardening | `d7ffe9b` — exact target names are assembled at runtime in the test |
| Focused CSS contract | PASS — 20 assertions |
| Full disposable PHP regression | PASS — 2,665 assertions (1,807 unit, 858 integration) |
| PHP lint | PASS — all tracked PHP files |
| JavaScript syntax | PASS — all 4 tracked JavaScript files |
| Repository security scan | PASS — zero findings |
| Supply-chain scan | PASS — zero findings |
| `git diff --check` | PASS |
| Browser QA | PASS — 18/18 at 375px, 768px, and 1440px |
| Disposable resources and artifacts | PASS — QA resources removed; no new secrets, environment files, generated artifacts, or dumps |
