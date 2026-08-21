# Phase 6F.1: Thermal Invoice Printing

## Scope

This correction restores the standalone `public/print_invoice.php` route to
its original thermal-receipt contract. It changes only the standalone invoice
presentation and its executable browser/source contracts. Order history,
HTML-to-PDF rendering, order reads, authorization, staff scoping, audit events,
and the POS checkout flow remain unchanged.

## Default print contract

`public/print_invoice.php?id=<validated-id>` remains the existing route and is
thermal-only by default:

- `@page { size: 80mm auto; margin: 0; }` defines the printer page width.
- The document and receipt surface are constrained to `80mm` and `100vw`.
- Print margins, surface padding, border, radius, and shadow are removed for
  predictable thermal output.
- Item and totals tables use fixed layouts, explicit column proportions, and
  `overflow-wrap`/`word-break` rules so long product or party values wrap rather
  than expanding the receipt horizontally.
- The body and receipt surface hide horizontal overflow and are validated at a
  320 CSS-pixel print viewport (approximately 80mm in the browser harness).
- The existing `window.print()` load handler is unchanged.

No A4 or desktop preview query parameter was added. The separate invoice
surface generated inside `public/order_history.php` remains the existing
HTML-to-PDF/preview behavior and was not changed by this correction.

The route continues to preserve `sanitize_id`, authentication, administrator
versus cashier staff scope, server-side order authorization, not-found audit
logging, invoice item rendering, subtotal, tax, and total output.

## Screenshot sanitization

The browser harness masks order-detail party metadata and account metadata,
including customer/supplier name, phone, email, address, order reference,
cashier/date/type, and account name/role/avatar. The standalone thermal helper
masks invoice paragraphs, cells, footer values, and the brand heading. The
screenshots therefore contain layout evidence without exposing fixture values.

Retained sanitized thermal evidence:

- [375px thermal invoice](baselines/phase-6f.1-after/mobile-375-admin-invoice-thermal.png)
- [768px thermal invoice](baselines/phase-6f.1-after/tablet-768-admin-invoice-thermal.png)
- [1440px thermal invoice](baselines/phase-6f.1-after/desktop-1440-admin-invoice-thermal.png)

The viewport is intentionally set to 320px for the thermal route in each
project so the screenshot shows the receipt-width composition rather than a
desktop canvas.

## TDD evidence

| Stage | Commit | Evidence |
|---|---|---|
| RED | `25bb5e5` | Added 46 focused thermal/source/browser contracts. The focused runner failed because the current invoice had no `@page` thermal rule and still contained the 860px surface. |
| GREEN | `647783b` | Added the 80mm CSS, print-media geometry checks, totals/item assertions, cross-staff invoice denial check, and screenshot masking. Focused contracts passed. |
| Documentation/evidence | this checkpoint | This document and the three sanitized thermal screenshots are recorded after the full gate. |

Focused command:

```text
docker compose --env-file .env exec -T app php -r "require 'tests/Unit/thermal_invoice_test.php'; echo 'FOCUSED_ASSERTIONS=' . run_thermal_invoice_unit_tests() . PHP_EOL;"
```

Result: **46 assertions passed**.

## Verification evidence

| Gate | Result |
|---|---|
| Full disposable PHP regression with `TEST_DB_*` variables | **PASS — 4,217 assertions** (2,590 unit, 1,627 integration) |
| Browser QA | **PASS — 30/30** across 375px, 768px, and 1440px; invoice print-media and cross-staff checks included |
| Thermal print geometry | **PASS** — document scroll width, body width, and invoice width stayed within the 320px viewport; body overflow was hidden |
| PHP lint | **PASS** — 97 tracked PHP files |
| JavaScript syntax | **PASS** — 4 tracked JavaScript files |
| Docker Compose validation | **PASS** — `docker compose --env-file .env config --quiet` |
| Repository security scan | **PASS** — no findings |
| Supply-chain scan | **PASS** — immutable action/image policy satisfied |
| Release-integrity scan | **PASS** — safe metadata only; no credentials or database content emitted |
| `git diff --check` | **PASS** |

Disposable browser containers, images, network, volume, npm output, and
temporary environment files were removed by the browser runner. Only the
three intentionally retained, sanitized evidence screenshots remain in the
repository working tree before the documentation checkpoint.

## Intentional compatibility boundary

The standalone invoice is intentionally thermal-only. A4/desktop behavior is
not silently selected and no new unvalidated query parameter exists. The
invoice route’s backend and security behavior were not redesigned.
