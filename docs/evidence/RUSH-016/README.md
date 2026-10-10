# RUSH-016 verification evidence

Requirements: R-02, R-03. Prerequisites: RUSH-001–015 merged on production;
Milestone 2 offline proof recorded by RUSH-014.

## Executed checks

- Full `php vendor/bin/pest --compact` against a separate disposable PostgreSQL
  17.2 database: **156 passed, 1,083 assertions, no skips**. Includes real
  concurrent managers, existing sync races, calendar policy and offline-proof
  assignment regressions.
- Final coverage read-lock change: `php vendor/bin/pest
  tests/Feature/PhaseCoverageTest.php tests/Feature/PhaseCoverageConcurrencyTest.php
  --compact` against PostgreSQL: **32 passed, 217 assertions**.
- Earlier SQLite full regression: **152 passed, 1,053 assertions, 3 PostgreSQL
  skips** (before adding the fourth PostgreSQL-only concurrency test).
- `php vendor/bin/pint --test` and `composer validate --strict`: **passed**.
- `npm run lint:check`, `npm run typecheck` from client: **passed**.
- `npm run test:unit` from client: **164 passed**.
- `npm run conventions:test`: **8 passed**. `git diff --check`: **passed**.
- `docker compose -p rush-016 -f docker/compose.ci.yaml up -d --build --wait
  --wait-timeout 120`: **passed**, building the production Server and PWA images.
- Isolated PostgreSQL migration → full rollback → reapplication → seed:
  **passed** using the existing CI Compose fixture and `artisan migrate --force`,
  `migrate:rollback --force`, `migrate --force`, `db:seed --force`.
- `npx playwright test --config playwright.integration.config.ts
  phase-coverage.spec.ts`: **2 passed**, desktop and mobile against
  Caddy/Laravel/PostgreSQL/built PWA.
- `npm run test:e2e` from client: **32 passed** against that production stack,
  including offline browser restart, reconnect, real Management assignment
  conflicts, two-device conflict recovery, session/account security and seasons.

Initial authorization tests exposed the need to allow the new scoped Orchid
route in ManagementAccess. The test also needed explicit web-guard authentication
after switching users across API/Orchid requests. The first mobile browser test
selected a same-named phase from the earlier desktop fixture; it now follows its
created phase's exact URL. All corrected cases passed.

## Acceptance mapping

| Criterion | Implementation and test evidence |
| --- | --- |
| Configurable starts/durations, no fixed daily pattern | SavePhaseCoverage, PhaseCoverageScreen; 390-minute and three daily shift fixtures |
| Dedicated per-area and shared group staffing | Normalized shifts/areas/groups/membership/staffing tables; API/Pest and browser model-switch workflow |
| Prohibit mixing models | Shared service validation, composite FK and PostgreSQL exclusive-target check; rejection and rollback tests |
| Independent phase inputs | Dedicated low-staff and shared three-shift phase tests; unconfigured second phase browser assertion |
| Partial manual representation | AssignmentShiftContext and audited official interval context; whole/partial bounds, immutable context after configuration deletion |
| Overnight and DST | SeasonCalendar reuse; elapsed overnight duration, nonexistent start rejection, both repeated-time offsets |
| Management permissions and isolation | Guest/Ranger/inactive/foreign checks on API, Orchid and service; foreign row identity rejection |
| Stale/concurrent writes and audit | Season/coverage revision checks, transactional audit failure, real PostgreSQL competing creators |
| Mobile UX and retained intent | Desktop/mobile form, save/reload, mixed-model error, stale tab preserving entered shift |
| Offline boundary | Online Orchid only; no Dexie schema or sync change; existing production-PWA regression retained |

Scenarios 1–2 have configuration evidence only. Their generation/gap/publication
owners and all nine full release acceptance owners remain unchanged.

## Screenshots

Captured from successful production-stack workflows and visually inspected:
[desktop](desktop-coverage.png), [mobile](mobile-coverage.png). Screenshots are
also attached to the Playwright report. Mobile forms stack without horizontal
overflow and keep the standard Orchid save action accessible.

## Migration, limitations and review

Apply `2026_10_10_220000_create_phase_coverage` before serving the new UI/API.
It adds seven configuration/audit tables and nullable official assignment context;
existing assignments remain unchanged. No dependency, Client store, queue or sync
protocol changes. Rollback removes these configuration records and the context
column; back up before rolling back after real data is entered. Existing
assignment audit JSON remains intact.

Configuration is online and phase-scoped. Group member entry is comma-separated
in Orchid, so area names cannot contain commas. Exact-name references must be
updated when renaming inputs. Partial assignment creation/edit UI and generated
whole-shift defaults remain RUSH-023; this task provides their validated persisted
representation through the existing Management API. No employment/fairness
policy, gap calculation, publication or payroll behavior is added.

Required CI, human review and merge remain completion gates.
