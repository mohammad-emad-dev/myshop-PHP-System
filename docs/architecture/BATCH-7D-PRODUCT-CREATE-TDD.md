# Batch 7D: Product Create Service Extraction

Batch 7D moved the implementation of create_product() into the focused
includes/products.php module as products_create(). The public product page
continued using the legacy compatibility wrapper. Upload validation, request
validation, authorization, CSRF, messages, and rendering remained page-owned.

## Implementation and compatibility boundary

products_create() owns the product-creation transaction, General-category
fallback, barcode normalization, initial stock-history integration, success
and failure audit writes, commit ordering, rollback, and safe failure
diagnostics. create_product() remains a delegation-only wrapper with its
original signature and boolean return contract. The module accepts explicit
dependencies and does not read session or global state.

## TDD and verification evidence

| Evidence | Result |
|---|---|
| Characterization commit | 12c4a54f79c9d982f3fb7b86bb5acbeecd813d17 — test(products): characterize product creation service |
| Extraction commit | c0aa6319d044b39d4456930cd1b0d90d4aee6124 — refactor(products): extract product creation service |
| Rollback-audit coverage commit | 58cd04577ae974878b387428ffb7280a5032a894 — test(products): verify rollback audit isolation |
| Focused product tests | 37 assertions |
| Full disposable regression | 1284 assertions |
| Browser QA | Not required for this page-unchanged service extraction; no Browser QA result is claimed for this batch. |

Focused tests covered successful creation, initial stock movement, success
audit metadata, movement/audit failure rollback, invalid connections, and the
continued use of the compatibility wrapper. The full regression retained
existing order, inventory, authorization, CSRF, and security behavior.
