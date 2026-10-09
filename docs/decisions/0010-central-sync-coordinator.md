# 0010 Central sync coordinator

Date: 2026-10-09. Task: RUSH-010. Prerequisites: RUSH-001–009 merged on
production. Requirements: Technical §7 with §8 authorization boundaries;
cross-cutting support for R-01–R-38. No product rule or scenario owner changes.

## Scope and use

The account-scoped SyncCoordinator owns durable queue submission, foreground
push/pull, retry scheduling, resumed work and failure states. The existing member
profile command is the infrastructure fixture. This task does not activate a new
operational editor or add component senders.

The session owner opens AccountStorage only for a verified account/organization,
constructs one coordinator, and calls start. queueProfileUpdate returns only after
the command and local projection commit together. It does not wait for connectivity
or imply Server acceptance. syncOnce is an awaited, coalesced foreground pass for
explicit refresh/tests. start also wakes on online/visible events and checks every
30 seconds when idle; queueing wakes it immediately. No Background Sync dependency
or Workbox domain cache exists.

Before logout, scope changes or closing storage, await stop. It removes listeners
and timers, aborts requests and lock waits, and ignores late responses. Pending
work is retained. After revalidating the **same** identity and active membership,
the session owner may call resumeAfterAuthentication to clear a durable pause.
That method is not authentication. RUSH-012/013 own shell activation, verified
cached startup, cross-tab identity changes and logout/account security UX.
RUSH-014 owns user-facing sync observability/recovery controls. The current shell
does not automatically open a persistent account or start this infrastructure.

## Invariants and failure behavior

- Queue commands retain their original UUID, expected revision and payload.
  Independent edits are not silently coalesced or rebased. Only Server revisions
  establish accepted ordering; local times are retry/display metadata.
- An account/organization Web Lock encloses each pass, including network requests.
  It serializes tabs and independent connections; browser termination releases it.
  Requests have a 30-second abort timeout. The environment adapter keeps browser
  capabilities replaceable for a future Capacitor implementation. Missing Web
  Locks fails explicitly and preserves work, rather than using unsafe local locks.
- Short Dexie transactions never hold open over network calls. A command becomes
  syncing before sending; an interrupted syncing command can be replayed. Accepted
  results remove command and projection atomically. Rejected/conflicting commands
  keep both intent and explanation, and are excluded from automatic retries.
- Accepted push receipts may describe old state. They **never** write confirmed
  profiles. Only the ordered, authorized pull stream does, so replay cannot restore
  a consumed tombstone or replace a newer profile. Until pull completes the cache
  can still be older than the accepted operation; pending count alone is not proof
  of synchronization.
- Pull pages, tombstones, cache time and checkpoint commit together. Expected-token
  comparison also refuses stale responses. Lower/equal profile revisions do not
  replace higher cached revisions. Pagination resumes at the last committed token.
  An incomplete first pass has no successful-sync timestamp.
- A checkpoint_invalid response clears confirmed profiles/checkpoint and sync time
  atomically, preserves all pending intent, and restarts with null. One reset per
  pass prevents an invalid-checkpoint loop; a repeat is a persisted paused failure.
- Connectivity is a browser hint. Network failures, timeouts, throttling and 5xx
  persist an error, failure count and next retry time, with exponential backoff
  (1 second doubling to 5 minutes, with 75–100% jitter). Reload, timer and online
  events respect that deadline. A successful completed pull clears transient
  failure state. Last successful sync is a completed pass, not a declaration that
  no unresolved or newly queued intent exists.
- HTTP 401/403/419 pauses the account until explicit session recovery; it does not
  erase user work. HTTP 404/422 rejects an individual command and 409 marks it
  conflicting, permitting other commands and pulls to proceed. Other permanent
  request failures pause the pass. Invalid saved commands remain rejected with
  review guidance. Transient failure of a command stops that pass.
- At most 100 commands and 100 pages execute per pass; a larger backlog yields
  the lock and continues from durable state. Storage errors propagate and remain
  available as lastError because a broken store cannot reliably persist errors.
  The caller must show them and retain input.
- Cache reset, deletion and revoked-visibility tombstones do not discard pending
  projections. Full account lifecycle handling remains RUSH-013; no automatic
  cross-account reuse of storage is permitted.

## Schema and deployment

Dexie schema **v2** adds syncState keyed by key, storing coordinator failure count,
next retry deadline, pause/problem and last successful pass time. The released v1
schema is unchanged; upgrade only updates cache metadata's schemaVersion to 2.
All v1 profiles, commands, projections and checkpoints survive. Initial runtime
state is lazy and cold, without inventing a successful pass from a prior cache.
Checkpoint lastSuccessfulSyncAt permits null for incomplete initial pagination;
existing string values need no conversion.

No Server migration, endpoint, wire contract or dependency changes are required.
Normal Client deployment upgrades each partition on opening. Upgrade failure
rolls back rather than deleting data. Older Client code rejects v2 storage; use
a forward fix, never clear IndexedDB to downgrade while intent remains.

## Verification and follow-on boundaries

Vitest executes real Dexie transactions with fake-indexeddb, tests v1-to-v2 success
and rollback, page/settlement rollback, stale responses, tombstones, terminal and
temporary failures, restart/backoff, timeout/cancellation and multiple connections.
Playwright bundles a test-only entry in memory and injects the actual coordinator
into the production-built site's origin. Desktop/mobile tests exercise real
Sanctum/CSRF, native Web Locks, IndexedDB reload, and a real Server revision
conflict. The test harness is not included in production app assets.

This is not the mandatory offline **availability** proof: offline shell startup,
availability and a conflicting official assignment remain RUSH-011–014.
See [test evidence](../evidence/RUSH-010/README.md).
