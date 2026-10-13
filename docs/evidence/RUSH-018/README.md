# RUSH-018 verification evidence

Requirements: R-07, R-08. Prerequisites: RUSH-001–017 merged on production;
Milestone 2 offline proof recorded by RUSH-014.

## Executed checks

- `php vendor/bin/pest --compact` against disposable PostgreSQL 17.2:
  **198 passed, 1,377 assertions, no skips**. Includes competing Ranger/Management
  edits, concurrent duplicate acknowledgments and all existing sync/calendar tests.
- `php vendor/bin/pint --test`, `composer validate --strict`: **passed**.
- Client `npm run test:unit`: **165 passed** across 16 files.
- Client `npm run lint:check`, `npm run typecheck`: **passed**.
- Client `npm run build`: **passed**; `npm run test:shell`: **12 passed**.
- `npx playwright test --config playwright.integration.config.ts
  unavailability-management.spec.ts`: **2 passed**, desktop and mobile.
- Full Client `npm run test:e2e` against the rebuilt, freshly seeded production
  stack: **38 passed**, desktop/mobile, including offline browser restart,
  official-assignment conflicts, account security, two-device recovery and the
  new Management workflow. An earlier run also hit an existing mobile season
  picker timeout; the final fresh-fixture rerun passed without changing that test.
- Root `npm run conventions:test`: **8 passed**.
- All **33 local** and **33 Docker** Artisan helpers executed with `--help`:
  **passed**; verified commands and argument forwarding without mutations.
- `docker compose -p rush-018 -f docker/compose.ci.yaml up -d --build --wait
  --wait-timeout 120`: **passed**, production Laravel and Quasar PWA images.
- Isolated-stack `artisan migrate --force` → `migrate:rollback --force` →
  `migrate --force` → `db:seed --force`: **passed**. The full migration cycle
  includes the new acknowledgment table and nullable Management audit reason.

Initial tests found a missing Orchid route allowlist entry, test response-envelope
and guard assumptions, browser navigation/selectors, and an older migration test
that needed to roll back the new dependent table first. These were corrected;
the final results above refer to reruns. No production database was used.

## Acceptance mapping

| Criterion | Evidence |
| --- | --- |
| Ranger creates/edits online and offline | Existing availability command/coordinator, UnavailabilityTest, availability repository tests, production-PWA availability and Management workflow specs |
| Management edits on behalf with audit | Shared SaveUnavailability service, scoped API/Orchid, ManageUnavailabilityTest actor/reason/before/after and audit-failure rollback |
| Management notices | Home count and organization notice list, existing reports automatically needing acknowledgment |
| Revision-specific acknowledgment | Unique report/revision history, first actor/time retained on retry, edit resets notice, stale acknowledgment denied |
| Acknowledgment does not repair schedule | API and browser assertions retain assignment revision and conflicts after acknowledgment; no official assignment audit added |
| Affected assignments and gaps | Positive overlap intersection and optional immutable target context; existing boundary/conflict-clearing tests |
| Privacy and permissions | Guest/Ranger/foreign/inactive Management denial, immutable Ranger ownership, Ranger pull remains owner-only |
| Concurrency | Independent PostgreSQL processes serialize competing Ranger/manager edits and two managers acknowledging one revision |
| Offline acknowledgment | Same-revision projection through ordered pull and Dexie, reopen test, pending edits do not inherit seen status |
| Preserved rejected intent | Manager edits while Ranger offline; Ranger reconnect retains its conflicting range alongside current Server range |

## Migration and boundaries

Apply `2026_10_13_120000_add_unavailability_acknowledgments` before serving the
new Server. It preserves existing reports, range audit and assignments. Existing
reports start unacknowledged. Back up before rollback because it removes the new
acknowledgment audit and Management reason column. No Client schema migration,
new dependency or separate offline queue/cache is needed.

Screenshots from the passing production-PWA tests, visually inspected:
[desktop Management](desktop-management.png), [mobile Management](mobile-management.png),
[desktop Ranger](desktop-ranger.png), [mobile Ranger](mobile-ranger.png).

Orchid is online-only. Coverage warnings identify affected intervals requiring
review, not aggregate missing-position accounting or confirmed replacements.
RUSH-025/040 retain those gap ledgers; RUSH-034 retains the general notification
center. Scenario 4's full draft/publication/replacement journey remains with
RUSH-027/028/031. Human review, required CI and merge remain necessary.
