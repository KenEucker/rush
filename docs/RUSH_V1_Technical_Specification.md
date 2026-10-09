# RUSH — V1 Technical Specification

**Ranger Unified Scheduling & Hours**  
Version: 1.0  
Status: Technical implementation baseline  
Date: October 8, 2026

## 1. Purpose

RUSH is an offline-capable, installable web application for managing seasonal Ranger scheduling, availability, staffing coverage, shift changes, and hours worked.

The initial deployment is designed for a single Ranger organization with approximately 5–15 Rangers. The architecture should remain maintainable and capable of supporting additional organizations in the future.

This specification accompanies the previously completed **Ranger Scheduling & Hours — V1 Requirements** document. That document defines the expected functionality, workflows, business rules, and acceptance scenarios.

This specification defines the technical architecture, implementation conventions, and engineering requirements.

The implementation will derive its database models and API contracts from these specifications. A separate exhaustive database or API design document is unnecessary.

## 2. Technology stack

The following technologies are selected for V1.

| Component | Technology |
|---|---|
| Client framework | Vue 3 |
| Programming language | TypeScript |
| Vue development pattern | Composition API, script setup |
| Client framework and UI | Quasar CLI with Vite |
| Calendar | Quasar QCalendar |
| Date/time controls | Quasar QDate and QTime |
| Client state | Pinia |
| Offline database | Dexie.js with IndexedDB |
| PWA | Quasar PWA mode with Workbox |
| Server framework | Laravel |
| Administration interface | Orchid |
| Authentication | Laravel Sanctum |
| Server database | PostgreSQL |
| Jobs and scheduling | Laravel queues and scheduler |
| Web server | Caddy |
| Development and deployment | Docker Compose |
| Server tests | Pest |
| Client tests | Vitest |
| End-to-end tests | Playwright |

Use stable, compatible versions available at initialization. Commit dependency lockfiles and pin production container versions.

All V1 functionality must operate without paid software licenses, commercial sync subscriptions, or required managed cloud services.

## 3. Application architecture

RUSH consists of two applications in one repository.

### 3.1 Server

The **Server** is a Laravel application containing:

- Authentication and authorization.
- Business logic and domain services.
- Scheduling and fairness calculations.
- API controllers and validation.
- Database persistence.
- Orchid administration.
- Automated jobs and notifications.
- Reporting, exports, and integrations.
- Offline synchronization endpoints.

Laravel is the authoritative system for all accepted domain changes.

Orchid provides conventional management and configuration interfaces. Rich interactive management workflows, including schedule calendars, may be implemented in the Client when appropriate.

Both interfaces must rely on the same Laravel services and authorization rules.

### 3.2 Client

The **Client** is a Vue 3 and Quasar application providing:

- Ranger and team schedules.
- Availability and scheduling preferences.
- Assignment details and notifications.
- Ready and Finished shift actions.
- Shift swaps and transfers.
- Weekly timesheet review.
- Offline operation and synchronization.
- Selected management-facing scheduling workflows.

Use standard Vue 3 Composition API with TypeScript throughout.

Quasar is the exclusive general-purpose UI component library. Do not introduce another component framework without an explicit architecture decision.

### 3.3 Repository organization

```text
rush/
├── client/
│   ├── src/
│   │   ├── modules/
│   │   ├── components/
│   │   ├── composables/
│   │   ├── stores/
│   │   ├── domain/
│   │   ├── data/
│   │   │   ├── database/
│   │   │   ├── repositories/
│   │   │   ├── sync/
│   │   │   └── api/
│   │   └── boot/
│   └── src-pwa/
├── server/
│   ├── app/
│   ├── database/
│   ├── routes/
│   └── tests/
├── docker/
├── docs/
├── compose.yaml
└── .env.example
```

The specific internal module structure may evolve according to standard Laravel and Quasar conventions.

Maintain clear boundaries between business rules, persistence, synchronization, and presentation.

## 4. Hosting and networking

RUSH uses a single public origin for its applications.

Production routing:

| Path | Destination |
|---|---|
| `/` | Client |
| `/admin` | Orchid administration |
| `/api/v1/*` | Laravel API |
| Authentication routes | Laravel |
| Laravel/Orchid assets | Server assets |

Caddy serves the compiled Client's static assets and forwards Server requests to Laravel. It must route authentication and Orchid assets correctly, and apply Vue SPA fallback only to actual Client routes.

The public web interface uses ports 80 and 443, with HTTPS as the default.

During development, Docker may expose the web application on port 8080. A separate internal Quasar development process is acceptable for hot module replacement.

PostgreSQL must remain inaccessible from the public network.

Production Docker Compose will support inexpensive VPS hosting and deployment on privately owned hardware.

## 5. Domain-driven implementation

Implement the application as a modular monolith.

The major domains are:

1. Identity and organization membership.
2. Seasons, phases, and scheduling configuration.
3. Ranger availability and preferences.
4. Coverage requirements and staffing.
5. Schedule planning, generation, and publication.
6. Events and paid attendance.
7. Published schedule changes.
8. Shift activity and weekly timesheets.
9. Management gap coverage.
10. Notifications, reporting, and exports.

Each domain must encapsulate the rules established in the functional requirements.

The implementation should infer normalized data models with appropriate relationships, constraints, timestamps, revision tracking, and audit records.

### Data-model conventions

- Use UUIDs for independently created, synchronized domain records.
- Use database foreign keys and appropriate uniqueness constraints.
- Store authoritative event timestamps as UTC instants.
- Associate scheduling configurations with an explicit season time zone.
- Preserve histories for published assignments, management corrections, timesheets, and exports.
- Use database transactions for operations affecting multiple related records.
- Support organization scoping where appropriate, even when the initial installation contains only one organization.
- Avoid unnecessary generalized frameworks and abstract base classes.

Before implementing a domain, identify its entities, relationships, states, transitions, permissions, and invariants. Document significant assumptions alongside the code.

## 6. Scheduling engine

The Server contains a dedicated scheduling engine responsible for generating proposed assignments.

The engine must use:

- Phase-specific coverage requirements.
- Configurable shifts and coverage areas.
- Ranger unavailability.
- Default and week-specific preferences.
- Weekly desired hours and configured limits.
- Individual and team scheduling history.
- Shift desirability classifications.
- Special events and paid attendance.
- Existing published assignments.
- Weekly fairness, with season-long fairness as a secondary consideration.

Generation creates editable proposals and never publishes directly.

### Scheduling behavior

The engine should use a deterministic, testable constraint-and-scoring approach.

Hard restrictions, manager-overridable conditions, and preferences must be distinguished explicitly.

The engine must identify scheduling conflicts, unfilled positions, undesirable assignments, and potential alternatives.

Fairness should be explainable using identifiable factors rather than only an opaque numerical score.

A straightforward algorithm is preferred initially. An external optimization service is unnecessary for V1.

Generation should produce a record of its input revisions so drafts can be marked stale when relevant information changes.

Management remains responsible for editing, accepting, releasing, and publishing schedules.

Published schedules must never be silently overwritten by subsequent generation.

Scheduled publication deadlines are handled through idempotent Laravel jobs.

## 7. Offline-first architecture

Offline operation is a foundational Client requirement.

### 7.1 Local database

**Dexie.js is the required local operational database abstraction.**

It manages IndexedDB storage for:

- Authorized, previously synchronized records.
- Locally pending changes.
- Mutation commands awaiting submission.
- Synchronization checkpoints.
- Record versions.
- Synchronization errors and conflict states.
- Client cache metadata.

Dexie database schemas must be versioned, with documented migrations.

Components must access persistent data through typed repositories and composables.

Pinia is reserved primarily for transient UI and session state.

Do not introduce independent persistent stores or mutation queues in individual components.

### 7.2 Synchronization

Develop a centralized Client synchronization coordinator and corresponding Laravel Server functionality.

The sync coordinator must:

1. Persist offline changes before reporting them as locally saved.
2. Queue changes using unique operation identifiers.
3. Push pending operations when Server connectivity is available.
4. Retrieve authoritative Server changes using revision checkpoints.
5. Apply incoming changes transactionally.
6. Retry temporary failures safely.
7. Detect and expose conflicting changes.
8. Preserve unsynchronized user input during failures.
9. Support interrupted synchronization and resumed sessions.
10. Prevent duplicate operations and duplicated Server effects.

Use idempotent Server commands and stable synchronization checkpoints.

Server authorization and validation must apply equally to online and previously queued offline operations.

### 7.3 Conflict resolution

Conflict behavior depends on the operation.

For example:

- Offline preference edits can generally be reconsidered against the current authoritative preference revision.
- Unavailability changes must preserve the Ranger's reported intent and identify any conflicting assignments.
- Published assignment changes require Server approval and cannot be overwritten by stale Client records.
- Timesheet corrections must preserve the historical record and relevant approval state.
- Finalization and publication remain Server-authoritative.

No universal last-write-wins strategy is permitted for sensitive operational records.

A rejected operation must remain visible with an explanation and an appropriate recovery action.

### 7.4 Offline operation matrix

| Operation | Offline support |
|---|---|
| View cached schedules | Yes |
| View own historical records | Yes, if synchronized |
| Edit availability | Yes, pending sync |
| Edit preferences | Yes, pending sync |
| Record Ready/Finished | Yes, pending sync |
| Enter hours corrections | Yes, pending sync |
| Submit weekly timesheet | Queue pending submission |
| Propose/accept transfer | Queue intent |
| Generate schedule | Online |
| Publish schedule | Online |
| Approve official changes | Online |
| Finalize timesheets | Online |
| Export records | Online |
| Orchid administration | Online |

Offline submission does not establish authoritative acceptance until the Server confirms the operation.

### 7.5 Offline UI requirements

The Client must visibly distinguish:

- Online and synchronized.
- Offline with cached information.
- Changes waiting to synchronize.
- Synchronization in progress.
- Synchronization failed.
- Conflict requiring attention.

Each affected record should show its synchronization state where relevant.

Provide an application-level pending-change indicator and last successful synchronization time.

### 7.6 Browser storage and service workers

Quasar's Workbox integration handles application asset caching and offline application startup.

Dexie handles domain-data persistence.

Do not independently cache mutable authenticated API responses through Workbox in a way that creates competing authoritative local data.

Handle browser storage restrictions, migrations, schema failures, and potential eviction.

Offline functionality must not depend on browser background synchronization, which is not consistently available.

Queued commands must survive application reload and device connectivity changes, subject to browser storage availability.

## 8. Authentication and authorization

Use Laravel Sanctum with same-origin session authentication and secure, HTTP-only cookies.

V1 defines two roles:

**Ranger:** Access personal scheduling information, published team schedules, permitted preferences, availability, shift actions, swap/transfer workflows, and personal timesheets.

**Management:** Manage configurations, planning, assignments, schedule changes, timesheets, reporting, and exports.

All authorization decisions must occur on the Server.

Client permissions control presentation but cannot establish access.

The Server must validate every read and write against the authenticated actor and relevant organization.

Sensitive information must remain isolated between accounts.

Cached offline information must be partitioned by account and organization. Signing out must provide secure handling of cached data, including warnings before removing unsynchronized work.

Queued offline commands are revalidated when received by the Server, including after permission changes or session expiry.

## 9. UI and interaction conventions

The Client should prioritize a simple, accessible experience suitable for Rangers with varying technical familiarity.

Use Quasar's standard components wherever possible.

### Navigation

Mobile interfaces should prioritize the Ranger's immediate tasks: upcoming schedule, availability, shift activity, timesheets, and notifications.

Desktop views may use more detailed calendars and tables.

Use consistent navigation, readable labels, clear action buttons, and prominent confirmation states.

### Calendar and date handling

Use QCalendar for schedule presentation and Quasar QDate/QTime for date and time entry.

All calendar views must display schedule information consistently.

Use clearly labeled dates with weekdays and familiar 12-hour time formatting.

Support overnight assignments and shifts spanning week boundaries.

Avoid relying on dragging, hovering, or complex gestures for essential operations.

Use explicit actions for editing, proposing, approving, and confirming changes.

### Forms

Use Quasar form controls and Client-side validation for immediate feedback.

Laravel remains authoritative for validation.

Provide descriptive errors, appropriate loading indicators, and confirmation for important changes.

### Accessibility

Support keyboard interaction, semantic labels, readable text, sufficient contrast, and touch-friendly controls.

Do not communicate important status solely through color.

## 10. Hours and timesheets

The system must maintain a clear distinction among scheduled hours, observed shift activity, reported worked hours, and finalized timesheet records.

Ready and Finished actions record exact activity timestamps but do not automatically modify payable hours.

Weekly timesheets default to scheduled shifts and paid event assignments.

Rangers can report corrections, including early or late work, regardless of any convenience-action time window.

Timesheet states are:

- Open
- Submitted
- Needs Review
- Finalized

A submission matching scheduled hours may finalize automatically.

Exceptions require management resolution.

Management may finalize on behalf of a Ranger, with clear attribution.

Maintain an auditable history of corrections and finalizations.

Configured hour limits generate warnings but must not prevent recording legitimately reported hours.

## 11. Notifications and integrations

RUSH V1 uses in-app notifications exclusively.

Notifications cover draft releases, schedule publication, changes, approvals, acknowledgments, timesheet exceptions, and management reminders.

Do not add email, SMS, or device push infrastructure.

For Signal sharing, use the browser's Web Share API where supported, allowing the user to select Signal and manually send the prepared message. Provide a copy-to-clipboard fallback.

The Client must not automatically send Signal messages or assume access to a particular Signal group.

Exports support CSV and JSON, management-defined profiles, preview, and manual HTTPS delivery.

Export profiles and deliveries must preserve sufficient history to identify the records and configuration used.

## 12. Reliability and security

The application must provide:

- HTTPS in production.
- Server-side authorization and validation.
- CSRF protection and reasonable rate limits.
- Secure management of application secrets.
- Database transactions for critical operations.
- Idempotent synchronization commands and scheduled jobs.
- Appropriate audit histories.
- Automated database backups.
- Documented backup restoration.
- Health checks and actionable logs.
- Safe schema migrations and deployment procedures.

PostgreSQL data must persist independently of application container lifecycles.

A production deployment must support documented upgrades and recovery without requiring third-party hosting services.

## 13. Testing

Each feature requires automated tests covering normal behavior, edge cases, authorization, and offline behavior when relevant.

### Server tests

Use Pest to verify domain services, scheduling algorithms, permissions, state transitions, persistence, jobs, exports, and audit records.

### Client tests

Use Vitest to verify components, repositories, data transformations, local persistence, and synchronization behavior.

### End-to-end tests

Use Playwright to exercise complete user journeys.

Offline tests must use a production-built PWA, since Quasar's development PWA mode does not provide equivalent offline caching.

Tests must include:

- Launching the installed application offline.
- Editing offline and surviving reload.
- Reconnecting and synchronizing changes.
- Duplicate sync requests.
- Network interruption during synchronization.
- Conflicting edits on different devices.
- Rejected changes due to permissions.
- Session expiration and reauthentication.
- Overnight shifts and daylight-saving transitions.
- Automatic publication and hours finalization.
- Backup restoration and deployment verification.

All nine acceptance scenarios in the functional requirements are mandatory release tests.

## 14. AI-assisted development rules

RUSH will be developed with AI coding assistance. Implementation must be consistent, reviewable, and maintainable by a human developer.

The following rules are mandatory:

1. Use the agreed stack and existing framework conventions.
2. Treat the functional requirements and this specification as authoritative.
3. Prefer established Laravel, Vue, and Quasar patterns over custom abstractions.
4. Keep domain logic out of Vue components and API controllers.
5. Use Dexie repositories and the centralized synchronization coordinator for all durable Client operations.
6. Never introduce a competing offline persistence or synchronization mechanism.
7. Derive and document domain models before implementing dependent features.
8. Keep API interfaces typed and consistent; generate Client types from a maintained contract when practical.
9. Write automated tests alongside implementation.
10. Record important architectural decisions and assumptions in the repository.
11. Do not introduce required paid services or libraries without explicit approval.
12. Obtain approval before changing established stack or synchronization architecture.
13. Implement complete vertical feature slices rather than broad layers of unfinished scaffolding.
14. Trace implementation and tests to requirements R-01 through R-38.

A feature is complete when its relevant Server behavior, Client interface, offline behavior, permissions, and tests are implemented and verified.

---

# RUSH — Simple Implementation Plan

The implementation will proceed through seven milestones. Each milestone must produce a working, testable result.

| Milestone | Primary deliverable |
|---|---|
| **1. Foundation** | Repository, Docker environment, Laravel/Orchid, Vue/Quasar PWA, PostgreSQL, authentication, and baseline automated testing |
| **2. Offline infrastructure** | Dexie local database, synchronization coordinator, Laravel sync support, error handling, and offline integration tests |
| **3. Scheduling configuration** | Seasons, phases, shifts, coverage definitions, Ranger availability, preferences, and one-off events |
| **4. Schedule generation** | Scheduling engine, fairness logic, draft generation, review, publication, coverage gaps, and management suggestions |
| **5. Schedule operations** | Ranger calendars, published schedules, changes, transfers, swaps, management approvals, and notifications |
| **6. Hours and timesheets** | Ready/Finished activity, reported corrections, weekly review, management finalization, management gap coverage |
| **7. Reports and release** | Summaries, export profiles, CSV/JSON downloads, manual webhook delivery, auditing, deployment hardening, and acceptance testing |

## Milestone execution

For each milestone:

1. Identify the relevant functional requirements.
2. Derive necessary domain models and state transitions.
3. Implement Server services and authorization.
4. Implement Client interfaces and local repositories.
5. Implement the required offline synchronization behavior.
6. Write and execute automated tests.
7. Verify the milestone through an end-to-end user workflow.
8. Update implementation documentation and record significant design decisions.

Complete foundational synchronization work before expanding the Client across multiple domains.

## Initial technical proof

The first significant technical test is an offline availability workflow:

A Ranger signs in, synchronizes their information, disconnects, records unavailability, closes and reopens the Client, and reconnects. RUSH must successfully reconcile the pending operation with Laravel.

The same workflow must be tested when management has changed an affected assignment during the offline period.

The resulting state must remain accurate, preserve the Ranger's reported intent, and communicate conflicts without silently changing the official schedule.

This proof validates the most important architectural risk before broader development.

## V1 completion criteria

RUSH V1 is complete when:

- The full functional requirements are implemented.
- Every required acceptance scenario passes.
- The Client is installable and operates correctly offline.
- Scheduling and fairness behavior is inspectable and reproducible.
- Published schedules and finalized hours are reliable and auditable.
- Ranger and Management permissions are enforced.
- Self-hosted deployment, backup, and recovery are documented and tested.
- No required functionality depends on a paid third-party service.

**Implementation principle:** Build the simplest maintainable system that satisfies the complete V1 requirements, using established tools and conventions, with explicit tests protecting offline data, scheduling accuracy, and hours integrity.
