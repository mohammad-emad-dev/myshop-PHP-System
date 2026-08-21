# Phase 5D: Final Localhost Release Gate

Status: PASS WITH RISKS for the localhost-first operating model.

This gate validates the current checkout end to end. It does not certify cloud
deployment, internet exposure, a hosted SaaS service, or an external release
artifact. TLS, WAFs, cloud secret managers, external monitoring, and public
firewall controls remain intentionally out of scope.

## Gate evidence

| Gate | Result | Evidence |
|---|---|---|
| Clean local startup | PASS | `docker compose --env-file .env config --quiet`, clean `down --remove-orphans`, and `up --detach --build db app`; app and database became healthy. |
| Loopback bindings | PASS | Runtime inspection reported `127.0.0.1:8080` for the app and `127.0.0.1:3307` for the optional host database port. |
| Health/readiness | PASS | `/health.php` returned `200 {"status":"ok","check":"liveness"}` and `/ready.php` returned `200 {"status":"ready","check":"database"}`. |
| Database outage/recovery | PASS | `scripts/run-production-smoke.ps1` stopped MySQL, verified liveness 200/readiness 503, restarted MySQL, verified readiness 200, and cleaned its project. |
| Disposable regression | PASS | `tests/run.php`: 3,587 assertions — 1,960 unit and 1,627 integration. Required `TEST_DB_*` variables were supplied without printing credentials. |
| Backup/restore | PASS | Focused backup/restore run: 84 assertions covering completion marker, excluded `LoginRateLimit`, isolated restore integrity, restricted runtime access, and cleanup. |
| Production preflight | PASS | `scripts/production-preflight.php` accepted disposable non-placeholder credentials, trusted loopback proxy configuration, and immutable verification-only image references. |
| PHP/JavaScript/Compose | PASS | 88 PHP files linted, 4 JavaScript files passed `node --check`, Compose config validated, and `git diff --check` passed. |
| Security/supply chain/release integrity | PASS | Tracked-file security scan and CI supply-chain scan reported zero findings; safe release-integrity metadata was generated for the current commit. |
| Browser QA | PASS | Existing Playwright suite passed 18/18 at 375px, 768px, and 1440px, including authentication, authorization, POS lookup, export, responsive overflow, keyboard, and accessibility-reporting journeys. |

## Phase 5C contract and performance evidence

The focused Phase 5C contracts passed with 79 unit assertions and 769
disposable integration assertions. The integration fixture uses 600 categories,
products, customers, suppliers, stock movements, and orders across two staff
scopes; bounded pages/selectors, ordering, prepared searches, scoping, and
representative EXPLAIN plans passed.

One disposable timing check measured five repeated operations on a fixed
600-category/600-product fixture:

- `catalog_get_products_page(..., 50, 0)`: 50 rows, 0.891 ms average;
- `catalog_get_pos_products(..., 100)`: 100 rows, 1.039 ms average;
- measured memory delta was 0 bytes for both repeated operations.

These are local container timings, not production benchmarks or service-level
objectives. They exclude network latency, concurrent users, cold image pulls,
large text payload variation, and long-running export throughput. No index or
schema change was justified or added.

## Defect found and fixed

The documentation review found a duplicated local-operator responsibility
sentence in `docs/PRODUCTION-DEPLOYMENT.md`. It was removed, and the current
localhost readiness source contract was updated to require the responsibility
statement once rather than requiring the duplicated wording.

## Cleanup and remaining risks

Post-gate inspection found no temporary databases, users, containers, browser
QA/production-smoke volumes, generated dumps, or repository artifacts. The
canonical local app and database containers remain healthy by design; the
normal `mysql_data` volume is preserved.

The remaining risk is release identity: localhost operation builds locally and
does not publish a registry artifact. The release-integrity command therefore
validated safe metadata shape with a disposable immutable verification
reference, not a remotely attested production image. The production-stage
Compose/preflight path remains available when a real immutable image digest is
deliberately supplied.

No application behavior, database schema, compatibility wrapper, UI, or cloud
deployment control changed in this gate. Nothing was pushed or merged.
