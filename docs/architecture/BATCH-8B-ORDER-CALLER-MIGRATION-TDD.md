# Batch 8B — Order Caller Migration TDD Evidence

This batch migrates only the order-submission caller in `public/orders.php`
from the legacy `create_order()` compatibility wrapper to the focused
`orders_create()` service. The page continues to own request parsing, CSRF,
page-level purchase authorization, server-side product prevalidation, generic
messages, session flood-protection state, completion state, links, rendering,
and POS JavaScript.

## Caller inventory before migration

The repository-wide search was performed before editing with:

```text
rg -n -C 3 "create_order\s*\(" public includes tests scripts --glob '*.php'
```

The active order submission caller was:

| Caller | Pre-migration location | Arguments and contract |
|---|---|---|
| POS/order page | `public/orders.php:98` | `create_order($conn, $_SESSION['staff_id'], $order_items, $order_type, $customer_id, $supplier_id)`; consumes an order ID on success and `false` on failure |

The legacy wrapper remained intentionally exercised by validation tests,
database integration tests, and backup/restore fixtures. Those callers were
not migrated in this batch. The wrapper in `includes/functions.php` remains a
delegation-only compatibility boundary.

## TDD checkpoints

| Stage | Commit | Command/result | Evidence |
|---|---|---|---|
| RED | `8abc648` | `docker compose run --rm --no-deps app php -r "require 'tests/Unit/order_write_test.php'; echo run_order_write_unit_tests(), PHP_EOL;"` failed with `Orders page must call orders_create with the established argument order and actor ID.` | The new direct-call, no-legacy-call, and ordering contract executed against the unchanged page and failed for the intended reason. |
| GREEN | `f023054` | Same focused command passed with `43` assertions. | `public/orders.php` calls `orders_create()` with the established argument order and actor ID; the request/auth/CSRF/purchase-authorization ordering contract remains intact. |
| Contract synchronization | `2c0df2b` | Focused auth and architecture contracts passed with `231` and `98` assertions. | Existing repository contracts now describe the direct order-service caller rather than the pre-migration wrapper caller. |
| Contract hardening | `2b4a922` | The focused order source test passed again with `43` assertions. | The legacy-caller check now requires zero `create_order()` matches in `public/orders.php`, preventing multiple stale calls from being accepted. |

## Preserved page boundary

The only production statement changed was the order-service target at the
existing dispatch point. The page still:

- authenticates before request processing;
- validates CSRF before purchase authorization and service dispatch;
- preserves sale/purchase selection and cashier purchase denial;
- parses and normalizes the cart and revalidates products/prices;
- passes `$_SESSION['staff_id']` as the explicit actor ID;
- treats the service result as an order ID or `false`;
- sets `completed_order_id` and `$_SESSION['last_order_time']` only on success;
- preserves generic failure auditing/messages, redirects, links, and rendering.

`orders_create()` itself was not changed. Its existing transaction, row locks,
server-side pricing, stock/movement writes, audit records, commit/rollback,
and `int|false` return contract remain covered by the Batch 8A integration
tests. The legacy `create_order()` wrapper remains available for all
un-migrated callers and compatibility tests.

## Verification evidence

| Check | Result |
|---|---|
| Focused order source tests | PASS — `43` assertions |
| Focused product tests | PASS — `108` assertions |
| Focused inventory read tests | PASS — `60` assertions |
| Focused inventory adjustment tests | PASS — `40` assertions |
| Full disposable regression | PASS — `1469` assertions (`873` unit, `596` integration) |
| Browser QA | PASS — `18/18` tests at 375px, 768px, and 1440px; auth, POS/catalog journeys, cashier denial, console/network, keyboard, axe, and overflow checks passed |
| Automated accessibility | No axe findings were emitted in this run. This remains an automated signal, not a complete manual WCAG audit. |
| Visual regression | INCONCLUSIVE — no committed visual baselines exist; sanitized screenshots are temporary review artifacts only. |

The full regression and Browser QA runs used disposable environments only. No
production credentials, normal local database, secrets, cookies, screenshots,
or generated artifacts were committed.

## Scope and compatibility risks

This is a caller-boundary change, so the primary risk is an accidental change
to page-side sequencing or result handling. The source contracts and page-level
integration coverage protect the exact dispatch arguments, CSRF/authorization
ordering, sale/purchase behavior, success state, failure behavior, and
compatibility wrapper. Other legacy callers continue to use `create_order()`.

## Rollback

Revert the local Batch 8B commits in reverse order, or restore the single
service-call line in `public/orders.php` to `create_order(...)` and revert the
associated source-contract/documentation updates. No schema, data, or
deployment configuration was changed.
