# Phase 5A — Operational Baseline and Deployment Readiness

This document records a repository-local operational audit and the focused
follow-up changes. It does not certify an external production environment.

## Production readiness score

**68/100 — risky.** The repository has strong deployment boundaries and
disposable verification, but launch remains dependent on external controls that
are not represented in this checkout: secret-manager delivery, TLS/reverse
proxy/firewall configuration, durable monitoring and alerting, protected backup
storage, a real restore drill, branch protection, and an approved incident
owner. The score is intentionally capped below launchable because those
high-impact rollback and recovery controls are not locally verifiable.

## Audit classification

### Blocker

- No repository-local code blocker was found after the focused fixes. A real
  production launch is blocked until the external secret, TLS, monitoring,
  backup/restore, and ownership controls listed under Evidence missing are
  verified by the deployment operator.

### High priority

- Deployment instructions previously mixed the protected immutable deployment
  environment with the temporary image-build workflow. The runbook now requires
  digest resolution before deployment and a deploy-only protected environment.
- A concise response path is now documented for Database outage, Failed
  application deployment, and Restore incident scenarios.
- The example application image is now a fail-closed sentinel instead of a
  mutable deployable-looking tag.

### Medium priority

- The production container still uses the conventional Apache root supervisor;
  worker privilege reduction is provided by Apache, but a fully non-root
  container entrypoint and explicit CPU/memory limits are not established.
- Release metadata is emitted as CI evidence but is not uploaded to a durable
  release-artifact store by this repository.
- No point-in-time recovery, replication failover, automatic DDL rollback, or
  zero-downtime orchestration is implemented.

### Accepted risk

- No cloud-provider deployment, monitoring vendor, secret manager, registry
  promotion, external WAF, or certificate automation was added without a
  confirmed target infrastructure.
- Existing migration behavior remains explicit and fail-stop. Batch 3 DDL can
  commit individual statements; the documented backup and separately named
  restore target are the recovery path.
- The liveness endpoint remains database-independent, while readiness proves a
  database `SELECT 1`; this split is intentional.

### Evidence missing

- Production host/platform, reverse-proxy source IPs, TLS policy, firewall/WAF,
  registry and signing policy, secret-manager integration, branch protection,
  durable log/metric/alert retention, backup encryption/replication, restore
  drill results, RPO/RTO, and named incident/recovery owners.

## Evidence checked

- `Dockerfile`, both Compose files, `.dockerignore`, `.env.example`, PHP
  production settings, Apache configuration, and document-root restrictions.
- `config/db.php`, `public/health.php`, `public/ready.php`, request correlation
  and error logging in `includes/security.php`, and the backup endpoint/service.
- Canonical schema, migration order, runtime privilege restriction, schema
  validation, backup/restore integration tests, and operational HTTP tests.
- Production preflight, release-integrity, security, supply-chain, browser-QA,
  and disposable production-smoke scripts.
- GitHub Quality Gate workflow and the current production deployment runbook.

## Concrete follow-up and TDD evidence

The RED contract in `tests/Unit/operational_baseline_test.php` verifies the
fail-closed image template, digest-first release instructions, incident
runbook headings, and the audit classification. It failed before the
Phase 5A document and documentation/configuration corrections existed.

The GREEN checkpoint and final verification are recorded in the commit history
and in the final delivery report. No database schema, application business
behavior, authentication boundary, compatibility wrapper, or third-party
integration was changed.

## Exact rollback/recovery status

- Application-only rollback: documented and executable by selecting the
  previously reviewed immutable application digest and redeploying the app.
- Database rollback: not automatic. Recovery requires quiescing writes,
  restoring a verified backup into a separately named target, validating it,
  and performing an approved cutover.
- Production proof: not available in this repository; the operator must attach
  the image digest, backup marker, restore-drill evidence, change ID, and
  recovery-owner approval to the deployment record.
