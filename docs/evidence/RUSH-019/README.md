# RUSH-019 verification evidence

Requirements: R-09, R-10. Prerequisites: RUSH-001–018 merged on production;
Milestone 2 offline proof recorded by RUSH-014. The owner approved field-level
inheritance in [ADR 0019](../../decisions/0019-ranger-preference-profiles.md).

## Executed checks

- Full Server `php vendor/bin/pest --compact` against disposable PostgreSQL 17.2:
  **220 passed, 1,508 assertions, no skips**.
- Focused Server `php vendor/bin/pest --compact --filter=Preference` against
  disposable PostgreSQL 17.2: **22 passed, 131 assertions**, including independent
  processes competing to create the same default scope.
- `php vendor/bin/pint --test`, `composer validate --strict`: **passed**.
- Client `npm run test:unit`: **173 passed** across 17 files.
- Client `npm run lint:check`, `npm run typecheck`: **passed**.
- Client `npm run build`: **passed**; `npm run test:shell`: **12 passed**.
- Full Client `npm run test:e2e` against the final production PWA and freshly
  seeded disposable stack: **40 passed**, desktop/mobile, including inherited
  preferences, offline reload, conflict resubmission, account isolation,
  multi-tab sync and the existing official-assignment conflict gate.
- Root `npm run conventions:test`: **8 passed**.
- All **37 local** and **37 Docker** Artisan helpers executed with `--help`:
  **passed**, verifying command availability and argument forwarding without
  running migrations, clearing application caches or starting workers.
- Production Laravel/Quasar PWA Docker builds and isolated Compose startup:
  **passed**, using project `rush-019` and port 9187.
- Isolated-stack `artisan migrate --force` → `migrate:rollback --force` →
  `migrate --force` → `db:seed --force`: **passed**, including the preference
  schema and audit/projection tables. Fresh migration/seeding also passed.

Initial checks exposed reactive arrays that IndexedDB could not clone, a
non-atomic conflict resubmission, fixture response/selector assumptions and an
older authentication test that assumed sync contained only a member profile.
The fixes clone validated wire data, atomically replace reviewed terminal intent,
and scope that authentication assertion to member profiles while allowing the
authorized catalog. A repository regression proves replacement failure retains
the original intent. No production database was used.

## Acceptance mapping

| Criterion | Evidence |
| --- | --- |
| Default and one-week profiles | PreferenceProfileTest, unique membership/scope key, immutable scope and revision checks |
| Unspecified fields inherit the current default | Server and Client effective resolvers; explicit neutral, numeric target, maximum-hours and fully inherited cases |
| Return to default next week | Server local-week/DST/year boundary cases; desktop/mobile production-PWA next-week preview |
| Positive preferred shifts, desired hours, days off | Validated applicable shift UUIDs, whole minutes, consecutive/separated choices; browser shift selection and inherited hours |
| Offline edits survive reload/reconnect | Dexie v4 migration, atomic command/projection writes, shared coordinator and production-PWA offline reload |
| Conflict recovery preserves intent | Two authenticated sessions, retained 24-hour edit beside accepted 40-hour default, explicit review/resubmit; repository replacement rollback |
| Authorization and audit | Guest, foreign organization, wrong role, peer and revoked membership denial; replay authorization; actor/before/after history and audit-failure rollback |
| Idempotency and concurrency | Receipt replay, stale revision and duplicate scope tests; PostgreSQL concurrent creation yields one accepted profile and one conflict |
| Ordered synchronization and isolation | Profile/catalog pull, tombstones, access changes, invalid checkpoint reset, accepted-but-not-yet-pulled intent, existing account-switch/multi-tab regressions |

## Migration and boundaries

Apply `2026_10_13_190000_create_preference_profiles` before deploying the new
Server/Client. Dexie v4 preserves existing data and adds preferences/catalogs.
Close old PWA tabs to activate the updated bundle. Back up before Server rollback;
it removes preference records, audits and sync projections. Do not downgrade a
Client with pending preference intent. Older Clients retain intent but reject
new sync record types until updated. No dependency or second offline queue was
introduced.

RUSH-022/027 retain draft input snapshots, draft staleness and lifecycle edit
guards. Both preference revisions are exposed for those future consumers.
RUSH-024 retains preference weights/fairness; configured hour limits stay separate.
All nine full acceptance-scenario owners remain unchanged. Human review, required
CI and merge remain necessary.

Screenshots from passing production-PWA preference tests, visually inspected:
[desktop preferences](desktop-preferences.png), [mobile preferences](mobile-preferences.png),
[desktop offline](desktop-offline.png), [mobile offline](mobile-offline.png),
[desktop conflict](desktop-conflict.png), [mobile conflict](mobile-conflict.png).
