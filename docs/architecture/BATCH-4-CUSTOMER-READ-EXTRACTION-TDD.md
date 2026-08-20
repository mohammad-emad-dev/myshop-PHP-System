# Batch 4 Customer read extraction evidence

## Scope

This evidence was derived from the Batch 4 request rather than an external
plan file. The change extracts only bounded Customer count, page, and selector
reads into `includes/people.php`. Customer writes, the unbounded customer
loader, and the uncalled single-customer lookup remain in the compatibility
facade.

## RED and GREEN evidence

| Stage | Command | Result | Guarantee |
|---|---|---|---|
| RED | `docker compose run --rm --no-deps app php -r "require 'tests/Unit/people_read_test.php'; echo run_people_read_unit_tests(), PHP_EOL;"` | Failed because `includes/people.php` was absent. | The new test executes and fails for the intended missing module. |
| GREEN | Same focused command | Passed with `28` assertions. | The People module exists, pages use it directly, wrappers delegate without SQL, and customer writes remain outside it. |
| Integration | `docker compose exec -T -e TEST_DB_HOST=db -e TEST_DB_PORT=3306 -e TEST_DB_ROOT_USER=root -e TEST_DB_ROOT_PASSWORD=<local disposable-test value> app php tests/run.php` | Passed: `691 assertions (347 unit, 344 integration)`. | Customer name/phone/email search, pagination, ordering, selector fields, empty results, CRUD, orders, and authorization contracts remain covered. |
| Browser QA | `pwsh -NoProfile -File scripts/run-browser-qa.ps1` | Passed: `18` tests. | The disposable admin/customer-order journeys, responsive checks, keyboard smoke coverage, and automated axe checks passed at 375px, 768px, and 1440px. |

The root password value used for the disposable database was read locally and
was never printed or committed.

## Known gap

The existing PHP harness is assertion-based and has no configured coverage
reporter. No coverage dependency was added for this extraction. The full
regression, focused source-contract, integration, and disposable browser
checks above are the available verification evidence.
