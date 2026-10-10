# ADR 0014: Sync observability and explicit recovery

Task: RUSH-014. Status: implemented, pending review and merge.
Requirements: Technical §§7, 13; cross-cutting support for R-01–R-38,
with the R-07/R-08 availability conflict proof as the integrated regression.
Prerequisites: RUSH-001–013 merged on production.

## State and ownership

The existing account-scoped Dexie command, projection, confirmed availability and
coordinator stores remain the only durable Client state. No schema, API, Server
policy or operational workflow is added. A repository reads one transactional
snapshot for the application shell; Dexie live queries observe changes from other
tabs. The subscription clears on workspace locking/account changes. Runtime
coordinator activity and storage/capability errors are transient observations,
not a second cache or a persisted running flag that could survive a crash.

The shell shows connectivity separately from actual synchronization, pending
counts (including unresolved intent), failed/terminal counts, official assignment
conflicts, last completed push/pull cycle, automatic retry time and recovery.
An empty queue alone does not establish synchronization. Successful pull does not
hide terminal command conflicts or imply an official assignment was repaired.
Availability records retain their individual acceptance/error labels and ranges.

## Recovery

“Sync now” uses the existing coordinator and account Web Lock, bypassing temporary
backoff only. It cannot unpause authorization, alter expected revisions, replace
operation IDs or resend terminal rejections/conflicts. Sign-in recovery retains
the existing verified-account lifecycle. Lost accepted receipts and interrupted
pulls preserve the local representation until ordered pull reconciles it.

Rejected/conflicting availability intent remains visible alongside the current
Server range until the user explicitly confirms discarding that local edit.
The repository checks the terminal state and removes command plus projection in
one transaction. Pending/syncing/failed operations cannot be discarded this way:
their Server outcome may be unknown. A failed transaction preserves both records.
Confirmed records and Server audit/receipts are untouched. The user can then edit
the current range (with its current expected revision) or submit a new report.
Official assignment conflicts require Management action and are not dismissible.

## Verification and scope

Unit tests cover explicit retry, authentication pauses, terminal intent, runtime
errors, cross-connection observation, account locking and discard rollback.
The production-PWA regression exercises a real accepted command with a lost
response, repeated operation IDs, an interrupted pull, reload, recovery, two
independent device stores editing the same range, and cancel/confirm discard.
Existing production tests retain offline browser restart, Management assignment
conflict, revoked access, expired sessions and multi-tab exclusion coverage.
See [evidence](../evidence/RUSH-014/README.md) and [test guide](../testing.md).

No new scheduling, labor or visibility policy is decided. The nine functional
scenario owners are unchanged. Native installation/device checks and the later
release security/backup gate remain with their existing tasks. Milestone 2 still
requires required CI, human review and merge before it is declared complete.
