# ADR 0007: CI and full-stack browser foundation

Date: 2026-10-09

Task: RUSH-007

Status: Implemented; pending review and merge

## Context and invariants

RUSH-001–006 are merged into `production`. Technical §13 requires reproducible
Pest, Vitest and Playwright checks. This infrastructure task supports R-01–R-38
through regression protection; it does not complete any operational requirement
or one of the nine acceptance scenarios. There are no new entities, state
transitions, schema changes, sync stores, or unresolved product decisions.

The foundation demonstration must use real Sanctum cookie sessions, Server
authorization, PostgreSQL, the built Client and Orchid through one Caddy origin.
Mocked shell tests remain useful for layout and connection-failure assertions,
but cannot establish that those boundaries work together.

## Decision

- GitHub Actions runs four independent jobs for PRs targeting `production` or
  `main`, pushes to those branches, and manual runs. PR metadata edits rerun the
  checks. There are no path filters that could accidentally skip required checks.
- Each job starts from checkout and committed lockfiles. The Server initializes
  its disposable `.env` from the committed example, then overlays CI settings.
  Node 24.16.0 matches the
  Client production builder; PHP 8.5 runs the locked Pest 5/PHPUnit 13 development
  dependencies, while integration tests exercise the pinned PHP 8.4 production
  image. PostgreSQL uses the same pinned image as production.
- Server checks include Composer validation, Pint, fresh migrations,
  rollback/reapplication and all Pest tests against a disposable PostgreSQL
  service. Vitest, Client lint (including browser tests), type checks, SPA/PWA
  builds and the existing mocked desktop/mobile shell suite run separately.
- `docker/compose.ci.yaml` builds the existing production Dockerfiles and uses
  the production Caddyfile. Only Caddy publishes a loopback port (9187); PostgreSQL
  and PHP-FPM remain private. PostgreSQL data is temporary. Fixed credentials and
  the application key are public test values for this disposable fixture only.
  No deployment secrets, production volumes or external services are involved.
- The fixture uses local HTTP and `APP_ENV=local` so the real CSRF middleware
  remains enabled. Production TLS and secure cookies remain configured in the
  deployment stack; the existing Pest secure-cookie check remains in CI. Browser
  TLS/certificate validation belongs to RUSH-048.
- Integration Playwright runs desktop and mobile Chromium sequentially, with
  fresh browser contexts, real UI sign-in and no intercepted APIs. Separate Ranger
  accounts per project avoid exhausting the real per-account login limiter.
  Profile writes use an authorized no-op, keeping the shared fixture immutable.
  Tests cover guests, wrong credentials, missing CSRF, both roles, private profile
  access, owner-only writes, sign-out and switching accounts. An Origin header
  makes direct Playwright API requests stateful under Sanctum, like the browser.
- The production Client build now includes the shared contract examples as build
  inputs because its type checker also validates the contract tests. Those
  examples do not become a new runtime API or data store.
- A dependency-free Node validator checks task commits and PR metadata. It
  requires supported Conventional Commit types and domain scopes, rationale,
  task/requirement/test footers and a breaking-change footer when marked. PR titles
  use Conventional Commit format for squash merging; the PR template requires
  decisions, database/sync impact, actual test outcomes, screenshots or an
  explanation, and limitations/migration notes. It checks structure; reviewers
  still judge imperative wording, truthfulness and adequacy of evidence.
- Commit checks cover non-merge commits in the PR base-to-head range, excluding
  historical commits and GitHub-generated merge commits. PR metadata is parsed
  from JSON, never interpolated into shell commands. Actions use immutable SHAs,
  read-only permissions, no persisted Git credentials and no `pull_request_target`.
- No automatic retries hide flaky E2E failures. Reports, failure screenshots,
  traces, Pest results and Compose logs are retained for seven days. Cleanup runs
  on failure as well as success. Fixture traces can contain test session cookies;
  these reports must never be generated against live user accounts.

## Consequences and scope

The test runner installs no additional application dependency. No material UI
change or data migration is required. `server/tests/Unit/.gitkeep` preserves the
configured test directory in clean checkouts. Existing library audit notices are
not silently addressed through unrelated dependency upgrades.

Human review and required checks remain prerequisites to merge. Repository
administrators should require all four named CI jobs in branch protection; this
task does not change repository policy or merge automatically. Milestone 1 can
be closed only after review and merge. The production PWA build is exercised
online here; installed offline startup and offline write/reload/reconnect/conflict
proof remain RUSH-008–014, and all nine acceptance scenarios keep their owners.
