# RUSH-014 verification evidence

Task: RUSH-014. Requirements: Technical §§7, 13; cross-cutting R-01–R-38,
including regression of the R-07/R-08 offline availability foundation.
RUSH-001–013 are merged prerequisites on production. No functional scenario
ownership changes; review, required CI and merge remain completion gates.

## Local checks

- `npm --prefix client run test:unit`: **164 passed**.
- `npm --prefix client run typecheck` and `lint:check`: **passed**.
- `npm run conventions:test`: **8 passed**.
- `npm run client:build`: **passed**.
- `npm --prefix client run build:pwa`: **passed**, including Workbox build.
- `npm --prefix client run test:shell`: **12 passed**, desktop/mobile.
- `npm run server:test`: **84 passed, 618 assertions, 2 skipped** on SQLite.
  The skipped cases require PostgreSQL row locks and run in CI.
- `npm run server:format`: **passed**.

Initial development checks exposed a test expecting the raw error instead of the
storage layer's wrapped error, and an incorrect test fixture membership property;
both were corrected before the passing runs. Restricted-process test launches
were rerun with normal process access.

Local Compose startup is blocked by Docker Desktop's inaccessible runtime Unix
sockets on Windows. The engine failed before any test container started. The
production-PWA integration suite and PostgreSQL concurrency checks are being
verified through the repository's clean-checkout CI; results are recorded below
when available. No acceptance claim is made from a test that has not run.

The first CI browser run passed 22 existing tests and failed the two new tests at
the second device login: the PWA/account-security suites and new tests together
used six logins per fixture account within the real five-attempt limit. The new
tests now share the less-used Jamie/Robin fixtures (three logins per account),
assert the login HTTP status explicitly, and wait for worker control before
offline reload. Application rate limits remain unchanged. The lost-response and
interrupted-pull steps passed before the login failure.

## Acceptance mapping

| Criterion | Evidence |
| --- | --- |
| Global/per-record pending, syncing, synced, failed and conflict states | SyncStatus/useSyncStatus, AvailabilityPage, sync-observability.spec.ts |
| Last successful synchronization and explicit recovery | Coordinator status/retry tests and production-PWA interrupted-pull regression |
| Duplicate delivery after lost accepted response | Coordinator unit replay, Server SyncProtocolTest/SyncConcurrencyTest, production transport fault injection |
| Interruption, reload and stable command IDs | Coordinator tests, availability.spec.ts, sync-observability.spec.ts |
| Independent-device changes | Two isolated browser contexts edit the same availability revision; local and Server ranges remain visible |
| Terminal recovery preserves intent until explicit choice | Cancel/confirm UI, repository terminal-state guard and transaction rollback tests |
| Account boundaries and multi-tab observation | useSyncStatus.test.ts, existing session/security tests and native Web Lock integration |
| Integrated milestone proof | conflict-security.spec.ts closes/reopens offline, changes a real official assignment and reconciles without rewriting it |

## Scope and migration

No database migrations, dependency changes, API changes or new operational
workflows. Dexie stays at version 3. Deploy the normal Client/PWA build; do not
clear browser data or pending commands. Official assignment conflicts continue
to require Management action. Human review and merge are required to complete
RUSH-014/Milestone 2.
