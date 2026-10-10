# ADR 0013: Assignment conflict and account-security proof

RUSH-013 builds on RUSH-001–012 merged to production. It covers the R-07/R-08
conflict foundation and Technical §§7–8. Full Management availability editing,
acknowledgments and gap accounting remain RUSH-018/025/040; publication,
notification and team-calendar workflows remain their existing tasks.

## Official intervals and reported intent

The proof needs a real Server-authoritative assignment, rather than a simulated
availability revision conflict. An official assignment is a UUID, organization,
Ranger membership, UTC interval and revision. Management can create/revise this
minimal interval using the online-only PUT endpoint and shared
SaveOfficialAssignment service. Every write requires a reason, checks the
current manager and target Ranger under membership locks, checks the expected
revision, and commits actor-attributed before/after history atomically.
No offline assignment command exists. A null expected revision means create;
updates require the current positive revision. A stale or repeated write cannot
silently overwrite a newer assignment.

This is deliberately the minimal assignment substrate for the required proof,
not a generation or publication workflow. Season, shift, area, publication-block
and gap relationships are added by their owner tasks. It does not decide any
open fairness, employment or draft-visibility rules.

Unavailability remains accepted even when an assignment overlaps it. Intervals
overlap only when each starts before the other ends; touching boundaries do not
conflict. AssignmentConflicts returns only the report owner's official intervals
and revisions. It never updates either record. The Ranger sees the accepted
report, conflicting official interval, warning that coverage is unreliable, and
a request to contact Management. Reporting or acknowledging availability does
not repair the schedule.

## Sync projection and failures

The existing owner-authorized unavailability resource includes
assignment_conflicts. The existing sequenced pull journal detects changes to
that projection, including conflicts introduced or resolved after acceptance.
It can emit a changed projection at the same unavailability revision; Dexie
therefore applies equal report revisions from a newly committed checkpointed
page. Older report revisions remain ignored. There is no additional queue,
Workbox data cache, or Dexie schema change. Old cached reports without the
optional field remain readable until the next pull.

Push receipts remain immutable and idempotent; only ordered pull updates
confirmed data. Validation field explanations are retained with rejected intent.
A denied push becomes rejected and pauses sync; it is never automatically resent
after access is restored. The user can review the preserved report and submit
new intent. Expired authentication/CSRF pauses failed work until verified
reauthentication, retaining the original operation ID.

## Account lifecycle and retention

Every workspace sync request includes its expected account and Ranger membership.
The Server rejects cookie-account mismatches before touching a stream; current
Ranger membership is checked inside the sync membership lock. A changed role
cannot cause a Ranger cache to ingest newly visible Management data. Headers
narrow access only; cookie authentication, CSRF and Server policy remain required.
Non-workspace protocol callers retain the existing role-authorized API contract.

Session transitions are serialized. Known 401/403/419 sync failures lock the
workspace and remove the offline locator, clear confirmed records/checkpoints,
and preserve queued/local intent in its original partition. Role loss discovered
by session refresh similarly closes access. Offline startup never establishes
authorization or discovers another account's retained partitions.

The shared Dexie workspace locator is observed across tabs. Clearing it or
switching its account closes the old tab's handles and hides its content.
Account-bound requests protect against cookie changes while a request is in
flight. Foreground sync remains sufficient.

Sign-out first warns if work remains. The explicit “Keep saved work and sign out”
action stops synchronization, confirms Server logout, removes confirmed cache
and the locator, and retains pending/rejected/conflicting intent for that account.
Signing into a different account cannot read or send it. Signing back into the
original authorized account reopens it. Browser storage is not encryption; do
not clear browser data while work is unsent. A failed network or CSRF logout is
not presented as confirmed logout. No new automatic retention/deletion policy
is introduced.

## Deployment and rollback

Run the new official_assignments/official_assignment_changes migration before
deploying the Client/Server update. PostgreSQL enforces membership/organization
integrity, positive revisions and valid intervals; audit revisions are unique.
No production dependency changed. The Server AGENTS-required Boost setup was
performed locally and is not included as an unrelated dependency change.

A rollback must preserve official records and their audit history. Restore a
matching backup/application version instead of dropping populated tables.
Dexie remains v3, and pending operations use the existing wire contract.
See [verification evidence](../evidence/RUSH-013/README.md).
