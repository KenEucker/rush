<div align="center">
  <img src="rush-wordmark.png" alt="RUSH — Ranger Unified Scheduling & Hours" width="560" />

  **Fair schedules. Clear coverage. Trusted hours.**

  An installable, offline-capable scheduling and hours application built for Ranger teams.
</div>

## About RUSH

**RUSH** stands for **Ranger Unified Scheduling & Hours**. It helps seasonal Ranger teams coordinate staffing, balance demanding shifts fairly, respond to schedule changes, and maintain trustworthy records of hours worked.

RUSH is designed around the realities of small teams: staffing needs change throughout a season, availability changes at short notice, some shifts are less desirable than others, and management sometimes needs to cover gaps. RUSH makes those constraints visible while keeping everyday work simple for Rangers.

The initial version is designed for a team of roughly **5–15 Rangers**, with the architecture kept maintainable for future growth.

## Features

### Scheduling and coverage

- **Season-based planning:** Define operational phases with different shift patterns, staffing requirements, and coverage policies.
- **Flexible coverage models:** Combine dedicated areas and shared coverage groups within a phase, with one target per staffing requirement.
- **Assisted schedule generation:** Prepare editable schedules based on staffing needs, availability, preferences, hour limits, and fairness history.
- **Fairness over time:** Prioritize balance within each week, taking season-long patterns and recurring undesirable shifts into account.
- **Visible staffing gaps:** Identify unfilled positions, flag conflicts, and suggest possible replacements without hiding shortages.
- **Draft review and publication:** Release drafts for visibility, publish in weekly or multiweek blocks, and preserve published assignments.
- **Events and meetings:** Schedule individual events, including paid meetings, alongside ordinary shift coverage.

### Tools for Rangers

- View personal assignments and the published team schedule.
- Record unavailability and preferred shifts or working patterns.
- Request shift swaps or transfers, subject to the agreement of everyone involved and management approval.
- Mark **Ready for Shift** and **Finished** as lightweight activity records.
- Review, correct, and submit a weekly timesheet.
- Receive in-app notifications about schedule changes and review actions.
- Manually share prepared shift messages through the device's share interface, including to Signal where supported.

### Hours and accountability

RUSH distinguishes **scheduled hours**, **shift activity timestamps**, **reported worked hours**, and **finalized hours**. Ready and Finished actions do not automatically change payable hours.

Timesheets start with scheduled assignments and paid events. Rangers can report exceptions, and management can review corrections or finalize missing submissions with an audit trail. Management can also record coverage of unfilled positions separately from Rangers' paid hours.

Management can review staffing and hours summaries, export CSV or JSON, and manually deliver configured exports to an HTTPS endpoint.

## Offline-first by design

RUSH is a **Progressive Web App (PWA)** that can be added to a phone's home screen without going through an app store.

The Client uses **Dexie.js and IndexedDB** to store authorized schedule data and pending changes on the device. Rangers can view previously synchronized information and perform supported actions during an interruption, then synchronize when connectivity returns.

Laravel remains authoritative for official schedules, approvals, timesheet finalization, and exports. Pending actions and synchronization conflicts must be shown clearly rather than silently treated as accepted. Offline operation depends on data previously synchronized to the device; the application does not guarantee that browser storage cannot be cleared or evicted.

## Technology

| Layer | Stack |
| --- | --- |
| **Client** | Vue 3, TypeScript, Composition API, Quasar |
| **Calendar and forms** | Quasar QCalendar, QDate, QTime |
| **Offline data** | Dexie.js, IndexedDB |
| **PWA** | Quasar PWA mode, Workbox |
| **Client state** | Pinia |
| **Server** | Laravel, Orchid |
| **Authentication** | Laravel Sanctum |
| **Database** | PostgreSQL |
| **Web entry point** | Caddy |
| **Development / deployment** | Docker Compose |
| **Testing** | Pest, Vitest, Playwright |

The codebase has two applications:

- **`server/`** — Laravel API, business rules, persistence, background jobs, and Orchid administration.
- **`client/`** — Vue/Quasar interface for Rangers and suitable interactive management workflows.

In production, both are served from one origin: the Client at `/`, Orchid at `/admin`, and the Laravel API under `/api/v1/`. The deployment is intended to work on a modest VPS or privately hosted hardware, without a required managed database or paid synchronization service.

## Project status

**Status: RUSH-001–006 merged; RUSH-007 CI and E2E foundation implemented, pending review.**

RUSH V1 is defined by **38 functional requirements** and **nine acceptance scenarios**. The [V1 implementation plan](docs/RUSH_V1_Implementation_Plan.md) organizes the work into **seven milestones and 49 development tasks**, with testable acceptance criteria, requirement traceability, and a target release of `v1.0.0`.

The repository now contains the initial `server/` Laravel/Orchid application, the
`client/` Quasar/Vue/TypeScript application shell, and the production-style Docker Compose
entry point for Caddy, PHP-FPM, and private PostgreSQL routing. Organization identity,
Sanctum session sign-in/sign-out, and role/record policies are available. Operational
domains, synchronization, and deployment hardening follow in the implementation plan.

### Bootstrap commands

The development workflow assumes a Unix workspace and a POSIX-compatible shell.
Run these commands from the repository root in a clean checkout:

```sh
composer install --working-dir=server
cp server/.env.example server/.env
touch server/database/database.sqlite
php server/artisan key:generate
php server/artisan migrate --force
npm ci --prefix client
npm ci --prefix client/src-pwa
```

Useful checks:

```sh
npm run server:test
npm run server:format
npm run client:lint
npm run client:typecheck
npm run client:test
npm run client:build
npm run client:build:pwa
```

### Artisan helpers

From the repository root, `npm run artisan -- <command> [options]` runs any
Server Artisan command with the Server's environment, on Windows or Unix.
For example, `npm run artisan -- make:migration add_example` or
`npm run artisan -- list`.

Common aliases are available in the root `package.json`:

| Purpose | npm scripts |
| --- | --- |
| Command discovery | `artisan:list`, `artisan:help -- <command>` |
| Database | `server:migrate`, `server:migrate:status`, `server:migrate:rollback`, `server:migrate:fresh`, `server:migrate:refresh`, `server:migrate:reset`, `server:seed`, `server:db:show`, `server:db:table` |
| Clear caches | `server:cache:clear`, `server:cache:forget`, `server:config:clear`, `server:route:clear`, `server:view:clear`, `server:event:clear`, `server:optimize:clear`, `server:clear-compiled` |
| Build caches | `server:optimize`, `server:config:cache`, `server:route:cache`, `server:view:cache`, `server:event:cache` |
| Setup | `server:key:generate`, `server:storage:link` |
| Queues | `server:queue:work`, `server:queue:restart`, `server:queue:failed`, `server:queue:retry` |
| Scheduler | `server:schedule:list`, `server:schedule:run`, `server:schedule:work`, `server:schedule:clear-cache`, `server:schedule:interrupt` |
| Operations | `server:about`, `server:config:show`, `server:route:list`, `server:event:list`, `server:up`, `server:down` |

Pass extra arguments after `--`, such as `npm run server:migrate -- --pretend`
or `npm run server:queue:retry -- <job-id>`. These run locally, using `server/.env`.

Every Artisan helper also has a `docker:` counterpart that executes in the
running Compose `server` service, using its container environment:

```sh
npm run docker:artisan -- list
npm run docker:server:migrate -- --pretend
npm run docker:server:migrate -- --force
npm run docker:server:optimize:clear
npm run docker:server:queue:restart
```

Docker Artisan helpers run as `www-data` so generated cache files remain writable
by PHP-FPM, and disable TTY allocation for terminal/CI compatibility. For an
interactive shell use `npm run docker:shell`; for interactive Artisan commands
use `npm run docker:exec -- --user www-data server php artisan tinker`.
`npm run docker:exec -- <service> <command>` also reaches other services.
`docker:up`, `docker:down`, `docker:ps`, and `docker:logs` manage or inspect the
stack; for example `npm run docker:logs -- --tail 100 server`.

Compose uses this repository's default stack unless you select another with
`COMPOSE_FILE` and `COMPOSE_PROJECT_NAME`. For explicit Compose flags use
`npm run docker:compose -- -p <project> -f <file> exec -T server php artisan <command>`.
Start the intended stack first (`npm run docker:up -- --build`). These scripts do
not silently start containers or change the host environment. The production
container receives `APP_KEY` from the root environment: use
`npm run docker:server:key:generate -- --show` to generate a value, store it in
the deployment's root `.env`, and recreate the Server container to apply it.

No alias adds `--force`. `migrate:fresh` drops all tables, `migrate:reset` rolls back
all migrations, `migrate:refresh` rolls back and reruns them, rollback removes the
last migration batch, and key generation changes encryption keys: use those
deliberately against the intended environment. `server:optimize:clear` includes
the application cache; the individual clear commands are available when needed.

The [testing guide](docs/testing.md) covers clean-checkout dependencies, disposable
PostgreSQL and full-stack Playwright runs, CI reports, and commit/PR validation.
CI checks PRs targeting `production` and `main`; human review is required to merge.

### Compose routing

RUSH-002 adds a production-style Compose environment. Create a root `.env` from
`.env.example`, set `APP_KEY` and a non-default `POSTGRES_PASSWORD`, then build and start
the single-origin stack:

```sh
docker compose up -d --build
docker compose exec server php artisan migrate --force
```

By default Caddy publishes ports `80` and `443`, serves the built Client at `/`, forwards
Laravel and Orchid paths such as `/admin`, `/api/v1/*`, `/vendor/orchid/*`, and `/up` to the
Server, and keeps PostgreSQL on an internal Docker network with no public database port.
For local smoke tests without privileged ports, set `RUSH_HTTP_PORT=8080` and
`RUSH_HTTPS_PORT=8443` in the root `.env`.

The Server image uses PHP 8.4 and verifies the locked production dependencies against
the final runtime during its build. After a Server Dockerfile update, rebuild and recreate
the running container (restarting an existing container does not update its image):

```sh
docker compose up -d --build --no-deps server
```

### Authentication development

For Docker development with live source updates, use Docker Compose 2.24.4 or
newer and the same root `.env` as your existing stack:

```sh
npm run docker:dev:up
```

This performs a one-time recreation of the web containers and starts an internal
Quasar development server. It reuses the existing PostgreSQL, Server storage and
Caddy TLS volumes. It does not migrate, seed or reset the database. Open the
`APP_URL` from your root `.env` (normally `https://localhost`). The development
override binds Caddy to loopback; PHP, Quasar and PostgreSQL have no published ports.

- Laravel/Orchid edits in `server/app`, `config`, `database`, `resources`, `routes`,
  `public` and the bootstrap entry files appear on the next browser refresh.
  PHP checks source timestamps and startup clears compiled config/routes/views.
  Source mounts are read-only inside PHP; use your host editor or local Artisan
  for code generation, and container Artisan for database/cache operations.
- Vue/TypeScript/CSS edits update through Quasar HMR at the same origin. Polling
  supports Docker Desktop file sharing. Container dependencies and `.quasar`
  output use separate named volumes, leaving host `node_modules` untouched.
- A previously installed production service worker is retired when the browser
  checks `/sw.js` for updates. If an old shell is still displayed, close/reopen
  the tab or use DevTools → Application → Service Workers → Update. IndexedDB
  and pending work are preserved; do not clear site data to switch modes.
- This runs the development SPA. Offline acceptance tests must continue to use
  the production-built PWA fixture documented in [testing](docs/testing.md).

Routine edits require no restart or rebuild. Dependency, environment, PHP INI
or container configuration changes still require an explicit update:

```sh
npm run docker:dev:logs -- --tail 50 server client
npm run docker:dev:artisan -- migrate
npm run docker:dev -- restart client                 # reinstall changed Node lockfile
npm run docker:dev:up                                # rebuild PHP dependencies/configuration
```

Use the `docker:dev:*` helpers while working in this mode. `docker:dev:down` stops
the development stack without deleting volumes. To return to built images:

```sh
npm run docker:dev:down
npm run docker:up -- --build
```

For custom ports/hostnames, `APP_URL` must match the public browser origin so HMR
connects through Caddy. For local HTTP only, set `RUSH_SITE_ADDRESS=http://:80`,
`APP_URL=http://localhost:8080`, `RUSH_HTTP_PORT=8080` and
`SESSION_SECURE_COOKIE=false` in the root `.env`. Production Compose behavior is
unchanged; the override enables local debug output and must not be deployed.

After migrations, use `php server/artisan db:seed` only in a local development
database to create the demo fixture. Sign in at `/sign-in` with
`casey.ranger@example.com` (Ranger) or `avery.management@example.com` (Management),
using the fixture password `password`. Never seed these known credentials into a
production deployment. Management can open `/admin`; Rangers cannot.

For hot reload, run `php server/artisan serve --host=127.0.0.1 --port=8000` and
`npm --prefix client run dev -- --port 9000`, then use `http://localhost:9000`.
Quasar proxies Server routes to port 8000. Set Server `APP_URL` to the public
origin (including its port); set `SESSION_SECURE_COOKIE=false` only for local
HTTP development. Sanctum includes the default local ports and `APP_URL`; custom
origins can be configured with `SANCTUM_STATEFUL_DOMAINS`.

The session API returns only your account and active memberships. Profile APIs
demonstrate owner-only writes and organization-scoped Management reads. Orchid's
unscoped starter account/role tools are disabled. See
[`session authentication decisions`](docs/decisions/0004-session-authentication-and-policies.md)
for the contract and follow-on offline account safeguards.

### Implementation roadmap

Domain services, revision checks, state transitions, API contracts, errors and
transactional audit conventions are documented in
[ADR 0006](docs/decisions/0006-domain-contract-conventions.md). The
[member-profile contract](docs/contracts/member-profile.md) is the tested proof;
profile PATCH requests require the revision returned by GET.

Each milestone contains seven bounded tasks and ends with a working, verifiable result. Refer to the [implementation plan](docs/RUSH_V1_Implementation_Plan.md) for task IDs, dependencies, completion criteria, and mandatory acceptance tests.

1. **Foundation:** Repository, Docker, authentication, Server, Client, and PWA setup.
2. **Offline infrastructure:** Dexie persistence, synchronization, conflict handling, and offline tests.
3. **Scheduling configuration:** Seasons, phases, shifts, coverage, availability, preferences, and events.
4. **Planning and publication:** Schedule generation, fairness, drafts, gaps, and publication.
5. **Schedule operations:** Calendars, changes, swaps, transfers, approvals, and notifications.
6. **Hours and timesheets:** Activity records, weekly confirmation, corrections, and management coverage.
7. **Reporting and release:** Exports, auditability, deployment hardening, and acceptance testing.

### Development workflow

Development uses task-scoped branches, Conventional Commits, and reviewed pull requests:

- **Branches:** One branch per task, such as `feat/RUSH-023-draft-publication`.
- **Commits:** Group related changes by behavior, including applicable tests. Use `<type>(<scope>): <imperative summary>` and include `Refs: RUSH-###`, relevant `Requirements: R-##`, and actual `Tests:` results in the commit body.
- **Pull requests:** Normally one PR per task. Include requirement references, implementation decisions, verified tests, and screenshots where relevant. Squash merge after checks and review.
- **Definition of done:** The completed behavior works through the appropriate UI/API, passes relevant tests, enforces permissions, preserves auditability, and satisfies the specified offline behavior.
- **Releases:** Milestone checkpoints lead to `v1.0.0`. V1 is complete only when all 38 requirements and all nine acceptance scenarios are verified.

The [implementation plan](docs/RUSH_V1_Implementation_Plan.md) defines the full Git conventions, task breakdown, and acceptance requirements. The functional requirements and technical specification take precedence if a plan summary is ambiguous.

## Development documentation

For installation, first-online setup, updates and current offline limits, see the
[PWA guide](docs/pwa.md).

These documents guide implementation:

- [`docs/RUSH_V1_Requirements.md`](docs/RUSH_V1_Requirements.md) — Functional behavior, exclusions, and acceptance scenarios.
- [`docs/RUSH_V1_Technical_Specification.md`](docs/RUSH_V1_Technical_Specification.md) — Architecture, offline contract, conventions, and implementation milestones.
- [`docs/RUSH_V1_Implementation_Plan.md`](docs/RUSH_V1_Implementation_Plan.md) — Seven milestones, 49 development tasks, Git workflow, requirements traceability, and V1 release criteria.
- [`AGENTS.md`](AGENTS.md) — Instructions and guardrails for AI-assisted development.

The requirements and technical specification are the source of truth for implementing features. Exact database models and API contracts will be derived incrementally as cohesive, tested domain slices.

## Guiding principles

- **Ranger-friendly:** Clear interactions that work for people with varying technical experience.
- **Fairness with transparency:** Show the reasons behind scheduling decisions and make exceptions visible.
- **Offline reliability:** Preserve pending work and provide understandable synchronization status.
- **Human authority:** Management controls publication, exceptions, and official changes.
- **Trustworthy hours:** Keep scheduled, observed, reported, and finalized time distinct.
- **Practical ownership:** Favor maintainable, self-hostable, free/open-source tooling with minimal operational cost.

---

<div align="center">
  <strong>RUSH</strong> · Ranger Unified Scheduling & Hours
</div>
