# ADR 0022: Planning inputs and constraint precedence

Task: RUSH-022. Requirements: R-12, R-13. Status: owner-approved rules; implementation pending review.

## Approved product rules

In the RUSH-022 chat the owner approved this hierarchy: automatic proposals do
not violate unavailability, overlapping work, configured caps or rest. Management
may explicitly override these with a reason and visible conflicts. Published
commitments remain untouched. Coverage and event shortfalls remain visible.
Weekly fairness precedes season fairness and positive preferences. RUSH-024 still
owns scoring weights and fairness tradeoffs within these priorities.

The owner chose calendar-day proration for mixed-phase weekly targets, caps and
warning thresholds: sum each phase's weekly value multiplied by its days / 7.
This is calendar days, including DST days, not elapsed-hour weighting. Store the
numerator as integer minute-sevenths; compare elapsed seconds * 7 to numerator *
60, avoiding rounding around caps. Days outside the season contribute zero;
missing or stale policies inside the season make the relevant week unknown,
blocking automatic candidates. No policy is silently treated as unlimited.

The owner specified configurable minimum rest, initially 8 hours (480 minutes),
and overlapping paid shift/event time counted only once toward weekly hours.
Rest is an explicit per-planning-run input, 0–10080 whole minutes, saved in the
snapshot; it is elapsed time, with equality allowed. It checks work intervals
including unpaid assigned events, and both earlier and later commitments.
Deduplicating paid hours does not establish that simultaneous assignments are
conflict-free: overlapping work remains an explicit overridable conflict.
Neither these calculations nor rest settings certify employment compliance or
change actual/payable hours. RUSH-037 retains employment/pay validation.

## Slice and invariants

Online Management-only API and Orchid capture arbitrary inclusive date ranges
inside one season, including a single day and partial weeks. Shift opportunities
use the local start date and full elapsed duration, retaining the originating
staffing row, dedicated/shared target and resolved desirability. Overnight work
expands affected weeks; an event intersecting the requested range retains its
whole interval. Work outside the season is an input issue, not silently clipped
into a valid assignment. DST gaps remain actionable errors; folds require an
explicit offset keyed by shift UUID and local date. The form exposes offset fields
when needed. Historical snapshots remain readable; archived seasons cannot create
new planning runs, consistent with Management's read-only year navigation.

`planning_inputs` is an immutable UUID record with tenant/season foreign keys,
actor, reason, creation instant, schema version, full source payload and SHA-256
fingerprint. Capture is the audit event; there is no update/delete endpoint.
The payload includes season/phase configuration and policies with revisions,
roster eligibility, default/weekly effective preferences and revisions, all
organization availability, official commitments and event attendance/revisions.
Retaining organization-wide commitments includes cross-season conflicts and
historical context; unrelated source changes may conservatively mark it stale.
Record insertion/removal and eligibility changes are detected as well as revision
changes. Reads rebuild inputs to report staleness but never rewrite the snapshot.
RUSH-027 owns released-draft staleness and lifecycle behavior.

PostgreSQL capture/read transactions use REPEATABLE READ so independently locked
configuration, Ranger writes and roster changes are read from one committed MVCC
snapshot. Serialization/deadlock retries use Laravel's transaction facility.
Tests nested within an existing transaction inherit its isolation; the dedicated
PostgreSQL concurrency test uses real committed independent connections.

Pure candidate evaluation accepts only active same-organization Rangers and
snapshot opportunity identifiers. Structural/configuration errors cannot be
overridden. Duplicate candidates and reassigning an already-assigned event are
hard errors. All supplied candidate intervals are evaluated together alongside
existing commitments; the evaluator never silently drops conflicting candidates.
Availability, work overlap, rest and caps are separately identified overridable
conditions. Warning thresholds and positive preference factors stay separate.
Missing positive preferences imply no negative score. An override reason reports
hypothetical eligibility only: RUSH-023 must persist reasons when implementing
actual draft edits. Current authorization is checked on every API/UI access.

## Infeasibility and remaining tasks

Missing configuration or invalid wall times produce inspectable issues and block
automatic evaluation. Constraints are never relaxed automatically to fill demand.
Coverage targets and event number/percentage targets stay independent; percentage
uses all active Rangers, rounded up, preserving the approved event rule. Insufficient
Rangers/hours/availability leaves unmet demand for RUSH-023/025 to represent as gaps
and suggestions. Existing event staffing evaluation remains authoritative for
configured attendance. This slice does not generate assignments, rank Rangers,
publish, approve overrides, compute fairness scores or implement gap accounting.

Administration stays online; no Dexie schema, sync command or Workbox changes.
Published records, Ready/Finished and payable hours are untouched. No dependency
was added. Scenarios 1–2 gain input/constraint evidence; their complete journeys
and all nine scenario owners remain unchanged.
