# Phase 6G: Final UI Closure, Accessibility, and CSS Cleanup

## Scope and outcome

Phase 6G audited the remaining user-facing surfaces after the staged
Dashboard, POS, catalog, people, and order-history migrations. The audit
covered every route in `public/`, the shared navbar/sidebar/layout, the
current design tokens, page-specific CSS, the browser QA harness, and the
existing accessibility/source contracts.

The remaining legacy surfaces that needed presentation work were:

| Surface | Result | Preserved contracts |
|---|---|---|
| `public/login.php` | Migrated to a distinct Signal Workbench authentication surface with a restrained dark context, clear identity, and accessible form hierarchy. | Login CSRF, rate limiting, authentication, sessions, errors, redirects, form names, and autocomplete behavior. |
| `public/settings.php` | Migrated to shared page-header, section, callout, backup, staff-table, and modal presentation. | Current-password verification, password policy, CSRF, administrator authorization, backup behavior, staff mutations, modal IDs, form names, and messages. |
| `public/audit_log.php` | Migrated to the shared page header, filter toolbar, table surface, contained-scroll region, empty state, and pagination patterns. | Authentication, administrator authorization, filters, bounded reads, pagination, query parameters, table data, and read-only behavior. |
| `includes/layouts/sidebar.php` | Added `aria-current="page"` to the existing active link only. | Existing links, hrefs, role guards, IDs, mobile toggle, layout structure, and navigation behavior. |
| `public/assets/css/style.css` | Removed the obsolete login glass/animated-orb/purple-gradient block and added final shared-surface rules for the three remaining surfaces. | Bootstrap structure/utilities, shared `data-*` components, POS animation use, modal/table contracts, focus styles, RTL logical properties, and reduced-motion behavior. |

The already migrated operational pages were reviewed and intentionally left
unchanged: `index.php`, `orders.php`, `products.php`,
`stock_movements.php`, `customers.php`, `suppliers.php`, `categories.php`,
`order_history.php`, `get_order_details.php`, and `print_invoice.php`.
They already use the current shared design system, or in the case of the
last two, are action/thermal presentation surfaces with separate contracts.
The health, readiness, backup, export, and POS lookup routes were also
reviewed as operational/action endpoints and were not treated as redesign
targets.

## CSS deletion and retention manifest

The removal decision was made from repository-wide `rg` searches over PHP,
HTML/templates, JavaScript, tests, documentation, and dynamic class usage.
No selector was removed solely because it was absent from one PHP page.

### Removed

| CSS | Evidence and reason |
|---|---|
| Obsolete login `linear-gradient(135deg, #0c1222, ...)` background block | The old login surface was replaced by the explicit `.login-page` authentication context. No remaining page or script referenced the old gradient contract. |
| `@keyframes floatOrb` and its animated login pseudo-element declarations | Repository-wide search found no runtime or test dependency. The new login treatment uses static radial context shapes, avoiding decorative motion. |
| Old login glass/backdrop-filter, purple gradient text, and purple gradient button rules | These declarations belonged only to the retired login visual vocabulary. Form IDs, names, security markup, and the login route were not changed. |

### Retained

| CSS or contract | Evidence and reason |
|---|---|
| `.pulse-btn` and `@keyframes pulseGlow` | Retained because `public/orders.php` still uses `.pos-complete-button.pulse-btn`; removing it would change checkout feedback. Settings no longer adds this class. |
| `.settings-section` and its heading rules | Retained and consolidated because settings profile, backup, and staff sections are now explicit consumers. |
| `.data-*`, `.ui-*`, Bootstrap `.modal`, table, form, and responsive utilities | Used by multiple migrated pages, JavaScript hooks, source contracts, and browser tests. |
| `.login-page`, `.login-card`, and `.login-shell__main` | Retained as the deliberate authentication boundary and its responsive implementation. |
| `.settings-staff-table` and `.audit-log-table` | Retained for contained dense-data behavior and the screenshot sanitizer's sensitive-cell hooks. |
| Focus and motion rules | Retained because keyboard navigation and `prefers-reduced-motion` are explicit cross-page requirements. |

No PHP function, backend query, database schema, route, compatibility wrapper,
authentication rule, authorization rule, CSRF flow, rate limiter, audit write,
export, upload, invoice, or thermal-print rule was removed or changed.

## Accessibility review

### Verified

- The shared skip link targets the existing `#main-content` landmark.
- Active sidebar links expose `aria-current="page"` without changing their
  destination or role guards.
- Login fields retain visible labels, autocomplete semantics, the existing
  alert behavior, and a clear primary submit action.
- Settings sections retain labels and existing form controls; the security
  verification callout is visually and semantically associated with the
  save action.
- Audit filters retain labels. The audit table has a caption, scoped column
  headings, and a keyboard-focusable labelled scroll region so contained
  horizontal scrolling is discoverable.
- Buttons retain usable touch dimensions from the shared component rules.
- Global `:focus-visible` styling remains visible, and the browser suite
  exercises keyboard navigation at all three target widths.
- Reduced-motion emulation is exercised in browser QA and the stylesheet
  keeps motion reduction for transitions and animations.
- The final browser run reported no critical or serious axe findings in the
  covered journeys. Automated axe results are regression signals, not proof
  of full WCAG conformance.

### Limitations

Both settings modals explicitly retain Bootstrap's
`data-bs-keyboard="true"` and `data-bs-focus="true"` policy and their existing
close controls. The final local Chromium audit opened the modal, waited for
Bootstrap's opening transition to settle, pressed Escape, verified dismissal,
and verified focus returned to the Add Staff trigger. The browser contract now
waits for that real settled state before exercising Escape, so the earlier
transition-timing false negative is not reported as an application defect.

No claim of full WCAG conformance is made. Manual contrast review, screen
reader behavior, browser/OS zoom combinations, and full RTL rendering remain
outside this phase's executable coverage.

## Sanitized visual evidence

The browser harness masks inputs, textareas, selects, table cells, account
metadata, order/customer/supplier fields, invoice values, and the explicit
settings/audit sensitive-cell selectors before writing screenshots. The
remaining visible dashboard/report labels are disposable synthetic QA values.
The retained capture directory contains the complete 30-journey set; these
are the required Phase 6G review captures:

- Login: [375px](baselines/phase-6g-after/mobile-375-login-final.png),
  [768px](baselines/phase-6g-after/tablet-768-login-final.png),
  [1440px](baselines/phase-6g-after/desktop-1440-login-final.png)
- Settings: [375px](baselines/phase-6g-after/mobile-375-admin-settings-final.png),
  [768px](baselines/phase-6g-after/tablet-768-admin-settings-final.png),
  [1440px](baselines/phase-6g-after/desktop-1440-admin-settings-final.png)
- Audit log: [375px](baselines/phase-6g-after/mobile-375-admin-audit-log-final.png),
  [768px](baselines/phase-6g-after/tablet-768-admin-audit-log-final.png),
  [1440px](baselines/phase-6g-after/desktop-1440-admin-audit-log-final.png)
- Representative migrated Dashboard: [375px](baselines/phase-6g-after/mobile-375-admin-dashboard.png),
  [768px](baselines/phase-6g-after/tablet-768-admin-dashboard.png),
  [1440px](baselines/phase-6g-after/desktop-1440-admin-dashboard.png)
- Representative migrated Products: [375px](baselines/phase-6g-after/mobile-375-admin-products.png),
  [768px](baselines/phase-6g-after/tablet-768-admin-products.png),
  [1440px](baselines/phase-6g-after/desktop-1440-admin-products.png)

The images were visually reviewed at 375px, 768px, and 1440px. The mobile
audit table intentionally scrolls inside its labelled region; the page itself
does not overflow horizontally.

## TDD and verification evidence

### RED -> GREEN

| Stage | Commit | Evidence |
|---|---|---|
| RED | `36efaac` | Added the initial final-UI source, ownership, CSS, shell, and screenshot-contract tests; the focused runner failed against the legacy surfaces. |
| RED extension | `c6f97d6`, `bad577b` | Added executable contracts for the audit scroll region and modal keyboard policy before each corresponding fix. |
| GREEN | `4356839`, `6d72ca6`, `650a1dd` | Migrated login/settings/audit presentation, restored the keyboardable audit region, and made Bootstrap modal keyboard/focus policy explicit. |
| Final test GREEN | `13d41df` | Kept the browser contract deterministic by checking the existing modal close control; no temporary custom Escape handler remains. |
| Audit follow-up | `4817069`, `40f026f` | Proved the initial Escape failure was caused by testing during Bootstrap's opening transition, then added a settled-transition Escape and focus-return browser contract without changing production code. |
| Documentation | this checkpoint | Records the final audit, retention decisions, accessibility limitation, and sanitized evidence. |

Focused command:

```text
docker compose --env-file .env exec -T app php -r "require 'tests/Unit/final_ui_closure_test.php'; echo 'FOCUSED_ASSERTIONS=' . run_final_ui_closure_unit_tests() . PHP_EOL;"
```

Result: **97 focused assertions passed**.

Final verification:

| Gate | Result |
|---|---|
| Full disposable PHP regression with `TEST_DB_*` variables | **PASS - 4,312 assertions** (2,685 unit, 1,627 integration) |
| Browser QA | **PASS - 30/30** across 375px, 768px, and 1440px |
| PHP lint | **PASS** for all tracked PHP files |
| JavaScript syntax | **PASS** for all 4 tracked JavaScript files |
| Docker Compose validation | **PASS** - `docker compose --env-file .env config --quiet` |
| Repository security scan | **PASS** - no tracked-file findings |
| Supply-chain scan | **PASS** - immutable workflow/image policy satisfied |
| Release-integrity/secrets scan | **PASS** - safe metadata only; no credentials or database content emitted |
| `git diff --check` | **PASS** |

The browser runner removed its disposable containers, images, network,
database volume, npm output, and temporary environment files. The normal local
Compose stack was brought back up after the interrupted prior run. No push or
merge was performed.

## Remaining risk

Escape-key modal dismissal and focus return are now verified in the actual local
Chromium run. The remaining accessibility risk is the normal limitation of
automated and targeted manual checks: full screen-reader, contrast, zoom, RTL,
and WCAG conformance review still require a broader operator-led audit.
