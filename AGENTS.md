# RUSH Agent Instructions

RUSH (Ranger Unified Scheduling & Hours) is an installable, offline-capable Ranger scheduling and hours web application. This file applies to the entire repository and to every coding agent working on it.

## Sources of truth

Read these documents before implementing a feature:

1. `docs/RUSH_V1_Requirements.md` — authoritative functional requirements R-01 through R-38, V1 exclusions, and nine acceptance scenarios.
2. `docs/RUSH_V1_Technical_Specification.md` — authoritative architecture, technology choices, offline contract, and technical acceptance criteria.
3. `docs/RUSH_V1_Implementation_Plan.md` — execution roadmap, tasks RUSH-001 through RUSH-049, requirement traceability, Git conventions, milestone gates, and release procedure.

If a document is not at its stated path, locate it before proceeding; do not invent its contents. Functional requirements and technical constraints take precedence over task summaries. When sources disagree, flag the conflict and obtain a decision before implementing affected behavior. Keep requirement-to-task-to-test traceability current.

## Fixed stack and terminology

- **Server:** Laravel, Orchid administration, PostgreSQL, Sanctum sessions, Laravel queues/scheduler, Pest.
- **Client:** Vue 3, TypeScript, Composition API (`<script setup lang="ts">`), Quasar CLI with Vite, Pinia, Quasar QCalendar/QDate/QTime, Vitest.
- **Offline:** Dexie.js + IndexedDB for durable Client data; Quasar PWA mode/Workbox for application-shell caching.
- **End-to-end:** Playwright, including production-built PWA offline tests.
- **Hosting:** Docker Compose and Caddy, with Client, `/admin`, and `/api/v1/*` served from one origin. Keep PostgreSQL private.
- **UI:** Quasar is the sole general-purpose Client component library. No second UI framework.
- **Cost:** No required paid services, commercial sync subscriptions, or proprietary components.

Use current compatible stable dependencies at project initialization; commit lockfiles and pin production images. Do not replace agreed technologies or add paid dependencies without approval. Keep a future Capacitor build possible through browser-capability adapters; V1 is a PWA.

## Architecture boundaries

- Laravel is authoritative for permissions, validation, scheduling, publication, approvals, hours, and exports.
- Orchid and Client must invoke the same Server domain services and policies. Keep business rules out of controllers and Vue components.
- Keep domains cohesive: identity, season/phase configuration, availability/preferences, planning, published scheduling, events, changes, shift activity, timesheets, notifications, management coverage, and exports.
- Infer normalized data models and typed API contracts from the specifications as each vertical slice is built. Maintain migrations, constraints, and tests; avoid an unnecessary up-front exhaustive schema/API document.
- Use UUIDs for syncable records, explicit state transitions, database transactions for multi-record changes, and audit history for sensitive operations.
- Preserve the distinction between drafts and published assignments, and between Ready/Finished activity and payable hours.
- Store authoritative instants in UTC and interpret calendar dates/weeks using the configured season time zone. Test overnight and DST boundaries.

## Offline-first contract — mandatory

**Dexie is the only persistent Client operational data layer.** Do not substitute RxDB, Dexie Cloud, localStorage caches, ad-hoc IndexedDB wrappers, or independent per-feature mutation queues.

- All durable Client reads/writes go through typed repositories/composables backed by Dexie. Pinia holds transient UI/session state.
- Persist a pending command and its local pending representation atomically in a Dexie transaction before reporting local success.
- A centralized sync coordinator pushes queued operations and pulls authorized Server changes using stable revisions/checkpoints. Do not treat device timestamps as authoritative ordering.
- Every command has a unique operation ID; Server processing is idempotent and revalidates permissions, business rules, and expected record revisions.
- Handle retry/backoff, pagination, duplicate delivery, interruptions, invalid checkpoints, deletions, revoked access, and multi-tab usage.
- Preserve rejected/conflicting user intent and display actionable states: pending, syncing, synced, failed, rejected/conflict.
- Never silently overwrite Server-authoritative schedules, approvals, publication, or finalized hours. Offline submission is *pending* until Server acceptance.
- Workbox caches app assets, not a second writable source of authenticated domain data.
- Scope cached sensitive data by account/organization; do not store session secrets in Dexie. Protect pending changes during logout/account switching.
- Core correctness must work with foreground sync; do not rely on browser Background Sync support.

Offline policies: viewing cached schedules, updating own availability/preferences, Ready/Finished activity, timesheet corrections, and queued submissions/requests work offline as specified. Schedule generation/publication, official approvals, hours finalization, exports, and Orchid administration require the Server.

**Do not implement another workflow until the initial offline write → reload → reconnect → reconcile flow has passed automated testing, including one conflicting Server-side update.**

## UI conventions

- Favor clear mobile-first views, named actions, accessible Quasar controls, and simple navigation over complex interactions.
- Use QCalendar for scheduling views and QDate/QTime for date/time entry. Do not add FullCalendar or another general UI kit.
- Present local dates with weekdays and clear AM/PM times; show overnight ranges explicitly.
- Show offline state, last sync, pending changes, and conflicts. Never imply a queued operation is officially accepted.
- Avoid hover-only, drag-only, or ambiguous gestures for consequential actions.
- Signal sharing is manual via Web Share API with clipboard fallback; do not automatically send messages.

## Security, reliability, and deployment

- Enforce Server-side authorization on reads and mutations; V1 roles are Ranger and Management.
- Use same-origin Sanctum cookie sessions, CSRF protection, HTTPS, rate limits, and least-privilege secrets.
- Rangers may see published team assignments but not other Rangers' private availability/preferences or individually identified fairness data.
- Keep integration credentials on the Server. Preserve export profile versions, delivery results, source-record linkage, and audit history.
- Production: only Caddy is publicly exposed; PostgreSQL is private, persistent, and backed up. Document restores, upgrades, health checks, and rollback considerations.
- No recurring events, auto Signal messaging, payroll, native app-store builds, or other V1 exclusions unless the requirements change explicitly.

## Implementation plan and task selection

Use `docs/RUSH_V1_Implementation_Plan.md` as the authoritative **execution plan**. V1 comprises **seven sequential milestones, each containing seven development tasks (49 total)**:

| Milestone | Tasks | Outcome |
| --- | --- | --- |
| 1. Foundation | RUSH-001–007 | Working repository, authentication, baseline Server/Client/PWA and development tooling |
| 2. Offline infrastructure | RUSH-008–014 | Dexie, centralized sync, conflicts, and proven offline availability flow |
| 3. Scheduling configuration | RUSH-015–021 | Seasons, phases, coverage, Ranger availability/preferences, and events |
| 4. Schedule generation | RUSH-022–028 | Explainable fairness, proposals, gap handling, review, and publication |
| 5. Published schedule operations | RUSH-029–035 | Team schedules, changes, transfers/swaps, approvals, and notifications |
| 6. Hours and timesheets | RUSH-036–042 | Ready/Finished, weekly review, corrections, finalization, and management gap coverage |
| 7. Reports and release | RUSH-043–049 | Summaries, export profiles/delivery/audit, production hardening, and V1 acceptance |

- Start work from a specific `RUSH-###` task, and read its outcome, acceptance criteria, requirements mapping, and dependencies before changing code.
- Complete prerequisite tasks and observe milestone gates. In particular, finish the Milestone 2 offline availability proof before expanding into additional durable Client workflows.
- Work in complete vertical slices. One task normally maps to one branch and one pull request; infrastructure tasks may follow the boundaries defined by the plan.
- Do not treat a milestone as a single change, implement later-milestone features opportunistically, or expand V1 without an explicit approved change to the finite plan.
- Before a task with an unresolved product rule, resolve and document the relevant bounded decision from Implementation Plan §11. An agent must not independently decide scheduling fairness policy, employment rules, or access visibility.
- Keep the plan's requirements traceability and acceptance scenario ownership current. Every R-01 through R-38 requirement and all nine acceptance scenarios must have implementation and test evidence.

## Git commits, branches, and pull requests

Follow Implementation Plan §2. These rules apply to human and AI contributors:

- Keep `main` deployable and protected. Use one task branch such as `feat/RUSH-023-draft-publication` and normally one PR per task.
- Group commits by **coherent behavior**, including the tests for that behavior; avoid splitting commits mechanically by Server/Client/database file location.
- Use Conventional Commits with an imperative summary and a stable domain scope: `feat(activity): record ready action without altering paid hours`.
- Each commit must use this format:

  ```text
  <type>(<scope>): <imperative summary>

  <Why the change is necessary and key decisions>

  Refs: RUSH-###
  Requirements: R-##[, R-##]
  Tests: <actual commands run and pass/fail/skip results>
  ```

- Supported types: `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `ci`, `perf`. For breaking changes, use `!` and a `BREAKING CHANGE:` footer.
- Make small, reviewable working commits as needed. Squash merge the completed task to a single Conventional Commit on `main`.
- PR descriptions must include the task and requirement IDs, behavior, implementation decisions, database/sync changes, tests and real results, screenshots for material UI changes, limitations, and migration notes as applicable.
- Never state that CI or a test passed unless it actually ran and passed. Never commit secrets. Obtain human review and required CI checks before merging.

## How to work on each task

1. Select the assigned `RUSH-###` task from the implementation plan; identify its prerequisites, mapped requirement IDs, and acceptance criteria in the task/PR description.
2. Identify affected domain states, authorization, persistence, offline behavior, and acceptance criteria.
3. Implement one **vertical slice**: migration/domain service/policy → API → Client repositories/UI → sync behavior → tests.
4. Prefer existing Laravel, Vue, and Quasar conventions. Keep abstractions small; do not add dependencies without a specific need.
5. Add or update automated tests for success, failures, permissions, concurrency, and offline behavior where relevant.
6. Run the appropriate available checks; report commands executed, results, and any tests not run. Do not claim tests passed without executing them.
7. Document meaningful model assumptions and architectural choices in `docs/decisions/`, updating the specs if an approved change affects them.

If information is missing, infer only low-risk implementation details consistent with both specifications. Surface material product/architecture ambiguities instead of quietly choosing new behavior. Never silently replace the offline architecture.

## Definition of done

A task is done only when its stated acceptance criteria, Server behavior, Client UX, authorization, appropriate offline persistence/sync, failure states, auditability, tests, and documentation/traceability are complete and its PR has been reviewed and merged. A milestone is done only when all seven constituent tasks pass and the milestone's integrated user journey succeeds. Release `v1.0.0` only after all 49 tasks are complete, R-01 through R-38 are accounted for, all nine functional acceptance scenarios pass, and the technical specification's offline, security, backup/recovery, and deployment checks pass. Refer to the implementation plan for release checkpoints and required evidence.
