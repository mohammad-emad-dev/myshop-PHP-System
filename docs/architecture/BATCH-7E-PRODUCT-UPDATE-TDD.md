# Batch 7E: Product Update Service Extraction

Batch 7E moved the implementation of update_product() into the focused
includes/products.php module as products_update(). The public product page
continued using the legacy compatibility wrapper. Upload handling, request
validation, authorization, CSRF, messages, and rendering remained page-owned.

## Implementation and compatibility boundary

products_update() owns the product-row lock, stock validation and delta
calculation, General-category fallback, barcode normalization, image and
no-image update paths, conditional stock-history integration, audit writes,
commit ordering, rollback, and safe failure diagnostics. update_product()
remains a delegation-only wrapper with its original signature and boolean
return contract. The module accepts explicit dependencies and does not read
session or global state.

## TDD and verification evidence

| Evidence | Result |
|---|---|
| Characterization commit | 6869508d48e3a3630a38c94156ee5c2762309906 — test(products): characterize product update service |
| Extraction commit | f074e0339d33d95fb9e5d1246de3f2e81d2b75a2 — refactor(products): extract product update service |
| Failure-audit coverage commit | 75115b76431b580f68822e11f1a55123e91ad73d — test(products): preserve update failure audits |
| Focused product tests | 74 assertions |
| Full disposable regression | 1360 assertions |
| Browser QA | Not required for this page-unchanged service extraction; no Browser QA result is claimed for this batch. |

Focused tests covered direct service and wrapper success, stock increases and
decreases, no-op updates, image/no-image paths, category and barcode behavior,
movement and audit failure rollback, invalid connections, and the preserved
public-page wrapper boundary. The full regression retained existing product,
order, inventory, authorization, CSRF, and security behavior.
