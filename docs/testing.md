# Testing RUSH

RUSH-007 implements Technical §13 and cross-cutting regression support for
R-01–R-38. CI is defined in `.github/workflows/ci.yml`; the fixture and scope are
documented in [ADR 0007](decisions/0007-ci-and-e2e-foundation.md).

## Clean checkout

Use Node 24.16.0, PHP 8.5 with the extensions listed in CI, Composer 2, Git and
Docker Compose. Install locked dependencies from the repository root:

```sh
cp server/.env.example server/.env
composer install --working-dir=server --no-interaction --prefer-dist
npm ci --prefix client
npm ci --prefix client/src-pwa
npx --prefix client playwright install chromium
```

On Linux, install browser OS dependencies with
`npx --prefix client playwright install --with-deps chromium`.
The Server test environment is initialized from the committed example; no secret
values or pre-existing database are needed. CI supplies its disposable key and
PostgreSQL settings through environment variables. Preserve an existing local
`.env` when working outside a clean checkout.

```sh
npm run conventions:test
composer validate --working-dir=server --strict
npm run server:format
npm run server:test
npm run client:lint
npm run client:typecheck
npm run client:test
npm run client:build
npm run client:build:pwa
npm --prefix client run test:shell
```

Pest defaults to in-memory SQLite. CI overrides `DB_CONNECTION`, `DB_HOST`,
`DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` and clears `DB_URL` to run
against PostgreSQL 17.2. Set these only to a disposable test database: Pest resets
its target schema. Against that database, CI also runs `php server/artisan migrate
--force`, `migrate:rollback --force`, and `migrate --force` before Pest. It stores
JUnit output with `composer --working-dir=server test -- --compact --log-junit
storage/logs/pest.xml`.

## Full-stack browser tests

The standalone fixture below never uses `compose.yaml` or its production volumes.
It builds the production PWA and Laravel images, serves Caddy on loopback port
9187, and creates temporary PostgreSQL storage. Do not reuse it for real data.
Run from the repository root:

```sh
docker compose -p rush-ci -f docker/compose.ci.yaml up -d --build --wait --wait-timeout 120
docker compose -p rush-ci -f docker/compose.ci.yaml exec -T server php artisan migrate --force
docker compose -p rush-ci -f docker/compose.ci.yaml exec -T server php artisan db:seed --force
npm run client:test:e2e
docker compose -p rush-ci -f docker/compose.ci.yaml down --volumes --remove-orphans
```

Always run the final cleanup, even if a command fails. To rerun tests immediately
against the same disposable fixture, clear its login counters with `docker compose
-p rush-ci -f docker/compose.ci.yaml exec -T server php artisan cache:clear`.
The real limiter remains enabled during each test run.

The suite asserts real Ranger/Management login, authorization differences,
same-origin Orchid and its stylesheet, CSRF rejection, private profile access,
owner-only writes, sign-out and account switching on desktop/mobile Chromium.
It complements `test:shell`, which uses the built SPA and mocked session responses.
Offline reconciliation and installed-PWA proof remain Milestone 2 work.

## Dexie foundation (RUSH-008)

`npm --prefix client run test:unit` includes the database and account repository
suites under `client/src/data/`. They use Dexie with `fake-indexeddb` to verify
reload persistence, account/organization isolation, atomic command/projection
writes, concurrent connections, retention guards, metadata/checkpoints, migration
success/rollback, blocked upgrades and storage restrictions/quota/read failures.
These tests do not satisfy the production-built PWA offline reconciliation gate;
RUSH-011–014 retain that acceptance scope. See
[ADR 0008](decisions/0008-dexie-storage-foundation.md) and
[task evidence](evidence/RUSH-008/README.md).

## Sync protocol (RUSH-009)

`server/tests/Feature/SyncProtocolTest.php` covers validation, replay, authorization,
conflicts, receipt/journal rollback, bounded pagination, tombstones and invalid
checkpoints. `SyncConcurrencyTest.php` requires a disposable PostgreSQL database;
SQLite explicitly skips its two row-lock cases. Two independent PHP processes
must both reach the membership lock before release, proving duplicate requests
have one effect and competing operations cannot accept the same revision. Never
run concurrent test suites against the same disposable database.

Vitest adds `data/sync/protocol.test.ts` and `data/api/sync.test.ts` for operation
IDs across Dexie reopen, failed local saves, shared wire examples, malformed
responses, account scope, interruption and transport errors. The existing full-stack
`e2e/integration/auth.spec.ts` also exercises real sync CSRF/authorization, replay
and checkpointed pull on desktop/mobile. This is protocol evidence, not the later
offline availability/reconciliation acceptance proof. See
[RUSH-009 evidence](evidence/RUSH-009/README.md).

## PR and commit checks

Fill every section of `.github/pull_request_template.md`. Use a Conventional Commit
PR title (for example `ci(foundation): add RUSH-007 CI and E2E checks`). Task commits
must follow Implementation Plan §2, including rationale and `Refs:`,
`Requirements:` and `Tests:` footers. Infrastructure work can identify R-01–R-38
as cross-cutting support and name its technical sections without claiming those
functional requirements complete.

```sh
node scripts/lint-conventions.mjs --commit-file commit-message.txt
node scripts/lint-conventions.mjs --range origin/production HEAD
```

CI reads PR metadata from the event JSON and validates non-merge task commits.
The validator tests include malformed metadata, missing sections/footers,
breaking changes, historical-commit exclusion and safe handling of literal shell
syntax. Human review must verify the quality and truthfulness of the content.

## CI diagnostics and merge gate

Require these four status checks on the target branch:

- Git conventions
- Server (Pest, PostgreSQL, migrations, Pint)
- Client (Vitest, lint, types, SPA/PWA, shell)
- E2E (Caddy, Laravel, PostgreSQL, built PWA)

GitHub Actions uploads `server-results`, `shell-results` and `integration-results`
for seven days, including Playwright HTML reports and failure traces/screenshots,
Pest JUnit/logs, and Compose status/logs. Locally, reports are under
`client/playwright-report/` and `client/test-results/`; use
`npx --prefix client playwright show-report client/playwright-report/integration`.
Never commit reports containing session cookies. There are no automatic retries;
fix the cause of a failing check. Branch protection and human review remain
repository-admin responsibilities, and no workflow deploys or merges code.

## Central coordinator (RUSH-010)

The Client unit suite includes data/sync/coordinator.test.ts and
data/repositories/syncRepository.test.ts. They execute durable queue/reopen,
backoff/timer/timeout, terminal rejection, authentication pause, pagination/reset,
tombstone/revision, multiple-connection and transaction rollback cases.
database.test.ts now also proves the actual v1-to-v2 migration and rollback.

The existing full-stack test command additionally runs e2e/integration/sync.spec.ts
on desktop/mobile. It bundles the production coordinator into a test-only in-memory
harness, injects it into the real site origin, and exercises native Web Locks,
IndexedDB document reload, accepted Server writes and conflicting revisions.
The harness never becomes a production asset. This is coordinator integration,
not the later offline-shell/availability/official-assignment acceptance gate.
See [ADR 0010](decisions/0010-central-sync-coordinator.md) and
[RUSH-010 evidence](evidence/RUSH-010/README.md).
