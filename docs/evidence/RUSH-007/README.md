# RUSH-007 verification

Executed 2026-10-09 on `feat/RUSH-007-ci-e2e-foundation`, based on `production`
commit `259590f` after merged RUSH-001–006. Maps to Technical §13 and cross-cutting
R-01–R-38 test infrastructure. No operational requirement or functional acceptance
scenario is claimed complete.

## Local results

| Command | Result |
| --- | --- |
| `npm run conventions:test` | 8 passed, including real Git-range/CLI positive and negative cases |
| `composer validate --working-dir=server --strict` | Passed |
| `composer --working-dir=server format:check` | Pint passed |
| `composer --working-dir=server test -- --compact` | 47 passed, 385 assertions (SQLite) |
| Fresh Git archive, copy committed `.env.example`, locked `composer install`, then `composer test -- --compact --display-warnings` | 47 passed, 385 assertions; no warnings |
| `php server/artisan migrate --force`, `migrate:rollback --force`, `migrate --force` | All passed on disposable PostgreSQL 17.2 |
| `composer --working-dir=server test -- --compact --log-junit storage/logs/pest.xml` | 47 passed, 385 assertions (PostgreSQL) |
| `npm --prefix client run test:unit` | 32 passed |
| `npm --prefix client run typecheck` | Passed |
| `npm --prefix client run lint:check` | Prettier and ESLint passed, including E2E/config TypeScript |
| `npm --prefix client run build` | Production SPA passed |
| `npm --prefix client run build:pwa` | Production PWA/Workbox passed |
| `npm --prefix client run test:shell -- --workers=2` | 12 passed, desktop/mobile Chromium |
| `docker compose -p rush-007-ci -f docker/compose.ci.yaml up -d --build --wait --wait-timeout 120` | Production Dockerfile builds and isolated stack startup passed |
| Fixture `php artisan migrate --force` and `db:seed --force` | Passed |
| `npm --prefix client run test:e2e` | 6 passed, real sessions on desktop/mobile Chromium |
| `docker run --rm -v "${PWD}:/repo:ro" -w /repo rhysd/actionlint:1.7.7 -color .github/workflows/ci.yml` | Workflow validation passed |
| `git diff --check` | Passed |

Local runtimes: Node 24.16.0, PHP 8.5.7, Composer 2.10.1, Docker Desktop Linux
containers. The browser stack exercises PHP 8.4.26 and PostgreSQL 17.2 with the
production Caddyfile and compiled PWA. The Pest database ran in a separate
`rush-007-pest-postgres` container bound only to `127.0.0.1:52447`, with disposable
database/user `rush_test`. No application database or existing Compose service
was altered. See [testing guide](../../testing.md) for repeatable commands.

## Failures found and resolved

- Strict TypeScript rejected an explicit `undefined` Playwright worker setting;
  the config now supplies either one CI worker or the normal percentage locally.
- The Docker Client build lacked the shared profile contract JSON imported by
  the RUSH-006 tests. Copying `docs/contracts` into the build stage fixes the
  production type-check/build without changing runtime behavior.
- Initial E2E assertions expected the field-level credentials message rather
  than the established generic validation message, and expected `/admin` rather
  than Orchid's `/admin/main` redirect. The tests now assert the actual maintained
  contract, including HTTP 422 and exact same-origin admin destination.
- The first formatting invocation resolved paths from the wrong working
  directory; it was rerun from `client` and the complete lint check passed.
- The first hosted run passed Client and convention checks but exposed an absent
  `.env`: the health test received the default application name `Laravel`. CI now
  initializes its disposable environment from the committed example before
  installing and testing, with key/database settings supplied by the job.
- The first hosted E2E build was interrupted by Docker Hub returning HTTP 504
  for its authentication token endpoint. This external pull failure is distinct
  from an application/test failure; the subsequent CI run retries the build.

## Acceptance and limits

The suite demonstrates Ranger and Management authentication, real cookie
persistence across reload, guest and Ranger administration denial, Management
Orchid access and stylesheet routing, private profile reads, owner-only writes,
CSRF rejection for login/logout/profile writes, and sign-out/account isolation.
Existing Pest tests cover denied cross-organization and revoked-membership cases.
The shared fixture uses no-op profile writes and independent Ranger accounts for
the two browser projects; no API responses are mocked in the integration suite.

CI starts each job from checkout and lockfiles, validates PR metadata and task
commits, preserves failure diagnostics and tears down its disposable stack.
GitHub-hosted execution results are reported in the PR checks, separately from
these local results. The initial submitted implementation still requires that
hosted run, human review and merge before RUSH-007/Milestone 1 are complete.

No new application dependencies, schema migrations, sync behavior or material UI
changes; screenshots are not applicable. Local HTTP is deliberate for this
loopback fixture; production TLS verification remains RUSH-048. Offline startup,
durable writes and reconciliation remain RUSH-008–014. The nine acceptance
scenario owners are unchanged. Existing npm audit notices were observed during
locked installation; no unrelated dependency upgrades were introduced.
