# Batch 6F settings authentication caller migration evidence

## Scope

This batch changes only the authentication and authorization calls in
`public/settings.php`. The page now passes its existing `$conn` directly to
the extracted authentication module. Profile updates, current-password
reauthentication, password policy, staff CRUD/status operations, sole-admin
and self-deactivation protections, CSRF ordering, direct Staff SQL,
transactions, audit events, forms, responses, and rendered markup are
unchanged.

The compatibility wrappers remain available for the still-unmigrated callers:
login, backup handling, and shared layouts.

## Original and migrated call order

The original calls were:

1. `verify_login()` at `public/settings.php:6`.
2. `require_admin()` at `public/settings.php:57`, after CSRF validation.
3. `is_admin()` at `public/settings.php:330` for the admin-only staff panel.

They are now:

1. `auth_verify_login($conn)`.
2. `auth_require_admin($conn)`.
3. `auth_is_admin($conn)`.

## RED and GREEN evidence

| Stage | Command | Result | Guarantee |
|---|---|---|---|
| RED | `docker compose run --rm --no-deps app php -r "require 'tests/Unit/auth_extraction_test.php'; echo run_auth_extraction_unit_tests(), PHP_EOL;"` after adding the Batch 6F contract | Failed because `public/settings.php` still exposed the legacy authentication sequence. | The exact settings caller contract executed before the production change. |
| GREEN | Same focused command after migration | Passed with `212` assertions. | The page has exactly one explicit login call, one explicit admin enforcement call, and one explicit admin view check in the original order; legacy auth calls are absent; CSRF remains before admin authorization; settings security landmarks remain present. |
| Regression | `docker compose run --rm --no-deps -e TEST_DB_HOST=db -e TEST_DB_PORT=3306 -e TEST_DB_ROOT_USER=root -e TEST_DB_ROOT_PASSWORD=<local disposable value> app php tests/run.php` | Passed with `1062 assertions (598 unit, 464 integration)` in `10.29s`. | Existing authentication, authorization, CSRF, password, staff integrity, profile transaction, audit, and all prior business/security contracts remained green. |
| Browser QA | `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/run-browser-qa.ps1` | Passed with `18` tests in `51.0s`. | Disposable admin/cashier journeys, settings surface loading, responsive checks, keyboard checks, accessibility checks, console/network checks, and overflow checks passed at 375px, 768px, and 1440px. |

## Page-level settings boundary coverage

The disposable PHP integration suite submits the actual `public/settings.php`
POST boundary with temporary admin and cashier sessions. It verifies:

- incorrect current-password reauthentication leaves the profile unchanged;
- valid current-password reauthentication persists a profile update;
- weak profile and staff passwords retain HTTP 400 and create no account;
- mismatched new-password confirmation rolls back the profile transaction;
- invalid CSRF retains HTTP 403 and performs no mutation;
- cashier profile and staff mutations retain HTTP 403 and create no changes;
- admin staff create, update, activation, and deactivation remain functional;
- self-deactivation remains blocked;
- sole-active-administrator demotion remains blocked; and
- password hashes remain unchanged when no new password is submitted.

No production or normal local database was used. Browser QA remains
non-destructive; the PHP integration suite covers the mutating settings POST
boundary against a disposable database.

## Additional verification

- All PHP sources passed the Docker `php -l` sweep.
- All `4` tracked JavaScript files passed `node --check`.
- `git diff --check` passed.
- Disposable browser containers, volumes, networks, temporary files, test
  databases, users, secrets, and generated artifacts were cleaned up.

## Compatibility and rollback

Legacy authentication wrappers remain operational for all remaining callers.
To roll back this batch locally, revert the Batch 6F commits in reverse order:

```text
git revert <evidence-commit>
git revert 7102ffa
git revert 5b1b7d4
```

These reversions restore the legacy settings-page calls without changing
database data or schema.
