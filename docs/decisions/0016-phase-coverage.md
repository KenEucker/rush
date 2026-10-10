# ADR 0016: Phase shifts, areas and coverage

Task: RUSH-016. Requirements: R-02, R-03. Status: implemented, pending review.

## Scope and prerequisites

RUSH-001–015 are merged on production, including the Milestone 2 offline proof.
This slice configures scheduling inputs through online Orchid and the same
authorized API service. RUSH-017 retains desirability/hour policies, RUSH-021
integrated configuration acceptance, RUSH-022–025 generation/drafts/gaps, and
RUSH-028/030 publication/change UX. No unresolved §11 product decision belongs
to this task; no fairness, employment or Ranger visibility policy is introduced.

## Model and invariants

- Each phase has an optional coverage aggregate: one `dedicated` or `shared`
  model and an integer revision. Missing configuration is explicitly null.
  Separate phases may use different models. Configuration is replaced atomically.
- Phase-scoped UUID shift definitions have a name, local `HH:mm` start, and a
  positive integer elapsed duration in minutes. Neither three shifts nor eight
  hours is hardcoded. Area and shared-group identities are UUIDs. Group membership
  is normalized into an area join table. Names are unique ignoring case within
  each collection. Staffing references exact names within the submitted aggregate;
  the service resolves these to same-phase UUID foreign keys.
- Dedicated staffing has one area and no group; shared staffing has one group
  and no individual area. A shared group contains a nonempty set of distinct
  configured areas. Dedicated phases cannot contain shared groups. Switching
  models requires a complete valid replacement; no implicit conversion occurs.
- One nonnegative integer count applies per shift/target pair. Zero explicitly
  requires no positions; omitted pairs are unconfigured, never inferred coverage.
  Empty staffing is allowed while configuring inputs. Future generation must
  inspect configuration completeness; this slice does not claim reliable coverage.
- Composite foreign keys enforce phase ownership and the phase-wide staffing
  model. PostgreSQL checks protect model choice, positive duration, valid start
  time, nonnegative counts and exclusive area/group targets. The shared service
  additionally validates group membership, unique names and input bounds.
- Whole-aggregate saves retain supplied UUIDs, create new ones for new rows and
  remove omitted configuration rows. Dependent staffing/group joins are replaced
  in the same transaction. Assignment context and audit snapshots survive removal.
  There is no record deletion/pull protocol for this online-only configuration.
- Current Management membership is checked on every read/write. Writes lock
  membership, organization and season and check both coverage and calendar
  revisions. Snapshot reads hold an organization shared lock to avoid a mixture
  of revisions across queries. Replayed or stale saves return 409. Every save
  records actor, reason, before/after and revision; audit failure rolls back all
  effects. Configuration bounds (100 shifts/areas/groups, 10,000 staffing rows,
  32-bit positive durations/counts) are storage limits, not employment rules.

## Whole shifts and partial manual assignment representation

The existing Management-only official-interval service optionally accepts shift
context. It resolves the dated whole-shift start with SeasonCalendar and adds
the configured elapsed minutes in UTC. The start date must belong to the phase;
an overnight end can cross its boundary. ADR 0015 continues to govern allocation.
Nonexistent DST starts fail; repeated starts require an explicit matching offset.

An assignment stores its own positive UTC interval plus an immutable JSON snapshot
of the whole interval, phase/season/configuration revisions, zone, shift identity
and name, target identity/name and area names. The interval must fit inside the
whole shift, and `is_partial` is derived from its bounds. Changing or deleting
configuration cannot change these saved facts. Subsequent assignment edits retain
and validate against the snapshot unless Management explicitly supplies new,
revision-checked context. Null/empty context cannot strip an existing snapshot.
Legacy proof intervals remain valid with null context. Existing assignment audit
snapshots now include the context. Ready/Finished and payable hours are untouched.

This is the representation/API foundation requested by RUSH-016; creating
generated draft assignments and their manual-edit UI remains RUSH-023. It does
not introduce publication or treat a configuration save as a schedule change.

## UI and offline behavior

The existing season screen links each phase to an Orchid form with accessible
shift/area/group/staffing rows. Native time fields use the browser's time format;
help explains elapsed duration and overnight continuation. Forms retain entered
values on validation and stale conflicts. Mobile rows stack with visible labels.
Group members use comma-separated area names in the form (arrays in JSON);
area names cannot contain commas. References use exact names and must be updated
in the same save when renaming an input. This keeps initial configuration in one
form without requiring preliminary saves to populate selectors.

Orchid administration is online per Technical §7.4. No Dexie version, sync
command, Workbox API cache, Pinia persistence or extra queue is added. Ranger
cached schedule consumption remains RUSH-029. The production-PWA offline suite
is retained as regression evidence.

See [contract](../contracts/phase-coverage.md) and
[verification evidence](../evidence/RUSH-016/README.md).
