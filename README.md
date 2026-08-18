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

Every push to `main` or `security-hardening-baseline`, and every pull request, runs the repository Quality Gate in GitHub Actions. It validates PHP syntax, JavaScript syntax, and the Docker Compose configuration.

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

## Database migrations

The normal PHP application never creates or migrates schema objects. Use a controlled deployment/schema account for existing databases.

For a database created from the original Batch 1 schema, run these migrations in order:

1. database/batch2_staff_active.sql
2. database/batch3_product_history.sql
3. database/batch14_runtime_privileges.sql
4. database/batch17_login_rate_limit.sql

Take and verify a backup before upgrading an existing database. Stop on the first migration error and inspect the database before retrying. Do not blindly import database/schema.sql into an existing database.

## Backups and recovery

Backups are generated only by an authenticated administrator and contain one-way staff password hashes. Treat every backup as sensitive credential-bearing data.

The local workflow has verified backup restoration into an isolated temporary database. A real deployment still needs:

- Encrypted and access-controlled backup storage.
- Off-site or independent backup copies.
- Defined retention, RPO, and RTO values.
- A scheduled restore drill against the target deployment environment.

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

Current published baseline commit: 06a4e9f (fix: address final regression findings).

## License

Add the project license before treating this repository as a reusable open-source package.
