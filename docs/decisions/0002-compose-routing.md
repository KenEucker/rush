# 0002 Compose and Routing

Date: 2026-10-09
Task: RUSH-002
Status: Accepted

## Context

RUSH-002 establishes the production routing skeleton required by Technical Specification
§4. The application must be served from one public origin, with the Client at `/`, Orchid
at `/admin`, the Laravel API at `/api/v1/*`, and PostgreSQL kept off the public network.

## Decisions

- Use Docker Compose with three production services: Caddy, Laravel PHP-FPM, and PostgreSQL.
- Make Caddy the only public entry point. It publishes HTTP/HTTPS ports, terminates the
  public route, serves the built Quasar PWA, and forwards Laravel-owned paths to PHP-FPM.
- Keep PostgreSQL on an internal Compose network with no published ports. The Server reaches
  it through the service DNS name `postgres`.
- Build the Quasar PWA into the Caddy image and switch the Client router to history mode so
  Caddy can provide an actual SPA fallback for Client routes.
- Route `/admin*`, `/api/v1*`, Laravel health, Orchid assets, storage/build assets, and
  authentication/session endpoints to Laravel before applying the Client fallback.
- Add a minimal `/api/v1/health` endpoint to prove the versioned API prefix and Caddy routing
  target without implementing domain APIs early.

## Consequences

The deployment skeleton now matches the intended one-origin shape while avoiding a second
public port for Laravel or PostgreSQL. Local development can still run the Server and Client
directly; Compose is the production-style path that later tasks will harden with
authentication, synchronization, jobs, backups, and CI/E2E coverage.
