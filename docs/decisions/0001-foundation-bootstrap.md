# 0001 Foundation Bootstrap

Date: 2026-10-09
Task: RUSH-001
Status: Accepted

## Context

RUSH-001 establishes the repository skeleton required by the V1 technical specification:
Laravel and Orchid for the Server, Quasar/Vue/TypeScript for the Client, linting and
formatting baselines, and enough automated tests to prove each application boots.

## Decisions

- Scaffold the Server as a Laravel 13 application under `server/`.
- Install Orchid Platform 14 and keep the generated `/admin` route registration.
- Use Pest 5 with the Laravel plugin for Server tests. Laravel 13's default `laravel/pao`
  dev test runner was removed because the RUSH technical specification names Pest.
- Keep the Server's local development database as SQLite for bootstrap tests only.
  RUSH-002 owns Docker Compose, PostgreSQL, Caddy, and production routing.
- Scaffold the Client as a Quasar CLI with Vite application under `client/`, using Vue 3,
  TypeScript, Pinia, ESLint, and Quasar PWA mode.
- Add Vitest as the Client unit test runner and defer Playwright and CI wiring to RUSH-007.

## Consequences

Both applications can be installed, linted, tested, and built independently while preserving
the target monorepo layout. No scheduling, identity, synchronization, or deployment behavior
is implemented in this task.
