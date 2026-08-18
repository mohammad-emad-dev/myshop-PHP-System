# MyShop

MyShop is a native PHP and MySQL inventory, point-of-sale, and order management system.

It is built as a portfolio-grade software engineering project with a focus on secure database access, role-based authorization, transactional stock updates, and practical deployment hygiene.

> **Current status:** Security-hardened Release Candidate. The current baseline is published on `main` and mirrored on the [security-hardening-baseline](https://github.com/mohammad-emad-dev/myshop-PHP-System/tree/security-hardening-baseline) for traceability. It is suitable for portfolio review and local evaluation; production deployment still requires the deployment checklist below.

## Highlights

- Admin and cashier roles with server-side authorization.
- Product, category, customer, supplier, staff, and stock management.
- Sales and purchase orders with server-side pricing and stock validation.
- Transactional order creation and stock-movement history.
- Server-side product pagination for catalog views.
- Dashboard metrics, reports, CSV export, invoices, and protected database backups.
- Password hashing, password policy enforcement, CSRF protection, secure sessions, and generic database errors.
- Account/IP-aware login rate limiting with fail-closed behavior when its database control is unavailable.
- Queryable audit logging for authentication, authorization denials, staff, inventory, orders, settings, and backup operations.
- Restricted runtime database privileges: SELECT, INSERT, UPDATE, and DELETE only.
- Hardened uploads, CSV formula protection, security headers, and a nonce-based Content Security Policy.
- Docker-based local runtime with a dedicated schema/migration workflow.

## Screenshots

| Login | Dashboard |
|---|---|
| ![Login screen](screenshots/login.png) | ![Dashboard](screenshots/Dashboard.png) |

| Products | Orders |
|---|---|
| ![Products screen](screenshots/Products.png) | ![Orders screen](screenshots/orders.png) |

## Architecture

MyShop is a server-rendered, page-oriented PHP application. It does not require a PHP framework or a JavaScript build step.

~~~text
public/                 Apache document root and web routes
includes/               Shared authentication, database, validation, and layouts
config/                 Runtime database configuration
database/               Canonical schema, migrations, and CLI bootstrap utility
public/assets/          CSS, JavaScript, and static assets
public/uploads/         Validated user-uploaded images
docker/                 Apache container configuration
docs/                   Supporting project documentation
screenshots/            Curated documentation images
~~~

Only public/ should be exposed as the web document root. The repository root, config/, database/, backups, and local environment files must not be publicly served.

## Run locally with Docker

### Requirements

- Docker Desktop with the Linux container engine.
- Git.

### 1. Clone the published baseline

~~~powershell
git clone -b security-hardening-baseline https://github.com/mohammad-emad-dev/myshop-PHP-System.git
cd myshop-PHP-System
~~~

### 2. Create local environment values

~~~powershell
Copy-Item .env.example .env
notepad .env
~~~

Replace every password placeholder with a separate, long, locally scoped value. Never commit .env.

### 3. Validate and start the stack

~~~powershell
docker compose --env-file .env config --quiet
docker compose --env-file .env up --build -d
docker compose --env-file .env ps
~~~

The application is available at http://127.0.0.1:8080 by default. The port can be changed through APP_PORT in .env.

The first initialization of a new MySQL volume imports database/schema.sql and applies the runtime privilege restriction. Initialization scripts do not run again for an already initialized volume.

### 4. Create the first administrator

Set these values in .env:

~~~text
BOOTSTRAP_ADMIN_USERNAME=local_admin
BOOTSTRAP_ADMIN_FULL_NAME=Local Administrator
BOOTSTRAP_ADMIN_PASSWORD=replace_with_a_long_unique_password
~~~

Then run the CLI-only bootstrap profile:

~~~powershell
docker compose --env-file .env --profile bootstrap run --rm bootstrap
~~~

The bootstrap password must be at least 12 characters. The bootstrap utility is not an HTTP route and is not included in the normal web service environment.

### 5. Stop the stack

~~~powershell
docker compose --env-file .env down
~~~

Do not use down --volumes unless the local database is disposable.

## Verification

Validate the Compose model and lint the PHP sources with the same runtime used by Docker:

~~~powershell
docker compose --env-file .env config --quiet
docker compose --env-file .env run --rm --no-deps app sh -c 'find config database includes public -type f -name "*.php" -print0 | xargs -0 -n1 php -l'
~~~

The reviewed baseline has also been checked with disposable database integration tests, authorization/CSRF HTTP checks, Docker health checks, JavaScript syntax checks, and database-failure return-contract tests.

Every push to `main` or `security-hardening-baseline`, and every pull request, runs the repository Quality Gate in GitHub Actions. It validates PHP syntax, JavaScript syntax, Docker Compose configuration, and the disposable database regression suite.

### Automated regression tests

The repository uses a dependency-free CLI PHP harness instead of PHPUnit. This keeps the project free of a Composer dependency while still executing the real application functions and real MySQL transactions.

Run the unit and integration suite from Docker after the local stack is running. The command reads the root password into a process environment variable without printing it:

~~~powershell
$rootPasswordLine = Get-Content .env | Where-Object { $_ -match '^MYSQL_ROOT_PASSWORD=' } | Select-Object -First 1
$env:TEST_DB_ROOT_PASSWORD = $rootPasswordLine -replace '^MYSQL_ROOT_PASSWORD=', ''
docker compose --env-file .env exec -T `
  -e TEST_DB_HOST=db -e TEST_DB_PORT=3306 -e TEST_DB_ROOT_USER=root `
  -e TEST_DB_ROOT_PASSWORD="$env:TEST_DB_ROOT_PASSWORD" app php tests/run.php
Remove-Item Env:TEST_DB_ROOT_PASSWORD
~~~

The integration harness generates a unique `myshop_test_*` database and a random temporary runtime account. A controlled schema/root account loads `database/schema.sql` followed by the documented migrations; application operations then run through the restricted temporary runtime account. The harness never selects `ioms_db`, never uses the normal runtime account, and drops the temporary database and account in a `finally` cleanup step. Cleanup failure fails the test job and identifies the leftover artifact.

The test layers are deliberately separate:

- `tests/Unit/` covers sanitization, identifiers, password policy, CSRF helpers, and validation that completes before database access.
- `tests/Integration/` covers real MySQL schema, migrations, CRUD, transactions, stock/order integrity, authorization-sensitive order creation, rate limiting, and database-failure contracts.
- Browser smoke tests remain a separate local verification layer. They require a running web server and browser automation; they are not represented as passing unit or integration tests.

The GitHub Actions regression job creates its own disposable MySQL container with a generated root password, runs `php tests/run.php`, and removes the container and its volume even when the tests fail.

## Security model

- State-changing requests require POST and CSRF validation.
- Cashiers are restricted to sales and their own order visibility; administrative mutations are server-side protected.
- Product prices and stock are read and validated on the server.
- Order and stock writes use transactions and rollback on failure.
- Login failures are rate-limited per normalized account/IP pair.
- Login rate-limit failures fail closed with a generic response.
- The application database account cannot create, alter, drop, or grant database objects.
- Backup downloads require an active administrator, POST, CSRF validation, and current-password re-authentication.
- Uploaded files are validated and served from a protected upload directory.
- Database errors are logged server-side; SQL details and stack traces are not shown to users.
- Security headers and a nonce-based CSP protect the browser boundary.
- Audit-log reads are administrator-only and paginated; audit metadata is bounded and excludes passwords, CSRF tokens, session IDs, credentials, request bodies, and dump contents.

## Database migrations

The normal PHP application never creates or migrates schema objects. Use a controlled deployment/schema account for existing databases.

For a database created from the original Batch 1 schema, run these migrations in order:

1. database/batch2_staff_active.sql
2. database/batch3_product_history.sql
3. database/batch14_runtime_privileges.sql
4. database/batch17_login_rate_limit.sql
5. database/batch22_audit_log.sql

Take and verify a backup before upgrading an existing database. Stop on the first migration error and inspect the database before retrying. Do not blindly import database/schema.sql into an existing database.

Batch 22 records security-sensitive and business-critical events in `AuditLog`. The migration is idempotent when the table is absent or already matches the canonical schema, but it deliberately fails on a partial or incompatible table so unrelated schema problems are not hidden. Execute migrations with a controlled deployment/schema account only; never run them from a web request or application startup. Databases created by pre-Batch-1 versions may require manual inspection before applying this order.

Fresh databases receive `AuditLog` from `database/schema.sql`; the migration is still required for existing databases and is safe to re-run against the canonical table.

Transactional product, stock, and order writes insert their success audit event before commit and roll back when that audit insert fails. Authentication, non-transactional CRUD/settings, authorization-denial, and backup-attempt entries use bounded best-effort logging: an audit insert failure is logged server-side and does not falsely report an audit event as successful or expose a database error to the user. Audit retention and deletion are deployment responsibilities and must follow the organization’s legal and operational requirements.

Administrators can review the bounded log at `public/audit_log.php`. Cashiers have no route access to this page. The application has no web restore endpoint; database restores are deployment/schema-account operations and must be recorded in the deployment change/operations log. The controlled CLI/test restore verification workflow is documented below.

## Backups and recovery

The application backup endpoint is a download-only operation. It has no web restore endpoint and does not write a backup file to the server. An active administrator must submit a `POST` request from the existing Settings UI with a valid CSRF token and current-password re-authentication. The response uses a fixed filename, no-cache headers, and streams the SQL dump through `php://output`.

The backup allow-list is explicit and ordered for foreign-key-safe restore:

`Staff`, `Category`, `Customer`, `Supplier`, `Product`, `Order`, `OrderDetail`, `StockMovement`, and `AuditLog`.

`LoginRateLimit` is intentionally excluded. It contains ephemeral account/IP failure state, not business history; restoring it could reintroduce expired blocks. A fresh target must receive `LoginRateLimit` from the canonical schema and `database/batch17_login_rate_limit.sql`, rather than from a business backup. Staff password fields contain one-way hashes because they are required for restore integrity; plaintext passwords are never stored or emitted. Treat every backup as highly sensitive credential-bearing data.

The stream ends with `-- MYSHOP_BACKUP_COMPLETE`, written only after the consistent read-only snapshot commits. Operators and restore tooling must reject a file without this marker. A failure after streaming begins may leave an incomplete download; the endpoint records the failure server-side, appends a non-technical failure marker when possible, and the incomplete file must be deleted rather than restored.

#### Local isolated restore verification

Run the existing CLI regression harness after the stack is running. It creates a unique `myshop_test_*` source database, applies `database/schema.sql` and all migrations in the documented order, generates a backup outside the repository, initializes a separate unique `myshop_restore_qa_*` database with the canonical schema and all migrations, loads the backup with a controlled root/schema connection, verifies tables, columns, indexes, foreign keys, row counts, and representative relationships, confirms that the excluded `LoginRateLimit` table is fresh, tests a restricted CRUD-only runtime account, and removes both databases, the temporary account, and files in a `finally` cleanup path:

~~~powershell
# Supply TEST_DB_ROOT_PASSWORD from a private deployment environment; never paste it into source or logs.
docker compose --env-file .env exec -T `
  -e TEST_DB_HOST=db -e TEST_DB_PORT=3306 -e TEST_DB_ROOT_USER=root `
  -e TEST_DB_ROOT_PASSWORD="$env:TEST_DB_ROOT_PASSWORD" app php tests/run.php
~~~

The verifier refuses `ioms_db` and the configured `DB_NAME` as source or restore targets. It uses the root/schema account only for disposable schema setup, restore, metadata checks, grants, and cleanup. The restored application connection is tested with a temporary restricted account; it is not granted DDL or `GRANT OPTION`. The normal local database and Docker volume are not selected by this workflow.

#### Production backup procedure and responsibilities

1. Configure the application `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` values from the production secret manager. Keep the runtime account CRUD-only. Keep schema/root credentials in a separate deployment secret, never in the web service environment or repository.
2. An active administrator downloads a backup through Settings after current-password re-authentication. Save it only to a protected path outside `public/`, the repository, and Docker bind mounts that serve the application.
3. Verify the completion marker and run the isolated restore verification before accepting the artifact into retention storage.
4. Encrypt the verified artifact at rest using the deployment-approved KMS/key-management process before it leaves the operator workstation or enters backup storage. Encryption is an operational requirement; this PHP endpoint intentionally streams plaintext SQL to the authenticated administrator and does not hold an encryption key.
5. Keep encryption keys outside `.env`, Git, backups, and the web container. For key rotation, re-encrypt retained artifacts with the new key and retain the old key only until all artifacts encrypted with it expire. If the encryption key or KMS is unavailable, fail the storage job and do not retain an unencrypted backup.
6. Keep at least one independent/off-site copy in storage controlled separately from the application host. Off-site replication is not configured by this repository and must not be assumed.

Deployment-owned variables should be defined in the secret manager or backup scheduler, not committed here:

- `BACKUP_STORAGE_PATH` — protected path outside the document root.
- `BACKUP_ENCRYPTION_KEY_ID` — KMS/key identifier; no key material belongs in the repository.
- `BACKUP_RETENTION_DAYS` — operator-approved retention value.
- `BACKUP_RPO` and `BACKUP_RTO` — documented service objectives.

Suggested starting values such as daily backups retained for 35 days, weekly copies for 12 weeks, and monthly copies for 12 months are policy placeholders only. Operators must choose values based on legal, business, storage, and recovery requirements. Define the actual RPO (`[deployment decision]`) and RTO (`[deployment decision]`) before production use, and schedule restore drills against the real deployment environment.

For a manual restore, stop application writes, create a uniquely named target database, apply `database/schema.sql` and the documented migrations first, and use a controlled schema/deployment account—not `DB_USER`—to load only a verified file whose completion marker is present. This preserves a fresh `LoginRateLimit` table while the backup replaces the allow-listed business and audit tables. Validate the schema, constraints, indexes, row counts, and application read access before any controlled cutover. There is deliberately no browser-accessible restore route.

Never place backups inside the document root, repository, or a public download directory.

## Production readiness roadmap

The current baseline is not certified for a large production deployment. Remaining hardening work includes:

- HTTPS, secure secret provisioning, production HSTS, firewalling, and monitoring.
- Pagination or bounded search for large POS, report, history, and selector datasets.
- Pinning Docker image digests and operating-system package versions after compatibility testing.
- CI checks for syntax, security scanning, migrations, Docker builds, and regression tests.
- Fresh cross-browser and accessibility verification.
- Splitting the large shared functions module into focused application services over time.

These items are tracked as follow-up engineering work rather than hidden behind a production-ready claim.

## Git workflow

The project follows a lightweight GitHub Flow:

1. Create a short-lived feature or fix branch from the current baseline.
2. Make one focused change.
3. Run the relevant verification checks.
4. Commit with a descriptive Conventional Commit message.
5. Open a Pull Request and document the evidence.

Security baseline preceding the regression-suite work: 06a4e9f (fix: address final regression findings).

## License

Add the project license before treating this repository as a reusable open-source package.
