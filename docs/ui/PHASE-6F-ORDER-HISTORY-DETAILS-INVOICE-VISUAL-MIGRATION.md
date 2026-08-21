# Phase 6F — Order History, Order Details, and Invoice Visual Migration

Status: complete
Date: 2026-08-21
Scope: `public/order_history.php`, `public/print_invoice.php`, shared Phase 6F
styles, focused UI contracts, and disposable browser evidence.

## Boundary and guardrails

Phase 6F completes the dense-data visual migration for the order-history and
invoice workflow. The change is presentation-only. `public/get_order_details.php`
was audited but did not require a visual change because it is a JSON boundary,
not a rendered surface.

No order query, pagination calculation, scope calculation, route, authentication,
authorization, audit event, CSRF contract, export contract, compatibility wrapper,
database schema, or business behavior changed.

## Shared components reused

The history page and detail modal now use the shared ownership in
`public/assets/css/style.css`:

| Component | Use |
| --- | --- |
| `.data-page`, `.data-page-header` | reporting context, title hierarchy, and admin actions |
| `.data-surface`, `.data-surface__body` | summary cards and ledger surface |
| `.data-toolbar` | sale/all/purchase filters and page-size control |
| `.data-table-shell`, `.data-table` | bounded, dense history and line-item tables |
| `.data-pagination` | existing page navigation with the same query parameters |
| `.data-empty-state` | intentional no-transaction state |
| `.data-modal`, `.data-modal__header`, `.data-modal__body`, `.data-modal__footer` | detail and export modal hierarchy |

Phase 6F adds one order-history visual vocabulary: restrained teal accents,
semantic sale/purchase accents, tabular numeric emphasis, contained table
scrolling, and a consistent detail/invoice document treatment. It does not add a
framework, build step, or page-specific token set.

## Preserved contracts

- Administrators retain global authorized history, purchase filtering, export,
  detail, and invoice access.
- Cashiers retain sale-only history and cannot see the purchase filter or
  purchase controls.
- `orders_count`, `orders_get_summary`, and `orders_get_page` are called with
  the same existing scope, type, page-size, and offset values.
- `order-details-btn`, `data-order-id`, `orderDetailsModal`, all modal field
  IDs, `downloadPdfBtn`, and the `get_order_details.php?id=` AJAX path remain.
- The detail endpoint continues to return 401 for anonymous access and 404 for
  missing or cross-staff orders; the browser suite verifies cashier denial of an
  administrator-created order.
- The invoice route continues to use `sanitize_id`, the administrator/cashier
  staff scope, server-side order authorization, audit failure logging, and
  `window.print()` behavior.
- Export form names, `export_report.php` route, filter query parameters,
  pagination links, messages, and redirects remain unchanged.

## Legacy selectors

No legacy stylesheet rule was deleted in this phase. Repository-wide evidence
shows the shared Bootstrap and legacy selectors remain used by other pages or
test/security contracts. The history markup retires its old dashboard-card
composition and uses the shared data components instead. `.history-kpi-value`
is retained only as a sanitized screenshot masking hook; `.ui-modal-title` and
`.ui-col-*` remain shared compatibility hooks. The existing invoice class and
ID hooks (`.invoice-box`, `.invoice-items-table`, `#invoicePrintContainer`,
`#invoicePrintWrapper`, `.invoice-item-*`) remain for PDF/print JavaScript and
are restyled rather than renamed.

## Responsive and accessibility review

- 375px: header actions stack, summary cards become a single-column operational
  stack, filters remain touch-sized, and table/detail content scrolls inside a
  contained shell without page-level horizontal overflow.
- 768px: the summary and ledger hierarchy remain balanced while the table keeps
  its intentional dense-data width.
- 1440px: the page uses the sidebar and controlled whitespace for a fast review
  workflow; detail metadata, party information, line items, and totals scan in
  a clear sequence.
- Existing visible focus styles, semantic labels, button targets, reduced-motion
  rules, and role-specific visibility remain active.
- Browser QA captured no unexpected application responses or critical console
  errors.

## Visual evidence

Screenshots were captured from disposable seeded data and sanitized by the
browser harness. Table bodies and sensitive account values are masked.

| State | 375px before / after | 768px before / after | 1440px before / after |
| --- | --- | --- | --- |
| Admin history list | [before](baselines/phase-6f-before/mobile-375-admin-order-history.png) / [after](baselines/phase-6f-after/mobile-375-admin-order-history.png) | [before](baselines/phase-6f-before/tablet-768-admin-order-history.png) / [after](baselines/phase-6f-after/tablet-768-admin-order-history.png) | [before](baselines/phase-6f-before/desktop-1440-admin-order-history.png) / [after](baselines/phase-6f-after/desktop-1440-admin-order-history.png) |
| Admin order details | [before](baselines/phase-6f-before/mobile-375-admin-order-history-detail.png) / [after](baselines/phase-6f-after/mobile-375-admin-order-history-detail.png) | [before](baselines/phase-6f-before/tablet-768-admin-order-history-detail.png) / [after](baselines/phase-6f-after/tablet-768-admin-order-history-detail.png) | [before](baselines/phase-6f-before/desktop-1440-admin-order-history-detail.png) / [after](baselines/phase-6f-after/desktop-1440-admin-order-history-detail.png) |
| Cashier history scope | [before](baselines/phase-6f-before/mobile-375-cashier-order-history.png) / [after](baselines/phase-6f-after/mobile-375-cashier-order-history.png) | [before](baselines/phase-6f-before/tablet-768-cashier-order-history.png) / [after](baselines/phase-6f-after/tablet-768-cashier-order-history.png) | [before](baselines/phase-6f-before/desktop-1440-cashier-order-history.png) / [after](baselines/phase-6f-after/desktop-1440-cashier-order-history.png) |

The invoice route was exercised as an authenticated response and its rendered
print document was visually inspected through the same invoice class/ID
contract. The hidden HTML-to-PDF document and standalone print route now share
the Phase 6A surface, border, typography, and total hierarchy without changing
the print trigger or data population behavior.

## Verification evidence

| Gate | Result |
| --- | --- |
| Focused Phase 6F UI source contract | PASS — 95 assertions |
| Disposable browser QA | PASS — 30/30 at 375px, 768px, and 1440px |
| Full disposable regression | PASS — 4,171 assertions (2,544 unit, 1,627 integration) |
| PHP lint | PASS — 96 tracked PHP files |
| JavaScript syntax | PASS — 4 tracked JavaScript files |
| Repository security scan | PASS — 11 assertions |
| Supply-chain scan | PASS — 12 assertions |
| Release-integrity scan | PASS — 9 assertions |
| `git diff --check` | PASS |

RED and GREEN implementation evidence is recorded in the repository commits.
No production read boundary or authorization logic was altered. Nothing was
pushed or merged.
