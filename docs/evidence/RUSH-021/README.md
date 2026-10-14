# RUSH-021 verification

Task: Configuration UI and tests · Requirements: R-01–R-11 (configuration scope)
Date: 2026-10-13 · Target: production

RUSH-001–020 are merged prerequisites on production (`ccb6ca2`, PR #21), including
the Milestone 2 offline/conflict proof. This task integrates the existing screens
and services rather than adding a second configuration interface. All previously
approved calendar, coverage, preference, event and archive decisions remain in force.

## Behavior and defect fixed

`client/e2e/integration/configuration.spec.ts` configures one season through the
Management UI: adjacent low-staff/shared and 24/7/dedicated phases, configurable
shifts, distinct hour targets/caps, and a one-off paid Monday Meeting. A Ranger
sets default preferences, then saves availability and a weekly preferred-shift/
days-off override offline. Both survive reload through the same Dexie queue and
reconcile with Laravel. Default hours are inherited; next week returns to defaults.
The Server denies Ranger configuration reads and writes. Invalid hour policy input
is retained for correction. No browser network or operational records are mocked.

The journey exposed a future-year notice navigation defect: Orchid's acknowledgment
button omitted the year, and its redirect returned to the current-year list. The
button now carries the selected year and the redirect uses `ManagementYear::route`.
List URLs preserve their year; record URLs still derive it from the record.
`ManagementYearTest.php` covers both paths, acknowledgment persistence, unchanged
range revision, and independent current-year navigation. Domain permission,
revision, archive and audit behavior is unchanged.

An unavailable attendee stops counting toward event staffing only after Server
acceptance. Attendance remains assigned and phase coverage stays unchanged
through the API. Acknowledgment means seen and does not repair coverage.
Screenshots of the reconciled Ranger and event views attach to the Playwright report.

## Requirement-to-test map

| Requirements | Implementation and automated evidence |
| --- | --- |
| R-01 | Season/phase UI and archive; integrated journey, `seasons.spec.ts`, `management-years.spec.ts`, `SeasonConfigurationTest`, `SeasonCalendarTest`, `ManagementYearTest` |
| R-02/R-03 | Configurable overnight shifts, dedicated/shared/mixed targets; integrated journey, `phase-coverage.spec.ts`, `PhaseCoverageTest`, `MixedCoverageMigrationTest`, `PhaseCoverageConcurrencyTest` |
| R-04/R-05 | Configuration inputs only here. Existing `AssignmentConflictTest`/unavailability tests flag unreliable coverage; full gap accounting and generator recommendations remain RUSH-022–025/040 |
| R-06 | One-off paid event and attendance, staffing independent of coverage; integrated journey, `events.spec.ts`, `EventTest`, `EventConcurrencyTest`. Timesheet integration remains RUSH-037–042 |
| R-07/R-08 | Offline report/reload/sync and Management acknowledgment/editing; integrated journey, `unavailability-management.spec.ts`, `availability.spec.ts`, `conflict-security.spec.ts`, `ManageUnavailabilityTest`, `UnavailabilityConcurrencyTest` |
| R-09/R-10 | Positive shifts, default/weekly inheritance and next-week fallback; integrated journey, `preferences.spec.ts`, `PreferenceProfileTest`, `PreferenceConcurrencyTest`, Client preference repository/protocol/coordinator tests. Draft staleness remains RUSH-022/027 |
| R-11 | Distinct phase targets/caps/warnings and validation; integrated journey, `phase-policies.spec.ts`, `PhaseSchedulingPolicyTest`, `PhaseSchedulingPolicyConcurrencyTest`. Mixed-phase calculations and employment-rule validation remain with their existing owners |

The nine full functional acceptance scenarios retain their plan owners. This is
Milestone 3 integration evidence, not generation, publication, timesheet or release
acceptance. No new product policy, dependency, database migration, Dexie schema,
sync command or protocol is introduced.

## Executed verification

- Full Pest on isolated PostgreSQL 17.2: `php vendor/bin/pest --compact --colors=never`
  in `server/`: **275 passed, 1,937 assertions**, no skips (before the two new
  notice-year regression cases were added).
- Full Pest on SQLite: **258 passed, 17 PostgreSQL-only skips, 1,840 assertions**.
- Final notice-year suite on both SQLite and PostgreSQL: `php vendor/bin/pest
  tests/Feature/ManagementYearTest.php --compact --colors=never`: **11 passed,
  114 assertions**.
- `php vendor/bin/pint --test`: **passed**.
- `npm run client:test`: **173 passed in 17 files**.
- `npm run client:lint`, `npm run client:typecheck`: **passed**.
- `npm run conventions:test`: **8 passed**.
- Production Docker fixture build, PWA compilation, fresh migrations and browser
  seed (including historical Management years): **passed**.
- `npm --prefix client run test:e2e -- configuration.spec.ts`: **2 passed**,
  desktop and mobile, after the notice-year fix.
- Initial full `npm run client:test:e2e`: **44 passed, 2 failed**. Both failures
  were the obsolete empty-next-year assumption in `management-years.spec.ts`,
  corrected as described below. All **26** tests outside the configuration
  group passed, including the Milestone 2 offline/conflict/security gate.
- Final `npm run client:test:configuration`: **19 passed, 1 artifact-write error**.
  The corrected year-navigation tests passed on both layouts. The desktop
  preference test completed all behavior assertions, then Windows returned
  `UNKNOWN` while writing its final screenshot. Disk space and file write access
  were verified, generated screenshots restored, and the fixture reset.
  `npm --prefix client run test:e2e -- preferences.spec.ts --project=desktop` then
  **passed (1 test)**, including screenshot capture. All 20 configuration cases
  therefore have passing final evidence; no behavior assertion was suppressed.
  The combined configuration report is retained locally in the ignored
  `client/playwright-report/rush-021-configuration/` directory.
- Local `artisan:help -- migrate`, `server:queue:monitor -- --help` and development
  `docker:dev:help -- optimize:clear`: **passed**, with help-only invocations.
- Help-only checks for all nine additional local Artisan commands: **passed**.

Initial sandboxed Pest/Vitest attempts could not read dependency/temporary files;
the same suites passed with normal filesystem access. The first browser attempt
omitted the event target type in the test; the corrected test then exposed the
notice-year defect above. The full suite also exposed a test assumption that next
year would always be empty. The year-navigation test now verifies future-year
creation through the menu with the selected year preserved, for both empty and
populated years. Failures were investigated and fixed, not retried away.

## CI failure follow-up

PR #22's initial CI run [38500286263](https://github.com/KenEucker/rush/actions/runs/38500286263)
finished with **45 browser tests passed, 1 failed**. The mobile configuration
journey reloaded immediately after clicking Save preferences, before observing
the durable pending write. Its trace starts reload less than one millisecond
after the click completes; the failure snapshot contains only the availability
command and the previously synced default preference. The test now waits for
the pending weekly record, captures its ID, and checks that exact record's
pending status and content after reload. Retries remain zero; no fixed sleep,
mock or weaker assertion was added.

Review of the last five PRs found different failure causes, rather than one
shared CI outage:

- #21: duplicated navigation links made broad locators ambiguous (run
  `38464791353`), then changed headings broke old assertions (`38492864679`).
  Those fixes were already present; final run `38494850861` passed.
- #19: development-stack checks failed during live-development setup and worker
  retirement. The navigation-race fix and its evidence are in `docs/testing.md`;
  final run `38451646850` passed.
- #18 and #20: their available branch CI runs `38430461296` and `38446715046`
  passed.

The earlier local focused reruns did not establish that the final full suite or
PR CI was green. `docs/testing.md` now explicitly requires both a fresh full
browser run and checking CI on the pushed commit before reporting verification.
Its fixture instructions now include the historical-year seed already used by CI.

Follow-up validation after the fix:

- Fresh production-PWA Docker build, migrations, normal seed and historical-year
  seed: **passed**.
- `npm run client:test:e2e`: **46 passed in 8.7 minutes**, desktop and mobile,
  with zero retries, in one complete run after the final test change.
- `npm run client:lint`, `npm run client:typecheck`: **passed**.
- `npm run conventions:test`: **8 passed** with normal filesystem access (the
  initial sandbox run could not create its temporary Git repository).
- `git diff --check`: **passed**.

## Operations and remaining gates

The root package adds development-Compose migration/status/rollback/seed and cache
helpers, plus local/Docker environment, development-server, Tinker and queue
maintenance aliases. Generic `artisan`, `docker:artisan` and `docker:dev:artisan`
continue to run any Artisan command with forwarded arguments. No helper adds
`--force`. See the root README for usage and commands that remove data or jobs.

`npm run client:test:configuration` selects all Milestone 3 browser suites;
the normal full E2E command and CI discover the new journey automatically.
Reproduce against the disposable seeded fixture in `docs/testing.md`. Reset that
fixture before repeating the complete journey so earlier Ranger reports do not
affect its event. No production or normal development database was changed.
Human review, required CI and merge remain necessary for task completion.
