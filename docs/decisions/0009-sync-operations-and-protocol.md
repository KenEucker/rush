# 0009 Sync operations and protocol

Date: 2026-10-09. Task: RUSH-009. Prerequisites: merged RUSH-001–008 on
`production`. Requirements: Technical §7, with §8 authorization; cross-cutting
support for R-01–R-38. No functional acceptance scenario changes ownership.

## Scope and invariants

The existing owner-editable member profile proves the protocol without enabling
another operational workflow before the availability gate. The API and sync both
call `UpdateMemberProfile`; its validation, policies, revision checks and audit
transaction remain authoritative. No scheduling, employment or visibility rule
is decided here. Management's profile read access and owner-only writes are the
existing policy, not a new grant.

One POST pushes one typed command. A UUID is generated once, before atomically
staging its command and local representation in the existing Dexie repository.
Reopening reconstructs the identical command; retries must not mint new IDs.
Client transport validates ingress, uses Sanctum cookies/CSRF, and neither retries
nor persists responses independently. The shell does not invoke these primitives.

The Server locks the current organization membership before processing sync.
This serializes concurrent pushes and pulls from that account across devices.
It rechecks active membership, scoped record lookup and the domain update policy
before reading a replay receipt. A primary key on membership/operation prevents
duplicates. A normalized command fingerprint rejects reuse for different intent.
The operation receipt, domain update and audit commit together. A failure writing
the receipt rolls all effects back. Unexpected failures leave no receipt and are
safe to retry. Domain validation/conflict results are durable receipts; corrected
intent requires a new ID. No-op writes retain the domain revision and no audit.

Accepted replay returns the original accepted record, even after later edits.
It is evidence of that operation, not proof that the record is still current.
The coordinator must never replace a higher cached revision with it. Permission
loss blocks replay; it cannot be used to retrieve formerly authorized data.

## Pull projection and ordering

The small V1 organization permits a deliberately bounded implementation: each
pull captures the current authorized profile set into a per-membership Server
projection. This observes existing online service changes, creation and deletion
without adding a second mutation path or requiring historical bootstrap events.
It is a state synchronization journal, not an audit log: multiple domain edits
between pulls can coalesce; the domain audit retains individual changes.

`sync_streams` stores the generation, role and sequence counter; `sync_profiles`
stores the last observed allowlisted values; `sync_changes` stores sequenced
snapshots and null tombstones. All projection/journal/counter changes commit in
one transaction under the membership lock. Sequences reflect committed capture
order, never device time, `updated_at`, or an auto-increment allocated in another
uncommitted transaction. A mutation committed after capture is observed next pull.

Checkpoints use Laravel authenticated encryption and bind protocol version,
membership, generation, cursor and page upper bound. Tokens are opaque and reusable;
encryption can produce different token strings for the same logical position.
An unfinished page retains its upper bound. Once drained, the next pull advances
to the current sequence. Changes captured during pagination therefore remain for
the next pass. Initial pull replays the journal from zero, including the first
captured baseline. Checkpoints are account/organization specific, not per device.

Every pull rechecks policy. Historic snapshots of now-absent or inaccessible IDs
are returned as tombstones. A role mismatch invalidates the previous generation;
a checkpoint-free restart rebuilds only currently authorized data. An invalid
checkpoint returns 409 without committing any changes. This includes malformed,
foreign, impossible future and prior-generation tokens. Restarts must preserve
pending Client intent while replacing confirmed records/checkpoints. An inactive
membership gets 403, never a partial data response.

No journal or receipt age expiry is introduced. Receipts cannot be pruned while
an old operation may replay. Role resets discard derived sync history, not domain
audit or receipts. Retention/erasure review remains RUSH-048. Future large domains
must assess capture cost and can introduce transactionally recorded change feeds;
they must preserve commit-safe ordering, bootstrap and authorization behavior.

## Follow-on boundary

RUSH-010 owns the coordinator, retries/backoff, connectivity, applying incoming
pages and checkpoints in one Dexie transaction, accepted-command removal and
failed/conflict state transitions. It must serialize local consumers or compare
the request checkpoint to the current one before applying a response, so an old
in-flight page cannot resurrect a tombstone or roll back progress. A reset must
retain pending commands/projections; `clearCache({ discardPending: true })` is not
appropriate recovery. RUSH-013 owns account lifecycle UX and permission-loss
integration. RUSH-011–014 retain the production-PWA offline availability gate.

## Deployment

Run `php artisan migrate --force` before serving the new endpoints. Four additive
Server tables are introduced; existing profiles and their audits are unchanged.
No dependency, Dexie schema, UI or Workbox change is required. Keep `APP_KEY` stable;
key rotation without previous-key support invalidates checkpoints and requires
resynchronization. Do not roll back/drop receipts after accepting production
commands: that loses replay protection. Back up and prefer a forward fix.

See the [wire contract](../contracts/sync.md) and
[verification evidence](../evidence/RUSH-009/README.md). Review and merge remain
required for task completion.
