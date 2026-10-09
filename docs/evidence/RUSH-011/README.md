# RUSH-011 verification evidence

Verified locally on 2026-10-09. Branch: feat/RUSH-011-pwa-offline-shell.
Base: production, RUSH-001–010 merged. Technical §§7.6, 13; cross-cutting R-01–R-38.

## Acceptance coverage

| Criterion | Evidence |
| --- | --- |
| Installable RUSH PWA | Production manifest parsed with expected stable ID/name/scope/start URL/standalone display; Chromium reports no installability errors |
| Workbox precaches assets | Every Cache Storage entry is checked against the asset-only policy after real cookie-session login; offline fonts and lazy shell assets load |
| Cached startup works offline | HTTP cache disabled; offline reload, new-tab deep link and persistent-profile browser close/relaunch pass on desktop/mobile Chromium |
| Authenticated mutable data stays in Dexie | API navigation is JSON and bypasses the worker; offline API fetch fails; API/admin/auth/health/storage paths never receive cached shell HTML; existing Dexie/coordinator regression passes |
| Safe installation/update UX | Ready/install guidance and offline retry shown; real waiting worker preserves an unsaved form and original controller, then activates after all tabs close |
| Deployment boundaries | Worker/manifest no-cache headers; missing Workbox file returns 404 instead of shell HTML |
| Route/lifecycle regressions | Vitest tests current route coverage, Server exclusions, preparation/failure/readiness and update states |

The production fixture uses real Caddy, Laravel, Sanctum and PostgreSQL. No debug
worker or mocked API supplies these PWA results. The update test changes the
registered script URL's query string while serving the same production worker
policy. The restart test retains the actual on-disk browser profile between
processes and removes its disposable profile afterward.

## Commands and results

- npm --prefix client run lint:check — **passed**.
- npm --prefix client run typecheck — **passed**.
- npm --prefix client run test:unit — **passed, 136 tests in 12 files**.
- npm --prefix client run build — **passed**, production SPA.
- npm --prefix client run build:pwa — **passed**, production PWA.
- docker compose -p rush-ci -f docker/compose.ci.yaml up -d --build --wait --wait-timeout 120 — **passed**, production Dockerfiles; all fixture services healthy.
- docker compose -p rush-ci -f docker/compose.ci.yaml exec -T server php artisan migrate --force — **passed**.
- docker compose -p rush-ci -f docker/compose.ci.yaml exec -T server php artisan db:seed --force — **passed**.
- docker compose -p rush-ci -f docker/compose.ci.yaml exec -T server php artisan cache:clear — **passed**, reset only disposable fixture rate limits before full regression.
- npm --prefix client run test:e2e — **passed, 16 tests**, including eight PWA tests across desktop/mobile.
- npm --prefix client run test:e2e -- pwa.spec.ts --grep "production PWA" — **passed, 2 tests**, repeated after waiting for the install disclosure animation before screenshots.
- npm --prefix client run test:shell — **passed, 12 tests**, desktop/mobile.
- npm run conventions:test — **passed, 8 tests**.
- git diff --check — **passed**.
- docker compose -p rush-ci -f docker/compose.ci.yaml down --volumes --remove-orphans — **passed**, removed only the disposable CI fixture.

Initial lint/build found an unnecessary type assertion; it was removed. Initial
offline new-tab assertions exposed Chromium's navigator.onLine hint differing
from request blocking. The test now explicitly sets the offline hint through
CDP while context-level offline mode continues to block all network requests.
Screenshots were recaptured after disclosure animation completion and visually
checked for complete text and responsive layout.

Server source/schema was unchanged; Pest was not rerun locally. The full Server
suite remains a required CI check. Local results do not claim CI passed.

## Screenshots

Guest-only production PWA after offline deep-link startup, with installation help
expanded. No account data is shown.

- [Desktop offline shell](desktop-offline-shell.png)
- [Mobile offline shell](mobile-offline-shell.png)

## Limits and review

Chromium installability and persistent offline startup are automated. OS install
dialogs/shortcuts and physical iOS Safari installation are **not** automated.
A local attempt to automate native installation could not launch full Chromium;
headless-shell does not expose the PWA.install protocol. No native installation
success is claimed. Follow the device check in [the installation guide](../../pwa.md)
before release.

RUSH-012/013 still own offline account boot, availability and the conflicting
official-assignment proof; RUSH-014 owns sync observability. This task does not
claim the Milestone 2 availability gate or any of the nine functional scenarios
complete. See [ADR 0011](../../decisions/0011-pwa-offline-shell.md).
Human review, required CI checks and merge remain required.
