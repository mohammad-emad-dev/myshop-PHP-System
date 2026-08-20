# Phase 3A — Remaining Facade Ownership Audit

Status: read-only audit completed at `93dccfebf66943b5e6e2f79ce92869df341d5891`
on branch `security-hardening-baseline`.

This document is an evidence record. It does not authorize extraction, deletion,
or behavior changes. Historical batch documents remain historical records and
are not rewritten by this audit.

## Scope and method

The audit covered every function definition in `includes/functions.php` and
searched callers under `public/`, `includes/`, `tests/`, and `scripts/`.

Evidence commands used:

```text
rg -n '^function\s+' includes/functions.php
rg -n --glob '*.php' '<facade-function-call-pattern>' public includes tests scripts
rg -n -i 'call_user_func|call_user_func_array|\$[A-Za-z_][A-Za-z0-9_]*\s*\(' public includes tests scripts
rg -n '\$_SESSION|\$GLOBALS|global\s+\$' includes/functions.php
rg -n -i 'SELECT|INSERT|UPDATE|DELETE|FROM |JOIN |INTO ' includes/functions.php
```

The facade contains **77 functions across 2,500 lines**. Static inspection found
no dynamic invocation of a facade function. Calls reported in source-contract
tests are identified as source assertions below; they are not treated as
runtime production callers.

Caller notation in the inventory:

- `P:` verified production caller under `public/` or `includes/layouts/`;
- `T:` test caller or source-contract assertion;
- `S:` script caller;
- `I:` internal facade caller;
- `—` no caller found outside the definition itself.

## Executive verdict

`includes/functions.php` is now a transitional compatibility facade, but it is
still also the owner of several real domains. The extracted Catalog, People,
Inventory, Product, Order, Auth, Audit, HTTP, Export, and Backup modules have
reduced the facade's architectural risk, yet the remaining file still mixes:

- login rate limiting and transaction helpers;
- shared input and password policy helpers;
- legacy unbounded loaders;
- dashboard/reporting SQL;
- secure upload filesystem operations;
- staff administration mutations;
- category, customer, and supplier mutations; and
- compatibility wrappers for already-extracted services.

The most important current risk is not that every facade function is unsafe. It
is that ownership is difficult to see: a page can require the facade and gain
access to unrelated database, session, filesystem, and authorization behavior.
The highest-value next batch is a small read-only dashboard extraction beginning
with `get_dashboard_stats()` behind a compatibility wrapper. It has one
production caller, explicit staff scope, no transaction or mutation boundary,
and a clear default-array contract. It can reduce facade ownership without
touching staff, CSRF, order, inventory, or upload behavior.

## Current-state boundaries

### Already extracted and therefore not remaining implementation ownership

The following names are still declared in `functions.php` only to preserve
legacy callers, except where noted:

| Existing module | Current implementation ownership | Facade status |
|---|---|---|
| `includes/catalog.php` | Product/POS/barcode reads and bounded category reads | Catalog names are delegation-only wrappers; a few stock-page callers still use product/POS wrappers. |
| `includes/people.php` | Bounded customer and supplier reads/selectors | People names are delegation-only wrappers; public customer/supplier pages call People directly. |
| `includes/inventory.php` | Bounded stock-movement reads, movement insert, atomic manual adjustment | Movement writer and bounded read names are wrappers; `public/stock_movements.php` calls the focused adjustment/read services directly but still uses two Catalog wrappers. |
| `includes/products.php` | Product create/update/delete transactions | `create_product()`, `update_product()`, and `delete_product()` are delegation-only wrappers; `public/products.php` calls focused services directly. |
| `includes/orders.php` | Order creation and bounded/scoped order reads | `create_order()` and five order-read names are delegation-only wrappers; current public order pages call focused services directly. |
| `includes/auth.php` / `includes/http.php` | Authentication, authorization, and terminating redirect | `verify_login()`, `is_admin()`, `require_admin()`, and `redirect()` remain compatibility wrappers. The layout still uses `is_admin()`. |

These wrappers are compatibility ownership, not domain implementation ownership.
They must not be deleted until all verified callers, tests, and scripts have
been migrated or explicitly retired.

## Severity-ranked findings

### High — Staff administration remains a transaction-bearing security boundary

Evidence: `includes/functions.php:1115-1579`; production callers in
`public/settings.php:209,227,237,251,381`; script caller
`scripts/browser-qa-seed.php:90` for staff creation.

`get_staff_members()`, `create_staff_member()`, `update_staff_member()`,
`delete_staff_member()`, and `set_staff_active()` combine staff SQL, password
hashing/policy, transaction handling, row locks, and last-active-administrator
invariants. The functions accept explicit database and actor IDs, but they call
the facade's password policy directly and are protected by page-level auth and
CSRF. A partial extraction could alter role validation, self-protection,
transaction rollback, or audit timing.

Recommendation: extract a focused Staff module only after characterization of
the settings POST boundary, current-password reauthentication, audit events,
sole-admin protections, and rollback behavior. Fix before broad architectural
cleanup because this boundary owns administrative integrity; do not combine it
with dashboard or reference-data work.

### High — Reporting/dashboard SQL is duplicated across one large facade owner

Evidence: `get_dashboard_stats()` at `:785-872`, `get_chart_data()` at
`:1018-1090`, `get_inventory_valuation()` at `:2356-2370`,
`get_top_selling_products()` at `:2375-2422`, and
`get_category_sales_distribution()` at `:2427-2500`; production caller
`public/index.php:24-29`.

These functions are read-only and accept explicit staff scope where required,
but their SQL is owned by the facade and duplicates order/product aggregate
concepts already present in `includes/orders.php` and the Catalog/Inventory
domains. Dashboard defaults are part of the UI contract and cashier scoping is
security-sensitive. Extraction is low mutation risk but medium behavior risk
because numeric defaults, date padding, sale-only totals, and scope filters must
remain exact.

Recommendation: first extract `get_dashboard_stats()` as a bounded read service
with a thin wrapper and page migration. Follow with chart and aggregate reads
only after output contracts are characterized. This is the recommended next
implementation batch.

### High — Unbounded legacy loaders remain available at the facade boundary

Evidence: `get_all_products():508-527`, `get_stock_movements():605-662`,
`get_orders():693-712`, `get_orders_for_staff():714-758`,
`get_categories():1585-1603`, `get_customers():1956-1970`, and
`get_suppliers():2156-2170`. No verified production callers were found, but
tests and source-contract checks intentionally preserve several names.

These functions use `query()` plus `fetch_all()` and can load complete tables or
history sets into memory. The export implementation already uses bounded
streaming and source tests ensure it does not call the old loaders. They are
not safe to remove solely because current production searches found no page
caller: integration tests and compatibility contracts still exercise some of
them.

Recommendation: do not extract them as-is. Record a deprecation/retirement
decision per function, migrate or replace every test/script caller, then delete
only after a repository-wide zero-caller check and a compatibility decision.

### Medium — Category, customer, and supplier mutations are still page-facing

Evidence: category mutations at `:1664-1951` with production callers in
`public/categories.php:35,52,62`; customer mutations at `:2031-2151` with
`public/customers.php:36,58,72`; supplier mutations at `:2231-2351` with
`public/suppliers.php:36,58,72`.

These operations perform direct SQL from the facade, while their bounded read
paths have already moved into Catalog or People. Category deletion owns the
General-category guard, transaction, product reassignment, and rollback.
Customer and supplier writes own default-record protections and normalization.
They have no extracted domain service to receive the invariants yet.

Recommendation: extract one domain at a time, beginning with a source contract
and direct compatibility wrapper. Category deletion should be isolated from
customer/supplier work because it changes related Product rows in a transaction.

### Medium — Authentication compatibility still depends on global database state

Evidence: `verify_login():491-496`, `is_admin():1095-1100`,
`require_admin():1105-1110`; `global $conn` is used only by these wrappers in
the facade. `includes/layouts/sidebar.php:17,46` still calls `is_admin()`.

The actual Auth module accepts a connection explicitly but preserves session and
`$GLOBALS['current_staff_record']` compatibility. The remaining layout wrapper
is intentional but keeps global coupling alive and makes facade removal
dependent on layout bootstrap changes.

Recommendation: migrate the shared sidebar to an explicit connection-aware Auth
call only after confirming header/bootstrap scope and layout tests. Retire the
three wrappers last, not during a database-domain extraction.

### Medium — Upload ownership combines filesystem security with page request flow

Evidence: `handle_image_upload():874-973` and
`delete_newly_uploaded_image():979-1012`; production callers in
`public/products.php:36,47,65,77`.

The functions correctly validate upload status, MIME, image structure, size,
dimensions, canonical paths, generated filenames, and cleanup. They do not use
the database, but they are security-sensitive and their relative-path return
contract is coupled to Product mutation rollback/cleanup in the page.

Recommendation: extract only after the Product page upload contract is modeled;
keep filesystem validation and cleanup in one focused upload module and preserve
the current page-owned authorization and error behavior.

### Low — Facade wrappers and uncalled single-record loaders obscure retirement

Evidence: delegation-only wrappers at `:534-556`, `:600-602`, `:664-692`,
`:760-782`, `:1605-1617`, `:1972-1984`, and `:2172-2184`; uncalled single-record
loaders at `get_category_by_id():1623-1659`, `get_customer_by_id():1990-2026`,
and `get_supplier_by_id():2190-2226`.

The wrappers are useful compatibility boundaries, but their presence makes
source searches overstate facade ownership. The single-record loaders duplicate
module domains without verified runtime callers.

Recommendation: maintain a call-site ledger and retire wrappers only in explicit
cleanup batches after tests/scripts are migrated. Do not silently replace them
with new aliases.

## Complete remaining-function inventory

The following tables include every function currently declared in
`includes/functions.php`. “Candidate” is a proposed future owner, not an
implemented module.

### Shared normalization, authentication, and rate limiting

| Function / location | Type and callers | Dependencies / duplication | Candidate, risk, and order |
|---|---|---|---|
| `sanitize_input()` `:19` | Normalization. `P:` customers, categories, products, order history, settings, stock movements, suppliers; `T:` validation and backup fixture. | No DB/session/global dependency; output encoding is intentionally different from auth normalization. | `includes/validation.php`; low code risk but broad contract risk. Extract after characterization. |
| `sanitize_email()` `:27` | Normalization. `I:` customer/supplier mutations; `T:` validation. | Pure `filter_var`; no DB/session/global. | Validation module; low risk, but migrate with customer/supplier writes only after wrapper tests. |
| `sanitize_phone()` `:36` | Normalization. `I:` customer/supplier mutations; `T:` validation. | Pure regex; no DB/session/global. | Validation module; low risk. |
| `sanitize_id()` `:44` | Normalization. `P:` print invoice; `I:` customer/supplier updates; `T:` validation. | Pure integer validation; no DB/session/global. | Validation module; low risk if return `0` behavior remains exact. |
| `password_meets_policy()` `:52` | Security normalization. `P:` settings; `S:` browser QA seed; `I:` staff create/update; `T:` auth/validation. | Pure policy with optional mbstring; no DB/session/global. | Auth/Staff policy boundary; medium risk because all password-change flows depend on it. |
| `normalize_login_identifier()` `:67` | Auth normalization. `I:` rate-limit key builder; `T:` validation. | Pure string normalization; no DB/session/global. | Auth/rate-limit module; low implementation risk, high compatibility sensitivity for keys. |
| `build_login_rate_limit_key()` `:79` | Auth/rate-limit key creation. `P:` login; `T:` database and backup fixtures, validation. | Uses `normalize_login_identifier`; no session/global; key format is persisted behavior. | `includes/auth.php` or `includes/rate_limit.php`; medium risk. |
| `login_rate_limit_log_failure()` `:91` | Internal diagnostic helper. `I:` all rate-limit transaction/query helpers. | `error_log()` only; no DB/session/global. | Rate-limit module; low risk, preserve redaction/log wording. |
| `login_rate_limit_begin_transaction()` `:101` | Internal transaction helper. `I:` check, failure, reset. | Explicit mysqli connection; shared error logging. | Rate-limit module; medium risk because every rate-limit transaction uses it. |
| `login_rate_limit_rollback()` `:116` | Internal rollback helper. `I:` check, failure, reset. | Explicit mysqli connection; rollback diagnostics can see connection errors. | Rate-limit module; medium risk, preserve safe failure behavior. |
| `login_rate_limit_cleanup_expired()` `:127` | Mutation/maintenance. `I:` check and record-failure. | Direct `DELETE` SQL on `LoginRateLimit`; explicit connection. | Rate-limit module; medium risk due transaction ordering and cleanup limits. |
| `login_rate_limit_check()` `:165` | Bounded auth read plus transaction. `P:` login; `T:` integration and auth source tests. | Direct `LoginRateLimit` SQL, row locking, default `['status'=>'error','retry_after'=>0]`. | Rate-limit module; high security/behavior risk. Extract as one unit with companion helpers. |
| `login_rate_limit_record_failure()` `:246` | Auth mutation plus transaction. `P:` login; `T:` integration and backup fixture. | Upsert, row lock, window/block calculations, exact statuses. | Rate-limit module; high risk; migrate with check/reset and login tests. |
| `login_rate_limit_reset()` `:435` | Auth mutation plus transaction. `P:` login; `T:` integration. | Deletes key state, explicit connection, boolean return. | Rate-limit module; medium/high risk because successful-login behavior depends on it. |
| `verify_login()` `:491` | Compatibility Auth wrapper. `T:` integration and auth source tests; no current direct `P:` caller. | Reads global `$conn` with null-safe fallback and delegates to `auth_verify_login()`. | Retire last after layout/CLI compatibility migration; high session behavior risk. |
| `redirect()` `:498` | Compatibility HTTP wrapper. `P:` login; `T:` auth source tests. | Delegates to terminating `http_redirect()`; no DB/session in wrapper. | Retain until login migration is explicitly approved; low implementation risk, high response-contract sensitivity. |
| `build_product_filter_sql()` `:503` | Compatibility Catalog wrapper. No verified `P:`, `T:`, or `S:` caller outside the definition. | Delegates to `catalog_build_product_filter_sql()`; no duplicated implementation. | Candidate retirement after zero-caller verification; low risk. |

### Catalog, inventory, product, and order compatibility boundary

| Function / location | Type and callers | Dependencies / duplication | Candidate, risk, and order |
|---|---|---|---|
| `get_all_products()` `:508` | Unbounded read. `T:` export-streaming source contract only. | Direct query plus `fetch_all()`; duplicates Catalog product fields and is memory-unbounded. | Retire or replace after zero-caller proof; do not extract unchanged. |
| `get_pos_products()` `:534` | Compatibility bounded read. `P:` stock movements `:71`; `T:` database `:962-963`. | Delegates to `catalog_get_pos_products()`; no SQL duplication. | Migrate stock page to Catalog directly; low risk, preserve selector limit. |
| `get_pos_product_by_barcode()` `:539` | Compatibility single-record read. `T:` database `:964`; no current `P:` caller. | Delegates to Catalog barcode lookup. | Retire after test/CLI migration; low risk. |
| `count_products()` `:544` | Compatibility bounded count. `T:` database `:942,943,946,948`. | Delegates to Catalog. | Retire after compatibility callers migrate; low risk. |
| `get_products_page()` `:549` | Compatibility bounded page. `T:` database `:944,945,947`. | Delegates to Catalog. | Retire after compatibility callers migrate; low risk. |
| `get_product_by_id()` `:554` | Compatibility single-record read. `P:` stock movements `:55`; `T:` database `:1667`, inventory source test. | Delegates to Catalog; current stock page still uses the legacy name. | Migrate with stock page `get_pos_products()`; low risk but preserve null/failure contract. |
| `get_low_stock_products()` `:559` | Bounded inventory read. `P:` dashboard index `:29`, shared navbar `:27`; `T:` inventory source tests. | Direct Product/Category SQL with limit normalization; related to inventory UI, not a wrapper. | `includes/inventory.php` or dashboard read module; medium risk because two layouts/pages consume it. |
| `log_stock_movement()` `:600` | Compatibility mutation wrapper. `T:` database and inventory source tests; no current `P:` caller. | Delegates directly to Inventory writer. | Retire after remaining test/CLI callers migrate; preserve boolean contract. |
| `get_stock_movements()` `:605` | Unbounded read with optional scoped branch. `T:` export-streaming source contract only. | Direct SQL and `fetch_all()`; duplicates Inventory movement domain. | Retire or replace, never extract unchanged; high memory risk if reintroduced. |
| `count_stock_movements()` `:664` | Compatibility bounded count. `T:` database `:1301,1306`. | Delegates to Inventory. | Retire after compatibility test migration; low risk. |
| `get_stock_movements_page()` `:669` | Compatibility bounded page. `T:` database `:1302-1304,1307`. | Delegates to Inventory. | Retire after compatibility test migration; low risk. |
| `create_product()` `:674` | Compatibility mutation wrapper. `T:` database and backup integration, product source tests. | Delegates to `products_create()` with exact signature. | Retain until all external callers migrate; low code risk, high rollback/audit contract sensitivity. |
| `update_product()` `:679` | Compatibility mutation wrapper. `T:` database, product source tests. | Delegates to `products_update()`. | Retain; migrate compatibility callers only in a separately verified batch. |
| `delete_product()` `:684` | Compatibility mutation wrapper. `T:` database, product source tests. | Delegates to `products_delete()`. | Retain; deletion/history/audit contracts are high risk. |
| `create_order()` `:689` | Compatibility mutation wrapper. `T:` database, backup integration, validation/order source tests. | Delegates to `orders_create()`; no duplicate SQL. | Retain until CLI/test callers migrate; high business-invariant sensitivity. |
| `get_orders()` `:693` | Unbounded order read. `T:` database closed-connection test and order-read source contract. | Direct joined query plus `fetch_all()`; duplicates Orders read domain. | Retire or replace only after test compatibility decision; high memory risk. |
| `get_orders_for_staff()` `:714` | Unbounded scoped order read. `T:` database `:1115,1625`. | Direct joined query plus `fetch_all()`; duplicates scoped Orders reads. | Retire after replacement with bounded service; high behavior/memory risk. |
| `count_orders()` `:760` | Compatibility bounded count. `T:` database `:1104-1106`. | Delegates to Orders. | Retain until compatibility test callers migrate; low code risk. |
| `get_order_summary()` `:765` | Compatibility scoped aggregate. `T:` database `:1112`. | Delegates to Orders. | Retain until compatibility callers migrate; medium response-contract sensitivity. |
| `get_orders_page()` `:770` | Compatibility bounded page. `T:` database `:1107-1111`. | Delegates to Orders. | Retain until compatibility callers migrate; low code risk. |
| `get_order_by_id()` `:775` | Compatibility scoped single-record lookup. `T:` database `:1626,1628`. | Delegates to Orders; null unauthorized contract matters. | Retain until compatibility callers migrate; medium authorization sensitivity. |
| `get_order_details()` `:780` | Compatibility scoped detail lookup. `T:` database `:1627,1629,1673`. | Delegates to Orders; empty-array unauthorized/failure contract matters. | Retain until compatibility callers migrate; medium authorization sensitivity. |

### Dashboard, upload, and authorization boundary

| Function / location | Type and callers | Dependencies / duplication | Candidate, risk, and order |
|---|---|---|---|
| `get_dashboard_stats()` `:785` | Bounded reporting aggregate. `P:` `public/index.php:24`. | Explicit optional staff scope; five scalar queries; returns fixed defaults on failure; duplicates Product/Order aggregate concepts. | `includes/dashboard.php`; recommended first extraction. Medium output risk, low mutation risk. |
| `handle_image_upload()` `:874` | Security-sensitive filesystem mutation. `P:` products `:36,65`; `T:` auth source contract. | Explicit file array; MIME, image, path, size, dimensions, random filename; no DB/session/global. | `includes/uploads.php`; medium/high security risk; extract after upload characterization. |
| `delete_newly_uploaded_image()` `:979` | Filesystem cleanup. `P:` products `:47,77`; no runtime test caller found. | Validates current-request relative path and canonical `public/uploads` boundary. | Same upload module; medium path-contract risk; migrate with upload helper. |
| `get_chart_data()` `:1018` | Bounded reporting read with padded date series. `P:` `public/index.php:25`. | Explicit staff scope; direct Order aggregate; returns complete N-day array on failures. | `includes/dashboard.php` or reporting module; medium date/scope risk. |
| `is_admin()` `:1095` | Compatibility Auth wrapper. `P:` shared sidebar `:17,46`; `T:` integration and auth source tests. | Reads global `$conn` null-safely and delegates to Auth; Auth also preserves session/global staff record. | Migrate sidebar last among auth callers; high global/session compatibility risk. |
| `require_admin()` `:1105` | Compatibility Auth wrapper. No verified `P:` caller; `T:` auth source tests. | Reads global `$conn`, delegates to terminating Auth guard. | Retire after zero-caller proof; high response-contract sensitivity. |

### Staff administration

| Function / location | Type and callers | Dependencies / duplication | Candidate, risk, and order |
|---|---|---|---|
| `get_staff_members()` `:1115` | Bounded read. `P:` settings `:381`; `T:` database `:380`. | Explicit connection, normalized page size/offset, no password fields. | Staff module; low/medium read risk, extract before staff writes only if wrapper contract is tested. |
| `create_staff_member()` `:1158` | Staff mutation. `P:` settings `:209`; `S:` browser QA seed `:90`; `T:` database and auth source tests. | Password policy/hash, role allow-list, direct insert; no explicit transaction. | Staff module; high security risk; migrate with settings and seed callers. |
| `update_staff_member()` `:1211` | Staff mutation/transaction. `P:` settings `:227`; `T:` database and auth source tests. | Locks target/admin rows, optional password hash, last-active-admin demotion rule, rollback. | Staff module; high invariant risk; migrate after direct tests for every branch. |
| `delete_staff_member()` `:1346` | Staff mutation/transaction, implemented as deactivation. `P:` settings `:237`; `T:` auth source tests. | Locks target/admin rows, blocks deleting current admin and last active admin, rollback. | Staff module; high authorization/invariant risk. |
| `set_staff_active()` `:1462` | Staff mutation/transaction. `P:` settings `:251`; `T:` auth source tests. | Locks target/admin rows, self-protection, last-admin deactivation, rollback. | Staff module; high invariant risk; migrate after delete/update characterization. |

### Category domain

| Function / location | Type and callers | Dependencies / duplication | Candidate, risk, and order |
|---|---|---|---|
| `get_categories()` `:1585` | Unbounded read with product count. `T:` Catalog source contract only. | Direct query plus `fetch_all()`; duplicates Catalog category read domain. | Retire or replace; do not extract unchanged. |
| `count_categories()` `:1605` | Compatibility bounded count. `T:` database `:949`. | Delegates to Catalog. | Retain until compatibility callers migrate; low risk. |
| `get_categories_page()` `:1610` | Compatibility bounded page. `T:` database `:950,953,958-960`. | Delegates to Catalog. | Retain until compatibility callers migrate; low risk. |
| `get_categories_for_selector()` `:1615` | Compatibility bounded selector. `T:` database `:961`. | Delegates to Catalog. | Retain until compatibility callers migrate; low risk. |
| `get_category_by_id()` `:1623` | Uncalled single-record read. `T:` Catalog source contract only. | Direct prepared SQL; no verified runtime caller. | Retire after zero-caller check or move only with a real caller; low runtime risk. |
| `create_category()` `:1664` | Category mutation. `P:` categories `:35`; `T:` database `:686,933`. | Uniqueness lookup plus insert; explicit connection; no transaction across the two statements. | `includes/categories.php`; medium behavior risk, preserve duplicate/failure behavior. |
| `update_category()` `:1731` | Category mutation. `P:` categories `:52`; `T:` database `:689,690`. | Uniqueness lookup, General rename guard, update; no transaction. | Categories module; medium/high risk due General invariant and multiple reads. |
| `delete_category()` `:1822` | Category mutation/transaction. `P:` categories `:62`; `T:` database `:691,841,1683`. | General guard, transaction, delete, product reassignment query, rollback. | Categories module; high risk; extract separately from create/update. |

### Customer and supplier domains

| Function / location | Type and callers | Dependencies / duplication | Candidate, risk, and order |
|---|---|---|---|
| `get_customers()` `:1956` | Unbounded read. `T:` export-streaming source contract only. | Direct query plus `fetch_all()`; duplicates People domain. | Retire or replace; do not extract unchanged. |
| `count_customers()` `:1972` | Compatibility bounded count. `T:` database `:970,979`. | Delegates to People. | Retain until compatibility callers migrate; low risk. |
| `get_customers_page()` `:1977` | Compatibility bounded page. `T:` database `:971,972,975-978`. | Delegates to People. | Retain until compatibility callers migrate; low risk. |
| `get_customers_for_selector()` `:1982` | Compatibility bounded selector. `T:` database `:980`. | Delegates to People. | Retain until compatibility callers migrate; low risk. |
| `get_customer_by_id()` `:1990` | Uncalled single-record read. `T:` People source contract only. | Direct prepared SQL; no verified runtime caller. | Retire after zero-caller proof; low runtime risk. |
| `create_customer()` `:2031` | Customer mutation. `P:` customers `:36`; `T:` database, backup integration `:147`. | Normalizes fields, inserts one row, protects only through caller policy; no transaction. | `includes/people.php` or a future Customer module; medium risk. |
| `update_customer()` `:2075` | Customer mutation. `P:` customers `:58`; `T:` database `:670`. | Normalizes fields, blocks default Walk-in Customer ID 1, updates directly. | People/Customer write module; medium risk. |
| `delete_customer()` `:2116` | Customer mutation. `P:` customers `:72`; `T:` database `:672`. | Blocks default ID 1, deletes directly, affected-row check. | People/Customer write module; medium risk. |
| `get_suppliers()` `:2156` | Unbounded read. `T:` export-streaming source contract only. | Direct query plus `fetch_all()`; duplicates People domain. | Retire or replace; do not extract unchanged. |
| `count_suppliers()` `:2172` | Compatibility bounded count. `T:` database `:993,1002`. | Delegates to People. | Retain until compatibility callers migrate; low risk. |
| `get_suppliers_page()` `:2177` | Compatibility bounded page. `T:` database `:994-1001`. | Delegates to People. | Retain until compatibility callers migrate; low risk. |
| `get_suppliers_for_selector()` `:2182` | Compatibility bounded selector. `T:` database `:1003`. | Delegates to People. | Retain until compatibility callers migrate; low risk. |
| `get_supplier_by_id()` `:2190` | Uncalled single-record read. `T:` People source contract only. | Direct prepared SQL; no verified runtime caller. | Retire after zero-caller proof; low runtime risk. |
| `create_supplier()` `:2231` | Supplier mutation. `P:` suppliers `:36`; `T:` database. | Normalizes fields, inserts one row, no transaction. | People/Supplier write module; medium risk. |
| `update_supplier()` `:2275` | Supplier mutation. `P:` suppliers `:58`; `T:` database `:679`. | Normalizes fields, blocks default General Supplier ID 1, direct update. | People/Supplier write module; medium risk. |
| `delete_supplier()` `:2316` | Supplier mutation. `P:` suppliers `:72`; `T:` database `:681`. | Blocks default ID 1, direct delete, affected-row check. | People/Supplier write module; medium risk. |

### Dashboard/reporting aggregates

| Function / location | Type and callers | Dependencies / duplication | Candidate, risk, and order |
|---|---|---|---|
| `get_inventory_valuation()` `:2356` | Scalar reporting aggregate. `P:` index `:26`; `T:` inventory source test. | Direct Product aggregate, returns `0.0` on failure; overlaps dashboard Product reads. | Dashboard/reporting module; low mutation risk, medium numeric contract risk. |
| `get_top_selling_products()` `:2375` | Bounded reporting read. `P:` index `:27`. | Explicit optional staff scope, sale-only OrderDetail aggregate, limit capped at 50, `fetch_all()` bounded. | Dashboard/reporting module; medium scope/ordering risk. |
| `get_category_sales_distribution()` `:2427` | Bounded reporting read. `P:` index `:28`. | Explicit optional staff scope, sale-only aggregate, normalized limit, two query branches, `fetch_all()` bounded. | Dashboard/reporting module; medium scope/default risk. |

## Production caller map by page/module

This is the page-oriented view of the same evidence and makes migration order
visible:

| Production surface | Remaining facade calls | Notes |
|---|---|---|
| `public/login.php` | `build_login_rate_limit_key`, `login_rate_limit_check`, `login_rate_limit_record_failure`, `login_rate_limit_reset`, `redirect` | Auth/rate-limit and HTTP boundaries; highly behavior-sensitive. |
| `public/index.php` | `get_dashboard_stats`, `get_chart_data`, `get_inventory_valuation`, `get_top_selling_products`, `get_category_sales_distribution`, `get_low_stock_products` | Read-only dashboard/reporting; explicit staff scope for order aggregates. |
| `public/products.php` | `handle_image_upload`, `delete_newly_uploaded_image` | Product services and Catalog are already direct; upload remains facade-owned. |
| `public/stock_movements.php` | `get_product_by_id`, `get_pos_products` | Remaining Catalog compatibility callers; Inventory reads/adjustment are direct. |
| `public/settings.php` | `password_meets_policy`, `create_staff_member`, `update_staff_member`, `delete_staff_member`, `set_staff_active`, `get_staff_members` | Staff security and transaction boundary. |
| `public/categories.php` | `create_category`, `update_category`, `delete_category` | Category writes remain facade-owned; reads are Catalog-direct. |
| `public/customers.php` | `create_customer`, `update_customer`, `delete_customer` | Customer writes remain facade-owned; reads are People-direct. |
| `public/suppliers.php` | `create_supplier`, `update_supplier`, `delete_supplier` | Supplier writes remain facade-owned; reads are People-direct. |
| `includes/layouts/sidebar.php` | `is_admin` | Sole remaining production Auth wrapper caller; layout bootstrap/global coupling. |
| Other public pages | No remaining facade implementation calls beyond shared required helpers | They use focused Auth/Catalog/People/Inventory/Products/Orders modules directly where migrated. |

## Dependency and coupling findings

### Explicit dependencies

Most database functions receive `$conn` explicitly. Staff mutation functions
also receive the current administrator ID where needed. Reporting reads receive
an optional staff scope explicitly. Upload functions receive the uploaded file
array or relative path explicitly. This is a favorable extraction seam.

### Hidden dependencies

- `verify_login()`, `is_admin()`, and `require_admin()` read the global `$conn`
  for compatibility. Auth itself intentionally mutates session state and
  `$GLOBALS['current_staff_record']`.
- The facade's write functions call shared normalization helpers by global
  function name. Moving a domain without making those dependencies explicit
  would create a new hidden module dependency.
- `includes/functions.php` requires every extracted module, so requiring the
  facade has broad side effects and exposes all legacy names to every page.
- `includes/layouts/header.php` requires the facade and performs session and
  security-header side effects; the sidebar therefore inherits the facade's
  global Auth contract.

### Duplicate SQL and logic

Confirmed duplication includes:

1. Unbounded Product, StockMovement, Order, Category, Customer, and Supplier
   loaders duplicate read domains already represented by Catalog, Inventory,
   Orders, and People modules.
2. `get_dashboard_stats()` repeats Product and Order aggregate concepts also
   used by valuation, chart, and order-summary services.
3. `get_orders()` and `get_orders_for_staff()` duplicate joins and visibility
   concepts from the bounded Orders services.
4. `get_low_stock_products()` is a direct Product/Category query adjacent to
   Inventory and dashboard ownership, but is not shared with either module.
5. Customer and supplier write implementations are structurally duplicated,
   as are their uncalled single-record loaders.
6. Category write paths contain repeated prepare/bind/execute/error patterns;
   these are implementation duplication, but replacing them with generic CRUD
   helpers would risk changing failure and transaction contracts.

No duplicate authentication policy was found outside `includes/auth.php` and
the compatibility wrappers. No `$GLOBALS` access was found in
`includes/functions.php`; the only explicit `global` declarations are the
three Auth compatibility wrappers.

## Response and side-effect contracts that constrain migration

The current code uses several distinct failure contracts:

- scalar dashboard reads return fixed arrays or numeric defaults (`0`, `0.0`);
- list reads return `[]` on query/connection failures;
- single-record reads return `null` on failure or missing data;
- order detail reads return `[]` when unauthorized or unavailable;
- mutation functions generally return `bool`, while `create_order()` retains
  an order ID or `false` through its wrapper;
- Auth guards redirect or terminate with route-specific status behavior;
- upload helpers return a relative `uploads/<random-name>` path or `false`;
- rate-limit functions return structured status arrays or `false` for reset;
- staff/category deletion and update transactions must preserve rollback and
  last-administrator protections.

Migration tests must assert these values, not only SQL equivalence. The current
Audit and CSRF boundaries belong to pages or extracted services as documented;
facade extraction must not move page authorization or CSRF order implicitly.

## Recommended migration order

1. **Dashboard stats read service — recommended next batch.** Extract only
   `get_dashboard_stats()` into a focused dashboard module, keep the exact
   wrapper, migrate `public/index.php`, and add admin/cashier/default/failure
   characterization tests. This is one read-only caller and has no transaction
   or mutation boundary.
2. **Remaining dashboard/reporting reads.** Extract `get_chart_data()`,
   `get_inventory_valuation()`, `get_top_selling_products()`, and
   `get_category_sales_distribution()` with explicit staff-scope and date/
   limit contracts. Keep this separate from dashboard stats if the first batch
   shows different ownership or output lifecycles.
3. **Low-stock and remaining stock-page Catalog callers.** Move
   `get_low_stock_products()` to the chosen Inventory/dashboard boundary and
   migrate `stock_movements.php` from `get_product_by_id()` and
   `get_pos_products()` to Catalog names. Preserve navbar behavior and limit
   normalization.
4. **Upload helper module.** Extract upload validation and cleanup together;
   retain page-owned auth, CSRF, messages, and Product service calls.
5. **Category writes.** Extract create/update first behind wrappers; extract
   deletion separately because of General-category and Product reassignment
   transaction invariants.
6. **Customer and supplier writes.** Extract one domain at a time into the
   existing People boundary or a dedicated write module, preserving default
   record protections and normalization.
7. **Staff read and mutation services.** Extract staff reads first, then
   mutations with direct tests for password policy, active roles, self-
   deactivation, last-admin locking, audit, and rollback.
8. **Rate-limit module and remaining Auth/HTTP wrappers.** Move the complete
   rate-limit helper cluster together. Migrate the shared sidebar, then retire
   `verify_login()`, `is_admin()`, `require_admin()`, and `redirect()` only after
   compatibility callers are gone.
9. **Legacy loader retirement.** Replace or remove unbounded loaders only after
   every test/script/source-contract caller has an explicit bounded alternative
   and a final repository-wide caller audit is clean.

This order deliberately puts read-only, single-caller work before staff,
category, or authentication transactions. It reduces facade ownership while
keeping the highest-risk invariants isolated.

## Historical constraints and non-goals

- Batch 7A–8D extraction documents describe the behavior at their historical
  checkpoints; they are not current caller inventories and must remain intact.
- Legacy function names remain available until a dedicated retirement batch
  proves no verified callers remain and records the compatibility decision.
- No schema, migration, UI, authorization, CSRF, session, upload, order, or
  inventory behavior is changed by this audit.
- External production requirements and CI/release checks remain outside the
  facade ownership decision.

## Phase 3A verification record

The audit itself changed no production or test file. Verification at the audit
revision recorded:

- **77 facade functions** inventoried from `includes/functions.php`;
- repository-wide caller search completed across `public/`, `includes/`,
  `tests/`, and `scripts/`; no dynamic facade-function calls found;
- PHP syntax check passed for all PHP files under `config`, `database`,
  `includes`, `public`, `scripts`, and `tests`;
- full disposable regression passed: **1,540 assertions** (`944` unit,
  `596` integration);
- `git diff --check` passed;
- one documentation-only local commit created; no push performed.
