# RUSH-015 verification evidence

Requirements: R-01, R-11 (season/calendar foundation). Prerequisites RUSH-001–014
are merged on production; Milestone 2 proof is recorded in RUSH-014 evidence.

## Checks

- Focused Pest SeasonConfigurationTest + SeasonCalendarTest: **26 passed,
  136 assertions**.
- Full `php vendor/bin/pest --compact` from server: **110 passed, 754 assertions,
  3 PostgreSQL-only skips** (two existing sync cases and SeasonConcurrencyTest).
  The new test exercises two different Management writers with real PostgreSQL locks in CI.
- `npm run server:format`, `composer validate --working-dir=server --strict`:
  **passed**.
- Fresh migration → rollback → reapplication: **passed** on a disposable SQLite
  fixture, followed by fixture seeding.
- `npm --prefix client run typecheck`: **passed**.
- `npm --prefix client run lint:check`: **passed**.
- `npm --prefix client run test:unit`: **164 passed**.
- `npm run conventions:test`: **8 passed** (requires temporary Git write access).
- `npx playwright test --config playwright.integration.config.ts seasons.spec.ts`
  from client: **2 passed**, desktop/mobile, on the isolated Laravel/SQLite fixture.

Docker's Linux engine pipe is unavailable locally. Orchid browser tests use a
disposable SQLite-backed Laravel server on loopback port 9187; this is UI evidence,
not PostgreSQL or production-PWA evidence. Clean-checkout CI runs the new tests
with the existing Caddy/PostgreSQL/built-PWA suite.

Initial tests expected HTTP 200 for new Eloquent resources and 404 for a disallowed
Orchid method. Corrected to Laravel's actual 201 and 405 responses. Initial
Playwright selectors omitted Orchid's required-field marker; selectors were fixed.
Browser tests also exposed missing phase-field accessible labels (fixed using
Orchid's explicit aria-label attributes), mobile table overflow (phase rows now
stack), and a test race caused by observing a different season's revision in
the list (now waits for the edited form's actual revision).

## Screenshots

Captured from the successful desktop/mobile workflow using seeded fixture accounts
and visually inspected: [desktop](desktop-season.png), [mobile](mobile-season.png).
The browser test also attaches screenshots to its Playwright report for CI review.

## Acceptance mapping

| Criterion | Evidence |
| --- | --- |
| Create/edit organization seasons and dated phases | SaveSeason, SeasonScreen, API, SeasonConfigurationTest, seasons.spec.ts |
| Explicit season zone and configurable local week | Required fields, validation, SeasonCalendarTest |
| Phase containment, overlap rejection and atomic boundary changes | Configuration tests and browser validation/recovery |
| Server authorization, revocation, foreign records | Service/API/Orchid denied tests |
| Audit, rollback, stale/duplicate saves | Configuration tests, SeasonConcurrencyTest (PostgreSQL) |
| Overnight/week/phase/season allocation | Exact boundary and conservation tests |
| DST gaps/folds and non-hour shifts | Los Angeles, Lord Howe, Havana, Apia tests |
| Offline policy | Online-only Orchid; no Client persistent data or sync changes |

## Migration and scope

Run the additive season/phase/audit migration before serving the new screen/API.
No dependencies, existing domain data, Client stores or pending queues change.
Rollback removes the new tables and their data; back up before rollback after
real configuration is entered. Employment/meeting/overtime rules remain deferred
to RUSH-017/037. Human review, required CI and merge remain completion gates.
