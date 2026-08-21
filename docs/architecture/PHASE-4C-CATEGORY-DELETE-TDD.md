# Phase 4C: Category deletion service extraction

## Scope and caller inventory

This batch moved the category deletion transaction from
`includes/functions.php` into the existing Category module:

- `categories_delete($conn, $id): bool` is the focused service in
  `includes/categories.php`;
- `delete_category($conn, $id)` remains available as a delegation-only
  compatibility wrapper;
- `public/categories.php` calls `categories_delete()` directly.

The page keeps its existing authentication, CSRF-before-authorization order,
administrator gate, validation, audit calls, messages, redirects, rendering,
and delete form behavior. No other category write or read caller was changed.

Historical Phase 3A, Phase 4A, and Phase 4B documents remain unchanged.

## Preserved deletion contract

The focused service does not require `includes/functions.php`, read
`$_SESSION`, or read `$GLOBALS`. It accepts the database connection and ID
explicitly and preserves the existing boolean contract and database sequence:

- cast and reject non-positive IDs;
- look up the category and return `false` for a missing category or `General`;
- begin the existing transaction only after that lookup;
- verify that `General` exists inside the transaction;
- delete exactly one category row;
- rely on the existing foreign-key behavior that makes deleted-category
  product references `NULL`, then reassign all `NULL` product references to
  `General`;
- commit only after reassignment succeeds;
- roll back after delete, reassignment, commit, or other transactional failure;
- preserve affected-row checks, error logging, safe rollback diagnostics,
  statement cleanup, closed-connection behavior, and missing-category behavior.

No schema, UI, CSS, JavaScript, customer/supplier code, or category
create/update behavior changed.

## RED checkpoint

Commit `8a8482c` added the source contracts, focused integration fixtures, test
runner wiring, and updated caller expectations before the focused service and
page migration existed.

Focused source command:

```powershell
docker compose run --rm --no-deps app php -r "require 'tests/Unit/category_delete_test.php'; echo 'CATEGORY_DELETE_UNIT_ASSERTIONS=' . run_category_delete_unit_tests() . PHP_EOL;"
```

Observed RED result: `Category delete contract is missing:
function categories_delete($conn, $id): bool`.

Focused integration command:

```powershell
$testRootPassword = ((Get-Content .env | Where-Object { $_ -match '^MYSQL_ROOT_PASSWORD=' } | Select-Object -First 1) -split '=',2)[1]; docker compose run --rm --no-deps -e TEST_DB_ROOT_PASSWORD=$testRootPassword -e TEST_DB_USER=root -e TEST_DB_HOST=db -e TEST_DB_PORT=3306 app php -r "require 'tests/Integration/category_delete_test.php'; echo 'CATEGORY_DELETE_INTEGRATION_ASSERTIONS=' . run_category_delete_integration_tests() . PHP_EOL;"
```

Observed RED result: `Call to undefined function categories_delete()`.

## GREEN checkpoint

Commit `d5cb0e3` added the focused service, reduced the compatibility facade to
delegation, and migrated the public caller.

Focused results:

- source contracts: **43 assertions passed**;
- disposable integration tests: **28 assertions passed**.

The integration tests cover positive-ID validation, missing categories,
General-category rejection, missing General, successful deletion and product
reassignment, legacy-wrapper equivalence, closed connections, delete-query
failure, reassignment-query failure, rollback, and continued connection use
after failed operations. Trigger-backed failure fixtures verify that neither
the category delete nor product reassignment leaves partial committed state.

## Full verification

- Full disposable regression: **2024 assertions passed** (1249 unit, 775
  integration) in 14.72 seconds.
- PHP lint: all tracked PHP files passed.
- Repository security scan: passed with no findings using the repository's
  scanner over the host-provided tracked-file list; the minimal PHP image does
  not include Git for self-enumeration.
- CI supply-chain scan: passed with only immutable workflow and production
  image references.
- Browser QA: **18/18 passed** at 375px, 768px, and 1440px. Disposable
  containers, images, volume, and network were removed by the runner.
- `git diff --check`: passed.

No GitHub push was performed.
