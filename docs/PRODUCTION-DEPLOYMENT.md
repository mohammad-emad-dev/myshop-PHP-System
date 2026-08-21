# MyShop localhost deployment and operations runbook

MyShop is a localhost-first application for a developer or business computer.
It is not intended to be exposed directly to the public internet or to a LAN
by default. This historical filename is retained so existing links continue
to work; the runbook is intentionally local, not a cloud deployment guide.

The recommended local workflow is Docker Desktop with the Linux container
engine. XAMPP remains supported as the manual local alternative. In this model,
local operators are responsible for protecting the machine, local credentials,
and local backup files.

local operators are responsible for protecting the machine and local backup files.

Cloud controls such as TLS termination, WAFs, secret managers, external
monitoring, public firewall policy, off-site backup storage, and internet-facing
deployment are intentionally out of scope. Do not add them to a local install
as an implicit requirement.

## 1. Canonical Docker installation

From a clean checkout:

```powershell
Copy-Item .env.example .env
notepad .env
docker compose --env-file .env config --quiet
docker compose --env-file .env up --build -d
docker compose --env-file .env ps
```

Replace every password placeholder with a unique local value. Keep `.env`
outside Git and never print it, put it in an image, or paste its contents into
an issue or log. The application is available at
`http://127.0.0.1:${APP_PORT}`; the default is `http://127.0.0.1:8080`.

The Compose app port defaults to `127.0.0.1:8080` and the optional host MySQL
port defaults to `127.0.0.1:3307`. Both bindings are loopback-only. The app
waits for the MySQL health check before starting normal traffic, and the named
`mysql_data` volume retains the database across app/container restarts. The
project `public/uploads/` directory retains validated local uploads.

Create the first local administrator through the existing CLI-only bootstrap
profile after setting the three `BOOTSTRAP_ADMIN_*` values in `.env`:

```powershell
docker compose --env-file .env --profile bootstrap run --rm bootstrap
```

Do not use `down --volumes` for an active local installation. It destroys the
local database volume and is reserved for disposable verification.

## 2. XAMPP installation

Start Apache and MySQL from the existing XAMPP control panel. Configure the
project using the XAMPP Apache/PHP environment values:

```text
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=<local database name>
DB_USER=<local runtime user>
DB_PASSWORD=<local runtime password>
```

Keep these values in the local machine's Apache or PHP environment; `.env`
loading is not assumed by a manually managed XAMPP process. Configure Apache's
document root or virtual host to `public/`, keep the repository root outside
the web document root, and browse only to `http://127.0.0.1/` or the local port
configured by XAMPP. Initialize a new database with `database/schema.sql`, then
apply the documented migrations in order. The Docker bootstrap profile is not
used by XAMPP.

If Apache or MySQL reports a port conflict, use the documented port conflicts
procedure: stop the conflicting local service
or select an unused localhost port in XAMPP and update the matching local URL
or DB port. Never solve a local conflict by binding a service to every network
interface.

## 3. Ports and local exposure

| Service | Default host binding | Configuration | Purpose |
| --- | --- | --- | --- |
| MyShop Apache | `127.0.0.1:8080` | `APP_PORT` | Local browser traffic |
| Docker MySQL | `127.0.0.1:3307` | `MYSQL_PORT` | Optional host-side DB tools |
| XAMPP MySQL | `127.0.0.1:3306` | XAMPP config and `DB_PORT` | Manual local alternative |

The Docker app connects to the Compose service name `db` on port `3306`; the
host `MYSQL_PORT` is only for optional local tools. Keep Docker and XAMPP
stacks stopped when not in use so they do not compete for ports. If a port is
occupied, change only the local port value and retain the loopback address.

## 4. Health, readiness, and restart and recovery

`/health.php` is liveness-only. It returns a generic HTTP 200 response without
opening MySQL. `/ready.php` performs `SELECT 1`, returns generic HTTP 200 only
when the local database is ready, and returns generic HTTP 503 while MySQL is
stopped or unavailable. Neither endpoint exposes SQL errors, credentials,
filesystem paths, or stack traces.

Check and recover the Docker stack without printing `.env` values:

```powershell
docker compose --env-file .env ps
docker compose --env-file .env logs --tail 80 app db
docker compose --env-file .env up -d
docker compose --env-file .env restart
docker compose --env-file .env ps
```

After stopping and starting MySQL, wait for its health check and require
`/ready.php` to change from generic 503 back to 200 before retrying a write.
The liveness endpoint may remain 200 during that outage. After an application
or computer restart, the database volume and upload directory must still be
present.

## 5. Local backup and restore

An active administrator creates a backup from the protected Settings flow. The
request remains POST-only, CSRF-protected, and requires current-password
reauthentication. Save the download outside the repository and outside both
the public document root and upload directory. Local backup files contain
staff password hashes and must be protected like credentials.

Accept a backup only when it contains the exact completion marker:

```text
-- MYSHOP_BACKUP_COMPLETE
```

The marker is written only after the consistent snapshot and transaction have
completed. A failed or interrupted stream must not be treated as complete.
The application intentionally has no browser restore endpoint.

Before a migration or repair, back up the database and verify the dump in a
separately named disposable local database. Confirm the canonical table set,
foreign keys, representative rows, runtime-account privileges, and application
read access before considering the backup usable. Never blindly import
`database/schema.sql` over an existing database.

For a corrupted or replaced local database:

1. Stop application writes and preserve the original database volume or data
   directory. Do not overwrite the only recovery point.
2. Reject any dump without `MYSHOP_BACKUP_COMPLETE` and restore the verified
   dump into a uniquely named disposable local database first.
3. Validate schema, migrations, constraints, representative data, and a
   read-only application check.
4. Recreate or replace the local database only after the operator accepts the
   recovery point. Keep the original until the restored database is verified.
5. Restart the app, require readiness HTTP 200, and record the recovery point,
   operator, and validation result in the local operations record.

## 6. Migrations and rollback limits

For an existing local database, use the controlled schema account and apply
the migrations in order:

1. `database/batch2_staff_active.sql`
2. `database/batch3_product_history.sql`
3. `database/batch14_runtime_privileges.sql`
4. `database/batch17_login_rate_limit.sql`
5. `database/batch22_audit_log.sql`

Stop on the first migration error and inspect the database before retrying.
Container startup is not a migration mechanism. The repository does not
provide automatic DDL rollback; the recovery path is a verified backup into a
separately named local target followed by operator-approved replacement.

An application image or source rollback does not roll back database changes.
For the optional Compose production-stage verification, retain the previous
image digest and deploy only after the digest passes preflight. For routine
localhost work, restore the previous checkout only when its schema is
compatible with the current database.

## 7. Local incident quick runbook

### Database outage

1. Confirm `/health.php` remains liveness-only and `/ready.php` returns generic
   503; do not delete or recreate `mysql_data` or the XAMPP data directory.
2. Inspect bounded local logs and the Docker/XAMPP service state without
   printing `.env` or credentials.
3. Restore MySQL availability, wait for the health check, and require readiness
   200 before reopening writes.

### Failed application deployment

1. Stop the change and retain the failed checkout/image identity.
2. Restart the app after verifying the database is healthy.
3. If schema compatibility is uncertain, stop writes and use the backup/recovery
   procedure before changing the database. An application rollback does not
   undo a migration.

### Restore incident

1. Stop writes and preserve the original database; preserve the original database
   until the recovery target is validated.
2. Reject incomplete dumps, restore only into a uniquely named local target,
   and validate the schema and representative data.
3. Record the recovery point and operator decision before replacement.

## 8. Optional disposable image verification

The production-stage Compose file and preflight are retained for local
read-only verification of image isolation and release metadata. They are not a
cloud deployment or a prerequisite for Docker/XAMPP operation.

The repository's optional process may use a temporary CI build tag, but the
temporary CI build tag is never a deployable identity. Resolve the built image
ID to its immutable digest, run the preflight and release-integrity checks, and
retain only disposable credentials. Do not build from a protected deployment
file. Deploy only after the digest passes preflight.

The repository's CI actions remain pinned and the local checks may be run with:

```powershell
php scripts/ci-supply-chain-check.php
php scripts/release-integrity-check.php
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/run-production-smoke.ps1
```

These checks do not add cloud TLS, WAF, secret-manager, external monitoring,
registry promotion, or public firewall controls.

## 9. Local logging and security boundaries

Apache access logs use stdout and Apache/PHP technical errors use stderr in
Docker. Requests receive a server-generated `X-Request-ID`. Passwords,
cookies, authorization headers, CSRF tokens, request bodies, database
credentials, and sensitive customer data must remain out of logs. Public
health/readiness responses remain generic.

Authentication, CSRF validation, administrator authorization, upload
validation, rate limiting, security headers, and transactional business writes
remain active in both local workflows. Only `public/` may be the web document
root; `config/`, `database/`, backup files, and local environment files must
not be served.

## 10. Local readiness conclusion

The local readiness contract is satisfied when a clean checkout starts with
documented local values, both services remain loopback-bound, the database and
uploads survive restart, liveness/readiness return the documented safe
responses, a complete backup restores into a disposable target, and the full
regression and browser checks pass. Local operators remain responsible for the
host machine and backup-file security.
