# ADR 0012: Offline Ranger unavailability proof

RUSH-012 implements the foundational R-07 slice after RUSH-001–011 on
production. The full Management notice, acknowledgment, on-behalf editing and
assignment-conflict workflow remains RUSH-013/018. This decision does not close
the Milestone 2 gate or any of the nine functional acceptance scenarios.

## Domain and authorization

An unavailability range belongs to one organization membership, has a UUID,
UTC start/end instants and a positive Server revision. The end must be strictly
later than the start. Overlapping reports are retained; the proof does not infer
schedule repair, acknowledgment, or preferences from them. Rangers can create
and revise their own ranges. No peer or Management access is enabled by this
slice; RUSH-018 adds Management's full workflow.

The shared SaveUnavailability service rechecks current membership and policy
inside a transaction, validates the interval, checks the expected revision,
and writes the record plus actor/revision audit. A null expected revision means
create; a positive revision means update. Existing UUIDs cannot be overwritten
by a create. There is no delete workflow in this slice.

## Sync and local storage

The existing push endpoint adds unavailability.save. It binds the payload's
membership UUID to the current actor before processing or replaying a receipt.
The shared receipt, transaction, backoff and cross-tab coordinator mechanisms
are reused. There is no separate availability queue.

The pull stream includes record_type=unavailability alongside member profiles,
with the same sequence/checkpoint and visibility rechecks. The new projection
table uses the existing membership stream. Changes remain owner-only, including
historic pages after role changes. Confirmed data is populated by ordered pull,
never by replay receipts. An accepted receipt records its revision but keeps the
local range visible until a matching or newer pull revision replaces it in the
same transaction as the checkpoint. A connection loss between push and pull
therefore cannot make a saved range disappear. Rejections/conflicts retain their
local representation.

Dexie v3 adds the unavailabilities store. V1/v2 migrations retain pending
commands, local representations, profiles, checkpoints and coordinator state.
Account scope is copied through an explicit allowlist so a caller's metadata
cannot replace the account cache key.

## Startup and dates

A separate, small Dexie directory holds only the last verified Ranger's account,
organization and membership IDs and organization label. It is a locator for the
partition, not authentication. Offline startup opens only availability and leaves
session identity unset. It does not restore Management privileges or start sync.
The coordinator resumes after Server session verification. Known authentication
failures remove the locator; cached intent remains in its original partition.
Sign-out is blocked while current-account work remains unresolved, and successful
sign-out removes the locator. Expanded role-loss, account-switching and conflict
recovery UX remains RUSH-013.

For V1's single-organization proof, the workspace uses the first active Ranger
membership returned by the Server. Organization selection remains future work
if multi-organization operation becomes necessary.

The editor explicitly labels the device time zone, uses QDate/QTime with AM/PM,
and shows both dates for overnight ranges. It converts to UTC before staging.
Nonexistent and repeated local times at the usual one-hour DST transition are
rejected rather than silently shifted. Season/week allocation policy is still
RUSH-015; the proof does not calculate hours, weeks or scheduling constraints.

## Deployment and rollback

Run Laravel migrations before deploying the Client. The migration adds
unavailabilities, unavailability_changes and sync_unavailabilities, and adds
record_type to sync_changes with member_profile as the default for existing
rows. PostgreSQL enforces membership/organization integrity and positive ranges.
No new production dependency is required.

Do not downgrade a populated database by dropping availability or receipts.
Back up and restore the matching application/database version, preserving
accepted records and replay protection. Older Client code fails closed on the
new Dexie schema; do not clear unsent user data to force a downgrade.

See [verification evidence](../evidence/RUSH-012/README.md).
