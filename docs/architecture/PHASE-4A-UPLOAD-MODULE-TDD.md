# Phase 4A: secure Uploads module extraction

## Scope

This batch extracted only the secure image-upload helpers from the
compatibility facade:

- `uploads_handle_image($file)` in `includes/uploads.php`
- `uploads_delete_newly_uploaded_image($relative_path)` in `includes/uploads.php`

The legacy `handle_image_upload()` and
`delete_newly_uploaded_image()` names remain in `includes/functions.php` as
thin delegation-only wrappers. `public/products.php` now calls the focused
functions directly. The page still owns request dispatch, authentication,
authorization, CSRF, input validation, cleanup decisions, generic messages,
HTTP responses, and rendering. Product database services and their behavior
were not changed.

## Pre-change caller inventory

Repository-wide search found four verified production call sites, all in
`public/products.php`:

| Location | Original call | Return-value use |
|---|---|---|
| `public/products.php:36` | `handle_image_upload($_FILES['image'])` | Stores the relative path; `false` enters the existing upload error response. |
| `public/products.php:47` | `delete_newly_uploaded_image($image_path)` | Best-effort cleanup after product-creation failure. |
| `public/products.php:65` | `handle_image_upload($_FILES['image'])` | Stores the relative path; `false` enters the existing upload error response. |
| `public/products.php:77` | `delete_newly_uploaded_image($image_path)` | Best-effort cleanup after product-update failure. |

No dynamic calls to either helper were found under `public/`, `includes/`,
`tests/`, or `scripts/`. Tests and architecture documents also referenced the
legacy names as compatibility or characterization contracts; those references
were updated only where they describe current active ownership.

## Characterized contracts

`uploads_handle_image()` preserves the previous implementation's security and
filesystem behavior:

- rejects missing or non-success upload errors and requires
  `is_uploaded_file()`;
- accepts only the existing JPEG/JPG, PNG, and GIF MIME mapping, with finfo
  content validation and `getimagesize()` structure/MIME consistency;
- rejects files larger than 5 MiB, images above 4096 pixels in either
  dimension, or images above 16 megapixels;
- resolves and creates only the canonical `public/uploads` directory below
  the public root;
- generates a 32-lowercase-hex random basename, preserves the validated
  extension, moves the file, and returns `uploads/<filename>`;
- returns `false` for every validation, path, random-name, directory, or move
  failure without exposing technical details to the caller.

`uploads_delete_newly_uploaded_image()` preserves the cleanup contract:

- accepts only the generated relative `uploads/[a-f0-9]{32}.(jpg|jpeg|png|gif)`
  shape;
- rejects traversal, absolute paths, invalid paths, symlinks, and paths whose
  canonical target is outside the canonical uploads directory;
- returns `true` for a safe missing path or successful unlink and `false` for
  unsafe paths or unlink failure;
- never reads session state, global state, database state, or the facade.

The focused module has no dependency on `includes/functions.php`, `$_SESSION`,
or `$GLOBALS`. The compatibility wrappers preserve the original signatures and
return values for remaining callers.

## TDD evidence

### RED checkpoint

Commit `aaba4e6e0b520c87cb8f8516480407703c34a096` added the source contracts,
disposable upload integration tests, and test-runner wiring before the module
existed.

Focused unit command:

```powershell
docker compose run --rm --no-deps app php -r "require 'tests/Unit/uploads_test.php'; echo 'UPLOAD_UNIT_ASSERTIONS=' . run_upload_unit_tests() . PHP_EOL;"
```

Expected RED result: failure because the focused upload source fixture did not
yet exist.

Focused integration command:

```powershell
docker compose run --rm --no-deps app php -r "require 'tests/Integration/upload_test.php'; echo 'UPLOAD_INTEGRATION_ASSERTIONS=' . run_upload_integration_tests() . PHP_EOL;"
```

Expected RED result: failure because the focused upload probe endpoint was not
yet available.

### GREEN implementation

Commit `0c44aa79714f9c9e8f56eb1a21751d834206a2ac` moved the implementations,
added the wrappers, and migrated the four page call sites.

Focused results:

- upload source/contract tests: **44 assertions**;
- disposable upload security/integration tests: **15 assertions**;
- architecture contracts: **101 assertions**;
- authentication/extraction contracts: **231 assertions**.

The upload integration tests used temporary files and a temporary PHP built-in
server. They covered valid PNG storage, cleanup, invalid MIME/content,
oversized input, invalid dimensions, no-file failure, missing-path idempotence,
preservation of an unrelated existing file, traversal/absolute/invalid paths,
and an outside-root symlink. All temporary files and server resources were
removed by the test.

## Verification evidence

Executed after the GREEN implementation:

- Full disposable PHP regression: **PASS: 1881 assertions** (1158 unit,
  723 integration).
- PHP lint over `config database includes public scripts tests`: **PASS, 68
  PHP files**.
- JavaScript syntax checks: **PASS, 4 tracked JavaScript files**.
- Repository security scan: **PASS, no findings**.
- CI supply-chain scan: **PASS, immutable references**.
- Browser QA runner: **18 passed** across 375px, 768px, and 1440px disposable
  environments; cleanup removed the disposable containers, image, network, and
  MySQL volume.
- `git diff --check`: **PASS** before the documentation update.

Browser QA was run because `public/products.php` caller wiring changed. No
visual baselines were added in this batch; automated accessibility checks are
not a substitute for a manual WCAG audit.

## Files and compatibility boundary

Production changes are limited to `includes/uploads.php`, the two facade
delegation wrappers in `includes/functions.php`, and the four focused calls in
`public/products.php`. Test changes add source and disposable integration
coverage and register those checks in `tests/run.php`. Current architecture
documentation records the focused ownership; historical batch evidence remains
unchanged.

Rollback is a local Git revert of the Phase 4A implementation and test commits,
followed by restoring the prior facade callers. No database migration or
runtime data change is part of this batch.
