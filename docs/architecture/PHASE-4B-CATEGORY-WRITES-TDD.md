# Phase 4B: Category create/update services

## Scope and caller inventory

This batch extracted only the implementations of `create_category()` and
`update_category()` from `includes/functions.php` into the independent
`includes/categories.php` module:

- `categories_create($conn, $name, $description): bool`
- `categories_update($conn, $id, $name, $description): bool`

The verified production callers before migration were:

| Location | Original call | Current call |
|---|---|---|
| `public/categories.php:35` | `create_category($conn, $name, $description)` | `categories_create($conn, $name, $description)` |
| `public/categories.php:52` | `update_category($conn, $id, $name, $description)` | `categories_update($conn, $id, $name, $description)` |

Existing integration tests called the legacy names. No dynamic category-write
dispatch was found under `public/`, `includes/`, `tests/`, or `scripts/`.
`delete_category()` remains the only category mutation implementation in the
facade and remains the active caller for deletion.

## Preserved contracts

The focused module has no dependency on `includes/functions.php`, `$_SESSION`,
or `$GLOBALS`. It accepts the database connection and input values explicitly.
The extraction preserves:

- trimming of names and descriptions;
- empty-name and non-positive-ID rejection;
- prepared SQL, `s`, `si`, and `ssi` parameter bindings;
- duplicate-name checks;
- General-category rename protection;
- the create affected-row requirement of exactly one row;
- the existing update behavior that returns `true` after an executed zero-row
  update for a missing category;
- error logging, statement cleanup, boolean return values, and closed-connection
  failure behavior;
- page-level authentication, CSRF-before-authorization ordering, admin
  authorization, validation, messages, redirects, rendering, and the unchanged
  delete path.

## RED checkpoint

Commit `ecf413a13d70b37f54c4f7e5d86aec24f4518052` added the source contracts,
disposable integration tests, runner wiring, and updated architecture source
expectations before the focused module and page calls existed.

Focused source command:

```powershell
docker compose run --rm --no-deps app php -r "require 'tests/Unit/category_write_test.php'; echo 'CATEGORY_WRITE_UNIT_ASSERTIONS=' . run_category_write_unit_tests() . PHP_EOL;"
```

Observed RED result: `Category write source fixture could not be read.`

Focused integration command:

```powershell
$testRootPassword = ((Get-Content .env | Where-Object { $_ -match '^MYSQL_ROOT_PASSWORD=' } | Select-Object -First 1) -split '=',2)[1]; docker compose run --rm --no-deps -e TEST_DB_ROOT_PASSWORD=$testRootPassword -e TEST_DB_USER=root -e TEST_DB_HOST=db -e TEST_DB_PORT=3306 app php -r "require 'tests/Integration/category_write_test.php'; echo 'CATEGORY_WRITE_INTEGRATION_ASSERTIONS=' . run_category_write_integration_tests() . PHP_EOL;"
```

Observed RED result: `Call to undefined function categories_create()`.

## GREEN checkpoint

Commit `2ed617c1954c38543ecce9e52450885df49a19de` added the module, changed the
two facade functions to delegation-only wrappers, and migrated the two active
page callers.

Focused results:

- source-contract tests: **50 assertions passed**;
- disposable category-write integration tests: **24 assertions passed**.

The integration tests cover create success, trimming, empty input, duplicate
names, update success, duplicate update names, General rename rejection,
missing-category update behavior, invalid IDs, wrapper equivalence, and closed
connections. The disposable database is created and removed by the test
harness; no normal application database is used.

## Full verification

- Full disposable regression: **1955 assertions passed** (1208 unit,
  747 integration) in 12.54 seconds.
- PHP lint: **74 tracked PHP files passed**.
- JavaScript syntax checks: **4 tracked JavaScript files passed**.
- Repository security scan: **passed with no findings**.
- CI supply-chain scan: **passed with only immutable workflow and production
  image references**.
- Browser QA: **18/18 passed** across 375px, 768px, and 1440px. The disposable
  containers, image, MySQL volume, and network were removed by the runner.
- `git diff --check`: passed before the documentation commit.

No UI, CSS, JavaScript, schema, migration, category-read, or category-delete
behavior was changed. Historical Phase 3A, Batch 7A, and Phase 4A documents
remain unchanged.
