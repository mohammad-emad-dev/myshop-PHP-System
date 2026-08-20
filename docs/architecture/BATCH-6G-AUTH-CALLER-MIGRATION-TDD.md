# Batch 6G: Backup Authentication Caller Migration

This batch moved only the authentication and administrator checks in
`public/backup_database.php` to the explicit authentication module. The page
still loads the compatibility facade for the unchanged backup, CSRF, current
password, audit, and response behavior. `includes/backup.php` was not changed.

## Original call-site inventory

Before the change, the page had exactly these authentication calls, in source
order:

1. `public/backup_database.php:78` — `verify_login(false)`
2. `public/backup_database.php:90` — `is_admin()`

The migration changed them to:

1. `auth_verify_login($conn, false)`
2. `auth_is_admin($conn)`

No other caller was migrated. `login.php`, the shared sidebar, and the
remaining legacy callers retain their compatibility wrappers.

## RED/GREEN evidence

| Stage | Command | Result | Guarantee |
|---|---|---|---|
| RED | `docker compose run --rm --no-deps app php -r "require 'tests/Unit/auth_extraction_test.php'; echo run_auth_extraction_unit_tests(), PHP_EOL;"` | Failed with `Backup authentication call count or execution order changed.` | The new contract executed against the pre-migration page and rejected its two legacy calls. |
| GREEN | Same focused command after migration | Passed with `221` assertions. | The page uses the exact explicit auth sequence; legacy auth calls are absent; authentication/authorization, CSRF, stream, headers, audit, and failure-response landmarks remain in the original order/source. |
| Page integration | `docker compose --env-file .env exec -T -e TEST_DB_HOST=db -e TEST_DB_PORT=3306 -e TEST_DB_ROOT_USER=root -e TEST_DB_ROOT_PASSWORD=<local disposable value> app php -r "require 'tests/Integration/backup_restore_test.php'; echo run_backup_restore_tests(), PHP_EOL;"` | Passed with `84` assertions. | Disposable admin success, cashier denial, invalid session, invalid CSRF, invalid current-password reauthentication, completion marker, plaintext-password exclusion, sensitive-table exclusion, restore integrity, and closed-output failure cleanup remain covered. |

The disposable database harness generates a unique database and restricted
runtime account, never selects `ioms_db`, and removes the database, account,
temporary backup files, and failed-output fixture in its `finally` cleanup.
Generated credentials are passed only to the local test process and are not
printed or stored in this document.

## Preserved contracts

- Authentication still uses the current active database-backed staff record.
- Only active administrators can start a backup; cashiers remain denied with
  the existing HTTP 403 path.
- CSRF validation, current-password reauthentication, audit events, logging,
  response headers, filename, streaming, completion marker, failure marker,
  and cleanup behavior are unchanged.
- The canonical backup allow-list and exclusion of `LoginRateLimit` remain
  owned by `includes/backup.php`.
- No business logic, schema, migration, UI, or deployment behavior changed.

## Known coverage limitation

The focused subprocess harness verifies the page boundary and source-level
header contract. PHP CLI does not expose browser response headers through an
HTTP server, so the exact headers remain protected by the source contract and
the existing browser/deployment checks rather than a new HTTP server fixture.
