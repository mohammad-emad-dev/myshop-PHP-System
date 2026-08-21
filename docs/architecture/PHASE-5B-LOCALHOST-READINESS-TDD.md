# Phase 5B: Localhost Readiness

## Scope and operating model

MyShop is a localhost-first application intended to run on a developer or
business computer. It is not intended to be exposed directly to the public internet
or to a LAN by default. This phase makes the local install, local
health contract, local recovery path, and local operator documentation
explicit without adding cloud deployment controls.

Cloud TLS, WAFs, secret managers, external monitoring, public firewall policy,
off-site backup storage, and internet-facing deployment are intentionally out
of scope. Local operators are responsible for protecting the host machine,
local credentials, and local backup files.

The localhost operating model keeps cloud controls intentionally out of scope.

## Evidence-first audit

### Evidence checked

- `Dockerfile`, `docker-compose.yml`, and `docker-compose.production.yml`.
- `.env.example`, `.gitignore`, and `.dockerignore`.
- `config/db.php`, `public/health.php`, `public/ready.php`, and
  `includes/security.php`.
- `includes/backup.php`, `public/backup_database.php`, and the disposable
  `tests/Integration/backup_restore_test.php`.
- `database/schema.sql`, migration files, runtime privilege initialization,
  and the MySQL foreign-key definitions.
- `scripts/run-production-smoke.ps1`, `scripts/run-browser-qa.ps1`,
  `scripts/production-preflight.php`, repository security and supply-chain
  checks, and the Quality Gate workflow.
- `README.md`, this runbook, and the existing operational, deployment,
  health/readiness, and recovery tests.

### Findings

| Classification | Finding | Evidence and disposition |
| --- | --- | --- |
| Blocker | None found for the documented localhost scope. | Docker app and optional host DB ports bind to loopback; sensitive routes retain authentication/CSRF/authorization. |
| High priority | The canonical local setup and XAMPP alternative were not explicit in the current operational docs. | Added one Docker clean-checkout path, a conservative XAMPP path, port-conflict guidance, and local `.env` handling. |
| High priority | Restart/readiness and local database recovery instructions were mixed with cloud-oriented production language. | Reframed the historical runbook filename as a localhost operations runbook and documented 503-to-200 recovery, persistent uploads/database storage, backup markers, and disposable restore. |
| Medium priority | The existing disposable production-stage smoke is easy to mistake for a hosting requirement. | Reclassified it as optional local image-isolation verification; existing isolation contracts remain unchanged. |
| Accepted risk | Local operators protect the computer, `.env`, and backup files. | No cloud secret manager, external monitoring, TLS, WAF, or off-site backup is being added by design. |
| Evidence missing | A real operator's XAMPP installation, host firewall state, disk failure, and physical backup retention were not available in repository-local evidence. | Documented as operator checks, not treated as application blockers. |

## Verified local contracts

### Installation and exposure

- `.env.example` remains a safe template; `.env` and local dump formats remain
  ignored by Git.
- The canonical Docker flow validates Compose configuration before startup,
  creates the database from the canonical schema on a fresh volume, and uses a
  persistent `mysql_data` volume.
- The app binds to `127.0.0.1:${APP_PORT:-8080}` and the optional host MySQL
  port binds to `127.0.0.1:${MYSQL_PORT:-3307}`. The app connects internally to
  `db:3306`.
- The application source is mounted read-only in the development container;
  validated uploads use `public/uploads/`. The production-stage disposable
  check uses a named upload volume and a read-only application root.
- XAMPP guidance keeps Apache's document root at `public/` and uses loopback
  database values without assuming that a manually managed XAMPP process loads
  the repository `.env`.

### Restart and recovery

- The restart and recovery contract keeps the app available for liveness while
  readiness waits for MySQL.

- `/health.php` is liveness-only and returns a generic 200 without MySQL.
- `/ready.php` runs `SELECT 1` and returns generic 503 while MySQL is stopped,
  then 200 after the connection recovers.
- Readiness failures do not disclose SQL errors, credentials, filesystem paths,
  or stack traces.
- Compose `depends_on` waits for the database health check, and local restart
  guidance uses `up -d`, `restart`, and bounded logs without printing secrets.

### Local backup and restore

- Backup downloads remain administrator-only, POST-only, CSRF-protected, and
  require current-password reauthentication.
- `-- MYSHOP_BACKUP_COMPLETE` is emitted only after the consistent snapshot and
  transaction complete. The integration test restores the dump into a uniquely
  named disposable database and verifies tables, relationships, data, and
  runtime privileges.
- There is no browser restore endpoint. A corrupted or replaced local database
  is recovered by preserving the original, restoring into a disposable target,
  validating it, and replacing the active target only after operator approval.

The local backup and restore contract is verified separately from the web
health contract.

### Security boundaries preserved

- Authentication, CSRF, administrator authorization, upload validation, login
  rate limiting, security headers, and transactional business behavior were not
  changed in this phase.
- Request IDs are server-generated and logs exclude passwords, cookies,
  authorization headers, CSRF tokens, request bodies, database credentials,
  and sensitive customer data.
- No `.env`, dump, secret, generated artifact, or external service integration
  was added.

The local port conflicts contract is handled by selecting another unused
loopback port while retaining the `127.0.0.1` binding.

## TDD evidence

The Phase 5B RED source-contract test was added before the documentation
changes and failed because this evidence document did not exist:

```text
docker compose --env-file .env run --rm --no-deps app php -r "require 'tests/Unit/localhost_readiness_test.php'; run_localhost_readiness_unit_tests();"
FAIL: Localhost readiness fixture could not be read.
```

The GREEN checkpoint is the first commit that makes the new source contracts,
runbook, and current README pass. The final documentation checkpoint records
the complete disposable verification results below.

## Verification record

The following checks are required for this phase and must be recorded with
actual results before closure:

| Check | Result | Evidence |
| --- | --- | --- |
| Focused localhost source contracts | Pending at document creation | `tests/Unit/localhost_readiness_test.php` |
| Full disposable regression | Pending at document creation | `docker compose ... app php tests/run.php` |
| Backup/restore disposable test | Pending at document creation | `tests/Integration/backup_restore_test.php` |
| Local stop/start readiness recovery | Pending at document creation | disposable runtime smoke and local restart check |
| PHP lint | Pending at document creation | all tracked PHP files |
| JavaScript syntax | Pending at document creation | all tracked JavaScript files |
| Docker Compose validation | Pending at document creation | development and optional production-stage models |
| Repository security and supply-chain scans | Pending at document creation | dependency-free repository checks |
| Browser QA | Pending at document creation | 375px, 768px, and 1440px |
| `git diff --check` and artifact review | Pending at document creation | final tracked diff and status |

## Local readiness assessment

The target is a strong localhost baseline when all verification rows pass. A
failure in local startup, loopback binding, health/readiness recovery, backup
completeness, disposable restore, or restart persistence is a local blocker.
Cloud deployment controls remain intentionally unavailable and are not counted
as localhost blockers.
