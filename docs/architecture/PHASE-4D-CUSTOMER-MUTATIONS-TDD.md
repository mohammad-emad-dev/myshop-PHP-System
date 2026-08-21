# Phase 4D: Customer mutation service extraction

Phase 4D moves customer creation, update, and deletion implementations out of
the legacy `includes/functions.php` facade and into the dependency-clean
`includes/customers.php` module. `public/customers.php` calls the focused
services directly. The legacy names remain delegation-only wrappers for
unmigrated callers and compatibility.

## TDD checkpoints

| Checkpoint | Commit | Result |
|---|---|---|
| RED characterization and source contracts | `bc7d719` | Focused customer source/integration coverage was added before production implementation; the source boundary failed against the pre-extraction code because the focused module and direct page boundary were absent. |
| GREEN implementation | `689d790` | Added `validation.php` and `customers.php`, reduced the facade customer mutations to delegation-only wrappers, and migrated the page to direct focused callers. |
| Contract synchronization | `a0b97c6` | Updated the existing architecture baseline contract for the direct customer callers; focused tests passed again. |

## Module boundary

`includes/customers.php` uses strict typing, accepts `$conn` explicitly, and
requires only the pure helpers in `includes/validation.php`. It does not
require `functions.php`, access `$_SESSION`, or access `$GLOBALS`. Every
mutation uses prepared statements and closes its statement in `finally` on
both success and failure paths.

The service preserves the observed legacy contracts:

- Creation sanitizes all fields, rejects an empty name, and succeeds only when
  the insert affects exactly one row.
- Update sanitizes all fields, rejects IDs `<= 1` and empty names, and retains
  the legacy execute-success behavior for a missing ID.
- Deletion rejects IDs `<= 1` and succeeds only when exactly one row is
  deleted. ID `1` is the protected Walk-in Customer.
- Historical orders retain their existing foreign-key behavior: deleting a
  customer sets `Order.customer_id` to `NULL` through the existing
  `ON DELETE SET NULL` constraint.
- Closed connections, prepared-statement failures, SQL failures, and affected
  row mismatches return safe boolean failures with the existing error logging.

`includes/people.php` remains read-only. Supplier writes were not changed.
The customer page retains authentication, CSRF ordering, administrator
authorization, input handling, audit metadata, messages, redirects,
pagination, and rendering.

## Verification

| Verification | Result |
|---|---|
| Focused source-contract tests | PASS — 68 assertions |
| Focused disposable customer integration tests | PASS — 37 assertions |
| Full disposable regression | PASS — 2,131 assertions (`1,319` unit, `812` integration) |
| PHP lint | PASS — all PHP files under `includes/`, `public/`, `tests/`, `scripts/`, `database/`, and `config/` |
| Repository security scan | PASS — tracked files |
| CI supply-chain scan | PASS — tracked files |
| `git diff --check` | PASS |
| Browser QA | PASS — 18/18 at 375px, 768px, and 1440px |

The disposable integration tests cover create, update, delete, protected and
invalid IDs, empty names, prepared-statement failures, closed connections,
historical-order foreign-key behavior, statement cleanup, legacy-wrapper
equivalence, direct page callers, and failure-state integrity.

## Intentionally legacy behavior

The customer update service does not require an affected-row count; a missing
customer ID still returns `true` when the prepared update executes
successfully. The protected ID rule remains `<= 1`, creation and deletion
retain exact affected-row checks, and the facade wrappers retain their
original signatures and boolean results. Customer request validation and
responses remain page-owned, while customer reads remain in the People read
module.
