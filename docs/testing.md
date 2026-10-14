# Testing RUSH

## Configuration integration (RUSH-021)

After building, migrating and seeding the production-PWA fixture below (including
`php tests/Fixtures/seed-management-years.php` inside its Server container), run
`npm run client:test:configuration` from the repository root. This runs the
Milestone 3 journey and the season, coverage, policy, availability, preference,
event and management-year regression suites on desktop and mobile.

`configuration.spec.ts` creates one season through Orchid with limited shared
coverage and three contiguous eight-hour dedicated shifts for a 24/7 phase,
distinct weekly targets, and one paid Monday Meeting. It rejects invalid policy
inputs and Ranger access to configuration, then saves Ranger unavailability and
weekly preferences into the same offline queue across reloads. After reconnect,
Management acknowledges the report and the meeting excludes the unavailable
attendee from staffing without removing attendance or changing area coverage.
The weekly preference inherits default hours and returns to defaults next week.
Screenshots are attached to the Playwright report.

The test uses an upcoming season so year-archive rules remain active. Configuration
is performed through actual UI controls; API reads verify independent records
and denied API writes verify authorization. No operational data or network
responses are mocked. Existing focused suites retain revision-conflict, audit,
time-zone and multi-device coverage. The full `npm run client:test:e2e` also
includes the mandatory Milestone 2 production-PWA offline/conflict regression gate.
See [RUSH-021 evidence](evidence/RUSH-021/README.md) for executed results and the
R-01–R-11 evidence map; R-04/R-05 generation and gap behavior retain their later owners.

## Preference profiles (RUSH-019)

Run Server `php vendor/bin/pest --filter=Preference --compact` against disposable
PostgreSQL for inherited weekly fields, default resolution, calendar boundaries,
validation, privacy, idempotency, audit rollback and simultaneous profile creation.
Client `npm run test:unit` covers Dexie v4 migration, reactive form data, atomic
queue writes/replacement, interruptions, conflicts and protocol validation.
Run `npx playwright test --config playwright.integration.config.ts preferences.spec.ts`
from `client/` against the production fixture below. It verifies desktop/mobile
defaults, preferred shifts, inherited weekly fields, offline reload/reconnect,
next-week fallback and two-session conflict recovery. Screenshots are saved in
`docs/evidence/RUSH-019/`; see [the evidence](evidence/RUSH-019/README.md).

## Unavailability review (RUSH-018)

`php vendor/bin/pest tests/Feature/ManageUnavailabilityTest.php
tests/Feature/UnavailabilityConcurrencyTest.php --compact` from `server/`
covers scoped Management creation/editing, revision-specific acknowledgment,
audit rollback and independent PostgreSQL writers. Existing `UnavailabilityTest`
and `AssignmentConflictTest` cover Ranger sync and overlap clearing. The
concurrency cases require a disposable PostgreSQL database and skip on SQLite.

Client `npm run test:unit` covers acknowledgment/coverage projection persistence
at unchanged revisions, reopen and subsequent edit resets. Against the production
fixture below, `npx playwright test --config playwright.integration.config.ts
unavailability-management.spec.ts` verifies desktop/mobile Management forms,
offline range entry/reload, acknowledged snapshots offline, stale input and a
competing Management edit while Ranger intent remains queued. It writes the
RUSH-018 screenshots. The full E2E suite retains the offline browser-restart and
official-assignment conflict gate. See [evidence](evidence/RUSH-018/README.md).

## Seasons and calendar policy (RUSH-015)

`composer --working-dir=server test -- --filter=Season --compact` covers scoped
season/phase editing, validation, audit/rollback, stale writes, Orchid access,
overnight allocation, local week/year edges and DST gaps/folds. PostgreSQL adds
SeasonConcurrencyTest's simultaneous saves by different managers; SQLite skips
that case. `npm --prefix client run test:e2e -- seasons.spec.ts` exercises the
Orchid form on desktop/mobile with real sessions and preserves stale input.
Its screenshots are written to `docs/evidence/RUSH-015/`. Run against the same
disposable seeded stack described below; no new dependencies are required.

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

### Live Docker development (RUSH-002 follow-up)

CI's separate Development job runs `npm run docker:dev:test` against a seeded
`rush-dev-test` project using `compose.yaml` plus `compose.dev.yaml`. It verifies
PHP and Blade edits, Vue HMR over Caddy's WebSocket, same-origin login/Orchid/assets,
and development service-worker retirement without clearing IndexedDB. Container
IDs and restart counts must remain unchanged across the edits. The script creates
uniquely named temporary probe files and removes them in `finally`; it never edits
tracked application files. Run it against a disposable stack before enabling
development mode on another stack that mounts the same checkout.

To reproduce locally in PowerShell (Docker Compose 2.24.4+), use a fresh terminal
and set disposable environment values before starting the fixture:

```powershell
$env:APP_KEY = 'base64:4JgDPkNG6PpRLCpJvf4n3uGRrjkk+ySOGFbqVDJcX8k='
$env:APP_URL = 'http://localhost:9188'
$env:RUSH_SITE_ADDRESS = 'http://:80'
$env:RUSH_HTTP_PORT = '9188'
$env:RUSH_HTTPS_PORT = '9448'
$env:SESSION_SECURE_COOKIE = 'false'
$env:POSTGRES_DB = 'rush_dev_test'
$env:POSTGRES_USER = 'rush_dev_test'
$env:POSTGRES_PASSWORD = 'disposable-dev-only'
docker compose -p rush-dev-test -f compose.yaml -f compose.dev.yaml up -d --build --wait --wait-timeout 180
docker compose -p rush-dev-test -f compose.yaml -f compose.dev.yaml exec -T --user www-data server php artisan migrate --seed --force
npm run docker:dev:test
docker compose -p rush-dev-test -f compose.yaml -f compose.dev.yaml down --volumes
```

Always clean up the disposable project, including after a failed check, and close
that terminal before working with the normal stack so test environment overrides
do not carry over. On Unix, export the same variables. The test refuses project
names outside `rush-dev-test` / `rush-dev-test-*` and non-loopback browser origins.
For parallel fixtures override `RUSH_DEV_TEST_PROJECT` and `RUSH_DEV_TEST_URL` to
match the chosen project/ports. This is a development smoke test, not offline PWA
acceptance; continue using the production fixture below for offline evidence.

Local verification (2026-10-13): all development smoke checks passed, including
preserved IndexedDB and unchanged PHP/Client container IDs and restart counts.
Client lint, type checking and the production PWA build passed; root convention
tests passed (8). Both Compose configurations validated. After enabling the
normal local development stack, an HTTPS browser smoke check passed for sign-in,
Laravel `/up` and the WSS HMR connection; the PostgreSQL container ID was preserved.

Development CI regression (2026-10-13, PR #19): run `38449136859` failed while
reading worker registrations because navigation destroyed the evaluation context.
The signed-in `/sign-in` page could redirect independently of the worker, and
`framenavigated` did not guarantee the replacement document had loaded. The smoke
test now uses a same-origin probe document, writes IndexedDB before activation,
and triggers registration through a button while awaiting the exact document's
completed reload. It asserts a second document load, worker unregistration and
preserved IndexedDB without catching or suppressing browser errors. The actual
development worker, Caddy routing and application behavior are unchanged.
Verification: `npm run docker:dev:test` passed three consecutive runs against an
isolated development stack; `node --check scripts/test-docker-development.mjs`
passed and `npm run conventions:test` passed all eight tests. The unchanged test
also passed one local comparison run; the original failure was observed in CI.

### Production-built PWA fixture

The standalone fixture below never uses `compose.yaml` or its production volumes.
It builds the production PWA and Laravel images, serves Caddy on loopback port
9187, and creates temporary PostgreSQL storage. Do not reuse it for real data.
Run from the repository root:

```sh
docker compose -p rush-ci -f docker/compose.ci.yaml up -d --build --wait --wait-timeout 120
docker compose -p rush-ci -f docker/compose.ci.yaml exec -T server php artisan migrate --force
docker compose -p rush-ci -f docker/compose.ci.yaml exec -T server php artisan db:seed --force
docker compose -p rush-ci -f docker/compose.ci.yaml exec -T server php tests/Fixtures/seed-management-years.php
npm run client:test:e2e
docker compose -p rush-ci -f docker/compose.ci.yaml down --volumes --remove-orphans
```

Always run the final cleanup, even if a command fails. To rerun tests immediately
against the same disposable fixture, clear its login counters with `docker compose
-p rush-ci -f docker/compose.ci.yaml exec -T server php artisan cache:clear`.
The real limiter remains enabled during each test run.

Before reporting a PR verified, run the full browser suite against a fresh fixture
after the final code change, then check all GitHub CI jobs on the pushed commit.
Focused reruns help diagnose failures but do not replace a green full run. Rebuild
the fixture after application changes; recreate its temporary database before
repeating the full suite because tests create durable Server records.

Offline write/reload tests must observe the saved pending record before reloading,
then assert the same record and content after reload. A completed Playwright click
does not await an asynchronous Dexie transaction. Keep retries disabled and wait
on visible application state rather than adding fixed delays. When navigation or
headings change, run all affected browser suites and scope locators to the intended
content or navigation region so duplicated menu links remain unambiguous.

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

## Milestone 2 regression gate (RUSH-014)

Run the full Client unit suite and production-stack E2E command above. The new
`e2e/integration/sync-observability.spec.ts` exercises lost accepted receipts,
duplicate operation replay, interruption before pull completes, reload, manual
recovery, two independent device stores, and explicit conflict discard. It uses
real Laravel/PostgreSQL mutations and only intercepts transport failures.
Desktop/mobile screenshots are saved to `docs/evidence/RUSH-014/` using seeded
fixture accounts. Clear fixture rate-limit counters before rerunning a suite.

The gate also includes `availability.spec.ts` (offline browser restart and
competing availability revision), `conflict-security.spec.ts` (real Management
assignment changes, logout/account switching and expired sessions), `sync.spec.ts`
(native Web Locks/multiple tabs), `pwa.spec.ts` (shell/cache isolation) and
`auth.spec.ts` (real sessions and authorization). Pest's PostgreSQL-only concurrent
duplicate/competing-writer cases remain mandatory CI evidence; SQLite skips them.
Do not claim later scheduling or nine-scenario release acceptance from this gate.
