# Phase 6E — Customers, Suppliers, and Categories Visual Migration

Status: complete
Date: 2026-08-21
Scope: `public/customers.php`, `public/suppliers.php`, `public/categories.php`, and the shared Phase 6E visual styles.

## Scope and guardrails

Phase 6E extends the Phase 6D dense-data pattern to the related CRUD surfaces.
The work is limited to presentation markup, semantic styling hooks, focused UI
contracts, and disposable browser evidence. No database query, schema, route,
authorization, CSRF, audit, compatibility wrapper, or mutation implementation
was changed.

All existing form names, IDs, `data-*` attributes, JavaScript hooks, redirects,
messages, pagination/search parameters, and modal IDs remain in place.

## Shared components reused

The three pages reuse the existing shared components owned by
`public/assets/css/style.css`:

| Component | Use |
| --- | --- |
| `.data-page`, `.data-page-header` | page context, title hierarchy, and actions |
| `.data-surface`, `.data-surface__body` | consistent CRUD surfaces |
| `.data-toolbar`, `.data-toolbar__field`, `.data-control-button` | search, page size, and submit controls |
| `.data-table-shell`, `.data-table` | dense table rhythm and contained responsive scrolling |
| `.data-pagination` | consistent range and navigation treatment |
| `.data-action-group`, `.data-action-button` | row action grouping and touch targets |
| `.data-empty-state` | composed no-result states |
| `.data-modal`, `.data-modal__header`, `.data-modal__body`, `.data-modal__footer` | create/edit modal hierarchy |

Phase 6E adds only page-semantic hooks: customer/supplier contact cells and
actions, category product counts and actions, and the protected default-category
state. It does not introduce another token set or visual vocabulary.

## Customer and Supplier surfaces

Customers and Suppliers now share the same people-operations data workflow:

- contextual page header and primary actions;
- consistent search and page-size toolbar;
- bounded, contained contact tables with readable name/contact hierarchy;
- compact row actions and protected system-default affordances;
- intentional empty states and pagination;
- shared create/edit modal shells.

Phone, email, address, required-name validation, multipart-free CRUD forms,
CSRF inputs, administrator-only export links, local JavaScript search hooks,
historical-order behavior, and deletion restrictions remain unchanged.

## Categories surface

Categories now uses the shared table, toolbar, pagination, empty-state, action,
and modal components. Product counts are given a dedicated scan-friendly cell,
and the General row is visibly identified as the protected default state.

The existing `categories_create`, `categories_update`, and `categories_delete`
flows remain untouched. The full disposable integration suite continues to
prove default-category protection, product reassignment, rollback atomicity,
failure cleanup, exact messages, and CSRF behavior.

## Retained legacy selectors

No old CSS selector was removed. Repository evidence shows these remain needed:

- `.ui-page-heading`, `.ui-modal-title`, `.ui-search-input`, and `.ui-col-*`
  remain shared compatibility hooks.
- `.table-row-hidden`, `.delete-form`, and `.edit-customer-btn`,
  `.edit-supplier-btn`, `.edit-category-btn` remain JavaScript contracts.
- Bootstrap `.table-responsive`, modal, form, button, and pagination classes
  remain required by the existing framework behavior.

## Responsive and accessibility review

- 375px: actions and controls stack; tables remain in intentional contained
  horizontal scroll regions without widening the page.
- 768px: headers, actions, filters, and table surfaces remain balanced beside
  the application shell.
- 1440px: the three pages use a consistent, efficient desktop CRUD workflow
  with controlled whitespace.
- Shared focus-visible behavior, touch-sized row actions, and reduced-motion
  rules remain active.
- Browser QA reported no critical accessibility findings, no unexpected
  application responses, and no horizontal page overflow at the target widths.

## Visual evidence

Screenshots were captured from disposable seeded data and sanitized by the
browser harness. Table bodies and sensitive values are masked where applicable.

| Page | 375px before / after | 768px before / after | 1440px before / after |
| --- | --- | --- | --- |
| Customers | [before](baselines/phase-6e-before/mobile-375-admin-customers.png) / [after](baselines/phase-6e-after/mobile-375-admin-customers-after.png) | [before](baselines/phase-6e-before/tablet-768-admin-customers.png) / [after](baselines/phase-6e-after/tablet-768-admin-customers-after.png) | [before](baselines/phase-6e-before/desktop-1440-admin-customers.png) / [after](baselines/phase-6e-after/desktop-1440-admin-customers-after.png) |
| Suppliers | [before](baselines/phase-6e-before/mobile-375-admin-suppliers.png) / [after](baselines/phase-6e-after/mobile-375-admin-suppliers-after.png) | [before](baselines/phase-6e-before/tablet-768-admin-suppliers.png) / [after](baselines/phase-6e-after/tablet-768-admin-suppliers-after.png) | [before](baselines/phase-6e-before/desktop-1440-admin-suppliers.png) / [after](baselines/phase-6e-after/desktop-1440-admin-suppliers-after.png) |
| Categories | [before](baselines/phase-6e-before/mobile-375-admin-categories.png) / [after](baselines/phase-6e-after/mobile-375-admin-categories-after.png) | [before](baselines/phase-6e-before/tablet-768-admin-categories.png) / [after](baselines/phase-6e-after/tablet-768-admin-categories-after.png) | [before](baselines/phase-6e-before/desktop-1440-admin-categories.png) / [after](baselines/phase-6e-after/desktop-1440-admin-categories-after.png) |

## Verification evidence

| Gate | Result |
| --- | --- |
| Focused Customers/Suppliers/Categories UI contracts | PASS — 156 assertions |
| Disposable PHP regression with `TEST_DB_*` variables | PASS — 4,076 assertions (2,449 unit, 1,627 integration) |
| Browser QA | PASS — 27/27 at 375px, 768px, and 1440px |
| PHP lint | PASS — 95 tracked PHP files |
| JavaScript syntax | PASS — 4 tracked JavaScript files |
| Repository security scan | PASS — 11 assertions |
| Supply-chain scan | PASS — 12 assertions |
| Release-integrity scan | PASS — 9 assertions |
| `git diff --check` | PASS |

Browser coverage exercised customer and supplier search, page size, modal
validation, create, update, delete, default-row restrictions, export visibility,
and cashier role restrictions. Category browser coverage exercised shared
layout, search, default General protection, edit modal hooks, add modal/CSRF
presence, invalid CSRF rejection, and cashier export restrictions. Existing
disposable integration tests cover category create/update/delete, product
reassignment, rollback/atomicity, and the historical customer/supplier foreign
key contracts.

Nothing was pushed or merged. No business, security, authorization, CSRF,
database, query, schema, or compatibility behavior was changed.
