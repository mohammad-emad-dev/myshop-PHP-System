# Phase 4E: Supplier mutation service extraction

Phase 4E moves supplier creation, update, and deletion implementations out of
the legacy `includes/functions.php` facade and into the dependency-clean
`includes/suppliers.php` module. `public/suppliers.php` calls the focused
services directly. The legacy names remain delegation-only wrappers for
unmigrated callers and compatibility.

## TDD checkpoints

| Checkpoint | Commit | Result |
|---|---|---|
| RED characterization and source contracts | `3021cf0` | Focused supplier source/integration coverage was added before production implementation; the source boundary failed against the pre-extraction code because the focused module and direct page boundary were absent. |
| GREEN implementation | `544494e` | Added `suppliers.php`, reduced the facade supplier mutations to delegation-only wrappers, and migrated the page to direct focused callers. |
| Contract synchronization | `91509d3` | Updated the existing architecture baseline contract for the direct supplier callers; the full disposable suite passed. |

## Module boundary

`includes/suppliers.php` uses strict typing, accepts `$conn` explicitly, and
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
  deleted. ID `1` is the protected General Supplier.
- Historical purchase orders retain their existing foreign-key behavior:
  deleting a supplier sets `Order.supplier_id` to `NULL` through the existing
  `ON DELETE SET NULL` constraint.
- Closed connections, prepared-statement failures, SQL failures, and affected
  row mismatches return safe boolean failures with the existing error logging.

`includes/people.php` remains read-only. Customer code and supplier reads were
not moved; `get_supplier_by_id()` remains in the facade because no verified
caller requires extraction. The supplier page retains authentication, CSRF
ordering, administrator authorization, input normalization, validation,
audit metadata, messages, redirects, pagination, and rendering.

## Verification

| Verification | Result |
|---|---|
| Focused source-contract tests | PASS — 68 assertions |
| Focused disposable supplier integration tests | PASS — 42 assertions |
| Full disposable regression | PASS — 2,246 assertions (`1,388` unit, `858` integration) |
| PHP lint | PASS — all PHP files under `includes/`, `public/`, `tests/`, `scripts/`, `database/`, and `config/` |
| Repository security scan | PASS — tracked files |
| CI supply-chain scan | PASS — tracked files |
| `git diff --check` | PASS |
| Browser QA | PASS — 18/18 at 375px, 768px, and 1440px |

The disposable integration tests cover successful create/update/delete,
sanitization, empty names, protected and invalid IDs, missing-ID update
behavior, prepared-statement failures, closed connections, statement cleanup
contracts, legacy-wrapper equivalence, historical purchase-order FK behavior,
direct page callers, and failure-state integrity.

## Intentionally legacy behavior

The supplier update service does not require an affected-row count; a missing
supplier ID still returns `true` when the prepared update executes
successfully. The protected ID rule remains `<= 1`, creation and deletion
retain exact affected-row checks, and the facade wrappers retain their
original signatures and boolean results. Customer behavior, supplier reads,
schema, UI, CSS, JavaScript, and unrelated write paths remain unchanged.
