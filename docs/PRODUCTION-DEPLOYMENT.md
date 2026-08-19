# MyShop production deployment runbook

This repository provides a hardened Docker Compose deployment baseline. It
does not deploy to an environment, manage production secrets, operate a
reverse proxy, or provide an external backup/monitoring service. Use this
runbook with an approved deployment platform and change record.

## 1. Inject deployment configuration

Create a protected environment file outside the repository, or inject the
same variables through the deployment platform. Never commit it, place it in
the image, print it, or pass it to the normal application service unless the
Compose file explicitly requires the value there.

Required values include:

- `APP_ENV=production`.
- The restricted runtime `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and
  `DB_PASSWORD`.
- A separate `DB_SCHEMA_USER` and `DB_SCHEMA_PASSWORD` for controlled schema
  work. These are not application-service credentials.
- `MYSQL_ROOT_PASSWORD` only for the database/deployment boundary.
- Immutable `PHP_BASE_IMAGE`, `PRODUCTION_APP_IMAGE`, and
  `PRODUCTION_MYSQL_IMAGE` references in the form `name@sha256:<64 hex chars>`.
- `HSTS_ENABLED=true` and `HSTS_MAX_AGE` between 31536000 and 63072000.
- `TRUSTED_PROXY_IPS` as a comma-separated list of exact reverse-proxy IPs.

Validate the file without revealing its values:

```text
php scripts/production-preflight.php \
  --env-file /protected/myshop/production.env \
  --compose-file docker-compose.production.yml
```

The preflight rejects missing settings, placeholder or weak credentials,
mutable image tags, invalid HSTS values, invalid proxy addresses, and root or
schema credentials in the normal `app` service. It does not contact a
registry, database, secret manager, or production host.

## 2. Disposable production runtime smoke

Before a deployment change is accepted, run the disposable runtime smoke from
the repository root:

```text
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/run-production-smoke.ps1
```

The runner creates a unique Compose project and fresh named volumes, builds
the current production image, injects generated temporary database
credentials, and publishes only the app to a temporary loopback port. It
verifies `/health.php` and `/ready.php`, stops MySQL to require a generic HTTP
503 from readiness, starts MySQL again to require HTTP 200, and inspects the
runtime for a read-only root, the intended uploads volume, no host database
port, `no-new-privileges`, restricted app environment, no Git executable or
repository metadata, and disabled PHP error display.

The runner performs no business mutations and never uses `.env`, `ioms_db`,
production credentials, GitHub secrets, or persistent deployment volumes. It
removes its image, containers, volumes, network, temporary environment, and
HTTP scratch file in a `finally` cleanup path; cleanup failure is fatal. The
smoke proves only this repository's disposable Docker runtime boundary. It
does not verify external TLS, firewalling, registry promotion or signing,
secret-manager delivery, monitoring, backup storage, migration ownership, or
rollback infrastructure.

## 3. Verify the immutable release and release evidence

Resolve and review the application, PHP base, and MySQL image digests before
the change window. Confirm that the application digest is the reviewed image
containing the intended commit, and retain the previous application digest for
rollback. Do not replace a digest with `latest`, a branch tag, or a mutable CI
tag during deployment.

The repository policy also requires every third-party GitHub Action to use a
full 40-character commit SHA and an inline official release comment. To update
one deliberately, inspect the official tag and commit with `git ls-remote`,
review the release notes, replace the workflow reference with that exact SHA,
and run `php scripts/ci-supply-chain-check.php` plus the complete Quality Gate.
Do not infer or copy an action SHA from an untrusted example.

Validate the resolved Compose model and build the reviewed application image:

```text
docker compose --env-file /protected/myshop/production.env \
  --file docker-compose.production.yml config --quiet
docker compose --env-file /protected/myshop/production.env \
  --file docker-compose.production.yml build --pull app
```

After the reviewed image digest and verification checks are complete, generate
the safe release manifest/check. It records only the commit, workflow/ref,
image digest, migration version/list, and `verified` status; it does not read
or emit credentials, environment files, PII, database data, or backup contents.
The CI job prints this JSON as evidence and does not publish it externally.

```text
RELEASE_IMAGE_REFERENCE=registry.example/myshop@sha256:<reviewed-digest> \
RELEASE_VERIFICATION_STATUS=verified \
RELEASE_COMMIT_SHA=<reviewed-commit> \
RELEASE_WORKFLOW=production-verification \
RELEASE_REF=<reviewed-ref> \
php scripts/release-integrity-check.php
```

Retain the previous application image by its complete digest in the protected
deployment record and keep it available in the registry or deployment cache
until the new release is accepted. A CI build tag is an intermediate label,
not rollback evidence or a deployable release reference.

The production image has no repository bind mount. The application root is
read-only; only the named `production_uploads` volume is writable by the
application. MySQL has no published host port.

## 4. Back up the database before schema changes

Before applying any migration to an existing database:

1. Stop or quiesce application writes according to the deployment procedure.
2. Have an administrator create a backup through the protected Settings flow
   using the required current-password re-authentication.
3. Verify the `-- MYSHOP_BACKUP_COMPLETE` marker and perform isolated restore
   verification before accepting the artifact into retention storage.
4. Store the verified backup outside the repository and document its protected
   storage location, encryption key identifier, operator, and change ID.

Backups contain one-way staff password hashes and are sensitive credentials.
Never place them under `public/`, in a Docker bind mount serving the app, or in
Git. The repository streams the SQL backup but does not encrypt or replicate
it; those are external operations.

## 5. Apply migrations in order

Use the controlled schema/deployment account, never `DB_USER`, and never run
migrations from an HTTP request or application startup. For a database created
from the original Batch 1 schema, apply these files in order:

1. `database/batch2_staff_active.sql`
2. `database/batch3_product_history.sql`
3. `database/batch14_runtime_privileges.sql`
4. `database/batch17_login_rate_limit.sql`
5. `database/batch22_audit_log.sql`

Stop on the first error. Inspect the database and change record before
retrying. Do not import `database/schema.sql` over an existing database. The
repository does not implement automatic migration orchestration or a
transactional rollback for arbitrary DDL.

After migration, run the schema validation against a disposable verification
target where possible. A database restore or rollback must use a controlled
schema account and a verified backup; it must not use a browser-accessible
restore route.

## 6. Start and verify the release

Start the reviewed Compose release only after preflight, image, backup, and
migration checks pass:

```text
docker compose --env-file /protected/myshop/production.env \
  --file docker-compose.production.yml up -d
docker compose --env-file /protected/myshop/production.env \
  --file docker-compose.production.yml ps
```

Verify the database and application health checks, then check readiness
through the configured reverse proxy:

```text
curl --fail --silent --show-error https://shop.example/ready.php
```

`/health.php` is liveness-only and does not require MySQL. `/ready.php` returns
HTTP 200 only after `SELECT 1` succeeds and returns a generic HTTP 503 when the
database is unavailable. Neither endpoint returns SQL details, credentials,
paths, stack traces, or configuration values.

Perform a small authenticated smoke check for login/logout, one permitted
admin or cashier read path, and one expected authorization denial. Do not
perform destructive or business-volume actions as a smoke test. Confirm that
existing uploads are present through the named `production_uploads` volume and
that new validated uploads do not require a writable application root.

## 7. HSTS and reverse proxy requirements

Terminate TLS at the approved reverse proxy or platform edge, preserve the
direct proxy source IP, and forward HTTPS state only from an address in
`TRUSTED_PROXY_IPS`. Configure the exact proxy IPs; never use `*`, arbitrary
client forwarding headers, or a broad unreviewed network range. Enable HSTS
only when HTTPS is guaranteed for the complete hostname policy and the
operator accepts the long-lived browser policy.

The repository does not provision certificates, redirect HTTP to HTTPS,
configure DNS, preserve proxy source addresses, manage firewall rules, or
decide HSTS preload/subdomain policy.

## 8. Rollback and database limitations

For an application-only regression, select the previously reviewed immutable
`PRODUCTION_APP_IMAGE` in the protected deployment configuration and redeploy
the app service:

```text
docker compose --env-file /protected/myshop/production.env \
  --file docker-compose.production.yml up -d app
```

Keep the prior image digest available until the release is accepted. An image
rollback does not roll back database changes. For an incompatible migration,
stop writes, follow the approved restore procedure into a separately named
target database, validate schema and representative data, and perform a
controlled database cutover. Do not delete or overwrite the original target
until the incident owner approves the recovery point.

The repository does not implement automatic database rollback, blue/green
cutover, point-in-time recovery, replication failover, or zero-downtime
orchestration.

## 9. Logging, monitoring, and incident ownership

Collect Apache access logs from stdout and Apache/PHP technical errors from
stderr. Retain and restrict them according to operational policy. Request IDs
are server-generated; passwords, cookies, CSRF tokens, authorization headers,
request bodies, and database credentials must remain out of logs.

Alert on readiness failures, sustained 5xx responses, latency, authentication
failure or rate-limit spikes, authorization denials, database/storage errors,
backup or restore-verification failures, upload-volume exhaustion, and
container/host disk pressure. Assign an on-call owner, escalation path,
retention period, redaction reviewer, backup owner, and recovery decision
owner before accepting production traffic.

The repository does not provide a scheduler, log backend, metrics backend,
alert manager, incident-management integration, secret manager, KMS,
off-site backup replication, registry promotion/signing policy, certificate
authority integration, or external firewall/WAF.

## Readiness conclusion

Passing repository checks means the code and deployment baseline are
production-deployable with the documented external controls. It does not by
itself establish that the application is production-ready: secret injection,
immutable registry verification, TLS/proxy/firewall policy, backup retention
and restore drills, migration ownership, monitoring, alerting, incident
response, and rollback authority must be implemented and verified by the
deployment operator.
