# RUSH-006 verification

Executed 2026-10-09 from `feat/RUSH-006-domain-contract-conventions`, based on
`production` after merged RUSH-001–005. Covers Technical §§5 and 14, with
cross-cutting convention support for R-01–R-38. No functional acceptance scenario
is claimed complete; the nine scenario owners and Milestone 2 offline gate remain.

## Results

| Command | Result |
| --- | --- |
| `composer --working-dir=server test -- --compact` | 47 passed, 385 assertions (SQLite) |
| Same command with isolated PostgreSQL environment below | 47 passed, 385 assertions |
| `composer --working-dir=server format:check` | Pint passed |
| `npm --prefix client run test:unit` | 32 passed |
| `npm --prefix client run typecheck` | Passed |
| `npm --prefix client run lint:check` | Prettier and ESLint passed |
| `npm --prefix client run build` | Production SPA build passed |
| `npm --prefix client run build:pwa` | Production PWA and Workbox build passed |
| `npm --prefix client run test:shell -- --workers=2` | 12 passed, desktop/mobile Chromium |
| `git diff --check` | Passed |

The first PostgreSQL run found that the test's intentional restrictive-FK violation
aborted its surrounding test transaction. Wrapping that assertion in a nested
transaction/savepoint fixed the test isolation; the full suite then passed on both
engines. An initial formatting command used the repository root for Client-relative
paths; it was corrected to run from `client`, and formatting/lint passed afterward.

## Acceptance evidence

- ADR 0006 documents UUID/organization/UTC conventions, record revisions versus
  checkpoints, explicit domain state transitions, API/Client boundaries, shared
  validation, stable error envelopes, transactional audit, and roadmap boundaries.
- `ProfileContractTest.php` and `profiles.test.ts` consume the same maintained JSON
  example. The Server asserts exact serialization; the Client checks typed requests
  and runtime decoding. Malformed/wrong-account responses cannot become valid profiles.
- Profile GET/PATCH preserve existing owner/Management visibility and owner-only
  writes. Existing authorization tests cover guests, peers, other organizations,
  forged nested IDs, revoked membership, and calls outside controllers.
- Successful writes increment once and preserve actor, organization, before/after
  values and UTC audit time. Unknown privilege/secret fields never enter the audit.
- Stale second-writer intent and replay return 409 without altering the accepted
  result. No-op revisions, omitted/null phone values, field validation, and malformed
  revision bounds are tested. This covers stale-writer behavior, not a multi-process
  load/concurrency benchmark or the future idempotent sync protocol.
- Forced audit insertion failure rolls back profile data and revision. Composite
  organization keys and restrictive deletion protect history. Upgrade/down/up tests
  preserve existing profiles and initialize revision 1 on SQLite and PostgreSQL.
- Errors preserve field messages and Retry-After, while removing private exception
  details even with debug enabled. Client network/proxy failures do not claim success
  and mutation conflicts are not silently retried or substituted into user input.
- Existing session, CSRF, browser navigation and mobile shell regressions pass.

## PostgreSQL reproduction

Use a disposable database, never an existing application database: Pest's
RefreshDatabase resets its target schema. The run used PostgreSQL 17.2 on a
temporary Docker container, with no application volume and only a random loopback
port. The normal running RUSH Compose stack was not altered.

```powershell
docker run -d --name rush-006-postgres-test -e POSTGRES_HOST_AUTH_METHOD=trust -e POSTGRES_DB=rush_006_test -p 127.0.0.1::5432 postgres:17.2-alpine3.21
docker port rush-006-postgres-test 5432
docker exec rush-006-postgres-test pg_isready -U postgres -d rush_006_test
# In a fresh shell, use the loopback port returned above (50491 for this run).
$env:DB_CONNECTION='pgsql'
$env:DB_HOST='127.0.0.1'
$env:DB_PORT='50491'
$env:DB_DATABASE='rush_006_test'
$env:DB_USERNAME='postgres'
$env:DB_PASSWORD=''
$env:DB_URL=''
composer --working-dir=server test -- --compact
docker rm -f -v rush-006-postgres-test
```

The local PHP runtime includes pdo_pgsql and pdo_sqlite. Test credentials and trust
authentication are confined to this disposable loopback fixture.

## Deployment and limits

Apply the new migration before deploying revised callers. PATCH now requires
`expected_revision`; GET adds revision, organization and UTC timestamp. Schema
rollback drops newly captured history: back up first and prefer forward correction.
Audit retention/redaction policy remains with RUSH-048.

No material UI change, new dependencies, sync storage or offline workflow is added;
screenshots are not applicable. Browser tests mock sessions and verify the existing
built shell. This does not claim full-stack browser profile editing, production PWA
offline reconciliation, or the RUSH-007 CI foundation. Human review and merge remain
required by the task definition of done.
