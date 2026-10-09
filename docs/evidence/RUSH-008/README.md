# RUSH-008 verification evidence

Date: 2026-10-09. Environment: Windows, Node 24.16.0, npm 11.13.0.
Task: Dexie schema and repositories. Requirements: Technical §7, with §8 account
partitioning; cross-cutting support for R-01–R-38, no new functional scenario claim.

## Acceptance coverage

| Criterion | Implementation/test evidence |
| --- | --- |
| Versioned Dexie stores | `database.ts` v1; cold initialization and populated next-version migration tests |
| Account/organization isolation | Separate database names, immutable scope; duplicate IDs in three partitions and scoped deletion tests |
| Typed repositories | `accountStorage.ts`, typed envelopes, shared profile runtime validation/allowlist |
| Durable records and metadata | Reopen tests for profiles, Server revisions, pending commands/projections, metadata and opaque checkpoints |
| Safe migration | Successful test-only v2 preserves every store; failed upgrade rolls back version and intent; future schema rejected |
| IndexedDB failures | Missing API, blocked upgrade, quota, clone failure, read failure, closed handle and schema mismatch tests |
| Pending intent protection | Command/projection transaction rollback, duplicate UUID rejection, unresolved-state retention and guarded clear |
| Multiple connections | Concurrent revision-safe cache writes, one winning operation insert, versionchange closes old handles |
| Eviction | Empty recreated database has no checkpoint or successful-sync claim |

## Executed checks

| Command | Result |
| --- | --- |
| `npm --prefix client run test:unit` | PASS: 6 files, 66 tests |
| `npm --prefix client run typecheck` | PASS |
| `npm --prefix client run lint:check` | PASS |
| `npm --prefix client run build` | PASS: production SPA |
| `npm --prefix client run build:pwa` | PASS: production PWA and Workbox service worker |
| `npm --prefix client run test:shell` | PASS: 12 desktop/mobile Chromium tests |
| `npm run conventions:test` | PASS: 8 tests |
| `npm audit --prefix client --omit=dev --json` | PASS: zero runtime dependency advisories |
| `git diff --check` | PASS |

Initial type checking found an unavailable-IndexedDB fixture incompatible with
`exactOptionalPropertyTypes`; the explicit test-only fault injection cast fixed it.
Initial lint/build runs found an unbound-method warning in the quota fault injector;
the test documents its explicit `this` receiver. All affected checks were rerun
successfully after those fixes. No test failures remain.

Server/Pest and full-stack authentication E2E were not rerun locally because this
task changes no Server/authentication behavior. Existing CI runs those checks for
the PR. No CI pass is claimed by this local evidence. No UI changes or screenshots.

## Scope and migration limits

Schema v1 is the first production Dexie schema. Next-version migration fixtures
exercise upgrade behavior without inventing a previously deployed schema. There
are no Server migrations; install locked Client dependencies. Do not downgrade or
clear an unknown newer Client database. See [ADR 0008](../../decisions/0008-dexie-storage-foundation.md).

Storage is not yet connected to a new user workflow. Sync protocol/coordinator,
offline availability, logout/revocation/account-switch UX, and the production-PWA
write → reload → reconnect → conflict proof remain RUSH-009–014. The built-PWA
check above proves compilation only. Human review and merge remain required.
