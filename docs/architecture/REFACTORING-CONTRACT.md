# MyShop refactoring contract

This contract is the safety boundary for future modularization. Batch 3
extracts only bounded Catalog category reads and preserves application
behavior.

## Behavior that must remain unchanged

- Server-rendered PHP remains the primary request model.
- Existing routes, query parameters, HTTP methods, redirects, and response
  statuses remain unchanged.
- Existing admin and cashier authorization decisions remain server-side.
- CSRF checks remain required on every existing state-changing form/action.
- Session cookie attributes, idle timeout, session regeneration, logout
  invalidation, and CSRF token refresh remain unchanged.
- User-facing failures remain generic and must not expose SQL, credentials,
  paths, stack traces, or secret values.
- Existing audit events, action names, actor scoping, and success/failure
  outcomes remain compatible.
- Database transaction boundaries, row locks, stock invariants, order totals,
  price authority, and rollback behavior remain unchanged.
- Upload MIME checks, size/dimension/pixel limits, canonical path checks,
  random filenames, and writable-volume boundaries remain unchanged.
- CSV headers, filenames, ordering, UTF-8 BOM behavior, formula-injection
  protection, and bounded streaming remain unchanged.
- Health and readiness response contracts remain unchanged.
- Catalog search, pagination, ordering, bounded POS loading, exact barcode
  lookup, category selector ordering, and their legacy failure values remain
  unchanged.
- Category search, pagination, deterministic name/ID ordering, `product_count`,
  bounded page sizes, and empty-result behavior remain unchanged.

## URLs and routes that must remain unchanged

The following public paths are compatibility contracts:

```text
login.php
index.php
products.php
categories.php
stock_movements.php
orders.php
order_history.php
get_order_details.php
pos_product_lookup.php
customers.php
suppliers.php
audit_log.php
export_report.php
print_invoice.php
settings.php
backup_database.php
health.php
ready.php
```

No route should be renamed, removed, or moved behind a new URL during an
extraction batch.

## Form and request field names

Existing names must remain valid until every browser and HTTP test is migrated
in the same reviewed change. Important fields include:

- Login/logout: `username`, `password`, `action`, `csrf_token`.
- Product CRUD: `action`, `id`, `name`, `description`, `price`, `stock`,
  `alert_threshold`, `category_id`, `barcode`, `image`.
- Category CRUD: `action`, `id`, `name`, `description`, `csrf_token`.
- Customer/supplier CRUD: `action`, `id`, `name`, `phone`, `email`, `address`,
  `csrf_token`.
- Stock adjustment: `action=adjust_stock`, `product_id`, `quantity`, `reason`,
  `csrf_token`.
- POS/order creation: `complete_order`, `order_type`, `cart_data`,
  `customer_id`, `supplier_id`, `csrf_token`.
- Settings/staff: `action`, `full_name`, `username`, `current_password`,
  `new_password`, `confirm_password`, `staff_id`, `staff_username`,
  `staff_full_name`, `staff_role`, `staff_password`, `is_active`,
  `csrf_token`.
- Backup: `csrf_token`, `current_password`.

Names are listed from current page source and must be rechecked before each
domain extraction.

## Compatibility-wrapper policy

1. Existing callers may continue requiring `includes/functions.php`.
2. New focused modules must not require the compatibility facade back.
3. A moved function keeps its legacy name and argument/return contract through
   a thin wrapper until all call sites are migrated.
4. Wrappers must not duplicate business logic.
5. Each extraction must migrate one bounded caller group at a time.
6. A wrapper may be marked deprecated in documentation, but it must remain
   operational until call-site verification is complete.

## Rules for deleting legacy functions

A legacy function may be deleted only when all of the following are true:

- `rg`/equivalent call-site inventory shows no production, script, or test
  caller remains.
- The compatibility wrapper is no longer required by documented external use.
- Unit, integration, HTTP, browser, schema, and relevant operational tests pass.
- The replacement preserves return values, exceptions, redirects, headers,
  audit events, session effects, and database transaction behavior.
- The complete diff has been reviewed for indirect calls and dynamic includes.
- A rollback path exists through the previous commit or retained wrapper.

## Database invariants

The following are protected contracts:

- Runtime application accounts remain restricted to CRUD privileges.
- Schema/root accounts never enter the normal app service environment.
- Sale orders use server-side product prices and reduce stock atomically.
- Purchase orders remain admin-only.
- Stock movement history remains consistent with stock mutations.
- Failed product/order/profile transactions leave no partial business rows.
- Staff integrity rules prevent unsafe self-deactivation or removal of the last
  active administrator.
- `LoginRateLimit` remains ephemeral and excluded from application backups.
- Migrations remain ordered and are never run from an HTTP request or startup.

## Baseline verification contract

Every architectural extraction should run, as applicable:

```text
PHP syntax checks for tracked PHP files
JavaScript syntax checks for tracked JavaScript files
tests/run.php
tests/validate_schema.php
php scripts/repository-security-check.php
php scripts/ci-supply-chain-check.php
php scripts/release-integrity-check.php
docker compose ... config --quiet
docker compose -f docker-compose.production.yml ... config --quiet
Browser QA against a disposable environment
Disposable production runtime smoke
git diff --check
```

No test result may be inferred from source inspection. Results must be recorded
with the exact command and environment.

## Recommended next batch

After Batch 3, the next candidate is a similarly bounded read-side extraction
for customer and supplier pagination. It must be planned separately and must
not include customer/supplier writes.

Acceptance criteria:

- Customer/supplier search, pagination, ordering, selector behavior, and
  failure values are unchanged.
- Existing assertion count is unchanged or higher.
- Browser QA customer/supplier journeys remain passing.
- No customer/supplier read SQL remains in migrated page controllers.
- The old function names still work through wrappers.

## Rollback instructions

Batch 3 can be rolled back without a schema or data rollback:

1. Revert the local Batch 3 commit, or restore the previous local commit if it
   is not shared.
2. Confirm `public/categories.php` uses the legacy category read names again
   and that the category wrappers still delegate through `catalog.php`.
3. Re-run `git diff --check` and the previously passing test suite.

No database rollback, migration rollback, container rollback, or production
configuration rollback is required for this batch.
