# RUSH-017 verification evidence

Requirements: R-09, R-11, R-29. Prerequisites: RUSH-001–016 merged on production;
Milestone 2 offline proof recorded by RUSH-014.

## Executed checks

- Full `php vendor/bin/pest --compact` against isolated PostgreSQL 17.2:
  **183 passed, 1,268 assertions, no skips**. Includes the new independent-process
  competing-manager test and all existing sync/calendar/coverage regressions.
- Final policy regression additions: `php vendor/bin/pest
  tests/Feature/PhaseSchedulingPolicyTest.php
  tests/Feature/PhaseSchedulingPolicyConcurrencyTest.php --compact` against
  PostgreSQL: **30 passed, 205 assertions**, including database constraints,
  stale calendar/coverage revisions and classification preservation on rename.
- Initial SQLite `php vendor/bin/pest tests/Feature/PhaseSchedulingPolicyTest.php
  --compact`: **26 passed, 177 assertions**.
- `php vendor/bin/pint --test`, `composer validate --strict`: **passed**.
- Client `npm run lint:check`, `npm run typecheck`: **passed**.
- Client `npm run test:unit`: **164 passed** across 16 files.
- Root `npm run conventions:test`: **8 passed**. The first sandboxed run could
  not create its temporary Git fixture; the permitted rerun passed.
- `docker compose -p rush-017 -f docker/compose.ci.yaml up -d --build --wait
  --wait-timeout 120`: **passed** using production Server and PWA Dockerfiles.
- Isolated PostgreSQL `artisan migrate --force` → `migrate:rollback --force` →
  `migrate --force` → `db:seed --force`: **passed**.
- `npx playwright test --config playwright.integration.config.ts
  phase-policies.spec.ts`: **2 passed**, desktop and mobile.
- Full Client `npm run test:e2e` against the rebuilt production stack:
  **36 passed** in 2.7 minutes, including both desktop/mobile policy forms,
  offline browser restart, reconnect, actual Management assignment conflicts,
  multi-tab/account security and two-device reconciliation.
- Root `npm run artisan -- list --raw`, `npm run server:migrate -- --help`,
  `npm run server:optimize:clear -- --help`: **passed**.
- All **27** root Artisan aliases executed with `--help`: **passed**, confirming
  that each command exists and arguments are forwarded without mutating data.

## Acceptance mapping

| Criterion | Evidence |
| --- | --- |
| Phase-specific hour target/cap and warning configuration | Shared save service, normalized policy table, explicit zero/no-default tests, independent phases, Orchid save/reload |
| Management desirability with approved date/weekday scope | Normalized classifications, unique keys, date → weekday → baseline resolver and removal fallback tests |
| Keep positive personal preferences separate | No preference writes or inference; baseline semantics documented in ADR 0017 and UI |
| Labor-policy dependencies | Owner-approved configuration boundary, ADR/contract and UI; RUSH-022/037/041 retain calculations and worked-hour warnings |
| Authorization | Guest, Ranger, inactive Management, foreign organization and direct-service tests; scoped Orchid GET/POST |
| Validation and atomic history | Invalid values, dates, duplicates, foreign shifts, audit-failure rollback and actor/reason/snapshot checks |
| Concurrent/stale writes | All three expected revisions, PostgreSQL competing managers, browser stale input retention |
| Configuration changes | Coverage/season changes require review; new shifts remain unclassified; removed shift history preserved; current date bounds revalidated |
| Calendar interpretation | Inclusive phase-date bounds, local starting weekday/date, DST transition and overnight shift fixture |
| Mobile administration | Responsive labeled rows, no horizontal overflow, successful form validation/save/reload |
| Offline boundary | Online Orchid only; no Client persistence, sync protocol or Workbox data changes |

Screenshots from successful browser tests, visually inspected:
[desktop](desktop-policies.png), [mobile](mobile-policies.png).

## Migration and limitations

Apply `2026_10_11_210000_create_phase_scheduling_policies` before serving the new
API/UI. It adds policy, classification and audit tables. Existing phases remain
explicitly unconfigured; no defaults or assignment/hour changes are backfilled.
Rollback removes these new tables and their history, so back up before rollback
after entering real policies. No Client migration or sync change is required.

Laravel Boost 2.10.3 and its development dependencies were installed and locked
because the local `server/AGENTS.md` explicitly requires Boost setup. Production
images exclude dev dependencies. No runtime dependency or paid service was added.

Full scenario completion remains with the original nine scenario owners. These
are planning inputs, not a generation engine, warning dashboard, employment-rule
certification or payroll implementation. Human review, required CI and merge
remain necessary before the task meets the project's definition of done.
