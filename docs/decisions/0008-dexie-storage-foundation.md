# 0008 Dexie storage foundation

Date: 2026-10-09

## Scope and prerequisites

RUSH-008 follows merged RUSH-001–007 on `production`. It implements Technical
§7.1 and the storage foundations of §§7.2, 7.3, 7.6 and 8. This is cross-cutting
infrastructure for R-01–R-38; it does not complete a functional requirement or
one of the nine acceptance scenarios. No additional operational workflow is
enabled before the Milestone 2 availability proof.

## Entities and invariants

- Dexie is the only durable Client operational store. Each account/organization
  pair has a separate database named `rush:account:<integer>:organization:<UUID>`.
  The immutable scope cannot be changed after opening; identical record IDs,
  operation IDs and checkpoint stream names in other partitions cannot collide.
  IDs are identifiers, not credentials. Partitioning prevents accidental mixing;
  it is not encryption or a substitute for Server authorization on a shared origin.
- `openAccountStorage` returns typed repositories, not raw tables. No global active
  account, Pinia persistence, Workbox domain cache or localStorage fallback is added.
  The session/coordinator must select a scope from verified active memberships.
  The existing transient shell does not open this infrastructure yet. RUSH-012/013
  own its integration with authorized cached startup and account lifecycle UX.
- The only concrete cached domain entity is the existing `MemberProfile` contract.
  Cache writes reuse its runtime validation and explicit field allowlist, reject a
  foreign organization, and update records and cache metadata in one transaction.
  Lower or equal revisions never replace cached Server values. Device timestamps
  describe cache activity only; they do not establish record ordering.
- Pending commands and local representations are separate from confirmed records.
  Their common operation UUID is a unique key in both stores. `pending.stage`
  resolves only after both inserts commit. Failure of either aborts both; reuse of
  an operation ID cannot replace prior intent. Multiple edits to the same record
  retain independent representations. Pending/failed/rejected/conflict/syncing
  states and problem details can be retained without changing official data.
- These are local storage envelopes, not the Server sync protocol. The JSON command
  and projection must contain only explicitly selected domain fields, never session
  cookies, passwords, tokens, integration credentials or whole transport responses.
  No production domain command is enabled here. RUSH-009 owns the discriminated
  command union, operation generation, Server idempotency, and validated wire contracts.
  RUSH-010 owns retries, queue execution and transactionally applying pulled records
  with checkpoints. No per-feature sender, retry loop or queue is implemented.
- Checkpoints are opaque Server tokens keyed by stream, with a separate last
  successful sync timestamp. A new database has no checkpoint and no last cache
  time. A cached record is not evidence of a completed synchronization.

## Retention and privacy decision (Implementation Plan §11 item 8)

Use conservative local retention without inventing Server audit-retention policy:
no age-based expiry, automatic pruning, destructive migration recovery or silent
discard of pending intent. `close()` invalidates repository handles and retains
disk data; it is not secure logout. Reopening is explicit. `clearCache()` clears
confirmed data and checkpoints transactionally, but refuses while **any** command
or local pending representation remains, including failed/rejected/conflicting work.
Only explicit `discardPending: true` permits removal of that work. The future logout
UX must warn and obtain the user's discard choice before using it; otherwise retain
the partition and close access. This primitive is not invoked by current sign-out.
RUSH-013 still owns logout, revoked membership, offline session expiry, account
switching and cross-tab identity reconciliation. RUSH-048 owns deployment/privacy
review and Server retention. No visibility or employment rule is decided here.

Browser eviction can remove all data, including unsent work. With no competing
persistent marker it is impossible to distinguish first use from complete eviction.
Recreated storage is explicitly cold (empty records, null cache time, no checkpoint);
the coordinator must resynchronize before claiming current data. The app cannot
promise to recover data the browser or user erased. Storage never falls back to an
in-memory success path.

## Schema and migrations

The first released schema is **version 1** (IndexedDB version 10 in Dexie's encoding):

| Store | Key/indexes | Data |
| --- | --- | --- |
| profiles | `id` | Allowlisted confirmed profile, Server revision, local cache time |
| pendingCommands | `operationId`, `state`, `[recordType+recordId]` | Intent, expected revision, state, attempts, problem |
| pendingRecords | `operationId`, `[recordType+recordId]` | Local projection corresponding to that command |
| checkpoints | `stream` | Opaque token and last successful sync time |
| metadata | `key` | Partition identity, schema version, creation/cache times |

Keep `schemaV1` immutable. Introduce new numbered Dexie versions for future changes;
use transactional `upgrade` callbacks for data transformations and update metadata
in that transaction. Never delete/recreate a database to repair a migration. There
is no pre-existing released Dexie schema to migrate in this task. The migration
tests seed production v1, rehearse a test-only v2 index/data upgrade, verify every
store survives, and verify a failed upgrade rolls back its changes and version.
The test-only v2 is deliberately not shipped as an artificial production migration.

Connections close on `versionchange` to release other tabs' upgrades. Old handles
fail closed. Blocked opens fail with guidance to close other tabs and retry; they
do not hang indefinitely. Because Dexie 4 can adapt to a higher on-disk version,
initialization explicitly rejects a newer schema before creating metadata or writing
data. Rolling back Client code must not downgrade or clear user data; deploy a
forward-compatible fix. IndexedDB restrictions, quota exhaustion, closed connections,
read/write failures and schema failures produce typed `StorageError` codes and
actionable messages. Callers must retain form input and show the error, never report
a local save before the returned promise resolves.

## Validation and follow-on boundaries

Vitest uses `fake-indexeddb` to execute real Dexie transactions and upgrades,
including multiple connections, rollback after a second-write failure, quota
fault injection, blocked upgrades, future-schema rejection, partition isolation,
metadata, intent persistence, read failures and cold initialization after eviction.
This is storage testing, not the production-built PWA offline acceptance proof.
RUSH-011–014 retain that gate, including offline restart, reconnect and a conflicting
Server assignment. No Server schema, endpoint, UI, authentication or PWA caching
behavior changes. Run `npm ci --prefix client` to install the locked dependencies.

References: [Dexie transactions](https://dexie.org/docs/Dexie/Dexie.transaction()),
[version changes](https://dexie.org/docs/Dexie/Dexie.on.versionchange),
[version compatibility](https://dexie.org/docs/DexieErrors/Dexie.VersionError).
