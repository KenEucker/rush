# RUSH (rush-client)

## Install the dependencies

```bash
pnpm install
# or: yarn/npm/bun install
```

### Start the app in development mode (HMR, error reporting, etc.)

```bash
quasar dev
```

### Format & Lint the files

```bash
pnpm run lint
# or: yarn/npm/bun run lint
```

...or just check formatting & linting:

```bash
pnpm run lint:check
# or: yarn/npm/bun run lint:check
```

### Build the app for production

```bash
quasar build
```

### Customize the configuration

See [Configuring quasar.config.js](https://v2.quasar.dev/quasar-cli-vite/quasar-config-file).

## UI shell checks (RUSH-005)

After npm ci, run npm run test:unit, npm run lint:check, and npm run typecheck.
Run npm run build, npx playwright install chromium, then npm run test:shell
for desktop/mobile shell regression tests against the built SPA. The suite starts
its own local static server on port 9105 and uses synthetic session responses.
It does not require demo credentials or a running Server. Run the Server Pest
suite separately for actual authentication and Orchid authorization.

The calendar route is a transient component preview; it loads no assignments and
saves no operational data. Full-stack CI and production PWA offline tests remain
with RUSH-007 and RUSH-011–014 respectively.

## Offline storage foundation (RUSH-008)

Use `src/data/repositories/accountStorage.ts` for durable operational data. Open
an explicit account/organization scope from the authenticated session, use the
typed repositories, and close its handles when leaving that scope. Never use raw
Dexie tables in components. Await writes before reporting local success and show
the actionable `StorageError` if persistence fails.

`pending.stage` stores a command and its local projection atomically; this is
pending intent, not Server acceptance. `clearCache` refuses unresolved work unless
the caller explicitly supplies a user-approved discard choice. Closing handles
does not erase data. The current shell has no durable workflow connected yet;
session/security UX and the sync coordinator remain RUSH-009–014.

`npm run test:unit` includes partition, reload, transaction, migration and storage
failure tests using Dexie with `fake-indexeddb`. See
[ADR 0008](../docs/decisions/0008-dexie-storage-foundation.md) for the schema,
retention policy, upgrade rules and remaining production-PWA proof.
