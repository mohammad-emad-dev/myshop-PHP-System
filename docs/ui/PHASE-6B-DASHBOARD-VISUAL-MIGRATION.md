# Phase 6B: Dashboard Visual Migration

Status: Implemented and verified

Date: 2026-08-21

## Scope and guardrails

This phase migrates the Dashboard page only. The existing Dashboard queries,
role scoping, authorization checks, routes, JSON data hooks, chart time window,
forms, and business contracts remain unchanged. No backend, schema,
authentication, CSRF, or security code was changed.

The migration uses the Signal Workbench tokens and shell established in
[Phase 6A](PHASE-6A-UI-REDESIGN-SPEC.md): a pale operational canvas, white
surfaces, deep teal primary actions, semantic status colors, compact density,
predictable focus, and restrained motion.

## Dashboard audit and migration result

The legacy page combined Bootstrap cards, five unrelated accent colors,
dashboard-only progress selectors, oversized chart wrappers, gradient quick
actions, and several repeated empty-state treatments. It also left too much
vertical space around the chart and category panel at desktop and tablet
widths.

The migrated page now has:

- A page header with operational context plus Inventory and Open POS actions.
- A five-column desktop KPI grid with brand, success, warning, and neutral
  semantic accents; it collapses to two columns and then one column.
- A compact chart panel that keeps the existing seven-day sales/purchases
  data and Chart.js behavior while using shared CSS tokens for colors.
- Structured top-selling and category panels with consistent headers, ranking
  meters, category chart sizing, and useful empty states.
- A severity-led low-stock panel with an explicit alert summary and the same
  administrator-only action links and product highlighting behavior.
- A restrained quick-action list using the same four existing destinations;
  no new actions or gradients were introduced.

## Component ownership

Dashboard-only markup is owned by `public/index.php`. Dashboard migration
styles are grouped in the marked Phase 6B layer at the end of
`public/assets/css/style.css`; they consume Phase 6A tokens and do not create
a second token vocabulary. Existing shell styles remain owned by the shared
layout styles.

| Component | Shared contract | Dashboard ownership |
| --- | --- | --- |
| Page header | `--space-*`, `--color-*`, focus-visible button rules | `.dashboard-page-header`, `.dashboard-page-title`, `.dashboard-page-actions` |
| KPI cards | surface, border, radius, shadow, semantic colors | `.dashboard-kpi-grid`, `.dashboard-kpi-card`, `.dashboard-kpi-number` |
| Data panels | shared surface and typography tokens | `.dashboard-panel`, `.dashboard-panel-header`, `.dashboard-panel-body` |
| Chart state | shared muted text and focus/motion rules | `.dashboard-chart-frame`, `.dashboard-chart-canvas`, `.dashboard-state` |
| Rankings | shared spacing and semantic brand meter | `.dashboard-ranking-list`, `.dashboard-ranking-row`, `.dashboard-ranking-meter` |
| Low stock | warning/danger semantics and responsive table shell | `.dashboard-alert-summary`, `.dashboard-alert-table`, `.dashboard-stock-level` |
| Quick actions | shared button/link focus and surface rules | `.dashboard-quick-list`, `.dashboard-quick-link` |

## Retired and retained selectors

Repository-wide searches confirmed the following Dashboard-only selectors were
not used by any other page, script, test, template, or dynamic class builder,
so their legacy declarations were removed:

`dashboard-kpi-value`, `dashboard-kpi-icon`, `dashboard-section-title`,
`dashboard-chart-large`, `dashboard-chart-category`, `dashboard-progress`,
`dashboard-progress-bar`, `dashboard-quick-heading`, `quick-action-card`,
`quick-action-products`, `quick-action-pos`, `quick-action-customers`,
`quick-action-reports`, `quick-action-icon`, `quick-action-title`, and
`quick-action-description`.

`.dashboard-card` and the `border-left-*` accent classes were retained. They
still have production callers in `public/order_history.php`; retiring them in
this page migration would change a different page and would not be
evidence-based.

## Responsive and accessibility review

- At 375px, the header actions, KPI cards, panels, alerts, and quick actions
  form a single usable column with no horizontal overflow.
- At 768px, the sidebar/content composition remains usable; KPIs use a
  compact grid and the insight panels stack to avoid narrow chart/table
  surfaces.
- At 1440px, five KPIs fit in one row, the chart receives the primary visual
  width, and the insight panels share a controlled two-column composition.
- Existing `:focus-visible` and reduced-motion contracts remain in force.
  New links and buttons use standard button focus styles, charts retain
  textual headings and labels, and empty states use `role="status"`.
- Existing role-based Dashboard behavior is preserved: cashier/admin data
  scoping and administrator-only low-stock actions are unchanged.

## Visual evidence

The Phase 6A before baselines remain unchanged in
`docs/ui/baselines/phase-6a-before/`. Sanitized Phase 6B after captures are
in `docs/ui/baselines/phase-6b-after/`:

- `mobile-375-admin-dashboard.png`
- `tablet-768-admin-dashboard.png`
- `desktop-1440-admin-dashboard.png`

The browser run also produced the corresponding sanitized journey captures
for login, cashier, products, and settings in the same directory. Inputs and
data cells are masked by the existing Browser QA sanitizer; no credentials or
customer data are stored in the screenshots.

## Verification

- Focused Dashboard source contracts: 71 assertions.
- Full disposable regression: 3,703 assertions (2,076 unit and 1,627
  integration), passed.
- Browser QA: 18/18 journeys passed at 375px, 768px, and 1440px.
- PHP lint, JavaScript syntax, repository security/supply-chain checks, and
  `git diff --check` are required release checks for the phase; their exact
  final results are recorded in the delivery report.

The migration intentionally stops at the Dashboard. POS, products,
inventory, customers, suppliers, categories, order history, and login remain
visually unchanged in this phase apart from shared Phase 6A foundation styles.
