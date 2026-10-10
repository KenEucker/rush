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

The first production CI run (38070115309) passed PostgreSQL **113 tests / 776
assertions**, all Client checks, and **23 E2E tests**. The new mobile season login
then reached the existing shared 30/minute IP login limit, also affecting the
two following tests. The season test's fixture login now honors a bounded Server
Retry-After cooldown once; production rate limits and test retry counts remain
unchanged. The same desktop season workflow passed against PostgreSQL/Caddy.

## Screenshots

Captured from the successful desktop/mobile workflow using seeded fixture accounts
and visually inspected: [desktop](desktop-season.png), [mobile](mobile-season.png).
The browser test also attaches screenshots to its Playwright report for CI review.

## Phase creation defaults follow-up

R-01: Adding the first phase defaults its start to the entered season start;
subsequent rows default to the calendar day after the preceding row's inclusive
end. These are editable suggestions applied only when adding a row. Saved,
manually entered and validation-recovered dates are preserved. Missing source
dates and suggestions outside entered season bounds leave the new start blank.
Calendar-day arithmetic uses date-input UTC values, independent of browser DST.
No database, API, dependency or sync changes are required.

- `php vendor/bin/pest tests/Feature/SeasonConfigurationTest.php --compact`:
  **18 passed, 108 assertions**.
- `npx playwright test --config playwright.integration.config.ts seasons.spec.ts`
  from client: **4 passed**, desktop/mobile on isolated Laravel/SQLite, including
  leap day, month/year/DST transitions, missing dates, season bounds, row removal,
  editable defaults, saved-season extension, validation and stale-edit recovery.
- `php vendor/bin/pint --dirty --format agent`, `npx eslint e2e/integration/seasons.spec.ts`,
  `npx prettier --write e2e/integration/seasons.spec.ts`, and `npx vue-tsc --noEmit`:
  **passed**. The initial sandboxed Pest run could not open its bootstrap;
  the reported passing run used normal local filesystem access.

The previous full CI run 38070577975 passed all four jobs, including **113 Pest
tests / 776 assertions** and **26 production-stack E2E tests**. The phase-default
follow-up adds two browser cases and requires a new CI run on the updated PR.

## Acceptance mapping

### Strict end-date minimum follow-up

The owner explicitly approved a strict next-day end minimum for seasons and
phases. The shared save service rejects same-day ends on creates and updates;
native date inputs update their minimum when the start changes and suggest it
when an empty end is focused. Existing entered ends are preserved. Phase ends
are capped at the season end, and new phase starts need room for their minimum
end. No schema, dependency or offline/sync changes are needed. Existing one-day
records remain readable and require correction before the next save.

- Focused `php vendor/bin/pest tests/Feature/SeasonConfigurationTest.php --compact`:
  **22 passed, 156 assertions**.
- Full `php vendor/bin/pest --compact`: **114 passed, 802 assertions,
  3 PostgreSQL-only skips**.
- `npx playwright test --config playwright.integration.config.ts seasons.spec.ts`:
  **6 passed** on isolated Laravel/SQLite, desktop/mobile. Covers strict bounds,
  empty-end suggestions, clearing, preservation, leap/year/DST transitions and
  insufficient space before the season ends. An initial desktop failure exposed
  native date segments emitting focus again while clearing; repeated focus within
  the same field no longer reapplies a default.
- Pint, TypeScript and ESLint: **passed**. Production server image build and
  local `/api/v1/health` check: **passed**.
- Prior CI run 38072624092 passed Server/Client/conventions and **27/28 E2E**;
  its mobile PWA login hit the existing shared IP throttle (the artifact showed
  "Too many attempts"). That test now respects one bounded Retry-After delay,
  as the season fixture already does. Production limits remain unchanged.
  A full-stack CI rerun is required for this test adjustment and the new changes.

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
