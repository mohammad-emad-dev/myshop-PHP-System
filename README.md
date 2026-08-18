# MyShop Inventory, POS, and Order Management System

Native PHP and MySQL inventory, point-of-sale, and order management application.

The repository contains the completed application-hardening work through Batch
13 and the Batch 14 migration and database least-privilege hardening. The
current codebase includes the database and installation foundation,
authentication and HTTP-boundary protections, inventory/order data-integrity
controls, browser/XSS hardening, upload and CSV safeguards, protected backups,
security headers/CSP, reproducible Docker setup, browser-QA fixes, release
hygiene, and server-side product pagination. Batch 14 keeps the canonical
schema unchanged while making the active-staff migration safe across supported
database baselines and restricting the normal PHP runtime account to CRUD
privileges.

## Project overview and architecture

MyShop is a server-rendered native PHP application for inventory, POS sales and
purchases, staff administration, reporting, CSV export, image uploads, and
database backups. The application uses MySQL/MariaDB through `mysqli`; it does
not require a PHP framework or a JavaScript build step.

The web boundary is `public/`, which is the only directory that should be used
as the Apache document root. Shared PHP behavior lives under `includes/`,
configuration is under `config/`, and installation/schema operations live under
`database/`. The local Docker definition is kept in `Dockerfile` and
`docker-compose.yml`.

Repository layout:

```text
config/                 Runtime configuration and database connection
database/               Canonical schema, reviewed migrations, CLI bootstrap
includes/               Shared functions, authentication, layouts, helpers
public/                 Web document root, PHP routes, CSS, JavaScript, uploads
docs/                   Project documentation and review material
screenshots/            Curated documentation images only
output/                 Generated local QA output (ignored by Git)
.playwright-cli/        Local browser session state (ignored by Git)
```

The source remains a traditional page-oriented PHP application. Pages call
shared functions for database and security operations and render the existing
HTML/CSS UI. This structure is intentionally documented as-is; a future
refactor may introduce stronger service/repository boundaries, but this release
does not change application behavior.

## Current modules

- Authentication and staff management
- Product and category management
- Customer and supplier management
- Sales and purchase orders
- Stock movement history
- Dashboard, exports, and supporting reports already present in the application

These modules do not by themselves establish production readiness. The current
limitations and deployment responsibilities are listed below and must be
reviewed before a real deployment.

## Requirements

- PHP 8 or later with the `mysqli` extension enabled
- MySQL or MariaDB with InnoDB support
- A web server configured with `public/` as the document root
- A command-line PHP runtime for the one-time administrator bootstrap command

Runtime verification is environment-specific and must be rerun for each target
environment. This repository does not include a complete automated test suite or
a runtime test harness.

## Reproducible Docker local runtime

Docker Desktop with the Linux container engine is the recommended local
verification environment. The stack uses `php:8.3-apache-bookworm` with
`mysqli`, `fileinfo`, GD, and mbstring, and `mysql:8.4.3`. Apache serves only
`/var/www/html/public`; the repository source is mounted read-only and only
`public/uploads` is mounted read-write for image uploads. The MySQL data is
kept in the named `mysql_data` volume.

### 1. Create local environment values

Copy the example and replace every password placeholder with a locally scoped,
random value. Never commit `.env`:

```powershell
Copy-Item .env.example .env
notepad .env
```

The normal web service receives only `DB_HOST`, `DB_PORT`, `DB_NAME`,
`DB_USER`, and `DB_PASSWORD`. `DB_SCHEMA_USER` and `DB_SCHEMA_PASSWORD` are
deployment-only documentation variables; they are not passed to the web
service. The `BOOTSTRAP_ADMIN_*` variables are used only by the opt-in CLI
bootstrap profile.

### 2. Validate and start the stack

Validate the resolved Compose model before starting services:

```powershell
docker compose --env-file .env config --quiet
docker compose --env-file .env up --build -d
docker compose --env-file .env ps
```

On the first start of a new `mysql_data` volume, MySQL automatically imports
`database/schema.sql` as `001-schema.sql`, then runs a root-only initialization
hook that applies `database/batch14_runtime_privileges.sql` to the configured
runtime account. This is the canonical schema and contains no administrator
password or demo user. Initialization scripts do not run again for an already
initialized volume.

The normal PHP service uses only the runtime account. The clean-volume hook
explicitly revokes the broad privileges initially granted by the official
MySQL image and grants only `SELECT`, `INSERT`, `UPDATE`, and `DELETE` on the
application database. Schema creation and migrations remain deployment/schema
operations and are not performed by PHP requests.

To inspect startup or health failures:

```powershell
docker compose --env-file .env logs --tail=100 db
docker compose --env-file .env logs --tail=100 app
```

The PHP healthcheck requests the document root and therefore also detects a
failed application database connection. The MySQL healthcheck uses the
container root password only inside the database container.

### 3. Import the schema manually when required

Use a manual import only for a new/disposable database or an explicitly
reviewed deployment operation. Do not import the schema into an already
initialized database without checking its state first:

```powershell
docker compose --env-file .env cp database/schema.sql db:/tmp/schema.sql
docker compose --env-file .env exec db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" < /tmp/schema.sql'
```

Existing databases require the documented migration order below, not a fresh
schema import. Migrations remain deployment/schema-account operations and are
never executed by the web service.

### 4. Bootstrap the first administrator through CLI only

Set the three `BOOTSTRAP_ADMIN_*` values in `.env`, then run the dedicated
Compose profile. This starts the existing `database/bootstrap_admin.php` with
PHP CLI (`PHP_SAPI === 'cli'`); it is not an HTTP route and is not part of the
normal `app` service environment:

```powershell
docker compose --env-file .env --profile bootstrap run --rm bootstrap
```

The bootstrap password must be at least 12 characters. After the command
reports success, open `http://127.0.0.1:8080/login.php` (or the configured
`APP_PORT`) and sign in with the administrator credentials you supplied.

### 5. Run static checks in the container

When Docker is available, lint every application PHP file using the exact PHP
runtime supplied by the Dockerfile:

```powershell
docker compose --env-file .env run --rm --no-deps app sh -c 'find config database includes public -type f -name "*.php" -print0 | xargs -0 -n1 php -l'
docker compose --env-file .env run --rm --no-deps app php -m
```

The second command should show `mysqli`, `fileinfo`, `gd`, and `mbstring`.

### 6. Stop the local runtime

Stop containers while preserving database data:

```powershell
docker compose --env-file .env down
```

To destroy the local database volume and force a clean schema initialization,
use the following only when the data is disposable:

```powershell
docker compose --env-file .env down --volumes
```

### Local smoke-test checklist

After the containers report healthy, manually verify:

- Login succeeds with the bootstrapped administrator; invalid credentials and
  inactive accounts are rejected.
- Category, customer, supplier, product, and staff CRUD operations work, with
  CSRF failures rejected.
- Sale and purchase orders calculate totals from server-side product data and
  preserve stock movement history.
- Valid image uploads display correctly; invalid types, oversized images, and
  executable extensions are rejected.
- CSV exports download with the expected entity/date filters and do not execute
  spreadsheet formulas from text fields.
- Database backup requires POST, CSRF, active-admin authorization, and current
  password re-authentication.
- Browser responses include the expected security headers and CSP; no console
  CSP violations occur during the tested flows.
- Direct GET requests to mutation endpoints do not change state, and invalid
  or missing CSRF tokens fail safely.

## Local installation

### 1. Create the database and application account

Create the database with an administrative account. Use a dedicated application
account with a strong password; do not use MySQL `root` from the application.
The host in the account definition must match the host used by `DB_HOST`.

```sql
CREATE DATABASE ioms_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE USER 'myshop_runtime'@'%'
    IDENTIFIED BY 'REPLACE_WITH_A_LONG_RANDOM_PASSWORD';

GRANT SELECT, INSERT, UPDATE, DELETE
    ON ioms_db.* TO 'myshop_runtime'@'%';

FLUSH PRIVILEGES;
```

The password above is a placeholder only. Replace it during local setup and do
not commit the resulting value.

### 2. Configure the PHP process environment

Copy `.env.example` as a reference. The application intentionally does not load
`.env` files automatically. Configure these variables in the web server/PHP
process environment instead:

```text
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=ioms_db
DB_USER=myshop_runtime
DB_PASSWORD=your_actual_local_password
```

All five variables are required. The application does not fall back to a blank
password, `root`, or another implicit local-development credential. The
runtime account must not receive `CREATE`, `ALTER`, `DROP`, `INDEX`,
`GRANT OPTION`, or global administrative privileges.

Use a separate deployment/schema account for imports and migrations, or use
the controlled root initialization path in Docker. The schema account is never
passed to the normal PHP service.

### 3. Import the canonical schema

The base schema is `database/schema.sql`. It must be imported into the already
created database by a deployment/schema account that has permission to create
tables and constraints. The file does not drop, create, or select a database.

For example, from a POSIX shell:

```bash
mysql --host=127.0.0.1 --port=3306 --user=SCHEMA_ACCOUNT --password ioms_db \
    < database/schema.sql
```

On Windows, use the equivalent MySQL client import command for the installed
shell. Run the schema once against a new database and treat later schema changes
as explicit migrations reviewed separately from request handling.

The schema contains only the tables, constraints, and three required system
records used by the current application fallback behavior: the `General`
category, `Walk-in Customer`, and `General Supplier`. It contains no demo users,
products, orders, or passwords.

### 3a. Upgrade an existing Batch 1 database

Existing databases must be upgraded in this exact order, using an explicit
deployment/schema account:

1. `database/batch2_staff_active.sql`
2. `database/batch3_product_history.sql`
3. `database/batch14_runtime_privileges.sql`

Run each migration successfully before starting the next. These files
must be executed from a controlled CLI/deployment process and must never be
included from PHP, `config/db.php`, a web request, or an application startup
path. Do not run them with a client option that ignores SQL errors. Stop on the
first failure, preserve the error output, and inspect the database before
retrying; do not blindly rerun a partially applied migration.

Batch 2 is an idempotent baseline bridge. It conditionally adds the
`Staff.is_active` column, `chk_staff_is_active` check, and `idx_staff_active`
index only when they are missing. It is therefore safe to run against both an
old Batch 1 database and a current database created from `database/schema.sql`.
An unexpected existing index definition or unrelated DDL failure stops the
migration; failures are not ignored.

Before running the migrations, take a verified backup and confirm that the
database uses InnoDB and was created from the canonical Batch 1 schema. Batch 3
changes the history-preserving foreign keys for `OrderDetail` and
`StockMovement`. Batch 14 revokes excessive privileges from the runtime account
and grants only the application CRUD set. It requires the deployment account
to set the runtime account variables before sourcing the file:

```bash
mysql --host="$DB_HOST" --port="$DB_PORT" \
  --user="$DB_SCHEMA_USER" --password --database="$DB_NAME" \
  --init-command="SET @myshop_runtime_user = '$DB_USER'; SET @myshop_runtime_host = '%';" \
  < database/batch14_runtime_privileges.sql
```

Use the actual MySQL account host if an existing deployment does not use `%`.
The schema account password is entered interactively or supplied by the
deployment secret manager; no migration credential belongs in this repository.

Databases created by versions older than Batch 1 may have different tables,
columns, or foreign-key names. Stop and perform a manual schema and data
inspection before running either migration; do not assume these migration
scripts are compatible with those databases.

### 4. Create the first administrator from the CLI

`database/bootstrap_admin.php` is deliberately CLI-only. Set a real local
administrator username, name, and password in the process environment, then run:

```bash
BOOTSTRAP_ADMIN_USERNAME=first_admin \
BOOTSTRAP_ADMIN_FULL_NAME="First Administrator" \
BOOTSTRAP_ADMIN_PASSWORD="choose-a-long-unique-password" \
php database/bootstrap_admin.php
```

The password must be at least 12 characters and is stored using PHP's
`password_hash()` function. No administrator credential is supplied by this
repository. On PowerShell, set the same three environment variables with
`$env:VARIABLE_NAME = 'value'` before invoking `php database/bootstrap_admin.php`.

### 5. Configure the web server

Set the virtual host/document root to the repository's `public/` directory. Do
not expose the repository root, the `config/` directory, the `database/`
directory, uploaded files, or backup files as web-accessible content. Enable
HTTPS before using the application with real data.

Open `login.php` through the configured virtual host after the administrator
bootstrap completes.

## Configuration and data handling

- `.env.example` is documentation and contains no real secret.
- Copy `.env.example` to `.env` for local Docker use and fill in local-only
  values. `.env` is ignored by Git and must never be committed. The example
  file contains placeholder/sample values only; replace every password before
  starting a local stack.
- `.env`, local configuration overrides, uploaded files, generated browser
  sessions, raw QA output, and local backup paths are ignored by Git where
  applicable. The four existing images in `screenshots/` are intentionally
  retained as curated documentation assets; new raw screenshots belong in the
  ignored output locations.
- Database errors are logged server-side while users receive a generic
  connection failure message.
- Demo/sample data is intentionally separate from the base schema. No
  development seed file is included in the repository because the current
  application has no safe sample-data requirement for installation.
- `database/bootstrap_admin.php` is an installation utility, not a web route.

## Database backups

Database backups are generated only by an authenticated, active administrator
through a POST request with CSRF protection and current-password
re-authentication. The backup contains the canonical project tables and
includes `Staff.password` one-way password hashes so a restore preserves staff
authentication. It never contains plaintext passwords. Treat every downloaded
backup as highly sensitive credential-bearing data; store it outside the web
root with restricted access and rotate administrator credentials if a backup is
exposed.

The backup endpoint uses a fixed table allow-list and a consistent read
transaction. It does not include environment variables, database credentials,
or filesystem secrets. It is a download mechanism only; backup retention,
encryption at rest, transfer protection, and restore verification remain
deployment responsibilities.

## Browser security boundary

The public document root emits these enforced headers from `public/.htaccess`:

- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy` disables camera, microphone, geolocation, payment, USB,
  motion-sensor, and display-capture capabilities
- `X-Frame-Options: DENY`

HTML responses generated by the shared PHP layout, `login.php`, and
`print_invoice.php` also emit an enforced per-response Content Security Policy:

```text
default-src 'self'; base-uri 'self'; object-src 'none'; frame-src 'none'; frame-ancestors 'none';
form-action 'self'; script-src 'self' 'nonce-<per-response-value>'
  https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; script-src-attr 'none';
style-src 'self' 'nonce-<per-response-value>' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com
  https://fonts.googleapis.com; style-src-attr 'none';
img-src 'self' data:; font-src 'self' https://fonts.gstatic.com
  https://cdnjs.cloudflare.com; connect-src 'self'
```

`unsafe-eval` is not allowed, and `unsafe-inline` is not allowed for scripts.
All remaining inline scripts and the print stylesheet use a cryptographically
random response nonce. All inline `style` attributes in `public/` and
`includes/` have been migrated into reusable or narrowly scoped classes in
`public/assets/css/style.css`; dashboard progress widths use a bounded numeric
`data-progress` value handled by the external script. HTML inline event
handlers were removed, including the logout confirmation handlers.

SweetAlert2's pinned browser bundle creates a runtime `<style>` element. The
self-hosted `public/assets/js/sweetalert-csp.js` loader runs before that bundle
and applies only the current response nonce to dynamically created style
elements. This keeps the enforced policy nonce-based; neither `unsafe-inline`
nor `unsafe-eval` is used.

Bootstrap 5.3.2, Font Awesome 6.4.2, SweetAlert2 11.10.0, Chart.js 4.4.1,
and html2pdf.js 0.10.1 are pinned to exact URLs and emitted with verified
SHA-384 SRI metadata and `crossorigin="anonymous"`. Google Fonts remains a
documented exception: its CSS is generated by the Google Fonts service and
does not provide a stable, reliable SRI hash. The safer long-term alternative
is to self-host the selected font files and CSS.

The Google Fonts exception is restricted by CSP to `fonts.googleapis.com` for
the stylesheet and `fonts.gstatic.com` for font files. It is not a substitute
for self-hosting in a production deployment with stricter supply-chain
requirements.

The public document root also serves `assets/favicon.svg` and rewrites the
legacy `/favicon.ico` request to that validated asset.

HSTS is intentionally not emitted by this repository because local development
uses HTTP. Production deployments must enable HSTS only after HTTPS is
confirmed and configured for the complete host.

## Repository and release hygiene

Before any GitHub publication or subsequent release commit, review the staged
file list and run the local secret and artifact checks described below. This
repository is not production ready merely because it has a local Docker runtime.
Do not add `.env`, browser
session directories, raw screenshots, generated output, database dumps, or
local backups. Keep `.env.example`, the canonical schema, and reviewed migration
files versioned so a fresh checkout remains reproducible.

The current `.gitignore` intentionally preserves these safe repository inputs:
`.env.example`, `public/uploads/.gitkeep`, and files under `database/` including
the canonical schema and reviewed migrations. It ignores local runtime state
and generated artifacts instead. Every GitHub publication must be reviewed
locally before publication; this repository does not configure a remote or push
to GitHub automatically.

Security controls currently implemented include environment-based database
configuration, generic user-facing database errors, password hashing,
database-backed active-staff authorization, session idle expiry, POST and CSRF
protection for state-changing actions, validated non-executable image uploads,
formula-safe CSV exports, an allow-listed authenticated backup endpoint, CSP
without `unsafe-eval` or `unsafe-inline`, and standard browser security headers.
These controls still require runtime and deployment verification; they are not a
claim of production readiness.

Current limitations and deployment responsibilities include: no complete
automated regression suite, no verified backup restore in this repository, no
cross-browser or accessibility certification, Google Fonts remains an explicitly
documented external CSP/SRI exception until fonts are self-hosted, production
HTTPS/HSTS and secret provisioning are deployment concerns, and order-detail
visibility currently allows authenticated staff according to the application’s
documented policy. Local `QA_BATCH9_*` records, if present, belong to the Docker
MySQL volume and are not installation files; inspect or reset disposable local
data separately from source control.

## Verification status

The Batch 11 pre-commit gate runs Compose configuration validation, PHP syntax
checks, JavaScript syntax checks, candidate-file secret scanning, and Git ignore
review. A successful local gate verifies repository hygiene and syntax only; it
does not certify deployment behavior or production readiness.

The project still has no complete automated regression suite. Before any real
deployment, run the Docker/browser smoke checklist, authentication and CSRF
tests, concurrent inventory/order tests, upload and export tests, backup restore
verification, and deployment-specific HTTPS and header checks against the
target environment.
