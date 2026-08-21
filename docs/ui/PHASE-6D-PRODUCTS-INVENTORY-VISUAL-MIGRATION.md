# Phase 6D — Products and Inventory Visual Migration

Status: complete
Date: 2026-08-21
Scope: `public/products.php`, `public/stock_movements.php`, and their shared visual styles only.

## Intent and guardrails

Phase 6D applies the Phase 6A design system to the two dense-data operational
surfaces that follow the Dashboard and POS migrations. The migration is visual
and structural at the markup level only. Product queries, inventory queries,
pagination values, exports, uploads, redirects, authorization, CSRF handling,
and business contracts were left unchanged.

The existing DOM and JavaScript contracts remain in place, including product
form names and IDs, `data-product-*` attributes, `#productsTable`,
`#product_id`, `#ledgerPageSize`, both product modals, the stock adjustment
modal, and the existing edit/image/delete hooks.

## Shared component ownership

The Phase 6D component layer is owned by the shared stylesheet in
`public/assets/css/style.css`. It is page-scoped by `.data-page` and reused by
both migrated pages:

| Component | Responsibility |
| --- | --- |
| `.data-page`, `.data-page-header` | page context, title hierarchy, and primary actions |
| `.data-surface`, `.data-surface__body` | consistent dense-data card surfaces |
| `.data-toolbar`, `.data-toolbar__field`, `.data-control-button` | search, filters, page size, and submit controls |
| `.data-table-shell`, `.data-table` | contained responsive table scrolling, headings, row rhythm, and focus |
| `.data-empty-state` | intentional empty and no-result messaging |
| `.data-pagination` | range summary and compact navigation |
| `.data-action-group`, `.data-action-button` | compact row-level actions and touch targets |
| `.data-image-thumb` | bounded product image presentation |
| `.data-modal`, `.data-modal__header`, `.data-modal__body`, `.data-modal__footer` | product and stock-adjustment modal hierarchy |

Products adds only semantic hooks for product rows, stock status, image cells,
and actions. Inventory adds only semantic hooks for the movement ledger,
quantity, movement type, reason, staff, and date cells. Neither page creates a
second token set or page-specific component vocabulary.

## Products migration

The Products page now has a catalog-operations header, a bounded search/page
size toolbar, a contained data table, explicit no-result messaging, compact
status/image/action cells, and a consistent pagination surface. The existing
Export CSV and Add Product actions remain administrator-only.

The create/edit modals now use the shared modal shell while retaining their
multipart forms, file input names, accepted image type, validation, upload
cleanup, CSRF fields, and existing JavaScript edit population behavior.

## Inventory migration

The Stock Ledger page now has an inventory-control header, a shared product
filter toolbar, a separate ledger surface with a record summary, a contained
movement table, semantic movement cells, and consistent pagination/empty
states. Product-scoped filtering and administrator-only export and adjustment
actions remain unchanged.

## Retained legacy selectors

No legacy selector was removed in this phase. Repository-wide evidence showed
that the retained hooks are still required:

- `.ui-page-heading`, `.ui-modal-title`, `.ui-search-input`, and `.ui-col-*`
  remain shared by other pages and existing source contracts.
- `.product-image-hover`, `.product-image-zoomed`,
  `.product-image-placeholder`, and `.product-img-wrapper` remain part of the
  existing product image/hover behavior.
- Bootstrap `.table-responsive`, modal, form, and button classes remain in
  place for existing behavior and framework contracts.

The Phase 6D stylesheet adds no decorative gradients and keeps the existing
Phase 6A tokens, focus treatment, reduced-motion rule, and responsive breakpoints.

## Responsive and accessibility review

- 375px: page actions and filter controls stack; data tables remain in an
  intentional contained horizontal scroll region rather than widening the
  page.
- 768px: sidebar context, header actions, toolbar, and pagination remain
  balanced within the tablet composition.
- 1440px: headers, actions, filters, and tables form a dense desktop workflow
  with controlled whitespace.
- Keyboard focus remains visible through the shared focus-visible rules;
  reduced-motion users retain the global reduced-motion behavior.
- The browser harness reported no axe violations for the migrated surfaces,
  and no horizontal page overflow was detected at the three target widths.

## Visual evidence

The browser harness sanitized these screenshots using disposable catalog and
ledger data. Table bodies are masked by the harness where appropriate.

| Surface | 375px before / after | 768px before / after | 1440px before / after |
| --- | --- | --- | --- |
| Products | [before](baselines/phase-6d-before/mobile-375-admin-products.png) / [after](baselines/phase-6d-after/mobile-375-admin-products-after.png) | [before](baselines/phase-6d-before/tablet-768-admin-products.png) / [after](baselines/phase-6d-after/tablet-768-admin-products-after.png) | [before](baselines/phase-6d-before/desktop-1440-admin-products.png) / [after](baselines/phase-6d-after/desktop-1440-admin-products-after.png) |
| Stock Ledger | [before](baselines/phase-6d-before/mobile-375-admin-stock-ledger.png) / [after](baselines/phase-6d-after/mobile-375-admin-stock-ledger-after.png) | [before](baselines/phase-6d-before/tablet-768-admin-stock-ledger.png) / [after](baselines/phase-6d-after/tablet-768-admin-stock-ledger-after.png) | [before](baselines/phase-6d-before/desktop-1440-admin-stock-ledger.png) / [after](baselines/phase-6d-after/desktop-1440-admin-stock-ledger-after.png) |

## Verification evidence

| Gate | Result |
| --- | --- |
| Focused Products/Inventory UI source contracts | PASS — 109 assertions |
| Disposable PHP regression with `TEST_DB_*` variables | PASS — 3,920 assertions (2,293 unit, 1,627 integration) |
| Browser QA | PASS — 24/24 at 375px, 768px, and 1440px |
| PHP lint | PASS — 94 tracked PHP files |
| JavaScript syntax | PASS — 4 tracked JavaScript files |
| Repository security scan | PASS — 11 assertions |
| Supply-chain scan | PASS — 12 assertions |
| Release-integrity scan | PASS — 9 assertions |
| `git diff --check` | PASS |

Browser coverage includes products and ledger loading, shared dense-data
structure, search/page-size controls, export links, product add/edit modal
contracts, multipart image input, product-scoped ledger filtering, clear
filter behavior, keyboard/focus checks, role-aware controls, accessibility,
console/network checks, and responsive overflow checks. Existing upload
validation and authorization integration contracts remain in the full
regression suite.

No backend, schema, security, authorization, export, upload, or business
behavior was changed. Nothing was pushed or merged.
