# RUSH-013 verification evidence

Task: RUSH-013. Requirements: R-07, R-08 (conflict foundation), Technical §§7–8.
Prerequisites RUSH-001–012 are merged into production. Human review, required CI
and merge are still required; this does not claim the full R-07/R-08 workflows
or any of the nine release acceptance scenarios complete.

## Tests executed locally

- `composer --working-dir=server test -- --compact` against a dedicated
  PostgreSQL 17.2 container: **86 passed, 633 assertions, no skips**.
- `php server/artisan migrate --force`, `migrate:rollback --force`, then
  `migrate --force` on that disposable PostgreSQL database: **passed**.
- `npm run client:test` / `npm --prefix client run test:unit`: **159 passed**.
- `npm run client:lint`, `npm run client:typecheck`: **passed**.
- `npm run server:format`, `composer validate --working-dir=server --strict`:
  **passed**.
- `npm run conventions:test`: **8 passed**.
- `npm run client:build`: **passed**; `npm --prefix client run test:shell`:
  **12 passed** across desktop/mobile.
- Production PWA build through
  `docker compose -p rush-ci -f docker/compose.ci.yaml up -d --build --wait --wait-timeout 120`:
  **passed**.
- `npm run client:test:e2e`: **22 passed** across desktop/mobile with real Caddy,
  Sanctum, PostgreSQL, native IndexedDB/Web Locks and Workbox.
- `npm --prefix client run test:e2e -- conflict-security.spec.ts` after the final
  recovery-screen refinement: **4 passed**, including session expiration during
  a save and successful reauthentication. The recovery-link locator was corrected
  after an initial timeout; no retries or skipped tests were used.

Earlier development runs found the expected obsolete 403 auto-retry assertion,
strict test typing/lint issues, and a test that attempted a new login before
confirmed logout. These were corrected before the passing runs above. The first
SQLite run passed 83 tests and skipped the two PostgreSQL-only concurrency cases;
the final PostgreSQL run includes those cases and the added workspace role test.

## CI follow-up

[CI run 38019900316](https://github.com/KenEucker/rush/actions/runs/38019900316)
passed Server, Client and Git conventions checks but failed one mobile E2E test.
The authentication suite and conflict proof reused the same Management fixture
six times within one minute, exceeding the real five-attempt per-account limit.
The conflict proof now uses the existing Riley Management fixture independently
of the authentication suite, and its login helper asserts the HTTP status before
waiting for navigation. Production rate limits remain unchanged.

After the correction, the disposable production stack was rebuilt and seeded:
`npm --prefix client run test:e2e` **passed all 22 desktop/mobile tests** without
retries or skips. `npm --prefix client run lint:check` and
`npm --prefix client run typecheck` **passed**.

## Acceptance mapping

| Criterion | Evidence |
| --- | --- |
| Manager changes assignment while Ranger is offline | AssignmentConflictTest and conflict-security.spec.ts exercise the real online Management endpoint and audit |
| Offline intent survives reload and closed/reopened browser | Persistent Chromium profile, disabled HTTP cache, offline restart, actual Dexie queue |
| Intent is accepted without silently changing official assignment | Report remains accepted, assignment stays revision 2; only a subsequent Management revision-checked write resolves it |
| Conflict changes after report acceptance | Server emits changed pull projection at unchanged report revision; Client applies it and removes resolved conflicts |
| Rejected operations remain explained | Stored Server field errors, terminal 403 state, stale-revision report and preserved local representation tests |
| Current permission/role enforcement | Server membership locks, role-loss/mismatch tests, Client workspace locking tests |
| Logout and account switching | Explicit preserved-work logout, live second tab loses access, second account cannot read/send first account's work, original account resumes |
| Expired sessions | 401/419 pause and durable queue unit tests plus real session invalidation in browser |
| Migration/audit safety | PostgreSQL rollback/reapply, assignment audit failure rollback, stale revision and actor/reason assertions |

## Screenshots

- [Desktop official conflict](desktop-official-conflict.png)
- [Mobile official conflict](mobile-official-conflict.png)
- [Desktop preserve-work logout](desktop-preserve-signout.png)
- [Mobile preserve-work logout](mobile-preserve-signout.png)
- [Desktop session recovery](desktop-session-recovery.png)
- [Mobile session recovery](mobile-session-recovery.png)

Screenshots contain seeded demonstration users only. Browser traces/cookies and
temporary test databases are not committed.

## Scope and release notes

The official interval API is a minimal Server-only proof substrate. Complete
scheduling/publication UI, Management availability editing, notification,
acknowledgment and gap workflows remain with their existing owner tasks.
Dexie remains version 3; optional conflict fields support existing cached records.
Migrate Server tables before deployment. Preserve assignment audit and pending
intent when rolling back; do not drop populated tables or clear browser data.
RUSH-014 still owns the integrated observability/regression milestone gate.
