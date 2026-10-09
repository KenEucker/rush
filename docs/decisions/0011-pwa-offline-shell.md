# 0011 PWA installation and offline shell

Date: 2026-10-09. Task: RUSH-011. Prerequisites: RUSH-001–010 merged on
production. Requirements: Technical §§7.6, 13; cross-cutting R-01–R-38.
No functional acceptance scenario owner or product rule changes.

## Asset and data boundaries

Quasar GenerateSW precaches the HTML shell, manifest, built JavaScript/CSS/fonts
and RUSH icons. There are no runtime caching strategies. Navigation fallback
allows only current Client routes (including query strings); adding a route
requires updating the allowlist and its route-coverage test. Server/API/admin,
authentication, health, storage and unknown URLs cannot receive cached shell
HTML. The framework's own worker-file exclusions are preserved.

Workbox owns only rebuildable app assets. It never persists authenticated API
responses, commands, identity or sync checkpoints. Dexie remains the only durable
Client domain store; neither registration nor update clears or changes it.
No new schema, migration, endpoint, dependency or sync protocol is introduced.

The shell can start offline after a successful online visit. It renders connection
guidance when it cannot verify a protected session. Account boot and offline
availability remain RUSH-012/013; this task does not turn cached HTML into proof
of identity or Server acceptance. Browser connectivity is a hint, not proof that
the Server is reachable. No Background Sync support is required.

## Installation and deployment

The manifest uses RUSH branding, a root start URL/scope and standalone display.
The explicit /rush-client manifest ID preserves the prior Quasar-generated ID's
resolved identity. The existing repository rush-icon.png is copied unchanged for
touch/favicon use and embedded unchanged in a square SVG viewport for a scalable
manifest icon. No image generation, paid service or new dependency is needed.

Serve the production PWA through Caddy on the configured HTTPS origin. Loopback
HTTP is used only by the disposable test fixture. Caddy returns real worker,
Workbox and manifest files with no-cache revalidation, and a 404 for missing
worker files, never the HTML fallback. Hashed assets remain build-generated.

The in-app Install RUSH disclosure explains native browser installation and
first-online setup. Asset readiness is transient Vue state, separate from account
sync. A successful initial precache/active worker marks readiness; a registration
failure displays connection/reload guidance. A failed update check cannot erase
an already active shell's ready state. Browser eviction or clearing site storage
requires another online visit; installation is not a backup of pending work.

## Updates and rollback

New workers wait for all old controlled tabs/windows to close; skipWaiting is
disabled. Users see a notice to finish work, close all RUSH windows, and reopen.
There is no forced reload or message that activates a worker over an open form.
The first worker claims the initial page once ready, and Workbox cleans obsolete
asset caches on activation. Application code never clears IndexedDB.

Deploy normally and allow the waiting worker to activate after users close the
app. Rollback similarly deploys assets with a new precache manifest; keep Dexie's
existing forward-schema compatibility requirements. Do not clear browser data
as an update or rollback procedure.

## Verification boundaries

Vitest checks every Client route plus Server exclusions and lifecycle transitions.
Desktop/mobile Chromium tests use the production Docker/Caddy build, validate
manifest installability, disable HTTP cache, reload/reopen offline, then restart a
persistent browser profile offline. They also inspect all Cache Storage entries,
verify real authenticated responses bypass Workbox, refuse Server navigation
fallback, and preserve an unsaved form through a waiting-worker transition.

Chromium's network emulation and navigator.onLine can diverge for a new target.
The test explicitly sets the browser's offline hint through CDP while Playwright
blocks network requests; an API fetch must still fail independently. No production
response or worker script is mocked. OS installation dialogs/shortcuts and physical
iOS Safari installation remain manual release checks. Chromium's installability
check and durable offline browser startup are automated; native installation is
not claimed as automated evidence.

See [installation guide](../pwa.md) and [test evidence](../evidence/RUSH-011/README.md).
The mandatory availability/conflicting-assignment gate remains RUSH-012–014.
