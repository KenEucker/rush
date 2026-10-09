# RUSH-010 verification evidence

Verified locally on 2026-10-09. Branch: feat/RUSH-010-sync-coordinator;
base: production with RUSH-001–009 merged. Technical §7, cross-cutting R-01–R-38.

## Acceptance coverage

| Criterion | Evidence |
| --- | --- |
| Queue before local acknowledgment | Coordinator queue/reopen test plus repository atomic command/projection failure tests |
| Push/pull and accepted settlement | Coordinator/repository tests; real-session desktop/mobile integration |
| Retry and connectivity checks | Offline hint, lost response replay, exponential backoff across reopen, timer-driven retry, 429/503 and request timeout tests |
| Resumed synchronization | Syncing-command replay, persisted pagination checkpoint, reload integration |
| Persisted failure handling | Rejected/conflicting intent, 401/403/419 pause and explicit recovery, malformed command, repeated invalid-checkpoint tests |
| Centralized concurrency | Coalesced calls, two Dexie connections, stale-page CAS; native two-tab Web Locks with one actual push |
| Transactional safety | Checkpoint-write and settlement-delete fault injection; tombstone and revision preservation |
| Migration | Released v1-to-v2 preservation and actual failed-upgrade rollback; future-schema rejection |

## Commands and results

- npm --prefix client run test:unit — **passed, 111 tests in 10 files**.
- npm --prefix client run lint:check — **passed**.
- npm --prefix client run typecheck — **passed**.
- npm --prefix client run build — **passed**, production SPA.
- docker compose -p rush-ci -f docker/compose.ci.yaml up -d --build --wait --wait-timeout 120 — **passed**;
  production Dockerfiles built the PWA and Server, all fixture services healthy.
- docker compose -p rush-ci -f docker/compose.ci.yaml exec -T server php artisan migrate --force — **passed**.
- docker compose -p rush-ci -f docker/compose.ci.yaml exec -T server php artisan db:seed --force — **passed**.
- npm --prefix client run test:e2e — **passed, 8 tests**, desktop/mobile Chromium
  against real Caddy, Laravel, Sanctum and PostgreSQL.
- npm --prefix client run test:shell — **passed, 12 tests**, desktop/mobile Chromium.
- npm run conventions:test — **passed, 8 tests**.
- git diff --check — **passed**.
- docker compose -p rush-ci -f docker/compose.ci.yaml down --volumes --remove-orphans — **passed**;
  only the disposable fixture was removed.

Initial unit verification exposed the old v1-only migration assertions after the
v2 change, and lint identified test formatting/type-import issues. These were
corrected before the passing checks above.

Server source/schema was unchanged; Pest was not rerun locally for this Client
task. Full Server regression remains a required CI check. No CI result is claimed
by these local results. No material UI change requires a screenshot.

## Limits and review

The real coordinator is injected through a test-only bundle, never a shipped
debug API. The test writes offline, then restores connectivity before document
reload; it does **not** claim offline shell startup. The real revision conflict is
on the existing profile fixture, not an official scheduling assignment.

RUSH-011–014 still own offline PWA startup, availability, the conflicting
assignment proof, account lifecycle UX and global/per-record recovery UI.
The nine functional acceptance scenarios retain their plan owners.
See [ADR 0010](../../decisions/0010-central-sync-coordinator.md) for caller lifecycle,
Web Locks, retry, schema and rollback requirements. Human review and merge remain
required.
