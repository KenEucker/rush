# RUSH-020 verification

Task: one-off events and paid attendance · Requirement: R-06  
Date: 2026-10-13 · Target branch: production

Prerequisites RUSH-001–019 are merged on production, including the Milestone 2
production-PWA offline/conflict gate. The owner explicitly chose cross-area
scheduled-Ranger counting; [ADR 0020](../../decisions/0020-one-off-events.md)
records the rule and boundaries. [API contract](../../contracts/events.md).

## Implemented behavior

Management creates/edits a single dated event through Orchid or the API, selects
paid/unpaid full-event attendance, optional number/percentage target, and whether
scheduled Rangers count. Evaluation counts scheduled Rangers across all areas,
deduplicates per interval, flags unavailable/inactive attendance, and preserves
ordinary shift/coverage records. Paid attendance exposes elapsed source intervals
split by local week/phase for later scheduling and timesheet integration.
No recurrence, pay calculation, publication, or offline event administration.

The owner-approved year selector scopes menus and lists to season starting year.
It lives in a collapsed **Year: YYYY** sidebar item, leaving page content free of
the switching form. Choosing a year opens its Management overview.
Cross-year seasons appear only under their starting year and become read-only
when that year is past in the season time zone. Historical configuration remains
visible; Server services reject edits, new events and backdating into archived
seasons. Notice browsing uses the organization's local starting year. Selected
years live in URLs, not a shared session preference, so tabs remain independent.

Management has separate expandable sidebar sections for **Seasons and phases**,
**Shifts and coverage**, **Scheduling policies**, **Events and attendance**, and
**Unavailability notices**. The menu links directly to authorized organization,
season and phase screens. Missing seasons/phases offer setup links. The home
screen retains its shortcuts.

UUID event/attendance identities, same-tenant foreign keys, revision/season
checks, Management policies, transactional before/after audit, and retained form
input protect changes. Attendance identities remain after removal/reassignment.
RUSH-022/023 own draft integration; RUSH-037–042 own timesheet/meeting-pay
integration. This is partial scenario 6 evidence, not its completed journey.

## Executed checks

- `php vendor/bin/pest --compact` in `server`, with disposable PostgreSQL 17.2:
  **263 passed, 1,823 assertions**, no skips. Includes event feature, migration,
  tenant/check-constraint, audit rollback, and concurrent-edit tests.
- After extending the concurrency case to cover creation too:
  `php vendor/bin/pest tests/Feature/EventConcurrencyTest.php --compact` on
  PostgreSQL: **2 passed, 14 assertions** (creation and edits).
- `php vendor/bin/pest tests/Feature/EventTest.php tests/Feature/EventConcurrencyTest.php --compact`
  on SQLite: **31 passed, 7 PostgreSQL-only cases skipped**, 181 assertions.
- `php vendor/bin/pint --test`: **passed**.
- `npm run client:test`: **173 passed in 17 files**.
- `npm run client:lint` and `npm run client:typecheck`: **passed**.
- `npm run conventions:test`: **8 passed**.
- Production-built fixture:
  `docker compose -p rush-ci -f docker/compose.ci.yaml up -d --build --wait --wait-timeout 180`:
  **passed**, including Quasar PWA build and final Laravel runtime checks.
- In that disposable fixture, `php artisan migrate --force`,
  `migrate:rollback --step=1 --force`, `migrate --force`, and `db:seed --force`:
  **passed**. The running development stack's data was not migrated or seeded.
- `npm run client:test:e2e -- events.spec.ts`: **2 passed** (desktop/mobile):
  actual Orchid controls, server validation with retained input, paid attendee
  save/reload, percentage evaluation, deduplicated shift contribution, stale
  manager edits, no recurrence, and mobile overflow check.
- `npm run client:test:e2e -- auth.spec.ts seasons.spec.ts`: **12 passed**
  (desktop/mobile), including real CSRF, role boundaries, login/account isolation,
  season navigation, validation, date controls, and stale-edit recovery.
- After final review, `php vendor/bin/pest tests/Feature/EventTest.php --compact`
  on SQLite: **31 passed, 5 PostgreSQL-only cases skipped**, 185 assertions.
  This includes keeping inactive attendees selected until explicitly removed.
- Final PostgreSQL event suite:
  `php vendor/bin/pest tests/Feature/EventTest.php tests/Feature/EventConcurrencyTest.php --compact`:
  **38 passed, 209 assertions**, no skips.
- Artisan help smoke checks: local `artisan:help -- migrate`,
  `artisan:help -- cache:forget`, `artisan:help -- schedule:interrupt`,
  `server:clear-compiled -- --help`, `server:cache:forget -- --help`,
  `server:schedule:interrupt -- --help`, and `docker:artisan:help -- migrate`:
  **passed**. Existing generic runners continue to support every Artisan command.

Initial sandbox attempts blocked temporary Git/PHP/Vitest files; the same checks
passed outside the sandbox. Initial event tests exposed the scoped Orchid route
allowlist and form-option property issues, both fixed. Browser selectors were
corrected to operate visible Tom Select controls instead of hidden native/search
inputs; the final desktop/mobile run passed without retries or suppressed errors.

## Screenshots

Seeded fixture accounts only; images were visually reviewed.

- [Desktop event](events-desktop.png)
- [Mobile event](events-mobile.png)
- [Desktop retained conflict](events-conflict-desktop.png)
- [Mobile retained conflict](events-conflict-mobile.png)
- [Desktop event navigation](events-navigation-desktop.png)
- [Mobile event navigation](events-navigation-mobile.png)

Navigation follow-up: `php vendor/bin/pest tests/Feature/EventTest.php --compact`
on SQLite passed **33 tests, 195 assertions**, with 5 PostgreSQL-only cases
skipped. `php vendor/bin/pint --test` passed. The updated
`npm run client:test:e2e -- events.spec.ts` passed **2 desktop/mobile tests**
starting from the Management home, with the production-PWA fixture rebuilt,
migrated and seeded. Navigation screenshots were visually reviewed.
The local development PHP container was restarted to invalidate opcode caching;
no development data was changed.

Expanded-menu follow-up: the targeted authorization/empty-season Pest cases
passed **2 tests, 15 assertions**; Pint, Client lint and TypeScript passed.
The updated desktop/mobile Playwright event tests passed **2 tests**, navigating
all five sidebar sections before creating the event. The initial mobile run
clicked the empty attendee control without opening its dropdown; the final test
uses the visible attendee search input. Menu screenshots wait for expansion to
finish and were visually reviewed. No routes, permissions or database schema
changed for the expanded menu.

## Migration and remaining gates

Year-selector follow-up verification:

- `php vendor/bin/pest --compact` on disposable PostgreSQL: **273 passed,
  1,921 assertions**, no skips.
- The full SQLite suite: **256 passed, 17 PostgreSQL-only cases skipped,
  1,824 assertions**.
- After final review added notice-only years, `php vendor/bin/pest
  tests/Feature/ManagementYearTest.php --compact` on PostgreSQL:
  **8 passed, 75 assertions**.
- `php vendor/bin/pint --test`: **passed**.
- Production-PWA fixture build, migrations and explicit historical browser
  fixture: **passed**. CI invokes `tests/Fixtures/seed-management-years.php`
  only against the isolated `rush_ci` database.
- Final `npm run client:test:e2e -- management-years.spec.ts phase-coverage.spec.ts
  phase-policies.spec.ts unavailability-management.spec.ts`: **8 passed** on
  desktop/mobile. The existing event tests (**2 passed**) and season tests
  (**6 passed**) also passed in the broader run. Initial duplicate-link test
  selectors were scoped to the intended menu/page link, then rerun successfully.
- Final Client lint, TypeScript, and convention tests (**8 tests**): **passed**.
- Archive screenshots: [desktop](year-archive-desktop.png),
  [mobile](year-archive-mobile.png); visually reviewed, with readable disabled
  controls and no mobile horizontal overflow.

This follow-up requires no additional migration or data rewrite. Restart PHP
when deploying into a runtime that disables opcode timestamp checks. The local
development PHP container was restarted; its database was not reseeded.

Compact year-menu refinement:

- `php vendor/bin/pest tests/Feature/ManagementYearTest.php --compact` on SQLite:
  **8 passed, 75 assertions**.
- `npm run client:test:e2e -- management-years.spec.ts`: **2 passed** on
  desktop/mobile against the rebuilt production-PWA fixture. Covers collapsed
  defaults, past/future switching, menu filtering, independent tabs, reloads,
  archived controls and rejected forged edits.
- Pint, Client lint and TypeScript: **passed**. The initial Prettier check reported
  formatting in the updated test; formatting was corrected and lint rerun.
- Visually reviewed the [desktop year menu](year-menu-desktop.png),
  [mobile year menu](year-menu-mobile.png), and refreshed archive screenshots.

Run `npm run docker:server:migrate -- --force` against the intended deployment
after backup. The additive migration creates events, retained attendance, audit
history, and a composite season key. Rollback removes this task's event and audit
data; back it up before rollback. There are no Client database/sync migrations,
new dependencies, or scheduled jobs. Human review, required CI, and merge remain
necessary before marking RUSH-020 done.
