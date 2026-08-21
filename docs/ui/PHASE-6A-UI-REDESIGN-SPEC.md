# Phase 6A: UI/UX Redesign Audit and Design System Foundation

Status: Foundation implemented; page migration remains staged

Date: 2026-08-21

## Product and design read

MyShop is a localhost-first inventory and POS workbench for administrators and
cashiers. The redesign is a preserve-and-modernize effort: information
architecture, routes, labels, form names, security boundaries, and business
flows remain stable while the visual system becomes quieter, more coherent,
and easier to operate during a busy sales or stock task.

Design direction: **Signal Workbench**. The visual language uses a deep teal
navigation rail, a pale mineral canvas, white work surfaces, one primary teal
action color, and semantic colors reserved for success, warning, information,
and destructive states. It is intentionally practical rather than decorative:
clear hierarchy, compact but breathable data surfaces, predictable focus, and
strong mobile collapse rules.

Design dials for this product UI:

- Variance: 5/10. Differentiated enough to feel intentional, restrained enough
  for an operational tool.
- Motion: 2/10. Motion communicates focus, hover, and state changes only.
- Density: 6/10. Tables and POS controls remain efficient, with consistent
  spacing and readable grouping.

## Audit evidence

The audit covered the shared layout templates, all requested public surfaces,
the existing stylesheet and script, current Browser QA journeys, and the
curated screenshot assets already in the repository. Shared pages currently
load Bootstrap 5.3.2, Font Awesome 6.4.2, the existing Inter/Outfit font
assets, `style.css`, and the unchanged `script.js` toggle/logout/progress
behavior.

| Surface | Current strengths | Current visual debt | Priority |
| --- | --- | --- | --- |
| Dashboard | Clear KPI, chart, top products, category, and quick-action regions | Multiple card treatments, competing accent colors, decorative hover effects, and large visual weight before the data | High |
| POS/order screen | Barcode field, search, category filter, product cards, and cart are easy to locate | Product/catalog and cart surfaces do not share one panel language; mobile density needs dedicated validation | High |
| Products | Search, page size, export, CRUD actions, stock state, and pagination are present | Table header, action buttons, thumbnails, and empty state use several unrelated radii and color treatments | High |
| Inventory/stock movements | Ledger structure and filters support operational review | Table and filter controls inherit broad global overrides; status semantics need a single badge rule | Medium |
| Customers | CRUD form, search, selector, pagination, and protected default record behavior remain clear | Page header and table surface differ from suppliers despite identical workflow shape | Medium |
| Suppliers | CRUD form, search, selector, pagination, and historical-order behavior remain clear | Same duplicated page-specific visual treatment as customers | Medium |
| Categories | Default-category protection is visible and understandable | Modal, table, count badge, and action controls mix rounded and pill conventions | Medium |
| Order history/details | Summary cards, scoped history, details modal, invoice link, and print flow are available | Summary and table surfaces need shared hierarchy; detail modal has separate legacy spacing rules | Medium |
| Login | Focused sign-in path with visible labels and feedback | Current dark glass/orb treatment is visually disconnected from the operational shell and relies on animated decoration | High |
| Shared navigation | Stable keyboard skip link, sidebar active state, account menu, notifications, logout confirmation, and toggle IDs | Sidebar and topbar use gradients, global transform hover effects, duplicated visual tokens, and a fragile mobile breakpoint | Blocked by none; addressed in foundation |

### Existing CSS problems and ownership risks

The pre-foundation stylesheet is a large global override layer. It contains
multiple visual eras in one file: a dark-glass sidebar, gradient buttons,
dashboard-specific shadows, login-only effects, global Bootstrap overrides,
and page-specific utility selectors. The main risks are:

1. `:root` tokens are reused for unrelated purposes, so changing the primary
   color also changes badges, buttons, charts, and login effects.
2. `.card`, `.btn`, `.table`, `.form-control`, and utility selectors are global
   and then redefined again for dashboard, login, POS, and settings contexts.
3. Radius and shadow values are repeated as literals and as variables.
4. Several hover animations (`translateY`, glow, bell ring, pulse buttons, and
   page-load animation) compete with the task and need reduced-motion coverage.
5. Layout uses physical left/right properties in older rules, making future RTL
   support harder.
6. The current shell IDs are behavior contracts, but there was no explicit
   class boundary for shell ownership.

The foundation does not delete the legacy layer. It appends a clearly marked
shared contract so the migration can be verified page by page and retired
rules can be removed only after repository-wide evidence.

## Foundation specification

### Color tokens

The foundation is light by default, with the login surface treated as a dark
context. `--color-brand-600` is the single primary action color. Success,
warning, information, and danger remain semantic and are not used as brand
decoration.

| Token | Value | Use |
| --- | --- | --- |
| `--color-canvas` | `#f2f6f5` | Application background |
| `--color-surface` | `#ffffff` | Cards, tables, menus, forms |
| `--color-surface-muted` | `#e8f0ef` | Table headers and quiet grouping |
| `--color-ink-strong` | `#10252c` | Headings and primary labels |
| `--color-ink` | `#173139` | Body text |
| `--color-ink-muted` | `#4b6268` | Secondary text, metadata, and table headings |
| `--color-border` | `#d9e5e3` | Dividers and control borders |
| `--color-brand-600` | `#0f766e` | Primary action and active navigation |
| `--color-brand-700` | `#0b5f59` | Hover and pressed action |
| `--color-brand-900` | `#113e42` | Sidebar background |
| `--color-focus` | `#0f766e` | Keyboard focus outline |

### Typography

The existing Inter/Outfit loading remains in place for this foundation; no
new font package or build step is introduced.

- Display and page headings: Outfit, 700, tight tracking.
- Body, labels, table values, and controls: Inter, 400 to 600.
- Metadata: Inter, 0.75 to 0.875rem, muted ink.
- Numeric operational values: existing heading family with tabular numeric
  styling added during the relevant page migration.
- No copy, route, label, or language change is part of Phase 6A.

### Spacing, shape, and elevation

The base spacing scale is 4, 8, 12, 16, 20, 24, 32, and 40px through the
`--space-*` tokens. Controls use `--radius-control` (`0.65rem`), panels use
`--radius-panel` (`1rem`), and status badges use `--radius-pill`.

Elevation is reserved for panels, menus, and focused operational hierarchy:
`--shadow-panel` and `--shadow-panel-hover`. Decorative glows are not part of
the shared contract.

### Components and states

The foundation provides shared styling for the existing Bootstrap-backed
components without replacing Bootstrap markup:

- Buttons: minimum 40px height, one control radius, restrained hover lift,
  pressed reset, and high-contrast primary action.
- Inputs/selects: consistent height and border, visible focus ring, shared
  label weight, and unchanged names/validation.
- Tables: muted header surface, semantic body text, border rhythm, and brand
  tint for hover.
- Badges: pill shape for compact status/count content only.
- Alerts: one radius and semantic border/background treatment.
- Modals and dropdowns: shared border, radius, divider, and elevation.
- Empty/error/success: reusable `.ui-state-*` primitives are available for
  page migrations; current page copy and behavior stay unchanged until each
  page is migrated.
- Loading: `.ui-skeleton` is available for future asynchronous surfaces. It is
  not wired into a new request path in Phase 6A.

### Focus, motion, and accessibility

`:focus-visible` uses a 2px teal outline plus a 0.22rem ring. It is not
removed on buttons, links, form controls, the skip link, or the menu toggle.
The shared CSS includes `prefers-reduced-motion: reduce` coverage for
transitions, animations, and scroll behavior. Browser QA continues to verify
visible focus and critical accessibility findings; it does not claim full WCAG
conformance from automated checks alone.

### Responsive and RTL readiness

The shell keeps the existing 768px sidebar behavior for tablet and desktop,
uses a fixed off-canvas sidebar below 768px, and uses 992px and 576px as
content-spacing refinement boundaries. The 375px, 768px, and 1440px browser
projects remain the release verification widths.

New shell rules use logical properties such as `margin-inline-start`,
`padding-inline`, `margin-inline`, `inset-inline-start`, and `inset-block`.
The document language remains English and no direction attribute is changed.
This makes a future RTL language switch safer without pretending Phase 6A is a
translated UI.

## Application shell

The shared shell retains the current information architecture and behavior
hooks:

- `#wrapper` remains the layout root.
- `#sidebar-wrapper` remains the navigation target for `#menu-toggle`.
- `#page-content-wrapper` remains the content column.
- `#main-content` remains the skip-link target and focusable main landmark.
- `data-confirm-logout`, account dropdown IDs, notification IDs, route names,
  and Bootstrap collapse/dropdown attributes remain unchanged.

The new explicit ownership classes are `app-sidebar`, `app-topbar`, and
`app-main`. The sidebar is a dark teal navigation rail with grouped labels and
one active state. The topbar is a quiet white work surface with the existing
menu control, page title, low-stock notification, account menu, and logout
path. Main content is centered to a 1440px maximum with logical padding.

The first foundation pass does not add breadcrumbs or a second mobile menu
control because the existing page-title topbar and toggle already provide the
context and interaction contract. Breadcrumbs and page-level header actions
will be introduced only when the affected page has a characterized migration.

## Component inventory and ownership

| Component | Current owner | Phase 6A foundation | Migration owner |
| --- | --- | --- | --- |
| Application shell | `includes/layouts/*.php` + `style.css` | Explicit shell classes and tokens | Shared layout layer |
| Navigation groups | `includes/layouts/sidebar.php` | Active/hover/focus visual contract | Shared layout layer |
| Topbar/account/notifications | `includes/layouts/navbar.php` | Surface, focus, menu, avatar contract | Shared layout layer |
| Page content frame | `includes/layouts/navbar.php` + page `.container-fluid` | Max width and logical spacing | Shared layout layer |
| Buttons/forms/tables/badges | Bootstrap + `style.css` | Shared component layer | `style.css`, then page migration tests |
| Alerts and feedback | page markup + `script.js`/SweetAlert | Shared alert/state primitives | Page owner, preserving messages |
| POS catalog/cart | `public/orders.php` + existing JS | No markup behavior change | POS page migration |
| Dashboard charts/KPIs | `public/index.php` + dashboard module | No data/markup behavior change | Dashboard page migration |
| CRUD lists/forms | products, customers, suppliers, categories | Shared control language only | Each page, one at a time |
| Stock ledger | `public/stock_movements.php` | Table/control foundation only | Inventory page migration |
| History/detail/invoice | `public/order_history.php`, print route | Modal/table foundation only | History page migration |
| Login | `public/login.php` + body class | Dark context aligned to brand | Login refinement |

### Migration order

1. Phase 6A foundation and shared shell: completed in this change.
2. Dashboard: establish page-header/action pattern and KPI/chart hierarchy.
3. POS: optimize catalog/cart split at 375px and keyboard scanning flow.
4. Products and inventory: unify dense table, filters, stock states, and
   action hierarchy.
5. Customers, suppliers, and categories: migrate the shared CRUD pattern and
   modal forms together while preserving exact messages and CSRF ordering.
6. Order history/details and print view: align history hierarchy and modal
   detail without changing invoice behavior.
7. Login and final shell/accessibility pass: verify role variants, focus order,
   no overflow, and reduced motion.

No page-specific PHP markup or JavaScript behavior is changed by Phase 6A.

## Visual baselines

The current-state baseline was captured before the foundation CSS was applied
using the existing disposable Browser QA runner. Screenshots are sanitized by
the existing helper: form controls, table cells, KPI values, account metadata,
and sensitive data selectors are masked. The baseline is stored under
`docs/ui/baselines/phase-6a-before/`:

- `mobile-375-*` for the mobile browser project
- `tablet-768-*` for the tablet browser project
- `desktop-1440-*` for the desktop browser project

Each width includes `login-dashboard`, `admin-dashboard`, `admin-products`,
`admin-settings`, and `cashier-orders`. These are review baselines, not pixel
approval snapshots; the existing E2E runner explicitly reports that no visual
comparison baseline is configured.

## Verification guardrails

Every page migration must prove:

- the existing PHP and disposable regression suites remain green;
- role-based navigation and page denial behavior are unchanged;
- CSRF ordering, form names, redirects, messages, and audit metadata remain
  unchanged;
- no horizontal overflow at 375px, 768px, or 1440px;
- keyboard focus remains visible and usable;
- no new inline styles, frontend framework, or build tool is introduced;
- the page uses shared tokens/components rather than another page-specific
  visual vocabulary;
- screenshots are sanitized and contain no credentials or sensitive local
  records.

## Explicit non-goals

Phase 6A does not change business logic, database schema, authentication,
authorization, CSRF, staff scoping, exports, backups, upload behavior, public
routes, JavaScript behavior, customer/supplier/category contracts, or the
localhost Docker/XAMPP workflow. It also does not claim that the complete
redesign is finished: page migrations and visual regression comparison remain
future work.
