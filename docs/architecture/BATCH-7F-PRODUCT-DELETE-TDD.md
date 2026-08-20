# Batch 7F: Product Delete Service Extraction

Batch 7F moved the implementation of delete_product() into the focused
includes/products.php module as products_delete(). The public product page
remained unchanged and continued using the legacy compatibility wrapper.
Request validation, authorization, CSRF, upload handling, generic messages,
HTTP responses, and rendering remain page-owned.

## Implementation and compatibility boundary

products_delete() owns ID normalization, the locked product lookup,
OrderDetail and StockMovement historical-use checks, deletion affected-row
validation, the nullable actor audit contract, commit ordering, rollback,
statement cleanup, and safe rollback diagnostics. delete_product() remains a
delegation-only wrapper with its original signature and boolean return
contract. The module accepts explicit dependencies and does not read session
or global state.

## TDD and verification evidence

| Evidence | Result |
|---|---|
| Characterization commit | 1d09747f84a53fd737852dd777c6f5107dd1f9cf — test(products): characterize product deletion service |
| Extraction commit | b942f1bb611cc79e5cd23238688e5a4197ca18c0 — refactor(products): extract product deletion service |
| Focused product tests | 106 assertions |
| Full disposable regression | 1409 assertions |
| Browser QA | Not required for this page-unchanged service extraction; no Browser QA result is claimed for this batch. |

Focused tests covered direct service and wrapper success, nullable actor IDs,
invalid IDs, missing products, OrderDetail and StockMovement history guards,
closed connections, audit insertion failure, rollback, and the unchanged
public-page wrapper boundary. The full regression retained existing order,
inventory, authorization, CSRF, and security behavior.
