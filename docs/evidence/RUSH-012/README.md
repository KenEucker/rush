# RUSH-012 verification evidence

Verified locally on 2026-10-09. Branch: feat/RUSH-012-availability-proof.
Base: production, RUSH-001–011 merged. Requirement: R-07 (foundational slice).

## Acceptance coverage

| Criterion | Evidence |
| --- | --- |
| Minimal Ranger unavailability editor | Quasar QDate/QTime range entry and editing; explicit device zone, weekdays, AM/PM and overnight dates; desktop/mobile screenshots below |
| Cached reads and atomic offline writes | Typed Dexie repositories and shared pending queue; local success follows command/representation transaction; invalid intervals never stage |
| Offline reload and restart | Production-PWA Playwright disables HTTP cache, writes offline, reloads, closes Chromium and reopens the same persistent profile offline; the range and pending state survive |
| Reconnect and reconcile | Real Sanctum session revalidation resumes the shared coordinator; accepted Server range becomes synced and survives reload |
| Interrupted acceptance | Vitest retains local intent after accepted push/reopen and until ordered pull; injected quota failure proves confirmed data, pending cleanup and checkpoint roll back together |
| Server validation and authority | Pest covers interval validation, ownership/current role, peer/foreign access, revision conflict, idempotent replay, receipt/audit transaction rollback and paginated visibility |
| Conflicting Server update | Real API advances the same range while an edit is offline; reconnection preserves local intent, displays current Server range and conflict, and retains it on reload |
| Storage upgrade and startup | Vitest verifies v2→v3 retention, allowlisted partition metadata, offline directory without secrets, unauthenticated cached startup, known-session-failure behavior and pending-work logout protection |
| UTC and local entry | Unit tests cover overnight conversion, invalid dates and America/Los_Angeles DST gaps/repeated times; CI fixes TZ so DST tests execute |

The conflict here is a competing **unavailability revision**. RUSH-013 still
owns the conflicting official-assignment proof, expanded account-security cases
and conflict recovery. This task does not close the Milestone 2 gate or any of
the nine functional acceptance scenarios. RUSH-014 retains global recovery and
observability; RUSH-018 completes Management notices, acknowledgment and on-behalf
editing. See [ADR 0012](../../decisions/0012-availability-proof.md) and
[the API contract](../../contracts/availability.md).

## Commands and results

- `composer --working-dir=server test -- --compact` with disposable PostgreSQL 17.2 on loopback port 55439 — **passed, 79 tests / 589 assertions**, including concurrency tests; no skips.
- `php server/artisan migrate --force`, `php server/artisan migrate:rollback --force`, then `php server/artisan migrate --force` against that disposable database — **passed**.
- `composer validate --working-dir=server --strict` — **passed**.
- `composer --working-dir=server format:check` — **passed**.
- `npm --prefix client run typecheck` — **passed**.
- `npm --prefix client run lint:check` — **passed**.
- `npm --prefix client run test:unit` with `TZ=America/Los_Angeles` — **passed, 149 tests in 15 files**, no skips.
- `npm --prefix client run build` — **passed**, production SPA.
- `docker compose -p rush-ci -f docker/compose.ci.yaml up -d --build --wait --wait-timeout 120` — **passed**, production PWA/Server images and healthy Caddy/Laravel/PostgreSQL fixture.
- `docker compose -p rush-ci -f docker/compose.ci.yaml exec -T server php artisan migrate:fresh --seed --force` — **passed**, reset only the disposable CI fixture before final browser verification.
- `docker compose -p rush-ci -f docker/compose.ci.yaml exec -T server php artisan cache:clear` — **passed**, disposable fixture rate limits reset.
- `npm --prefix client run test:e2e` — **passed, 18 desktop/mobile tests**, real production stack, no mocked API for availability.
- `npm --prefix client run test:shell` — **passed, 12 desktop/mobile tests**.
- `npm run conventions:test` — **passed, 8 tests**.
- `git diff --check` — **passed**.
- `docker compose -p rush-ci -f docker/compose.ci.yaml down --volumes --remove-orphans` and `docker rm -f rush-012-test-postgres` — **passed**, removed only the disposable test fixtures.

Earlier runs exposed and fixed the new migration's dependency in the existing
rollback test, account metadata copying the directory's `active` key, a Dexie
transaction type overload, and test-harness races with automatic session sync.
The infrastructure sync harness now unloads the application coordinator while
retaining real cookies. The existing guest PWA test restores both transport and
the browser connectivity hint before an online reload. Final reruns above pass.
These are local results, not a claim that GitHub CI has passed.

## Screenshots

Synthetic seeded Ranger data only; screenshots captured from the production PWA
and visually checked for readable range/status text and responsive layout.

- [Desktop: offline restart](desktop-offline-availability.png)
- [Mobile: offline restart](mobile-offline-availability.png)
- [Desktop: retained conflict](desktop-conflict-availability.png)
- [Mobile: retained conflict](mobile-conflict-availability.png)

## Deployment and limits

Run Server migrations before deploying the new Client. Dexie v3 upgrades existing
partitions without clearing pending work. Back up before deployment; restore a
matching application/database version for rollback instead of destructively
dropping accepted availability or receipts. No new production dependency.

The proof labels the device time zone; season calendar policy remains RUSH-015.
Browser restart is automated in Chromium, not native OS installation or physical
iOS Safari. Human review, required CI checks and merge remain required.
