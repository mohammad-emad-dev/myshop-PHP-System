# Batch 5 Supplier read extraction evidence

## Scope

This evidence was derived from the Batch 5 request rather than an external
plan file. The change extends `includes/people.php` with bounded Supplier
count, page, and selector reads. Supplier writes, the unbounded supplier
loader, and the uncalled single-supplier lookup remain in the compatibility
facade.

## RED and GREEN evidence

| Stage | Command | Result | Guarantee |
|---|---|---|---|
| RED | `docker compose run --rm --no-deps app php -r "require 'tests/Unit/people_read_test.php'; echo run_people_read_unit_tests(), PHP_EOL;"` | Failed because `people_count_suppliers()` was absent. | The new test executes and fails for the intended missing read boundary. |
| GREEN | Same focused command | Passed with `54` assertions. | The People module owns the supplier reads, pages use it directly, wrappers delegate without SQL, and supplier writes remain outside it. |
| Integration | `docker compose exec -T -e TEST_DB_HOST=db -e TEST_DB_PORT=3306 -e TEST_DB_ROOT_USER=root -e TEST_DB_ROOT_PASSWORD=<local disposable-test value> app php tests/run.php` | Passed: `731 assertions (381 unit, 350 integration)`. | Supplier search, pagination, ordering, selector fields, empty results, CRUD, admin purchase success, cashier purchase denial, inventory, CSRF, audit, and authorization contracts remain covered. |
| Browser QA | `pwsh -NoProfile -File scripts/run-browser-qa.ps1` | Passed: `18` tests. | Disposable order/POS authorization, responsive checks, keyboard smoke coverage, and automated axe checks passed at 375px, 768px, and 1440px. |

The root password value used for the disposable database was read locally and
was never printed or committed.

## Known gap

The existing PHP harness is assertion-based and has no configured coverage
reporter. No coverage dependency was added for this extraction. The full
regression, focused source-contract, integration, and disposable browser
checks above are the available verification evidence.
