# Browser E2E and accessibility QA

The browser suite is a separate QA layer for the critical MyShop journeys. It
must run only against the disposable environment created by the runner below.
It does not use the repository `.env`, the normal `ioms_db` database, production
credentials, or production data.

## Prerequisites

- Docker Desktop with `docker compose` available.
- Node.js 20 or newer and npm.
- PowerShell 7 or Windows PowerShell.

The browser dependencies are pinned in `e2e/package.json` and
`e2e/package-lock.json`: Playwright Test `1.62.1` and axe-core `4.13.0`. The
runner installs exactly the lockfile dependencies and the matching Playwright
Chromium browser. The Compose override uses the reviewed immutable MySQL image
`mysql:8.4.3@sha256:106d5197fd8e4892980469ad42eb20f7a336bd81509aae4ee175d852f5cc4565`
and the reviewed immutable PHP base image digest.

## Run locally

From the repository root:

```powershell
pwsh -File scripts/run-browser-qa.ps1
```

Windows PowerShell users can run the same file with
`powershell -NoProfile -File scripts/run-browser-qa.ps1`.

The runner chooses free loopback ports, creates a unique Compose project and
database name, builds the development image with the pinned PHP base, starts
MySQL and the application, and waits for `/health.php`. It then runs the
CLI-only bootstrap and seed commands:

```text
docker compose ... run --rm bootstrap
docker compose ... run --rm --no-deps --env-from-file seed.env app php scripts/browser-qa-seed.php
```

Those commands receive generated temporary values only. The normal application
service receives only its restricted runtime database account; the MySQL root
password and bootstrap values are not passed to the normal app service. The
repository bind mount remains read-only and no browser test submits a
state-changing application form.

Cleanup is unconditional:

```text
docker compose ... down --rmi local --volumes --remove-orphans
```

The runner removes its temporary environment files and Playwright output after
the run. The generated admin and cashier accounts therefore disappear with the
disposable database. A cleanup failure makes the run fail; it is not ignored.

## GitHub Actions

The `Quality Gate` workflow runs the Browser QA job on every push to `main` or
`security-hardening-baseline` and on every pull request. It uses a clean
GitHub-hosted Ubuntu runner, the pinned Node.js 20.19.5 runtime, the locked
Playwright Test 1.62.1 and axe-core 4.13.0 dependencies, and Chromium with its
Linux prerequisites. The job invokes this same PowerShell runner used locally;
it never reads repository `.env` files, GitHub secrets, production data, or the
normal development database.

The job has a 20-minute timeout. The runner's unconditional cleanup is backed
by an `always()` workflow cleanup step that removes only resources with the
`myshop-browser-qa-` prefix. A failed test or cleanup makes the job fail.

## Coverage

The suite covers login/logout and invalid login, unauthenticated redirects,
admin pages and CSV export access, cashier sales/history access and direct
administrative/purchase denial, keyboard smoke paths, automated axe checks,
console and same-origin network failures, and horizontal-overflow checks at
375px, 768px, and 1440px. It uses semantic roles, labels, accessible names,
and stable IDs already present in the application.

Screenshots are written to the temporary run directory with table cells,
inputs, selects, text areas, and KPI values masked. They are deleted during
cleanup and are never committed. No committed visual baselines currently
exist, so visual comparison is reported as **INCONCLUSIVE** rather than passed.

The axe result is an automated signal, not a full manual WCAG audit. Critical
findings fail the run. Serious and lower-impact findings are printed and
attached as explicit test annotations for remediation review; they are not
silently treated as compliance. Summaries contain only rule/target
information, never page HTML or data values. Third-party
asset request noise is limited to the documented CDN/font hosts in the test;
same-origin 4xx/5xx responses and failures always fail the run.

If Docker, Chromium, or the disposable app cannot start, the result is
**INCONCLUSIVE**. Do not report a browser QA pass in that situation.
