# RUSH-009 verification evidence

Date: 2026-10-09. Environment: Windows, PHP 8.5.7, Node 24.16.0,
PostgreSQL 17.2; full-stack containers use the repository's pinned images.
Requirements: Technical §7 and §8 authorization, cross-cutting R-01–R-38 support.

## Acceptance coverage

| Criterion | Implementation and test evidence |
| --- | --- |
| Stable operation IDs | Typed `stageProfileUpdate` atomically uses the existing Dexie queue; Vitest reconstructs identical intent after reopen and proves failed saves do not succeed |
| Idempotent commands | `PushOperation`, membership/operation primary key, normalized fingerprint, original receipt replay after later edits, no-op and changed-intent tests |
| Server revisions | Existing `UpdateMemberProfile` domain revision/audit checks; stale operation preserves current state and returns durable conflict |
| Transactional application | Receipt, domain record and audit roll back together on receipt failure; pull projection, sequence and journal roll back on journal failure |
| Concurrency | Two separate PHP processes both reach PostgreSQL membership lock; duplicate UUID gives one effect, distinct operations on one revision give one acceptance and one conflict |
| Push/pull contracts | Shared JSON asserted by Pest/Vitest; runtime field/type/scope validation; real API decoded by Client parsers in Playwright |
| Stable checkpoints | Encrypted membership/generation/cursor/upper-bound tokens; bounded page replay, in-flight edits, foreign/future/tampered tokens, role reset |
| Authorization/deletions | Current membership and profile policy on every request and replay; owner/peer/manager/foreign account tests; deletion tombstones and historic redaction |
| Browser security | Real Sanctum cookies, missing-CSRF rejection for both endpoints, manager denied peer write, Ranger replay and checkpoint pull on desktop/mobile |

## Final executed checks

| Command | Result |
| --- | --- |
| `composer --working-dir=server test -- --compact` with disposable PostgreSQL environment | PASS: 67 tests, 505 assertions, including both concurrent-process tests |
| `php artisan migrate --force`, `migrate:rollback --force`, `migrate --force` on disposable PostgreSQL | PASS: complete schema round trip and reapplication |
| `composer --working-dir=server format:check` | PASS |
| `composer validate --working-dir=server --strict` | PASS |
| `npm --prefix client run test:unit` | PASS: 8 files, 80 tests |
| `npm --prefix client run typecheck` | PASS |
| `npm --prefix client run lint:check` | PASS |
| `npm --prefix client run build` | PASS: production SPA |
| `npm --prefix client run build:pwa` | PASS: production PWA and service worker |
| `npm --prefix client run test:shell` | PASS: 12 desktop/mobile Chromium tests |
| `npm run client:test:e2e` | PASS: 6 desktop/mobile real-session tests, extended with sync checks |
| `npm run conventions:test` | PASS: 8 tests |
| `git diff --check` | PASS |

Full-stack setup used `docker compose -p rush-009-ci -f docker/compose.ci.yaml
up -d --build --wait --wait-timeout 120`, then `exec -T server php artisan
migrate --force` and `db:seed --force`. The PostgreSQL unit/integration suite
used a separate `rush-009-test-postgres` container on loopback port 55439, database
and user `rush_test`, with the same disposable password as CI. No production data
was used. Both fixtures were removed after checks.

The initial concurrency harness timed out waiting for subprocess stdout on Windows.
It now waits for both writers' PostgreSQL lock state, directly checking that they
overlap. One diagnostic rerun overlapped the previous suite's migration cleanup
and failed with a disposable-database DDL collision. After the old process exited,
the corrected isolated tests and complete sequential suite passed. No failures
remain. Build plugin-timing and Playwright color-environment notices were nonfatal.

No CI result is claimed here; these are local results. No material UI changes or
screenshots. The built-PWA browser tests exercise protocol/authentication, not
offline availability. RUSH-010–014 retain coordinator, reconciliation, account
lifecycle and the mandatory offline write/reload/reconnect/conflict proof.

## Deployment and limitations

Four additive Server tables require `php artisan migrate --force`. No dependencies
or Dexie schema changes. The Server captures authorized profile state on pull;
intermediate domain revisions can coalesce, while domain audits remain intact.
Do not drop production receipts after operations have been accepted, because this
removes replay protection. See [ADR 0009](../../decisions/0009-sync-operations-and-protocol.md)
and the [contract](../../contracts/sync.md). Human review and merge remain required.
