# ADR 0018 — Complete unavailability review

Task: RUSH-018. Requirements: R-07, R-08. Prerequisites: RUSH-001–017 merged
on production, including the RUSH-014 offline conflict gate.

## Decisions

- Preserve the existing Ranger create/edit command, Dexie stores and centralized
  sync. Management uses online Orchid/API calls into the same range save service.
  Active Management can create/edit for active Rangers in its organization;
  a report's Ranger cannot be changed. Every Management save requires a reason
  and records actor, before/after range, revision and UTC timestamp atomically.
- Each accepted report revision is a durable Management notice. The Management
  home links to the notice list and shows the count awaiting acknowledgment.
  This is the availability-specific notice surface; the consolidated read/unread
  notification center remains RUSH-034. No external messages are sent.
- Acknowledgment means “seen,” applies only to the reviewed revision and records
  the first acknowledging actor/time. The unique report/revision pair makes
  repeated requests harmless. It never increments the range revision or changes
  assignments. Any later accepted edit requires acknowledgment again. Previous
  acknowledgments and range changes remain in the audit history.
- Lock actor membership, target Ranger membership, then report consistently.
  Saves and acknowledgments recheck authorization and expected revision while
  locked. Concurrent Ranger/Management edits cannot both accept one revision.
- Pull adds the current revision's acknowledgment timestamp to the existing
  owner-only unavailability projection. Equal-revision projection changes use
  the existing ordered stream, just like assignment-conflict updates. Receipt
  replay never replaces the pulled projection. No Dexie schema/index migration
  or extra writable cache/queue is necessary; old snapshots lacking the optional
  fields remain readable. Pending edits never inherit a “seen” status.
- Each overlapping official assignment flags its affected coverage interval:
  the intersection of assignment and unavailability, with its immutable target
  name if configured. Endpoints are exclusive for overlap. These are coverage
  gaps needing review, not confirmed replacement/Management coverage or a sum
  of missing staffing positions. Multiple reports can flag the same assignment;
  callers must not sum them. RUSH-025/040 retain aggregate and historical gap
  accounting. Conflicts are recalculated when ranges or assignments change.
- Orchid labels exact UTC entry and formats notices with weekday, date and AM/PM.
  Ranger entry retains QDate/QTime and its explicitly labeled device time zone;
  UTC instants remain unambiguous across overnight/DST intervals.

## Scope and migration

No unresolved §11 product decision is owned by RUSH-018. Draft/publication,
replacement generation and general notification-center behavior remain with
their assigned tasks. Scenario 4 has notice/acknowledgment/conflict evidence;
its complete release/replacement journey remains RUSH-027/028/031.

Apply `2026_10_13_120000_add_unavailability_acknowledgments` before deploying the
Server. It adds nullable Management reasons to existing range audit rows and a
revision-specific acknowledgment table. Existing reports start unacknowledged;
no assignments or historic ranges are rewritten. Back up before rollback because
it removes new acknowledgment history and Management reasons.

The owner also requested Artisan helpers. Existing generic local/Docker commands
support every Artisan function. Added aliases cover migration refresh/reset,
event cache/clear/list and environment diagnostics, with argument forwarding and
no implicit force flags. Destructive aliases remain explicit manual commands.
