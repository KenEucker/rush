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

Configuration is online and phase-scoped. Group member entry uses removable
selection pills in Orchid; the existing domain restriction on commas in area
names remains. Exact-name references must be updated when renaming inputs.
Partial assignment creation/edit UI and generated
whole-shift defaults remain RUSH-023; this task provides their validated persisted
representation through the existing Management API. No employment/fairness
policy, gap calculation, publication or payroll behavior is added.

Required CI, human review and merge remain completion gates.

## CI regression follow-up

CI run `38076038969` passed Server, Client and conventions but failed two of 32
E2E cases. The mobile conflict proof received HTTP 429 during fixture sign-in.
The desktop observability proof captured Last sync before a later successful
startup/navigation pull; its trace shows successful pulls at 18:35:42.820Z and
18:35:43.166Z before the first injected push failure. The test incorrectly
compared the subsequent failed pull to the earlier timestamp.

All integration fixture logins now share a single bounded Retry-After handler.
It retries once only for HTTP 429, validates the 1–60 second cooldown, extends
the test budget only by that wait, and refills the password cleared by the real
sign-in form. Other responses still reach the original assertions. Production
limits, application code and Playwright test retry counts are unchanged. A
deterministic browser case injects 429 followed by 422 to verify waiting,
password restoration, bounded timeout allowance and retained rejection behavior.

The observability proof captures its comparison timestamp after the injected
lost-receipt failure is visible. The single coordinator has then completed any
earlier successful pulls; the assertion still requires that the subsequent
interrupted pull leave Last sync unchanged.

Verification for this follow-up: clean seeded production-stack `npm run test:e2e`
**34 passed** (desktop/mobile, including the two original failures and two
cooldown regressions); Client `npm run lint:check` and `npm run typecheck`
**passed**; `npm run conventions:test` **8 passed**. The earlier full local
attempt reused records from focused tests and hit the availability fixture's
last-row assumption; the clean fixture rerun above passed without changing
that workflow. GitHub CI must rerun for the updated commit.

## Coverage selector follow-up (2026-10-13)

R-02/R-03 configuration now uses Orchid multiple-select pills for shared-area
membership and a dropdown for staffing shifts. Choices follow unsaved definitions
on the page. Renamed/deleted selected references remain marked “not defined” for
explicit correction, including after Server rejection. Clearing an invalid
selection removes it from the choices. Position counts and staffing rows remain
explicit; no database, API schema, dependency or offline changes are needed.

Checks executed for this follow-up:

- `php vendor/bin/pest tests/Feature/PhaseCoverageTest.php --compact`: **32 passed,
  239 assertions**, SQLite. Includes Orchid array submissions, removals, empty/
  unknown/malformed member rejection, permissions and unchanged aggregate on error.
- `php vendor/bin/pint --test`: **passed**.
- `npx playwright test --config playwright.integration.config.ts phase-coverage.spec.ts`:
  **2 passed**, desktop and 360px mobile, against isolated Caddy/Laravel/PostgreSQL
  and a production-built PWA. Covers dropdown choices before saving definitions,
  shift renames, pill removal/reselection, newly added areas, removed references,
  retained selections after validation, stale revision recovery and save/reload.
- Client `npm run lint:check` and `npm run typecheck`: **passed**.
- Root `npm run conventions:test`: **8 passed**; `git diff --check`: **passed**.

Initial browser attempts exposed test clicks that closed an already-open menu or
hit a pill's remove button at the control's center on mobile. The test now opens
closed menus only and targets the multi-select search input. The final desktop
and mobile run passed. Updated screenshots above were visually inspected;
mobile labels and controls fit without horizontal overflow.

No migration is required. Existing stored names and group membership load into
the new controls. Review and required CI remain pending for this follow-up.
