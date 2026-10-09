# RUSH — V1 Implementation Plan

**Ranger Unified Scheduling & Hours**  
**Status:** Implementation plan aligned to V1 requirements and technical specification  
**Target release:** `v1.0.0`  
**Source of truth:** `RUSH_V1_Requirements.md` (R-01–R-38, scenarios 1–9) and `RUSH_V1_Technical_Specification.md`.

## 1. Scope and governance

Implement all 38 functional requirements and verify all nine acceptance scenarios. Deliver seven sequential, demonstrable milestones comprising **49 bounded development tasks**. The requirements and technical specification take precedence if wording in this plan conflicts with them. Unresolved design questions listed in Requirements §11 must be decided and documented in the relevant task before implementation; agents must not silently invent those policies. No required paid services or subscriptions.

A task owns a complete, testable vertical slice: relevant domain model and rules, Laravel service/API and authorization, Quasar/Orchid UI, Dexie persistence and offline behavior where applicable, and Pest/Vitest/Playwright tests. Infrastructure tasks are explicitly identified. Avoid a repository-wide backend-first/frontend-later sequencing pattern.

### Repository conventions

- Single repository with `server/` (Laravel, Orchid, Sanctum, PostgreSQL, Pest), `client/` (Vue 3, TypeScript, Quasar, Pinia, QCalendar, Dexie, Vitest), `docker/`, and `docs/`.
- Quasar PWA mode with Workbox; Laravel Server is authoritative, Dexie stores durable client data and queued operations, Pinia holds primarily transient state.
- Laravel queues/scheduler, Caddy reverse proxy, Docker Compose, Playwright E2E.
- UTC authoritative instants, explicit season time zones, UUID synchronized records, revision tracking, strong authorization and audit trails.
- Production PWA build is required to validate offline asset caching.

## 2. Git, commits, and pull requests

### Branching and grouping

- `main` is protected and deployable. One task is one branch and normally one pull request: `feat/RUSH-023-draft-publication`.
- A **commit** expresses one cohesive logical change, including its applicable implementation and tests. Group by behavior rather than by layer or file type.
- A **task** may use several well-scoped working commits. Squash merge to one Conventional Commit on `main` once complete.
- A **milestone** groups seven independently verified tasks and ends with a demonstrated integrated workflow. A milestone is not one giant commit or PR.
- Do not mix unrelated tasks in a commit or PR. If a prerequisite change is required, document it and keep it minimal or complete the prerequisite task first.

### Commit format

```
<type>(<scope>): <imperative summary>

<Why the change is needed and key implementation decisions>

Refs: RUSH-###
Requirements: R-##[, R-##]
Tests: <actual commands and pass/fail/skip results>
```

Types: `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `ci`, `perf`. Suggested scopes: `foundation`, `auth`, `sync`, `season`, `availability`, `coverage`, `scheduler`, `fairness`, `publication`, `transfers`, `activity`, `timesheets`, `exports`, `admin`, `pwa`. Use imperative summaries (ideally ≤72 characters). Breaking changes use `!` and a `BREAKING CHANGE:` footer.

Example:

```
feat(activity): record ready action without altering paid hours

Allow a Ranger to mark Ready within the permitted pre-shift window.
Store the exact activity timestamp independently of scheduled and paid time.

Refs: RUSH-036
Requirements: R-24, R-26
Tests: php artisan test --filter=ReadyActionTest (passed)
```

### Pull request requirements

Each PR contains: task/requirements references; user-visible behavior; key implementation decisions; database or sync changes; test commands and real outcomes; screenshots for material UI changes; risks, known limitations, and migration notes. Never report tests as passed without running them. Require CI on changed surfaces, migration checks, and human review before merge. Do not push secrets. Update requirements traceability as part of the task.

### Definition of done

A task is done only when its stated outcome works through the relevant UI/API, permissions and error cases are tested, offline behavior matches the offline matrix, history/audit is preserved where required, tests pass, docs/traceability are updated, and the PR is merged. A milestone is done when all seven tasks meet this standard and its end-to-end demonstration succeeds. Defects are tracked separately; no new V1 feature may be silently added. Gaps in requirements coverage must be assigned to an existing task or addressed through an explicitly approved change to the finite plan.

## 3. Milestone 1 — Foundation (RUSH-001–007)

**Deliverable:** A reproducible, secure, installable skeleton with authentication, role separation, tested APIs, and running infrastructure.

| Task | Implementation and acceptance | Requirements |
|---|---|---|
| **RUSH-001** Bootstrap monorepo | Set up `server/`, `client/`, Quasar/Vue/TypeScript, Laravel, Orchid, lint and formatting; both apps boot. | Technical §§2–3 |
| **RUSH-002** Compose and routing | Docker Compose, PostgreSQL, Caddy, same-origin `/`, `/admin`, `/api/v1`, HTTPS production routing; assets and SPA fallback work; database inaccessible publicly. | Technical §4 |
| **RUSH-003** Identity and organization model | Ranger/Management roles, organization-scoped membership and profiles; seed a 5–15 Ranger fixture; no qualifications/seniority/eligibility roles. | R-01–R-38 (cross-cutting) |
| **RUSH-004** Session authentication and policies | Sanctum cookie sessions, CSRF, role/record policies, sign-out, read/write authorization and account isolation; denied tests. | Technical §8 |
| **RUSH-005** UI and administration shell | Quasar navigation for Ranger and Management, accessibility conventions, Orchid entry, responsive layout, QCalendar/QDate/QTime baseline. | Technical §§3, 9 |
| **RUSH-006** Domain and contract conventions | Document entity/revision/state conventions, typed API boundaries, standardized validation/error and audit patterns; prove through one vertical endpoint. | Technical §§5, 14 |
| **RUSH-007** CI and E2E foundation | Pest, Vitest, Playwright, build/lint/type checks, PR/commit linting; CI executes from clean checkout and tests basic login and authorization. | Technical §13 |

**Milestone demonstration:** Start from a clean checkout, authenticate as each role, inspect authorization differences, and open the Ranger Client and Orchid through the intended routes.

### RUSH-004 implementation evidence

RUSH-001–003 are prerequisites. RUSH-004 implements Technical §8 (cross-cutting
authorization support for R-01–R-38): Sanctum same-origin sessions, CSRF,
sign-out, current-membership policies, and account-isolated identity/profile
reads and writes. Decisions and endpoint contracts are in
[`0004-session-authentication-and-policies.md`](decisions/0004-session-authentication-and-policies.md).
Evidence: `server/tests/Feature/SessionAuthenticationTest.php`,
`server/tests/Feature/IdentityAuthorizationTest.php`,
`client/src/stores/session.test.ts`, and
[`RUSH-004 browser evidence`](evidence/RUSH-004/README.md).
Implementation is ready for review; task completion still requires PR review and merge.
The nine functional scenarios retain their later-task owners below; durable offline
account security remains RUSH-008/013.

### RUSH-005 implementation evidence

RUSH-001–004 are merged prerequisites. RUSH-005 provides the responsive Quasar
Ranger/Management shell, accessible navigation, existing policy-protected Orchid
entry, and a transient QCalendar/QDate/QTime preview (Technical §§3, 9;
cross-cutting presentation support for R-01–R-38).
See [ADR 0005](decisions/0005-ui-administration-shell.md),
[verification and screenshots](evidence/RUSH-005/README.md),
the Client's e2e/shell.spec.ts and src/ui/calendarPreview.test.ts, and the existing
Server IdentityAuthorizationTest.php / SessionAuthenticationTest.php.
No operational workflow or acceptance scenario is claimed complete; the nine
scenario owners below and the Milestone 2 offline gate are unchanged.
Implementation completion still requires PR review and merge.

### RUSH-006 implementation evidence

RUSH-001–005 are merged prerequisites on `production`. RUSH-006 documents entity,
revision, state-transition, typed API, validation/error and audit conventions
(Technical §§5, 14; cross-cutting support for R-01–R-38). The existing owner-only
profile endpoint proves revision-checked transactional changes, actor-attributed
audit history, shared service authorization/validation, explicit resources and
typed Client transport. See [ADR 0006](decisions/0006-domain-contract-conventions.md),
[profile contract](contracts/member-profile.md), and
[verification evidence](evidence/RUSH-006/README.md).
Tests: `server/tests/Feature/ProfileContractTest.php`, existing identity/session
regressions, and `client/src/data/api/profiles.test.ts` with a shared contract example.
There is no new durable Client workflow; RUSH-008–014 retain the offline gate and
all nine functional scenarios retain their owners below. Review and merge are
still required for task completion.

### RUSH-007 implementation evidence

RUSH-001–006 are merged prerequisites on `production`. RUSH-007 implements
Technical §13 through clean-checkout GitHub Actions jobs for Pest/PostgreSQL,
migration rollback/reapplication, Vitest, lint/type checks, SPA/PWA builds,
Playwright shell and real-session integration tests, and PR/commit conventions.
The full-stack suite authenticates both roles through Caddy, checks Orchid and
private profile authorization, rejects missing CSRF, and verifies logout/account
isolation. This provides cross-cutting regression support for R-01–R-38 without
claiming their operational behavior complete. See [ADR 0007](decisions/0007-ci-and-e2e-foundation.md),
[test reproduction](testing.md), and [verification evidence](evidence/RUSH-007/README.md).
No nine-scenario owner changes; the Milestone 2 offline gate remains mandatory.
Task and Milestone 1 completion still require human review and merge.

## 4. Milestone 2 — Offline infrastructure (RUSH-008–014)

**Deliverable:** The initial *offline unavailability proof* from Technical §14 succeeds before the rest of the scheduling Client is built.

| Task | Implementation and acceptance | Requirements |
|---|---|---|
| **RUSH-008** Dexie schema and repositories | Versioned, account/organization-partitioned Dexie stores, typed repositories, cache metadata, migration tests and IndexedDB failure handling. | Technical §7 |
| **RUSH-009** Sync operations and protocol | Stable operation IDs, Server revisions/checkpoints, push/pull contracts, validation, idempotent commands, transactional application and replay tests. | Technical §7 |
| **RUSH-010** Central sync coordinator | Queue writes before local acknowledgment, push/pull, retries, resumed synchronization, connectivity checks, persisted failure handling; no per-component queues. | Technical §7 |
| **RUSH-011** PWA installation and offline shell | Workbox precaches assets, cached startup works offline from a production-built PWA; mutable authenticated data stays in Dexie. | Technical §§7, 13 |
| **RUSH-012** Availability proof slice | Minimal Ranger unavailability editor, cached read, offline mutation, reload and reconnect, Server validation; a Ranger’s unavailability survives an offline restart. | R-07 (foundational slice) |
| **RUSH-013** Conflict and account-security proof | Manager-side assignment change conflicts with offline unavailability; preserve Ranger intent, flag conflicting official assignment, explain rejected operations; test role loss, logout, expired sessions and account switching. | R-07, R-08; Technical §§7–8 |
| **RUSH-014** Sync observability and regression suite | Global/per-record synced/pending/failed/conflict states, last-sync time and recovery; test duplicate requests, interruptions and two-device changes. | Technical §§7, 13 |

**Milestone demonstration:** Ranger syncs → disconnects → enters unavailability → closes/reopens app offline → manager changes a conflicting assignment → Ranger reconnects → system preserves intent and displays the conflict without silently rewriting the official assignment.

### RUSH-008 implementation evidence

RUSH-001–007 are merged prerequisites on `production`. RUSH-008 implements
Technical §7's storage foundation: versioned, account/organization-partitioned
Dexie stores, typed repositories, separate confirmed records and pending intent,
atomic local writes, cache metadata/checkpoints, protected cache removal and
actionable IndexedDB failures. See [ADR 0008](decisions/0008-dexie-storage-foundation.md)
for the bounded local retention/privacy decision, schema and migration contract.
Tests: `client/src/data/database/database.test.ts` and
`client/src/data/repositories/accountStorage.test.ts`; results are recorded in
[verification evidence](evidence/RUSH-008/README.md).
This provides cross-cutting support for R-01–R-38 without enabling a new workflow.
RUSH-009–014 retain protocol, coordinator, PWA, availability and account-security
integration ownership; the offline proof and all nine scenario owners are unchanged.
Task completion still requires human review and merge.

### RUSH-009 implementation evidence

RUSH-001–008 are merged prerequisites on `production`. RUSH-009 implements
Technical §7 protocol support: stable operation UUIDs, typed push/pull contracts,
current Server authorization/validation, atomic idempotency receipts and domain
audit, revision conflicts, account-scoped sequenced pull pages, tombstones and
checkpoint recovery. The existing member profile is the infrastructure proof;
no new operational workflow or functional scenario is enabled. See
[ADR 0009](decisions/0009-sync-operations-and-protocol.md),
[wire contract](contracts/sync.md), and [test evidence](evidence/RUSH-009/README.md).
Pest includes real PostgreSQL concurrent replay/competing-writer tests; Vitest
proves durable command reconstruction and transport validation; Playwright checks
real-session sync authorization, CSRF, replay and pull. This is cross-cutting
support for R-01–R-38. RUSH-010–014 retain coordinator, Client reconciliation,
availability/account-security integration and the mandatory offline proof.
All nine scenario owners are unchanged; human review and merge remain required.

### RUSH-010 implementation evidence

RUSH-001–009 are merged prerequisites on production. RUSH-010 implements the
Technical §7 central Client coordinator: durable acknowledgment, stable-ID push,
transactional checkpointed pull, persisted failure/backoff and session pauses,
interrupted-work recovery, foreground connectivity checks and cross-tab exclusion.
Dexie v2 preserves v1 intent and adds coordinator state. See
[ADR 0010](decisions/0010-central-sync-coordinator.md) and
[verification evidence](evidence/RUSH-010/README.md). Vitest covers migration,
transaction rollback, retries, cancellation and reconciliation. Playwright runs
the real coordinator against Sanctum/PostgreSQL with native Web Locks and a real
revision conflict. This is cross-cutting infrastructure support for R-01–R-38;
all nine acceptance scenario owners are unchanged. RUSH-011–014 retain PWA startup,
availability, account-security UX, observability and the mandatory offline gate.
Human review and merge remain required for task completion.

## 5. Milestone 3 — Scheduling configuration (RUSH-015–021)

**Deliverable:** Management can configure every season scheduling input, while Rangers maintain default and weekly availability/preferences.

| Task | Implementation and acceptance | Requirements |
|---|---|---|
| **RUSH-015** Seasons, phases, calendar policy | Create seasons and dated phases, explicit time zone, week boundaries, phase changes, tested overnight/DST handling. Resolve Requirements §11 date/time policy. | R-01, R-11 |
| **RUSH-016** Shifts, areas and phase coverage | Configurable shift starts/durations, coverage areas, dedicated *or* shared model per phase, staffing counts and partial manual assignment representation; prohibit mixing models. | R-02, R-03 |
| **RUSH-017** Scheduling policies | Phase-specific hour targets/planned caps/overtime warnings and shift desirability baseline; document exact classification scope and labor-policy validation dependencies. | R-09, R-11, R-29 |
| **RUSH-018** Complete unavailability workflow | Extend proof slice: create/edit time spans online/offline; Management edits on behalf with audit; notices and acknowledgments, affected-assignment/gap flags; acknowledge does not repair schedule. | R-07, R-08 |
| **RUSH-019** Ranger preference profiles | Default profile, full-week overrides, preferred shifts, weekly desired hours, consecutive/separated days-off preference; return to default next week; editable offline. Resolve override semantics. | R-09, R-10 |
| **RUSH-020** One-off events and paid attendance | Dated, nonrecurring events, paid attendance assignments, number/percentage targets, choice whether scheduled shifts count, independent coverage/event evaluation. Resolve event-counting rule. | R-06 |
| **RUSH-021** Configuration UI and tests | End-to-end phase/coverage/availability/preferences/event configuration with management and Ranger interfaces, permissions, validation, and sync tests. | R-01–R-11 |

**Milestone demonstration:** Configure a low-staff phase and a 24/7 phase with distinct models, Ranger availability and preference overrides, and a single Monday Meeting without recurrence.

## 6. Milestone 4 — Schedule generation (RUSH-022–028)

**Deliverable:** The deterministic planning engine produces inspectable, editable drafts and publishes correctly, including when coverage is incomplete.

| Task | Implementation and acceptance | Requirements |
|---|---|---|
| **RUSH-022** Constraint specification and scheduling inputs | Implement testable hard, overridable and preference constraints; planning arbitrary ranges, events, weekly limits, published commitments and input revision snapshots. Record decisions on precedence and infeasibility. | R-12, R-13 |
| **RUSH-023** Generation and editable drafts | Generate whole-shift assignments and event assignments as proposals only; managers edit or make partial assignments; never overwrite published records. | R-02, R-06, R-12, R-17 |
| **RUSH-024** Fairness and explainability | Weekly-first scoring with season secondary; desirability, relative hours, difficult-shift rotation, weekday/social-time rotation and days-off preferences; deterministic fixtures and factor explanations. | R-09, R-13, R-14 |
| **RUSH-025** Coverage gaps and recommendations | Track draft/published missing positions independently of event targets; identify conflicts, infeasible requirements, manager-only suggestions to adjust coverage. | R-03, R-04, R-05 |
| **RUSH-026** Fairness visibility | Ranger personal fairness plus anonymous context-aware team averages; Management identified breakdowns by Ranger/week/season; never expose individual preferences to peers. | R-15 |
| **RUSH-027** Draft release and staleness | Released-draft review, notification, Ranger weekly preference edits, input revision staleness flags, explicit manager edits; no formal dispute workflow. Decide team-draft visibility. | R-10, R-16, R-37 |
| **RUSH-028** Publication and reminders | Weekly/multiweek release blocks, automatic configured publication using idempotent job, manual early publication, publish despite conspicuous gaps/conflicts, management reminders, notifications and immutable assignment history. | R-17, R-18, R-19, R-37 |

**Milestone demonstration:** Generate low-staff and 24/7 plans, show historical Monday-evening fairness tradeoffs, review released draft, modify preferences to mark it stale, and publish on time despite gaps (scenarios 1–4).

## 7. Milestone 5 — Published schedule operations (RUSH-029–035)

**Deliverable:** Rangers can view official team schedules, request mutually agreed changes, and share messages manually; management retains control over official assignments.

| Task | Implementation and acceptance | Requirements |
|---|---|---|
| **RUSH-029** Ranger calendars and visibility | Mobile personal/team schedule, official assignment detail, dates/times/area context; protect drafts, detailed unavailability and others’ preference data. | R-21 |
| **RUSH-030** Management published changes | Manager edits/partial reassignment and emergency changes with conflict flags, preserved history, audited actor/reasons and affected-Ranger notifications. | R-23, R-37 |
| **RUSH-031** Replacement suggestions | Ranked replacement candidates from availability, conflicts, hours, preferences and fairness; management alone applies a replacement. | R-20 |
| **RUSH-032** Transfer and swap requests | Ranger-initiated one-way transfers and two-way exchanges, participant agreement records, cancellation/rejection states; pending intent never edits official schedule; offline queue. | R-22 |
| **RUSH-033** Change approval and revision checks | Management approves/rejects fully agreed transfers; recheck conflicts and stale published assignment revisions transactionally; audit changes and notify. | R-22, R-23, R-37 |
| **RUSH-034** In-app notification center | Read/unread and context links for draft release, publication, changes, transfer actions, availability acknowledgment, exceptions and management reminders; no email/SMS/push. | R-07, R-16–R-19, R-22, R-23, R-37 |
| **RUSH-035** Share-sheet and schedule E2E | Prepare scheduling/LOG messages using Web Share API, clipboard fallback; user selects Signal/group and sends manually; test full swap/transfer and emergency changes. | R-38; scenarios 4–5 |

**Milestone demonstration:** A Ranger views full published team schedule, proposes a transfer, recipient agrees, management approves, and only then the official assignment changes; Ranger manually shares prepared LOG text.

## 8. Milestone 6 — Hours, timesheets and management coverage (RUSH-036–042)

**Deliverable:** Ready/Finished activity is independent of pay, Rangers submit weekly timesheets, management resolves exceptions, and gap coverage is accounted for.

| Task | Implementation and acceptance | Requirements |
|---|---|---|
| **RUSH-036** Ready/Finished actions | Ready within 30 minutes pre-shift, exact timestamp; Finished after shift with bounded convenience UI; offline queue; neither activity automatically changes payable hours. Resolve proposed Finished window detail. | R-24, R-25, R-26 |
| **RUSH-037** Worked hours and correction ledger | Scheduled shifts plus paid event assignments default to reported hours; early/late/missing time corrections offline; auditable timestamps and actor/reasons; limit warnings without blocking legitimate time. | R-06, R-26, R-29 |
| **RUSH-038** Weekly timesheet review | Ranger weekly ledger with Open/Submitted/Needs Review/Finalized; full-week submission once, configurable deadlines, offline pending submission, edit/resubmit policy documented. | R-27 |
| **RUSH-039** Exception review and finalization | Matching submissions auto-finalize where eligible; corrected timesheets require management resolution; Management finalizes on Ranger behalf, preserving distinct actor and approval history. | R-27, R-28 |
| **RUSH-040** Management gap responsibility | Persist published unfilled position-hours; record partial/full actual management coverage and uncovered intervals; no named-manager requirement or mixing with Ranger payable hours. | R-04, R-30 |
| **RUSH-041** Hour and gap summaries | Ranger personal scheduled/submitted/finalized totals; Management weekly/phase/season worked hours, exceptions, overtime warnings and historical covered/uncovered gap breakdowns. | R-29, R-30, R-32 |
| **RUSH-042** Timesheet and coverage E2E | Test early Ready, late Finished, corrected work, automatic and manager finalization, optional simple transport notation if approved, Monday Meeting, and partial gap coverage. | R-24–R-31; scenarios 6–8 |

**Milestone demonstration:** Ready 22 minutes early and Finished late leaves scheduled payable hours unchanged; correction enters review; Ranger confirms week; management records four of eight previously uncovered position-hours.

## 9. Milestone 7 — Reports, export and V1 release (RUSH-043–049)

**Deliverable:** Complete reporting/export features and a validated self-hosted `v1.0.0` with every acceptance scenario passing.

| Task | Implementation and acceptance | Requirements |
|---|---|---|
| **RUSH-043** Reporting and audit views | Consolidated Management reporting by week/phase/season for hours, timesheet statuses, gaps, management coverage, fairness; enforce access and history. | R-15, R-30, R-32 |
| **RUSH-044** Versioned export profiles | Management-configurable selected fields, output order/labels, structure, versioning, reusable file/webhook mapping, accurate preview with pending exceptions clearly labeled. | R-34, R-36 |
| **RUSH-045** CSV/JSON download | Management date-range selection (week/phase/custom), correct scheduled/submitted/finalized representation, both formats, profile mapping and tests for dates/overnight work. | R-33, R-34 |
| **RUSH-046** Manual HTTPS delivery | Authenticated HTTPS POST to configured endpoint, manual retry, delivery status and errors, safe credential handling; no automatic/scheduled export. | R-35 |
| **RUSH-047** Export history and reconciliation | Snapshot records/versions/profile used, export/delivery audit, distinguish later corrections and unresolved hours; test downloaded and transmitted datasets match preview. | R-36; scenario 9 |
| **RUSH-048** Production hardening | Verify security/privacy, account cache handling, migrations, health checks, logs, automated backups, restore, low-cost Compose deployment, accessibility, performance and production PWA offline E2E. | Technical §§4, 7–9, 12–13 |
| **RUSH-049** Acceptance and tagged release | Reconcile R-01–R-38 with tests; execute all nine acceptance scenarios, close critical defects, finalize Ranger/Management/deployment docs, changelog, tagged `v1.0.0` release and smoke tests. | All R-01–R-38; scenarios 1–9 |

**Milestone demonstration:** Preview and download an approved hours dataset; manually send the same mapped dataset to a test HTTPS endpoint with credentials; inspect audit history; restore a backup; run all nine acceptance scenarios on a production-built deployable application.

## 10. Requirements traceability

The following is the authoritative task assignment index. Several requirements span tasks because their behavior crosses domains.

| Requirement | Primary task(s) |
|---|---|
| R-01 Season and phases | 015, 021 |
| R-02 Configurable shifts | 016, 023 |
| R-03 Coverage model | 016, 025 |
| R-04 Gaps | 025, 040 |
| R-05 Shortage recommendations | 025 |
| R-06 Events and meetings | 020, 023, 037 |
| R-07 Unavailability | 012, 018 |
| R-08 Emergency changes | 018, 030 |
| R-09 Positive preferences | 017, 019, 024 |
| R-10 Persistent/weekly preferences | 019, 027 |
| R-11 Weekly hour policies | 015, 017 |
| R-12 Assisted generation | 022, 023 |
| R-13 Priorities | 022, 024 |
| R-14 Fairness rotation | 024 |
| R-15 Fairness visibility | 026, 043 |
| R-16 Draft release | 027 |
| R-17 Rolling publication | 028 |
| R-18 Publish despite conflicts | 028 |
| R-19 Manager reminders | 028, 034 |
| R-20 Replacement suggestions | 031 |
| R-21 Team visibility | 029 |
| R-22 Swaps/transfers | 032, 033 |
| R-23 Management changes | 030, 033 |
| R-24 Ready | 036 |
| R-25 Finished | 036 |
| R-26 Scheduled-hours default | 037 |
| R-27 Weekly submission | 038, 039 |
| R-28 Management finalization | 039 |
| R-29 Overtime awareness | 017, 037, 041 |
| R-30 Management gap coverage | 040, 041 |
| R-31 Transport notation | 042 (optional; exclude unless approved) |
| R-32 Views/summaries | 041, 043 |
| R-33 Download | 045 |
| R-34 Export profiles | 044, 045 |
| R-35 Manual API delivery | 046 |
| R-36 Export audit | 044, 047 |
| R-37 In-app notifications | 027, 028, 030, 033, 034 |
| R-38 Manual sharing | 035 |

### Mandatory acceptance scenario ownership

| Scenario | Principal tasks |
|---|---|
| 1. Low-staff phase | 015–016, 022–025, 028 |
| 2. 24/7 phase | 015–016, 022–025 |
| 3. Fairness | 024, 026 |
| 4. Unavailable Ranger after draft release | 018, 027–028, 031 |
| 5. Swap or transfer | 032–035 |
| 6. One-off Monday Meeting | 020, 037–042 |
| 7. Ready/Finished and weekly pay | 036–039, 042 |
| 8. Management partial gap coverage | 040–042 |
| 9. Export preview/file/manual POST | 044–047 |

## 11. Bounded design decisions and stop conditions

Resolve and document these **before** completing their owner task. They remain open in Requirements §11:

1. `RUSH-022/024`: precise constraint hierarchy, fairness tradeoffs, rest rules and infeasible cases.
2. `RUSH-017`: baseline desirability definition and whether it varies by phase, date or weekday.
3. `RUSH-015/037`: week boundaries, season time zones, overnight allocation, employment/meeting/overtime rules before payroll use.
4. `RUSH-019`: whether a weekly override replaces the entire default preference profile (suggested) or supports partial inheritance.
5. `RUSH-027`: released team-draft visibility for Rangers.
6. `RUSH-020`: whether cross-area scheduled shifts count toward percentage event attendance targets.
7. `RUSH-036`: exact Finished convenience window; it may never gate later accurate hours reporting.
8. `RUSH-004/008/044/046/048`: retention, privacy, authentication details, integration credential storage, export schema choices; technical specification already fixes Sanctum, hosting, and offline mechanisms.
9. `RUSH-022/024`: structured scheduling intent is sufficient in V1; free-text intent is optional only if explicitly approved.

No automatic Signal messaging, recurring events, recurring export pushes, payroll, shift bidding, dispatch, GPS, shift clock-based payroll calculation, or automatic official assignment transfers may be added under this plan.

## 12. Milestone releases and agent execution

Suggested integration tags: after M2 `v1.0.0-alpha.1`, M4 `v1.0.0-alpha.2`, M6 `v1.0.0-beta.1`, M7 verification `v1.0.0-rc.1`, then `v1.0.0`. Tags indicate tested integration points; individual milestones do not require a formal distribution release.

**Agent instruction for each task:** Read both source specifications, `AGENTS.md`, this plan, and neighboring code. State the entities/invariants and relevant open decisions first. Implement one complete vertical slice with tests, including offline requirements where applicable. Report tests actually run, update traceability and docs, and submit a single PR referencing the task and R-numbers. Do not resolve open product decisions through undocumented assumptions.

**V1 exit gate:** every R-01–R-38 mapped and verified; all nine user acceptance scenarios pass; installed PWA functions offline and reconciles conflicts safely; schedules/fairness remain explainable; published schedules and finalized hours retain audit histories; role boundaries enforced; manual export delivery verified; self-hosting, backup and restoration tested; no required paid vendor services.
